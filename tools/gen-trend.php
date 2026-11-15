<?php
// 用法（本地开发用，需要数据库可用）：
//   php tools/gen-trend.php                                    生成三枚内置种子文件（default 温和 + volatile 波动 + realistic 拟真极端）
//   php tools/gen-trend.php --style=volatile --slug=my --name="我的走势"   只生成指定的一枚
//   php tools/gen-trend.php --style=volatile --slug=volatile --salt=随机串  用指定盐重新生成（换曲线，不改名字）
// 说明：种子文件只包含 5 家默认公司；自创公司会在运行时自动补进「当前生效」的文件。
//      realistic 风格会轮流给公司分配「跳水久不起 / 飞升不下来 / 冲高崩盘 / 慢牛 / 震荡」剧本，价格可低至 0.01。
declare(strict_types=1);
require dirname(__DIR__) . '/public/includes/bootstrap.php';

$args = array_slice($argv, 1);
function trend_arg($args, $key, $default = '')
{
    foreach ($args as $a) {
        if (strpos($a, '--' . $key . '=') === 0) { return substr($a, strlen($key) + 3); }
    }
    return $default;
}

$style = trend_arg($args, 'style');
$slug = trend_arg($args, 'slug');
$name = trend_arg($args, 'name');
$salt = trend_arg($args, 'salt');
$jobs = array();
if ($style !== '' || $slug !== '') {
    $st = in_array($style, array('mild', 'volatile', 'realistic'), true) ? $style : 'mild';
    $presets = array(
        'mild' => array('default', '温和上涨（内置）'),
        'volatile' => array('volatile', '大幅波动（内置）'),
        'realistic' => array('realistic', '拟真极端（内置）'),
    );
    $jobs[] = array(
        'style' => $st,
        'slug' => ($slug !== '' ? $slug : $presets[$st][0]),
        'name' => ($name !== '' ? $name : $presets[$st][1]),
    );
} else {
    $jobs[] = array('style' => 'mild', 'slug' => 'default', 'name' => '温和上涨（内置）');
    $jobs[] = array('style' => 'volatile', 'slug' => 'volatile', 'name' => '大幅波动（内置）');
    $jobs[] = array('style' => 'realistic', 'slug' => 'realistic', 'name' => '拟真极端（内置）');
}

$allCompanies = thp_companies();
$companies = array_values(array_filter($allCompanies, function ($c) { return !empty($c['is_default']); }));
echo '公司数量：' . count($companies) . '（只含默认公司）' . PHP_EOL;

foreach ($jobs as $job) {
    $value = thp_trend_build($companies, null, $job['style'], ($salt !== '' ? $salt : 'seed-' . $job['slug']));
    $err = thp_trend_validate($value);
    if ($err !== null) { echo '生成失败：' . $err . PHP_EOL; exit(1); }
    $value['name'] = $job['name'];
    $value['source'] = 'seed';
    $value['created_at'] = thp_now();
    $value['prompt'] = '';
    $file = thp_trend_path($job['slug']);
    $dir = thp_trend_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) { echo '无法创建目录：' . $dir . PHP_EOL; exit(1); }
    if (!thp_trend_save($file, $value)) { echo '写入失败：' . $file . PHP_EOL; exit(1); }
    echo '已生成：' . $file . '（风格 ' . $job['style'] . '）' . PHP_EOL;
    foreach ($value['companies'] as $id => $path) {
        $n = count($path);
        $maxStep = 0;
        for ($i = 1; $i < $n; $i++) {
            $d = abs($path[$i] - $path[$i - 1]);
            if ($d > $maxStep) { $maxStep = $d; }
        }
        $cname = '公司' . $id;
        foreach ($companies as $c) { if ((string)$c['id'] === (string)$id) { $cname = $c['name']; } }
        echo '  ' . $cname . '：开盘 ' . $path[0] . ' -> 收盘 ' . $path[$n - 1]
            . '（最低 ' . min($path) . '，最高 ' . max($path) . '，单步最大 ' . $maxStep . '）' . PHP_EOL;
    }
}
