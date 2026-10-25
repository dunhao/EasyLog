<?php

declare(strict_types=1);

namespace EasyLog\Services\MQ\Kafka;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * rdkafka Producer（生产环境）
 *
 * 依赖：ext-rdkafka（librdkafka 2.x）
 * 异步投递：内部维护进程级 producer 实例，按需 lazy 初始化，
 * 析构时调用 flush(timeoutMs) 保证进程退出前消息落盘。
 */
class RdkafkaProducer implements KafkaProducerInterface
{
    private array $config;
    private ?\RdKafka\Producer $producer = null;
    private array $topics = [];

    public function __construct(array $config)
    {
        if (!extension_loaded('rdkafka')) {
            throw new RuntimeException('ext-rdkafka is required for rdkafka producer driver.');
        }
        $this->config = $config;
    }

    public function produce(string $topicName, string $key, string $payload, array $headers = []): void
    {
        $topic = $this->getTopic($topicName);
        $topic->producev(
            RD_KAFKA_PARTITION_UA,   // 让 partitioner 按 key 哈希决定分区
            0,                       // msgflags
            $payload,
            $key,
            $headers
        );

        // 触发一次非阻塞 poll：驱动 producer 实际把消息推给 broker。
        // 必须保留——在 Octane/Swoole 这类常驻进程里 producer 是单例，
        // 不会走到 __destruct，缺少 poll 会导致消息一直滞留在本地队列。
        $this->producer?->poll(0);
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
        // 触发一次内部 poll，让 producer 真正把消息推到 broker
        $this->producer?->poll(0);
    }

    public function flush(): void
    {
        if ($this->producer === null) return;
        // 等待所有 in-flight 消息落盘，最多 10s
        for ($i = 0; $i < 10; $i++) {
            $result = $this->producer->flush(1000);
            if ($result === RD_KAFKA_RESP_ERR_NO_ERROR) {
                return;
            }
        }
        Log::warning('RdkafkaProducer flush timed out, in-flight messages may be lost');
    }

    public function ping(): bool
    {
        try {
            $rdConf = $this->buildConf();
            // 用 metadata 请求探活
            $producer = new \RdKafka\Producer($rdConf);
            $producer->poll(0);
            $brokers = $this->config['brokers'] ?? '';
            // 简化：只要能 new 出 producer 即视为配置可用
            return $brokers !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function __destruct()
    {
        $this->flush();
    }

    private function getTopic(string $name): \RdKafka\ProducerTopic
    {
        if (isset($this->topics[$name])) {
            return $this->topics[$name];
        }
        if ($this->producer === null) {
            $this->producer = new \RdKafka\Producer($this->buildConf());
        }
        return $this->topics[$name] = $this->producer->newTopic($name);
    }

    private function buildConf(): \RdKafka\Conf
    {
        $conf = new \RdKafka\Conf();
        $conf->set('metadata.broker.list', (string) ($this->config['brokers'] ?? 'localhost:9092'));

        // SASL
        if (!empty($this->config['sasl']['enabled'])) {
            $conf->set('sasl.mechanism', $this->config['sasl']['mechanism'] ?? 'PLAIN');
            $conf->set('sasl.username', (string) ($this->config['sasl']['user'] ?? ''));
            $conf->set('sasl.password', (string) ($this->config['sasl']['password'] ?? ''));
        }

        // TLS
        if (!empty($this->config['tls'])) {
            $conf->set('security.protocol', 'SASL_SSL');
            if (!empty($this->config['ca_cert'])) {
                $conf->set('ssl.ca.location', $this->config['ca_cert']);
            }
        }

        // 幂等 producer（避免重复消息），需要 broker >= 0.11
        try {
            $conf->set('enable.idempotence', 'true');
            $conf->set('acks', 'all');
        } catch (\Throwable) {
            // 老版本 librdkafka 不支持，忽略
        }

        // 内部分发回调（错误日志）
        $conf->setDrMsgCb(function (\RdKafka\Producer $producer, \RdKafka\Message $message) {
            if ($message->err !== 0) {
                Log::error('RdkafkaProducer delivery failed', [
                    'topic' => $message->topic_name,
                    'err'   => $message->err,
                    'str'   => $message->errstr(),
                ]);
            }
        });

        return $conf;
    }
}
