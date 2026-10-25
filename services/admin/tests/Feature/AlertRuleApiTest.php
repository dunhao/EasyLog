<?php

declare(strict_types=1);

namespace Tests\Feature;

use EasyLog\Models\AlertEvent;
use EasyLog\Models\AlertRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 告警规则管理 API 测试（CRUD + 事件查询）
 */
class AlertRuleApiTest extends AdminApiTestCase
{
    public function testIndexReturnsEmptyList(): void
    {
        $this->getJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/alert-rules')
            ->assertOk()
            ->assertJson(['meta' => ['total' => 0]]);
    }

    public function testStoreCreatesRule(): void
    {
        $this->postJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/alert-rules', [
            'name'      => '订单错误过多',
            'topic_id'  => $this->topicId,
            'condition' => ['metric' => 'count', 'filter' => 'level:ERROR'],
            'window_sec' => 60,
            'threshold'  => 100,
            'severity'   => 'critical',
        ])->assertStatus(201)
            ->assertJsonPath('name', '订单错误过多')
            ->assertJsonPath('threshold', 100)
            ->assertJsonPath('severity', 'critical')
            ->assertJsonPath('status', 'active');
    }

    public function testStoreValidatesTopic(): void
    {
        $this->postJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/alert-rules', [
            'name'     => 'bad',
            'topic_id' => 999999,
        ])->assertStatus(422);
    }

    public function testShowReturnsRule(): void
    {
        $uuid = $this->createRule();
        $this->getJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/alert-rules/' . $uuid)
            ->assertOk()
            ->assertJsonPath('name', '错误率告警');
    }

    public function testUpdatePausesRule(): void
    {
        $uuid = $this->createRule();
        $this->putJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/alert-rules/' . $uuid, [
            'status'   => 'paused',
            'threshold' => 50,
        ])->assertOk()
            ->assertJsonPath('status', 'paused')
            ->assertJsonPath('threshold', 50);
    }

    public function testDestroySoftDeletes(): void
    {
        $uuid = $this->createRule();
        $this->deleteJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/alert-rules/' . $uuid)
            ->assertStatus(204);

        $this->getJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/alert-rules/' . $uuid)
            ->assertStatus(404);
    }

    public function testEventsListsFiredEventsDesc(): void
    {
        $rule = $this->createRuleModel();
        AlertEvent::create([
            'uuid'          => (string) Str::uuid(),
            'rule_id'       => $rule->id,
            'fired_at'      => now()->subMinutes(5),
            'severity'      => 'warning',
            'context'       => ['count' => 150, 'threshold' => 100],
            'notify_status' => 'pending',
        ]);
        AlertEvent::create([
            'uuid'          => (string) Str::uuid(),
            'rule_id'       => $rule->id,
            'fired_at'      => now()->subMinutes(1),
            'severity'      => 'critical',
            'context'       => ['count' => 300, 'threshold' => 100],
            'notify_status' => 'pending',
        ]);

        $this->getJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/alert-rules/' . $rule->uuid . '/events')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.context.count', 300)   // 按 fired_at 倒序
            ->assertJsonPath('data.0.severity', 'critical');
    }

    public function testEventsFiltersBySeverity(): void
    {
        $rule = $this->createRuleModel();
        AlertEvent::create([
            'uuid' => (string) Str::uuid(), 'rule_id' => $rule->id,
            'fired_at' => now(), 'severity' => 'info',
            'context' => [], 'notify_status' => 'pending',
        ]);

        $this->getJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/alert-rules/' . $rule->uuid . '/events?severity=critical')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function testCrossTenantRuleInvisible(): void
    {
        $rule = $this->createRule();

        // 另一租户的真实用户
        $otherTenantId = DB::table('tenants')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other', 'slug' => 'other',
            'status' => 'active', 'plan' => 'free',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherTenantUser = \EasyLog\Models\User::create([
            'tenant_id' => $otherTenantId, 'email' => 'other@x.io',
            'password' => bcrypt('x'), 'name' => 'Other', 'status' => 'active',
        ]);
        // 本用例验证的是「租户隔离」而非「授权」：若该用户没有任何角色，会被 ability
        // 中间件挡成 403，从而掩盖真正要断言的隔离语义（看不到 => 404）。
        $otherTenantUser->assignRole('owner');

        // 切换用户后看不到（tenant 隔离通过 workspace 解析实现）
        \Laravel\Sanctum\Sanctum::actingAs($otherTenantUser);
        $this->getJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/alert-rules/' . $rule)
            ->assertStatus(404);
    }

    private function createRule(): string
    {
        return $this->createRuleModel()->uuid;
    }

    private function createRuleModel(): AlertRule
    {
        return AlertRule::create([
            'uuid'            => (string) Str::uuid(),
            'workspace_id'    => $this->workspaceId,
            'topic_id'        => $this->topicId,
            'name'            => '错误率告警',
            'condition'       => ['metric' => 'count', 'filter' => 'level:ERROR'],
            'window_sec'      => 60,
            'threshold'       => 100,
            'severity'        => 'warning',
            'notify_channels' => [],
            'status'          => 'active',
        ]);
    }
}
