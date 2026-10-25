<?php

declare(strict_types=1);

namespace EasyLog\Services\MQ\Kafka;

use EasyLog\Services\Writer\WriterService;
use Illuminate\Support\Facades\Log;

/**
 * Direct Producer（lite 单机模式）
 *
 * MQ_BUS_DRIVER=direct 时使用：不经内部 Kafka，produce 即同步交给
 * WriterService 进程内缓冲，复用其批量聚合 / 重试 / DLQ 语义。
 *
 * 适用：lite 档（无 Kafka/ZK，单 app 容器）。
 * 消息不会跨进程流动 —— 本驱动只在本进程内闭环，因此：
 * - flush() 直接 flushAll（由请求终止钩子 / 进程退出触发，保证不丢尾包）
 * - ping() 恒真（总线即本进程）
 */
class DirectKafkaProducer implements KafkaProducerInterface
{
    private ?WriterService $writer = null;

    public function produce(string $topicName, string $key, string $payload, array $headers = []): void
    {
        $writer = $this->writer();
        $writer->handle($this->decode($payload, $headers, $topicName));
        $writer->checkTimeouts();
    }

    public function batchProduce(array $messages): void
    {
        $writer = $this->writer();
        foreach ($messages as $msg) {
            $writer->handle($this->decode(
                $msg['payload'] ?? '',
                $msg['headers'] ?? [],
                $msg['topic'] ?? ''
            ));
        }
        $writer->checkTimeouts();
    }

    public function flush(): void
    {
        $this->writer?->flushAll();
    }

    public function ping(): bool
    {
        return true;
    }

    /**
     * 解码 payload 并按 WriterCommand::processMessage 的规则
     * 从消息头补齐路由字段（消息体自带时以消息体为准）
     *
     * @return array<string,mixed>
     */
    private function decode(string $payload, array $headers, string $topicName): array
    {
        try {
            $entry = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            Log::warning('EasyLog direct producer skip malformed JSON', [
                'topic' => $topicName,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        if (!is_array($entry)) {
            Log::warning('EasyLog direct producer skip non-array entry', ['topic' => $topicName]);
            return [];
        }

        foreach (['tenant_id', 'workspace_id', 'topic_id'] as $k) {
            if (!isset($entry['_' . $k]) && isset($headers[$k])) {
                $entry['_' . $k] = $headers[$k];
            }
        }

        return $entry;
    }

    private function writer(): WriterService
    {
        if ($this->writer === null) {
            $direct = config('message-queue.bus.direct', []);
            $this->writer = new WriterService(
                batchSize:    (int) ($direct['batch_size'] ?? 1000),
                flushSeconds: (int) ($direct['flush_seconds'] ?? 5),
                indexPrefix:  config('storage.index_prefix', 'easylog-'),
            );
        }
        return $this->writer;
    }
}
