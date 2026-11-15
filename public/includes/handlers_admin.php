<?php
declare(strict_types=1);
if (!defined('THP_APP')) { http_response_code(403); exit('forbidden'); }

// ===================== 概览数据 =====================
function thp_act_admin_stats()
{
    global $pdo;
    thp_require_admin_perm('stats');
    $out = array();
    $out['user_count'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user' AND is_test = 0")->fetchColumn();
    $out['admin_count'] = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role = ' . $pdo->quote('admin'))->fetchColumn();
    $out['test_count'] = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_test = 1')->fetchColumn();
    $out['banned_count'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE banned = 1 AND role = 'user'")->fetchColumn();
    $out['balance_sum'] = (int)$pdo->query('SELECT COALESCE(SUM(balance), 0) FROM users')->fetchColumn();
    $out['debt_sum'] = (int)$pdo->query('SELECT COALESCE(SUM(debt), 0) FROM users')->fetchColumn();
    $out['new_today'] = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE created_at >= CURDATE()')->fetchColumn();
    $out['tx_today'] = (int)$pdo->query('SELECT COUNT(*) FROM token_tx WHERE created_at >= CURDATE()')->fetchColumn();
    $out['pending_issue'] = (int)$pdo->query('SELECT COUNT(*) FROM issue_codes WHERE status = ' . $pdo->quote('pending'))->fetchColumn();
    $out['pending_spend'] = (int)$pdo->query('SELECT COUNT(*) FROM spend_requests WHERE status = ' . $pdo->quote('pending'))->fetchColumn();
    $out['question_count'] = (int)$pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn();
    $out['story_count'] = (int)$pdo->query('SELECT COUNT(*) FROM stories')->fetchColumn();
    $out['company_count'] = (int)$pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
    try {
        $out['risk_24h'] = (int)$pdo->query('SELECT COUNT(*) FROM risk_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)')->fetchColumn();
    } catch (Throwable $e) {
        $out['risk_24h'] = 0;
    }
    $st = $pdo->query('SELECT username, created_at FROM users ORDER BY id DESC LIMIT 6');
    $out['recent_users'] = $st->fetchAll();
    $st = $pdo->query('SELECT l.action, l.detail, l.created_at, u.username FROM admin_logs l LEFT JOIN users u ON u.id = l.admin_id ORDER BY l.id DESC LIMIT 12');
    $out['recent_logs'] = $st->fetchAll();
    return $out;
}

// ===================== 用户管理 =====================
function thp_act_admin_users()
{
    global $pdo;
    thp_require_admin_perm('users');
    $q = trim((string)thp_param('q'));
    $page = max(1, (int)thp_param('page', 1));
    $per = 20;
    $where = '';
    $args = array();
    if ($q !== '') {
        $where = ' WHERE username LIKE ? OR CAST(id AS CHAR) = ?';
        $args[] = '%' . $q . '%';
        $args[] = $q;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM users' . $where);
    $st->execute($args);
    $total = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT id, username, role, admin_scope, balance, debt, banned, is_test, created_at, last_login_at, ' .
        '(SELECT COUNT(*) FROM risk_events re WHERE re.user_id = users.id AND re.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS risk_24h ' .
        'FROM users' . $where . ' ORDER BY id DESC LIMIT ' . (($page - 1) * $per) . ', ' . $per);
    $st->execute($args);
    $rows = array();
    foreach ($st->fetchAll() as $r) {
        $r['id'] = (int)$r['id'];
        $r['balance'] = (int)$r['balance'];
        $r['debt'] = (int)$r['debt'];
        $r['banned'] = (int)$r['banned'];
        $r['is_test'] = (int)$r['is_test'];
        $r['risk_24h'] = (int)$r['risk_24h'];
        $rows[] = $r;
    }
    return array('total' => $total, 'page' => $page, 'per' => $per, 'items' => $rows);
}

function thp_act_admin_user_update()
{
    global $pdo;
    $admin = thp_require_admin_perm('users_manage');
    $uid = (int)thp_param('user_id');
    $op = (string)thp_param('op');
    $value = thp_param('value');
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute(array($uid));
    $target = $st->fetch();
    if (!$target) { throw new ThpError('用户不存在'); }
    if ((string)$target['role'] === 'admin' && (int)$target['id'] !== (int)$admin['id'] && thp_admin_scope($admin) !== 'full') {
        throw new ThpError('只有全权管理员可以调整其他管理员账号', 403);
    }

    if ($op === 'add' || $op === 'set') {
        if (thp_admin_scope($admin) !== 'full') {
            throw new ThpError('只有全权管理员可以调整用户余额', 403);
        }
        $v = (int)$value;
        if ($v < -1000000 || $v > 1000000) { throw new ThpError('数值超出范围'); }
        $pdo->beginTransaction();
        try {
            if ($op === 'add') {
                $newBal = thp_balance_apply($pdo, $uid, $v, 'admin_adjust', (string)$admin['id'], '管理员调整代币（' . $admin['username'] . '）');
            } else {
                $delta = $v - (int)$target['balance'];
                $newBal = thp_balance_apply($pdo, $uid, $delta, 'admin_adjust', (string)$admin['id'], '管理员设置代币为 ' . $v . '（' . $admin['username'] . '）');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ($e instanceof ThpError) { throw $e; }
            throw new ThpError('操作失败，请稍后再试', 500);
        }
        thp_log_admin('调整代币', '用户 ' . $target['username'] . ' (' . $uid . ') -> ' . $newBal . '（操作：' . $op . ' ' . $v . '）');
        return array('balance' => $newBal);
    }
    if ($op === 'ban' || $op === 'unban') {
        if ((int)$target['id'] === (int)$admin['id']) { throw new ThpError('不能封禁自己'); }
        $ban = ($op === 'ban') ? 1 : 0;
        $up = $pdo->prepare('UPDATE users SET banned = ? WHERE id = ?');
        $up->execute(array($ban, $uid));
        if ($ban === 1) { thp_auth_revoke_user_tokens($uid); }
        thp_log_admin($ban ? '封禁用户' : '解封用户', $target['username'] . ' (' . $uid . ')');
        return array('banned' => $ban);
    }
    if ($op === 'test_on' || $op === 'test_off') {
        if (thp_admin_scope($admin) !== 'full') { throw new ThpError('只有全权管理员可以标记测试账号', 403); }
        if ((int)$target['role'] === 'admin') { throw new ThpError('管理员账号不能标记为压测账号'); }
        $isTest = ($op === 'test_on') ? 1 : 0;
        $up = $pdo->prepare('UPDATE users SET is_test = ? WHERE id = ?');
        $up->execute(array($isTest, $uid));
        thp_log_admin($isTest ? '标记测试账号' : '取消测试标记', $target['username'] . ' (' . $uid . ')');
        return array('is_test' => $isTest);
    }
    if ($op === 'clear_loans') {
        if (thp_admin_scope($admin) !== 'full') { throw new ThpError('只有全权管理员可以清理游客贷款数据', 403); }
        if ((string)$target['role'] === 'admin') { throw new ThpError('只能清理游客账号的贷款数据'); }
        $pdo->beginTransaction();
        try {
            $delNew = $pdo->prepare('DELETE FROM bank_loans WHERE user_id = ?');
            $delNew->execute(array($uid));
            $delOld = $pdo->prepare('DELETE FROM loans WHERE user_id = ?');
            $delOld->execute(array($uid));
            $up = $pdo->prepare('UPDATE users SET debt = 0 WHERE id = ?');
            $up->execute(array($uid));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw new ThpError('清理贷款数据失败，数据库已回滚', 500);
        }
        $cleared = (int)$delNew->rowCount() + (int)$delOld->rowCount();
        thp_log_admin('清理游客贷款', $target['username'] . ' (' . $uid . ') 清除 ' . $cleared . ' 条贷款记录；余额未调整');
        return array('ok' => true, 'cleared' => $cleared, 'debt' => 0);
    }
    if ($op === 'role_user' || $op === 'role_admin') {
        if (thp_admin_scope($admin) !== 'full') { throw new ThpError('只有全权管理员可以变更管理员身份', 403); }
        if ((int)$target['id'] === (int)$admin['id'] && $op === 'role_user') { throw new ThpError('不能取消自己的管理员身份'); }
        $role = ($op === 'role_admin') ? 'admin' : 'user';
        $scope = ($role === 'admin') ? 'full' : '';
        $up = $pdo->prepare('UPDATE users SET role = ?, admin_scope = ? WHERE id = ?');
        $up->execute(array($role, $scope, $uid));
        thp_log_admin('调整权限', $target['username'] . ' (' . $uid . ') -> ' . $role);
        return array('role' => $role);
    }
    if ($op === 'set_admin_scope') {
        if (thp_admin_scope($admin) !== 'full') { throw new ThpError('只有全权管理员可以调整权限范围', 403); }
        if ((string)$target['role'] !== 'admin') { throw new ThpError('该账号不是管理员'); }
        $scope = trim((string)$value);
        if (!in_array($scope, array('full', 'finance', 'data'), true)) { throw new ThpError('权限范围不合法'); }
        if ((int)$target['id'] === (int)$admin['id'] && $scope !== 'full') { throw new ThpError('不能降低自己的全权管理员权限'); }
        $up = $pdo->prepare('UPDATE users SET admin_scope = ? WHERE id = ?');
        $up->execute(array($scope, $uid));
        thp_log_admin('调整管理员范围', $target['username'] . ' (' . $uid . ') -> ' . $scope);
        return array('admin_scope' => $scope);
    }
    if ($op === 'reset_password') {
        $pw = (string)$value;
        $pwErr = thp_password_policy_error($pw, (string)$target['role'] === 'admin');
        if ($pwErr !== '') { throw new ThpError($pwErr); }
        $up = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $up->execute(array(password_hash($pw, PASSWORD_DEFAULT), $uid));
        thp_log_admin('重置密码', $target['username'] . ' (' . $uid . ')');
        return array('ok' => true);
    }
    throw new ThpError('未知操作');
}

function thp_act_admin_user_create()
{
    global $pdo;
    $admin = thp_require_admin_perm('users_manage');
    thp_require_throttle('admin-user-create:' . (int)$admin['id'], 20, 60, '创建账号太频繁，请稍后再试');
    $username = trim((string)thp_param('username'));
    $password = (string)thp_param('password');
    $role = ((string)thp_param('role') === 'admin') ? 'admin' : 'user';
    $scope = ($role === 'admin') ? trim((string)thp_param('admin_scope', 'full')) : '';
    if ($scope !== '' && !in_array($scope, array('full', 'finance', 'data'), true)) { throw new ThpError('管理员权限范围不合法'); }
    if ($role === 'admin' && thp_admin_scope($admin) !== 'full') { throw new ThpError('只有全权管理员可以创建管理员账号', 403); }
    if (!thp_valid_username($username)) { throw new ThpError('昵称需为 2-16 位中文 / 字母 / 数字 / 下划线'); }
    // 管理员创建的账号不受设备限制，但昵称仍要遵守违禁词规则。
    thp_check_banned($username, '昵称', array('username' => $username, 'user_id' => (int)$admin['id']));
    $pwErr = thp_password_policy_error($password, $role === 'admin');
    if ($pwErr !== '') { throw new ThpError($pwErr); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $st->execute(array($username));
    if ($st->fetchColumn()) { throw new ThpError('昵称已被使用'); }
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT INTO users (username, password_hash, role, admin_scope, balance, debt, banned, created_at) VALUES (?, ?, ?, ?, 100, 0, 0, ?)');
        $ins->execute(array($username, password_hash($password, PASSWORD_DEFAULT), $role, $scope, thp_now()));
        $uid = (int)$pdo->lastInsertId();
        $ins2 = $pdo->prepare('INSERT INTO token_tx (user_id, delta, balance_after, type, ref, note, created_at) VALUES (?, 100, 100, ?, ?, ?, ?)');
        $ins2->execute(array($uid, 'initial', '', '注册赠送', thp_now()));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw new ThpError('创建失败，请稍后再试', 500);
    }
    thp_log_admin('创建账户', $username . ' -> ' . $role);
    return array('id' => $uid, 'username' => $username, 'role' => $role, 'admin_scope' => $scope);
}

// ===================== 风控 =====================
function thp_act_admin_risk()
{
    global $pdo;
    thp_require_admin_perm('risk');
    $page = max(1, (int)thp_param('page', 1));
    $per = 30;
    $type = trim((string)thp_param('type', ''));
    $userId = (int)thp_param('user_id', 0);
    $where = array('1=1');
    $args = array();
    if ($type !== '') { $where[] = 'r.type = ?'; $args[] = $type; }
    if ($userId > 0) { $where[] = 'r.user_id = ?'; $args[] = $userId; }
    $whereSql = implode(' AND ', $where);
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM risk_events r WHERE ' . $whereSql);
        $st->execute($args);
        $total = (int)$st->fetchColumn();
        $st = $pdo->prepare(
            'SELECT r.id, r.user_id, COALESCE(u.username, r.username) AS username, r.device_key, r.ip, r.type, r.severity, r.detail, r.created_at ' .
            'FROM risk_events r LEFT JOIN users u ON u.id = r.user_id WHERE ' . $whereSql .
            ' ORDER BY r.id DESC LIMIT ' . (($page - 1) * $per) . ', ' . $per
        );
        $st->execute($args);
        $events = $st->fetchAll();
        foreach ($events as &$ev) {
            $ev['id'] = (int)$ev['id'];
            $ev['user_id'] = ($ev['user_id'] === null) ? null : (int)$ev['user_id'];
            $ev['severity'] = (int)$ev['severity'];
        }
        unset($ev);

        $summary = $pdo->query(
            "SELECT " .
            "COALESCE(SUM(created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)), 0) AS events_24h, " .
            "COALESCE(SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)), 0) AS events_7d, " .
            "COALESCE(SUM(type = 'banned_word' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)), 0) AS banned_24h, " .
            "COALESCE(SUM(type = 'throttle_blocked' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)), 0) AS throttle_24h, " .
            "COALESCE(SUM(type = 'device_conflict' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)), 0) AS device_24h, " .
            "COALESCE(SUM(type = 'device_fingerprint_conflict' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)), 0) AS fingerprint_24h, " .
            "COALESCE(SUM(type = 'invalid_code_burst' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)), 0) AS code_24h " .
            "FROM risk_events"
        )->fetch();
        foreach ($summary as $k => $v) { $summary[$k] = (int)$v; }
        $summary['high_users_24h'] = (int)$pdo->query(
            'SELECT COUNT(*) FROM (' .
            'SELECT user_id FROM risk_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND user_id IS NOT NULL ' .
            'GROUP BY user_id HAVING SUM(severity) >= 5' .
            ') t'
        )->fetchColumn();

        $users = $pdo->query(
            'SELECT r.user_id, COALESCE(u.username, MAX(r.username)) AS username, COUNT(*) AS events, SUM(r.severity) AS score, ' .
            'MAX(r.created_at) AS last_at, ' .
            "SUBSTRING_INDEX(GROUP_CONCAT(r.ip ORDER BY r.id DESC SEPARATOR ','), ',', 1) AS last_ip, " .
            "SUBSTRING_INDEX(GROUP_CONCAT(r.device_key ORDER BY r.id DESC SEPARATOR ','), ',', 1) AS last_device " .
            'FROM risk_events r LEFT JOIN users u ON u.id = r.user_id ' .
            'WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ' .
            'GROUP BY r.user_id, u.username ORDER BY score DESC, events DESC, last_at DESC LIMIT 20'
        )->fetchAll();
        foreach ($users as &$row) {
            $row['user_id'] = ($row['user_id'] === null) ? null : (int)$row['user_id'];
            $row['events'] = (int)$row['events'];
            $row['score'] = (int)$row['score'];
        }
        unset($row);
    } catch (Throwable $e) {
        // 数据库尚未执行升级时不要让后台直接白屏
        $total = 0;
        $events = array();
        $users = array();
        $summary = array(
            'events_24h' => 0, 'events_7d' => 0, 'high_users_24h' => 0,
            'banned_24h' => 0, 'throttle_24h' => 0, 'device_24h' => 0,
            'fingerprint_24h' => 0, 'code_24h' => 0,
        );
    }
    return array(
        'summary' => $summary,
        'users' => $users,
        'events' => $events,
        'total' => $total,
        'page' => $page,
        'per' => $per,
    );
}

// ===================== 发币 / 收币 =====================
function thp_act_admin_issue_create()
{
    global $pdo;
    $admin = thp_require_admin_perm('trade');
    $amount = (int)thp_param('amount');
    $count = max(1, min(20, (int)thp_param('count', 1)));
    $note = trim((string)thp_param('note'));
    if ($amount < 1 || $amount > 100000) { throw new ThpError('面额需在 1 - 100000 之间'); }
    thp_require_throttle('admin-issue:' . (int)$admin['id'], 60, 60, '生成太快了');
    $codes = array();
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT INTO issue_codes (code, amount, status, created_by, note, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        for ($i = 0; $i < $count; $i++) {
            $code = thp_gen_code('issue_codes');
            $ins->execute(array($code, $amount, 'pending', (int)$admin['id'], $note, thp_now()));
            $codes[] = $code;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($e instanceof ThpError) { throw $e; }
        throw new ThpError('生成失败，请稍后再试', 500);
    }
    thp_log_admin('生成发币码', '面额 ' . $amount . ' × ' . count($codes));
    return array('amount' => $amount, 'codes' => $codes);
}

function thp_act_admin_issue_list()
{
    global $pdo;
    thp_require_admin_perm('trade');
    $st = $pdo->query('SELECT i.id, i.code, i.amount, i.status, i.note, i.created_at, i.used_at, u.username AS used_by_name FROM issue_codes i LEFT JOIN users u ON u.id = i.used_by ORDER BY i.id DESC LIMIT 120');
    $rows = array();
    foreach ($st->fetchAll() as $r) {
        $r['id'] = (int)$r['id'];
        $r['amount'] = (int)$r['amount'];
        $rows[] = $r;
    }
    return array('items' => $rows);
}

function thp_act_admin_issue_revoke()
{
    global $pdo;
    $admin = thp_require_admin_perm('trade');
    $id = (int)thp_param('id');
    $up = $pdo->prepare('UPDATE issue_codes SET status = ? WHERE id = ? AND status = ?');
    $up->execute(array('revoked', $id, 'pending'));
    if ($up->rowCount() !== 1) { throw new ThpError('该代码不是待使用状态，无法作废'); }
    thp_log_admin('作废发币码', 'ID ' . $id);
    return array('ok' => true);
}

function thp_act_admin_collect()
{
    global $pdo;
    $admin = thp_require_admin_perm('trade');
    thp_require_throttle('admin-collect:' . (int)$admin['id'], 30, 60, '收币操作太频繁，请稍后再试');
    $code = preg_replace('/[^0-9]/', '', (string)thp_param('code'));
    if (strlen($code) !== 6) { throw new ThpError('请输入正确的六位代码'); }
    $row = thp_spend_settle($code);
    if (!$row) { throw new ThpError('没有找到这个支付码'); }
    if ((string)$row['status'] === 'used') { throw new ThpError('这个支付码已经收过款了（' . $row['used_at'] . '）'); }
    if ((string)$row['status'] === 'expired') { throw new ThpError('这个支付码已超时，代币已自动退回用户'); }
    if ((string)$row['status'] === 'cancelled') { throw new ThpError('这个支付码已被用户取消'); }
    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare('UPDATE spend_requests SET status = ?, used_at = ?, used_by_admin = ? WHERE id = ? AND status = ?');
        $up->execute(array('used', thp_now(), (int)$admin['id'], (int)$row['id'], 'pending'));
        if ($up->rowCount() !== 1) { throw new ThpError('这个支付码刚刚已被处理，请刷新查看'); }
        $st = $pdo->prepare('SELECT username, balance FROM users WHERE id = ?');
        $st->execute(array((int)$row['user_id']));
        $owner = $st->fetch();
        thp_tx_note($pdo, (int)$row['user_id'], 'spend_used', $code, '管理员已收款（' . $admin['username'] . '）', (int)$owner['balance']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($e instanceof ThpError) { throw $e; }
        throw new ThpError('收款失败，请稍后再试', 500);
    }
    thp_log_admin('收款', '用户 ' . $owner['username'] . ' 支付 ' . (int)$row['amount'] . ' 代币（码 ' . $code . '）');
    return array('username' => (string)$owner['username'], 'amount' => (int)$row['amount'], 'code' => $code);
}

// ===================== 题库 / 故事 =====================
function thp_act_admin_question_list()
{
    global $pdo;
    thp_require_admin_perm('content');
    $st = $pdo->query('SELECT q.*, (SELECT COUNT(*) FROM quiz_attempts a WHERE a.question_id = q.id) AS attempt_count, (SELECT COUNT(*) FROM quiz_attempts a WHERE a.question_id = q.id AND a.correct = 1) AS correct_count FROM questions q ORDER BY q.id DESC');
    $rows = array();
    foreach ($st->fetchAll() as $r) {
        $options = json_decode((string)$r['options'], true);
        $rows[] = array(
            'id' => (int)$r['id'],
            'code' => (string)$r['code'],
            'title' => (string)$r['title'],
            'body' => (string)$r['body'],
            'options' => is_array($options) ? array_values($options) : array(),
            'correct_index' => (int)$r['correct_index'],
            'reward' => (int)$r['reward'],
            'image' => (string)$r['image'],
            'enabled' => (int)$r['enabled'],
            'attempt_count' => (int)$r['attempt_count'],
            'correct_count' => (int)$r['correct_count'],
            'updated_at' => (string)$r['updated_at'],
        );
    }
    return array('items' => $rows);
}

function thp_act_admin_question_save()
{
    global $pdo;
    $admin = thp_require_admin_perm('content');
    $id = (int)thp_param('id');
    $title = trim((string)thp_param('title'));
    $body = trim((string)thp_param('body'));
    $image = trim((string)thp_param('image'));
    $reward = (int)thp_param('reward');
    $enabled = ((int)thp_param('enabled', 1) === 1) ? 1 : 0;
    $optionsRaw = thp_param('options', array());
    $options = array();
    if (is_array($optionsRaw)) {
        foreach ($optionsRaw as $o) {
            $o = trim((string)$o);
            if ($o !== '') { $options[] = mb_substr($o, 0, 120); }
        }
    }
    $correct = (int)thp_param('correct_index');
    if ($title === '' || mb_strlen($title) > 64) { throw new ThpError('题目标题需要 1-64 个字'); }
    if ($body === '') { throw new ThpError('请填写题目内容'); }
    if (count($options) < 2) { throw new ThpError('至少需要 2 个选项'); }
    if (count($options) > 6) { throw new ThpError('最多 6 个选项'); }
    if ($correct < 0 || $correct >= count($options)) { throw new ThpError('正确选项下标不合法'); }
    if ($reward < 1 || $reward > 10000) { throw new ThpError('奖励代币需在 1 - 10000 之间'); }
    if ($image !== '' && !preg_match('#^(https?://|/)#', $image)) { throw new ThpError('图片地址不合法'); }
    $optionsJson = json_encode($options, JSON_UNESCAPED_UNICODE);
    if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM questions WHERE id = ?');
        $st->execute(array($id));
        if (!$st->fetch()) { throw new ThpError('题目不存在'); }
        $up = $pdo->prepare('UPDATE questions SET title = ?, body = ?, options = ?, correct_index = ?, reward = ?, image = ?, enabled = ?, updated_at = ? WHERE id = ?');
        $up->execute(array($title, $body, $optionsJson, $correct, $reward, $image, $enabled, thp_now(), $id));
        thp_log_admin('修改题目', $title . ' (#' . $id . ')');
    } else {
        $code = thp_gen_code('questions');
        $ins = $pdo->prepare('INSERT INTO questions (code, title, body, options, correct_index, reward, image, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute(array($code, $title, $body, $optionsJson, $correct, $reward, $image, $enabled, thp_now(), thp_now()));
        $id = (int)$pdo->lastInsertId();
        thp_log_admin('新建题目', $title . ' 代码 ' . $code);
    }
    $st = $pdo->prepare('SELECT code FROM questions WHERE id = ?');
    $st->execute(array($id));
    return array('id' => $id, 'code' => (string)$st->fetchColumn());
}

function thp_act_admin_question_delete()
{
    global $pdo;
    $admin = thp_require_admin_perm('content');
    $id = (int)thp_param('id');
    $st = $pdo->prepare('SELECT title FROM questions WHERE id = ?');
    $st->execute(array($id));
    $title = $st->fetchColumn();
    if ($title === false) { throw new ThpError('题目不存在'); }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM quiz_attempts WHERE question_id = ?')->execute(array($id));
        $pdo->prepare('DELETE FROM questions WHERE id = ?')->execute(array($id));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw new ThpError('删除失败', 500);
    }
    thp_log_admin('删除题目', (string)$title . ' (#' . $id . ')');
    return array('ok' => true);
}

function thp_act_admin_story_list()
{
    global $pdo;
    thp_require_admin_perm('content');
    $st = $pdo->query('SELECT s.*, (SELECT COUNT(*) FROM user_stories us WHERE us.story_id = s.id) AS unlock_count FROM stories s ORDER BY s.id DESC');
    $rows = array();
    foreach ($st->fetchAll() as $r) {
        $images = json_decode((string)$r['images'], true);
        $rows[] = array(
            'id' => (int)$r['id'],
            'code' => (string)$r['code'],
            'title' => (string)$r['title'],
            'body' => (string)$r['body'],
            'images' => is_array($images) ? array_values($images) : array(),
            'enabled' => (int)$r['enabled'],
            'unlock_count' => (int)$r['unlock_count'],
            'updated_at' => (string)$r['updated_at'],
        );
    }
    return array('items' => $rows);
}

function thp_act_admin_story_save()
{
    global $pdo;
    $admin = thp_require_admin_perm('content');
    $id = (int)thp_param('id');
    $title = trim((string)thp_param('title'));
    $body = trim((string)thp_param('body'));
    $enabled = ((int)thp_param('enabled', 1) === 1) ? 1 : 0;
    $imagesRaw = thp_param('images', array());
    $images = array();
    if (is_array($imagesRaw)) {
        foreach ($imagesRaw as $img) {
            $img = trim((string)$img);
            if ($img === '') { continue; }
            if (!preg_match('#^(https?://|/)#', $img)) { throw new ThpError('图片地址不合法：' . $img); }
            $images[] = mb_substr($img, 0, 255);
            if (count($images) >= 6) { break; }
        }
    }
    if ($title === '' || mb_strlen($title) > 64) { throw new ThpError('故事标题需要 1-64 个字'); }
    if ($body === '') { throw new ThpError('请填写故事内容'); }
    $imagesJson = json_encode($images, JSON_UNESCAPED_UNICODE);
    if ($id > 0) {
        $st = $pdo->prepare('SELECT id FROM stories WHERE id = ?');
        $st->execute(array($id));
        if (!$st->fetchColumn()) { throw new ThpError('故事不存在'); }
        $up = $pdo->prepare('UPDATE stories SET title = ?, body = ?, images = ?, enabled = ?, updated_at = ? WHERE id = ?');
        $up->execute(array($title, $body, $imagesJson, $enabled, thp_now(), $id));
        thp_log_admin('修改故事', $title . ' (#' . $id . ')');
    } else {
        $code = thp_gen_code('stories');
        $ins = $pdo->prepare('INSERT INTO stories (code, title, body, images, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $ins->execute(array($code, $title, $body, $imagesJson, $enabled, thp_now(), thp_now()));
        $id = (int)$pdo->lastInsertId();
        thp_log_admin('新建故事', $title . ' 代码 ' . $code);
    }
    $st = $pdo->prepare('SELECT code FROM stories WHERE id = ?');
    $st->execute(array($id));
    return array('id' => $id, 'code' => (string)$st->fetchColumn());
}

function thp_act_admin_story_delete()
{
    global $pdo;
    $admin = thp_require_admin_perm('content');
    $id = (int)thp_param('id');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM user_stories WHERE story_id = ?')->execute(array($id));
        $pdo->prepare('DELETE FROM stories WHERE id = ?')->execute(array($id));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw new ThpError('删除失败', 500);
    }
    thp_log_admin('删除故事', '#' . $id);
    return array('ok' => true);
}

// ===================== 图片上传 =====================
function thp_act_admin_upload()
{
    thp_require_admin_perm('content');
    if (empty($_FILES['file']) || !is_array($_FILES['file'])) { throw new ThpError('没有收到文件'); }
    $f = $_FILES['file'];
    if ((int)$f['error'] !== 0) { throw new ThpError('上传失败（错误码 ' . (int)$f['error'] . '）'); }
    if ((int)$f['size'] > 5 * 1024 * 1024) { throw new ThpError('图片不能超过 5MB'); }
    $info = @getimagesize($f['tmp_name']);
    if (!$info || empty($info['mime'])) { throw new ThpError('这不是有效的图片文件'); }
    $map = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp');
    if (!isset($map[$info['mime']])) { throw new ThpError('仅支持 jpg / png / gif / webp 图片'); }
    if (!is_dir(THP_UPLOADS)) { @mkdir(THP_UPLOADS, 0775, true); }
    $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $map[$info['mime']];
    $dest = THP_UPLOADS . '/' . $name;
    if (!move_uploaded_file($f['tmp_name'], $dest)) { throw new ThpError('保存图片失败，请检查 uploads 目录权限'); }
    @chmod($dest, 0644);
    return array('url' => '/uploads/' . $name);
}

// ===================== 大盘管理（旧走势文件实现，保留作迁移参考，不参与运行） =====================
if (false) {
function thp_act_admin_market_info()
{
    thp_require_admin_perm('market');
    $load = thp_trend_load();
    $data = $load['data'];
    $holderMap = thp_company_holders();
    $allowedRanges = array(10, 30, 60, 120, 180, 300, 720);
    $rangeMinutes = (int)thp_param('range', 60);
    if (!in_array($rangeMinutes, $allowedRanges, true)) { $rangeMinutes = 60; }
    $tick = thp_tick_of_day();
    $rangeTicks = (int)($rangeMinutes * 60 / THP_TICK_SECONDS);
    $from = max(0, $tick - $rangeTicks);
    $stats = thp_trend_stats($data, $from, $tick);
    $trend = array();
    foreach ((isset($data['companies']) ? $data['companies'] : array()) as $id => $path) {
        if (is_array($path)) { $trend[(string)$id] = array_slice($path, $from, $tick - $from + 1); }
    }
    $indexSeries = array();
    for ($m = $from; $m <= $tick; $m++) { $indexSeries[] = thp_index_at($data, $m); }
    $indexOpen = count($indexSeries) ? (int)$indexSeries[0] : 1000;
    $indexNow = count($indexSeries) ? (int)$indexSeries[count($indexSeries) - 1] : 1000;
    $activeStyleId = (isset($data['style']) && $data['style'] !== '') ? (string)$data['style'] : 'mild';
    $activeStyleMeta = thp_trend_style_get($activeStyleId);
    $activeStyleName = isset($data['style_name']) ? (string)$data['style_name'] : '';
    if ($activeStyleName === '' && $activeStyleMeta !== null) { $activeStyleName = (string)$activeStyleMeta['name']; }
    if ($activeStyleName === '' && isset($data['style_profile']['name'])) { $activeStyleName = (string)$data['style_profile']['name']; }
    if ($activeStyleName === '') { $activeStyleName = $activeStyleId; }
    $out = array(
        'active' => array(
            'slug' => isset($load['slug']) ? (string)$load['slug'] : '',
            'name' => isset($load['name']) ? (string)$load['name'] : '',
            'used' => isset($load['source']) ? (string)$load['source'] : '',
            'style' => $activeStyleId,
            'style_name' => $activeStyleName,
            'source' => isset($data['source']) ? (string)$data['source'] : '',
            'generated_at' => isset($data['generated_at']) ? (string)$data['generated_at'] : '',
            'warning' => isset($load['warning']) ? (string)$load['warning'] : '',
        ),
        'styles' => thp_trend_styles(),
        'files' => thp_trend_list(),
        'tick' => $tick,
        'tick_from' => $from,
        'tick_seconds' => THP_TICK_SECONDS,
        'view_range_minutes' => $rangeMinutes,
        'trend' => $trend,
        'index' => array(
            'value' => $indexNow,
            'prev' => thp_index_at($data, max(0, $tick - 1)),
            'change_percent' => $indexOpen > 0 ? round(($indexNow - $indexOpen) * 100 / $indexOpen, 1) : 0,
            'series' => $indexSeries,
        ),
        'companies' => array(),
    );
    foreach (thp_companies() as $c) {
        $id = (string)$c['id'];
        $out['companies'][] = array(
            'id' => (int)$c['id'],
            'name' => (string)$c['name'],
            'holders' => isset($holderMap[$id]) ? (int)$holderMap[$id] : 0,
            'is_default' => ((int)$c['is_default'] === 1),
            'stat' => isset($stats[$id]) ? $stats[$id] : null,
        );
    }
    // 股市盈亏排行榜：赚钱最多 / 亏钱最多各前 5（累计盈亏 = 已落袋 + 持仓浮动）
    $out['rank'] = thp_stock_pnl_rank($data, (int)$out['tick'], 5);
    return $out;
}

// 导出走势数据（直接输出 JSON 文件下载）；可指定文件，默认导出当前生效的
function thp_act_admin_market_export()
{
    thp_require_admin_perm('market');
    $slug = trim((string)thp_param('slug'));
    if ($slug === '' || !thp_trend_valid_slug($slug)) { $slug = thp_trend_active_slug(); }
    $data = thp_trend_read_file(thp_trend_path($slug));
    $err = ($data === null) ? '文件不存在或无法解析' : thp_trend_validate($data);
    if ($err !== null) { throw new ThpError('走势文件不可用：' . $err); }
    // 导出时附上服务器记录的真实持有人数，供 AI 参考走势；导入时会忽略这个字段
    $data['holders'] = thp_company_holders();
    $tt = array();
    foreach ($data['companies'] as $cid => $path) {
        $tt[(string)$cid] = array(
            'open' => thp_price_norm($path[0]),
            'close' => thp_price_norm($path[count($path) - 1]),
            'min' => thp_price_norm(min($path)),
            'max' => thp_price_norm(max($path)),
        );
    }
    $data['summary'] = $tt;
    $ascii = preg_replace('/[^A-Za-z0-9_\-]+/', '-', $slug);
    if ($ascii === '' || $ascii === null) { $ascii = 'trend'; }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="trend-' . $ascii . '.json"');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 导入一份走势 JSON：保存成一份新文件，不会自动切换生效
function thp_act_admin_market_import()
{
    $admin = thp_require_admin_perm('market');
    $raw = '';
    $maxBytes = 20 * 1024 * 1024; // 20MB：正常走势文件远小于这个体积，超过直接拒绝，防止拖垮服务器
    if (!empty($_FILES['file']) && is_array($_FILES['file']) && (int)$_FILES['file']['error'] === 0) {
        if ((int)$_FILES['file']['size'] > $maxBytes) { throw new ThpError('导入失败：文件超过 20MB，不是正常的走势文件吧'); }
        $raw = (string)file_get_contents($_FILES['file']['tmp_name']);
    } else {
        $dataParam = thp_param('data');
        if (is_array($dataParam)) {
            $parsed = $dataParam;
        } else {
            $raw = (string)$dataParam;
            $parsed = null;
        }
    }
    if (strlen($raw) > $maxBytes) { throw new ThpError('导入失败：内容超过 20MB，不是正常的走势文件吧'); }
    if (!isset($parsed) || $parsed === null) {
        if ($raw === '') { throw new ThpError('没有收到走势数据（请选择 JSON 文件或粘贴内容）'); }
        $parsed = json_decode($raw, true);
    }
    if (!is_array($parsed)) { throw new ThpError('导入失败：内容不是合法的 JSON，原走势文件未受影响'); }
    $err = thp_trend_validate($parsed);
    if ($err !== null) { throw new ThpError('导入失败：' . $err . '（原走势文件未受影响）'); }
    // 导入只取走势本身；holders / summary 等字段一律忽略，以服务器实际记录为准
    unset($parsed['holders'], $parsed['summary']);
    $name = trim((string)thp_param('name'));
    if ($name === '' && isset($parsed['name'])) { $name = trim((string)$parsed['name']); }
    if ($name === '') { $name = '导入 ' . date('m-d H:i'); }
    $slug = thp_trend_create($name, $parsed, 'import');
    if ($slug === null) { throw new ThpError('导入失败：无法写入走势文件，请检查 data/trends 目录权限'); }
    thp_log_admin('导入走势文件', $name . '（' . $slug . '）');
    return array('ok' => true, 'slug' => $slug, 'name' => $name, 'companies' => count($parsed['companies']), 'activated' => false);
}

// 生成一份新的走势文件（本地随机 / AI），不会自动切换生效
function thp_act_admin_market_generate()
{
    $admin = thp_require_admin_perm('market');
    $mode = (string)thp_param('mode');
    $style = trim((string)thp_param('style'));
    $styleMeta = thp_trend_style_get($style);
    if ($styleMeta === null) { throw new ThpError('选择的走势风格不存在，请刷新页面后重试'); }
    $prompt = trim((string)thp_param('prompt'));
    $name = trim((string)thp_param('name'));
    $companies = thp_companies();
    $params = null;
    $comment = '';
    $aiError = '';
    if ($mode === 'api' || $mode === 'auto') {
        $params = thp_ai_gen_params($companies, $style, $prompt, $aiError);
        if ($params === null) {
            if ($mode === 'api') { throw new ThpError('AI 生成失败：' . $aiError); }
        } else {
            if (strpos($aiError, '__comment__:') === 0) { $comment = substr($aiError, 12); }
        }
    }
    $trend = thp_trend_build($companies, $params, $style, ($name !== '' ? $name : $style));
    $err = thp_trend_validate($trend);
    if ($err !== null) { throw new ThpError('生成结果校验失败：' . $err); }
    if ($name === '') { $name = (string)$styleMeta['name'] . ' ' . date('m-d H:i'); }
    $slug = thp_trend_create($name, $trend, ($params !== null ? 'api' : 'local'), $prompt);
    if ($slug === null) { throw new ThpError('保存走势文件失败，请检查 data/trends 目录权限'); }
    thp_log_admin('生成走势文件', $name . '（' . $slug . '，风格 ' . $style . '，模式 ' . $mode . '）' . ($comment !== '' ? ' AI评语：' . $comment : ''));
    return array(
        'ok' => true,
        'slug' => $slug,
        'name' => $name,
        'style' => $style,
        'source' => ($params !== null ? 'api' : 'local'),
        'comment' => $comment,
        'ai_error' => ($params !== null ? '' : $aiError),
        'activated' => false,
        'stats' => thp_trend_stats($trend),
    );
}

// 生成预测览：临时文件不会出现在走势列表，确认后再正式保存。
function thp_act_admin_market_preview()
{
    global $pdo;
    $admin = thp_require_admin_perm('market');
    thp_require_throttle('market-preview:' . (int)$admin['id'], 8, 60, '走势预览生成太频繁，请稍后再试');
    $mode = (string)thp_param('mode');
    $style = trim((string)thp_param('style'));
    $styleMeta = thp_trend_style_get($style);
    if ($styleMeta === null) { throw new ThpError('选择的走势风格不存在，请刷新页面后重试'); }
    if ($mode !== 'api' && $mode !== 'local') { throw new ThpError('生成模式不合法'); }
    $prompt = trim((string)thp_param('prompt'));
    if (mb_strlen($prompt) > 800) { throw new ThpError('提示词请控制在 800 字以内'); }
    $name = trim((string)thp_param('name'));
    $companies = thp_companies();
    $params = null;
    $comment = '';
    $aiError = '';
    if ($mode === 'api') {
        $params = thp_ai_gen_params($companies, $style, $prompt, $aiError);
        if ($params === null) { throw new ThpError('AI 生成失败：' . $aiError); }
        if (strpos($aiError, '__comment__:') === 0) { $comment = substr($aiError, 12); }
    }
    $trend = thp_trend_build($companies, $params, $style, ($name !== '' ? $name : $style));
    $err = thp_trend_validate($trend);
    if ($err !== null) { throw new ThpError('生成结果校验失败：' . $err); }
    if ($name === '') { $name = (string)$styleMeta['name'] . ' ' . date('m-d H:i'); }
    $trend['name'] = $name;
    $trend['source'] = ($params !== null ? 'api' : 'local');
    $trend['prompt'] = $prompt;
    $trend['created_at'] = thp_now();
    $dir = THP_DATA . '/previews';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    if (!is_dir($dir)) { throw new ThpError('预览目录创建失败，请检查 data 目录权限', 500); }
    foreach (glob($dir . '/*.php') ?: array() as $old) {
        if (@filemtime($old) < time() - 3600) { @unlink($old); }
    }
    $token = bin2hex(random_bytes(16));
    $file = $dir . '/' . $token . '.php';
    if (!thp_trend_save($file, $trend)) { throw new ThpError('保存预览失败，请检查 data/previews 权限', 500); }
    return array(
        'ok' => true,
        'preview_token' => $token,
        'name' => $name,
        'style' => $style,
        'style_name' => (string)$styleMeta['name'],
        'source' => ($params !== null ? 'api' : 'local'),
        'comment' => $comment,
        'stats' => thp_trend_stats($trend),
        'expires_in' => 3600,
    );
}

function thp_act_admin_market_preview_save()
{
    global $pdo;
    $admin = thp_require_admin_perm('market');
    $token = trim((string)thp_param('preview_token'));
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) { throw new ThpError('预览编号不合法'); }
    $file = THP_DATA . '/previews/' . $token . '.php';
    $trend = thp_trend_read_file($file);
    if ($trend === null) { throw new ThpError('预览已过期或不存在，请重新生成'); }
    $err = thp_trend_validate($trend);
    if ($err !== null) { throw new ThpError('预览数据损坏：' . $err); }
    $name = trim((string)thp_param('name'));
    if ($name === '') { $name = isset($trend['name']) ? trim((string)$trend['name']) : ''; }
    if ($name === '') { $name = '走势 ' . date('m-d H:i'); }
    $source = isset($trend['source']) ? (string)$trend['source'] : 'local';
    $prompt = isset($trend['prompt']) ? (string)$trend['prompt'] : '';
    $slug = thp_trend_create($name, $trend, $source, $prompt);
    if ($slug === null) { throw new ThpError('正式保存失败，请检查 data/trends 目录权限'); }
    @unlink($file);
    thp_log_admin('保存走势预览', $name . '（' . $slug . '，来源 ' . $source . '）');
    return array('ok' => true, 'slug' => $slug, 'name' => $name, 'source' => $source, 'activated' => false);
}

function thp_act_admin_market_preview_cancel()
{
    $admin = thp_require_admin_perm('market');
    $token = trim((string)thp_param('preview_token'));
    if (preg_match('/^[a-f0-9]{32}$/', $token)) { @unlink(THP_DATA . '/previews/' . $token . '.php'); }
    return array('ok' => true);
}

// 新建自定义走势风格（保存在设置里，不需要数据库迁移）
function thp_act_admin_market_style_save()
{
    thp_require_admin_perm('market');
    $id = trim((string)thp_param('id'));
    $args = array(
        (string)thp_param('name'),
        (int)thp_param('max_step'),
        (int)thp_param('change_min'),
        (int)thp_param('change_max'),
        (int)thp_param('volatility'),
    );
    if ($id !== '') {
        $style = call_user_func_array('thp_trend_custom_style_update', array_merge(array($id), $args));
        thp_log_admin('编辑走势风格', $style['name'] . '（' . $style['id'] . '）');
    } else {
        $style = call_user_func_array('thp_trend_custom_style_create', $args);
        thp_log_admin('新建走势风格', $style['name'] . '（' . $style['id'] . '）');
    }
    return array('ok' => true, 'style' => $style, 'styles' => thp_trend_styles());
}

function thp_act_admin_market_style_import()
{
    thp_require_admin_perm('market');
    $raw = (string)thp_param('data');
    if ($raw === '' && !empty($_FILES['file']['tmp_name'])) {
        if ((int)$_FILES['file']['size'] > 256 * 1024) { throw new ThpError('风格文件不能超过 256KB'); }
        $raw = (string)file_get_contents($_FILES['file']['tmp_name']);
    }
    if ($raw === '') { throw new ThpError('没有收到风格文件内容'); }
    if (strlen($raw) > 256 * 1024) { throw new ThpError('风格文件不能超过 256KB'); }
    $payload = json_decode($raw, true);
    if (!is_array($payload)) { throw new ThpError('导入失败：不是合法的 JSON 文件'); }
    $created = thp_trend_custom_styles_import($payload);
    $names = array_map(function ($s) { return $s['name']; }, $created);
    thp_log_admin('导入走势风格', implode('、', $names));
    return array('ok' => true, 'created' => $created, 'styles' => thp_trend_styles());
}

function thp_act_admin_market_style_delete()
{
    thp_require_admin_perm('market');
    $id = trim((string)thp_param('id'));
    thp_trend_custom_style_delete($id);
    thp_log_admin('删除走势风格', $id);
    return array('ok' => true, 'deleted_id' => $id, 'styles' => thp_trend_styles());
}

// 手动切换当前生效的走势文件
function thp_act_admin_market_switch()
{
    thp_require_admin_perm('market');
    $slug = trim((string)thp_param('slug'));
    if (!thp_trend_valid_slug($slug)) { throw new ThpError('文件名不合法'); }
    if (!is_file(thp_trend_path($slug))) { throw new ThpError('这个走势文件不存在'); }
    $data = thp_trend_read_file(thp_trend_path($slug));
    $err = ($data === null) ? '文件无法解析' : thp_trend_validate($data);
    if ($err !== null) { throw new ThpError('不能切换到损坏的走势文件：' . $err); }
    thp_trend_set_active($slug);
    $name = (isset($data['name']) && $data['name'] !== '') ? (string)$data['name'] : $slug;
    thp_log_admin('切换走势文件', $name . '（' . $slug . '）');
    return array('ok' => true, 'slug' => $slug, 'name' => $name);
}

// 删除一份走势文件（内置默认与正在生效的不能删）
function thp_act_admin_market_delete()
{
    thp_require_admin_perm('market');
    $slug = trim((string)thp_param('slug'));
    if (!thp_trend_valid_slug($slug)) { throw new ThpError('文件名不合法'); }
    if ($slug === 'default') { throw new ThpError('内置默认走势不能删除'); }
    if (is_file(thp_trend_path($slug)) && $slug === thp_trend_active_slug()) { throw new ThpError('这份走势正在生效中，请先切换到其它文件再删除'); }
    if (!thp_trend_delete($slug)) { throw new ThpError('删除失败，文件不存在或没有权限'); }
    thp_log_admin('删除走势文件', $slug);
    return array('ok' => true);
}

function thp_act_admin_company_rename()
{
    thp_require_admin_perm('market');
    $r = thp_company_rename((int)thp_param('company_id'), (string)thp_param('name'));
    return $r;
}

} // end legacy trend-file market handlers

// ===================== 设置 =====================
function thp_act_admin_settings_get()
{
    thp_require_admin_perm('settings');
    $cfg = thp_ai_config();
    $rules = thp_market_rules();
    $out = array(
        'registration_open' => thp_setting('registration_open', '1'),
        'trade_enabled' => thp_setting('trade_enabled', '1'),
        'device_limit_enabled' => thp_setting('device_limit_enabled', '1'),
        'event_name' => thp_setting('event_name', ''),
        'announcement' => thp_setting('announcement', ''),
        'oss_base_url' => thp_setting('oss_base_url', ''),
        'ai_endpoint' => $cfg['endpoint'],
        'ai_model' => $cfg['model'],
        'ai_key_set' => ($cfg['key'] !== ''),
        'ai_key_tail' => ($cfg['key'] !== '' ? substr($cfg['key'], -6) : ''),
        'banned_words' => thp_setting('banned_words', ''),
        'trade_throttle_count' => $rules['trade_throttle_count'],
        'trade_throttle_window_minutes' => $rules['trade_throttle_window_minutes'],
        'transfer_limit_30m' => $rules['transfer_limit_30m'],
        'holding_lock_minutes' => $rules['holding_lock_minutes'],
        'loan_max_per_hour' => $rules['loan_max_per_hour'],
        'loan_base_rate' => $rules['loan_base_rate'],
        'loan_rate_step' => $rules['loan_rate_step'],
        'loan_max_rate' => $rules['loan_max_rate'],
        'loan_interest_minutes' => $rules['loan_interest_minutes'],
        'market_refresh_minutes' => $rules['market_refresh_minutes'],
        'market_forecast_ticks' => $rules['market_forecast_ticks'],
        'market_step_max_percent' => $rules['market_step_max_percent'],
        'market_min_ratio' => $rules['market_min_ratio'],
        'market_max_ratio' => $rules['market_max_ratio'],
        'market_noise_weight' => $rules['market_noise_weight'],
        'market_mean_reversion' => $rules['market_mean_reversion'],
        'market_index_reversion' => $rules['market_index_reversion'],
        'market_hedge_weight' => $rules['market_hedge_weight'],
        'market_investment_weight' => $rules['market_investment_weight'],
        'market_investment_cap_percent' => $rules['market_investment_cap_percent'],
        'market_big_move_chance' => $rules['market_big_move_chance'],
        'market_big_move_min_percent' => $rules['market_big_move_min_percent'],
        'market_big_move_max_percent' => $rules['market_big_move_max_percent'],
        'market_long_drift_percent' => $rules['market_long_drift_percent'],
        'market_cache_seconds' => thp_market_cfg_int('market_cache_seconds', 60),
        'news_auto_enabled' => thp_setting('news_auto_enabled', '1'),
        'news_auto_popup_enabled' => thp_setting('news_auto_popup_enabled', '0'),
        'news_interval_minutes' => $rules['news_interval_minutes'],
        'news_auto_min' => $rules['news_auto_min'],
        'news_auto_max' => $rules['news_auto_max'],
    );
    return $out;
}

function thp_act_admin_settings_update()
{
    $admin = thp_require_admin_perm('settings');
    $fields = array(
        'registration_open', 'trade_enabled', 'device_limit_enabled', 'event_name', 'announcement',
        'icp_number', 'police_beian', 'police_code',
        'oss_base_url', 'ai_endpoint', 'ai_model', 'banned_words',
        'trade_throttle_count', 'trade_throttle_window_minutes', 'transfer_limit_30m',
        'holding_lock_minutes', 'loan_max_per_hour', 'loan_base_rate', 'loan_rate_step', 'loan_max_rate', 'loan_interest_minutes',
        'market_refresh_minutes', 'market_forecast_ticks', 'market_step_max_percent',
        'market_min_ratio', 'market_max_ratio', 'market_noise_weight', 'market_mean_reversion',
        'market_index_reversion', 'market_hedge_weight', 'market_investment_weight',
        'market_investment_cap_percent', 'market_big_move_chance', 'market_big_move_min_percent',
        'market_big_move_max_percent', 'market_long_drift_percent', 'market_cache_seconds',
        'news_auto_enabled', 'news_auto_popup_enabled', 'news_interval_minutes', 'news_auto_min', 'news_auto_max',
    );
    foreach ($fields as $f) {
        $v = thp_param($f, null);
        if ($v === null) { continue; }
        $v = trim((string)$v);
        if ($f === 'registration_open' || $f === 'trade_enabled' || $f === 'device_limit_enabled' || $f === 'news_auto_enabled' || $f === 'news_auto_popup_enabled') {
            $v = ($v === '1' || $v === 'true') ? '1' : '0';
        }
        if ($f === 'event_name' && ($v === '' || mb_strlen($v) > 40)) { throw new ThpError('网站名称需为 1-40 个字'); }
        if (($f === 'icp_number' || $f === 'police_beian') && mb_strlen($v) > 80) { throw new ThpError('备案信息过长'); }
        if ($f === 'police_code' && $v !== '' && !preg_match('/^[0-9]{6,20}$/', $v)) { throw new ThpError('公安备案编号格式不正确'); }
        if ($f === 'oss_base_url' && $v !== '' && !preg_match('#^https?://#', $v)) { throw new ThpError('OSS 地址需以 http(s):// 开头'); }
        if ($f === 'ai_endpoint' && $v !== '' && !preg_match('#^https?://#', $v)) { throw new ThpError('API Endpoint 需以 http(s):// 开头'); }
        if ($f === 'banned_words' && mb_strlen($v) > 2000) { throw new ThpError('违禁词太长了，控制在 2000 字以内'); }
        $intBounds = array(
            'trade_throttle_count' => array(1, 200), 'trade_throttle_window_minutes' => array(1, 180),
            'transfer_limit_30m' => array(0, 1000000), 'holding_lock_minutes' => array(1, 1440),
            'loan_max_per_hour' => array(1, 100),
            'loan_base_rate' => array(1, 100), 'loan_rate_step' => array(1, 100), 'loan_max_rate' => array(1, 300),
            'loan_interest_minutes' => array(1, 1440), 'market_refresh_minutes' => array(1, 60),
            'market_forecast_ticks' => array(1, THP_TICKS_PER_DAY), 'market_cache_seconds' => array(1, 60),
            'news_interval_minutes' => array(5, 180), 'news_auto_min' => array(0, 5), 'news_auto_max' => array(0, 5),
        );
        $floatBounds = array(
            'market_step_max_percent' => array(1, 20), 'market_min_ratio' => array(0.01, 1),
            'market_max_ratio' => array(1, 20), 'market_noise_weight' => array(0, 5),
            'market_mean_reversion' => array(0, 1), 'market_index_reversion' => array(0, 1),
            'market_hedge_weight' => array(0, 1), 'market_investment_weight' => array(0, 2),
            'market_investment_cap_percent' => array(0, 5), 'market_big_move_chance' => array(0, 20),
            'market_big_move_min_percent' => array(1, 20), 'market_big_move_max_percent' => array(1, 20),
            'market_long_drift_percent' => array(-0.5, 0.5),
        );
        if (isset($intBounds[$f])) {
            if (!is_numeric($v)) { throw new ThpError('参数 ' . $f . ' 必须是数字'); }
            $v = (string)max($intBounds[$f][0], min($intBounds[$f][1], (int)$v));
        }
        if (isset($floatBounds[$f])) {
            if (!is_numeric($v)) { throw new ThpError('参数 ' . $f . ' 必须是数字'); }
            $v = (string)max($floatBounds[$f][0], min($floatBounds[$f][1], (float)$v));
        }
        thp_set_setting($f, $v);
    }
    $key = thp_param('ai_key', null);
    if ($key !== null) {
        $key = trim((string)$key);
        if ($key === '__clear__') {
            thp_set_setting('ai_key', '');
        } elseif ($key !== '') {
            thp_set_setting('ai_key', $key);
        }
    }
    thp_log_admin('更新设置', '');
    return array('ok' => true);
}

function thp_act_admin_logs()
{
    global $pdo;
    thp_require_admin_perm('logs');
    $st = $pdo->query('SELECT l.id, l.action, l.detail, l.created_at, u.username FROM admin_logs l LEFT JOIN users u ON u.id = l.admin_id ORDER BY l.id DESC LIMIT 150');
    return array('items' => $st->fetchAll());
}

// ===================== 活动运维 / 数据清理 =====================
function thp_admin_backup_json($label, array $sections)
{
    $dir = THP_DATA . '/backups';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    if (!is_dir($dir)) { throw new ThpError('备份目录创建失败，请检查 data 目录权限', 500); }
    global $pdo;
    $out = array('created_at' => thp_now(), 'label' => (string)$label, 'tables' => array());
    foreach ($sections as $name => $spec) {
        $st = $pdo->prepare((string)$spec['sql']);
        $st->execute(isset($spec['params']) ? $spec['params'] : array());
        $out['tables'][(string)$name] = $st->fetchAll();
    }
    $name = 'backup-' . date('Ymd-His') . '-' . preg_replace('/[^a-z0-9_-]+/i', '-', (string)$label) . '-' . bin2hex(random_bytes(3)) . '.php';
    $file = $dir . '/' . $name;
    $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($file, "<?php exit; ?>\n" . $json, LOCK_EX) === false) {
        throw new ThpError('写入备份失败，请检查 data/backups 目录权限', 500);
    }
    @chmod($file, 0640);
    return 'data/backups/' . $name;
}

function thp_admin_delete_ids($table, $column, array $ids)
{
    global $pdo;
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; })));
    if (count($ids) === 0) { return 0; }
    $deleted = 0;
    foreach (array_chunk($ids, 400) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare('DELETE FROM ' . $table . ' WHERE ' . $column . ' IN (' . $marks . ')');
        $st->execute($chunk);
        $deleted += $st->rowCount();
    }
    return $deleted;
}

