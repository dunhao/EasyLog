<?php

declare(strict_types=1);

namespace EasyLog\Console\Commands;

use EasyLog\Facades\MessageQueue;
use EasyLog\Facades\StorageAdapter;
use EasyLog\Services\MQ\Kafka\KafkaConsumerInterface;
use EasyLog\Services\Writer\WriterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 启动 EasyLog Writer
 *
 * 用法：
 *   php artisan easylog:writer
 *   php artisan easylog:writer --group=easylog-writer-1 --batch-size=2000 --flush-seconds=10
 *
 * 处理流程：
 * 1. 创建 KafkaConsumer（按 MQ_BUS_DRIVER 选择 rdkafka|log|inmemory）
 * 2. subscribe ^easylog\.ingest\..* （正则订阅所有写入 topic）
 * 3. 循环 consume → 解码 payload → WriterService::handle
 * 4. 周期性 checkTimeouts 触发超时桶 flush
 * 5. 进程退出时 flushAll + consumer.close
 */
class WriterCommand extends Command
{
    protected $signature = 'easylog:writer
                             {--group=easylog-writer : Kafka consumer group ID}
                             {--batch-size=1000 : 单批触发 flush 的条数}
                             {--flush-seconds=5 : 强制 flush 间隔}
                             {--poll-ms=1000 : 单次 consume 阻塞超时（毫秒）}';

    protected $description = '启动 EasyLog 写入器（消费内部 Kafka → 批量写入存储）';

    private bool $running = true;
    private int $processed = 0;

    public function handle(): int
    {
        $this->info('Starting EasyLog Writer');
        $this->info(sprintf('  Consumer group: %s', $this->option('group')));
        $this->info(sprintf('  Batch size: %d', (int) $this->option('batch-size')));
        $this->info(sprintf('  Flush interval: %ds', (int) $this->option('flush-seconds')));
        $this->info(sprintf('  Poll timeout: %dms', (int) $this->option('poll-ms')));
        $this->info(sprintf('  MQ driver: %s', config('message-queue.bus.driver', 'log')));

        // lite 档（direct 总线）写入发生在接入进程内，无需独立 writer 消费进程
        if (config('message-queue.bus.driver') === 'direct') {
            $this->info('MQ_BUS_DRIVER=direct：日志在接入进程内直写存储，writer 进程无需启动，正常退出。');
            return self::SUCCESS;
        }

        // 健康检查：存储后端（log 驱动也允许启动）
        if (!StorageAdapter::ping()) {
            $this->error('Storage backend unreachable. Check config/storage.php and .env STORAGE_DRIVER.');
            $this->warn('Hint: for local dev without Elasticsearch, run with a StorageAdapter mock.');
            return self::FAILURE;
        }
        $this->info('  Storage backend: ' . config('storage.driver', 'elasticsearch'));

        $writer = new WriterService(
            batchSize:    (int) $this->option('batch-size'),
            flushSeconds: (int) $this->option('flush-seconds'),
            indexPrefix:  config('storage.index_prefix', 'easylog-'),
        );

        $consumer = $this->makeConsumer();
        $topicPattern = '^' . (config('message-queue.bus.topic_prefix', 'easylog.ingest.')) . '.*';
        $consumer->subscribe([$topicPattern]);

        // 信号处理：SIGTERM/SIGINT 优雅退出
        pcntl_signal(SIGTERM, fn () => $this->running = false);
        pcntl_signal(SIGINT,  fn () => $this->running = false);

        $lastTimeoutCheck = time();

        try {
            while ($this->running) {
                $msg = $consumer->consume((int) $this->option('poll-ms'));
                pcntl_signal_dispatch();

                if ($msg === null) {
                    // 超时无消息：定期触发超时 flush
                    if (time() - $lastTimeoutCheck >= 1) {
                        $writer->checkTimeouts();
                        $lastTimeoutCheck = time();
                    }
                    continue;
                }

                $this->processMessage($msg, $writer);
                $consumer->commit();

                if ($this->processed % 1000 === 0) {
                    $this->info(sprintf('  processed=%d', $this->processed));
                }
            }
        } catch (\Throwable $e) {
            Log::error('EasyLog Writer fatal error', ['error' => $e->getMessage()]);
            $this->error('Writer fatal: ' . $e->getMessage());
        } finally {
            $this->info('Writer shutting down, flushing remaining buffers...');
            $writer->flushAll();
            $consumer->close();
            MessageQueue::flush();
            $this->info(sprintf('Writer stopped. Total processed: %d', $this->processed));
        }

        return self::SUCCESS;
    }

    /**
     * 处理一条 Kafka 消息
     *
     * @param array{topic:string,payload:string,key:?string,headers:array} $msg
     */
    private function processMessage(array $msg, WriterService $writer): void
    {
        $payload = $msg['payload'] ?? '';
        if ($payload === '') return;

        try {
            $entry = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            Log::warning('EasyLog writer skip malformed JSON', [
                'topic'   => $msg['topic'] ?? '',
                'error'   => $e->getMessage(),
            ]);
            return;
        }

        if (!is_array($entry)) {
            Log::warning('EasyLog writer skip non-array entry', ['topic' => $msg['topic'] ?? '']);
            return;
        }

        // 从消息头补齐路由字段（容错：消息体已含 _workspace_uuid 等时以消息体为准）
        foreach (['tenant_id', 'workspace_id', 'topic_id'] as $k) {
            $mapKey = '_' . $k;
            if (!isset($entry[$mapKey]) && isset($msg['headers'][$k])) {
                $entry[$mapKey] = $msg['headers'][$k];
            }
        }

        $writer->handle($entry);
        $this->processed++;
    }

    /**
     * 按 MQ_BUS_DRIVER 选择 consumer 实现
     */
    private function makeConsumer(): KafkaConsumerInterface
    {
        $driver = config('message-queue.bus.driver', 'log');
        $config = config('message-queue.bus', []);
        $config['group_id'] = $this->option('group');
        $config['auto_offset_reset'] = 'latest';

        return match ($driver) {
            'rdkafka' => extension_loaded('rdkafka')
                ? new \EasyLog\Services\MQ\Kafka\RdkafkaConsumer($config)
                : throw new \RuntimeException('ext-rdkafka not loaded but MQ_BUS_DRIVER=rdkafka'),
            'inmemory' => new \EasyLog\Services\MQ\Kafka\InMemoryKafkaConsumer(),
            default    => new \EasyLog\Services\MQ\Kafka\LogKafkaConsumer(),
        };
    }
}
