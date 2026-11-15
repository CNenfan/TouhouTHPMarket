/* 管理员动态行情、未来走势干预与排行榜 */
(function () {
  'use strict';

  var RANGES = [10, 30, 60, 120, 180, 300, 720];
  var state = {
    host: null,
    data: null,
    range: 60,
    view: 'both',
    selected: null,
    future: null,
    futureHours: 1,
    chartScale: null,
    dragIndex: -1
  };

  function THP() { return window.THP; }
  function esc(v) { return window.THP.esc(v === null || v === undefined ? '' : String(v)); }
  function qs(sel) { return window.THP.qs(sel, state.host); }
  function num(v, digits) {
    var n = Number(v);
    if (!isFinite(n)) { return '-'; }
    return String(Math.round(n * Math.pow(10, digits || 2)) / Math.pow(10, digits || 2));
  }
  function signed(v) {
    var n = Number(v) || 0;
    return (n > 0 ? '+' : '') + Math.round(n).toLocaleString('zh-CN');
  }
  function timeLabel(tick) {
    var mins = Math.max(0, Number(tick) || 0) * 3;
    var h = Math.floor(mins / 60) % 24;
    var m = mins % 60;
    return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
  }
  function rangeOptions() {
    function label(minutes) {
      return minutes >= 60 ? (minutes / 60) + ' 小时' : minutes + ' 分钟';
    }
    return [
      ['past', '过去'],
      ['both', '过去 + 未来'],
      ['future', '未来']
    ].map(function (group) {
      var options = RANGES.map(function (minutes) {
        var value = group[0] + ':' + minutes;
        var selected = state.view === group[0] && Number(state.range) === minutes;
        return '<option value="' + value + '"' + (selected ? ' selected' : '') + '>' + group[1] + ' ' + label(minutes) + '</option>';
      }).join('');
      return '<optgroup label="' + group[1] + '">' + options + '</optgroup>';
    }).join('');
  }
  function selectedCompany() {
    if (!state.selected || !state.data) { return null; }
    var found = null;
    (state.data.companies || []).forEach(function (c) { if (String(c.id) === String(state.selected)) { found = c; } });
    return found;
  }
  function chartPath(values, w, h, pad) {
    values = (values || []).filter(function (v) { return v !== null && v !== undefined && isFinite(Number(v)); }).map(Number);
    if (values.length === 0) { values = [0, 0]; }
    if (values.length === 1) { values = [values[0], values[0]]; }
    var min = Math.min.apply(null, values), max = Math.max.apply(null, values);
    var span = Math.max(0.01, max - min);
    min -= span * 0.08; max += span * 0.08; span = max - min;
    var coords = values.map(function (v, i) {
      var x = pad + i * (w - pad * 2) / (values.length - 1);
      var y = h - pad - (v - min) / span * (h - pad * 2);
      return Math.round(x * 10) / 10 + ' ' + Math.round(y * 10) / 10;
    });
    return {
      line: 'M' + coords.join(' L'),
      area: 'M' + coords.join(' L') + ' L' + (w - pad) + ' ' + (h - pad) + ' L' + pad + ' ' + (h - pad) + ' Z',
      min: min, max: max, span: span
    };
  }
  function trendClass(values) {
    if (!values || values.length < 2) { return 'flat'; }
    return Number(values[values.length - 1]) >= Number(values[0]) ? 'up' : 'down';
  }
  function mainChart(values, fromTick, toTick, title, nowTick) {
    var w = 900, h = 260, pad = 38;
    var p = chartPath(values, w, h, pad);
    var grid = '';
    var nowX = null;
    if (toTick > fromTick && Number(nowTick) >= fromTick && Number(nowTick) <= toTick) {
      nowX = pad + (Number(nowTick) - fromTick) * (w - pad * 2) / Math.max(1, toTick - fromTick);
    }
    var futureBand = nowX !== null && nowX < w - pad
      ? '<rect class="market-future-band" x="' + nowX.toFixed(1) + '" y="' + pad + '" width="' + (w - pad - nowX).toFixed(1) + '" height="' + (h - pad * 2) + '"/>'
      : '';
    var nowLine = nowX !== null
      ? '<line class="market-now-line" x1="' + nowX.toFixed(1) + '" y1="' + pad + '" x2="' + nowX.toFixed(1) + '" y2="' + (h - pad) + '"/>'
      : '';
    for (var i = 0; i <= 4; i++) {
      var y = pad + (h - pad * 2) * i / 4;
      var val = p.max - p.span * i / 4;
      grid += '<line x1="' + pad + '" y1="' + y + '" x2="' + (w - pad) + '" y2="' + y + '" class="market-grid-line"/>' +
        '<text x="4" y="' + (y + 4) + '" class="market-axis-text">' + num(val, 1) + '</text>';
    }
    return '<div class="market-chart-title">' + esc(title) + '</div>' +
      '<svg class="market-admin-chart ' + trendClass(values) + '" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" role="img" aria-label="行情走势图">' +
      grid + futureBand + '<path class="area" d="' + p.area + '"/><path class="line" d="' + p.line + '"/>' + nowLine + '</svg>' +
      '<div class="mkt-axis"><span>' + esc(timeLabel(fromTick)) + '</span><span>现在 ' + esc(timeLabel(nowTick)) + ' · 最低 ' + num(p.min + p.span * 0.08, 2) + ' · 最高 ' + num(p.max - p.span * 0.08, 2) + '</span><span>' + esc(timeLabel(toTick)) + '</span></div>';
  }

  function load(keepFuture) {
    var host = state.host;
    host.innerHTML = '<div class="card"><div class="empty">天狗正在整理大盘数据…</div></div>';
    THP().api('admin.market_info', { range: state.range, view: state.view }, { method: 'GET' }).then(function (d) {
      state.data = d;
      if (state.selected && !selectedCompany()) { state.selected = null; }
      renderPage();
      if (state.selected) { loadFuture(keepFuture); }
    }).catch(function (e) {
      host.innerHTML = '<div class="notice err">' + esc(e.message) + '</div>';
    });
  }

  function renderPage() {
    var d = state.data;
    var c = selectedCompany();
    var chartValues = c ? (c.history || []) : ((d.index || {}).series || []);
    var viewLabel = d.view_label || '过去 1 小时 + 未来 1 小时';
    var chartTitle = (c ? c.name : '幻想乡大盘') + ' · ' + viewLabel;
    var change = c ? Number(c.stat.change_percent) : Number(d.index.change_percent);
    var cls = change >= 0 ? 'up' : 'down';
    var engine = d.engine || {};
    var intervention = d.intervention || {};
    var html =
      '<div class="card market-admin-head">' +
      '<div class="flex-between wrap gap12"><div><div class="card-title" style="margin:0"><span class="dot"></span>动态行情驾驶舱</div>' +
      '<div class="muted">每 3 分钟一个价格点；请求达到 6 分钟阈值时自动续写未来 1 小时。人工 / AI 点优先于算法。</div></div>' +
      '<div class="flex gap8 wrap admin-range-bar"><label>全局视图</label><select class="input" id="am-range">' + rangeOptions() + '</select>' +
      '<a class="btn ghost small" href="api.php?action=admin.market_export&range=' + state.range + '&view=' + encodeURIComponent(state.view) + '">导出 JSON</a>' +
      '<button class="btn ghost small" id="am-reload">刷新数据</button></div></div>' +
      '<div class="market-kpi-grid">' +
      '<div class="market-kpi"><span>幻想乡大盘</span><b>' + Number(d.index.value) + '</b><em class="' + cls + '">' + (change >= 0 ? '+' : '') + Number(change).toFixed(2) + '%</em></div>' +
      '<div class="market-kpi"><span>当前时间点</span><b>' + timeLabel(d.tick) + '</b><em>tick #' + d.tick + '</em></div>' +
      '<div class="market-kpi"><span>上次生成</span><b>' + String((engine && engine.tick !== undefined) ? '已加锁运行' : '空闲') + '</b><em>下次约 ' + esc(String(d.next_engine_at || '-').slice(11, 16)) + '</em></div>' +
      '<div class="market-kpi"><span>人工干预截止</span><b>' + esc(intervention.end_time || '无') + '</b><em>' + esc(intervention.source ? ('来源 ' + intervention.source) : '算法自动衔接') + '</em></div>' +
      '</div></div>' +
      '<div class="card mt12"><div class="flex-between wrap gap8"><div class="card-title" style="margin:0"><span class="dot"></span>走势总览</div>' +
      '<div class="muted">' + (c ? '点击公司表的同一行可返回大盘' : '点击下方公司行切换公司图，并打开未来干预') + '</div></div>' +
      '<div id="am-main-chart">' + mainChart(chartValues, d.tick_from, d.tick_to, chartTitle, d.tick) + '</div></div>' +
      '<div class="card mt12"><div class="card-title"><span class="dot"></span>公司行情与购买人数</div>' +
      '<div class="table-wrap"><table class="thp-table market-company-table"><thead><tr><th>公司</th><th>现价</th><th>日涨跌</th><th>购买人数</th><th>当前持有</th><th>今日成交</th><th>操作</th></tr></thead><tbody>';
    (d.companies || []).forEach(function (row) {
      var chg = Number(row.stat.change_percent) || 0;
      html += '<tr data-company="' + row.id + '" class="' + (String(state.selected) === String(row.id) ? 'selected' : '') + '">' +
        '<td><b>' + esc(row.name) + '</b>' + (row.is_default ? ' <span class="badge gray">内置</span>' : ' <span class="badge warn">游客创建</span>') +
        (row.owner_name ? '<div class="muted">创建者 ' + esc(row.owner_name) + '</div>' : '') + '</td>' +
        '<td><b class="pill-price">' + num(row.price) + '</b></td>' +
        '<td class="' + (chg >= 0 ? 'up' : 'down') + '"><b>' + (chg >= 0 ? '+' : '') + chg.toFixed(2) + '%</b></td>' +
        '<td><b>' + Number(row.buyers || 0) + '</b> 人</td><td>' + Number(row.holders || 0) + ' 人</td>' +
        '<td>' + Number(row.volume_qty || 0) + ' 股 / ' + Number(row.trades || 0) + ' 笔<div class="muted">' + Number(row.volume_amount || 0) + ' FDT</div></td>' +
        '<td><div class="flex gap8 wrap"><button class="btn small ghost" data-am-rename="' + row.id + '">改名</button>' +
        '<button class="btn small" data-am-future="' + row.id + '">未来干预</button></div></td></tr>';
    });
    html += '</tbody></table></div></div>' +
      '<div id="am-future-host"></div>' +
      '<div class="card mt12"><div class="card-title"><span class="dot"></span>股票盈亏排行榜</div>' +
      '<div class="muted">累计盈亏 = 已落袋 + 持仓浮动，不受消费、转账、借款影响；测试 / 封禁账号不参与。</div>' +
      rankTables(d.rank) + '</div>' +
      '<div class="card mt12"><div class="card-title"><span class="dot"></span>幻想乡资产榜（每 15 分钟更新）</div>' +
      assetRankTables(d.asset_rank) + '</div>' +
      '<div id="am-news-host"></div>';
    state.host.innerHTML = html;
    bindPage();
    if (window.THP_ADMIN_NEWS) {
      THP_ADMIN_NEWS.render(qs('#am-news-host'), d.companies || [], function () { load(false); });
    }
  }

  function bindPage() {
    qs('#am-range').addEventListener('change', function () {
      var parts = String(this.value || '').split(':');
      state.view = parts[0] === 'past' || parts[0] === 'future' ? parts[0] : 'both';
      state.range = parseInt(parts[1], 10) || 60;
      load(false);
    });
    qs('#am-reload').addEventListener('click', function () { load(true); });
    state.host.querySelectorAll('[data-company]').forEach(function (tr) {
      tr.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('button,a')) { return; }
        var id = tr.getAttribute('data-company');
        state.selected = String(state.selected) === String(id) ? null : id;
        updateSelectionUI();
      });
    });
    state.host.querySelectorAll('[data-am-rename]').forEach(function (el) {
      el.addEventListener('click', function (e) {
        e.stopPropagation();
        var id = el.getAttribute('data-am-rename');
        var company = null;
        state.data.companies.forEach(function (c) { if (String(c.id) === String(id)) { company = c; } });
        if (company) { renameCompany(company); }
      });
    });
    state.host.querySelectorAll('[data-am-future]').forEach(function (el) {
      el.addEventListener('click', function (e) {
        e.stopPropagation();
        state.selected = el.getAttribute('data-am-future');
        updateSelectionUI();
      });
    });
  }

  function updateSelectionUI() {
    var d = state.data;
    var c = selectedCompany();
    state.host.querySelectorAll('[data-company]').forEach(function (tr) {
      tr.classList.toggle('selected', !!c && String(tr.getAttribute('data-company')) === String(c.id));
    });
    var chartHost = qs('#am-main-chart');
    if (chartHost) {
      var values = c ? (c.history || []) : ((d.index || {}).series || []);
      var title = (c ? c.name : '幻想乡大盘') + ' · ' + (d.view_label || '过去 1 小时 + 未来 1 小时');
      chartHost.innerHTML = mainChart(values, d.tick_from, d.tick_to, title, d.tick);
    }
    var futureHost = qs('#am-future-host');
    if (futureHost) { futureHost.innerHTML = ''; }
    if (c) { loadFuture(); }
  }

  function rankTable(list, cls, empty) {
    list = Array.isArray(list) ? list : [];
    if (!list.length) { return '<div class="empty">' + esc(empty) + '</div>'; }
    var html = '<div class="table-wrap"><table class="thp-table"><thead><tr><th>名次</th><th>玩家</th><th>累计盈亏（FDT）</th><th>已落袋 / 浮动</th></tr></thead><tbody>';
    list.forEach(function (it, i) {
      html += '<tr><td>#' + (i + 1) + '</td><td><b>' + esc(it.username) + '</b> <span class="muted">#' + it.id + '</span></td>' +
        '<td class="' + cls + '"><b>' + signed(it.total) + ' FDT</b></td><td class="muted">' + signed(it.realized) + ' / ' + signed(it.unrealized) + '</td></tr>';
    });
    return html + '</tbody></table></div>';
  }
  function rankTables(rank) {
    rank = rank || { earn: [], lose: [] };
    return '<div class="mt12"><b>💰 赚得最多</b></div>' + rankTable(rank.earn, 'up', '暂无盈利玩家') +
      '<div class="mt12"><b>💸 亏得最多</b></div>' + rankTable(rank.lose, 'down', '暂无亏损玩家');
  }
  function assetRankTables(rank) {
    rank = rank || { top: [], bottom: [] };
    function table(title, list, cls) {
      if (!list || !list.length) { return '<div class="mt8"><b>' + title + '</b></div><div class="empty">暂无</div>'; }
      var html = '<div class="mt8"><b>' + title + '</b></div><div class="table-wrap"><table class="thp-table"><thead><tr><th>名次</th><th>玩家</th><th>净资产（FDT）</th></tr></thead><tbody>';
      list.forEach(function (it) {
        html += '<tr><td>#' + it.rank + '</td><td><b>' + esc(it.username) + '</b> <span class="muted">#' + it.id + '</span></td><td class="' + cls + '"><b>' + signed(it.net_assets) + ' FDT</b></td></tr>';
      });
      return html + '</tbody></table></div>';
    }
    return '<div class="muted">净资产 = FDT 余额 + 持股市值 - 欠款；测试与封禁账号不参与。</div>' +
      table('🏆 净资产前三', rank.top, 'up') + table('🕳️ 负资产倒三', rank.bottom, 'down') +
      '<div class="muted mt8">更新于 ' + esc(String(rank.generated_at || '-').replace('T', ' ').slice(0, 19)) + '</div>';
  }

  function renameCompany(company) {
    var m = THP().modal('<h3>修改公司名</h3><p class="muted">当前：<b>' + esc(company.name) + '</b>' + (company.is_default ? '（内置公司）' : '') + '</p>' +
      '<div class="field"><label>新名字（2-12 位中文 / 字母 / 数字 / 下划线）</label><input class="input" id="am-rename-name" maxlength="12" value="' + esc(company.name) + '"></div>' +
      '<div class="modal-actions"><button class="btn ghost" data-close>取消</button><button class="btn blue" id="am-rename-save">保存</button></div>');
    THP().qs('#am-rename-save', m.el).addEventListener('click', function () {
      var btn = this;
      var name = THP().qs('#am-rename-name', m.el).value.trim();
      if (!name || name === company.name) { m.close(); return; }
      THP().busy(btn, true);
      THP().api('admin.company_rename', { company_id: company.id, name: name }).then(function (r) {
        m.close();
        THP().toast('公司已改名为「' + r.name + '」', 'ok');
        load(true);
      }).catch(function (e) { THP().toast(e.message, 'err'); }).finally(function () { THP().busy(btn, false); });
    });
  }

  function loadFuture() {
    var company = selectedCompany();
    var host = qs('#am-future-host');
    if (!company || !host) { return; }
    host.innerHTML = '<div class="card mt12"><div class="empty">读取「' + esc(company.name) + '」的未来价格点…</div></div>';
    THP().api('admin.market_future', { company_id: company.id, hours: state.futureHours }, { method: 'GET' }).then(function (d) {
      state.future = d;
      state.future.pendingSource = 'manual';
      renderFutureEditor();
    }).catch(function (e) {
      host.innerHTML = '<div class="notice err">' + esc(e.message) + '</div>';
    });
  }

  function sourceBadge(src) {
    if (src === 'manual') { return '<span class="badge warn">人工</span>'; }
    if (src === 'ai') { return '<span class="badge ok">AI</span>'; }
    return '<span class="badge gray">算法</span>';
  }

  function renderFutureEditor() {
    var d = state.future;
    var host = qs('#am-future-host');
    if (!d || !host) { return; }
    var c = d.company;
    var rows = d.rows || [];
    var html = '<div class="card mt12 future-editor"><div class="flex-between wrap gap8"><div><div class="card-title" style="margin:0"><span class="dot"></span>' + esc(c.name) + ' · 未来走势干预</div>' +
      '<div class="muted">只允许修改当前时间之后的点。可拖动折线圆点、精确填表，或指定两个端点自动插值。AI 结果会先映射到编辑器，确认保存后才生效。</div></div>' +
      '<div class="flex gap8 wrap"><select class="input" id="am-future-hours">' +
      [1, 2, 3, 5].map(function (h) { return '<option value="' + h + '"' + (state.futureHours === h ? ' selected' : '') + '>未来 ' + h + ' 小时</option>'; }).join('') +
      '</select><button class="btn ghost small" id="am-future-reload">重新读取</button>' +
      '<button class="btn blue small" id="am-force-refresh">强制生成未来 ' + state.futureHours + 'h</button></div></div>';
    if (!rows.length) {
      html += '<div class="empty mt12">今天已经收盘，没有可修改的未来点。</div></div>';
      host.innerHTML = html;
      bindFutureHeader();
      return;
    }
    var cutoffOptions = rows.map(function (r) {
      return '<option value="' + r.tick + '"' + (rows[rows.length - 1].tick === r.tick ? ' selected' : '') + '>' + esc(r.time) + ' (tick ' + r.tick + ')</option>';
    }).join('');
    html += '<div class="future-toolbar mt12">' +
      '<div><label>人工干预截止</label><select class="input" id="am-cutoff">' + cutoffOptions + '</select></div>' +
      '<div class="future-toolbar-actions"><button class="btn" id="am-future-save">保存人工走势</button>' +
      '<button class="btn ghost" id="am-future-reset">恢复当前算法结果</button></div></div>' +
      '<div class="notice info">价格边界：' + num(c.min_price) + ' ~ ' + num(c.max_price) + '（基准价 ' + num(c.base_price) + ' 的三分之一到三倍）；每个 3 分钟点相对前一点最多变动 ' + d.rules.market_step_max_percent + '%。</div>' +
      '<div id="am-future-chart" class="future-chart-wrap"></div>' +
      '<div class="interp-panel"><b>两点自动插值</b><div class="interp-grid">' +
      '<label>起点<select class="input" id="am-int-start">' + rows.map(function (r, i) { return '<option value="' + i + '">' + esc(r.time) + '</option>'; }).join('') + '</select></label>' +
      '<label>起点价<input class="input" id="am-int-start-price" inputmode="decimal" value="' + num(rows[0].price) + '"></label>' +
      '<label>终点<select class="input" id="am-int-end">' + rows.map(function (r, i) { return '<option value="' + i + '"' + (i === rows.length - 1 ? ' selected' : '') + '>' + esc(r.time) + '</option>'; }).join('') + '</select></label>' +
      '<label>终点价<input class="input" id="am-int-end-price" inputmode="decimal" value="' + num(rows[rows.length - 1].price) + '"></label>' +
      '<button class="btn ghost" id="am-int-apply">线性填充</button></div></div>' +
      '<div class="future-table-wrap"><table class="thp-table future-table"><thead><tr><th>时间</th><th>tick</th><th>价格</th><th>来源</th></tr></thead><tbody>';
    rows.forEach(function (r, i) {
      html += '<tr data-frow="' + i + '"><td>' + esc(r.time) + '</td><td class="muted">' + r.tick + '</td>' +
        '<td><input class="input price-input" data-fprice="' + i + '" inputmode="decimal" value="' + num(r.price) + '"></td>' +
        '<td data-fsource="' + i + '">' + sourceBadge(r.source) + '</td></tr>';
    });
    html += '</tbody></table></div>' +
      '<div class="news-ai-box mt12"><div class="field" style="margin-top:0"><label>让 AI 修改未来走势（管理员描述方向）</label>' +
      '<textarea class="input" id="am-ai-direction" rows="3" maxlength="500" placeholder="例如：先缓慢阴跌 30 分钟，然后尾盘突然拉升，但不要超过基准价两倍"></textarea></div>' +
      '<button class="btn blue small" id="am-ai-run">🤖 AI 生成未来 ' + state.futureHours + 'h 建议</button></div></div>';
    host.innerHTML = html;
    bindFutureHeader();
    bindFutureEditor();
    updateFutureSaveButton();
    drawFutureChart();
  }

  function bindFutureHeader() {
    var sel = qs('#am-future-hours');
    if (sel) {
      sel.addEventListener('change', function () {
        state.futureHours = parseInt(this.value, 10) || 1;
        loadFuture();
      });
    }
    var reload = qs('#am-future-reload');
    if (reload) { reload.addEventListener('click', loadFuture); }
    var force = qs('#am-force-refresh');
    if (force) {
      force.addEventListener('click', function () {
        var btn = this;
        THP().busy(btn, true);
        THP().api('admin.market_refresh', { hours: state.futureHours }).then(function (r) {
          var generated = r.result && r.result.generated ? r.result.generated : 0;
          THP().toast('已强制续写未来 ' + state.futureHours + ' 小时（' + generated + ' 个算法点）', 'ok');
          load(true);
        }).catch(function (e) { THP().toast(e.message, 'err'); }).finally(function () { THP().busy(btn, false); });
      });
    }
  }

  function bindFutureEditor() {
    var rows = state.future.rows || [];
    state.host.querySelectorAll('[data-fprice]').forEach(function (input) {
      input.addEventListener('change', function () {
        var i = parseInt(input.getAttribute('data-fprice'), 10);
        var value = parseFloat(input.value);
        if (!isFinite(value)) { input.value = num(rows[i].price); return; }
        rows[i].price = clampPrice(rows[i], value);
        rows[i].dirty = true;
        enforceFutureStepLimits(i);
        input.value = num(rows[i].price);
        drawFutureChart();
      });
    });
    qs('#am-int-apply').addEventListener('click', function () {
      var start = parseInt(qs('#am-int-start').value, 10);
      var end = parseInt(qs('#am-int-end').value, 10);
      var p0 = parseFloat(qs('#am-int-start-price').value);
      var p1 = parseFloat(qs('#am-int-end-price').value);
      if (!isFinite(p0) || !isFinite(p1)) { THP().toast('请输入有效的起止价格', 'err'); return; }
      if (end <= start) { THP().toast('终点必须在起点之后', 'err'); return; }
      p0 = clampPrice(rows[start], p0);
      p1 = clampPrice(rows[end], p1);
      for (var i = start; i <= end; i++) {
        var ratio = (i - start) / (end - start);
        rows[i].price = clampPrice(rows[i], p0 + (p1 - p0) * ratio);
        rows[i].dirty = true;
      }
      enforceFutureStepLimits(start);
      drawFutureChart();
      THP().toast('已按两个端点线性填充这 ' + (end - start + 1) + ' 个价格点', 'ok');
    });
    qs('#am-future-reset').addEventListener('click', loadFuture);
    qs('#am-future-save').addEventListener('click', function () {
      var btn = this;
      var source = state.future.pendingSource === 'ai' ? 'ai' : 'manual';
      var cutoff = parseInt(qs('#am-cutoff').value, 10);
      var points = [];
      rows.forEach(function (r) {
        if (Number(r.tick) <= cutoff) { points.push({ tick: Number(r.tick), price: Number(r.price) }); }
      });
      if (!points.length) { THP().toast('截止点之前没有可保存的价格点', 'err'); return; }
      var title = source === 'ai' ? '保存 AI 建议？' : '保存人工走势？';
      var sourceText = source === 'ai' ? 'AI' : '人工';
      THP().confirm(title, '将把当前时间到截止点的 ' + points.length + ' 个点标记为' + sourceText + '干预。截止后算法会自动衔接；新闻与自动刷新不会覆盖这些点。', '确认保存', 'blue').then(function (yes) {
        if (!yes) { return; }
        THP().busy(btn, true);
        THP().api('admin.market_future_save', { company_id: state.future.company.id, points: points, source: source }).then(function (r) {
          THP().toast(sourceText + '干预已保存到 ' + r.cutoff_time + '（' + r.written + ' 个点）', 'ok');
          loadFuture();
        }).catch(function (e) { THP().toast(e.message, 'err'); }).finally(function () { THP().busy(btn, false); });
      });
    });
    qs('#am-ai-run').addEventListener('click', function () {
      var btn = this;
      var direction = qs('#am-ai-direction').value.trim();
      if (!direction) { THP().toast('先填写希望 AI 如何修改未来走势', 'err'); return; }
      THP().busy(btn, true);
      THP().api('admin.market_ai_future', { company_id: state.future.company.id, hours: state.futureHours, direction: direction }).then(function (r) {
        var applied = applyAiSuggestion(r.points || []);
        var result = qs('#am-ai-result');
        if (!result) {
          result = document.createElement('div');
          result.id = 'am-ai-result';
          result.className = 'notice info mt8';
          btn.parentNode.appendChild(result);
        }
        result.textContent = 'AI 已生成 ' + applied + ' 个待确认点，并映射到上方可编辑走势。请检查后点击「保存 AI 建议」。' + (r.comment ? ' 点评：' + r.comment : '');
        THP().toast('AI 建议已映射到编辑器，尚未写入服务器', 'ok');
      }).catch(function (e) { THP().toast(e.message, 'err'); }).finally(function () { THP().busy(btn, false); });
    });
  }

  function updateFutureSaveButton() {
    var btn = qs('#am-future-save');
    if (!btn || !state.future) { return; }
    btn.textContent = state.future.pendingSource === 'ai' ? '保存 AI 建议' : '保存人工走势';
  }

  function applyAiSuggestion(points) {
    if (!state.future) { return 0; }
    var rows = state.future.rows || [];
    var byTick = {};
    (points || []).forEach(function (p) {
      if (p && isFinite(Number(p.price))) { byTick[Number(p.tick)] = Number(p.price); }
    });
    var first = -1;
    var applied = 0;
    rows.forEach(function (row, index) {
      if (!Object.prototype.hasOwnProperty.call(byTick, Number(row.tick))) { return; }
      row.price = clampPrice(row, byTick[Number(row.tick)]);
      row.source = 'ai';
      row.dirty = true;
      if (first < 0) { first = index; }
      applied++;
    });
    if (!applied) { throw new Error('AI 建议没有匹配到当前可编辑的未来价格点'); }
    enforceFutureStepLimits(first);
    state.future.pendingSource = 'ai';
    rows.forEach(function (row, index) {
      var cell = qs('[data-fsource="' + index + '"]');
      if (cell) { cell.innerHTML = sourceBadge(row.source); }
      var input = qs('[data-fprice="' + index + '"]');
      if (input) { input.value = num(row.price); }
    });
    updateFutureSaveButton();
    drawFutureChart();
    return applied;
  }

  function clampPrice(row, value) {
    var c = state.future.company;
    return Math.round(Math.max(Number(c.min_price), Math.min(Number(c.max_price), Number(value))) * 100) / 100;
  }

  // 与后端一致：从被修改点开始逐点裁剪，每点相对前一点最多 ±market_step_max_percent。
  function enforceFutureStepLimits(startIndex) {
    var rows = state.future.rows || [];
    var pct = Number(state.future.rules && state.future.rules.market_step_max_percent) || 20;
    var maxStep = pct / 100;
    var previous = startIndex === 0
      ? Number(state.future.company.current_price)
      : Number(rows[Math.max(0, startIndex - 1)].price);
    for (var i = Math.max(0, startIndex); i < rows.length; i++) {
      var price = clampPrice(rows[i], rows[i].price);
      price = Math.round(Math.max(previous * (1 - maxStep), Math.min(previous * (1 + maxStep), price)) * 100) / 100;
      if (price !== Number(rows[i].price)) { rows[i].dirty = true; }
      rows[i].price = price;
      previous = price;
    }
  }

  function futureScale(rows) {
    var c = state.future.company;
    var values = rows.map(function (r) { return Number(r.price); }).concat([Number(c.current_price)]);
    var min = Math.min.apply(null, values), max = Math.max.apply(null, values);
    var span = Math.max(0.2, max - min);
    min -= span * 0.12; max += span * 0.12;
    return { min: min, max: max, span: max - min, w: 900, h: 280, l: 58, r: 18, t: 28, b: 30 };
  }
  function pointX(scale, index, total) { return scale.l + index * (scale.w - scale.l - scale.r) / Math.max(1, total - 1); }
  function pointY(scale, price) { return scale.t + (scale.max - Number(price)) / scale.span * (scale.h - scale.t - scale.b); }
  function rowPath(rows, scale) {
    return rows.map(function (r, i) { return (i === 0 ? 'M' : 'L') + pointX(scale, i, rows.length).toFixed(1) + ' ' + pointY(scale, r.price).toFixed(1); }).join(' ');
  }

  function drawFutureChart() {
    var box = qs('#am-future-chart');
    if (!box || !state.future) { return; }
    var rows = state.future.rows || [];
    if (!rows.length) { box.innerHTML = ''; return; }
    var scale = futureScale(rows);
    state.chartScale = scale;
    var d = rowPath(rows, scale);
    var area = d + ' L' + pointX(scale, rows.length - 1, rows.length) + ' ' + (scale.h - scale.b) + ' L' + pointX(scale, 0, rows.length) + ' ' + (scale.h - scale.b) + ' Z';
    var grid = '';
    for (var g = 0; g <= 4; g++) {
      var gy = scale.t + (scale.h - scale.t - scale.b) * g / 4;
      var price = scale.max - scale.span * g / 4;
      grid += '<line x1="' + scale.l + '" y1="' + gy.toFixed(1) + '" x2="' + (scale.w - scale.r) + '" y2="' + gy.toFixed(1) + '" class="market-grid-line"/>' +
        '<text x="4" y="' + (gy + 4).toFixed(1) + '" class="market-axis-text">' + num(price) + '</text>';
    }
    var dots = rows.map(function (r, i) {
      var src = r.source === 'manual' ? 'manual' : (r.source === 'ai' ? 'ai' : 'auto');
      return '<circle class="future-dot ' + src + (r.dirty ? ' dirty' : '') + '" data-fdot="' + i + '" cx="' + pointX(scale, i, rows.length).toFixed(1) + '" cy="' + pointY(scale, r.price).toFixed(1) + '" r="6"><title>' + esc(r.time + ' · ' + num(r.price) + ' · ' + src) + '</title></circle>';
    }).join('');
    var currentY = pointY(scale, state.future.company.current_price);
    box.innerHTML = '<svg class="future-chart" viewBox="0 0 ' + scale.w + ' ' + scale.h + '" preserveAspectRatio="none" aria-label="可拖动未来走势编辑器">' +
      grid + '<line class="future-current-line" x1="' + scale.l + '" y1="' + currentY.toFixed(1) + '" x2="' + (scale.w - scale.r) + '" y2="' + currentY.toFixed(1) + '"/>' +
      '<path class="future-area" d="' + area + '"/><path class="future-line" d="' + d + '"/>' + dots +
      '<text x="' + scale.l + '" y="' + (scale.h - 8) + '" class="market-axis-text">' + esc(rows[0].time) + '</text>' +
      '<text x="' + (scale.w - scale.r - 36) + '" y="' + (scale.h - 8) + '" class="market-axis-text">' + esc(rows[rows.length - 1].time) + '</text></svg>' +
      '<div class="future-chart-hint">拖动圆点修改价格 · 当前价虚线为 ' + num(state.future.company.current_price) + '</div>';
    bindFutureDrag(box.querySelector('svg'));
  }

  function updateFutureGeometry(svg) {
    var rows = state.future.rows || [];
    var scale = state.chartScale;
    if (!svg || !scale || !rows.length) { return; }
    var d = rowPath(rows, scale);
    var line = svg.querySelector('.future-line');
    var area = svg.querySelector('.future-area');
    if (line) { line.setAttribute('d', d); }
    if (area) { area.setAttribute('d', d + ' L' + pointX(scale, rows.length - 1, rows.length) + ' ' + (scale.h - scale.b) + ' L' + pointX(scale, 0, rows.length) + ' ' + (scale.h - scale.b) + ' Z'); }
    svg.querySelectorAll('[data-fdot]').forEach(function (dot) {
      var i = parseInt(dot.getAttribute('data-fdot'), 10);
      dot.setAttribute('cy', pointY(scale, rows[i].price).toFixed(1));
    });
    rows.forEach(function (r, i) {
      var input = qs('[data-fprice="' + i + '"]');
      if (input) { input.value = num(r.price); }
    });
  }

  function bindFutureDrag(svg) {
    if (!svg) { return; }
    var rows = state.future.rows || [];
    var scale = state.chartScale;
    svg.addEventListener('pointerdown', function (e) {
      var dot = e.target.closest ? e.target.closest('[data-fdot]') : null;
      if (!dot) { return; }
      state.dragIndex = parseInt(dot.getAttribute('data-fdot'), 10);
      if (svg.setPointerCapture) { try { svg.setPointerCapture(e.pointerId); } catch (err) {} }
      e.preventDefault();
    });
    svg.addEventListener('pointermove', function (e) {
      if (state.dragIndex < 0 || !scale) { return; }
      var rect = svg.getBoundingClientRect();
      var localY = (e.clientY - rect.top) * scale.h / Math.max(1, rect.height);
      var price = scale.max - ((localY - scale.t) / (scale.h - scale.t - scale.b)) * scale.span;
      rows[state.dragIndex].price = clampPrice(rows[state.dragIndex], price);
      rows[state.dragIndex].dirty = true;
      enforceFutureStepLimits(state.dragIndex);
      updateFutureGeometry(svg);
      e.preventDefault();
    });
    function finishDrag() {
      if (state.dragIndex < 0) { return; }
      state.dragIndex = -1;
      drawFutureChart();
    }
    svg.addEventListener('pointerup', finishDrag);
    svg.addEventListener('pointercancel', finishDrag);
  }

  window.THP_ADMIN_MARKET = {
    render: function (host) {
      state.host = host;
      state.future = null;
      state.dragIndex = -1;
      load(false);
    }
  };
})();
