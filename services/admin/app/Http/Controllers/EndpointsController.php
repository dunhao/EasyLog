<?php

declare(strict_types=1);

namespace EasyLog\Http\Controllers;

use EasyLog\Models\IngestionEndpoint;
use EasyLog\Models\Workspace;
use EasyLog\Services\MQ\Consumers\ConsumerFactory;
use EasyLog\Support\SensitiveMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * 接入点 CRUD（日志来源的配置单元 + 凭证）
 *
 * 路由（嵌套在 workspace 下，避免 shallow 兼容歧义）：
 * - GET    /workspaces/{workspace}/endpoints                       index
 * - POST   /workspaces/{workspace}/endpoints                       store
 * - GET    /workspaces/{workspace}/endpoints/{endpoint}            show
 * - PUT    /workspaces/{workspace}/endpoints/{endpoint}            update
 * - DELETE /workspaces/{workspace}/endpoints/{endpoint}            destroy（软删）
 * - POST   /workspaces/{workspace}/endpoints/{endpoint}/test-connection
 * - POST   /workspaces/{workspace}/endpoints/{endpoint}/rotate-secret
 *
 * 多租户隔离：tenant → workspace → endpoint 三层限定。
 * app_secret 仅 store/rotate-secret 时明文返回一次，库中只存 bcrypt 哈希。
 */
class EndpointsController extends Controller
{
    public function __construct()
    {
        // testConnection 会用 config 里的凭据去连外部 MQ，等同写操作
        $this->middleware('ability:endpoints.view')->only(['index', 'show']);
        $this->middleware('ability:endpoints.create')->only(['store']);
        $this->middleware('ability:endpoints.update')->only(['update', 'rotateSecret', 'testConnection']);
        $this->middleware('ability:endpoints.delete')->only(['destroy']);
    }
    public function index(Request $request, string $workspace): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $query = IngestionEndpoint::where('workspace_id', $ws->id)
            ->when($request->input('keyword'), function ($q, $kw) {
                $q->where('name', 'like', "%{$kw}%");
            })
            ->when($request->input('source_type'), fn ($q, $t) => $q->where('source_type', $t));

        $page     = max(1, (int) $request->input('page', 1));
        $pageSize = min(100, max(1, (int) $request->input('page_size', 20)));
        $total    = $query->count();
        $items    = $query->forPage($page, $pageSize)->get();

        return response()->json([
            'data' => $items->map(fn (IngestionEndpoint $e) => $this->serialize($e)),
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
        $validated = $this->validateEndpoint($request);

        $config = $validated['config'] ?? [];
        if (in_array($validated['source_type'], ['kafka', 'rabbitmq', 'redis', 'activemq'], true)) {
            $this->validateConsumerConfig($validated['source_type'], $config);
        }

        $secret = Str::random(40);

        $endpoint = new IngestionEndpoint();
        $endpoint->uuid              = Str::uuid()->toString();
        $endpoint->workspace_id      = $ws->id;
        $endpoint->topic_id          = $validated['topic_id'];
        $endpoint->name              = $validated['name'];
        $endpoint->source_type       = $validated['source_type'];
        $endpoint->config            = $config;
        $endpoint->app_id            = 'app_' . Str::lower(Str::random(10));
        $endpoint->app_secret_hash   = bcrypt($secret);
        $endpoint->rate_limit_per_sec = (int) ($validated['rate_limit_per_sec'] ?? 1000);
        $endpoint->daily_quota_gb    = (int) ($validated['daily_quota_gb'] ?? 0);   // 0 = 不限
        $endpoint->status            = $validated['status'] ?? 'active';
        $endpoint->save();

        return response()->json($this->serialize($endpoint) + ['app_secret' => $secret], 201);
    }

    public function show(string $workspace, string $endpoint): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        return response()->json($this->serialize($this->loadEndpoint($ws, $endpoint)));
    }

    public function update(Request $request, string $workspace, string $endpoint): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $model = $this->loadEndpoint($ws, $endpoint);
        $validated = $this->validateEndpoint($request, true);

