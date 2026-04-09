<?php
$file = $_GET['file'] ?? '';

// 安全限制：仅允许读取白名单文件
if (!in_array($file, ['korazone', '74live', 'ddkanq', 'gogozq'])) {
    header("HTTP/1.1 403 Forbidden");
    die("Forbidden");
}

$path = __DIR__ . "/data/{$file}.m3u";

if (file_exists($path)) {
    header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
    header('Content-Disposition: inline; filename="'.$file.'.m3u"');
    header('Cache-Control: no-cache, must-revalidate');
    readfile($path);
} else {
    header("HTTP/1.1 404 Not Found");
    echo "直播源文件尚未生成，请等待后台抓取完成 (约需1-3分钟)。";
}
