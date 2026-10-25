<?php

declare(strict_types=1);

namespace EasyLog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 看板（控制台可视化布局，layout 存 JSON 面板配置）
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property string $name
 * @property array $layout
 * @property int $created_by
 */
class Dashboard extends Model
{
    use SoftDeletes;

    protected $fillable = ['uuid', 'workspace_id', 'name', 'layout', 'created_by'];

    protected $casts = [
        'layout' => 'array',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
