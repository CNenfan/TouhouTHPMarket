<?php
declare(strict_types=1);
if (!defined('THP_APP')) { http_response_code(403); exit('forbidden'); }

// ===================== 动态行情后台 =====================
function thp_admin_market_range($raw)
{
    $allowed = array(10, 30, 60, 120, 180, 300, 720);
    $range = (int)$raw;
    return in_array($range, $allowed, true) ? $range : 60;
}

function thp_admin_market_hours($raw)
{
    $hours = (int)$raw;
    return in_array($hours, array(1, 2, 3, 5), true) ? $hours : 1;
}

function thp_admin_market_view($raw)
{
    $view = (string)$raw;
    return in_array($view, array('past', 'future', 'both'), true) ? $view : 'both';
}

function thp_admin_market_range_label($minutes)
{
    $minutes = (int)$minutes;
    return $minutes >= 60 && $minutes % 60 === 0 ? ((int)($minutes / 60)) . ' 小时' : $minutes . ' 分钟';
}

function thp_admin_market_view_label($view, $minutes)
{
    $range = thp_admin_market_range_label($minutes);
    if ($view === 'past') { return '过去 ' . $range; }
    if ($view === 'future') { return '未来 ' . $range; }
    return '过去 ' . $range . ' + 未来 ' . $range;
}

// 后台查看未来区间时按需补齐预测点；只补缺口，不重算已经存在的自动预测。
function thp_admin_market_fill_horizon($toTick)
{
    $date = thp_today();
    $tick = thp_tick_of_day();
    $toTick = max($tick, min(THP_TICKS_PER_DAY - 1, (int)$toTick));
    if ($toTick <= $tick) { return; }
    $companies = thp_companies();
    if (count($companies) === 0) { return; }
    $points = thp_market_load_day_points($date);
    $firstMissing = null;
    foreach ($companies as $c) {
        $cid = (string)$c['id'];
        $start = (int)thp_market_company_start_tick($c, $date);
        if ($start > $tick) { continue; }
        for ($t = $tick + 1; $t <= $toTick; $t++) {
            if (!isset($points[$t][$cid])) {
                $firstMissing = $firstMissing === null ? $t : min($firstMissing, $t);
                break;
            }
        }
    }
    if ($firstMissing !== null) {
        thp_market_generate_range($date, $firstMissing, $toTick, 'auto');
    }
}

