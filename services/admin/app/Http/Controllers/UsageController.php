<?php

declare(strict_types=1);

namespace EasyLog\Http\Controllers;

use EasyLog\Models\UsageRecord;
use EasyLog\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 用量查询 API
 *
 * 路由：
 * - GET /workspaces/{workspace}/usage           按天聚合 bytes/count
 * - GET /workspaces/{workspace}/usage/endpoints 按端点聚合
 *
 * 多租户隔离：tenant → workspace 两层限定。
 */
class UsageController extends Controller
{
    public function __construct()
    {
        $this->middleware('ability:usage.view')->only(['usage', 'byEndpoint']);
    }
    public function usage(Request $request, string $workspace): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $validated = $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $rows = UsageRecord::selectRaw('usage_date, SUM(bytes) as bytes, SUM(count) as count')
            ->where('workspace_id', $ws->id)
            ->whereBetween('usage_date', [
                \Illuminate\Support\Carbon::parse($validated['from'])->toDateString(),
                \Illuminate\Support\Carbon::parse($validated['to'])->toDateString(),
            ])
            ->groupBy('usage_date')
            ->orderBy('usage_date')
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'date'  => \Illuminate\Support\Carbon::parse($r->usage_date)->toDateString(),
                'bytes' => (int) $r->bytes,
                'count' => (int) $r->count,
            ]),
        ]);
    }

    public function byEndpoint(Request $request, string $workspace): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $validated = $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $rows = UsageRecord::with('endpoint:id,uuid,name')
            ->selectRaw('endpoint_id, SUM(bytes) as bytes, SUM(count) as count')
            ->where('workspace_id', $ws->id)
            ->whereBetween('usage_date', [
                \Illuminate\Support\Carbon::parse($validated['from'])->toDateString(),
                \Illuminate\Support\Carbon::parse($validated['to'])->toDateString(),
            ])
            ->groupBy('endpoint_id')
            ->orderByDesc('bytes')
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'endpoint_uuid' => $r->endpoint?->uuid,
                'endpoint_name' => $r->endpoint?->name,
                'bytes'         => (int) $r->bytes,
                'count'         => (int) $r->count,
            ]),
        ]);
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
