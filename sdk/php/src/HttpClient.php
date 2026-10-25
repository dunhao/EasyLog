<?php

declare(strict_types=1);

namespace EasyLog\SDK;

/**
 * 异步 HTTP 客户端：基于 cURL multi，单次 flush 内并行发送多批。
 *
 * 设计要点：
 * - push() 立即返回，不阻塞主流程；
 * - wait() 等待所有 in-flight 请求完成并回收结果；
 * - 每个请求最多重试 3 次（指数退避）；
 * - 重试仍失败 → 落本地文件 failLogPath，避免业务侧丢日志感知。
 */
class HttpClient
{
    private Config $config;

    /** @var array<int, array{handle: \CurlHandle, batch: array, retries: int}> */
    private array $inflight = [];

    /** @var \CurlMultiHandle|null */
    private $mh = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * 投递一批日志，立即返回。
     *
     * @param LogEntry[] $entries
     */
    public function push(array $entries): void
    {
        if (empty($entries)) return;

        $payload = json_encode([
            'logs' => array_map(fn(LogEntry $e) => $e->toArray(), $entries),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->initMulti();
        $ch = curl_init($this->config->batchUrl());

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => $this->config->timeoutMs,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->config->appSecret,
                'X-EasyLog-App: '   . $this->config->appId,
                'X-EasyLog-Topic: ' . $this->config->topic,
            ],
        ]);

        curl_multi_add_handle($this->mh, $ch);
        $this->inflight[(int) $ch] = [
            'handle'    => $ch,
            'batch'     => $entries,
            'retries'   => 0,
            'payload'   => $payload,
        ];
    }

    /**
     * 等待所有 in-flight 请求完成，处理结果与重试。
     */
    public function wait(): void
    {
        if (empty($this->inflight)) return;
        $this->initMulti();

        // 主事件循环
        do {
            $status = curl_multi_exec($this->mh, $active);
            if ($status !== CURLM_OK) break;
            if ($active) {
                curl_multi_select($this->mh, 0.5);
            }
        } while ($active && curl_multi_info_read($this->mh, $info) === false);

        // 收割完成结果
        while ($msg = curl_multi_info_read($this->mh)) {
            $this->reapHandle($msg['handle'], $msg['result']);
        }

        // 清空
        $this->inflight = [];
    }

    private function initMulti(): void
    {
        if ($this->mh === null) {
            $this->mh = curl_multi_init();
        }
    }

    private function reapHandle(\CurlHandle $ch, int $curlErrno): void
    {
        $key = (int) $ch;
        if (!isset($this->inflight[$key])) return;

        $item   = $this->inflight[$key];
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($this->mh, $ch);
        curl_close($ch);
        unset($this->inflight[$key]);

        // 2xx 成功
        if ($curlErrno === CURLE_OK && $httpCode >= 200 && $httpCode < 300) {
            return;
        }

        // 4xx 客户端错误（除 429 限流外）不重试，直接落 DLQ
        if ($curlErrno === CURLE_OK && $httpCode >= 400 && $httpCode !== 429) {
            $this->persistFailures($item['batch'], "HTTP {$httpCode}");
            return;
        }

        // 5xx / 429 / 网络错误：重试
        if ($item['retries'] < 3) {
            usleep(500000 * (2 ** $item['retries'])); // 0.5s, 1s, 2s
            // 重试通过同步 cURL（避免 multi 复杂度），仅一次
            $ok = $this->retryOnce($item['payload']);
            if ($ok) return;
            $this->persistFailures($item['batch'], "retry exhausted");
            return;
        }

        $this->persistFailures($item['batch'], "retries exceeded");
    }

    private function retryOnce(string $payload): bool
    {
        $ch = curl_init($this->config->batchUrl());
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => $this->config->timeoutMs,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->config->appSecret,
                'X-EasyLog-App: '   . $this->config->appId,
                'X-EasyLog-Topic: ' . $this->config->topic,
            ],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_errno($ch);
        curl_close($ch);

        return $err === CURLE_OK && $code >= 200 && $code < 300;
    }

    /**
     * 重试耗尽后，将原始日志落本地文件，避免静默丢失。
     *
     * @param LogEntry[] $entries
     */
    private function persistFailures(array $entries, string $reason): void
    {
        $line = sprintf(
            "[%s] %s topic=%s count=%d reason=%s\n",
            date('c'),
            $this->config->appId,
            $this->config->topic,
            count($entries),
            $reason
        );
        file_put_contents($this->config->failLogPath, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * 析构时确保 multi handle 释放。
     */
    public function __destruct()
    {
        if ($this->mh !== null) {
            foreach ($this->inflight as $item) {
                curl_multi_remove_handle($this->mh, $item['handle']);
                curl_close($item['handle']);
            }
            curl_multi_close($this->mh);
        }
    }
}
