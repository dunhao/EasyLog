<?php

namespace EasyLog\Services\MQ;

use EasyLog\Services\MQ\Consumers\ConsumerInterface;
use InvalidArgumentException;

/**
 * MQ 消费者工厂
 *
 * 根据 source_type 返回对应的消费者实现。
 */
class ConsumerFactory
{
    public static function make(string $sourceType): ConsumerInterface
    {
        return match ($sourceType) {
            'kafka'    => new \EasyLog\Services\MQ\Consumers\KafkaConsumer(),
            'rabbitmq' => new \EasyLog\Services\MQ\Consumers\RabbitMqConsumer(),
            'redis'    => new \EasyLog\Services\MQ\Consumers\RedisStreamConsumer(),
            'activemq' => new \EasyLog\Services\MQ\Consumers\ActiveMqConsumer(),
            default => throw new InvalidArgumentException("Unsupported source_type: {$sourceType}"),
        };
    }
}
