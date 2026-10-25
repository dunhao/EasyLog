<?php

namespace EasyLog\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * MQ Facade（内部 Kafka Producer + 外部 4 种 MQ Consumer 抽象）
 */
class MessageQueue extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'easylog.mq';
    }
}
