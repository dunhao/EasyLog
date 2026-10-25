<?php

declare(strict_types=1);

namespace Tests\Feature;

use EasyLog\Models\AuditLog;
use EasyLog\Models\Dashboard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 看板 / 投递任务 / 消费组 / 审计日志 管理 API 测试
 */
class MiscManagementApiTest extends AdminApiTestCase
{
    // ---------- Dashboards ----------

    public function testDashboardStoreAndShow(): void
    {
        $response = $this->postJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/dashboards', [
            'name'   => '订单大盘',
            'layout' => [['type' => 'histogram', 'title' => '请求量']],
        ])->assertStatus(201)
            ->assertJsonPath('name', '订单大盘');

        $id = $response->json('id');
        $this->getJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/dashboards/' . $id)
            ->assertOk()
            ->assertJsonPath('uuid', $response->json('uuid'));
    }

    public function testDashboardUpdateAndDelete(): void
    {
        $id = $this->createDashboard();
        $this->putJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/dashboards/' . $id, [
            'name' => '改名大盘',
        ])->assertOk()->assertJsonPath('name', '改名大盘');

        $this->deleteJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/dashboards/' . $id)
            ->assertStatus(204);
        $this->getJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/dashboards/' . $id)
            ->assertStatus(404);
    }

    // ---------- Shipping Tasks ----------

    public function testShippingTaskStoreValidatesTargetType(): void
    {
        $this->postJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/shipping-tasks', [
            'name'        => 'bad',
            'topic_id'    => $this->topicId,
            'target_type' => 'sftp',
        ])->assertStatus(422);
    }

    public function testShippingTaskCrud(): void
    {
        $response = $this->postJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/shipping-tasks', [
            'name'          => '导出到 OSS',
            'topic_id'      => $this->topicId,
            'target_type'   => 'oss',
            'target_config' => ['bucket' => 'logs-backup', 'prefix' => 'daily/'],
            'filter'        => ['level' => 'ERROR'],
        ])->assertStatus(201)
            ->assertJsonPath('target_type', 'oss');

        $id = $response->json('id');
        $this->putJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/shipping-tasks/' . $id, [
            'status' => 'paused',
        ])->assertOk()->assertJsonPath('status', 'paused');

        $this->deleteJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/shipping-tasks/' . $id)
            ->assertStatus(204);
    }

    // ---------- Consumption Groups ----------

    public function testConsumptionGroupStoreReturnsTokenOnce(): void
    {
        $response = $this->postJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/consumption-groups', [
            'topic_id'       => $this->topicId,
            'consumer_group' => 'data-pipeline',
        ])->assertStatus(201)
            ->assertJsonPath('consumer_group', 'data-pipeline');

        $data = $response->json();
        $this->assertArrayHasKey('auth_token', $data);
        $this->assertStringNotContainsString('auth_token_hash', json_encode($data));

        $hash = DB::table('consumption_groups')->where('uuid', $data['uuid'])->value('auth_token_hash');
        $this->assertTrue(password_verify($data['auth_token'], $hash));
    }

    public function testConsumptionGroupRotateToken(): void
    {
        $id = $this->createConsumptionGroup();
        $oldHash = DB::table('consumption_groups')->where('id', $id)->value('auth_token_hash');

        $this->postJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/consumption-groups/' . $id . '/rotate-token')
            ->assertOk()
            ->assertJsonStructure(['consumer_group', 'auth_token']);

        $this->assertNotEquals($oldHash, DB::table('consumption_groups')->where('id', $id)->value('auth_token_hash'));
    }

    public function testConsumptionGroupUniquePerTopic(): void
    {
        $this->postJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/consumption-groups', [
            'topic_id'       => $this->topicId,
            'consumer_group' => 'dup-group',
        ])->assertStatus(201);

        $this->postJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/consumption-groups', [
            'topic_id'       => $this->topicId,
            'consumer_group' => 'dup-group',
        ])->assertStatus(422);
    }

    // ---------- Audit Logs ----------

    public function testAuditLogIndexListsAndFilters(): void
    {
        AuditLog::create([
            'tenant_id'   => $this->tenantId,
            'user_id'     => $this->userId,
            'action'      => 'endpoint.rotate_secret',
            'target_type' => 'endpoint',
            'target_id'   => 'ep-1',
            'context'     => ['reason' => 'leak'],
            'ip'          => '10.0.0.1',
            'user_agent'  => 'tests',
        ]);
        AuditLog::create([
            'tenant_id'   => $this->tenantId,
            'action'      => 'workspace.update',
            'target_type' => 'workspace',
            'target_id'   => 'ws-1',
            'context'     => [],
        ]);

        $this->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/audit-logs?action=endpoint.rotate_secret')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.context.reason', 'leak');
    }

    public function testAuditLogTenantIsolation(): void
    {
        AuditLog::create([
            'tenant_id' => 99999,   // 其它租户的记录
            'action'    => 'workspace.delete',
            'context'   => [],
        ]);

        $this->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ---------- helpers ----------

    private function createDashboard(): int
    {
        return Dashboard::create([
            'uuid'         => (string) Str::uuid(),
            'workspace_id' => $this->workspaceId,
            'name'         => '默认大盘',
            'layout'       => [],
            'created_by'   => $this->userId,
        ])->id;
    }

    private function createConsumptionGroup(): int
    {
        return DB::table('consumption_groups')->insertGetId([
            'uuid' => (string) Str::uuid(), 'workspace_id' => $this->workspaceId,
            'topic_id' => $this->topicId, 'consumer_group' => 'cg-' . Str::random(6),
            'auth_token_hash' => bcrypt('old-token'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
