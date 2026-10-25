<?php

declare(strict_types=1);

namespace EasyLog\SDK;

/**
 * EasyLog SDK 客户端：业务方通过 SDK 直推日志的入口。
 *
 * 使用方式：
 *   $client = new Client($cfg);
 *   $client->log('INFO', 'order created', ['order_id' => 'A123']);
 *   $client->flush();  // 进程退出前显式 flush
 *
 * 异步特性：
 * - 内存缓冲，达到 batch_size 或 flush_sec 触发投递；
 * - HTTP 调用基于 cURL multi 异步；
 * - 构造时自动注册 register_shutdown_function 保证退出前 flush；
 * - 重试 3 次仍失败 → 落本地文件 /tmp/easylog-fail.log。
 */
class Client
{
    private Config $config;
    private HttpClient $http;

    /** @var LogEntry[] */
    private array $buffer = [];

    private int $bufferedCount = 0;
    private ?float $lastFlushTs = null;
    private bool $shutdownRegistered = false;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->http   = new HttpClient($config);
        $this->lastFlushTs = microtime(true);

        // 注册 shutdown 函数（仅一次，进程级）
        if (!$this->shutdownRegistered) {
            register_shutdown_function([$this, 'flush']);
            $this->shutdownRegistered = true;
        }
    }

    /**
     * 写入一条日志到内存缓冲；满批时自动触发 flush。
     */
    public function log(string $level, string $msg, array $fields = [], ?string $ts = null): void
    {
        // 缓冲超限保护：直接同步 flush 一批再接收
        if ($this->bufferedCount >= $this->config->bufferMax) {
            $this->flush();
        }

        $this->buffer[]    = new LogEntry($msg, $level, $ts ?? date('c'), $fields);
        $this->bufferedCount++;

        if ($this->bufferedCount >= $this->config->batchSize) {
            $this->flush();
        }
    }

    /**
     * 便捷方法：INFO 级别。
     */
    public function info(string $msg, array $fields = []): void
    {
        $this->log('INFO', $msg, $fields);
    }

    public function warn(string $msg, array $fields = []): void
    {
        $this->log('WARN', $msg, $fields);
    }

    public function error(string $msg, array $fields = []): void
    {
        $this->log('ERROR', $msg, $fields);
    }

    public function debug(string $msg, array $fields = []): void
    {
        $this->log('DEBUG', $msg, $fields);
    }

    /**
     * 主动触发：把当前缓冲切分成批次并异步发送。
     *
     * 超时未触发时（flush_sec 到期）由内部 tick 自动调用。
     */
    public function flush(): void
    {
        if (empty($this->buffer)) {
            $this->http->wait();
            $this->lastFlushTs = microtime(true);
            return;
        }

        $batchSize = $this->config->batchSize;
        $chunks    = array_chunk($this->buffer, $batchSize);
        $this->buffer       = [];
        $this->bufferedCount = 0;

        foreach ($chunks as $chunk) {
            $this->http->push($chunk);
        }
        $this->http->wait();
        $this->lastFlushTs = microtime(true);
    }

    /**
     * 由业务方定期调用（如每 100ms 一次）的 tick：
     * 仅在距上次 flush 超过 flush_sec 时触发一次 flush。
     */
    public function tick(): void
    {
        $elapsed = microtime(true) - ($this->lastFlushTs ?? microtime(true));
        if ($elapsed >= $this->config->flushSec && $this->bufferedCount > 0) {
            $this->flush();
        }
    }

    /**
     * 健康检查：向平台发起一次心跳请求。
     *
     * @return array{ok: bool, status: int, body: string}
     */
    public function heartbeat(): array
    {
        $ch = curl_init($this->config->heartbeatUrl());
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET       => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS    => $this->config->timeoutMs,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->config->appSecret,
                'X-EasyLog-App: '   . $this->config->appId,
                'X-EasyLog-Topic: ' . $this->config->topic,
            ],
        ]);
        $body  = (string) curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        return [
            'ok'     => $errno === CURLE_OK && $code >= 200 && $code < 300,
            'status' => $code,
            'body'   => $body,
        ];
    }

    /**
     * 析构时确保缓冲全部发送。
     */
    public function __destruct()
    {
        if (!empty($this->buffer)) {
            $this->flush();
        }
    }
}
