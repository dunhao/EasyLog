<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 管理 API 测试基类：seed 租户/工作空间/主题/用户 + Sanctum 认证
 */
abstract class AdminApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected const WORKSPACE_UUID = 'ws_acme_prod';
    protected const TOPIC_UUID = 'tpc_order_logs';

    protected int $tenantId;
    protected int $workspaceId;
    protected int $topicId;
    protected int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemoData();

        // 种角色与权限：RBAC 接线后，没有权限记录会把管理面整体打成 403
        $this->seed(\Database\Seeders\RbacSeeder::class);

        $user = \EasyLog\Models\User::find($this->userId);
        // 默认以 owner 身份通过授权校验；需要验证越权的用例自行切换为 viewer/billing
        $user->assignRole('owner');
        Sanctum::actingAs($user);
    }

    private function seedDemoData(): void
    {
        $this->tenantId = DB::table('tenants')->insertGetId([
            'uuid' => Str::uuid()->toString(), 'name' => 'Acme Inc.', 'slug' => 'acme',
            'status' => 'active', 'plan' => 'pro',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->workspaceId = DB::table('workspaces')->insertGetId([
            'uuid' => self::WORKSPACE_UUID, 'tenant_id' => $this->tenantId, 'name' => '生产',
            'description' => '', 'retention_days' => 30, 'shard_count' => 6, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->topicId = DB::table('topics')->insertGetId([
            'uuid' => self::TOPIC_UUID, 'workspace_id' => $this->workspaceId, 'name' => 'order-logs',
            'description' => '', 'auto_field_index' => true, 'retention_days' => 30,
            'enable_full_text' => true, 'json_extract' => true, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->userId = DB::table('users')->insertGetId([
            'tenant_id' => $this->tenantId, 'email' => 'admin@acme.io',
            'password' => bcrypt('secret'), 'name' => 'Admin', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
