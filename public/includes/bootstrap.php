<?php
declare(strict_types=1);
// 公共引导文件：加载配置、Session、数据库与各功能模块
define('THP_APP', true);
define('THP_ROOT', dirname(__DIR__));          // public 目录
define('THP_DATA', THP_ROOT . '/data');        // 走势数据目录
define('THP_UPLOADS', THP_ROOT . '/uploads');  // 上传图片目录
define('THP_VERSION', '2.5.3');
define('THP_TICK_SECONDS', 180);           // 每 3 分钟一个价格点
define('THP_TICKS_PER_HOUR', 20);          // 一小时 20 个点
define('THP_TICKS_PER_DAY', 480);          // 全天 480 个点

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (!is_dir(THP_DATA)) { @mkdir(THP_DATA, 0775, true); }
ini_set('error_log', THP_DATA . '/php-errors.log');

$thpConfigFile = THP_ROOT . '/config.php';
if (!is_file($thpConfigFile)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>尚未安装</title></head>';
    echo '<body style="font-family:sans-serif;padding:48px;background:#fff8f8;color:#5a3a3a">';
    echo '<h2>站点尚未完成安装</h2><p>请先访问 <a href="install.php">install.php</a> 运行安装向导。</p></body></html>';
    exit;
}
$CONFIG = require $thpConfigFile;
if (!is_array($CONFIG)) { $CONFIG = array(); }

date_default_timezone_set(!empty($CONFIG['timezone']) ? (string)$CONFIG['timezone'] : 'Asia/Shanghai');
if (function_exists('mb_internal_encoding')) { mb_internal_encoding('UTF-8'); }

// 登录持久化：Session 保留 30 天，长期 Cookie 由数据库中的哈希令牌兜底。
$thpRememberDays = isset($CONFIG['auth_remember_days']) ? (int)$CONFIG['auth_remember_days'] : 30;
if ($thpRememberDays < 1 || $thpRememberDays > 365) { $thpRememberDays = 30; }
define('THP_AUTH_REMEMBER_DAYS', $thpRememberDays);
define('THP_AUTH_REMEMBER_SECONDS', $thpRememberDays * 86400);
define('THP_AUTH_COOKIE', 'THPREMEMBER');

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // 内容安全策略：脚本/样式只允许站内（页面里有内联脚本与内联样式，所以允许 unsafe-inline）；
    // 图片额外允许 OSS 的 https 图与 data:/blob:（二维码、摄像头预览）；禁止外部框架与对象嵌入。
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https: http:; media-src 'self' blob:; connect-src 'self'; font-src 'self' data:; worker-src 'self' blob:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
}

$thpSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.gc_maxlifetime', (string)THP_AUTH_REMEMBER_SECONDS);
    ini_set('session.use_strict_mode', '1');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array(
            'lifetime' => THP_AUTH_REMEMBER_SECONDS, 'path' => '/', 'httponly' => true,
            'samesite' => 'Lax', 'secure' => (bool)$thpSecure,
        ));
    } else {
        session_set_cookie_params(THP_AUTH_REMEMBER_SECONDS, '/', '', (bool)$thpSecure, true);
    }
    session_name('THPSESSID');
    session_start();
}

require THP_ROOT . '/includes/db.php';
require THP_ROOT . '/includes/helpers.php';
require THP_ROOT . '/includes/auth.php';
require THP_ROOT . '/includes/risk.php';
require THP_ROOT . '/includes/market.php';
require THP_ROOT . '/includes/deepseek.php';
require THP_ROOT . '/includes/handlers.php';
require THP_ROOT . '/includes/handlers_admin.php';
require THP_ROOT . '/includes/admin_market.php';

$pdo = thp_db($CONFIG['db']);
thp_device_cookie_ensure();
