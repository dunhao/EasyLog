<?php

declare(strict_types=1);

namespace EasyLog\Http\Controllers;

use EasyLog\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 配额管理 API（阶段二 T5）
 *
 * 路由：
 * - GET /workspaces/{workspace}/quota   查看工作空间配额与当日用量
 * - PUT /workspaces/{workspace}/quota   设置工作空间日配额（0 = 不限）
 * - GET /tenant/quota                   查看当前租户配额与当日总用量
 * - PUT /tenant/quota                   设置租户日配额（0 = 不限）
 *
 * 生效层级：endpoint → workspace → tenant，任一层超限 ingest 即 429。
 */
class QuotaController extends Controller
{
    public function __construct()
    {
        $this->middleware('ability:quota.view')->only(['workspaceQuota', 'tenantQuota']);
        $this->middleware('ability:quota.manage')->only(['updateWorkspaceQuota', 'updateTenantQuota']);
    }
    public function workspaceQuota(Request $request, string $workspace): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);

        return response()->json([
            'scope'            => 'workspace',
            'uuid'             => $ws->uuid,
            'daily_quota_gb'   => (int) $ws->daily_quota_gb,
            'used_bytes_today' => $this->workspaceBytesToday($ws->id),
        ]);
    }

    public function updateWorkspaceQuota(Request $request, string $workspace): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $validated = $request->validate([
            'daily_quota_gb' => 'required|integer|min:0|max:102400',
        ]);

        $ws->daily_quota_gb = (int) $validated['daily_quota_gb'];
        $ws->save();

        return response()->json([
            'scope'            => 'workspace',
            'uuid'             => $ws->uuid,
            'daily_quota_gb'   => $ws->daily_quota_gb,
            'used_bytes_today' => $this->workspaceBytesToday($ws->id),
        ]);
    }

    public function tenantQuota(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        return response()->json([
            'scope'            => 'tenant',
            'uuid'             => $tenant?->uuid,
            'daily_quota_gb'   => (int) ($tenant?->daily_quota_gb ?? 0),
            'used_bytes_today' => $this->tenantBytesToday((int) $request->user()->tenant_id),
        ]);
    }

    public function updateTenantQuota(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'daily_quota_gb' => 'required|integer|min:0|max:1024000',
        ]);

        $tenant = $request->user()->tenant;
        if (!$tenant) {
            abort(404, 'Tenant not found');
        }

        $tenant->daily_quota_gb = (int) $validated['daily_quota_gb'];
        $tenant->save();

        return response()->json([
            'scope'            => 'tenant',
            'uuid'             => $tenant->uuid,
            'daily_quota_gb'   => $tenant->daily_quota_gb,
            'used_bytes_today' => $this->tenantBytesToday((int) $tenant->id),
        ]);
    }

    private function workspaceBytesToday(int $workspaceId): int
    {
        return (int) (\EasyLog\Models\UsageRecord::where('workspace_id', $workspaceId)
            ->where('usage_date', today()->toDateString())
            ->sum('bytes'));
    }

    private function tenantBytesToday(int $tenantId): int
    {
        return (int) (\EasyLog\Models\UsageRecord::where('usage_date', today()->toDateString())
            ->whereIn('workspace_id', fn ($q) => $q->select('id')->from('workspaces')->where('tenant_id', $tenantId))
            ->sum('bytes'));
    }

    private function resolveWorkspace(string $workspaceUuid): Workspace
    {
        $tenantId = app('easylog.tenant_id');
        $ws = Workspace::where('uuid', $workspaceUuid)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('status', 'active')
            ->first();
        if (!$ws) {
            abort(404, 'Workspace not found');
        }
        return $ws;
    }
}
