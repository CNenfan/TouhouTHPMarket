<?php
// 数据库 v8 升级：新增公司随机事件表，并为当前游客创建的公司补足至少 2 条事件。
declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    $expected = '';
    $keyFile = __DIR__ . '/.thp-migration-key.php';
    if (is_file($keyFile)) {
        $loadedKey = require $keyFile;
        if (is_string($loadedKey)) { $expected = trim($loadedKey); }
    }
    if ($expected === '' || !isset($_GET['key']) || !hash_equals($expected, (string)$_GET['key'])) {
        http_response_code(404);
        echo "Not found\n";
        exit;
    }
}

$configCandidates = array(
    dirname(__DIR__) . '/public/config.php',
    dirname(__DIR__) . '/config.php',
    __DIR__ . '/config.php',
);
$configFile = '';
foreach ($configCandidates as $candidate) {
    if (@is_file($candidate)) { $configFile = $candidate; break; }
}
if ($configFile === '') { echo "config.php not found\n"; exit(1); }
$config = require $configFile;
date_default_timezone_set(!empty($config['timezone']) ? (string)$config['timezone'] : 'Asia/Shanghai');
$db = isset($config['db']) && is_array($config['db']) ? $config['db'] : array();
$dsn = 'mysql:host=' . (string)($db['host'] ?? '127.0.0.1')
    . ';port=' . (int)($db['port'] ?? 3306)
    . ';dbname=' . (string)($db['name'] ?? '')
    . ';charset=utf8mb4';
$pdo = new PDO($dsn, (string)($db['user'] ?? ''), (string)($db['pass'] ?? ''), array(
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
));
$pdo->exec('SET NAMES utf8mb4');

function v8_random_impact(): float
{
    return round(mt_rand(35, 135) / 10, 1);
}

function v8_pick(array $items): array
{
    return $items[array_rand($items)];
}

$singlePositive = array(
    array('外界订单突然涌入', '一封盖着外界邮戳的大订单落在公司前台。发货组连夜加班，市场对短期营收充满期待。'),
    array('新品在评测中意外走红', '原本只是试作的商品被路过的行家拍下，口碑沿着妖怪之山一路传开，订单开始变多。'),
    array('天狗号外给出整版好评', '天狗记者把这家公司写进了今日头版。虽然配图有点夸张，但读者显然很买账。'),
    array('临时摊位引发排队热潮', '公司临时加开的摊位居然后来居上，队伍一路排到了隔壁摊位，连路过的妖精都来凑热闹。'),
    array('合作邀约从隙间送来', '一份没有署名的合作邀约突然出现在办公桌上，条件好得不太真实，但市场先选择相信。'),
);
$singleNegative = array(
    array('仓库被妖精搬空', '夜班守卫打了个盹，仓库里的紧俏货就被妖精们当成收藏品搬走了。补货需要时间。'),
    array('原料运输遭遇异变', '运输队在半路撞上了不知名的结界，原料抵达时间被迫推迟，短期交付承压。'),
    array('匿名传单质疑经营状况', '一份匿名传单在人群中流传，内容真假难辨，但投资者显然开始变得谨慎。'),
    array('设备在夜里自己跑了', '公司最倚重的一台设备在深夜自行启动又失踪，维修与停工成本让账面不太好看。'),
    array('退货单堆满了前台', '一批商品因为尺寸不合被集中退回，前台被退货单淹没，现金流短期吃紧。'),
);
$multiTemplates = array(
    array('联合展销会临时加场', '几家公司的展位被安排在同一条街上，客流互相带动，现场气氛比预想中更热闹。'),
    array('供应链异变出现连锁反应', '妖怪山物流短暂中断，有的公司抢到了替代原料，有的只能推迟交付，几家公司的命运在同一条线上分化。'),
    array('天狗号外同时点名多家公司', '天狗记者把几家公司写进了同一篇报道。有人因此获得关注，也有人被迫面对更严格的围观。'),
    array('妖怪山物流短暂中断', '一场突如其来的大雾让运输队集体迷路，相关公司的交付节奏都被打乱。'),
    array('商会发起跨界联名', '幻想乡商会临时撮合了一次跨界联名，有的公司拿到稀缺渠道，有的公司只分到了仓库角落。'),
    array('临时限电打乱交付节奏', '守矢能源进行临时检修，部分公司被迫调整生产班次，订单与库存出现明显分化。'),
);

