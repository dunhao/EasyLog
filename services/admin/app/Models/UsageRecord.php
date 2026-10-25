<?php

declare(strict_types=1);

namespace EasyLog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 用量计量记录（endpoint 粒度按天累计 bytes/count）
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $topic_id
 * @property int $endpoint_id
 * @property string $usage_date Y-m-d
 * @property int $bytes
 * @property int $count
 */
class UsageRecord extends Model
{
    protected $fillable = ['workspace_id', 'topic_id', 'endpoint_id', 'usage_date', 'bytes', 'count'];

    protected $casts = [
        'usage_date' => 'date:Y-m-d',
    ];

    public function endpoint()
    {
        return $this->belongsTo(IngestionEndpoint::class);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
