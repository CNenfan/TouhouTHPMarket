<?php
// 数据库 v4 升级：动态行情、持仓批次、银行、新闻/通知、资产榜、注册条款。
// 默认只补结构，不删除活动数据；传入 --reset（HTTP 为 reset=1）才执行删档重置。
declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';
$doReset = false;
if ($isCli) {
    $doReset = in_array('--reset', $argv, true);
} else {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    $expected = '';
    $keyFile = __DIR__ . '/.thp-migration-key.php';
    if (is_file($keyFile)) {
        $loadedKey = require $keyFile;
        if (is_string($loadedKey)) { $expected = trim($loadedKey); }
    }
    if ($expected === '' || !isset($_GET['key']) || !hash_equals($expected, (string)$_GET['key'])) {
        http_response_code(404);
        echo "Not found\n";
        exit;
    }
    $doReset = isset($_GET['reset']) && (string)$_GET['reset'] === '1';
}

// 兼容三种目录布局：
// 1) 本地 tools/ 与 public/ 同级；
// 2) 迁移脚本放在网站根目录的 tools/ 下；
// 3) 迁移脚本与 config.php 放在同一目录。
$configCandidates = array(
    dirname(__DIR__) . '/public/config.php',
    dirname(__DIR__) . '/config.php',
    __DIR__ . '/config.php',
);
$configFile = '';
foreach ($configCandidates as $candidate) {
    // open_basedir 下的候选路径可能直接触发 warning；这里只探测可访问的文件。
    if (@is_file($candidate)) { $configFile = $candidate; break; }
}
if ($configFile === '') { echo "config.php not found\n"; exit(1); }
$config = require $configFile;
$db = isset($config['db']) && is_array($config['db']) ? $config['db'] : array();
$dsn = 'mysql:host=' . (string)($db['host'] ?? '127.0.0.1')
    . ';port=' . (int)($db['port'] ?? 3306)
    . ';dbname=' . (string)($db['name'] ?? '')
    . ';charset=utf8mb4';
$pdo = new PDO($dsn, (string)($db['user'] ?? ''), (string)($db['pass'] ?? ''), array(
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
));
$pdo->exec('SET NAMES utf8mb4');

function v4_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $st->execute(array($table));
    return (int)$st->fetchColumn() > 0;
}

function v4_column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $st->execute(array($table, $column));
    return (int)$st->fetchColumn() > 0;
}

function v4_run_schema(PDO $pdo, string $file): void
{
    $sql = file_get_contents($file);
    if ($sql === false) { throw new RuntimeException('cannot read schema.sql'); }
    $buffer = '';
    foreach (preg_split('/\r?\n/', $sql) as $line) {
        $trim = trim($line);
        if ($trim === '' || strpos($trim, '--') === 0) { continue; }
        $buffer .= $line . "\n";
        if (substr(rtrim($line), -1) === ';') {
            $pdo->exec($buffer);
            $buffer = '';
        }
    }
}

$schemaCandidates = array(
    dirname(__DIR__) . '/public/schema.sql',
    dirname(__DIR__) . '/schema.sql',
    __DIR__ . '/schema.sql',
);
$schemaFile = '';
foreach ($schemaCandidates as $candidate) {
    if (@is_file($candidate)) { $schemaFile = $candidate; break; }
}
if ($schemaFile === '' || !@is_file($schemaFile)) { echo "schema.sql not found\n"; exit(1); }

