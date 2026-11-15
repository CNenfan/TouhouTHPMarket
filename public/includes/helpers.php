<?php
declare(strict_types=1);
if (!defined('THP_APP')) { http_response_code(403); exit('forbidden'); }

// 业务错误：消息会原样返回给前端
class ThpError extends RuntimeException
{
    public $extra;
    public function __construct($message, $httpCode = 400, $extra = array())
    {
        parent::__construct((string)$message, (int)$httpCode);
        $this->extra = is_array($extra) ? $extra : array();
    }
}

// 输出 JSON 并结束请求
function thp_json_out($payload, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 读取请求参数：GET 参数 + JSON 请求体 + 表单
function thp_input()
{
    static $data = null;
    if ($data !== null) { return $data; }
    $data = is_array($_GET) ? $_GET : array();
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) { $data = array_merge($data, $decoded); }
    }
    if (is_array($_POST) && count($_POST) > 0) { $data = array_merge($data, $_POST); }
    return $data;
}

function thp_param($key, $default = '')
{
    $data = thp_input();
    return array_key_exists($key, $data) ? $data[$key] : $default;
}

function thp_now() { return date('Y-m-d H:i:s'); }
function thp_today() { return date('Y-m-d'); }
// 当前处于当天的第几个价格点（0 ~ 479，每 3 分钟一个点）
function thp_tick_of_day()
{
    $sec = ((int)date('G')) * 3600 + ((int)date('i')) * 60 + (int)date('s');
    return max(0, min(THP_TICKS_PER_DAY - 1, intdiv($sec, THP_TICK_SECONDS)));
}

function thp_client_ip()
{
    // 只信任 TCP 对端地址：站点由 nginx 直连 PHP-FPM，REMOTE_ADDR 就是真实客户端 IP。
    // 绝不采信 X-Real-IP / X-Forwarded-For —— 它们可以随便伪造，会绕过基于 IP 的验证码限速。
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

// 仅允许 POST，并校验来源（前端会带 X-Thp:1 请求头，防跨站伪造）
function thp_require_post()
{
    if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') !== 'POST') {
        throw new ThpError('请求方式不正确', 405);
    }
    if (empty($_SERVER['HTTP_X_THP']) || $_SERVER['HTTP_X_THP'] !== '1') {
        throw new ThpError('请在网站页面内操作', 403);
    }
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : '';
    if ($origin !== '') {
        $oh = parse_url($origin, PHP_URL_HOST);
        $sh = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
        if ($oh && $sh && strcasecmp($oh, preg_replace('/:\d+$/', '', $sh)) !== 0) {
            throw new ThpError('请求来源校验失败', 403);
        }
    }
}

// 当前登录用户（被封禁会自动注销）
function thp_current_user()
{
    static $loaded = false;
    static $user = null;
    if ($loaded) { return $user; }
    $loaded = true;
    global $pdo;
    $uid = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;
    if ($uid > 0) {
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute(array($uid));
        $u = $st->fetch();
        if ($u && (int)$u['banned'] !== 1) {
            // 兼容升级前已登录的旧会话：首次请求时补发长期令牌，无须强制重新登录。
            if (!isset($_SESSION['auth_remember_ready'])) {
                if (empty($_COOKIE[THP_AUTH_COOKIE])) {
                    thp_auth_issue_remember_token($uid);
                }
                $_SESSION['auth_remember_ready'] = 1;
            }
            $user = $u;
            return $user;
        }
        unset($_SESSION['uid']);
        unset($_SESSION['auth_remember_ready']);
        if ($u && (int)$u['banned'] === 1) {
            thp_auth_revoke_user_tokens($uid);
            thp_auth_clear_remember_cookie();
            session_regenerate_id(true);
        }
    }

    $restored = thp_auth_restore_user();
    if ($restored) {
        $user = $restored;
        return $user;
    }
    return $user;
}

function thp_require_login()
{
    $u = thp_current_user();
    if (!$u) { throw new ThpError('请先登录', 401); }
    return $u;
}

function thp_require_admin()
{
    $u = thp_require_login();
    if ((string)$u['role'] !== 'admin') { throw new ThpError('需要管理员权限', 403); }
    return $u;
}

// ===== 系统设置 =====
function thp_settings_all($reload = false)
{
    static $cache = null;
    if ($reload) { $cache = null; }
    if ($cache !== null) { return $cache; }
    global $pdo;
    $cache = array();
    foreach ($pdo->query('SELECT k, v FROM settings') as $row) {
        $cache[(string)$row['k']] = (string)$row['v'];
    }
    return $cache;
}

