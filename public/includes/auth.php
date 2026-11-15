<?php
declare(strict_types=1);
if (!defined('THP_APP')) { http_response_code(403); exit('forbidden'); }

// ===================== 持久登录令牌 =====================
// 浏览器关闭或 PHP 回收 Session 后，使用数据库中的哈希令牌自动恢复登录。
// Cookie 只保存随机 selector 与原始 token，数据库只保存 token 的 SHA-256 摘要。
function thp_auth_is_https()
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
}

function thp_auth_set_remember_cookie($value, $expires)
{
    if (headers_sent()) { return; }
    setcookie(THP_AUTH_COOKIE, (string)$value, array(
        'expires' => (int)$expires,
        'path' => '/',
        'secure' => thp_auth_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    if ((string)$value === '') {
        unset($_COOKIE[THP_AUTH_COOKIE]);
    } else {
        $_COOKIE[THP_AUTH_COOKIE] = (string)$value;
    }
}

function thp_auth_clear_remember_cookie()
{
    thp_auth_set_remember_cookie('', time() - 3600);
}

function thp_auth_cookie_parts()
{
    $raw = isset($_COOKIE[THP_AUTH_COOKIE]) ? trim((string)$_COOKIE[THP_AUTH_COOKIE]) : '';
    if (!preg_match('/^([a-f0-9]{24})\.([a-f0-9]{64})$/', $raw, $matches)) { return null; }
    return array('raw' => $raw, 'selector' => $matches[1], 'token' => $matches[2]);
}

function thp_auth_revoke_selector($selector)
{
    global $pdo;
    $selector = (string)$selector;
    if (!preg_match('/^[a-f0-9]{24}$/', $selector)) { return; }
    try {
        $st = $pdo->prepare('DELETE FROM auth_tokens WHERE selector = ?');
        $st->execute(array($selector));
    } catch (Throwable $e) {
        error_log('[THP] revoke auth token failed: ' . $e->getMessage());
    }
}

function thp_auth_revoke_user_tokens($userId)
{
    global $pdo;
    $userId = (int)$userId;
    if ($userId <= 0) { return; }
    try {
        $st = $pdo->prepare('DELETE FROM auth_tokens WHERE user_id = ?');
        $st->execute(array($userId));
    } catch (Throwable $e) {
        error_log('[THP] revoke user auth tokens failed: ' . $e->getMessage());
    }
}

function thp_auth_issue_remember_token($userId)
{
    global $pdo;
    $userId = (int)$userId;
    if ($userId <= 0) { return false; }

    // 清掉当前设备旧值，数据库异常时也不会误恢复成上一个账号。
    thp_auth_clear_remember_cookie();
    $selector = bin2hex(random_bytes(12));
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $now = time();
    $expires = $now + THP_AUTH_REMEMBER_SECONDS;
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
    if (function_exists('mb_substr')) { $ua = mb_substr($ua, 0, 255, 'UTF-8'); } else { $ua = substr($ua, 0, 255); }

    try {
        $cleanup = $pdo->prepare('DELETE FROM auth_tokens WHERE expires_at < ?');
        $cleanup->execute(array(date('Y-m-d H:i:s', $now)));
        $st = $pdo->prepare('INSERT INTO auth_tokens (user_id, selector, token_hash, expires_at, created_at, last_used_at, user_agent, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute(array(
            $userId,
            $selector,
            $tokenHash,
            date('Y-m-d H:i:s', $expires),
            date('Y-m-d H:i:s', $now),
            date('Y-m-d H:i:s', $now),
            $ua,
            thp_client_ip(),
        ));

        // 每个账号最多保留最近 20 个设备令牌，避免长期登录记录无限增长。
        $old = $pdo->prepare('SELECT id FROM auth_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 20, 100');
        $old->execute(array($userId));
        $oldIds = $old->fetchAll(PDO::FETCH_COLUMN);
        if ($oldIds) {
            $deleteOld = $pdo->prepare('DELETE FROM auth_tokens WHERE id = ?');
            foreach ($oldIds as $oldId) { $deleteOld->execute(array((int)$oldId)); }
        }
    } catch (Throwable $e) {
        error_log('[THP] issue auth token failed: ' . $e->getMessage());
        return false;
    }

    thp_auth_set_remember_cookie($selector . '.' . $token, $expires);
    return true;
}

function thp_auth_restore_user()
{
    global $pdo;
    $parts = thp_auth_cookie_parts();
    if ($parts === null) {
        if (isset($_COOKIE[THP_AUTH_COOKIE])) { thp_auth_clear_remember_cookie(); }
        return null;
    }

    try {
        $st = $pdo->prepare('SELECT t.token_hash, t.expires_at, u.* FROM auth_tokens t INNER JOIN users u ON u.id = t.user_id WHERE t.selector = ? LIMIT 1');
        $st->execute(array($parts['selector']));
        $row = $st->fetch();
    } catch (Throwable $e) {
        error_log('[THP] restore auth token failed: ' . $e->getMessage());
        return null;
    }

    if (!$row) {
        thp_auth_clear_remember_cookie();
        return null;
    }
    if (!hash_equals((string)$row['token_hash'], hash('sha256', $parts['token']))) {
        thp_auth_revoke_selector($parts['selector']);
        thp_auth_clear_remember_cookie();
        return null;
    }
    if (strtotime((string)$row['expires_at']) <= time()) {
        thp_auth_revoke_selector($parts['selector']);
        thp_auth_clear_remember_cookie();
        return null;
    }
    if ((int)$row['banned'] === 1) {
        thp_auth_revoke_user_tokens((int)$row['id']);
        thp_auth_clear_remember_cookie();
        return null;
    }

    $expires = time() + THP_AUTH_REMEMBER_SECONDS;
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
    if (function_exists('mb_substr')) { $ua = mb_substr($ua, 0, 255, 'UTF-8'); } else { $ua = substr($ua, 0, 255); }
    try {
        $up = $pdo->prepare('UPDATE auth_tokens SET last_used_at = ?, expires_at = ?, user_agent = ?, ip = ? WHERE selector = ?');
        $up->execute(array(date('Y-m-d H:i:s'), date('Y-m-d H:i:s', $expires), $ua, thp_client_ip(), $parts['selector']));
    } catch (Throwable $e) {
        error_log('[THP] touch auth token failed: ' . $e->getMessage());
    }

    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$row['id'];
    $_SESSION['auth_remember_ready'] = 1;
    thp_auth_set_remember_cookie($parts['raw'], $expires);
    return $row;
}

// ===================== 图形验证码 =====================
function thp_captcha_make_code()
{
    $chars = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < 4; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    $_SESSION['captcha'] = array('code' => $code, 'time' => time());
    return $code;
}

function thp_captcha_verify($input)
{
    if (!isset($_SESSION['captcha']) || !is_array($_SESSION['captcha'])) { return false; }
    $c = $_SESSION['captcha'];
    unset($_SESSION['captcha']);
    if (!isset($c['time']) || (time() - (int)$c['time']) > 300) { return false; }
    return strtoupper(trim((string)$input)) === (string)$c['code'];
}

// 输出验证码图片（优先 GD 生成 PNG，没有 GD 时用 SVG）
function thp_captcha_output()
{
    thp_require_throttle('captcha:' . thp_client_ip(), 600, 600, '验证码请求过于频繁');
    $code = thp_captcha_make_code();
    $w = 132;
    $h = 44;

    if (function_exists('imagecreatetruecolor')) {
        $im = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($im, 255, 250, 251);
        imagefilledrectangle($im, 0, 0, $w, $h, $bg);
        for ($i = 0; $i < 70; $i++) {
            $c = imagecolorallocate($im, random_int(200, 255), random_int(188, 236), random_int(195, 240));
            imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), $c);
        }
        for ($i = 0; $i < 4; $i++) {
            $c = imagecolorallocate($im, random_int(180, 240), random_int(120, 200), random_int(140, 210));
            imageline($im, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
        }
        for ($i = 0; $i < strlen($code); $i++) {
            $c = imagecolorallocate($im, random_int(150, 215), random_int(20, 90), random_int(60, 120));
            imagestring($im, 5, 16 + $i * 28 + random_int(-3, 3), random_int(10, 20), $code[$i], $c);
        }
        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        imagepng($im);
        imagedestroy($im);
        exit;
    }

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">';
    $svg .= '<rect width="' . $w . '" height="' . $h . '" fill="#fffafb"/>';
    for ($i = 0; $i < 6; $i++) {
        $svg .= '<line x1="' . random_int(0, $w) . '" y1="' . random_int(0, $h) . '" x2="' . random_int(0, $w) . '" y2="' . random_int(0, $h) . '" stroke="rgb(' . random_int(200, 255) . ',' . random_int(170, 220) . ',' . random_int(180, 225) . ')" stroke-width="1.4"/>';
    }
    for ($i = 0; $i < 4; $i++) {
        $x = 18 + $i * 28;
        $col = 'rgb(' . random_int(150, 215) . ',' . random_int(20, 90) . ',' . random_int(60, 120) . ')';
        $svg .= '<text x="' . $x . '" y="32" font-size="26" font-family="Verdana,Arial" font-weight="bold" fill="' . $col . '" transform="rotate(' . random_int(-14, 14) . ' ' . $x . ' 24)">' . $code[$i] . '</text>';
    }
    $svg .= '</svg>';
    header('Content-Type: image/svg+xml');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $svg;
    exit;
}

