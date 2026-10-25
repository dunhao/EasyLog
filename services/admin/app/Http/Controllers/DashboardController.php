<?php

declare(strict_types=1);

namespace EasyLog\Http\Controllers;

use EasyLog\Models\Dashboard;
use EasyLog\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 看板 CRUD（控制台可视化布局）
 *
 * 路由（嵌套在 workspace 下）：
 * - GET    /workspaces/{workspace}/dashboards           index
 * - POST   /workspaces/{workspace}/dashboards           store
 * - GET    /workspaces/{workspace}/dashboards/{id}      show
 * - PUT    /workspaces/{workspace}/dashboards/{id}      update
 * - DELETE /workspaces/{workspace}/dashboards/{id}      destroy（软删）
 *
 * 多租户隔离：tenant → workspace → dashboard 三层限定。
 */
class DashboardController extends Controller
{
    public function __construct()
    {
        $this->middleware('ability:dashboards.view')->only(['index', 'show']);
        $this->middleware('ability:dashboards.manage')->only(['store', 'update', 'destroy']);
    }
    public function index(Request $request, string $workspace): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $query = Dashboard::where('workspace_id', $ws->id)
            ->when($request->input('keyword'), fn ($q, $kw) => $q->where('name', 'like', "%{$kw}%"));

        $page     = max(1, (int) $request->input('page', 1));
        $pageSize = min(100, max(1, (int) $request->input('page_size', 20)));
        $total    = $query->count();
        $items    = $query->forPage($page, $pageSize)->get();

        return response()->json([
            'data' => $items->map(fn (Dashboard $d) => $this->serialize($d)),
            'meta' => [
                'total'     => $total,
                'page'      => $page,
                'page_size' => $pageSize,
            ],
        ]);
    }

    public function store(Request $request, string $workspace): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $validated = $request->validate([
            'name'   => 'required|string|max:64',
            'layout' => 'nullable|array',
        ]);

        $dashboard = new Dashboard();
        $dashboard->uuid         = Str::uuid()->toString();
        $dashboard->workspace_id = $ws->id;
        $dashboard->name         = $validated['name'];
        $dashboard->layout       = $validated['layout'] ?? [];
        $dashboard->created_by   = $request->user()?->id ?? 0;
        $dashboard->save();

        return response()->json($this->serialize($dashboard), 201);
    }

    public function show(string $workspace, int $dashboard): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        return response()->json($this->serialize($this->loadDashboard($ws, $dashboard)));
    }

    public function update(Request $request, string $workspace, int $dashboard): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $model = $this->loadDashboard($ws, $dashboard);

        $validated = $request->validate([
            'name'   => 'sometimes|string|max:64',
            'layout' => 'sometimes|array',
        ]);

        $model->fill($validated);
        $model->save();

        return response()->json($this->serialize($model));
    }

    public function destroy(string $workspace, int $dashboard): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $model = $this->loadDashboard($ws, $dashboard);
        $model->delete();   // 软删

        return response()->json(null, 204);
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

    private function loadDashboard(Workspace $ws, int $dashboardId): Dashboard
    {
        $dashboard = Dashboard::where('id', $dashboardId)
            ->where('workspace_id', $ws->id)
            ->first();
        if (!$dashboard) {
            abort(404, 'Dashboard not found');
        }
        return $dashboard;
    }

    private function serialize(Dashboard $d): array
    {
        return [
            'id'         => $d->id,
            'uuid'       => $d->uuid,
            'name'       => $d->name,
            'layout'     => $d->layout,
            'created_by' => $d->created_by,
            'created_at' => $d->created_at?->toISOString(),
            'updated_at' => $d->updated_at?->toISOString(),
        ];
    }
}
