<?php

declare(strict_types=1);

namespace Tests\Feature;

use EasyLog\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/**
 * RBAC 授权生效性回归。
 *
 * 背景：CheckAbility 中间件此前注册了 ability 别名但 routes/ 全文 0 处使用，
 * 且 RbacSeeder 只种角色不种权限 —— 等于「认证有、授权无」，任何已登录用户
 * （哪怕 viewer）都能建删用户、轮换他人密钥、读全租户审计日志。
 *
 * 本组用例的作用是把这次接线钉死：一旦有人摘掉 ability 中间件或清空 permission，
 * 这里会立刻变红，而不是静默退化成人人可管。
 */
class RbacEnforcementTest extends AdminApiTestCase
{
    public function testViewerCannotCreateUsers(): void
    {
        $this->actingAsRole('viewer');

        $this->postJson('/api/v1/users', [
            'name' => 'Sneaky', 'email' => 'sneaky@acme.io', 'password' => 'password123',
        ])->assertForbidden();
    }

    public function testViewerCannotListUsers(): void
    {
        $this->actingAsRole('viewer');

        $this->getJson('/api/v1/users')->assertForbidden();
    }

    public function testViewerCannotReadAuditLogs(): void
    {
        $this->actingAsRole('viewer');

        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    public function testViewerCannotCreateWorkspace(): void
    {
        $this->actingAsRole('viewer');

        $this->postJson('/api/v1/workspaces', [
            'name' => 'Sneaky WS', 'description' => '',
        ])->assertForbidden();
    }

    public function testViewerCanStillReadWorkspaces(): void
    {
        $this->actingAsRole('viewer');

        // 只读能力必须对 viewer 保持可用，否则是一次把管理面整体打死的误伤
        $this->getJson('/api/v1/workspaces')->assertOk();
    }

    public function testViewerCanStillSearch(): void
    {
        $this->actingAsRole('viewer');

        $status = $this->postJson('/api/v1/topics/' . self::TOPIC_UUID . '/search', [
            'query' => '*', 'limit' => 10,
        ])->status();

        // 检索属基础能力：允许业务性 4xx，但绝不能是授权拒绝
        $this->assertNotSame(403, $status, 'viewer 应保留检索能力，不能被授权层挡住');
    }

    public function testViewerCannotRotateEndpointSecret(): void
    {
        $endpointId = DB::table('ingestion_endpoints')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'workspace_id' => $this->workspaceId,
            'topic_id' => $this->topicId,
            'name' => 'demo', 'source_type' => 'http',
            'config' => json_encode([]),
            'app_id' => 'app_test', 'app_secret_hash' => 'x',
            'rate_limit_per_sec' => 100, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $uuid = DB::table('ingestion_endpoints')->where('id', $endpointId)->value('uuid');

        $this->actingAsRole('viewer');

        $this->postJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/endpoints/' . $uuid . '/rotate-secret')
            ->assertForbidden();
    }

    public function testBillingCanManageQuota(): void
    {
        $this->actingAsRole('billing');

        $this->putJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/quota', [
            'daily_quota_gb' => 5,
        ])->assertOk();
    }

    public function testBillingCannotManageUsers(): void
    {
        $this->actingAsRole('billing');

        $this->postJson('/api/v1/users', [
            'name' => 'Sneaky', 'email' => 'sneaky2@acme.io', 'password' => 'password123',
        ])->assertForbidden();
    }

    public function testOwnerCanManageUsers(): void
    {
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/users', [
            'name' => 'Legit Admin', 'email' => 'legit@acme.io', 'password' => 'password123',
        ])->assertCreated();
    }

    /**
     * 以指定角色身份发起请求。
     */
    private function actingAsRole(string $role): User
    {
        $user = User::find($this->userId);
        $user->syncRoles([$role]);

        // spatie 会缓存权限，切换角色后必须失效，否则断言的是上一次的授权结果
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $fresh = $user->fresh();
        Sanctum::actingAs($fresh);

        return $fresh;
    }
}
