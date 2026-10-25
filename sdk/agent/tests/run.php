<?php
/**
 * EasyLog Agent standalone 测试（无 phpunit 依赖，php tests/run.php 直接跑）
 *
 * 覆盖：配置加载与校验 / 5 种解析器（含多行合并）/ 级别归一 /
 *       TailReader（增量、截断、轮转、起点策略）/ 进度持久化 /
 *       FileScanner（glob 与目录递归）/ Sender（mock 服务端，含 401 快速失败）
 */

error_reporting(E_ALL);
$AGENT = dirname(__DIR__) . '/easylog-agent';

// 吸掉 include 主脚本时 shebang 行的文本输出
ob_start();
require $AGENT;
ob_end_clean();

$PASS = 0;
$FAIL = 0;
$FAILURES = [];

function t(string $name, callable $fn): void
{
    global $PASS, $FAIL, $FAILURES;
    try {
        $fn();
        $PASS++;
        echo "ok   {$name}\n";
    } catch (Throwable $e) {
        $FAIL++;
        $FAILURES[] = $name . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
        echo "FAIL {$name}: {$e->getMessage()}\n";
    }
}

function eq($expected, $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($what . ' 期望 ' . var_export($expected, true) . ' 实际 ' . var_export($actual, true));
    }
}

function mkTemp(string $content = ''): string
{
    $f = tempnam(sys_get_temp_dir(), 'easylog-agent-test-');
    if ($content !== '') file_put_contents($f, $content);
    return $f;
}

function baseWatch(array $overrides = []): array
{
    return array_merge([
        'path' => '/tmp/x', 'parser' => 'thinkphp', 'topic' => '', 'recursive' => false,
        'pattern' => '*.log', 'start_position' => 'beginning', 'fields' => [], 'source_prefix' => '',
    ], $overrides);
}

// ============================================================================
// 配置
// ============================================================================

t('config: 缺少必填项报错', function () {
    $f = mkTemp('{"watches":[{"path":"/tmp"}]}');
    try {
        AgentConfig::load($f);
        throw new RuntimeException('应当抛异常');
    } catch (RuntimeException $e) {
        eq(true, strpos($e->getMessage(), 'server.url') !== false, '错误信息');
    }
    unlink($f);
});

t('config: 默认值合并', function () {
    $f = mkTemp(json_encode([
        'server' => ['url' => 'http://x:9515/api/v1/', 'app_id' => 'a', 'app_secret' => 's'],
        'watches' => [['path' => '/tmp/logs']],
    ]));
    $c = AgentConfig::load($f);
    eq('http://x:9515/api/v1', $c['server']['url'], 'url 去尾斜杠');
    eq('plain', $c['watches'][0]['parser'], '默认 parser');
    eq('end', $c['watches'][0]['start_position'], '默认起点');
    eq(500, $c['batch_size'], '默认批量');
    unlink($f);
});

t('config: 非法 start_position 报错', function () {
    $f = mkTemp(json_encode([
        'server' => ['url' => 'http://x', 'app_id' => 'a', 'app_secret' => 's'],
        'watches' => [['path' => '/tmp', 'start_position' => 'middle']],
    ]));
    try {
        AgentConfig::load($f);
        throw new RuntimeException('应当抛异常');
    } catch (RuntimeException $e) {
        eq(true, strpos($e->getMessage(), 'start_position') !== false);
    }
    unlink($f);
});

// ============================================================================
// 解析器
// ============================================================================

t('parser: thinkphp TP6 行', function () {
    $r = LogParser::parseLine("[2026-10-25 10:00:00][error]订单创建失败 OrderFailed", baseWatch());
    eq('entry', $r['type']);
    eq('订单创建失败 OrderFailed', $r['entry']['msg']);
    eq('ERROR', $r['entry']['level']);
    eq('2026-10-25 10:00:00', $r['entry']['ts']);
});

t('parser: thinkphp TP5 宽松空格 + ISO 时间', function () {
    $r = LogParser::parseLine('[ 2026-10-25T10:00:00+08:00 ] [ info ] sql log here', baseWatch());
    eq('entry', $r['type']);
    eq('INFO', $r['entry']['level']);
    eq('2026-10-25T10:00:00+08:00', $r['entry']['ts']);
});

