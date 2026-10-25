<?php

declare(strict_types=1);

namespace EasyLog\Services\MQ\Kafka;

/**
 * 内部 Kafka Consumer 抽象
 *
 * 多驱动：
 * - rdkafka：生产环境（需 ext-rdkafka + librdkafka）
 * - inmemory：单元测试（同步内存队列）
 * - log：本地开发占位（无消息消费，循环空跑 sleep，仅验证进程可启动）
 *
 * 通过 config('message-queue.bus.driver') 切换。
 */
interface KafkaConsumerInterface
{
    /**
     * 订阅一组 topic（支持正则模式 ^easylog.ingest.*）
     *
     * @param array<int,string> $topics
     */
    public function subscribe(array $topics): void;

    /**
     * 阻塞消费下一条消息
     *
     * @param int $timeoutMs 阻塞超时，0 = 立即返回
     * @return array{topic:string,payload:string,key:?string,headers:array,partition:int,offset:int}|null
     *               返回 null 表示超时无消息
     */
    public function consume(int $timeoutMs): ?array;

    /**
     * 异步提交当前分区 offset
     */
    public function commit(): void;

    /**
     * 关闭消费者，释放底层资源
     */
    public function close(): void;

    /**
     * 探活内部 Kafka 集群
     */
    public function ping(): bool;
}