function thp_admin_market_snapshot($rangeMinutes, $view = 'both')
{
    global $pdo;
    thp_news_tick();
    $view = thp_admin_market_view($view);
    $rangeMinutes = thp_admin_market_range($rangeMinutes);
    $date = thp_today();
    $tick = thp_tick_of_day();
    $rangeTicks = max(1, (int)round($rangeMinutes * 60 / THP_TICK_SECONDS));
    $engine = thp_market_ensure_engine($view === 'past' ? 0 : $rangeTicks);
    $toFuture = min(THP_TICKS_PER_DAY - 1, $tick + $rangeTicks);
    if ($view !== 'past') { thp_admin_market_fill_horizon($toFuture); }
    if ($view === 'future') {
        $from = $tick;
        $to = $toFuture;
    } elseif ($view === 'both') {
        $from = max(0, $tick - $rangeTicks);
        $to = $toFuture;
    } else {
        $from = max(0, $tick - $rangeTicks);
        $to = $tick;
    }
    $companies = thp_companies();
    $points = thp_market_load_day_points($date);
    $full = thp_market_build_full_series($points, $companies, $to, $date);
    $indexSeries = thp_market_index_series_from_full($full, $companies, $from, $to);
    $prices = thp_market_price_map($full, $companies, $tick);
    $holders = thp_company_holders();
    $buyers = thp_company_buyer_counts();
    $volumes = array();
    foreach ($pdo->query("SELECT company_id, SUM(qty) AS qty, SUM(amount) AS amount, COUNT(*) AS trades
        FROM stock_trades WHERE created_at >= CURDATE() GROUP BY company_id") as $r) {
        $volumes[(string)(int)$r['company_id']] = array('qty' => (int)$r['qty'], 'amount' => (int)$r['amount'], 'trades' => (int)$r['trades']);
    }
    $ownerIds = array();
    foreach ($companies as $c) { if ((int)$c['owner_user_id'] > 0) { $ownerIds[] = (int)$c['owner_user_id']; } }
    $owners = array();
    if (count($ownerIds) > 0) {
        $in = implode(',', array_map('intval', array_unique($ownerIds)));
        foreach ($pdo->query('SELECT id, username FROM users WHERE id IN (' . $in . ')') as $r) { $owners[(int)$r['id']] = (string)$r['username']; }
    }
    $outCompanies = array();
    $trend = array();
    foreach ($companies as $c) {
        $cid = (string)$c['id'];
        $start = (int)$full['starts'][$cid];
        $path = $full['series'][$cid];
        $seen = array();
        for ($t = $start; $t <= $tick; $t++) {
            if (isset($path[$t]) && $path[$t] !== null) { $seen[] = (float)$path[$t]; }
        }
        if (count($seen) === 0) { $seen[] = (float)$c['base_price']; }
        $price = $prices[$cid] ?? end($seen);
        $history = array_slice($path, $from, $to - $from + 1);
        $trend[$cid] = $history;
        $open = (float)$full['bases'][$cid];
        $outCompanies[] = array(
            'id' => (int)$c['id'],
            'name' => (string)$c['name'],
            'is_default' => ((int)$c['is_default'] === 1),
            'owner_user_id' => (int)$c['owner_user_id'],
            'owner_name' => isset($owners[(int)$c['owner_user_id']]) ? $owners[(int)$c['owner_user_id']] : '',
            'base_price' => thp_price_norm($open),
            'price' => thp_price_norm($price),
            'holders' => isset($holders[$cid]) ? $holders[$cid] : 0,
            'buyers' => isset($buyers[$cid]) ? $buyers[$cid] : 0,
            'volume_qty' => isset($volumes[$cid]) ? $volumes[$cid]['qty'] : 0,
            'volume_amount' => isset($volumes[$cid]) ? $volumes[$cid]['amount'] : 0,
            'trades' => isset($volumes[$cid]) ? $volumes[$cid]['trades'] : 0,
            'stat' => array(
                'open' => thp_price_norm($open),
                'close' => thp_price_norm($price),
                'min' => thp_price_norm(min($seen)),
                'max' => thp_price_norm(max($seen)),
                'change_percent' => $open > 0 ? round(((float)$price / $open - 1) * 100, 2) : 0,
            ),
            'history' => $history,
        );
    }
    $indexNow = thp_market_index_value($full, $companies, $tick);
    $interventionTick = (int)thp_setting('market_intervention_end_tick', '0');
    return array(
        'date' => $date,
        'tick' => $tick,
        'tick_from' => $from,
        'tick_to' => $to,
        'tick_seconds' => THP_TICK_SECONDS,
        'view_range_minutes' => $rangeMinutes,
        'view' => $view,
        'view_label' => thp_admin_market_view_label($view, $rangeMinutes),
        'engine' => $engine,
        'next_engine_at' => date('Y-m-d H:i:s', strtotime((string)thp_setting('market_last_generated_at', thp_now())) + thp_market_rules()['market_refresh_minutes'] * 60),
        'intervention' => array(
            'end_tick' => $interventionTick,
            'end_time' => $interventionTick > $tick ? thp_market_tick_time_label($date, $interventionTick) : '',
            'source' => (string)thp_setting('market_intervention_source', ''),
            'at' => (string)thp_setting('market_intervention_at', ''),
        ),
        'companies' => $outCompanies,
        'trend' => $trend,
        'index' => array(
            'value' => $indexNow,
            'open' => 1000,
            'change_percent' => round(($indexNow - 1000) / 10, 2),
            'series' => $indexSeries,
        ),
        'rank' => thp_stock_pnl_rank_from_prices($prices, 5),
        'asset_rank' => thp_asset_rank_snapshot_if_due($prices),
        'rules' => thp_market_rules(),
    );
}

function thp_act_admin_market_info()
{
    thp_require_admin_perm('market');
    return thp_admin_market_snapshot((int)thp_param('range', 60), (string)thp_param('view', 'both'));
}

function thp_act_admin_company_rename()
{
    thp_require_admin_perm('market');
    return thp_company_rename((int)thp_param('company_id'), (string)thp_param('name'));
}

function thp_act_admin_market_refresh()
{
    $admin = thp_require_admin_perm('market');
    $hours = thp_admin_market_hours((int)thp_param('hours', 1));
    $result = thp_market_ensure_engine($hours * THP_TICKS_PER_HOUR, true);
    thp_log_admin('手动刷新行情', '生成未来 ' . $hours . ' 小时，写入 ' . (int)($result['generated'] ?? 0) . ' 个点');
    return array('ok' => true, 'hours' => $hours, 'result' => $result, 'snapshot' => thp_admin_market_snapshot(60, 'both'));
}

function thp_admin_market_future_payload($companyId, $hours, $ensureEngine = true)
{
    $company = thp_company_by_id((int)$companyId);
    if (!$company) { throw new ThpError('公司不存在'); }
    $hours = thp_admin_market_hours($hours);
    $date = thp_today();
    $tick = thp_tick_of_day();
    $toTick = min(THP_TICKS_PER_DAY - 1, $tick + $hours * THP_TICKS_PER_HOUR);
    if ($ensureEngine) {
        thp_market_ensure_engine($hours * THP_TICKS_PER_HOUR);
        thp_admin_market_fill_horizon($toTick);
    }
    $companies = thp_companies();
    $points = thp_market_load_day_points($date);
    $full = thp_market_build_full_series($points, $companies, $toTick, $date);
    $cid = (string)$company['id'];
    $path = $full['series'][$cid];
    $rows = array();
    for ($t = $tick + 1; $t <= $toTick; $t++) {
        $src = isset($points[$t][$cid]) ? (string)$points[$t][$cid]['source'] : 'auto';
        $rows[] = array(
            'tick' => $t,
            'time' => thp_market_tick_time_label($date, $t),
            'price' => isset($path[$t]) ? thp_price_norm($path[$t]) : null,
            'source' => $src,
        );
    }
    $prev = thp_market_quote((int)$company['id'], max(0, $tick - 1));
    $current = thp_market_quote((int)$company['id'], $tick);
    return array(
        'company' => array(
            'id' => (int)$company['id'],
            'name' => (string)$company['name'],
            'base_price' => (float)$company['base_price'],
            'current_price' => $current,
            'prev_price' => $prev,
            'min_price' => thp_price_norm((float)$company['base_price'] * thp_market_rules()['market_min_ratio']),
            'max_price' => thp_price_norm((float)$company['base_price'] * thp_market_rules()['market_max_ratio']),
        ),
        'date' => $date,
        'tick' => $tick,
        'hours' => $hours,
        'cutoff_tick' => (int)thp_setting('market_intervention_end_tick', '0'),
        'intervention_source' => (string)thp_setting('market_intervention_source', ''),
        'rows' => $rows,
        'rules' => thp_market_rules(),
    );
}

function thp_act_admin_market_future()
{
    thp_require_admin_perm('market');
    return thp_admin_market_future_payload((int)thp_param('company_id'), (int)thp_param('hours', 1));
}

function thp_act_admin_market_future_save()
{
    $admin = thp_require_admin_perm('market');
    $companyId = (int)thp_param('company_id');
    $source = (string)thp_param('source', 'manual');
    if (!in_array($source, array('manual', 'ai'), true)) { throw new ThpError('走势来源不合法'); }
    $raw = thp_param('points', array());
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : array();
    }
    if (!is_array($raw) || count($raw) === 0) { throw new ThpError('请至少提交一个未来价格点'); }
    if (count($raw) > 320) { throw new ThpError('一次最多保存 320 个价格点'); }
    $points = array();
    foreach ($raw as $p) {
        if (!is_array($p) || !isset($p['tick'], $p['price'])) { continue; }
        $points[] = array('tick' => (int)$p['tick'], 'price' => (float)$p['price']);
    }
    $result = thp_market_save_future_points($companyId, $points, $source);
    thp_log_admin($source === 'ai' ? '确认 AI 未来走势' : '人工修改未来走势', '公司 #' . $companyId . '，截止 ' . $result['cutoff_time'] . '，写入 ' . $result['written'] . ' 个点');
    return $result;
}

