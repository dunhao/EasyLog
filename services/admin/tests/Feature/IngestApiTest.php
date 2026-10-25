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
 * 接入 API 冒烟测试（AppID 鉴权 → 归一化 → 投递内部 Kafka）
 */
class IngestApiTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ID = 'app_demo_001';
    private const APP_SECRET = 'sec_demo_001';
    private const WORKSPACE_UUID = 'ws_acme_prod';
    private const TOPIC_UUID = 'tpc_order_logs';

    private InMemoryKafkaProducer $producer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemoData();

        // 用 in-memory producer 捕获投递消息
        $this->producer = new InMemoryKafkaProducer();
        MessageQueue::setProducer($this->producer);
    }

    private function seedDemoData(): void
    {
        $tenantId = DB::table('tenants')->insertGetId([
            'uuid' => Str::uuid()->toString(), 'name' => 'Acme Inc.', 'slug' => 'acme',
            'status' => 'active', 'plan' => 'pro',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $workspaceId = DB::table('workspaces')->insertGetId([
            'uuid' => self::WORKSPACE_UUID, 'tenant_id' => $tenantId, 'name' => '生产',
            'description' => '', 'retention_days' => 30, 'shard_count' => 6, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $topicId = DB::table('topics')->insertGetId([
            'uuid' => self::TOPIC_UUID, 'workspace_id' => $workspaceId, 'name' => 'order-logs',
            'description' => '', 'auto_field_index' => true, 'retention_days' => 30,
            'enable_full_text' => true, 'json_extract' => true, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('ingestion_endpoints')->insert([
            'uuid' => Str::uuid()->toString(), 'workspace_id' => $workspaceId, 'topic_id' => $topicId,
            'name' => 'HTTP 接入', 'source_type' => 'http', 'config' => json_encode([]),
            'app_id' => self::APP_ID, 'app_secret_hash' => bcrypt(self::APP_SECRET),
            'rate_limit_per_sec' => 1000, 'daily_quota_gb' => 50, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ingestHeaders(array $extra = []): array
    {
        return array_merge([
            'X-EasyLog-App' => self::APP_ID,
            'Authorization' => 'Bearer ' . self::APP_SECRET,
            'Accept' => 'application/json',
        ], $extra);
    }

    public function testSingleIngestReturns202AndPublishesToKafka(): void
    {
        $response = $this->postJson('/api/v1/ingest', [
            'msg' => 'order created',
            'level' => 'INFO',
        ], $this->ingestHeaders());

        $response->assertStatus(202)
            ->assertJson(['accepted' => 1, 'rejected' => 0]);

        $messages = $this->producer->getMessages();
        $this->assertCount(1, $messages);
        $this->assertEquals('easylog.ingest.' . self::WORKSPACE_UUID . '.' . self::TOPIC_UUID, $messages[0]['topic']);

        $entry = json_decode($messages[0]['payload'], true);
        $this->assertEquals('order created', $entry['msg']);
        $this->assertEquals('INFO', $entry['level']);
        // 归一化补齐路由字段
        $this->assertEquals(self::WORKSPACE_UUID, $entry['_workspace_uuid']);
        $this->assertEquals(self::TOPIC_UUID, $entry['_topic_uuid']);
        $this->assertArrayHasKey('_received_at', $entry);
    }

    public function testSingleIngestPublishesTenantHeaders(): void
    {
        $this->postJson('/api/v1/ingest', ['msg' => 'hi'], $this->ingestHeaders());

        $headers = $this->producer->getMessages()[0]['headers'];
        $this->assertArrayHasKey('tenant_id', $headers);
        $this->assertArrayHasKey('workspace_id', $headers);
        $this->assertArrayHasKey('topic_id', $headers);
    }

    public function testBatchIngestAcceptsAllEntries(): void
    {
        $response = $this->postJson('/api/v1/ingest/batch', [
            'logs' => [
                ['msg' => 'a', 'level' => 'INFO'],
                ['msg' => 'b', 'level' => 'ERROR'],
                ['msg' => 'c'],
            ],
        ], $this->ingestHeaders());

        $response->assertStatus(202)
            ->assertJson(['accepted' => 3, 'rejected' => 0]);

        $this->assertCount(3, $this->producer->getMessages());
    }

    public function testMissingAppHeaderReturns401(): void
    {
        $this->postJson('/api/v1/ingest', ['msg' => 'x'])
            ->assertStatus(401);
    }

    public function testInvalidSecretReturns401(): void
    {
        $this->postJson('/api/v1/ingest', ['msg' => 'x'], [
            'X-EasyLog-App' => self::APP_ID,
            'Authorization' => 'Bearer wrong_secret',
        ])->assertStatus(401);
    }

    public function testInvalidLevelReturns422(): void
    {
        $this->postJson('/api/v1/ingest', ['msg' => 'x', 'level' => 'NO_SUCH_LEVEL'], $this->ingestHeaders())
            ->assertStatus(422);
    }

    public function testMissingMsgReturns422(): void
    {
        $this->postJson('/api/v1/ingest', ['level' => 'INFO'], $this->ingestHeaders())
            ->assertStatus(422);
    }

    public function testTopicOverrideHeaderRoutesToOtherTopic(): void
    {
        $this->postJson('/api/v1/ingest', ['msg' => 'x'], $this->ingestHeaders([
            'X-EasyLog-Topic' => self::TOPIC_UUID,
        ]))->assertStatus(202);

        // 覆盖为同一 topic 仍投递到正确 topic
        $this->assertEquals(
            'easylog.ingest.' . self::WORKSPACE_UUID . '.' . self::TOPIC_UUID,
            $this->producer->getMessages()[0]['topic']
        );
    }

    public function testHeartbeatNeedsNoAuth(): void
    {
        $this->getJson('/api/v1/ingest/heartbeat')
            ->assertStatus(200)
            ->assertJson(['status' => 'ok']);
    }

    public function testHealthCheckReturnsOk(): void
    {
        $this->getJson('/api/v1/health')
            ->assertStatus(200)
            ->assertJson(['status' => 'ok']);
    }
}
