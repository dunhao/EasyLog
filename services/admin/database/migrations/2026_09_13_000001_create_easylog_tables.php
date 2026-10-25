<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 64);
            $table->string('slug', 64)->unique();
            $table->enum('status', ['active', 'suspended', 'deleted'])->default('active');
            $table->string('plan', 32)->default('free');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status']);
        });

        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 64);
            $table->string('description', 255)->nullable();
            $table->integer('retention_days')->default(30);
            $table->integer('shard_count')->default(6);
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name', 64);
            $table->string('description', 255)->nullable();
            $table->boolean('auto_field_index')->default(true);
            $table->integer('retention_days')->default(30);
            $table->boolean('enable_full_text')->default(true);
            $table->boolean('json_extract')->default(true);
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('ingestion_endpoints', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('topic_id');
            $table->string('name', 64);
            $table->enum('source_type', ['http', 'sdk', 'rabbitmq', 'kafka', 'redis', 'activemq', 'syslog', 'filebeat']);
            $table->json('config');
            $table->string('app_id', 48)->unique();
            $table->string('app_secret_hash', 96);
            $table->integer('rate_limit_per_sec')->default(1000);
            $table->bigInteger('daily_quota_gb')->default(0);
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('topic_id')->references('id')->on('topics')->cascadeOnDelete();
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('topic_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('topic_id');
            $table->string('field_name', 64);
            $table->enum('field_type', ['text', 'keyword', 'long', 'double', 'date', 'ip', 'geo']);
            $table->boolean('indexed')->default(true);
            $table->string('analyzer', 32)->default('standard');
            $table->timestamps();
            $table->foreign('topic_id')->references('id')->on('topics')->cascadeOnDelete();
            $table->unique(['topic_id', 'field_name']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('name', 64);
            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('dashboards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name', 64);
            $table->json('layout');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('topic_id');
            $table->string('name', 64);
            $table->json('condition');
            $table->integer('window_sec')->default(60);
            $table->integer('threshold')->default(100);
            $table->enum('severity', ['info', 'warning', 'critical'])->default('warning');
            $table->json('notify_channels');
            $table->enum('status', ['active', 'paused'])->default('active');
            $table->timestamp('last_fired_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('topic_id')->references('id')->on('topics')->cascadeOnDelete();
        });

        Schema::create('alert_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('rule_id');
            $table->timestamp('fired_at');
            $table->timestamp('resolved_at')->nullable();
            $table->enum('severity', ['info', 'warning', 'critical']);
            $table->json('context');
            $table->string('notify_status', 32)->default('pending');
            $table->timestamps();
            $table->foreign('rule_id')->references('id')->on('alert_rules')->cascadeOnDelete();
            $table->index(['fired_at']);
        });

        Schema::create('shipping_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('topic_id');
            $table->string('name', 64);
            $table->enum('target_type', ['oss', 'file', 'rabbitmq', 'kafka', 'redis', 'activemq', 'http']);
            $table->json('target_config');
            $table->json('filter')->nullable();
            $table->timestamp('start_from')->nullable();
            $table->enum('status', ['active', 'paused', 'completed'])->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('topic_id')->references('id')->on('topics')->cascadeOnDelete();
        });

        Schema::create('consumption_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('topic_id');
            $table->string('consumer_group', 64);
            $table->string('auth_token_hash', 96);
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('topic_id')->references('id')->on('topics')->cascadeOnDelete();
            $table->unique(['topic_id', 'consumer_group']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 64);
            $table->string('target_type', 32)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->json('context')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestampTz('created_at');
            $table->index(['tenant_id', 'created_at']);
            $table->index(['action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('consumption_groups');
        Schema::dropIfExists('shipping_tasks');
        Schema::dropIfExists('alert_events');
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('dashboards');
        Schema::dropIfExists('users');
        Schema::dropIfExists('topic_fields');
        Schema::dropIfExists('ingestion_endpoints');
        Schema::dropIfExists('topics');
        Schema::dropIfExists('workspaces');
        Schema::dropIfExists('tenants');
    }
};
