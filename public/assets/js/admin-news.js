/* 管理员新闻与通知编辑模块 */
(function () {
  'use strict';

  var state = {
    host: null,
    companies: [],
    templates: [],
    published: [],
    editingId: 0,
    editingPool: 'news',
    templatePage: 1,
    templatePageSize: 10,
    aiDraft: false,
    onChanged: null
  };

  function THP() { return window.THP; }
  function esc(v) { return window.THP.esc(v === null || v === undefined ? '' : String(v)); }
  function qs(sel) { return window.THP.qs(sel, state.host); }
  function fmtTime(v) {
    if (!v) { return '-'; }
    return String(v).replace('T', ' ').slice(0, 19);
  }
  function idsOf(raw) {
    return String(raw || '').split(',').map(function (v) { return parseInt(v, 10); }).filter(function (v) { return v > 0; });
  }
  function companyNames(raw) {
    var names = [];
    idsOf(raw).forEach(function (id) {
      state.companies.forEach(function (c) { if (Number(c.id) === id) { names.push(c.name); } });
    });
    return names.length ? names.join(' / ') : '-';
  }
  function selectedValues(sel) {
    return Array.prototype.slice.call(sel.selectedOptions || []).map(function (o) { return o.value; }).join(',');
  }
  function setSelected(sel, raw) {
    var ids = idsOf(raw);
    Array.prototype.slice.call(sel.options || []).forEach(function (o) {
      o.selected = ids.indexOf(parseInt(o.value, 10)) !== -1;
    });
  }
  function companyOptions() {
    return state.companies.map(function (c) {
      return '<option value="' + Number(c.id) + '">' + esc(c.name) + ' #' + Number(c.id) + '</option>';
    }).join('');
  }
  function sourceLabel(src) {
    return src === 'ai' ? 'AI' : (src === 'auto' ? '自动' : (src === 'event' ? '随机事件' : (src === 'notice' ? '通知' : '手动')));
  }

  function formHtml() {
    return '<div class="news-editor-grid">' +
      '<div class="news-editor-form">' +
      '<div class="grid2">' +
      '<div class="field" style="margin-top:0"><label>类型</label><select class="input" id="an-kind">' +
      '<option value="market">股市新闻（影响未来股价）</option><option value="notice">全站通知（不直接影响股价）</option></select></div>' +
      '<div class="field" style="margin-top:0" id="an-pinned-field"><label>置顶 30 分钟</label><div class="switch" id="an-pinned"></div></div>' +
      '<div class="field" style="margin-top:0" id="an-popup-field"><label>手动发布时弹窗</label><div class="switch" id="an-popup"></div><div class="setting-help">默认关闭；仅影响手动发布。自动播报是否弹窗由设置页的全局开关控制；全站通知始终弹窗。</div></div>' +
      '<div class="field" style="margin-top:0" id="an-enabled-field"><label>参与自动播报</label><div class="switch on" id="an-enabled"></div><div class="setting-help">仅普通股市新闻模板参与每 15 分钟随机抽取；随机事件池按排期发布。</div></div>' +
      '</div>' +
      '<div class="field"><label>标题（1-60 字）</label><input class="input" id="an-title" maxlength="60" placeholder="例：红魔馆茶叶订单激增"></div>' +
      '<div class="field"><label>正文（1-2000 字）</label><textarea class="input" id="an-body" rows="7" maxlength="2000" placeholder="新闻正文 / 通知内容"></textarea></div>' +
      '<div id="an-market-fields">' +
      '<div class="grid2">' +
      '<div class="field"><label>利好公司（未来 8 个价格点逐步生效）</label><select class="input multi-select" id="an-positive" multiple size="5">' + companyOptions() + '</select></div>' +
      '<div class="field"><label>利空公司（未来 8 个价格点逐步生效）</label><select class="input multi-select" id="an-negative" multiple size="5">' + companyOptions() + '</select></div>' +
      '</div>' +
      '<div class="field"><label>影响幅度：<b id="an-impact-label">5.0%</b></label>' +
      '<input class="range-input" id="an-impact" type="range" min="0" max="20" step="0.5" value="5">' +
      '<div class="setting-help">0 表示只播报新闻、不改变未来走势；最高 20%。新闻只重算尚未发生的自动预测点，不改写已成交价格。</div></div>' +
      '</div>' +
      '<div class="news-ai-box">' +
      '<div class="field" style="margin-top:0"><label>AI 写作方向（管理员给大致方向，AI 完善文本）</label>' +
      '<textarea class="input" id="an-direction" rows="3" maxlength="500" placeholder="例如：写一条红魔馆利好新闻，轻松搞笑，突出咲夜很忙"></textarea></div>' +
      '<button class="btn ghost small" id="an-draft">🤖 AI 起草标题和正文</button>' +
      '</div>' +
      '<div class="flex gap8 wrap mt12">' +
      '<button class="btn blue" id="an-save">保存为新模板 / 更新模板</button>' +
      '<button class="btn" id="an-publish">立即发布</button>' +
      '<button class="btn ghost" id="an-reset">清空编辑</button>' +
      '</div>' +
      '<div class="muted mt8" id="an-edit-hint">当前为新建模板。</div>' +
      '</div>' +
      '<div class="news-guide">' +
      '<div class="card-title" style="margin-top:0"><span class="dot"></span>播报规则</div>' +
      '<p>股市新闻默认只进入天狗快讯；打开“发布后弹窗”后才进入玩家股市页未读队列。全站通知始终弹窗。玩家关闭弹窗后才记为已读，多条未读会逐条弹。</p>' +
      '<p>自动播报只从<b>已启用</b>的股市新闻模板中随机抽取。后台设置可调间隔和每次 1~2 条。</p>' +
      '<p>随机事件池模板不参与自动播报；保存后会同步该模板关联的全部历史事件与快讯的标题、正文。利好 / 利空公司与影响幅度仍只同步尚未生效的排期和未来快讯。</p>' +
      '<p>利好 / 利空只改变未来预测。AI 生成的价格和新闻影响都受单步 ±20% 与三倍 / 三分之一价格边界限制。</p>' +
      '</div>' +
      '</div>';
  }

  function shellHtml() {
    return '<div class="card">' +
      '<div class="flex-between wrap gap8"><div><div class="card-title" style="margin:0"><span class="dot"></span>新闻 / 通知编辑与播报</div>' +
      '<div class="muted">可手动编写，也可给 AI 一个大方向。发布后自动进入对应未读队列。</div></div>' +
      '<button class="btn ghost small" id="an-refresh">刷新列表</button></div>' +
      '<div id="an-form" class="mt12">' + formHtml() + '</div></div>' +
      '<div class="card mt12"><div class="flex-between wrap gap8"><div class="card-title" style="margin:0"><span class="dot"></span>新闻模板 <span class="badge gray" id="an-tpl-count">共 0 条</span></div>' +
      '<div class="flex gap8 wrap"><select class="input" id="an-tpl-pool" style="width:150px"><option value="all">全部模板</option><option value="news">普通新闻模板</option><option value="event">随机事件池</option></select></div></div>' +
      '<div id="an-templates" class="mt12"><div class="empty">读取中…</div></div><div class="company-pager" id="an-tpl-pager"></div></div>' +
      '<div class="card mt12"><div class="flex-between wrap gap8"><div class="card-title" style="margin:0"><span class="dot"></span>已发布新闻 / 通知 <span class="badge gray" id="an-pub-count">共 0 条</span></div>' +
      '<div class="flex gap8 wrap"><select class="input" id="an-pub-kind" style="width:128px"><option value="all">全部类型</option><option value="market">新闻</option><option value="notice">通知</option></select>' +
      '<button class="btn small danger" id="an-pub-clear">删除当前类型全部</button></div></div><div id="an-published" class="mt12"><div class="empty">读取中…</div></div></div>';
  }

  function bindForm() {
    var kindSel = qs('#an-kind');
    var impact = qs('#an-impact');
    function refreshKind() {
      qs('#an-market-fields').style.display = kindSel.value === 'notice' ? 'none' : '';
      qs('#an-popup-field').style.display = kindSel.value === 'notice' ? 'none' : '';
    }
    kindSel.addEventListener('change', refreshKind);
    impact.addEventListener('input', function () { qs('#an-impact-label').textContent = Number(impact.value).toFixed(1) + '%'; });
    qs('#an-pinned').addEventListener('click', function () { this.classList.toggle('on'); });
    qs('#an-popup').addEventListener('click', function () { this.classList.toggle('on'); });
    qs('#an-enabled').addEventListener('click', function () { this.classList.toggle('on'); });
    qs('#an-reset').addEventListener('click', function () { resetForm(); });
    qs('#an-save').addEventListener('click', function () { saveTemplate(this, false); });
    qs('#an-publish').addEventListener('click', function () { saveTemplate(this, true); });
    qs('#an-draft').addEventListener('click', function () {
      var btn = this;
      var direction = qs('#an-direction').value.trim();
      if (!direction) { THP().toast('先写一个 AI 写作方向', 'err'); return; }
      THP().busy(btn, true);
      THP().api('admin.news_draft', {
        kind: kindSel.value,
        direction: direction,
        positive_company_ids: selectedValues(qs('#an-positive')),
        negative_company_ids: selectedValues(qs('#an-negative')),
        impact_percent: Number(impact.value)
      }).then(function (r) {
        qs('#an-title').value = r.title || '';
        qs('#an-body').value = r.body || '';
        state.aiDraft = true;
        THP().toast('AI 草稿已填入，请检查后发布', 'ok');
      }).catch(function (e) { THP().toast(e.message, 'err'); }).finally(function () { THP().busy(btn, false); });
    });
    refreshKind();
  }

  function resetForm() {
    state.editingId = 0;
    state.editingPool = 'news';
    state.aiDraft = false;
    qs('#an-kind').disabled = false;
    qs('#an-publish').disabled = false;
    qs('#an-kind').value = 'market';
    qs('#an-title').value = '';
    qs('#an-body').value = '';
    qs('#an-direction').value = '';
    qs('#an-impact').value = '5';
    qs('#an-impact-label').textContent = '5.0%';
    qs('#an-pinned').classList.remove('on');
    qs('#an-popup').classList.remove('on');
    qs('#an-enabled').classList.add('on');
    qs('#an-positive').selectedIndex = -1;
    qs('#an-negative').selectedIndex = -1;
    qs('#an-market-fields').style.display = '';
    qs('#an-pinned-field').style.display = '';
    qs('#an-enabled-field').style.display = '';
    qs('#an-popup-field').style.display = '';
    qs('#an-edit-hint').textContent = '当前为新建模板。';
  }

  function formPayload() {
    var kind = qs('#an-kind').value;
    return {
      id: state.editingId || 0,
      pool: state.editingPool,
      kind: kind,
      title: qs('#an-title').value.trim(),
      body: qs('#an-body').value.trim(),
      positive_company_ids: kind === 'notice' ? '' : selectedValues(qs('#an-positive')),
      negative_company_ids: kind === 'notice' ? '' : selectedValues(qs('#an-negative')),
      impact_percent: kind === 'notice' ? 0 : Number(qs('#an-impact').value),
      enabled: qs('#an-enabled').classList.contains('on') ? 1 : 0,
      popup_enabled: kind === 'notice' ? 1 : (qs('#an-popup').classList.contains('on') ? 1 : 0),
      pinned: qs('#an-pinned').classList.contains('on') ? 1 : 0
    };
  }

  function saveTemplate(btn, publish) {
    var payload = formPayload();
    if (!payload.title || !payload.body) { THP().toast('标题和正文都要填写', 'err'); return; }
    if (publish) {
      if (state.editingPool === 'event') { THP().toast('随机事件池模板只能按排期发布', 'err'); return; }
      if (!window.confirm('确认立即发布「' + payload.title + '」？')) { return; }
      payload.source = state.aiDraft ? 'ai' : 'manual';
      if (state.editingId) { payload.template_id = state.editingId; }
      THP().busy(btn, true);
      THP().api('admin.news_publish', payload).then(function () {
        THP().toast(payload.kind === 'notice' ? '全站通知已发布' : '股市新闻已发布', 'ok');
        state.aiDraft = false;
        if (state.onChanged) { state.onChanged(); }
        load();
      }).catch(function (e) { THP().toast(e.message, 'err'); }).finally(function () { THP().busy(btn, false); });
      return;
    }
    THP().busy(btn, true);
    THP().api('admin.news_save', payload).then(function (r) {
      state.editingId = Number(r.id) || 0;
      state.editingPool = r.pool === 'event' ? 'event' : 'news';
      qs('#an-edit-hint').textContent = state.editingPool === 'event'
        ? '正在编辑随机事件池模板 #' + state.editingId + '；标题与正文已同步全部关联记录。'
        : '正在编辑模板 #' + state.editingId + '。';
      THP().toast('新闻模板已保存', 'ok');
      load();
    }).catch(function (e) { THP().toast(e.message, 'err'); }).finally(function () { THP().busy(btn, false); });
  }

  function editTemplate(t) {
    var isEvent = t.pool === 'event';
    state.editingId = Number(t.id);
    state.editingPool = isEvent ? 'event' : 'news';
    state.aiDraft = false;
    qs('#an-kind').value = t.kind === 'notice' ? 'notice' : 'market';
    qs('#an-kind').disabled = isEvent;
    qs('#an-publish').disabled = isEvent;
    qs('#an-title').value = t.title || '';
    qs('#an-body').value = t.body || '';
    qs('#an-direction').value = '';
    qs('#an-impact').value = String(Number(t.impact_percent) || 0);
    qs('#an-impact-label').textContent = Number(t.impact_percent || 0).toFixed(1) + '%';
    qs('#an-pinned').classList.remove('on');
    qs('#an-popup').classList.toggle('on', !!Number(t.popup_enabled));
    qs('#an-enabled').classList.toggle('on', !!Number(t.enabled));
    setSelected(qs('#an-positive'), t.positive_company_ids);
    setSelected(qs('#an-negative'), t.negative_company_ids);
    qs('#an-market-fields').style.display = t.kind === 'notice' ? 'none' : '';
    qs('#an-pinned-field').style.display = isEvent ? 'none' : '';
    qs('#an-enabled-field').style.display = isEvent ? 'none' : '';
    qs('#an-popup-field').style.display = (isEvent || t.kind === 'notice') ? 'none' : '';
    qs('#an-edit-hint').textContent = isEvent
      ? '正在编辑随机事件池模板 #' + t.id + '；保存后标题与正文同步全部关联事件和快讯，利好/利空与幅度只同步尚未生效部分。'
      : '正在编辑模板 #' + t.id + '。';
    qs('#an-title').scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function publishTemplate(t) {
    if (!window.confirm('立即发布模板「' + t.title + '」？')) { return; }
    THP().api('admin.news_publish', {
      kind: t.kind,
      title: t.title,
      body: t.body,
      positive_company_ids: t.positive_company_ids || '',
      negative_company_ids: t.negative_company_ids || '',
      impact_percent: Number(t.impact_percent) || 0,
      popup_enabled: t.kind === 'notice' ? 1 : (Number(t.popup_enabled) ? 1 : 0),
      template_id: t.id,
      source: 'manual'
    }).then(function () {
      THP().toast('已发布', 'ok');
      if (state.onChanged) { state.onChanged(); }
      load();
    }).catch(function (e) { THP().toast(e.message, 'err'); });
  }

  function templateById(id) {
    var found = null;
    state.templates.forEach(function (t) { if (Number(t.id) === Number(id)) { found = t; } });
    return found;
  }

  function renderTemplates() {
    var box = qs('#an-templates');
    var pager = qs('#an-tpl-pager');
    var filter = qs('#an-tpl-pool') ? qs('#an-tpl-pool').value : 'all';
    var items = state.templates.filter(function (t) {
      return filter === 'all' || (filter === 'event' ? t.pool === 'event' : t.pool !== 'event');
    });
    var count = qs('#an-tpl-count');
    if (count) { count.textContent = '共 ' + items.length + ' 条'; }
    if (!items.length) {
      box.innerHTML = '<div class="empty">' + (filter === 'event' ? '随机事件池还没有模板' : '还没有模板，先在上方写一条吧') + '</div>';
      if (pager) { pager.innerHTML = ''; }
      return;
    }
    var pages = Math.max(1, Math.ceil(items.length / state.templatePageSize));
    state.templatePage = Math.min(Math.max(1, state.templatePage), pages);
    var start = (state.templatePage - 1) * state.templatePageSize;
    var pageItems = items.slice(start, start + state.templatePageSize);
    var html = '<div class="table-wrap"><table class="thp-table news-admin-table"><thead><tr><th>类型</th><th>池</th><th>标题</th><th>利好</th><th>利空</th><th>幅度</th><th>状态</th><th>最近使用 / 排期</th><th>操作</th></tr></thead><tbody>';
    pageItems.forEach(function (t) {
      var isEvent = t.pool === 'event';
      var schedule = '';
      if (isEvent) {
        schedule = t.event_effective_at
          ? '<div class="muted">排期 ' + fmtTime(t.event_effective_at) + (t.event_applied_at ? ' · 已生效' : ' · 待生效') + '</div>'
          : '<div class="muted">未绑定事件</div>';
      }
      var actions = '<button class="btn small ghost" data-at-edit="' + Number(t.id) + '">编辑</button>';
      if (!isEvent) {
        actions += '<button class="btn small ghost" data-at-toggle="' + Number(t.id) + '">' + (t.enabled ? '停用' : '启用') + '</button>' +
          '<button class="btn small" data-at-pub="' + Number(t.id) + '">发布</button>' +
          '<button class="btn small danger" data-at-del="' + Number(t.id) + '">删除</button>';
      }
      html += '<tr><td>' + (t.kind === 'notice' ? '<span class="badge gray">通知</span>' : '<span class="badge warn">新闻</span>') + '</td>' +
        '<td>' + (isEvent ? '<span class="badge err">随机池</span>' : '<span class="badge gray">普通</span>') + '</td>' +
        '<td><b>' + esc(t.title) + '</b>' + (!isEvent && t.kind === 'market' && Number(t.popup_enabled) ? ' <span class="badge warn">弹窗</span>' : '') +
        '<div class="muted news-body-preview">' + esc(String(t.body || '').slice(0, 42)) + '</div>' + schedule + '</td>' +
        '<td>' + esc(companyNames(t.positive_company_ids)) + '</td><td>' + esc(companyNames(t.negative_company_ids)) + '</td>' +
        '<td>' + (t.kind === 'notice' ? '-' : Number(t.impact_percent || 0).toFixed(1) + '%') + '</td>' +
        '<td>' + (isEvent ? '<span class="badge gray">按排期</span>' : (t.enabled ? '<span class="badge ok">启用</span>' : '<span class="badge gray">停用</span>')) + '</td>' +
        '<td class="muted">' + (isEvent ? fmtTime(t.event_effective_at) : fmtTime(t.last_used_at)) + '</td>' +
        '<td><div class="flex gap8 wrap">' + actions + '</div></td></tr>';
    });
    box.innerHTML = html + '</tbody></table></div>';
    box.querySelectorAll('[data-at-edit]').forEach(function (el) { el.addEventListener('click', function () { var t = templateById(el.getAttribute('data-at-edit')); if (t) { editTemplate(t); } }); });
    box.querySelectorAll('[data-at-pub]').forEach(function (el) { el.addEventListener('click', function () { var t = templateById(el.getAttribute('data-at-pub')); if (t) { publishTemplate(t); } }); });
    box.querySelectorAll('[data-at-toggle]').forEach(function (el) {
      el.addEventListener('click', function () {
        var t = templateById(el.getAttribute('data-at-toggle'));
        if (!t) { return; }
        THP().api('admin.news_save', {
          id: t.id,
          kind: t.kind,
          title: t.title,
          body: t.body,
          positive_company_ids: t.positive_company_ids || '',
          negative_company_ids: t.negative_company_ids || '',
          impact_percent: Number(t.impact_percent) || 0,
          popup_enabled: Number(t.popup_enabled) || 0,
          enabled: t.enabled ? 0 : 1
        }).then(function () {
          THP().toast(t.enabled ? '已停用自动播报' : '已启用自动播报', 'ok');
          load();
        }).catch(function (e) { THP().toast(e.message, 'err'); });
      });
    });
    box.querySelectorAll('[data-at-del]').forEach(function (el) {
      el.addEventListener('click', function () {
        var t = templateById(el.getAttribute('data-at-del'));
        if (!t) { return; }
        THP().confirm('删除新闻模板？', '「' + t.title + '」删除后不可恢复，已发布的历史新闻不会删除。', '确认删除', 'danger').then(function (yes) {
          if (!yes) { return; }
          THP().api('admin.news_delete', { id: t.id }).then(function () {
            THP().toast('已删除', 'ok');
            if (state.editingId === Number(t.id)) { resetForm(); }
            load();
          }).catch(function (e) { THP().toast(e.message, 'err'); });
        });
      });
    });
    if (pager) {
      pager.innerHTML = pages > 1
        ? '<button class="btn ghost small" id="an-tpl-prev"' + (state.templatePage <= 1 ? ' disabled' : '') + '>上一页</button>' +
          '<span class="muted">第 ' + state.templatePage + ' / ' + pages + ' 页</span>' +
          '<button class="btn ghost small" id="an-tpl-next"' + (state.templatePage >= pages ? ' disabled' : '') + '>下一页</button>'
        : '';
      if (pages > 1) {
        qs('#an-tpl-prev').addEventListener('click', function () { if (state.templatePage > 1) { state.templatePage--; renderTemplates(); } });
        qs('#an-tpl-next').addEventListener('click', function () { if (state.templatePage < pages) { state.templatePage++; renderTemplates(); } });
      }
    }
  }

  function updatePublishedClearLabel() {
    var kind = qs('#an-pub-kind') ? qs('#an-pub-kind').value : 'all';
    var btn = qs('#an-pub-clear');
    if (btn) { btn.textContent = kind === 'all' ? '删除全部已发布' : (kind === 'market' ? '删除全部新闻' : '删除全部通知'); }
  }

  function renderPublished() {
    var box = qs('#an-published');
    var kind = qs('#an-pub-kind') ? qs('#an-pub-kind').value : 'all';
    updatePublishedClearLabel();
    var items = state.published.filter(function (n) { return kind === 'all' || n.kind === kind; });
    var count = qs('#an-pub-count');
    if (count) { count.textContent = '共 ' + items.length + ' 条'; }
    if (!items.length) { box.innerHTML = '<div class="empty">当前类型还没有发布记录</div>'; return; }
    var html = '<div class="table-wrap"><table class="thp-table news-admin-table"><thead><tr><th>时间</th><th>类型</th><th>标题</th><th>来源</th><th>已读人数</th><th>操作</th></tr></thead><tbody>';
    items.forEach(function (n) {
      html += '<tr><td class="muted">' + fmtTime(n.published_at) + '</td><td>' + (n.kind === 'notice' ? '通知' : '新闻') + '</td>' +
        '<td><b>' + esc(n.title) + '</b>' + (n.pinned ? ' <span class="badge warn">置顶</span>' : '') + '</td>' +
        '<td>' + esc(sourceLabel(n.source)) + '</td><td>' + Number(n.read_count || 0) + '</td>' +
        '<td><button class="btn small danger" data-ap-del="' + Number(n.id) + '">删除</button></td></tr>';
    });
    box.innerHTML = html + '</tbody></table></div>';
    box.querySelectorAll('[data-ap-del]').forEach(function (el) {
      el.addEventListener('click', function () {
        var id = Number(el.getAttribute('data-ap-del'));
        var item = state.published.find(function (n) { return Number(n.id) === id; });
        if (!item) { return; }
        THP().confirm('删除这条已发布内容？', '「' + item.title + '」会从所有玩家的未读列表和阅读历史中移除，不可恢复。已经发生的股价影响不会回滚。', '确认删除', 'danger').then(function (yes) {
          if (!yes) { return; }
          THP().busy(el, true);
          THP().api('admin.news_published_delete', { id: id }).then(function () {
            THP().toast('已删除发布记录', 'ok');
            if (state.onChanged) { state.onChanged(); }
            load();
          }).catch(function (e) { THP().toast(e.message, 'err'); }).finally(function () { THP().busy(el, false); });
        });
      });
    });
  }

  function clearPublished(btn) {
    var kind = qs('#an-pub-kind').value;
    var items = state.published.filter(function (n) { return kind === 'all' || n.kind === kind; });
    if (!items.length) { THP().toast('当前类型没有可删除的发布记录', 'info'); return; }
    var label = kind === 'all' ? '全部已发布新闻和通知' : (kind === 'market' ? '全部已发布新闻' : '全部已发布通知');
    THP().confirm('清空' + label + '？', '将删除 ' + items.length + ' 条记录，并清除所有玩家的对应阅读记录。此操作不可恢复，已经发生的股价影响不会回滚。', '确认全部删除', 'danger').then(function (yes) {
      if (!yes) { return; }
      THP().busy(btn, true);
      THP().api('admin.news_published_clear', { kind: kind }).then(function (r) {
        THP().toast('已删除 ' + Number(r.deleted || 0) + ' 条发布记录', 'ok');
        if (state.onChanged) { state.onChanged(); }
        load();
      }).catch(function (e) { THP().toast(e.message, 'err'); }).finally(function () { THP().busy(btn, false); });
    });
  }

  function load() {
    THP().api('admin.news_list', {}, { method: 'GET' }).then(function (d) {
      state.templates = d.templates || [];
      state.published = d.published || [];
      renderTemplates();
      renderPublished();
    }).catch(function (e) {
      qs('#an-templates').innerHTML = '<div class="notice err">' + esc(e.message) + '</div>';
    });
  }

  window.THP_ADMIN_NEWS = {
    render: function (host, companies, onChanged) {
      state.host = host;
      state.companies = companies || [];
      state.onChanged = onChanged || null;
      host.innerHTML = shellHtml();
      bindForm();
      resetForm();
      qs('#an-refresh').addEventListener('click', load);
      qs('#an-tpl-pool').addEventListener('change', function () { state.templatePage = 1; renderTemplates(); });
      qs('#an-pub-kind').addEventListener('change', renderPublished);
      qs('#an-pub-clear').addEventListener('click', function () { clearPublished(this); });
      load();
    }
  };
})();
