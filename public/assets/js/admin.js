/* 管理员后台 */
(function () {
  'use strict';
  var VIEWS = window.THP_VIEWS || (window.THP_VIEWS = {});
  var usersPage = 1;
  var usersQuery = '';
  var riskPage = 1;
  var riskType = '';
  var riskUser = 0;
  function can(permission) {
    var u = window.THP && window.THP.state ? window.THP.state.user : null;
    return !!(u && u.role === 'admin' && Array.isArray(u.admin_permissions) && u.admin_permissions.indexOf(permission) !== -1);
  }
  function isFullAdmin() {
    var u = window.THP && window.THP.state ? window.THP.state.user : null;
    return !!(u && u.role === 'admin' && u.admin_scope === 'full');
  }
  function scopeLabel(scope) {
    return scope === 'finance' ? '财务管理员' : (scope === 'data' ? '数据管理员' : '全权管理员');
  }

  VIEWS.admin = {
    render: function (content) {
      var THP = window.THP;
      var state = THP.state;
      if (!THP.isAdmin()) { THP.go('home'); return; }
      var allTabs = [
        ['overview', '概览', 'stats'], ['users', '用户', 'users'], ['risk', '风控', 'risk'], ['trade', '发币收币', 'trade'],
        ['market', '大盘', 'market'], ['board', '看板', 'market'], ['questions', '题库', 'content'], ['stories', '故事', 'content'],
        ['settings', '设置', 'settings'], ['maintenance', '活动运维', 'maintenance']
      ];
      var tabs = allTabs.filter(function (t) { return can(t[2]); });
      if (!tabs.some(function (t) { return t[0] === state.adminTab; })) { state.adminTab = tabs.length ? tabs[0][0] : 'overview'; }
      var html = '<div class="segs admin-subnav" id="admin-tabs">';
      tabs.forEach(function (t) {
        if (t[0] === 'board') {
          html += '<a class="seg" href="board.php" target="_blank" rel="noopener">' + t[1] + '</a>';
        } else {
          html += '<div class="seg' + (state.adminTab === t[0] ? ' active' : '') + '" data-t="' + t[0] + '">' + t[1] + '</div>';
        }
      });
      html += '</div><div id="admin-panel"></div>';
      content.innerHTML = html;
      content.querySelectorAll('#admin-tabs .seg[data-t]').forEach(function (el) {
        el.addEventListener('click', function () {
          state.adminTab = el.getAttribute('data-t');
          VIEWS.admin.render(content);
        });
      });
      var panel = document.getElementById('admin-panel');
      if (state.adminTab === 'users') { renderUsers(panel, content); }
      else if (state.adminTab === 'risk') { renderRisk(panel, content); }
      else if (state.adminTab === 'trade') { renderTrade(panel, content); }
      else if (state.adminTab === 'market') { renderMarket(panel, content); }
      else if (state.adminTab === 'questions') { renderQuestions(panel, content); }
      else if (state.adminTab === 'stories') { renderStories(panel, content); }
      else if (state.adminTab === 'settings') { renderSettings(panel, content); }
      else if (state.adminTab === 'maintenance') { renderMaintenance(panel, content); }
      else { renderOverview(panel, content); }
    }
  };

  // ================= 概览 =================
  function renderOverview(panel, content) {
    var THP = window.THP;
    panel.innerHTML = '<div class="card"><div class="empty">统计中…</div></div>';
    THP.api('admin.stats').then(function (s) {
      function card(k, v) { return '<div class="stat-card"><div class="k">' + k + '</div><div class="v">' + v + '</div></div>'; }
      var usersHtml = '';
      (s.recent_users || []).forEach(function (u) {
        usersHtml += '<div class="log-line">' + THP.esc(u.username) + ' 注册于 ' + THP.fmtTime(u.created_at) + '</div>';
      });
      var logsHtml = '';
      (s.recent_logs || []).forEach(function (l) {
        logsHtml += '<div class="log-line"><b>' + THP.esc(l.username || '系统') + '</b> ' + THP.esc(l.action) + ' · ' + THP.esc(l.detail || '') + ' <span class="muted">' + THP.fmtTime(l.created_at) + '</span></div>';
      });
      panel.innerHTML =
        '<div class="stat-grid">' +
        card('注册游客', s.user_count) + card('管理员', s.admin_count) + card('测试账号', s.test_count || 0) + card('被封禁', s.banned_count) + card('今日新注册', s.new_today) +
        card('流通 FDT', s.balance_sum) + card('欠款总额（FDT）', s.debt_sum) + card('今日交易笔数', s.tx_today) +
        card('待使用发币码', s.pending_issue) + card('待收款付款码', s.pending_spend) +
        card('题目数', s.question_count) + card('故事数', s.story_count) + card('公司数', s.company_count) +
        card('24h 风险事件', s.risk_24h || 0) +
        '</div>' +
        '<div class="card"><div class="card-title"><span class="dot"></span>最近注册</div>' + (usersHtml || '<div class="empty">暂无</div>') + '</div>' +
        '<div class="card"><div class="card-title"><span class="dot"></span>最近操作日志</div>' + (logsHtml || '<div class="empty">暂无</div>') + '</div>';
    }).catch(function (e) { panel.innerHTML = '<div class="notice err">' + THP.esc(e.message) + '</div>'; });
  }

  // ================= 用户 =================
  function renderUsers(panel, content) {
    var THP = window.THP;
    panel.innerHTML =
      '<div class="card">' +
      '<div class="flex gap8 wrap"><input class="input grow" id="u-q" placeholder="搜索昵称 / ID" value="' + THP.esc(usersQuery) + '" style="max-width:260px">' +
      '<button class="btn" id="u-search">搜索</button>' +
      '<button class="btn blue" id="u-create">新建账号</button></div>' +
      '<div class="bulk-bar mt12" id="u-bulk-bar"><div><b>批量操作</b> <span class="muted" id="u-selected-count">未选择</span></div>' +
      '<select class="input" id="u-bulk-op">' +
      (isFullAdmin() ? '<option value="add_balance">批量增加 / 减少 FDT</option><option value="set_balance">批量设为指定 FDT</option>' : '') +
      '<option value="ban">批量封禁</option><option value="unban">批量解封</option>' +
      '<option value="test_on">标记为测试账号</option><option value="test_off">取消测试标记</option>' +
      (isFullAdmin() ? '<option value="role_admin">设为全权管理员</option><option value="role_user">取消管理员</option><option value="set_admin_scope">设置管理员权限范围</option>' : '') +
      '</select><input class="input" id="u-bulk-value" placeholder="金额 / 权限 full、finance、data" style="max-width:240px">' +
      '<button class="btn" id="u-bulk-apply">应用到已选</button><button class="btn ghost small" id="u-bulk-clear">清除选择</button></div>' +
      '<div id="u-list"><div class="empty">少女祈祷中…</div></div>' +
      '<div class="flex-between mt12"><button class="btn ghost small" id="u-prev">上一页</button><span class="muted" id="u-pageinfo"></span><button class="btn ghost small" id="u-next">下一页</button></div>' +
      '</div>';
    function load() {
      THP.api('admin.users', { q: usersQuery, page: usersPage }).then(function (d) {
        var box = THP.qs('#u-list', panel);
        if (!d.items.length) { box.innerHTML = '<div class="empty">没有找到用户</div>'; }
        else {
          var selected = {};
          var html = '<div class="table-wrap"><table class="thp-table"><thead><tr><th class="check-cell"><input type="checkbox" id="u-check-all" title="全选本页"></th><th>ID</th><th>昵称</th><th>FDT 余额</th><th>欠款</th><th>状态</th><th>风控</th><th>最近登录</th><th>操作</th></tr></thead><tbody>';
          d.items.forEach(function (u, i) {
            var stateBadges = '';
            if (u.role === 'admin') { stateBadges += ' <span class="badge ok">' + THP.esc(scopeLabel(u.admin_scope)) + '</span>'; }
            if (u.is_test) { stateBadges += ' <span class="badge warn">测试账号</span>'; }
            var ops = '';
            if (can('users_manage')) {
              ops += '<button class="btn small ghost" data-ubalance="' + i + '">代币</button> ' +
                '<button class="btn small ghost" data-uban="' + i + '">' + (u.banned ? '解封' : '封禁') + '</button> ' +
                '<button class="btn small ghost" data-upass="' + i + '">密码</button> ';
            }
            if (isFullAdmin()) {
              ops += '<button class="btn small ghost" data-urole="' + i + '">' + (u.role === 'admin' ? '取消管理' : '设管理员') + '</button> ';
              if (u.role === 'admin') { ops += '<button class="btn small ghost" data-usscope="' + i + '">权限</button> '; }
              if (u.role !== 'admin') {
                ops += '<button class="btn small ghost" data-uloan="' + i + '">清贷款</button> ' +
                  '<button class="btn small ghost" data-utest="' + i + '">' + (u.is_test ? '取消测试' : '标记测试') + '</button>';
              }
            }
            html += '<tr><td class="check-cell"><input type="checkbox" class="u-check" value="' + u.id + '"></td><td>' + u.id + '</td>' +
              '<td><b>' + THP.esc(u.username) + '</b>' + stateBadges + '</td>' +
              '<td class="pill-price">' + u.balance + ' FDT</td>' +
              '<td>' + (u.debt > 0 ? '<span class="badge warn">' + u.debt + ' FDT</span>' : '-') + '</td>' +
              '<td>' + (u.banned ? '<span class="badge err">已封禁</span>' : '<span class="badge ok">正常</span>') + '</td>' +
              '<td>' + (can('risk') && u.risk_24h > 0 ? '<button class="btn small danger" data-urisk="' + i + '">风险 ' + u.risk_24h + '</button>' : (u.risk_24h > 0 ? u.risk_24h : '-')) + '</td>' +
              '<td>' + THP.fmtTime(u.last_login_at) + '</td>' +
              '<td>' + (ops || '<span class="muted">只读</span>') + '</td></tr>';
          });
          html += '</tbody></table></div>';
          box.innerHTML = html;
          var checkAll = THP.qs('#u-check-all', box);
          var checks = Array.prototype.slice.call(box.querySelectorAll('.u-check'));
          function refreshBulkCount() {
            var count = Object.keys(selected).length;
            THP.qs('#u-selected-count', panel).textContent = count ? ('已选择 ' + count + ' 人') : '未选择';
            if (checkAll) { checkAll.checked = count > 0 && count === checks.length; }
          }
          checks.forEach(function (check) {
            check.addEventListener('change', function () {
              if (check.checked) { selected[check.value] = true; } else { delete selected[check.value]; }
              refreshBulkCount();
            });
          });
          if (checkAll) {
            checkAll.addEventListener('change', function () {
              checks.forEach(function (check) {
                check.checked = checkAll.checked;
                if (checkAll.checked) { selected[check.value] = true; } else { delete selected[check.value]; }
              });
              refreshBulkCount();
            });
          }
          THP.qs('#u-bulk-clear', panel).addEventListener('click', function () {
            Object.keys(selected).forEach(function (id) { delete selected[id]; });
            checks.forEach(function (check) { check.checked = false; });
            if (checkAll) { checkAll.checked = false; }
            refreshBulkCount();
          });
          THP.qs('#u-bulk-apply', panel).addEventListener('click', function () {
            var ids = Object.keys(selected).map(function (id) { return parseInt(id, 10); });
            if (!ids.length) { THP.toast('先勾选要处理的用户', 'err'); return; }
            var opSel = THP.qs('#u-bulk-op', panel);
            var op = opSel.value;
            var opText = opSel.options[opSel.selectedIndex].textContent;
            var value = THP.qs('#u-bulk-value', panel).value.trim();
            if ((op === 'add_balance' || op === 'set_balance' || op === 'set_admin_scope') && value === '') {
              THP.toast(op === 'set_admin_scope' ? '请填写 full、finance 或 data' : '请填写 FDT 数量', 'err');
              return;
            }
            THP.confirm('确认批量操作？', '将对已选 ' + ids.length + ' 人执行「' + opText + '」。金额和权限操作仅全权管理员可用。', '确认执行').then(function (yes) {
              if (!yes) { return; }
              var btn = THP.qs('#u-bulk-apply', panel);
              THP.busy(btn, true);
              THP.api('admin.user_bulk_update', { ids: ids, op: op, value: value }).then(function (r) {
                THP.toast('已批量处理 ' + r.updated + ' 人', 'ok');
                load();
              }).catch(function (e) { THP.toast(e.message, 'err'); }).finally(function () { THP.busy(btn, false); });
            });
          });
          box.querySelectorAll('[data-ubalance]').forEach(function (el) { el.addEventListener('click', function () { balanceModal(d.items[parseInt(el.getAttribute('data-ubalance'), 10)], load); }); });
          box.querySelectorAll('[data-uban]').forEach(function (el) { el.addEventListener('click', function () { banUser(d.items[parseInt(el.getAttribute('data-uban'), 10)], load); }); });
          box.querySelectorAll('[data-urole]').forEach(function (el) { el.addEventListener('click', function () { roleUser(d.items[parseInt(el.getAttribute('data-urole'), 10)], load); }); });
          box.querySelectorAll('[data-upass]').forEach(function (el) { el.addEventListener('click', function () { passUser(d.items[parseInt(el.getAttribute('data-upass'), 10)], load); }); });
          box.querySelectorAll('[data-usscope]').forEach(function (el) { el.addEventListener('click', function () { scopeUser(d.items[parseInt(el.getAttribute('data-usscope'), 10)], load); }); });
          box.querySelectorAll('[data-uloan]').forEach(function (el) { el.addEventListener('click', function () { clearLoans(d.items[parseInt(el.getAttribute('data-uloan'), 10)], load); }); });
          box.querySelectorAll('[data-utest]').forEach(function (el) { el.addEventListener('click', function () { testUser(d.items[parseInt(el.getAttribute('data-utest'), 10)], load); }); });
          box.querySelectorAll('[data-urisk]').forEach(function (el) {
            el.addEventListener('click', function () {
              var u = d.items[parseInt(el.getAttribute('data-urisk'), 10)];
              riskUser = u.id;
              riskType = '';
              riskPage = 1;
              THP.state.adminTab = 'risk';
              VIEWS.admin.render(content);
            });
          });
        }
        THP.qs('#u-pageinfo', panel).textContent = '第 ' + d.page + ' 页 / 共 ' + Math.max(1, Math.ceil(d.total / d.per)) + ' 页（' + d.total + ' 人）';
      }).catch(function (e) { THP.qs('#u-list', panel).innerHTML = '<div class="notice err">' + THP.esc(e.message) + '</div>'; });
    }
    THP.qs('#u-search', panel).addEventListener('click', function () { usersQuery = THP.qs('#u-q', panel).value.trim(); usersPage = 1; load(); });
    THP.qs('#u-q', panel).addEventListener('keydown', function (e) { if (e.key === 'Enter') { usersQuery = this.value.trim(); usersPage = 1; load(); } });
    THP.qs('#u-prev', panel).addEventListener('click', function () { if (usersPage > 1) { usersPage--; load(); } });
    THP.qs('#u-next', panel).addEventListener('click', function () { usersPage++; load(); });
    THP.qs('#u-create', panel).addEventListener('click', function () { createUserModal(load); });
    load();
  }

  // ================= 风控 =================
  function riskTypeName(type) {
    var map = {
      banned_word: '违禁词',
      throttle_blocked: '高频请求',
      device_conflict: '设备重复注册',
      device_fingerprint_conflict: '设备指纹疑似重复',
      invalid_code_burst: '连续错误代码'
    };
    return map[type] || type || '其他';
  }

  function riskLevel(sev) {
    sev = parseInt(sev, 10) || 0;
    if (sev >= 3) { return '<span class="badge err">高</span>'; }
    if (sev >= 2) { return '<span class="badge warn">中</span>'; }
    return '<span class="badge ok">低</span>';
  }

  function renderRisk(panel, content) {
    var THP = window.THP;
    panel.innerHTML = '<div class="card"><div class="empty">整理风控记录中…</div></div>';
    THP.api('admin.risk', { page: riskPage, type: riskType, user_id: riskUser }).then(function (d) {
      var s = d.summary || {};
      function card(k, v) { return '<div class="stat-card"><div class="k">' + k + '</div><div class="v">' + v + '</div></div>'; }

      var usersHtml = '';
      if (!(d.users || []).length) {
        usersHtml = '<div class="empty">近 7 天没有风险记录</div>';
      } else {
        usersHtml = '<div class="table-wrap"><table class="thp-table risk-table"><thead><tr><th>用户</th><th>事件数</th><th>风险分</th><th>最近时间</th><th>最近 IP</th><th>操作</th></tr></thead><tbody>';
        d.users.forEach(function (u) {
          var name = u.username || '未登录 / 匿名';
          usersHtml += '<tr><td><b>' + THP.esc(name) + '</b>' + (u.user_id ? ' <span class="muted">#' + u.user_id + '</span>' : '') + '</td>' +
            '<td>' + u.events + '</td>' +
            '<td><b>' + u.score + '</b></td>' +
            '<td>' + THP.fmtTime(u.last_at) + '</td>' +
            '<td>' + THP.esc(u.last_ip || '-') + '</td>' +
            '<td>' + (u.user_id ? '<button class="btn small ghost" data-risk-user="' + u.user_id + '">只看 TA</button>' : '-') + '</td></tr>';
        });
        usersHtml += '</tbody></table></div>';
      }

      var typeOptions = '<option value="">全部类型</option>';
      [['banned_word', '违禁词'], ['throttle_blocked', '高频请求'], ['device_conflict', '设备重复注册'], ['device_fingerprint_conflict', '设备指纹疑似重复'], ['invalid_code_burst', '连续错误代码']].forEach(function (t) {
        typeOptions += '<option value="' + t[0] + '"' + (riskType === t[0] ? ' selected' : '') + '>' + t[1] + '</option>';
      });

      var eventsHtml = '';
      if (!(d.events || []).length) {
        eventsHtml = '<div class="empty">没有符合条件的风险事件</div>';
      } else {
        eventsHtml = '<div class="table-wrap"><table class="thp-table risk-table"><thead><tr><th>时间</th><th>用户</th><th>类型</th><th>级别</th><th>详情</th><th>IP</th><th>设备</th></tr></thead><tbody>';
        d.events.forEach(function (ev) {
          var name = ev.username || '未登录 / 匿名';
          var device = String(ev.device_key || '');
          if (device.length > 12) { device = device.slice(0, 10) + '…'; }
          eventsHtml += '<tr><td class="muted">' + THP.fmtTime(ev.created_at) + '</td>' +
            '<td><b>' + THP.esc(name) + '</b>' + (ev.user_id ? ' <span class="muted">#' + ev.user_id + '</span>' : '') + '</td>' +
            '<td>' + THP.esc(riskTypeName(ev.type)) + '</td>' +
            '<td>' + riskLevel(ev.severity) + '</td>' +
            '<td style="min-width:220px">' + THP.esc(ev.detail || '') + '</td>' +
            '<td>' + THP.esc(ev.ip || '-') + '</td>' +
            '<td class="muted" title="' + THP.esc(ev.device_key || '') + '">' + THP.esc(device || '-') + '</td></tr>';
        });
        eventsHtml += '</tbody></table></div>';
      }

      var pages = Math.max(1, Math.ceil((d.total || 0) / (d.per || 30)));
      panel.innerHTML =
        '<div class="stat-grid">' +
        card('24h 风险事件', s.events_24h || 0) + card('24h 高风险用户', s.high_users_24h || 0) +
        card('24h 违禁词', s.banned_24h || 0) + card('24h 高频请求', s.throttle_24h || 0) +
        card('24h 设备重复', s.device_24h || 0) + card('24h 连续错码', s.code_24h || 0) +
        '</div>' +
        '<div class="card"><div class="card-title"><span class="dot"></span>高风险用户（近 7 天）</div>' +
        '<div class="muted" style="margin-bottom:8px">风险分 = 事件严重度合计（高=3 / 中=2 / 低=1）。同一设备重复注册、命中违禁词会优先关注。</div>' +
        usersHtml + '</div>' +
        '<div class="card"><div class="flex-between wrap gap8"><div class="card-title" style="margin:0"><span class="dot"></span>风险事件</div>' +
        '<div class="flex gap8 wrap" style="align-items:center">' +
        '<select class="input" id="risk-type" style="max-width:180px">' + typeOptions + '</select>' +
        (riskUser ? '<span class="badge warn">用户 #' + riskUser + '</span><button class="btn ghost small" id="risk-clear-user">清除用户筛选</button>' : '') +
        '<button class="btn ghost small" id="risk-refresh">刷新</button></div></div>' +
        '<div class="mt12">' + eventsHtml + '</div>' +
        '<div class="flex-between mt12"><button class="btn ghost small" id="risk-prev">上一页</button><span class="muted">第 ' + (d.page || 1) + ' 页 / 共 ' + pages + ' 页（' + (d.total || 0) + ' 条）</span><button class="btn ghost small" id="risk-next">下一页</button></div>' +
        '</div>';

      THP.qs('#risk-type', panel).addEventListener('change', function () {
        riskType = this.value;
        riskPage = 1;
        renderRisk(panel, content);
      });
      var clearUser = THP.qs('#risk-clear-user', panel);
      if (clearUser) { clearUser.addEventListener('click', function () { riskUser = 0; riskPage = 1; renderRisk(panel, content); }); }
      THP.qs('#risk-refresh', panel).addEventListener('click', function () { renderRisk(panel, content); });
      THP.qs('#risk-prev', panel).addEventListener('click', function () { if (riskPage > 1) { riskPage--; renderRisk(panel, content); } });
      THP.qs('#risk-next', panel).addEventListener('click', function () { if (riskPage < pages) { riskPage++; renderRisk(panel, content); } });
      panel.querySelectorAll('[data-risk-user]').forEach(function (el) {
        el.addEventListener('click', function () { riskUser = parseInt(el.getAttribute('data-risk-user'), 10) || 0; riskPage = 1; renderRisk(panel, content); });
      });
    }).catch(function (e) {
      panel.innerHTML = '<div class="notice err">' + THP.esc(e.message) + '</div>';
    });
  }

  function balanceModal(user, done) {
    var THP = window.THP;
    var m = THP.modal(
      '<h3>调整「' + THP.esc(user.username) + '」的 FDT</h3>' +
      '<p class="muted">当前余额 ' + user.balance + ' FDT</p>' +
      '<div class="segs mt12" id="bm-mode"><div class="seg active" data-m="add">增加 / 减少</div><div class="seg" data-m="set">直接设为</div></div>' +
      '<div class="field"><label id="bm-label">变动数量（减少请填负数，如 -50）</label><input class="input" id="bm-value" inputmode="numeric" placeholder="例如 100"></div>' +
      '<div class="modal-actions"><button class="btn ghost" data-close>取消</button><button class="btn" id="bm-ok">确认</button></div>'
    );
    var mode = 'add';
    m.el.querySelectorAll('#bm-mode .seg').forEach(function (el) {
      el.addEventListener('click', function () {
        mode = el.getAttribute('data-m');
        m.el.querySelectorAll('#bm-mode .seg').forEach(function (x) { x.classList.toggle('active', x === el); });
        THP.qs('#bm-label', m.el).textContent = mode === 'add' ? '变动数量（减少请填负数，如 -50）' : '要把余额设为多少';
      });
    });
    THP.qs('#bm-ok', m.el).addEventListener('click', function () {
      var v = parseInt(THP.qs('#bm-value', m.el).value, 10);
      if (isNaN(v)) { THP.toast('请输入数字', 'err'); return; }
      var btn = this;
      THP.busy(btn, true);
      THP.api('admin.user_update', { user_id: user.id, op: mode, value: v }).then(function (r) {
        m.close();
        THP.toast('已调整为 ' + r.balance + ' FDT', 'ok');
        done();
      }).catch(function (e) { THP.toast(e.message, 'err'); }).finally(function () { THP.busy(btn, false); });
    });
  }

  function clearLoans(user, done) {
    var THP = window.THP;
    THP.confirm(
      '清空「' + user.username + '」的贷款数据？',
      '将删除该游客全部贷款与利息记录，把欠款归零，并重置本小时的贷款次数。\n已经到账的 FDT 余额不会回收，账号、公司和持股不受影响。',
      '确认清空贷款',
      'danger'
    ).then(function (yes) {
      if (!yes) { return; }
      THP.api('admin.user_update', { user_id: user.id, op: 'clear_loans' }).then(function (r) {
        THP.toast('已清除 ' + r.cleared + ' 条贷款记录，欠款归零', 'ok');
        done();
      }).catch(function (e) { THP.toast(e.message, 'err'); });
    });
  }

  function banUser(user, done) {
    var THP = window.THP;
    var op = user.banned ? 'unban' : 'ban';
    THP.confirm(op === 'ban' ? '封禁用户？' : '解封用户？', '用户：' + user.username, op === 'ban' ? '确认封禁' : '确认解封', op === 'ban' ? 'danger' : '').then(function (yes) {
      if (!yes) { return; }
      THP.api('admin.user_update', { user_id: user.id, op: op }).then(function () { THP.toast('操作完成', 'ok'); done(); }).catch(function (e) { THP.toast(e.message, 'err'); });
    });
  }

  function roleUser(user, done) {
    var THP = window.THP;
    var op = user.role === 'admin' ? 'role_user' : 'role_admin';
    THP.confirm(op === 'role_admin' ? '设为全权管理员？' : '取消管理员？', '用户：' + user.username + '\n' + (op === 'role_admin' ? '将获得全部后台权限；建议先重置为至少 12 位强密码。' : '将失去后台权限。'), '确认').then(function (yes) {
      if (!yes) { return; }
      THP.api('admin.user_update', { user_id: user.id, op: op }).then(function () { THP.toast('操作完成', 'ok'); done(); }).catch(function (e) { THP.toast(e.message, 'err'); });
    });
  }

  function scopeUser(user, done) {
    var THP = window.THP;
    var m = THP.modal(
      '<h3>调整「' + THP.esc(user.username) + '」的权限</h3>' +
      '<div class="field"><label>权限范围</label><select class="input" id="as-scope">' +
      '<option value="full"' + (user.admin_scope === 'full' ? ' selected' : '') + '>全权管理员：全部功能</option>' +
      '<option value="finance"' + (user.admin_scope === 'finance' ? ' selected' : '') + '>财务管理员：概览 + 发币收币</option>' +
      '<option value="data"' + (user.admin_scope === 'data' ? ' selected' : '') + '>数据管理员：用户 / 内容 / 行情 / 设置</option>' +
      '</select></div>' +
      '<div class="notice info">权限变更后对方下次刷新页面生效。发币收币与活动重置始终按最小权限隔离。</div>' +
      '<div class="modal-actions"><button class="btn ghost" data-close>取消</button><button class="btn" id="as-ok">保存</button></div>'
    );
    THP.qs('#as-ok', m.el).addEventListener('click', function () {
      var btn = this;
      THP.busy(btn, true);
      THP.api('admin.user_update', { user_id: user.id, op: 'set_admin_scope', value: THP.qs('#as-scope', m.el).value }).then(function (r) {
        m.close();
        THP.toast('权限已调整为' + scopeLabel(r.admin_scope), 'ok');
        done();
      }).catch(function (e) { THP.toast(e.message, 'err'); }).finally(function () { THP.busy(btn, false); });
    });
  }

  function testUser(user, done) {
    var THP = window.THP;
    var next = user.is_test ? 'test_off' : 'test_on';
    THP.confirm(user.is_test ? '取消测试标记？' : '标记为压测账号？', '测试账号会从排行榜、统计与活动数据中排除，可在「活动运维」中批量清理。\n用户：' + user.username, '确认').then(function (yes) {
      if (!yes) { return; }
      THP.api('admin.user_update', { user_id: user.id, op: next }).then(function () {
        THP.toast(user.is_test ? '已取消测试标记' : '已标记为测试账号', 'ok');
        done();
      }).catch(function (e) { THP.toast(e.message, 'err'); });
    });
  }

  function passUser(user, done) {
    var THP = window.THP;
    var m = THP.modal(
      '<h3>重置「' + THP.esc(user.username) + '」的密码</h3>' +
      '<div class="field"><label>' + (user.role === 'admin' ? '管理员新密码（至少 12 位）' : '新密码（至少 6 位）') + '</label><input class="input" id="np-pass" type="password"></div>' +
      '<div class="modal-actions"><button class="btn ghost" data-close>取消</button><button class="btn" id="np-ok">确认重置</button></div>'
    );
    THP.qs('#np-ok', m.el).addEventListener('click', function () {
      var pw = THP.qs('#np-pass', m.el).value;
      if (pw.length < (user.role === 'admin' ? 12 : 6)) { THP.toast(user.role === 'admin' ? '管理员密码至少 12 位' : '密码至少 6 位', 'err'); return; }
      var btn = this;
      THP.busy(btn, true);
      THP.api('admin.user_update', { user_id: user.id, op: 'reset_password', value: pw }).then(function () {
        m.close(); THP.toast('密码已重置', 'ok'); done();
      }).catch(function (e) { THP.toast(e.message, 'err'); }).finally(function () { THP.busy(btn, false); });
    });
  }

  function createUserModal(done) {
    var THP = window.THP;
    var m = THP.modal(
      '<h3>新建账号</h3>' +
      '<div class="field"><label>昵称</label><input class="input" id="cu-name" maxlength="16"></div>' +
      '<div class="field"><label id="cu-pass-label">密码（至少 6 位）</label><input class="input" id="cu-pass" type="password"></div>' +
      '<div class="field"><label>身份</label><select class="input" id="cu-role"><option value="user">普通用户</option>' + (isFullAdmin() ? '<option value="admin">管理员</option>' : '') + '</select></div>' +
      '<div class="field hidden" id="cu-scope-wrap"><label>管理员权限</label><select class="input" id="cu-scope"><option value="full">全权管理员</option><option value="finance">财务管理员</option><option value="data">数据管理员</option></select></div>' +
      '<div class="modal-actions"><button class="btn ghost" data-close>取消</button><button class="btn" id="cu-ok">创建</button></div>'
    );
    function syncRole() {
      var admin = THP.qs('#cu-role', m.el).value === 'admin';
      THP.qs('#cu-scope-wrap', m.el).classList.toggle('hidden', !admin);
      THP.qs('#cu-pass-label', m.el).textContent = admin ? '管理员密码（至少 12 位，至少三类字符）' : '密码（至少 6 位）';
    }
    THP.qs('#cu-role', m.el).addEventListener('change', syncRole);
    syncRole();
    THP.qs('#cu-ok', m.el).addEventListener('click', function () {
      var btn = this;
      THP.busy(btn, true);
      THP.api('admin.user_create', {
        username: THP.qs('#cu-name', m.el).value.trim(),
        password: THP.qs('#cu-pass', m.el).value,
        role: THP.qs('#cu-role', m.el).value,
        admin_scope: THP.qs('#cu-scope', m.el).value
      }).then(function (r) {
        m.close(); THP.toast('已创建「' + r.username + '」', 'ok'); done();
      }).catch(function (e) { THP.toast(e.message, 'err'); }).finally(function () { THP.busy(btn, false); });
    });
  }

  // ================= 发币 / 收币 =================
  function renderTrade(panel, content) {
    var THP = window.THP;
    var state = THP.state;
    panel.innerHTML =
      '<div class="card"><div class="card-title"><span class="dot"></span>发币（游客扫码 / 输码获得 FDT）</div>' +
      '<p class="muted">输入面额生成六位代码和二维码，每个代码只能被使用 1 次（二维码和代码共用一次机会）。</p>' +
      '<div class="amount-row"><input class="input" id="iss-amount" inputmode="numeric" placeholder="面额（FDT），如 20" maxlength="6">' +
      '<input class="input" id="iss-count" inputmode="numeric" value="1" maxlength="2" style="max-width:80px" title="生成数量">' +
      '<button class="btn" id="iss-gen">生成</button></div>' +
      '<div class="field"><input class="input" id="iss-note" maxlength="60" placeholder="备注（可选，例如：服务台专属码）"></div>' +
      '<div id="iss-result" class="mt12"></div>' +
      '</div>' +
      '<div class="card"><div class="card-title"><span class="dot"></span>收币（扫描游客付款码）</div>' +
      '<p class="muted">扫描游客「使用代币」页面的二维码，或让他报出六位付款码，确认后完成收款。</p>' +
      '<div class="flex gap12 wrap mt12"><button class="btn green" id="col-scan">📷 扫码收币</button>' +
      '<input class="input input-code grow" id="col-code" inputmode="numeric" maxlength="6" placeholder="或输入六位付款码" style="max-width:220px">' +
      '<button class="btn green" id="col-ok">确认收币</button></div>' +
      '<div id="col-result" class="mt12"></div>' +
      '</div>' +
      '<div class="card"><div class="card-title"><span class="dot"></span>最近发币记录</div><div id="iss-list"><div class="empty">加载中…</div></div></div>';

    var amountInput = THP.qs('#iss-amount', panel);
    amountInput.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 6); });
    var countInput = THP.qs('#iss-count', panel);
    countInput.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 2); });
    THP.qs('#iss-gen', panel).addEventListener('click', function () {
      var amount = parseInt(amountInput.value, 10);
      var count = Math.max(1, Math.min(20, parseInt(countInput.value, 10) || 1));
      if (!amount || amount < 1) { THP.toast('请输入正确的面额', 'err'); return; }
      var btn = this;
      THP.busy(btn, true);
      THP.api('admin.issue_create', { amount: amount, count: count, note: THP.qs('#iss-note', panel).value.trim() }).then(function (r) {
        var box = THP.qs('#iss-result', panel);
        box.innerHTML = '<div class="card-title"><span class="dot"></span>本次生成的代码（' + r.amount + ' FDT × ' + r.codes.length + '）</div><div id="iss-qrs"></div>';
        r.codes.forEach(function (code) {
          var row = document.createElement('div');
          row.className = 'issue-code-item';
          row.innerHTML = '<div class="qr-mini" data-qc="' + code + '"></div>' +
            '<div><div class="cc">' + code + '</div><div class="muted">扫二维码或输入代码领取 ' + r.amount + ' FDT</div></div>' +
            '<div class="grow" style="text-align:right"><button class="btn small ghost" data-dl="' + code + '">下载二维码</button></div>';
          THP.qs('#iss-qrs', box).appendChild(row);
          var base = THP.state.base_url || (location.origin + location.pathname.replace(/[^\/]*$/, ''));
          THP.renderQR(row.querySelector('[data-qc]'), base + '?c=' + code, 74);
          row.querySelector('[data-dl]').addEventListener('click', function () { THP.downloadQR(row.querySelector('[data-qc]'), 'thp-issue-' + code + '.png'); });
        });
        THP.toast('已生成 ' + r.codes.length + ' 个发币码', 'ok');
        loadIssues();
      }).catch(function (e) { THP.toast(e.message, 'err'); }).finally(function () { THP.busy(btn, false); });
    });

    function doCollect(code) {
      THP.confirm('确认收币？', '付款码：' + code + '\n确认后将从该游客账户扣除对应 FDT。', '确认收款', 'green').then(function (yes) {
        if (!yes) { return; }
        THP.api('admin.collect', { code: code }).then(function (r) {
          THP.qs('#col-result', panel).innerHTML = '<div class="result-banner ok">✨ 已收到来自「' + THP.esc(r.username) + '」的 ' + r.amount + ' FDT</div>';
          THP.toast('收款成功', 'ok');
        }).catch(function (e) {
          THP.qs('#col-result', panel).innerHTML = '<div class="result-banner err">💧 ' + THP.esc(e.message) + '</div>';
          THP.toast(e.message, 'err');
        });
      });
    }
    var colCode = THP.qs('#col-code', panel);
    colCode.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 6); });
    THP.qs('#col-ok', panel).addEventListener('click', function () {
      var code = colCode.value.trim();
      if (code.length !== 6) { THP.toast('请输入六位付款码', 'err'); return; }
      doCollect(code);
    });
    THP.qs('#col-scan', panel).addEventListener('click', function () {
      THP.openScanner(function (text) {
        var code = THP.extractCode(text);
        if (!code) { THP.toast('二维码里没有找到六位代码', 'err'); return; }
        colCode.value = code;
        doCollect(code);
      });
    });
    if (state.collectCode) {
      var cc = state.collectCode;
      state.collectCode = '';
      colCode.value = cc;
      doCollect(cc);
    }

    function loadIssues() {
      THP.api('admin.issue_list').then(function (d) {
        var box = THP.qs('#iss-list', panel);
        if (!d.items.length) { box.innerHTML = '<div class="empty">还没有发过币</div>'; return; }
        var html = '<div class="table-wrap"><table class="thp-table"><thead><tr><th>代码</th><th>面额</th><th>状态</th><th>使用人</th><th>时间</th><th>操作</th></tr></thead><tbody>';
        d.items.forEach(function (it, i) {
          var badge = it.status === 'pending' ? '<span class="badge warn">待使用</span>' : (it.status === 'used' ? '<span class="badge ok">已使用</span>' : '<span class="badge gray">已作废</span>');
          html += '<tr><td class="pill-price">' + it.code + '</td><td>' + it.amount + '</td><td>' + badge + '</td>' +
            '<td>' + THP.esc(it.used_by_name || '-') + '</td><td>' + THP.fmtTime(it.used_at || it.created_at) + '</td>' +
            '<td>' + (it.status === 'pending' ? '<button class="btn small ghost" data-revoke="' + i + '">作废</button>' : '-') + '</td></tr>';
        });
        html += '</tbody></table></div>';
        box.innerHTML = html;
        box.querySelectorAll('[data-revoke]').forEach(function (el) {
          el.addEventListener('click', function () {
            var it = d.items[parseInt(el.getAttribute('data-revoke'), 10)];
            THP.confirm('作废这个发币码？', '代码 ' + it.code + '（' + it.amount + ' FDT）作废后无法再使用。', '确认作废', 'danger').then(function (yes) {
              if (!yes) { return; }
              THP.api('admin.issue_revoke', { id: it.id }).then(function () { THP.toast('已作废', 'ok'); loadIssues(); }).catch(function (e) { THP.toast(e.message, 'err'); });
            });
          });
        });
      });
    }
    loadIssues();
  }

  // ================= 大盘 =================
  function sparkSvg(points, w, h) {
    if (!points || !points.length) { return ''; }
    if (points.length === 1) { points = [points[0], points[0]]; }
    var min = Math.min.apply(null, points), max = Math.max.apply(null, points);
    var span = Math.max(1, max - min);
    var coords = points.map(function (v, i) {
      return Math.round(i * (w / (points.length - 1)) * 10) / 10 + ' ' + Math.round((h - 4 - (v - min) / span * (h - 8)) * 10) / 10;
    });
    return '<svg viewBox="0 0 ' + w + ' ' + h + '" style="width:150px;height:40px"><path fill="none" stroke="#e05a6d" stroke-width="1.8" d="M' + coords.join(' L') + '"/></svg>';
  }

  var MKT_RANGES = [[10, '过去 10 分钟'], [30, '过去 30 分钟'], [60, '过去 1 小时'], [120, '过去 2 小时'], [180, '过去 3 小时'], [300, '过去 5 小时'], [720, '过去 12 小时']];
  var mktRange = 60;

  // 今天的走势按所选范围取分钟数据，历史日期只有每小时的点
  function companyChart(d, companyId, stat) {
    var path = d.trend && d.trend[String(companyId)] ? d.trend[String(companyId)] : null;
    if (path && path.length >= 2) { return sparkSvg(path, 150, 40); }
    return stat ? sparkSvg(stat.points, 150, 40) : '';
  }

  var SOURCE_NAMES = { seed: '内置', local: '本地生成', api: 'AI 生成', import: '导入' };
  var STYLE_FALLBACK = [
    { id: 'mild', name: '温和上涨', builtin: true, description: '单步 1~5 · 整体温和上涨' },
    { id: 'volatile', name: '大幅波动', builtin: true, description: '单步最高 20 · 大起大落' },
    { id: 'realistic', name: '拟真极端', builtin: true, description: '跳水 / 飞升 · 可跌到 0.01' }
  ];

  function fmtNum(n) {
    var neg = n < 0;
    return (neg ? '-' : '') + Math.abs(n).toLocaleString('zh-CN');
  }
  // 股价显示：整数就整数，小数最多两位（0.05 / 7.5 / 12.34）
  function fmtP(n) {
    if (n === null || n === undefined || typeof n !== 'number') { return '-'; }
    return String(Math.round(n * 100) / 100);
  }

  // 股市盈亏排行榜：赚最多 / 亏最多
  function renderRank(box, rank) {
    rank = rank || { earn: [], lose: [] };
    function table(title, list, cls) {
      if (!list.length) { return '<div class="empty">暂无（还没有人' + (cls === 'up' ? '赚到钱' : '亏到钱') + '）</div>'; }
      var h = '<div class="table-wrap"><table class="thp-table"><thead><tr><th>' + title + '</th><th>ID</th><th>累计盈亏（FDT）</th><th>已落袋（FDT）</th><th>持仓浮动（FDT）</th></tr></thead><tbody>';
      list.forEach(function (it) {
        h += '<tr><td><b>' + THP.esc(it.username) + '</b></td><td class="muted">#' + it.id + '</td>' +
          '<td class="' + cls + '"><b>' + (it.total > 0 ? '+' : '') + fmtNum(it.total) + '</b></td>' +
          '<td>' + (it.realized > 0 ? '+' : '') + fmtNum(it.realized) + '</td>' +
          '<td>' + (it.unrealized > 0 ? '+' : '') + fmtNum(it.unrealized) + '</td></tr>';
      });
      return h + '</tbody></table></div>';
    }
    box.innerHTML =
      '<div><b>💰 赚得最多</b></div>' + table('赚得最多', rank.earn, 'up') +
      '<div class="mt12"><b>💸 亏得最多</b></div>' + table('亏得最多', rank.lose, 'down');
  }

  function renderMarket(panel, content) {
    if (window.THP_ADMIN_MARKET) {
      window.THP_ADMIN_MARKET.render(panel);
      return;
    }
    renderLegacyMarket(panel, content);
  }

  function renderLegacyMarket(panel, content) {
    var THP = window.THP;
    var styles = STYLE_FALLBACK.slice();
    var styleMap = {};
    var marketCompanies = [];
    var previousStyle = 'mild';

    function rebuildStyleMap(list) {
      styles = (Array.isArray(list) && list.length) ? list.slice() : STYLE_FALLBACK.slice();
      styleMap = {};
      styles.forEach(function (s) { styleMap[String(s.id)] = s; });
    }
    function styleLabel(id) {
      var s = styleMap[String(id)];
      return s ? s.name : (String(id || '') || '-');
    }
    function styleOptions(selected) {
      return styles.map(function (s) {
        var label = (s.builtin ? '' : '自定义：') + s.name;
        if (s.description) { label += '（' + s.description + '）'; }
        return '<option value="' + THP.esc(s.id) + '"' + (String(s.id) === String(selected) ? ' selected' : '') + '>' + THP.esc(label) + '</option>';
      }).join('') + '<option value="__new__">＋ 新建走势风格…</option><option value="__manage__">⚙ 管理走势风格…</option>';
    }
    function setStyleSelect(selected) {
      var sel = THP.qs('#mk-style', panel);
      if (!sel) { return; }
      var current = selected || sel.value || 'mild';
      if (current === '__new__' || !styleMap[String(current)]) { current = 'mild'; }
      sel.innerHTML = styleOptions(current);
      sel.value = current;
      previousStyle = current;
    }
    rebuildStyleMap(styles);
    panel.innerHTML =
      '<div class="card">' +
      '<div class="card-title"><span class="dot"></span>走势文件管理</div>' +
      '<div class="muted">走势文件按名字管理、不再区分日期；同一时间只有一份生效，切换后全场立即使用新文件。生成 / 导入的新文件不会自动替换当前文件，需要手动切换。</div>' +
      '<div id="mk-active" class="mt12"></div>' +
      '<div id="mk-files" class="mt8"></div>' +
      '</div>' +
      '<div class="card mt12">' +
      '<div class="card-title"><span class="dot"></span>生成新走势（不会自动切换）</div>' +
      '<div class="flex gap8 wrap">' +
      '<input class="input" id="mk-name" maxlength="20" placeholder="文件名字（可留空，自动命名）" style="max-width:240px">' +
          '<select class="input" id="mk-style" style="max-width:360px">' + styleOptions('mild') + '</select>' +
      '<button class="btn" id="mk-local">🎲 本地生成</button>' +
      '<button class="btn blue" id="mk-ai">🤖 用 DeepSeek 生成</button>' +
      '</div>' +
      '<div class="field mt12"><label>DeepSeek 提示词（可选）：描述你想要的走势，例如「早上平淡，中午大起大落，尾盘拉升」</label>' +
      '<textarea class="input" id="mk-prompt" rows="3" placeholder="写给 AI 的走势描述指令，留空则按所选风格默认生成"></textarea></div>' +
      '<div class="flex gap8 wrap">' +
      '<label class="btn ghost" style="cursor:pointer">📥 导入 JSON（作为新文件）<input type="file" id="mk-import" accept=".json,application/json" style="display:none"></label>' +
      '</div>' +
      '</div>' +
      '<div class="card mt12">' +
      '<div class="card-title"><span class="dot"></span>大盘数据</div>' +
      '<div class="range-row"><label for="mk-range">走势范围</label><select class="input" id="mk-range">' +
      MKT_RANGES.map(function (r) { return '<option value="' + r[0] + '"' + (r[0] === mktRange ? ' selected' : '') + '>' + r[1] + '</option>'; }).join('') +
      '</select><button class="btn ghost small" id="mk-load">刷新信息</button></div>' +
      '<div class="notice info mt12">导入 / 生成出错时会保留原有文件；8 点前与 17 点后只会有较小波动。拟真风格里便宜的股票还可能继续跌（最低 0.01），抄底需谨慎。</div>' +
      '<div id="mk-info" class="mt12"></div>' +
      '</div>' +
      '<div class="card mt12">' +
      '<div class="card-title"><span class="dot"></span>股票盈亏排行榜</div>' +
      '<div class="muted">按「股市累计盈亏」排序 = 已落袋盈亏 + 当前持仓浮动盈亏；消费、转账、借款都不影响这个数值。</div>' +
      '<div id="mk-rank" class="mt12"></div>' +
      '</div>';

    function styleBadge(style) {
      var meta = styleMap[String(style)];
      if (!meta) { return '<span class="badge gray">未知风格</span>'; }
      if (meta.id === 'volatile') { return '<span class="badge warn">大幅波动</span>'; }
      if (meta.id === 'realistic') { return '<span class="badge err">拟真极端</span>'; }
      if (meta.id === 'mild') { return '<span class="badge ok">温和上涨</span>'; }
      return '<span class="badge gray">自定义</span>';
    }

    function renderActive(d) {
      var a = d.active || {};
      var html = '';
      if (a.warning) { html += '<div class="notice warn">⚠️ ' + THP.esc(a.warning) + '</div>'; }
      html += '<div class="muted">当前生效：<b>' + THP.esc(a.name || a.slug || '-') + '</b> ' + styleBadge(a.style) +
        ' · 来源：' + THP.esc(SOURCE_NAMES[a.source] || a.source || '-') +
        ' · 生成时间：' + THP.esc(a.generated_at || '-') +
        ' · 实际使用：' + THP.esc(a.used || '-') + '</div>';
      THP.qs('#mk-active', panel).innerHTML = html;
    }

    function renderFiles(d) {
      var box = THP.qs('#mk-files', panel);
      var files = d.files || [];
      if (!files.length) { box.innerHTML = '<div class="empty">还没有走势文件</div>'; return; }
      var html = '<div class="table-wrap"><table class="thp-table"><thead><tr><th>名字</th><th>风格</th><th>来源</th><th>生成时间</th><th>状态</th><th>操作</th></tr></thead><tbody>';
      files.forEach(function (f, i) {
        var status = [];
        if (f.active) { status.push('<span class="badge ok">当前生效</span>'); }
        if (f.protected) { status.push('<span class="badge gray">内置保底</span>'); }
        if (!f.ok) { status.push('<span class="badge err">损坏</span>'); }
        if (!status.length) { status.push('<span class="muted">-</span>'); }
        var ops = '';
        if (!f.active && f.ok) { ops += '<button class="btn small" data-i="' + i + '" data-op="use">切换为当前</button> '; }
        ops += '<a class="btn small ghost" href="api.php?action=admin.market_export&slug=' + encodeURIComponent(f.slug) + '" download>下载</a>';
        if (!f.protected && !f.active) { ops += ' <button class="btn small danger" data-i="' + i + '" data-op="del">删除</button>'; }
        html += '<tr><td><b>' + THP.esc(f.name) + '</b><div class="muted">' + THP.esc(f.slug) + '.php · ' + f.companies + ' 家公司</div>' +
          (f.prompt ? '<div class="muted">提示词：' + THP.esc(f.prompt).slice(0, 60) + '</div>' : '') +
          (!f.ok ? '<div class="muted">' + THP.esc(f.error) + '</div>' : '') +
          '</td><td>' + THP.esc(f.style_name || styleLabel(f.style)) + '</td><td>' + THP.esc(SOURCE_NAMES[f.source] || f.source || '-') + '</td>' +
          '<td>' + THP.esc(THP.fmtTime ? THP.fmtTime(f.created_at) : f.created_at) + '</td>' +
          '<td>' + status.join(' ') + '</td><td>' + ops + '</td></tr>';
      });
      html += '</tbody></table></div>';
      box.innerHTML = html;
      box.querySelectorAll('[data-op]').forEach(function (el) {
        var f = files[parseInt(el.getAttribute('data-i'), 10)];
        var op = el.getAttribute('data-op');
        el.addEventListener('click', function () {
          if (op === 'use') {
            THP.busy(el, true);
            THP.api('admin.market_switch', { slug: f.slug }).then(function () {
              THP.toast('已切换到「' + f.name + '」', 'ok');
              loadInfo();
            }).catch(function (e) { THP.toast(e.message, 'err'); }).finally(function () { THP.busy(el, false); });
          } else if (op === 'del') {
            THP.confirm('删除走势文件？', '「' + f.name + '」删除后无法恢复。', '确认删除', 'danger').then(function (yes) {
              if (!yes) { return; }
              THP.api('admin.market_delete', { slug: f.slug }).then(function () { THP.toast('已删除', 'ok'); loadInfo(); }).catch(function (e) { THP.toast(e.message, 'err'); });
            });
          }
        });
      });
    }

    function loadInfo() {
      THP.api('admin.market_info', { range: mktRange }, { method: 'GET' }).then(function (d) {
        if (Array.isArray(d.styles) && d.styles.length) {
          rebuildStyleMap(d.styles);
          setStyleSelect(previousStyle);
        }
        marketCompanies = d.companies || [];
        renderActive(d);
        renderFiles(d);
        var html = '';
        var idx = d.index || {};
        var idxSeries = Array.isArray(idx.series) ? idx.series : [];
        var idxPct = Number(idx.change_percent || 0);
        html += '<div class="flex-between wrap gap8" style="align-items:center">' +
          '<div><b>大盘指数</b> <span style="font-size:22px;font-weight:800">' + (idx.value || 1000) + '</span> ' +
          '<span class="' + (idxPct >= 0 ? 'up' : 'down') + '">' + (idxPct >= 0 ? '+' : '') + idxPct + '%</span></div>' +
          '<div>' + sparkSvg(idxSeries, 300, 52) + '</div>' +
          '<div class="muted">视图：过去 ' + (d.view_range_minutes || mktRange) + ' 分钟</div></div>';
        html += '<div class="table-wrap"><table class="thp-table"><thead><tr><th>公司</th><th>购买人数</th><th>开盘</th><th>收盘</th><th>最低</th><th>最高</th><th>涨幅</th><th>走势</th><th>操作</th></tr></thead><tbody>';
        (d.companies || []).forEach(function (c) {
          var st = c.stat;
          var holders = (typeof c.holders === 'number') ? c.holders : 0;
          html += '<tr><td><b>' + THP.esc(c.name) + '</b></td><td>' + holders + ' 人</td>';
          if (st) {
            html += '<td>' + fmtP(st.open) + '</td><td>' + fmtP(st.close) + '</td><td>' + fmtP(st.min) + '</td><td>' + fmtP(st.max) + '</td>' +
              '<td class="' + (st.change_percent >= 0 ? 'up' : 'down') + '">' + (st.change_percent >= 0 ? '+' : '') + st.change_percent + '%</td>' +
              '<td>' + companyChart(d, c.id, st) + '</td>';
          } else { html += '<td colspan="6" class="muted">无数据</td>'; }
          html += '<td><button class="btn small ghost" data-ren="' + c.id + '">改名</button></td></tr>';
        });
        html += '</tbody></table></div>';
        html += '<div class="muted mt8">导出的 JSON 会带上每家公司的「购买人数」和开盘/收盘摘要，方便交给 AI 参考；导入时这些字段会被忽略，以服务器实际记录为准。</div>';
        var infoBox = THP.qs('#mk-info', panel);
        infoBox.innerHTML = html;
        infoBox.querySelectorAll('[data-ren]').forEach(function (el) {
          el.addEventListener('click', function () { renameCompany(el.getAttribute('data-ren'), d, loadInfo); });
        });
        renderRank(THP.qs('#mk-rank', panel), d.rank);
      }).catch(function (e) { THP.qs('#mk-info', panel).innerHTML = '<div class="notice err">' + THP.esc(e.message) + '</div>'; });
    }
    loadInfo();

    function openStyleEditor(base, isCopy) {
      base = base || {};
      var isEdit = !!base.id && !isCopy;
      var m = THP.modal(
        '<h3>' + (isEdit ? '编辑走势风格' : (isCopy ? '复制走势风格' : '新建走势风格')) + '</h3>' +
        '<p class="muted">自定义风格会和内置风格一起出现在下拉框里。8 点前和 17 点后仍会自动收敛波动。</p>' +
        '<div class="field"><label>风格名称（2-20 字）</label><input class="input" id="ns-name" maxlength="20" value="' + THP.esc(base.name || '') + '" placeholder="例如：午后心跳"></div>' +
        '<div class="flex gap8 wrap mt12">' +
        '<div style="flex:1;min-width:140px"><label class="muted" style="font-size:12px">单步最大波动（1-20）</label><input class="input" id="ns-step" type="number" min="1" max="20" value="' + (base.max_step || 8) + '"></div>' +
        '<div style="flex:1;min-width:140px"><label class="muted" style="font-size:12px">波动强度（5-100）</label><input class="input" id="ns-vol" type="number" min="5" max="100" value="' + (base.volatility || 55) + '"></div>' +
        '</div>' +
        '<div class="flex gap8 wrap mt12">' +
        '<div style="flex:1;min-width:140px"><label class="muted" style="font-size:12px">日涨跌幅下限（%）</label><input class="input" id="ns-min" type="number" min="-90" max="500" value="' + (base.change_min === undefined ? -10 : base.change_min) + '"></div>' +
        '<div style="flex:1;min-width:140px"><label class="muted" style="font-size:12px">日涨跌幅上限（%）</label><input class="input" id="ns-max" type="number" min="-90" max="500" value="' + (base.change_max === undefined ? 60 : base.change_max) + '"></div>' +
        '</div>' +
        '<div class="muted mt8" style="font-size:12px">「日涨跌幅」指收盘价相对开盘价的目标范围，例如 -10 与 60 表示允许收跌 10%，也可能上涨 60%。</div>' +
        '<div class="modal-actions"><button class="btn ghost" data-close>取消</button><button class="btn blue" id="ns-save">' + (isEdit ? '保存修改' : '保存并选用') + '</button></div>'
      );
      THP.qs('#ns-save', m.el).addEventListener('click', function () {
        var btn = this;
        var name = THP.qs('#ns-name', m.el).value.trim();
        var maxStep = parseInt(THP.qs('#ns-step', m.el).value, 10);
        var volatility = parseInt(THP.qs('#ns-vol', m.el).value, 10);
        var changeMin = parseInt(THP.qs('#ns-min', m.el).value, 10);
        var changeMax = parseInt(THP.qs('#ns-max', m.el).value, 10);
        if (name.length < 2 || name.length > 20) { THP.toast('风格名称需为 2-20 个字', 'err'); return; }
        if (!(maxStep >= 1 && maxStep <= 20)) { THP.toast('单步最大波动需为 1-20', 'err'); return; }
        if (!(volatility >= 5 && volatility <= 100)) { THP.toast('波动强度需为 5-100', 'err'); return; }
        if (!(changeMin >= -90 && changeMin <= 500 && changeMax >= -90 && changeMax <= 500)) { THP.toast('日涨跌幅需在 -90% 到 +500% 之间', 'err'); return; }
        if (changeMax < changeMin) { THP.toast('日涨跌幅上限不能低于下限', 'err'); return; }
        var payload = { name: name, max_step: maxStep, change_min: changeMin, change_max: changeMax, volatility: volatility };
        if (isEdit) { payload.id = base.id; }
        THP.busy(btn, true);
        THP.api('admin.market_style_save', payload).then(function (r) {
          rebuildStyleMap(r.styles || STYLE_FALLBACK);
          setStyleSelect(r.style.id);
          m.close();
          THP.toast((isEdit ? '已保存' : '已新建') + '走势风格「' + r.style.name + '」', 'ok');
        }).catch(function (e) { THP.toast(e.message, 'err'); }).finally(function () { THP.busy(btn, false); });
      });
    }

    function exportStyle(style) {
      var data = {
        format: 'thp-style-v1',
        exported_at: new Date().toISOString(),
        styles: [{
          name: style.name,
          max_step: style.max_step,
          change_min: style.change_min,
          change_max: style.change_max,
          volatility: style.volatility
        }]
      };
      var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = 'thp-style-' + style.name.replace(/[\\/:*?"<>|]/g, '_') + '.json';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    function openStyleManager() {
      var m = THP.modal('<div id="sm-body"></div>', { maskClose: true });
      function draw() {
        var body = THP.qs('#sm-body', m.el);
        var rows = styles.map(function (s) {
          var actions;
          if (s.builtin) {
            actions = '<span class="badge gray">内置只读</span>';
          } else {
            actions = '<div class="flex gap8 wrap">' +
              '<button class="btn small ghost" data-edit-style="' + THP.esc(s.id) + '">编辑</button>' +
              '<button class="btn small ghost" data-copy-style="' + THP.esc(s.id) + '">复制</button>' +
              '<button class="btn small ghost" data-export-style="' + THP.esc(s.id) + '">导出</button>' +
              '<button class="btn small danger" data-del-style="' + THP.esc(s.id) + '">删除</button></div>';
          }
          return '<div class="list-item"><div class="grow"><div><b>' + THP.esc(s.name) + '</b> ' +
            (s.builtin ? '<span class="badge ok">内置</span>' : '<span class="badge gray">自定义</span>') + '</div>' +
            '<div class="muted">' + THP.esc(s.description || '') + '</div></div>' + actions + '</div>';
        }).join('');
        body.innerHTML = '<h3>管理走势风格</h3>' +
          '<p class="muted">自定义风格可以编辑、复制、导出、导入。导入时不会覆盖已有风格；删除风格不会影响已经生成的走势文件。</p>' +
          '<div class="flex gap8 wrap mt12"><button class="btn" id="sm-new">＋ 新建</button>' +
          '<label class="btn ghost" style="cursor:pointer">📥 导入 JSON<input type="file" id="sm-import" accept=".json,application/json" style="display:none"></label></div>' +
          '<div class="mt12">' + rows + '</div>' +
          '<div class="modal-actions"><button class="btn ghost" data-close>关闭</button></div>';
        THP.qs('#sm-new', body).addEventListener('click', function () { m.close(); openStyleEditor(null, false); });
        THP.qs('#sm-import', body).addEventListener('change', function () {
          var file = this.files && this.files[0];
          if (!file) { return; }
          if (file.size > 256 * 1024) { THP.toast('风格文件不能超过 256KB', 'err'); this.value = ''; return; }
          var reader = new FileReader();
          reader.onload = function () {
            THP.showLoading('少女正在整理风格数据…');
            THP.api('admin.market_style_import', { data: String(reader.result || '') }).then(function (r) {
              rebuildStyleMap(r.styles || STYLE_FALLBACK);
              var names = (r.created || []).map(function (s) { return s.name; }).join('、');
              THP.toast('已导入：' + names, 'ok');
              setStyleSelect(r.created && r.created.length ? r.created[0].id : previousStyle);
              draw();
            }).catch(function (e) { THP.toast(e.message, 'err'); }).finally(function () { THP.hideLoading(); });
          };
          reader.readAsText(file);
          this.value = '';
        });
        body.querySelectorAll('[data-edit-style]').forEach(function (el) {
          el.addEventListener('click', function () {
            var s = styleMap[el.getAttribute('data-edit-style')];
            if (!s) { return; }
            m.close();
            openStyleEditor(s, false);
          });
        });
        body.querySelectorAll('[data-copy-style]').forEach(function (el) {
          el.addEventListener('click', function () {
            var s = styleMap[el.getAttribute('data-copy-style')];
            if (!s) { return; }
            var copy = Object.assign({}, s, { id: '', name: (s.name + ' 副本').slice(0, 20) });
            m.close();
            openStyleEditor(copy, true);
          });
        });
        body.querySelectorAll('[data-export-style]').forEach(function (el) {
          el.addEventListener('click', function () {
            var s = styleMap[el.getAttribute('data-export-style')];
            if (s) { exportStyle(s); }
          });
        });
        body.querySelectorAll('[data-del-style]').forEach(function (el) {
          el.addEventListener('click', function () {
            var id = el.getAttribute('data-del-style');
            var meta = styleMap[id];
            var name = meta ? meta.name : id;
            THP.confirm('删除自定义风格？', '「' + name + '」删除后不能再从下拉框选择；已经生成的走势文件不受影响。', '确认删除', 'danger').then(function (yes) {
              if (!yes) { return; }
              THP.api('admin.market_style_delete', { id: id }).then(function (r) {
                rebuildStyleMap(r.styles || STYLE_FALLBACK);
                setStyleSelect(previousStyle === id ? 'mild' : previousStyle);
                THP.toast('已删除自定义风格「' + name + '」', 'ok');
                draw();
              }).catch(function (e) { THP.toast(e.message, 'err'); });
            });
          });
        });
      }
      draw();
    }

    function openStyleCreator() { openStyleEditor(null, false); }

    var styleSelect = THP.qs('#mk-style', panel);
    styleSelect.addEventListener('change', function () {
      if (this.value === '__new__') {
        this.value = previousStyle;
        openStyleCreator();
        return;
      }
      if (this.value === '__manage__') {
        this.value = previousStyle;
        openStyleManager();
        return;
      }
      previousStyle = this.value;
    });

    var rangeSel = THP.qs('#mk-range', panel);
    if (rangeSel) { rangeSel.addEventListener('change', function () { mktRange = parseInt(this.value, 10) || 60; loadInfo(); }); }
    THP.qs('#mk-load', panel).addEventListener('click', loadInfo);

    THP.qs('#mk-import', panel).addEventListener('change', function () {
      var file = this.files && this.files[0];
      if (!file) { return; }
      var fd = new FormData();
      fd.append('file', file);
      var nm = THP.qs('#mk-name', panel).value.trim();
      if (nm) { fd.append('name', nm); }
      THP.showLoading('少女正在整理走势数据…');
      THP.api('admin.market_import', fd, { formData: true }).then(function (r) {
        THP.toast('导入成功：「' + r.name + '」已保存为新文件（未切换）', 'ok');
        loadInfo();
      }).catch(function (e) {
        THP.toast(e.message, 'err');
      }).finally(function () { THP.hideLoading(); this.value = ''; }.bind(this));
    });

    function gen(mode, btn) {
      var style = THP.qs('#mk-style', panel).value;
      var name = THP.qs('#mk-name', panel).value.trim();
      var prompt = THP.qs('#mk-prompt', panel).value.trim();
      THP.busy(btn, true);
      if (mode === 'api') { THP.toast('AI 正在构思行情，请稍等…', 'info'); }
      THP.api('admin.market_preview', { mode: mode, style: style, name: name, prompt: prompt }).then(function (r) {
        var rows = '';
        Object.keys(r.stats || {}).forEach(function (cid) {
          var st = r.stats[cid];
          var company = null;
          marketCompanies.forEach(function (c) { if (String(c.id) === String(cid)) { company = c; } });
          rows += '<tr><td>' + THP.esc(company ? company.name : ('#' + cid)) + '</td><td>' + st.change_percent + '%</td><td>' + fmtP(st.min) + ' ~ ' + fmtP(st.max) + '</td></tr>';
        });
        var m = THP.modal(
          '<h3>走势预览</h3>' +
          '<p class="muted">「' + THP.esc(r.name) + '」 · 风格：' + THP.esc(r.style_name) + ' · 来源：' + THP.esc(SOURCE_NAMES[r.source] || r.source) + '</p>' +
          (r.comment ? '<div class="notice info">AI 点评：' + THP.esc(r.comment) + '</div>' : '') +
          '<div class="table-wrap" style="max-height:260px;overflow:auto"><table class="thp-table preview-table"><thead><tr><th>公司</th><th>日涨跌</th><th>最低 ~ 最高</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
          '<p class="muted mt8">预览保存在临时目录，1 小时内有效；确认保存后才会加入走势文件列表，仍需手动切换生效。</p>' +
          '<div class="modal-actions"><button class="btn ghost" id="pv-cancel">放弃</button><button class="btn blue" id="pv-save">保存为新文件</button></div>'
        );
        var previewName = r.name;
        THP.qs('#pv-cancel', m.el).addEventListener('click', function () {
          THP.api('admin.market_preview_cancel', { preview_token: r.preview_token }).catch(function () {});
          m.close();
        });
        THP.qs('#pv-save', m.el).addEventListener('click', function () {
          var saveBtn = this;
          THP.busy(saveBtn, true);
          THP.api('admin.market_preview_save', { preview_token: r.preview_token, name: previewName }).then(function (saved) {
            m.close();
            THP.toast('「' + saved.name + '」已保存为新文件（未切换）', 'ok');
            loadInfo();
          }).catch(function (e) { THP.toast(e.message, 'err'); }).finally(function () { THP.busy(saveBtn, false); });
        });
      }).catch(function (e) {
        THP.modal('<h3>生成失败</h3><p class="muted" style="white-space:pre-wrap">' + THP.esc(e.message) + '</p><p class="muted">原有走势文件没有被改动。</p><div class="modal-actions"><button class="btn" data-close>知道了</button></div>');
      }).finally(function () { THP.busy(btn, false); });
    }
    THP.qs('#mk-local', panel).addEventListener('click', function () { gen('local', this); });
    THP.qs('#mk-ai', panel).addEventListener('click', function () { gen('api', this); });
  }

  // ================= 题库 =================
  function renderQuestions(panel, content) {
    var THP = window.THP;
    panel.innerHTML = '<div class="card"><div class="flex-between"><div class="card-title" style="margin:0"><span class="dot"></span>题目管理（答题得代币）</div>' +
      '<div class="flex gap8"><a class="btn ghost small" href="print.php?type=question" target="_blank">批量打印二维码</a><button class="btn" id="q-new">新建题目</button></div></div>' +
      '<div id="q-list" class="mt12"><div class="empty">加载中…</div></div></div>';
    function load() {
      THP.api('admin.question_list').then(function (d) {
        var box = THP.qs('#q-list', panel);
        if (!d.items.length) { box.innerHTML = '<div class="empty">还没有题目，点右上角新建一道吧</div>'; return; }
        var html = '';
        d.items.forEach(function (q, i) {
          html += '<div class="list-item"><div class="grow"><div><b>' + THP.esc(q.title) + '</b> ' +
            (q.enabled ? '<span class="badge ok">启用中</span>' : '<span class="badge gray">已停用</span>') + '</div>' +
            '<div class="muted">代码 <b class="pill-price">' + q.code + '</b> · 奖励 ' + q.reward + ' FDT · 作答 ' + q.attempt_count + ' 次 / 答对 ' + q.correct_count + ' 次</div></div>' +
            '<div class="flex gap8 wrap"><button class="btn small ghost" data-i="' + i + '" data-op="edit">编辑</button>' +
            '<button class="btn small ghost" data-i="' + i + '" data-op="qr">二维码</button>' +
            '<button class="btn small danger" data-i="' + i + '" data-op="del">删除</button></div></div>';
        });
        box.innerHTML = html;
        box.querySelectorAll('[data-op]').forEach(function (el) {
          var q = d.items[parseInt(el.getAttribute('data-i'), 10)];
          var op = el.getAttribute('data-op');
          el.addEventListener('click', function () {
            if (op === 'edit') { questionEditor(q, load); }
            else if (op === 'qr') { qrModal(q.code, 'question', q.title); }
            else if (op === 'del') {
              THP.confirm('删除题目？', '「' + q.title + '」及所有答题记录都会被删除，不可恢复。', '确认删除', 'danger').then(function (yes) {
                if (!yes) { return; }
                THP.api('admin.question_delete', { id: q.id }).then(function () { THP.toast('已删除', 'ok'); load(); }).catch(function (e) { THP.toast(e.message, 'err'); });
              });
            }
          });
        });
      }).catch(function (e) { THP.qs('#q-list', panel).innerHTML = '<div class="notice err">' + THP.esc(e.message) + '</div>'; });
    }
    THP.qs('#q-new', panel).addEventListener('click', function () { questionEditor(null, load); });
    load();
  }

  function questionEditor(q, done) {
    var THP = window.THP;
    var isNew = !q;
    q = q || { id: 0, title: '', body: '', options: ['', '', '', ''], correct_index: 0, reward: 20, image: '', enabled: 1 };
    var options = q.options.slice();
    while (options.length < 2) { options.push(''); }
    var imgUrl = q.image || '';
    var m = THP.modal(
      '<h3>' + (isNew ? '新建题目' : '编辑题目') + '</h3>' +
      '<div class="field"><label>标题（最多 64 字）</label><input class="input" id="qe-title" maxlength="64" value="' + THP.esc(q.title) + '"></div>' +
      '<div class="field"><label>题目内容</label><textarea class="input" id="qe-body">' + THP.esc(q.body) + '</textarea></div>' +
      '<div class="field"><label>选项（2-6 个）</label><div id="qe-opts"></div>' +
      '<button class="btn ghost small mt8" id="qe-addopt">+ 添加选项</button></div>' +
      '<div class="field"><label>正确答案</label><select class="input" id="qe-correct"></select></div>' +
      '<div class="grid2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' +
      '<div class="field"><label>答对奖励 FDT</label><input class="input" id="qe-reward" inputmode="numeric" value="' + q.reward + '"></div>' +
      '<div class="field"><label>是否启用</label><div class="switch' + (q.enabled ? ' on' : '') + '" id="qe-enabled"></div></div>' +
      '</div>' +
      '<div class="field"><label>配图（可选）</label><div id="qe-imgholder"></div>' +
      '<label class="btn ghost small mt8" style="cursor:pointer">上传图片<input type="file" id="qe-imgfile" accept="image/*" style="display:none"></label></div>' +
      '<div class="modal-actions"><button class="btn ghost" data-close>取消</button><button class="btn" id="qe-save">保存</button></div>',
      { maskClose: false }
    );

    function renderOpts() {
      var holder = THP.qs('#qe-opts', m.el);
      var html = '';
      options.forEach(function (o, i) {
        html += '<div class="opt-row"><span class="idx" style="width:26px;height:26px;border-radius:50%;background:#ffe9ee;color:#c94b5f;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px">' + String.fromCharCode(65 + i) + '</span>' +
          '<input class="input" data-oi="' + i + '" maxlength="120" value="' + THP.esc(o) + '">' +
          (options.length > 2 ? '<button class="btn small ghost" data-orm="' + i + '">×</button>' : '') + '</div>';
      });
      holder.innerHTML = html;
      holder.querySelectorAll('[data-oi]').forEach(function (el) {
        el.addEventListener('input', function () { options[parseInt(el.getAttribute('data-oi'), 10)] = el.value; updateCorrect(); });
      });
      holder.querySelectorAll('[data-orm]').forEach(function (el) {
        el.addEventListener('click', function () {
          options.splice(parseInt(el.getAttribute('data-orm'), 10), 1);
          renderOpts();
        });
      });
      updateCorrect();
    }
    function updateCorrect() {
      var sel = THP.qs('#qe-correct', m.el);
      var cur = sel.value ? parseInt(sel.value, 10) : (q.correct_index || 0);
      var html = '';
      options.forEach(function (o, i) {
        html += '<option value="' + i + '"' + (i === cur ? ' selected' : '') + '>' + String.fromCharCode(65 + i) + (o ? '：' + THP.esc(o.slice(0, 18)) : '') + '</option>';
      });
      sel.innerHTML = html;
    }
    function renderImg() {
      THP.qs('#qe-imgholder', m.el).innerHTML = imgUrl ? ('<span class="upload-thumb"><img src="' + THP.esc(imgUrl) + '"><span class="rm" id="qe-imgrm">×</span></span>') : '<span class="muted">未上传</span>';
      var rm = THP.qs('#qe-imgrm', m.el);
      if (rm) { rm.addEventListener('click', function () { imgUrl = ''; renderImg(); }); }
    }
    renderOpts();
    renderImg();
    THP.qs('#qe-addopt', m.el).addEventListener('click', function () {
      if (options.length >= 6) { THP.toast('最多 6 个选项', 'err'); return; }
      options.push('');
      renderOpts();
    });
    THP.qs('#qe-enabled', m.el).addEventListener('click', function () { this.classList.toggle('on'); });
    THP.qs('#qe-imgfile', m.el).addEventListener('change', function () {
      var f = this.files && this.files[0];
      if (!f) { return; }
      uploadImage(f).then(function (r) { imgUrl = r.url; renderImg(); }).catch(function (e) { THP.toast(e.message, 'err'); });
    });
    THP.qs('#qe-save', m.el).addEventListener('click', function () {
      var btn = this;
      var payload = {
        id: q.id || 0,
        title: THP.qs('#qe-title', m.el).value.trim(),
        body: THP.qs('#qe-body', m.el).value.trim(),
        options: options.map(function (o) { return o.trim(); }).filter(function (o) { return o.length > 0; }),
        correct_index: parseInt(THP.qs('#qe-correct', m.el).value, 10) || 0,
        reward: parseInt(THP.qs('#qe-reward', m.el).value, 10) || 0,
        image: imgUrl,
        enabled: THP.qs('#qe-enabled', m.el).classList.contains('on') ? 1 : 0
      };
      THP.busy(btn, true);
      THP.api('admin.question_save', payload).then(function () {
        m.close();
        THP.toast('已保存（代码可在列表中查看）', 'ok');
        done();
      }).catch(function (e) { THP.toast(e.message, 'err'); }).finally(function () { THP.busy(btn, false); });
    });
  }

  // ================= 故事 =================
  function renderStories(panel, content) {
    var THP = window.THP;
    panel.innerHTML = '<div class="card"><div class="flex-between"><div class="card-title" style="margin:0"><span class="dot"></span>故事管理</div>' +
      '<div class="flex gap8"><a class="btn ghost small" href="print.php?type=story" target="_blank">批量打印二维码</a><button class="btn" id="s-new">新建故事</button></div></div>' +
      '<div id="s-list" class="mt12"><div class="empty">加载中…</div></div></div>';
    function load() {
      THP.api('admin.story_list').then(function (d) {
        var box = THP.qs('#s-list', panel);
        if (!d.items.length) { box.innerHTML = '<div class="empty">还没有故事</div>'; return; }
        var html = '';
        d.items.forEach(function (s, i) {
          html += '<div class="list-item"><div class="grow"><div><b>' + THP.esc(s.title) + '</b> ' +
            (s.enabled ? '<span class="badge ok">启用中</span>' : '<span class="badge gray">已停用</span>') + '</div>' +
            '<div class="muted">代码 <b class="pill-price">' + s.code + '</b> · 已解锁 ' + s.unlock_count + ' 次 · 图片 ' + (s.images ? s.images.length : 0) + ' 张</div></div>' +
            '<div class="flex gap8 wrap"><button class="btn small ghost" data-i="' + i + '" data-op="edit">编辑</button>' +
            '<button class="btn small ghost" data-i="' + i + '" data-op="qr">二维码</button>' +
            '<button class="btn small ghost" data-i="' + i + '" data-op="preview">预览</button>' +
            '<button class="btn small danger" data-i="' + i + '" data-op="del">删除</button></div></div>';
        });
        box.innerHTML = html;
        box.querySelectorAll('[data-op]').forEach(function (el) {
          var s = d.items[parseInt(el.getAttribute('data-i'), 10)];
          var op = el.getAttribute('data-op');
          el.addEventListener('click', function () {
            if (op === 'edit') { storyEditor(s, load); }
            else if (op === 'qr') { qrModal(s.code, 'story', s.title); }
            else if (op === 'preview') { THP.openStory({ title: s.title, body: s.body, images: s.images || [] }); }
            else if (op === 'del') {
              THP.confirm('删除故事？', '「' + s.title + '」及所有解锁记录都会被删除。', '确认删除', 'danger').then(function (yes) {
                if (!yes) { return; }
                THP.api('admin.story_delete', { id: s.id }).then(function () { THP.toast('已删除', 'ok'); load(); }).catch(function (e) { THP.toast(e.message, 'err'); });
              });
            }
          });
        });
      }).catch(function (e) { THP.qs('#s-list', panel).innerHTML = '<div class="notice err">' + THP.esc(e.message) + '</div>'; });
    }
    THP.qs('#s-new', panel).addEventListener('click', function () { storyEditor(null, load); });
    load();
  }

  function storyEditor(s, done) {
    var THP = window.THP;
    var isNew = !s;
    s = s || { id: 0, title: '', body: '', images: [], enabled: 1 };
    var images = (s.images || []).slice();
    var m = THP.modal(
      '<h3>' + (isNew ? '新建故事' : '编辑故事') + '</h3>' +
      '<div class="field"><label>故事标题（最多 64 字）</label><input class="input" id="se-title" maxlength="64" value="' + THP.esc(s.title) + '"></div>' +
      '<div class="field"><label>故事内容</label><textarea class="input" id="se-body" style="min-height:180px">' + THP.esc(s.body) + '</textarea></div>' +
      '<div class="field"><label>配图（最多 6 张）</label><div id="se-imgs"></div>' +
      '<label class="btn ghost small mt8" style="cursor:pointer">上传图片<input type="file" id="se-imgfile" accept="image/*" style="display:none"></label></div>' +
      '<div class="field"><label>是否启用</label><div class="switch' + (s.enabled ? ' on' : '') + '" id="se-enabled"></div></div>' +
      '<div class="modal-actions"><button class="btn ghost" data-close>取消</button><button class="btn" id="se-save">保存</button></div>',
      { maskClose: false }
    );
    function renderImgs() {
      var holder = THP.qs('#se-imgs', m.el);
      if (!images.length) { holder.innerHTML = '<span class="muted">未上传</span>'; return; }
      var html = '';
      images.forEach(function (u, i) {
        html += '<span class="upload-thumb"><img src="' + THP.esc(u) + '"><span class="rm" data-irm="' + i + '">×</span></span>';
      });
      holder.innerHTML = html;
      holder.querySelectorAll('[data-irm]').forEach(function (el) {
        el.addEventListener('click', function () { images.splice(parseInt(el.getAttribute('data-irm'), 10), 1); renderImgs(); });
      });
    }
    renderImgs();
    THP.qs('#se-enabled', m.el).addEventListener('click', function () { this.classList.toggle('on'); });
    THP.qs('#se-imgfile', m.el).addEventListener('change', function () {
      var f = this.files && this.files[0];
      if (!f) { return; }
      if (images.length >= 6) { THP.toast('最多 6 张图片', 'err'); return; }
      uploadImage(f).then(function (r) { images.push(r.url); renderImgs(); }).catch(function (e) { THP.toast(e.message, 'err'); });
      this.value = '';
    });
    THP.qs('#se-save', m.el).addEventListener('click', function () {
      var btn = this;
      THP.busy(btn, true);
      THP.api('admin.story_save', {
        id: s.id || 0,
        title: THP.qs('#se-title', m.el).value.trim(),
        body: THP.qs('#se-body', m.el).value.trim(),
        images: images,
        enabled: THP.qs('#se-enabled', m.el).classList.contains('on') ? 1 : 0
      }).then(function () {
        m.close(); THP.toast('已保存', 'ok'); done();
      }).catch(function (e) { THP.toast(e.message, 'err'); }).finally(function () { THP.busy(btn, false); });
    });
  }

  // ================= 设置 =================
  // ================= 活动运维 =================
  function renderMaintenance(panel, content) {
    var THP = window.THP;
    if (!can('maintenance')) { panel.innerHTML = '<div class="notice err">当前账号没有活动运维权限</div>'; return; }
    panel.innerHTML =
      '<div class="card"><div class="flex-between wrap gap8"><div class="card-title" style="margin:0"><span class="dot"></span>运行健康检查</div><button class="btn" id="mt-health">立即检查</button></div>' +
      '<div id="mt-health-result" class="mt12"><div class="muted">检查数据库、目录权限、走势文件、日志、OSS、AI 与磁盘空间。</div></div></div>' +
      '<div class="card mt12"><div class="flex-between wrap gap8"><div class="card-title" style="margin:0"><span class="dot"></span>清理预览</div><button class="btn ghost small" id="mt-refresh">刷新统计</button></div>' +
      '<div id="mt-info" class="mt12"><div class="empty">统计中…</div></div></div>' +
      '<div class="card mt12"><div class="card-title"><span class="dot"></span>清理测试账号</div>' +
      '<p class="muted">只清理已标记为「测试账号」的普通用户，连同其代币流水、持仓、答题、故事、设备绑定和测试公司一并移除。操作前会自动生成服务器备份。</p>' +
      '<div class="amount-row"><input class="input" id="mt-test-confirm" placeholder="输入：清理测试账号"><button class="btn danger" id="mt-test-clean">清理测试账号</button></div></div>' +
      '<div class="card mt12"><div class="card-title"><span class="dot"></span>孤儿数据清理</div>' +
      '<p class="muted">删除已不存在的用户/公司/题目留下的流水、持仓、成交、绑定与过期幂等记录，不会影响正常账号。</p>' +
      '<div class="amount-row"><input class="input" id="mt-orphan-confirm" placeholder="输入：清理孤儿数据"><button class="btn ghost" id="mt-orphan-clean">清理孤儿数据</button></div></div>' +
      '<div class="card mt12" style="border:1.5px solid #f3c1c9"><div class="card-title" style="color:#b83b4f"><span class="dot"></span>活动最终重置（高危）</div>' +
      '<p class="muted">将清空全部游客、代币流水、持仓、股票成交、答题记录、故事解锁、发币码、收款码、风控与设备绑定，并删除游客创建的公司。<b>题目、故事、管理员账号、设置以及动态行情历史会保留。</b>操作前自动备份。</p>' +
      '<div class="amount-row"><input class="input" id="mt-reset-confirm" placeholder="输入：重置本次活动"><button class="btn danger" id="mt-event-reset">重置本次活动</button></div></div>';

    function loadInfo() {
      THP.api('admin.maintenance_info').then(function (d) {
        var labels = {
          normal_users: '正常游客', test_users: '测试账号', orphan_token_tx: '孤儿流水', orphan_holdings: '孤儿持仓',
          orphan_stock_trades: '孤儿成交', orphan_loans: '孤儿借款', orphan_quiz_attempts: '孤儿答题',
          orphan_user_stories: '孤儿故事解锁', orphan_spend: '孤儿支付码', orphan_devices: '孤儿设备绑定',
          orphan_risk: '孤儿风控事件', expired_idempotency: '过期幂等记录'
        };
        var html = '<div class="stat-grid">';
        Object.keys(labels).forEach(function (k) {
          html += '<div class="stat-card"><div class="k">' + labels[k] + '</div><div class="v">' + (d.counts[k] < 0 ? '?' : d.counts[k]) + '</div></div>';
        });
        html += '</div><div class="muted mt8">数据库结构：v' + THP.esc(d.schema_version) + '</div>';
        if (d.test_accounts && d.test_accounts.length) {
          html += '<div class="card-title mt12"><span class="dot"></span>已标记测试账号</div>';
          d.test_accounts.forEach(function (u) {
            html += '<div class="log-line">#' + u.id + ' ' + THP.esc(u.username) + ' · ' + u.balance + ' FDT · ' + THP.fmtTime(u.created_at) + '</div>';
          });
        }
        THP.qs('#mt-info', panel).innerHTML = html;
      }).catch(function (e) { THP.qs('#mt-info', panel).innerHTML = '<div class="notice err">' + THP.esc(e.message) + '</div>'; });
    }
    function loadHealth() {
      var box = THP.qs('#mt-health-result', panel);
      box.innerHTML = '<div class="empty">少女祈祷中，正在检查…</div>';
      THP.api('admin.health').then(function (d) {
        var html = '<div class="muted">检查时间：' + THP.esc(d.generated_at) + ' · 正常 ' + d.summary.ok + ' / 警告 ' + d.summary.warn + ' / 错误 ' + d.summary.error + '</div>';
        d.checks.forEach(function (c) {
          var cls = c.status === 'error' ? 'err' : (c.status === 'warn' ? 'warn' : 'ok');
          var label = c.status === 'error' ? '错误' : (c.status === 'warn' ? '注意' : '正常');
          html += '<div class="list-item" style="align-items:flex-start"><div class="grow"><div><b>' + THP.esc(c.label) + '</b> <span class="badge ' + cls + '">' + label + '</span></div>' +
            '<div class="muted" style="white-space:pre-wrap;word-break:break-all">' + THP.esc(c.detail) + '</div>' +
            (c.fix ? '<div class="muted" style="font-size:12px">建议：' + THP.esc(c.fix) + '</div>' : '') + '</div></div>';
        });
        box.innerHTML = html;
      }).catch(function (e) { box.innerHTML = '<div class="notice err">' + THP.esc(e.message) + '</div>'; });
    }
    THP.qs('#mt-health', panel).addEventListener('click', loadHealth);
    THP.qs('#mt-refresh', panel).addEventListener('click', loadInfo);
    loadInfo();
    loadHealth();

    THP.qs('#mt-test-clean', panel).addEventListener('click', function () {
      var phrase = THP.qs('#mt-test-confirm', panel).value.trim();
      if (phrase !== '清理测试账号') { THP.toast('请先输入确认短语「清理测试账号」', 'err'); return; }
      THP.confirm('确认清理测试账号？', '测试账号及其全部活动数据将被删除，系统会先自动备份。', '确认清理', 'danger').then(function (yes) {
        if (!yes) { return; }
        var btn = this;
        THP.api('admin.cleanup_test_accounts', { confirm: phrase }).then(function (r) {
          THP.toast('已清理 ' + r.deleted_users + ' 个测试账号' + (r.backup ? '，备份：' + r.backup : ''), 'ok');
          THP.qs('#mt-test-confirm', panel).value = '';
          loadInfo();
        }).catch(function (e) { THP.toast(e.message, 'err'); });
      });
    });
    THP.qs('#mt-orphan-clean', panel).addEventListener('click', function () {
      var phrase = THP.qs('#mt-orphan-confirm', panel).value.trim();
      if (phrase !== '清理孤儿数据') { THP.toast('请先输入确认短语「清理孤儿数据」', 'err'); return; }
      THP.confirm('确认清理孤儿数据？', '只删除失去关联的记录，不删除正常账号。', '确认清理', 'danger').then(function (yes) {
        if (!yes) { return; }
        THP.api('admin.cleanup_orphans', { confirm: phrase }).then(function () {
          THP.toast('孤儿数据已清理', 'ok');
          THP.qs('#mt-orphan-confirm', panel).value = '';
          loadInfo();
        }).catch(function (e) { THP.toast(e.message, 'err'); });
      });
    });
    THP.qs('#mt-event-reset', panel).addEventListener('click', function () {
      var phrase = THP.qs('#mt-reset-confirm', panel).value.trim();
      if (phrase !== '重置本次活动') { THP.toast('请先输入确认短语「重置本次活动」', 'err'); return; }
      THP.confirm('最终确认：重置本次活动？', '全部游客活动数据将被清空，只保留管理员、题目、故事、设置和走势文件。此操作不可从网站界面撤销。', '仍要重置', 'danger').then(function (yes) {
        if (!yes) { return; }
        THP.api('admin.event_reset', { confirm: phrase }).then(function (r) {
          THP.modal('<h3>活动已重置</h3><p class="muted">已清除游客 ' + r.deleted_users + ' 人、公司 ' + r.deleted_companies + ' 家。</p><p class="muted" style="word-break:break-all">备份：' + THP.esc(r.backup) + '</p><div class="modal-actions"><button class="btn" data-close>知道了</button></div>');
          THP.qs('#mt-reset-confirm', panel).value = '';
          loadInfo();
        }).catch(function (e) { THP.toast(e.message, 'err'); });
      });
    });
  }

  var SETTINGS_RULE_GROUPS = [
    { title: '交易与风控', note: '限制高频买卖、转账与短期倒卖；值越大越宽松。', items: [
      { k: 'trade_throttle_count', label: '每窗口最多股票交易次数', min: 1, max: 200, step: 1, def: 30, help: '每名游客在一个窗口内最多买入 + 卖出合计次数。建议 25~40。' },
      { k: 'trade_throttle_window_minutes', label: '股票交易限流窗口（分钟）', min: 1, max: 180, step: 1, def: 30, help: '与上一项共同组成“每 N 分钟最多 M 次”。' },
      { k: 'transfer_limit_30m', label: '每 30 分钟最多转让 FDT', min: 0, max: 1000000, step: 50, def: 1000, help: '0 表示禁止转让；只统计玩家之间的转让，不影响管理员发币。' },
      { k: 'holding_lock_minutes', label: '买入后最低持有时长（分钟）', min: 1, max: 1440, step: 1, def: 15, help: '按买入批次 FIFO 解锁，防止刚买就卖的低买高卖脚本。' }
    ] },
    { title: '银行借贷', note: '额度按自有资金（FDT 余额 − 未还欠款）计算，不含股票；单次最多 200%，每滚动 1 小时的次数可在下方调整。欠款未清前不能消费、转让或创建公司。', items: [
      { k: 'loan_max_per_hour', label: '每滚动 1 小时最多借款次数', min: 1, max: 100, step: 1, def: 10, help: '按最近 60 分钟实际发起的贷款计算，过期贷款会滚动释放额度。默认 10 次。' },
      { k: 'loan_base_rate', label: '基础利率（%）', min: 1, max: 100, step: 1, def: 10, help: '首期计息即按此利率；默认 10%，之后每期在此基础上叠加加息值。' },
      { k: 'loan_rate_step', label: '每次加息（%）', min: 1, max: 100, step: 1, def: 10, help: '每过一个计息周期，剩余本金利率增加的值。' },
      { k: 'loan_max_rate', label: '最高利率（%）', min: 1, max: 300, step: 1, def: 100, help: '利率上限；不要低于基础利率。' },
      { k: 'loan_interest_minutes', label: '加息周期（分钟）', min: 1, max: 1440, step: 1, def: 30, help: '默认每 30 分钟加一次利息。' }
    ] },
    { title: '行情引擎', note: '小波动为主，同时保留节目效果明显的大波动；开盘与收盘由均值回归拉回基准附近。', items: [
      { k: 'market_refresh_minutes', label: '自动续写未来走势间隔（分钟）', min: 1, max: 60, step: 1, def: 6, help: '有玩家请求且达到该间隔时，加锁生成未来一段行情。默认 6 分钟。' },
      { k: 'market_forecast_ticks', label: '默认预测窗口（3 分钟点）', min: 1, max: 480, step: 1, def: 20, help: '20 个点 = 未来 1 小时；只影响默认生成，不限制后台手动 1/2/3/5h。' },
      { k: 'market_noise_weight', label: '随机小波动权重', min: 0, max: 5, step: 0.05, def: 0.85, help: '越大，日常小起伏越明显。推荐 0.75~1.1。' },
      { k: 'market_mean_reversion', label: '价格回归基准力度', min: 0, max: 1, step: 0.01, def: 0.04, help: '越大，价格越容易回到公司基准价，避免长期跑偏。' },
      { k: 'market_index_reversion', label: '跟随大盘回归力度', min: 0, max: 1, step: 0.005, def: 0.035, help: '抑制所有股票一起飞或一起跳水。' },
      { k: 'market_hedge_weight', label: '股票对冲权重', min: 0, max: 1, step: 0.01, def: 0.35, help: '一只股票上涨时，其它股票承压的强度。' },
      { k: 'market_investment_weight', label: '个人交易影响权重', min: 0, max: 2, step: 0.01, def: 0.1, help: '玩家净买入对未来价格的推力；已做上限约束。' },
      { k: 'market_investment_cap_percent', label: '单次个人影响上限（%）', min: 0, max: 5, step: 0.1, def: 1.5, help: '防止大户一笔交易把价格拉飞。' },
      { k: 'market_big_move_chance', label: '大波动概率（%/点）', min: 0, max: 20, step: 0.1, def: 1.2, help: '每个 3 分钟点触发戏剧性波动的概率。1~2 兼顾小波动与节目效果。' },
      { k: 'market_big_move_min_percent', label: '大波动最小幅度（%）', min: 1, max: 20, step: 0.5, def: 6, help: '触发大波动时至少跳这么多。' },
      { k: 'market_big_move_max_percent', label: '大波动最大幅度（%）', min: 1, max: 20, step: 0.5, def: 18, help: '最高 20%，不会被单步上限放大。' },
      { k: 'market_step_max_percent', label: '单点最大涨跌（%）', min: 1, max: 20, step: 1, def: 20, help: '算法、人工、AI 共用硬限制；每个 3 分钟点相对前一点不能超过此值。' },
      { k: 'market_min_ratio', label: '最低价 / 基准价', min: 0.01, max: 1, step: 0.0001, def: 0.3333, help: '0.3333 即最低可跌到基准价三分之一。' },
      { k: 'market_max_ratio', label: '最高价 / 基准价', min: 1, max: 20, step: 0.1, def: 3, help: '3 即最高可涨到基准价三倍。' },
      { k: 'market_long_drift_percent', label: '全天长期漂移（%/点）', min: -0.5, max: 0.5, step: 0.01, def: 0, help: '0 表示不刻意让全天收盘偏涨或偏跌；建议保持 0 以平衡开盘与收盘。' },
      { k: 'market_cache_seconds', label: '行情缓存秒数', min: 1, max: 60, step: 1, def: 60, help: '默认 60 秒；同 tick 内复用计算结果，显著降低展会现场数据库压力。' }
    ] },
    { title: '新闻自动播报', note: '自动播报默认开启；只挑选已启用的股市新闻模板，间隔到点后随机 1~2 条。', items: [
      { k: 'news_interval_minutes', label: '自动播报间隔（分钟）', min: 5, max: 180, step: 1, def: 15, help: '默认 15 分钟。' },
      { k: 'news_auto_min', label: '每次最少条数', min: 0, max: 5, step: 1, def: 1, help: '设为 0 且最大也为 0 时不会自动播报。' },
      { k: 'news_auto_max', label: '每次最多条数', min: 0, max: 5, step: 1, def: 2, help: '建议 1~2 条，避免玩家连续弹窗过多。' }
    ] }
  ];

  function setupSettingsRules(panel, s) {
    var THP = window.THP;
    var host = THP.qs('#st-rules', panel);
    if (!host) { return; }
    var html = '';
    SETTINGS_RULE_GROUPS.forEach(function (group) {
      html += '<div class="settings-rule-group"><div class="settings-rule-title">' + THP.esc(group.title) + '</div><div class="muted">' + THP.esc(group.note) + '</div>';
      group.items.forEach(function (item) {
        var value = s[item.k] === undefined || s[item.k] === null || s[item.k] === '' ? item.def : s[item.k];
        html += '<div class="setting-row"><div class="setting-copy"><b>' + THP.esc(item.label) + '</b><div class="setting-help">' + THP.esc(item.help) + '</div></div>' +
          '<div class="setting-control"><input class="range-input" type="range" min="' + item.min + '" max="' + item.max + '" step="' + item.step + '" value="' + THP.esc(value) + '" data-rule-range="' + item.k + '">' +
          '<input class="input setting-number" type="number" min="' + item.min + '" max="' + item.max + '" step="' + item.step + '" value="' + THP.esc(value) + '" data-rule="' + item.k + '"></div></div>';
      });
      html += '</div>';
    });
    html += '<div class="setting-row"><div class="setting-copy"><b>启用自动新闻播报</b><div class="setting-help">默认开启；关闭后仍可手动发布新闻 / 通知，只是不再自动抽取模板。</div></div>' +
      '<div class="setting-control"><div class="switch' + (s.news_auto_enabled !== '0' ? ' on' : '') + '" id="st-news-auto"></div></div></div>';
    html += '<div class="setting-row"><div class="setting-copy"><b>自动新闻播报是否弹窗</b><div class="setting-help">默认关闭；关闭时自动播报只进入天狗快讯，不打断正在炒股的游客。</div></div>' +
      '<div class="setting-control"><div class="switch' + (s.news_auto_popup_enabled === '1' ? ' on' : '') + '" id="st-news-auto-popup"></div></div></div>';
    host.innerHTML = html;
    host.querySelectorAll('[data-rule-range]').forEach(function (range) {
      var key = range.getAttribute('data-rule-range');
      var number = host.querySelector('[data-rule="' + key + '"]');
      range.addEventListener('input', function () { number.value = range.value; });
      number.addEventListener('input', function () {
        var v = Number(number.value);
        if (isFinite(v)) { range.value = String(v); }
      });
    });
    THP.qs('#st-news-auto', host).addEventListener('click', function () { this.classList.toggle('on'); });
    THP.qs('#st-news-auto-popup', host).addEventListener('click', function () { this.classList.toggle('on'); });
    THP.qs('#st-rule-defaults', panel).addEventListener('click', function () {
      SETTINGS_RULE_GROUPS.forEach(function (group) {
        group.items.forEach(function (item) {
          var number = host.querySelector('[data-rule="' + item.k + '"]');
          var range = host.querySelector('[data-rule-range="' + item.k + '"]');
          if (number) { number.value = String(item.def); }
          if (range) { range.value = String(item.def); }
        });
      });
      THP.qs('#st-news-auto', host).classList.add('on');
      THP.qs('#st-news-auto-popup', host).classList.remove('on');
      THP.toast('已填入推荐值，记得保存', 'info');
    });
  }

  function renderSettings(panel, content) {
    var THP = window.THP;
    panel.innerHTML = '<div class="card"><div class="empty">读取设置中…</div></div>';
    THP.api('admin.settings_get').then(function (s) {
      panel.innerHTML =
        '<div class="card"><div class="card-title"><span class="dot"></span>基础设置</div>' +
        '<div class="field"><label>网站名称</label><input class="input" id="st-name" maxlength="40" value="' + THP.esc(s.event_name) + '"></div>' +
        '<div class="field"><label>主界面活动介绍（可直接编辑，支持换行）</label><textarea class="input" id="st-ann" style="min-height:160px">' + THP.esc(s.announcement) + '</textarea></div>' +
        '<div class="flex-between mt12"><div><b>开放注册</b><div class="muted">关闭后只能由管理员创建账号</div></div><div class="switch' + (s.registration_open === '1' ? ' on' : '') + '" id="st-reg"></div></div>' +
        '<div class="flex-between mt12"><div><b>开放交易与股市</b><div class="muted">关闭后游客无法生成付款码 / 炒股</div></div><div class="switch' + (s.trade_enabled === '1' ? ' on' : '') + '" id="st-trade"></div></div>' +
        '<div class="flex-between mt12"><div><b>启用设备限制</b><div class="muted">同一浏览器设备最多注册 1 个账号；管理员创建的账号不受限制</div></div><div class="switch' + (s.device_limit_enabled === '1' ? ' on' : '') + '" id="st-device"></div></div>' +
        '</div>' +
        '<div class="card"><div class="card-title"><span class="dot"></span>图片与 AI</div>' +
        '<div class="field"><label>图片 OSS 前缀（留空则全部走服务器本地图片）</label><input class="input" id="st-oss" value="' + THP.esc(s.oss_base_url) + '" placeholder="https://bucket.oss-cn-hangzhou.aliyuncs.com/static"></div>' +
        '<div class="field"><label>AI Endpoint（兼容 OpenAI 格式，可换其它模型服务）</label><input class="input" id="st-ep" value="' + THP.esc(s.ai_endpoint) + '"></div>' +
        '<div class="field"><label>模型名</label><input class="input" id="st-model" value="' + THP.esc(s.ai_model) + '"></div>' +
        '<div class="field"><label>API Key</label><input class="input" id="st-key" type="password" placeholder="' + (s.ai_key_set ? ('已配置（尾号 ' + THP.esc(s.ai_key_tail) + '），留空不修改') : '未配置，请填写') + '"></div>' +
        '<button class="btn ghost small mt8" id="st-keyclear">清除已保存的 Key</button>' +
        '</div>' +
        '<div class="card"><div class="flex-between wrap gap8"><div><div class="card-title" style="margin:0"><span class="dot"></span>交易、银行、行情与新闻参数</div>' +
        '<div class="muted">所有滑条都有推荐默认值。修改后点最下方“保存全部设置”才会生效。</div></div>' +
        '<button class="btn ghost small" id="st-rule-defaults">恢复推荐值（尚未保存）</button></div>' +
        '<div id="st-rules" class="settings-rules mt12"></div></div>' +
        '<div class="card"><div class="card-title"><span class="dot"></span>违禁词</div>' +
        '<div class="field"><label>注册昵称、创建/修改公司名时会拦截这些词（用逗号或换行分隔，命中任意一个词就拒绝）</label>' +
        '<textarea class="input" id="st-banned" rows="7" placeholder="广告,加群,代刷">' + THP.esc(s.banned_words || '') + '</textarea></div>' +
        '<div class="flex gap8 wrap mt8" style="align-items:center">' +
        '<label class="btn ghost small" style="cursor:pointer">📄 从文件导入词库<input type="file" id="st-banned-file" accept=".txt,.csv,.json,text/plain" style="display:none"></label>' +
        '<button class="btn ghost small" id="st-banned-clear">清空</button>' +
        '<span class="muted" id="st-banned-count"></span>' +
        '</div>' +
        '<div class="muted mt8">一个词一行（逗号分隔也行），重复的会自动去掉。导入或粘贴后，记得点最下方的「保存全部设置」才生效。</div>' +
        '</div>' +
        '<button class="btn block big mt16" id="st-save">保存全部设置</button>';
      setupSettingsRules(panel, s);
      THP.qs('#st-reg', panel).addEventListener('click', function () { this.classList.toggle('on'); });
      THP.qs('#st-trade', panel).addEventListener('click', function () { this.classList.toggle('on'); });
      THP.qs('#st-device', panel).addEventListener('click', function () { this.classList.toggle('on'); });
      var bannedArea = THP.qs('#st-banned', panel);
      var bannedCount = THP.qs('#st-banned-count', panel);
      function bannedWords() {
        var seen = {}, out = [];
        String(bannedArea.value || '').split(/[\r\n,，、;；]+/).forEach(function (w) {
          w = w.trim();
          if (w && !seen[w]) { seen[w] = 1; out.push(w); }
        });
        return out;
      }
      function refreshBannedCount() { bannedCount.textContent = '当前 ' + bannedWords().length + ' 个词'; }
      bannedArea.addEventListener('input', refreshBannedCount);
      refreshBannedCount();
      THP.qs('#st-banned-clear', panel).addEventListener('click', function () {
        THP.confirm('清空违禁词？', '清空并保存后，昵称和公司名将不再做违禁词拦截。', '清空').then(function (yes) {
          if (!yes) { return; }
          bannedArea.value = '';
          refreshBannedCount();
        });
      });
      THP.qs('#st-banned-file', panel).addEventListener('change', function () {
        var file = this.files && this.files[0];
        if (!file) { return; }
        var input = this;
        var reader = new FileReader();
        reader.onload = function () {
          var text = String(reader.result || '');
          var existing = bannedArea.value.trim();
          bannedArea.value = (existing ? existing + '\n' : '') + text;
          refreshBannedCount();
          THP.toast('已导入 ' + file.name + '（记得点「保存全部设置」）', 'ok');
          input.value = '';
        };
        reader.onerror = function () { THP.toast('文件读取失败，换个 txt 试试', 'err'); input.value = ''; };
        reader.readAsText(file, 'utf-8');
      });
      THP.qs('#st-keyclear', panel).addEventListener('click', function () {
        THP.qs('#st-key', panel).value = '__clear__';
        THP.toast('保存后将清除 Key（将回退到 config.php 里的 Key）', 'info');
      });
      THP.qs('#st-save', panel).addEventListener('click', function () {
        var btn = this;
        var payload = {
          event_name: THP.qs('#st-name', panel).value.trim(),
          announcement: THP.qs('#st-ann', panel).value,
          registration_open: THP.qs('#st-reg', panel).classList.contains('on') ? '1' : '0',
          trade_enabled: THP.qs('#st-trade', panel).classList.contains('on') ? '1' : '0',
          device_limit_enabled: THP.qs('#st-device', panel).classList.contains('on') ? '1' : '0',
          oss_base_url: THP.qs('#st-oss', panel).value.trim(),
          ai_endpoint: THP.qs('#st-ep', panel).value.trim(),
          ai_model: THP.qs('#st-model', panel).value.trim(),
          banned_words: THP.qs('#st-banned', panel).value.trim()
        };
        panel.querySelectorAll('[data-rule]').forEach(function (input) {
          payload[input.getAttribute('data-rule')] = input.value;
        });
        var newsAuto = THP.qs('#st-news-auto', panel);
        if (newsAuto) { payload.news_auto_enabled = newsAuto.classList.contains('on') ? '1' : '0'; }
        var newsAutoPopup = THP.qs('#st-news-auto-popup', panel);
        if (newsAutoPopup) { payload.news_auto_popup_enabled = newsAutoPopup.classList.contains('on') ? '1' : '0'; }
        var key = THP.qs('#st-key', panel).value;
        if (key !== '') { payload.ai_key = key; }
        THP.busy(btn, true);
        THP.api('admin.settings_update', payload).then(function () {
          THP.toast('设置已保存', 'ok');
          THP.refreshMe().catch(function () {});
        }).catch(function (e) { THP.toast(e.message, 'err'); }).finally(function () { THP.busy(btn, false); });
      });
    }).catch(function (e) { panel.innerHTML = '<div class="notice err">' + THP.esc(e.message) + '</div>'; });
  }

  // 管理员改公司名（默认公司也能改）
  function renameCompany(companyId, d, after) {
    var THP = window.THP;
    var company = null;
    (d.companies || []).forEach(function (c) { if (String(c.id) === String(companyId)) { company = c; } });
    if (!company) { return; }
    var m = THP.modal(
      '<h3>修改公司名</h3>' +
      '<p class="muted">当前：<b>' + THP.esc(company.name) + '</b>' + (company.is_default ? '（默认公司）' : '') + '</p>' +
      '<div class="field"><label>新名字（2-12 位中文 / 字母 / 数字）</label><input class="input" id="ar-name" maxlength="12" value="' + THP.esc(company.name) + '"></div>' +
      '<div class="modal-actions"><button class="btn ghost" data-close>取消</button><button class="btn blue" id="ar-ok">保存</button></div>'
    );
    THP.qs('#ar-ok', m.el).addEventListener('click', function () {
      var name = THP.qs('#ar-name', m.el).value.trim();
      if (!name || name === company.name) { m.close(); return; }
      var btn = this;
      THP.busy(btn, true);
      THP.api('admin.company_rename', { company_id: companyId, name: name }).then(function (r) {
        m.close();
        THP.toast('已改名为「' + r.name + '」', 'ok');
        if (after) { after(); }
      }).catch(function (e) { THP.toast(e.message, 'err'); }).finally(function () { THP.busy(btn, false); });
    });
  }

  // ================= 公共小工具 =================
  function copyText(text, okMsg) {
    var THP = window.THP;
    function fallback() { window.prompt('请手动复制：', text); }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { THP.toast(okMsg || '已复制到剪贴板', 'ok'); }).catch(fallback);
    } else { fallback(); }
  }

  function uploadImage(file) {
    var THP = window.THP;
    var fd = new FormData();
    fd.append('file', file);
    return THP.api('admin.upload', fd, { formData: true });
  }

  function qrModal(code, type, title) {
    var THP = window.THP;
    var base = THP.state.base_url || (location.origin + location.pathname.replace(/[^\/]*$/, ''));
    var link = base + '/?c=' + code;
    var m = THP.modal(
      '<h3>' + THP.esc(title) + '</h3>' +
      '<p class="muted center">' + (type === 'story' ? '故事代码' : '题目代码') + '</p>' +
      '<div class="code-big">' + code + '</div>' +
      '<div class="qr-box" id="qm-qr"></div>' +
      '<div class="muted center" style="word-break:break-all">' + THP.esc(link) + '</div>' +
      '<div class="modal-actions"><button class="btn ghost" id="qm-copy">复制链接</button><button class="btn" id="qm-dl">下载二维码</button><button class="btn ghost" data-close>关闭</button></div>'
    );
    THP.renderQR(THP.qs('#qm-qr', m.el), link, 200);
    THP.qs('#qm-copy', m.el).addEventListener('click', function () { copyText(link); });
    THP.qs('#qm-dl', m.el).addEventListener('click', function () { THP.downloadQR(THP.qs('#qm-qr', m.el), 'thp-' + type + '-' + code + '.png'); });
  }
})();
