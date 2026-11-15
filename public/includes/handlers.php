<?php
declare(strict_types=1);
if (!defined('THP_APP')) { http_response_code(403); exit('forbidden'); }

// ===================== 基础 =====================
function thp_act_me()
{
    $u = thp_current_user();
    return array(
        'user' => thp_user_brief($u),
        'settings' => array(
            'registration_open' => thp_setting('registration_open', '1'),
            'event_name' => thp_setting('event_name', '芙湘东方高校联合THP活动网站'),
            'announcement' => thp_setting('announcement', ''),
            'oss_base_url' => trim((string)thp_setting('oss_base_url', '')),
            'trade_enabled' => thp_setting('trade_enabled', '1'),
            'device_limit_enabled' => thp_setting('device_limit_enabled', '1'),
            'icp_number' => thp_setting('icp_number', ''),
            'police_beian' => thp_setting('police_beian', ''),
            'police_code' => thp_setting('police_code', ''),
        ),
        'version' => THP_VERSION,
    );
}
function thp_act_register()
{
    $terms = in_array((string)thp_param('terms'), array('1', 'true', 'on'), true);
    $u = thp_register(thp_param('username'), thp_param('password'), thp_param('password2'), thp_param('captcha'), $terms);
    return array('user' => thp_user_brief($u));
}

function thp_act_login()
{
    $u = thp_login(thp_param('username'), thp_param('password'), thp_param('captcha'));
    return array('user' => thp_user_brief($u));
}

function thp_act_logout()
{
    thp_logout();
    return array('ok' => true);
}

// ===================== 代币 =====================
function thp_act_code_resolve()
{
    global $pdo;
    $u = thp_require_login();
    $code = preg_replace('/[^0-9]/', '', (string)thp_param('code'));
    // 防止拿接口暴力枚举六位代码（扫描/深链会用到）：每个账号每分钟最多查询 20 次
    thp_require_throttle('code:' . (int)$u['id'], 20, 60, '查询太频繁了，休息一分钟再试吧');
    if (strlen($code) !== 6) {
        thp_risk_note_invalid_code($u, '代码查询', $code);
        return array('type' => 'invalid');
    }
    // 只告诉调用者「这是什么类型的代码」，不泄露别人代码的状态/金额等细节
    $st = $pdo->prepare('SELECT 1 FROM issue_codes WHERE code = ? LIMIT 1');
    $st->execute(array($code));
    $row = $st->fetch();
    if ($row) { return array('type' => 'issue'); }
    $st = $pdo->prepare('SELECT id, title FROM questions WHERE code = ? AND enabled = 1 LIMIT 1');
    $st->execute(array($code));
    $row = $st->fetch();
    if ($row) { return array('type' => 'quiz', 'title' => (string)$row['title']); }
    $st = $pdo->prepare('SELECT id, title FROM stories WHERE code = ? AND enabled = 1 LIMIT 1');
    $st->execute(array($code));
    $row = $st->fetch();
    if ($row) {
        $st2 = $pdo->prepare('SELECT 1 FROM user_stories WHERE user_id = ? AND story_id = ?');
        $st2->execute(array((int)$u['id'], (int)$row['id']));
        return array('type' => 'story', 'title' => (string)$row['title'], 'unlocked' => $st2->fetchColumn() ? true : false);
    }
    $st = $pdo->prepare('SELECT 1 FROM spend_requests WHERE code = ? LIMIT 1');
    $st->execute(array($code));
    $row = $st->fetch();
    if ($row) { return array('type' => 'spend'); }
    thp_risk_note_invalid_code($u, '代码查询', $code);
    return array('type' => 'invalid');
}

