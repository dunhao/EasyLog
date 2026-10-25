<?php
/**
 * EasyLog Agent mock 接入端（php -S 路由脚本）
 *
 * 记录请求到环境变量 EASYLOG_TEST_CAPTURE 指定的 JSONL 文件；
 * app_id 以 "bad" 开头时返回 401（测试快速失败路径）。
 */
$capture = getenv('EASYLOG_TEST_CAPTURE');
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$body = file_get_contents('php://input');

$headers = [];
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0) {
        $headers[str_replace('_', '-', strtolower(substr($k, 5)))] = $v;
    }
}

if ($capture) {
    @file_put_contents($capture, json_encode([
        'method' => $method,
        'uri' => $uri,
        'headers' => $headers,
        'body' => json_decode($body, true),
    ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
}

if (isset($headers['x-easylog-app']) && strpos($headers['x-easylog-app'], 'bad') === 0) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['message' => 'Invalid app_id (mock)']);
    return;
}

header('Content-Type: application/json');
http_response_code(202);
$logs = isset(json_decode($body, true)['logs']) ? count(json_decode($body, true)['logs']) : 0;
echo json_encode(['accepted' => $logs, 'rejected' => 0, 'request_id' => 'mock']);
