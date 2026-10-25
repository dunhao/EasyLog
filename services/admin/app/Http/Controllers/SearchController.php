<?php

declare(strict_types=1);

namespace EasyLog\Http\Controllers;

use EasyLog\Facades\StorageAdapter;
use EasyLog\Models\Topic;
use EasyLog\Models\Workspace;
use EasyLog\Services\Query\QueryTranslator;
use EasyLog\Services\Query\SqlParseException;
use EasyLog\Services\Query\SqlQueryTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 日志检索 API
 *
 * 路由：
 * - POST /api/v1/workspaces/{workspace}/search         跨主题联邦检索
 * - POST /api/v1/workspaces/{workspace}/topics/{topic}/search    主题内检索（注：当前路由是 /topics/{topic}/search，与 workspace 关联通过鉴权上下文）
 * - POST /api/v1/topics/{topic}/search                 主题内检索（实际路由）
 * - POST /api/v1/topics/{topic}/histogram              时间分布直方图
 * - POST /api/v1/topics/{topic}/agg                   聚合分析
 *
 * 多租户隔离强约束：
 * 1. workspace/topic 鉴权：当前用户必须属于 workspace 所属 tenant
 * 2. 索引模式构造：仅按 workspace_uuid 限定，索引名格式 easylog-{ws_uuid}-*
 * 3. DSL 注入 workspace_id filter
 * 4. 结果二次过滤：丢弃任何 workspace_id 不匹配的文档（防止 ES 索引配置错误导致越权）
 */
class SearchController extends Controller
{
    private QueryTranslator $translator;

    public function __construct(QueryTranslator $translator = null)
    {
        $this->translator = $translator ?? new QueryTranslator();

        // 检索是任何登录用户的基础能力，但仍需显式授权点，避免未授权账号直接读日志
        $this->middleware('ability:search.query')->only(['search', 'searchTopic', 'histogram', 'aggregate']);
    }

    /**
     * 测试注入 translator
     */
    public function setTranslator(QueryTranslator $t): void
    {
        $this->translator = $t;
    }

    /**
     * 跨主题联邦检索
     * POST /api/v1/workspaces/{workspace}/search
     */
    public function search(Request $request, string $workspace): JsonResponse
    {
        $validated = $this->validateSearchRequest($request);

        // 鉴权 + 解析 workspace
        $ws = $this->resolveWorkspace($request, $workspace);

        // 构造索引模式（跨主题）：easylog-{workspace_uuid}-*
        $indexPattern = $this->indexPattern($ws->uuid);

        // 构造 DSL（Lucene 或 SQL），强制注入 workspace_id 隔离
        $parsed = $this->buildQueryDsl($validated);
        $dsl = $parsed['dsl'];
        $dsl['query']['bool']['filter'][] = ['term' => ['workspace_id' => (string) $ws->id]];

        // SQL 聚合查询（count/avg/GROUP BY …）
        if ($parsed['aggregate']) {
            try {
                $agg = StorageAdapter::aggregate($indexPattern, $dsl);
                $result = $agg['result'] ?? [];
            } catch (\Throwable $e) {
                Log::error('EasyLog SQL aggregate failed', ['error' => $e->getMessage()]);
                $result = [];
            }
            $total = isset($result['value']) ? (int) $result['value'] : count($result['buckets'] ?? []);
            return response()->json([
                'total'        => $total,
                'hits'         => [],
                'took_ms'      => 0,
                'query'        => $validated['query'],
                'aggregations' => $result,
            ]);
        }

        // 可选 topic uuid 过滤
        if (!empty($validated['topics'])) {
            $topicIds = Topic::whereIn('uuid', $validated['topics'])
                ->where('workspace_id', $ws->id)
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();
            if (!empty($topicIds)) {
                $dsl['query']['bool']['filter'][] = ['terms' => ['topic_id' => $topicIds]];
            }
        }

        try {
            $result = StorageAdapter::search($indexPattern, $dsl);
        } catch (\Throwable $e) {
            Log::error('EasyLog search failed', [
                'workspace' => $ws->uuid,
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'total' => 0, 'hits' => [], 'took_ms' => 0,
                'error' => 'search failed',
            ], 500);
        }

        // 二次过滤：剔除 workspace_id 不匹配的文档（防越权）
        $hits = array_values(array_filter(
            $result['hits'] ?? [],
            fn ($h) => (string) ($h['workspace_id'] ?? '') === (string) $ws->id
        ));

        return response()->json([
            'total'    => $result['total'] ?? count($hits),
            'hits'     => $hits,
            'took_ms'  => $result['took_ms'] ?? 0,
            'query'    => $validated['query'],
        ]);
    }

