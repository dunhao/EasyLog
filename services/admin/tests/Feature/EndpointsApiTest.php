<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 接入点管理 API 测试（CRUD + 密钥轮换 + 测试连接）
 */
class EndpointsApiTest extends AdminApiTestCase
{
    public function testIndexReturnsEmptyList(): void
    {
        $this->getJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/endpoints')
            ->assertOk()
            ->assertJson(['meta' => ['total' => 0]]);
    }

    public function testStoreCreatesEndpointWithSecret(): void
    {
        $response = $this->postJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/endpoints', [
            'name'               => '订单服务 HTTP',
            'source_type'        => 'http',
            'topic_id'           => $this->topicId,
            'rate_limit_per_sec' => 500,
            'daily_quota_gb'     => 10,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', '订单服务 HTTP')
            ->assertJsonPath('source_type', 'http');

        $data = $response->json();
        $this->assertArrayHasKey('app_id', $data);
        $this->assertArrayHasKey('app_secret', $data);
        $this->assertStringStartsWith('app_', $data['app_id']);
        $this->assertStringNotContainsString('app_secret_hash', json_encode($data));

        // 落库为 bcrypt 哈希，明文 secret 可用 password_verify 校验
        $hash = DB::table('ingestion_endpoints')->where('uuid', $data['uuid'])->value('app_secret_hash');
        $this->assertTrue(password_verify($data['app_secret'], $hash));
    }

    public function testStoreRejectsInvalidSourceType(): void
    {
        $this->postJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/endpoints', [
            'name'        => 'bad',
            'source_type' => 'ftp',
            'topic_id'    => $this->topicId,
        ])->assertStatus(422);
    }

    public function testStoreRejectsKafkaConfigMissingBrokers(): void
    {
        $this->postJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/endpoints', [
            'name'        => 'kafka 源',
            'source_type' => 'kafka',
            'topic_id'    => $this->topicId,
            'config'      => ['consumer_group' => 'cg-1'],
        ])->assertStatus(422);
    }

    public function testShowReturnsEndpoint(): void
    {
        $uuid = $this->createEndpoint();
        $this->getJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/endpoints/' . $uuid)
            ->assertOk()
            ->assertJsonPath('name', 'HTTP 接入');
    }

    public function testUpdateChangesConfig(): void
    {
        $uuid = $this->createEndpoint();
        $this->putJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/endpoints/' . $uuid, [
            'name' => '改名了',
        ])->assertOk()
            ->assertJsonPath('name', '改名了');
    }

    public function testRotateSecretReturnsNewSecretAndInvalidatesOld(): void
    {
        $uuid = $this->createEndpoint();
        $oldSecret = DB::table('ingestion_endpoints')->where('uuid', $uuid)->value('app_secret_hash');

        $response = $this->postJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/endpoints/' . $uuid . '/rotate-secret')
            ->assertOk();

        $newSecret = $response->json('app_secret');
        $this->assertNotEquals($oldSecret, DB::table('ingestion_endpoints')->where('uuid', $uuid)->value('app_secret_hash'));
        $this->assertTrue(password_verify($newSecret, DB::table('ingestion_endpoints')->where('uuid', $uuid)->value('app_secret_hash')));
    }

    public function testTestConnectionHttpTypeRejected(): void
    {
        $uuid = $this->createEndpoint();
        $this->postJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/endpoints/' . $uuid . '/test-connection')
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function testTestConnectionRedisMissingConfigRejected(): void
    {
        $uuid = $this->createEndpoint('redis');
        $this->postJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/endpoints/' . $uuid . '/test-connection')
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function testDestroySoftDeletes(): void
    {
        $uuid = $this->createEndpoint();
        $this->deleteJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/endpoints/' . $uuid)
            ->assertStatus(204);

        $this->getJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/endpoints/' . $uuid)
            ->assertStatus(404);
    }

    public function testCrossTenantIsolation(): void
    {
        // 其它租户的 workspace 不可见
        $otherTenant = DB::table('tenants')->insertGetId([
            'uuid' => Str::uuid()->toString(), 'name' => 'Other', 'slug' => 'other',
            'status' => 'active', 'plan' => 'free',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherWs = DB::table('workspaces')->insertGetId([
            'uuid' => 'ws_other_001', 'tenant_id' => $otherTenant, 'name' => 'other',
            'description' => '', 'retention_days' => 30, 'shard_count' => 1, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ingestion_endpoints')->insert([
            'uuid' => Str::uuid()->toString(), 'workspace_id' => $otherWs, 'topic_id' => $this->topicId,
            'name' => 'stealth', 'source_type' => 'http', 'config' => json_encode([]),
            'app_id' => 'app_stealth', 'app_secret_hash' => bcrypt('x'),
            'rate_limit_per_sec' => 100, 'daily_quota_gb' => 1, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 列表看不到其它租户的端点
        $this->getJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/endpoints')
            ->assertOk()
            ->assertJsonMissing(['app_id' => 'app_stealth']);

        // 无法访问其它租户的 workspace
        $this->getJson('/api/v1/workspaces/ws_other_001/endpoints')
            ->assertStatus(404);
    }

    private function createEndpoint(string $sourceType = 'http'): string
    {
        $uuid = Str::uuid()->toString();
        DB::table('ingestion_endpoints')->insert([
            'uuid' => $uuid, 'workspace_id' => $this->workspaceId, 'topic_id' => $this->topicId,
            'name' => 'HTTP 接入', 'source_type' => $sourceType, 'config' => json_encode([]),
            'app_id' => 'app_' . Str::random(10), 'app_secret_hash' => bcrypt('secret'),
            'rate_limit_per_sec' => 1000, 'daily_quota_gb' => 50, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $uuid;
    }
}
