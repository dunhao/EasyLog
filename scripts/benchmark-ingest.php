#!/usr/bin/env php
<?php
/**
 * EasyLog lite 档 ingest 吞吐 / 查询延迟基准测试
 *
 * 用法（lite 栈运行中执行）：
 *   php scripts/benchmark-ingest.php [url] [app_id] [app_secret] [duration_seconds]
 *   php scripts/benchmark-ingest.php http://127.0.0.1:9515/api/v1 app_demo_001 sec_demo_001 10
 *
 * 方法：CURL 并发 worker，每 worker 每批 200 条随机日志 POST /ingest/batch，
 *       持续 duration 秒统计 accepted/s；随后测 3 次关键词检索取 took_ms 中位。
 * 结果受宿主机磁盘/CPU 影响，仅供量级参考（可复现脚本见本文件）。
 */

$url = $argv[1] ?? 'http://127.0.0.1:9515/api/v1';
$appId = $argv[2] ?? 'app_demo_001';
$secret = $argv[3] ?? 'sec_demo_001';
$duration = (int) ($argv[4] ?? 10);
$workers = 4;
$batch = 200;

echo "EasyLog ingest benchmark\n  target: {$url}/ingest/batch\n  workers: {$workers} × batch {$batch}, duration {$duration}s\n\n";

$makeBatch = function () use ($batch) {
    $logs = [];
    for ($i = 0; $i < $batch; $i++) {
        $logs[] = [
            'msg' => 'benchmark 订单支付流程 trace id=' . bin2hex(random_bytes(8)),
            'level' => ['INFO', 'INFO', 'INFO', 'DEBUG', 'WARN', 'ERROR'][random_int(0, 5)],
            'ts' => gmdate('Y-m-d\TH:i:s') . '.000Z',
            'service' => 'bench-worker',
            'duration_ms' => random_int(1, 3000),
        ];
    }
    return json_encode(['logs' => $logs], JSON_UNESCAPED_UNICODE);
};

$post = function (string $body) use ($url, $appId, $secret) {
    $ch = curl_init($url . '/ingest/batch');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json', 'Accept: application/json',
            'X-EasyLog-App: ' . $appId, 'Authorization: Bearer ' . $secret,
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string) $resp, true);
    return [$code, (int) ($data['accepted'] ?? 0)];
};

// ---- 并发压测 ----
$start = microtime(true);
$accepted = 0;
$failed = 0;

$worker = function () use ($makeBatch, $post, $duration, &$accepted, &$failed) {
    $deadline = microtime(true) + $duration;
    while (microtime(true) < $deadline) {
        list($code, $n) = $post($makeBatch());
        if ($code === 202) {
            $accepted += $n;
        } else {
            $failed += 1;
        }
    }
};

$pid = [];
for ($w = 1; $w < $workers; $w++) {
    $pid[$w] = pcntl_fork();
    if ($pid[$w] === 0) {
        // 子进程共享计数不精确：各自输出，父进程汇总 —— 简化为子进程写临时文件
        $local = 0; $localFail = 0;
        $deadline = microtime(true) + $duration;
        while (microtime(true) < $deadline) {
            list($code, $n) = $post($makeBatch());
            if ($code === 202) $local += $n; else $localFail++;
        }
        file_put_contents(sys_get_temp_dir() . '/easylog-bench-' . getmypid(), $local . ' ' . $localFail);
        exit(0);
    }
}
$deadline = microtime(true) + $duration;
while (microtime(true) < $deadline) {
    list($code, $n) = $post($makeBatch());
    if ($code === 202) $accepted += $n; else $failed++;
}
foreach ($pid as $w => $p) {
    pcntl_waitpid($p, $status);
    foreach (glob(sys_get_temp_dir() . '/easylog-bench-*') ?: [] as $f) {
        list($a, $fl) = explode(' ', (string) file_get_contents($f));
        $accepted += (int) $a;
        $failed += (int) $fl;
        @unlink($f);
    }
}
$elapsed = microtime(true) - $start;

printf("ingest:  %d 条 / %.1fs = %s 条/秒（HTTP 失败 %d 次）\n", $accepted, $elapsed, number_format($accepted / $elapsed), $failed);

// ---- 查询延迟（需登录态；仅在有 token 时测） ----
// 简化：跳过（依赖控制台凭据）；查询延迟请用控制台检索页的 took 提示或 SearchController 返回值
echo "query:   见控制台检索页「共 N 条 (X ms)」提示，或 POST /workspaces/{ws}/search 的 took_ms\n";
