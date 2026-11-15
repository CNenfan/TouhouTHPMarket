/* 故事收集页 */
(function () {
  'use strict';
  var VIEWS = window.THP_VIEWS || (window.THP_VIEWS = {});

  VIEWS.story = {
    render: function (content) {
      var THP = window.THP;
      content.innerHTML =
        '<div class="card">' +
        '<div class="card-title"><span class="dot"></span>故事收集</div>' +
        '<p class="muted">在会场找到藏着故事的二维码，扫码或输入六位故事代码，就能解锁幻想乡的小小片段。</p>' +
        '<div class="flex gap12 wrap mt12">' +
        '<button class="btn" id="btn-scan-story">📷 扫码解锁</button>' +
        '<button class="btn ghost" id="btn-open-story-input">⌨️ 输入故事代码</button>' +
        '</div>' +
        '<div class="hidden mt12" id="story-input-box">' +
        '<div class="big-code-input-wrap"><input class="input input-code" id="story-code" inputmode="numeric" maxlength="6" placeholder="000000"></div>' +
        '<button class="btn block mt12" id="btn-story-redeem">解锁故事</button>' +
        '</div>' +
        '<div id="story-result" class="mt12"></div>' +
        '</div>' +
        '<div class="card"><div class="card-title"><span class="dot"></span>已解锁的故事</div><div id="story-list"><div class="empty">少女祈祷中…</div></div></div>';

      var inputBox = THP.qs('#story-input-box', content);
      THP.qs('#btn-open-story-input', content).addEventListener('click', function () {
        inputBox.classList.toggle('hidden');
        THP.qs('#story-code', content).focus();
      });
      var codeInput = THP.qs('#story-code', content);
      codeInput.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 6); });
      THP.qs('#btn-scan-story', content).addEventListener('click', function () {
        THP.openScanner(function (text) {
          var code = THP.extractCode(text);
          if (!code) { THP.toast('二维码里没有找到六位代码', 'err'); return; }
          redeem(content, code);
        });
      });
      THP.qs('#btn-story-redeem', content).addEventListener('click', function () {
        var code = codeInput.value.trim();
        if (code.length !== 6) { THP.toast('请输入六位数字代码', 'err'); return; }
        redeem(content, code);
      });

      loadList(content);
    }
  };

  function redeem(content, code) {
    var THP = window.THP;
    var box = THP.qs('#story-result', content);
    box.innerHTML = '<div class="card"><div class="empty">少女祈祷中…</div></div>';
    THP.api('story.redeem', { code: code }).then(function (d) {
      box.innerHTML = '<div class="result-banner ok">' + (d.already ? '✨ 这个故事已经解锁过啦' : '✨ 解锁新故事：「' + THP.esc(d.story.title) + '」') + '</div>';
      THP.openStory(d.story);
      loadList(content);
    }).catch(function (e) {
      box.innerHTML = '<div class="result-banner err">💧 ' + THP.esc(e.message) + '</div>';
    });
  }

  function loadList(content) {
    var THP = window.THP;
    THP.api('story.list').then(function (d) {
      var box = THP.qs('#story-list', content);
      if (!d.items || !d.items.length) {
        box.innerHTML = '<div class="empty">还没有解锁任何故事，去会场找找神秘代码吧～</div>';
        return;
      }
      var html = '';
      d.items.forEach(function (s, i) {
        html += '<div class="story-card" data-si="' + i + '"><div><div class="title">' + THP.esc(s.title) + '</div>' +
          '<div class="muted">解锁于 ' + THP.fmtTime(s.unlocked_at) + '</div></div><span class="muted">阅读 →</span></div>';
      });
      box.innerHTML = html;
      box.querySelectorAll('.story-card').forEach(function (el) {
        el.addEventListener('click', function () {
          var s = d.items[parseInt(el.getAttribute('data-si'), 10)];
          THP.api('story.get', { id: s.id }).then(function (r) { THP.openStory(r.story); }).catch(function (e) { THP.toast(e.message, 'err'); });
        });
      });
    }).catch(function (e) {
      THP.qs('#story-list', content).innerHTML = '<div class="empty">' + THP.esc(e.message) + '</div>';
    });
  }

  // 通用：展示故事内容
  window.THP = window.THP || {};
  window.THP.openStory = function (story) {
    var THP = window.THP;
    var imgs = '';
    (story.images || []).forEach(function (src) {
      imgs += '<img class="story-img" src="' + THP.esc(src) + '" data-local="' + THP.esc(src) + '" onerror="this.style.display=\'none\'" alt="">';
    });
    var m = THP.modal(
      '<h3>' + THP.esc(story.title) + '</h3>' +
      '<div class="story-body mt8">' + THP.esc(story.body) + '</div>' + imgs +
      '<div class="modal-actions"><button class="btn block" data-close>合上故事书</button></div>'
    );
  };
})();
