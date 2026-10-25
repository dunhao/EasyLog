<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Writer;

use EasyLog\Services\Writer\WriterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 写入器批量缓冲逻辑测试
 *
 * 通过 setWriterForTest 注入回调，绕过真实 StorageAdapter facade。
 */
class WriterServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeEntry(array $overrides = []): array
    {
        return array_merge([
            '_tenant_id'      => '1',
            '_workspace_id'   => '11',
            '_workspace_uuid' => 'ws-111',
            '_topic_id'       => '21',
            '_topic_uuid'     => 'tp-222',
            'ts'              => '2026-09-14T10:00:00Z',
            'level'           => 'INFO',
            'msg'             => 'hello',
        ], $overrides);
    }

    public function testBatchSizeTriggersFlush(): void
    {
        $flushed = [];
        $writer = new WriterService(batchSize: 2, flushSeconds: 60);
        $writer->setWriterForTest(function ($index, $docs) use (&$flushed) {
            $flushed[$index] = $docs;
            return ['success' => count($docs), 'failed' => 0];
        });

        $writer->handle($this->makeEntry(['msg' => 'a']));
        $writer->handle($this->makeEntry(['msg' => 'b']));

        $this->assertCount(1, $flushed, '批量达到上限应触发一次 flush');
        $this->assertCount(2, $flushed[array_key_first($flushed)]);
        $this->assertEmpty($writer->snapshot(), 'flush 后缓冲应清空');
    }

    public function testFlushRemovesUnderscoreRoutingFields(): void
    {
        $flushed = [];
        $writer = new WriterService(batchSize: 1, flushSeconds: 60);
        $writer->setWriterForTest(function ($indexName, $docs) use (&$flushed) {
            $flushed[$indexName] = $docs;
            return ['success' => count($docs), 'failed' => 0];
        });

        $writer->handle($this->makeEntry(['custom' => 'keep']));

        $bucket = reset($flushed);
        $doc = $bucket[0];
        $this->assertArrayNotHasKey('_tenant_id', $doc);
        $this->assertArrayNotHasKey('_workspace_uuid', $doc);
        $this->assertArrayHasKey('custom', $doc);
        // 兜底写入无下划线的过滤字段
        $this->assertEquals('1', $doc['tenant_id']);
        $this->assertEquals('11', $doc['workspace_id']);
        $this->assertEquals('21', $doc['topic_id']);
    }

    public function testEntryWithoutRoutingKeysIsSkipped(): void
    {
        $flushed = [];
        $writer = new WriterService(batchSize: 1, flushSeconds: 60);
        $writer->setWriterForTest(function ($docs) use (&$flushed) {
            $flushed = $docs;
            return ['success' => count($docs), 'failed' => 0];
        });

        $writer->handle(['msg' => 'no routing']);
        $this->assertEmpty($flushed);
        $this->assertEmpty($writer->snapshot());
    }

    public function testTimeoutFlushTriggersOldBuffers(): void
    {
        $flushed = [];
        $writer = new WriterService(batchSize: 100, flushSeconds: 0);
        $writer->setWriterForTest(function ($index, $docs) use (&$flushed) {
            $flushed[$index] = $docs;
            return ['success' => count($docs), 'failed' => 0];
        });

        $writer->handle($this->makeEntry());
        $writer->checkTimeouts();

        $this->assertCount(1, $flushed, '超时桶应触发 flush');
        $this->assertEmpty($writer->snapshot());
    }

    public function testFlushAllEmptiesEverything(): void
    {
        $flushed = [];
        $writer = new WriterService(batchSize: 100, flushSeconds: 60);
        $writer->setWriterForTest(function ($index, $docs) use (&$flushed) {
            $flushed[$index] = $docs;
            return ['success' => count($docs), 'failed' => 0];
        });

        $writer->handle($this->makeEntry(['msg' => 'a']));
        $writer->handle($this->makeEntry(['msg' => 'b', '_workspace_uuid' => 'ws-999']));
        $writer->flushAll();

        $this->assertCount(2, $flushed, '两个索引桶都应刷新');
        $this->assertEmpty($writer->snapshot());
    }

    public function testBuildIndexNameUsesMonthlySuffix(): void
    {
        $writer = new WriterService(indexPrefix: 'easylog-');
        $name = $writer->buildIndexName('ws-111', 'tp-222');
        $this->assertMatchesRegularExpression('/^easylog-ws-111-tp-222-\d{6}$/', $name);
    }

    public function testPersistentWriteFailureDropsBufferAndDoesNotThrow(): void
    {
        $writer = new WriterService(batchSize: 1, flushSeconds: 60);
        $writer->setWriterForTest(function () {
            // 模拟存储后端不可用（抛异常，重试后走 DLQ 分支）
            throw new \RuntimeException('ES unreachable');
        });

        // 不抛异常即通过（重试 3 次后落 DLQ，缓冲清空）
        $writer->handle($this->makeEntry());
        $this->assertEmpty($writer->snapshot());
    }
}
