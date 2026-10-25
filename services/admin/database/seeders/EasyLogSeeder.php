<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EasyLogSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = DB::table('tenants')->insertGetId([
            'uuid'       => Str::uuid()->toString(),
            'name'       => 'Acme Inc.',
            'slug'       => 'acme',
            'status'     => 'active',
            'plan'       => 'pro',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $workspace = DB::table('workspaces')->insertGetId([
            'uuid'           => 'ws_acme_prod',
            'tenant_id'      => $tenant,
            'name'           => 'Acme 生产',
            'description'    => '生产环境日志聚合',
            'retention_days' => 30,
            'shard_count'    => 6,
            'status'         => 'active',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $topicId = DB::table('topics')->insertGetId([
            'uuid'              => 'tpc_order_logs',
            'workspace_id'      => $workspace,
            'name'              => 'order-logs',
            'description'       => '订单服务日志',
            'auto_field_index'  => true,
            'retention_days'    => 30,
            'enable_full_text'  => true,
            'json_extract'      => true,
            'status'            => 'active',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        DB::table('ingestion_endpoints')->insert([
            'uuid'               => Str::uuid()->toString(),
            'workspace_id'       => $workspace,
            'topic_id'           => $topicId,
            'name'               => '订单 HTTP 接入',
            'source_type'        => 'http',
            'config'             => json_encode([]),
            'app_id'             => 'app_demo_001',
            'app_secret_hash'    => bcrypt('sec_demo_001'),
            'rate_limit_per_sec' => 1000,
            'daily_quota_gb'     => 50,
            'status'             => 'active',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $this->call([
            \Database\Seeders\RbacSeeder::class,
            \Database\Seeders\AdminUserSeeder::class,
        ]);
    }
}
