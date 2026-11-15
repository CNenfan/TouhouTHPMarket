<?php
declare(strict_types=1);
if (!defined('THP_APP')) { http_response_code(403); exit('forbidden'); }

// 读取 AI 配置：优先后台设置，其次 config.php
function thp_ai_config()
{
    global $CONFIG;
    $key = (string)thp_setting('ai_key', '');
    if ($key === '') {
        $key = isset($CONFIG['deepseek']['key']) ? (string)$CONFIG['deepseek']['key'] : '';
    }
    $endpoint = (string)thp_setting('ai_endpoint', '');
    if ($endpoint === '') {
        $endpoint = isset($CONFIG['deepseek']['endpoint']) ? (string)$CONFIG['deepseek']['endpoint'] : 'https://api.deepseek.com/chat/completions';
    }
    $model = (string)thp_setting('ai_model', '');
    if ($model === '') {
        $model = isset($CONFIG['deepseek']['model']) ? (string)$CONFIG['deepseek']['model'] : 'deepseek-chat';
    }
    return array('key' => $key, 'endpoint' => $endpoint, 'model' => $model);
}

function thp_ip_is_public($ip)
{
    $ip = trim((string)$ip);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) { return false; }
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return false; }
    if (strpos($ip, ':') === false) {
        $parts = array_map('intval', explode('.', $ip));
        if (count($parts) !== 4) { return false; }
        if ($parts[0] === 0 || $parts[0] === 10 || $parts[0] === 127) { return false; }
        if ($parts[0] === 169 && $parts[1] === 254) { return false; }
        if ($parts[0] === 172 && $parts[1] >= 16 && $parts[1] <= 31) { return false; }
        if ($parts[0] === 192 && $parts[1] === 168) { return false; }
        if ($parts[0] === 100 && $parts[1] >= 64 && $parts[1] <= 127) { return false; }
        if ($parts[0] >= 224) { return false; }
    } else {
        $low = strtolower($ip);
        if ($low === '::' || $low === '::1') { return false; }
        if (strpos($low, '::ffff:') === 0) {
            $mapped = substr($low, 7);
            return filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? thp_ip_is_public($mapped) : false;
        }
        if (strpos($low, 'fc') === 0 || strpos($low, 'fd') === 0 || strpos($low, 'fe8') === 0 || strpos($low, 'fe9') === 0
            || strpos($low, 'fea') === 0 || strpos($low, 'feb') === 0 || strpos($low, 'ff') === 0
            || strpos($low, '2001:db8:') === 0) {
            return false;
        }
    }
    return true;
}

// 只允许公网 HTTP(S) Endpoint，并返回可钉住的 IPv4，降低 SSRF 与 DNS 重绑定风险。
function thp_ai_endpoint_validate($url, &$pinIp = '', &$pinPort = 0)
{
    $pinIp = '';
    $pinPort = 0;
    $url = trim((string)$url);
    $parts = parse_url($url);
    if (!is_array($parts)) { return 'Endpoint 地址格式不正确'; }
    $scheme = strtolower(isset($parts['scheme']) ? (string)$parts['scheme'] : '');
    if ($scheme !== 'http' && $scheme !== 'https') { return 'Endpoint 只允许 http:// 或 https://'; }
    if (isset($parts['user']) || isset($parts['pass'])) { return 'Endpoint 不能包含用户名或密码'; }
    $host = strtolower(trim(isset($parts['host']) ? (string)$parts['host'] : '', '[]'));
    if ($host === '' || $host === 'localhost' || preg_match('/\.(local|internal|lan|home\.arpa)$/i', $host)) {
        return 'Endpoint 不允许指向本机或内网';
    }
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    if (!in_array($port, array(80, 443), true)) { return 'Endpoint 只允许 80 / 443 端口'; }
    $ips = array();
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        if (preg_match('/^[0-9.]+$/', $host)) { return 'Endpoint 主机地址不合法'; }
        $records = @dns_get_record($host, DNS_A);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (!empty($r['ip'])) { $ips[] = (string)$r['ip']; }
            }
        }
        if (count($ips) === 0) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) { $ips = array_merge($ips, $v4); }
        }
    }
    $ips = array_values(array_unique($ips));
    if (count($ips) === 0) { return 'Endpoint 域名无法解析，或只有暂不支持的 IPv6 地址'; }
    foreach ($ips as $ip) {
        if (!thp_ip_is_public($ip)) { return 'Endpoint 解析到了内网或保留地址，已拒绝'; }
    }
    $pinIp = (string)$ips[0];
    $pinPort = $port;
    return '';
}

