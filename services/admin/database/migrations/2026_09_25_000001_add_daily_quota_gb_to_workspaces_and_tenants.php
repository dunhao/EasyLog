<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 配额管理（阶段二 T5）：租户与工作空间级日配额
 *
 * 0 = 不限。生效层级：endpoint.daily_quota_gb → workspace.daily_quota_gb → tenant.daily_quota_gb，
 * 任一层超限即 429（见 UsageService::assertQuota）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('daily_quota_gb')->default(0)->after('plan');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->unsignedInteger('daily_quota_gb')->default(0)->after('shard_count');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('daily_quota_gb');
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('daily_quota_gb');
        });
    }
};