function thp_setting($key, $default = null)
{
    $all = thp_settings_all();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

function thp_set_setting($key, $value)
{
    global $pdo;
    $st = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    $st->execute(array((string)$key, (string)$value));
    thp_settings_all(true); // 清掉缓存，同一次请求里读到的是最新值
}

// ===== 轻量缓存（APCu 优先，未安装时使用原子写入的文件缓存）=====
function thp_cache_dir()
{
    $dir = THP_DATA . '/cache';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    return $dir;
}

function thp_cache_path($key)
{
    return thp_cache_dir() . '/' . hash('sha256', (string)$key) . '.json';
}

function thp_cache_get($key, $ttl = 60)
{
    $key = (string)$key;
    $ttl = max(1, (int)$ttl);
    if (function_exists('apcu_fetch')) {
        $ok = false;
        $value = apcu_fetch('thp:' . $key, $ok);
        if ($ok) { return $value; }
    }
    $file = thp_cache_path($key);
    if (!is_file($file)) { return null; }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') { return null; }
    $wrapped = json_decode($raw, true);
    if (!is_array($wrapped) || !isset($wrapped['expires']) || !array_key_exists('value', $wrapped)) { return null; }
    if ((int)$wrapped['expires'] < time()) { @unlink($file); return null; }
    return $wrapped['value'];
}

function thp_cache_set($key, $value, $ttl = 60)
{
    $key = (string)$key;
    $ttl = max(1, (int)$ttl);
    if (function_exists('apcu_store')) {
        apcu_store('thp:' . $key, $value, $ttl);
    }
    $dir = thp_cache_dir();
    if (!is_dir($dir)) { return false; }
    $file = thp_cache_path($key);
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode(array('expires' => time() + $ttl, 'value' => $value), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) { @unlink($tmp); return false; }
    @chmod($tmp, 0664);
    if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
    return true;
}

function thp_cache_delete($key)
{
    if (function_exists('apcu_delete')) { @apcu_delete('thp:' . (string)$key); }
    $file = thp_cache_path($key);
    if (is_file($file)) { @unlink($file); }
}

// ===== 请求幂等：双击、弱网重试时只执行一次 =====
function thp_idempotency_key_value()
{
    $key = trim((string)thp_param('idempotency_key', ''));
    if ($key === '') { return ''; }
    return preg_match('/^[A-Za-z0-9_-]{16,64}$/', $key) ? $key : '';
}

function thp_idempotency_current_key()
{
    return isset($GLOBALS['thp_idem_current_key']) ? (string)$GLOBALS['thp_idem_current_key'] : '';
}

function thp_payload_canonical($value)
{
    if (is_array($value)) {
        $isList = array_keys($value) === range(0, count($value) - 1);
        if (!$isList) { ksort($value); }
        foreach ($value as $k => $v) { $value[$k] = thp_payload_canonical($v); }
        return $value;
    }
    return $value;
}

function thp_idempotency_payload_hash($action)
{
    $data = thp_input();
    unset($data['idempotency_key']);
    $data = thp_payload_canonical($data);
    return hash('sha256', (string)$action . '|' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
}

function thp_idempotency_begin($action, $key, $userId)
{
    global $pdo;
    if ($key === '') { return array('cached' => false, 'claimed' => false); }
    $userId = (int)$userId;
    if ($userId <= 0) { return array('cached' => false, 'claimed' => false); }
    $hash = thp_idempotency_payload_hash($action);
    $now = time();
    $created = date('Y-m-d H:i:s', $now);
    $expires = date('Y-m-d H:i:s', $now + 600);
    $ins = $pdo->prepare('INSERT IGNORE INTO idempotency_keys (user_id, idem_key, action, payload_hash, status, response, created_at, expires_at) VALUES (?, ?, ?, ?, ?, NULL, ?, ?)');
    $ins->execute(array($userId, $key, (string)$action, $hash, 'running', $created, $expires));
    $claimed = ($ins->rowCount() === 1);
    $st = $pdo->prepare('SELECT action, payload_hash, status, response, created_at FROM idempotency_keys WHERE user_id = ? AND idem_key = ?');
    $st->execute(array($userId, $key));
    $row = $st->fetch();
    if (!$row) { throw new ThpError('请求锁创建失败，请重试', 500); }
    if ((string)$row['action'] !== (string)$action || !hash_equals((string)$row['payload_hash'], $hash)) {
        throw new ThpError('重复请求参数不一致，请刷新页面后重试', 409);
    }
    if ((string)$row['status'] === 'done') {
        $data = json_decode((string)$row['response'], true);
        return array('cached' => true, 'claimed' => false, 'data' => $data);
    }
    if ($claimed) {
        $GLOBALS['thp_idem_current_key'] = $key;
        return array('cached' => false, 'claimed' => true);
    }
    // 另一个并发请求正在执行。短暂等待它提交，避免按钮重试拿到半成品。
    for ($i = 0; $i < 25; $i++) {
        usleep(100000);
        $st->execute(array($userId, $key));
        $row = $st->fetch();
        if ($row && (string)$row['status'] === 'done') {
            return array('cached' => true, 'claimed' => false, 'data' => json_decode((string)$row['response'], true));
        }
    }
    if ($row && (time() - strtotime((string)$row['created_at'])) > 60) {
        $take = $pdo->prepare("UPDATE idempotency_keys SET status = 'running', response = NULL, created_at = ?, expires_at = ? WHERE user_id = ? AND idem_key = ? AND status = 'running'");
        $take->execute(array($created, $expires, $userId, $key));
        if ($take->rowCount() === 1) {
            $GLOBALS['thp_idem_current_key'] = $key;
            return array('cached' => false, 'claimed' => true);
        }
    }
    throw new ThpError('上一笔请求仍在处理中，请稍后刷新查看，不要重复提交', 409);
}

function thp_idempotency_finish($action, $key, $userId, $data)
{
    global $pdo;
    if ($key === '') { return; }
    $st = $pdo->prepare("UPDATE idempotency_keys SET status = 'done', response = ?, expires_at = ? WHERE user_id = ? AND idem_key = ? AND action = ?");
    $st->execute(array(
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
        date('Y-m-d H:i:s', time() + 600),
        (int)$userId,
        $key,
        (string)$action,
    ));
    if (mt_rand(1, 100) === 1) {
        try { $pdo->exec('DELETE FROM idempotency_keys WHERE expires_at < NOW() LIMIT 200'); } catch (Throwable $ignored) {}
    }
}

function thp_idempotency_abort($action, $key, $userId)
{
    global $pdo;
    if ($key === '') { return; }
    try {
        $st = $pdo->prepare("DELETE FROM idempotency_keys WHERE user_id = ? AND idem_key = ? AND action = ? AND status = 'running'");
        $st->execute(array((int)$userId, $key, (string)$action));
    } catch (Throwable $ignored) {}
}

// ===== 违禁词 =====
function thp_banned_words()
{
    $raw = (string)thp_setting('banned_words', '');
    $out = array();
    foreach (preg_split('/[\r\n,，、;；]+/u', $raw) as $w) {
        $w = trim($w);
        if ($w !== '') { $out[] = $w; }
    }
    return $out;
}

// 命中违禁词直接抛错；$label 用于提示语，例如「昵称」「公司名」
function thp_check_banned($text, $label = '内容', $context = array())
{
    $text = trim((string)$text);
    if ($text === '') { return; }
    $lower = mb_strtolower($text, 'UTF-8');
    foreach (thp_banned_words() as $w) {
        if (mb_strpos($lower, mb_strtolower($w, 'UTF-8')) !== false) {
            $uid = isset($context['user_id']) ? (int)$context['user_id'] : null;
            $deviceKey = isset($context['device_key']) ? (string)$context['device_key'] : '';
            $username = isset($context['username']) ? (string)$context['username'] : '';
            thp_risk_event('banned_word', $label . '命中「' . $w . '」：' . mb_substr($text, 0, 80, 'UTF-8'), 3, $uid, $deviceKey, $username);
            throw new ThpError($label . '里含有不允许的词语「' . $w . '」，换一个吧～');
        }
    }
}

// ===== 欠款限制 =====
// 借了钱没还清之前，不能拿代币去消费 / 转让
function thp_require_no_debt($action = '消费')
{
    global $pdo;
    $u = thp_require_login();
    $st = $pdo->prepare('SELECT debt FROM users WHERE id = ?');
    $st->execute(array((int)$u['id']));
    $debt = (int)$st->fetchColumn();
    if ($debt > 0) {
        throw new ThpError('还有 ' . $debt . ' FDT 欠款没还清，先还完款才能' . $action . '哦（去「炒股」页面还款）');
    }
    return $u;
}

// ===== 六位数字代码生成（全表查重）=====
function thp_gen_code($table)
{
    global $pdo;
    $allowed = array('issue_codes', 'questions', 'stories', 'spend_requests');
    if (!in_array($table, $allowed, true)) { throw new ThpError('内部错误：非法代码表', 500); }
    for ($i = 0; $i < 80; $i++) {
        $code = (string)random_int(100000, 999999);
        $st = $pdo->prepare('SELECT 1 FROM ' . $table . ' WHERE code = ? LIMIT 1');
        $st->execute(array($code));
        if (!$st->fetchColumn()) { return $code; }
    }
    throw new ThpError('代码生成失败，请稍后再试', 500);
}

// 简易限流：同一个 key 在 windowSec 秒内最多 limit 次
function thp_throttle($key, $limit, $windowSec)
{
    global $pdo;
    $now = time();
    // 单条 SQL 原子累加，避免并发请求同时读到旧计数而突破限制。
    // window_start 不相同时（窗口已过期）把计数重置为 1。
    $up = $pdo->prepare(
        'INSERT INTO throttle (k, window_start, count) VALUES (?, ?, 1) ' .
        'ON DUPLICATE KEY UPDATE ' .
        'count = IF(VALUES(window_start) - window_start >= ?, 1, count + 1), ' .
        'window_start = IF(VALUES(window_start) - window_start >= ?, VALUES(window_start), window_start)'
    );
    $up->execute(array((string)$key, $now, (int)$windowSec, (int)$windowSec));
    $st = $pdo->prepare('SELECT count FROM throttle WHERE k = ?');
    $st->execute(array((string)$key));
    return ((int)$st->fetchColumn() <= (int)$limit);
}

function thp_require_throttle($key, $limit, $windowSec, $message)
{
    if (!thp_throttle($key, $limit, $windowSec)) { throw new ThpError($message, 429); }
}

function thp_throttle_reset($key)
{
    global $pdo;
    try {
        $st = $pdo->prepare('DELETE FROM throttle WHERE k = ?');
        $st->execute(array((string)$key));
    } catch (Throwable $ignored) {}
}

// ===== 代币账务（必须在事务内调用）=====
function thp_balance_apply(PDO $pdo, $userId, $delta, $type, $ref = '', $note = '')
{
    $st = $pdo->prepare('SELECT balance FROM users WHERE id = ? FOR UPDATE');
    $st->execute(array((int)$userId));
    $bal = $st->fetchColumn();
    if ($bal === false) { throw new ThpError('用户不存在', 404); }
    $new = (int)$bal + (int)$delta;
    if ($new < 0) { throw new ThpError('FDT 数量不足'); }
    $up = $pdo->prepare('UPDATE users SET balance = ? WHERE id = ?');
    $up->execute(array($new, (int)$userId));
    $ins = $pdo->prepare('INSERT INTO token_tx (user_id, delta, balance_after, type, ref, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ins->execute(array((int)$userId, (int)$delta, $new, (string)$type, (string)$ref, (string)$note, thp_now()));
    return $new;
}

function thp_tx_note(PDO $pdo, $userId, $type, $ref, $note, $balance)
{
    $ins = $pdo->prepare('INSERT INTO token_tx (user_id, delta, balance_after, type, ref, note, created_at) VALUES (?, 0, ?, ?, ?, ?, ?)');
    $ins->execute(array((int)$userId, (int)$balance, (string)$type, (string)$ref, (string)$note, thp_now()));
}

function thp_log_admin($action, $detail = '')
{
    global $pdo;
    $u = thp_current_user();
    $st = $pdo->prepare('INSERT INTO admin_logs (admin_id, action, detail, created_at) VALUES (?, ?, ?, ?)');
    $st->execute(array($u ? (int)$u['id'] : 0, (string)$action, (string)$detail, thp_now()));
}

function thp_base_url()
{
    global $CONFIG;
    if (!empty($CONFIG['base_url'])) { return rtrim((string)$CONFIG['base_url'], '/'); }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'localhost';
    return ($secure ? 'https://' : 'http://') . $host;
}

// 从扫码文本里提取六位代码（支持 URL 形式 ?c=123456 或纯数字）
function thp_extract_code($text)
{
    $text = trim((string)$text);
    if ($text === '') { return ''; }
    if (preg_match('/(?:[?&]c=)(\d{6})/', $text, $m)) { return $m[1]; }
    if (preg_match('/^\d{6}$/', $text)) { return $text; }
    if (preg_match('/(?<!\d)(\d{6})(?!\d)/', $text, $m)) { return $m[1]; }
    return '';
}
