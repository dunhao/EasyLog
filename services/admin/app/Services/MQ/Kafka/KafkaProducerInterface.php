<?php

declare(strict_types=1);

namespace EasyLog\Services\MQ\Kafka;

/**
 * 内部 Kafka Producer 抽象
 *
 * 多驱动：
 * - rdkafka：生产环境（需 ext-rdkafka + librdkafka）
 * - log：本地开发，仅将消息写入 Laravel log，便于排查
 * - inmemory：单元测试，内存数组捕获消息供断言
 *
 * 通过 config('message-queue.bus.driver') 切换。
 */
interface KafkaProducerInterface
{
    /**
     * 投递单条日志到内部 Kafka
     *
     * @param string $topicName 完整 topic 名（如 easylog.ingest.{ws}.{topic}）
     * @param string $key       分区键（按 workspace 哈希，保证同租户消息落到同分区）
     * @param string $payload   JSON 编码后的日志体
     * @param array<string,mixed> $headers 消息头（request_id / tenant_id / workspace_id / topic_id）
     */
    public function produce(string $topicName, string $key, string $payload, array $headers = []): void;

    /**
     * 批量投递（按 topic 聚合，单次 flush 一次网络往返）
     *
     * @param array<int, array{topic:string,key:string,payload:string,headers?:array}> $messages
     */
    public function batchProduce(array $messages): void;

    /**
     * 触发底层缓冲 flush（rdkafka 是异步，进程退出前需显式 flush）
     */
    public function flush(): void;

    /**
     * 探活内部 Kafka 集群
     */
    public function ping(): bool;
}
