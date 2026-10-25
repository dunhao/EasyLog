<?php

declare(strict_types=1);

namespace EasyLog\Console\Commands;

use EasyLog\Facades\StorageAdapter;
use EasyLog\Models\AlertEvent;
use EasyLog\Models\AlertRule;
use EasyLog\Services\Query\QueryTranslator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * 告警评估器（定时模式）
 *
 * 每个评估周期扫描所有启用的规则：
 *   1. 对窗口（window_sec）内的日志做计数（search size=0 取 total）
 *   2. 超过 threshold 且窗口内未触发过（last_fired_at 幂等）则落库 AlertEvent
 *   3. 通知投递（webhook/邮件等）由后续模块消费 notify_status=pending 的事件
 *
 * 用法：
 *   php artisan easylog:alert-evaluator             # 每 60s 评估一轮
 *   php artisan easylog:alert-evaluator --once      # 单轮评估（调试/CRON）
 */
class AlertEvaluatorCommand extends Command
{
    protected $signature = 'easylog:alert-evaluator
                             {--interval=60 : 评估间隔秒}
                             {--once : 仅执行一轮后退出}';

    protected $description = '启动告警评估器（每分钟扫描规则）';

    private bool $running = true;

    public function handle(): int
    {
        $interval = max(1, (int) $this->option('interval'));

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->running = false);
        pcntl_signal(SIGINT, fn () => $this->running = false);

        $this->info(sprintf('Starting EasyLog Alert Evaluator (interval %ds)', $interval));

        while ($this->running) {
            try {
                $this->evaluateOnce();
            } catch (Throwable $e) {
                Log::error('EasyLog alert evaluator round failed', ['error' => $e->getMessage()]);
            }

            if ($this->option('once') || !$this->running) {
                break;
            }
            sleep($interval);
        }

        return self::SUCCESS;
    }

    private function evaluateOnce(): void
    {
        $rules = AlertRule::query()
            ->with(['workspace', 'topic'])
            ->where('status', 'active')
            ->get();

        if ($rules->isEmpty()) {
            $this->line('No active alert rules');
            return;
        }

        foreach ($rules as $rule) {
            $this->evaluateRule($rule);
        }
    }

    private function evaluateRule(AlertRule $rule): void
    {
        $workspace = $rule->workspace;
        $topic = $rule->topic;
        if (!$workspace || !$topic) {
            $this->warn("Alert rule {$rule->name} has no workspace/topic, skipped");
            return;
        }

        // 窗口内已触发过则跳过（幂等，避免重复轰炸）
        $windowStart = now()->subSeconds($rule->window_sec);
        if ($rule->last_fired_at && $rule->last_fired_at->gt($windowStart)) {
            return;
        }

        $condition = $rule->condition ?? [];

        if (($condition['metric'] ?? 'count') === 'anomaly') {
            $this->evaluateAnomaly($rule, $condition, $windowStart);
            return;
        }

        // count 指标：窗口计数超过固定阈值
        $count = $this->countWindow($rule, $windowStart, now());

        if ($count <= $rule->threshold) {
            return;
        }

        $this->fireAlert($rule, $count, $windowStart);
    }

    /**
     * 统计 [from, to] 窗口内匹配规则的日志条数
     */
    private function countWindow(AlertRule $rule, \Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to): int
    {
        $translator = new QueryTranslator();
        $dsl = $translator->translate((string) ($rule->condition['filter'] ?? ''), [
            'from' => $from->toISOString(),
            'to'   => $to->toISOString(),
        ]);
        $dsl['size'] = 0; // 只计数不取数据

        $pattern = sprintf('easylog-%s-%s-*', $rule->workspace->uuid, $rule->topic->uuid);
        $result = StorageAdapter::search($pattern, $dsl);
        return (int) ($result['total'] ?? 0);
    }

    /**
     * 异常检测（v1.5）：当前窗口计数 vs 历史基线的 σ 偏离
     *
     * condition 结构：
     *   {metric: 'anomaly', filter: '<lucene>', baseline_windows: 10, direction: 'both'|'spike'|'drop'}
     * threshold 语义变为 σ 倍数（整数，≥1）。
     *
     * 平坦基线（σ≈0，如长期零错误）时退化为绝对量判定：|偏离| ≥ σ 计数才触发。
     */
    private function evaluateAnomaly(AlertRule $rule, array $condition, \Illuminate\Support\Carbon $windowStart): void
    {
        $baselineWindows = max(3, min(60, (int) ($condition['baseline_windows'] ?? 10)));
        $direction = in_array($condition['direction'] ?? 'both', ['spike', 'drop', 'both'], true)
            ? $condition['direction'] : 'both';
        $sigma = max(1, (int) $rule->threshold);
        $now = now();
        $windowSec = $rule->window_sec;

        $current = $this->countWindow($rule, $now->copy()->subSeconds($windowSec), $now);

        $samples = [];
        for ($i = 1; $i <= $baselineWindows; $i++) {
            $to = $now->copy()->subSeconds($windowSec * $i);
            $samples[] = $this->countWindow($rule, $to->copy()->subSeconds($windowSec), $to);
        }

        $mean = array_sum($samples) / count($samples);
        $variance = array_sum(array_map(fn ($s) => ($s - $mean) ** 2, $samples)) / count($samples);
        $std = sqrt($variance);

        $deviation = $current - $mean;
        if ($direction === 'spike' && $deviation <= 0) return;
        if ($direction === 'drop' && $deviation >= 0) return;
        if ($deviation == 0) return;

        if ($std > 0.0001) {
            $deviationSigma = abs($deviation) / $std;
            if ($deviationSigma <= $sigma) return;
        } else {
            // 平坦基线：偏离是"无穷 σ"，要求绝对偏离至少达到 σ 计数，避免单条毛刺轰炸
            if (abs($deviation) < $sigma) return;
            $deviationSigma = null;
        }

        $this->fireAlert($rule, $current, $windowStart, [
            'metric'          => 'anomaly',
            'current'         => $current,
            'baseline_mean'   => round($mean, 2),
            'baseline_std'    => round($std, 2),
            'deviation_sigma' => $deviationSigma !== null ? round($deviationSigma, 2) : 'inf',
            'sigma'           => $sigma,
            'direction'       => $direction,
            'baseline_windows' => $baselineWindows,
        ]);
    }

    private function fireAlert(AlertRule $rule, int $count, \Illuminate\Support\Carbon $windowStart, array $extraContext = []): void
    {
        $context = array_merge([
            'count'       => $count,
            'threshold'   => $rule->threshold,
            'window_sec'  => $rule->window_sec,
            'window_from' => $windowStart->toISOString(),
            'rule'        => $rule->name,
            'severity'    => $rule->severity,
        ], $extraContext);

        AlertEvent::create([
            'uuid'          => (string) Str::uuid(),
            'rule_id'       => $rule->id,
            'fired_at'      => now(),
            'severity'      => $rule->severity,
            'context'       => $context,
            'notify_status' => 'pending',
        ]);

        $rule->update(['last_fired_at' => now()]);

        // 通知投递由 AlertNotifier 消费 notify_status=pending 的事件（webhook / 邮件）
        Log::info('EasyLog alert fired', $context);
        $this->line(sprintf('  → [%s] %s: %s (threshold %s)', $rule->severity, $rule->name,
            isset($context['metric']) ? "{$context['current']} vs mean {$context['baseline_mean']} (σ×{$context['sigma']})" : "{$count} logs in window",
            $rule->threshold));
    }
}