function thp_act_token_redeem()
{
    global $pdo;
    $u = thp_require_login();
    $code = preg_replace('/[^0-9]/', '', (string)thp_param('code'));
    thp_require_throttle('redeem:' . (int)$u['id'], 15, 60, '尝试太频繁啦：每分钟最多兑换 15 次，歇一会儿再来吧');
    if (strlen($code) !== 6) {
        thp_risk_note_invalid_code($u, '兑换码', $code);
        throw new ThpError('请输入正确的六位数字代码');
    }

    $st = $pdo->prepare('SELECT * FROM issue_codes WHERE code = ? LIMIT 1');
    $st->execute(array($code));
    $issue = $st->fetch();
    if ($issue) {
        if ((string)$issue['status'] === 'used') { throw new ThpError('这个代码已经被使用过了'); }
        if ((string)$issue['status'] === 'revoked') { throw new ThpError('这个代码已被管理员作废'); }
        $pdo->beginTransaction();
        try {
            $up = $pdo->prepare('UPDATE issue_codes SET status = ?, used_by = ?, used_at = ? WHERE id = ? AND status = ?');
            $up->execute(array('used', (int)$u['id'], thp_now(), (int)$issue['id'], 'pending'));
            if ($up->rowCount() !== 1) { throw new ThpError('这个代码已经被使用过了'); }
            $newBal = thp_balance_apply($pdo, (int)$u['id'], (int)$issue['amount'], 'issue', $code, '获得代币（发币码）');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ($e instanceof ThpError) { throw $e; }
            throw new ThpError('兑换失败，请稍后再试', 500);
        }
        return array('type' => 'issue', 'amount' => (int)$issue['amount'], 'balance' => $newBal);
    }

    $st = $pdo->prepare('SELECT * FROM questions WHERE code = ? AND enabled = 1 LIMIT 1');
    $st->execute(array($code));
    $q = $st->fetch();
    if ($q) {
        $st = $pdo->prepare('SELECT chosen, correct FROM quiz_attempts WHERE question_id = ? AND user_id = ?');
        $st->execute(array((int)$q['id'], (int)$u['id']));
        $att = $st->fetch();
        $options = json_decode((string)$q['options'], true);
        if (!is_array($options)) { $options = array(); }
        $payload = array(
            'type' => 'quiz',
            'answered' => $att ? true : false,
            'question' => array(
                'id' => (int)$q['id'],
                'title' => (string)$q['title'],
                'body' => (string)$q['body'],
                'options' => array_values($options),
                'reward' => (int)$q['reward'],
                'image' => (string)$q['image'],
            ),
        );
        if ($att) {
            $payload['chosen'] = (int)$att['chosen'];
            $payload['was_correct'] = ((int)$att['correct'] === 1);
        }
        return $payload;
    }
    thp_risk_note_invalid_code($u, '兑换码', $code);
    throw new ThpError('代码无效，请检查后重试');
}

function thp_act_token_quiz_answer()
{
    global $pdo;
    $u = thp_require_login();
    thp_require_throttle('quiz:' . (int)$u['id'], 10, 60, '提交太频繁啦，休息一下再答题吧');
    $qid = (int)thp_param('question_id');
    $chosen = (int)thp_param('chosen');
    $st = $pdo->prepare('SELECT * FROM questions WHERE id = ? AND enabled = 1 LIMIT 1');
    $st->execute(array($qid));
    $q = $st->fetch();
    if (!$q) { throw new ThpError('题目不存在或已下线'); }
    $options = json_decode((string)$q['options'], true);
    if (!is_array($options)) { $options = array(); }
    if ($chosen < 0 || $chosen >= count($options)) { throw new ThpError('选项不合法'); }
    $correct = ((int)$q['correct_index'] === $chosen);
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT INTO quiz_attempts (question_id, user_id, chosen, correct, created_at) VALUES (?, ?, ?, ?, ?)');
        try {
            $ins->execute(array($qid, (int)$u['id'], $chosen, $correct ? 1 : 0, thp_now()));
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') { throw new ThpError('这道题你已经答过啦，每人只能答 1 次哦'); }
            throw $e;
        }
        $newBal = null;
        if ($correct) {
            $newBal = thp_balance_apply($pdo, (int)$u['id'], (int)$q['reward'], 'quiz', 'q' . $qid, '答题奖励：' . $q['title']);
        }
        if ($newBal === null) {
            $stB = $pdo->prepare('SELECT balance FROM users WHERE id = ?');
            $stB->execute(array((int)$u['id']));
            $newBal = (int)$stB->fetchColumn();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($e instanceof ThpError) { throw $e; }
        throw new ThpError('提交失败，请稍后再试', 500);
    }
    return array(
        'correct' => $correct,
        'answer_index' => (int)$q['correct_index'],
        'reward' => (int)$q['reward'],
        'balance' => $newBal,
        'title' => (string)$q['title'],
    );
}

