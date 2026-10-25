<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use EasyLog\Services\Storage\OpenObserveAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpenObserve 适配器 HTTP 行为测试（Http::fake，无需真实服务）
 */
class OpenObserveAdapterTest extends TestCase
{
    private OpenObserveAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.fake_oo' => true]);
        $this->adapter = new OpenObserveAdapter([
            'url'     => 'http://oo.test:5080',
            'org'     => 'easylog',
            'api_key' => 'oo-token',
            'bulk_size' => 2,
        ]);
    }

    public function testBulkWritePostsJsonArrayWithTimestampAndChunks(): void
    {
        Http::fake(['*oo.test:5080/*' => Http::response(['failed' => 0], 200)]);

        $docs = [
            ['ts' => '2026-09-25T00:00:00Z', 'msg' => 'a'],
            ['ts' => '2026-09-25T00:01:00Z', 'msg' => 'b'],
            ['msg' => 'c'],
        ];
        $result = $this->adapter->bulkWrite('easylog-ws-tpc-202609', $docs);

        $this->assertSame(3, $result['success']);
        $this->assertSame(0, $result['failed']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/easylog/easylog_ws_tpc_202609/_json')
                && $request->hasHeader('Authorization', 'Bearer oo-token');
        });
        // bulk_size=2 → 两批
        Http::assertSentCount(2);

        // 捕获请求体验证 _timestamp 注入（ISO → epoch 微秒）
        $first = json_decode(Http::recorded()[0][0]->body(), true);
        $this->assertSame(1790294400000000, $first[0]['_timestamp']);
        // 无 ts 的文档用当前时间
        $this->assertGreaterThan(0, $first[1]['_timestamp'] ?? 0);
    }

    public function testSearchBuildsSqlAndMapsHits(): void
    {
        Http::fake(['*oo.test:5080/*' => Http::response([
            'hits' => [['msg' => 'x']], 'total' => 3, 'took' => 2500,
        ], 200)]);

        $dsl = [
            'query' => ['bool' => [
                'must'   => [['term' => ['level' => 'ERROR']]],
                'filter' => [['range' => ['ts' => ['gte' => '2026-09-25T00:00:00Z', 'lte' => '2026-09-25T01:00:00Z']]]],
            ]],
            'size' => 100,
            'sort' => [['ts' => ['order' => 'desc']]],
        ];
        $result = $this->adapter->search('easylog-ws-tpc-202609-*', $dsl);

        $this->assertSame(3, $result['total']);
        $this->assertSame(3, $result['took_ms']); // 2500 微秒 → round 3ms

        Http::assertSent(function ($request) {
            $sql = $request->data()['query']['sql'] ?? '';
            return str_contains($sql, '"level" = \'ERROR\'')
                && str_contains($sql, '_timestamp >= 1790294400000000')
                && str_contains($sql, 'ORDER BY "ts" DESC')
                && str_contains($sql, 'LIMIT 100');
        });
    }

    public function testAggregateTermsMapsBuckets(): void
    {
        Http::fake(['*oo.test:5080/*' => Http::response([
            'hits' => [
                ['k' => 'ERROR', 'doc_count' => 5],
                ['k' => 'INFO', 'doc_count' => 2],
            ],
        ], 200)]);

        $dsl = [
            'query' => ['bool' => ['must' => [['match_all' => new \stdClass()]]]],
            'size'  => 0,
            'aggs'  => ['result' => ['terms' => ['field' => 'level', 'size' => 10]]],
        ];
        $agg = $this->adapter->aggregate('easylog-ws-tpc-202609-*', $dsl);

        $this->assertSame('result', array_key_first($agg));
        $this->assertSame(
            [['key' => 'ERROR', 'doc_count' => 5], ['key' => 'INFO', 'doc_count' => 2]],
            $agg['result']['buckets']
        );

        Http::assertSent(function ($request) {
            $sql = $request->data()['query']['sql'] ?? '';
            return str_contains($sql, 'GROUP BY "level" ORDER BY doc_count DESC LIMIT 10');
        });
    }

    public function testAggregateHistogram(): void
    {
        Http::fake(['*oo.test:5080/*' => Http::response([
            'hits' => [['ts_bucket' => '2026-09-25 00:00:00', 'doc_count' => 7]],
        ], 200)]);

        $dsl = [
            'query' => ['bool' => ['must' => [['match_all' => new \stdClass()]]]],
            'size'  => 0,
            'aggs'  => ['histogram' => ['date_histogram' => ['field' => 'ts', 'calendar_interval' => '1h']]],
        ];
        $agg = $this->adapter->aggregate('easylog-ws-tpc-*', $dsl);

        $this->assertSame(
            [['key_as_string' => '2026-09-25 00:00:00', 'doc_count' => 7]],
            $agg['histogram']['buckets']
        );
    }

    public function testAggregateScalarValue(): void
    {
        Http::fake(['*oo.test:5080/*' => Http::response(['hits' => [['value' => 12.5]]], 200)]);

        $dsl = [
            'query' => ['bool' => ['must' => []]],
            'size'  => 0,
            'aggs'  => ['result' => ['avg' => ['field' => 'size']]],
        ];
        $agg = $this->adapter->aggregate('easylog-*', $dsl);
        $this->assertSame(['value' => 12.5], $agg['result']);
    }

    public function testListIndicesFiltersByPrefix(): void
    {
        Http::fake([
            '*oo.test:5080/api/easylog/streams' => Http::response([
                'list' => [
                    ['name' => 'easylog_ws1_tpc1_202609'],
                    ['name' => 'easylog_ws2_tpc1_202609'],
                    ['name' => 'unrelated'],
                ],
            ], 200),
        ]);

        $indices = $this->adapter->listIndices('easylog-ws1-');
        $this->assertSame(['easylog_ws1_tpc1_202609'], $indices);
    }

    public function testPingAndFailurePaths(): void
    {
        Http::fake(['*oo.test:5080/healthz' => Http::response('ok', 200)]);
        $this->assertTrue($this->adapter->ping());

        // 检索失败 → 空结果不抛异常（写链路友好降级）
        Http::fake(['*oo.test:5080/*' => Http::response('boom', 500)]);
        $result = $this->adapter->search('easylog-*', ['query' => ['bool' => ['must' => []]], 'size' => 10]);
        $this->assertSame(['hits' => [], 'total' => 0, 'took_ms' => 0], $result);
    }
}
