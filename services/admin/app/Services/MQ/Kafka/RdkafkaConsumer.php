<?php

declare(strict_types=1);

namespace EasyLog\Services\MQ\Kafka;

use Illuminate\Support\Facades\Log;
use RdKafka\Conf as RdConf;
use RdKafka\KafkaConsumer as KafkaConsumerClient;
use RdKafka\Message as RdMessage;
use RuntimeException;

/**
 * rdkafka Consumer（生产环境）
 *
 * 依赖：ext-rdkafka（librdkafka 2.x）
 * 使用 KafkaConsumer（高级别 API）+ consumer group 自动 rebalance。
 * offset 默认手动 commit（消费成功后调 commit()）。
 */
class RdkafkaConsumer implements KafkaConsumerInterface
{
    private array $config;
    private ?KafkaConsumerClient $consumer = null;
    private ?RdMessage $lastMessage = null;

    public function __construct(array $config)
    {
        if (!extension_loaded('rdkafka')) {
            throw new RuntimeException('ext-rdkafka is required for rdkafka consumer driver.');
        }
        $this->config = $config;
    }

    public function subscribe(array $topics): void
    {
        $consumer = $this->getConsumer();
        // rdkafka 的 subscribe 支持正则前缀 ^，例如 ^easylog\.ingest\..*
        $consumer->subscribe($topics);
    }

    public function consume(int $timeoutMs): ?array
    {
        $consumer = $this->getConsumer();
        $message = $consumer->consume($timeoutMs);

        switch ($message->err) {
            case RD_KAFKA_RESP_ERR_NO_ERROR:
                $this->lastMessage = $message;
                return [
                    'topic'     => $message->topic_name,
                    'payload'   => $message->payload,
                    'key'       => $message->key,
                    'headers'   => $this->extractHeaders($message),
                    'partition' => $message->partition,
                    'offset'    => $message->offset,
                ];
            case RD_KAFKA_RESP_ERR__TIMED_OUT:
            case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                return null;
            default:
                Log::error('RdkafkaConsumer consume error', [
                    'err' => $message->err,
                    'str' => $message->errstr(),
                ]);
                return null;
        }
    }

    public function commit(): void
    {
        if ($this->lastMessage !== null) {
            $this->getConsumer()->commit($this->lastMessage);
            $this->lastMessage = null;
        }
    }

    public function close(): void
    {
        if ($this->consumer !== null) {
            $this->consumer->close();
            $this->consumer = null;
        }
    }

    public function ping(): bool
    {
        try {
            $this->getConsumer();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function getConsumer(): KafkaConsumerClient
    {
        if ($this->consumer !== null) {
            return $this->consumer;
        }

        $conf = new RdConf();
        $conf->set('metadata.broker.list', (string) ($this->config['brokers'] ?? 'localhost:9092'));
        $conf->set('group.id', $this->config['group_id'] ?? 'easylog-writer');
        $conf->set('enable.auto.commit', 'false');
        $conf->set('auto.offset.reset', $this->config['auto_offset_reset'] ?? 'latest');

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

        // rebalance 回调
        // 注意：类型声明必须用完全限定名 \RdKafka\KafkaConsumer。
        // 本文件命名空间是 EasyLog\Services\MQ\Kafka，写 RdKafka\KafkaConsumer
        // 会被解析成 EasyLog\Services\MQ\Kafka\RdKafka\KafkaConsumer，
        // 触发 "must be of type ...\RdKafka\KafkaConsumer, RdKafka\KafkaConsumer given" 致命错误。
        $conf->setRebalanceCb(function (\RdKafka\KafkaConsumer $kafka, int $err, array $partitions = null) {
            switch ($err) {
                case RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS:
                    $kafka->assign($partitions);
                    break;
                case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
                    $kafka->assign(null);
                    break;
                default:
                    Log::error('RdkafkaConsumer rebalance error', ['err' => $err]);
                    $kafka->assign(null);
            }
        });

        $this->consumer = new KafkaConsumerClient($conf);
        return $this->consumer;
    }

    /**
     * 从 RdMessage 提取 headers
     *
     * php-rdkafka 6.x 的 Message::$headers 已是 array<string,string>（key => value）；
     * 5.x 及更早是 [['key'=>..,'value'=>..], ...]。
     * 两种形态都要兼容，否则 6.x 上会抛
     * "Cannot access offset of type string on string"。
     */
    private function extractHeaders(RdMessage $message): array
    {
        $headers = [];
        foreach ((array) ($message->headers ?? []) as $k => $h) {
            if (is_array($h) && array_key_exists('key', $h)) {
                // 旧格式（rdkafka < 6）
                $headers[$h['key']] = $h['value'] ?? null;
            } else {
                // 新格式（rdkafka >= 6）
                $headers[$k] = $h;
            }
        }
        return $headers;
    }
}
