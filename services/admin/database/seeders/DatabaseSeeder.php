<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 幂等守卫：容器每次启动 entrypoint 都会执行 db:seed，
        // 已有种子数据时直接跳过，避免重复插入导致 1062 唯一键冲突。
        if (DB::table('tenants')->exists()) {
            $this->command?->info('Database already seeded, skipping.');

            return;
        }

        $this->call([
            EasyLogSeeder::class,
        ]);
    }
}
