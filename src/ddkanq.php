<?php
/**
 * DDKANQ 体育直播抓取脚本 (Docker 后台 CLI 版) - 极速优化版
 */

date_default_timezone_set('Asia/Shanghai');
@set_time_limit(0);

$baseUrl = "https://www.ddkanqiu.cc";
$outputFile = __DIR__ . '/data/ddkanq.m3u';

// 确保数据目录存在
if (!is_dir(dirname($outputFile))) {
    mkdir(dirname($outputFile), 0755, true);
}

function log_msg($msg) {
    echo "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
}

function http_get($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

log_msg("DDKANQ: 开始极速抓取...");

$currentTime = time();
$startTime = $currentTime - (4 * 3600); // 抓取过去 4 小时
$endTime = $currentTime + (30 * 60);    // 抓取未来 30 分钟

$html = http_get($baseUrl);
if (!$html) {
    die("DDKANQ: 无法获取主页数据。\n");
}

$dom = new DOMDocument();
// 抑制 HTML 不规范导致的警告
@$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
$xpath = new DOMXPath($dom);
$matchNodes = $xpath->query("//a[contains(@class, 'match-link')]");

$m3uOutput = "#EXTM3U\n";
$matchCount = 0;

foreach ($matchNodes as $node) {
    $dateStr = $node->getAttribute('data-rowdate');
    if (empty($dateStr)) continue;

    $matchTime = strtotime($dateStr);
    if (!$matchTime) continue;

    // 时间过滤
    if ($matchTime < $startTime || $matchTime > $endTime) continue;

    $leagueNodes = $xpath->query(".//div[contains(@class, 'match-type')]", $node);
    $homeNodes = $xpath->query(".//span[@class='left-team']", $node);
    $awayNodes = $xpath->query(".//span[@class='right-team']", $node);

    $league = $leagueNodes->length > 0 ? trim($leagueNodes->item(0)->textContent) : "未知赛事";
    $home = $homeNodes->length > 0 ? trim($homeNodes->item(0)->textContent) : "未知主队";
    $away = $awayNodes->length > 0 ? trim($awayNodes->item(0)->textContent) : "未知客队";
    
    $timeStr = date('H:i', $matchTime);
    $matchTitle = "[{$timeStr}]{$league}:{$home}VS{$away}";

    // 直接从当前赛事的 DOM 节点下提取 HTML 注释，寻找 m3u8 链接
    $m3u8Url = "";
    $comments = $xpath->query(".//comment()", $node);
    foreach ($comments as $comment) {
        $commentText = trim($comment->nodeValue);
        // 如果注释内容里包含 m3u8 或者以 http 开头，这就是我们要的直播源
        if (strpos($commentText, '.m3u8') !== false || strpos($commentText, 'http') === 0) {
            $m3u8Url = $commentText;
            break;
        }
    }

    // 过滤掉提取不到源的比赛
    if (empty($m3u8Url) || strpos($m3u8Url, 'm3u8') === false) continue;

    // 清理 URL 中可能存在的 HTML 实体编码
    $m3u8Url = str_replace('&amp;', '&', $m3u8Url);
    
    // 写入 m3u 格式，统一 group-title 为 "其他比赛"
    $m3uOutput .= "#EXTINF:-1 tvg-name=\"{$home}VS{$away}\" group-title=\"其他比赛\", {$matchTitle}\n";
    $m3uOutput .= "{$m3u8Url}\n";
    $matchCount++;
}

if (file_put_contents($outputFile, $m3uOutput) !== false) {
    log_msg("DDKANQ: 抓取完成！成功写入 {$matchCount} 条源到 {$outputFile}");
} else {
    log_msg("DDKANQ: 写入失败，请检查 data 目录权限。");
}
