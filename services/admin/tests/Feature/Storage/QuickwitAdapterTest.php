<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use EasyLog\Services\Storage\QuickwitAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Quickwit 适配器 HTTP 行为测试（Http::fake，无需真实服务）
 */
class QuickwitAdapterTest extends TestCase
{
    private QuickwitAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new QuickwitAdapter([
            'url'   => 'http://qw.test:7280',
            'index' => 'easylog',
        ]);
    }

    public function testBulkWriteCreatesMissingIndexThenPostsNdjson(): void
    {
        Http::fake([
            '*qw.test:7280/api/v1/indexes' => Http::response(['indexes' => []], 200),
            'qw.test:7280/api/v1/easylog-ws-tpc-202609/ingest' => Http::response([
                'report' => ['successful_docs' => 2, 'failed_docs' => 0],
            ], 200),
        ]);

        $result = $this->adapter->bulkWrite('easylog-ws-tpc-202609', [
            ['ts' => '2026-09-25T00:00:00Z', 'msg' => 'a'],
            ['msg' => 'b'],
        ]);

        $this->assertSame(2, $result['success']);

        // 先建索引（dynamic mapping + _ts 时间字段）
        Http::assertSent(function ($request) {
            if (str_ends_with($request->url(), '/api/v1/indexes') && $request->method() === 'POST') {
                $body = $request->data();
                return ($body['doc_mapping']['mode'] ?? '') === 'dynamic'
                    && ($body['doc_mapping']['timestamp_field'] ?? '') === '_ts';
            }
            return true;
        });

        // NDJSON 带 _ts（epoch 秒）
        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/ingest')) return false;
            $body = $request->body();
            return str_contains($body, '"_ts":1790294400') && str_contains($body, '"msg":"a"');
        });
    }

    public function testSearchBuildsQueryStringAndTimeBounds(): void
    {
        Http::fake(['*qw.test:7280/*' => Http::response([
            'hits' => [['msg' => 'x']], 'num_hits' => 3, 'elapsed_time_micros' => 2500,
        ], 200)]);

        $dsl = [
            'query' => ['bool' => [
                'must'   => [['term' => ['level' => 'ERROR']], ['wildcard' => ['service' => ['value' => 'order-*']]]],
                'filter' => [['range' => ['ts' => ['gte' => '2026-09-25T00:00:00Z', 'lte' => '2026-09-25T01:00:00Z']]]],
            ]],
            'size' => 100,
            'sort' => [['ts' => ['order' => 'desc']]],
        ];
        $result = $this->adapter->search('easylog-ws-tpc-202609-*', $dsl);

        $this->assertSame(3, $result['total']);
        $this->assertSame(3, $result['took_ms']);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $body['query'] === 'level:ERROR AND service:order-*'
                && $body['start_timestamp'] === 1790294400
                && $body['end_timestamp'] === 1790298000
                && $body['max_hits'] === 100
                && $body['sort_by'] === 'ts:desc';
        });
    }

    public function testAggregatePassesThroughEsStyleAggs(): void
    {
        Http::fake(['*qw.test:7280/*' => Http::response([
            'aggregations' => ['result' => ['buckets' => [['key' => 'ERROR', 'doc_count' => 5]]]],
        ], 200)]);

        $dsl = [
            'query' => ['bool' => ['must' => [['match_all' => new \stdClass()]]]],
            'size'  => 0,
            'aggs'  => ['result' => ['terms' => ['field' => 'level', 'size' => 10]]],
        ];
        $agg = $this->adapter->aggregate('easylog-ws-*', $dsl);

        $this->assertSame(
            ['result' => ['buckets' => [['key' => 'ERROR', 'doc_count' => 5]]]],
            $agg
        );

        Http::assertSent(function ($request) {
            $body = $request->data();
            return ($body['max_hits'] ?? null) === 0
                && ($body['aggs']['result']['terms']['field'] ?? null) === 'level';
        });
    }

    public function testListIndicesFiltersByPrefix(): void
    {
        Http::fake([
            '*qw.test:7280/api/v1/indexes' => Http::response([
                'indexes' => [
                    ['index_id' => 'easylog-ws1-tpc1-202609'],
                    ['index_id' => 'easylog-ws2-tpc1-202609'],
                    ['index_id' => 'other-index'],
                ],
            ], 200),
        ]);

        $this->assertSame(['easylog-ws1-tpc1-202609'], $this->adapter->listIndices('easylog-ws1-'));
    }

    public function testPingAndFailurePaths(): void
    {
        Http::fake(['*qw.test:7280/health/readyz' => Http::response('OK', 200)]);
        $this->assertTrue($this->adapter->ping());

        Http::fake(['*qw.test:7280/*' => Http::response('boom', 500)]);
        $result = $this->adapter->search('easylog-*', ['query' => ['bool' => ['must' => []]], 'size' => 10]);
        $this->assertSame(['hits' => [], 'total' => 0, 'took_ms' => 0], $result);
    }
}
