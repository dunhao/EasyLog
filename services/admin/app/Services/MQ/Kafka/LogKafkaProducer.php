<?php

declare(strict_types=1);

namespace EasyLog\Services\MQ\Kafka;

use Illuminate\Support\Facades\Log;

/**
 * Log Producer（本地开发用）
 *
 * 当本地未安装 ext-rdkafka 时使用：将消息写入 Laravel log channel
 * （storage/logs/easylog-mq.log），便于排查接入链路。
 *
 * 不提供任何集群探活能力，ping() 永远返回 false。
 */
class LogKafkaProducer implements KafkaProducerInterface
{
    public function produce(string $topicName, string $key, string $payload, array $headers = []): void
    {
        Log::channel('stack')->info('easylog.mq.produce', [
            'topic'   => $topicName,
            'key'     => $key,
            'payload' => $payload,
            'headers' => $headers,
        ]);
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
        // log 模式无缓冲，no-op
    }

    public function ping(): bool
    {
        return false;
    }
}
