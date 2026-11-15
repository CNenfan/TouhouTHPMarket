<?php
declare(strict_types=1);
if (!defined('THP_APP')) { http_response_code(403); exit('forbidden'); }

// ===================== 参数 =====================
function thp_market_cfg_float($key, $default)
{
    $raw = thp_setting($key, (string)$default);
    return is_numeric($raw) ? (float)$raw : (float)$default;
}

function thp_market_cfg_int($key, $default)
{
    $raw = thp_setting($key, (string)$default);
    return is_numeric($raw) ? (int)$raw : (int)$default;
}

function thp_market_rules()
{
    $loanBase = max(1, min(100, thp_market_cfg_int('loan_base_rate', 10)));
    $loanStep = max(1, min(100, thp_market_cfg_int('loan_rate_step', 10)));
    $loanMax = max($loanBase, min(300, thp_market_cfg_int('loan_max_rate', 100)));
    return array(
        'tick_seconds' => THP_TICK_SECONDS,
        'trade_throttle_count' => max(1, min(200, thp_market_cfg_int('trade_throttle_count', 30))),
        'trade_throttle_window_minutes' => max(1, min(180, thp_market_cfg_int('trade_throttle_window_minutes', 30))),
        'transfer_limit_30m' => max(0, min(1000000, thp_market_cfg_int('transfer_limit_30m', 1000))),
        'holding_lock_minutes' => max(1, min(1440, thp_market_cfg_int('holding_lock_minutes', 15))),
        'company_cost' => 200,
        'loan_ratios' => array(10, 30, 50, 100, 200),
        'loan_max_per_hour' => max(1, min(100, thp_market_cfg_int('loan_max_per_hour', 10))),
        'loan_max_ratio_percent' => 200,
        'loan_base_rate' => $loanBase,
        'loan_rate_step' => $loanStep,
        'loan_max_rate' => $loanMax,
        'loan_interest_minutes' => max(1, thp_market_cfg_int('loan_interest_minutes', 30)),
        'market_refresh_minutes' => max(1, min(60, thp_market_cfg_int('market_refresh_minutes', 6))),
        'market_forecast_ticks' => max(1, min(THP_TICKS_PER_DAY, thp_market_cfg_int('market_forecast_ticks', THP_TICKS_PER_HOUR))),
        'market_step_max_percent' => max(1.0, min(20.0, thp_market_cfg_float('market_step_max_percent', 20))),
        'market_min_ratio' => max(0.01, min(1.0, thp_market_cfg_float('market_min_ratio', 0.3333))),
        'market_max_ratio' => max(1.0, min(20.0, thp_market_cfg_float('market_max_ratio', 3))),
        'market_noise_weight' => max(0.0, min(5.0, thp_market_cfg_float('market_noise_weight', 0.55))),
        'market_mean_reversion' => max(0.0, min(1.0, thp_market_cfg_float('market_mean_reversion', 0.16))),
        'market_index_reversion' => max(0.0, min(1.0, thp_market_cfg_float('market_index_reversion', 0.035))),
        'market_hedge_weight' => max(0.0, min(1.0, thp_market_cfg_float('market_hedge_weight', 0.28))),
        'market_investment_weight' => max(0.0, min(2.0, thp_market_cfg_float('market_investment_weight', 0.22))),
        'market_investment_cap_percent' => max(0.0, min(5.0, thp_market_cfg_float('market_investment_cap_percent', 0.8))),
        'market_big_move_chance' => max(0.0, min(20.0, thp_market_cfg_float('market_big_move_chance', 1.5))),
        'market_big_move_min_percent' => max(1.0, min(20.0, thp_market_cfg_float('market_big_move_min_percent', 7))),
        'market_big_move_max_percent' => max(1.0, min(20.0, thp_market_cfg_float('market_big_move_max_percent', 17))),
        'market_long_drift_percent' => max(-0.5, min(0.5, thp_market_cfg_float('market_long_drift_percent', 0))),
        'news_interval_minutes' => max(5, min(180, thp_market_cfg_int('news_interval_minutes', 15))),
        'news_auto_min' => max(0, min(5, thp_market_cfg_int('news_auto_min', 1))),
        'news_auto_max' => max(0, min(5, thp_market_cfg_int('news_auto_max', 2))),
    );
}

function thp_price_norm($p)
{
    $v = (float)$p;
    if (!is_finite($v) || $v < 0.01) { $v = 0.01; }
    return round($v, 2);
}

