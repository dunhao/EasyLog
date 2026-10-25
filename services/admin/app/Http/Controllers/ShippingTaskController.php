<?php

declare(strict_types=1);

namespace EasyLog\Http\Controllers;

use EasyLog\Models\ShippingTask;
use EasyLog\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 日志投递任务 CRUD（导出到 OSS/文件/外部 MQ/HTTP）
 *
 * 路由（嵌套在 workspace 下）：
 * - GET    /workspaces/{workspace}/shipping-tasks           index
 * - POST   /workspaces/{workspace}/shipping-tasks           store
 * - GET    /workspaces/{workspace}/shipping-tasks/{id}      show
 * - PUT    /workspaces/{workspace}/shipping-tasks/{id}      update
 * - DELETE /workspaces/{workspace}/shipping-tasks/{id}      destroy（软删）
 *
 * 多租户隔离：tenant → workspace → task 三层限定。
 */
class ShippingTaskController extends Controller
{
    public function __construct()
    {
        $this->middleware('ability:shipping.view')->only(['index', 'show']);
        $this->middleware('ability:shipping.manage')->only(['store', 'update', 'destroy']);
    }
    public function index(Request $request, string $workspace): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $query = ShippingTask::where('workspace_id', $ws->id)
            ->when($request->input('keyword'), fn ($q, $kw) => $q->where('name', 'like', "%{$kw}%"))
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s));

        $page     = max(1, (int) $request->input('page', 1));
        $pageSize = min(100, max(1, (int) $request->input('page_size', 20)));
        $total    = $query->count();
        $items    = $query->forPage($page, $pageSize)->get();

        return response()->json([
            'data' => $items->map(fn (ShippingTask $t) => $this->serialize($t)),
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
        $validated = $this->validateTask($request);

        $task = new ShippingTask();
        $task->uuid          = Str::uuid()->toString();
        $task->workspace_id  = $ws->id;
        $task->topic_id      = $validated['topic_id'];
        $task->name          = $validated['name'];
        $task->target_type   = $validated['target_type'];
        $task->target_config = $validated['target_config'] ?? [];
        $task->filter        = $validated['filter'] ?? null;
        $task->start_from    = $validated['start_from'] ?? null;
        $task->status        = $validated['status'] ?? 'active';
        $task->save();

        return response()->json($this->serialize($task), 201);
    }

    public function show(string $workspace, int $task): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        return response()->json($this->serialize($this->loadTask($ws, $task)));
    }

    public function update(Request $request, string $workspace, int $task): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $model = $this->loadTask($ws, $task);
        $validated = $this->validateTask($request, true);

        $model->fill($validated);
        $model->save();

        return response()->json($this->serialize($model));
    }

    public function destroy(string $workspace, int $task): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $model = $this->loadTask($ws, $task);
        $model->delete();   // 软删

        return response()->json(null, 204);
    }

    private function validateTask(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name'          => ($partial ? 'sometimes|' : 'required|') . 'string|max:64',
            'topic_id'      => ($partial ? 'sometimes|' : 'required|') . 'integer|exists:topics,id',
            'target_type'   => ($partial ? 'sometimes|' : 'required|') . 'in:oss,file,rabbitmq,kafka,redis,activemq,http',
            'target_config' => 'nullable|array',
            'filter'        => 'nullable|array',
            'start_from'    => 'nullable|date',
            'status'        => 'sometimes|in:active,paused,completed',
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

    private function loadTask(Workspace $ws, int $taskId): ShippingTask
    {
        $task = ShippingTask::where('id', $taskId)
            ->where('workspace_id', $ws->id)
            ->first();
        if (!$task) {
            abort(404, 'Shipping task not found');
        }
        return $task;
    }

    private function serialize(ShippingTask $t): array
    {
        return [
            'id'            => $t->id,
            'uuid'          => $t->uuid,
            'workspace_uuid'=> $t->workspace?->uuid,
            'topic_id'      => $t->topic_id,
            'topic_uuid'    => $t->topic?->uuid,
            'name'          => $t->name,
            'target_type'   => $t->target_type,
            'target_config' => $t->target_config,
            'filter'        => $t->filter,
            'start_from'    => $t->start_from?->toISOString(),
            'status'        => $t->status,
            'created_at'    => $t->created_at?->toISOString(),
            'updated_at'    => $t->updated_at?->toISOString(),
        ];
    }
}