    /**
     * 主题内检索
     * POST /api/v1/topics/{topic}/search
     */
    public function searchTopic(Request $request, string $topic): JsonResponse
    {
        $validated = $this->validateSearchRequest($request);

        [$ws, $topicModel] = $this->resolveWorkspaceAndTopic($request, $topic);

        $indexPattern = $this->indexPattern($ws->uuid, $topicModel->uuid);

        $parsed = $this->buildQueryDsl($validated);
        $dsl = $parsed['dsl'];
        // 强制注入 workspace_id + topic_id 双重隔离
        $dsl['query']['bool']['filter'][] = ['term' => ['workspace_id' => (string) $ws->id]];
        $dsl['query']['bool']['filter'][] = ['term' => ['topic_id'     => (string) $topicModel->id]];

        if ($parsed['aggregate']) {
            try {
                $agg = StorageAdapter::aggregate($indexPattern, $dsl);
                $result = $agg['result'] ?? [];
            } catch (\Throwable $e) {
                Log::error('EasyLog SQL aggregate failed', ['error' => $e->getMessage()]);
                $result = [];
            }
            $total = isset($result['value']) ? (int) $result['value'] : count($result['buckets'] ?? []);
            return response()->json([
                'total'        => $total,
                'hits'         => [],
                'took_ms'      => 0,
                'aggregations' => $result,
            ]);
        }

        try {
            $result = StorageAdapter::search($indexPattern, $dsl);
        } catch (\Throwable $e) {
            Log::error('EasyLog searchTopic failed', [
                'workspace' => $ws->uuid,
                'topic'     => $topicModel->uuid,
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'total' => 0, 'hits' => [], 'took_ms' => 0,
                'error' => 'search failed',
            ], 500);
        }

        // 二次过滤
        $hits = array_values(array_filter(
            $result['hits'] ?? [],
            fn ($h) => (string) ($h['workspace_id'] ?? '') === (string) $ws->id
                && (string) ($h['topic_id'] ?? '') === (string) $topicModel->id
        ));

        return response()->json([
            'total'    => $result['total'] ?? count($hits),
            'hits'     => $hits,
            'took_ms'  => $result['took_ms'] ?? 0,
        ]);
    }

    /**
     * 时间分布直方图
     * POST /api/v1/topics/{topic}/histogram
     */
    public function histogram(Request $request, string $topic): JsonResponse
    {
        $validated = $request->validate([
            'query'  => 'nullable|string|max:2048',
            'from'   => 'required|date',
            'to'     => 'required|date|after:from',
            'bucket' => 'nullable|in:minute,hour,day',
        ]);

        [$ws, $topicModel] = $this->resolveWorkspaceAndTopic($request, $topic);

        $indexPattern = $this->indexPattern($ws->uuid, $topicModel->uuid);
        $query = trim((string) ($validated['query'] ?? ''));
        $must = $this->mustClauses($query, $validated);
        $dsl = $this->translator->histogram('', array_merge($validated, ['bucket' => $validated['bucket'] ?? 'minute']));
        $dsl['query']['bool']['must'] = $must;
        $dsl['query']['bool']['filter'][] = ['term' => ['workspace_id' => (string) $ws->id]];
        $dsl['query']['bool']['filter'][] = ['term' => ['topic_id'     => (string) $topicModel->id]];

        try {
            $agg = StorageAdapter::aggregate($indexPattern, $dsl);
            $buckets = $agg['histogram']['buckets'] ?? [];
        } catch (\Throwable $e) {
            Log::error('EasyLog histogram failed', ['error' => $e->getMessage()]);
            $buckets = [];
        }

        return response()->json([
            'buckets'  => $buckets,
            'interval' => $validated['bucket'] ?? 'minute',
        ]);
    }

