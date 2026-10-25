<?php

declare(strict_types=1);

namespace EasyLog\Services\MQ;

use EasyLog\Services\MQ\Kafka\KafkaProducerInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 消息队列管理器
 *
 * 职责：
 * - 持有内部 Kafka Producer（按 config('message-queue.bus.driver') 选择 rdkafka|log|inmemory）
 * - 将归一化后的日志条目投递到内部 Kafka topic `easylog.ingest.{ws}.{topic}`
 * - 分区键按 workspace 哈希，保证同租户消息落到同分区（消费侧可保留顺序）
 * - 列出可用的外部 MQ 消费者配置（Consumer Hub 由 ConsumeCommand 单独驱动）
 */
class MessageQueueManager
{
    private array $config;
    private KafkaProducerInterface $producer;
    private bool $producerInitialized = false;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * 内部 Kafka Producer 实例（lazy 加载）
     */
    public function producer(): KafkaProducerInterface
    {
        if ($this->producerInitialized) {
            return $this->producer;
        }

        $bus = $this->config['bus'] ?? [];
        $driver = $bus['driver'] ?? 'log';
        $brokers = $bus['brokers'] ?? 'localhost:9092';

        $producerConfig = [
            'brokers'  => $brokers,
            'sasl'     => $bus['sasl'] ?? ['enabled' => false],
            'tls'      => $bus['tls'] ?? false,
            'ca_cert'  => $bus['ca_cert'] ?? null,
        ];

        $this->producer = match ($driver) {
            'rdkafka' => $this->makeRdkafkaProducer($producerConfig),
            'direct'  => new \EasyLog\Services\MQ\Kafka\DirectKafkaProducer(),
            'log'     => new \EasyLog\Services\MQ\Kafka\LogKafkaProducer(),
            'inmemory'=> new \EasyLog\Services\MQ\Kafka\InMemoryKafkaProducer(),
            default   => throw new RuntimeException("Unsupported MQ bus driver: {$driver}"),
        };
        $this->producerInitialized = true;

        Log::debug('EasyLog MQ producer initialized', ['driver' => $driver, 'brokers' => $brokers]);

        return $this->producer;
    }

    /**
     * 测试时注入 Producer（绕过 lazy 初始化）
     */
    public function setProducer(KafkaProducerInterface $producer): void
    {
        $this->producer = $producer;
        $this->producerInitialized = true;
    }

    /**
     * 投递归一化后的单条日志到内部 Kafka
     *
     * @param int $workspaceId   workspace 数字主键（用于日志路由）
     * @param int $topicId       topic 数字主键
     * @param string $workspaceUuid
     * @param string $topicUuid
     * @param array<string,mixed> $logEntry 已归一化的日志条目
     * @param array<string,mixed> $headers  消息头（request_id 等）
     */
    public function publishLog(
        int $workspaceId,
        int $topicId,
        string $workspaceUuid,
        string $topicUuid,
        array $logEntry,
        array $headers = []
    ): void {
        $topicName = $this->topicName($workspaceUuid, $topicUuid);
        $key = $this->partitionKey($workspaceUuid);
        $payload = $this->encodePayload($logEntry);
        $headers = array_merge([
            'workspace_id' => (string) $workspaceId,
            'topic_id'     => (string) $topicId,
            'tenant_id'    => (string) ($logEntry['_tenant_id'] ?? ''),
        ], $headers);

        $this->producer()->produce($topicName, $key, $payload, $headers);
    }

    /**
     * 批量投递（高吞吐写入路径专用）
     *
     * @param array<int, array{
     *   workspace_id:int,topic_id:int,workspace_uuid:string,topic_uuid:string,
     *   entry:array<string,mixed>,headers?:array<string,mixed>
     * }> $batch
     */
    public function batchProduce(array $batch): void
    {
        if (empty($batch)) return;

        $messages = [];
        foreach ($batch as $item) {
            $topicName = $this->topicName($item['workspace_uuid'], $item['topic_uuid']);
            $key = $this->partitionKey($item['workspace_uuid']);
            $payload = $this->encodePayload($item['entry']);
            $headers = array_merge([
                'workspace_id' => (string) $item['workspace_id'],
                'topic_id'     => (string) $item['topic_id'],
                'tenant_id'    => (string) ($item['entry']['_tenant_id'] ?? ''),
            ], $item['headers'] ?? []);
            $messages[] = [
                'topic'   => $topicName,
                'key'     => $key,
                'payload' => $payload,
                'headers' => $headers,
            ];
        }

        $this->producer()->batchProduce($messages);
    }

    /**
     * 进程退出前显式 flush
     */
    public function flush(): void
    {
        if ($this->producerInitialized) {
            $this->producer->flush();
        }
    }

    /**
     * 内部 Kafka 集群探活
     */
    public function ping(): bool
    {
        try {
            return $this->producer()->ping();
        } catch (\Throwable $e) {
            Log::warning('EasyLog MQ ping failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 列出可用的外部 MQ 消费者配置（由 ConsumeCommand 读取启动）
     */
    public function availableConsumers(): array
    {
        $consumers = [];
        foreach ($this->config['consumers'] ?? [] as $type => $cfg) {
            if (!empty($cfg['enabled'])) {
                $consumers[$type] = $cfg;
            }
        }
        return $consumers;
    }

    /**
     * 构造 topic 名：easylog.ingest.{workspace_uuid}.{topic_uuid}
     */
    public function topicName(string $workspaceUuid, string $topicUuid): string
    {
        $prefix = $this->config['bus']['topic_prefix'] ?? 'easylog.ingest.';
        // prefix 默认已含末尾点号；若用户传入 'easylog.ingest' 也容错
        if (!str_ends_with($prefix, '.')) $prefix .= '.';
        return $prefix . $workspaceUuid . '.' . $topicUuid;
    }

    /**
     * 分区键：按 workspace 哈希
     * （broker / partitioner 会再对 key 做一次 murmur2 取模，
     *  我们的职责是让相同 workspace 始终用相同 key）
     */
    public function partitionKey(string $workspaceUuid): string
    {
        return 'ws-' . $workspaceUuid;
    }

    /**
     * 编码 payload：JSON_UNESCAPED_UNICODE 保证中文可读
     */
    private function encodePayload(array $entry): string
    {
        return json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * rdkafka producer 工厂：扩展未装时降级到 log producer
     * 保证 PHP 应用在无 rdkafka 环境仍可启动（让 Ingest API 仍可接受请求）
     */
    private function makeRdkafkaProducer(array $config): KafkaProducerInterface
    {
        if (extension_loaded('rdkafka')) {
            return new \EasyLog\Services\MQ\Kafka\RdkafkaProducer($config);
        }
        Log::warning('ext-rdkafka not loaded, falling back to log producer. Set MQ_BUS_DRIVER=log explicitly to silence this warning.');
        return new \EasyLog\Services\MQ\Kafka\LogKafkaProducer();
    }
}
