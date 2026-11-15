<?php
declare(strict_types=1);
if (!defined('THP_APP')) { http_response_code(403); exit('forbidden'); }

// ===================== 设备标识 =====================
// 浏览器无法读取真实 MAC 地址；这里用持久设备 ID + 设备指纹做“尽力而为”的
// 一机一号限制。清缓存、换浏览器或换设备仍可能绕过，需要现场人工兜底。
function thp_device_cookie_name()
{
    return 'THPDEV';
}

function thp_device_valid_key($key)
{
    return (bool)preg_match('/^[a-f0-9]{32,64}$/i', (string)$key);
}

function thp_device_set_cookie($key)
{
    $key = strtolower((string)$key);
    if (!thp_device_valid_key($key) || headers_sent()) { return; }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $name = thp_device_cookie_name();
    if (PHP_VERSION_ID >= 70300) {
        setcookie($name, $key, array(
            'expires' => time() + 31536000,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => (bool)$secure,
        ));
    } else {
        setcookie($name, $key, time() + 31536000, '/', '', (bool)$secure, true);
    }
    $_COOKIE[$name] = $key;
}

function thp_device_cookie_ensure()
{
    $name = thp_device_cookie_name();
    if (isset($_COOKIE[$name]) && thp_device_valid_key($_COOKIE[$name])) {
        return strtolower((string)$_COOKIE[$name]);
    }
    $key = bin2hex(random_bytes(16));
    thp_device_set_cookie($key);
    return $key;
}

function thp_device_fingerprint()
{
    $meta = thp_param('device_meta', array());
    if (!is_array($meta)) { $meta = array(); }
    $clean = array();
    foreach (array('screen', 'language', 'timezone', 'platform', 'hardware') as $k) {
        if (isset($meta[$k]) && is_scalar($meta[$k])) {
            $clean[$k] = mb_substr(trim((string)$meta[$k]), 0, 120, 'UTF-8');
        }
    }
    $parts = array(
        'ua' => mb_substr(isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '', 0, 300, 'UTF-8'),
        'lang' => mb_substr(isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string)$_SERVER['HTTP_ACCEPT_LANGUAGE'] : '', 0, 160, 'UTF-8'),
        'meta' => $clean,
    );
    return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// 取得本次请求使用的设备 key：优先客户端 localStorage 传来的值，其次 HttpOnly Cookie。
function thp_device_request_context()
{
    $input = thp_param('device_id', '');
    if (is_scalar($input) && thp_device_valid_key((string)$input)) {
        thp_device_set_cookie((string)$input);
        $key = strtolower(trim((string)$input));
    } else {
        $key = thp_device_cookie_ensure();
    }
    return array('key' => $key, 'fingerprint' => thp_device_fingerprint());
}

function thp_device_limit_enabled()
{
    return thp_setting('device_limit_enabled', '1') === '1';
}

function thp_device_duplicate_error(Throwable $e)
{
    return $e instanceof PDOException && (string)$e->getCode() === '23000';
}

// 注册前的硬性检查：同一个 device key 只能绑定一个账号。
// 指纹相同但 device key 不同只记录风险，不直接拦截，避免同型号手机误伤。
function thp_device_precheck_registration($deviceKey, $fingerprint, $username)
{
    if (!thp_device_limit_enabled()) { return; }
    global $pdo;
    $deviceKey = strtolower((string)$deviceKey);
    $st = $pdo->prepare('SELECT user_id FROM device_bindings WHERE device_key = ? LIMIT 1');
    $st->execute(array($deviceKey));
    $bound = (int)$st->fetchColumn();
    if ($bound > 0) {
        thp_risk_event('device_conflict', '尝试注册「' . $username . '」，设备已绑定用户 ID ' . $bound, 3, null, $deviceKey, $username);
        throw new ThpError('这台设备已经注册过账号了，一台设备只能注册一个账号；如需帮助请联系现场管理员');
    }
    $fingerprint = trim((string)$fingerprint);
    if ($fingerprint !== '') {
        $st = $pdo->prepare('SELECT user_id FROM device_bindings WHERE fingerprint = ? LIMIT 1');
        $st->execute(array($fingerprint));
        $similar = (int)$st->fetchColumn();
        if ($similar > 0) {
            thp_risk_event('device_fingerprint_conflict', '尝试注册「' . $username . '」，设备指纹与用户 ID ' . $similar . ' 相似', 2, null, $deviceKey, $username);
        }
    }
}