    /**
     * 聚合分析
     * POST /api/v1/topics/{topic}/agg
     */
    public function aggregate(Request $request, string $topic): JsonResponse
    {
        $validated = $request->validate([
            'query'    => 'nullable|string|max:2048',
            'from'     => 'required|date',
            'to'       => 'required|date|after:from',
            'agg_type' => 'required|in:terms,date_histogram,avg,sum,max,min,cardinality',
            'field'    => 'required|string|max:64',
            'size'     => 'nullable|integer|min:1|max:500',
            'bucket'   => 'nullable|string|max:8',
        ]);

        [$ws, $topicModel] = $this->resolveWorkspaceAndTopic($request, $topic);

        $indexPattern = $this->indexPattern($ws->uuid, $topicModel->uuid);
        $query = trim((string) ($validated['query'] ?? ''));
        $must = $this->mustClauses($query, $validated);
        $dsl = $this->translator->aggregate('', $validated);
        $dsl['query']['bool']['must'] = $must;
        $dsl['query']['bool']['filter'][] = ['term' => ['workspace_id' => (string) $ws->id]];
        $dsl['query']['bool']['filter'][] = ['term' => ['topic_id'     => (string) $topicModel->id]];

        try {
            $agg = StorageAdapter::aggregate($indexPattern, $dsl);
            $result = $agg['result'] ?? [];
        } catch (\Throwable $e) {
            Log::error('EasyLog aggregate failed', ['error' => $e->getMessage()]);
            $result = [];
        }

        return response()->json([
            'aggregations' => $result,
        ]);
    }

    /**
     * 查询串 → DSL：SELECT 开头走 SQL 转译，否则 Lucene
     *
     * @param array<string,mixed> $validated
     * @return array{dsl:array<string,mixed>, aggregate:bool}
     */
    private function buildQueryDsl(array $validated): array
    {
        $query = trim((string) $validated['query']);
        if (SqlQueryTranslator::looksLikeSql($query)) {
            try {
                return (new SqlQueryTranslator())->translate($query, $validated);
            } catch (SqlParseException $e) {
                abort(422, "SQL 查询解析失败：{$e->getMessage()}");
            }
        }
        return ['dsl' => $this->translator->translate($query, $validated), 'aggregate' => false];
    }

    /**
     * 查询串 → must 子句（histogram / aggregate 入口用，支持 SQL 过滤）
     *
     * @param array<string,mixed> $validated
     * @return array<int,array<string,mixed>>
     */
    private function mustClauses(string $query, array $validated): array
    {
        if (SqlQueryTranslator::looksLikeSql($query)) {
            try {
                return (new SqlQueryTranslator())->mustClauses($query);
            } catch (SqlParseException $e) {
                abort(422, "SQL 查询解析失败：{$e->getMessage()}");
            }
        }
        return $this->translator->buildMust($query);
    }

    /**
     * 通用搜索请求校验
     */
    private function validateSearchRequest(Request $request): array
    {
        return $request->validate([
            'query'  => 'required|string|max:2048',
            'from'   => 'required|date',
            'to'     => 'required|date|after:from',
            'size'   => 'nullable|integer|min:1|max:1000',
            'sort'   => 'nullable|array',
            'fields' => 'nullable|array',
            'topics' => 'nullable|array',
        ]);
    }

    /**
     * 解析当前 workspace（鉴权）
     */
    private function resolveWorkspace(Request $request, string $workspaceUuid): Workspace
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

    /**
     * 解析 workspace + topic（鉴权 + 强隔离）
     */
    private function resolveWorkspaceAndTopic(Request $request, string $topicUuid): array
    {
        $tenantId = app('easylog.tenant_id');
        $topic = Topic::where('uuid', $topicUuid)
            ->where('status', 'active')
            ->first();
        if (!$topic) {
            abort(404, 'Topic not found');
        }

        $ws = Workspace::where('id', $topic->workspace_id)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('status', 'active')
            ->first();
        if (!$ws) {
            abort(403, 'Topic not in your workspace');
        }
        return [$ws, $topic];
    }

    /**
     * 构造 ES 索引模式
     * - 跨主题：easylog-{ws_uuid}-*
     * - 主题内：easylog-{ws_uuid}-{topic_uuid}-*
     */
    private function indexPattern(string $workspaceUuid, ?string $topicUuid = null): string
    {
        $prefix = config('storage.index_prefix', 'easylog-');
        if ($topicUuid) {
            return $prefix . $workspaceUuid . '-' . $topicUuid . '-*';
        }
        return $prefix . $workspaceUuid . '-*';
    }
}