function thp_act_admin_maintenance_info()
{
    global $pdo;
    thp_require_admin_perm('maintenance');
    $out = array(
        'schema_version' => (string)thp_setting('schema_version', '0'),
        'test_accounts' => array(),
        'counts' => array(),
    );
    $st = $pdo->query("SELECT id, username, balance, created_at FROM users WHERE is_test = 1 AND role = 'user' ORDER BY id DESC LIMIT 100");
    $out['test_accounts'] = $st->fetchAll();
    foreach ($out['test_accounts'] as &$row) { $row['id'] = (int)$row['id']; $row['balance'] = (int)$row['balance']; }
    unset($row);
    $queries = array(
        'normal_users' => "SELECT COUNT(*) FROM users WHERE role = 'user' AND is_test = 0",
        'test_users' => 'SELECT COUNT(*) FROM users WHERE is_test = 1',
        'orphan_token_tx' => 'SELECT COUNT(*) FROM token_tx t LEFT JOIN users u ON u.id = t.user_id WHERE u.id IS NULL',
        'orphan_holdings' => 'SELECT COUNT(*) FROM holdings h LEFT JOIN users u ON u.id = h.user_id LEFT JOIN companies c ON c.id = h.company_id WHERE u.id IS NULL OR c.id IS NULL',
        'orphan_stock_trades' => 'SELECT COUNT(*) FROM stock_trades s LEFT JOIN users u ON u.id = s.user_id LEFT JOIN companies c ON c.id = s.company_id WHERE u.id IS NULL OR c.id IS NULL',
        'orphan_loans' => 'SELECT COUNT(*) FROM loans l LEFT JOIN users u ON u.id = l.user_id WHERE u.id IS NULL',
        'orphan_quiz_attempts' => 'SELECT COUNT(*) FROM quiz_attempts a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN questions q ON q.id = a.question_id WHERE u.id IS NULL OR q.id IS NULL',
        'orphan_user_stories' => 'SELECT COUNT(*) FROM user_stories s LEFT JOIN users u ON u.id = s.user_id LEFT JOIN stories st ON st.id = s.story_id WHERE u.id IS NULL OR st.id IS NULL',
        'orphan_spend' => 'SELECT COUNT(*) FROM spend_requests s LEFT JOIN users u ON u.id = s.user_id WHERE u.id IS NULL',
        'orphan_devices' => 'SELECT COUNT(*) FROM device_bindings d LEFT JOIN users u ON u.id = d.user_id WHERE u.id IS NULL',
        'orphan_risk' => 'SELECT COUNT(*) FROM risk_events r LEFT JOIN users u ON u.id = r.user_id WHERE r.user_id IS NOT NULL AND u.id IS NULL',
        'expired_idempotency' => 'SELECT COUNT(*) FROM idempotency_keys WHERE expires_at < NOW()',
    );
    foreach ($queries as $key => $sql) {
        try { $out['counts'][$key] = (int)$pdo->query($sql)->fetchColumn(); }
        catch (Throwable $e) { $out['counts'][$key] = -1; }
    }
    return $out;
}

