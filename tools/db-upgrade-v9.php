<?php
// 数据库 v9 升级：把每条公司随机事件绑定到可编辑的随机事件池模板。
declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
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
}

$configCandidates = array(
    dirname(__DIR__) . '/public/config.php',
    dirname(__DIR__) . '/config.php',
    __DIR__ . '/config.php',
);
$configFile = '';
foreach ($configCandidates as $candidate) {
    if (@is_file($candidate)) { $configFile = $candidate; break; }
}
if ($configFile === '') { echo "config.php not found\n"; exit(1); }
$config = require $configFile;
date_default_timezone_set(!empty($config['timezone']) ? (string)$config['timezone'] : 'Asia/Shanghai');
$db = isset($config['db']) && is_array($config['db']) ? $config['db'] : array();
$dsn = 'mysql:host=' . (string)($db['host'] ?? '127.0.0.1')
    . ';port=' . (int)($db['port'] ?? 3306)
    . ';dbname=' . (string)($db['name'] ?? '')
    . ';charset=utf8mb4';
$pdo = new PDO($dsn, (string)($db['user'] ?? ''), (string)($db['pass'] ?? ''), array(
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
));
$pdo->exec('SET NAMES utf8mb4');

function v9_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $st->execute(array($table));
    return (int)$st->fetchColumn() > 0;
}

function v9_column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $st->execute(array($table, $column));
    return (int)$st->fetchColumn() > 0;
}

function v9_index_exists(PDO $pdo, string $table, string $index): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
    $st->execute(array($table, $index));
    return (int)$st->fetchColumn() > 0;
}

$changes = array();
$created = 0;
$linked = 0;
$normalized = 0;
$syncedEvents = 0;
$syncedNews = 0;
try {
    if (!v9_table_exists($pdo, 'settings')) { throw new RuntimeException('settings table not found'); }
    if (!v9_table_exists($pdo, 'news_templates') || !v9_table_exists($pdo, 'company_events')) {
        throw new RuntimeException('news_templates or company_events table not found');
    }
    if (!v9_column_exists($pdo, 'news_templates', 'pool')) {
        $pdo->exec("ALTER TABLE news_templates ADD COLUMN pool ENUM('news','event') NOT NULL DEFAULT 'news' AFTER kind");
        $changes[] = 'news_templates.pool';
    }
    if (!v9_column_exists($pdo, 'company_events', 'template_id')) {
        $pdo->exec('ALTER TABLE company_events ADD COLUMN template_id BIGINT UNSIGNED NULL AFTER news_id');
        $changes[] = 'company_events.template_id';
    }
    if (!v9_index_exists($pdo, 'news_templates', 'idx_pool_enabled_used')) {
        $pdo->exec('ALTER TABLE news_templates ADD KEY idx_pool_enabled_used (pool, kind, enabled, last_used_at)');
        $changes[] = 'news_templates.idx_pool_enabled_used';
    }
    if (!v9_index_exists($pdo, 'company_events', 'uk_event_template')) {
        $pdo->exec('ALTER TABLE company_events ADD UNIQUE KEY uk_event_template (template_id)');
        $changes[] = 'company_events.uk_event_template';
    }

    $pdo->beginTransaction();
    $prefix = '【随机事件】';
    $prefixLength = mb_strlen($prefix, 'UTF-8');
    $normalizers = array(
        array('company_events', ''),
        array('news_templates', " AND pool = 'event'"),
        array('news_items', " AND source = 'event'"),
    );
    foreach ($normalizers as $normalizer) {
        $table = $normalizer[0];
        $extra = $normalizer[1];
        $update = $pdo->prepare("UPDATE `$table` SET title = SUBSTRING(title, ?) WHERE title LIKE ? AND CHAR_LENGTH(title) > ?" . $extra);
        $update->execute(array($prefixLength + 1, $prefix . '%', $prefixLength));
        $normalized += $update->rowCount();
    }

    $missing = $pdo->query('SELECT e.id, e.news_id, e.title, e.body, e.positive_company_ids, e.negative_company_ids, e.impact_percent, e.created_at
        FROM company_events e
        LEFT JOIN news_templates t ON t.id = e.template_id
        WHERE e.template_id IS NULL OR t.id IS NULL
        ORDER BY e.id ASC')->fetchAll();
    $insert = $pdo->prepare("INSERT INTO news_templates
        (kind, pool, title, body, positive_company_ids, negative_company_ids, impact_percent, enabled, popup_enabled, created_by, created_at, updated_at)
        VALUES ('market', 'event', ?, ?, ?, ?, ?, 1, 0, NULL, ?, ?)");
    $link = $pdo->prepare('UPDATE company_events SET template_id = ? WHERE id = ?');
    $linkNews = $pdo->prepare('UPDATE news_items SET template_id = ? WHERE id = ?');

    foreach ($missing as $event) {
        $now = date('Y-m-d H:i:s');
        $insert->execute(array(
            (string)$event['title'],
            (string)$event['body'],
            (string)$event['positive_company_ids'],
            (string)$event['negative_company_ids'],
            (float)$event['impact_percent'],
            $now,
            $now,
        ));
        $templateId = (int)$pdo->lastInsertId();
        $link->execute(array($templateId, (int)$event['id']));
        if ($event['news_id'] !== null && (int)$event['news_id'] > 0) {
            $linkNews->execute(array($templateId, (int)$event['news_id']));
        }
        $created++;
        $linked++;
    }
    $syncedEvents = (int)$pdo->exec("UPDATE company_events e
        INNER JOIN news_templates t ON t.id = e.template_id
        SET e.title = t.title, e.body = t.body
        WHERE t.pool = 'event'");
    $syncedNews = (int)$pdo->exec("UPDATE news_items n
        INNER JOIN company_events e ON e.news_id = n.id
        INNER JOIN news_templates t ON t.id = e.template_id
        SET n.template_id = t.id, n.title = t.title, n.body = t.body
        WHERE n.source = 'event' AND t.pool = 'event'");
    $pdo->commit();

    $st = $pdo->prepare("INSERT INTO settings (k, v) VALUES ('schema_version', '9') ON DUPLICATE KEY UPDATE v = '9'");
    $st->execute();
    $changes[] = 'schema_version=9';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    echo "v9 upgrade failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "DB upgrade v9 completed.\n";
echo 'changes=' . implode(',', $changes) . "\n";
echo 'event_templates=' . $created . "\n";
echo 'linked_events=' . $linked . "\n";
echo 'normalized_titles=' . $normalized . "\n";
echo 'synced_events=' . $syncedEvents . "\n";
echo 'synced_news=' . $syncedNews . "\n";
$remainingPrefixed = (int)$pdo->query("SELECT COUNT(*) FROM company_events WHERE title LIKE '【随机事件】%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM news_templates WHERE pool = 'event' AND title LIKE '【随机事件】%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM news_items WHERE source = 'event' AND title LIKE '【随机事件】%'")->fetchColumn();
echo 'prefixed_remaining=' . $remainingPrefixed . "\n";
