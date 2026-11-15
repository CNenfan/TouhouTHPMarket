<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$u = thp_current_user();
if (!$u || (string)$u['role'] !== 'admin') {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>需要管理员权限</title></head><body style="font-family:sans-serif;padding:48px"><h2>需要管理员权限</h2><p><a href="index.php">返回网站</a></p></body></html>';
    exit;
}

global $pdo;
$type = isset($_GET['type']) ? (string)$_GET['type'] : 'question';
$items = array();
if ($type === 'story') {
    foreach ($pdo->query('SELECT code, title FROM stories WHERE enabled = 1 ORDER BY id') as $r) {
        $items[] = array('code' => (string)$r['code'], 'title' => (string)$r['title'], 'sub' => '故事');
    }
} else {
    foreach ($pdo->query('SELECT code, title, reward FROM questions WHERE enabled = 1 ORDER BY id') as $r) {
        $items[] = array('code' => (string)$r['code'], 'title' => (string)$r['title'], 'sub' => '答题 · 奖励 ' . (int)$r['reward'] . ' FDT');
    }
}
$base = thp_base_url();
$v = THP_VERSION;
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>二维码打印 - <?php echo $type === 'story' ? '故事' : '题目'; ?></title>
<link rel="stylesheet" href="assets/css/app.css?v=<?php echo $v; ?>">
<style>
body { background: #fff; padding: 16px; }
.qr-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
.qr-card { border: 1.5px dashed #e8aab5; border-radius: 12px; padding: 14px; text-align: center; page-break-inside: avoid; }
.qr-card h3 { font-size: 16px; margin: 0 0 6px; color: #7a4a56; }
.qr-card .sub { font-size: 12px; color: #a88; margin-bottom: 8px; }
.qr-card .code { font-size: 22px; letter-spacing: 4px; font-weight: 700; color: #d94f66; margin-top: 8px; font-family: monospace; }
.qr-card .qrbox { display: flex; justify-content: center; }
.qr-card .qrbox img, .qr-card .qrbox canvas { width: 168px !important; height: 168px !important; }
@media print { .no-print { display: none; } }
</style>
</head>
<body>
<div class="no-print" style="margin-bottom:16px; display:flex; gap:10px; align-items:center; flex-wrap:wrap">
  <a href="index.php">返回网站</a>
  <button onclick="window.print()" style="padding:8px 18px;border-radius:8px;border:none;background:#e05a6d;color:#fff;font-size:15px;cursor:pointer">打印 / 另存为 PDF</button>
  <a href="print.php?type=question">题目二维码</a>
  <a href="print.php?type=story">故事二维码</a>
  <span style="color:#999;font-size:13px">共 <?php echo count($items); ?> 个</span>
</div>
<div class="qr-grid">
<?php foreach ($items as $it): ?>
  <div class="qr-card">
    <h3><?php echo htmlspecialchars($it['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
    <div class="sub"><?php echo htmlspecialchars($it['sub'], ENT_QUOTES, 'UTF-8'); ?><br>扫码或在网站输入代码</div>
    <div class="qrbox" data-code="<?php echo $it['code']; ?>"></div>
    <div class="code"><?php echo $it['code']; ?></div>
  </div>
<?php endforeach; ?>
</div>
<script src="assets/js/lib/qrcode.min.js?v=<?php echo $v; ?>"></script>
<script>
document.querySelectorAll('.qrbox').forEach(function (box) {
  new QRCode(box, { text: <?php echo json_encode($base . '/?c=', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?> + box.getAttribute('data-code'), width: 168, height: 168, correctLevel: QRCode.CorrectLevel.M });
});
</script>
</body>
</html>
