<?php

declare(strict_types=1);

namespace EasyLog\Http\Controllers;

use EasyLog\Facades\MessageQueue;
use EasyLog\Models\IngestionEndpoint;
use EasyLog\Models\Topic;
use EasyLog\Models\Workspace;
use EasyLog\Services\Usage\UsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * 日志接入 API（业务方通过 SDK / HTTP 直推调用）
 * 鉴权：AppID + AppSecret（通过 X-EasyLog-App / Authorization 头）
 *
 * 路由：/api/v1/ingest/{,/batch,/heartbeat}
 */
class IngestController extends Controller
{
    /**
     * 单条日志写入
     * POST /api/v1/ingest
     *
     * Body：
     *   msg    string  必填
     *   level  string  DEBUG|INFO|WARN|ERROR|FATAL|TRACE
     *   ts     string  ISO8601，缺省用服务端接收时间
     *   其它字段  业务自定义字段
     *
     * Headers：
     *   X-EasyLog-App: <app_id>
     *   Authorization: Bearer <app_secret>
     *   X-EasyLog-Topic: <topic_uuid>   可选，覆盖 endpoint 默认 topic
     */
    public function single(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'msg'   => 'required|string|max:65535',
            'level' => ['nullable', 'string', Rule::in(['DEBUG', 'INFO', 'WARN', 'ERROR', 'FATAL', 'TRACE'])],
            'ts'    => 'nullable|date',
            '*'     => 'nullable',
        ]);

        /** @var IngestionEndpoint $endpoint */
        $endpoint = $request->attributes->get('easylog.endpoint');
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('easylog.workspace');
        /** @var Topic $topic */
        $topic = $request->attributes->get('easylog.topic');

        app(UsageService::class)->assertQuota($endpoint);

        $entry = $this->normalize(
            $validated,
            $workspace->tenant_id,
            $workspace->id,
            $topic->id,
            $workspace->uuid,
            $topic->uuid,
            $endpoint->uuid
        );

        try {
            MessageQueue::publishLog(
                $workspace->id,
                $topic->id,
                $workspace->uuid,
                $topic->uuid,
                $entry,
                ['request_id' => $this->requestId()]
            );
        } catch (\Throwable $e) {
            Log::error('EasyLog ingest single publish failed', [
                'workspace' => $workspace->uuid,
                'topic'     => $topic->uuid,
                'error'     => $e->getMessage(),
            ]);
            // 不让业务方感知投递失败，但返回 202 + rejected=1
            return response()->json([
                'accepted'   => 0,
                'rejected'   => 1,
                'request_id' => $this->requestId(),
                'error'      => 'publish failed',
            ], 202);
        }

        app(UsageService::class)->record($endpoint, strlen(json_encode($entry)), 1);

        return response()->json([
            'accepted'   => 1,
            'rejected'   => 0,
            'request_id' => $this->requestId(),
        ], 202);
    }

    /**
     * 批量日志写入
     * POST /api/v1/ingest/batch
     *
     * Body：
     *   logs array  最多 10000 条
     *   logs.*.msg    string 必填
     *   logs.*.level  string 可选
     *   logs.*.ts     string 可选
     */
    public function batch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'logs'         => 'required|array|max:10000',
            'logs.*.msg'   => 'required|string|max:65535',
            'logs.*.level' => ['nullable', 'string', Rule::in(['DEBUG', 'INFO', 'WARN', 'ERROR', 'FATAL', 'TRACE'])],
            'logs.*.ts'    => 'nullable|date',
            // 放行业务自定义字段（对齐 single 的 '*' => 'nullable'，否则批量行的
            // service/duration_ms 等字段会在验证阶段被丢弃）
            'logs.*.*'     => 'nullable',
        ]);

        /** @var IngestionEndpoint $endpoint */
        $endpoint = $request->attributes->get('easylog.endpoint');
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('easylog.workspace');
        /** @var Topic $topic */
        $topic = $request->attributes->get('easylog.topic');

        app(UsageService::class)->assertQuota($endpoint);

        $accepted = 0;
        $rejected = 0;
        $batchPayload = [];
        $usageBytes = 0;

        foreach ($validated['logs'] as $row) {
            try {
                $entry = $this->normalize(
                    $row,
                    $workspace->tenant_id,
                    $workspace->id,
                    $topic->id,
                    $workspace->uuid,
                    $topic->uuid,
                    $endpoint->uuid
                );
                $batchPayload[] = [
                    'workspace_id'   => $workspace->id,
                    'topic_id'       => $topic->id,
                    'workspace_uuid' => $workspace->uuid,
                    'topic_uuid'     => $topic->uuid,
                    'entry'          => $entry,
                    'headers'        => ['request_id' => $this->requestId()],
                ];
                $usageBytes += strlen(json_encode($entry));
                $accepted++;
            } catch (\Throwable $e) {
                $rejected++;
                Log::warning('EasyLog ingest batch normalize failed', [
                    'workspace' => $workspace->uuid,
                    'topic'     => $topic->uuid,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        if (!empty($batchPayload)) {
            try {
                MessageQueue::batchProduce($batchPayload);
            } catch (\Throwable $e) {
                Log::error('EasyLog ingest batch publish failed', [
                    'workspace' => $workspace->uuid,
                    'topic'     => $topic->uuid,
                    'error'     => $e->getMessage(),
                ]);
                return response()->json([
                    'accepted'   => 0,
                    'rejected'   => $accepted,
                    'request_id' => $this->requestId(),
                    'error'      => 'publish failed',
                ], 202);
            }
        }

        if ($accepted > 0) {
            app(UsageService::class)->record($endpoint, $usageBytes, $accepted);
        }

        return response()->json([
            'accepted'   => $accepted,
            'rejected'   => $rejected,
            'request_id' => $this->requestId(),
        ], 202);
    }

    /**
     * 心跳健康检查（SDK 探活用）
     * GET /api/v1/ingest/heartbeat
     */
    public function heartbeat(Request $request): JsonResponse
    {
        return response()->json([
            'status'  => 'ok',
            'service' => 'easylog-ingestion',
            'time'    => now()->toISOString(),
        ]);
    }

    /**
     * 归一化日志条目：
     * - 移除 Laravel validator 注入的非业务字段
     * - 补齐 _tenant_id / _workspace_id / _topic_id / _endpoint_id / _received_at（带下划线避免与业务字段冲突）
     * - level 默认 INFO，ts 默认服务端时间
     *
     * @param array<string,mixed> $row 原始日志行
     * @return array<string,mixed>
     */
    private function normalize(
        array $row,
        int $tenantId,
        int $workspaceId,
        int $topicId,
        string $workspaceUuid,
        string $topicUuid,
        string $endpointUuid
    ): array {
        $receivedAt = now()->toISOString();

        $entry = [
            // 路由元数据（带下划线前缀避免与业务字段冲突，Writer 侧会剔除）
            '_tenant_id'     => $tenantId,
            '_workspace_id'  => $workspaceId,
            '_workspace_uuid'=> $workspaceUuid,
            '_topic_id'      => $topicId,
            '_topic_uuid'    => $topicUuid,
            '_endpoint_uuid' => $endpointUuid,
            '_received_at'   => $receivedAt,

            // 业务字段
            'msg'   => $row['msg'],
            'level' => strtoupper((string) ($row['level'] ?? 'INFO')),
            'ts'    => $row['ts'] ?? $receivedAt,
        ];

        // 业务自定义字段（除 msg/level/ts 外的所有 key）
        foreach ($row as $k => $v) {
            if (in_array($k, ['msg', 'level', 'ts'], true)) continue;
            if (str_starts_with($k, '_')) continue;
            $entry[$k] = $v;
        }

        return $entry;
    }

    /**
     * 生成请求追踪 ID：优先透传业务方 X-Request-ID，否则生成 UUID
     */
    private function requestId(): string
    {
        $header = request()->header('X-Request-ID');
        return $header ?: (string) Str::uuid();
    }
}
