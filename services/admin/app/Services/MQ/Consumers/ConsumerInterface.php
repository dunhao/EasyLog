<?php

namespace EasyLog\Services\MQ\Consumers;

use EasyLog\Models\IngestionEndpoint;

/**
 * MQ 消费者统一接口
 *
 * 所有外部 MQ 类型（RabbitMQ/Kafka/Redis/ActiveMQ）都实现此接口，
 * 消息归一化后通过 MessageQueue facade 投递到内部 Kafka。
 */
interface ConsumerInterface
{
    /**
     * 启动消费循环
     */
    public function consume(IngestionEndpoint $endpoint): void;

    /**
     * 校验接入点配置（创建/更新时调用）
     */
    public function validateConfig(array $config): array;

    /**
     * 测试连接（控制台 [测试连接] 按钮调用，5 秒超时）
     *
     * @return array{success:bool, message:string}
     */
    public function testConnection(array $config): array;
}
