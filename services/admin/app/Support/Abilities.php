<?php

namespace EasyLog\Support;

/**
 * 权限点清单与角色映射（RBAC 唯一事实来源）。
 *
 * 背景：此前 CheckAbility 中间件注册了 ability 别名，但 routes/ 全文 0 处使用，
 * RbacSeeder 也只种角色不种权限 —— 等于「认证有、授权无」，任何已登录用户
 * （哪怕 viewer）都能建删用户、轮换他人密钥、读全租户审计日志。
 *
 * 约定：
 * - 命名 <resource>.<action>
 * - 所有权限点挂 web guard（与 User::$guard_name 保持一致，否则 Spatie 会抛
 *   GuardDoesNotMatch）
 */
final class Abilities
{
    // ---------------- 工作空间 ----------------
    public const WORKSPACE_VIEW = 'workspaces.view';
    public const WORKSPACE_CREATE = 'workspaces.create';
    public const WORKSPACE_UPDATE = 'workspaces.update';
    public const WORKSPACE_DELETE = 'workspaces.delete';

    // ---------------- 主题与字段 ----------------
    public const TOPIC_VIEW = 'topics.view';
    public const TOPIC_CREATE = 'topics.create';
    public const TOPIC_UPDATE = 'topics.update';
    public const TOPIC_DELETE = 'topics.delete';

    // ---------------- 接入点（含密钥，敏感） ----------------
    public const ENDPOINT_VIEW = 'endpoints.view';
    public const ENDPOINT_CREATE = 'endpoints.create';
    public const ENDPOINT_UPDATE = 'endpoints.update';
    public const ENDPOINT_DELETE = 'endpoints.delete';

    // ---------------- 告警 ----------------
    public const ALERT_VIEW = 'alerts.view';
    public const ALERT_MANAGE = 'alerts.manage';

    // ---------------- 仪表盘 ----------------
    public const DASHBOARD_VIEW = 'dashboards.view';
    public const DASHBOARD_MANAGE = 'dashboards.manage';

    // ---------------- 投递 / 消费组 ----------------
    public const SHIPPING_VIEW = 'shipping.view';
    public const SHIPPING_MANAGE = 'shipping.manage';
    public const CONSUMPTION_VIEW = 'consumption.view';
    public const CONSUMPTION_MANAGE = 'consumption.manage';

    // ---------------- 用户与审计（最敏感） ----------------
    public const USER_VIEW = 'users.view';
    public const USER_MANAGE = 'users.manage';
    public const AUDIT_VIEW = 'audit.view';

    // ---------------- 用量与配额 ----------------
    public const USAGE_VIEW = 'usage.view';
    public const QUOTA_VIEW = 'quota.view';
    public const QUOTA_MANAGE = 'quota.manage';

    // ---------------- 检索（任何登录用户可用） ----------------
    public const SEARCH_QUERY = 'search.query';

    /** 全部权限点 */
    public const ALL = [
        self::WORKSPACE_VIEW, self::WORKSPACE_CREATE, self::WORKSPACE_UPDATE, self::WORKSPACE_DELETE,
        self::TOPIC_VIEW, self::TOPIC_CREATE, self::TOPIC_UPDATE, self::TOPIC_DELETE,
        self::ENDPOINT_VIEW, self::ENDPOINT_CREATE, self::ENDPOINT_UPDATE, self::ENDPOINT_DELETE,
        self::ALERT_VIEW, self::ALERT_MANAGE,
        self::DASHBOARD_VIEW, self::DASHBOARD_MANAGE,
        self::SHIPPING_VIEW, self::SHIPPING_MANAGE,
        self::CONSUMPTION_VIEW, self::CONSUMPTION_MANAGE,
        self::USER_VIEW, self::USER_MANAGE,
        self::AUDIT_VIEW,
        self::USAGE_VIEW, self::QUOTA_VIEW, self::QUOTA_MANAGE,
        self::SEARCH_QUERY,
    ];

    /** 只读集合（viewer 及以上的基线） */
    private const READ_ONLY = [
        self::WORKSPACE_VIEW, self::TOPIC_VIEW, self::ENDPOINT_VIEW,
        self::ALERT_VIEW, self::DASHBOARD_VIEW,
        self::SHIPPING_VIEW, self::CONSUMPTION_VIEW,
        self::USAGE_VIEW, self::SEARCH_QUERY,
    ];

    /**
     * 角色 → 权限映射。
     *
     * - owner  全部
     * - admin  除「用户管理」外的全部（避免管理员自行提权到 owner）
     * - viewer 只读 + 检索
     * - billing 只读 + 用量/配额读写
     */
    public const ROLE_ABILITIES = [
        'owner' => self::ALL,
        'admin' => [
            self::WORKSPACE_VIEW, self::WORKSPACE_CREATE, self::WORKSPACE_UPDATE, self::WORKSPACE_DELETE,
            self::TOPIC_VIEW, self::TOPIC_CREATE, self::TOPIC_UPDATE, self::TOPIC_DELETE,
            self::ENDPOINT_VIEW, self::ENDPOINT_CREATE, self::ENDPOINT_UPDATE, self::ENDPOINT_DELETE,
            self::ALERT_VIEW, self::ALERT_MANAGE,
            self::DASHBOARD_VIEW, self::DASHBOARD_MANAGE,
            self::SHIPPING_VIEW, self::SHIPPING_MANAGE,
            self::CONSUMPTION_VIEW, self::CONSUMPTION_MANAGE,
            self::USER_VIEW,
            self::AUDIT_VIEW,
            self::USAGE_VIEW, self::QUOTA_VIEW, self::QUOTA_MANAGE,
            self::SEARCH_QUERY,
        ],
        'viewer' => self::READ_ONLY,
        'billing' => [
            self::WORKSPACE_VIEW, self::TOPIC_VIEW, self::ENDPOINT_VIEW,
            self::ALERT_VIEW, self::DASHBOARD_VIEW,
            self::SHIPPING_VIEW, self::CONSUMPTION_VIEW,
            self::USAGE_VIEW, self::QUOTA_VIEW, self::QUOTA_MANAGE,
            self::SEARCH_QUERY,
        ],
    ];

    /** 角色名清单（供 seeder 与测试复用） */
    public const ROLES = ['owner', 'admin', 'viewer', 'billing'];
}
