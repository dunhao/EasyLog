<?php

return [

    // 客户端 SDK / 直推 HTTP 端口（仅 ingestion 服务使用）
    'ingestion_port' => env('INGESTION_PORT', 9514),

    // 接入路径
    'ingest_path' => '/api/v1/ingest',
    'batch_path'  => '/api/v1/ingest/batch',
    'health_path' => '/api/v1/heartbeat',

    // 批量写入阈值
    'batch_size'    => (int) env('INGEST_BATCH_SIZE', 1000),
    'flush_seconds' => (int) env('INGEST_FLUSH_SECONDS', 5),

    // 默认限流（per app_id）
    'rate_limit_per_sec' => (int) env('INGEST_RATE_LIMIT', 1000),

    // 默认每日配额（GB），0=不限
    'daily_quota_gb' => (int) env('INGEST_DAILY_QUOTA_GB', 0),

    // 超额行为：reject | warn_only
    'quota_exceeded_action' => env('INGEST_QUOTA_ACTION', 'reject'),
];
