<?php

declare(strict_types=1);

namespace EasyLog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 日志投递任务（从存储导出到 OSS/文件/外部 MQ/HTTP）
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property int $topic_id
 * @property string $name
 * @property string $target_type oss|file|rabbitmq|kafka|redis|activemq|http
 * @property array $target_config
 * @property array|null $filter
 * @property \Illuminate\Support\Carbon|null $start_from
 * @property string $status active|paused|completed
 */
class ShippingTask extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'workspace_id', 'topic_id', 'name', 'target_type',
        'target_config', 'filter', 'start_from', 'status',
    ];

    protected $casts = [
        'target_config' => 'array',
        'filter'        => 'array',
        'start_from'    => 'datetime',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function topic()
    {
        return $this->belongsTo(Topic::class);
    }
}
