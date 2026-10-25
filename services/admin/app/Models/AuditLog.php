<?php

declare(strict_types=1);

namespace EasyLog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 审计日志（管理操作留痕：action/target/context/IP）
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $target_type
 * @property string|null $target_id
 * @property array $context
 * @property string|null $ip
 * @property string|null $user_agent
 */
class AuditLog extends Model
{
    /** 审计记录不可变更，无 updated_at 列 */
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'user_id', 'action', 'target_type', 'target_id',
        'context', 'ip', 'user_agent',
    ];

    protected $casts = [
        'context' => 'array',
    ];
}
