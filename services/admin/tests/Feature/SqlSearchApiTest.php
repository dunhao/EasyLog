<?php

declare(strict_types=1);

namespace Tests\Feature;

use EasyLog\Facades\StorageAdapter;
use EasyLog\Services\Storage\StorageAdapterInterface;

/**
 * SQL 查询端到端测试：POST /search 的 query 为 SELECT 语句时走 SqlQueryTranslator
 */
class SqlSearchApiTest extends AdminApiTestCase
{
    private SqlFakeStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new SqlFakeStorage();
        StorageAdapter::swap($this->storage);
    }

    public function testSqlWhereTranslatesToMustClauses(): void
    {
        $this->postJson('/api/v1/topics/' . self::TOPIC_UUID . '/search', [
            'query' => "SELECT * FROM logs WHERE level = 'ERROR' AND service LIKE 'order-%' LIMIT 25",
            'from'  => now()->subHour()->toISOString(),
            'to'    => now()->toISOString(),
        ])->assertOk()->assertJsonPath('total', 2)
            // 二次过滤：workspace_id 不匹配的命中被剔除（fake 返回 2 条 → 1 条出）
            ->assertJsonCount(1, 'hits');

        $must = $this->storage->lastDsl['query']['bool']['must'];
        $this->assertSame(['term' => ['level' => 'ERROR']], $must[0]);
        $this->assertSame(['wildcard' => ['service' => ['value' => 'order-*']]], $must[1]);
        $this->assertSame(25, $this->storage->lastDsl['size']);

        // 服务端租户隔离 filter 仍在（filter[0] 是时间范围，filter[1] 是 workspace_id）
        $filter = $this->storage->lastDsl['query']['bool']['filter'];
        $this->assertSame(['term' => ['workspace_id' => (string) $this->workspaceId]], $filter[1]);
    }

    public function testSqlSyntaxErrorReturns422(): void
    {
        $this->postJson('/api/v1/topics/' . self::TOPIC_UUID . '/search', [
            'query' => 'SELECT FROM WHERE',
            'from'  => now()->subHour()->toISOString(),
            'to'    => now()->toISOString(),
        ])->assertStatus(422);
    }

    public function testSqlAggregateCountReturnsTotal(): void
    {
        $this->storage->aggResult = ['result' => ['value' => 42]];

        $this->postJson('/api/v1/topics/' . self::TOPIC_UUID . '/search', [
            'query' => "SELECT count(*) FROM logs WHERE level = 'ERROR'",
            'from'  => now()->subHour()->toISOString(),
            'to'    => now()->toISOString(),
        ])->assertOk()
            ->assertJsonPath('total', 42)
            ->assertJsonCount(0, 'hits')
            ->assertJsonPath('aggregations.value', 42);
    }

    public function testSqlGroupByReturnsBuckets(): void
    {
        $this->storage->aggResult = ['result' => ['buckets' => [
            ['key' => 'ERROR', 'doc_count' => 5],
            ['key' => 'INFO', 'doc_count' => 3],
        ]]];

        $this->postJson('/api/v1/topics/' . self::TOPIC_UUID . '/search', [
            'query' => 'SELECT count(*), level FROM logs GROUP BY level',
            'from'  => now()->subHour()->toISOString(),
            'to'    => now()->toISOString(),
        ])->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('aggregations.buckets.0.key', 'ERROR');
    }

    public function testLuceneQueryStillWorksUnchanged(): void
    {
        $this->postJson('/api/v1/topics/' . self::TOPIC_UUID . '/search', [
            'query' => 'level:ERROR',
            'from'  => now()->subHour()->toISOString(),
            'to'    => now()->toISOString(),
        ])->assertOk()->assertJsonPath('total', 2);

        // Lucene 路径不产生聚合
        $this->assertArrayNotHasKey('aggs', $this->storage->lastDsl);
    }
}

/**
 * SQL 测试专用 Fake 存储：捕获 DSL 与命中，支持聚合预置
 */
class SqlFakeStorage implements StorageAdapterInterface
{
    public array $searchResult = ['hits' => [], 'total' => 0, 'took_ms' => 0];
    public array $aggResult = [];
    public array $lastDsl = [];
    public array $lastHits = [];

    public function __construct()
    {
        // 两条命中：一条本租户（二次过滤保留）、一条他租户（被剔除）
        // workspace_id 为数字主键的字符串形式（控制器用 (string) $ws->id 比对）
        $this->searchResult = [
            'hits' => [
                ['msg' => 'a', 'workspace_id' => '1', 'topic_id' => '1'],
                ['msg' => 'b', 'workspace_id' => '999999'],
            ],
            'total' => 2,
            'took_ms' => 1,
        ];
    }

    public function search(string $indexPattern, array $dsl): array
    {
        $this->lastDsl = $dsl;
        $this->lastHits = $this->searchResult['hits'];
        return $this->searchResult;
    }

    public function aggregate(string $indexPattern, array $dsl): array
    {
        $this->lastDsl = $dsl;
        return $this->aggResult;
    }

    public function bulkWrite(string $indexName, array $docs): array { return ['success' => 0, 'failed' => 0, 'errors' => null]; }
    public function createIndex(string $indexName, array $mapping): void {}
    public function ensureMonthlyRoll(string $indexPrefix): void {}
    public function listIndices(string $indexPrefix): array { return []; }
    public function deleteIndex(string $indexName): void {}
    public function ping(): bool { return true; }
}
