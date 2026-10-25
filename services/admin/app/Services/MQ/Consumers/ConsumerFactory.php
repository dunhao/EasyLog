<?php

declare(strict_types=1);

namespace EasyLog\Services\MQ\Consumers;

/**
 * 消费者工厂：按 source_type 实例化对应的 MQ 消费者实现
 */
final class ConsumerFactory
{
    public static function make(string $sourceType): ConsumerInterface
    {
        return match ($sourceType) {
            'kafka'    => new KafkaConsumer(),
            'rabbitmq' => new RabbitMqConsumer(),
            'redis'    => new RedisStreamConsumer(),
            'activemq' => new ActiveMqConsumer(),
            default    => throw new \InvalidArgumentException("Unsupported consumer type: {$sourceType}"),
        };
    }
}