// ===================== 昵称 / 密码校验 =====================
function thp_valid_username($s)
{
    return (bool)preg_match('/^[\p{Han}A-Za-z0-9_]{2,16}$/u', (string)$s);
}

function thp_user_brief($u)
{
    if (!$u) { return null; }
    $scope = ((string)$u['role'] === 'admin') ? thp_admin_scope($u) : '';
    return array(
        'id' => (int)$u['id'],
        'username' => (string)$u['username'],
        'role' => (string)$u['role'],
        'admin_scope' => $scope,
        'admin_permissions' => $scope !== '' ? thp_admin_permissions_for_scope($scope) : array(),
        'is_test' => isset($u['is_test']) ? (int)$u['is_test'] : 0,
        'balance' => (int)$u['balance'],
        'debt' => (int)$u['debt'],
    );
}

// ===================== 管理员密码与分权 =====================
function thp_password_policy_error($password, $forAdmin = false)
{
    $password = (string)$password;
    $len = mb_strlen($password, 'UTF-8');
    if ($len < 6) { return '密码至少需要 6 位'; }
    if (!$forAdmin) { return ''; }
    if ($len < 12) { return '管理员密码至少需要 12 位'; }
    $classes = 0;
    if (preg_match('/[a-z]/', $password)) { $classes++; }
    if (preg_match('/[A-Z]/', $password)) { $classes++; }
    if (preg_match('/[0-9]/', $password)) { $classes++; }
    if (preg_match('/[^A-Za-z0-9]/u', $password)) { $classes++; }
    if ($classes < 3 && $len < 16) {
        return '管理员密码需包含大小写字母、数字、符号中的至少三类（16 位以上可放宽）';
    }
    $lower = mb_strtolower($password, 'UTF-8');
    foreach (array('password', 'admin', '123456', 'qwerty', 'touhou', 'thp2026') as $weak) {
        if (strpos($lower, $weak) !== false) { return '管理员密码不能包含常见弱口令片段'; }
    }
    return '';
}

