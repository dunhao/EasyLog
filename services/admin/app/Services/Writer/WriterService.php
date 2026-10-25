<?php

declare(strict_types=1);

namespace EasyLog\Services\Writer;

use EasyLog\Facades\StorageAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 写入器核心服务
 *
 * 消费内部 Kafka → 批量聚合 → 调用 StorageAdapter 写入 ES。
 *
 * 实际消费循环由 WriterCommand 驱动，本类负责：
 * - handle(): 收到一条消息时调用（按索引名分桶缓冲）
 * - checkTimeouts(): 周期性调用，超时桶强制 flush
 * - flushAll(): 进程退出前调用，保证不丢消息
 *
 * 索引名约定：easylog-{workspace_uuid}-{topic_uuid}-{yyyymm}
 */
class WriterService
{
    /** @var array<string, array<array<string,mixed>>> 索引名 => 文档缓冲 */
    private array $buffers = [];

    /** @var array<string, int> 索引名 => 最后一次 flush 时间戳 */
    private array $lastFlush = [];

    public function __construct(
        private int $batchSize = 1000,
        private int $flushSeconds = 5,
        private string $indexPrefix = 'easylog-',
    ) {}

    /**
     * 处理单条 Kafka 消息
     *
     * @param array<string,mixed> $entry 已归一化的日志条目（含 _workspace_id/_workspace_uuid/_topic_uuid 等路由字段）
     */
    public function handle(array $entry): void
    {
        $workspaceUuid = $entry['_workspace_uuid'] ?? null;
        $topicUuid     = $entry['_topic_uuid'] ?? null;
        if (!$workspaceUuid || !$topicUuid) {
            Log::warning('EasyLog writer skip entry without routing keys', [
                'keys' => array_keys($entry),
            ]);
            return;
        }

        $indexName = $this->buildIndexName($workspaceUuid, $topicUuid);

        // 剔除所有 _ 前缀路由字段，保留业务字段
        $doc = [];
        foreach ($entry as $k => $v) {
            if (!str_starts_with($k, '_')) {
                $doc[$k] = $v;
            }
        }
        // 兜底：tenant/workspace/topic id 字段（不带下划线，供 ES 二次过滤使用）
        $doc['tenant_id']    = (string) ($entry['_tenant_id'] ?? '');
        $doc['workspace_id'] = (string) ($entry['_workspace_id'] ?? $workspaceUuid);
        $doc['topic_id']     = (string) ($entry['_topic_id'] ?? $topicUuid);

        $this->buffers[$indexName][] = $doc;
        $this->lastFlush[$indexName] ??= time();

        if (count($this->buffers[$indexName]) >= $this->batchSize) {
            $this->flush($indexName);
        }
    }

    /**
     * 直接处理一段已解码的消息数组（批量场景）
     *
     * @param array<int, array<string,mixed>> $entries
     */
    public function handleBatch(array $entries): void
    {
        foreach ($entries as $entry) {
            $this->handle($entry);
        }
    }

    /**
     * 定时检查所有缓冲是否超时
     */
    public function checkTimeouts(): void
    {
        $now = time();
        foreach ($this->buffers as $indexName => $docs) {
            if (empty($docs)) continue;
            $last = $this->lastFlush[$indexName] ?? $now;
            if ($now - $last >= $this->flushSeconds) {
                $this->flush($indexName);
            }
        }
    }

    /**
     * 强制刷新所有缓冲（进程退出前调用）
     */
    public function flushAll(): void
    {
        foreach (array_keys($this->buffers) as $indexName) {
            $this->flush($indexName);
        }
    }

    /**
     * 当前缓冲快照（仅供测试断言）
     *
     * @return array<string, array<array<string,mixed>>>
     */
    public function snapshot(): array
    {
        return $this->buffers;
    }

    /**
     * 暴露 batchSize 配置（供 WriterCommand 优化拉取策略）
     */
    public function batchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * 显式注入 StorageAdapter（测试用，绕过 facade）
     *
     * @param callable $writer 接收 (string $indexName, array $docs)，返回 array{success:int,failed:int,errors:?array}
     */
    public function setWriterForTest(callable $writer): void
    {
        $this->testWriter = $writer;
    }

    private $testWriter = null;

    private function flush(string $indexName): void
    {
        $docs = $this->buffers[$indexName] ?? [];
        if (empty($docs)) return;

        $retries = 3;
        $lastError = null;
        for ($i = 0; $i < $retries; $i++) {
            try {
                $result = $this->testWriter
                    ? ($this->testWriter)($indexName, $docs)
                    : StorageAdapter::bulkWrite($indexName, $docs);

                if (($result['failed'] ?? 0) === 0) {
                    unset($this->buffers[$indexName]);
                    $this->lastFlush[$indexName] = time();
                    return;
                }
                $lastError = $result['errors'] ?? ['unknown'];
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                // 指数退避：0.5s, 1s, 1.5s
                usleep(500000 * ($i + 1));
            }
        }

        // 重试失败，落 DLQ
        $this->writeToDLQ($indexName, $docs, $lastError);
        unset($this->buffers[$indexName]);
        $this->lastFlush[$indexName] = time();
    }

    private function writeToDLQ(string $indexName, array $docs, $error): void
    {
        Log::error('EasyLog writer flush failed, writing to DLQ', [
            'index' => $indexName,
            'count' => count($docs),
            'error' => $error,
        ]);
        try {
            \DB::table('write_failures')->insert([
                'index_name'  => $indexName,
                'docs'        => json_encode($docs, JSON_UNESCAPED_UNICODE),
                'error'       => json_encode($error),
                'retry_count' => 3,
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            Log::critical('EasyLog DLQ write failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 构造 ES 索引名：easylog-{workspace_uuid}-{topic_uuid}-{yyyymm}
     */
    public function buildIndexName(string $workspaceUuid, string $topicUuid): string
    {
        return $this->indexPrefix . $workspaceUuid . '-' . $topicUuid . '-' . now()->format('Ym');
    }
}
