#!/usr/bin/env node
// 通过 FTP 把 public/ 里的文件上传到服务器（网站根目录）
// 用法： node tools/deploy-ftp.js [--dry] [--prefix=子目录] [--only=关键字]
// 凭据读取顺序：环境变量 THP_FTP_* > 项目根目录的 ftp-凭据.txt（JSON，已 gitignore）
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const SITE_ROOT = path.join(ROOT, 'public');

function loadCreds() {
  const env = process.env;
  if (env.THP_FTP_HOST && env.THP_FTP_USER && env.THP_FTP_PASS) {
    return { host: env.THP_FTP_HOST, port: Number(env.THP_FTP_PORT || 21), user: env.THP_FTP_USER, pass: env.THP_FTP_PASS };
  }
  const f = path.join(ROOT, 'ftp-凭据.txt');
  if (fs.existsSync(f)) {
    const j = JSON.parse(fs.readFileSync(f, 'utf8'));
    return { host: j.host, port: Number(j.port || 21), user: j.user, pass: j.pass };
  }
  throw new Error('没有找到 FTP 凭据：请创建 ftp-凭据.txt 或设置 THP_FTP_* 环境变量');
}

// 这些文件属于本地环境或运行时数据，不部署
const SKIP_FILES = [
  /^config\.php$/,
  /^install\.php$/, // 线上已安装完成，安装脚本不再上传，避免暴露
  /^data\/php-errors\.log$/,
  /^data\/.*\.bak\.php$/,
  /^data\/.*\.tmp.*$/,
  /^data\/backups\//,
  /^data\/previews\//,
  /^data\/cache\//,
  /^data\/trend-\d{4}-\d{2}-\d{2}\.php$/,
  /^data\/trend-fallback\.php$/, // 旧版兜底文件，已改用 data/trends/
  /^data\/trends\//, // 线上走势文件属于运行时数据，默认不覆盖（用 --seed-trends 才上传）
  /^uploads\/(?!\.htaccess|\.gitkeep).*/,
];

function walk(dir, base, out) {
  for (const name of fs.readdirSync(dir)) {
    const full = path.join(dir, name);
    const rel = (base ? base + '/' : '') + name;
    if (fs.statSync(full).isDirectory()) walk(full, rel, out);
    else out.push(rel);
  }
  return out;
}

function curl(creds, args) {
  const full = ['-s', '--ftp-create-dirs', '--user', creds.user + ':' + creds.pass, '--connect-timeout', '20', '--max-time', '300', ...args];
  return execFileSync('curl.exe', full, { encoding: 'utf8' });
}

function redact(text, creds) {
  let out = String(text || '');
  out = out.split(creds.user + ':' + creds.pass).join('***:***');
  out = out.split(creds.pass).join('***');
  return out;
}

function main() {
  const argv = process.argv.slice(2);
  const dry = argv.includes('--dry');
  const prefixArg = argv.find((a) => a.startsWith('--prefix='));
  const prefix = prefixArg ? prefixArg.split('=')[1].replace(/^\/|\/$/g, '') : '';
  const onlyArg = argv.find((a) => a.startsWith('--only='));
  const only = onlyArg ? onlyArg.split('=')[1] : '';
  const seedTrends = argv.includes('--seed-trends');

  const creds = loadCreds();
  let files = walk(SITE_ROOT, '', []);
  files = files.filter((rel) => {
    // 明确要求时才上传种子走势；备份与临时文件永远不上传
    if (seedTrends && rel.startsWith('data/trends/')) {
      return /\.php$/.test(rel) && rel.indexOf('.bak') === -1 && rel.indexOf('.tmp') === -1;
    }
    return !SKIP_FILES.some((re) => re.test(rel));
  });
  if (only) files = files.filter((rel) => rel.indexOf(only) !== -1);
  files.sort();

  console.log('目标：ftp://' + creds.host + ':' + creds.port + '/' + (prefix ? prefix + '/' : ''));
  console.log('待上传：' + files.length + ' 个文件');
  if (dry) { files.forEach((f) => console.log('  ' + f)); return; }

  let ok = 0; const failed = [];
  for (const rel of files) {
    const remote = (prefix ? prefix + '/' : '') + rel;
    try {
      curl(creds, ['-T', path.join(SITE_ROOT, rel), 'ftp://' + creds.host + ':' + creds.port + '/' + remote]);
      ok++;
      console.log('  [OK] ' + rel);
    } catch (e) {
      failed.push(rel);
      console.log('  [!!] ' + rel + '  ' + redact(e.stderr || e.message || '', creds).trim());
    }
  }
  console.log('\n完成：成功 ' + ok + ' / 失败 ' + failed.length);
  if (failed.length) { console.log('失败清单：'); failed.forEach((f) => console.log('  ' + f)); process.exitCode = 1; }
}

main();
