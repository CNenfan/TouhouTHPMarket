<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = null;
$pageError = '';
try {
    $user = thp_current_user();
} catch (Throwable $e) {
    $pageError = '看板暂时无法连接数据服务';
    error_log('[THP board] ' . $e->getMessage());
}

if ($pageError === '' && !$user) {
    header('Location: index.php');
    exit;
}

$allowed = $pageError === '' && $user && thp_admin_can('market', $user);
if ($pageError === '' && !$allowed) {
    http_response_code(403);
}

$eventName = '芙湘东方高校联合THP活动网站';
if ($pageError === '' && $allowed) {
    try {
        $eventName = (string)thp_setting('event_name', $eventName);
    } catch (Throwable $e) {
        $pageError = '看板暂时无法读取活动信息';
    }
}

$v = THP_VERSION;
$boot = array('eventName' => $eventName, 'version' => $v);
$bootJson = json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#110d17">
<title><?php echo htmlspecialchars($eventName, ENT_QUOTES, 'UTF-8'); ?> · 看板</title>
<link rel="icon" href="assets/img/favicon.png?v=<?php echo $v; ?>">
<link rel="stylesheet" href="assets/css/board.css?v=<?php echo $v; ?>">
</head>
<body>
<?php if ($pageError !== '' || !$allowed) { ?>
<main class="board-denied">
  <img src="assets/img/logo.png?v=<?php echo $v; ?>" alt="">
  <h1><?php echo $pageError !== '' ? htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8') : '需要大盘管理权限'; ?></h1>
  <a href="index.php">返回活动网站</a>
</main>
<?php } else { ?>
<div class="board-screen">
  <div class="board-stage" id="board-stage">
    <div class="board-ambient" aria-hidden="true">
      <span class="ambient-sun"></span>
      <span class="ambient-glow glow-a"></span>
      <span class="ambient-glow glow-b"></span>
      <span class="petal petal-a"></span>
      <span class="petal petal-b"></span>
      <span class="petal petal-c"></span>
      <span class="petal petal-d"></span>
    </div>

    <header class="board-header">
      <div class="brand-block">
        <div class="brand-seal"><img src="assets/img/logo.png?v=<?php echo $v; ?>" alt=""></div>
        <div class="brand-copy">
          <div class="brand-overline">THP MARKET LIVE</div>
          <h1><span>芙湘东方高校联合</span><strong>THP活动网站</strong></h1>
        </div>
      </div>

      <div class="index-block">
        <div class="index-copy">
          <div class="index-label"><i></i>幻想乡大盘</div>
          <div class="index-value" id="index-value">----</div>
          <div class="index-meta">
            <span class="index-change flat" id="index-change">--</span>
            <span class="index-points" id="index-points">--</span>
          </div>
        </div>
        <div class="index-spark" id="index-spark"></div>
      </div>

      <div class="clock-block">
        <div class="live-chip" id="live-chip"><i></i><span id="live-text">LIVE</span></div>
        <div class="clock-time" id="clock-time">--:--:--</div>
        <div class="clock-date" id="clock-date">----</div>
      </div>
    </header>

    <main class="board-main">
      <section class="quote-panel panel">
        <div class="quote-heading">
          <div class="panel-title"><span class="title-mark"></span>股票行情</div>
          <div class="quote-summary" id="quote-summary">--</div>
        </div>
        <div class="quote-columns" aria-hidden="true">
          <span>公司</span>
          <span>走势</span>
          <span class="align-right">现价</span>
          <span class="align-right">涨跌</span>
          <span class="align-right">买入</span>
          <span class="align-right">持有</span>
        </div>
        <div class="quote-scroll" id="quote-scroll">
          <div class="quote-track" id="quote-track">
            <div class="quote-placeholder">少女祈祷中…</div>
          </div>
        </div>
      </section>

      <aside class="leaderboard">
        <div class="leaderboard-heading">
          <div class="panel-title"><span class="title-mark gold"></span>股票盈亏排行榜</div>
          <span class="leaderboard-stamp" id="rank-time">--:--</span>
        </div>
        <section class="rank-card profit-card">
          <div class="rank-card-head">
            <div><span class="rank-icon">▲</span><b>盈利榜</b></div>
            <span>TOP 5</span>
          </div>
          <div class="rank-list" id="rank-earn"><div class="rank-empty">暂无</div></div>
        </section>
        <section class="rank-card loss-card">
          <div class="rank-card-head">
            <div><span class="rank-icon">▼</span><b>亏损榜</b></div>
            <span>TOP 5</span>
          </div>
          <div class="rank-list" id="rank-lose"><div class="rank-empty">暂无</div></div>
        </section>
      </aside>
    </main>

    <footer class="news-ticker">
      <div class="ticker-label"><span>天狗快讯</span></div>
      <div class="ticker-window">
        <div class="ticker-track" id="news-track"><span class="ticker-item">幻想乡交易所行情同步中</span></div>
      </div>
    </footer>

    <div class="board-boot" id="board-boot">
      <img src="assets/img/coin.png?v=<?php echo $v; ?>" alt="">
      <span>少女祈祷中…</span>
    </div>
  </div>
</div>
<script>window.__THP_BOARD__ = <?php echo $bootJson; ?>;</script>
<script src="assets/js/board.js?v=<?php echo $v; ?>"></script>
<?php } ?>
</body>
</html>
