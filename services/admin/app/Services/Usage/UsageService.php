<?php

declare(strict_types=1);

namespace EasyLog\Services\Usage;

use EasyLog\Models\IngestionEndpoint;
use EasyLog\Models\UsageRecord;
use Illuminate\Support\Facades\Log;

/**
 * 用量计量与配额服务
 *
 * record()：ingest 入口按 endpoint 每日累计 bytes/count（increment 原子写）
 * assertQuota()：写入前分级校验 endpoint → workspace → tenant 的日配额，超限 429 拒绝
 */
final class UsageService
{
    /**
     * 累计用量（endpoint × 日粒度）
     */
    public function record(IngestionEndpoint $endpoint, int $bytes, int $count): void
    {
        if ($bytes <= 0 || $count <= 0) {
            return;
        }

        try {
            $record = UsageRecord::firstOrCreate(
                ['endpoint_id' => $endpoint->id, 'usage_date' => today()->toDateString()],
                [
                    'workspace_id' => $endpoint->workspace_id,
                    'topic_id'     => $endpoint->topic_id,
                    'bytes'        => 0,
                    'count'        => 0,
                ]
            );
            $record->increment('bytes', $bytes);
            $record->increment('count', $count);
        } catch (\Throwable $e) {
            // 计量失败不影响日志写入主链路
            Log::warning('EasyLog usage record failed', [
                'endpoint' => $endpoint->uuid,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * 校验日配额，超限抛 429
     *
     * 生效层级：endpoint → workspace → tenant（0 = 该层不限）。
     * 注意：workspace/tenant 层统计的是当日该范围内所有端点的累计写入。
     */
    public function assertQuota(IngestionEndpoint $endpoint): void
    {
        // 1) endpoint 层
        $quotaGb = $endpoint->daily_quota_gb;
        if ($quotaGb && $quotaGb > 0) {
            $todayBytes = UsageRecord::where('endpoint_id', $endpoint->id)
                ->where('usage_date', today()->toDateString())
                ->value('bytes') ?? 0;
            if ($todayBytes >= $this->gbToBytes((int) $quotaGb)) {
                abort(429, 'Daily quota exceeded (' . $quotaGb . 'GB)');
            }
        }

        // 2) workspace 层
        $wsQuotaGb = $endpoint->workspace?->daily_quota_gb ?? 0;
        if ($wsQuotaGb && $wsQuotaGb > 0) {
            $wsBytes = $this->scopeBytes(['workspace_id' => $endpoint->workspace_id]);
            if ($wsBytes >= $this->gbToBytes((int) $wsQuotaGb)) {
                abort(429, 'Workspace daily quota exceeded (' . $wsQuotaGb . 'GB)');
            }
        }

        // 3) tenant 层
        $tenantId = $endpoint->workspace?->tenant_id;
        if ($tenantId) {
            $tenantQuotaGb = \EasyLog\Models\Tenant::find($tenantId)?->daily_quota_gb ?? 0;
            if ($tenantQuotaGb && $tenantQuotaGb > 0) {
                $tenantBytes = UsageRecord::where('usage_date', today()->toDateString())
                    ->whereIn('workspace_id', function ($q) use ($tenantId) {
                        $q->select('id')->from('workspaces')->where('tenant_id', $tenantId);
                    })
                    ->sum('bytes');
                if ((int) $tenantBytes >= $this->gbToBytes((int) $tenantQuotaGb)) {
                    abort(429, 'Tenant daily quota exceeded (' . $tenantQuotaGb . 'GB)');
                }
            }
        }
    }

    /**
     * 当前端点当日已用字节数
     */
    public function todayBytes(IngestionEndpoint $endpoint): int
    {
        return (int) (UsageRecord::where('endpoint_id', $endpoint->id)
            ->where('usage_date', today()->toDateString())
            ->value('bytes') ?? 0);
    }

    private function scopeBytes(array $scope): int
    {
        return (int) (UsageRecord::where($scope)
            ->where('usage_date', today()->toDateString())
            ->sum('bytes'));
    }

    private function gbToBytes(int $gb): int
    {
        return $gb * 1024 * 1024 * 1024;
    }
}
