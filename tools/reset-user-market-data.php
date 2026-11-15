<?php
declare(strict_types=1);

// 一次性运维工具：清空普通游客的资产/交易数据，但保留账号、公司、故事解锁和设备绑定。
// 用法：php tools/reset-user-market-data.php           仅统计，不写入
//       php tools/reset-user-market-data.php --apply   备份后执行
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/public/includes/bootstrap.php';

global $pdo;
$apply = in_array('--apply', $argv, true);

function reset_count(PDO $pdo, string $sql): int
{
    return (int)$pdo->query($sql)->fetchColumn();
}

function reset_user_counts(PDO $pdo): array
{
    return array(
        'users' => reset_count($pdo, "SELECT COUNT(*) FROM users WHERE role = 'user'"),
        'users_with_balance' => reset_count($pdo, "SELECT COUNT(*) FROM users WHERE role = 'user' AND balance <> 0"),
        'users_with_debt' => reset_count($pdo, "SELECT COUNT(*) FROM users WHERE role = 'user' AND debt <> 0"),
        'companies' => reset_count($pdo, 'SELECT COUNT(*) FROM companies'),
        'token_tx' => reset_count($pdo, "SELECT COUNT(*) FROM token_tx t JOIN users u ON u.id = t.user_id WHERE u.role = 'user'"),
        'holdings' => reset_count($pdo, "SELECT COUNT(*) FROM holdings h JOIN users u ON u.id = h.user_id WHERE u.role = 'user'"),
        'holding_lots' => reset_count($pdo, "SELECT COUNT(*) FROM holding_lots h JOIN users u ON u.id = h.user_id WHERE u.role = 'user'"),
        'stock_trades' => reset_count($pdo, "SELECT COUNT(*) FROM stock_trades s JOIN users u ON u.id = s.user_id WHERE u.role = 'user'"),
        'quiz_attempts' => reset_count($pdo, "SELECT COUNT(*) FROM quiz_attempts q JOIN users u ON u.id = q.user_id WHERE u.role = 'user'"),
        'bank_loans' => reset_count($pdo, "SELECT COUNT(*) FROM bank_loans l JOIN users u ON u.id = l.user_id WHERE u.role = 'user'"),
        'legacy_loans' => reset_count($pdo, "SELECT COUNT(*) FROM loans l JOIN users u ON u.id = l.user_id WHERE u.role = 'user'"),
        'spend_requests' => reset_count($pdo, "SELECT COUNT(*) FROM spend_requests s JOIN users u ON u.id = s.user_id WHERE u.role = 'user'"),
        'idempotency_keys' => reset_count($pdo, "SELECT COUNT(*) FROM idempotency_keys i JOIN users u ON u.id = i.user_id WHERE u.role = 'user'"),
        'asset_rank_snapshots' => reset_count($pdo, 'SELECT COUNT(*) FROM asset_rank_snapshots'),
        'trade_news' => reset_count($pdo, "SELECT COUNT(*) FROM news_items WHERE source = 'trade'"),
    );
}

