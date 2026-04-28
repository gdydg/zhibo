<?php
/**
 * SportPlusTV 直播源抓取脚本
 * - 拉取赛事列表
 * - 并发抓取详情页 (并发数 10)
 * - 生成 m3u 与 txt 两种文件
 */

date_default_timezone_set('Asia/Shanghai');
@set_time_limit(0);

$indexUrl = 'https://api.sportplustv.live/v5/matches/index?live=1&lang=en&offset=28800';
$m3uFile = __DIR__ . '/data/sportplus.m3u';
$txtFile = __DIR__ . '/data/sportplus.txt';
$timeout = 15;
$concurrency = 10;

function log_msg($msg) {
    echo "SportPlus: {$msg}\n";
}

function get_circled_number($i) {
    static $circled = ['', '①', '②', '③', '④', '⑤', '⑥', '⑦', '⑧', '⑨', '⑩'];
    if ($i > 0 && $i < count($circled)) {
        return $circled[$i];
    }
    return "({$i})";
}

function init_handle($url, $timeout) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        'Accept: application/json,text/plain,*/*',
    ]);
    return $ch;
}

log_msg('开始抓取...');

$indexCh = init_handle($indexUrl, $timeout);
$indexRaw = curl_exec($indexCh);
$indexCode = curl_getinfo($indexCh, CURLINFO_HTTP_CODE);
curl_close($indexCh);

if ($indexRaw === false || $indexCode !== 200) {
    die("SportPlus: 获取赛事列表失败，HTTP {$indexCode}\n");
}

$indexData = json_decode($indexRaw, true);
if (!is_array($indexData) || empty($indexData['items']) || !is_array($indexData['items'])) {
    die("SportPlus: 赛事列表 JSON 解析失败或无可用赛事。\n");
}

$items = $indexData['items'];
$streams = [];

$mh = curl_multi_init();
$queue = [];
$running = null;

foreach ($items as $item) {
    if (!isset($item['id']) || !isset($item['name'])) {
        continue;
    }
    $queue[] = [
        'id' => intval($item['id']),
        'name' => trim((string) $item['name'])
    ];
}

$active = [];
$nextIndex = 0;

while ($nextIndex < count($queue) || !empty($active)) {
    while (count($active) < $concurrency && $nextIndex < count($queue)) {
        $match = $queue[$nextIndex++];
        $viewUrl = 'https://api.onsport365.live/v5/matches/view?lang=en&id=' . $match['id'];
        $ch = init_handle($viewUrl, $timeout);
        curl_multi_add_handle($mh, $ch);
        $active[(int) $ch] = [
            'handle' => $ch,
            'id' => $match['id'],
            'name' => $match['name']
        ];
    }

    do {
        $status = curl_multi_exec($mh, $running);
    } while ($status === CURLM_CALL_MULTI_PERFORM);

    while ($info = curl_multi_info_read($mh)) {
        $done = $info['handle'];
        $key = (int) $done;

        if (!isset($active[$key])) {
            curl_multi_remove_handle($mh, $done);
            curl_close($done);
            continue;
        }

        $meta = $active[$key];
        $raw = curl_multi_getcontent($done);
        $httpCode = curl_getinfo($done, CURLINFO_HTTP_CODE);

        if ($httpCode === 200 && !empty($raw)) {
            $viewData = json_decode($raw, true);
            if (is_array($viewData) && isset($viewData['item']['ls']) && is_array($viewData['item']['ls'])) {
                $i = 1;
                foreach ($viewData['item']['ls'] as $url) {
                    $url = trim((string) $url);
                    if ($url === '') {
                        continue;
                    }
                    $streams[] = [
                        'title' => $meta['name'] . ' ' . get_circled_number($i),
                        'url' => $url,
                    ];
                    $i++;
                }
            } else {
                log_msg("赛事 {$meta['id']} 详情解析失败");
            }
        } else {
            log_msg("赛事 {$meta['id']} 拉取失败 (HTTP {$httpCode})");
        }

        curl_multi_remove_handle($mh, $done);
        curl_close($done);
        unset($active[$key]);
    }

    if ($running) {
        curl_multi_select($mh, 1.0);
    }
}

curl_multi_close($mh);

if (empty($streams)) {
    die("SportPlus: 未抓取到可用直播源。\n");
}

$m3u = "#EXTM3U\n";
$txt = "Live Sports,#genre\n";

foreach ($streams as $stream) {
    $title = $stream['title'];
    $url = $stream['url'];
    $m3u .= "#EXTINF:-1,{$title}\n{$url}\n";
    $txt .= "{$title},{$url}\n";
}

$m3uOk = file_put_contents($m3uFile, $m3u);
$txtOk = file_put_contents($txtFile, $txt);

if ($m3uOk === false || $txtOk === false) {
    die("SportPlus: 文件写入失败。\n");
}

log_msg('成功写入 ' . count($streams) . ' 条源。');
