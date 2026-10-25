<?php

declare(strict_types=1);

namespace EasyLog\Console\Commands;

use EasyLog\Facades\StorageAdapter;
use EasyLog\Models\Topic;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 按月滚动存储索引
 *
 * 职责：
 * 1. 为每个启用 Topic 确保当月索引存在（ensureMonthlyRoll）
 * 2. 按 retention_days 清理过期月份索引（topic 未配置时继承 workspace）
 *
 * 调度建议（每月 1 号 00:05）：
 *   5 0 1 * * php artisan easylog:rollover-index
 *
 * 用法：
 *   php artisan easylog:rollover-index                    # 全部主题
 *   php artisan easylog:rollover-index --workspace=ws-xxx # 指定工作空间
 *   php artisan easylog:rollover-index --dry-run          # 演练，不实际执行
 */
class RolloverIndexCommand extends Command
{
    protected $signature = 'easylog:rollover-index
                             {--workspace= : 仅对指定工作空间（uuid）}
                             {--dry-run : 仅显示不实际执行}';

    protected $description = '按月滚动存储索引（创建新索引 + 清理过期索引）';

    public function handle(): int
    {
        $workspaceFilter = $this->option('workspace');
        $dryRun = (bool) $this->option('dry-run');

        $query = Topic::query()->with('workspace')->where('status', 'active');
        if ($workspaceFilter) {
            $query->whereHas('workspace', fn ($q) => $q->where('uuid', $workspaceFilter));
        }
        $topics = $query->get();

        if ($topics->isEmpty()) {
            $this->warn('No active topics found' . ($workspaceFilter ? " for workspace {$workspaceFilter}" : ''));
            return self::SUCCESS;
        }

        $this->info(sprintf('Rolling over %d topic(s) for %s (%s)', $topics->count(), now()->format('Y-m'), $dryRun ? 'dry-run' : 'live'));

        $created = 0;
        $deleted = 0;

        foreach ($topics as $topic) {
            /** @var \EasyLog\Models\Workspace $workspace */
            $workspace = $topic->workspace;
            if (!$workspace) {
                $this->warn("Topic {$topic->name} has no workspace, skipped");
                continue;
            }

            $prefix = 'easylog-' . $workspace->uuid . '-' . $topic->uuid;
            $retentionDays = (int) ($topic->retention_days ?: $workspace->retention_days ?: 30);

            // 1) 确保当月索引存在
            if (!$dryRun) {
                StorageAdapter::ensureMonthlyRoll($prefix);
            }
            $created++;
            $this->line(sprintf('  → %-40s ensure %s index', $prefix, now()->format('Ym')));

            // 2) 清理过期月份索引（dry-run 同样列出，仅不执行删除）
            $indices = StorageAdapter::listIndices($prefix);
            $cutoff = now()->subDays($retentionDays);
            foreach ($indices as $indexName) {
                $month = $this->extractMonth($indexName);
                if ($month === null) continue;

                $monthDate = \DateTimeImmutable::createFromFormat('!Ym', $month);
                if ($monthDate && $monthDate < $cutoff) {
                    $this->line(sprintf('  → delete %s (retention %dd)', $indexName, $retentionDays));
                    if (!$dryRun) {
                        StorageAdapter::deleteIndex($indexName);
                    }
                    $deleted++;
                }
            }
        }

        $this->info(sprintf('Done: %d index(es) ensured, %d expired index(es) %s', $created, $deleted, $dryRun ? 'would be deleted (dry-run)' : 'deleted'));
        return self::SUCCESS;
    }

    /**
     * 从索引名提取月份：easylog-{ws}-{topic}-{yyyymm} → yyyymm
     */
    private function extractMonth(string $indexName): ?string
    {
        if (!preg_match('/-(\d{6})$/', $indexName, $m)) {
            Log::debug('EasyLog rollover skip non-monthly index', ['index' => $indexName]);
            return null;
        }
        return $m[1];
    }
}
