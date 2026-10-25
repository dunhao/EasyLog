<?php

declare(strict_types=1);

namespace EasyLog\SDK;

/**
 * SDK 配置容器：不可变，构造时一次性校验。
 *
 * 必填：app_id / app_secret / endpoint / topic
 * 可选：batch_size / buffer_max / flush_sec / timeout_ms / fail_log_path
 */
class Config
{
    public readonly string $appId;
    public readonly string $appSecret;
    public readonly string $endpoint;
    public readonly string $topic;

    public readonly int $batchSize;
    public readonly int $bufferMax;
    public readonly int $flushSec;
    public readonly int $timeoutMs;
    public readonly string $failLogPath;

    public function __construct(array $cfg)
    {
        $required = ['app_id', 'app_secret', 'endpoint', 'topic'];
        foreach ($required as $k) {
            if (empty($cfg[$k])) {
                throw new Exception\ConfigException("Missing required config: {$k}");
            }
        }

        $this->appId      = (string) $cfg['app_id'];
        $this->appSecret  = (string) $cfg['app_secret'];
        $this->endpoint   = rtrim((string) $cfg['endpoint'], '/');
        $this->topic      = (string) $cfg['topic'];

        $this->batchSize    = (int) ($cfg['batch_size'] ?? 1000);
        $this->bufferMax    = (int) ($cfg['buffer_max']  ?? 5000);
        $this->flushSec     = (int) ($cfg['flush_sec']   ?? 5);
        $this->timeoutMs    = (int) ($cfg['timeout_ms']  ?? 2000);
        $this->failLogPath  = (string) ($cfg['fail_log_path'] ?? '/tmp/easylog-fail.log');

        if ($this->batchSize < 1 || $this->batchSize > 10000) {
            throw new Exception\ConfigException('batch_size must be between 1 and 10000');
        }
        if ($this->bufferMax < $this->batchSize) {
            throw new Exception\ConfigException('buffer_max must be >= batch_size');
        }
        if ($this->flushSec < 1 || $this->flushSec > 300) {
            throw new Exception\ConfigException('flush_sec must be between 1 and 300');
        }
    }

    /**
     * 批量写入端点 URL。
     */
    public function batchUrl(): string
    {
        return $this->endpoint . '/api/v1/ingest/batch';
    }

    /**
     * 心跳端点 URL。
     */
    public function heartbeatUrl(): string
    {
        return $this->endpoint . '/api/v1/ingest/heartbeat';
    }
}