// 调用兼容 OpenAI 格式的接口，生成走势参数；失败时通过 $errorOut 返回原因
function thp_ai_gen_params($companies, $style, $extraPrompt, &$errorOut = null)
{
    $errorOut = '';
    $cfg = thp_ai_config();
    if ($cfg['key'] === '') {
        $errorOut = '尚未配置 API Key（可在管理员后台的「设置」里填写）';
        return null;
    }
    $url = $cfg['endpoint'];
    if (strpos($url, '/chat/completions') === false) {
        $url = rtrim($url, '/') . '/chat/completions';
    }
    $pinIp = '';
    $pinPort = 0;
    $urlErr = thp_ai_endpoint_validate($url, $pinIp, $pinPort);
    if ($urlErr !== '') {
        $errorOut = $urlErr;
        return null;
    }
    $list = array();
    foreach ($companies as $c) {
        $list[] = array('id' => (string)$c['id'], 'name' => (string)$c['name']);
    }
    $style = trim((string)$style);
    $meta = thp_trend_style_get($style);
    if ($meta === null) { $style = 'mild'; $meta = thp_trend_style_get('mild'); }
    $styleName = (string)$meta['name'] . '风格';
    $ratioMin = (float)$meta['ratio_min'];
    $ratioMax = max($ratioMin, (float)$meta['ratio_max']);
    $minPct = (int)round(($ratioMin - 1.0) * 100);
    $maxPct = (int)round(($ratioMax - 1.0) * 100);
    if ($style === 'volatile') {
        $rules = '规则：一天共 2880 个价格点，每 30 秒一个点；走势要大起大落，单次跳动幅度最高可以到 20，大步与小步混搭；大盘总体仍然要上涨；每天 08:00 之前和 17:00 之后波动要收敛一些。';
        $paramHint = '每家公司需要给出四个参数：start（开盘价，10-30 的整数，整体偏低）、target_ratio（收盘价/开盘价，0.9-1.35，允许个别公司收跌，但整体要上涨）、volatility（波动强度，50-100 的整数）、seed（任意整数）。';
    } elseif ($style === 'realistic') {
        $rules = '规则：一天共 2880 个价格点，每 30 秒一个点；要像真实股市一样走极端：允许个别公司从高处断崖跳水、之后长期趴在 0.0x 的低价（最低 0.01），也允许个别公司起飞后涨到几十倍且居高不下，还允许冲高后崩盘；便宜的股票必须还能继续跌，不能出现「买低必赚」。';
        $paramHint = '每家公司需要给出四个参数：start（开盘价，5-60 的数，可带两位小数）、target_ratio（收盘价/开盘价：<0.85 表示要走「跳水 / 崩盘」剧本，>1.8 表示要走「飞升」剧本，0.85-1.8 之间会随机分配慢牛或震荡；请至少安排一家 ≤0.3 和一家 ≥3）、volatility（波动强度，50-100 的整数）、seed（任意整数）。';
    } elseif (!empty($meta['builtin'])) {
        $rules = '规则：一天共 2880 个价格点，每 30 秒一个点；每次跳动的幅度大致在 1~5 之间（幅度可小可大）；大盘总体必须上涨；每天 08:00 之前和 17:00 之后只允许 1~2 的小幅波动，08:00-17:00 波动较大。';
        $paramHint = '每家公司需要给出四个参数：start（开盘价，10-30 的整数，整体偏低）、target_ratio（收盘价/开盘价，1.05-1.25，温和上涨即可）、volatility（波动强度，20-80 的整数）、seed（任意整数）。';
    } else {
        $rules = '规则：一天共 2880 个价格点，每 30 秒一个点；这是管理员自定义的「' . (string)$meta['name'] . '」风格，单次绝对跳动幅度不得超过 ' . (int)$meta['max_step'] . '；收盘价相对开盘价的目标范围是 ' . $minPct . '% 到 +' . $maxPct . '%；每天 08:00 之前和 17:00 之后波动必须更收敛。' . ($maxPct > 0 ? '在不违反涨跌范围的前提下，尽量让大盘整体向上。' : '整体走势应符合自定义风格的涨跌范围。');
        $paramHint = '每家公司需要给出四个参数：start（开盘价，10-30 的整数，整体偏低）、target_ratio（收盘价/开盘价，必须在 ' . round($ratioMin, 3) . '-' . round($ratioMax, 3) . ' 之间）、volatility（波动强度，建议 ' . max(5, (int)$meta['volatility'] - 15) . '-' . min(100, (int)$meta['volatility'] + 15) . ' 的整数）、seed（任意整数）。';
    }
    $extra = trim((string)$extraPrompt);
    $prompt = '你是模拟股市走势数据设计器。请为一场漫展的线上炒股小游戏生成一份走势参数（' . $styleName . '）。'
        . $rules
        . "\n" . $paramHint
        . "\n" . '公司列表：' . json_encode($list, JSON_UNESCAPED_UNICODE)
        . ($extra !== '' ? ("\n" . '管理员的额外要求（尽量满足，但不要违反上面的规则）：' . $extra) : '')
        . "\n" . '只输出一个 JSON 对象，不要输出任何其它文字，格式：{"companies":{"<id>":{"start":int,"target_ratio":float,"volatility":int,"seed":int}},"comment":"一句话点评"}';
    $body = array(
        'model' => $cfg['model'],
        'messages' => array(
            array('role' => 'system', 'content' => '你只输出 JSON，不输出多余文字。'),
            array('role' => 'user', 'content' => $prompt),
        ),
        'temperature' => 1.0,
        'max_tokens' => 2000,
        'stream' => false,
    );
    $ch = curl_init($url);
    $curlOpts = array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Authorization: Bearer ' . $cfg['key']),
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => false,
    );
    if (defined('CURLOPT_PROTOCOLS')) { $curlOpts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS; }
    if ($pinIp !== '') {
        $hostForResolve = trim((string)parse_url($url, PHP_URL_HOST), '[]');
        if ($hostForResolve !== '' && !filter_var($hostForResolve, FILTER_VALIDATE_IP)) {
            $curlOpts[CURLOPT_RESOLVE] = array($hostForResolve . ':' . $pinPort . ':' . $pinIp);
        }
    }
    curl_setopt_array($ch, $curlOpts);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $httpCode < 200 || $httpCode >= 300) {
        $errorOut = 'API 调用失败：' . ($curlErr !== '' ? $curlErr : ('HTTP ' . $httpCode . ' ' . mb_substr((string)$resp, 0, 300)));
        return null;
    }
    $json = json_decode((string)$resp, true);
    $content = '';
    if (is_array($json) && isset($json['choices'][0]['message']['content'])) {
        $content = (string)$json['choices'][0]['message']['content'];
    }
    if ($content === '') {
        $errorOut = 'API 响应中没有找到内容';
        return null;
    }
    if (preg_match('/\{.*\}/s', $content, $m)) { $content = $m[0]; }
    $parsed = json_decode($content, true);
    if (!is_array($parsed) || !isset($parsed['companies']) || !is_array($parsed['companies'])) {
        $errorOut = 'API 返回的内容不是合法 JSON 走势参数';
        return null;
    }
    $out = array();
    foreach ($parsed['companies'] as $id => $p) {
        if (!is_array($p)) { continue; }
        $start = isset($p['start']) ? (int)$p['start'] : 0;
        if ($start < 5 || $start > 500) { $start = random_int(10, 30); }
        $ratio = isset($p['target_ratio']) ? (float)$p['target_ratio'] : 1.15;
        if ($style === 'realistic') {
            // 拟真风格允许「大跌剧本」（ratio 可以很小），只拦明显离谱的数据
            if ($ratio < 0.01 || $ratio > 5.0) { $ratio = 1.15; }
        } else {
            if (!is_finite($ratio)) { $ratio = ($ratioMin + $ratioMax) / 2.0; }
            $ratio = max($ratioMin, min($ratioMax, $ratio));
        }
        $vol = isset($p['volatility']) ? (int)$p['volatility'] : 45;
        if (!$meta['builtin']) {
            $volLo = max(5, (int)$meta['volatility'] - 15);
            $volHi = min(100, (int)$meta['volatility'] + 15);
            if ($vol < $volLo || $vol > $volHi) { $vol = random_int($volLo, $volHi); }
        } elseif ($vol < 5 || $vol > 100) {
            $vol = 45;
        }
        $seed = isset($p['seed']) ? (int)$p['seed'] : random_int(1, 2000000000);
        $out[(string)$id] = array('start' => $start, 'target_ratio' => $ratio, 'volatility' => $vol, 'seed' => $seed);
    }
    if (count($out) === 0) {
        $errorOut = 'API 没有返回任何有效的公司参数';
        return null;
    }
    if (isset($parsed['comment'])) {
        $errorOut = '__comment__:' . (string)$parsed['comment'];
    }
    return $out;
}

