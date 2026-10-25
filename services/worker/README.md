# EasyLog Worker (MQ Consumer Hub)

消费外部 4 种 MQ（RabbitMQ/Kafka/Redis/ActiveMQ）的日志消息，归一化后投递到内部 Kafka。

## 设计

- **共享 Laravel 11 安装**：位于 `services/admin/`
- **入口**：`php artisan easylog:consume [source_type?]`
- **水平扩**：每个 MQ 类型可独立扩副本
- **统一抽象**：所有消费者实现 `ConsumerInterface`，消息归一化后统一投递

## 启动

```bash
# 启动所有启用的消费者（按 config/message-queue.php）
cd services/admin
php artisan easylog:consume

# 仅启动 Kafka 消费者
php artisan easylog:consume kafka

# 仅启动 RabbitMQ
php artisan easylog:consume rabbitmq

# 仅启动 Redis Stream
php artisan easylog:consume redis

# 仅启动 ActiveMQ Stomp
php artisan easylog:consume activemq
```

## 消费者实现

每个消费者类实现 `ConsumerInterface`：

```php
interface ConsumerInterface {
    public function consume(IngestionEndpoint $ep): void;
    public function validateConfig(array $config): array;
    public function testConnection(array $config): array;
}
```

| 类 | 依赖扩展 / 包 | 状态 |
|---|---|---|
| `KafkaConsumer` | ext-rdkafka (librdkafka 2.x) | v0.1 MVP |
| `RabbitMqConsumer` | php-amqplib ^4.0 | v0.1 MVP |
| `RedisStreamConsumer` | predis ^2.4 | v0.1 MVP |
| `ActiveMqConsumer` | stomp-php ^6.0 | v0.1 MVP |

## 水平扩

| MQ 类型 | 多副本支持 |
|---|---|
| Kafka | ✅ 自动 consumer group rebalance |
| RabbitMQ | ✅ 多个消费者共享 queue |
| Redis Stream | ✅ consumer group |
| ActiveMQ Stomp | ⚠️ 仅 queue 模式；topic 模式需独立订阅 |

## 监控

- 队列 lag 通过 `/metrics` Prometheus exporter 暴露
- 接入点详情页查看每个端点的消费状态

## 关联代码

- 命令：`services/admin/app/Console/Commands/ConsumeCommand.php`
- 接口：`services/admin/app/Services/MQ/Consumers/ConsumerInterface.php`
- 实现：`services/admin/app/Services/MQ/Consumers/{Kafka,RabbitMq,RedisStream,ActiveMq}Consumer.php`
