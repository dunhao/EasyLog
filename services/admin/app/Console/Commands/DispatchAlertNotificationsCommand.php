<?php

declare(strict_types=1);

namespace EasyLog\Console\Commands;

use EasyLog\Services\Alert\AlertNotifier;
use Illuminate\Console\Command;
use Throwable;

/**
 * 告警通知投递器（消费 pending 事件 → webhook/邮件等渠道）
 *
 * 用法：
 *   php artisan easylog:dispatch-alert-notifications             # 每 30s 处理一轮
 *   php artisan easylog:dispatch-alert-notifications --once     # 单轮处理（CRON）
 */
class DispatchAlertNotificationsCommand extends Command
{
    protected $signature = 'easylog:dispatch-alert-notifications
                             {--interval=30 : 轮询间隔秒}
                             {--limit=100 : 单轮处理上限}
                             {--once : 仅执行一轮后退出}';

    protected $description = '投递待发送的告警通知（notify_status=pending 的事件）';

    private bool $running = true;

    public function handle(AlertNotifier $notifier): int
    {
        $interval = max(1, (int) $this->option('interval'));

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->running = false);
        pcntl_signal(SIGINT, fn () => $this->running = false);

        $this->info(sprintf('Starting EasyLog alert notifier (interval %ds)', $interval));

        while ($this->running) {
            try {
                $result = $notifier->dispatchPending((int) $this->option('limit'));
                $this->line(sprintf('  → %d dispatched, %d failed', $result['dispatched'], $result['failed']));
            } catch (Throwable $e) {
                $this->error('Dispatch round failed: ' . $e->getMessage());
            }

            if ($this->option('once') || !$this->running) {
                break;
            }
            sleep($interval);
        }

        return self::SUCCESS;
    }
}