function thp_act_admin_market_ai_future()
{
    $admin = thp_require_admin_perm('market');
    $companyId = (int)thp_param('company_id');
    $hours = thp_admin_market_hours((int)thp_param('hours', 1));
    $direction = trim((string)thp_param('direction'));
    if ($direction === '') { throw new ThpError('请填写希望 AI 如何修改未来走势'); }
    $payload = thp_admin_market_future_payload($companyId, $hours, false);
    $company = $payload['company'];
    $rows = $payload['rows'];
    if (count($rows) === 0) { throw new ThpError('当前没有可干预的未来价格点'); }
    $tickList = array();
    foreach ($rows as $r) { $tickList[] = array('tick' => (int)$r['tick'], 'time' => $r['time'], 'price' => $r['price']); }
    $prompt = '你是幻想乡证券交易所的行情编剧。请只修改给定公司未来 ' . $hours . ' 小时的 3 分钟价格点。'
        . "\n公司：" . $company['name'] . '（ID ' . $company['id'] . '）'
        . "\n当前价：" . thp_fmt_price((float)$company['current_price']) . '；基准价：' . thp_fmt_price((float)$company['base_price'])
        . '；允许价格区间：' . thp_fmt_price((float)$company['min_price']) . ' ~ ' . thp_fmt_price((float)$company['max_price'])
        . "\n每个点相对上一个点最多变动 20%。小波动为主，偶尔可有戏剧性大涨大跌。"
        . "\n管理员要求：" . $direction
        . "\n必须为下列每个 tick 各返回一个价格，不得增加、删除或修改 tick："
        . json_encode($tickList, JSON_UNESCAPED_UNICODE)
        . "\n只输出合法 JSON，不要 Markdown。格式：{\"comment\":\"一句行情点评\",\"points\":[{\"tick\":123,\"price\":18.25}]}";
    $content = thp_ai_chat_content('你只输出严格 JSON，不输出额外文字。', $prompt, 0.85, 3600);
    if (preg_match('/\{.*\}/s', $content, $m)) { $content = $m[0]; }
    $parsed = json_decode($content, true);
    if (!is_array($parsed) || !isset($parsed['points']) || !is_array($parsed['points'])) {
        throw new ThpError('AI 返回的走势格式不正确，请重试');
    }
    $allowed = array();
    foreach ($rows as $r) { $allowed[(int)$r['tick']] = true; }
    $rowByTick = array();
    foreach ($rows as $r) { $rowByTick[(int)$r['tick']] = $r; }
    $points = array();
    foreach ($parsed['points'] as $p) {
        if (!is_array($p) || !isset($p['tick'], $p['price'])) { continue; }
        $t = (int)$p['tick'];
        if (!isset($allowed[$t])) { continue; }
        $price = (float)$p['price'];
        if (!is_finite($price)) { continue; }
        $price = thp_price_norm(max((float)$company['min_price'], min((float)$company['max_price'], $price)));
        $points[] = array(
            'tick' => $t,
            'time' => isset($rowByTick[$t]) ? (string)$rowByTick[$t]['time'] : thp_market_tick_time_label($payload['date'], $t),
            'price' => $price,
        );
    }
    if (count($points) === 0) { throw new ThpError('AI 没有返回有效的未来价格点'); }
    usort($points, function ($a, $b) { return $a['tick'] <=> $b['tick']; });
    $comment = isset($parsed['comment']) ? trim((string)$parsed['comment']) : '';
    thp_log_admin('AI 生成未来走势建议', '公司 #' . $companyId . '，' . $hours . ' 小时，生成 ' . count($points) . ' 个待确认点');
    return array(
        'company_id' => $companyId,
        'company_name' => (string)$company['name'],
        'suggested' => count($points),
        'points' => $points,
        'comment' => $comment,
        'hours' => $hours,
        'source' => 'ai',
    );
}

