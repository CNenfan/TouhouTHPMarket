#!/usr/bin/env node
/**
 * THP 压力测试脚本（本地开发用，不部署到服务器）
 *
 * 用法：
 *   node tools/stress-test.js prepare --helper=/__st_7d2a.php --key=XXX --count=60
 *   node tools/stress-test.js burst   --users=60
 *   node tools/stress-test.js steady  --users=60 --seconds=180
 *   node tools/stress-test.js cleanup --helper=/__st_7d2a.php --key=XXX
 * 可选 --base=https://example.com 指定目标站点（默认本地 127.0.0.1:8123）
 */
'use strict';
const http = require('http');
const https = require('https');
const zlib = require('zlib');
const fs = require('fs');
const path = require('path');

const argv = process.argv.slice(2);
const cmd = argv.find(function (a) { return a.indexOf('--') !== 0; });
const args = {};
argv.forEach(function (a) {
  const m = /^--([^=]+)(?:=(.*))?$/.exec(a);
  if (m) { args[m[1]] = m[2] === undefined ? '1' : m[2]; }
});

const BASE = args.base || 'http://127.0.0.1:8123';
const baseUrl = new URL(BASE);
const lib = baseUrl.protocol === 'https:' ? https : http;
const agent = new lib.Agent({ keepAlive: true, maxSockets: 300, maxFreeSockets: 300 });
const sessionsFile = path.join(__dirname, '..', '.dev', 'stress-sessions.json');
const HELP_LINE = '用法: node tools/stress-test.js <prepare|burst|steady|cleanup> [--base=...] [--users=60] [--seconds=180] [--count=60] [--helper=/__st_x.php] [--key=...]';

let stats = new Map();
let errorSamples = [];
let bytesTotal = 0;

function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

function record(name, ms, status, bytes, appError) {
  let s = stats.get(name);
  if (!s) { s = { n: 0, err: 0, ms: [], bytes: 0 }; stats.set(name, s); }
  s.n++; s.ms.push(ms); s.bytes += bytes; bytesTotal += bytes;
  if (status !== 200 || appError) {
    s.err++;
    if (errorSamples.length < 30) { errorSamples.push(name + ' :: ' + (appError || ('HTTP ' + status))); }
  }
}

function request(pathname, opts) {
  opts = opts || {};
  const started = process.hrtime.bigint();
  return new Promise(function (resolve) {
    const req = lib.request({
      hostname: baseUrl.hostname,
      port: baseUrl.port || (baseUrl.protocol === 'https:' ? 443 : 80),
      path: pathname,
      method: opts.method || 'GET',
      agent: agent,
      headers: Object.assign({
        'Accept-Encoding': 'gzip',
        'User-Agent': 'THP-StressTest/1.0',
        'Connection': 'keep-alive'
      }, opts.cookie ? { Cookie: opts.cookie } : {}, opts.headers || {})
    }, function (res) {
      const chunks = [];
      res.on('data', function (c) { chunks.push(c); });
      res.on('end', function () {
        const rawBytes = Buffer.concat(chunks).length;
        let buf = Buffer.concat(chunks);
        const ms = Number(process.hrtime.bigint() - started) / 1e6;
        const enc = String(res.headers['content-encoding'] || '');
        if (enc.indexOf('gzip') !== -1) { try { buf = zlib.gunzipSync(buf); } catch (e) {} }
        resolve({ status: res.statusCode, ms: ms, bytes: rawBytes, text: buf.toString('utf8') });
      });
    });
    req.setTimeout(60000, function () { req.destroy(new Error('timeout')); });
    req.on('error', function (e) {
      const ms = Number(process.hrtime.bigint() - started) / 1e6;
      resolve({ status: 0, ms: ms, bytes: 0, text: '', error: String(e.message || e) });
    });
    if (opts.body) { req.write(opts.body); }
    req.end();
  });
}

async function call(action, session, payload, label, query) {
  const name = label || action;
  let qs = '/api.php?action=' + encodeURIComponent(action);
  if (query) { Object.keys(query).forEach(function (k) { qs += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(query[k]); }); }
  const body = payload ? JSON.stringify(payload) : null;
  const r = await request(qs, {
    method: body ? 'POST' : 'GET',
    body: body,
    cookie: session ? 'THPSESSID=' + session.sid : '',
    headers: body ? { 'Content-Type': 'application/json', 'X-Thp': '1' } : {}
  });
  let appError = r.error || '';
  if (!appError && r.status === 200) {
    try {
      const j = JSON.parse(r.text);
      if (!j.ok) { appError = j.error || 'ok=false'; }
    } catch (e) { appError = '不是 JSON 响应'; }
  }
  record(name, r.ms, r.status, r.bytes, appError);
  r.app_error = appError;
  return r;
}

function loadSessions() {
  if (!fs.existsSync(sessionsFile)) { throw new Error('还没有会话文件，先执行 prepare'); }
  return JSON.parse(fs.readFileSync(sessionsFile, 'utf8'));
}