function thp_admin_scope($u)
{
    if (!$u || (string)$u['role'] !== 'admin') { return ''; }
    $raw = isset($u['admin_scope']) ? trim((string)$u['admin_scope']) : '';
    if ($raw === '') { return 'full'; } // 兼容升级前的管理员账号
    return in_array($raw, array('full', 'finance', 'data'), true) ? $raw : 'data';
}

function thp_admin_scope_label($scope)
{
    $labels = array('full' => '全权管理员', 'finance' => '财务管理员', 'data' => '数据管理员');
    return isset($labels[(string)$scope]) ? $labels[(string)$scope] : '未知权限';
}

function thp_admin_permissions_for_scope($scope)
{
    if ($scope === 'full') {
        return array('stats', 'users', 'risk', 'users_manage', 'trade', 'content', 'market', 'settings', 'logs', 'maintenance');
    }
    if ($scope === 'finance') { return array('stats', 'trade'); }
    if ($scope === 'data') { return array('stats', 'users', 'risk', 'users_manage', 'content', 'market', 'settings', 'logs'); }
    return array('stats');
}

function thp_admin_can($permission, $u = null)
{
    $u = $u ?: thp_current_user();
    if (!$u || (string)$u['role'] !== 'admin') { return false; }
    return in_array((string)$permission, thp_admin_permissions_for_scope(thp_admin_scope($u)), true);
}

function thp_require_admin_perm($permission)
{
    $u = thp_require_admin();
    if (!thp_admin_can($permission, $u)) {
        throw new ThpError('当前管理员账号没有这个操作权限', 403);
    }
    return $u;
}

