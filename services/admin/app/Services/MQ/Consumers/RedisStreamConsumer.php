<?php

namespace EasyLog\Services\MQ\Consumers;

use EasyLog\Facades\MessageQueue;
use EasyLog\Models\IngestionEndpoint;
use Illuminate\Support\Facades\Log;
use Predis\Client;

/**
 * Redis 消费者实现（基于 predis）
 *
 * 支持三种模式：
 * - stream (XREADGROUP, 生产推荐)
 * - pubsub (SUBSCRIBE, 不持久化)
 * - list (BLPOP)
 */
class RedisStreamConsumer implements ConsumerInterface
{
    public function consume(IngestionEndpoint $endpoint): void
    {
        $config = $endpoint->config;
        $mode = $config['mode'] ?? 'stream';
        match ($mode) {
            'stream' => $this->consumeStream($endpoint, $config),
            'list'   => $this->consumeList($endpoint, $config),
            'pubsub' => $this->consumePubSub($endpoint, $config),
            default => throw new \InvalidArgumentException("Unknown Redis mode: $mode"),
        };
    }

    private function consumeStream(IngestionEndpoint $endpoint, array $config): void
    {
        $redis = new Client($config['dsn']);
        $group = $config['consumer_group'] ?? 'easylog';
        $consumer = $config['consumer_name'] ?? 'c-'.getmypid();
        $block = ($config['block_ms'] ?? 5000) / 1000;
        $batch = $config['batch_size'] ?? 1000;

        try {
            $redis->xgroup('CREATE', $config['stream_key'], $group, '$', 'MKSTREAM');
        } catch (\Throwable $e) {
            // GROUP 已存在忽略
        }

        while (true) {
            $entries = $redis->xreadgroup(
                'GROUP', $group, $consumer,
                'COUNT', $batch,
                'BLOCK', (int) ($config['block_ms'] ?? 5000),
                'STREAMS', $config['stream_key'], '>'
            );
            if (empty($entries)) continue;
            foreach ($entries[$config['stream_key']] ?? [] as $id => $fields) {
                $payload = $fields['data'] ?? json_encode($fields);
                $entry = $this->normalize($payload, $endpoint);
                MessageQueue::publishLog(
                    $endpoint->workspace_id,
                    $endpoint->topic_id,
                    $endpoint->workspace?->uuid ?? '',
                    $endpoint->topic?->uuid ?? '',
                    $entry
                );
                $redis->xack($config['stream_key'], $group, $id);
            }
        }
    }

    private function consumeList(IngestionEndpoint $endpoint, $config): void
    {
        $redis = new Client($config['dsn']);
        while (true) {
            $msg = $redis->blpop($config['list_key'], (int) ($config['block_ms'] ?? 5000) / 1000);
            if (empty($msg)) continue;
            $entry = $this->normalize($msg[1], $endpoint);
            MessageQueue::publishLog(
                $endpoint->workspace_id,
                $endpoint->topic_id,
                $endpoint->workspace?->uuid ?? '',
                $endpoint->topic?->uuid ?? '',
                $entry
            );
        }
    }

    private function consumePubSub(IngestionEndpoint $endpoint, $config): void
    {
        $redis = new Client($config['dsn'], ['read_timeout' => 0]);
        $pubsub = $redis->pubsub('subscribe', $config['channel'] ?? 'easylog');
        foreach ($pubsub as $msg) {
            if ($msg->kind !== 'message') continue;
            $entry = $this->normalize($msg->payload, $endpoint);
            MessageQueue::publishLog(
                $endpoint->workspace_id,
                $endpoint->topic_id,
                $endpoint->workspace?->uuid ?? '',
                $endpoint->topic?->uuid ?? '',
                $entry
            );
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
        if (empty($config['dsn'])) $errors[] = 'dsn required';
        if (empty($config['mode'])) $errors[] = 'mode required';
        $mode = $config['mode'] ?? '';
        if ($mode === 'stream' && empty($config['stream_key'])) $errors[] = 'stream_key required for stream mode';
        if ($mode === 'list' && empty($config['list_key'])) $errors[] = 'list_key required for list mode';
        if ($mode === 'pubsub' && empty($config['channel'])) $errors[] = 'channel required for pubsub mode';
        return $errors;
    }

    public function testConnection(array $config): array
    {
        try {
            $redis = new Client($config['dsn']);
            $redis->ping();
            return ['success' => true, 'message' => '连接成功'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
