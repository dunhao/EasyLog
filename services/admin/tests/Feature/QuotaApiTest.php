<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * 配额管理 API 测试（workspace / tenant 层日配额的查看与设置）
 */
class QuotaApiTest extends AdminApiTestCase
{
    public function testWorkspaceQuotaDefaultsToUnlimited(): void
    {
        $this->getJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/quota')
            ->assertStatus(200)
            ->assertJsonPath('scope', 'workspace')
            ->assertJsonPath('daily_quota_gb', 0)
            ->assertJsonPath('used_bytes_today', 0);
    }

    public function testUpdateWorkspaceQuota(): void
    {
        $this->putJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/quota', [
            'daily_quota_gb' => 10,
        ])
            ->assertStatus(200)
            ->assertJsonPath('daily_quota_gb', 10);

        $this->assertDatabaseHas('workspaces', [
            'uuid' => self::WORKSPACE_UUID, 'daily_quota_gb' => 10,
        ]);

        // GET 反映新值
        $this->getJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/quota')
            ->assertJsonPath('daily_quota_gb', 10);
    }

    public function testWorkspaceResourceExposesQuota(): void
    {
        // workspace PUT 也可直接改配额（管理面入口之二）
        $this->putJson('/api/v1/workspaces/' . self::WORKSPACE_UUID, [
            'daily_quota_gb' => 5,
        ])
            ->assertStatus(200)
            ->assertJsonPath('daily_quota_gb', 5);
    }

    public function testUpdateWorkspaceQuotaValidation(): void
    {
        $this->putJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/quota', [
            'daily_quota_gb' => -1,
        ])->assertStatus(422);
    }

    public function testTenantQuotaRoundTrip(): void
    {
        $this->getJson('/api/v1/tenant/quota')
            ->assertStatus(200)
            ->assertJsonPath('scope', 'tenant')
            ->assertJsonPath('daily_quota_gb', 0);

        $this->putJson('/api/v1/tenant/quota', ['daily_quota_gb' => 100])
            ->assertStatus(200)
            ->assertJsonPath('daily_quota_gb', 100);

        $this->getJson('/api/v1/tenant/quota')
            ->assertJsonPath('daily_quota_gb', 100);
    }
}
