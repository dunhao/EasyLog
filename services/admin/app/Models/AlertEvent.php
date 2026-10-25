<?php

declare(strict_types=1);

namespace EasyLog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 告警事件（规则触发记录，notify_status 由投递模块消费）
 *
 * @property int $id
 * @property string $uuid
 * @property int $rule_id
 * @property \Illuminate\Support\Carbon $fired_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property string $severity info|warning|critical
 * @property array $context
 * @property string $notify_status pending|sent|failed
 */
class AlertEvent extends Model
{
    protected $fillable = [
        'uuid', 'rule_id', 'fired_at', 'resolved_at', 'severity', 'context', 'notify_status',
    ];

    protected $casts = [
        'fired_at'    => 'datetime',
        'resolved_at' => 'datetime',
        'context'     => 'array',
    ];

    public function rule()
    {
        return $this->belongsTo(AlertRule::class, 'rule_id');
    }
}
