<?php
// 数据库 v5 升级：新闻独立弹窗开关，并把自动新闻播报默认设为关闭。
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

function v5_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $st->execute(array($table));
    return (int)$st->fetchColumn() > 0;
}

function v5_column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $st->execute(array($table, $column));
    return (int)$st->fetchColumn() > 0;
}

function v5_setting(PDO $pdo, string $key, string $default = ''): string
{
    $st = $pdo->prepare('SELECT v FROM settings WHERE k = ? LIMIT 1');
    $st->execute(array($key));
    $value = $st->fetchColumn();
    return $value === false ? $default : (string)$value;
}

$changes = array();
try {
    if (!v5_table_exists($pdo, 'news_templates') || !v5_table_exists($pdo, 'news_items')) {
        throw new RuntimeException('news tables not found; run schema.sql or v4 upgrade first');
    }
    if (!v5_column_exists($pdo, 'news_templates', 'popup_enabled')) {
        $pdo->exec('ALTER TABLE news_templates ADD COLUMN popup_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER enabled');
        $changes[] = 'news_templates.popup_enabled';
    }
    if (!v5_column_exists($pdo, 'news_items', 'popup_enabled')) {
        $pdo->exec('ALTER TABLE news_items ADD COLUMN popup_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER source');
        $changes[] = 'news_items.popup_enabled';
    }

    $currentVersion = (int)v5_setting($pdo, 'schema_version', '0');
    if ($currentVersion < 5) {
        $st = $pdo->prepare("INSERT INTO settings (k, v) VALUES ('news_auto_enabled', '0') ON DUPLICATE KEY UPDATE v = '0'");
        $st->execute();
        $changes[] = 'news_auto_enabled=0';
    }
    $st = $pdo->prepare("INSERT INTO settings (k, v) VALUES ('schema_version', '5') ON DUPLICATE KEY UPDATE v = '5'");
    $st->execute();
    $changes[] = 'schema_version=5';
} catch (Throwable $e) {
    http_response_code(500);
    echo "v5 upgrade failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "DB upgrade v5 completed.\n";
echo "Changes: " . implode(', ', array_values(array_unique($changes))) . "\n";
