<?php

namespace EasyLog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 租户（商业客户）
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string $status active|suspended|deleted
 * @property string $plan free|pro|enterprise
 * @property int $daily_quota_gb 0=不限
 */
class Tenant extends Model
{
    use SoftDeletes;

    protected $fillable = ['uuid', 'name', 'slug', 'status', 'plan', 'daily_quota_gb'];

    public function workspaces()
    {
        return $this->hasMany(Workspace::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