function thp_device_bind_registration(PDO $pdo, $userId, $deviceKey, $fingerprint)
{
    if (!thp_device_limit_enabled()) { return; }
    $st = $pdo->prepare('INSERT INTO device_bindings (device_key, user_id, fingerprint, first_ip, last_ip, user_agent, created_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute(array(
        strtolower((string)$deviceKey),
        (int)$userId,
        trim((string)$fingerprint),
        thp_client_ip(),
        thp_client_ip(),
        mb_substr(isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '', 0, 255, 'UTF-8'),
        thp_now(),
        thp_now(),
    ));
}

// 登录不拦截，只更新已绑定设备的活动时间。
function thp_device_touch_login($deviceKey, $userId)
{
    if (!thp_device_limit_enabled()) { return; }
    global $pdo;
    $st = $pdo->prepare('UPDATE device_bindings SET last_seen_at = ?, last_ip = ? WHERE device_key = ? AND user_id = ?');
    $st->execute(array(thp_now(), thp_client_ip(), strtolower((string)$deviceKey), (int)$userId));
}

// ===================== 风险事件 =====================
function thp_risk_event($type, $detail = '', $severity = 2, $userId = null, $deviceKey = '', $username = '')
{
    global $pdo;
    if (!$pdo instanceof PDO) { return; }
    $userId = ($userId === null || (int)$userId <= 0) ? null : (int)$userId;
    $username = trim((string)$username);
    if ($userId === null) {
        $u = thp_current_user();
        if ($u) {
            $userId = (int)$u['id'];
            if ($username === '') { $username = (string)$u['username']; }
        }
    } elseif ($username === '') {
        try {
            $st = $pdo->prepare('SELECT username FROM users WHERE id = ?');
            $st->execute(array($userId));
            $username = (string)$st->fetchColumn();
        } catch (Throwable $e) {
            $username = '';
        }
    }
    if ($deviceKey === '') { $deviceKey = thp_device_cookie_ensure(); }
    $deviceKey = thp_device_valid_key($deviceKey) ? strtolower((string)$deviceKey) : '';
    $detail = mb_substr(trim((string)$detail), 0, 250, 'UTF-8');
    try {
        $st = $pdo->prepare('INSERT INTO risk_events (user_id, username, device_key, ip, type, severity, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute(array(
            $userId,
            mb_substr($username, 0, 32, 'UTF-8'),
            $deviceKey,
            thp_client_ip(),
            mb_substr((string)$type, 0, 32, 'UTF-8'),
            max(1, min(3, (int)$severity)),
            $detail,
            thp_now(),
        ));
    } catch (Throwable $e) {
        error_log('[THP risk] ' . $e->getMessage());
    }
}

function thp_risk_note_invalid_code($user, $source, $code = '')
{
    if (!$user || empty($user['id'])) { return; }
    $uid = (int)$user['id'];
    if (!thp_throttle('badcode:' . $uid, 8, 300)) {
        if (thp_throttle('badcode-alert:' . $uid, 1, 300)) {
            $code = preg_replace('/[^0-9]/', '', (string)$code);
            thp_risk_event('invalid_code_burst', $source . '连续输错代码（最近一次：' . mb_substr($code, 0, 12, 'UTF-8') . '）', 2, $uid, '', (string)$user['username']);
        }
    }
}
