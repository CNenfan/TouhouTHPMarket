/* 模拟股市页：3 分钟一跳，行情来自服务器动态引擎 */
(function () {
  'use strict';
  var VIEWS = window.THP_VIEWS || (window.THP_VIEWS = {});
  var ctx = {
    timer: null,
    content: null,
    data: null,
    range: 60,
    selected: null,
    companyQuery: '',
    companyPage: 1,
    companyPageSize: 6,
    newsQueue: [],
    newsShowing: false,
    newsBusy: false,
    lastTick: null,
    companyOrder: null,
    companyOrderTick: ''
  };
  var RANGES = [
    { v: 10, t: '过去 10 分钟' },
    { v: 30, t: '过去 30 分钟' },
    { v: 60, t: '过去 1 小时' },
    { v: 120, t: '过去 2 小时' },
    { v: 180, t: '过去 3 小时' },
    { v: 300, t: '过去 5 小时' },
    { v: 720, t: '过去 12 小时' }
  ];

  function fmtPrice(p) {
    if (p === null || p === undefined || typeof p !== 'number') { return '-'; }
    return String(Math.round(p * 100) / 100);
  }
  function fmtNum(n) {
    return Math.round(Number(n) || 0).toLocaleString('zh-CN');
  }
  function fmtSigned(n) {
    n = Number(n) || 0;
    return (n > 0 ? '+' : '') + Math.round(n).toLocaleString('zh-CN');
  }
  function rangeOptions() {
    return RANGES.map(function (r) {
      return '<option value="' + r.v + '"' + (r.v === ctx.range ? ' selected' : '') + '>' + r.t + '</option>';
    }).join('');
  }
  function trendClass(points) {
    if (!points || points.length < 2) { return 'flat'; }
    var d = points[points.length - 1] - points[0];
    return d > 0 ? 'up' : (d < 0 ? 'down' : 'flat');
  }
  function pathOf(points, w, h, pad) {
    if (!points || !points.length) { return { line: '', area: '', min: 0, max: 0 }; }
    if (points.length === 1) { points = [points[0], points[0]]; }
    pad = pad || 6;
    var min = Math.min.apply(null, points);
    var max = Math.max.apply(null, points);
    var span = Math.max(0.0001, max - min);
    var coords = [];
    points.forEach(function (v, i) {
      var x = pad + i * (w - pad * 2) / (points.length - 1);
      var y = h - pad - (v - min) / span * (h - pad * 2);
      coords.push(Math.round(x * 10) / 10 + ' ' + Math.round(y * 10) / 10);
    });
    var line = 'M' + coords.join(' L');
    var area = line + ' L' + (w - pad) + ' ' + (h - pad) + ' L' + pad + ' ' + (h - pad) + ' Z';
    return { line: line, area: area, min: min, max: max };
  }
  function miniSpark(points) {
    if (!points || points.length < 2) { return '<span class="muted">—</span>'; }
    var p = pathOf(points, 96, 30, 2);
    return '<svg class="spark-mini ' + trendClass(points) + '" viewBox="0 0 96 30" preserveAspectRatio="none">' +
      '<path class="area" d="' + p.area + '"/><path class="line" d="' + p.line + '"/></svg>';
  }
  function largeChart(points, opts) {
    opts = opts || {};
    var w = 640, h = 190;
    var p = pathOf(points, w, h, 14);
    var cls = trendClass(points);
    var grid = '';
    for (var i = 1; i <= 3; i++) {
      var y = 14 + (h - 28) * i / 4;
      grid += '<line x1="14" y1="' + y + '" x2="' + (w - 14) + '" y2="' + y + '" stroke="#f3e4e8" stroke-width="1" stroke-dasharray="4 5"/>';
    }
    return '<svg class="mkt-chart ' + cls + '" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" aria-label="走势图">' +
      grid + '<path class="area" d="' + p.area + '"/><path class="line" d="' + p.line + '"/>' +
      '</svg>' +
      '<div class="mkt-axis"><span>' + THP_esc(opts.fromLabel || '') + '</span><span>最低 ' + fmtPrice(p.min) + ' · 最高 ' + fmtPrice(p.max) + '</span><span>' + THP_esc(opts.toLabel || '') + '</span></div>';
  }
  function THP_esc(s) { return window.THP.esc(s); }
  function tickTime(tick) {
    var minutes = Math.max(0, Number(tick) || 0) * 3;
    var h = Math.floor(minutes / 60) % 24;
    var m = minutes % 60;
    return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
  }
  function selectedCompany(data) {
    if (!ctx.selected) { return null; }
    var found = null;
    (data.companies || []).forEach(function (c) { if (String(c.id) === String(ctx.selected)) { found = c; } });
    return found;
  }
  function chartPoints(data) {
    var c = selectedCompany(data);
    return c ? (data.trend[String(c.id)] || []) : (data.index.series || []);
  }
  function shuffleSeed(text) {
    var h = 2166136261;
    for (var i = 0; i < text.length; i++) {
      h ^= text.charCodeAt(i);
      h = Math.imul(h, 16777619);
    }
    return h >>> 0;
  }
  function seededRandom(seed) {
    var s = seed >>> 0;
    return function () {
      s = (s + 0x6D2B79F5) >>> 0;
      var t = s;
      t = Math.imul(t ^ (t >>> 15), t | 1);
      t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }
  function ensureCompanyOrder(data) {
    var companies = data.companies || [];
    var tickKey = String(data.date || '') + ':' + String(data.tick || 0);
    var valid = !!ctx.companyOrder && ctx.companyOrder.length === companies.length;
    if (valid) {
      var seen = {};
      ctx.companyOrder.forEach(function (id) { seen[String(id)] = true; });
      companies.forEach(function (company) { if (!seen[String(company.id)]) { valid = false; } });
    }
    if (ctx.companyOrderTick === tickKey && valid) { return; }
    var defaults = [];
    var visitors = [];
    companies.forEach(function (company) {
      if (company.is_default) { defaults.push(String(company.id)); }
      else { visitors.push(String(company.id)); }
    });
    defaults.sort(function (a, b) { return Number(a) - Number(b); });
    var random = seededRandom(shuffleSeed(tickKey));
    for (var i = visitors.length - 1; i > 0; i--) {
      var j = Math.floor(random() * (i + 1));
      var tmp = visitors[i];
      visitors[i] = visitors[j];
      visitors[j] = tmp;
    }
    ctx.companyOrder = defaults.concat(visitors);
    ctx.companyOrderTick = tickKey;
  }
  function filteredCompanies(data) {
    ensureCompanyOrder(data);
    var byId = {};
    (data.companies || []).forEach(function (company) { byId[String(company.id)] = company; });
    var list = ctx.companyOrder.map(function (id) { return byId[String(id)]; }).filter(Boolean);
    var query = ctx.companyQuery.trim().toLowerCase();
    if (!query) { return list; }
    return list.filter(function (company) {
      return String(company.name || '').toLowerCase().indexOf(query) !== -1 ||
        String(company.owner_name || '').toLowerCase().indexOf(query) !== -1;
    });
  }
  function companyRowHtml(company, data) {
    var THP = window.THP;
    var chg = Number(company.change_percent || 0);
    var cls = chg > 0 ? 'up' : (chg < 0 ? 'down' : 'muted');
    var trend = data.trend[String(company.id)] || [];
    var ownerHtml = (!company.is_default && company.owner_name) ? '<span class="own">由 <b>' + THP.esc(company.owner_name) + '</b> 创建</span>' : '';
    return '<div class="company-item mkt-company' + (String(ctx.selected) === String(company.id) ? ' selected' : '') + '" data-select="' + company.id + '" role="button" tabindex="0">' +
      '<div class="cname">' + THP.esc(company.name) + (company.mine ? ' <span class="badge warn">我的公司</span>' : '') + '</div>' +
      '<div class="company-meta"><span>购买 <b>' + company.buyers + '</b> 人</span><span>当前持有 <b>' + company.holders + '</b> 人</span>' + ownerHtml + '</div>' +
      '<div class="cspark">' + miniSpark(trend) + '</div>' +
      '<div class="price-box"><div class="price">' + fmtPrice(company.price) + '</div><div class="chg ' + cls + '">' + (chg > 0 ? '▲ ' : chg < 0 ? '▼ ' : '') + Math.abs(chg) + '%</div></div>' +
      '<div class="ops"><button class="btn small" data-buy="' + company.id + '">买</button><button class="btn small green" data-sell="' + company.id + '">卖</button>' +
      '<button class="btn small ghost" data-detail="' + company.id + '">详情</button>' + (company.mine ? '<button class="btn small ghost" data-rename="' + company.id + '">改名</button>' : '') + '</div></div>';
  }
  function bindCompanyRows(host, content, data) {
    var THP = window.THP;
    host.querySelectorAll('[data-select]').forEach(function (el) {
      function choose() {
        var id = el.getAttribute('data-select');
        ctx.selected = String(ctx.selected) === String(id) ? null : id;
        renderAll(content, data);
      }
      el.addEventListener('click', function (e) {
        if (e.target && e.target.closest && e.target.closest('button,input,select')) { return; }
        choose();
      });
      el.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); choose(); } });
    });
    host.querySelectorAll('[data-detail]').forEach(function (el) {
      el.addEventListener('click', function (e) { e.stopPropagation(); openCompanyDetail(el.getAttribute('data-detail')); });
    });
    host.querySelectorAll('[data-buy]').forEach(function (el) {
      el.addEventListener('click', function (e) { e.stopPropagation(); openTrade(content, data, el.getAttribute('data-buy'), 'buy'); });
    });
    host.querySelectorAll('[data-sell]').forEach(function (el) {
      el.addEventListener('click', function (e) { e.stopPropagation(); openTrade(content, data, el.getAttribute('data-sell'), 'sell'); });
    });
    host.querySelectorAll('[data-rename]').forEach(function (el) {
      el.addEventListener('click', function (e) { e.stopPropagation(); openRename(content, data, el.getAttribute('data-rename')); });
    });
  }
  function renderCompanyList(content, data) {
    var THP = window.THP;
    var host = THP.qs('#mkt-companies', content);
    if (!host) { return; }
    var list = filteredCompanies(data);
    var pages = Math.max(1, Math.ceil(list.length / ctx.companyPageSize));
    ctx.companyPage = Math.min(Math.max(1, ctx.companyPage), pages);
    var start = (ctx.companyPage - 1) * ctx.companyPageSize;
    var pageList = list.slice(start, start + ctx.companyPageSize);
    host.innerHTML = pageList.length
      ? pageList.map(function (company) { return companyRowHtml(company, data); }).join('')
      : '<div class="empty">' + (ctx.companyQuery ? '没有找到匹配的公司，换个关键词试试～' : '还没有公司') + '</div>';
    var count = THP.qs('#mkt-company-count', content);
    if (count) { count.textContent = '共 ' + list.length + ' 家'; }
    var pager = THP.qs('#mkt-company-pager', content);
    if (pager) {
      if (list.length <= ctx.companyPageSize) {
        pager.innerHTML = '';
      } else {
        pager.innerHTML = '<button class="btn ghost small" id="mkt-companies-prev"' + (ctx.companyPage <= 1 ? ' disabled' : '') + '>上一页</button>' +
          '<span class="muted">第 ' + ctx.companyPage + ' / ' + pages + ' 页</span>' +
          '<button class="btn ghost small" id="mkt-companies-next"' + (ctx.companyPage >= pages ? ' disabled' : '') + '>下一页</button>';
        THP.qs('#mkt-companies-prev', pager).addEventListener('click', function () { if (ctx.companyPage > 1) { ctx.companyPage--; renderCompanyList(content, data); } });
        THP.qs('#mkt-companies-next', pager).addEventListener('click', function () { if (ctx.companyPage < pages) { ctx.companyPage++; renderCompanyList(content, data); } });
      }
    }
    bindCompanyRows(host, content, data);
  }

  VIEWS.market = {
    render: function (content) {
      var THP = window.THP;
      if (ctx.timer) { ctx.timer.stop(); ctx.timer = null; }
      ctx.content = content;
      content.innerHTML = '<div class="card"><div class="empty">荷取正在搬运行情数据…</div></div>';
      function load(first) {
        return THP.api('market.state', { range: ctx.range }).then(function (data) {
          if (!document.body.contains(content)) { return; }
          var changed = !ctx.data || data.tick !== ctx.lastTick || (data.companies || []).length !== (ctx.data.companies || []).length;
          ctx.data = data;
          ctx.lastTick = data.tick;
          if (changed || first) { renderAll(content, data); }
          pollNews();
        }).catch(function (e) {
          if (!ctx.data) { content.innerHTML = '<div class="notice err">' + THP.esc(e.message) + '</div>'; }
        });
      }
      load(true);
      ctx.timer = THP.everyVisible(function () {
        if (!document.body.contains(content)) {
          if (ctx.timer) { ctx.timer.stop(); ctx.timer = null; }
          return;
        }
        return load(false);
      }, 28000, 32000, { immediate: false });
    }
  };

  function rankTable(list, cls, emptyText) {
    var THP = window.THP;
    list = Array.isArray(list) ? list : [];
    if (!list.length) { return '<div class="empty">' + THP.esc(emptyText) + '</div>'; }
    var html = '<div class="table-wrap"><table class="thp-table"><thead><tr><th>名次</th><th>玩家</th><th>累计盈亏（FDT）</th><th>落袋 / 浮动</th></tr></thead><tbody>';
    list.forEach(function (it, i) {
      var total = Number(it.total) || 0;
      html += '<tr><td><b>#' + (i + 1) + '</b></td><td><b>' + THP.esc(it.username) + '</b><div class="muted">ID #' + it.id + '</div></td>' +
        '<td class="' + cls + '"><b>' + fmtSigned(total) + '</b></td>' +
        '<td class="muted">' + fmtSigned(it.realized) + ' / ' + fmtSigned(it.unrealized) + '</td></tr>';
    });
    return html + '</tbody></table></div>';
  }

  function assetRankHtml(rank) {
    var THP = window.THP;
    rank = rank || { top: [], bottom: [] };
    function table(title, list, cls) {
      var html = '<div class="muted mt8"><b>' + title + '</b></div>';
      if (!list || !list.length) { return html + '<div class="empty">暂无</div>'; }
      html += '<div class="table-wrap"><table class="thp-table"><thead><tr><th>名次</th><th>玩家</th><th>净资产（FDT）</th></tr></thead><tbody>';
      list.forEach(function (it) {
        html += '<tr><td>#' + it.rank + '</td><td><b>' + THP.esc(it.username) + '</b> <span class="muted">#' + it.id + '</span></td><td class="' + cls + '"><b>' + fmtSigned(it.net_assets) + '</b></td></tr>';
      });
      return html + '</tbody></table></div>';
    }
    return '<div class="card"><div class="card-title"><span class="dot"></span>幻想乡资产榜（每 15 分钟更新）</div>' +
      '<div class="muted">净资产 = FDT 余额 + 持股市值 - 欠款。</div>' +
      table('🏆 净资产前三', rank.top, 'up') + table('🕳️ 负资产倒三', rank.bottom, 'down') +
      '<div class="muted mt8">更新于 ' + THP.esc(String(rank.generated_at || '').replace('T', ' ').slice(0, 19)) + '</div></div>';
  }

  function renderAll(content, data) {
    var THP = window.THP;
    var c = selectedCompany(data);
    var points = chartPoints(data);
    var chartTitle = c ? c.name : '幻想乡大盘';
    var chartValue = c ? fmtPrice(c.price) : String(data.index.value);
    var chartPct = c ? Number(c.change_percent || 0) : Number(data.index.change_percent || 0);
    var chartClass = chartPct >= 0 ? 'up' : 'down';
    var chartSub = c ? ('今日开盘 ' + fmtPrice(c.open) + ' · 持仓 ' + c.holders + ' 人 · 购买 ' + c.buyers + ' 人') : '按各公司基准价归一化后取几何平均';

    var holdingsHtml = '';
    Object.keys(data.me.holdings || {}).forEach(function (cid) {
      var h = data.me.holdings[cid];
      var company = null;
      data.companies.forEach(function (x) { if (String(x.id) === String(cid)) { company = x; } });
      if (!company) { return; }
      var value = (company.price || 0) * h.shares;
      var profit = value - h.cost_total;
      holdingsHtml += '<div class="holding-row" data-holding-detail="' + company.id + '" role="button" tabindex="0" title="查看 ' + THP.esc(company.name) + ' 的公司详情">' +
        '<div><b>' + THP.esc(company.name) + '</b> <span class="muted">' + h.shares + ' 股</span></div>' +
        '<div style="text-align:right">市值 <b class="pill-price">' + Math.round(value) + ' FDT</b>' +
        '<div class="muted ' + (profit >= 0 ? 'up' : 'down') + '">' + fmtSigned(profit) + ' FDT</div></div></div>';
    });
    if (!holdingsHtml) { holdingsHtml = '<div class="empty">还没有持股，去挑一家公司吧 ～</div>'; }

    var recentNews = data.recent_news || [];
    var newsHtml = '';
    recentNews.forEach(function (n) {
      newsHtml += '<div class="news-line" data-news-id="' + THP.esc(n.id) + '" role="button" tabindex="0" title="点击查看新闻详情" aria-label="查看新闻详情：' + THP.esc(n.title) + '">' +
        '<span>' + THP.esc(n.title) + '</span><span class="news-line-aside"><span class="muted">' + THP.esc(String(n.published_at || '').slice(5, 16)) + '</span><span class="news-chevron" aria-hidden="true">›</span></span></div>';
    });
    if (!newsHtml) {
      newsHtml = '<div class="empty">天狗记者还在寻找大新闻…</div>';
    } else {
      newsHtml = '<div class="tengu-feed" tabindex="0" aria-label="天狗快讯，可上下滑动查看">' + newsHtml + '</div>' +
        '<div class="tengu-hint muted">共 ' + recentNews.length + ' 条 · 点击查看详情' + (recentNews.length > 5 ? ' · 上下滑动' : '') + '</div>';
    }

    var loan = data.me.loan || { own_capital: 0, max_amount: 0, used_last_hour: 0, remaining_last_hour: 0, max_per_hour: 10, max_ratio_percent: 200 };
    var loanButtons = '';
    (data.rules.loan_ratios || [10, 30, 50, 100, 200]).forEach(function (ratio) {
      var amount = Math.floor(Number(loan.own_capital || 0) * ratio / 100);
      var disabled = (loan.remaining_last_hour <= 0 || amount < 1) ? ' disabled' : '';
      loanButtons += '<button class="btn ghost small" data-loan-amount="' + amount + '" data-loan-pct="' + ratio + '"' + disabled + '>借 ' + ratio + '% · ' + fmtNum(amount) + ' FDT</button>';
    });
    var pnl = data.pnl || { total: 0, realized: 0, unrealized: 0 };

    content.innerHTML =
      '<div class="card index-card mkt-chart-card">' +
      '<div class="flex-between wrap gap8"><div><div class="muted">' + THP.esc(chartTitle) + (c ? ' · <span class="mkt-link" id="back-index">返回大盘</span>' : '') + '</div>' +
      '<div class="index-value">' + chartValue + '</div><div class="' + chartClass + '" style="font-weight:800">' + (chartPct >= 0 ? '+' : '') + chartPct + '%</div></div>' +
      '<div class="mkt-chart-meta"><div>视图范围</div><select class="input" id="mkt-range">' + rangeOptions() + '</select><div class="muted mt8">' + THP.esc(chartSub) + '</div></div></div>' +
      largeChart(points, { fromLabel: tickTime(data.tick_from), toLabel: tickTime(data.tick) }) +
      '<div class="muted center mt8">股市每3分钟更新一次噢~🎵</div></div>' +

      '<div class="card"><div class="card-title"><span class="dot"></span>我的资产</div>' +
      '<div class="flex-between"><span>FDT 余额</span><b style="font-size:18px">' + fmtNum(data.me.balance) + ' FDT</b></div>' +
      '<div class="flex-between mt8"><span>股票累计盈亏</span><b style="font-size:18px" class="' + (pnl.total >= 0 ? 'up' : 'down') + '">' + fmtSigned(pnl.total) + ' FDT</b></div>' +
      '<div class="muted" style="text-align:right">已落袋 ' + fmtSigned(pnl.realized) + ' · 持仓浮动 ' + fmtSigned(pnl.unrealized) + ' FDT</div>' +
      (data.me.debt > 0 ? '<div class="debt-line"><span>当前欠款</span><span class="num">' + fmtNum(data.me.debt) + ' FDT</span></div><div class="notice warn mt8">欠款还清前不能消费、转让或创建公司；买卖股票和还款不受影响。</div>' : '') +
      '</div>' +

      '<div class="card"><div class="card-title"><span class="dot"></span>持股明细</div>' + holdingsHtml + '</div>' +

      '<div class="card"><div class="card-title"><span class="dot"></span>公司行情 <span class="muted" style="font-weight:400;font-size:12px">单位 FDT · 点击公司切换走势图 · 游客公司每 3 分钟轮换展示顺序</span></div>' +
      '<div class="company-tools"><input class="input" id="mkt-company-search" type="search" placeholder="搜索公司名 / 创建者" value="' + THP.esc(ctx.companyQuery) + '"><span class="company-count" id="mkt-company-count"></span></div>' +
      '<div id="mkt-companies"></div><div class="company-pager" id="mkt-company-pager"></div></div>' +

      '<div class="card"><div class="card-title"><span class="dot"></span>天狗快讯</div>' + newsHtml + '</div>' +

      '<div class="card"><div class="card-title"><span class="dot"></span>银行借贷</div>' +
      '<p class="muted">自有资金 = FDT 余额 − 未还欠款，不含股票。每滚动 1 小时最多借款 <b>' + loan.max_per_hour + '</b> 次，单次最多借自有资金的 <b>' + loan.max_ratio_percent + '%</b>。初始利率 <b>' + data.rules.loan_base_rate + '%</b>，之后每 ' + data.rules.loan_interest_minutes + ' 分钟增加 ' + data.rules.loan_rate_step + '%，最高 ' + data.rules.loan_max_rate + '%。</p>' +
      '<div class="loan-status"><span>自有资金 <b>' + fmtNum(loan.own_capital) + ' FDT</b></span><span>本小时额度 <b>' + loan.used_last_hour + ' / ' + loan.max_per_hour + '</b></span><span>单次上限 <b>' + fmtNum(loan.max_amount) + ' FDT</b></span></div>' +
      '<div class="amount-row"><input class="input" id="loan-amount" inputmode="numeric" placeholder="借款金额，最多 ' + fmtNum(loan.max_amount) + ' FDT" maxlength="7"><button class="btn" id="btn-loan"' + (loan.remaining_last_hour <= 0 || loan.max_amount < 1 ? ' disabled' : '') + '>借款</button></div>' +
      '<div class="flex gap8 wrap mt8">' + loanButtons + '</div>' +
      '<div class="amount-row mt12"><input class="input" id="repay-amount" inputmode="numeric" placeholder="输入还款金额，最多 ' + fmtNum(data.me.debt) + ' FDT" maxlength="7"><button class="btn gold" id="btn-repay">还款</button></div></div>' +

      '<div class="card"><div class="card-title"><span class="dot"></span>创建自己的公司（' + data.rules.company_cost + ' FDT）</div>' +
      '<p class="muted">每人限 1 家，名称 2-12 位中文 / 字母 / 数字 / 下划线。创建后可以改名，所有玩家都能交易。</p>' +
      '<div class="amount-row"><input class="input" id="co-name" maxlength="12" placeholder="公司名字"><button class="btn blue" id="btn-create-co">创建</button></div></div>' +

      '<div class="card"><div class="card-title"><span class="dot"></span>股票盈亏排行榜</div>' +
      '<div class="muted">累计盈亏 = 已落袋 + 持仓浮动，不受提现或消费影响。</div>' +
      '<div class="mt12"><b>💰 赚得最多</b></div>' + rankTable((data.rank || {}).earn, 'up', '还没有人赚到钱，等你来当股神～') +
      '<div class="mt12"><b>💸 亏得最多</b></div>' + rankTable((data.rank || {}).lose, 'down', '还没有人亏钱，大家都好稳～') + '</div>' +

      assetRankHtml(data.asset_rank) +
      '<div class="muted center mt12">持仓至少 ' + data.rules.holding_lock_minutes + ' 分钟 · 每 ' + data.rules.trade_throttle_window_minutes + ' 分钟最多交易 ' + data.rules.trade_throttle_count + ' 次 · 贷款每 1 小时最多 ' + loan.max_per_hour + ' 次 · 股市有风险，少女请理性 ♪</div>';

    renderCompanyList(content, data);
    bindMarket(content, data);
  }

  function bindMarket(content, data) {
    var THP = window.THP;
    var rangeSel = THP.qs('#mkt-range', content);
    if (rangeSel) {
      rangeSel.addEventListener('change', function () {
        ctx.range = parseInt(this.value, 10) || 60;
        VIEWS.market.render(content);
      });
    }
    var back = THP.qs('#back-index', content);
    if (back) {
      back.addEventListener('click', function () { ctx.selected = null; renderAll(content, data); });
    }
    var companySearch = THP.qs('#mkt-company-search', content);
    if (companySearch) {
      companySearch.addEventListener('input', function () {
        ctx.companyQuery = this.value.trim();
        ctx.companyPage = 1;
        renderCompanyList(content, data);
      });
    }
    content.querySelectorAll('[data-holding-detail]').forEach(function (el) {
      function open() { openCompanyDetail(el.getAttribute('data-holding-detail')); }
      el.addEventListener('click', open);
      el.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
      });
    });
    content.querySelectorAll('[data-loan-amount]').forEach(function (el) {
      el.addEventListener('click', function () { doLoan(content, data, parseInt(el.getAttribute('data-loan-amount'), 10)); });
    });
    content.querySelectorAll('[data-news-id]').forEach(function (el) {
      function open() { openNewsDetail(el.getAttribute('data-news-id')); }
      el.addEventListener('click', open);
      el.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
      });
    });
    var repayInput = THP.qs('#repay-amount', content);
    if (repayInput) {
      repayInput.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 7); });
      THP.qs('#btn-repay', content).addEventListener('click', function () {
        var amount = parseInt(repayInput.value, 10);
        if (!amount || amount < 1) { THP.toast('请输入还款金额', 'err'); return; }
        var btn = this;
        THP.busy(btn, true);
        THP.api('market.repay', { amount: amount }).then(function (r) {
          THP.setBalance(r.balance);
          THP.toast('已还款 ' + fmtNum(r.paid) + ' FDT，剩余欠款 ' + fmtNum(r.debt) + ' FDT', 'ok');
          VIEWS.market.render(content);
        }).catch(function (e) { THP.toast(e.message, 'err'); }).finally(function () { THP.busy(btn, false); });
      });
    }
    var loanInput = THP.qs('#loan-amount', content);
    var loanButton = THP.qs('#btn-loan', content);
    if (loanInput) { loanInput.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 7); }); }
    if (loanButton && loanInput) {
      loanButton.addEventListener('click', function () {
        var amount = parseInt(loanInput.value, 10);
        if (!amount || amount < 1) { THP.toast('请输入借款金额', 'err'); return; }
        doLoan(content, data, amount);
      });
    }
    THP.qs('#btn-create-co', content).addEventListener('click', function () {
      var name = THP.qs('#co-name', content).value.trim();
      if (!name) { THP.toast('先给公司起个名字吧', 'err'); return; }
      THP.confirm('创建公司', '将花费 ' + data.rules.company_cost + ' FDT 创建「' + name + '」，每人限 1 家。', '确认创建', 'blue').then(function (yes) {
        if (!yes) { return; }
        THP.api('market.create_company', { name: name }).then(function (r) {
          THP.setBalance(r.balance);
          THP.toast('公司「' + r.name + '」上市啦！', 'ok');
          VIEWS.market.render(content);
        }).catch(function (e) { THP.toast(e.message, 'err'); });
      });
    });
  }

  function openTrade(content, data, companyId, mode) {
    var THP = window.THP;
    var company = null;
    data.companies.forEach(function (c) { if (String(c.id) === String(companyId)) { company = c; } });
    if (!company || company.price === null) { THP.toast('暂时拿不到这只股票的价格', 'err'); return; }
    var holding = data.me.holdings[String(companyId)] || { shares: 0, cost_total: 0 };
    var price = Number(company.price);
    var maxQty = mode === 'buy' ? Math.floor((data.me.balance + 1e-9) / price) : holding.shares;
    while (mode === 'buy' && maxQty > 0 && Math.round(price * maxQty) > data.me.balance) { maxQty--; }
    var m = THP.modal(
      '<h3>' + (mode === 'buy' ? '买入' : '卖出') + '「' + THP.esc(company.name) + '」</h3>' +
      '<p class="muted">现价 <b>' + fmtPrice(price) + ' FDT</b> / 股 · 当前持有 ' + holding.shares + ' 股 · 余额 ' + data.me.balance + ' FDT</p>' +
      '<div class="field"><label>数量</label><input class="input" id="tq-qty" inputmode="numeric" value="1" maxlength="7"></div>' +
      '<div class="flex gap8 mt8 wrap"><button class="btn ghost small" data-q="1">1 股</button><button class="btn ghost small" data-q="5">5 股</button><button class="btn ghost small" data-q="10">10 股</button><button class="btn ghost small" data-q="max">全部</button></div>' +
      '<p class="mt12">预计' + (mode === 'buy' ? '花费' : '获得') + '：<b class="pill-price" id="tq-total">' + fmtPrice(price) + '</b> FDT</p>' +
      '<p class="muted" style="font-size:12px;margin-top:6px">买入后至少锁定 ' + data.rules.holding_lock_minutes + ' 分钟才能卖出；每 ' + data.rules.trade_throttle_window_minutes + ' 分钟最多交易 ' + data.rules.trade_throttle_count + ' 次。</p>' +
      '<div class="modal-actions"><button class="btn ghost" data-close>取消</button><button class="btn ' + (mode === 'buy' ? '' : 'green') + '" id="tq-ok">' + (mode === 'buy' ? '确认买入' : '确认卖出') + '</button></div>'
    );
    var qtyInput = THP.qs('#tq-qty', m.el);
    function updateTotal() {
      var qty = Math.max(0, parseInt(qtyInput.value, 10) || 0);
      var total = price * qty;
      var text = qty <= 0 ? '0' : (mode === 'buy' ? Math.round(total) : Math.floor(total));
      if (qty > 0 && total < 1) { text = '不足 1 FDT（至少 1 FDT）'; }
      THP.qs('#tq-total', m.el).textContent = text;
    }
    qtyInput.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 7); updateTotal(); });
    m.el.querySelectorAll('[data-q]').forEach(function (el) {
      el.addEventListener('click', function () { qtyInput.value = String(el.getAttribute('data-q') === 'max' ? Math.max(0, maxQty) : parseInt(el.getAttribute('data-q'), 10)); updateTotal(); });
    });
    updateTotal();
    THP.qs('#tq-ok', m.el).addEventListener('click', function () {
      var qty = parseInt(qtyInput.value, 10);
      if (!qty || qty < 1) { THP.toast('数量不合法', 'err'); return; }
      if (qty > maxQty) { THP.toast(mode === 'buy' ? 'FDT 不够买这么多股' : '没有这么多股票可以卖', 'err'); return; }
      if (price * qty < 1) { THP.toast('交易金额至少 1 FDT', 'err'); return; }
      var btn = this;
      THP.busy(btn, true);
      THP.api(mode === 'buy' ? 'market.buy' : 'market.sell', { company_id: companyId, qty: qty }).then(function (r) {
        m.close();
        THP.setBalance(r.balance);
        THP.toast((mode === 'buy' ? '买入成功' : '卖出成功') + ' @' + fmtPrice(r.price), 'ok');
        VIEWS.market.render(content);
        THP.receipt(mode === 'buy' ? '买入成交' : '卖出成交', [
          ['公司', r.company_name || company.name], ['方向', (mode === 'buy' ? '买入 ' : '卖出 ') + r.qty + ' 股'],
          ['成交价', fmtPrice(r.price) + ' FDT / 股'], [mode === 'buy' ? '支出' : '收入', (mode === 'buy' ? r.cost : r.proceeds) + ' FDT'],
          ['成交编号', '#' + r.trade_id], ['余额', r.balance + ' FDT']
        ], '如对成交有疑问，请凭成交编号联系现场管理员。');
      }).catch(function (e) { THP.toast(e.message, 'err'); }).finally(function () { THP.busy(btn, false); });
    });
  }

  function openCompanyDetail(companyId) {
    var THP = window.THP;
    THP.showLoading('天狗记者正在整理公司资料…');
    THP.api('market.company_detail', { company_id: companyId, range: ctx.range }).then(function (d) {
      THP.hideLoading();
      var path = d.history || [];
      var tradesHtml = '';
      (d.trades || []).forEach(function (t) {
        tradesHtml += '<div class="trade-line"><span>' + THP.fmtTime(t.created_at) + ' · ' + (t.side === 'buy' ? '买入' : '卖出') + ' ' + t.qty + ' 股 @' + fmtPrice(t.price) + '</span><b class="' + (t.side === 'buy' ? 'down' : 'up') + '">' + (t.side === 'buy' ? '-' : '+') + fmtNum(t.amount) + ' FDT</b></div>';
      });
      if (!tradesHtml) { tradesHtml = '<div class="empty">暂时没有成交记录</div>'; }
      var holding = d.my_holding.shares > 0 ? '<div class="stock-detail-grid">' +
        '<div><div class="k">持有股数</div><div class="v">' + d.my_holding.shares + ' 股</div></div>' +
        '<div><div class="k">持仓市值</div><div class="v">' + fmtNum(d.my_holding.market_value) + ' FDT</div></div>' +
        '<div><div class="k">已落袋盈亏</div><div class="v ' + (d.my_holding.realized >= 0 ? 'up' : 'down') + '">' + fmtSigned(d.my_holding.realized) + '</div></div>' +
        '<div><div class="k">持仓浮动</div><div class="v ' + (d.my_holding.unrealized >= 0 ? 'up' : 'down') + '">' + fmtSigned(d.my_holding.unrealized) + '</div></div></div>' : '<div class="notice info mt12">你还没有持有这家公司的股票。</div>';
      var m = THP.modal(
        '<h3>' + THP.esc(d.name) + '</h3><div class="muted">' + (d.is_default ? '内置公司' : '游客创建') + ' · 购买 ' + d.buyers + ' 人 · 当前持有 ' + d.holders + ' 人</div>' +
        '<div class="stock-cap-strip"><div><div class="k">单股总市值</div><div class="v">' + fmtNum(d.market_cap) + ' FDT</div></div><div class="cap-sub">全站当前持仓 ' + fmtNum(d.total_shares) + ' 股 × 现价 ' + fmtPrice(d.price) + ' FDT</div></div>' +
        largeChart(path, { fromLabel: tickTime(d.tick_from), toLabel: tickTime(d.tick) }) +
        '<div class="stock-detail-grid"><div><div class="k">现价</div><div class="v">' + fmtPrice(d.price) + ' FDT</div></div><div><div class="k">今日开盘</div><div class="v">' + fmtPrice(d.open) + ' FDT</div></div>' +
        '<div><div class="k">今日最低</div><div class="v">' + fmtPrice(d.low) + '</div></div><div><div class="k">今日最高</div><div class="v">' + fmtPrice(d.high) + '</div></div></div>' +
        holding + '<div class="card-title mt12"><span class="dot"></span>我的近期成交</div>' + tradesHtml +
        '<div class="modal-actions"><button class="btn" id="cd-buy">买入</button><button class="btn green" id="cd-sell">卖出</button><button class="btn ghost" data-close>关闭</button></div>'
      );
      THP.qs('#cd-buy', m.el).addEventListener('click', function () { m.close(); openTrade(ctx.content, ctx.data, companyId, 'buy'); });
      THP.qs('#cd-sell', m.el).addEventListener('click', function () { m.close(); openTrade(ctx.content, ctx.data, companyId, 'sell'); });
    }).catch(function (e) { THP.hideLoading(); THP.toast(e.message, 'err'); });
  }

  function openRename(content, data, companyId) {
    var THP = window.THP;
    var company = null;
    data.companies.forEach(function (c) { if (String(c.id) === String(companyId)) { company = c; } });
    if (!company) { return; }
    var m = THP.modal('<h3>给公司改名</h3><p class="muted">当前名字：<b>' + THP.esc(company.name) + '</b></p>' +
      '<div class="field"><label>新名字（2-12 位中文 / 字母 / 数字 / 下划线）</label><input class="input" id="rn-name" maxlength="12" value="' + THP.esc(company.name) + '"></div>' +
      '<div class="modal-actions"><button class="btn ghost" data-close>取消</button><button class="btn blue" id="rn-ok">保存</button></div>');
    THP.qs('#rn-ok', m.el).addEventListener('click', function () {
      var name = THP.qs('#rn-name', m.el).value.trim();
      if (!name || name === company.name) { m.close(); return; }
      var btn = this;
      THP.busy(btn, true);
      THP.api('market.rename_company', { company_id: companyId, name: name }).then(function (r) {
        m.close(); THP.toast('公司已改名为「' + r.name + '」', 'ok'); VIEWS.market.render(content);
      }).catch(function (e) { THP.toast(e.message, 'err'); }).finally(function () { THP.busy(btn, false); });
    });
  }

  function doLoan(content, data, amount) {
    var THP = window.THP;
    var loan = data.me.loan || { own_capital: 0, max_amount: 0, used_last_hour: 0, remaining_last_hour: 0, max_per_hour: 2, max_ratio_percent: 200, next_reset_at: '' };
    amount = Math.floor(Number(amount) || 0);
    if (loan.remaining_last_hour <= 0) {
      THP.toast('每 1 小时最多贷款 ' + loan.max_per_hour + ' 次，本小时额度已用完' + (loan.next_reset_at ? '，约 ' + String(loan.next_reset_at).slice(11, 16) + ' 后恢复' : ''), 'err');
      return;
    }
    if (amount < 1) { THP.toast('借款金额至少 1 FDT', 'err'); return; }
    if (amount > loan.max_amount) { THP.toast('单次最多借 ' + fmtNum(loan.max_amount) + ' FDT（自有资金的 ' + loan.max_ratio_percent + '%）', 'err'); return; }
    THP.confirm('向银行借钱', '本次借款 ' + fmtNum(amount) + ' FDT，到账后余额约 ' + fmtNum(Number(data.me.balance) + amount) + ' FDT。\n这是本滚动小时内的第 ' + (loan.used_last_hour + 1) + ' / ' + loan.max_per_hour + ' 次借款。\n\n规则：每 1 小时最多贷款 ' + loan.max_per_hour + ' 次；每次最多借自有资金的 ' + loan.max_ratio_percent + '%（当前为 ' + fmtNum(loan.max_amount) + ' FDT）。初始利率 ' + data.rules.loan_base_rate + '%，之后每 ' + data.rules.loan_interest_minutes + ' 分钟增加 ' + data.rules.loan_rate_step + '%，最高 ' + data.rules.loan_max_rate + '%。\n欠款还清前不能消费、转让或创建公司。', '确认借款').then(function (yes) {
      if (!yes) { return; }
      THP.api('market.loan', { amount: amount }).then(function (r) {
        THP.setBalance(r.balance); THP.toast('借到 ' + fmtNum(r.amount) + ' FDT', 'ok'); VIEWS.market.render(content);
      }).catch(function (e) { THP.toast(e.message, 'err'); });
    });
  }

  function openNewsDetail(newsId) {
    var THP = window.THP;
    var id = parseInt(newsId, 10);
    if (!id || ctx.newsDetailBusy) { return; }
    ctx.newsDetailBusy = true;
    var m = THP.modal('<h3>📰 天狗快讯</h3><div class="empty">天狗正在翻找采访稿…</div>');
    THP.api('news.detail', { id: id }).then(function (d) {
      var n = d.news || {};
      var sourceNames = { manual: '编辑部', ai: 'AI 天狗', auto: '自动播报', event: '随机事件', notice: '活动通知' };
      var tags = '';
      if (n.pinned) { tags += '<span class="badge warn">置顶</span>'; }
      (n.positive_companies || []).forEach(function (c) { tags += '<span class="badge ok">利好 · ' + THP.esc(c.name) + '</span>'; });
      (n.negative_companies || []).forEach(function (c) { tags += '<span class="badge err">利空 · ' + THP.esc(c.name) + '</span>'; });
      var impact = Number(n.impact_percent || 0);
      var impactHtml = impact > 0
        ? '<div class="notice info mt12">相关公司未来走势影响幅度：最高 ' + impact + '%</div>'
        : '';
      if (!document.body.contains(m.el)) { return; }
      m.el.innerHTML = '<h3>📰 ' + THP.esc(n.title || '天狗快讯') + '</h3>' +
        '<div class="notice-meta">发布于 ' + THP.esc(String(n.published_at || '').replace('T', ' ').slice(0, 16)) + ' · ' + THP.esc(sourceNames[n.source] || '天狗快讯') + '</div>' +
        (tags ? '<div class="news-detail-tags">' + tags + '</div>' : '') +
        '<div class="announcement news-detail-body">' + THP.esc(n.body || '这条新闻还没有正文。') + '</div>' + impactHtml +
        '<div class="modal-actions"><button class="btn" data-close>看完啦</button></div>';
      THP.qs('[data-close]', m.el).addEventListener('click', function () { m.close(); });
    }).catch(function (e) {
      if (!document.body.contains(m.el)) { return; }
      m.el.innerHTML = '<h3>📰 天狗快讯</h3><div class="notice err">' + THP.esc(e.message) + '</div>' +
        '<div class="modal-actions"><button class="btn ghost" data-close>关闭</button></div>';
      THP.qs('[data-close]', m.el).addEventListener('click', function () { m.close(); });
    }).finally(function () { ctx.newsDetailBusy = false; });
  }

  function showNextNews() {
    var THP = window.THP;
    if (ctx.newsShowing || ctx.newsQueue.length === 0) { return; }
    if (!ctx.content || !document.body.contains(ctx.content)) { return; }
    ctx.newsShowing = true;
    var item = ctx.newsQueue.shift();
    var m = THP.modal('<h3>📰 ' + THP.esc(item.title) + '</h3><div class="notice-meta">发布于 ' + THP.esc(String(item.published_at || '').replace('T', ' ').slice(0, 19)) + '</div>' +
      '<div class="announcement" style="white-space:pre-wrap">' + THP.esc(item.body) + '</div>' +
      (item.impact_percent ? '<div class="notice info mt12">相关公司未来走势可能受到影响：' + item.impact_percent + '%</div>' : '') +
      '<div class="modal-actions"><button class="btn" id="news-read">我知道了</button></div>',
      { maskClose: false, onClose: function () {
        ctx.newsShowing = false;
        THP.api('news.mark_read', { id: item.id }).catch(function () {}).then(function () { showNextNews(); });
      } });
    THP.qs('#news-read', m.el).addEventListener('click', function () { m.close(); });
  }

  function pollNews() {
    var THP = window.THP;
    if (ctx.newsBusy || !THP.state || !THP.state.user) { return; }
    ctx.newsBusy = true;
    THP.api('news.inbox', { kind: 'market', limit: 20 }).then(function (d) {
      var seen = {};
      ctx.newsQueue.forEach(function (n) { seen[n.id] = 1; });
      (d.items || []).forEach(function (n) { if (!seen[n.id]) { ctx.newsQueue.push(n); seen[n.id] = 1; } });
      showNextNews();
    }).catch(function () {}).finally(function () { ctx.newsBusy = false; });
  }
})();
