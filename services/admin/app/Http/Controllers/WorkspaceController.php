<?php

declare(strict_types=1);

namespace EasyLog\Http\Controllers;

use EasyLog\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 工作空间 CRUD
 *
 * 路由（apiResource，shallow）：
 * - GET    /workspaces           index
 * - POST   /workspaces           store
 * - GET    /workspaces/{uuid}    show
 * - PUT    /workspaces/{uuid}    update
 * - DELETE /workspaces/{uuid}    destroy（软删）
 *
 * 多租户隔离：所有查询带 tenant_id 限定，来自 ResolveTenantContext 注入的容器单例。
 */
class WorkspaceController extends Controller
{
    public function __construct()
    {
        $this->middleware('ability:workspaces.view')->only(['index', 'show']);
        $this->middleware('ability:workspaces.create')->only(['store']);
        $this->middleware('ability:workspaces.update')->only(['update']);
        $this->middleware('ability:workspaces.delete')->only(['destroy']);
    }
    public function index(Request $request): JsonResponse
    {
        $tenantId = app('easylog.tenant_id');
        $query = Workspace::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($request->input('keyword'), function ($q, $kw) {
                $q->where('name', 'like', "%{$kw}%");
            });

        $page      = max(1, (int) $request->input('page', 1));
        $pageSize = min(100, max(1, (int) $request->input('page_size', 20)));
        $total    = $query->count();
        $items    = $query->forPage($page, $pageSize)->get();

        return response()->json([
            'data' => $items->map(fn (Workspace $ws) => $this->serialize($ws)),
            'meta' => [
                'total'     => $total,
                'page'      => $page,
                'page_size' => $pageSize,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:64',
            'description'    => 'nullable|string|max:255',
            'retention_days' => 'nullable|integer|min:1|max:3650',
            'shard_count'    => 'nullable|integer|min:1|max:100',
            'daily_quota_gb' => 'nullable|integer|min:0|max:102400',
        ]);

        $tenantId = app('easylog.tenant_id');
        if (!$tenantId) {
            abort(403, 'Tenant context missing');
        }

        $ws = new Workspace();
        $ws->uuid           = Str::uuid()->toString();
        $ws->tenant_id      = $tenantId;
        $ws->name           = $validated['name'];
        $ws->description    = $validated['description'] ?? null;
        $ws->retention_days = $validated['retention_days'] ?? 30;
        $ws->shard_count    = $validated['shard_count']    ?? 6;
        $ws->daily_quota_gb = (int) ($validated['daily_quota_gb'] ?? 0);   // 0 = 不限
        $ws->status         = 'active';
        $ws->save();

        return response()->json($this->serialize($ws), 201);
    }

    public function show(string $uuid): JsonResponse
    {
        $ws = $this->loadWorkspace($uuid);
        return response()->json($this->serialize($ws));
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'sometimes|string|max:64',
            'description'    => 'sometimes|nullable|string|max:255',
            'retention_days' => 'sometimes|integer|min:1|max:3650',
            'shard_count'    => 'sometimes|integer|min:1|max:100',
            'daily_quota_gb' => 'sometimes|nullable|integer|min:0|max:102400',
            'status'         => 'sometimes|in:active,disabled',
        ]);

        // daily_quota_gb 显式传 null 时还原为 0（0 = 不限）
        if (array_key_exists('daily_quota_gb', $validated)) {
            $validated['daily_quota_gb'] = (int) ($validated['daily_quota_gb'] ?? 0);
        }

        $ws = $this->loadWorkspace($uuid);
        $ws->fill($validated);
        $ws->save();

        return response()->json($this->serialize($ws));
    }

    public function destroy(string $uuid): JsonResponse
    {
        $ws = $this->loadWorkspace($uuid);
        $ws->delete();   // 软删

        return response()->json(null, 204);
    }

    /**
     * 鉴权 + 加载 workspace（多租户隔离强约束）
     */
    private function loadWorkspace(string $uuid): Workspace
    {
        $tenantId = app('easylog.tenant_id');
        $ws = Workspace::where('uuid', $uuid)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->first();
        if (!$ws) {
            abort(404, 'Workspace not found');
        }
        return $ws;
    }

    /**
     * 序列化输出（对外只暴露 uuid，不暴露自增主键）
     */
    private function serialize(Workspace $ws): array
    {
        return [
            'uuid'           => $ws->uuid,
            'name'           => $ws->name,
            'description'    => $ws->description,
            'retention_days' => $ws->retention_days,
            'shard_count'    => $ws->shard_count,
            'daily_quota_gb' => (int) $ws->daily_quota_gb,
            'status'         => $ws->status,
            'created_at'     => $ws->created_at?->toISOString(),
            'updated_at'     => $ws->updated_at?->toISOString(),
        ];
    }
}
