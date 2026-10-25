<?php

namespace Tests\Unit;

use EasyLog\Support\ServiceRole;
use PHPUnit\Framework\TestCase;

/**
 * 回归测试：服务角色是否承载管理平面路由（P0-2）。
 *
 * routes/api.php 据此决定是否注册 /api/v1/users、/api/v1/workspaces、
 * /audit-logs 等管理路由。此前无条件注册，导致接入网关 ingestion(9514)
 * 也暴露了管理面。这里锁定判定逻辑本身（单一决策点）。
 */
class ServiceRoleTest extends TestCase
{
    public static function managementRoleProvider(): array
    {
        return [
            'admin 承载管理面' => ['admin', true],
            'query 承载管理面（检索 + 控制台）' => ['query', true],
            'ingestion 不承载管理面（公开接入网关）' => ['ingestion', false],
            'writer 不承载管理面' => ['writer', false],
            'worker 不承载管理面' => ['worker', false],
            '未知角色不承载管理面' => ['bogus', false],
        ];
    }

    /**
     * @dataProvider managementRoleProvider
     */
    public function test_serves_management_api_by_explicit_role(string $role, bool $expected): void
    {
        $this->assertSame($expected, ServiceRole::servesManagementApi($role));
    }

    public function test_default_role_is_admin_and_serves_management_api(): void
    {
        // 未传角色、且进程未设 SERVICE_ROLE 时，缺省按 admin 处理（保持全功能）。
        $this->assertTrue(ServiceRole::servesManagementApi());
    }

    public function test_is_ingestion_only_for_ingestion_role(): void
    {
        $this->assertTrue(ServiceRole::isIngestion('ingestion'));
        $this->assertFalse(ServiceRole::isIngestion('admin'));
        $this->assertFalse(ServiceRole::isIngestion('query'));
    }
}
