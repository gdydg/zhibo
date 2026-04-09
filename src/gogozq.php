<?php
/**
 * GoGoZQ 自动抓取脚本（足球/篮球双分类，前 1.5 小时窗口，保留昨日晚间场次）
 */

set_time_limit(0);
ignore_user_abort(true);
date_default_timezone_set('Asia/Shanghai');

$nowTimestamp = time();
$todayDate = date('Y-m-d');
$todayStartTimestamp = strtotime($todayDate . ' 00:00:00');

// 昨日 20:00
$retentionCutoff = $todayStartTimestamp - (4 * 3600);

// 仅抓取前 90 分钟到当前时刻的比赛
$timeWindowBefore = 90 * 60;

$m3uFile = __DIR__ . '/data/gogozq.m3u';
$txtFile = __DIR__ . '/data/gogozq_live_links.txt';
$logFile = __DIR__ . '/data/gogozq_scraper_log.txt';

function writeLog($msg) {
    global $logFile;
    $currentTimeStr = date('Y-m-d H:i:s');
    $logEntry = "[{$currentTimeStr}] {$msg}\n";
    echo $logEntry;
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

function getHtml($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}

function getTimeBlock($timeStr) {
    if (!$timeStr) return '未知时间';
    $hour = (int)substr($timeStr, 0, 2);
    if ($hour >= 0 && $hour < 4) return '00:00-04:00';
    if ($hour >= 4 && $hour < 8) return '04:00-08:00';
    if ($hour >= 8 && $hour < 12) return '08:00-12:00';
    if ($hour >= 12 && $hour < 16) return '12:00-16:00';
    if ($hour >= 16 && $hour < 20) return '16:00-20:00';
    if ($hour >= 20 && $hour <= 23) return '20:00-24:00';
    return '未知时间';
}

writeLog('--- GoGoZQ 定时抓取任务启动 ---');

$allItems = [];
$existingUrls = [];
$existingTitles = [];

if (file_exists($m3uFile)) {
    $m3uLines = file($m3uFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    for ($i = 0; $i < count($m3uLines); $i++) {
        $line = trim($m3uLines[$i]);
        if (strpos($line, '#EXTINF') === 0) {
            $url = isset($m3uLines[$i + 1]) ? trim($m3uLines[$i + 1]) : '';
            if (preg_match('/group-title="([^"]+)", \[(\d{2}):(\d{2})\] (.*)/', $line, $m)) {
                $block = str_replace('昨日 ', '', $m[1]);
                $timeStr = "{$m[2]}:{$m[3]}";
                $title = $m[4];

                $itemDate = $todayDate;
                if (preg_match('/\((\d{4}-\d{2}-\d{2})\s*\)/', $title, $dateMatch)) {
                    $itemDate = $dateMatch[1];
                }

                $itemTimestamp = strtotime("{$itemDate} {$timeStr}:00");

                if ($itemTimestamp < $retentionCutoff) {
                    $i++;
                    continue;
                }

                $isYesterday = ($itemTimestamp < $todayStartTimestamp) ? 1 : 0;

                $allItems[] = [
                    'block' => $block,
                    'time' => $timeStr,
                    'title' => $title,
                    'url' => $url,
                    'timestamp' => $itemTimestamp,
                    'diff' => abs($itemTimestamp - $nowTimestamp),
                    'is_yesterday' => $isYesterday
                ];

                if ($url !== '') {
                    $existingUrls[$url] = true;
                }
                $existingTitles[$title] = true;
            }
            $i++;
        }
    }
}

$baseUrl = 'https://www.gogozq.cc';
$listUrls = [
    'https://www.gogozq.cc/category/zuqiu',
    'https://www.gogozq.cc/category/lanqiu'
];

$matchesData = [];
$tagPattern = '/<a[^>]*class="clearfix\s*"[^>]*>.*?<\/a>/is';
$skipCount = 0;

foreach ($listUrls as $listUrl) {
    writeLog("正在解析分类页面: {$listUrl}");
    $listHtml = getHtml($listUrl);
    if (!$listHtml) {
        writeLog("分类页面抓取失败: {$listUrl}");
        continue;
    }

    preg_match_all($tagPattern, $listHtml, $tagMatches);

    if (!empty($tagMatches[0])) {
        foreach ($tagMatches[0] as $tag) {
            preg_match('/data-time="([^"]+)"/i', $tag, $dateMatch);
            preg_match('/(\d{2}:\d{2})/i', $tag, $timeMatch);

            if (!empty($dateMatch[1]) && !empty($timeMatch[1])) {
                $matchDate = $dateMatch[1];
                $matchTime = $timeMatch[1];
                $matchTimestamp = strtotime("{$matchDate} {$matchTime}:00");

                if ($matchTimestamp >= ($nowTimestamp - $timeWindowBefore) && $matchTimestamp <= $nowTimestamp) {
                    preg_match('/href="([^"]+)"/i', $tag, $hrefMatch);
                    if (!empty($hrefMatch[1])) {
                        $homeTeam = '未知主队';
                        $awayTeam = '未知客队';

                        if (preg_match('/class=["\']team\s+zhudui[^"\']*["\'].*?<p>\s*([^<]+?)\s*<\/p>/is', $tag, $mHome)) {
                            $homeTeam = trim($mHome[1]);
                        }
                        if (preg_match('/class=["\']team\s+kedui[^"\']*["\'].*?<p>\s*([^<]+?)\s*<\/p>/is', $tag, $mAway)) {
                            $awayTeam = trim($mAway[1]);
                        }

                        $cleanTitle = $homeTeam . '-vs-' . $awayTeam;
                        if (strpos($cleanTitle, $matchDate) === false) {
                            $cleanTitle .= "({$matchDate})";
                        }

                        if (isset($existingTitles[$cleanTitle])) {
                            $skipCount++;
                            continue;
                        }

                        $matchesData[] = [
                            'url' => $hrefMatch[1],
                            'title' => $cleanTitle,
                            'time' => $matchTime,
                            'block' => getTimeBlock($matchTime),
                            'timestamp' => $matchTimestamp
                        ];
                    }
                }
            }
        }
    }

    usleep(500000);
}

$totalFound = count($matchesData);
writeLog("在规定时间窗口内，发现 {$totalFound} 场新比赛需抓取源，前置跳过 {$skipCount} 场已知比赛...");

$successCount = 0;
$urlSkipCount = 0;

foreach ($matchesData as $match) {
    $fullLink = strpos($match['url'], 'http') === 0 ? $match['url'] : $baseUrl . $match['url'];
    $detailHtml = getHtml($fullLink);
    if (!$detailHtml) {
        usleep(rand(500000, 1000000));
        continue;
    }

    $m3u8Pattern = '/src:\s*[\'\"]([^\'\"]+\.m3u8[^\'\"]*)[\'\"]/i';
    if (preg_match($m3u8Pattern, $detailHtml, $m3u8Match)) {
        $rawM3u8Url = $m3u8Match[1];
        $parsedUrl = parse_url($rawM3u8Url);
        $scheme = isset($parsedUrl['scheme']) ? $parsedUrl['scheme'] : 'https';
        $host = isset($parsedUrl['host']) ? $parsedUrl['host'] : '';
        $path = isset($parsedUrl['path']) ? $parsedUrl['path'] : '';

        if ($host && $path) {
            $cleanM3u8Url = $scheme . '://' . $host . $path;
            $cleanM3u8Url = str_replace('adaptive', '1080p', $cleanM3u8Url);

            if (isset($existingUrls[$cleanM3u8Url])) {
                $urlSkipCount++;
                usleep(rand(500000, 1000000));
                continue;
            }

            $isYesterday = ($match['timestamp'] < $todayStartTimestamp) ? 1 : 0;

            $allItems[] = [
                'block' => $match['block'],
                'time' => $match['time'],
                'title' => $match['title'],
                'url' => $cleanM3u8Url,
                'timestamp' => $match['timestamp'],
                'diff' => abs($match['timestamp'] - $nowTimestamp),
                'is_yesterday' => $isYesterday
            ];

            $existingUrls[$cleanM3u8Url] = true;
            $successCount++;
        }
    }

    usleep(rand(500000, 1000000));
}

$m3uHandle = fopen($m3uFile, 'w');
$txtHandle = fopen($txtFile, 'w');

fwrite($m3uHandle, "#EXTM3U\n");
fwrite($m3uHandle, "# DATE: {$todayDate}\n");

if (!empty($allItems)) {
    usort($allItems, function ($a, $b) use ($todayStartTimestamp) {
        $isYesterdayA = ($a['timestamp'] < $todayStartTimestamp) ? 1 : 0;
        $isYesterdayB = ($b['timestamp'] < $todayStartTimestamp) ? 1 : 0;

        if ($isYesterdayA !== $isYesterdayB) {
            return $isYesterdayA <=> $isYesterdayB;
        }

        return $a['diff'] <=> $b['diff'];
    });

    foreach ($allItems as $item) {
        $finalBlock = $item['is_yesterday'] ? '昨日 ' . $item['block'] : $item['block'];

        fwrite($m3uHandle, sprintf("#EXTINF:-1 group-title=\"%s\", [%s] %s\n", $finalBlock, $item['time'], $item['title']));
        fwrite($m3uHandle, $item['url'] . "\n");

        fwrite($txtHandle, "[{$finalBlock}] {$item['title']} : {$item['url']}\n");
    }
} else {
    fwrite($m3uHandle, "#EXTINF:-1 group-title=\"提示\", [00:00] 当前时段暂无符合条件的比赛\nhttp://127.0.0.1/empty.m3u8\n");
    fwrite($txtHandle, "当前时段暂无符合条件的比赛\n");
}

fclose($m3uHandle);
fclose($txtHandle);

writeLog("任务完成！共新增 {$successCount} 场。前置跳过 {$skipCount} 场，源去重跳过 {$urlSkipCount} 场。");
writeLog(str_repeat('=', 40));
