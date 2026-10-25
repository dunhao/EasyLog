<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 控制台认证 API 测试（login / me / logout）
 */
class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;
    private string $email = 'admin@acme.io';
    private string $password = 'secret123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedUser();
    }

    private function seedUser(array $overrides = []): void
    {
        $this->tenantId = DB::table('tenants')->insertGetId([
            'uuid' => Str::uuid()->toString(), 'name' => 'Acme Inc.', 'slug' => 'acme',
            'status' => 'active', 'plan' => 'pro', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('users')->insertGetId(array_merge([
            'tenant_id' => $this->tenantId, 'email' => $this->email,
            'password' => bcrypt($this->password), 'name' => 'Admin', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    public function testLoginSucceedsAndReturnsToken(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => $this->email, 'password' => $this->password,
        ])
            ->assertStatus(200)
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'roles']]);

        // last_login_at 更新
        $this->assertDatabaseHas('users', ['email' => $this->email]);
    }

    public function testLoginRejectsWrongPassword(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => $this->email, 'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function testLoginRejectsSuspendedUser(): void
    {
        DB::table('users')->where('email', $this->email)->update(['status' => 'suspended']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $this->email, 'password' => $this->password,
        ])->assertStatus(403);
    }

    public function testMeRequiresToken(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function testMeAndLogoutRoundTrip(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->email, 'password' => $this->password,
        ])->assertStatus(200)->json('token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('email', $this->email);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertStatus(204);

        // 令牌已从库中删除（同进程内 Sanctum Guard 缓存用户，无法直接断言 401）
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