function thp_fmt_price($p)
{
    $s = number_format((float)$p, 2, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');
    return ($s === '' || $s === '-') ? '0' : $s;
}

function thp_market_tick_datetime($date, $tick)
{
    $dayStart = strtotime($date . ' 00:00:00');
    return date('Y-m-d H:i:s', $dayStart + max(0, min(THP_TICKS_PER_DAY - 1, (int)$tick)) * THP_TICK_SECONDS);
}

function thp_market_tick_time_label($date, $tick)
{
    $dayStart = strtotime($date . ' 00:00:00');
    return date('H:i', $dayStart + max(0, min(THP_TICKS_PER_DAY - 1, (int)$tick)) * THP_TICK_SECONDS);
}

function thp_market_company_start_tick($company, $date)
{
    if ((int)$company['is_default'] === 1) { return 0; }
    $created = !empty($company['created_at']) ? strtotime((string)$company['created_at']) : time();
    $dayStart = strtotime($date . ' 00:00:00');
    if ($created <= $dayStart) { return 0; }
    return max(0, min(THP_TICKS_PER_DAY - 1, (int)floor(($created - $dayStart) / THP_TICK_SECONDS)));
}

function thp_market_lock($name = 'thp_market_engine', $timeout = 0)
{
    global $pdo;
    try {
        $st = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $st->execute(array((string)$name, (int)$timeout));
        return ((int)$st->fetchColumn() === 1);
    } catch (Throwable $e) {
        return false;
    }
}

function thp_market_unlock($name = 'thp_market_engine')
{
    global $pdo;
    try {
        $st = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $st->execute(array((string)$name));
    } catch (Throwable $ignored) {}
}

function thp_market_random_normal()
{
    $u1 = max(1e-9, mt_rand() / mt_getrandmax());
    $u2 = mt_rand() / mt_getrandmax();
    return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
}

// ===================== 公司 =====================
function thp_companies()
{
    global $pdo;
    $st = $pdo->query('SELECT id, name, owner_user_id, is_default, seed, base_price, created_at, renamed_at FROM companies ORDER BY id');
    $out = $st->fetchAll();
    foreach ($out as &$row) {
        $row['base_price'] = (float)$row['base_price'];
        if ($row['base_price'] < 0.01) { $row['base_price'] = 18.00; }
    }
    unset($row);
    return $out;
}

function thp_company_by_id($id)
{
    global $pdo;
    $st = $pdo->prepare('SELECT id, name, owner_user_id, is_default, seed, base_price, created_at, renamed_at FROM companies WHERE id = ?');
    $st->execute(array((int)$id));
    $row = $st->fetch();
    if ($row) {
        $row['base_price'] = (float)$row['base_price'];
        if ($row['base_price'] < 0.01) { $row['base_price'] = 18.00; }
    }
    return $row ? $row : null;
}

function thp_company_holders()
{
    global $pdo;
    $out = array();
    foreach ($pdo->query('SELECT company_id, COUNT(DISTINCT user_id) AS n FROM holdings WHERE shares > 0 GROUP BY company_id') as $r) {
        $out[(string)(int)$r['company_id']] = (int)$r['n'];
    }
    return $out;
}

// 实际购买人数：历史上至少成功买入过一次该公司的独立用户数。
// 持仓人数会随卖出减少，购买人数则保留，供页面与走势导出给 AI 参考。
function thp_company_buyer_counts()
{
    global $pdo;
    $out = array();
    foreach ($pdo->query("SELECT company_id, COUNT(DISTINCT user_id) AS n FROM stock_trades WHERE side = 'buy' GROUP BY company_id") as $r) {
        $out[(string)(int)$r['company_id']] = (int)$r['n'];
    }
    return $out;
}

function thp_company_holder_map()
{
    global $pdo;
    $out = array();
    $sql = 'SELECT h.company_id, h.user_id, h.shares, h.cost_total, u.username
            FROM holdings h JOIN users u ON u.id = h.user_id
            WHERE h.shares > 0 ORDER BY h.company_id, h.user_id';
    foreach ($pdo->query($sql) as $r) {
        $cid = (string)(int)$r['company_id'];
        if (!isset($out[$cid])) { $out[$cid] = array(); }
        $out[$cid][] = array(
            'user_id' => (int)$r['user_id'],
            'username' => (string)$r['username'],
            'shares' => (int)$r['shares'],
            'cost_total' => (int)$r['cost_total'],
        );
    }
    return $out;
}

// ===================== 行情引擎 =====================
function thp_market_load_day_points($date)
{
    global $pdo;
    $st = $pdo->prepare('SELECT tick, company_id, price, source, updated_at FROM market_points WHERE trade_date = ? ORDER BY tick ASC, company_id ASC');
    $st->execute(array((string)$date));
    $out = array();
    foreach ($st->fetchAll() as $r) {
        $tick = (int)$r['tick'];
        $cid = (string)(int)$r['company_id'];
        if (!isset($out[$tick])) { $out[$tick] = array(); }
        $out[$tick][$cid] = array(
            'price' => thp_price_norm($r['price']),
            'source' => (string)$r['source'],
            'updated_at' => (string)$r['updated_at'],
        );
    }
    return $out;
}

function thp_market_last_price_map($points, $tick, $companies)
{
    $caps = array();
    foreach ($companies as $c) { $caps[(string)$c['id']] = (float)$c['base_price']; }
    $maxTick = min((int)$tick, THP_TICKS_PER_DAY - 1);
    for ($t = 0; $t <= $maxTick; $t++) {
        if (!isset($points[$t])) { continue; }
        foreach ($points[$t] as $cid => $row) { $caps[(string)$cid] = (float)$row['price']; }
    }
    return $caps;
}

function thp_market_investment_pressure()
{
    global $pdo;
    $out = array();
    $st = $pdo->query("SELECT company_id, COALESCE(SUM(CASE WHEN side = 'buy' THEN amount ELSE -amount END), 0) AS net
        FROM stock_trades WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) GROUP BY company_id");
    foreach ($st->fetchAll() as $r) {
        $out[(string)(int)$r['company_id']] = (int)$r['net'];
    }
    return $out;
}

function thp_market_pending_news_effects($companies)
{
    global $pdo;
    $effects = array();
    $ids = array();
    $st = $pdo->query("SELECT id, positive_company_ids, negative_company_ids, impact_percent
        FROM news_items WHERE kind = 'market' AND effect_applied_at IS NULL AND published_at <= NOW()
        ORDER BY id ASC LIMIT 20");
    foreach ($st->fetchAll() as $row) {
        $impact = max(0.0, min(20.0, (float)$row['impact_percent']));
        foreach (array('positive_company_ids' => 1.0, 'negative_company_ids' => -1.0) as $field => $sign) {
            foreach (explode(',', (string)$row[$field]) as $id) {
                $id = (int)trim($id);
                if ($id <= 0) { continue; }
                if (!isset($effects[(string)$id])) { $effects[(string)$id] = 0.0; }
                $effects[(string)$id] += $impact * $sign;
            }
        }
        $ids[] = (int)$row['id'];
    }
    return array('effects' => $effects, 'ids' => $ids);
}

function thp_market_event_target_ids($raw, $validIds)
{
    $out = array();
    foreach (explode(',', (string)$raw) as $rawId) {
        $id = (int)trim($rawId);
        if ($id > 0 && isset($validIds[$id]) && !in_array($id, $out, true)) { $out[] = $id; }
    }
    return $out;
}

// 随机事件按 effective_at 落在具体 3 分钟价格点，并在其后 8 个点内平滑释放影响。
// 事件在影响窗口结束前保持 pending，避免后续自动续写把尚未走完的事件影响覆盖掉。
function thp_market_pending_company_event_effects($date, $fromTick, $toTick, $companies, $nowTick = null)
{
    global $pdo;
    $validIds = array();
    foreach ($companies as $company) { $validIds[(int)$company['id']] = true; }
    $fromTick = max(0, (int)$fromTick);
    $toTick = max($fromTick, (int)$toTick);
    $nowTick = $nowTick === null ? thp_tick_of_day() : (int)$nowTick;
    $dayStart = strtotime($date . ' 00:00:00');
    $toTime = date('Y-m-d H:i:s', $dayStart + ($toTick + 1) * THP_TICK_SECONDS);
    $st = $pdo->prepare("SELECT id, positive_company_ids, negative_company_ids, impact_percent, effective_at
        FROM company_events WHERE applied_at IS NULL AND effective_at < ?
        ORDER BY effective_at ASC, id ASC LIMIT 200");
    $st->execute(array($toTime));
    $byTick = array();
    $finishedIds = array();
    foreach ($st->fetchAll() as $row) {
        $effectiveTs = strtotime((string)$row['effective_at']);
        if ($effectiveTs === false) { continue; }
        $startTick = (int)floor(($effectiveTs - $dayStart) / THP_TICK_SECONDS);
        if ($startTick < 0) { $startTick = 0; }
        if ($startTick > $toTick) { continue; }
        $impact = max(0.0, min(20.0, (float)$row['impact_percent']));
        $positiveIds = thp_market_event_target_ids((string)$row['positive_company_ids'], $validIds);
        $negativeIds = thp_market_event_target_ids((string)$row['negative_company_ids'], $validIds);
        if ($impact > 0 && (count($positiveIds) > 0 || count($negativeIds) > 0)) {
            $windowEnd = min($toTick, $startTick + 7);
            for ($tick = max($startTick, $fromTick); $tick <= $windowEnd; $tick++) {
                if (!isset($byTick[$tick])) { $byTick[$tick] = array(); }
                foreach ($positiveIds as $id) {
                    $cid = (string)$id;
                    $byTick[$tick][$cid] = ($byTick[$tick][$cid] ?? 0.0) + $impact / 8.0;
                }
                foreach ($negativeIds as $id) {
                    $cid = (string)$id;
                    $byTick[$tick][$cid] = ($byTick[$tick][$cid] ?? 0.0) - $impact / 8.0;
                }
            }
        }
        if ($startTick + 8 <= $nowTick) { $finishedIds[] = (int)$row['id']; }
    }
    return array('by_tick' => $byTick, 'finished_ids' => array_values(array_unique($finishedIds)));
}

// 生成 [from, to] 的价格点。auto 会覆盖旧的预测，但永远不会覆盖 manual / ai。
function thp_market_generate_range($date, $from, $to, $mode = 'auto')
{
    global $pdo;
    $companies = thp_companies();
    if (count($companies) === 0) { return array('written' => 0, 'news_ids' => array(), 'event_ids' => array()); }
    $rules = thp_market_rules();
    $points = thp_market_load_day_points($date);
    $from = max(0, (int)$from);
    $to = max($from, min(THP_TICKS_PER_DAY - 1, (int)$to));
    $last = thp_market_last_price_map($points, max(0, $from - 1), $companies);
    $pressure = ($mode === 'auto') ? thp_market_investment_pressure() : array();
    $news = ($mode === 'auto') ? thp_market_pending_news_effects($companies) : array('effects' => array(), 'ids' => array());
    $companyEvents = ($mode === 'auto')
        ? thp_market_pending_company_event_effects($date, $from, $to, $companies, thp_tick_of_day())
        : array('by_tick' => array(), 'finished_ids' => array());
    $written = 0;
    $stmt = $pdo->prepare('INSERT INTO market_points (trade_date, tick, company_id, price, source, updated_at) VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          price = IF(source IN (\'manual\',\'ai\'), price, VALUES(price)),
          source = IF(source IN (\'manual\',\'ai\'), source, VALUES(source)),
          updated_at = IF(source IN (\'manual\',\'ai\'), updated_at, VALUES(updated_at))');
    $seedStmt = $pdo->prepare('INSERT IGNORE INTO market_points (trade_date, tick, company_id, price, source, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
    $source = ($mode === 'seed') ? 'seed' : 'auto';
    for ($tick = $from; $tick <= $to; $tick++) {
        $drifts = array();
        $priceNow = array();
        $stepTicks = $tick - $from;
        $logDevSum = 0.0;
        $devCount = 0;
        foreach ($companies as $c) {
            $cid = (string)$c['id'];
            $startTick = thp_market_company_start_tick($c, $date);
            if ($tick < $startTick) { continue; }
            $existing = isset($points[$tick][$cid]) ? $points[$tick][$cid] : null;
            if ($existing && ($existing['source'] === 'manual' || $existing['source'] === 'ai')) {
                $last[$cid] = (float)$existing['price'];
                continue;
            }
            if ($existing && $mode === 'seed') {
                $last[$cid] = (float)$existing['price'];
                continue;
            }
            $prev = isset($last[$cid]) ? max(0.01, (float)$last[$cid]) : (float)$c['base_price'];
            $base = max(0.01, (float)$c['base_price']);
            $logDev = log(max(0.0001, $prev / $base));
            $logDevSum += $logDev;
            $devCount++;
            $drift = -$rules['market_mean_reversion'] * $logDev;
            $drift += $rules['market_long_drift_percent'] / 100.0;
            $drift += thp_market_random_normal() * 0.0055 * $rules['market_noise_weight'];
            if (mt_rand(1, 10000) <= (int)round($rules['market_big_move_chance'] * 100)) {
                $lo = (int)round($rules['market_big_move_min_percent'] * 100);
                $hi = max($lo, (int)round($rules['market_big_move_max_percent'] * 100));
                $mag = mt_rand($lo, $hi) / 10000.0;
                $drift += (mt_rand(0, 1) === 1 ? $mag : -$mag);
            }
            if (isset($pressure[$cid]) && $pressure[$cid] !== 0) {
                $norm = max(-1.0, min(1.0, $pressure[$cid] / 12000.0));
                $drift += $norm * ($rules['market_investment_cap_percent'] / 100.0) * $rules['market_investment_weight'];
            }
            if (!empty($news['effects'][$cid]) && $stepTicks < 8) {
                $drift += ($news['effects'][$cid] / 100.0) / 8.0;
            }
            if (!empty($companyEvents['by_tick'][$tick][$cid])) {
                $drift += $companyEvents['by_tick'][$tick][$cid] / 100.0;
            }
            $drifts[$cid] = $drift;
            $priceNow[$cid] = $prev;
        }
        $avgDev = $devCount > 0 ? ($logDevSum / $devCount) : 0.0;
        $hedge = array_fill_keys(array_keys($drifts), 0.0);
        foreach ($drifts as $cid => $d) {
            if ($d <= 0) { continue; }
            $share = $drifts;
            unset($share[$cid]);
            if (count($share) === 0) { continue; }
            $each = $d * $rules['market_hedge_weight'] / count($share);
            foreach ($share as $other => $ignored) { $hedge[$other] -= $each; }
        }
        foreach ($companies as $c) {
            $cid = (string)$c['id'];
            if (!isset($drifts[$cid])) { continue; }
            $prev = (float)$priceNow[$cid];
            $base = max(0.01, (float)$c['base_price']);
            $maxStep = $rules['market_step_max_percent'] / 100.0;
            $drift = (float)$drifts[$cid] + (float)$hedge[$cid] - $rules['market_index_reversion'] * $avgDev;
            $drift = max(-$maxStep, min($maxStep, $drift));
            $price = $prev * (1.0 + $drift);
            $price = max($base * $rules['market_min_ratio'], min($base * $rules['market_max_ratio'], $price));
            $price = thp_price_norm($price);
            if ($mode === 'seed') {
                $seedStmt->execute(array($date, $tick, (int)$c['id'], $price, $source, thp_now()));
            } else {
                $stmt->execute(array($date, $tick, (int)$c['id'], $price, $source, thp_now()));
            }
            $written++;
            if (!isset($points[$tick])) { $points[$tick] = array(); }
            $points[$tick][$cid] = array('price' => $price, 'source' => $source, 'updated_at' => thp_now());
            $last[$cid] = $price;
        }
    }
    if ($written > 0 && count($news['ids']) > 0) {
        $marks = implode(',', array_fill(0, count($news['ids']), '?'));
        $up = $pdo->prepare('UPDATE news_items SET effect_applied_at = ? WHERE id IN (' . $marks . ') AND effect_applied_at IS NULL');
        $up->execute(array_merge(array(thp_now()), $news['ids']));
    }
    if (count($companyEvents['finished_ids']) > 0) {
        $marks = implode(',', array_fill(0, count($companyEvents['finished_ids']), '?'));
        $up = $pdo->prepare('UPDATE company_events SET applied_at = ? WHERE id IN (' . $marks . ') AND applied_at IS NULL');
        $up->execute(array_merge(array(thp_now()), $companyEvents['finished_ids']));
    }
    return array('written' => $written, 'news_ids' => $news['ids'], 'event_ids' => $companyEvents['finished_ids']);
}

function thp_market_ensure_engine($horizonTicks = 0, $force = false)
{
    $locked = thp_market_lock('thp_market_engine', 3);
    if (!$locked) { return array('locked' => false, 'generated' => 0); }
    try {
        $date = thp_today();
        $tick = thp_tick_of_day();
        $companies = thp_companies();
        $points = thp_market_load_day_points($date);
        $missingFrom = array();
        foreach ($companies as $c) {
            $cid = (string)$c['id'];
            $startTick = thp_market_company_start_tick($c, $date);
            $lastTick = -1;
            foreach ($points as $pt => $rows) {
                if (isset($rows[$cid]) && (int)$pt > $lastTick) { $lastTick = (int)$pt; }
            }
            if ($lastTick < $startTick) { $missingFrom[$cid] = $startTick; }
            elseif ($lastTick < $tick) { $missingFrom[$cid] = $lastTick + 1; }
        }
        if (count($missingFrom) > 0) {
            $lowest = min(array_values($missingFrom));
            thp_market_generate_range($date, $lowest, $tick, 'seed');
            $points = thp_market_load_day_points($date);
        }
        $rules = thp_market_rules();
        $horizon = max($rules['market_forecast_ticks'], (int)$horizonTicks);
        $horizon = max(1, min(THP_TICKS_PER_DAY - 1 - $tick, $horizon));
        $lastGen = (string)thp_setting('market_last_generated_at', '');
        $engineDay = (string)thp_setting('market_engine_day', '');
        $lastTs = $lastGen !== '' ? strtotime($lastGen) : 0;
        $due = $force || $engineDay !== $date || $lastTs <= 0 || (time() - $lastTs) >= $rules['market_refresh_minutes'] * 60;
        $generated = 0;
        if ($due) {
            $result = thp_market_generate_range($date, min(THP_TICKS_PER_DAY - 1, $tick + 1), min(THP_TICKS_PER_DAY - 1, $tick + $horizon), 'auto');
            $generated = (int)$result['written'];
            thp_set_setting('market_last_generated_at', thp_now());
            thp_set_setting('market_engine_day', $date);
        }
        return array('locked' => true, 'generated' => $generated, 'tick' => $tick, 'horizon' => $horizon, 'due' => $due);
    } finally {
        thp_market_unlock('thp_market_engine');
    }
}

function thp_market_build_full_series($points, $companies, $toTick, $date = null)
{
    $date = $date ?: thp_today();
    $toTick = max(0, min(THP_TICKS_PER_DAY - 1, (int)$toTick));
    $series = array();
    $starts = array();
    $bases = array();
    foreach ($companies as $c) {
        $cid = (string)$c['id'];
        $series[$cid] = array_fill(0, $toTick + 1, null);
        $starts[$cid] = thp_market_company_start_tick($c, $date);
        $bases[$cid] = max(0.01, (float)$c['base_price']);
    }
    $last = array();
    for ($t = 0; $t <= $toTick; $t++) {
        foreach ($companies as $c) {
            $cid = (string)$c['id'];
            if (isset($points[$t][$cid])) { $last[$cid] = (float)$points[$t][$cid]['price']; }
            if ($t < $starts[$cid]) { continue; }
            if (!isset($last[$cid])) { $last[$cid] = $bases[$cid]; }
            $series[$cid][$t] = thp_price_norm($last[$cid]);
        }
    }
    return array('series' => $series, 'starts' => $starts, 'bases' => $bases);
}

function thp_market_index_value($full, $companies, $tick)
{
    $sum = 0.0;
    $cnt = 0;
    foreach ($companies as $c) {
        $cid = (string)$c['id'];
        if ((int)$tick < (int)$full['starts'][$cid]) { continue; }
        $p = $full['series'][$cid][(int)$tick] ?? null;
        $base = (float)$full['bases'][$cid];
        if ($p === null || $base <= 0) { continue; }
        $sum += log(max(0.000001, (float)$p / $base));
        $cnt++;
    }
    return $cnt > 0 ? (int)round(exp($sum / $cnt) * 1000) : 1000;
}

function thp_market_index_series_from_full($full, $companies, $from, $to)
{
    $out = array();
    for ($t = max(0, (int)$from); $t <= (int)$to; $t++) {
        $out[] = thp_market_index_value($full, $companies, $t);
    }
    return $out;
}

function thp_market_cache_signature()
{
    global $pdo;
    $c = $pdo->query("SELECT COUNT(*) AS n, COALESCE(MAX(id), 0) AS max_id,
        COALESCE(SUM(CRC32(CONCAT(id, '|', name, '|', COALESCE(owner_user_id, 0), '|', is_default, '|', base_price))), 0) AS hash
        FROM companies")->fetch();
    $h = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(shares), 0) AS shares, COALESCE(SUM(cost_total), 0) AS cost
        FROM holdings WHERE shares > 0")->fetch();
    $t = $pdo->query("SELECT COUNT(*) AS n, COALESCE(MAX(id), 0) AS max_id FROM stock_trades")->fetch();
    $u = $pdo->query("SELECT COUNT(*) AS n,
        COALESCE(SUM(CRC32(CONCAT(id, '|', username, '|', role, '|', banned, '|', is_test))), 0) AS hash
        FROM users")->fetch();
    $n = $pdo->query("SELECT COUNT(*) AS n, COALESCE(MAX(id), 0) AS max_id FROM news_items")->fetch();
    return sha1(json_encode(array($c, $h, $t, $u, $n), JSON_UNESCAPED_UNICODE));
}

function thp_market_price_map($full, $companies, $tick)
{
    $out = array();
    foreach ($companies as $c) {
        $cid = (string)$c['id'];
        $p = $full['series'][$cid][(int)$tick] ?? null;
        if ($p !== null) { $out[$cid] = (float)$p; }
    }
    return $out;
}

// ===================== 盈亏与排行榜 =====================
function thp_stock_pnl_map_from_prices($prices)
{
    global $pdo;
    $flow = array();
    foreach ($pdo->query("SELECT tx.user_id, SUM(tx.delta) AS s
        FROM token_tx tx JOIN users u ON u.id = tx.user_id
        WHERE tx.type IN ('buy','sell') AND u.role = 'user' AND u.banned = 0 AND u.is_test = 0
        GROUP BY tx.user_id") as $r) {
        $flow[(int)$r['user_id']] = (int)$r['s'];
    }
    $value = array();
    $cost = array();
    foreach ($pdo->query("SELECT h.user_id, h.company_id, h.shares, h.cost_total
        FROM holdings h JOIN users u ON u.id = h.user_id
        WHERE h.shares > 0 AND u.role = 'user' AND u.banned = 0 AND u.is_test = 0") as $h) {
        $uid = (int)$h['user_id'];
        $cid = (string)(int)$h['company_id'];
        if (!isset($prices[$cid])) { continue; }
        $value[$uid] = (isset($value[$uid]) ? $value[$uid] : 0.0) + (float)$prices[$cid] * (int)$h['shares'];
        $cost[$uid] = (isset($cost[$uid]) ? $cost[$uid] : 0) + (int)$h['cost_total'];
    }
    $out = array();
    foreach (array_unique(array_merge(array_keys($flow), array_keys($value))) as $uid) {
        $f = isset($flow[$uid]) ? $flow[$uid] : 0;
        $v = isset($value[$uid]) ? (float)$value[$uid] : 0.0;
        $c = isset($cost[$uid]) ? (float)$cost[$uid] : 0.0;
        $realized = (int)round($f + $c);
        $unrealized = (int)round($v - $c);
        $out[(int)$uid] = array('realized' => $realized, 'unrealized' => $unrealized, 'total' => $realized + $unrealized);
    }
    return $out;
}

function thp_stock_pnl_rank_from_prices($prices, $limit = 5)
{
    global $pdo;
    $limit = max(1, min(20, (int)$limit));
    $pnl = thp_stock_pnl_map_from_prices($prices);
    $names = array();
    if (count($pnl) > 0) {
        $ids = implode(',', array_map('intval', array_keys($pnl)));
        foreach ($pdo->query("SELECT id, username FROM users
            WHERE id IN (" . $ids . ") AND role = 'user' AND banned = 0 AND is_test = 0") as $r) {
            $names[(int)$r['id']] = (string)$r['username'];
        }
    }
    $earn = array();
    $lose = array();
    foreach ($pnl as $uid => $v) {
        if (!isset($names[(int)$uid]) || (int)$v['total'] === 0) { continue; }
        $item = array(
            'id' => (int)$uid,
            'username' => $names[(int)$uid],
            'realized' => (int)$v['realized'],
            'unrealized' => (int)$v['unrealized'],
            'total' => (int)$v['total'],
        );
        if ((int)$v['total'] > 0) { $earn[] = $item; } else { $lose[] = $item; }
    }
    usort($earn, function ($a, $b) {
        if ($a['total'] === $b['total']) { return ($b['realized'] === $a['realized']) ? ($a['id'] - $b['id']) : ($b['realized'] - $a['realized']); }
        return $b['total'] - $a['total'];
    });
    usort($lose, function ($a, $b) {
        if ($a['total'] === $b['total']) { return ($b['realized'] === $a['realized']) ? ($a['id'] - $b['id']) : ($a['realized'] - $b['realized']); }
        return $a['total'] - $b['total'];
    });
    $earn = array_slice($earn, 0, $limit);
    $lose = array_slice($lose, 0, $limit);
    foreach ($earn as $i => &$item) { $item['rank'] = $i + 1; }
    unset($item);
    foreach ($lose as $i => &$item) { $item['rank'] = $i + 1; }
    unset($item);
    return array('earn' => $earn, 'lose' => $lose, 'generated_at' => date('c'), 'tie_rule' => 'total_desc_realized_desc_user_id_asc');
}

function thp_stock_pnl_of_from_prices($prices, $userId, $holdings)
{
    global $pdo;
    $st = $pdo->prepare("SELECT COALESCE(SUM(delta), 0) FROM token_tx WHERE user_id = ? AND type IN ('buy','sell')");
    $st->execute(array((int)$userId));
    $flow = (int)$st->fetchColumn();
    $value = 0.0;
    $cost = 0;
    foreach ($holdings as $cid => $h) {
        $key = (string)(int)$cid;
        if (isset($prices[$key])) { $value += (float)$prices[$key] * (int)$h['shares']; }
        $cost += (int)$h['cost_total'];
    }
    $realized = (int)round($flow + $cost);
    $unrealized = (int)round($value - $cost);
    return array('realized' => $realized, 'unrealized' => $unrealized, 'total' => $realized + $unrealized);
}

function thp_asset_rank_compute($prices)
{
    global $pdo;
    $debt = array();
    foreach ($pdo->query("SELECT user_id, SUM(principal + interest_charged) AS d
        FROM bank_loans WHERE status = 'active' GROUP BY user_id") as $r) {
        $debt[(int)$r['user_id']] = (int)$r['d'];
    }
    $stockValue = array();
    foreach ($pdo->query("SELECT h.user_id, h.company_id, h.shares FROM holdings h WHERE h.shares > 0") as $h) {
        $cid = (string)(int)$h['company_id'];
        if (!isset($prices[$cid])) { continue; }
        $uid = (int)$h['user_id'];
        $stockValue[$uid] = (isset($stockValue[$uid]) ? $stockValue[$uid] : 0.0) + (float)$prices[$cid] * (int)$h['shares'];
    }
    $rows = array();
    foreach ($pdo->query("SELECT id, username, balance, debt FROM users
        WHERE role = 'user' AND banned = 0 AND is_test = 0 ORDER BY id") as $u) {
        $uid = (int)$u['id'];
        $cash = (int)$u['balance'];
        $stocks = isset($stockValue[$uid]) ? (int)round($stockValue[$uid]) : 0;
        $loanDebt = isset($debt[$uid]) ? $debt[$uid] : (int)$u['debt'];
        $rows[] = array(
            'id' => $uid,
            'username' => (string)$u['username'],
            'balance' => $cash,
            'stocks' => $stocks,
            'debt' => max((int)$u['debt'], $loanDebt),
            'net_assets' => $cash + $stocks - max((int)$u['debt'], $loanDebt),
        );
    }
    $top = $rows;
    usort($top, function ($a, $b) { return $a['net_assets'] === $b['net_assets'] ? ($a['id'] - $b['id']) : ($b['net_assets'] - $a['net_assets']); });
    $top = array_slice($top, 0, 3);
    foreach ($top as $i => &$r) { $r['rank'] = $i + 1; }
    unset($r);
    $negative = array_values(array_filter($rows, function ($r) { return (int)$r['net_assets'] < 0; }));
    usort($negative, function ($a, $b) { return $a['net_assets'] === $b['net_assets'] ? ($a['id'] - $b['id']) : ($a['net_assets'] - $b['net_assets']); });
    $bottom = array_slice($negative, 0, 3);
    foreach ($bottom as $i => &$r) { $r['rank'] = $i + 1; }
    unset($r);
    return array('top' => $top, 'bottom' => $bottom, 'generated_at' => thp_now());
}

function thp_asset_rank_snapshot_if_due($prices)
{
    global $pdo;
    $next = (string)thp_setting('asset_rank_next_at', '');
    $due = $next === '' || strtotime($next) <= time();
    if ($due && thp_market_lock('thp_asset_rank', 1)) {
        try {
            $next = (string)thp_setting('asset_rank_next_at', '');
            if ($next === '' || strtotime($next) <= time()) {
                $rank = thp_asset_rank_compute($prices);
                $roundAt = thp_now();
                $ins = $pdo->prepare('INSERT INTO asset_rank_snapshots (round_at, bucket, rank_no, user_id, username, net_assets) VALUES (?, ?, ?, ?, ?, ?)');
                foreach ($rank['top'] as $r) { $ins->execute(array($roundAt, 'top', (int)$r['rank'], (int)$r['id'], (string)$r['username'], (int)$r['net_assets'])); }
                foreach ($rank['bottom'] as $r) { $ins->execute(array($roundAt, 'bottom', (int)$r['rank'], (int)$r['id'], (string)$r['username'], (int)$r['net_assets'])); }
                thp_set_setting('asset_rank_next_at', date('Y-m-d H:i:s', time() + 900));
            }
        } finally {
            thp_market_unlock('thp_asset_rank');
        }
    }
    $latest = $pdo->query('SELECT MAX(round_at) FROM asset_rank_snapshots')->fetchColumn();
    if (!$latest) { return thp_asset_rank_compute($prices); }
    $st = $pdo->prepare("SELECT bucket, rank_no, user_id, username, net_assets FROM asset_rank_snapshots WHERE round_at = ? ORDER BY bucket, rank_no");
    $st->execute(array((string)$latest));
    $out = array('top' => array(), 'bottom' => array(), 'generated_at' => (string)$latest);
    foreach ($st->fetchAll() as $r) {
        $out[(string)$r['bucket']][] = array(
            'rank' => (int)$r['rank_no'], 'id' => (int)$r['user_id'],
            'username' => (string)$r['username'], 'net_assets' => (int)$r['net_assets'],
        );
    }
    return $out;
}

// ===================== 页面数据 =====================
function thp_market_state_payload($user, $rangeMinutes)
{
    global $pdo;
    thp_market_ensure_engine();
    $allowedRanges = array(10, 30, 60, 120, 180, 300, 720);
    $rangeMinutes = in_array((int)$rangeMinutes, $allowedRanges, true) ? (int)$rangeMinutes : 60;
    $date = thp_today();
    $tick = thp_tick_of_day();
    $rangeTicks = max(1, (int)round($rangeMinutes * 60 / THP_TICK_SECONDS));
    $from = max(0, $tick - $rangeTicks);
    $cacheTtl = max(1, min(60, thp_market_cfg_int('market_cache_seconds', 60)));
    $cacheKey = 'market:state:v6:' . $date . ':' . $tick . ':' . $rangeMinutes . ':' . thp_market_cache_signature();
    $shared = thp_cache_get($cacheKey, $cacheTtl);
    if (!is_array($shared) || !isset($shared['companies'], $shared['index'], $shared['rank'])) {
        $companies = thp_companies();
        $points = thp_market_load_day_points($date);
        $full = thp_market_build_full_series($points, $companies, $tick, $date);
        $indexSeries = thp_market_index_series_from_full($full, $companies, $from, $tick);
        $prices = thp_market_price_map($full, $companies, $tick);
        $prevPrices = thp_market_price_map($full, $companies, max(0, $tick - 1));
        $holders = thp_company_holders();
        $buyers = thp_company_buyer_counts();
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
            $open = (float)$full['bases'][$cid];
            $price = $prices[$cid] ?? null;
            $startTick = (int)$full['starts'][$cid];
            if ($price === null && $startTick <= $tick) { $price = $open; }
            $prev = $prevPrices[$cid] ?? $price;
            $history = array_slice($full['series'][$cid], $from, $tick - $from + 1);
            $trend[$cid] = $history;
            $outCompanies[] = array(
                'id' => (int)$c['id'],
                'name' => (string)$c['name'],
                'holders' => isset($holders[$cid]) ? (int)$holders[$cid] : 0,
                'buyers' => isset($buyers[$cid]) ? (int)$buyers[$cid] : 0,
                'is_default' => ((int)$c['is_default'] === 1),
                'owner_user_id' => (int)$c['owner_user_id'],
                'owner_name' => isset($owners[(int)$c['owner_user_id']]) ? $owners[(int)$c['owner_user_id']] : '',
                'base_price' => thp_price_norm($open),
                'price' => $price !== null ? thp_price_norm($price) : null,
                'prev' => $prev !== null ? thp_price_norm($prev) : null,
                'open' => thp_price_norm($open),
                'change_percent' => ($price !== null && $open > 0) ? round((((float)$price / $open) - 1) * 100, 1) : 0,
                'history' => $history,
            );
        }
        $indexValue = count($indexSeries) > 0 ? (int)$indexSeries[count($indexSeries) - 1] : 1000;
        $indexPrev = count($indexSeries) > 1 ? (int)$indexSeries[count($indexSeries) - 2] : $indexValue;
        $shared = array(
            'date' => $date,
            'companies' => $outCompanies,
            'trend' => $trend,
            'index' => array(
                'value' => $indexValue,
                'prev' => $indexPrev,
                'open' => 1000,
                'change_percent' => round(($indexValue - 1000) / 10, 1),
                'series' => $indexSeries,
            ),
            'rank' => thp_stock_pnl_rank_from_prices($prices, 5),
            'asset_rank' => thp_asset_rank_snapshot_if_due($prices),
            'prices' => $prices,
            'recent_news' => iterator_to_array($pdo->query("SELECT id, title, published_at FROM news_items WHERE kind = 'market' AND published_at <= NOW() ORDER BY pinned DESC, id DESC LIMIT 30")),
        );
        thp_cache_set($cacheKey, $shared, $cacheTtl);
    }
    foreach ($shared['companies'] as &$sc) {
        $sc['mine'] = ((int)$sc['owner_user_id'] > 0 && (int)$sc['owner_user_id'] === (int)$user['id']);
        unset($sc['owner_user_id']);
    }
    unset($sc);
    $holdSt = $pdo->prepare('SELECT company_id, shares, cost_total FROM holdings WHERE user_id = ? AND shares > 0');
    $holdSt->execute(array((int)$user['id']));
    $holdings = array();
    foreach ($holdSt->fetchAll() as $h) {
        $holdings[(string)$h['company_id']] = array('shares' => (int)$h['shares'], 'cost_total' => (int)$h['cost_total']);
    }
    $pnl = thp_stock_pnl_of_from_prices($shared['prices'], (int)$user['id'], $holdings);
    $rules = thp_market_rules();
    $debt = thp_bank_sync_user_debt((int)$user['id']);
    return array(
        'server_ts' => time(),
        'date' => $date,
        'tick' => $tick,
        'tick_from' => $from,
        'tick_seconds' => THP_TICK_SECONDS,
        'view_range_minutes' => $rangeMinutes,
        'companies' => $shared['companies'],
        'trend' => $shared['trend'],
        'index' => $shared['index'],
        'me' => array(
            'balance' => (int)$user['balance'],
            'debt' => $debt,
            'holdings' => $holdings,
            'loan' => thp_bank_loan_snapshot((int)$user['id'], (int)$user['balance'], $debt, $rules),
        ),
        'pnl' => $pnl,
        'rank' => $shared['rank'],
        'asset_rank' => $shared['asset_rank'],
        'recent_news' => $shared['recent_news'],
        'rules' => $rules,
        'next_engine_at' => date('Y-m-d H:i:s', strtotime((string)thp_setting('market_last_generated_at', thp_now())) + $rules['market_refresh_minutes'] * 60),
    );
}

function thp_market_company_detail_payload($user, $companyId, $rangeMinutes)
{
    global $pdo;
    $company = thp_company_by_id((int)$companyId);
    if (!$company) { throw new ThpError('公司不存在'); }
    thp_market_ensure_engine();
    $allowedRanges = array(10, 30, 60, 120, 180, 300, 720);
    $rangeMinutes = in_array((int)$rangeMinutes, $allowedRanges, true) ? (int)$rangeMinutes : 60;
    $date = thp_today();
    $tick = thp_tick_of_day();
    $rangeTicks = max(1, (int)round($rangeMinutes * 60 / THP_TICK_SECONDS));
    $from = max(0, $tick - $rangeTicks);
    $companies = thp_companies();
    $points = thp_market_load_day_points($date);
    $full = thp_market_build_full_series($points, $companies, $tick, $date);
    $cid = (string)$company['id'];
    $path = $full['series'][$cid];
    $seen = array_slice($path, max(0, (int)$full['starts'][$cid]), $tick - max(0, (int)$full['starts'][$cid]) + 1);
    $seen = array_values(array_filter($seen, function ($v) { return $v !== null; }));
    if (count($seen) === 0) { $seen = array((float)$company['base_price']); }
    $price = thp_price_norm($path[$tick] ?? end($seen));
    $hs = $pdo->prepare('SELECT shares, cost_total FROM holdings WHERE user_id = ? AND company_id = ?');
    $hs->execute(array((int)$user['id'], (int)$company['id']));
    $holding = $hs->fetch() ?: array('shares' => 0, 'cost_total' => 0);
    $lots = array();
    $ls = $pdo->prepare('SELECT id, remaining_shares, remaining_cost, bought_at, unlock_at FROM holding_lots WHERE user_id = ? AND company_id = ? AND remaining_shares > 0 ORDER BY id');
    $ls->execute(array((int)$user['id'], (int)$company['id']));
    foreach ($ls->fetchAll() as $r) {
        $lots[] = array('id' => (int)$r['id'], 'shares' => (int)$r['remaining_shares'], 'cost' => (int)$r['remaining_cost'], 'bought_at' => (string)$r['bought_at'], 'unlock_at' => (string)$r['unlock_at']);
    }
    $holdings = array($cid => array('shares' => (int)$holding['shares'], 'cost_total' => (int)$holding['cost_total']));
    $pnl = thp_stock_pnl_of_from_prices(array($cid => $price), (int)$user['id'], $holdings);
    $trades = array();
    $ts = $pdo->prepare('SELECT id, side, qty, price, amount, created_at FROM stock_trades WHERE user_id = ? AND company_id = ? ORDER BY id DESC LIMIT 20');
    $ts->execute(array((int)$user['id'], (int)$company['id']));
    foreach ($ts->fetchAll() as $r) {
        $trades[] = array('id' => (int)$r['id'], 'side' => (string)$r['side'], 'qty' => (int)$r['qty'], 'price' => (float)$r['price'], 'amount' => (int)$r['amount'], 'created_at' => (string)$r['created_at']);
    }
    $holders = thp_company_holders();
    $buyers = thp_company_buyer_counts();
    $capSt = $pdo->prepare('SELECT COALESCE(SUM(shares), 0) FROM holdings WHERE company_id = ? AND shares > 0');
    $capSt->execute(array((int)$company['id']));
    $totalShares = (int)$capSt->fetchColumn();
    return array(
        'id' => (int)$company['id'],
        'name' => (string)$company['name'],
        'is_default' => ((int)$company['is_default'] === 1),
        'holders' => isset($holders[$cid]) ? (int)$holders[$cid] : 0,
        'buyers' => isset($buyers[$cid]) ? (int)$buyers[$cid] : 0,
        'price' => $price,
        'prev' => thp_price_norm($path[max(0, $tick - 1)] ?? $price),
        'open' => thp_price_norm((float)$company['base_price']),
        'high' => thp_price_norm(max($seen)),
        'low' => thp_price_norm(min($seen)),
        'total_shares' => $totalShares,
        'market_cap' => (int)round($price * $totalShares),
        'tick' => $tick,
        'tick_from' => $from,
        'history' => array_slice($path, $from, $tick - $from + 1),
        'my_holding' => array(
            'shares' => (int)$holding['shares'], 'cost_total' => (int)$holding['cost_total'],
            'market_value' => (int)round($price * (int)$holding['shares']),
            'realized' => (int)$pnl['realized'], 'unrealized' => (int)$pnl['unrealized'], 'total' => (int)$pnl['total'],
            'lots' => $lots,
        ),
        'trades' => $trades,
        'view_range_minutes' => $rangeMinutes,
        'rules' => thp_market_rules(),
    );
}

function thp_market_quote($companyId, $tick = null)
{
    global $pdo;
    $tick = ($tick === null) ? thp_tick_of_day() : (int)$tick;
    $date = thp_today();
    $st = $pdo->prepare('SELECT price FROM market_points WHERE trade_date = ? AND company_id = ? AND tick <= ? ORDER BY tick DESC LIMIT 1');
    $st->execute(array($date, (int)$companyId, max(0, min(THP_TICKS_PER_DAY - 1, $tick))));
    $price = $st->fetchColumn();
    return $price === false ? null : thp_price_norm($price);
}

function thp_market_require_trading_enabled()
{
    if ((string)thp_setting('trade_enabled', '1') !== '1') {
        throw new ThpError('股市与 FDT 交易暂时关闭，请稍后再试');
    }
}

function thp_market_trade_throttle($userId)
{
    $rules = thp_market_rules();
    thp_require_throttle(
        'trade:' . (int)$userId,
        $rules['trade_throttle_count'],
        $rules['trade_throttle_window_minutes'] * 60,
        '交易太频繁啦：每 ' . $rules['trade_throttle_window_minutes'] . ' 分钟最多交易 ' . $rules['trade_throttle_count'] . ' 次（买 + 卖合计），歇一会儿再回来～'
    );
}

function thp_market_buy($companyId, $qty)
{
    global $pdo;
    $u = thp_require_login();
    thp_market_require_trading_enabled();
    $qty = (int)$qty;
    if ($qty < 1 || $qty > 1000000) { throw new ThpError('购买数量不合法'); }
    $company = thp_company_by_id($companyId);
    if (!$company) { throw new ThpError('公司不存在'); }
    thp_market_ensure_engine();
    $price = thp_market_quote((int)$company['id']);
    if ($price === null) { throw new ThpError('该公司暂无行情数据'); }
    $cost = (int)round($price * $qty);
    if ($cost < 1) { throw new ThpError('交易金额至少 1 FDT 哦，多买几股吧'); }
    thp_market_trade_throttle((int)$u['id']);
    $rules = thp_market_rules();
    $unlockAt = date('Y-m-d H:i:s', time() + $rules['holding_lock_minutes'] * 60);
    $pdo->beginTransaction();
    try {
        $newBal = thp_balance_apply($pdo, (int)$u['id'], -$cost, 'buy', 'c' . (int)$company['id'], $company['name'] . ' 买入 ' . $qty . ' 股 @' . thp_fmt_price($price));
        $st = $pdo->prepare('INSERT INTO holdings (user_id, company_id, shares, cost_total) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE shares = shares + VALUES(shares), cost_total = cost_total + VALUES(cost_total)');
        $st->execute(array((int)$u['id'], (int)$company['id'], $qty, $cost));
        $lot = $pdo->prepare('INSERT INTO holding_lots (user_id, company_id, shares, remaining_shares, remaining_cost, bought_at, unlock_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $lot->execute(array((int)$u['id'], (int)$company['id'], $qty, $qty, $cost, thp_now(), $unlockAt));
        $trade = $pdo->prepare('INSERT INTO stock_trades (user_id, company_id, side, qty, price, amount, tick, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $trade->execute(array((int)$u['id'], (int)$company['id'], 'buy', $qty, $price, $cost, thp_tick_of_day(), thp_idempotency_current_key(), thp_now()));
        $tradeId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($e instanceof ThpError) { throw $e; }
        error_log('[THP buy] ' . $e->getMessage());
        throw new ThpError('交易失败，请稍后再试', 500);
    }
    return array('balance' => $newBal, 'price' => $price, 'qty' => $qty, 'cost' => $cost, 'trade_id' => $tradeId, 'company_name' => (string)$company['name'], 'unlock_at' => $unlockAt);
}

function thp_market_sell($companyId, $qty)
{
    global $pdo;
    $u = thp_require_login();
    thp_market_require_trading_enabled();
    $qty = (int)$qty;
    if ($qty < 1 || $qty > 1000000) { throw new ThpError('卖出数量不合法'); }
    $company = thp_company_by_id($companyId);
    if (!$company) { throw new ThpError('公司不存在'); }
    thp_market_ensure_engine();
    $price = thp_market_quote((int)$company['id']);
    if ($price === null) { throw new ThpError('该公司暂无行情数据'); }
    thp_market_trade_throttle((int)$u['id']);
    $proceeds = max(1, (int)floor($price * $qty));
    $pdo->beginTransaction();
    try {
        $hs = $pdo->prepare('SELECT shares, cost_total FROM holdings WHERE user_id = ? AND company_id = ? FOR UPDATE');
        $hs->execute(array((int)$u['id'], (int)$company['id']));
        $holding = $hs->fetch();
        if (!$holding || (int)$holding['shares'] < $qty) { throw new ThpError('没有这么多股票可以卖'); }
        $ls = $pdo->prepare('SELECT id, remaining_shares, remaining_cost, unlock_at FROM holding_lots WHERE user_id = ? AND company_id = ? AND remaining_shares > 0 ORDER BY id FOR UPDATE');
        $ls->execute(array((int)$u['id'], (int)$company['id']));
        $lots = $ls->fetchAll();
        $unlocked = 0;
        $nextUnlock = '';
        foreach ($lots as $lot) {
            if (strtotime((string)$lot['unlock_at']) <= time()) { $unlocked += (int)$lot['remaining_shares']; }
            elseif ($nextUnlock === '' || strtotime((string)$lot['unlock_at']) < strtotime($nextUnlock)) { $nextUnlock = (string)$lot['unlock_at']; }
        }
        if ($unlocked < $qty) {
            throw new ThpError('当前可卖 ' . $unlocked . ' 股，持仓至少需锁定 ' . thp_market_rules()['holding_lock_minutes'] . ' 分钟' . ($nextUnlock ? '（下一批 ' . date('H:i', strtotime($nextUnlock)) . ' 解锁）' : ''));
        }
        $left = $qty;
        $costBasis = 0;
        $upLot = $pdo->prepare('UPDATE holding_lots SET remaining_shares = ?, remaining_cost = ? WHERE id = ?');
        $delLot = $pdo->prepare('DELETE FROM holding_lots WHERE id = ?');
        foreach ($lots as $lot) {
            if ($left <= 0) { break; }
            if (strtotime((string)$lot['unlock_at']) > time()) { continue; }
            $take = min($left, (int)$lot['remaining_shares']);
            $sharesBefore = (int)$lot['remaining_shares'];
            $costBefore = (int)$lot['remaining_cost'];
            $takeCost = ($take >= $sharesBefore) ? $costBefore : (int)round($costBefore * $take / $sharesBefore);
            $costBasis += $takeCost;
            $sharesAfter = $sharesBefore - $take;
            if ($sharesAfter <= 0) { $delLot->execute(array((int)$lot['id'])); }
            else { $upLot->execute(array($sharesAfter, $costBefore - $takeCost, (int)$lot['id'])); }
            $left -= $take;
        }
        if ($left > 0) { throw new ThpError('可卖持仓发生变化，请刷新后重试'); }
        $newShares = (int)$holding['shares'] - $qty;
        if ($newShares <= 0) {
            $del = $pdo->prepare('DELETE FROM holdings WHERE user_id = ? AND company_id = ?');
            $del->execute(array((int)$u['id'], (int)$company['id']));
        } else {
            $up = $pdo->prepare('UPDATE holdings SET shares = ?, cost_total = ? WHERE user_id = ? AND company_id = ?');
            $up->execute(array($newShares, max(0, (int)$holding['cost_total'] - $costBasis), (int)$u['id'], (int)$company['id']));
        }
        $newBal = thp_balance_apply($pdo, (int)$u['id'], $proceeds, 'sell', 'c' . (int)$company['id'], $company['name'] . ' 卖出 ' . $qty . ' 股 @' . thp_fmt_price($price));
        $trade = $pdo->prepare('INSERT INTO stock_trades (user_id, company_id, side, qty, price, amount, tick, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $trade->execute(array((int)$u['id'], (int)$company['id'], 'sell', $qty, $price, $proceeds, thp_tick_of_day(), thp_idempotency_current_key(), thp_now()));
        $tradeId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($e instanceof ThpError) { throw $e; }
        error_log('[THP sell] ' . $e->getMessage());
        throw new ThpError('卖出失败，请稍后再试', 500);
    }
    return array('balance' => $newBal, 'price' => $price, 'qty' => $qty, 'proceeds' => $proceeds, 'trade_id' => $tradeId, 'company_name' => (string)$company['name']);
}

// ===================== 银行 =====================
function thp_bank_sync_user_debt($userId)
{
    global $pdo;
    $rules = thp_market_rules();
    $ownTxn = !$pdo->inTransaction();
    if ($ownTxn) { $pdo->beginTransaction(); }
    try {
        $st = $pdo->prepare("SELECT * FROM bank_loans WHERE user_id = ? AND status = 'active' ORDER BY id FOR UPDATE");
        $st->execute(array((int)$userId));
        $loans = $st->fetchAll();
        $total = 0;
        $up = $pdo->prepare('UPDATE bank_loans SET interest_steps = ?, interest_charged = ?, last_synced_at = ? WHERE id = ?');
        foreach ($loans as $loan) {
            $last = strtotime((string)$loan['last_synced_at']);
            if ($last <= 0) { $last = strtotime((string)$loan['opened_at']); }
            $steps = (int)$loan['interest_steps'];
            $interest = (int)$loan['interest_charged'];
            $interval = max(1, (int)$loan['last_synced_at'] ? $rules['loan_interest_minutes'] * 60 : $rules['loan_interest_minutes'] * 60);
            $due = max(0, (int)floor((time() - $last) / $interval));
            for ($i = 0; $i < $due; $i++) {
                // 首期按贷款创建时的 initial_rate（默认 10%）计息，之后每期叠加 rate_step。
                $rate = min((int)$loan['max_rate'], (int)$loan['initial_rate'] + $steps * (int)$loan['rate_step']);
                $interest += (int)round((int)$loan['principal'] * $rate / 100);
                $steps++;
            }
            if ($due > 0) { $up->execute(array($steps, $interest, thp_now(), (int)$loan['id'])); }
            $total += (int)$loan['principal'] + $interest;
        }
        $du = $pdo->prepare('UPDATE users SET debt = ? WHERE id = ?');
        $du->execute(array($total, (int)$userId));
        if ($ownTxn) { $pdo->commit(); }
        return $total;
    } catch (Throwable $e) {
        if ($ownTxn && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

function thp_bank_loan_snapshot($userId, $balance, $debt, $rules = null)
{
    global $pdo;
    if (!is_array($rules)) { $rules = thp_market_rules(); }
    $maxPerHour = max(1, (int)$rules['loan_max_per_hour']);
    $maxRatio = max(1, (int)$rules['loan_max_ratio_percent']);
    $st = $pdo->prepare("SELECT COUNT(*) AS n, MIN(opened_at) AS oldest FROM bank_loans
        WHERE user_id = ? AND opened_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $st->execute(array((int)$userId));
    $row = $st->fetch() ?: array('n' => 0, 'oldest' => null);
    $used = (int)$row['n'];
    $oldest = !empty($row['oldest']) ? strtotime((string)$row['oldest']) : 0;
    $nextReset = ($oldest > 0) ? date('Y-m-d H:i:s', $oldest + 3600) : '';
    $ownCapital = max(0, (int)$balance - (int)$debt);
    return array(
        'own_capital' => $ownCapital,
        'max_amount' => (int)floor($ownCapital * $maxRatio / 100),
        'used_last_hour' => $used,
        'remaining_last_hour' => max(0, $maxPerHour - $used),
        'max_per_hour' => $maxPerHour,
        'max_ratio_percent' => $maxRatio,
        'next_reset_at' => $nextReset,
    );
}

function thp_market_loan($amount = 0)
{
    global $pdo;
    $u = thp_require_login();
    thp_market_require_trading_enabled();
    $rules = thp_market_rules();
    $amount = (int)$amount;
    if ($amount < 1 || $amount > 1000000) { throw new ThpError('借款金额不合法'); }
    $debt = 0;
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT balance FROM users WHERE id = ? FOR UPDATE');
        $st->execute(array((int)$u['id']));
        $balance = (int)$st->fetchColumn();
        $debt = thp_bank_sync_user_debt((int)$u['id']);
        $snapshot = thp_bank_loan_snapshot((int)$u['id'], $balance, $debt, $rules);
        if ((int)$snapshot['remaining_last_hour'] <= 0) {
            $next = (string)$snapshot['next_reset_at'];
            $tip = $next !== '' ? ('；约 ' . substr($next, 11, 5) . ' 后释放 1 次额度') : '';
            throw new ThpError('每 1 小时最多贷款 ' . (int)$rules['loan_max_per_hour'] . ' 次，本小时额度已用完' . $tip);
        }
        if ((int)$snapshot['max_amount'] < 1) {
            throw new ThpError('当前可计算的自有资金不足，暂时无法借款');
        }
        if ($amount > (int)$snapshot['max_amount']) {
            throw new ThpError('单次借款最高为自有资金的 ' . (int)$rules['loan_max_ratio_percent'] . '%，你当前最多可借 ' . (int)$snapshot['max_amount'] . ' FDT');
        }
        $newBal = thp_balance_apply($pdo, (int)$u['id'], $amount, 'loan', 'bank', '银行贷款 ' . $amount . ' FDT');
        $ins = $pdo->prepare('INSERT INTO bank_loans (user_id, principal, initial_rate, rate_step, max_rate, interest_charged, interest_steps, opened_at, last_synced_at, status) VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, ?)');
        $now = thp_now();
        $ins->execute(array((int)$u['id'], $amount, $rules['loan_base_rate'], $rules['loan_rate_step'], $rules['loan_max_rate'], $now, $now, 'active'));
        $loanId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($e instanceof ThpError) { throw $e; }
        throw new ThpError('借款失败，请稍后再试', 500);
    }
    $debt += $amount;
    return array(
        'balance' => $newBal,
        'loan_id' => $loanId,
        'amount' => $amount,
        'debt' => $debt,
        'loan' => thp_bank_loan_snapshot((int)$u['id'], $newBal, $debt, $rules),
        'rules' => $rules,
    );
}

function thp_market_repay($amount)
{
    global $pdo;
    $u = thp_require_login();
    $amount = (int)$amount;
    if ($amount < 1) { throw new ThpError('还款金额不合法'); }
    $debt = thp_bank_sync_user_debt((int)$u['id']);
    if ($debt <= 0) { throw new ThpError('当前没有欠款需要归还'); }
    $actual = min($amount, $debt);
    $pdo->beginTransaction();
    try {
        $bs = $pdo->prepare('SELECT balance FROM users WHERE id = ? FOR UPDATE');
        $bs->execute(array((int)$u['id']));
        $balance = (int)$bs->fetchColumn();
        if ($balance < $actual) { throw new ThpError('FDT 余额不足，无法还款'); }
        $ls = $pdo->prepare("SELECT * FROM bank_loans WHERE user_id = ? AND status = 'active' ORDER BY id FOR UPDATE");
        $ls->execute(array((int)$u['id']));
        $loans = $ls->fetchAll();
        $left = $actual;
        $up = $pdo->prepare("UPDATE bank_loans SET principal = ?, interest_charged = ?, status = ?, closed_at = ? WHERE id = ?");
        $remainingDebt = 0;
        foreach ($loans as $loan) {
            $principal = (int)$loan['principal'];
            $interest = (int)$loan['interest_charged'];
            $outstanding = $principal + $interest;
            if ($left >= $outstanding) {
                $left -= $outstanding;
                $up->execute(array(0, 0, 'repaid', thp_now(), (int)$loan['id']));
                continue;
            }
            $payInterest = min($interest, $left);
            $interest -= $payInterest;
            $left -= $payInterest;
            $principal -= min($principal, $left);
            $left = 0;
            $up->execute(array(max(0, $principal), max(0, $interest), 'active', null, (int)$loan['id']));
            $remainingDebt += max(0, $principal) + max(0, $interest);
        }
        $newBal = thp_balance_apply($pdo, (int)$u['id'], -$actual, 'repay', 'bank', '偿还银行贷款 ' . $actual . ' FDT');
        $du = $pdo->prepare('UPDATE users SET debt = ? WHERE id = ?');
        $du->execute(array($remainingDebt, (int)$u['id']));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($e instanceof ThpError) { throw $e; }
        throw new ThpError('还款失败，请稍后再试', 500);
    }
    return array('balance' => $newBal, 'paid' => $actual, 'debt' => $remainingDebt);
}

// ===================== 公司创建 / 改名 =====================
function thp_market_create_company($name)
{
    global $pdo;
    $u = thp_require_no_debt('创建公司');
    thp_market_require_trading_enabled();
    $name = trim((string)$name);
    if (!preg_match('/^[\p{Han}A-Za-z0-9_]{2,12}$/u', $name)) { throw new ThpError('公司名需为 2-12 位中文 / 字母 / 数字 / 下划线'); }
    thp_check_banned($name, '公司名', array('username' => (string)$u['username'], 'device_key' => thp_device_request_context()['key']));
    $rules = thp_market_rules();
    $st = $pdo->prepare('SELECT COUNT(*) FROM companies WHERE owner_user_id = ?');
    $st->execute(array((int)$u['id']));
    if ((int)$st->fetchColumn() >= 1) { throw new ThpError('每人只能创建 1 家公司哦'); }
    $st = $pdo->prepare('SELECT id FROM companies WHERE name = ? LIMIT 1');
    $st->execute(array($name));
    if ($st->fetchColumn()) { throw new ThpError('这个名字已经被别的公司使用了'); }
    $base = mt_rand(1000, 2800) / 100;
    $pdo->beginTransaction();
    try {
        $newBal = thp_balance_apply($pdo, (int)$u['id'], -$rules['company_cost'], 'company_create', $name, '创建公司「' . $name . '」');
        $ins = $pdo->prepare('INSERT INTO companies (name, owner_user_id, is_default, seed, base_price, created_at) VALUES (?, ?, 0, ?, ?, ?)');
        $ins->execute(array($name, (int)$u['id'], mt_rand(1000, 2000000000), $base, thp_now()));
        $companyId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($e instanceof ThpError) { throw $e; }
        if (stripos($e->getMessage(), 'Duplicate') !== false) { throw new ThpError('这个名字已经被别的公司使用了'); }
        throw new ThpError('创建公司失败，请稍后再试', 500);
    }
    thp_market_ensure_engine(0, true);
    thp_log_admin('创建公司', $u['username'] . ' -> ' . $name);
    return array('id' => $companyId, 'name' => $name, 'balance' => $newBal, 'base_price' => $base);
}

function thp_company_rename($companyId, $name)
{
    global $pdo;
    $u = thp_require_login();
    $company = thp_company_by_id((int)$companyId);
    if (!$company) { throw new ThpError('公司不存在'); }
    $isAdmin = ((string)$u['role'] === 'admin');
    if (!$isAdmin && (int)$company['owner_user_id'] !== (int)$u['id']) { throw new ThpError('只能修改自己创建的公司名字'); }
    $name = trim((string)$name);
    if (!preg_match('/^[\p{Han}A-Za-z0-9_]{2,12}$/u', $name)) { throw new ThpError('公司名需为 2-12 位中文 / 字母 / 数字 / 下划线'); }
    if ($name === (string)$company['name']) { return array('ok' => true, 'name' => $name); }
    thp_check_banned($name, '公司名', array('username' => (string)$u['username'], 'device_key' => thp_device_request_context()['key']));
    if (!$isAdmin && !empty($company['renamed_at'])) {
        $cooldown = max(60, thp_market_cfg_int('company_rename_cooldown_seconds', 3600));
        $left = (int)$company['renamed_at'] ? strtotime((string)$company['renamed_at']) + $cooldown - time() : 0;
        if ($left > 0) { throw new ThpError('改名冷却中，还需等待 ' . (int)ceil($left / 60) . ' 分钟'); }
    }
    $st = $pdo->prepare('SELECT id FROM companies WHERE name = ? AND id <> ? LIMIT 1');
    $st->execute(array($name, (int)$company['id']));
    if ($st->fetchColumn()) { throw new ThpError('这个名字已经被别的公司使用了'); }
    $up = $pdo->prepare('UPDATE companies SET name = ?, renamed_at = ? WHERE id = ?');
    $up->execute(array($name, thp_now(), (int)$company['id']));
    thp_log_admin('公司改名', $u['username'] . ': ' . $company['name'] . ' -> ' . $name);
    return array('ok' => true, 'name' => $name);
}

// ===================== 未来走势干预 =====================
function thp_market_save_future_points($companyId, array $points, $source = 'manual')
{
    global $pdo;
    $company = thp_company_by_id((int)$companyId);
    if (!$company) { throw new ThpError('公司不存在'); }
    if (!in_array($source, array('manual', 'ai'), true)) { throw new ThpError('来源不合法'); }
    $nowTick = thp_tick_of_day();
    $rules = thp_market_rules();
    $minPrice = max(0.01, (float)$company['base_price'] * $rules['market_min_ratio']);
    $maxPrice = max($minPrice, (float)$company['base_price'] * $rules['market_max_ratio']);
    $clean = array();
    $stepMax = $rules['market_step_max_percent'] / 100.0;
    foreach ($points as $p) {
        if (!is_array($p) || !isset($p['tick'], $p['price'])) { continue; }
        $tick = (int)$p['tick'];
        $price = (float)$p['price'];
        if ($tick <= $nowTick || $tick >= THP_TICKS_PER_DAY || !is_finite($price)) { continue; }
        $price = max($minPrice, min($maxPrice, thp_price_norm($price)));
        $clean[$tick] = array('tick' => $tick, 'price' => $price);
    }
    if (count($clean) === 0) { throw new ThpError('没有可保存的未来价格点（只能修改当前时间之后的价格）'); }
    ksort($clean);
    // 每个 3 分钟点最多相对前一个点变动 market_step_max_percent；手工/AI 干预也不能绕过该限制。
    $quote = thp_market_quote((int)$company['id'], $nowTick);
    $previous = $quote !== null ? $quote : (float)$company['base_price'];
    foreach ($clean as $tick => &$p) {
        $low = $previous * (1.0 - $stepMax);
        $high = $previous * (1.0 + $stepMax);
        $p['price'] = thp_price_norm(max($low, min($high, $p['price'])));
        $previous = $p['price'];
    }
    unset($p);
    $cutoff = max(array_keys($clean));
    $stmt = $pdo->prepare('INSERT INTO market_points (trade_date, tick, company_id, price, source, updated_at) VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE price = VALUES(price), source = VALUES(source), updated_at = VALUES(updated_at)');
    $date = thp_today();
    foreach ($clean as $tick => $p) {
        if ($tick > $cutoff) { break; }
        $stmt->execute(array($date, (int)$tick, (int)$company['id'], $p['price'], $source, thp_now()));
    }
    thp_set_setting('market_intervention_end_tick', (string)$cutoff);
    thp_set_setting('market_intervention_source', $source);
    thp_set_setting('market_intervention_at', thp_now());
    $remaining = THP_TICKS_PER_DAY - 1 - $nowTick;
    $horizon = max($rules['market_forecast_ticks'], min($remaining, $cutoff - $nowTick + $rules['market_forecast_ticks']));
    thp_market_ensure_engine($horizon, true);
    return array(
        'company_id' => (int)$company['id'],
        'company_name' => (string)$company['name'],
        'written' => count($clean),
        'cutoff_tick' => $cutoff,
        'cutoff_time' => thp_market_tick_time_label($date, $cutoff),
        'source' => $source,
    );
}

// 新闻只影响尚未发生的行情：清除未来的自动预测点，保留人工/AI 干预，然后立即重算。
function thp_market_invalidate_future_auto()
{
    global $pdo;
    $date = thp_today();
    $tick = thp_tick_of_day();
    $st = $pdo->prepare("DELETE FROM market_points WHERE trade_date = ? AND tick > ? AND source = 'auto'");
    $st->execute(array($date, $tick));
    $deleted = $st->rowCount();
    thp_market_ensure_engine(0, true);
    return $deleted;
}

// ===================== 新闻 / 通知 =====================
function thp_news_company_ids($raw, $companies)
{
    $valid = array();
    foreach ($companies as $c) { $valid[(int)$c['id']] = true; }
    $out = array();
    foreach (explode(',', (string)$raw) as $id) {
        $id = (int)trim($id);
        if ($id > 0 && isset($valid[$id])) { $out[$id] = $id; }
    }
    return implode(',', array_values($out));
}

function thp_news_publish_item($kind, $title, $body, $positiveIds, $negativeIds, $impact, $source, $templateId = null, $pinned = 0, $popupEnabled = 0)
{
    global $pdo;
    $kind = ($kind === 'notice') ? 'notice' : 'market';
    $title = trim((string)$title);
    $body = trim((string)$body);
    if ($title === '' || mb_strlen($title, 'UTF-8') > 60) { throw new ThpError('标题需为 1-60 个字'); }
    if ($body === '' || mb_strlen($body, 'UTF-8') > 2000) { throw new ThpError('正文需为 1-2000 个字'); }
    if ($kind === 'notice') { $positiveIds = ''; $negativeIds = ''; $impact = 0; $popupEnabled = 1; }
    $popupEnabled = $popupEnabled ? 1 : 0;
    $impact = max(0.0, min(20.0, (float)$impact));
    $now = thp_now();
    $st = $pdo->prepare("INSERT INTO news_items
        (template_id, kind, title, body, positive_company_ids, negative_company_ids, impact_percent, source, popup_enabled, pinned, pinned_until, effect_applied_at, published_at, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)");
    $pinnedUntil = $pinned ? date('Y-m-d H:i:s', time() + 1800) : null;
    $st->execute(array($templateId ?: null, $kind, $title, $body, (string)$positiveIds, (string)$negativeIds, $impact, (string)$source, $popupEnabled, $pinned ? 1 : 0, $pinnedUntil, $now, $now));
    $newsId = (int)$pdo->lastInsertId();
    if ($kind === 'market' && $impact > 0 && ((string)$positiveIds !== '' || (string)$negativeIds !== '')) {
        thp_market_invalidate_future_auto();
    }
    return $newsId;
}

function thp_news_tick()
{
    global $pdo;
    if ((string)thp_setting('news_auto_enabled', '1') !== '1') { return 0; }
    if (!thp_market_lock('thp_news_auto', 1)) { return 0; }
    try {
        $rules = thp_market_rules();
        $next = (string)thp_setting('news_auto_next_at', '');
        if ($next === '') {
            // 首次启用时先完整等待一个播报周期，避免第一个访客进站就突然收到历史新闻。
            thp_set_setting('news_auto_next_at', date('Y-m-d H:i:s', time() + $rules['news_interval_minutes'] * 60));
            return 0;
        }
        if ($next !== '' && strtotime($next) > time()) { return 0; }
        $min = min($rules['news_auto_min'], $rules['news_auto_max']);
        $max = max($rules['news_auto_min'], $rules['news_auto_max']);
        $count = $max > 0 ? mt_rand($min, $max) : 0;
        $autoPopup = (string)thp_setting('news_auto_popup_enabled', '0') === '1' ? 1 : 0;
        $published = 0;
        if ($count > 0) {
            $st = $pdo->query("SELECT * FROM news_templates WHERE pool = 'news' AND kind = 'market' AND enabled = 1
                AND (last_used_at IS NULL OR last_used_at < DATE_SUB(NOW(), INTERVAL 2 HOUR))
                ORDER BY RAND() LIMIT " . (int)$count);
            $templates = $st->fetchAll();
            foreach ($templates as $tpl) {
                thp_news_publish_item('market', $tpl['title'], $tpl['body'], $tpl['positive_company_ids'], $tpl['negative_company_ids'], $tpl['impact_percent'], 'auto', (int)$tpl['id'], 0, $autoPopup);
                $up = $pdo->prepare('UPDATE news_templates SET last_used_at = ? WHERE id = ?');
                $up->execute(array(thp_now(), (int)$tpl['id']));
                $published++;
            }
        }
        thp_set_setting('news_auto_next_at', date('Y-m-d H:i:s', time() + $rules['news_interval_minutes'] * 60));
        return $published;
    } finally {
        thp_market_unlock('thp_news_auto');
    }
}

function thp_news_inbox($userId, $kind, $limit = 20)
{
    global $pdo;
    $kind = ($kind === 'notice') ? 'notice' : 'market';
    $limit = max(1, min(50, (int)$limit));
    $st = $pdo->prepare("SELECT n.id, n.kind, n.title, n.body, n.impact_percent, n.source, n.popup_enabled, n.pinned, n.published_at
        FROM news_items n LEFT JOIN news_reads r ON r.news_id = n.id AND r.user_id = ?
        WHERE n.kind = ? AND n.published_at <= NOW() AND r.news_id IS NULL
          AND (n.kind = 'notice' OR n.popup_enabled = 1)
        ORDER BY n.pinned DESC, n.id ASC LIMIT " . $limit);
    $st->execute(array((int)$userId, $kind));
    $items = $st->fetchAll();
    foreach ($items as &$it) {
        $it['id'] = (int)$it['id'];
        $it['impact_percent'] = (float)$it['impact_percent'];
        $it['popup_enabled'] = (int)$it['popup_enabled'];
        $it['pinned'] = (int)$it['pinned'];
    }
    unset($it);
    return array('items' => $items, 'kind' => $kind);
}

function thp_news_detail($newsId)
{
    global $pdo;
    $st = $pdo->prepare('SELECT id, kind, title, body, positive_company_ids, negative_company_ids, impact_percent, source, pinned, published_at
        FROM news_items WHERE id = ? AND published_at <= NOW() LIMIT 1');
    $st->execute(array((int)$newsId));
    $row = $st->fetch();
    if (!$row) { throw new ThpError('这条新闻不存在或尚未发布', 404); }

    $parseIds = function ($value) {
        $parts = preg_split('/[^0-9]+/', (string)$value, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_map('intval', $parts ?: array())));
    };
    $positiveIds = $parseIds($row['positive_company_ids']);
    $negativeIds = $parseIds($row['negative_company_ids']);
    $allIds = array_values(array_unique(array_merge($positiveIds, $negativeIds)));
    $companyNames = array();
    if (count($allIds) > 0) {
        $in = implode(',', array_map('intval', $allIds));
        foreach ($pdo->query('SELECT id, name FROM companies WHERE id IN (' . $in . ')') as $company) {
            $companyNames[(int)$company['id']] = (string)$company['name'];
        }
    }
    $named = function ($ids) use ($companyNames) {
        $out = array();
        foreach ($ids as $id) {
            if (isset($companyNames[$id])) { $out[] = array('id' => $id, 'name' => $companyNames[$id]); }
        }
        return $out;
    };

    return array('news' => array(
        'id' => (int)$row['id'],
        'kind' => (string)$row['kind'],
        'title' => (string)$row['title'],
        'body' => (string)$row['body'],
        'impact_percent' => (float)$row['impact_percent'],
        'source' => (string)$row['source'],
        'pinned' => (int)$row['pinned'],
        'published_at' => (string)$row['published_at'],
        'positive_companies' => $named($positiveIds),
        'negative_companies' => $named($negativeIds),
    ));
}

function thp_news_mark_read($userId, $newsId)
{
    global $pdo;
    $st = $pdo->prepare('INSERT IGNORE INTO news_reads (user_id, news_id, read_at) VALUES (?, ?, ?)');
    $st->execute(array((int)$userId, (int)$newsId, thp_now()));
    return array('ok' => true);
}
