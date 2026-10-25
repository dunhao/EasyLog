<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 用量计量表（endpoint 粒度按天累计，workspace 汇总用 sum）
 * 由 ingest 入口写入，daily_quota_gb 配额校验读取
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('topic_id')->nullable();
            $table->unsignedBigInteger('endpoint_id');
            $table->date('usage_date');
            $table->bigInteger('bytes')->default(0);
            $table->bigInteger('count')->default(0);
            $table->timestamps();
            $table->unique(['endpoint_id', 'usage_date']);
            $table->index(['workspace_id', 'usage_date']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('endpoint_id')->references('id')->on('ingestion_endpoints')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_records');
    }
};
