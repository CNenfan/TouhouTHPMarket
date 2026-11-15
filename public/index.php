<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$errorText = '';
try {
    $user = thp_current_user();
    $boot = array(
        'base_url' => thp_base_url(),
        'version' => THP_VERSION,
        'device_id' => thp_device_cookie_ensure(),
        'user' => thp_user_brief($user),
        'initial_code' => isset($_GET['c']) ? preg_replace('/[^0-9]/', '', (string)$_GET['c']) : '',
        'settings' => array(
            'event_name' => thp_setting('event_name', '芙湘东方高校联合THP活动网站'),
            'announcement' => thp_setting('announcement', ''),
            'oss_base_url' => trim((string)thp_setting('oss_base_url', '')),
            'registration_open' => thp_setting('registration_open', '1'),
            'trade_enabled' => thp_setting('trade_enabled', '1'),
            'device_limit_enabled' => thp_setting('device_limit_enabled', '1'),
            'icp_number' => thp_setting('icp_number', ''),
            'police_beian' => thp_setting('police_beian', ''),
            'police_code' => thp_setting('police_code', ''),
        ),
    );
} catch (Throwable $e) {
    $errorText = '数据库连接失败，请检查 config.php 配置。';
    $boot = array('base_url' => '', 'version' => THP_VERSION, 'device_id' => '', 'user' => null, 'initial_code' => '', 'settings' => array('event_name' => '芙湘东方高校联合THP活动网站', 'announcement' => '', 'oss_base_url' => '', 'registration_open' => '1', 'trade_enabled' => '1', 'device_limit_enabled' => '1', 'icp_number' => '', 'police_beian' => '', 'police_code' => ''));
}
if ($errorText !== '') {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>出错了</title></head><body style="font-family:sans-serif;padding:48px;background:#fff8f8;color:#5a3a3a"><h2>' . $errorText . '</h2></body></html>';
    exit;
}
$v = THP_VERSION;
// JSON_HEX_*：把 < > & ' " 转义成 \uXXXX，防止公告等文本里混入 </script> 逃出脚本标签
$bootJson = json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#e05a6d">
<title><?php echo htmlspecialchars((string)$boot['settings']['event_name'], ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" href="assets/img/favicon.png?v=<?php echo $v; ?>">
<link rel="stylesheet" href="assets/css/app.css?v=<?php echo $v; ?>">
</head>
<body>
<div id="loading" class="thp-loading">
  <div class="thp-loading-inner">
    <img src="assets/img/coin.png?v=<?php echo $v; ?>" alt="" class="thp-loading-coin">
    <div class="thp-loading-text" id="loading-text">少女祈祷中…</div>
  </div>
</div>
<div id="app"></div>
<div id="modal-root"></div>
<div id="toast-root"></div>
<script>window.__THP_BOOT__ = <?php echo $bootJson; ?>;</script>
<script src="assets/js/token.js?v=<?php echo $v; ?>"></script>
<script src="assets/js/market.js?v=<?php echo $v; ?>"></script>
<script src="assets/js/story.js?v=<?php echo $v; ?>"></script>
<script src="assets/js/admin-market.js?v=<?php echo $v; ?>"></script>
<script src="assets/js/admin-news.js?v=<?php echo $v; ?>"></script>
<script src="assets/js/admin.js?v=<?php echo $v; ?>"></script>
<script src="assets/js/app.js?v=<?php echo $v; ?>"></script>
</body>
</html>
