<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MQ;

use EasyLog\Services\MQ\Kafka\InMemoryKafkaProducer;
use EasyLog\Services\MQ\MessageQueueManager;
use Tests\TestCase;

/**
 * 消息队列管理器测试（topic 命名 / 分区键 / 投递行为）
 */
class MessageQueueManagerTest extends TestCase
{
    public function testTopicNameConcatenatesUuidsWithPrefix(): void
    {
        $mq = new MessageQueueManager(['bus' => ['topic_prefix' => 'easylog.ingest.']]);
        $this->assertEquals(
            'easylog.ingest.ws-111.tp-222',
            $mq->topicName('ws-111', 'tp-222')
        );
    }

    public function testTopicNameToleratesPrefixWithoutTrailingDot(): void
    {
        $mq = new MessageQueueManager(['bus' => ['topic_prefix' => 'easylog.ingest']]);
        $this->assertEquals(
            'easylog.ingest.ws-111.tp-222',
            $mq->topicName('ws-111', 'tp-222')
        );
    }

    public function testPartitionKeyIsStablePerWorkspace(): void
    {
        $mq = new MessageQueueManager([]);
        $this->assertEquals('ws-ws-111', $mq->partitionKey('ws-111'));
        // 同一 workspace 永远同一 key
        $this->assertEquals($mq->partitionKey('ws-111'), $mq->partitionKey('ws-111'));
    }

    public function testPublishLogProducesToInMemoryBus(): void
    {
        $producer = new InMemoryKafkaProducer();
        $mq = new MessageQueueManager(['bus' => ['topic_prefix' => 'easylog.ingest.']]);
        $mq->setProducer($producer);

        $mq->publishLog(
            workspaceId: 11,
            topicId: 21,
            workspaceUuid: 'ws-111',
            topicUuid: 'tp-222',
            logEntry: ['_tenant_id' => '1', 'ts' => '2026-09-14T10:00:00Z', 'level' => 'INFO', 'msg' => 'hi'],
            headers: ['request_id' => 'req-1'],
        );

        $messages = $producer->getMessages();
        $this->assertCount(1, $messages);

        $msg = $messages[0];
        $this->assertEquals('easylog.ingest.ws-111.tp-222', $msg['topic']);
        $this->assertEquals('ws-ws-111', $msg['key']);
        $this->assertEquals('1', $msg['headers']['tenant_id']);
        $this->assertEquals('11', $msg['headers']['workspace_id']);
        $this->assertEquals('21', $msg['headers']['topic_id']);
        $this->assertEquals('req-1', $msg['headers']['request_id']);

        // payload 是可解码的 JSON，且中文不转义
        $decoded = json_decode($msg['payload'], true);
        $this->assertEquals('hi', $decoded['msg']);
    }

    public function testBatchProduceFlattensMultipleEntries(): void
    {
        $producer = new InMemoryKafkaProducer();
        $mq = new MessageQueueManager(['bus' => ['topic_prefix' => 'easylog.ingest.']]);
        $mq->setProducer($producer);

        $mq->batchProduce([
            [
                'workspace_id' => 11, 'topic_id' => 21,
                'workspace_uuid' => 'ws-111', 'topic_uuid' => 'tp-222',
                'entry' => ['_tenant_id' => '1', 'msg' => 'a'],
            ],
            [
                'workspace_id' => 12, 'topic_id' => 22,
                'workspace_uuid' => 'ws-112', 'topic_uuid' => 'tp-223',
                'entry' => ['_tenant_id' => '1', 'msg' => 'b'],
            ],
        ]);

        $messages = $producer->getMessages();
        $this->assertCount(2, $messages);
        $this->assertEquals('easylog.ingest.ws-111.tp-222', $messages[0]['topic']);
        $this->assertEquals('easylog.ingest.ws-112.tp-223', $messages[1]['topic']);
    }

    public function testEmptyBatchProducesNothing(): void
    {
        $producer = new InMemoryKafkaProducer();
        $mq = new MessageQueueManager([]);
        $mq->setProducer($producer);

        $mq->batchProduce([]);
        $this->assertEmpty($producer->getMessages());
    }

    public function testAvailableConsumersOnlyReturnsEnabled(): void
    {
        $mq = new MessageQueueManager([
            'consumers' => [
                'kafka'     => ['enabled' => true],
                'rabbitmq'  => ['enabled' => false],
                'redis'     => ['enabled' => true],
            ],
        ]);

        $available = $mq->availableConsumers();
        $this->assertEqualsCanonicalizing(['kafka', 'redis'], array_keys($available));
    }

    public function testProducerDriverLogCannotPing(): void
    {
        // LogKafkaProducer 设计上不提供探活能力（见类注释）
        $mq = new MessageQueueManager(['bus' => ['driver' => 'log']]);
        $this->assertFalse($mq->ping());
    }
}
