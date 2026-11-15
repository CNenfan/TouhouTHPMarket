/* 代币交易页：获取 / 使用 / 转让 */
(function () {
  'use strict';
  var VIEWS = window.THP_VIEWS || (window.THP_VIEWS = {});

  function digitOnly(input, len) {
    input.addEventListener('input', function () {
      this.value = this.value.replace(/\D/g, '').slice(0, len || 6);
    });
  }

  VIEWS.token = {
    render: function (content) {
      var THP = window.THP;
      var state = THP.state;
      var tab = state.tokenTab || 'get';

      content.innerHTML =
        '<div class="segs" id="token-segs">' +
        '<div class="seg" data-tab="get">获取代币</div>' +
        '<div class="seg" data-tab="use">使用代币</div>' +
        '<div class="seg" data-tab="send">转让代币</div>' +
        '</div>' +
        '<div class="panel" id="token-panel"></div>';

      content.querySelectorAll('#token-segs .seg').forEach(function (el) {
        el.classList.toggle('active', el.getAttribute('data-tab') === tab);
        el.addEventListener('click', function () {
          state.tokenTab = el.getAttribute('data-tab');
          VIEWS.token.render(content);
        });
      });

      var panel = document.getElementById('token-panel');
      if (tab === 'get') { renderGet(panel); }
      else if (tab === 'use') { renderUse(panel); }
      else { renderSend(panel); }
    }
  };

  // ================= 获取代币 =================
  function renderGet(panel) {
    var THP = window.THP;
    panel.innerHTML =
      '<div class="card">' +
      '<div class="card-title"><span class="dot"></span>获取代币</div>' +
      '<p class="muted">在摊位扫二维码，或输入摊位码 / 答题码，少女就会把代币送过来～</p>' +
      '<div class="flex gap12 wrap mt12">' +
      '<button class="btn" id="btn-scan-get">📷 扫码获取</button>' +
      '<button class="btn ghost" id="btn-open-manual">⌨️ 输入六位代码</button>' +
      '</div>' +
      '<div class="hidden mt12" id="manual-box">' +
      '<div class="big-code-input-wrap"><input class="input input-code" id="get-code" inputmode="numeric" maxlength="6" placeholder="000000"></div>' +
      '<button class="btn block mt12" id="btn-redeem">兑换</button>' +
      '<div class="scan-hint">提示：现场二维码在手机自带相机里扫也可以，会自动跳回本网站～</div>' +
      '</div>' +
      '</div>' +
      '<div id="get-result"></div>';

    var manualBox = THP.qs('#manual-box', panel);
    THP.qs('#btn-open-manual', panel).addEventListener('click', function () {
      manualBox.classList.toggle('hidden');
      THP.qs('#get-code', panel).focus();
    });
    var codeInput = THP.qs('#get-code', panel);
    digitOnly(codeInput);
    THP.qs('#btn-scan-get', panel).addEventListener('click', function () {
      THP.openScanner(function (text) {
        var code = THP.extractCode(text);
        if (!code) { THP.toast('二维码里没有找到六位代码', 'err'); return; }
        resolveAndRedeem(code, panel);
      });
    });
    THP.qs('#btn-redeem', panel).addEventListener('click', function () {
      var code = codeInput.value.trim();
      if (code.length !== 6) { THP.toast('请输入六位数字代码', 'err'); return; }
      resolveAndRedeem(code, panel);
    });

    if (window.THP.state.quizCode) {
      var c = window.THP.state.quizCode;
      window.THP.state.quizCode = '';
      resolveAndRedeem(c, panel);
    }
  }

  function resolveAndRedeem(code, panel) {
    var THP = window.THP;
    var box = THP.qs('#get-result', panel);
    box.innerHTML = '<div class="card"><div class="empty">少女祈祷中…</div></div>';
    THP.api('token.redeem', { code: code }).then(function (d) {
      if (d.type === 'issue') {
        THP.setBalance(d.balance);
        box.innerHTML =
          '<div class="result-banner ok">✨ 成功获得 ' + d.amount + ' FDT！</div>' +
          '<div class="card center"><img src="' + THP.asset('coin.png') + '" style="width:64px"><div class="mt8">当前余额：<b style="font-size:20px">' + d.balance + '</b> FDT</div></div>';
        THP.toast('获得 ' + d.amount + ' FDT，好耶！', 'ok');
      } else if (d.type === 'quiz') {
        renderQuiz(box, d);
      }
    }).catch(function (e) {
      box.innerHTML = '<div class="result-banner err">💧 ' + THP.esc(e.message) + '</div>';
      THP.toast(e.message, 'err');
    });
  }

  var quizState = { selected: -1 };
  function renderQuiz(box, d) {
    var THP = window.THP;
    var q = d.question;
    quizState.selected = -1;
    var optionsHtml = '';
    (q.options || []).forEach(function (opt, i) {
      optionsHtml += '<div class="quiz-option" data-i="' + i + '"><span class="idx">' + String.fromCharCode(65 + i) + '</span><span>' + THP.esc(opt) + '</span></div>';
    });
    var imgHtml = q.image ? '<img src="' + THP.esc(q.image) + '" style="width:100%;border-radius:12px;margin-bottom:10px">' : '';
    box.innerHTML =
      '<div class="card">' +
      '<div class="card-title"><span class="dot"></span>答题：「' + THP.esc(q.title) + '」</div>' +
      imgHtml +
      '<div style="white-space:pre-wrap;font-size:15px">' + THP.esc(q.body) + '</div>' +
      '<div class="mt12">' + optionsHtml + '</div>' +
      '<div class="notice info">答对可获得 <b>' + q.reward + '</b> FDT，每人只有 1 次答题机会哦～</div>' +
      '<div id="quiz-foot" class="mt12"></div>' +
      '</div>';

    var locked = !!d.answered;
    if (locked) {
      box.querySelectorAll('.quiz-option').forEach(function (el) {
        var i = parseInt(el.getAttribute('data-i'), 10);
        el.style.pointerEvents = 'none';
        if (i === d.chosen) { el.classList.add(d.was_correct ? 'correct' : 'wrong'); }
      });
      THP.qs('#quiz-foot', box).innerHTML = d.was_correct
        ? '<div class="result-banner ok">这道题你已经答对过啦 +' + q.reward + '</div>'
        : '<div class="result-banner err">这道题你已经答过（答错了），每人只能答 1 次哦</div>';
      return;
    }

    box.querySelectorAll('.quiz-option').forEach(function (el) {
      el.addEventListener('click', function () {
        quizState.selected = parseInt(el.getAttribute('data-i'), 10);
        box.querySelectorAll('.quiz-option').forEach(function (x) { x.classList.remove('selected'); });
        el.classList.add('selected');
      });
    });
    THP.qs('#quiz-foot', box).innerHTML = '<button class="btn block" id="btn-quiz-submit">提交答案</button>';
    THP.qs('#btn-quiz-submit', box).addEventListener('click', function () {
      if (quizState.selected < 0) { THP.toast('先选一个答案吧～', 'err'); return; }
      var btn = this;
      THP.busy(btn, true);
      THP.api('token.quiz_answer', { question_id: q.id, chosen: quizState.selected }).then(function (r) {
        THP.setBalance(r.balance);
        box.querySelectorAll('.quiz-option').forEach(function (el) {
          var i = parseInt(el.getAttribute('data-i'), 10);
          el.style.pointerEvents = 'none';
          if (i === r.answer_index) { el.classList.add('correct'); }
          else if (i === quizState.selected && !r.correct) { el.classList.add('wrong'); }
        });
        THP.qs('#quiz-foot', box).innerHTML = r.correct
          ? '<div class="result-banner ok">✨ 答对啦！获得 ' + r.reward + ' FDT</div>'
          : '<div class="result-banner err">💧 答错了…正确答案是 ' + String.fromCharCode(65 + r.answer_index) + '，下次再来挑战别的题吧</div>';
        if (r.correct) { THP.toast('答题成功 +' + r.reward + ' FDT', 'ok'); }
      }).catch(function (e) {
        THP.qs('#quiz-foot', box).innerHTML = '<div class="result-banner err">' + THP.esc(e.message) + '</div>';
      }).finally(function () { THP.busy(btn, false); });
    });
  }

  // ================= 使用代币 =================
  function renderUse(panel) {
    var THP = window.THP;
    var state = THP.state;
    if (state.spendOrder) { renderSpendOrder(panel); return; }
    panel.innerHTML =
      '<div class="card">' +
      '<div class="card-title"><span class="dot"></span>使用代币</div>' +
      '<p class="muted">输入要支付的金额，生成六位收款码和二维码，把屏幕给管理员扫一下即可完成支付。</p>' +
      '<div class="amount-row"><input class="input" id="spend-amount" inputmode="numeric" placeholder="输入 FDT 金额" maxlength="4">' +
      '<button class="btn" id="btn-spend-create">生成付款码</button></div>' +
      '<div class="notice info mt12">付款码 15 分钟内有效；超时或点取消都会自动把 FDT 退回给你，不用担心～</div>' +
      '<div id="spend-result"></div>' +
      '</div>';
    var amt = THP.qs('#spend-amount', panel);
    amt.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 5); });
    THP.qs('#btn-spend-create', panel).addEventListener('click', function () {
      var amount = parseInt(amt.value, 10);
      if (!amount || amount < 1) { THP.toast('请输入正确的金额', 'err'); return; }
      var btn = this;
      THP.busy(btn, true);
      THP.api('token.spend_create', { amount: amount }).then(function (d) {
        THP.setBalance(d.balance);
        state.spendOrder = d;
        renderSpendOrder(panel);
      }).catch(function (e) { THP.toast(e.message, 'err'); }).finally(function () { THP.busy(btn, false); });
    });
  }

  function renderSpendOrder(panel) {
    var THP = window.THP;
    var state = THP.state;
    var order = state.spendOrder;
    panel.innerHTML =
      '<div class="card center">' +
      '<div class="card-title" style="justify-content:center"><span class="dot"></span>等待管理员收款</div>' +
      '<div class="code-big">' + order.code + '</div>' +
      '<div class="qr-box" id="spend-qr"></div>' +
      '<div class="countdown"><span class="status-pulse"></span><span id="spend-status-text">请把屏幕给管理员扫描，或报出上面的六位码</span></div>' +
      '<div class="countdown" id="spend-countdown"></div>' +
      '<button class="btn ghost block mt16" id="btn-spend-cancel">取消支付</button>' +
      '<div id="spend-order-result" class="mt12"></div>' +
      '</div>';
    var box = THP.qs('#spend-qr', panel);
    var qrBase = THP.state.base_url || (location.origin + location.pathname.replace(/[^\/]*$/, ''));
    THP.renderQR(box, qrBase + '?c=' + order.code, 180);
    var left = order.seconds || 900;
    var cdEl = THP.qs('#spend-countdown', panel);
    function tick() {
      if (!document.body.contains(cdEl)) { clearInterval(timer); return; }
      left = Math.max(0, left - 1);
      var m = Math.floor(left / 60);
      var s = left % 60;
      cdEl.textContent = '剩余时间 ' + m + ':' + (s < 10 ? '0' + s : s);
      if (left <= 0) {
        cdEl.textContent = '付款码已过期，正在退回 FDT…';
        pollStatus();
      }
    }
    var timer = setInterval(tick, 1000);
    tick();

    var polling = false;
    var stopped = false;   // 订单结束（已支付 / 过期 / 取消）后彻底停止轮询，避免重复弹提示
    function pollStatus() {
      if (stopped || polling || document.visibilityState === 'hidden') { return; }
      if (!document.body.contains(panel)) { stopped = true; stopTimers(); return; }
      polling = true;
      THP.api('token.spend_status', { code: order.code }).then(function (d) {
        if (d.status === 'used') {
          stopped = true;
          stopTimers();
          state.spendOrder = null;
          THP.setBalance(state.user.balance);
          THP.api('token.history').catch(function () {});
          var holder = panel;
          holder.innerHTML = '<div class="card center"><img src="' + THP.asset('coin.png') + '" style="width:70px"><div class="result-banner ok mt12">✨ 支付成功！' + d.amount + ' FDT 已交给管理员</div><button class="btn ghost block mt12" id="btn-spend-again">再来一单</button></div>';
          THP.qs('#btn-spend-again', holder).addEventListener('click', function () { renderUse(panel); });
          THP.toast('支付成功！', 'ok');
          THP.refreshMe().catch(function () {});
        } else if (d.status === 'expired' || d.status === 'cancelled') {
          stopped = true;
          stopTimers();
          state.spendOrder = null;
          var holder2 = panel;
          holder2.innerHTML = '<div class="result-banner ' + (d.status === 'expired' ? 'err' : 'info') + '">' + (d.status === 'expired' ? '付款码超时，' + d.amount + ' FDT 已退回余额' : '已取消支付，' + d.amount + ' FDT 已退回余额') + '</div><button class="btn ghost block mt12" id="btn-spend-again2">重新生成</button>';
          THP.qs('#btn-spend-again2', holder2).addEventListener('click', function () { renderUse(panel); });
          THP.refreshMe().catch(function () {});
        } else {
          left = d.seconds_left;
        }
      }).catch(function () {}).finally(function () { polling = false; });
    }
    function stopTimers() {
      clearInterval(timer);
      if (pollTimer) { pollTimer.stop(); pollTimer = null; }
    }
    var pollTimer = THP.everyVisible(pollStatus, 2800, 3200, { immediate: false });
    pollStatus();

    THP.qs('#btn-spend-cancel', panel).addEventListener('click', function () {
      var btn = this;
      THP.busy(btn, true);
      THP.api('token.spend_cancel', { code: order.code }).then(function (d) {
        stopped = true;
        stopTimers();
        state.spendOrder = null;
        THP.setBalance(d.balance);
        THP.toast('已取消，' + d.amount + ' FDT 已退回', 'ok');
        renderUse(panel);
      }).catch(function (e) { THP.toast(e.message, 'err'); }).finally(function () { THP.busy(btn, false); });
    });
  }

  // ================= 转让代币 =================
  function renderSend(panel) {
    var THP = window.THP;
    panel.innerHTML =
      '<div class="card">' +
      '<div class="card-title"><span class="dot"></span>转让代币</div>' +
      '<p class="muted">把代币转让给同在现场的小伙伴：输入对方的昵称和金额即可，即时到账。</p>' +
      '<div class="field"><label>对方的昵称</label><input class="input" id="send-to" maxlength="16" placeholder="对方注册时用的昵称"></div>' +
      '<div class="field"><label>转让金额（FDT）</label><input class="input" id="send-amount" inputmode="numeric" maxlength="5" placeholder="例如 50"></div>' +
      '<div class="notice info">转让前请确认昵称完全一致（区分大小写）；转让不可撤销，请谨慎操作。</div>' +
      '<button class="btn block mt12" id="btn-send">确认转让</button>' +
      '<div id="send-result" class="mt12"></div>' +
      '</div>';
    var amt = THP.qs('#send-amount', panel);
    amt.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 6); });
    THP.qs('#btn-send', panel).addEventListener('click', function () {
      var to = THP.qs('#send-to', panel).value.trim();
      var amount = parseInt(amt.value, 10);
      if (!to) { THP.toast('请输入对方的昵称', 'err'); return; }
      if (!amount || amount < 1) { THP.toast('请输入正确的金额', 'err'); return; }
      var btn = this;
      THP.confirm('确认转让？', '将 ' + amount + ' FDT 转让给「' + to + '」，转让后不可撤销。', '确认转让').then(function (yes) {
        if (!yes) { return; }
        THP.busy(btn, true);
        THP.api('token.transfer', { to: to, amount: amount }).then(function (d) {
          THP.setBalance(d.balance);
          THP.qs('#send-result', panel).innerHTML = '<div class="result-banner ok">✨ 已把 ' + d.amount + ' FDT 转让给「' + THP.esc(d.to) + '」</div>';
          THP.toast('转让成功', 'ok');
        }).catch(function (e) {
          THP.qs('#send-result', panel).innerHTML = '<div class="result-banner err">💧 ' + THP.esc(e.message) + '</div>';
        }).finally(function () { THP.busy(btn, false); });
      });
    });
  }
})();