function thp_act_admin_market_export()
{
    global $pdo;
    $admin = thp_require_admin_perm('market');
    $range = thp_admin_market_range((int)thp_param('range', 60));
    $snapshot = thp_admin_market_snapshot($range);
    $date = $snapshot['date'];
    $points = array();
    foreach (thp_market_load_day_points($date) as $tick => $rows) {
        foreach ($rows as $companyId => $row) {
            $points[] = array(
                'tick' => (int)$tick,
                'time' => thp_market_tick_time_label($date, (int)$tick),
                'company_id' => (int)$companyId,
                'price' => (float)$row['price'],
                'source' => (string)$row['source'],
            );
        }
    }
    $trades = array();
    $st = $pdo->prepare('SELECT company_id, side, qty, price, amount, tick, created_at
        FROM stock_trades WHERE created_at >= ? ORDER BY id ASC LIMIT 3000');
    $st->execute(array($date . ' 00:00:00'));
    foreach ($st->fetchAll() as $r) {
        $trades[] = array(
            'company_id' => (int)$r['company_id'],
            'side' => (string)$r['side'],
            'qty' => (int)$r['qty'],
            'price' => (float)$r['price'],
            'amount' => (int)$r['amount'],
            'tick' => (int)$r['tick'],
            'created_at' => (string)$r['created_at'],
        );
    }
    $companies = array();
    foreach ($snapshot['companies'] as $c) {
        $companies[] = array(
            'id' => (int)$c['id'],
            'name' => (string)$c['name'],
            'is_default' => (bool)$c['is_default'],
            'base_price' => (float)$c['base_price'],
            'open' => (float)$c['stat']['open'],
            'close' => (float)$c['stat']['close'],
            'min' => (float)$c['stat']['min'],
            'max' => (float)$c['stat']['max'],
            'change_percent' => (float)$c['stat']['change_percent'],
            'holders' => (int)$c['holders'],
            'buyers' => (int)$c['buyers'],
            'volume_qty' => (int)$c['volume_qty'],
            'volume_amount' => (int)$c['volume_amount'],
            'trade_count' => (int)$c['trades'],
        );
    }
    $payload = array(
        'format' => 'thp-market-export-v4',
        'generated_at' => thp_now(),
        'trade_date' => $date,
        'tick_seconds' => THP_TICK_SECONDS,
        'current_tick' => (int)$snapshot['tick'],
        'tick_timezone' => 'Asia/Shanghai',
        'note' => 'buyers/holders 来自服务器实际成交与持仓记录；该文件供 AI 分析，不用于导入。',
        'rules' => $snapshot['rules'],
        'intervention' => $snapshot['intervention'],
        'index' => $snapshot['index'],
        'companies' => $companies,
        'market_points' => $points,
        'stock_trades' => $trades,
        'stock_pnl_rank' => $snapshot['rank'],
        'asset_rank' => $snapshot['asset_rank'],
    );
    thp_log_admin('导出行情数据', $date . '，' . count($points) . ' 个价格点');
    $name = 'thp-market-' . $date . '-' . date('His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// ===================== 新闻 / 通知 =====================
function thp_act_admin_news_list()
{
    global $pdo;
    thp_require_admin_perm('market');
    $templates = array();
    foreach ($pdo->query("SELECT t.id, t.kind, t.pool, t.title, t.body, t.positive_company_ids, t.negative_company_ids,
        t.impact_percent, t.enabled, t.popup_enabled, t.created_by, t.created_at, t.updated_at, t.last_used_at,
        e.id AS event_id, e.effective_at AS event_effective_at, e.applied_at AS event_applied_at
        FROM news_templates t LEFT JOIN company_events e ON e.template_id = t.id
        ORDER BY (t.pool = 'event') ASC, t.id DESC") as $r) {
        $r['id'] = (int)$r['id'];
        $r['pool'] = (string)$r['pool'];
        $r['impact_percent'] = (float)$r['impact_percent'];
        $r['enabled'] = (int)$r['enabled'];
        $r['popup_enabled'] = (int)$r['popup_enabled'];
        $r['event_id'] = $r['event_id'] !== null ? (int)$r['event_id'] : null;
        $r['event_effective_at'] = $r['event_effective_at'] !== null ? (string)$r['event_effective_at'] : null;
        $r['event_applied_at'] = $r['event_applied_at'] !== null ? (string)$r['event_applied_at'] : null;
        $templates[] = $r;
    }
    $published = array();
    foreach ($pdo->query('SELECT n.id, n.kind, n.title, n.body, n.positive_company_ids, n.negative_company_ids,
        n.impact_percent, n.source, n.popup_enabled, n.pinned, n.published_at,
        (SELECT COUNT(*) FROM news_reads r WHERE r.news_id = n.id) AS read_count
        FROM news_items n ORDER BY n.id DESC LIMIT 100') as $r) {
        $r['id'] = (int)$r['id'];
        $r['impact_percent'] = (float)$r['impact_percent'];
        $r['popup_enabled'] = (int)$r['popup_enabled'];
        $r['pinned'] = (int)$r['pinned'];
        $r['read_count'] = (int)$r['read_count'];
        $published[] = $r;
    }
    return array('templates' => $templates, 'published' => $published);
}

function thp_act_admin_news_save()
{
    global $pdo;
    $admin = thp_require_admin_perm('market');
    $id = (int)thp_param('id', 0);
    $existing = null;
    if ($id > 0) {
        $check = $pdo->prepare('SELECT id, pool FROM news_templates WHERE id = ?');
        $check->execute(array($id));
        $existing = $check->fetch();
        if (!$existing) { throw new ThpError('新闻模板不存在', 404); }
    }
    $pool = ($existing && (string)$existing['pool'] === 'event') ? 'event' : 'news';
    $kind = ((string)thp_param('kind', 'market') === 'notice') ? 'notice' : 'market';
    if ($pool === 'event') { $kind = 'market'; }
    $title = trim((string)thp_param('title'));
    $body = trim((string)thp_param('body'));
    if ($title === '' || mb_strlen($title, 'UTF-8') > 60) { throw new ThpError('标题需为 1-60 个字'); }
    if ($body === '' || mb_strlen($body, 'UTF-8') > 2000) { throw new ThpError('正文需为 1-2000 个字'); }
    $companies = thp_companies();
    $positive = ($kind === 'notice') ? '' : thp_news_company_ids((string)thp_param('positive_company_ids', ''), $companies);
    $negative = ($kind === 'notice') ? '' : thp_news_company_ids((string)thp_param('negative_company_ids', ''), $companies);
    $impact = ($kind === 'notice') ? 0.0 : max(0.0, min(20.0, (float)thp_param('impact_percent', 0)));
    $enabled = ($pool === 'event') ? 1 : (in_array((string)thp_param('enabled', '1'), array('1', 'true', 'on'), true) ? 1 : 0);
    $popupEnabled = ($pool === 'event') ? 0 : (($kind === 'notice') ? 1 : (in_array((string)thp_param('popup_enabled', '0'), array('1', 'true', 'on'), true) ? 1 : 0));
    $now = thp_now();
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $st = $pdo->prepare('UPDATE news_templates SET kind = ?, pool = ?, title = ?, body = ?, positive_company_ids = ?, negative_company_ids = ?, impact_percent = ?, enabled = ?, popup_enabled = ?, updated_at = ? WHERE id = ?');
            $st->execute(array($kind, $pool, $title, $body, $positive, $negative, $impact, $enabled, $popupEnabled, $now, $id));
        } else {
            $st = $pdo->prepare('INSERT INTO news_templates (kind, pool, title, body, positive_company_ids, negative_company_ids, impact_percent, enabled, popup_enabled, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $st->execute(array($kind, $pool, $title, $body, $positive, $negative, $impact, $enabled, $popupEnabled, (int)$admin['id'], $now, $now));
            $id = (int)$pdo->lastInsertId();
            $pool = 'news';
        }
        if ($pool === 'event') {
            $eventTitleUp = $pdo->prepare('UPDATE company_events SET title = ?, body = ? WHERE template_id = ?');
            $eventTitleUp->execute(array($title, $body, $id));
            $eventFutureUp = $pdo->prepare('UPDATE company_events
                SET positive_company_ids = ?, negative_company_ids = ?, impact_percent = ?
                WHERE template_id = ? AND applied_at IS NULL AND effective_at > ?');
            $eventFutureUp->execute(array($positive, $negative, $impact, $id, $now));
            $newsTitleUp = $pdo->prepare("UPDATE news_items n
                INNER JOIN company_events e ON e.news_id = n.id
                SET n.template_id = ?, n.title = ?, n.body = ?
                WHERE e.template_id = ? AND n.source = 'event'");
            $newsTitleUp->execute(array($id, $title, $body, $id));
            $newsFutureUp = $pdo->prepare("UPDATE news_items n
                INNER JOIN company_events e ON e.news_id = n.id
                SET n.positive_company_ids = ?, n.negative_company_ids = ?, n.impact_percent = ?
                WHERE e.template_id = ? AND n.source = 'event' AND n.published_at > ?");
            $newsFutureUp->execute(array($positive, $negative, $impact, $id, $now));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    thp_log_admin($id > 0 ? '保存新闻模板' : '新建新闻模板', '#' . $id . ' ' . $title);
    return array('id' => $id, 'pool' => $pool, 'ok' => true);
}

function thp_act_admin_news_delete()
{
    global $pdo;
    $admin = thp_require_admin_perm('market');
    $id = (int)thp_param('id');
    $check = $pdo->prepare('SELECT pool FROM news_templates WHERE id = ?');
    $check->execute(array($id));
    $row = $check->fetch();
    if (!$row) { throw new ThpError('新闻模板不存在', 404); }
    if ((string)$row['pool'] === 'event') {
        throw new ThpError('随机事件池模板已绑定排期事件，不能在模板列表删除');
    }
    $st = $pdo->prepare('DELETE FROM news_templates WHERE id = ?');
    $st->execute(array($id));
    if ($st->rowCount() === 0) { throw new ThpError('新闻模板不存在', 404); }
    thp_log_admin('删除新闻模板', '#' . $id);
    return array('ok' => true);
}

function thp_act_admin_news_published_delete()
{
    global $pdo;
    thp_require_admin_perm('market');
    $id = (int)thp_param('id');
    if ($id <= 0) { throw new ThpError('请选择要删除的已发布内容'); }
    $st = $pdo->prepare('SELECT kind, title FROM news_items WHERE id = ?');
    $st->execute(array($id));
    $item = $st->fetch();
    if (!$item) { throw new ThpError('已发布内容不存在', 404); }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM news_reads WHERE news_id = ?')->execute(array($id));
        $pdo->prepare('DELETE FROM news_items WHERE id = ?')->execute(array($id));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw new ThpError('删除失败，请稍后重试', 500);
    }
    thp_log_admin('删除已发布内容', '#' . $id . ' ' . (string)$item['title']);
    return array('ok' => true, 'deleted' => 1, 'id' => $id, 'kind' => (string)$item['kind']);
}

function thp_act_admin_news_published_clear()
{
    global $pdo;
    thp_require_admin_perm('market');
    $kind = (string)thp_param('kind', 'all');
    if (!in_array($kind, array('all', 'market', 'notice'), true)) { throw new ThpError('删除类型不合法'); }
    $pdo->beginTransaction();
    try {
        if ($kind === 'all') {
            $deleted = (int)$pdo->query('SELECT COUNT(*) FROM news_items')->fetchColumn();
            $pdo->exec('DELETE FROM news_reads');
            $pdo->exec('DELETE FROM news_items');
        } else {
            $st = $pdo->prepare('SELECT COUNT(*) FROM news_items WHERE kind = ?');
            $st->execute(array($kind));
            $deleted = (int)$st->fetchColumn();
            $st = $pdo->prepare('DELETE FROM news_reads WHERE news_id IN (SELECT id FROM news_items WHERE kind = ?)');
            $st->execute(array($kind));
            $st = $pdo->prepare('DELETE FROM news_items WHERE kind = ?');
            $st->execute(array($kind));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw new ThpError('批量删除失败，已全部回滚', 500);
    }
    $label = $kind === 'all' ? '全部' : ($kind === 'market' ? '全部新闻' : '全部通知');
    thp_log_admin('清空已发布内容', $label . '，删除 ' . $deleted . ' 条');
    return array('ok' => true, 'deleted' => $deleted, 'kind' => $kind);
}

function thp_act_admin_news_draft()
{
    thp_require_admin_perm('market');
    $direction = trim((string)thp_param('direction'));
    if ($direction === '') { throw new ThpError('请填写希望 AI 撰写的新闻方向'); }
    $kind = ((string)thp_param('kind', 'market') === 'notice') ? 'notice' : 'market';
    $companies = thp_companies();
    $positive = ($kind === 'notice') ? '' : thp_news_company_ids((string)thp_param('positive_company_ids', ''), $companies);
    $negative = ($kind === 'notice') ? '' : thp_news_company_ids((string)thp_param('negative_company_ids', ''), $companies);
    $impact = ($kind === 'notice') ? 0.0 : max(0.0, min(20.0, (float)thp_param('impact_percent', 0)));
    return thp_ai_news_draft($direction, $companies, $kind, $positive, $negative, $impact);
}

function thp_act_admin_news_publish()
{
    global $pdo;
    $admin = thp_require_admin_perm('market');
    $kind = ((string)thp_param('kind', 'market') === 'notice') ? 'notice' : 'market';
    $title = trim((string)thp_param('title'));
    $body = trim((string)thp_param('body'));
    $positive = '';
    $negative = '';
    $impact = 0.0;
    $templateId = null;
    $companyList = thp_companies();
    if ($kind === 'market') {
        $positive = thp_news_company_ids((string)thp_param('positive_company_ids', ''), $companyList);
        $negative = thp_news_company_ids((string)thp_param('negative_company_ids', ''), $companyList);
        $impact = max(0.0, min(20.0, (float)thp_param('impact_percent', 0)));
    }
    $tplId = (int)thp_param('template_id', 0);
    if ($tplId > 0) {
        $st = $pdo->prepare('SELECT id, pool FROM news_templates WHERE id = ?');
        $st->execute(array($tplId));
        $tpl = $st->fetch();
        if (!$tpl) { throw new ThpError('新闻模板不存在', 404); }
        if ((string)$tpl['pool'] === 'event') { throw new ThpError('随机事件池模板只能按排期发布'); }
        $templateId = $tplId;
    }
    $source = ((string)thp_param('source', 'manual') === 'ai') ? 'ai' : 'manual';
    if ($kind === 'notice') { $source = 'notice'; }
    $pinned = in_array((string)thp_param('pinned', '0'), array('1', 'true', 'on'), true) ? 1 : 0;
    $popupEnabled = ($kind === 'notice') ? 1 : (in_array((string)thp_param('popup_enabled', '0'), array('1', 'true', 'on'), true) ? 1 : 0);
    $id = thp_news_publish_item($kind, $title, $body, $positive, $negative, $impact, $source, $templateId, $pinned, $popupEnabled);
    if ($templateId) {
        $up = $pdo->prepare('UPDATE news_templates SET last_used_at = ? WHERE id = ?');
        $up->execute(array(thp_now(), $templateId));
    }
    thp_log_admin($kind === 'notice' ? '发布全站通知' : '发布股市新闻', '#' . $id . ' ' . $title);
    return array('id' => $id, 'kind' => $kind, 'ok' => true);
}

function thp_act_admin_notice_publish()
{
    global $pdo;
    $admin = thp_require_admin_perm('market');
    $title = trim((string)thp_param('title'));
    $body = trim((string)thp_param('body'));
    $pinned = in_array((string)thp_param('pinned', '0'), array('1', 'true', 'on'), true) ? 1 : 0;
    $id = thp_news_publish_item('notice', $title, $body, '', '', 0, 'notice', null, $pinned);
    thp_log_admin('发布全站通知', '#' . $id . ' ' . $title);
    return array('id' => $id, 'kind' => 'notice', 'ok' => true);
}

// ===================== 批量用户操作 =====================
function thp_act_admin_user_bulk_update()
{
    global $pdo;
    $admin = thp_require_admin_perm('users_manage');
    $idsRaw = thp_param('ids', array());
    if (is_string($idsRaw)) {
        $decoded = json_decode($idsRaw, true);
        $idsRaw = is_array($decoded) ? $decoded : explode(',', $idsRaw);
    }
    $ids = array();
    foreach ((array)$idsRaw as $id) {
        $id = (int)$id;
        if ($id > 0) { $ids[$id] = $id; }
    }
    if (count($ids) === 0) { throw new ThpError('请先选择用户'); }
    if (count($ids) > 200) { throw new ThpError('一次最多批量处理 200 人'); }
    $op = trim((string)thp_param('op'));
    $value = thp_param('value', 0);
    $moneyOps = array('add_balance', 'set_balance');
    $roleOps = array('role_user', 'role_admin', 'set_admin_scope');
    if (in_array($op, $moneyOps, true) || in_array($op, $roleOps, true)) {
        if (thp_admin_scope($admin) !== 'full') { throw new ThpError('只有全权管理员可以批量调整代币或权限', 403); }
    }
    if (!in_array($op, array('add_balance', 'set_balance', 'ban', 'unban', 'test_on', 'test_off', 'role_user', 'role_admin', 'set_admin_scope'), true)) {
        throw new ThpError('未知批量操作');
    }
    $amount = (int)$value;
    if (in_array($op, $moneyOps, true) && ($amount < -1000000 || $amount > 1000000)) { throw new ThpError('代币数值超出范围'); }
    $scope = trim((string)$value);
    if ($op === 'set_admin_scope' && !in_array($scope, array('full', 'finance', 'data'), true)) { throw new ThpError('权限范围不合法'); }
    $in = implode(',', array_map('intval', array_values($ids)));
    $users = array();
    foreach ($pdo->query('SELECT id, username, role, admin_scope, balance, banned, is_test FROM users WHERE id IN (' . $in . ')') as $r) { $users[] = $r; }
    if (count($users) === 0) { throw new ThpError('选中的用户不存在'); }
    $updated = 0;
    $pdo->beginTransaction();
    try {
        foreach ($users as $target) {
            $uid = (int)$target['id'];
            if ((string)$target['role'] === 'admin' && thp_admin_scope($admin) !== 'full') {
                continue;
            }
            if ($op === 'add_balance' || $op === 'set_balance') {
                if ($op === 'add_balance') {
                    if ($amount === 0) { continue; }
                    thp_balance_apply($pdo, $uid, $amount, 'admin_bulk_adjust', (string)$admin['id'], '管理员批量调整代币（' . $admin['username'] . '）');
                } else {
                    if ($amount < 0) { throw new ThpError('余额不能设为负数'); }
                    $delta = $amount - (int)$target['balance'];
                    if ($delta !== 0) {
                        thp_balance_apply($pdo, $uid, $delta, 'admin_bulk_set', (string)$admin['id'], '管理员批量设置代币（' . $admin['username'] . '）');
                    }
                }
            } elseif ($op === 'ban' || $op === 'unban') {
                $ban = ($op === 'ban') ? 1 : 0;
                $st = $pdo->prepare('UPDATE users SET banned = ? WHERE id = ?');
                $st->execute(array($ban, $uid));
                if ($ban === 1) { thp_auth_revoke_user_tokens($uid); }
            } elseif ($op === 'test_on' || $op === 'test_off') {
                $isTest = ($op === 'test_on') ? 1 : 0;
                $st = $pdo->prepare('UPDATE users SET is_test = ? WHERE id = ?');
                $st->execute(array($isTest, $uid));
            } elseif ($op === 'role_user' || $op === 'role_admin') {
                if ($uid === (int)$admin['id'] && $op === 'role_user') { continue; }
                $role = ($op === 'role_admin') ? 'admin' : 'user';
                $newScope = ($role === 'admin') ? 'full' : '';
                $st = $pdo->prepare('UPDATE users SET role = ?, admin_scope = ? WHERE id = ?');
                $st->execute(array($role, $newScope, $uid));
            } elseif ($op === 'set_admin_scope') {
                if ((string)$target['role'] !== 'admin') { continue; }
                if ($uid === (int)$admin['id'] && $scope !== 'full') { continue; }
                $st = $pdo->prepare('UPDATE users SET admin_scope = ? WHERE id = ?');
                $st->execute(array($scope, $uid));
            }
            $updated++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($e instanceof ThpError) { throw $e; }
        throw new ThpError('批量操作失败，已全部回滚', 500);
    }
    thp_log_admin('批量用户操作', $op . '，影响 ' . $updated . ' 人');
    return array('ok' => true, 'updated' => $updated, 'op' => $op);
}
