<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 用量计量与配额测试（ingest 埋点 → usage_records 累计 → 429 配额 / 查询 API）
 */
class UsageApiTest extends AdminApiTestCase
{
    public function testSingleIngestRecordsUsage(): void
    {
        $endpointId = $this->createEndpoint(dailyQuotaGb: null);
        $this->ingest($endpointId, ['msg' => 'hello', 'level' => 'INFO']);

        $row = DB::table('usage_records')->where('endpoint_id', $endpointId)->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->count);
        $this->assertGreaterThan(0, (int) $row->bytes);
        $this->assertSame(today()->toDateString(), $row->usage_date);
    }

    public function testBatchIngestAccumulatesUsage(): void
    {
        $endpointId = $this->createEndpoint(dailyQuotaGb: null);
        $this->ingest($endpointId, ['logs' => [
            ['msg' => 'a'], ['msg' => 'b'], ['msg' => 'c'],
        ]]);

        $row = DB::table('usage_records')->where('endpoint_id', $endpointId)->first();
        $this->assertNotNull($row);
        $this->assertSame(3, (int) $row->count);
    }

    public function testQuotaExceededReturns429(): void
    {
        $endpointId = $this->createEndpoint(dailyQuotaGb: 1);

        // 预置当日用量已超 1GB
        DB::table('usage_records')->insert([
            'workspace_id' => $this->workspaceId, 'topic_id' => $this->topicId,
            'endpoint_id' => $endpointId, 'usage_date' => today()->toDateString(),
            'bytes' => 1024 * 1024 * 1024 + 1, 'count' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->ingest($endpointId, ['msg' => 'blocked'], assertStatus: 429);
        $response->assertJsonPath('message', fn ($m) => str_contains($m, 'Daily quota exceeded'));
    }

    public function testQuotaWithinLimitAllowsIngest(): void
    {
        $endpointId = $this->createEndpoint(dailyQuotaGb: 10);
        DB::table('usage_records')->insert([
            'workspace_id' => $this->workspaceId, 'topic_id' => $this->topicId,
            'endpoint_id' => $endpointId, 'usage_date' => today()->toDateString(),
            'bytes' => 100, 'count' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->ingest($endpointId, ['msg' => 'ok'], assertStatus: 202);
    }

    public function testUsageApiAggregatesByDay(): void
    {
        $endpointId = $this->createEndpoint(dailyQuotaGb: null);
        $this->ingest($endpointId, ['msg' => 'x']);

        $this->getJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/usage?from=' . today()->subDay()->toDateString() . '&to=' . today()->addDay()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.date', today()->toDateString())
            ->assertJsonPath('data.0.count', 1);
    }

    public function testUsageByEndpointApi(): void
    {
        $endpointId = $this->createEndpoint(dailyQuotaGb: null);
        $this->ingest($endpointId, ['msg' => 'x']);

        $this->getJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/usage/endpoints?from=' . today()->subDay()->toDateString() . '&to=' . today()->addDay()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.endpoint_name', '计量端点')
            ->assertJsonPath('data.0.count', 1);
    }

    // ---------- helpers ----------

    private function createEndpoint(?int $dailyQuotaGb): int
    {
        return DB::table('ingestion_endpoints')->insertGetId([
            'uuid' => (string) Str::uuid(), 'workspace_id' => $this->workspaceId, 'topic_id' => $this->topicId,
            'name' => '计量端点', 'source_type' => 'http', 'config' => json_encode([]),
            'app_id' => 'app_usage_' . Str::random(6), 'app_secret_hash' => bcrypt('secret'),
            'rate_limit_per_sec' => 1000, 'daily_quota_gb' => (int) ($dailyQuotaGb ?? 0), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ingest(int $endpointId, array $payload, int $assertStatus = 202)
    {
        $endpoint = DB::table('ingestion_endpoints')->where('id', $endpointId)->first();
        $isBatch = isset($payload['logs']);

        $response = $this->postJson(
            '/api/v1/ingest' . ($isBatch ? '/batch' : ''),
            $payload,
            [
                'X-EasyLog-App' => $endpoint->app_id,
                'Authorization' => 'Bearer secret',
                'Accept' => 'application/json',
            ]
        );
        $response->assertStatus($assertStatus);
        return $response;
    }
}
