<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Storage;

use EasyLog\Services\Query\QueryTranslator;
use EasyLog\Services\Storage\SqliteStorageAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SQLite 存储适配器测试（lite 档，临时文件库，无需外部服务）
 *
 * DSL 输入用真实的 QueryTranslator 产出，保证适配器与查询链路的端到端契约。
 */
class SqliteStorageAdapterTest extends TestCase
{
    private SqliteStorageAdapter $adapter;
    private string $dbFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbFile = sys_get_temp_dir() . '/easylog-sqlite-test-' . uniqid() . '.sqlite';
        $this->adapter = new SqliteStorageAdapter(['path' => $this->dbFile]);

        $this->adapter->bulkWrite('easylog-ws1-top1-202610', [
            $this->doc('订单创建成功 order created', 'INFO', '2026-10-25T10:00:00Z', ['service' => 'order', 'duration_ms' => 12]),
            $this->doc('数据库连接超时 timeout', 'ERROR', '2026-10-25T10:01:30Z', ['service' => 'db', 'duration_ms' => 3000]),
            $this->doc('订单取消 order canceled', 'WARN', '2026-10-25T10:02:00Z', ['service' => 'order', 'duration_ms' => 45]),
            $this->doc('中文日志测试 支付成功', 'INFO', '2026-10-25T10:03:00Z', ['service' => 'pay']),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->dbFile);
        @unlink($this->dbFile . '-wal');
        @unlink($this->dbFile . '-shm');
        parent::tearDown();
    }

    public function testBulkWriteReportsSuccess(): void
    {
        $result = $this->adapter->bulkWrite('easylog-ws1-top2-202610', [
            $this->doc('another', 'INFO', '2026-10-25T11:00:00Z'),
        ]);
        $this->assertSame(1, $result['success']);
        $this->assertSame(0, $result['failed']);
    }

    #[DataProvider('queryProvider')]
    public function testSearchTranslatesLuceneSubset(string $query, int $expectedTotal): void
    {
        $dsl = $this->translate($query);
        $result = $this->adapter->search('easylog-ws1-*', $dsl);
        $this->assertSame($expectedTotal, $result['total']);
    }

    /** @return array<string, array{string, int}> */
    public static function queryProvider(): array
    {
        return [
            '空查询 match_all'   => ['', 4],
            '中文关键词'          => ['支付', 1],
            '英文关键词'          => ['order', 2],
            'term 专列'          => ['level:ERROR', 1],
            'term JSON 字段'     => ['service:order', 2],
            '布尔 OR 嵌套'       => ['level:ERROR OR level:WARN', 2],
            '括号+AND+通配符'    => ['(level:ERROR OR level:WARN) AND service:ord*', 1],
            'NOT'               => ['NOT level:INFO', 2],
            '字符串范围'          => ['level:[ERROR TO FATAL]', 1],
            '数值范围'           => ['duration_ms:[100 TO 4000]', 1],
            '短语'              => ['msg:"order created"', 1],
            '无命中'            => ['nothing-match-xyz', 0],
        ];
    }

    public function testSearchEnforcesWorkspaceIsolation(): void
    {
        // SearchController 注入的越权防线：别的 workspace_id 过滤必须查不到
        $dsl = $this->translate('');
        $dsl['query']['bool']['filter'][] = ['term' => ['workspace_id' => '999']];
        $result = $this->adapter->search('easylog-ws1-*', $dsl);
        $this->assertSame(0, $result['total']);
    }

    public function testSearchReturnsFullSourceDocAndSort(): void
    {
        $dsl = $this->translate('', ['sort' => ['ts:asc'], 'size' => 10]);
        $result = $this->adapter->search('easylog-ws1-*', $dsl);

        $this->assertCount(4, $result['hits']);
        $this->assertSame('订单创建成功 order created', $result['hits'][0]['msg']);
        // 业务自定义字段原样返回
        $this->assertSame(12, $result['hits'][0]['duration_ms']);
        $this->assertSame('order', $result['hits'][0]['service']);
    }

    public function testSearchSourceFieldFiltering(): void
    {
        $dsl = $this->translate('');
        $dsl['_source'] = ['msg', 'level'];
        $result = $this->adapter->search('easylog-ws1-*', $dsl);

        $this->assertSame(['msg', 'level'], array_keys($result['hits'][0]));
    }

