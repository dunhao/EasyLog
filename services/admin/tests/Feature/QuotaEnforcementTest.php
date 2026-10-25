<?php

declare(strict_types=1);

namespace Tests\Feature;

use EasyLog\Facades\MessageQueue;
use EasyLog\Services\MQ\Kafka\InMemoryKafkaProducer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 配额分级生效测试：workspace / tenant 层超限 → ingest 429
 */
class QuotaEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ID = 'app_quota_001';
    private const APP_SECRET = 'sec_quota_001';
    private const WORKSPACE_UUID = 'ws_quota';
    private const TOPIC_UUID = 'tpc_quota';

    private int $tenantId;
    private int $workspaceId;
    private int $endpointId;
    private InMemoryKafkaProducer $producer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemoData();

        $this->producer = new InMemoryKafkaProducer();
        MessageQueue::setProducer($this->producer);
    }

    private function seedDemoData(): void
    {
        $this->tenantId = DB::table('tenants')->insertGetId([
            'uuid' => Str::uuid()->toString(), 'name' => 'QuotaCo', 'slug' => 'quotaco',
            'status' => 'active', 'plan' => 'pro',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->workspaceId = DB::table('workspaces')->insertGetId([
            'uuid' => self::WORKSPACE_UUID, 'tenant_id' => $this->tenantId, 'name' => '配额',
            'description' => '', 'retention_days' => 30, 'shard_count' => 6, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $topicId = DB::table('topics')->insertGetId([
            'uuid' => self::TOPIC_UUID, 'workspace_id' => $this->workspaceId, 'name' => 'quota-logs',
            'description' => '', 'auto_field_index' => true, 'retention_days' => 30,
            'enable_full_text' => true, 'json_extract' => true, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->endpointId = DB::table('ingestion_endpoints')->insertGetId([
            'uuid' => Str::uuid()->toString(), 'workspace_id' => $this->workspaceId, 'topic_id' => $topicId,
            'name' => '配额接入', 'source_type' => 'http', 'config' => json_encode([]),
            'app_id' => self::APP_ID, 'app_secret_hash' => bcrypt(self::APP_SECRET),
            'rate_limit_per_sec' => 1000, 'daily_quota_gb' => 0, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ingestHeaders(): array
    {
        return [
            'X-EasyLog-App' => self::APP_ID,
            'Authorization' => 'Bearer ' . self::APP_SECRET,
            'Accept' => 'application/json',
        ];
    }

    /** 模拟当日已写入 $bytes 字节 */
    private function seedUsage(int $bytes, int $endpointId = null, int $workspaceId = null): void
    {
        DB::table('usage_records')->insert([
            'endpoint_id' => $endpointId ?? $this->endpointId,
            'workspace_id' => $workspaceId ?? $this->workspaceId,
            'topic_id' => DB::table('topics')->where('uuid', self::TOPIC_UUID)->value('id'),
            'bytes' => $bytes,
            'count' => 1,
            'usage_date' => today()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function testIngestSucceedsWithoutQuota(): void
    {
        $this->seedUsage(1024);

        $this->postJson('/api/v1/ingest', ['msg' => 'ok'], $this->ingestHeaders())
            ->assertStatus(202);
    }

    public function testWorkspaceQuotaExceededReturns429(): void
    {
        DB::table('workspaces')->where('id', $this->workspaceId)->update(['daily_quota_gb' => 1]);
        // workspace 层累计 2GB > 1GB 配额
        $this->seedUsage(2 * 1024 * 1024 * 1024);

        $this->postJson('/api/v1/ingest', ['msg' => 'boom'], $this->ingestHeaders())
            ->assertStatus(429);
    }

    public function testTenantQuotaExceededReturns429(): void
    {
        DB::table('tenants')->where('id', $this->tenantId)->update(['daily_quota_gb' => 1]);
        $this->seedUsage(2 * 1024 * 1024 * 1024);

        $this->postJson('/api/v1/ingest', ['msg' => 'boom'], $this->ingestHeaders())
            ->assertStatus(429);
    }

    public function testQuotaRecoversNextDay(): void
    {
        DB::table('workspaces')->where('id', $this->workspaceId)->update(['daily_quota_gb' => 1]);
        // 昨天的用量不计入今天
        DB::table('usage_records')->insert([
            'endpoint_id' => $this->endpointId,
            'workspace_id' => $this->workspaceId,
            'topic_id' => DB::table('topics')->where('uuid', self::TOPIC_UUID)->value('id'),
            'bytes' => 2 * 1024 * 1024 * 1024, 'count' => 1,
            'usage_date' => today()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/ingest', ['msg' => 'ok'], $this->ingestHeaders())
            ->assertStatus(202);
    }
}