function thp_act_admin_cleanup_orphans()
{
    global $pdo;
    $admin = thp_require_admin_perm('maintenance');
    $confirm = trim((string)thp_param('confirm'));
    if ($confirm !== '清理孤儿数据') { throw new ThpError('确认短语不正确，请输入「清理孤儿数据」'); }
    $statements = array(
        'DELETE t FROM token_tx t LEFT JOIN users u ON u.id = t.user_id WHERE u.id IS NULL',
        'DELETE h FROM holdings h LEFT JOIN users u ON u.id = h.user_id LEFT JOIN companies c ON c.id = h.company_id WHERE u.id IS NULL OR c.id IS NULL',
        'DELETE s FROM stock_trades s LEFT JOIN users u ON u.id = s.user_id LEFT JOIN companies c ON c.id = s.company_id WHERE u.id IS NULL OR c.id IS NULL',
        'DELETE l FROM loans l LEFT JOIN users u ON u.id = l.user_id WHERE u.id IS NULL',
        'DELETE a FROM quiz_attempts a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN questions q ON q.id = a.question_id WHERE u.id IS NULL OR q.id IS NULL',
        'DELETE s FROM user_stories s LEFT JOIN users u ON u.id = s.user_id LEFT JOIN stories st ON st.id = s.story_id WHERE u.id IS NULL OR st.id IS NULL',
        'DELETE s FROM spend_requests s LEFT JOIN users u ON u.id = s.user_id WHERE u.id IS NULL',
        'DELETE d FROM device_bindings d LEFT JOIN users u ON u.id = d.user_id WHERE u.id IS NULL',
        'DELETE r FROM risk_events r LEFT JOIN users u ON u.id = r.user_id WHERE r.user_id IS NOT NULL AND u.id IS NULL',
        'DELETE FROM idempotency_keys WHERE expires_at < NOW()',
    );
    $deleted = array();
    $pdo->beginTransaction();
    try {
        foreach ($statements as $sql) { $deleted[] = $pdo->exec($sql); }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw new ThpError('清理失败，数据库已回滚', 500);
    }
    thp_cache_delete('market:shared:cleanup');
    thp_log_admin('清理孤儿数据', '删除计数：' . implode(' / ', $deleted));
    return array('ok' => true, 'deleted' => $deleted);
}

