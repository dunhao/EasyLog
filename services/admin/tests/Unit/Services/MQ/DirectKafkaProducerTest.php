<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MQ;

use EasyLog\Services\MQ\Kafka\DirectKafkaProducer;
use EasyLog\Services\Storage\StorageAdapterInterface;
use Tests\TestCase;

/**
 * Direct 直写总线驱动测试（lite 档）
 *
 * produce/batchProduce 的消息应经 WriterService 缓冲后由 flush 落到存储适配器，
 * 复用 WriterService 的索引路由（_workspace_uuid/_topic_uuid → 索引名）与
 * 路由字段剥离逻辑。
 */
class DirectKafkaProducerTest extends TestCase
{
    /** @var array<int, array{index:string, docs:array<array<string,mixed>>}> */
    private array $written = [];

    private DirectKafkaProducer $producer;

    protected function setUp(): void
    {
        parent::setUp();

        $capturing = new class ($this->written) implements StorageAdapterInterface {
            /** @param array<int, array{index:string, docs:array<array<string,mixed>>}> $written */
            public function __construct(private array &$written) {}

            public function bulkWrite(string $indexName, array $docs): array
            {
                $this->written[] = ['index' => $indexName, 'docs' => $docs];
                return ['success' => count($docs), 'failed' => 0, 'errors' => null];
            }
            public function search(string $indexPattern, array $dsl): array { return ['hits' => [], 'total' => 0, 'took_ms' => 0]; }
            public function aggregate(string $indexPattern, array $dsl): array { return []; }
            public function createIndex(string $indexName, array $mapping): void {}
            public function ensureMonthlyRoll(string $indexPrefix): void {}
            public function listIndices(string $indexPrefix): array { return []; }
            public function deleteIndex(string $indexName): void {}
            public function ping(): bool { return true; }
        };
        $this->app->instance('easylog.storage', $capturing);

        $this->producer = new DirectKafkaProducer();
    }

    public function testProduceRoutesToWriterAndStripsRoutingFields(): void
    {
        $entry = [
            '_tenant_id' => 1,
            '_workspace_id' => 7,
            '_workspace_uuid' => 'ws-uuid-1',
            '_topic_id' => 9,
            '_topic_uuid' => 'top-uuid-2',
            '_received_at' => '2026-10-25T10:00:00Z',
            'msg' => '直写测试',
            'level' => 'ERROR',
            'ts' => '2026-10-25T10:00:00Z',
            'service' => 'order',
        ];

        $this->producer->produce('easylog.ingest.ws-uuid-1.top-uuid-2', 'ws-ws-uuid-1', json_encode($entry, JSON_THROW_ON_ERROR), [
            'workspace_id' => '7', 'topic_id' => '9', 'tenant_id' => '1',
        ]);
        $this->producer->flush();

        $this->assertCount(1, $this->written);
        [$write] = $this->written;

        // 索引名由消息体路由字段决定（WriterService::buildIndexName）
        $this->assertStringStartsWith('easylog-ws-uuid-1-top-uuid-2-', $write['index']);

        // _ 前缀路由字段剥离，业务字段 + 兜底 id 字段保留
        $doc = $write['docs'][0];
        $this->assertSame('直写测试', $doc['msg']);
        $this->assertSame('order', $doc['service']);
        $this->assertSame('1', $doc['tenant_id']);
        $this->assertSame('7', $doc['workspace_id']);
        $this->assertSame('9', $doc['topic_id']);
        $this->assertArrayNotHasKey('_workspace_uuid', $doc);
    }

    public function testBatchProduceFlushesAllMessages(): void
    {
        $mk = fn (string $msg): array => [
            '_tenant_id' => 1, '_workspace_id' => 7,
            '_workspace_uuid' => 'ws-uuid-1', '_topic_id' => 9, '_topic_uuid' => 'top-uuid-2',
            'msg' => $msg, 'level' => 'INFO', 'ts' => '2026-10-25T10:00:00Z',
        ];

        $this->producer->batchProduce([
            ['topic' => 'easylog.ingest.ws-uuid-1.top-uuid-2', 'key' => 'k', 'payload' => json_encode($mk('a')), 'headers' => []],
            ['topic' => 'easylog.ingest.ws-uuid-1.top-uuid-2', 'key' => 'k', 'payload' => json_encode($mk('b')), 'headers' => []],
        ]);
        $this->producer->flush();

        $this->assertCount(1, $this->written);
        $this->assertCount(2, $this->written[0]['docs']);
    }

    public function testMalformedPayloadIsSkippedSafely(): void
    {
        // 坏 JSON / 非 JSON 顶层结构：不抛异常，不产生写入
        $this->producer->produce('t', 'k', '{broken json', []);
        $this->producer->batchProduce([['topic' => 't', 'key' => 'k', 'payload' => '"scalar"', 'headers' => []]]);
        $this->producer->flush();

        $this->assertSame([], $this->written);
    }

    public function testPingAlwaysTrue(): void
    {
        $this->assertTrue($this->producer->ping());
    }
}
