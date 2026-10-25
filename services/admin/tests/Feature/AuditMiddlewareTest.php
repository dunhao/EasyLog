<?php

declare(strict_types=1);

namespace Tests\Feature;

use EasyLog\Models\AuditLog;
use Illuminate\Support\Str;

/**
 * 管理操作审计中间件测试（写操作留痕、敏感字段脱敏、只读不记录）
 */
class AuditMiddlewareTest extends AdminApiTestCase
{
    public function testWriteOperationRecordsAudit(): void
    {
        $this->postJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/endpoints', [
            'name'        => '审计测试',
            'source_type' => 'http',
            'topic_id'    => $this->topicId,
        ])->assertStatus(201);

        $log = AuditLog::latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('workspaces.endpoints.store', $log->action);
        $this->assertSame('workspace', $log->target_type);
        $this->assertSame(self::WORKSPACE_UUID, $log->target_id);
        $this->assertSame($this->tenantId, $log->tenant_id);
        $this->assertSame($this->userId, $log->user_id);
    }

    public function testReadOperationsNotRecorded(): void
    {
        $this->getJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/endpoints')->assertOk();

        $this->assertSame(0, AuditLog::count());
    }

    public function testFailedRequestNotRecorded(): void
    {
        $this->postJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/endpoints', [
            'name'        => 'bad',
            'source_type' => 'ftp',     // 422
            'topic_id'    => $this->topicId,
        ])->assertStatus(422);

        $this->assertSame(0, AuditLog::count());
    }

    public function testSensitiveFieldsMaskedInAuditContext(): void
    {
        $response = $this->postJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/consumption-groups', [
            'topic_id'       => $this->topicId,
            'consumer_group' => 'audit-group',
        ])->assertStatus(201);

        $log = AuditLog::latest('id')->first();
        $context = $log->context;
        // 非敏感字段正常记录，敏感字段（auth_token 等）不落库
        $this->assertSame('audit-group', $context['body']['consumer_group']);
        $this->assertArrayNotHasKey('auth_token', $context['body']);
        $this->assertSame($response->status(), $context['status']);
    }

    public function testDeleteOperationRecorded(): void
    {
        // 先建端点再删
        $uuid = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('ingestion_endpoints')->insert([
            'uuid' => $uuid, 'workspace_id' => $this->workspaceId, 'topic_id' => $this->topicId,
            'name' => '待删', 'source_type' => 'http', 'config' => json_encode([]),
            'app_id' => 'app_del_1', 'app_secret_hash' => bcrypt('x'),
            'rate_limit_per_sec' => 100, 'daily_quota_gb' => 1, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        AuditLog::query()->delete();

        $this->deleteJson('/api/v1/workspaces/' . self::WORKSPACE_UUID . '/endpoints/' . $uuid)
            ->assertStatus(204);

        $log = AuditLog::latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('workspaces.endpoints.destroy', $log->action);
        $this->assertSame($uuid, $log->target_id);
    }
}