function thp_act_admin_cleanup_test_accounts()
{
    global $pdo;
    $admin = thp_require_admin_perm('maintenance');
    $confirm = trim((string)thp_param('confirm'));
    if ($confirm !== '清理测试账号') { throw new ThpError('确认短语不正确，请输入「清理测试账号」'); }
    $st = $pdo->query("SELECT id, username FROM users WHERE is_test = 1 AND role = 'user'");
    $users = $st->fetchAll();
    $ids = array_map(function ($r) { return (int)$r['id']; }, $users);
    if (count($ids) === 0) { return array('ok' => true, 'deleted_users' => 0, 'backup' => '', 'message' => '没有标记为测试的账号'); }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $companySt = $pdo->prepare('SELECT id FROM companies WHERE owner_user_id IN (' . $marks . ')');
    $companySt->execute($ids);
    $companyIds = array_map('intval', $companySt->fetchAll(PDO::FETCH_COLUMN));
    $sections = array(
        'users' => array('sql' => 'SELECT * FROM users WHERE id IN (' . $marks . ')', 'params' => $ids),
        'token_tx' => array('sql' => 'SELECT * FROM token_tx WHERE user_id IN (' . $marks . ')', 'params' => $ids),
        'holdings' => array('sql' => 'SELECT * FROM holdings WHERE user_id IN (' . $marks . ')', 'params' => $ids),
        'stock_trades' => array('sql' => 'SELECT * FROM stock_trades WHERE user_id IN (' . $marks . ')', 'params' => $ids),
        'loans' => array('sql' => 'SELECT * FROM loans WHERE user_id IN (' . $marks . ')', 'params' => $ids),
        'quiz_attempts' => array('sql' => 'SELECT * FROM quiz_attempts WHERE user_id IN (' . $marks . ')', 'params' => $ids),
        'user_stories' => array('sql' => 'SELECT * FROM user_stories WHERE user_id IN (' . $marks . ')', 'params' => $ids),
        'spend_requests' => array('sql' => 'SELECT * FROM spend_requests WHERE user_id IN (' . $marks . ')', 'params' => $ids),
        'device_bindings' => array('sql' => 'SELECT * FROM device_bindings WHERE user_id IN (' . $marks . ')', 'params' => $ids),
        'risk_events' => array('sql' => 'SELECT * FROM risk_events WHERE user_id IN (' . $marks . ')', 'params' => $ids),
    );
    $backup = thp_admin_backup_json('cleanup-test-accounts', $sections);
    $pdo->beginTransaction();
    try {
        thp_admin_delete_ids('stock_trades', 'user_id', $ids);
        thp_admin_delete_ids('holdings', 'user_id', $ids);
        thp_admin_delete_ids('loans', 'user_id', $ids);
        thp_admin_delete_ids('quiz_attempts', 'user_id', $ids);
        thp_admin_delete_ids('user_stories', 'user_id', $ids);
        thp_admin_delete_ids('spend_requests', 'user_id', $ids);
        thp_admin_delete_ids('device_bindings', 'user_id', $ids);
        thp_admin_delete_ids('risk_events', 'user_id', $ids);
        thp_admin_delete_ids('admin_logs', 'admin_id', $ids);
        thp_admin_delete_ids('idempotency_keys', 'user_id', $ids);
        thp_admin_delete_ids('issue_codes', 'used_by', $ids);
        thp_admin_delete_ids('token_tx', 'user_id', $ids);
        if (count($companyIds) > 0) {
            thp_admin_delete_ids('holdings', 'company_id', $companyIds);
            thp_admin_delete_ids('stock_trades', 'company_id', $companyIds);
            thp_admin_delete_ids('companies', 'id', $companyIds);
        }
        thp_admin_delete_ids('users', 'id', $ids);
        $pdo->exec('DELETE FROM throttle');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw new ThpError('清理测试账号失败，数据库已回滚', 500);
    }
    thp_log_admin('清理测试账号', count($ids) . ' 个账号；备份 ' . $backup);
    return array('ok' => true, 'deleted_users' => count($ids), 'deleted_companies' => count($companyIds), 'backup' => $backup);
}

