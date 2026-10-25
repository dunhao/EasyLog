<?php

namespace Database\Seeders;

use EasyLog\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * 控制台管理员账号种子（幂等）
 *
 * 默认账号：admin@easylog.local / easylog@2026
 * 生产环境请立刻改密码，或用 easylog:token 生成令牌后轮换。
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('EASYLOG_ADMIN_EMAIL', 'admin@easylog.local');

        $exists = User::withTrashed()->where('email', $email)->exists();
        if ($exists) {
            $this->command?->info("Admin user {$email} already exists, skipping.");
            return;
        }

        $tenantId = \DB::table('tenants')->orderBy('id')->value('id');
        if (!$tenantId) {
            $this->command?->warn('No tenant found, run EasyLogSeeder first.');
            return;
        }

        $user = User::create([
            'tenant_id' => $tenantId,
            'email'     => $email,
            'password'  => Hash::make((string) env('EASYLOG_ADMIN_PASSWORD', 'easylog@2026')),
            'name'      => 'EasyLog Admin',
            'status'    => 'active',
        ]);

        // 授予 owner 角色（RbacSeeder 已建好 roles 表数据）
        if (\DB::table('roles')->where('name', 'owner')->exists()) {
            $user->assignRole('owner');
        }

        $this->command?->info("Admin user created: {$email}");
    }
}
