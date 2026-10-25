<?php

declare(strict_types=1);

namespace EasyLog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 字段索引定义（控制台维护字段类型，写入存储 mapping）
 *
 * @property int $id
 * @property int $topic_id
 * @property string $field_name
 * @property string $field_type text|keyword|long|double|date|ip|geo
 * @property bool $indexed
 * @property string $analyzer
 */
class TopicField extends Model
{
    protected $fillable = ['topic_id', 'field_name', 'field_type', 'indexed', 'analyzer'];

    protected $casts = [
        'indexed' => 'boolean',
    ];

    public function topic()
    {
        return $this->belongsTo(Topic::class);
    }
}
