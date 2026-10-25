<?php

namespace EasyLog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 日志主题（数据分类粒度，类比 SLS Logstore / CLS Topic）
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property string $name
 * @property string|null $description
 * @property bool $auto_field_index
 * @property int $retention_days
 * @property bool $enable_full_text
 * @property bool $json_extract
 * @property string $status active|disabled
 */
class Topic extends Model
{
    use SoftDeletes;

    protected $fillable = ['uuid', 'workspace_id', 'name', 'description', 'auto_field_index', 'retention_days', 'enable_full_text', 'json_extract', 'status'];

    protected $casts = [
        'auto_field_index' => 'boolean',
        'enable_full_text' => 'boolean',
        'json_extract'     => 'boolean',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function endpoints()
    {
        return $this->hasMany(IngestionEndpoint::class);
    }

    public function fields()
    {
        return $this->hasMany(TopicField::class);
    }
}
