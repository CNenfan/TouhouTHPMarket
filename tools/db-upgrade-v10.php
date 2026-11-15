<?php
// 数据库 v10 升级：新增持久登录令牌表。
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

function v10_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $st->execute(array($table));
    return (int)$st->fetchColumn() > 0;
}

$lockName = 'thp_db_upgrade_v10';
$locked = false;
try {
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute(array($lockName));
    $locked = ((int)$lock->fetchColumn() === 1);
    if (!$locked) { throw new RuntimeException('could not acquire migration lock'); }

    $existed = v10_table_exists($pdo, 'auth_tokens');
    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        selector CHAR(24) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        user_agent VARCHAR(255) NOT NULL DEFAULT '',
        ip VARCHAR(45) NOT NULL DEFAULT '',
        PRIMARY KEY (id),
        UNIQUE KEY uk_selector (selector),
        KEY idx_user (user_id, id),
        KEY idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st = $pdo->prepare("INSERT INTO settings (k, v) VALUES ('schema_version', '10') ON DUPLICATE KEY UPDATE v = '10'");
    $st->execute();
} catch (Throwable $e) {
    http_response_code(500);
    echo "v10 upgrade failed: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    if ($locked) {
        try {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute(array($lockName));
        } catch (Throwable $ignored) {}
    }
}

echo "DB upgrade v10 completed.\n";
echo 'auth_tokens=' . ($existed ? 'existing' : 'created') . "\n";
echo "schema_version=10\n";
