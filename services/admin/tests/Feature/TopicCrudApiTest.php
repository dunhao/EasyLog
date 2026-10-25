<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * 主题 shallow 路由 CRUD 测试（GET/PUT/DELETE /topics/{topic}）
 */
class TopicCrudApiTest extends AdminApiTestCase
{
    public function testShowTopicByUuid(): void
    {
        $this->getJson('/api/v1/topics/' . self::TOPIC_UUID)
            ->assertStatus(200)
            ->assertJsonPath('uuid', self::TOPIC_UUID)
            ->assertJsonPath('name', 'order-logs');
    }

    public function testUpdateTopic(): void
    {
        $this->putJson('/api/v1/topics/' . self::TOPIC_UUID, [
            'name'           => 'order-logs-v2',
            'retention_days' => 60,
        ])
            ->assertStatus(200)
            ->assertJsonPath('name', 'order-logs-v2')
            ->assertJsonPath('retention_days', 60);
    }

    public function testDestroyTopic(): void
    {
        $this->deleteJson('/api/v1/topics/' . self::TOPIC_UUID)->assertStatus(204);
        $this->assertSoftDeleted('topics', ['uuid' => self::TOPIC_UUID]);
    }

    public function testShowMissingTopic404(): void
    {
        $this->getJson('/api/v1/topics/tpc_nonexistent')->assertStatus(404);
    }
}
