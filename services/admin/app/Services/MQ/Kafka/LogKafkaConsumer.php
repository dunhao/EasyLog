<?php

declare(strict_types=1);

namespace EasyLog\Services\MQ\Kafka;

/**
 * Log Consumer（本地开发占位）
 *
 * 不连接真实 Kafka：consume() 永远返回 null（无消息），
 * 让 WriterCommand 的循环可以空转 + sleep，
 * 用于本地无 rdkafka 扩展时验证进程可启动。
 */
class LogKafkaConsumer implements KafkaConsumerInterface
{
    private array $subscribed = [];

    public function subscribe(array $topics): void
    {
        $this->subscribed = $topics;
    }

    public function consume(int $timeoutMs): ?array
    {
        // 模拟阻塞等待：sleep 一段后返回 null
        usleep(max(0, $timeoutMs) * 1000);
        return null;
    }

    public function commit(): void
    {
        // no-op
    }

    public function close(): void
    {
        $this->subscribed = [];
    }

    public function ping(): bool
    {
        return false;
    }
}
