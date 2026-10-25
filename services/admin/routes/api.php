<?php

use EasyLog\Support\ServiceRole;
use Illuminate\Support\Facades\Route;

// 健康检查（所有角色都暴露，K8s 探针 / 负载均衡探活依赖它）
Route::get('/health', fn () => response()->json(['status' => 'ok']));

// 接入 API（业务方调用，AppID 鉴权）
// 所有角色均注册：只有 9514(ingestion) 对外暴露该前缀，admin/query 上注册也无害
// （业务方亦可向 9515 投递，AppID 鉴权同样生效）。
Route::prefix('ingest')->group(function () {
    Route::post('/', [\EasyLog\Http\Controllers\IngestController::class, 'single'])
        ->middleware(['throttle:ingest']);
    Route::post('/batch', [\EasyLog\Http\Controllers\IngestController::class, 'batch'])
        ->middleware(['throttle:ingest']);
    Route::get('/heartbeat', [\EasyLog\Http\Controllers\IngestController::class, 'heartbeat']);
});

// 管理平面（控制台认证 + 管理 API）：仅 admin / query 角色注册。
// ingestion(9514) 是公开接入网关，绝不注册用户 / 工作空间 / 审计等管理路由（P0-2）。
// 判定逻辑集中在 EasyLog\Support\ServiceRole，便于单测与后续调整角色白名单。
if (ServiceRole::servesManagementApi()) {

    // 控制台认证（Sanctum 个人访问令牌）
    Route::prefix('auth')->group(function () {
        Route::post('login', [\EasyLog\Http\Controllers\AuthController::class, 'login'])
            ->middleware('throttle:10,1');
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', [\EasyLog\Http\Controllers\AuthController::class, 'me']);
            Route::post('logout', [\EasyLog\Http\Controllers\AuthController::class, 'logout']);
        });
    });

    // 管理 API（控制台调用，JWT 鉴权；写操作审计留痕）
    Route::middleware(['auth:sanctum', 'audit'])->group(function () {

        // 租户与工作空间
        Route::apiResource('workspaces', \EasyLog\Http\Controllers\WorkspaceController::class);
        Route::apiResource('workspaces.topics', \EasyLog\Http\Controllers\TopicController::class)
            ->shallow();

        // 查询
        Route::post('workspaces/{workspace}/search', [\EasyLog\Http\Controllers\SearchController::class, 'search']);
        Route::post('topics/{topic}/search', [\EasyLog\Http\Controllers\SearchController::class, 'searchTopic']);
        Route::post('topics/{topic}/histogram', [\EasyLog\Http\Controllers\SearchController::class, 'histogram']);
        Route::post('topics/{topic}/agg', [\EasyLog\Http\Controllers\SearchController::class, 'aggregate']);

        // 接入点（CRUD + 测试连接 + 密钥轮换）
        Route::apiResource('workspaces.endpoints', \EasyLog\Http\Controllers\EndpointsController::class);
        Route::post('workspaces/{workspace}/endpoints/{endpoint}/test-connection', [\EasyLog\Http\Controllers\EndpointsController::class, 'testConnection']);
        Route::post('workspaces/{workspace}/endpoints/{endpoint}/rotate-secret', [\EasyLog\Http\Controllers\EndpointsController::class, 'rotateSecret']);

        // 字段索引
        Route::apiResource('topics.fields', \EasyLog\Http\Controllers\TopicFieldController::class);

        // 告警规则 + 事件
        Route::apiResource('workspaces.alert-rules', \EasyLog\Http\Controllers\AlertRuleController::class);
        Route::get('workspaces/{workspace}/alert-rules/{rule}/events', [\EasyLog\Http\Controllers\AlertRuleController::class, 'events']);
        Route::post('workspaces/{workspace}/alert-rules/{rule}/events/{event}/resolve', [\EasyLog\Http\Controllers\AlertRuleController::class, 'resolveEvent']);

        // 看板 / 投递任务 / 消费组
        Route::apiResource('workspaces.dashboards', \EasyLog\Http\Controllers\DashboardController::class);
        Route::apiResource('workspaces.shipping-tasks', \EasyLog\Http\Controllers\ShippingTaskController::class);
        Route::apiResource('workspaces.consumption-groups', \EasyLog\Http\Controllers\ConsumptionGroupController::class);
        Route::post('workspaces/{workspace}/consumption-groups/{group}/rotate-token', [\EasyLog\Http\Controllers\ConsumptionGroupController::class, 'rotateToken']);

        // 用户与权限管理
        Route::apiResource('users', \EasyLog\Http\Controllers\UserController::class);
        Route::post('users/{user}/reset-password', [\EasyLog\Http\Controllers\UserController::class, 'resetPassword']);

        // 审计日志
        Route::get('audit-logs', [\EasyLog\Http\Controllers\AuditLogController::class, 'index']);

        // 用量查询
        Route::get('workspaces/{workspace}/usage', [\EasyLog\Http\Controllers\UsageController::class, 'usage']);
        Route::get('workspaces/{workspace}/usage/endpoints', [\EasyLog\Http\Controllers\UsageController::class, 'byEndpoint']);

        // 配额管理（workspace / tenant 层日配额）
        Route::get('workspaces/{workspace}/quota', [\EasyLog\Http\Controllers\QuotaController::class, 'workspaceQuota']);
        Route::put('workspaces/{workspace}/quota', [\EasyLog\Http\Controllers\QuotaController::class, 'updateWorkspaceQuota']);
        Route::get('tenant/quota', [\EasyLog\Http\Controllers\QuotaController::class, 'tenantQuota']);
        Route::put('tenant/quota', [\EasyLog\Http\Controllers\QuotaController::class, 'updateTenantQuota']);

        // 后续：quotas 管理
    });
}
