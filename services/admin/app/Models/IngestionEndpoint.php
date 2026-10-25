<?php

namespace EasyLog\Models;

use EasyLog\Casts\EncryptedConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 接入点（一个日志来源的配置单元 + 凭证）
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property int $topic_id
 * @property string $name
 * @property string $source_type http|sdk|rabbitmq|kafka|redis|activemq|syslog|filebeat
 * @property array $config 类型相关 JSON 配置
 * @property string $app_id 业务方凭证 ID
 * @property string $app_secret_hash 哈希存储
 * @property int $rate_limit_per_sec
 * @property int $daily_quota_gb
 * @property string $status active|disabled
 */
class IngestionEndpoint extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'workspace_id', 'topic_id', 'name', 'source_type',
        'config', 'app_id', 'app_secret_hash',
        'rate_limit_per_sec', 'daily_quota_gb', 'status',
    ];

    /**
     * config 含 MQ / SMTP 等连接凭据（密码、token、SASL 口令），必须加密落库。
     *
     * 用自定义 Caster 而非内置的 'encrypted:array'：内置 cast 读到历史明文行会直接
     * 抛 DecryptException，而本项目的 seeder 与部分写入路径会绕过模型直插该列。
     * Caster 采用「写入一律加密、读取兼容明文」的渐进策略，存量明文由迁移
     * 2026_09_30_000001_encrypt_existing_endpoint_config.php 逐步转换。
     */
    protected $casts = [
        'config' => EncryptedConfig::class,
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
