<?php

return [

    // 内部消息总线
    // driver: rdkafka（core/full，需 Kafka 集群与扩展）| direct（lite 单机直写，无中间件）
    //       | log（本地开发，写日志）| inmemory（单元测试）
    'bus' => [
        'driver'   => env('MQ_BUS_DRIVER', 'log'),
        'brokers'  => env('KAFKA_BROKERS', 'localhost:9092'),
        'sasl'     => [
            'enabled'   => (bool) env('KAFKA_SASL_ENABLED', false),
            'mechanism' => env('KAFKA_SASL_MECHANISM', 'PLAIN'),
            'user'      => env('KAFKA_SASL_USER'),
            'password'  => env('KAFKA_SASL_PASSWORD'),
        ],
        'tls'      => (bool) env('KAFKA_TLS', false),
        'ca_cert'  => env('KAFKA_CA_CERT'),
        'topic_prefix' => env('KAFKA_TOPIC_PREFIX', 'easylog.ingest.'),
        // 保留时长（小时），用于回放
        'retention_hours' => (int) env('KAFKA_RETENTION_HOURS', 24),
        // 分区数（按 workspace 哈希到固定桶）
        'partition_count' => (int) env('KAFKA_PARTITION_COUNT', 6),
        // direct 驱动参数：进程内批量大小与超时 flush 秒数（lite 档取小值保证时延）
        'direct' => [
            'batch_size'    => (int) env('DIRECT_BUS_BATCH_SIZE', 200),
            'flush_seconds' => (int) env('DIRECT_BUS_FLUSH_SECONDS', 1),
        ],
    ],

    // 外部 MQ 消费者配置
    'consumers' => [

        'kafka' => [
            'enabled'        => (bool) env('CONSUMER_KAFKA_ENABLED', true),
            'worker_count'   => (int) env('CONSUMER_KAFKA_WORKERS', 2),
            'poll_timeout_ms'=> (int) env('CONSUMER_KAFKA_POLL_MS', 5000),
        ],

        'rabbitmq' => [
            'enabled'        => (bool) env('CONSUMER_RABBITMQ_ENABLED', true),
            'worker_count'   => (int) env('CONSUMER_RABBITMQ_WORKERS', 2),
            'prefetch_count' => (int) env('CONSUMER_RABBITMQ_PREFETCH', 1000),
        ],

        'redis' => [
            'enabled'        => (bool) env('CONSUMER_REDIS_ENABLED', true),
            'worker_count'   => (int) env('CONSUMER_REDIS_WORKERS', 2),
            'block_ms'       => (int) env('CONSUMER_REDIS_BLOCK_MS', 5000),
            'batch_size'     => (int) env('CONSUMER_REDIS_BATCH', 1000),
        ],

        'activemq' => [
            'enabled'        => (bool) env('CONSUMER_ACTIVEMQ_ENABLED', true),
            'worker_count'   => (int) env('CONSUMER_ACTIVEMQ_WORKERS', 1),
        ],
    ],
];
