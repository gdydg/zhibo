<?php
/**
 * KoraZone TV m3u8 定时生成脚本 (Docker 后台适配版)
 */

set_time_limit(0);
date_default_timezone_set('Asia/Shanghai');

$targetUrl = 'https://korazone.tv/';
$outputFile = __DIR__ . '/data/korazone.m3u'; 
$cacheFile = __DIR__ . '/data/translation_cache.json';

echo "开始抓取数据...\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $targetUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
]);
$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($html === false || $httpCode !== 200) {
    die("抓取失败！HTTP状态码: {$httpCode}\n");
}

$cleanHtml = stripslashes($html);
$jsonStartFlag = '{"liveStreams":[';
$startPos = strpos($cleanHtml, $jsonStartFlag);

if ($startPos === false) die("未能在源码中找到 JSON 数据的起始标志。\n");

$jsonString = extractBalancedJson($cleanHtml, $startPos);
if (!$jsonString) die("JSON 括号匹配失败。\n");

$data = json_decode($jsonString);
if (json_last_error() !== JSON_ERROR_NONE) die("JSON 解析失败。\n");

$rawStreams = [];
if (isset($data->liveStreams) && is_array($data->liveStreams)) {
    $rawStreams = array_merge($rawStreams, $data->liveStreams);
}
if (isset($data->upcomingSchedule) && is_array($data->upcomingSchedule)) {
    foreach ($data->upcomingSchedule as $match) {
        if (isset($match->streams) && is_array($match->streams)) {
            $rawStreams = array_merge($rawStreams, $match->streams);
        }
    }
}

if (empty($rawStreams)) die("没有找到任何有效的 m3u8 直播源。\n");

$now = time(); 
$validStreams = [];
$uniqueUrls = [];

$translationCache = file_exists($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : [];
if (!is_array($translationCache)) $translationCache = [];
$cacheUpdated = false;

foreach ($rawStreams as $stream) {
    if (empty($stream->streamUrl) || in_array($stream->streamUrl, $uniqueUrls)) continue;

    $streamDate = isset($stream->streamDate) ? $stream->streamDate : '';
    $matchTime = strtotime($streamDate);
    if (!$matchTime) $matchTime = $now; 

    if (($now - $matchTime) > 14400) continue; 

    $uniqueUrls[] = $stream->streamUrl;
    $group = ($matchTime <= $now) ? 'korazone-比赛中' : 'korazone-待开赛';
    $timeDiff = abs($now - $matchTime);

    $homeTeam    = isset($stream->homeTeamName) ? trim($stream->homeTeamName) : '未知主队';
    $awayTeam    = isset($stream->awayTeamName) ? trim($stream->awayTeamName) : '未知客队';
    $translatedHome   = smartTranslate($homeTeam, $translationCache, $cacheUpdated);
    $translatedAway   = smartTranslate($awayTeam, $translationCache, $cacheUpdated);
    $formattedTime    = formatTimeToBeijing($streamDate);

    $channelName = "{$translatedHome} vs {$translatedAway} ({$formattedTime})";

    $validStreams[] = [
        'diff'  => $timeDiff,
        'group' => $group,
        'name'  => $channelName,
        'url'   => $stream->streamUrl
    ];
}

if ($cacheUpdated) {
    file_put_contents($cacheFile, json_encode($translationCache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

usort($validStreams, function($a, $b) {
    return $a['diff'] <=> $b['diff'];
});

$m3uContent = "#EXTM3U\n";
foreach ($validStreams as $s) {
    $m3uContent .= "#EXTINF:-1 tvg-id=\"\" tvg-name=\"{$s['name']}\" group-title=\"{$s['group']}\",{$s['name']}\n";
    $m3uContent .= "{$s['url']}\n";
}

if (file_put_contents($outputFile, $m3uContent) !== false) {
    $count = count($validStreams);
    echo "KoraZone: 成功写入 {$count} 个源。\n";
} else {
    echo "KoraZone: 写入失败！\n";
}

// 辅助函数
function smartTranslate($text, &$cache, &$cacheUpdated) {
    $text = trim($text);
    if (empty($text)) return $text;
    if (preg_match('/^[A-Z0-9\s\.\-]{2,6}$/', $text)) return $text;

    static $static_map = [
        'Real Madrid' => '皇家马德里', 'Barcelona' => '巴塞罗那', 'Atletico Madrid' => '马德里竞技',
        'Manchester United' => '曼联', 'Manchester City' => '曼城', 'Arsenal' => '阿森纳',
        'Liverpool' => '利物浦', 'Chelsea' => '切尔西', 'Tottenham Hotspur' => '热刺',
        'Juventus' => '尤文图斯', 'Inter Milan' => '国际米兰', 'AC Milan' => 'AC米兰',
        'Bayern Munich' => '拜仁慕尼黑', 'Borussia Dortmund' => '多特蒙德', 'Paris Saint-Germain' => '巴黎圣日耳曼'
    ];
    if (isset($static_map[$text])) return $static_map[$text];
    if (isset($cache[$text])) return $cache[$text];

    $translatedText = googleTranslateAPI($text);
    if ($translatedText && $translatedText !== $text) {
        $cache[$text] = $translatedText;
        $cacheUpdated = true;
        return $translatedText;
    }
    return $text; 
}

function googleTranslateAPI($text) {
    $url = "https://translate.googleapis.com/translate_a/single?client=gtx&sl=auto&tl=zh-CN&dt=t&q=" . urlencode($text);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $data = json_decode($response, true);
        if (isset($data[0][0][0])) {
            usleep(100000); 
            return $data[0][0][0];
        }
    }
    return false;
}

function extractBalancedJson($str, $startPos) {
    $depth = 0; $inString = false; $escape = false;
    for ($i = $startPos; $i < strlen($str); $i++) {
        $char = $str[$i];
        if ($escape) { $escape = false; continue; }
        if ($char === '\\') { $escape = true; continue; }
        if ($char === '"') { $inString = !$inString; continue; }
        if (!$inString) {
            if ($char === '{') $depth++;
            elseif ($char === '}') {
                $depth--;
                if ($depth === 0) return substr($str, $startPos, $i - $startPos + 1);
            }
        }
    }
    return null;
}

function formatTimeToBeijing($utcString) {
    if (empty($utcString)) return '时间未知';
    try {
        $dateTime = new DateTime($utcString, new DateTimeZone('UTC'));
        $dateTime->setTimezone(new DateTimeZone('Asia/Shanghai'));
        return $dateTime->format('m-d H:i');
    } catch (Exception $e) {
        return '时间格式错误';
    }
}