t('parser: thinkphp 分隔线跳过 / 续行合并由 reader 处理', function () {
    eq('skip', LogParser::parseLine('------------------------------------------------', baseWatch())['type']);
    eq('continue', LogParser::parseLine('  #0 /app/vendor/foo.php(12): bar()', baseWatch())['type']);
});

t('parser: laravel 行', function () {
    $r = LogParser::parseLine('[2026-10-25 10:00:00] production.ERROR: Call to undefined fn()', baseWatch(['parser' => 'laravel']));
    eq('ERROR', $r['entry']['level']);
    eq('Call to undefined fn()', $r['entry']['msg']);
    eq('continue', LogParser::parseLine('#0 /app/app/Http/Controllers.php(30)', baseWatch(['parser' => 'laravel']))['type']);
});

t('parser: json 行字段映射与透传', function () {
    $line = '{"msg":"支付成功","level":"warning","ts":"2026-10-25T02:00:00Z","order_id":"A1","cost":9.9}';
    $r = LogParser::parseLine($line, baseWatch(['parser' => 'json']));
    eq('支付成功', $r['entry']['msg']);
    eq('WARN', $r['entry']['level']);
    eq('2026-10-25T02:00:00Z', $r['entry']['ts']);
    eq('A1', $r['entry']['order_id']);
    eq(9.9, $r['entry']['cost']);
});

t('parser: json 坏行作为续行', function () {
    eq('continue', LogParser::parseLine('{"broken": ', baseWatch(['parser' => 'json']))['type']);
});

t('parser: regex 命名分组', function () {
    $w = baseWatch(['parser' => 'regex', 'line_pattern' => '/^(?<ts>\S+ \S+)\s+(?<level>\w+)\s+(?<msg>.*)$/']);
    $r = LogParser::parseLine('2026-10-25 10:00:00 crit disk full on /dev/sda1', $w);
    eq('FATAL', $r['entry']['level']);
    eq('disk full on /dev/sda1', $r['entry']['msg']);
});

t('parser: 级别归一', function () {
    eq('FATAL', LogParser::normalizeLevel('EMERGENCY'));
    eq('WARN', LogParser::normalizeLevel('warn'));
    eq('INFO', LogParser::normalizeLevel('whatever'), '未知级别归 INFO');
});

t('parser: decorate 附加 source/host/静态字段', function () {
    $e = LogParser::decorate(['msg' => 'x', 'level' => 'INFO', 'ts' => 't'], baseWatch(['source_prefix' => 'app1', 'fields' => ['env' => 'prod']]), '/var/www/app/runtime/log/1025.log', 'host');
    eq('app1/1025.log', $e['source']);
    eq('prod', $e['env']);
    eq(true, !empty($e['host']), 'host 字段');
});

// ============================================================================
// TailReader
// ============================================================================

t('tail: 首读全量（beginning）+ 多行堆栈合并', function () {
    $f = mkTemp(
        "[2026-10-25 10:00:00][error]SQL 查询失败\n" .
        "#0 /app/Model.php(22): Database::query()\n" .
        "#1 /app/Controller.php(10): Model->find()\n" .
        "[2026-10-25 10:00:05][info]重试成功\n"
    );
    $r = TailReader::read($f, baseWatch(), ['inode' => 0, 'pos' => 0]);
    eq(2, count($r['entries']));
    eq("SQL 查询失败\n#0 /app/Model.php(22): Database::query()\n#1 /app/Controller.php(10): Model->find()", $r['entries'][0]['msg']);
    eq('重试成功', $r['entries'][1]['msg']);
    unlink($f);
});

