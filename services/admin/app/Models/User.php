<?php

namespace EasyLog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * 用户
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $email
 * @property string $password_hash
 * @property string $name
 * @property string $status active|suspended
 */
class User extends Authenticatable
{
    use HasApiTokens;
    use Notifiable;
    use HasRoles;
    use SoftDeletes;

    // spatie 角色固定挂 web guard（种子数据如此）；Sanctum 请求下默认会误解析为 sanctum guard
    protected $guard_name = 'web';

    protected $fillable = ['tenant_id', 'email', 'password', 'name', 'status'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'last_login_at' => 'datetime',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * 获取所属租户 ID（多租户隔离 trait 调用）
     */
    public function getTenantId(): int
    {
        return $this->tenant_id;
    }
}
