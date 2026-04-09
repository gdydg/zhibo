<?php
// 安全机制：防止接口被滥用
$token = $_GET['token'] ?? '';
$my_secret_token = '123456'; // ⚠️ 部署前请务必修改此密码

if ($token !== $my_secret_token) {
    header("HTTP/1.1 401 Unauthorized");
    die('Unauthorized');
}

// 异步在后台执行抓取脚本，防止阻塞外部定时任务的 HTTP 请求
exec("php " . __DIR__ . "/korazone.php > /dev/null 2>&1 &");
exec("php " . __DIR__ . "/74live.php > /dev/null 2>&1 &");
exec("php " . __DIR__ . "/ddkanq.php > /dev/null 2>&1 &");
exec("php " . __DIR__ . "/gogozq.php > /dev/null 2>&1 &");

header('Content-Type: application/json');
echo json_encode([
    "status" => "success", 
    "message" => "抓取任务已在后台启动。请稍后访问 .m3u 接口。"
]);
