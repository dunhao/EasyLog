<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * 字段索引管理 API 测试（topics/{topic}/fields CRUD）
 */
class TopicFieldApiTest extends AdminApiTestCase
{
    public function testIndexReturnsEmptyList(): void
    {
        $this->getJson('/api/v1/topics/' . self::TOPIC_UUID . '/fields')
            ->assertOk()
            ->assertJson(['data' => []]);
    }

    public function testStoreCreatesField(): void
    {
        $this->postJson('/api/v1/topics/' . self::TOPIC_UUID . '/fields', [
            'field_name' => 'user_id',
            'field_type' => 'keyword',
        ])->assertStatus(201)
            ->assertJsonPath('field_name', 'user_id')
            ->assertJsonPath('field_type', 'keyword')
            ->assertJsonPath('indexed', true);
    }

    public function testStoreRejectsInvalidType(): void
    {
        $this->postJson('/api/v1/topics/' . self::TOPIC_UUID . '/fields', [
            'field_name' => 'weird',
            'field_type' => 'blob',
        ])->assertStatus(422);
    }

    public function testStoreRejectsDuplicateFieldName(): void
    {
        $this->postJson('/api/v1/topics/' . self::TOPIC_UUID . '/fields', [
            'field_name' => 'user_id', 'field_type' => 'keyword',
        ])->assertStatus(201);

        $this->postJson('/api/v1/topics/' . self::TOPIC_UUID . '/fields', [
            'field_name' => 'user_id', 'field_type' => 'long',
        ])->assertStatus(422);
    }

    public function testShowReturnsField(): void
    {
        $id = $this->createField();
        $this->getJson('/api/v1/topics/' . self::TOPIC_UUID . '/fields/' . $id)
            ->assertOk()
            ->assertJsonPath('field_name', 'latency');
    }

    public function testUpdateChangesType(): void
    {
        $id = $this->createField();
        $this->putJson('/api/v1/topics/' . self::TOPIC_UUID . '/fields/' . $id, [
            'field_type' => 'double',
            'indexed'    => false,
        ])->assertOk()
            ->assertJsonPath('field_type', 'double')
            ->assertJsonPath('indexed', false);
    }

    public function testDestroyDeletesField(): void
    {
        $id = $this->createField();
        $this->deleteJson('/api/v1/topics/' . self::TOPIC_UUID . '/fields/' . $id)
            ->assertStatus(204);

        $this->getJson('/api/v1/topics/' . self::TOPIC_UUID . '/fields/' . $id)
            ->assertStatus(404);
    }

    public function testUnknownTopicReturns404(): void
    {
        $this->getJson('/api/v1/topics/nonexistent/fields')->assertStatus(404);
    }

    private function createField(): int
    {
        return \EasyLog\Models\TopicField::create([
            'topic_id'   => $this->topicId,
            'field_name' => 'latency',
            'field_type' => 'long',
            'indexed'    => true,
            'analyzer'   => 'standard',
        ])->id;
    }
}
