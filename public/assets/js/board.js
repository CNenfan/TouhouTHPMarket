(function () {
  'use strict';

  var stage = document.getElementById('board-stage');
  if (!stage) { return; }

  var track = document.getElementById('quote-track');
  var quoteScroll = document.getElementById('quote-scroll');
  var indexValue = document.getElementById('index-value');
  var indexChange = document.getElementById('index-change');
  var indexPoints = document.getElementById('index-points');
  var indexSpark = document.getElementById('index-spark');
  var quoteSummary = document.getElementById('quote-summary');
  var rankEarn = document.getElementById('rank-earn');
  var rankLose = document.getElementById('rank-lose');
  var rankTime = document.getElementById('rank-time');
  var newsTrack = document.getElementById('news-track');
  var clockTime = document.getElementById('clock-time');
  var clockDate = document.getElementById('clock-date');
  var liveChip = document.getElementById('live-chip');
  var liveText = document.getElementById('live-text');
  var boot = document.getElementById('board-boot');

  var POLL_MS = 20000;
  var SCROLL_PX_PER_SECOND = 25;
  var state = {
    data: null,
    prices: {},
    quoteSignature: '',
    newsSignature: '',
    quoteSetHeight: 0,
    scrollOffset: 0,
    loading: false,
    failed: false,
    lastLoadAt: 0,
    lastFrameAt: 0,
    booted: false
  };

  function pad2(value) {
    return value < 10 ? '0' + value : String(value);
  }

  function esc(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function numberValue(value) {
    var n = Number(value);
    return Number.isFinite(n) ? n : 0;
  }

  function fmtPrice(value) {
    if (value === null || value === undefined || value === '') { return '--'; }
    var n = Number(value);
    if (!Number.isFinite(n)) { return '--'; }
    return n.toFixed(2);
  }

  function fmtInt(value) {
    return Math.round(numberValue(value)).toLocaleString('zh-CN');
  }

  function fmtSigned(value) {
    var n = Math.round(numberValue(value));
    return (n > 0 ? '+' : '') + n.toLocaleString('zh-CN');
  }

  function fmtPercent(value) {
    var n = numberValue(value);
    return (n > 0 ? '+' : '') + n.toFixed(1) + '%';
  }

  function trendClass(change) {
    var n = numberValue(change);
    return n > 0.0001 ? 'up' : (n < -0.0001 ? 'down' : 'flat');
  }

  function trendArrow(change) {
    var cls = trendClass(change);
    return cls === 'up' ? '▲ ' : (cls === 'down' ? '▼ ' : '');
  }

  function parseClock(value) {
    if (!value) { return ''; }
    var direct = String(value).match(/(\d{2}):(\d{2})/);
    if (direct) { return direct[1] + ':' + direct[2]; }
    var dt = new Date(value);
    if (Number.isNaN(dt.getTime())) { return ''; }
    return pad2(dt.getHours()) + ':' + pad2(dt.getMinutes());
  }

  function parseDateKey(value) {
    if (!value) { return ''; }
    var direct = String(value).match(/(\d{4})-(\d{2})-(\d{2})/);
    if (direct) { return direct[1] + '.' + direct[2] + '.' + direct[3]; }
    return '';
  }

  function tickLabel(tick) {
    var minutes = Math.max(0, Math.round(numberValue(tick))) * 3;
    var h = Math.floor(minutes / 60) % 24;
    var m = minutes % 60;
    return pad2(h) + ':' + pad2(m);
  }

  function fitStage() {
    var scale = Math.min(window.innerWidth / 1920, window.innerHeight / 1080);
    stage.style.setProperty('--board-scale', String(Math.max(0.1, scale)));
  }

  function updateClock() {
    var now = new Date();
    var weekdays = ['日', '一', '二', '三', '四', '五', '六'];
    if (clockTime) {
      clockTime.textContent = pad2(now.getHours()) + ':' + pad2(now.getMinutes()) + ':' + pad2(now.getSeconds());
    }
    if (clockDate) {
      clockDate.textContent = now.getFullYear() + '.' + pad2(now.getMonth() + 1) + '.' + pad2(now.getDate()) + ' 周' + weekdays[now.getDay()];
    }
  }

  function chartPath(points, width, height, padding) {
    if (!Array.isArray(points)) { return null; }
    var values = points.map(Number).filter(Number.isFinite);
    if (!values.length) { return null; }
    if (values.length === 1) { values.push(values[0]); }
    var min = Math.min.apply(null, values);
    var max = Math.max.apply(null, values);
    var span = max - min;
    var flat = span < 0.000001;
    var innerW = width - padding * 2;
    var innerH = height - padding * 2;
    var coords = values.map(function (value, index) {
      var x = padding + index * innerW / (values.length - 1);
      var y = flat ? height / 2 : height - padding - (value - min) / span * innerH;
      return Math.round(x * 10) / 10 + ' ' + Math.round(y * 10) / 10;
    });
    var line = 'M' + coords.join(' L');
    return {
      line: line,
      area: line + ' L' + (width - padding) + ' ' + (height - padding) + ' L' + padding + ' ' + (height - padding) + ' Z'
    };
  }

  function miniSpark(points, change) {
    var path = chartPath(points, 180, 46, 2);
    if (!path) { return ''; }
    return '<svg class="spark-mini ' + trendClass(change) + '" viewBox="0 0 180 46" preserveAspectRatio="none" aria-hidden="true">' +
      '<path class="spark-area" d="' + path.area + '"/><path class="spark-line" d="' + path.line + '"/></svg>';
  }

  function renderIndex(data) {
    var index = data.index || {};
    var value = numberValue(index.value);
    var change = numberValue(index.change_percent);
    if (indexValue) { indexValue.textContent = fmtInt(value); }
    if (indexChange) {
      indexChange.className = 'index-change ' + trendClass(change);
      indexChange.textContent = trendArrow(change) + fmtPercent(change);
    }
    if (indexPoints) {
      indexPoints.textContent = 'OPEN ' + fmtInt(index.open || 1000);
    }
    if (indexSpark) {
      var path = chartPath(index.series || [], 920, 76, 3);
      indexSpark.className = 'index-spark ' + trendClass(change);
      indexSpark.innerHTML = path
        ? '<svg viewBox="0 0 920 76" preserveAspectRatio="none" aria-hidden="true"><path class="spark-area" d="' + path.area + '"/><path class="spark-line" d="' + path.line + '"/></svg>'
        : '';
    }
  }

  function companyHtml(company, previousPrices) {
    var id = String(company.id || '');
    var oldPrice = previousPrices[id];
    var price = company.price === null || company.price === undefined ? null : Number(company.price);
    var change = numberValue(company.change_percent);
    var flashClass = '';
    if (oldPrice !== undefined && price !== null && Number.isFinite(Number(oldPrice))) {
      if (price > Number(oldPrice) + 0.000001) { flashClass = ' flash-up'; }
      else if (price < Number(oldPrice) - 0.000001) { flashClass = ' flash-down'; }
    }
    var owner = String(company.owner_name || '');
    var meta = 'THP-' + String(id).padStart(4, '0');
    if (owner) {
      meta += ' · 创建者 ' + esc(owner);
    }
    return '<div class="quote-row' + flashClass + '">' +
      '<div class="company-cell"><div class="company-name">' + esc(company.name || '未命名公司') + '</div>' +
      '<div class="company-owner">' + meta + '</div></div>' +
      '<div class="spark-cell">' + miniSpark(company.history || [], change) + '</div>' +
      '<div class="price-cell">' + fmtPrice(price) + '</div>' +
      '<div><span class="change-pill ' + trendClass(change) + '">' + trendArrow(change) + fmtPercent(change) + '</span></div>' +
      '<div class="count-cell">' + fmtInt(company.buyers) + '<em>人</em></div>' +
      '<div class="count-cell">' + fmtInt(company.holders) + '<em>人</em></div>' +
      '</div>';
  }

  function updateQuoteSummary(companies) {
    if (!quoteSummary) { return; }
    var up = 0;
    var down = 0;
    companies.forEach(function (company) {
      var change = numberValue(company.change_percent);
      if (change > 0.0001) { up++; }
      else if (change < -0.0001) { down++; }
    });
    quoteSummary.textContent = companies.length + ' 支 · ' + up + ' 涨 · ' + down + ' 跌';
  }

  function renderQuotes(companies) {
    if (!track) { return; }
    if (!companies.length) {
      track.innerHTML = '<div class="quote-placeholder">暂无行情</div>';
      state.quoteSetHeight = 0;
      return;
    }
    var rows = companies.map(function (company) { return companyHtml(company, state.prices); }).join('');
    var viewportHeight = quoteScroll ? quoteScroll.clientHeight : 648;
    var setHeight = companies.length * 96;
    var copies = Math.max(2, Math.ceil(viewportHeight / Math.max(1, setHeight)) + 1);
    var html = '';
    for (var i = 0; i < copies; i++) {
      html += '<div class="quote-set"' + (i > 0 ? ' aria-hidden="true"' : '') + '>' + rows + '</div>';
    }
    track.innerHTML = html;
    var firstSet = track.querySelector('.quote-set');
    state.quoteSetHeight = firstSet ? firstSet.getBoundingClientRect().height : 0;
    if (state.quoteSetHeight > 0) {
      state.scrollOffset = state.scrollOffset % state.quoteSetHeight;
    } else {
      state.scrollOffset = 0;
    }
    track.style.transform = 'translate3d(0,' + (-state.scrollOffset) + 'px,0)';
    var nextPrices = {};
    companies.forEach(function (company) {
      nextPrices[String(company.id)] = company.price === null || company.price === undefined ? null : Number(company.price);
    });
    state.prices = nextPrices;
    updateQuoteSummary(companies);
  }

  function rankListHtml(items, kind) {
    if (!Array.isArray(items) || !items.length) {
      return '<div class="rank-empty">暂无</div>';
    }
    return items.map(function (item, index) {
      var total = numberValue(item.total);
      var realized = numberValue(item.realized);
      var unrealized = numberValue(item.unrealized);
      return '<div class="rank-row">' +
        '<div class="rank-no">' + pad2(index + 1) + '</div>' +
        '<div class="rank-person"><div class="rank-name">' + esc(item.username || '匿名') + '</div>' +
        '<div class="rank-id">UID ' + esc(item.id || '') + '</div></div>' +
        '<div class="rank-value"><b>' + (kind === 'lose' ? '' : '') + fmtSigned(total) + '</b>' +
        '<small>已落袋 ' + fmtSigned(realized) + ' · 浮动 ' + fmtSigned(unrealized) + '</small></div>' +
        '</div>';
    }).join('');
  }

  function renderRanks(rank) {
    rank = rank || {};
    var earn = Array.isArray(rank.earn) ? rank.earn : [];
    var lose = Array.isArray(rank.lose) ? rank.lose : [];
    if (rankEarn) { rankEarn.innerHTML = rankListHtml(earn, 'earn'); }
    if (rankLose) { rankLose.innerHTML = rankListHtml(lose, 'lose'); }
    if (rankTime) {
      rankTime.textContent = parseClock(rank.generated_at) || tickLabel(state.data ? state.data.tick : 0);
    }
  }

  function renderNews(news) {
    if (!newsTrack) { return; }
    var items = Array.isArray(news) ? news.slice(0, 30) : [];
    if (!items.length) {
      items = [{ title: '幻想乡交易所行情同步中', published_at: '' }];
    }
    var repeat = Math.max(1, Math.ceil(9 / items.length));
    var oneGroup = '';
    for (var r = 0; r < repeat; r++) {
      oneGroup += items.map(function (item) {
        var time = parseClock(item.published_at);
        return '<span class="ticker-item">' + esc(item.title || '') +
          (time ? '<i class="ticker-time">' + time + '</i>' : '') + '</span>';
      }).join('');
    }
    newsTrack.innerHTML = '<div class="ticker-group">' + oneGroup + '</div><div class="ticker-group" aria-hidden="true">' + oneGroup + '</div>';
    var duration = Math.max(48, Math.min(125, items.length * 4.2));
    newsTrack.style.animationDuration = duration + 's';
  }

  function hideBoot() {
    if (!boot || state.booted) { return; }
    state.booted = true;
    boot.classList.add('hide');
  }

  function markLive() {
    if (!liveChip) { return; }
    liveChip.classList.remove('retry');
    if (liveText) { liveText.textContent = 'LIVE'; }
  }

  function markRetry() {
    if (!liveChip) { return; }
    liveChip.classList.add('retry');
    if (liveText) { liveText.textContent = 'RETRY'; }
    if (!state.booted && boot) {
      var label = boot.querySelector('span');
      if (label) { label.textContent = '行情连接中断 · 重试中…'; }
    }
  }

  function applyData(data) {
    var companies = Array.isArray(data.companies) ? data.companies : [];
    var quoteSignature = companies.map(function (company) {
      return [company.id, company.name, company.price, company.prev, company.buyers, company.holders, company.owner_name].join('|');
    }).join(';');
    if (quoteSignature !== state.quoteSignature) {
      state.quoteSignature = quoteSignature;
      renderQuotes(companies);
    } else {
      updateQuoteSummary(companies);
    }

    renderIndex(data);
    renderRanks(data.rank);

    var newsSignature = (data.recent_news || []).map(function (item) { return item.id; }).join(',');
    if (newsSignature !== state.newsSignature) {
      state.newsSignature = newsSignature;
      renderNews(data.recent_news || []);
    }
    hideBoot();
    markLive();
  }

  function load() {
    if (state.loading || document.hidden) { return; }
    state.loading = true;
    state.lastLoadAt = Date.now();
    fetch('api.php?action=market.state&range=60', {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Accept': 'application/json' }
    }).then(function (response) {
      return response.json().then(function (payload) {
        return { response: response, payload: payload };
      });
    }).then(function (result) {
      if (!result.response.ok || !result.payload || !result.payload.ok) {
        throw new Error((result.payload && result.payload.error) || '行情读取失败');
      }
      state.data = result.payload.data || {};
      state.failed = false;
      applyData(state.data);
    }).catch(function () {
      state.failed = true;
      markRetry();
      if (!state.booted) {
        window.setTimeout(load, 3500);
      }
    }).finally(function () {
      state.loading = false;
    });
  }

  function scrollFrame(now) {
    if (!state.lastFrameAt) { state.lastFrameAt = now; }
    var elapsed = Math.min(80, now - state.lastFrameAt) / 1000;
    state.lastFrameAt = now;
    if (!document.hidden && track && state.quoteSetHeight > 0) {
      state.scrollOffset += SCROLL_PX_PER_SECOND * elapsed;
      if (state.scrollOffset >= state.quoteSetHeight) {
        state.scrollOffset -= state.quoteSetHeight;
      }
      track.style.transform = 'translate3d(0,' + (-state.scrollOffset) + 'px,0)';
    }
    window.requestAnimationFrame(scrollFrame);
  }

  fitStage();
  updateClock();
  window.addEventListener('resize', fitStage);
  window.addEventListener('visibilitychange', function () {
    if (!document.hidden && Date.now() - state.lastLoadAt > POLL_MS) {
      load();
    }
  });
  window.setInterval(updateClock, 1000);
  window.setInterval(load, POLL_MS);
  window.requestAnimationFrame(scrollFrame);
  load();
})();
