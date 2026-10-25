<?php

namespace EasyLog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 工作空间（租户内的逻辑分组 = 一个项目/产品）
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $name
 * @property string|null $description
 * @property int $retention_days
 * @property int $shard_count
 * @property int $daily_quota_gb 0=不限
 * @property string $status active|disabled
 */
class Workspace extends Model
{
    use SoftDeletes;

    protected $fillable = ['uuid', 'tenant_id', 'name', 'description', 'retention_days', 'shard_count', 'daily_quota_gb', 'status'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function topics()
    {
        return $this->hasMany(Topic::class);
    }

    public function endpoints()
    {
        return $this->hasMany(IngestionEndpoint::class);
    }

    public function dashboards()
    {
        return $this->hasMany(Dashboard::class);
    }

    public function alertRules()
    {
        return $this->hasMany(AlertRule::class);
    }

    public function shippingTasks()
    {
        return $this->hasMany(ShippingTask::class);
    }

    public function consumptionGroups()
    {
        return $this->hasMany(ConsumptionGroup::class);
    }
}