// ===================== 注册 =====================
function thp_register($username, $password, $password2, $captcha, $terms = false)
{
    global $pdo;
    $username = trim((string)$username);
    if (!$terms) {
        throw new ThpError('请先阅读并同意开屏说明与活动规则');
    }
    if (thp_setting('registration_open', '1') !== '1') {
        throw new ThpError('当前暂未开放注册，请联系现场管理员');
    }
    if (!thp_captcha_verify($captcha)) {
        throw new ThpError('验证码不正确，请重新输入');
    }
    $device = thp_device_request_context();
    if (!thp_valid_username($username)) {
        throw new ThpError('昵称需为 2-16 位中文 / 字母 / 数字 / 下划线');
    }
    thp_check_banned($username, '昵称', array('username' => $username, 'device_key' => $device['key']));
    $pwErr = thp_password_policy_error($password, false);
    if ($pwErr !== '') { throw new ThpError($pwErr); }
    if ((string)$password !== (string)$password2) {
        throw new ThpError('两次输入的密码不一致');
    }
    thp_require_throttle('reg:' . thp_client_ip(), 60, 600, '注册操作过于频繁，请稍后再试');
    thp_device_precheck_registration($device['key'], $device['fingerprint'], $username);
    $st = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $st->execute(array($username));
    if ($st->fetchColumn()) {
        throw new ThpError('这个昵称已经被使用了，换一个吧');
    }
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT INTO users (username, password_hash, role, balance, debt, banned, terms_accepted_at, created_at) VALUES (?, ?, ?, 100, 0, 0, ?, ?)');
        $ins->execute(array($username, password_hash((string)$password, PASSWORD_DEFAULT), 'user', thp_now(), thp_now()));
        $uid = (int)$pdo->lastInsertId();
        try {
            thp_device_bind_registration($pdo, $uid, $device['key'], $device['fingerprint']);
        } catch (Throwable $de) {
            if (thp_device_duplicate_error($de)) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                thp_risk_event('device_conflict', '并发注册时设备已被占用，尝试昵称「' . $username . '」', 3, null, $device['key'], $username);
                throw new ThpError('这台设备已经注册过账号了，一台设备只能注册一个账号；如需帮助请联系现场管理员');
            }
            throw $de;
        }
        $ins2 = $pdo->prepare('INSERT INTO token_tx (user_id, delta, balance_after, type, ref, note, created_at) VALUES (?, 100, 100, ?, ?, ?, ?)');
        $ins2->execute(array($uid, 'initial', '', '注册赠送', thp_now()));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($e instanceof ThpError) { throw $e; }
        throw new ThpError('注册失败，请稍后再试', 500);
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    thp_auth_issue_remember_token($uid);
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute(array($uid));
    return $st->fetch();
}

// ===================== 登录 =====================
function thp_login($username, $password, $captcha)
{
    global $pdo;
    $username = trim((string)$username);
    if (!thp_captcha_verify($captcha)) {
        throw new ThpError('验证码不正确，请重新输入');
    }
    thp_require_throttle('login-ip:' . thp_client_ip(), 160, 600, '登录尝试过于频繁，请稍后再试');
    $st = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute(array($username));
    $u = $st->fetch();
    if (!$u || !password_verify((string)$password, (string)$u['password_hash'])) {
        $ipOk = thp_throttle('login-fail-ip:' . thp_client_ip(), 30, 900);
        $userOk = true;
        $adminOk = true;
        if ($u) {
            $lowerName = mb_strtolower((string)$u['username'], 'UTF-8');
            $userOk = thp_throttle('login-fail-u:' . $lowerName, 12, 900);
            if ((string)$u['role'] === 'admin') {
                $adminOk = thp_throttle('login-fail-admin:' . $lowerName, 5, 900);
                if (!$adminOk) {
                    thp_risk_event('admin_login_blocked', '管理员账号连续登录失败，已触发更严格的 15 分钟限制', 3, (int)$u['id']);
                }
            }
        }
        if (!$ipOk || !$userOk || !$adminOk) {
            throw new ThpError('连续失败次数过多，请 15 分钟后再试', 429);
        }
        throw new ThpError('昵称或密码不正确');
    }
    if ((int)$u['banned'] === 1) {
        throw new ThpError('该账号已被封禁，请联系现场管理员');
    }
    $device = thp_device_request_context();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    thp_auth_issue_remember_token((int)$u['id']);
    thp_device_touch_login($device['key'], (int)$u['id']);
    $st = $pdo->prepare('UPDATE users SET last_login_at = ?, last_ip = ? WHERE id = ?');
    $st->execute(array(thp_now(), thp_client_ip(), (int)$u['id']));
    $lowerName = mb_strtolower((string)$u['username'], 'UTF-8');
    thp_throttle_reset('login-fail-u:' . $lowerName);
    thp_throttle_reset('login-fail-admin:' . $lowerName);
    return $u;
}

function thp_logout()
{
    $parts = thp_auth_cookie_parts();
    if ($parts !== null) { thp_auth_revoke_selector($parts['selector']); }
    thp_auth_clear_remember_cookie();
    unset($_SESSION['uid']);
    unset($_SESSION['auth_remember_ready']);
    session_regenerate_id(true);
}
