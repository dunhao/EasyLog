<?php

declare(strict_types=1);

namespace EasyLog\Http\Controllers;

use EasyLog\Models\ConsumptionGroup;
use EasyLog\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 消费组 CRUD（第三方拉取日志的凭证单元）
 *
 * 路由（嵌套在 workspace 下）：
 * - GET    /workspaces/{workspace}/consumption-groups           index
 * - POST   /workspaces/{workspace}/consumption-groups           store
 * - GET    /workspaces/{workspace}/consumption-groups/{id}      show
 * - PUT    /workspaces/{workspace}/consumption-groups/{id}      update
 * - DELETE /workspaces/{workspace}/consumption-groups/{id}      destroy
 *
 * auth_token 仅 store/rotate 时明文返回一次，库中只存哈希。
 * 多租户隔离：tenant → workspace → group 三层限定。
 */
class ConsumptionGroupController extends Controller
{
    public function __construct()
    {
        $this->middleware('ability:consumption.view')->only(['index', 'show']);
        $this->middleware('ability:consumption.manage')->only(['store', 'update', 'destroy', 'rotateToken']);
    }
    public function index(Request $request, string $workspace): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $query = ConsumptionGroup::where('workspace_id', $ws->id)
            ->when($request->input('consumer_group'), fn ($q, $cg) => $q->where('consumer_group', 'like', "%{$cg}%"));

        $page     = max(1, (int) $request->input('page', 1));
        $pageSize = min(100, max(1, (int) $request->input('page_size', 20)));
        $total    = $query->count();
        $items    = $query->forPage($page, $pageSize)->get();

        return response()->json([
            'data' => $items->map(fn (ConsumptionGroup $g) => $this->serialize($g)),
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
            'topic_id'       => 'required|integer|exists:topics,id',
            'consumer_group' => 'required|string|max:64',
        ]);

        // unique(topic_id, consumer_group)：显式检查，保证 SQLite/MySQL 下统一返回 422
        $exists = ConsumptionGroup::where('topic_id', $validated['topic_id'])
            ->where('consumer_group', $validated['consumer_group'])
            ->exists();
        if ($exists) {
            abort(422, "Consumer group '{$validated['consumer_group']}' already exists on topic");
        }

        $token = Str::random(48);

        $group = new ConsumptionGroup();
        $group->uuid            = Str::uuid()->toString();
        $group->workspace_id    = $ws->id;
        $group->topic_id        = $validated['topic_id'];
        $group->consumer_group  = $validated['consumer_group'];
        $group->auth_token_hash = bcrypt($token);
        $group->save();

        return response()->json($this->serialize($group) + ['auth_token' => $token], 201);
    }

    public function show(string $workspace, int $group): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        return response()->json($this->serialize($this->loadGroup($ws, $group)));
    }

    public function update(Request $request, string $workspace, int $group): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $model = $this->loadGroup($ws, $group);

        $validated = $request->validate([
            'consumer_group' => 'sometimes|string|max:64',
        ]);

        $model->fill($validated);
        $model->save();

        return response()->json($this->serialize($model));
    }

    public function destroy(string $workspace, int $group): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $model = $this->loadGroup($ws, $group);
        $model->delete();

        Log::info('EasyLog consumption group deleted', ['group' => $model->uuid]);
        return response()->json(null, 204);
    }

    public function rotateToken(string $workspace, int $group): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $model = $this->loadGroup($ws, $group);

        $token = Str::random(48);
        $model->auth_token_hash = bcrypt($token);
        $model->save();

        Log::info('EasyLog consumption group token rotated', ['group' => $model->uuid]);
        return response()->json(['consumer_group' => $model->consumer_group, 'auth_token' => $token]);
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

    private function loadGroup(Workspace $ws, int $groupId): ConsumptionGroup
    {
        $group = ConsumptionGroup::where('id', $groupId)
            ->where('workspace_id', $ws->id)
            ->first();
        if (!$group) {
            abort(404, 'Consumption group not found');
        }
        return $group;
    }

    /**
     * 序列化输出（不暴露 auth_token_hash）
     */
    private function serialize(ConsumptionGroup $g): array
    {
        return [
            'id'              => $g->id,
            'uuid'            => $g->uuid,
            'workspace_uuid'  => $g->workspace?->uuid,
            'topic_id'        => $g->topic_id,
            'topic_uuid'      => $g->topic?->uuid,
            'consumer_group'  => $g->consumer_group,
            'created_at'      => $g->created_at?->toISOString(),
            'updated_at'      => $g->updated_at?->toISOString(),
        ];
    }
}