$changes = array();
$seeded = 0;
$newsSeeded = 0;
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'settings'");
    $st->execute();
    if ((int)$st->fetchColumn() === 0) {
        throw new RuntimeException('settings table not found; run the v7 upgrade or schema.sql first');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS company_events (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      news_id BIGINT UNSIGNED NULL,
      anchor_company_id INT UNSIGNED NOT NULL,
      title VARCHAR(120) NOT NULL,
      body TEXT NOT NULL,
      positive_company_ids VARCHAR(255) NOT NULL DEFAULT '',
      negative_company_ids VARCHAR(255) NOT NULL DEFAULT '',
      impact_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
      source ENUM('random','manual','ai') NOT NULL DEFAULT 'random',
      effective_at DATETIME NOT NULL,
      applied_at DATETIME NULL,
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY idx_pending (applied_at, effective_at),
      KEY idx_anchor_time (anchor_company_id, effective_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $changes[] = 'company_events table';

    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'company_events' AND column_name = 'news_id'");
    $st->execute();
    if ((int)$st->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE company_events ADD COLUMN news_id BIGINT UNSIGNED NULL AFTER id');
        $changes[] = 'company_events.news_id';
    }
    $pdo->exec("ALTER TABLE news_items MODIFY source ENUM('manual','ai','auto','trade','notice','event') NOT NULL DEFAULT 'manual'");
    $changes[] = 'news_items.source includes event';

    $companies = $pdo->query('SELECT id, name, is_default FROM companies ORDER BY id')->fetchAll();
    $visitorCompanies = array_values(array_filter($companies, function ($company) { return (int)$company['is_default'] === 0; }));
    $companyIds = array_map(function ($company) { return (int)$company['id']; }, $companies);
    $insert = $pdo->prepare('INSERT INTO company_events
        (anchor_company_id, title, body, positive_company_ids, negative_company_ids, impact_percent, source, effective_at, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM company_events WHERE anchor_company_id = ?');
    $newsInsert = $pdo->prepare('INSERT INTO news_items
        (template_id, kind, title, body, positive_company_ids, negative_company_ids, impact_percent, source, popup_enabled, pinned, pinned_until, effect_applied_at, published_at, created_at)
        VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, 0, 0, NULL, ?, ?, ?)');
    $eventNewsUpdate = $pdo->prepare('UPDATE company_events SET news_id = ? WHERE id = ?');

    $pdo->beginTransaction();

    foreach ($visitorCompanies as $company) {
        $anchorId = (int)$company['id'];
        $countStmt->execute(array($anchorId));
        $existing = (int)$countStmt->fetchColumn();
        for ($slot = $existing; $slot < 2; $slot++) {
            $isMulti = ($slot === 1);
            $impact = v8_random_impact();
            $positiveIds = array();
            $negativeIds = array();
            if (!$isMulti) {
                $template = v8_pick(array_rand(array(0, 1)) === 1 ? $singlePositive : $singleNegative);
                if (in_array($template, $singlePositive, true)) { $positiveIds[] = $anchorId; }
                else { $negativeIds[] = $anchorId; }
            } else {
                $others = array_values(array_filter($companyIds, function ($id) use ($anchorId) { return $id !== $anchorId; }));
                shuffle($others);
                $targets = array($anchorId);
                $extraCount = (count($others) > 1 && mt_rand(1, 100) <= 45) ? 2 : 1;
                for ($i = 0; $i < $extraCount && $i < count($others); $i++) { $targets[] = (int)$others[$i]; }
                $template = v8_pick($multiTemplates);
                $pattern = mt_rand(1, 100);
                if ($pattern <= 30) {
                    $positiveIds = $targets;
                } elseif ($pattern <= 55) {
                    $negativeIds = $targets;
                } elseif ($pattern <= 78) {
                    $positiveIds[] = $anchorId;
                    foreach (array_slice($targets, 1) as $id) { $negativeIds[] = $id; }
                } else {
                    $negativeIds[] = $anchorId;
                    foreach (array_slice($targets, 1) as $id) { $positiveIds[] = $id; }
                }
            }
            $waitMinutes = $slot === 0 ? mt_rand(10, 90) : mt_rand(90, 330);
            $effectiveAt = date('Y-m-d H:i:s', time() + $waitMinutes * 60 + mt_rand(0, 179));
            $insert->execute(array(
                $anchorId,
                (string)$template[0],
                (string)$template[1],
                implode(',', array_unique($positiveIds)),
                implode(',', array_unique($negativeIds)),
                $impact,
                'random',
                $effectiveAt,
                date('Y-m-d H:i:s'),
            ));
            $eventId = (int)$pdo->lastInsertId();
            $eventTitle = (string)$template[0];
            $eventBody = (string)$template[1];
            $positiveRaw = implode(',', array_unique($positiveIds));
            $negativeRaw = implode(',', array_unique($negativeIds));
            $newsInsert->execute(array('market', $eventTitle, $eventBody, $positiveRaw, $negativeRaw, $impact, 'event', $effectiveAt, $effectiveAt, date('Y-m-d H:i:s')));
            $eventNewsUpdate->execute(array((int)$pdo->lastInsertId(), $eventId));
            $newsSeeded++;
            $seeded++;
        }
    }
    $changes[] = 'visitor_companies=' . count($visitorCompanies);
    $changes[] = 'seeded_events=' . $seeded;

    $missingNews = $pdo->query('SELECT id, title, body, positive_company_ids, negative_company_ids, impact_percent, effective_at FROM company_events WHERE news_id IS NULL ORDER BY id')->fetchAll();
    foreach ($missingNews as $event) {
        $newsInsert->execute(array('market', (string)$event['title'], (string)$event['body'], (string)$event['positive_company_ids'], (string)$event['negative_company_ids'], (float)$event['impact_percent'], 'event', (string)$event['effective_at'], (string)$event['effective_at'], date('Y-m-d H:i:s')));
        $eventNewsUpdate->execute(array((int)$pdo->lastInsertId(), (int)$event['id']));
        $newsSeeded++;
    }
    $changes[] = 'event_news=' . $newsSeeded;

    $st = $pdo->prepare("INSERT INTO settings (k, v) VALUES ('schema_version', '8') ON DUPLICATE KEY UPDATE v = '8'");
    $st->execute();
    $changes[] = 'schema_version=8';
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    echo "v8 upgrade failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "DB upgrade v8 completed.\n";
echo "Changes: " . implode(', ', array_values(array_unique($changes))) . "\n";
