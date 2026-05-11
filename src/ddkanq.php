<?php
/**
 * DDKANQ 体育直播抓取脚本 (Docker 后台 CLI 版) - 正则极速版
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
// 恢复为原定的时间范围
$startTime = $currentTime - (4 * 3600); // 抓取过去 4 小时内开赛的
$endTime = $currentTime + (30 * 60);    // 抓取未来 30 分钟内开赛的

$html = http_get($baseUrl);
if (!$html) {
    die("DDKANQ: 无法获取主页数据。\n");
}

// 弃用 DOMDocument，改用字符串分割，完美兼容不规范的 HTML
$blocks = explode('class="col-12 match-link', $html);
array_shift($blocks); // 丢弃第一段不相关的顶部源码

$m3uOutput = "#EXTM3U\n";
$matchCount = 0;

foreach ($blocks as $block) {
    // 1. 提取比赛时间
    if (!preg_match('/data-rowdate="([^"]+)"/', $block, $matches)) continue;
    $dateStr = trim($matches[1]);
    $matchTime = strtotime($dateStr);
    if (!$matchTime) continue;

    // 时间过滤
    if ($matchTime < $startTime || $matchTime > $endTime) continue;

    // 2. 提取赛事名称、主队、客队
    $league = preg_match('/class="match-type[^"]*">(.*?)<\/div>/s', $block, $m) ? trim(strip_tags($m[1])) : "未知赛事";
    $home = preg_match('/class="left-team[^"]*">(.*?)<\/span>/s', $block, $m) ? trim(strip_tags($m[1])) : "未知主队";
    $away = preg_match('/class="right-team[^"]*">(.*?)<\/span>/s', $block, $m) ? trim(strip_tags($m[1])) : "未知客队";
    
    $timeStr = date('H:i', $matchTime);
    $matchTitle = "[{$timeStr}]{$league}:{$home}VS{$away}";

    // 3. 直接提取 HTML 注释中的 m3u8 链接
    $m3u8Url = "";
    if (preg_match('/<!--\s*(https?:\/\/[^\s<>]+?\.m3u8[^\s<>]*?)\s*-->/i', $block, $m)) {
        $m3u8Url = trim($m[1]);
    }

    // 如果没提取到有效的 m3u8 链接，跳过
    if (empty($m3u8Url)) continue;

    // 清理 URL 转义字符
    $m3u8Url = str_replace('&amp;', '&', $m3u8Url);
    
    // 写入 M3U (统一 group-title)
    $m3uOutput .= "#EXTINF:-1 tvg-name=\"{$home}VS{$away}\" group-title=\"其他比赛\", {$matchTitle}\n";
    $m3uOutput .= "{$m3u8Url}\n";
    $matchCount++;
}

if (file_put_contents($outputFile, $m3uOutput) !== false) {
    log_msg("DDKANQ: 抓取完成！成功写入 {$matchCount} 条源。");
} else {
    log_msg("DDKANQ: 写入失败，请检查 data 目录权限。");
}