function thp_spend_settle($code)
{
    global $pdo;
    $code = preg_replace('/[^0-9]/', '', (string)$code);
    $st = $pdo->prepare('SELECT * FROM spend_requests WHERE code = ? LIMIT 1');
    $st->execute(array($code));
    $row = $st->fetch();
    if (!$row) { return null; }
    if ((string)$row['status'] === 'pending' && strtotime((string)$row['expires_at']) <= time()) {
        $pdo->beginTransaction();
        try {
            $up = $pdo->prepare('UPDATE spend_requests SET status = ? WHERE id = ? AND status = ?');
            $up->execute(array('expired', (int)$row['id'], 'pending'));
            if ($up->rowCount() === 1) {
                thp_balance_apply($pdo, (int)$row['user_id'], (int)$row['amount'], 'spend_refund', $code, '支付码过期，自动退回');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw new ThpError('处理订单状态失败', 500);
        }
        $row['status'] = 'expired';
    }
    return $row;
}

function thp_act_token_spend_create()
{
    global $pdo;
    $u = thp_require_no_debt('使用代币');
    $amount = (int)thp_param('amount');
    if ($amount < 1 || $amount > 5000) { throw new ThpError('金额需在 1 - 5000 之间'); }
    if (thp_setting('trade_enabled', '1') !== '1') { throw new ThpError('管理员暂时关闭了交易功能'); }
    thp_require_throttle('spend:' . (int)$u['id'], 12, 60, '生成太频繁，请稍后再试');
    $code = thp_gen_code('spend_requests');
    $expiresAt = date('Y-m-d H:i:s', time() + 900);
    $pdo->beginTransaction();
    try {
        $newBal = thp_balance_apply($pdo, (int)$u['id'], -$amount, 'spend', $code, '生成支付码（等待管理员收款）');
        $ins = $pdo->prepare('INSERT INTO spend_requests (code, user_id, amount, status, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)');
        $ins->execute(array($code, (int)$u['id'], $amount, 'pending', thp_now(), $expiresAt));
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($e instanceof ThpError) { throw $e; }
        throw new ThpError('生成支付码失败，请稍后再试', 500);
    }
    return array('id' => $id, 'code' => $code, 'amount' => $amount, 'balance' => $newBal, 'seconds' => 900, 'expires_at' => $expiresAt);
}

function thp_act_token_spend_status()
{
    $u = thp_require_login();
    $row = thp_spend_settle((string)thp_param('code'));
    if (!$row || (int)$row['user_id'] !== (int)$u['id']) { throw new ThpError('订单不存在'); }
    $left = max(0, strtotime((string)$row['expires_at']) - time());
    return array(
        'status' => (string)$row['status'],
        'amount' => (int)$row['amount'],
        'seconds_left' => $left,
        'code' => (string)$row['code'],
    );
}

function thp_act_token_spend_cancel()
{
    global $pdo;
    $u = thp_require_login();
    $code = preg_replace('/[^0-9]/', '', (string)thp_param('code'));
    $row = thp_spend_settle($code);
    if (!$row || (int)$row['user_id'] !== (int)$u['id']) { throw new ThpError('订单不存在'); }
    if ((string)$row['status'] !== 'pending') { throw new ThpError('该订单当前无法取消'); }
    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare('UPDATE spend_requests SET status = ? WHERE id = ? AND status = ?');
        $up->execute(array('cancelled', (int)$row['id'], 'pending'));
        if ($up->rowCount() !== 1) { throw new ThpError('该订单当前无法取消'); }
        $newBal = thp_balance_apply($pdo, (int)$u['id'], (int)$row['amount'], 'spend_refund', $code, '取消支付，退回代币');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($e instanceof ThpError) { throw $e; }
        throw new ThpError('取消失败，请稍后再试', 500);
    }
    return array('balance' => $newBal, 'amount' => (int)$row['amount']);
}