// 通用 OpenAI 兼容对话调用，返回纯文本。Endpoint 仍经过公网地址校验。
function thp_ai_chat_content($system, $prompt, $temperature = 0.8, $maxTokens = 1600)
{
    $cfg = thp_ai_config();
    if ($cfg['key'] === '') { throw new ThpError('尚未配置 AI Key，请先到「设置」里填写'); }
    $url = $cfg['endpoint'];
    if (strpos($url, '/chat/completions') === false) { $url = rtrim($url, '/') . '/chat/completions'; }
    $pinIp = '';
    $pinPort = 0;
    $err = thp_ai_endpoint_validate($url, $pinIp, $pinPort);
    if ($err !== '') { throw new ThpError($err); }
    $body = array(
        'model' => $cfg['model'],
        'messages' => array(
            array('role' => 'system', 'content' => (string)$system),
            array('role' => 'user', 'content' => (string)$prompt),
        ),
        'temperature' => max(0.0, min(1.5, (float)$temperature)),
        'max_tokens' => max(64, min(8000, (int)$maxTokens)),
        'stream' => false,
    );
    $ch = curl_init($url);
    $opts = array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Authorization: Bearer ' . $cfg['key']),
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => false,
    );
    if (defined('CURLOPT_PROTOCOLS')) { $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS; }
    if ($pinIp !== '') {
        $host = trim((string)parse_url($url, PHP_URL_HOST), '[]');
        if ($host !== '' && !filter_var($host, FILTER_VALIDATE_IP)) {
            $opts[CURLOPT_RESOLVE] = array($host . ':' . $pinPort . ':' . $pinIp);
        }
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $httpCode < 200 || $httpCode >= 300) {
        throw new ThpError('AI 调用失败：' . ($curlErr !== '' ? $curlErr : ('HTTP ' . $httpCode . ' ' . mb_substr((string)$resp, 0, 240))));
    }
    $json = json_decode((string)$resp, true);
    $content = is_array($json) && isset($json['choices'][0]['message']['content']) ? trim((string)$json['choices'][0]['message']['content']) : '';
    if ($content === '') { throw new ThpError('AI 没有返回有效内容'); }
    return $content;
}

