<?php
// 数据库 v6 升级：自动新闻播报默认开启；新增自动播报弹窗开关（默认关闭）。
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

function v6_setting(PDO $pdo, string $key, string $default = ''): string
{
    $st = $pdo->prepare('SELECT v FROM settings WHERE k = ? LIMIT 1');
    $st->execute(array($key));
    $value = $st->fetchColumn();
    return $value === false ? $default : (string)$value;
}

$changes = array();
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'settings'");
    $st->execute();
    if ((int)$st->fetchColumn() === 0) {
        throw new RuntimeException('settings table not found; run the v5 upgrade or schema.sql first');
    }

    $wasEnabled = v6_setting($pdo, 'news_auto_enabled', '0');
    if ($wasEnabled !== '1') {
        $st = $pdo->prepare("INSERT INTO settings (k, v) VALUES ('news_auto_next_at', '') ON DUPLICATE KEY UPDATE v = ''");
        $st->execute();
        $changes[] = 'news_auto_next_at reset';
    }
    $st = $pdo->prepare("INSERT INTO settings (k, v) VALUES ('news_auto_enabled', '1') ON DUPLICATE KEY UPDATE v = '1'");
    $st->execute();
    $changes[] = 'news_auto_enabled=1';

    $st = $pdo->prepare("INSERT IGNORE INTO settings (k, v) VALUES ('news_auto_popup_enabled', '0')");
    $st->execute();
    $changes[] = 'news_auto_popup_enabled=' . v6_setting($pdo, 'news_auto_popup_enabled', '0');

    $st = $pdo->prepare("INSERT INTO settings (k, v) VALUES ('schema_version', '6') ON DUPLICATE KEY UPDATE v = '6'");
    $st->execute();
    $changes[] = 'schema_version=6';
} catch (Throwable $e) {
    http_response_code(500);
    echo "v6 upgrade failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "DB upgrade v6 completed.\n";
echo "Changes: " . implode(', ', array_values(array_unique($changes))) . "\n";