<?php

namespace EasyLog\Services\MQ\Consumers;

use EasyLog\Facades\MessageQueue;
use EasyLog\Models\IngestionEndpoint;
use Illuminate\Support\Facades\Log;

/**
 * Kafka 消费者实现（基于 ext-rdkafka）
 *
 * 依赖：pecl install rdkafka (librdkafka 2.x)
 * 消费 Topic Pattern（支持通配符），按 consumer_group 自动 rebalance。
 */
class KafkaConsumer implements ConsumerInterface
{
    /** topic 发现刷新间隔（秒）：运行期新建的匹配 topic 无需重启 worker */
    private const TOPIC_DISCOVERY_INTERVAL = 30;

    /** @var array<string> 当前已订阅的 topic 列表（用于发现变化后重订阅） */
    private array $subscribedTopics = [];

    public function consume(IngestionEndpoint $endpoint): void
    {
        $config = $endpoint->config;
        $conf = new \RdKafka\Conf();

        // SASL / TLS
        if (!empty($config['sasl_user'])) {
            $conf->set('sasl.mechanism', $config['sasl_mechanism'] ?? 'PLAIN');
            $conf->set('sasl.username', $config['sasl_user']);
            $conf->set('sasl.password', $config['sasl_password'] ?? '');
        }
        if (!empty($config['tls'])) {
            $conf->set('security.protocol', 'SASL_SSL');
            if (!empty($config['ca_cert'])) {
                $conf->set('ssl.ca.location', $config['ca_cert']);
            }
        }

        $conf->set('group.id', $config['consumer_group']);
        $conf->set('metadata.broker.list', is_array($config['brokers']) ? implode(',', $config['brokers']) : $config['brokers']);
        $conf->set('auto.offset.reset', $config['from_offset'] ?? 'latest');
        $conf->set('enable.auto.commit', $config['auto_commit'] ?? 'true' ? 'true' : 'false');

        $consumer = new \RdKafka\KafkaConsumer($conf);

        // 初始订阅：按 pattern 从 broker metadata 展开（无匹配则空订阅，循环里周期重试）
        $this->subscribedTopics = $this->expandTopicPattern($config['topic_pattern'], $consumer);
        if (!empty($this->subscribedTopics)) {
            $consumer->subscribe($this->subscribedTopics);
            Log::info('KafkaConsumer subscribed', [
                'endpoint' => $endpoint->uuid,
                'pattern'  => $config['topic_pattern'],
                'topics'   => $this->subscribedTopics,
            ]);
        } else {
            Log::warning('KafkaConsumer no topics match pattern yet, will retry', [
                'endpoint' => $endpoint->uuid,
                'pattern'  => $config['topic_pattern'],
            ]);
        }

        $this->loop($consumer, $endpoint, (string) $config['topic_pattern']);
    }

    private function loop(\RdKafka\KafkaConsumer $consumer, IngestionEndpoint $endpoint, string $pattern): void
    {
        $lastDiscovery = time();
        while (true) {
            $msg = $consumer->consume(5000);
            switch ($msg->err) {
                case RD_KAFKA_RESP_ERR_NO_ERROR:
                    $this->processMessage($msg->payload, $endpoint);
                    break;
                case RD_KAFKA_RESP_ERR__TIMED_OUT:
                case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                    break;
                default:
                    Log::error('KafkaConsumer error', ['err' => $msg->errstr()]);
                    sleep(1);
            }

            // 周期性重新发现：broker 上新出现匹配 pattern 的 topic 时自动加入订阅
            if (time() - $lastDiscovery >= self::TOPIC_DISCOVERY_INTERVAL) {
                $lastDiscovery = time();
                $this->refreshSubscription($consumer, $endpoint, $pattern);
            }
        }
    }

    private function refreshSubscription(\RdKafka\KafkaConsumer $consumer, IngestionEndpoint $endpoint, string $pattern): void
    {
        try {
            $topics = $this->expandTopicPattern($pattern, $consumer);
        } catch (\Throwable $e) {
            Log::warning('KafkaConsumer topic rediscovery failed', ['error' => $e->getMessage()]);
            return;
        }

        if (empty($topics) || $topics === $this->subscribedTopics) {
            return;
        }

        $consumer->subscribe($topics);
        Log::info('KafkaConsumer subscription updated', [
            'endpoint' => $endpoint->uuid,
            'pattern'  => $pattern,
            'from'     => $this->subscribedTopics,
            'to'       => $topics,
        ]);
        $this->subscribedTopics = $topics;
    }

    private function processMessage(string $payload, IngestionEndpoint $endpoint): void
    {
        $entry = $this->normalize($payload, $endpoint);
        MessageQueue::publishLog(
            $endpoint->workspace_id,
            $endpoint->topic_id,
            $endpoint->workspace?->uuid ?? '',
            $endpoint->topic?->uuid ?? '',
            $entry
        );
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

    /**
     * 拉取 broker metadata，把 topic pattern 展开为实际 topic 列表
     */
    private function expandTopicPattern(string $pattern, \RdKafka\KafkaConsumer $consumer): array
    {
        $metadata = $consumer->getMetadata(false, 10000);
        $brokerTopics = [];
        foreach ($metadata->getTopics() as $topic) {
            $brokerTopics[] = $topic->getTopic();
        }

        return self::filterTopics($brokerTopics, $pattern);
    }

    /**
     * topic pattern 匹配：^ 开头按正则，否则支持 * 通配；无通配符视为精确名
     * 公开静态以便单测（不依赖 rdkafka 扩展）
     *
     * @param array<string> $topicNames
     * @return array<string>
     */
    public static function filterTopics(array $topicNames, string $pattern): array
    {
        $regex = self::patternToRegex($pattern);
        return array_values(array_filter($topicNames, fn ($t) => preg_match($regex, $t) === 1));
    }

    public static function patternToRegex(string $pattern): string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return '/^$/';
        }
        // ^ 开头：调用方直接传正则
        if ($pattern[0] === '^') {
            return '/' . str_replace('/', '\/', $pattern) . '/';
        }
        // 通配符 * → .*（其余字符按字面量处理）
        $parts = explode('*', $pattern);
        $escaped = array_map(fn ($p) => preg_quote($p, '/'), $parts);
        return '/^' . implode('.*', $escaped) . '$/';
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        if (empty($config['brokers'])) $errors[] = 'brokers required';
        if (empty($config['topic_pattern'])) $errors[] = 'topic_pattern required';
        if (empty($config['consumer_group'])) $errors[] = 'consumer_group required';
        return $errors;
    }

    public function testConnection(array $config): array
    {
        try {
            $conf = new \RdKafka\Conf();
            $conf->set('metadata.broker.list', is_array($config['brokers']) ? implode(',', $config['brokers']) : $config['brokers']);
            $conf->set('group.id', 'easylog-test-'.uniqid());
            $consumer = new \RdKafka\KafkaConsumer($conf);
            $topics = $consumer->getMetadata(false, 10000)->getTopics();
            return ['success' => true, 'message' => '连接成功，发现 '.count($topics).' 个 topic'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
