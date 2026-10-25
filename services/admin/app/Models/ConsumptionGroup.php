<?php

declare(strict_types=1);

namespace EasyLog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 消费组（第三方通过内部 Kafka 拉取日志，auth_token 换消费凭证）
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property int $topic_id
 * @property string $consumer_group
 * @property string $auth_token_hash
 */
class ConsumptionGroup extends Model
{
    protected $fillable = ['uuid', 'workspace_id', 'topic_id', 'consumer_group', 'auth_token_hash'];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function topic()
    {
        return $this->belongsTo(Topic::class);
    }
}