t('tail: 增量续读不重发', function () {
    $f = mkTemp("[2026-10-25 10:00:00][info]a\n");
    $r1 = TailReader::read($f, baseWatch(), ['inode' => 0, 'pos' => 0]);
    file_put_contents($f, "[2026-10-25 10:00:01][info]b\n[2026-10-25 10:00:02][info]c\n", FILE_APPEND);
    $r2 = TailReader::read($f, baseWatch(), $r1['state']);
    eq(2, count($r2['entries']));
    eq('b', $r2['entries'][0]['msg']);
    unlink($f);
});

t('tail: end 起点跳过历史', function () {
    $f = mkTemp("[2026-10-25 10:00:00][info]old\n");
    $r = TailReader::read($f, baseWatch(['start_position' => 'end']), ['inode' => 0, 'pos' => 0]);
    eq(0, count($r['entries']));
    unlink($f);
});

t('tail: 截断（copytruncate）自动重读', function () {
    $f = mkTemp("[2026-10-25 10:00:00][info]old-line-long-content\n");
    $r1 = TailReader::read($f, baseWatch(), ['inode' => 0, 'pos' => 0]);
    eq(1, count($r1['entries']));
    // 模拟 copytruncate：内容整个变短重写
    file_put_contents($f, "[2026-10-25 11:00:00][info]new\n");
    $r2 = TailReader::read($f, baseWatch(), $r1['state']);
    eq(1, count($r2['entries']));
    eq('new', $r2['entries'][0]['msg']);
    unlink($f);
});

t('tail: 轮转后同路径新 inode 按 start_position 处理', function () {
    $f = mkTemp("[2026-10-25 10:00:00][info]old\n");
    $r1 = TailReader::read($f, baseWatch(), ['inode' => 0, 'pos' => 0]);
    // rename 原文件 + 写入新文件（同路径新 inode）
    $rotated = $f . '.1';
    rename($f, $rotated);
    file_put_contents($f, "[2026-10-25 12:00:00][warn]rotated-new\n");
    $r2 = TailReader::read($f, baseWatch(['start_position' => 'end']), $r1['state']);
    eq(0, count($r2['entries']), 'end 策略下轮转新文件只采后续增量');
    file_put_contents($f, "[2026-10-25 12:00:01][warn]after\n", FILE_APPEND);
    $r3 = TailReader::read($f, baseWatch(['start_position' => 'end']), $r2['state']);
    eq(1, count($r3['entries']));
    eq('after', $r3['entries'][0]['msg']);
    unlink($rotated);
    unlink($f);
});

// ============================================================================
// StateStore / FileScanner
// ============================================================================

t('state: save/load roundtrip', function () {
    $f = mkTemp('');
    $cfg = ['state_file' => $f];
    StateStore::save($cfg, ['/a.log' => ['inode' => 99, 'pos' => 1234]]);
    eq(['/a.log' => ['inode' => 99, 'pos' => 1234]], StateStore::load($cfg));
    StateStore::clear($cfg);
    eq([], StateStore::load($cfg));
    @unlink($f);
});

t('scanner: 目录递归 + pattern 过滤', function () {
    $dir = sys_get_temp_dir() . '/easylog-scan-' . getmypid() . '-' . uniqid();
    mkdir($dir . '/202610', 0777, true);
    file_put_contents($dir . '/app.log', 'x');
    file_put_contents($dir . '/202610/25.log', 'x');
    file_put_contents($dir . '/notes.txt', 'x');
    $files = FileScanner::scan(baseWatch(['path' => $dir, 'recursive' => true, 'pattern' => '*.log']));
    eq(2, count($files));
    unlink($dir . '/app.log'); unlink($dir . '/202610/25.log'); unlink($dir . '/notes.txt');
    rmdir($dir . '/202610'); rmdir($dir);
});

t('scanner: glob 模式', function () {
    $dir = sys_get_temp_dir() . '/easylog-glob-' . getmypid() . '-' . uniqid();
    mkdir($dir, 0777, true);
    file_put_contents($dir . '/app-20261025.log', 'x');
    file_put_contents($dir . '/app-20261024.log', 'x');
    file_put_contents($dir . '/other.log', 'x');
    $files = FileScanner::scan(baseWatch(['path' => $dir . '/app-*.log']));
    eq(2, count($files));
    foreach (glob($dir . '/*') ?: [] as $f2) unlink($f2);
    rmdir($dir);
});