function pct(sorted, p) { return sorted.length ? sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * p))] : 0; }

function report(title, wallMs) {
  const rows = [];
  let nTotal = 0, nErr = 0;
  for (const entry of Array.from(stats.entries()).sort(function (a, b) { return b[1].n - a[1].n; })) {
    const name = entry[0], s = entry[1];
    nTotal += s.n; nErr += s.err;
    const sorted = s.ms.slice().sort(function (x, y) { return x - y; });
    rows.push({
      name: name, n: s.n, err: s.err,
      p50: pct(sorted, 0.5).toFixed(1), p90: pct(sorted, 0.9).toFixed(1),
      p99: pct(sorted, 0.99).toFixed(1), max: sorted.length ? sorted[sorted.length - 1].toFixed(1) : '0',
      avgKB: s.n ? (s.bytes / s.n / 1024).toFixed(1) : '0'
    });
  }
  console.log('\n===== ' + title + ' =====');
  console.log('总请求 ' + nTotal + '，错误 ' + nErr + '，接收流量 ' + (bytesTotal / 1048576).toFixed(2) + ' MB，用时 '
    + (wallMs / 1000).toFixed(1) + ' s，平均 ' + (nTotal / (wallMs / 1000)).toFixed(1) + ' req/s');
  console.log('接口'.padEnd(20) + '次数'.padStart(7) + '错误'.padStart(6) + 'p50'.padStart(9) + 'p90'.padStart(9) + 'p99'.padStart(10) + 'max'.padStart(10) + '平均KB'.padStart(9));
  rows.forEach(function (r) {
    console.log(String(r.name).padEnd(20) + String(r.n).padStart(7) + String(r.err).padStart(6)
      + (r.p50 + 'ms').padStart(9) + (r.p90 + 'ms').padStart(9) + (r.p99 + 'ms').padStart(10) + (r.max + 'ms').padStart(10) + String(r.avgKB).padStart(9));
  });
  if (errorSamples.length) { console.log('错误样例：\n  ' + errorSamples.slice(0, 12).join('\n  ')); }
  const out = path.join(__dirname, '..', '.dev', 'stress-report.json');
  fs.writeFileSync(out, JSON.stringify({ title: title, wall_ms: wallMs, rows: rows, errors: errorSamples, bytes_total: bytesTotal }, null, 2));
  console.log('报告已写入 ' + out);
  stats = new Map(); errorSamples = []; bytesTotal = 0;
}

