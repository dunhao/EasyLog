<?php

namespace EasyLog\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 能力检查（基于 RBAC 角色判断是否拥有某权限）
 */
class CheckAbility
{
    public function handle(Request $request, Closure $next, string $ability): mixed
    {
        $user = $request->user();
        if (!$user || !$user->can($ability)) {
            abort(403, 'Forbidden: missing ability '.$ability);
        }
        return $next($request);
    }
}
