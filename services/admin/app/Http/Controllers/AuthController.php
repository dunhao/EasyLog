<?php

declare(strict_types=1);

namespace EasyLog\Http\Controllers;

use EasyLog\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * 控制台认证（Sanctum 个人访问令牌）
 *
 * 路由：
 * - POST /auth/login   登录（公开，限流）
 * - GET  /auth/me      当前用户（auth:sanctum）
 * - POST /auth/logout  注销当前令牌（auth:sanctum）
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => '邮箱或密码不正确'], 401);
        }
        if ($user->status !== 'active') {
            return response()->json(['message' => '账号已被停用'], 403);
        }
        if ($user->trashed()) {
            return response()->json(['message' => '账号不存在'], 401);
        }

        $expiresIn = config('sanctum.expiration') ? (int) config('sanctum.expiration') * 60 : null;

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token'      => $user->createToken('easylog-console')->plainTextToken,
            'expires_in' => $expiresIn,
            'user'       => $this->serialize($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->serialize($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(null, 204);
    }

    private function serialize(User $user): array
    {
        return [
            'id'            => $user->id,
            'name'          => $user->name,
            'email'         => $user->email,
            'roles'         => $user->getRoleNames()->values()->all(),
            'status'        => $user->status,
            'last_login_at' => $user->last_login_at?->toISOString(),
        ];
    }
}
