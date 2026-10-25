<?php

namespace Database\Seeders;

use EasyLog\Support\Abilities;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 角色与权限种子。
 *
 * 此前只种 4 个角色、0 条权限 —— 这是「RBAC 授权从未接线」的根因之一：
 * 即便路由挂上 ability 中间件，没有任何权限记录时 $user->can() 恒为 false，
 * 会直接把整个管理面打成 403。
 *
 * 幂等：findOrCreate + syncPermissions，可随每次 db:seed 重复执行。
 */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        // 权限缓存会让新增的权限在本次请求内不可见
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Abilities::ALL as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (Abilities::ROLE_ABILITIES as $role => $abilities) {
            Role::findOrCreate($role, 'web')->syncPermissions($abilities);
        }
    }
}
