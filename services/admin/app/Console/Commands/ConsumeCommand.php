<?php

declare(strict_types=1);

namespace EasyLog\Console\Commands;

use EasyLog\Facades\MessageQueue;
use EasyLog\Models\IngestionEndpoint;
use EasyLog\Services\MQ\Consumers\ConsumerFactory;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 启动 MQ 消费者（消费外部 MQ → 投递到内部 Kafka）
 *
 * 每个启用端点一个子进程，父进程负责信号转发与回收；
 * 子进程消费异常自动重启（sleep 3s），避免瞬时错误终止长驻进程。
 * 生产环境建议用 supervisord 托管本命令实现多副本。
 *
 * 用法：
 *   php artisan easylog:consume                 # 所有启用的 MQ 类型
 *   php artisan easylog:consume kafka            # 仅 Kafka
 *   php artisan easylog:consume redis            # 仅 Redis Stream
 */
class ConsumeCommand extends Command
{
    protected $signature = 'easylog:consume
                            {source_type? : MQ 类型 (kafka|rabbitmq|redis|activemq)}
                            {--endpoint= : 仅消费指定 uuid 的接入点}
                            {--memory=512 : 单 worker 内存上限 MB}
                            {--timeout=60 : 心跳上报间隔秒}';

    protected $description = '启动 EasyLog MQ 消费者（消费外部 MQ → 投递到内部 Kafka）';

    /** @var array<int,int> 子进程 PID 列表 */
    private array $childPids = [];

    private bool $running = true;

    public function handle(): int
    {
        $available = MessageQueue::availableConsumers();
        if (empty($available)) {
            $this->error('No MQ consumers enabled in config/message-queue.php');
            return self::FAILURE;
        }

        $sourceType = $this->argument('source_type');
        $types = $sourceType ? [$sourceType] : array_keys($available);
        $invalid = array_diff($types, array_keys($available));
        if (!empty($invalid)) {
            $this->error('Disabled or unknown consumers: ' . implode(', ', $invalid));
            return self::FAILURE;
        }

        $endpoints = $this->loadEndpoints($types);
        if ($endpoints->isEmpty()) {
            $this->warn('No active endpoints found for: ' . implode(', ', $types));
            return self::SUCCESS;
        }

        ini_set('memory_limit', $this->option('memory') . 'M');
        $this->info(sprintf('Starting %d consumer worker(s): %s', $endpoints->count(), implode(', ', $types)));
        $this->info('Memory limit: ' . $this->option('memory') . 'MB');

        // 父进程收到 SIGTERM/SIGINT 后转发给所有子进程，优雅退出
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->forwardSignal(SIGTERM));
        pcntl_signal(SIGINT, fn () => $this->forwardSignal(SIGINT));

        foreach ($endpoints as $endpoint) {
            $this->spawnWorker($endpoint);
        }

        $this->waitForChildren();
        return self::SUCCESS;
    }

    private function loadEndpoints(array $types): Collection
    {
        $query = IngestionEndpoint::query()
            ->with(['workspace', 'topic'])
            ->where('status', 'active')
            ->whereIn('source_type', $types);

        $endpointUuid = $this->option('endpoint');
        if ($endpointUuid) {
            $query->where('uuid', $endpointUuid);
        }

        return $query->get();
    }

    private function spawnWorker(IngestionEndpoint $endpoint): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            Log::error('EasyLog consume fork failed', ['endpoint' => $endpoint->uuid]);
            return;
        }
        if ($pid === 0) {
            // 子进程：断开父进程遗留的 DB 连接，避免连接句柄共享
            DB::disconnect();
            $this->runWorker($endpoint);
            exit(0);
        }
        $this->childPids[] = $pid;
        $this->line(sprintf('  → %s consumer for %s (pid %d)', $endpoint->source_type, $endpoint->name, $pid));
    }

    private function runWorker(IngestionEndpoint $endpoint): void
    {
        $consumer = ConsumerFactory::make($endpoint->source_type);
        while (true) {
            try {
                $consumer->consume($endpoint);
            } catch (Throwable $e) {
                Log::error('EasyLog consumer crashed, restarting in 3s', [
                    'endpoint' => $endpoint->uuid,
                    'error'    => $e->getMessage(),
                ]);
                sleep(3);
            }
        }
    }

    private function waitForChildren(): void
    {
        while ($this->running && !empty($this->childPids)) {
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
            if ($pid === -1) {
                break; // 无子进程
            }
            if ($pid > 0) {
                $this->childPids = array_values(array_diff($this->childPids, [$pid]));
                $this->line("  → worker pid {$pid} exited");
            } else {
                usleep(200_000);
            }
        }
    }

    private function forwardSignal(int $signal): void
    {
        $this->running = false;
        foreach ($this->childPids as $pid) {
            posix_kill($pid, $signal);
        }
    }
}
