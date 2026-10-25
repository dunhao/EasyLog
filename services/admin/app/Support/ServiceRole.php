<?php

namespace EasyLog\Support;

/**
 * 服务角色判定（多角色共用同一份 Laravel 应用 + 同一份 routes/api.php）。
 *
 * 背景（P0-2）：admin / ingestion / query / writer / worker 共用一个镜像与一个
 * Laravel 应用，routes/api.php 原本无条件注册全部路由 —— 导致接入网关
 * ingestion(9514) 也暴露了 /api/v1/users、/api/v1/workspaces、/audit-logs 等
 * 管理路由，任何人都能在 9514 上撞库式访问控制台管理面。
 *
 * 约定：
 * - 管理平面（控制台认证 + 管理 API）只应由 admin / query 角色注册；
 * - ingestion(9514) 是公开接入网关，仅暴露 /api/v1/health 与 /api/v1/ingest/*；
 * - writer / worker 不跑 HTTP 服务，路由注册与否对其无意义。
 *
 * 注意：本判定在路由注册期读取 SERVICE_ROLE（容器级环境变量，每个容器固定），
 * 因此不会随请求变化；请勿在 config:cache 之后依赖未缓存的 env() 值。
 */
final class ServiceRole
{
    /** 承载管理平面（控制台 + 检索）的角色白名单。 */
    private const MANAGEMENT_ROLES = ['admin', 'query'];

    /**
     * 当前角色是否应注册管理平面路由。
     *
     * @param string|null $role 显式传入角色（用于测试）；为空时读 SERVICE_ROLE，
     *                          缺省按 admin 处理（开发态 / 未设环境变量时保持全功能）。
     */
    public static function servesManagementApi(?string $role = null): bool
    {
        $role = $role ?? env('SERVICE_ROLE', 'admin');

        return in_array($role, self::MANAGEMENT_ROLES, true);
    }

    /**
     * 当前角色是否为公开接入网关（ingestion）。
     */
    public static function isIngestion(?string $role = null): bool
    {
        $role = $role ?? env('SERVICE_ROLE', 'admin');

        return $role === 'ingestion';
    }
}