// 管理员给方向，AI 补完一条幻想乡新闻；只返回文本，不直接发布。
function thp_ai_news_draft($direction, $companies, $kind, $positiveIds, $negativeIds, $impact)
{
    $companyList = array();
    foreach ($companies as $c) { $companyList[] = array('id' => (string)$c['id'], 'name' => (string)$c['name']); }
    $prompt = '你是东方 Project 展会里「天狗新闻社」的编辑。请根据管理员给的大致方向，写一条适合手机阅读的短新闻。'
        . "\n类型：" . ($kind === 'notice' ? '全站通知（不直接影响股价）' : '股市新闻（只影响未来走势）')
        . "\n管理员方向：" . trim((string)$direction)
        . "\n公司列表：" . json_encode($companyList, JSON_UNESCAPED_UNICODE)
        . "\n利好公司 ID：" . (string)$positiveIds . '；利空公司 ID：' . (string)$negativeIds . '；影响幅度：' . (float)$impact . '%。'
        . "\n要求：标题 8-24 字；正文 60-180 字；东方同人活动语气，轻松但不要低俗；只输出 JSON，不要 Markdown。"
        . "\n格式：{\"title\":\"...\",\"body\":\"...\"}";
    $content = thp_ai_chat_content('你只输出合法 JSON，不输出任何额外文字。', $prompt, 0.9, 700);
    if (preg_match('/\{.*\}/s', $content, $m)) { $content = $m[0]; }
    $parsed = json_decode($content, true);
    if (!is_array($parsed) || empty($parsed['title']) || empty($parsed['body'])) { throw new ThpError('AI 返回的新闻格式不正确，请重试'); }
    return array('title' => trim((string)$parsed['title']), 'body' => trim((string)$parsed['body']));
}
