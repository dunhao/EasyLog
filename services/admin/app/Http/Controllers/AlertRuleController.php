<?php

declare(strict_types=1);

namespace EasyLog\Http\Controllers;

use EasyLog\Models\AlertEvent;
use EasyLog\Models\AlertRule;
use EasyLog\Models\Workspace;
use EasyLog\Support\SensitiveMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 告警规则 CRUD + 事件查询
 *
 * 路由：
 * - GET    /workspaces/{workspace}/alert-rules             index
 * - POST   /workspaces/{workspace}/alert-rules             store
 * - GET    /workspaces/{workspace}/alert-rules/{rule}      show
 * - PUT    /workspaces/{workspace}/alert-rules/{rule}      update
 * - DELETE /workspaces/{workspace}/alert-rules/{rule}      destroy（软删）
 * - GET    /workspaces/{workspace}/alert-rules/{rule}/events
 *
 * 多租户隔离：tenant → workspace → rule 三层限定。
 */
class AlertRuleController extends Controller
{
    public function __construct()
    {
        $this->middleware('ability:alerts.view')->only(['index', 'show', 'events']);
        $this->middleware('ability:alerts.manage')->only(['store', 'update', 'destroy', 'resolveEvent']);
    }
    public function index(Request $request, string $workspace): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $query = AlertRule::with(['topic'])
            ->where('workspace_id', $ws->id)
            ->when($request->input('keyword'), fn ($q, $kw) => $q->where('name', 'like', "%{$kw}%"))
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s));

        $page     = max(1, (int) $request->input('page', 1));
        $pageSize = min(100, max(1, (int) $request->input('page_size', 20)));
        $total    = $query->count();
        $items    = $query->forPage($page, $pageSize)->get();

        return response()->json([
            'data' => $items->map(fn (AlertRule $r) => $this->serialize($r)),
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
        $validated = $this->validateRule($request);

        $rule = new AlertRule();
        $rule->uuid            = Str::uuid()->toString();
        $rule->workspace_id    = $ws->id;
        $rule->topic_id        = $validated['topic_id'];
        $rule->name            = $validated['name'];
        $rule->condition       = $validated['condition'] ?? ['metric' => 'count'];
        $rule->window_sec      = $validated['window_sec'] ?? 60;
        $rule->threshold       = $validated['threshold'] ?? 100;
        $rule->severity        = $validated['severity'] ?? 'warning';
        $rule->notify_channels = $this->normalizeChannels($validated['notify_channels'] ?? []);
        $rule->status          = $validated['status'] ?? 'active';
        $rule->save();

        return response()->json($this->serialize($rule), 201);
    }

    public function show(string $workspace, string $rule): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        return response()->json($this->serialize($this->loadRule($ws, $rule)));
    }

    public function update(Request $request, string $workspace, string $rule): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $model = $this->loadRule($ws, $rule);
        $validated = $this->validateRule($request, true);

        if (array_key_exists('notify_channels', $validated)) {
            $validated['notify_channels'] = $this->normalizeChannels($validated['notify_channels'] ?? []);
        }

        $model->fill($validated);
        $model->save();

        return response()->json($this->serialize($model));
    }

    public function destroy(string $workspace, string $rule): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $model = $this->loadRule($ws, $rule);
        $model->delete();   // 软删

        return response()->json(null, 204);
    }

    public function events(Request $request, string $workspace, string $rule): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $model = $this->loadRule($ws, $rule);

        $query = AlertEvent::where('rule_id', $model->id)
            ->when($request->input('severity'), fn ($q, $s) => $q->where('severity', $s));

        $page     = max(1, (int) $request->input('page', 1));
        $pageSize = min(100, max(1, (int) $request->input('page_size', 20)));
        $total    = $query->count();
        $items    = $query->forPage($page, $pageSize)->orderByDesc('fired_at')->get();

        return response()->json([
            'data' => $items->map(fn (AlertEvent $e) => $this->serializeEvent($e)),
            'meta' => [
                'total'     => $total,
                'page'      => $page,
                'page_size' => $pageSize,
            ],
        ]);
    }

    /**
     * 标记事件已处理（设置 resolved_at）
     * POST /workspaces/{workspace}/alert-rules/{rule}/events/{event}/resolve
     */
    public function resolveEvent(Request $request, string $workspace, string $rule, string $event): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $ruleModel = $this->loadRule($ws, $rule);

        $eventModel = AlertEvent::where('uuid', $event)
            ->where('rule_id', $ruleModel->id)
            ->first();
        if (!$eventModel) {
            abort(404, 'Alert event not found');
        }

        if (!$eventModel->resolved_at) {
            $eventModel->resolved_at = now();
            $eventModel->save();
        }

        return response()->json($this->serializeEvent($eventModel));
    }

    private function serializeEvent(AlertEvent $e): array
    {
        return [
            'uuid'          => $e->uuid,
            'rule_id'       => $e->rule_id,
            'fired_at'      => $e->fired_at?->toISOString(),
            'resolved_at'   => $e->resolved_at?->toISOString(),
            'severity'      => $e->severity,
            'context'       => $e->context,
            'notify_status' => $e->notify_status,
        ];
    }

    private function validateRule(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name'             => ($partial ? 'sometimes|' : 'required|') . 'string|max:64',
            'topic_id'         => ($partial ? 'sometimes|' : 'required|') . 'integer|exists:topics,id',
            'condition'        => 'nullable|array',
            'window_sec'       => 'nullable|integer|min:1|max:86400',
            'threshold'        => 'nullable|integer|min:0',
            'severity'         => 'sometimes|in:info,warning,critical',
            'notify_channels'  => 'nullable|array',
            'status'           => 'sometimes|in:active,paused',
        ]);
    }

    /**
     * 渠道归一化：兼容字符串简写 ['webhook', 'email'] →
     * 对象结构 [{'type' => 'webhook', 'config' => []}, ...]（AlertNotifier 依赖）
     */
    private function normalizeChannels(mixed $channels): array
    {
        if (!is_array($channels)) {
            return [];
        }
        return array_values(array_map(
            fn ($c) => is_array($c)
                ? ['type' => (string) ($c['type'] ?? 'webhook'), 'config' => (array) ($c['config'] ?? [])]
                : ['type' => (string) $c, 'config' => []],
            $channels
        ));
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

    private function loadRule(Workspace $ws, string $ruleUuid): AlertRule
    {
        $rule = AlertRule::where('uuid', $ruleUuid)
            ->where('workspace_id', $ws->id)
            ->first();
        if (!$rule) {
            abort(404, 'Alert rule not found');
        }
        return $rule;
    }

    private function serialize(AlertRule $r): array
    {
        return [
            'uuid'            => $r->uuid,
            'workspace_uuid'  => $r->workspace?->uuid,
            'topic_id'        => $r->topic_id,
            'topic_uuid'      => $r->topic?->uuid,
            'name'            => $r->name,
            'condition'       => $r->condition,
            'window_sec'      => $r->window_sec,
            'threshold'       => $r->threshold,
            'severity'        => $r->severity,
            // 出网前脱敏：notify_channels 含 webhook url / SMTP 口令
            'notify_channels' => SensitiveMasker::mask($r->notify_channels ?? []),
            'status'          => $r->status,
            'last_fired_at'   => $r->last_fired_at?->toISOString(),
            'created_at'      => $r->created_at?->toISOString(),
            'updated_at'      => $r->updated_at?->toISOString(),
        ];
    }
}