// ============================================================================
// Sender（对接 php -S mock 服务）
// ============================================================================

function withMockServer(callable $fn): void
{
    $capture = tempnam(sys_get_temp_dir(), 'easylog-capture-');
    @unlink($capture); // php -S 侧 append，确保从空开始
    putenv('EASYLOG_TEST_CAPTURE=' . $capture);

    $port = 18100 + random_int(100, 899);
    $cmd = sprintf('php -S 127.0.0.1:%d %s >/dev/null 2>&1', $port, escapeshellarg(__DIR__ . '/mock-server.php'));
    $proc = proc_open($cmd, [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
    usleep(400000); // 等待 server 起来

    try {
        for ($i = 0; $i < 20; $i++) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
            if ($conn) { fclose($conn); break; }
            usleep(200000);
        }
        $fn('http://127.0.0.1:' . $port, $capture);
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        @unlink($capture);
        putenv('EASYLOG_TEST_CAPTURE');
    }
}

function cfgFor(string $url, string $appId = 'app_demo_001'): array
{
    return [
        'server' => ['url' => $url, 'app_id' => $appId, 'app_secret' => 'sec_x', 'timeout' => 5],
        'batch_size' => 500, 'max_buffer' => 10000,
    ];
}

t('sender: 按 topic 分组发送 + 请求头', function () {
    withMockServer(function ($url, $capture) {
        $s = new Sender(cfgFor($url), true, false);
        $s->add('', ['msg' => 'default-1', 'level' => 'INFO', 'ts' => '2026-10-25T10:00:00Z']);
        $s->add('', ['msg' => 'default-2', 'level' => 'INFO', 'ts' => '2026-10-25T10:00:01Z']);
        $s->add('tpc_special', ['msg' => 'special', 'level' => 'ERROR', 'ts' => '2026-10-25T10:00:02Z']);
        eq(true, $s->flush(cfgFor($url)), '缓冲清空');
        eq(0, $s->pending());

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($capture))));
        eq(2, count($lines), '按 topic 拆成两个请求');
        $r1 = json_decode($lines[0], true);
        $r2 = json_decode($lines[1], true);
        eq('/ingest/batch', $r1['uri']);
        eq('app_demo_001', $r1['headers']['x-easylog-app']);
        eq('Bearer sec_x', $r1['headers']['authorization']);
        eq(2, count($r1['body']['logs']), '默认 topic 组 2 条');
        $hasTopicHeader = isset($r2['headers']['x-easylog-topic']) && $r2['headers']['x-easylog-topic'] === 'tpc_special';
        eq(true, $hasTopicHeader || ($r1['headers']['x-easylog-topic'] ?? '') === 'tpc_special', 'X-EasyLog-Topic 头');
    });
});

t('sender: 401 配置错误快速失败', function () {
    withMockServer(function ($url, $capture) {
        $s = new Sender(cfgFor($url, 'bad_app'), true, false);
        $s->add('', ['msg' => 'x', 'level' => 'INFO', 'ts' => 't']);
        try {
            $s->flush(cfgFor($url, 'bad_app'));
            throw new RuntimeException('应当抛 401 快速失败');
        } catch (RuntimeException $e) {
            eq(true, strpos($e->getMessage(), '接入被拒') !== false, '错误信息');
        }
        eq(1, $s->pending(), '失败条目留在缓冲');
    });
});

t('sender: dry-run 不发送', function () {
    withMockServer(function ($url, $capture) {
        $s = new Sender(cfgFor($url), true, true);
        $s->add('', ['msg' => 'dry', 'level' => 'INFO', 'ts' => 't']);
        eq(true, $s->flush(cfgFor($url)));
        eq(false, is_file($capture), 'mock 未收到任何请求');
    });
});

// ============================================================================

echo "\n{$PASS} passed, {$FAIL} failed\n";
if ($FAILURES) {
    echo "\n失败详情:\n - " . implode("\n - ", $FAILURES) . "\n";
    exit(1);
}
exit(0);
