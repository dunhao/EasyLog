<?php

declare(strict_types=1);

namespace EasyLog\Http\Controllers;

use EasyLog\Models\Topic;
use EasyLog\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 日志主题 CRUD
 *
 * 路由（apiResource 'workspaces.topics' shallow）：
 * - GET    /workspaces/{workspace}/topics           index
 * - POST   /workspaces/{workspace}/topics           store
 * - GET    /topics/{topic}                            show     （shallow 后路径切到 /topics）
 * - PUT    /topics/{topic}                            update
 * - DELETE /topics/{topic}                            destroy（软删）
 *
 * 多租户隔离：所有查询带 workspace_id + tenant 双重约束。
 */
class TopicController extends Controller
{
    public function __construct()
    {
        $this->middleware('ability:topics.view')->only(['index', 'show']);
        $this->middleware('ability:topics.create')->only(['store']);
        $this->middleware('ability:topics.update')->only(['update']);
        $this->middleware('ability:topics.delete')->only(['destroy']);
    }
    public function index(Request $request, string $workspace): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $query = Topic::where('workspace_id', $ws->id)
            ->when($request->input('keyword'), function ($q, $kw) {
                $q->where('name', 'like', "%{$kw}%");
            });

        $page      = max(1, (int) $request->input('page', 1));
        $pageSize = min(100, max(1, (int) $request->input('page_size', 20)));
        $total    = $query->count();
        $items    = $query->forPage($page, $pageSize)->get();

        return response()->json([
            'data' => $items->map(fn (Topic $t) => $this->serialize($t)),
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
            'name'              => 'required|string|max:64',
            'description'       => 'nullable|string|max:255',
            'retention_days'    => 'nullable|integer|min:1|max:3650',
            'auto_field_index'  => 'nullable|boolean',
            'enable_full_text'  => 'nullable|boolean',
            'json_extract'      => 'nullable|boolean',
        ]);

        $topic = new Topic();
        $topic->uuid              = Str::uuid()->toString();
        $topic->workspace_id      = $ws->id;
        $topic->name              = $validated['name'];
        $topic->description       = $validated['description'] ?? null;
        $topic->retention_days    = $validated['retention_days']    ?? $ws->retention_days;
        $topic->auto_field_index  = (bool) ($validated['auto_field_index'] ?? true);
        $topic->enable_full_text  = (bool) ($validated['enable_full_text'] ?? true);
        $topic->json_extract      = (bool) ($validated['json_extract']     ?? true);
        $topic->status            = 'active';
        $topic->save();

        return response()->json($this->serialize($topic), 201);
    }

    public function show(Request $request, string $topic): JsonResponse
    {
        // shallow 路由：GET /topics/{topic}，仅携带 topic 参数
        [, $topicModel] = $this->resolveTopicWithWorkspace($topic);
        return response()->json($this->serialize($topicModel));
    }

    public function update(Request $request, string $topic): JsonResponse
    {
        [$ws, $topicModel] = $this->resolveTopicWithWorkspace($topic);

        $validated = $request->validate([
            'name'              => 'sometimes|string|max:64',
            'description'       => 'sometimes|nullable|string|max:255',
            'retention_days'    => 'sometimes|integer|min:1|max:3650',
            'auto_field_index'  => 'sometimes|boolean',
            'enable_full_text'  => 'sometimes|boolean',
            'json_extract'      => 'sometimes|boolean',
            'status'            => 'sometimes|in:active,disabled',
        ]);

        $topicModel->fill($validated);
        $topicModel->save();

        return response()->json($this->serialize($topicModel));
    }

    public function destroy(Request $request, string $topic): JsonResponse
    {
        [, $topicModel] = $this->resolveTopicWithWorkspace($topic);
        $topicModel->delete();  // 软删

        return response()->json(null, 204);
    }

    /**
     * 按 uuid 加载 topic 并校验其 workspace 归属当前租户
     */
    private function resolveTopicWithWorkspace(string $topicUuid): array
    {
        $topicModel = Topic::where('uuid', $topicUuid)->first();
        if (!$topicModel) {
            abort(404, 'Topic not found');
        }

        $tenantId = app('easylog.tenant_id');
        $ws = Workspace::where('id', $topicModel->workspace_id)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('status', 'active')
            ->first();
        if (!$ws) {
            abort(403, 'Topic not in your tenant');
        }
        return [$ws, $topicModel];
    }

    /**
     * 鉴权 + 加载 workspace
     */
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

    private function serialize(Topic $t): array
    {
        return [
            'id'                => $t->id,
            'uuid'              => $t->uuid,
            'workspace_uuid'    => $t->workspace?->uuid,
            'name'              => $t->name,
            'description'       => $t->description,
            'retention_days'    => $t->retention_days,
            'auto_field_index'  => (bool) $t->auto_field_index,
            'enable_full_text'  => (bool) $t->enable_full_text,
            'json_extract'      => (bool) $t->json_extract,
            'status'            => $t->status,
            'created_at'        => $t->created_at?->toISOString(),
            'updated_at'        => $t->updated_at?->toISOString(),
        ];
    }
}
