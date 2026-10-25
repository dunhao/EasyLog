<?php

declare(strict_types=1);

namespace Tests\Feature;

use EasyLog\Facades\StorageAdapter;
use EasyLog\Services\Storage\StorageAdapterInterface;

/**
 * 检索 API 测试（Fake 存储捕获 DSL，验证隔离注入与结果过滤）
 */
class SearchApiTest extends AdminApiTestCase
{
    private FakeStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new FakeStorage();
        StorageAdapter::swap($this->storage);
    }

    public function testSearchTopicBuildsIsolatedDsl(): void
    {
        $this->storage->searchResult = [
            'hits' => [
                ['msg' => 'ok', 'workspace_id' => (string) $this->workspaceId, 'topic_id' => (string) $this->topicId],
            ],
            'total' => 1,
            'took_ms' => 5,
        ];

        $this->postJson('/api/v1/topics/' . self::TOPIC_UUID . '/search', [
            'query' => 'level:ERROR',
            'from'  => now()->subHour()->toISOString(),
            'to'    => now()->toISOString(),
        ])->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonCount(1, 'hits');

        // 索引模式与隔离 filter
        $this->assertStringContainsString(self::WORKSPACE_UUID . '-' . self::TOPIC_UUID, $this->storage->lastPattern);
        $this->assertStringContainsString('workspace_id', json_encode($this->storage->lastDsl));
        $this->assertStringContainsString('topic_id', json_encode($this->storage->lastDsl));
    }

    public function testSearchTopicFiltersOutCrossTenantHits(): void
    {
        // 存储返回了其它租户/主题的文档（模拟索引配置错误），二次过滤应剔除
        $this->storage->searchResult = [
            'hits' => [
                ['msg' => 'mine', 'workspace_id' => (string) $this->workspaceId, 'topic_id' => (string) $this->topicId],
                ['msg' => 'theirs', 'workspace_id' => '999', 'topic_id' => '999'],
            ],
            'total' => 2,
            'took_ms' => 5,
        ];

        $this->postJson('/api/v1/topics/' . self::TOPIC_UUID . '/search', [
            'query' => '*',
            'from'  => now()->subHour()->toISOString(),
            'to'    => now()->toISOString(),
        ])->assertOk()
            ->assertJsonCount(1, 'hits')
            ->assertJsonPath('hits.0.msg', 'mine');
    }

    public function testSearchTopicCrossTenantTopicForbidden(): void
    {
        // 另一租户的 topic
        $otherTenantId = \Illuminate\Support\Facades\DB::table('tenants')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Other', 'slug' => 'other',
            'status' => 'active', 'plan' => 'free',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherWs = \Illuminate\Support\Facades\DB::table('workspaces')->insertGetId([
            'uuid' => 'ws_other_search', 'tenant_id' => $otherTenantId, 'name' => 'other',
            'description' => '', 'retention_days' => 30, 'shard_count' => 1, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('topics')->insert([
            'uuid' => 'tpc_other', 'workspace_id' => $otherWs, 'name' => 'stealth',
            'description' => '', 'auto_field_index' => true, 'retention_days' => 30,
            'enable_full_text' => true, 'json_extract' => true, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/topics/tpc_other/search', [
            'query' => '*',
            'from'  => now()->subHour()->toISOString(),
            'to'    => now()->toISOString(),
        ])->assertStatus(403);
    }

    public function testHistogramPassesBucketsThrough(): void
    {
        $this->storage->aggResult = [
            'histogram' => ['buckets' => [
                ['key_as_string' => '2026-09-14T12:00:00Z', 'doc_count' => 10],
            ]],
        ];

        $this->postJson('/api/v1/topics/' . self::TOPIC_UUID . '/histogram', [
            'query'  => '',
            'from'   => now()->subHour()->toISOString(),
            'to'     => now()->toISOString(),
            'bucket' => 'minute',
        ])->assertOk()
            ->assertJsonCount(1, 'buckets')
            ->assertJsonPath('buckets.0.doc_count', 10);
    }

    public function testAggregatePassesAggregationsThrough(): void
    {
        $this->storage->aggResult = [
            'result' => [
                'buckets' => [
                    ['key' => 'ERROR', 'doc_count' => 5],
                    ['key' => 'INFO', 'doc_count' => 95],
                ],
            ],
        ];

        $this->postJson('/api/v1/topics/' . self::TOPIC_UUID . '/agg', [
            'query'    => '',
            'from'     => now()->subHour()->toISOString(),
            'to'       => now()->toISOString(),
            'agg_type' => 'terms',
            'field'    => 'level',
        ])->assertOk()
            ->assertJsonCount(2, 'aggregations.buckets')
            ->assertJsonPath('aggregations.buckets.0.key', 'ERROR');
    }

    public function testSearchRejectsMissingTimeRange(): void
    {
        $this->postJson('/api/v1/topics/' . self::TOPIC_UUID . '/search', [
            'query' => '*',
        ])->assertStatus(422);
    }
}

/**
 * 内存 Fake 存储：捕获收到的 pattern/DSL，返回预置结果
 */
class FakeStorage implements StorageAdapterInterface
{
    public array $searchResult = ['hits' => [], 'total' => 0, 'took_ms' => 0];
    public array $aggResult = [];
    public string $lastPattern = '';
    public array $lastDsl = [];

    public function bulkWrite(string $indexName, array $docs): array
    {
        return ['success' => 0, 'failed' => 0, 'errors' => null];
    }

    public function search(string $indexPattern, array $dsl): array
    {
        $this->lastPattern = $indexPattern;
        $this->lastDsl = $dsl;
        return $this->searchResult;
    }

    public function aggregate(string $indexPattern, array $dsl): array
    {
        $this->lastPattern = $indexPattern;
        $this->lastDsl = $dsl;
        return $this->aggResult;
    }

    public function createIndex(string $indexName, array $mapping): void {}
    public function ensureMonthlyRoll(string $indexPrefix): void {}
    public function listIndices(string $indexPrefix): array { return []; }
    public function deleteIndex(string $indexName): void {}
    public function ping(): bool { return true; }
}
