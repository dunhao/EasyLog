<?php

declare(strict_types=1);

namespace EasyLog\Services\MQ\Kafka;

/**
 * 内存 Producer（单元测试用）
 *
 * 不连接真实 Kafka，将所有投递消息捕获到 $captured 数组，
 * 测试用例通过 getMessages() 断言消息内容。
 */
class InMemoryKafkaProducer implements KafkaProducerInterface
{
    /** @var array<int, array{topic:string,key:string,payload:string,headers:array}> */
    private array $captured = [];

    private bool $flushed = false;

    public function produce(string $topicName, string $key, string $payload, array $headers = []): void
    {
        $this->captured[] = [
            'topic'   => $topicName,
            'key'     => $key,
            'payload' => $payload,
            'headers' => $headers,
        ];
    }

    public function batchProduce(array $messages): void
    {
        foreach ($messages as $msg) {
            $this->produce(
                $msg['topic'],
                $msg['key'],
                $msg['payload'],
                $msg['headers'] ?? []
            );
        }
    }

    public function flush(): void
    {
        $this->flushed = true;
    }

    public function ping(): bool
    {
        return true;
    }

    /**
     * 测试辅助：取回捕获的所有消息
     *
     * @return array<int, array{topic:string,key:string,payload:string,headers:array}>
     */
    public function getMessages(): array
    {
        return $this->captured;
    }

    public function isFlushed(): bool
    {
        return $this->flushed;
    }

    public function reset(): void
    {
        $this->captured = [];
        $this->flushed = false;
    }
}
