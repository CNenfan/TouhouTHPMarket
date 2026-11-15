/* ============================================================
   芙湘东方高校联合THP活动网站 - 前端核心
   ============================================================ */
(function () {
  'use strict';

  var BOOT = window.__THP_BOOT__ || {};
  var state = {
    user: BOOT.user || null,
    settings: BOOT.settings || {},
    version: BOOT.version || '',
    pendingCode: BOOT.initial_code || '',
    base_url: BOOT.base_url || '',
    route: 'home',
    tokenTab: 'get',
    adminTab: 'overview',
    quizCode: '',
    collectCode: '',
    marketMinute: null,
    marketTs: 0,
    marketTrend: null
  };
  var views = window.THP_VIEWS || (window.THP_VIEWS = {});
  var LOADING_PHRASES = ['少女祈祷中…', '灵梦正在赶来的路上…', '魔理沙偷走了重要的数据…', '紫大人正在搬运隙间…', '琪露诺掰着手指算数中…', '帕秋莉正在翻魔导书…'];
  var loadingTimer = null;
  var deviceCache = null;
  var noticeQueue = [];
  var noticeShowing = false;
  var noticePollBusy = false;

  // 浏览器无法读取 MAC；用 localStorage + 服务端 Cookie 的持久设备 ID，
  // 配合设备指纹做“一机一号”的尽力而为限制。
  function getDeviceId() {
    if (deviceCache) { return deviceCache; }
    var bootId = String(BOOT.device_id || '');
    var id = '';
    try { id = String(localStorage.getItem('thp_device_id') || ''); } catch (e) {}
    if (!/^[a-f0-9]{32,64}$/i.test(id)) { id = /^[a-f0-9]{32,64}$/i.test(bootId) ? bootId : ''; }
    if (!id) {
      id = '';
      if (window.crypto && window.crypto.getRandomValues) {
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        for (var i = 0; i < bytes.length; i++) {
          var h = bytes[i].toString(16);
          id += h.length < 2 ? ('0' + h) : h;
        }
      } else {
        for (var j = 0; j < 32; j++) { id += Math.floor(Math.random() * 16).toString(16); }
      }
    }
    id = id.toLowerCase();
    try { localStorage.setItem('thp_device_id', id); } catch (e) {}
    deviceCache = id;
    return id;
  }

  function deviceMeta() {
    var tz = '';
    try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
    return {
      screen: (window.screen ? [screen.width, screen.height, screen.colorDepth].join('x') : ''),
      language: navigator.language || '',
      timezone: tz,
      platform: navigator.platform || '',
      hardware: navigator.hardwareConcurrency || 0
    };
  }

  // ---------- 基础工具 ----------
  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function fmtTime(s) {
    if (!s) { return ''; }
    var t = String(s);
    return t.length >= 16 ? t.substr(5, 11) : t;
  }
  function isAdmin() { return !!(state.user && state.user.role === 'admin'); }
  function qs(sel, el) { return (el || document).querySelector(sel); }
  // 可见性感知轮询：后台标签页不发请求，回到前台立即刷新；间隔带随机抖动。
  function everyVisible(fn, minDelay, maxDelay, opts) {
    opts = opts || {};
    var min = Math.max(1000, Number(minDelay) || 30000);
    var max = Math.max(min, Number(maxDelay) || min);
    var timer = null;
    var busy = false;
    var stopped = false;
    function delay() { return min + Math.floor(Math.random() * (max - min + 1)); }
    function schedule() {
      if (stopped || document.visibilityState === 'hidden') { timer = null; return; }
      clearTimeout(timer);
      timer = setTimeout(run, delay());
    }
    function run() {
      if (stopped || busy || document.visibilityState === 'hidden') { return; }
      busy = true;
      Promise.resolve().then(fn).catch(function () {}).finally(function () {
        busy = false;
        schedule();
      });
    }
    function visibilityChanged() {
      if (stopped) { return; }
      clearTimeout(timer);
      timer = null;
      if (document.visibilityState === 'hidden') { return; }
      if (!busy) { run(); } else { schedule(); }
    }
    document.addEventListener('visibilitychange', visibilityChanged);
    if (opts.immediate) { run(); } else { schedule(); }
    return {
      stop: function () {
        stopped = true;
        clearTimeout(timer);
        timer = null;
        document.removeEventListener('visibilitychange', visibilityChanged);
      },
      now: function () {
        if (stopped || busy) { return; }
        clearTimeout(timer);
        timer = null;
        run();
      }
    };
  }

  function requestId() {
    var out = '';
    try {
      var bytes = new Uint8Array(16);
      window.crypto.getRandomValues(bytes);
      for (var i = 0; i < bytes.length; i++) {
        var h = bytes[i].toString(16);
        out += h.length < 2 ? ('0' + h) : h;
      }
    } catch (e) {
      out = Date.now().toString(16) + Math.random().toString(16).slice(2) + Math.random().toString(16).slice(2);
    }
    return out.slice(0, 48);
  }

  // ---------- API ----------
  function api(action, data, opts) {
    opts = opts || {};
    var method = opts.method || ((data === undefined || data === null) ? 'GET' : 'POST');
    var url = 'api.php?action=' + encodeURIComponent(action);
    var init = { method: method, headers: { 'X-Thp': '1' }, credentials: 'same-origin' };
    if (method === 'GET' && data) {
      var parts = [];
      Object.keys(data).forEach(function (k) { parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k])); });
      if (parts.length) { url += '&' + parts.join('&'); }
    }
    if (method === 'POST') {
      var idem = (data && data.idempotency_key) ? String(data.idempotency_key) : requestId();
      if (opts.formData) {
        if (data && typeof data.append === 'function' && !data.has('idempotency_key')) { data.append('idempotency_key', idem); }
        init.body = data;
      } else {
        var payload = Object.assign({}, data || {});
        if (!payload.idempotency_key) { payload.idempotency_key = idem; }
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(payload);
      }
    }
    function send(retried) {
      return fetch(url, init).catch(function (networkError) {
        if (method === 'POST' && !retried) {
          return new Promise(function (resolve) { setTimeout(resolve, 450); }).then(function () { return send(true); });
        }
        throw networkError;
      }).then(function (resp) {
        if (method === 'POST' && !retried && (resp.status === 502 || resp.status === 503 || resp.status === 504)) {
          return new Promise(function (resolve) { setTimeout(resolve, 450); }).then(function () { return send(true); });
        }
        return resp;
      });
    }
    return send(false).then(function (resp) {
      return resp.json().catch(function () { throw new Error('服务器响应异常，请稍后再试'); }).then(function (j) {
        if (!j || j.ok !== true) {
          var err = new Error((j && j.error) ? j.error : '操作失败');
          err.status = resp.status;
          err.payload = j;
          throw err;
        }
        return j.data;
      });
    }).catch(function (e) {
      if (!e.status) {
        var busyError = new Error('网络好像被隙间吞掉了，已自动重试一次；请确认操作结果后再试。');
        busyError.network = true;
        throw busyError;
      }
      throw e;
    });
  }

  function apiSafe(action, data, opts) {
    return api(action, data, opts).catch(function (e) {
      if (e.status === 401) { forceAuth(); }
      throw e;
    });
  }

  function forceAuth() {
    noticeQueue = [];
    noticePollBusy = false;
    state.user = null;
    render();
  }

  // ---------- Toast / Modal / Loading ----------
  function toast(msg, type) {
    var rootEl = document.getElementById('toast-root');
    var el = document.createElement('div');
    el.className = 'toast ' + (type || 'info');
    var icon = type === 'ok' ? '✨' : (type === 'err' ? '💧' : '🌸');
    el.innerHTML = '<span>' + icon + '</span><span>' + esc(msg) + '</span>';
    rootEl.appendChild(el);
    setTimeout(function () { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; }, 2400);
    setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, 2800);
  }

  function modal(html, opts) {
    opts = opts || {};
    var rootEl = document.getElementById('modal-root');
    var mask = document.createElement('div');
    mask.className = 'modal-mask';
    var box = document.createElement('div');
    box.className = 'modal';
    box.innerHTML = html;
    mask.appendChild(box);
    rootEl.appendChild(mask);
    var closed = false;
    function close() {
      if (closed) { return; }
      closed = true;
      if (mask.parentNode) { mask.parentNode.removeChild(mask); }
      if (typeof opts.onClose === 'function') { setTimeout(function () { opts.onClose(); }, 0); }
    }
    mask.addEventListener('click', function (e) { if (e.target === mask && opts.maskClose !== false) { close(); } });
    var closer = qs('[data-close]', box);
    if (closer) { closer.addEventListener('click', close); }
    return { el: box, mask: mask, close: close };
  }

  function confirmBox(title, text, okText, okClass) {
    return new Promise(function (resolve) {
      var m = modal('<h3>' + esc(title) + '</h3><p class="muted" style="white-space:pre-wrap">' + esc(text || '') + '</p>' +
        '<div class="modal-actions"><button class="btn ghost" data-act="no">再想想</button><button class="btn ' + (okClass || '') + '" data-act="yes">' + esc(okText || '确认') + '</button></div>',
        { maskClose: true });
      qs('[data-act="no"]', m.el).addEventListener('click', function () { m.close(); resolve(false); });
      qs('[data-act="yes"]', m.el).addEventListener('click', function () { m.close(); resolve(true); });
    });
  }

  function showReceipt(title, rows, note) {
    var body = '';
    (rows || []).forEach(function (row) {
      body += '<div class="receipt-row"><span>' + esc(row[0]) + '</span><b class="' + esc(row[2] || '') + '">' + esc(row[1]) + '</b></div>';
    });
    var now = new Date();
    var stamp = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0') +
      ' ' + String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0') + ':' + String(now.getSeconds()).padStart(2, '0');
    return modal('<div class="receipt-head"><div class="receipt-seal">✿</div><h3>' + esc(title) + '</h3><div class="muted">幻想乡交易凭证</div></div>' +
      '<div class="receipt-box">' + body + '<div class="receipt-row"><span>时间</span><b>' + esc(stamp) + '</b></div></div>' +
      (note ? '<div class="notice info mt12">' + esc(note) + '</div>' : '') +
      '<div class="modal-actions"><button class="btn" data-close>收好凭证</button></div>');
  }

  // ---------- 备案页脚 / 风险说明 ----------
  function siteFooterHtml() {
    var name = state.settings.event_name || '芙湘东方高校联合THP活动网站';
    var icp = String(state.settings.icp_number || '').trim();
    var police = String(state.settings.police_beian || '').trim();
    var policeCode = String(state.settings.police_code || '').trim();
    var filings = '';
    if (icp) {
      filings += '<a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer">' + esc(icp) + '</a>';
    }
    if (police) {
      var policeUrl = policeCode
        ? 'https://www.beian.gov.cn/portal/registerSystemInfo?recordcode=' + encodeURIComponent(policeCode)
        : 'https://www.beian.gov.cn/portal/index';
      filings += '<a class="police-link" href="' + policeUrl + '" target="_blank" rel="noopener noreferrer">' + esc(police) + '</a>';
    }
    return '<div class="site-footer">' +
      '<div class="copyright">© 2026 ' + esc(name) + '</div>' +
      (filings ? '<div class="icp-info">' + filings + '</div>' : '') +
      '</div>';
  }

  function riskNoticeHtml() {
    return '<div class="card risk-notice">' +
      '<div class="card-title"><span class="dot"></span>代币与交易风险说明</div>' +
      '<div class="risk-lead">本网站代币是本次活动的活动积分，不具备货币属性，<b>不可充值、提现、兑换现金，也不能与真实货币互换</b>；仅限在本次活动现场按规则兑换奖品或服务。</div>' +
      '<ul class="risk-list">' +
      '<li>请勿向任何人购买代币，或使用真实货币与代币进行交换，谨防诈骗。</li>' +
      '<li>官方工作人员不会索要你的密码、验证码或短信验证码。</li>' +
      '<li>付款码、发币码和故事码仅限本人使用，请勿截图转发给陌生人。</li>' +
      '<li>如发现代币异常、重复扣款或可疑交易，请立即联系现场管理员。</li>' +
      '<li>通过作弊、脚本、盗号、倒卖等方式获得的代币，主办方有权冻结或撤销。</li>' +
      '</ul>' +
      '<div class="risk-tail">活动结束后未使用代币的处理方式以现场公告为准。</div>' +
      '</div>';
  }

  function showLoading(text) {
    var ov = document.getElementById('loading');
    if (!ov) { return; }
    ov.classList.remove('hide');
    var txt = document.getElementById('loading-text');
    var i = 0;
    if (txt) { txt.textContent = text || LOADING_PHRASES[0]; }
    if (loadingTimer) { clearInterval(loadingTimer); }
    loadingTimer = setInterval(function () {
      i = (i + 1) % LOADING_PHRASES.length;
      if (txt) { txt.textContent = LOADING_PHRASES[i]; }
    }, 1800);
  }
  function hideLoading() {
    var ov = document.getElementById('loading');
    if (ov) { ov.classList.add('hide'); }
    if (loadingTimer) { clearInterval(loadingTimer); loadingTimer = null; }
  }
  function busy(btn, on) {
    if (!btn) { return; }
    if (on) { btn.dataset.html = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<span class="spin"></span> 处理中…'; }
    else { btn.disabled = false; if (btn.dataset.html) { btn.innerHTML = btn.dataset.html; } }
  }

  // ---------- 图片 OSS 优先 + 本地兜底 ----------
  function ossBase() { return String(state.settings.oss_base_url || '').replace(/\/+$/, ''); }
  function versionedAsset(url) {
    var v = String(state.version || '');
    return v ? (url + (url.indexOf('?') === -1 ? '?' : '&') + 'v=' + encodeURIComponent(v)) : url;
  }
  function asset(path) {
    var p = String(path || '').replace(/^\/+/, '');
    var base = ossBase();
    return versionedAsset(base ? (base + '/' + p) : ('assets/img/' + p));
  }
  function imgTag(path, cls, alt) {
    var local = versionedAsset('assets/img/' + String(path || '').replace(/^\/+/, ''));
    var src = asset(path);
    if (src === local) { return '<img class="' + cls + '" src="' + local + '" alt="' + esc(alt || '') + '">'; }
    return '<img class="' + cls + '" src="' + esc(src) + '" data-local="' + esc(local) + '" onerror="this.onerror=null;this.src=this.getAttribute(\'data-local\')" alt="' + esc(alt || '') + '">';
  }

  // ---------- 余额同步 ----------
  function setBalance(n) {
    if (state.user && typeof n === 'number') { state.user.balance = n; updateHeader(); }
  }
  function updateHeader() {
    var chip = qs('#balance-chip');
    if (!chip) { return; }
    chip.innerHTML = state.user ? ('<img src="' + asset('coin.png') + '" alt="FDT">' + state.user.balance + ' <small>FDT</small>') : '';
  }
  function refreshMe() {
    return apiSafe('me').then(function (d) {
      state.user = d.user;
      state.settings = d.settings || state.settings;
      updateHeader();
      return d;
    });
  }

  // ---------- 动态加载脚本 ----------
  var scriptCache = {};
  function loadScript(src) {
    if (scriptCache[src]) { return scriptCache[src]; }
    scriptCache[src] = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = src;
      s.onload = resolve;
      s.onerror = function () { reject(new Error('资源加载失败：' + src)); };
      document.head.appendChild(s);
    });
    return scriptCache[src];
  }

  // ---------- 二维码 ----------
  function renderQR(container, text, size) {
    var px = size || 180;
    return loadScript('assets/js/lib/qrcode.min.js').then(function () {
      container.innerHTML = '';
      new QRCode(container, { text: text, width: px, height: px, correctLevel: QRCode.CorrectLevel.M });
      return container;
    });
  }
  function downloadQR(container, filename) {
    var canvas = qs('canvas', container);
    var url = '';
    if (canvas) { url = canvas.toDataURL('image/png'); }
    else {
      var img = qs('img', container);
      if (img && img.src) { url = img.src; }
    }
    if (!url) { toast('二维码还没准备好', 'err'); return; }
    var a = document.createElement('a');
    a.href = url;
    a.download = filename || 'qrcode.png';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  }

  // ---------- 扫码 ----------
  function extractCode(text) {
    var s = String(text || '');
    var m = s.match(/[?&]c=(\d{6})/);
    if (m) { return m[1]; }
    m = s.match(/(?:^|\D)(\d{6})(?:\D|$)/);
    return m ? m[1] : '';
  }
  function openScanner(onResult) {
    loadScript('assets/js/lib/html5-qrcode.min.js').then(function () {
      var mask = document.createElement('div');
      mask.className = 'scanner-mask';
      mask.innerHTML = '<div class="scanner-card">' +
        '<div class="frame" id="qr-reader"></div>' +
        '<div class="tip">把二维码放进框里，少女正在解读…</div>' +
        '<div class="tip soft">摄像头不可用时，可以拍照识别或直接输入六位代码</div>' +
        '<div class="scanner-fallback">' +
        '<label class="btn ghost small" style="cursor:pointer">🖼️ 拍照 / 选图识别<input type="file" id="qr-file" accept="image/*" capture="environment" style="display:none"></label>' +
        '<div class="scanner-manual"><input class="input input-code" id="qr-manual" inputmode="numeric" maxlength="6" placeholder="六位代码"><button class="btn small" id="qr-manual-ok">确认</button></div>' +
        '</div>' +
        '<button class="btn ghost" style="margin-top:14px" data-close>关闭扫码</button></div>';
      document.body.appendChild(mask);
      var scanner = new Html5Qrcode('qr-reader');
      var finished = false;
      function stopCamera() {
        try { return scanner.stop().catch(function () {}); } catch (e) { return Promise.resolve(); }
      }
      function close() {
        stopCamera();
        if (mask.parentNode) { mask.parentNode.removeChild(mask); }
      }
      function finish(text) {
        if (finished) { return; }
        finished = true;
        stopCamera().then(function () {
          if (mask.parentNode) { mask.parentNode.removeChild(mask); }
          onResult(text);
        });
      }
      mask.querySelector('[data-close]').addEventListener('click', close);
      var manual = mask.querySelector('#qr-manual');
      manual.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 6); });
      mask.querySelector('#qr-manual-ok').addEventListener('click', function () {
        var code = manual.value.trim();
        if (!/^\d{6}$/.test(code)) { toast('请输入六位数字代码', 'err'); return; }
        finish(code);
      });
      manual.addEventListener('keydown', function (e) { if (e.key === 'Enter') { mask.querySelector('#qr-manual-ok').click(); } });
      mask.querySelector('#qr-file').addEventListener('change', function () {
        var file = this.files && this.files[0];
        if (!file) { return; }
        var tip = mask.querySelector('.tip');
        if (tip) { tip.textContent = '正在读取图片中的二维码…'; }
        stopCamera().then(function () { return scanner.scanFile(file, false); }).then(finish).catch(function () {
          if (tip) { tip.textContent = '这张图里没有识别到二维码，请重拍或手动输入代码'; }
          this.value = '';
        }.bind(this));
      });
      scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 240, height: 240 } },
        function (decodedText) { finish(decodedText); },
        function () { /* 未识别帧，忽略 */ }
      ).catch(function (err) {
        var tip = mask.querySelector('.tip');
        if (tip) { tip.textContent = '摄像头启动失败：' + (err && err.message ? err.message : '未知原因') + '；请用拍照识别或手动输入'; }
      });
    }).catch(function () { toast('扫码组件加载失败，请手动输入代码', 'err'); });
  }

  // ---------- 导航与路由 ----------
  var ICONS = {
    home: '<svg viewBox="0 0 24 24"><path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10.5V19h13v-8.5"/></svg>',
    token: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 8.5v7M9.5 10.5h3.8a1.6 1.6 0 0 1 0 3h-3.6a1.6 1.6 0 0 0 0 3h4"/></svg>',
    market: '<svg viewBox="0 0 24 24"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M7 15l3.5-4 3 2.5L19 8"/></svg>',
    story: '<svg viewBox="0 0 24 24"><path d="M5 5.5A2 2 0 0 1 7 3.5h11v15H7a2 2 0 0 0-2 2z"/><path d="M5 5.5v15"/><path d="M9 8h5M9 11.5h5"/></svg>',
    admin: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3.2"/><path d="M12 3.5v2.2M12 18.3v2.2M3.5 12h2.2M18.3 12h2.2M6 6l1.6 1.6M16.4 16.4 18 18M18 6l-1.6 1.6M7.6 16.4 6 18"/></svg>'
  };
  function navItems() {
    var items = [
      { key: 'home', label: '主页' },
      { key: 'token', label: '代币' },
      { key: 'market', label: '股市' },
      { key: 'story', label: '故事' }
    ];
    if (isAdmin()) { items.push({ key: 'admin', label: '管理' }); }
    return items;
  }
  function go(route, opts) {
    opts = opts || {};
    if (route === 'admin' && !isAdmin()) { route = 'home'; }
    if (opts.tab) {
      if (route === 'token') { state.tokenTab = opts.tab; }
      if (route === 'admin') { state.adminTab = opts.tab; }
    }
    state.route = route;
    if (window.location.hash !== '#/' + route) {
      try { history.replaceState(null, '', '#/' + route); } catch (e) { window.location.hash = '#/' + route; }
    }
    render();
  }

  function buildShell(contentCallback) {
    var app = document.getElementById('app');
    app.innerHTML = '';
    var shell = document.createElement('div');
    shell.className = 'app-shell';
    if (state.user) {
      var header = document.createElement('div');
      header.className = 'thp-header';
      header.innerHTML =
        '<div class="thp-brand">' + imgTag('logo.png', '', 'logo') + '<div class="name">' + esc(state.settings.event_name || '芙湘东方高校联合THP活动网站') + '</div></div>' +
        '<div class="flex gap8">' +
        '<div class="balance-chip" id="balance-chip"></div>' +
        '<button class="btn ghost small" id="btn-logout">退出</button>' +
        '</div>';
      shell.appendChild(header);
    }
    var content = document.createElement('div');
    content.id = 'view';
    shell.appendChild(content);
    app.appendChild(shell);
    if (state.user) {
      var nav = document.createElement('div');
      nav.className = 'thp-nav';
      var html = '';
      navItems().forEach(function (it) {
        html += '<div class="nav-item' + (state.route === it.key ? ' active' : '') + '" data-nav="' + it.key + '">' + ICONS[it.key] + '<span>' + it.label + '</span></div>';
      });
      nav.innerHTML = html;
      app.appendChild(nav);
      nav.querySelectorAll('.nav-item').forEach(function (el) {
        el.addEventListener('click', function () { go(el.getAttribute('data-nav')); });
      });
      var lo = qs('#btn-logout', shell);
      if (lo) {
        lo.addEventListener('click', function () {
          confirmBox('要退出登录吗？', '', '退出', 'ghost').then(function (yes) {
            if (!yes) { return; }
            apiSafe('auth.logout', {}).then(function () {
              noticeQueue = [];
              noticePollBusy = false;
              state.user = null;
              toast('期待下次再见～', 'ok');
              render();
            });
          });
        });
      }
    }
    updateHeader();
    contentCallback(content);
  }

  // ---------- 登录 / 注册 ----------
  var authMode = 'login';
  var termsAccepted = false;

  function showTermsModal(done) {
    var remain = 5;
    var m = modal(
      '<h3>开屏说明与活动规则</h3>' +
      '<div class="terms-box">' +
      '<p><b>欢迎来到芙湘东方高校联合THP活动网站。</b>开始前请确认以下事项：</p>' +
      '<p>① 本网站仅为娱乐模拟，不提供任何真实金融服务。</p>' +
      '<p>② 活动代币可通过现场指定二维码、六位代码或答题兑换获得。</p>' +
      '<p>③ 活动积分可用于兑换本次活动非卖品无料，不可充值、提现或兑换现金。</p>' +
      '<p>④ 任何环节均不涉及实际金钱交易；请勿向他人购买代币。</p>' +
      '<p>⑤ 股市有风险，入行需谨慎。模拟盈亏不代表任何真实收益。</p>' +
      '</div>' +
      '<div class="notice info">请阅读 5 秒后再同意；同意后即可继续注册。</div>' +
      '<div class="modal-actions"><button class="btn ghost" id="terms-cancel">暂不注册</button><button class="btn" id="terms-ok" disabled>请阅读（5）</button></div>',
      { maskClose: false }
    );
    var ok = qs('#terms-ok', m.el);
    var timer = setInterval(function () {
      remain--;
      if (remain <= 0) {
        clearInterval(timer);
        ok.disabled = false;
        ok.textContent = '我已阅读并同意';
      } else {
        ok.textContent = '请阅读（' + remain + '）';
      }
    }, 1000);
    qs('#terms-cancel', m.el).addEventListener('click', function () {
      clearInterval(timer);
      m.close();
      done(false);
    });
    ok.addEventListener('click', function () {
      if (ok.disabled) { return; }
      clearInterval(timer);
      termsAccepted = true;
      m.close();
      done(true);
    });
  }

  function renderAuth(content) {
    var app = document.getElementById('app');
    app.innerHTML = '';
    var wrap = document.createElement('div');
    wrap.className = 'app-shell auth-wrap';
    wrap.innerHTML =
      '<div class="auth-logo">' + imgTag('logo.png', '', 'logo') + '</div>' +
      '<div class="auth-title">' + esc(state.settings.event_name || '芙湘东方高校联合THP活动网站') + '</div>' +
      '<div class="auth-sub">幻想乡的集章代币系统 · 少女祈祷中</div>' +
      '<div class="card">' +
      '<div class="segs" id="auth-segs">' +
      '<div class="seg' + (authMode === 'login' ? ' active' : '') + '" data-m="login">登录</div>' +
      '<div class="seg' + (authMode === 'register' ? ' active' : '') + '" data-m="register">注册</div>' +
      '</div>' +
      '<div class="field"><label>昵称</label><input class="input" id="f-username" maxlength="16" placeholder="给自己起个名字吧" autocomplete="username"></div>' +
      '<div class="field"><label>密码</label><input class="input" id="f-password" type="password" placeholder="至少 6 位" autocomplete="current-password"></div>' +
      '<div class="field hidden" id="f-pass2-wrap"><label>确认密码</label><input class="input" id="f-password2" type="password" placeholder="再输一次密码"></div>' +
      '<div class="auth-device-hint hidden" id="auth-device-hint">同一设备仅可注册一个账号；如提示设备已注册，请联系现场管理员。</div>' +
      '<div class="field"><label>验证码</label><div class="captcha-row"><input class="input" id="f-captcha" maxlength="4" placeholder="看图输入" autocomplete="off"><img class="captcha-img" id="captcha-img" alt="点击刷新" title="点击刷新"></div></div>' +
      '<div class="notice err hidden" id="auth-err"></div>' +
      '<button class="btn block big mt16" id="auth-submit">登录</button>' +
      '<div class="auth-switch" id="auth-tip">' + (state.settings.registration_open === '1' || state.settings.registration_open === 'true' ? '还没有账号？<a id="auth-switch-link">去注册</a>' : '当前未开放注册，请找现场管理员哦') + '</div>' +
      '</div>' +
      '<div class="auth-quote">「就算在幻想乡，也要记得保管好自己的密码哦」</div>' +
      siteFooterHtml();
    app.appendChild(wrap);

    var captchaImg = qs('#captcha-img', wrap);
    function refreshCaptcha() { captchaImg.src = 'api.php?action=captcha&t=' + Date.now(); }
    refreshCaptcha();
    captchaImg.addEventListener('click', refreshCaptcha);
    qs('#f-captcha', wrap).addEventListener('input', function () { this.value = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase(); });

    function applyMode() {
      qs('#auth-segs', wrap).querySelectorAll('.seg').forEach(function (el) {
        el.classList.toggle('active', el.getAttribute('data-m') === authMode);
      });
      qs('#f-pass2-wrap', wrap).classList.toggle('hidden', authMode !== 'register');
      qs('#auth-device-hint', wrap).classList.toggle('hidden', authMode !== 'register');
      qs('#auth-submit', wrap).textContent = authMode === 'login' ? '登录' : '注册';
      var tip = qs('#auth-tip', wrap);
      if (state.settings.registration_open === '1' || state.settings.registration_open === 'true' || authMode === 'register') {
        tip.innerHTML = authMode === 'login'
          ? '还没有账号？<a id="auth-switch-link">去注册</a>'
          : '已经有账号了？<a id="auth-switch-link">去登录</a>';
        var lk = qs('#auth-switch-link', wrap);
        if (lk) {
          lk.addEventListener('click', function () {
            if (authMode === 'login' && !termsAccepted) {
              showTermsModal(function (ok) { authMode = ok ? 'register' : 'login'; applyMode(); });
              return;
            }
            authMode = authMode === 'login' ? 'register' : 'login';
            applyMode();
          });
        }
      }
    }
    qs('#auth-segs', wrap).querySelectorAll('.seg').forEach(function (el) {
      el.addEventListener('click', function () {
        var target = el.getAttribute('data-m');
        if (target === 'register' && !termsAccepted) {
          showTermsModal(function (ok) { authMode = ok ? 'register' : 'login'; applyMode(); });
          return;
        }
        authMode = target;
        applyMode();
      });
    });
    applyMode();

    function showErr(msg) {
      var box = qs('#auth-err', wrap);
      box.textContent = msg;
      box.classList.remove('hidden');
    }
    function submit() {
      var username = qs('#f-username', wrap).value.trim();
      var password = qs('#f-password', wrap).value;
      var password2 = qs('#f-password2', wrap).value;
      var captcha = qs('#f-captcha', wrap).value.trim();
      var btn = qs('#auth-submit', wrap);
      qs('#auth-err', wrap).classList.add('hidden');
      if (!username) { showErr('请先填写昵称'); return; }
      if (!password) { showErr('请先填写密码'); return; }
      if (!captcha) { showErr('请先填写验证码'); return; }
      var action = authMode === 'login' ? 'auth.login' : 'auth.register';
      var payload = { username: username, password: password, captcha: captcha, device_id: getDeviceId(), device_meta: deviceMeta() };
      if (authMode === 'register') { payload.password2 = password2; payload.terms = 1; }
      busy(btn, true);
      apiSafe(action, payload).then(function (d) {
        noticeQueue = [];
        noticePollBusy = false;
        state.user = d.user;
        toast(authMode === 'login' ? '欢迎回来～' : '注册成功，欢迎来到幻想乡！', 'ok');
        render();
      }).catch(function (e) {
        showErr(e.message);
        refreshCaptcha();
        qs('#f-captcha', wrap).value = '';
      }).finally(function () { busy(btn, false); });
    }
    qs('#auth-submit', wrap).addEventListener('click', submit);
    wrap.addEventListener('keydown', function (e) { if (e.key === 'Enter') { submit(); } });
  }

  // ---------- 主页 ----------
  function renderHome(content) {
    var cards = [
      { img: 'coin.png', t: '获取代币', d: '扫码 / 输码 / 答题', act: function () { go('token', { tab: 'get' }); } },
      { img: 'coin.png', t: '使用代币', d: '生成付款码给管理员', act: function () { go('token', { tab: 'use' }); } },
      { img: 'bg.svg', t: '模拟股市', d: '炒股小游戏', act: function () { go('market'); } },
      { img: 'logo.png', t: '故事收集', d: '解锁路上的故事', act: function () { go('story'); } }
    ];
    if (isAdmin()) { cards.push({ img: 'logo.png', t: '管理后台', d: '发币收币 / 大盘 / 题库', act: function () { go('admin'); } }); }
    if (!isAdmin()) { cards.push({ img: 'coin.png', t: '转让代币', d: '把代币送给朋友', act: function () { go('token', { tab: 'send' }); } }); }
    var cardsHtml = '';
    cards.forEach(function (c, i) {
      cardsHtml += '<div class="quick-card" data-card="' + i + '"><img src="' + asset(c.img) + '" alt="">' +
        '<div><div class="t">' + esc(c.t) + '</div><div class="d">' + esc(c.d) + '</div></div></div>';
    });
    content.innerHTML =
      '<div class="hero">' + imgTag('coin.png', 'hero-flower', '') +
      '<div class="hero-inner"><h2>' + esc(state.settings.event_name || '芙湘东方高校联合THP活动网站') + '</h2>' +
      '<div class="sub">少女祈祷中 · 线上代币 × 模拟股市 × 故事收集</div></div></div>' +
      '<div class="quick-grid">' + cardsHtml + '</div>' +
      '<div class="card"><div class="card-title"><span class="dot"></span>活动介绍</div>' +
      '<div class="announcement">' + esc(state.settings.announcement || '活动信息待更新，敬请期待～') + '</div></div>' +
      '<div class="card"><div class="flex-between"><div class="card-title" style="margin:0"><span class="dot"></span>我的 FDT 明细</div>' +
      '<span class="muted" id="tx-refresh" style="cursor:pointer">刷新</span></div>' +
      '<div id="tx-list"><div class="empty">少女祈祷中…</div></div></div>' +
      '<div class="muted center mt16">祝各位在幻想乡玩得开心 ✿ 芙湘THP</div>' +
      riskNoticeHtml() +
      siteFooterHtml();
    content.querySelectorAll('.quick-card').forEach(function (el) {
      el.addEventListener('click', function () { cards[parseInt(el.getAttribute('data-card'), 10)].act(); });
    });
    function loadTx() {
      apiSafe('token.history').then(function (d) {
        var box = qs('#tx-list', content);
        if (!d.items || !d.items.length) { box.innerHTML = '<div class="empty">还没有记录，去摊位扫个码试试吧～</div>'; return; }
        var html = '';
        d.items.slice(0, 12).forEach(function (t) {
          var sign = t.delta > 0 ? '+' + t.delta : String(t.delta);
          html += '<div class="list-item"><div><div>' + esc(t.note || t.type) + '</div><div class="muted">' + fmtTime(t.created_at) + '</div></div>' +
            '<div class="' + (t.delta > 0 ? 'up' : (t.delta < 0 ? 'down' : 'muted')) + '" style="font-family:ui-monospace,monospace;font-weight:800">' + sign + ' FDT</div></div>';
        });
        box.innerHTML = html;
      }).catch(function (e) { qs('#tx-list', content).innerHTML = '<div class="empty">' + esc(e.message) + '</div>'; });
    }
    loadTx();
    qs('#tx-refresh', content).addEventListener('click', loadTx);
    pollNotices();
  }

  // ---------- 全站通知：未读时逐条弹出，关闭后才记为已读 ----------
  function showNextNotice() {
    if (noticeShowing || noticeQueue.length === 0) { return; }
    noticeShowing = true;
    var item = noticeQueue.shift();
    var m = modal(
      '<h3>📣 ' + esc(item.title) + '</h3>' +
      '<div class="notice-meta">发布于 ' + esc(String(item.published_at || '').replace('T', ' ').slice(0, 19)) + '</div>' +
      '<div class="announcement" style="white-space:pre-wrap">' + esc(item.body) + '</div>' +
      '<div class="modal-actions"><button class="btn" id="notice-read">我知道了</button></div>',
      { maskClose: false, onClose: function () {
        noticeShowing = false;
        apiSafe('news.mark_read', { id: item.id }).catch(function () {}).then(function () { showNextNotice(); });
      } }
    );
    qs('#notice-read', m.el).addEventListener('click', function () { m.close(); });
  }

  function pollNotices() {
    if (!state.user || state.route !== 'home' || noticePollBusy || document.visibilityState === 'hidden') { return; }
    noticePollBusy = true;
    apiSafe('news.inbox', { kind: 'notice', limit: 20 }).then(function (d) {
      var seen = {};
      noticeQueue.forEach(function (n) { seen[n.id] = 1; });
      (d.items || []).forEach(function (n) {
        if (!seen[n.id]) { noticeQueue.push(n); seen[n.id] = 1; }
      });
      showNextNotice();
    }).catch(function () {}).finally(function () { noticePollBusy = false; });
  }

  // ---------- 深链代码处理 ----------
  function handlePendingCode() {
    var code = state.pendingCode;
    if (!code) { return false; }
    state.pendingCode = '';
    if (!state.user) { return false; }
    apiSafe('code.resolve', { code: code }).then(function (r) {
      if (r.type === 'issue') {
        apiSafe('token.redeem', { code: code }).then(function (d) {
          setBalance(d.balance);
          toast('获得 ' + d.amount + ' FDT，好耶！', 'ok');
          go('token', { tab: 'get' });
        }).catch(function (e) { toast(e.message, 'err'); });
      } else if (r.type === 'quiz') {
        state.quizCode = code;
        go('token', { tab: 'get' });
        toast('已找到题目「' + (r.title || '') + '」，来答题吧！', 'info');
      } else if (r.type === 'story') {
        apiSafe('story.redeem', { code: code }).then(function (d) {
          go('story');
          window.THP.openStory(d.story);
          toast(d.already ? '这个故事已经解锁过啦，重温一下～' : '解锁了新故事！', 'ok');
        }).catch(function (e) { toast(e.message, 'err'); go('story'); });
      } else if (r.type === 'spend') {
        if (isAdmin()) {
          state.collectCode = code;
          go('admin', { tab: 'trade' });
          toast('检测到付款码，请确认收款', 'info');
        } else {
          toast('这是付款码哦，需要交给管理员扫描～', 'info');
        }
      } else {
        toast('这段代码没有对应内容呀，再看看？', 'err');
      }
    }).catch(function (e) { toast(e.message, 'err'); });
    return true;
  }

  // ---------- 渲染入口 ----------
  function render() {
    var viewDef = null;
    if (!state.user) {
      var app = document.getElementById('app');
      app.innerHTML = '';
      var holder = document.createElement('div');
      renderAuth(holder);
      hideLoading();
      return;
    }
    if (state.route === 'admin' && !isAdmin()) { state.route = 'home'; }
    viewDef = { auth: null, home: { render: renderHome }, token: views.token, market: views.market, story: views.story, admin: views.admin }[state.route] || { render: renderHome };
    if (!viewDef || !viewDef.render) { viewDef = { render: renderHome }; }
    buildShell(function (content) {
      viewDef.render(content);
      hideLoading();
    });
  }

  // ---------- 对外接口 ----------
  window.THP = Object.assign(window.THP || {}, {
    api: apiSafe,
    apiRaw: api,
    state: state,
    esc: esc,
    toast: toast,
    modal: modal,
    confirm: confirmBox,
    receipt: showReceipt,
    showLoading: showLoading,
    hideLoading: hideLoading,
    busy: busy,
    go: go,
    asset: asset,
    imgTag: imgTag,
    renderQR: renderQR,
    downloadQR: downloadQR,
    openScanner: openScanner,
    extractCode: extractCode,
    setBalance: setBalance,
    refreshMe: refreshMe,
    render: render,
    isAdmin: isAdmin,
    fmtTime: fmtTime,
    qs: qs,
    everyVisible: everyVisible
  });

  // ---------- 启动 ----------
  function boot() {
    render();
    if (state.user && state.pendingCode) { handlePendingCode(); }
    everyVisible(pollNotices, 55000, 65000, { immediate: false });
    setInterval(function () {
      if (state.user && state.pendingCode) { handlePendingCode(); }
    }, 1500);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
