<?php

namespace EasyLog\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * EasyLog 主服务提供者
 *
 * 职责：
 * - 加载迁移（仅 admin 服务）
 * - 注册多租户上下文单例容器
 * - 注册接入 API 限流器（throttle:ingest 中间件使用）
 */
class EasyLogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 加载迁移（仅 admin 服务）
        if (is_dir(__DIR__.'/../../database/migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

        // 接入 API 限流器：按 AppID 限流，无 AppID 时按 IP
        RateLimiter::for('ingest', function ($request) {
            $limit = (int) config('ratelimit.rate_limits.ingest.limit', 1000);
            $key = $request->header('X-EasyLog-App') ?: $request->ip();
            return Limit::perSecond($limit)->by($key);
        });

        // lite 档（direct 总线）：请求结束时 flush 进程内写入缓冲，
        // 保证低流量场景下最后一批日志不滞留内存
        if (config('message-queue.bus.driver') === 'direct') {
            $this->app->terminating(function () {
                try {
                    \EasyLog\Facades\MessageQueue::flush();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('EasyLog direct bus flush failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        }
    }

    public function register(): void
    {
        // 多租户上下文容器占位（由 ResolveTenantContext 中间件运行时注入实际值）
        $this->app->singleton('easylog.tenant_id', fn () => null);
        $this->app->singleton('easylog.workspace_id', fn () => null);
        $this->app->singleton('easylog.topic_id', fn () => null);
        $this->app->singleton('easylog.endpoint', fn () => null);
    }
}
