<?php

namespace EasyLog\Services\MQ\Consumers;

use EasyLog\Facades\MessageQueue;
use EasyLog\Models\IngestionEndpoint;
use Illuminate\Support\Facades\Log;
use Stomp\Client;
use Stomp\StatefulStomp;
use Stomp\Transport\Message as StompMessage;
use Stomp\Transport\Frame;

/**
 * ActiveMQ 消费者实现（基于 stomp-php，Stomp 1.2 协议）
 *
 * 注意：ActiveMQ Topic 模式下，多副本会产生重复消息；
 * Queue 模式下多副本共享消费，安全。
 */
class ActiveMqConsumer implements ConsumerInterface
{
    public function consume(IngestionEndpoint $endpoint): void
    {
        $config = $endpoint->config;
        $client = new Client(sprintf('tcp://%s:%d', $config['host'], $config['port'] ?? 61613));
        $client->setLogin($config['login'] ?? '', $config['passcode'] ?? '');
        $stomp = new StatefulStomp($client);

        $dest = $config['destination'] ?? '';
        if (($config['destination_type'] ?? 'queue') === 'queue') {
            $dest = '/queue/'.$dest;
        } else {
            $dest = '/topic/'.$dest;
        }
        $selector = $config['selector'] ?? null;
        $stomp->subscribe($dest, $selector, ($config['ack'] ?? 'client') === 'client' ? 'client' : 'auto');

        while (true) {
            $frame = $stomp->read();
            if (!$frame instanceof Frame) {
                continue;
            }
            $entry = $this->normalize($frame->body, $endpoint);
            MessageQueue::publishLog(
                $endpoint->workspace_id,
                $endpoint->topic_id,
                $endpoint->workspace?->uuid ?? '',
                $endpoint->topic?->uuid ?? '',
                $entry
            );
            if (($config['ack'] ?? 'client') === 'client') {
                $stomp->ack($frame);
            }
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
        if (empty($config['destination'])) $errors[] = 'destination required';
        if (empty($config['login'])) $errors[] = 'login required';
        return $errors;
    }

    public function testConnection(array $config): array
    {
        try {
            $client = new Client(sprintf('tcp://%s:%d', $config['host'], $config['port'] ?? 61613));
            $client->setLogin($config['login'] ?? '', $config['passcode'] ?? '');
            $client->connect();
            return ['success' => true, 'message' => '连接成功'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