(async function main() {
  if (cmd === 'prepare') {
    const helper = args.helper, key = args.key;
    if (!helper || !key) { console.log(HELP_LINE); process.exit(1); }
    const count = parseInt(args.count || '60', 10);
    const ping = await request(helper + '?action=ping&key=' + encodeURIComponent(key));
    if (ping.status !== 200) { console.log('压测助手不可用：HTTP ' + ping.status + ' ' + ping.text.slice(0, 160)); process.exit(1); }
    const t0 = Date.now();
    const r = await request(helper + '?action=prepare&count=' + count + '&key=' + encodeURIComponent(key));
    let j = null;
    try { j = JSON.parse(r.text); } catch (e) {}
    if (!j || !j.ok) { console.log('prepare 失败：' + r.text.slice(0, 240)); process.exit(1); }
    fs.writeFileSync(sessionsFile, JSON.stringify(j.users, null, 2));
    console.log('已创建 ' + j.users.length + ' 个压测账号与会话（含管理员），用时 ' + (Date.now() - t0) + ' ms');
    const smoke = await call('me', j.users[0], null, 'me(冒烟)');
    console.log('冒烟 me：HTTP ' + smoke.status + ' ' + smoke.text.slice(0, 120));
    const admin = j.users.find(function (u) { return u.role === 'admin'; });
    if (admin) {
      const a = await call('admin.market_info', admin, null, 'admin.market_info(冒烟)');
      console.log('冒烟 admin.market_info：HTTP ' + a.status + '，响应 ' + (a.bytes / 1024).toFixed(0) + ' KB');
    }
    return;
  }

  if (cmd === 'cleanup') {
    const helper = args.helper, key = args.key;
    if (!helper || !key) { console.log(HELP_LINE); process.exit(1); }
    const r = await request(helper + '?action=cleanup&key=' + encodeURIComponent(key));
    console.log('清理结果：' + r.text.slice(0, 200));
    if (fs.existsSync(sessionsFile)) { fs.unlinkSync(sessionsFile); }
    await sleep(500);
    const again = await request(helper + '?action=ping&key=' + encodeURIComponent(key));
    console.log('助手复查：HTTP ' + again.status + '（404 表示助手文件已自删）');
    return;
  }

  if (cmd === 'burst') {
    const users = parseInt(args.users || '60', 10);
    const sessions = loadSessions().filter(function (s) { return s.role === 'user'; }).slice(0, users);
    const assets = ['/assets/css/app.css', '/assets/js/app.js', '/assets/js/market.js', '/assets/js/token.js', '/assets/img/logo.png'];
    const withAssets = args.assets !== '0';
    const t0 = Date.now();
    await Promise.all(sessions.map(async function (s) {
      const r = await request('/index.php', {});
      record('index.php', r.ms, r.status, r.bytes, r.error || '');
      if (withAssets) {
        await Promise.all(assets.map(async function (a) {
          const rr = await request(a, {});
          record('静态资源', rr.ms, rr.status, rr.bytes, rr.error || '');
        }));
      }
      await call('me', s);
    }));
    report('突发打开（' + sessions.length + ' 人同时进入网站）', Date.now() - t0);
    return;
  }

  if (cmd === 'steady') {
    const users = parseInt(args.users || '60', 10);
    const seconds = parseInt(args.seconds || '180', 10);
    const all = loadSessions();
    let pool = all.filter(function (s) { return s.role === 'user'; });
    if (args.spread === '1') {
      const seenNames = {};
      pool = pool.filter(function (s) {
        if (seenNames[s.username]) { return false; }
        seenNames[s.username] = true;
        return true;
      });
    }
    const sessions = pool.slice(0, users);
    const admin = all.find(function (s) { return s.role === 'admin'; });
    const maxBuys = parseInt(args.buys || '10', 10);
    const maxSpends = parseInt(args.spends || '3', 10);
    const thinkMul = parseFloat(args.think || '1');
    const deadline = Date.now() + seconds * 1000;
    const companyIds = {};
    const t0 = Date.now();
    const workers = sessions.map(async function (s, idx) {
      let buys = 0, spends = 0;
      const first = await call('market.state', s);
      try {
        const j = JSON.parse(first.text);
        if (j.ok) { companyIds[idx] = j.data.companies.map(function (c) { return c.id; }); }
      } catch (e) {}
      while (Date.now() < deadline) {
        const roll = Math.random();
        if (roll < 0.25) { await call('me', s); }
        else if (roll < 0.5) { await call('market.state', s); }
        else if (roll < 0.58 && buys < maxBuys) {
          const ids = companyIds[idx] || [1];
          const r = await call('market.buy', s, { company_id: ids[Math.floor(Math.random() * ids.length)], qty: 1 });
          buys++;
          if (r.status === 200) { await call('market.state', s); }
        }
        else if (roll < 0.68) { await call('token.history', s); }
        else if (roll < 0.78) { await call('story.list', s); }
        else if (roll < 0.84 && spends < maxSpends) {
          const c = await call('token.spend_create', s, { amount: 10 });
          let code = '';
          try { const j = JSON.parse(c.text); if (j.ok) { code = j.data.code; } } catch (e) {}
          if (code) {
            for (let i = 0; i < 5 && Date.now() < deadline; i++) {
              await sleep(3000);
              await call('token.spend_status', s, null, 'token.spend_status', { code: code });
            }
            await call('token.spend_cancel', s, { code: code });
          }
          spends++;
        }
        await sleep((800 + Math.random() * 2200) * thinkMul);
      }
    });
    if (admin && args.admin !== '0') {
      workers.push((async function () {
        while (Date.now() < deadline) {
          await call('admin.market_info', admin, null, 'admin.market_info');
          await sleep(20000);
        }
      })());
    }
    await Promise.all(workers);
    report('持续负载（' + sessions.length + ' 名游客' + (admin && args.admin !== '0' ? ' + 1 名管理员' : '') + '，' + seconds + ' 秒）', Date.now() - t0);
    return;
  }

  if (cmd === 'unwind') {
    const all = loadSessions();
    const seen = {};
    const uniques = [];
    all.forEach(function (s) { if (!seen[s.username]) { seen[s.username] = true; uniques.push(s); } });
    const t0 = Date.now();
    let soldShares = 0;
    for (const s of uniques) {
      const st = await call('market.state', s, null, 'unwind.state');
      let data = null;
      try { data = JSON.parse(st.text); } catch (e) {}
      if (!data || !data.ok) { continue; }
      const holdings = (data.data.me && data.data.me.holdings) ? data.data.me.holdings : {};
      for (const cid of Object.keys(holdings)) {
        const shares = parseInt(holdings[cid].shares || '0', 10);
        if (shares > 0) {
          const r = await call('market.sell', s, { company_id: parseInt(cid, 10), qty: shares }, 'unwind.sell');
          if (r.status === 200) { soldShares += shares; }
          await sleep(300);
        }
      }
    }
    report('卖出压测持仓（' + uniques.length + ' 个账号，共卖出 ' + soldShares + ' 股）', Date.now() - t0);
    return;
  }

  console.log(HELP_LINE);
  process.exit(1);
})().catch(function (e) { console.error('压测脚本出错：' + (e && e.message ? e.message : e)); process.exit(1); });
