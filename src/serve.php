<?php
$file = $_GET['file'] ?? '';
$format = $_GET['format'] ?? 'm3u';

// 安全限制：仅允许读取白名单文件
if (!in_array($file, ['korazone', '74live', 'ddkanq', 'sportplus'], true)) {
    header('HTTP/1.1 403 Forbidden');
    die('Forbidden');
}

if (!in_array($format, ['m3u', 'txt'], true)) {
    header('HTTP/1.1 400 Bad Request');
    die('Invalid format');
}

$path = __DIR__ . "/data/{$file}.{$format}";

if (!file_exists($path)) {
    header('HTTP/1.1 404 Not Found');
    echo '直播源文件尚未生成，请等待后台抓取完成 (约需1-3分钟)。';
    exit;
}

if ($format === 'm3u') {
    header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
} else {
    header('Content-Type: text/plain; charset=utf-8');
}
header('Content-Disposition: inline; filename="' . $file . '.' . $format . '"');
header('Cache-Control: no-cache, must-revalidate');
readfile($path);
