<?php
/**
 * 74直播 (74001.tv) 全自动抓取与解密脚本 (Docker 后台 CLI 版)
 */

date_default_timezone_set('Asia/Shanghai');
@set_time_limit(0);

$BASE_URL = "https://www.74001.tv";
$PLAY_HOST = "https://play.74001.tv";
$M3U_FILENAME = __DIR__ . "/data/74live.m3u";
$CACHE_FILENAME = __DIR__ . "/data/74live_cache.json";

function log_msg($msg) { 
    // CLI 环境下清理 HTML 标签输出
    echo strip_tags(str_replace(['<br>', '&nbsp;'], ["\n", ' '], $msg)) . "\n"; 
}

$current_timestamp = time();
log_msg("74Live: 开始运行...");

function fetch_url($url, $referer = "") {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
    ];
    if ($referer) $headers[] = "Referer: $referer";
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}

function generate_random_string($length) {
    $chars = "ABCDEFGHJKMNPQRSTWXYZabcdefhijkmnprstwxyz2345678";
    $res = "";
    for ($i = 0; $i < $length; $i++) $res .= $chars[mt_rand(0, strlen($chars) - 1)];
    return $res;
}

function encode_bn($e) {
    $a = "UT9kQDKZsjIOezPXha7xYG5Jyfg2b8Fv4ASmCw1B0HoRu6cr3WtVnlLpEqMidN";
    $r = "";
    for ($t = 0; $t < strlen($e); $t++) {
        $n = strpos($a, $e[$t]);
        $i = ($n === false) ? $e[$t] : $a[($n + 3) % 62];
        $r .= $a[mt_rand(0, 61)] . $i . $a[mt_rand(0, 61)];
    }
    return $r;
}

function decode_live_url($encrypted_str) {
    if (empty($encrypted_str)) return false;
    $url = urldecode(hex2bin(base64_decode(hex2bin($encrypted_str))));
    return (strpos($url, '//') === 0) ? "https:" . $url : $url;
}

$home_html = fetch_url($BASE_URL);
if (!$home_html) die("首页抓取失败\n");

$dom = new DOMDocument();
@$dom->loadHTML(mb_convert_encoding($home_html, 'HTML-ENTITIES', 'UTF-8'));
$xpath = new DOMXPath($dom);
$nodes = $xpath->query("//a[contains(@href, '/bofang/')]");
$matches = [];

foreach ($nodes as $node) {
    $href = $node->getAttribute('href');
    if (!preg_match('/\/bofang\/(\d+)/', $href, $m)) continue;
    
    $raw_time = $node->getAttribute('t-nzf-o') ?: $node->getAttribute('nzw-o-t');
    $time_only = trim($xpath->evaluate("string(.//p[contains(@class, 'eventtime')]/i)", $node));
    
    if (empty($raw_time)) {
        $full_time = date('Y-m-d') . ' ' . ($time_only ?: '00:00') . ':00';
    } elseif (strlen($raw_time) <= 10) {
        $full_time = $raw_time . ' ' . ($time_only ?: '00:00') . ':00';
    } else {
        $full_time = $raw_time;
    }

    if (abs(strtotime($full_time) - $current_timestamp) > 14400) continue;

    $home = trim($xpath->evaluate("string(.//div[contains(@class, 'zhudui')]//p)", $node));
    $away = trim($xpath->evaluate("string(.//div[contains(@class, 'kedui')]//p)", $node));
    $league = trim($xpath->evaluate("string(.//p[contains(@class, 'eventtime')]/em)", $node));

    $matches[] = [
        'id' => $m[1],
        'league' => $league ?: "其它赛事",
        'time' => $time_only,
        'name' => ($home ?: "主队") . " VS " . ($away ?: "客队")
    ];
}

$cache_data = [];
if (file_exists($CACHE_FILENAME)) {
    $json_content = file_get_contents($CACHE_FILENAME);
    $cache_data = json_decode($json_content, true) ?: [];
}

$m3u_content = "#EXTM3U\n";
$success_num = 0;
$new_cache_data = [];

foreach ($matches as $match) {
    $match_id = $match['id'];
    $display_title = ($match['time'] ? "[{$match['time']}] " : "") . $match['name'];
    $real_src = "";

    if (isset($cache_data[$match_id]) && !empty($cache_data[$match_id])) {
        $real_src = $cache_data[$match_id];
    } else {
        $live_url = "{$BASE_URL}/live/{$match_id}";
        $live_html = fetch_url($live_url, $BASE_URL);
        
        preg_match('/nz-g-c\s*=\s*["\']([^"\']+)["\']/i', $live_html, $m_nz);
        preg_match('/data-secrt\s*=\s*["\']([^"\']+)["\']/i', $live_html, $m_sec);
        preg_match('/zr-cg-t\s*=\s*["\']([^"\']+)["\']/i', $live_html, $m_frm);
        preg_match('/zr-zfr-y\s*=\s*["\']([^"\']+)["\']/i', $live_html, $m_wf);
        preg_match('/zfr-c-at\s*=\s*["\']([^"\']+)["\']/i', $live_html, $m_yr);

        if (!empty($m_nz)) {
            $src = $m_nz[1];
            $sfk = generate_random_string(5) . substr($src, 0, 4) . generate_random_string(4) . substr($src, 4) . generate_random_string(8);
            $bn = encode_bn("w42Fw5" . ($m_sec[1] ?? ''));
            
            $api = "{$PLAY_HOST}/?sfk={$sfk}&frm=" . ($m_frm[1] ?? '1') . "&wf=" . ($m_wf[1] ?? '') . "&yr=" . ($m_yr[1] ?? '1') . "&bn={$bn}";
            $play_html = fetch_url($api, $live_url);

            if (preg_match('/no-zw-zxx\s*=\s*["\']([^"\']+)["\']/i', $play_html, $m_final)) {
                $real_src = decode_live_url($m_final[1]);
            }
            usleep(300000);
        }
    }

    if ($real_src) {
        $m3u_content .= "#EXTINF:-1 group-title=\"{$match['league']}\",{$display_title}\n";
        $m3u_content .= "{$real_src}\n";
        $success_num++;
        $new_cache_data[$match_id] = $real_src;
    }
}

file_put_contents($CACHE_FILENAME, json_encode($new_cache_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

if (file_put_contents($M3U_FILENAME, $m3u_content) !== false) {
    log_msg("74Live: 成功写入 {$success_num} 条源。");
} else {
    log_msg("74Live: 写入失败！");
}
