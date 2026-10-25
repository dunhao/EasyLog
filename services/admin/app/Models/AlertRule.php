<?php

declare(strict_types=1);

namespace EasyLog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 告警规则（定时评估：窗口内日志数超阈值则触发）
 *
 * condition 结构：
 *   {
 *     "metric": "count",                // 指标类型（MVP 仅 count）
 *     "filter": "level:ERROR",          // 搜索表达式，可选，复用 QueryTranslator
 *   }
 * window_sec / threshold 以表列为准。
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property int $topic_id
 * @property string $name
 * @property array $condition
 * @property int $window_sec
 * @property int $threshold
 * @property string $severity info|warning|critical
 * @property array $notify_channels
 * @property string $status active|paused
 * @property \Illuminate\Support\Carbon|null $last_fired_at
 */
class AlertRule extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'workspace_id', 'topic_id', 'name', 'condition',
        'window_sec', 'threshold', 'severity', 'notify_channels', 'status',
    ];

    protected $casts = [
        'condition'      => 'array',
        'notify_channels'=> 'array',
        'last_fired_at'  => 'datetime',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function topic()
    {
        return $this->belongsTo(Topic::class);
    }

    public function events()
    {
        return $this->hasMany(AlertEvent::class, 'rule_id');
    }
}
