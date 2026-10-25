<?php

namespace EasyLog\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * 消息队列抽象（内部 Kafka Producer + 外部 MQ Consumer 统一管理）
 */
class MessageQueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('easylog.mq', function ($app) {
            return new \EasyLog\Services\MQ\MessageQueueManager(config('message-queue'));
        });
    }
}