function thp_act_admin_event_reset()
{
    global $pdo;
    $admin = thp_require_admin_perm('maintenance');
    $confirm = trim((string)thp_param('confirm'));
    if ($confirm !== '重置本次活动') { throw new ThpError('确认短语不正确，请输入「重置本次活动」'); }
    $userSt = $pdo->query("SELECT id FROM users WHERE role = 'user'");
    $userIds = array_map('intval', $userSt->fetchAll(PDO::FETCH_COLUMN));
    $companySt = $pdo->query('SELECT id FROM companies WHERE is_default = 0');
    $companyIds = array_map('intval', $companySt->fetchAll(PDO::FETCH_COLUMN));
    $sections = array(
        'users' => array('sql' => "SELECT * FROM users WHERE role = 'user'", 'params' => array()),
        'token_tx' => array('sql' => 'SELECT * FROM token_tx', 'params' => array()),
        'holdings' => array('sql' => 'SELECT * FROM holdings', 'params' => array()),
        'stock_trades' => array('sql' => 'SELECT * FROM stock_trades', 'params' => array()),
        'loans' => array('sql' => 'SELECT * FROM loans', 'params' => array()),
        'quiz_attempts' => array('sql' => 'SELECT * FROM quiz_attempts', 'params' => array()),
        'user_stories' => array('sql' => 'SELECT * FROM user_stories', 'params' => array()),
        'spend_requests' => array('sql' => 'SELECT * FROM spend_requests', 'params' => array()),
        'issue_codes' => array('sql' => 'SELECT * FROM issue_codes', 'params' => array()),
        'device_bindings' => array('sql' => 'SELECT * FROM device_bindings', 'params' => array()),
        'risk_events' => array('sql' => 'SELECT * FROM risk_events', 'params' => array()),
        'companies' => array('sql' => 'SELECT * FROM companies WHERE is_default = 0', 'params' => array()),
    );
    $backup = thp_admin_backup_json('event-reset', $sections);
    $pdo->beginTransaction();
    try {
        foreach (array('idempotency_keys', 'stock_trades', 'holdings', 'loans', 'quiz_attempts', 'user_stories', 'spend_requests', 'issue_codes', 'token_tx', 'device_bindings', 'risk_events', 'admin_logs', 'throttle') as $table) {
            $pdo->exec('DELETE FROM ' . $table);
        }
        if (count($companyIds) > 0) { thp_admin_delete_ids('companies', 'id', $companyIds); }
        thp_admin_delete_ids('users', 'id', $userIds);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw new ThpError('活动重置失败，数据库已回滚；原数据未受影响', 500);
    }
    foreach (glob(THP_DATA . '/cache/*.json') ?: array() as $f) { @unlink($f); }
    thp_log_admin('活动重置', '清除游客 ' . count($userIds) . ' 人、公司 ' . count($companyIds) . ' 家；备份 ' . $backup);
    return array('ok' => true, 'deleted_users' => count($userIds), 'deleted_companies' => count($companyIds), 'backup' => $backup);
}