$before = reset_user_counts($pdo);
if (!$apply) {
    echo json_encode(array('ok' => true, 'dry_run' => true, 'before' => $before), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit(0);
}

$lock = $pdo->query("SELECT GET_LOCK('thp_user_market_reset', 30)")->fetchColumn();
if ((int)$lock !== 1) {
    fwrite(STDERR, "无法取得清理锁，请稍后重试\n");
    exit(1);
}

$backup = '';
try {
    $pdo->beginTransaction();
    // 先锁住普通游客行，和买卖/贷款/转让使用的 users 行锁保持一致。
    $pdo->query("SELECT id FROM users WHERE role = 'user' FOR UPDATE")->fetchAll();
    $sections = array(
        'users' => array('sql' => "SELECT * FROM users WHERE role = 'user'", 'params' => array()),
        'token_tx' => array('sql' => "SELECT t.* FROM token_tx t JOIN users u ON u.id = t.user_id WHERE u.role = 'user'", 'params' => array()),
        'holdings' => array('sql' => "SELECT h.* FROM holdings h JOIN users u ON u.id = h.user_id WHERE u.role = 'user'", 'params' => array()),
        'holding_lots' => array('sql' => "SELECT h.* FROM holding_lots h JOIN users u ON u.id = h.user_id WHERE u.role = 'user'", 'params' => array()),
        'stock_trades' => array('sql' => "SELECT s.* FROM stock_trades s JOIN users u ON u.id = s.user_id WHERE u.role = 'user'", 'params' => array()),
        'quiz_attempts' => array('sql' => "SELECT q.* FROM quiz_attempts q JOIN users u ON u.id = q.user_id WHERE u.role = 'user'", 'params' => array()),
        'bank_loans' => array('sql' => "SELECT l.* FROM bank_loans l JOIN users u ON u.id = l.user_id WHERE u.role = 'user'", 'params' => array()),
        'legacy_loans' => array('sql' => "SELECT l.* FROM loans l JOIN users u ON u.id = l.user_id WHERE u.role = 'user'", 'params' => array()),
        'spend_requests' => array('sql' => "SELECT s.* FROM spend_requests s JOIN users u ON u.id = s.user_id WHERE u.role = 'user'", 'params' => array()),
        'idempotency_keys' => array('sql' => "SELECT i.* FROM idempotency_keys i JOIN users u ON u.id = i.user_id WHERE u.role = 'user'", 'params' => array()),
        'asset_rank_snapshots' => array('sql' => 'SELECT * FROM asset_rank_snapshots', 'params' => array()),
        'trade_news' => array('sql' => "SELECT * FROM news_items WHERE source = 'trade'", 'params' => array()),
        'trade_news_reads' => array('sql' => "SELECT r.* FROM news_reads r JOIN news_items n ON n.id = r.news_id WHERE n.source = 'trade'", 'params' => array()),
    );
    $backup = thp_admin_backup_json('user-market-reset', $sections);

    $pdo->exec("DELETE t FROM token_tx t JOIN users u ON u.id = t.user_id WHERE u.role = 'user'");
    $pdo->exec("DELETE h FROM holdings h JOIN users u ON u.id = h.user_id WHERE u.role = 'user'");
    $pdo->exec("DELETE h FROM holding_lots h JOIN users u ON u.id = h.user_id WHERE u.role = 'user'");
    $pdo->exec("DELETE s FROM stock_trades s JOIN users u ON u.id = s.user_id WHERE u.role = 'user'");
    $pdo->exec("DELETE q FROM quiz_attempts q JOIN users u ON u.id = q.user_id WHERE u.role = 'user'");
    $pdo->exec("DELETE l FROM bank_loans l JOIN users u ON u.id = l.user_id WHERE u.role = 'user'");
    $pdo->exec("DELETE l FROM loans l JOIN users u ON u.id = l.user_id WHERE u.role = 'user'");
    $pdo->exec("DELETE s FROM spend_requests s JOIN users u ON u.id = s.user_id WHERE u.role = 'user'");
    $pdo->exec("DELETE i FROM idempotency_keys i JOIN users u ON u.id = i.user_id WHERE u.role = 'user'");
    $pdo->exec('DELETE FROM asset_rank_snapshots');
    $pdo->exec("DELETE r FROM news_reads r JOIN news_items n ON n.id = r.news_id WHERE n.source = 'trade'");
    $pdo->exec("DELETE FROM news_items WHERE source = 'trade'");
    $pdo->exec("UPDATE users SET balance = 0, debt = 0 WHERE role = 'user'");
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $pdo->query("SELECT RELEASE_LOCK('thp_user_market_reset')")->fetchColumn();
    fwrite(STDERR, '清理失败，数据库已回滚：' . $e->getMessage() . "\n");
    exit(1);
}
$pdo->query("SELECT RELEASE_LOCK('thp_user_market_reset')")->fetchColumn();

foreach (glob(THP_DATA . '/cache/*.json') ?: array() as $file) { @unlink($file); }
$after = reset_user_counts($pdo);
$sameCompanies = (int)$before['companies'] === (int)$after['companies'];
$clean = (int)$after['users_with_balance'] === 0
    && (int)$after['users_with_debt'] === 0
    && (int)$after['token_tx'] === 0
    && (int)$after['holdings'] === 0
    && (int)$after['holding_lots'] === 0
    && (int)$after['stock_trades'] === 0
    && (int)$after['quiz_attempts'] === 0
    && (int)$after['bank_loans'] === 0
    && (int)$after['legacy_loans'] === 0
    && (int)$after['spend_requests'] === 0
    && (int)$after['idempotency_keys'] === 0
    && (int)$after['asset_rank_snapshots'] === 0
    && (int)$after['trade_news'] === 0
    && $sameCompanies;

echo json_encode(array(
    'ok' => $clean,
    'dry_run' => false,
    'backup' => $backup,
    'before' => $before,
    'after' => $after,
    'companies_preserved' => $sameCompanies,
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
if (!$clean) { exit(2); }

