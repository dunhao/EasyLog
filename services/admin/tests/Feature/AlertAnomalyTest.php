<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\Carbon;
use EasyLog\Facades\StorageAdapter;
use EasyLog\Models\AlertRule;
use EasyLog\Services\Storage\StorageAdapterInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * 异常检测告警测试（v1.5）：基线窗口统计 + σ 偏离触发
 */
class AlertAnomalyTest extends AdminApiTestCase
{
    private const BASE_COUNT = 10;
    private const CURRENT_SPIKE = 100;
    private const CURRENT_NORMAL = 11;
    private const CURRENT_DROP = 1;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function swapCountingStorage(array $countsByFrom): void
    {
        StorageAdapter::swap(new class($countsByFrom) implements StorageAdapterInterface {
            public function __construct(private array $counts) {}

            public function search(string $indexPattern, array $dsl): array
            {
                $from = '';
                foreach ($dsl['query']['bool']['filter'] ?? [] as $clause) {
                    if (isset($clause['range']['ts']['gte'])) {
                        $from = (string) $clause['range']['ts']['gte'];
                    }
                }
                return ['hits' => [], 'total' => $this->counts[$from] ?? 0, 'took_ms' => 0];
            }

            public function bulkWrite(string $indexName, array $docs): array { return ['success' => 0, 'failed' => 0, 'errors' => null]; }
            public function aggregate(string $indexPattern, array $dsl): array { return []; }
            public function createIndex(string $indexName, array $mapping): void {}
            public function ensureMonthlyRoll(string $indexPrefix): void {}
            public function listIndices(string $indexPrefix): array { return []; }
            public function deleteIndex(string $indexName): void {}
            public function ping(): bool { return true; }
        });
    }

    /** 当前窗口 from = now-windowSec；基线 i 的 from = now-windowSec*(i+1) */
    private function expectedCounts(int $windowSec, int $current, int $baseline): array
    {
        $counts = [];
        $now = Carbon::now();
        $counts[$now->copy()->subSeconds($windowSec)->toISOString()] = $current;
        for ($i = 1; $i <= 10; $i++) {
            $counts[$now->copy()->subSeconds($windowSec * ($i + 1))->toISOString()] = $baseline;
        }
        return $counts;
    }

    private function createAnomalyRule(string $direction = 'both', int $sigma = 3): void
    {
        AlertRule::create([
            'uuid'            => (string) Str::uuid(),
            'workspace_id'    => $this->workspaceId,
            'topic_id'        => $this->topicId,
            'name'            => '错误数异常检测',
            'condition'       => ['metric' => 'anomaly', 'filter' => 'level:ERROR', 'baseline_windows' => 10, 'direction' => $direction],
            'window_sec'      => 60,
            'threshold'       => $sigma,
            'severity'        => 'critical',
            'notify_channels' => [['type' => 'webhook', 'config' => []]],
            'status'          => 'active',
        ]);
    }

    public function testSpikeAgainstFlatBaselineFires(): void
    {
        Carbon::setTestNow('2026-09-25 12:00:00');
        $this->swapCountingStorage($this->expectedCounts(60, self::CURRENT_SPIKE, self::BASE_COUNT));
        $this->createAnomalyRule('spike', 3);

        Artisan::call('easylog:alert-evaluator', ['--once' => true]);

        $this->assertDatabaseCount('alert_events', 1);
        $event = \EasyLog\Models\AlertEvent::first();
        $this->assertSame('anomaly', $event->context['metric']);
        $this->assertSame(100, $event->context['current']);
        $this->assertSame(10, $event->context['baseline_mean']);
        $this->assertSame('inf', $event->context['deviation_sigma']); // 平坦基线
    }

    public function testNormalFluctuationDoesNotFire(): void
    {
        Carbon::setTestNow('2026-09-25 12:00:00');
        $this->swapCountingStorage($this->expectedCounts(60, self::CURRENT_NORMAL, self::BASE_COUNT));
        $this->createAnomalyRule('both', 3);

        Artisan::call('easylog:alert-evaluator', ['--once' => true]);

        // 平坦基线下 |11 - 10| = 1 < σ=3 → 不触发
        $this->assertDatabaseCount('alert_events', 0);
    }

    public function testDropDirectionFiresOnDrop(): void
    {
        Carbon::setTestNow('2026-09-25 12:00:00');
        $this->swapCountingStorage($this->expectedCounts(60, self::CURRENT_DROP, self::BASE_COUNT));
        $this->createAnomalyRule('drop', 3);

        Artisan::call('easylog:alert-evaluator', ['--once' => true]);

        $this->assertDatabaseCount('alert_events', 1);
        $event = \EasyLog\Models\AlertEvent::first();
        $this->assertSame('drop', $event->context['direction']);
    }

    public function testSpikeDirectionIgnoresDrop(): void
    {
        Carbon::setTestNow('2026-09-25 12:00:00');
        $this->swapCountingStorage($this->expectedCounts(60, self::CURRENT_DROP, self::BASE_COUNT));
        $this->createAnomalyRule('spike', 3);

        Artisan::call('easylog:alert-evaluator', ['--once' => true]);

        $this->assertDatabaseCount('alert_events', 0);
    }

    public function testNonAnomalyMetricStillUsesFixedThreshold(): void
    {
        Carbon::setTestNow('2026-09-25 12:00:00');
        $this->swapCountingStorage($this->expectedCounts(60, 100, self::BASE_COUNT));

        AlertRule::create([
            'uuid'            => (string) Str::uuid(),
            'workspace_id'    => $this->workspaceId,
            'topic_id'        => $this->topicId,
            'name'            => '固定阈值',
            'condition'       => ['metric' => 'count', 'filter' => 'level:ERROR'],
            'window_sec'      => 60,
            'threshold'       => 50,
            'severity'        => 'warning',
            'notify_channels' => [],
            'status'          => 'active',
        ]);

        Artisan::call('easylog:alert-evaluator', ['--once' => true]);

        $this->assertDatabaseCount('alert_events', 1);
        $event = \EasyLog\Models\AlertEvent::first();
        $this->assertArrayNotHasKey('metric', $event->context);
    }
}
