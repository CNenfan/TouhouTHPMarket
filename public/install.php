<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Shanghai');

$root = __DIR__;
$configFile = $root . '/config.php';
$cfgDefaults = array();   // 已有 config.php 时用于预填表单
$isInstalled = is_file($configFile);
// 有 config.php 但库里还没建表（比如配置是部署时预置的），也要能继续安装
if ($isInstalled) {
    try {
        $probe = include $configFile;
        if (is_array($probe)) { $cfgDefaults = $probe; }
        if (is_array($probe) && isset($probe['db'])) {
            $probePdo = new PDO(
                'mysql:host=' . $probe['db']['host'] . ';port=' . (int)$probe['db']['port'] . ';dbname=' . $probe['db']['name'] . ';charset=utf8mb4',
                (string)$probe['db']['user'], (string)$probe['db']['pass'],
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5)
            );
            $st = $probePdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'");
            if ((int)$st->fetchColumn() === 0) { $isInstalled = false; }
        }
    } catch (Throwable $e) {
        $isInstalled = false;
    }
}
$force = isset($_GET['force']) || isset($_POST['force']);
$done = false;
$error = '';
$writtenCfg = '';
$cfgNote = '';

function thp_install_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['db_host'])) {
    $dbHost = trim((string)$_POST['db_host']);
    $dbPort = (int)$_POST['db_port'];
    $dbName = trim((string)$_POST['db_name']);
    $dbUser = trim((string)$_POST['db_user']);
    $dbPass = (string)$_POST['db_pass'];
    $baseUrl = rtrim(trim((string)$_POST['base_url']), '/');
    $aiKey = trim((string)$_POST['ai_key']);
    $ossBase = rtrim(trim((string)$_POST['oss_base_url']), '/');
    $adminUser = trim((string)$_POST['admin_user']);
    $adminPass = (string)$_POST['admin_pass'];
    $adminPass2 = (string)$_POST['admin_pass2'];

    if ($dbHost === '' || $dbName === '' || $dbUser === '') { $error = '请填写数据库地址、库名与账号'; }
    elseif (!preg_match('/^[\p{Han}A-Za-z0-9_]{2,16}$/u', $adminUser)) { $error = '管理员昵称需为 2-16 位中文/字母/数字/下划线'; }
    elseif (strlen($adminPass) < 6) { $error = '管理员密码至少 6 位'; }
    elseif ($adminPass !== $adminPass2) { $error = '两次输入的管理员密码不一致'; }

    if ($error === '') {
        try {
            $dsn = 'mysql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $dbName . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $dbUser, $dbPass, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ));
            $sql = file_get_contents($root . '/schema.sql');
            if ($sql === false) { throw new Exception('读取 schema.sql 失败'); }
            $lines = explode("\n", $sql);
            $buffer = '';
            foreach ($lines as $line) {
                $trim = trim($line);
                if ($trim === '' || substr($trim, 0, 2) === '--') { continue; }
                $buffer .= $line . "\n";
                if (substr(rtrim($line), -1) === ';') {
                    $pdo->exec($buffer);
                    $buffer = '';
                }
            }
            $st = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $st->execute(array($adminUser));
            $adminExists = $st->fetchColumn();
            if (!$adminExists) {
                $ins = $pdo->prepare('INSERT INTO users (username, password_hash, role, balance, debt, banned, created_at) VALUES (?, ?, ?, 100, 0, 0, NOW())');
                $ins->execute(array($adminUser, password_hash($adminPass, PASSWORD_DEFAULT), 'admin'));
                $uid = (int)$pdo->lastInsertId();
                $ins2 = $pdo->prepare('INSERT INTO token_tx (user_id, delta, balance_after, type, ref, note, created_at) VALUES (?, 100, 100, ?, ?, ?, NOW())');
                $ins2->execute(array($uid, 'initial', '', '注册赠送'));
            } else {
                $up = $pdo->prepare('UPDATE users SET role = ?, password_hash = ? WHERE id = ?');
                $up->execute(array('admin', password_hash($adminPass, PASSWORD_DEFAULT), (int)$adminExists));
            }
            $cfg = array(
                'db' => array('host' => $dbHost, 'port' => $dbPort, 'name' => $dbName, 'user' => $dbUser, 'pass' => $dbPass),
                'base_url' => $baseUrl,
                'deepseek' => array('key' => $aiKey, 'endpoint' => 'https://api.deepseek.com/chat/completions', 'model' => 'deepseek-chat'),
                'oss_base_url' => $ossBase,
                'timezone' => 'Asia/Shanghai',
                'debug' => false,
                'auth_remember_days' => 30,
            );
            $writtenCfg = '<?php' . "\n" . '// 由安装向导生成（' . date('Y-m-d H:i:s') . '），包含了数据库密码与 API Key，请勿公开或提交到 git' . "\n" . 'return ' . var_export($cfg, true) . ';' . "\n";
            $cfgNote = 'config.php 已生成。';
            if (@file_put_contents($configFile, $writtenCfg) === false) {
                // 写不进去时，如果已经存在一份连接信息一致的配置，就照常完成安装
                $existing = is_file($configFile) ? @include $configFile : null;
                $same = is_array($existing) && isset($existing['db']) && is_array($existing['db'])
                    && (string)$existing['db']['name'] === $dbName && (string)$existing['db']['user'] === $dbUser;
                if (!$same) {
                    throw new Exception('数据库初始化成功，但无法写入 config.php（目录权限不足）。请手动在网站根目录创建 config.php 并粘贴下方内容：' . "\n" . $writtenCfg);
                }
                $cfgNote = '站点上已有一份连接信息相同的 config.php（目录不可写，未做改动）。';
            }
            $done = true;
        } catch (Exception $e) {
            $error = '安装失败：' . $e->getMessage();
        } catch (Throwable $e) {
            $error = '安装失败：' . $e->getMessage();
        }
    }
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$autoBase = $scheme . '://' . (isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'localhost');
$old = isset($_POST['db_host']) ? $_POST : array();
$val = function ($k, $d) use ($old) { return isset($old[$k]) && $old[$k] !== '' ? (string)$old[$k] : $d; };
$dv = function ($path, $d) use ($cfgDefaults) {
    $cur = $cfgDefaults;
    foreach (explode('.', $path) as $seg) {
        if (!is_array($cur) || !array_key_exists($seg, $cur)) { return $d; }
        $cur = $cur[$seg];
    }
    return ($cur === '' || $cur === null) ? $d : $cur;
};
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装向导 - 芙湘东方高校联合THP活动网站</title>
<style>
body { font-family: "PingFang SC", "Microsoft YaHei", sans-serif; background: linear-gradient(160deg, #fff5f6, #ffe9ee); margin: 0; padding: 28px 14px; color: #5a3a3a; }
.card { max-width: 620px; margin: 0 auto; background: #fff; border-radius: 18px; padding: 26px 28px 30px; box-shadow: 0 10px 40px rgba(224, 90, 109, .12); }
h1 { font-size: 22px; margin: 0 0 4px; color: #d94f66; }
p.hint { color: #a8878f; font-size: 13px; }
label { display: block; margin: 14px 0 4px; font-size: 14px; font-weight: 600; }
input { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1.5px solid #f0d4d9; border-radius: 10px; font-size: 15px; }
input:focus { outline: none; border-color: #e05a6d; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
button { margin-top: 20px; width: 100%; padding: 12px; font-size: 16px; border: none; border-radius: 12px; background: #e05a6d; color: #fff; font-weight: 700; cursor: pointer; }
button:hover { background: #d14a5e; }
.err { background: #fff1f2; color: #c94b5f; border-radius: 10px; padding: 10px 12px; margin-top: 14px; white-space: pre-wrap; font-size: 14px; }
.ok { background: #f1fbf3; color: #3c8a54; border-radius: 10px; padding: 14px; margin-top: 14px; font-size: 15px; line-height: 1.8; }
code, pre { background: #faf3f5; border-radius: 8px; }
pre { padding: 12px; overflow: auto; font-size: 12px; }
a { color: #d94f66; }
</style>
</head>
<body>
<div class="card">
<?php if ($done): ?>
  <h1>安装完成！</h1>
  <div class="ok">
    <p>数据库初始化成功，<?php echo thp_install_h($cfgNote); ?></p>
    <p><b>接下来：</b></p>
    <p>1. 访问 <a href="index.php">网站首页</a> 用刚才的管理员账号登录；<br>
       2. 为了安全，请在 FTP / 宝塔文件管理里删除 <b>install.php</b>；<br>
       3. 手机扫码功能需要 HTTPS 才能调起摄像头，请给域名配置 SSL 证书（宝塔面板一键申请）；<br>
       4. 可在管理后台的「设置」与「大盘」里继续微调。</p>
    <p>少女祈祷完毕，祝展会顺利～</p>
  </div>
<?php elseif ($isInstalled && !$force): ?>
  <h1>网站已经安装过了</h1>
  <p class="hint">检测到 config.php 已存在。如需重新安装，请点击下面的链接（会覆盖原配置）。</p>
  <p><a href="install.php?force=1">重新安装</a> ｜ <a href="index.php">返回网站</a></p>
<?php else: ?>
  <h1>安装向导</h1>
  <p class="hint">第一步：在宝塔面板里创建一个 MySQL 数据库（记住库名 / 用户名 / 密码）；第二步：把连接信息填进来，点下面的按钮即可完成。</p>
  <?php if ($error !== ''): ?><div class="err"><?php echo thp_install_h($error); ?></div><?php endif; ?>
  <form method="post">
    <div class="grid2">
      <div><label>数据库地址</label><input name="db_host" value="<?php echo thp_install_h($val('db_host', $dv('db.host', '127.0.0.1'))); ?>" required></div>
      <div><label>端口</label><input name="db_port" value="<?php echo thp_install_h($val('db_port', $dv('db.port', '3306'))); ?>" required></div>
    </div>
    <label>数据库名</label><input name="db_name" value="<?php echo thp_install_h($val('db_name', $dv('db.name', ''))); ?>" placeholder="例如 thp_site" required>
    <div class="grid2">
      <div><label>数据库用户名</label><input name="db_user" value="<?php echo thp_install_h($val('db_user', $dv('db.user', ''))); ?>" required></div>
      <div><label>数据库密码</label><input name="db_pass" type="password" value="<?php echo thp_install_h($val('db_pass', $dv('db.pass', ''))); ?>"></div>
    </div>
    <label>网站访问地址（用于二维码链接）</label><input name="base_url" value="<?php echo thp_install_h($val('base_url', $dv('base_url', $autoBase))); ?>" placeholder="https://example.com">
    <label>DeepSeek API Key（用于自动生成股市走势，可留空；装好后也可在后台「设置」里填）</label><input name="ai_key" value="<?php echo thp_install_h($val('ai_key', $dv('deepseek.key', ''))); ?>">
    <label>图片 OSS 前缀（可选，留空则使用服务器本地图片）</label><input name="oss_base_url" value="<?php echo thp_install_h($val('oss_base_url', $dv('oss_base_url', ''))); ?>" placeholder="https://xxx.oss-cn-hangzhou.aliyuncs.com/static">
    <label>管理员昵称</label><input name="admin_user" value="<?php echo thp_install_h($val('admin_user', 'admin')); ?>" required>
    <div class="grid2">
      <div><label>管理员密码</label><input name="admin_pass" type="password" required></div>
      <div><label>再输一次</label><input name="admin_pass2" type="password" required></div>
    </div>
    <?php if ($force): ?><input type="hidden" name="force" value="1"><?php endif; ?>
    <button type="submit">开始安装</button>
  </form>
<?php endif; ?>
</div>
</body>
</html>
