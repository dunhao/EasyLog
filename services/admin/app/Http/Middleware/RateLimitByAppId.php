<?php

namespace EasyLog\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 按 AppID 限流（保护接入网关不被单租户压垮）
 */
class RateLimitByAppId
{
    public function handle(Request $request, Closure $next): mixed
    {
        $appId = $request->header('X-EasyLog-App');
        if (!$appId) {
            return $next($request);
        }
        $limit = (int) config('easylog.rate_limit_per_sec', 1000);
        $key = 'ingest:'.$appId.':'.floor(time() / 1);
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return response()->json([
                'error' => 'rate_limited',
                'message' => 'Too many requests, please retry later.',
            ], 429)->withHeaders([
                'X-EasyLog-RateLimit-Limit'     => $limit,
                'X-EasyLog-RateLimit-Remaining' => 0,
            ]);
        }
        RateLimiter::hit($key, 2);
        return $next($request);
    }
}
