<?php

declare(strict_types=1);

namespace EasyLog\Http\Controllers;

use EasyLog\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 用户与权限管理（控制台账号 CRUD）
 *
 * 路由：
 * - GET    /users                        index
 * - POST   /users                        store
 * - GET    /users/{user}                 show
 * - PUT    /users/{user}                 update
 * - DELETE /users/{user}                 destroy（软删，禁止自删）
 * - POST   /users/{user}/reset-password  重置密码
 *
 * 多租户隔离：所有操作限定在当前登录用户所属 tenant 内。
 */
class UserController extends Controller
{
    public function __construct()
    {
        // 用户管理是最敏感的能力：此前任何已登录用户都能建账号并给自己提权到 owner
        $this->middleware('ability:users.view')->only(['index', 'show']);
        $this->middleware('ability:users.manage')->only(['store', 'update', 'destroy', 'resetPassword']);
    }
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $query = User::with('roles')
            ->where('tenant_id', $tenantId)
            ->when($request->input('keyword'), function ($q, $kw) {
                $q->where(function ($q2) use ($kw) {
                    $q2->where('name', 'like', "%{$kw}%")->orWhere('email', 'like', "%{$kw}%");
                });
            })
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s));

        $page     = max(1, (int) $request->input('page', 1));
        $pageSize = min(100, max(1, (int) $request->input('page_size', 20)));
        $total    = $query->count();
        $items    = $query->forPage($page, $pageSize)->get();

        return response()->json([
            'data' => $items->map(fn (User $u) => $this->serialize($u)),
            'meta' => [
                'total'     => $total,
                'page'      => $page,
                'page_size' => $pageSize,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:64',
            'email'    => 'required|email|max:128|unique:users,email',
            'password' => 'required|string|min:8|max:64',
            'roles'    => 'nullable|array',
            'roles.*'  => 'string|max:32',
            'status'   => 'sometimes|in:active,suspended',
        ]);

        $user = new User();
        $user->tenant_id = $request->user()->tenant_id;
        $user->email     = $validated['email'];
        $user->name      = $validated['name'];
        $user->password  = Hash::make($validated['password']);
        $user->status    = $validated['status'] ?? 'active';
        $user->save();

        if (!empty($validated['roles'])) {
            $user->syncRoles($validated['roles']);
        }

        return response()->json($this->serialize($user->load('roles')), 201);
    }

    public function show(Request $request, int $user): JsonResponse
    {
        return response()->json($this->serialize($this->loadUser($request, $user)));
    }

    public function update(Request $request, int $user): JsonResponse
    {
        $model = $this->loadUser($request, $user);
        $validated = $request->validate([
            'name'     => 'sometimes|string|max:64',
            'email'    => 'sometimes|email|max:128|unique:users,email,' . $model->id,
            'password' => 'sometimes|string|min:8|max:64',
            'roles'    => 'sometimes|nullable|array',
            'roles.*'  => 'string|max:32',
            'status'   => 'sometimes|in:active,suspended',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }
        $model->fill($validated);
        $model->save();

        if (array_key_exists('roles', $validated)) {
            $model->syncRoles($validated['roles'] ?? []);
        }

        return response()->json($this->serialize($model->load('roles')));
    }

    public function destroy(Request $request, int $user): JsonResponse
    {
        $model = $this->loadUser($request, $user);

        if ($model->id === $request->user()->id) {
            return response()->json(['message' => '不能删除当前登录账号'], 400);
        }

        $model->delete();

        return response()->json(null, 204);
    }

    public function resetPassword(Request $request, int $user): JsonResponse
    {
        $validated = $request->validate([
            'password' => 'required|string|min:8|max:64',
        ]);

        $model = $this->loadUser($request, $user);
        $model->password = Hash::make($validated['password']);
        $model->save();

        return response()->json(['message' => '密码已重置']);
    }

    private function loadUser(Request $request, int $userId): User
    {
        $user = User::where('id', $userId)
            ->where('tenant_id', $request->user()->tenant_id)
            ->first();
        if (!$user) {
            abort(404, 'User not found');
        }
        return $user;
    }

    private function serialize(User $u): array
    {
        return [
            'id'            => $u->id,
            'name'          => $u->name,
            'email'         => $u->email,
            'roles'         => $u->getRoleNames()->values()->all(),
            'status'        => $u->status,
            'last_login_at' => $u->last_login_at?->toISOString(),
            'created_at'    => $u->created_at?->toISOString(),
        ];
    }
}