function thp_act_token_transfer()
{
    global $pdo;
    $u = thp_require_no_debt('转让代币');
    $to = trim((string)thp_param('to'));
    $amount = (int)thp_param('amount');
    if ($to === '') { throw new ThpError('请输入对方的昵称'); }
    if ($amount < 1 || $amount > 100000) { throw new ThpError('转让金额不合法'); }
    if (mb_strtolower($to) === mb_strtolower((string)$u['username'])) { throw new ThpError('不能转让给自己哦'); }
    thp_require_throttle('transfer:' . (int)$u['id'], 30, 600, '转让太频繁，请稍后再试');
    $rules = thp_market_rules();
    $limit = (int)$rules['transfer_limit_30m'];
    $pdo->beginTransaction();
    try {
        // 锁住转出账户，保证并发请求也会按最近 30 分钟累计额度串行校验。
        $lock = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $lock->execute(array((int)$u['id']));
        $sum = $pdo->prepare("SELECT COALESCE(SUM(-delta), 0) FROM token_tx
            WHERE user_id = ? AND type = 'transfer_out' AND delta < 0
              AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
        $sum->execute(array((int)$u['id']));
        $used = (int)$sum->fetchColumn();
        if ($limit > 0 && $used + $amount > $limit) {
            $left = max(0, $limit - $used);
            throw new ThpError('每 30 分钟最多转让 ' . $limit . ' FDT，本周期还剩 ' . $left . ' FDT 额度');
        }
        $st = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $st->execute(array($to));
        $target = $st->fetch();
        if (!$target) { throw new ThpError('没有找到这位用户，请确认昵称是否输入完整'); }
        if ((int)$target['banned'] === 1) { throw new ThpError('对方账号已被封禁，无法转让'); }
        $newBal = thp_balance_apply($pdo, (int)$u['id'], -$amount, 'transfer_out', (string)$target['id'], '转让给 ' . $target['username']);
        thp_balance_apply($pdo, (int)$target['id'], $amount, 'transfer_in', (string)$u['id'], '来自 ' . $u['username'] . ' 的转让');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($e instanceof ThpError) { throw $e; }
        throw new ThpError('转让失败，请稍后再试', 500);
    }
    return array('balance' => $newBal, 'to' => (string)$target['username'], 'amount' => $amount);
}

function thp_act_token_history()
{
    global $pdo;
    $u = thp_require_login();
    $st = $pdo->prepare('SELECT delta, balance_after, type, note, created_at FROM token_tx WHERE user_id = ? ORDER BY id DESC LIMIT 50');
    $st->execute(array((int)$u['id']));
    return array('items' => $st->fetchAll());
}

// ===================== 股市 =====================
function thp_act_market_state()
{
    $u = thp_require_login();
    thp_news_tick();
    return thp_market_state_payload($u, (int)thp_param('range', 60));
}

function thp_act_market_company_detail()
{
    $u = thp_require_login();
    return thp_market_company_detail_payload($u, (int)thp_param('company_id'), (int)thp_param('range', 60));
}

function thp_act_market_buy() { return thp_market_buy((int)thp_param('company_id'), (int)thp_param('qty')); }
function thp_act_market_sell() { return thp_market_sell((int)thp_param('company_id'), (int)thp_param('qty')); }
function thp_act_market_loan() { return thp_market_loan((int)thp_param('amount')); }
function thp_act_market_repay() { return thp_market_repay((int)thp_param('amount')); }
function thp_act_market_create_company() { return thp_market_create_company((string)thp_param('name')); }
function thp_act_market_rename_company() { return thp_company_rename((int)thp_param('company_id'), (string)thp_param('name')); }

// ===================== 新闻 / 通知 =====================
function thp_act_news_inbox()
{
    $u = thp_require_login();
    thp_news_tick();
    $kind = ((string)thp_param('kind') === 'notice') ? 'notice' : 'market';
    return thp_news_inbox((int)$u['id'], $kind, (int)thp_param('limit', 20));
}

function thp_act_news_detail()
{
    thp_require_login();
    return thp_news_detail((int)thp_param('id'));
}

function thp_act_news_mark_read()
{
    global $pdo;
    $u = thp_require_login();
    $id = (int)thp_param('id');
    $st = $pdo->prepare('SELECT 1 FROM news_items WHERE id = ? AND published_at <= NOW() LIMIT 1');
    $st->execute(array($id));
    if (!$st->fetchColumn()) { throw new ThpError('消息不存在', 404); }
    return thp_news_mark_read((int)$u['id'], $id);
}

// ===================== 故事 =====================
function thp_act_story_list()
{
    global $pdo;
    $u = thp_require_login();
    $st = $pdo->prepare('SELECT s.id, s.title, us.unlocked_at FROM user_stories us JOIN stories s ON s.id = us.story_id WHERE us.user_id = ? AND s.enabled = 1 ORDER BY us.unlocked_at DESC');
    $st->execute(array((int)$u['id']));
    $items = array();
    foreach ($st->fetchAll() as $r) {
        $items[] = array('id' => (int)$r['id'], 'title' => (string)$r['title'], 'unlocked_at' => (string)$r['unlocked_at']);
    }
    return array('items' => $items);
}

function thp_act_story_get()
{
    global $pdo;
    $u = thp_require_login();
    $id = (int)thp_param('id');
    $st = $pdo->prepare('SELECT * FROM stories WHERE id = ? AND enabled = 1 LIMIT 1');
    $st->execute(array($id));
    $s = $st->fetch();
    if (!$s) { throw new ThpError('故事不存在或已下线'); }
    if ((string)$u['role'] !== 'admin') {
        $st2 = $pdo->prepare('SELECT 1 FROM user_stories WHERE user_id = ? AND story_id = ?');
        $st2->execute(array((int)$u['id'], $id));
        if (!$st2->fetchColumn()) { throw new ThpError('还没有解锁这个故事哦'); }
    }
    $images = json_decode((string)$s['images'], true);
    if (!is_array($images)) { $images = array(); }
    return array('story' => array(
        'id' => (int)$s['id'],
        'title' => (string)$s['title'],
        'body' => (string)$s['body'],
        'images' => array_values($images),
    ));
}

function thp_act_story_redeem()
{
    global $pdo;
    $u = thp_require_login();
    $code = preg_replace('/[^0-9]/', '', (string)thp_param('code'));
    if (strlen($code) !== 6) { throw new ThpError('请输入正确的六位数字代码'); }
    thp_require_throttle('story:' . (int)$u['id'], 30, 60, '操作太频繁了，请稍后再试');
    $st = $pdo->prepare('SELECT * FROM stories WHERE code = ? AND enabled = 1 LIMIT 1');
    $st->execute(array($code));
    $s = $st->fetch();
    if (!$s) { throw new ThpError('故事代码不正确，再去现场找找线索吧～'); }
    $ins = $pdo->prepare('INSERT IGNORE INTO user_stories (user_id, story_id, unlocked_at) VALUES (?, ?, ?)');
    $ins->execute(array((int)$u['id'], (int)$s['id'], thp_now()));
    $already = ($ins->rowCount() === 0);
    $images = json_decode((string)$s['images'], true);
    if (!is_array($images)) { $images = array(); }
    return array(
        'already' => $already,
        'story' => array(
            'id' => (int)$s['id'],
            'title' => (string)$s['title'],
            'body' => (string)$s['body'],
            'images' => array_values($images),
        ),
    );
}
