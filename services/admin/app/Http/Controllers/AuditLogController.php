<?php

declare(strict_types=1);

namespace EasyLog\Http\Controllers;

use EasyLog\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 审计日志查询（记录由管理操作中间件/服务写入）
 *
 * 路由：
 * - GET /audit-logs   index（按操作/目标/时间筛选）
 *
 * 多租户隔离：tenant_id 限定。
 */
class AuditLogController extends Controller
{
    public function __construct()
    {
        $this->middleware('ability:audit.view')->only(['index']);
    }
    public function index(Request $request): JsonResponse
    {
        $tenantId = app('easylog.tenant_id');
        $query = AuditLog::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($request->input('action'), fn ($q, $a) => $q->where('action', $a))
            ->when($request->input('target_type'), fn ($q, $t) => $q->where('target_type', $t))
            ->when($request->input('from'), fn ($q, $f) => $q->where('created_at', '>=', $f))
            ->when($request->input('to'), fn ($q, $t) => $q->where('created_at', '<=', $t));

        $page     = max(1, (int) $request->input('page', 1));
        $pageSize = min(100, max(1, (int) $request->input('page_size', 20)));
        $total    = $query->count();
        $items    = $query->orderByDesc('id')->forPage($page, $pageSize)->get();

        return response()->json([
            'data' => $items->map(fn (AuditLog $l) => [
                'id'          => $l->id,
                'user_id'     => $l->user_id,
                'action'      => $l->action,
                'target_type' => $l->target_type,
                'target_id'   => $l->target_id,
                'context'     => $l->context,
                'ip'          => $l->ip,
                'created_at'  => $l->created_at?->toISOString(),
            ]),
            'meta' => [
                'total'     => $total,
                'page'      => $page,
                'page_size' => $pageSize,
            ],
        ]);
    }
}