$changes = array();
try {
    if (v4_table_exists($pdo, 'users') && !v4_column_exists($pdo, 'users', 'terms_accepted_at')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN terms_accepted_at DATETIME NULL AFTER is_test');
        $changes[] = 'users.terms_accepted_at';
    }
    if (v4_table_exists($pdo, 'companies')) {
        $st = $pdo->query("SELECT DATA_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'companies' AND column_name = 'base_price' LIMIT 1");
        $type = strtolower((string)$st->fetchColumn());
        if ($type !== 'decimal') {
            $pdo->exec('ALTER TABLE companies MODIFY base_price DECIMAL(12,2) NOT NULL DEFAULT 0');
            $changes[] = 'companies.base_price->decimal';
        }
    }
    // news_templates 可能来自一次未完成的本轮升级；补 kind 字段后再交给 schema 建其它表。
    if (v4_table_exists($pdo, 'news_templates') && !v4_column_exists($pdo, 'news_templates', 'kind')) {
        $pdo->exec("ALTER TABLE news_templates ADD COLUMN kind ENUM('market','notice') NOT NULL DEFAULT 'market' AFTER id");
        $changes[] = 'news_templates.kind';
    }

    v4_run_schema($pdo, $schemaFile);
    $changes[] = 'schema tables/settings ensured';

    $defaults = array(
        1 => array('红魔馆商会', 18.00),
        2 => array('博丽神社基金会', 22.00),
        3 => array('永远亭药业', 16.00),
        4 => array('守矢能源', 20.00),
        5 => array('天狗新闻社', 24.00),
    );
    $co = $pdo->prepare('UPDATE companies SET name = ?, base_price = ?, is_default = 1, owner_user_id = NULL WHERE id = ?');
    foreach ($defaults as $id => $def) { $co->execute(array($def[0], $def[1], $id)); }

    if ($doReset) {
        // 先清运行数据，再清游客。管理员账号、题目、故事与系统设置保留。
        $tables = array(
            'news_reads', 'news_items', 'news_templates',
            'asset_rank_snapshots', 'market_points', 'holding_lots', 'holdings',
            'stock_trades', 'bank_loans', 'loans', 'quiz_attempts', 'user_stories',
            'spend_requests', 'issue_codes', 'token_tx', 'admin_logs', 'risk_events',
            'device_bindings', 'throttle', 'idempotency_keys',
        );
        foreach ($tables as $table) {
            if (v4_table_exists($pdo, $table)) { $pdo->exec('DELETE FROM ' . $table); }
        }
        $pdo->exec('DELETE FROM companies WHERE is_default = 0 OR owner_user_id IS NOT NULL');
        $pdo->exec("DELETE FROM users WHERE role <> 'admin'");
        $pdo->exec('UPDATE users SET balance = 100, debt = 0, banned = 0, terms_accepted_at = NULL WHERE role = ' . $pdo->quote('admin'));
        $resetSettings = array(
            'market_last_generated_at' => '',
            'market_engine_day' => '',
            'market_force_refresh' => '0',
            'market_intervention_end_tick' => '',
            'market_intervention_source' => '',
            'market_intervention_at' => '',
            'news_auto_next_at' => '',
            'asset_rank_next_at' => '',
            // 删档测试时一并恢复推荐玩法参数；这些值仍可在后台滑条中调整。
            'trade_throttle_count' => '30',
            'trade_throttle_window_minutes' => '30',
            'transfer_limit_30m' => '1000',
            'holding_lock_minutes' => '15',
            'loan_base_rate' => '10',
            'loan_rate_step' => '10',
            'loan_max_rate' => '100',
            'loan_interest_minutes' => '30',
            'market_refresh_minutes' => '6',
            'market_forecast_ticks' => '20',
            'market_step_max_percent' => '20',
            'market_min_ratio' => '0.3333',
            'market_max_ratio' => '3',
            'market_noise_weight' => '0.85',
            'market_mean_reversion' => '0.04',
            'market_index_reversion' => '0.035',
            'market_hedge_weight' => '0.35',
            'market_investment_weight' => '0.1',
            'market_investment_cap_percent' => '1.5',
            'market_big_move_chance' => '1.2',
            'market_big_move_min_percent' => '6',
            'market_big_move_max_percent' => '18',
            'market_long_drift_percent' => '0',
            'market_cache_seconds' => '12',
            'news_auto_enabled' => '1',
            'news_interval_minutes' => '15',
            'news_auto_min' => '1',
            'news_auto_max' => '2',
        );
        $ss = $pdo->prepare('UPDATE settings SET v = ? WHERE k = ?');
        foreach ($resetSettings as $k => $v) { $ss->execute(array($v, $k)); }
        // 重置会清空新闻模板，再跑一次幂等 schema 把内置幻想乡新闻补回来。
        v4_run_schema($pdo, $schemaFile);
        $changes[] = 'activity data reset (admins/content/settings kept)';
    }

    $up = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    $up->execute(array('schema_version', '4'));
    $changes[] = 'schema_version=4';
} catch (Throwable $e) {
    http_response_code(500);
    echo "v4 upgrade failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "DB upgrade v4 completed.\n";
echo "Mode: " . ($doReset ? 'schema + reset' : 'schema only') . "\n";
echo "Changes: " . implode(', ', array_values(array_unique($changes))) . "\n";
