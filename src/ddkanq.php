<?php
/**
 * DDKANQ 体育直播抓取脚本 (Docker 后台 CLI 版)
 */

date_default_timezone_set('Asia/Shanghai');
@set_time_limit(0);

$baseUrl = "https://ddkanq.com";
$outputFile = __DIR__ . '/data/ddkanq.m3u';

function log_msg($msg) {
    echo $msg . "\n";
}

function http_get($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

log_msg("DDKANQ: 开始抓取...");

$currentTime = time();
$startTime = $currentTime - (4 * 3600);
$endTime = $currentTime + (30 * 60);

$html = http_get($baseUrl);
if (!$html) {
    die("DDKANQ: 无法获取主页数据。\n");
}

$dom = new DOMDocument();
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

    if ($matchTime < $startTime || $matchTime > $endTime) continue;

    $href = $node->getAttribute('href');
    if (!$href) continue;

    $detailUrl = str_starts_with($href, 'http') ? $href : rtrim($baseUrl, '/') . '/' . ltrim($href, '/');

    $leagueNodes = $xpath->query(".//div[contains(@class, 'match-type')]", $node);
    $homeNodes = $xpath->query(".//span[@class='left-team']", $node);
    $awayNodes = $xpath->query(".//span[@class='right-team']", $node);

    $league = $leagueNodes->length > 0 ? trim($leagueNodes->item(0)->textContent) : "未知赛事";
    $home = $homeNodes->length > 0 ? trim($homeNodes->item(0)->textContent) : "未知主队";
    $away = $awayNodes->length > 0 ? trim($awayNodes->item(0)->textContent) : "未知客队";
    
    // 提取时分，并取消拼接时的所有空格
    $timeStr = date('H:i', $matchTime);
    $matchTitle = "[{$timeStr}]{$league}:{$home}VS{$away}";

    $detailHtml = http_get($detailUrl);
    if (!$detailHtml) continue;

    $m3u8Url = "";
    if (preg_match('/<span\s+id=[\'"]singlemoren[\'"].*?>(.*?)<\/span>/is', $detailHtml, $matches)) {
        $m3u8Url = trim(strip_tags($matches[1]));
    } elseif (preg_match('/id=[\'"]signalone[\'"].*?data-url=[\'"](.*?)[\'"]/is', $detailHtml, $matches)) {
        $m3u8Url = trim($matches[1]);
    }

    if (empty($m3u8Url) || strpos($m3u8Url, 'm3u8') === false) continue;

    $m3u8Url = str_replace('&amp;', '&', $m3u8Url);
    
    // 将 tvg-name 里的空格也一并去除了，保持统一
    $m3uOutput .= "#EXTINF:-1 tvg-name=\"{$home}VS{$away}\" group-title=\"其他比赛\", {$matchTitle}\n";
    $m3uOutput .= "{$m3u8Url}\n";
    $matchCount++;
}

if (file_put_contents($outputFile, $m3uOutput) !== false) {
    log_msg("DDKANQ: 成功写入 {$matchCount} 条源。");
} else {
    log_msg("DDKANQ: 写入失败。");
}
