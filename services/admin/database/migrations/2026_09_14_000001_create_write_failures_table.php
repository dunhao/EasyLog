<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 写入失败 DLQ 表：Writer 重试 3 次仍失败的批次落盘，便于人工重投。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('write_failures', function (Blueprint $table) {
            $table->id();
            $table->string('index_name', 128);
            $table->longText('docs');           // JSON：失败批次原文
            $table->longText('error')->nullable();   // JSON：错误详情
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('status', 16)->default('pending'); // pending|replayed|dropped
            $table->timestamp('replayed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('write_failures');
    }
};