function thp_act_admin_health()
{
    global $pdo;
    thp_require_admin_perm('maintenance');
    $checks = array();
    function add_check(array &$checks, $key, $label, $status, $detail, $fix = '')
    {
        $checks[] = array('key' => $key, 'label' => $label, 'status' => $status, 'detail' => (string)$detail, 'fix' => (string)$fix);
    }
    try {
        add_check($checks, 'db', '数据库连接', 'ok', 'MySQL ' . $pdo->query('SELECT VERSION()')->fetchColumn());
    } catch (Throwable $e) {
        add_check($checks, 'db', '数据库连接', 'error', '连接失败', '检查 config.php 数据库配置');
    }
    $schema = (string)thp_setting('schema_version', '0');
    add_check($checks, 'schema', '数据库结构版本', ((int)$schema >= 9 ? 'ok' : 'warn'), '当前 v' . $schema . '，目标 v9', '运行一次性升级脚本');
    $required = array(
        'users.admin_scope', 'users.is_test', 'companies.renamed_at', 'company_events', 'market_points', 'stock_trades', 'idempotency_keys',
        'news_templates.popup_enabled', 'news_templates.pool', 'company_events.template_id', 'news_items.popup_enabled'
    );
    $missing = array();
    foreach ($required as $item) {
        if (strpos($item, '.') !== false) {
            list($table, $column) = explode('.', $item, 2);
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
            $st->execute(array($table, $column));
            if ((int)$st->fetchColumn() === 0) { $missing[] = $item; }
        } else {
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $st->execute(array($item));
            if ((int)$st->fetchColumn() === 0) { $missing[] = $item; }
        }
    }
    add_check($checks, 'tables', '关键表 / 字段', count($missing) ? 'error' : 'ok', count($missing) ? ('缺失：' . implode(', ', $missing)) : '结构完整', '执行数据库升级脚本');
    foreach (array('data' => THP_DATA, 'cache' => THP_DATA . '/cache', 'uploads' => THP_UPLOADS) as $key => $dir) {
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $writable = is_dir($dir) && is_writable($dir);
        add_check($checks, 'dir-' . $key, '目录 ' . $key, $writable ? 'ok' : 'error', $dir, '把目录权限设为 PHP 可写（通常 775）');
    }
    try {
        $marketDate = thp_today();
        $marketTick = thp_tick_of_day();
        $st = $pdo->prepare('SELECT COUNT(*) FROM market_points WHERE trade_date = ?');
        $st->execute(array($marketDate));
        $pointCount = (int)$st->fetchColumn();
        $st = $pdo->prepare('SELECT COUNT(*) FROM market_points WHERE trade_date = ? AND tick <= ?');
        $st->execute(array($marketDate, $marketTick));
        $pastCount = (int)$st->fetchColumn();
        $futureCount = max(0, $pointCount - $pastCount);
        $lastGeneratedRaw = (string)thp_setting('market_last_generated_at', '');
        $lastGenerated = $lastGeneratedRaw !== '' ? $lastGeneratedRaw : '尚未生成';
        $nextGenerated = $lastGeneratedRaw !== ''
            ? date('Y-m-d H:i:s', strtotime($lastGeneratedRaw) + thp_market_rules()['market_refresh_minutes'] * 60)
            : '等待首次行情请求';
        $stale = $lastGeneratedRaw !== '' && time() - strtotime($lastGeneratedRaw) > thp_market_rules()['market_refresh_minutes'] * 180;
        $status = $pointCount > 0 && !$stale ? 'ok' : 'warn';
        $detail = '今日 ' . $pointCount . ' 个价格点（已产生 ' . $pastCount . ' / 预测 ' . $futureCount . '） · 最近生成 ' . $lastGenerated . ' · 下次续写 ' . $nextGenerated;
        add_check($checks, 'market', '动态行情引擎', $status, $detail, '访问一次股市，或在「大盘」点击刷新 / 强制生成');
    } catch (Throwable $e) {
        add_check($checks, 'market', '动态行情引擎', 'error', '读取失败：' . $e->getMessage(), '检查 market_points 表与行情设置');
    }
    $cfg = thp_ai_config();
    $pinIp = ''; $pinPort = 0;
    $aiErr = $cfg['endpoint'] !== '' ? thp_ai_endpoint_validate($cfg['endpoint'], $pinIp, $pinPort) : '未配置 Endpoint';
    $aiKey = $cfg['key'] !== '';
    add_check($checks, 'ai', 'AI 走势生成', ($aiKey && $aiErr === '') ? 'ok' : 'warn', ($aiKey ? 'Key 已配置' : 'Key 未配置') . ' · ' . ($aiErr === '' ? $cfg['endpoint'] : $aiErr), '到「设置」填写 Key 与公网 Endpoint');
    $oss = trim((string)thp_setting('oss_base_url', ''));
    add_check($checks, 'oss', 'OSS 图片地址', $oss === '' ? 'warn' : 'ok', $oss === '' ? '未配置，将直接使用服务器图片' : $oss, '如需减轻服务器图片压力，请配置 OSS 公网地址');
    $log = THP_DATA . '/php-errors.log';
    if (!is_file($log)) {
        add_check($checks, 'log', 'PHP 错误日志', 'ok', '暂无日志文件');
    } else {
        $size = (int)filesize($log);
        $tail = '';
        $fp = @fopen($log, 'rb');
        if ($fp) {
            $start = max(0, $size - 16384);
            fseek($fp, $start);
            $tail = (string)fread($fp, 16384);
            fclose($fp);
            $lines = array_values(array_filter(explode("\n", trim($tail))));
            $tail = implode("\n", array_slice($lines, -6));
        }
        add_check($checks, 'log', 'PHP 错误日志', $size > 2 * 1024 * 1024 ? 'warn' : 'ok', '大小 ' . round($size / 1024, 1) . ' KB' . ($tail !== '' ? "\n" . $tail : ''), '持续报错时检查日志并联系维护者');
    }
    $free = @disk_free_space(THP_ROOT);
    $total = @disk_total_space(THP_ROOT);
    $diskOk = $free !== false && $free > 200 * 1024 * 1024;
    add_check($checks, 'disk', '磁盘空间', $diskOk ? 'ok' : 'error', $free === false ? '无法读取' : (round($free / 1073741824, 2) . ' GB 可用 / ' . round($total / 1073741824, 2) . ' GB'), '清理日志、备份或扩容');
    add_check($checks, 'php', 'PHP 运行环境', version_compare(PHP_VERSION, '7.4', '>=') ? 'ok' : 'warn', 'PHP ' . PHP_VERSION . ' · ' . PHP_SAPI . ' · 内存上限 ' . ini_get('memory_limit'), '建议 PHP 7.4+');
    $summary = array('ok' => 0, 'warn' => 0, 'error' => 0);
    foreach ($checks as $c) { $summary[$c['status']]++; }
    return array('summary' => $summary, 'checks' => $checks, 'generated_at' => thp_now());
}
