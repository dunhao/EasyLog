<?php

namespace EasyLog\Services\MQ\Consumers;

use EasyLog\Facades\MessageQueue;
use EasyLog\Models\IngestionEndpoint;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * RabbitMQ 消费者实现（基于 php-amqplib）
 *
 * 支持队列消费 + Exchange 路由（direct/topic/fanout/headers）
 * 多副本通过多个消费者共享同一 queue 实现
 */
class RabbitMqConsumer implements ConsumerInterface
{
    public function consume(IngestionEndpoint $endpoint): void
    {
        $config = $endpoint->config;
        $conn = new AMQPStreamConnection(
            $config['host'], $config['port'] ?? 5672,
            $config['user'], $config['password'],
            $config['vhost'] ?? '/', false
        );
        $channel = $conn->channel();
        $prefetch = $config['prefetch_count'] ?? 1000;
        $channel->basic_qos(null, $prefetch, null);

        $callback = function (AMQPMessage $msg) use ($endpoint, $config) {
            $entry = $this->normalize($msg->body, $endpoint);
            MessageQueue::publishLog(
                $endpoint->workspace_id,
                $endpoint->topic_id,
                $endpoint->workspace?->uuid ?? '',
                $endpoint->topic?->uuid ?? '',
                $entry
            );
            if ($config['auto_ack'] ?? false) {
                return;
            }
            $msg->ack();
        };

        $channel->basic_consume(
            $config['queue'],
            '',
            false,
            $config['auto_ack'] ?? false,
            false,
            false,
            $callback
        );

        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }

    private function normalize(string $payload, IngestionEndpoint $endpoint): array
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            $decoded = ['msg' => $payload, '_raw' => $payload];
        }
        $decoded['received_at'] = now()->toISOString();
        return $decoded;
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        if (empty($config['host'])) $errors[] = 'host required';
        if (empty($config['queue']) && empty($config['exchange'])) $errors[] = 'queue or exchange required';
        return $errors;
    }

    public function testConnection(array $config): array
    {
        try {
            $conn = new AMQPStreamConnection(
                $config['host'], $config['port'] ?? 5672,
                $config['user'] ?? 'guest', $config['password'] ?? 'guest',
                $config['vhost'] ?? '/', false
            );
            $ch = $conn->channel();
            $q = $ch->queue_declare($config['queue'] ?? 'test', false, false, false, false);
            $conn->close();
            return ['success' => true, 'message' => '连接成功，queue 消息数: '.($q[1] ?? 0)];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