        if (isset($validated['source_type'])) {
            $config = $validated['config'] ?? $model->config;
            if (in_array($validated['source_type'], ['kafka', 'rabbitmq', 'redis', 'activemq'], true)) {
                $this->validateConsumerConfig($validated['source_type'], $config);
            }
        }

        // daily_quota_gb 显式传 null 时还原为 0（列 NOT NULL，0 = 不限）
        if (array_key_exists('daily_quota_gb', $validated)) {
            $validated['daily_quota_gb'] = (int) ($validated['daily_quota_gb'] ?? 0);
        }

        $model->fill($validated);
        $model->save();

        return response()->json($this->serialize($model));
    }

    public function destroy(string $workspace, string $endpoint): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $model = $this->loadEndpoint($ws, $endpoint);
        $model->delete();   // 软删

        return response()->json(null, 204);
    }

    public function testConnection(string $workspace, string $endpoint): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $model = $this->loadEndpoint($ws, $endpoint);

        try {
            $consumer = ConsumerFactory::make($model->source_type);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        $config = $model->config ?? [];
        $errors = $consumer->validateConfig($config);
        if (!empty($errors)) {
            return response()->json(['success' => false, 'message' => implode('; ', $errors)], 400);
        }

        $result = $consumer->testConnection($config);
        return response()->json($result, $result['success'] ? 200 : 400);
    }

    public function rotateSecret(string $workspace, string $endpoint): JsonResponse
    {
        $ws = $this->resolveWorkspace($workspace);
        $model = $this->loadEndpoint($ws, $endpoint);

        $secret = Str::random(40);
        $model->app_secret_hash = bcrypt($secret);
        $model->save();

        Log::info('EasyLog endpoint secret rotated', ['endpoint' => $model->uuid]);
        return response()->json(['app_id' => $model->app_id, 'app_secret' => $secret]);
    }

    /**
     * 端点校验规则（store 必填 / update 可选）
     */
    private function validateEndpoint(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name'               => ($partial ? 'sometimes|' : 'required|') . 'string|max:64',
            'source_type'        => ($partial ? 'sometimes|' : 'required|') . 'in:http,sdk,rabbitmq,kafka,redis,activemq,syslog,filebeat',
            'topic_id'           => ($partial ? 'sometimes|' : 'required|') . 'integer|exists:topics,id',
            'config'             => 'nullable|array',
            'rate_limit_per_sec' => 'nullable|integer|min:1|max:100000',
            'daily_quota_gb'     => 'nullable|integer|min:0|max:10240',
            'status'             => 'sometimes|in:active,disabled',
        ]);
    }

    /**
     * MQ 类型接入点的 config 必填项校验（委托给对应消费者实现）
     */
    private function validateConsumerConfig(string $sourceType, array $config): void
    {
        $errors = ConsumerFactory::make($sourceType)->validateConfig($config);
        if (!empty($errors)) {
            abort(422, implode('; ', $errors));
        }
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

    private function loadEndpoint(Workspace $ws, string $endpointUuid): IngestionEndpoint
    {
        $endpoint = IngestionEndpoint::where('uuid', $endpointUuid)
            ->where('workspace_id', $ws->id)
            ->first();
        if (!$endpoint) {
            abort(404, 'Endpoint not found');
        }
        return $endpoint;
    }

    /**
     * 序列化输出（不暴露 app_secret_hash）
     */
    private function serialize(IngestionEndpoint $e): array
    {
        return [
            'uuid'              => $e->uuid,
            'workspace_uuid'    => $e->workspace?->uuid,
            'topic_id'          => $e->topic_id,
            'topic_uuid'        => $e->topic?->uuid,
            'name'              => $e->name,
            'source_type'       => $e->source_type,
            // 出网前脱敏：config 含 MQ / SMTP 连接凭据，不回显明文
            'config'            => SensitiveMasker::mask($e->config ?? []),
            'app_id'            => $e->app_id,
            'rate_limit_per_sec'=> $e->rate_limit_per_sec,
            'daily_quota_gb'    => $e->daily_quota_gb,
            'status'            => $e->status,
            'created_at'        => $e->created_at?->toISOString(),
            'updated_at'        => $e->updated_at?->toISOString(),
        ];
    }
}
