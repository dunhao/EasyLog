<?php

declare(strict_types=1);

namespace EasyLog\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use EasyLog\Models\IngestionEndpoint;
use EasyLog\Models\Workspace;
use EasyLog\Models\Topic;

/**
 * 多租户上下文注入中间件
 *
 * 处理两类请求：
 *
 * 1. 业务方接入请求（/api/v1/ingest/*）：
 *    通过 X-EasyLog-App / Authorization: Bearer 头解析接入点 →
 *    注入 tenant_id / workspace_id / topic_id / endpoint 上下文。
 *    X-EasyLog-Topic 头（可选）覆盖 endpoint 默认 topic。
 *
 * 2. 控制台请求（其它 /api/v1/*）：
 *    通过 JWT（auth:sanctum）解析 user → tenant_id；
 *    通过 URL 参数 workspace uuid 解析 workspace_id。
 *
 * 注入的容器单例：
 * - easylog.tenant_id
 * - easylog.workspace_id
 * - easylog.topic_id
 * - easylog.endpoint  (IngestionEndpoint 实例，仅接入请求)
 *
 * 所有 Eloquent 全局 scope 通过这些容器单例实现强隔离。
 */
class ResolveTenantContext
{
    public function handle(Request $request, Closure $next): mixed
    {
        // /api/v1/ingest/* 接入路径走 AppID 解析
        if ($request->is('api/v1/ingest*') && !$request->is('api/v1/ingest/heartbeat')) {
            $this->resolveFromAppId($request);
        } else {
            // 控制台路径走 JWT
            $this->resolveFromJwt($request);
        }

        return $next($request);
    }

    /**
     * 接入路径：通过 X-EasyLog-App + Bearer 鉴权接入点
     */
    private function resolveFromAppId(Request $request): void
    {
        $appId = $request->header('X-EasyLog-App');
        if (!$appId) {
            abort(401, 'Missing X-EasyLog-App header');
        }

        /** @var IngestionEndpoint|null $endpoint */
        $endpoint = IngestionEndpoint::where('app_id', $appId)
            ->where('status', 'active')
            ->first();

        if (!$endpoint) {
            abort(401, 'Invalid app_id');
        }

        // 校验 app_secret：Authorization: Bearer <secret> vs app_secret_hash (bcrypt)
        $secret = $this->extractBearer($request);
        if ($secret === null) {
            abort(401, 'Missing app_secret');
        }
        if (!password_verify($secret, $endpoint->app_secret_hash)) {
            abort(401, 'Invalid app_secret');
        }

        // 校验 endpoint 启用状态
        if ($endpoint->status !== 'active') {
            abort(403, 'Endpoint disabled');
        }

        // 校验 topic 启用状态（默认走 endpoint 配置的 topic，允许 X-EasyLog-Topic 覆盖到同 workspace 的其它 topic）
        $topic = $endpoint->topic;
        $topicHeader = $request->header('X-EasyLog-Topic');
        if ($topicHeader) {
            $override = Topic::where('workspace_id', $endpoint->workspace_id)
                ->where('uuid', $topicHeader)
                ->where('status', 'active')
                ->first();
            if (!$override) {
                abort(400, "Topic {$topicHeader} not found in workspace");
            }
            $topic = $override;
        }

        if (!$topic) {
            abort(500, 'Endpoint has no associated topic');
        }

        $workspace = $endpoint->workspace;
        if (!$workspace || $workspace->status !== 'active') {
            abort(403, 'Workspace disabled');
        }

        // 注入容器（供 Controller / Eloquent scope 使用）
        app()->forgetInstance('easylog.tenant_id');
        app()->forgetInstance('easylog.workspace_id');
        app()->forgetInstance('easylog.topic_id');
        app()->forgetInstance('easylog.endpoint');
        app()->instance('easylog.tenant_id', $workspace->tenant_id);
        app()->instance('easylog.workspace_id', $workspace->id);
        app()->instance('easylog.topic_id', $topic->id);
        app()->instance('easylog.endpoint', $endpoint);

        // 也在 request attributes 上挂一份，便于 Controller 直接取
        $request->attributes->set('easylog.endpoint', $endpoint);
        $request->attributes->set('easylog.workspace', $workspace);
        $request->attributes->set('easylog.topic', $topic);
    }

    /**
     * 控制台路径：通过 JWT 解析 user → tenant
     * URL 中的 workspace uuid 由具体 Controller 二次解析（避免本中间件耦合路由）
     */
    private function resolveFromJwt(Request $request): void
    {
        $user = $request->user();
        if ($user) {
            app()->forgetInstance('easylog.tenant_id');
            app()->instance('easylog.tenant_id', $user->tenant_id);
        }
        // workspace_id 在 Controller 内根据路由参数 {workspace} 解析
    }

    /**
     * 从 Authorization 头取 Bearer token
     */
    private function extractBearer(Request $request): ?string
    {
        $auth = $request->header('Authorization');
        if (!$auth) return null;
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
