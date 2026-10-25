<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * 用户与权限管理 API 测试
 */
class UserApiTest extends AdminApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 角色与权限由 AdminApiTestCase 统一 seed（RbacSeeder），此处不再手工插入，
        // 否则会与种子数据撞 roles.name + guard_name 唯一键。
    }

    public function testIndexReturnsPaginatedUsers(): void
    {
        $this->getJson('/api/v1/users')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'roles']], 'meta' => ['total', 'page', 'page_size']])
            ->assertJsonPath('meta.total', 1);
    }

    public function testStoreCreatesUserWithRoles(): void
    {
        $this->postJson('/api/v1/users', [
            'name'     => 'Dev User',
            'email'    => 'dev@acme.io',
            'password' => 'password123',
            'roles'    => ['viewer'],
        ])
            ->assertStatus(201)
            ->assertJsonPath('email', 'dev@acme.io')
            ->assertJsonPath('roles.0', 'viewer');

        $this->assertDatabaseHas('users', ['email' => 'dev@acme.io']);
    }

    public function testStoreRejectsDuplicateEmail(): void
    {
        $this->postJson('/api/v1/users', [
            'name'     => 'Dup',
            'email'    => 'admin@acme.io',
            'password' => 'password123',
        ])->assertStatus(422);
    }

    public function testStoreRejectsShortPassword(): void
    {
        $this->postJson('/api/v1/users', [
            'name'     => 'Shorty',
            'email'    => 'short@acme.io',
            'password' => 'short',
        ])->assertStatus(422);
    }

    public function testUpdateChangesNameAndRoles(): void
    {
        $id = $this->getJson('/api/v1/users')->json('data.0.id');

        $this->putJson("/api/v1/users/{$id}", [
            'name'  => 'Renamed',
            'roles' => ['admin'],
        ])
            ->assertStatus(200)
            ->assertJsonPath('name', 'Renamed')
            ->assertJsonPath('roles.0', 'admin');
    }

    public function testDestroyForbidsSelfDelete(): void
    {
        $this->deleteJson('/api/v1/users/' . $this->userId)
            ->assertStatus(400);
    }

    public function testDestroySoftDeletesOtherUser(): void
    {
        $id = $this->postJson('/api/v1/users', [
            'name' => 'Temp', 'email' => 'temp@acme.io', 'password' => 'password123',
        ])->json('id');

        $this->deleteJson("/api/v1/users/{$id}")->assertStatus(204);
        $this->assertSoftDeleted('users', ['id' => $id]);
    }

    public function testResetPassword(): void
    {
        $id = $this->postJson('/api/v1/users', [
            'name' => 'Reset', 'email' => 'reset@acme.io', 'password' => 'password123',
        ])->json('id');

        $this->postJson("/api/v1/users/{$id}/reset-password", ['password' => 'newpassword1'])
            ->assertStatus(200);

        // 新密码可登录
        $this->postJson('/api/v1/auth/login', ['email' => 'reset@acme.io', 'password' => 'newpassword1'])
            ->assertStatus(200);
    }

    public function testTenantIsolation(): void
    {
        $otherTenant = DB::table('tenants')->insertGetId([
            'uuid' => '11111111-1111-1111-1111-111111111111', 'name' => 'Other', 'slug' => 'other',
            'status' => 'active', 'plan' => 'free', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherUserId = DB::table('users')->insertGetId([
            'tenant_id' => $otherTenant, 'email' => 'x@other.io',
            'password' => bcrypt('password123'), 'name' => 'X', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 列表不含其他租户用户
        $this->getJson('/api/v1/users')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1);

        // 单查其他租户用户 404
        $this->getJson("/api/v1/users/{$otherUserId}")->assertStatus(404);
    }
}
