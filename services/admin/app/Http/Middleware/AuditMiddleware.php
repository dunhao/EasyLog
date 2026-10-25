<?php

declare(strict_types=1);

namespace EasyLog\Http\Middleware;

use Closure;
use EasyLog\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 管理操作审计中间件
 *
 * 记录管理 API 的成功写操作（POST/PUT/PATCH/DELETE → 2xx/3xx）。
 * action 取路由名（如 workspaces.store / endpoints.rotate-secret），
 * target 从路由参数（uuid）提取，context 记录请求摘要。
 *
 * 仅挂载在管理路由组（auth:sanctum），不记录 ingest 业务流量。
 */
class AuditMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (!$this->shouldRecord($request, $response)) {
            return $response;
        }

        try {
            AuditLog::create([
                'tenant_id'   => app('easylog.tenant_id'),
                'user_id'     => $request->user()?->id,
                'action'      => $request->route()?->getName() ?? $request->path(),
                'target_type' => $this->targetType($request),
                'target_id'   => $this->targetId($request),
                'context'     => $this->context($request, $response),
                'ip'          => $request->ip(),
                'user_agent'  => mb_substr((string) $request->userAgent(), 0, 255),
            ]);
        } catch (\Throwable $e) {
            Log::warning('EasyLog audit write failed', ['error' => $e->getMessage()]);
        }

        return $response;
    }

    private function shouldRecord(Request $request, mixed $response): bool
    {
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }
        $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
        if ($status < 200 || $status >= 400) {
            return false;
        }
        // 无登录用户的管理操作不记录（如健康检查路径）
        return $request->user() !== null;
    }

    /**
     * 目标类型：从路由参数 key 推断（workspace → workspace）
     */
    private function targetType(Request $request): ?string
    {
        $params = $request->route()?->parameters() ?? [];
        foreach (['workspace', 'endpoint', 'rule', 'alert_rule', 'topic', 'group', 'field', 'task'] as $key) {
            if (isset($params[$key])) {
                return $key;
            }
        }
        return null;
    }

    private function targetId(Request $request): ?string
    {
        $params = $request->route()?->parameters() ?? [];
        foreach (array_reverse($params) as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    private function context(Request $request, mixed $response): array
    {
        $body = $request->getContent();
        $decoded = json_decode((string) $body, true);

        return [
            'method' => $request->method(),
            'path'   => $request->path(),
            'status' => method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null,
            // 请求体摘要：截断并排除敏感字段
            'body'   => is_array($decoded)
                ? $this->sanitize($decoded)
                : mb_substr((string) $body, 0, 500),
        ];
    }

    /**
     * 剔除请求体中的敏感字段
     */
    private function sanitize(array $body): array
    {
        $sensitive = ['app_secret', 'auth_token', 'password', 'app_secret_hash'];
        foreach ($body as $key => $value) {
            if (in_array($key, $sensitive, true)) {
                $body[$key] = '***';
            } elseif (is_array($value)) {
                $body[$key] = $this->sanitize($value);
            }
        }
        return $body;
    }
}
