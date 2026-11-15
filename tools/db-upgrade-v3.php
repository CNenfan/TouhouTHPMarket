<?php
// 数据库 v3 平滑升级：可重复执行，只补结构，不删业务数据。
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

$configFile = '';
foreach (array(__DIR__ . '/config.php', dirname(__DIR__) . '/public/config.php') as $candidate) {
    if (is_file($candidate)) { $configFile = $candidate; break; }
}
if ($configFile === '') {
    echo "config.php not found\n";
    exit(1);
}
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
$pdo->exec("SET NAMES utf8mb4");

function v3_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $st->execute(array($table));
    return (int)$st->fetchColumn() > 0;
}

function v3_column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $st->execute(array($table, $column));
    return (int)$st->fetchColumn() > 0;
}

function v3_index_exists(PDO $pdo, string $table, string $index): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
    $st->execute(array($table, $index));
    return (int)$st->fetchColumn() > 0;
}

$changes = array();
if (!v3_column_exists($pdo, 'users', 'admin_scope')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN admin_scope VARCHAR(16) NOT NULL DEFAULT '' AFTER role");
    $changes[] = 'users.admin_scope';
}
if (!v3_column_exists($pdo, 'users', 'is_test')) {
    $pdo->exec('ALTER TABLE users ADD COLUMN is_test TINYINT(1) NOT NULL DEFAULT 0 AFTER banned');
    $changes[] = 'users.is_test';
}
if (!v3_index_exists($pdo, 'users', 'idx_role_state')) {
    $pdo->exec('ALTER TABLE users ADD KEY idx_role_state (role, banned, is_test)');
    $changes[] = 'users.idx_role_state';
}
if (!v3_column_exists($pdo, 'companies', 'renamed_at')) {
    $pdo->exec('ALTER TABLE companies ADD COLUMN renamed_at DATETIME NULL AFTER created_at');
    $changes[] = 'companies.renamed_at';
}
if (!v3_index_exists($pdo, 'token_tx', 'idx_type_user')) {
    $pdo->exec('ALTER TABLE token_tx ADD KEY idx_type_user (type, user_id)');
    $changes[] = 'token_tx.idx_type_user';
}
if (!v3_index_exists($pdo, 'token_tx', 'idx_created')) {
    $pdo->exec('ALTER TABLE token_tx ADD KEY idx_created (created_at)');
    $changes[] = 'token_tx.idx_created';
}
if (!v3_index_exists($pdo, 'holdings', 'idx_company_shares')) {
    $pdo->exec('ALTER TABLE holdings ADD KEY idx_company_shares (company_id, shares)');
    $changes[] = 'holdings.idx_company_shares';
}
if (!v3_table_exists($pdo, 'stock_trades')) {
    $pdo->exec("CREATE TABLE stock_trades (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id INT UNSIGNED NOT NULL,
      company_id INT UNSIGNED NOT NULL,
      side ENUM('buy','sell') NOT NULL,
      qty INT NOT NULL,
      price DECIMAL(12,2) NOT NULL,
      amount BIGINT NOT NULL,
      tick SMALLINT UNSIGNED NOT NULL,
      idempotency_key VARCHAR(64) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY idx_user_company (user_id, company_id, id),
      KEY idx_company_time (company_id, created_at),
      KEY idx_user_time (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $changes[] = 'stock_trades';
}
if (!v3_table_exists($pdo, 'idempotency_keys')) {
    $pdo->exec("CREATE TABLE idempotency_keys (
      user_id INT UNSIGNED NOT NULL,
      idem_key VARCHAR(64) NOT NULL,
      action VARCHAR(64) NOT NULL,
      payload_hash CHAR(64) NOT NULL,
      status ENUM('running','done') NOT NULL DEFAULT 'running',
      response MEDIUMTEXT NULL,
      created_at DATETIME NOT NULL,
      expires_at DATETIME NOT NULL,
      PRIMARY KEY (user_id, idem_key),
      KEY idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $changes[] = 'idempotency_keys';
}

$settings = array(
    'company_rename_cooldown_seconds' => '3600',
    'market_cache_seconds' => '12',
    'schema_version' => '3',
);
$ins = $pdo->prepare('INSERT IGNORE INTO settings (k, v) VALUES (?, ?)');
foreach ($settings as $key => $value) {
    $ins->execute(array($key, $value));
    if ($ins->rowCount() > 0) { $changes[] = 'settings.' . $key; }
}
$up = $pdo->prepare('UPDATE settings SET v = ? WHERE k = ?');
$up->execute(array('3', 'schema_version'));

echo "DB upgrade v3 completed. Changes: " . (count($changes) ? implode(', ', $changes) : 'none') . "\n";