    public function testHistogramFillsEmptyBuckets(): void
    {
        $translator = new QueryTranslator();
        $dsl = $translator->histogram('', [
            'from' => '2026-10-25T10:00:00Z',
            'to' => '2026-10-25T10:05:00Z',
            'bucket' => 'minute',
        ]);
        $dsl['query']['bool']['filter'][] = ['term' => ['workspace_id' => '1']];

        $result = $this->adapter->aggregate('easylog-ws1-*', $dsl);
        $buckets = $result['histogram']['buckets'];

        // 10:00 ~ 10:05 含首尾共 6 个分钟桶，其中 3 个有数据
        $this->assertCount(6, $buckets);
        $this->assertSame(4, array_sum(array_column($buckets, 'doc_count')));
        $this->assertSame('2026-10-25T10:00:00.000Z', $buckets[0]['key_as_string']);
        $this->assertSame(1, $buckets[0]['doc_count']);
        // 10:03 桶有 1 条（中文日志测试 10:03:00），10:04 为空桶
        $this->assertSame(1, $buckets[3]['doc_count']);
        $this->assertSame(0, $buckets[4]['doc_count']);
    }

    public function testTermsAggregation(): void
    {
        $translator = new QueryTranslator();
        $dsl = $translator->aggregate('', [
            'from' => '2026-10-25T00:00:00Z', 'to' => '2026-10-26T00:00:00Z',
            'agg_type' => 'terms', 'field' => 'level', 'size' => 10,
        ]);

        $result = $this->adapter->aggregate('easylog-ws1-*', $dsl);
        $buckets = $result['result']['buckets'];

        $this->assertSame([
            ['key' => 'INFO', 'doc_count' => 2],
            ['key' => 'ERROR', 'doc_count' => 1],
            ['key' => 'WARN', 'doc_count' => 1],
        ], $buckets);
    }

    public function testMetricAggregations(): void
    {
        $translator = new QueryTranslator();
        $ctx = ['from' => '2026-10-25T00:00:00Z', 'to' => '2026-10-26T00:00:00Z'];

        $avg = $this->adapter->aggregate('easylog-ws1-*', $translator->aggregate('', $ctx + ['agg_type' => 'avg', 'field' => 'duration_ms']));
        $this->assertEqualsWithDelta(1019, $avg['result']['value'], 0.001);

        $card = $this->adapter->aggregate('easylog-ws1-*', $translator->aggregate('', $ctx + ['agg_type' => 'cardinality', 'field' => 'service']));
        $this->assertSame(3, $card['result']['value']);

        // count(*) 走 value_count(_ts) 形状（SqlQueryTranslator 约定）
        $count = $this->adapter->aggregate('easylog-ws1-*', ['size' => 0, 'aggs' => ['result' => ['value_count' => ['field' => '_ts']]]]);
        $this->assertSame(4, $count['result']['value']);
    }

    public function testTermsWithSubMetricAggregation(): void
    {
        // SQL GROUP BY + 单指标：terms.aggs.metric 子聚合（ES 形状对齐）
        $dsl = [
            'size' => 0,
            'aggs' => ['result' => [
                'terms' => ['field' => 'service', 'size' => 10],
                'aggs' => ['metric' => ['avg' => ['field' => 'duration_ms']]],
            ]],
        ];

        $result = $this->adapter->aggregate('easylog-ws1-*', $dsl);
        $buckets = $result['result']['buckets'];

        $this->assertSame('order', $buckets[0]['key']);
        $this->assertEqualsWithDelta(28.5, $buckets[0]['metric']['value'], 0.001);
        $this->assertSame(3000.0, $buckets[1]['metric']['value']);
        // pay 无 duration_ms 字段 → null（对齐 ES 无值行为）
        $this->assertNull($buckets[2]['metric']['value']);
    }

    public function testListAndDeleteIndex(): void
    {
        $this->assertSame(['easylog-ws1-top1-202610'], $this->adapter->listIndices('easylog-ws1-*'));

        $this->adapter->deleteIndex('easylog-ws1-top1-202610');
        $this->assertSame([], $this->adapter->listIndices('easylog-ws1-*'));
    }

    public function testPing(): void
    {
        $this->assertTrue($this->adapter->ping());
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function doc(string $msg, string $level, string $ts, array $overrides = []): array
    {
        return array_merge([
            'tenant_id' => '1',
            'workspace_id' => '1',
            'topic_id' => '1',
            'msg' => $msg,
            'level' => $level,
            'ts' => $ts,
        ], $overrides);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function translate(string $query, array $context = []): array
    {
        $translator = new QueryTranslator();
        $dsl = $translator->translate($query, $context + [
            'from' => '2026-10-25T00:00:00Z',
            'to' => '2026-10-26T00:00:00Z',
            'size' => 10,
        ]);
        // SearchController 强制注入的租户隔离过滤
        $dsl['query']['bool']['filter'][] = ['term' => ['workspace_id' => '1']];
        return $dsl;
    }
}
