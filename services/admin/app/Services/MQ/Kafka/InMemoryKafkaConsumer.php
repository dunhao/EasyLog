<?php

declare(strict_types=1);

namespace EasyLog\Services\MQ\Kafka;

/**
 * 内存 Consumer（单元测试用）
 *
 * 测试用例通过 enqueue() 注入消息，consume() 按顺序吐出，
 * 用于验证 WriterCommand / WriterService 的消费回调逻辑。
 */
class InMemoryKafkaConsumer implements KafkaConsumerInterface
{
    /** @var array<int, array{topic:string,payload:string,key:?string,headers:array,partition:int,offset:int}> */
    private array $queue = [];
    private int $index = 0;
    private array $subscribed = [];

    public function subscribe(array $topics): void
    {
        $this->subscribed = $topics;
    }

    public function consume(int $timeoutMs): ?array
    {
        if (!isset($this->queue[$this->index])) {
            return null;
        }
        return $this->queue[$this->index++];
    }

    public function commit(): void
    {
        // no-op：内存队列无 offset 概念
    }

    public function close(): void
    {
        $this->queue = [];
        $this->index = 0;
        $this->subscribed = [];
    }

    public function ping(): bool
    {
        return true;
    }

    /**
     * 测试辅助：注入一条消息
     */
    public function enqueue(string $topic, string $payload, ?string $key = null, array $headers = []): void
    {
        $this->queue[] = [
            'topic'     => $topic,
            'payload'   => $payload,
            'key'       => $key,
            'headers'   => $headers,
            'partition' => 0,
            'offset'    => count($this->queue),
        ];
    }

    /**
     * 测试辅助：剩余待消费消息数
     */
    public function pending(): int
    {
        return max(0, count($this->queue) - $this->index);
    }
}
