# 详细设计文档

> 项目：EasyLog
> 版本：v1.0
> 日期：2026-09-12
> 上一级文档：[01-architecture.md](01-architecture.md)

## 1. 数据模型

### 1.1 ER 总览

```
Tenant ──< Workspace ──< Topic ──< Index
   │           │             │
   │           │             └─< IngestionEndpoint ──< IngestionLog
   │           │             └─< AlertRule ──< AlertEvent
   │           │             └─< Dashboard
   │           │             └─< ShippingTask
   │           │             └─< ConsumptionGroup
   │           │             └─< IndexField
   │           └──< User ──< RoleAssignment
   └──< BillingAccount ──< UsageRecord
   └──< Quota
```

### 1.2 表结构

#### 1.2.1 tenants（租户）

| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigint PK | — |
| uuid | char(36) UQ | 对外 ID |
| name | varchar(64) | 租户名 |
| slug | varchar(64) UQ | URL slug |
| status | enum | active/suspended/deleted |
| plan | varchar(32) | free/pro/enterprise |
| created_at | datetime | — |
| updated_at | datetime | — |
| deleted_at | datetime | 软删除 |

#### 1.2.2 workspaces（工作空间）

| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigint PK | — |
| uuid | char(36) UQ | — |
| tenant_id | bigint IDX | — |
| name | varchar(64) | — |
| description | varchar(255) | — |
| retention_days | int | 默认 30 |
| shard_count | int | Kafka 分区数 |
| status | enum | active/disabled |
| created_at / updated_at | datetime | — |

复合索引：`(tenant_id, status)`。所有后续表均带 `workspace_id` 并建复合索引。

#### 1.2.3 topics（日志主题）

| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigint PK | — |
| uuid | char(36) UQ | — |
| workspace_id | bigint IDX | — |
| name | varchar(64) | — |
| description | varchar(255) | — |
| storage_index_prefix | varchar(128) | 计算字段 `easylog-{ws}-{topic}-{yyyymm}` |
| auto_field_index | boolean | 是否开启字段自动索引 |
| retention_days | int | 默认继承 workspace |
| enable_full_text | boolean | 全文索引 |
| json_extract | boolean | 自动 JSON 字段提取 |
| status | enum | active/disabled |
| created_at / updated_at | datetime | — |

#### 1.2.4 ingestion_endpoints（接入点）

每个接入点是一种"日志来源"的配置单元。

| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigint PK | — |
| uuid | char(36) UQ | — |
| workspace_id | bigint IDX | — |
| topic_id | bigint | 目标主题 |
| name | varchar(64) | — |
| source_type | enum | http/sdk/rabbitmq/kafka/redis/activemq/syslog/filebeat |
| config | json | 类型相关配置（见 §2.1） |
| app_id | varchar(48) UQ | 业务方持有凭证 |
| app_secret | varchar(96) | 哈希存储 |
| rate_limit_per_sec | int | 默认 1000 |
| daily_quota | bigint | 默认 0=无限 |
| status | enum | active/disabled |
| created_at / updated_at | datetime | — |

#### 1.2.5 users / role_assignments（用户与授权）

| 表 | 字段 |
|---|---|
| users | id, tenant_id, email, password_hash, name, status, last_login_at |
| role_assignments | id, user_id, workspace_id, role(owner/admin/viewer/billing) |

RBAC 简化为三级角色：owner（租户所有者）、admin（工作空间管理员）、viewer（只读）、billing（计费）。

#### 1.2.6 index_fields（字段索引配置）

| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigint PK | — |
| topic_id | bigint IDX | — |
| field_name | varchar(64) | 如 `level` `request_id` |
| field_type | enum | text/long/double/date/ip/geo |
| indexed | boolean | 是否建索引 |
| analyzer | varchar(32) | standard/keyword/none |
| created_at | datetime | — |

#### 1.2.7 dashboards（仪表盘）

| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigint PK | — |
| workspace_id | bigint IDX | — |
| name | varchar(64) | — |
| layout | json | 图表布局 |
| created_by | bigint | user_id |
| created_at / updated_at | datetime | — |

#### 1.2.8 alert_rules / alert_events

| 表 | 字段 |
|---|---|
| alert_rules | id, workspace_id, topic_id, name, condition(json), window_sec, threshold, severity, notify_channels(json), status, created_at |
| alert_events | id, rule_id, fired_at, resolved_at, severity, context(json), notify_status, created_at |

#### 1.2.9 shipping_tasks（投递任务）

| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigint PK | — |
| workspace_id | bigint IDX | — |
| topic_id | bigint | 源主题 |
| target_type | enum | oss/file/rabbitmq/kafka/redis/activemq/http |
| target_config | json | 类型相关 |
| filter | json | 过滤条件 |
| start_from | datetime | 起始时间 |
| status | enum | active/paused/completed |
| created_at / updated_at | datetime | — |

#### 1.2.10 consumption_groups（消费组）

供业务方按 Kafka 协议消费日志（参考 SLS 数据消费 API）。

| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigint PK | — |
| workspace_id | bigint IDX | — |
| topic_id | bigint | — |
| consumer_group | varchar(64) | 业务方消费组名 |
| auth_token | varchar(96) | 消费凭证 |
| created_at | datetime | — |

#### 1.2.11 quotas / usage_records（配额与计量）

| 表 | 字段 |
|---|---|
| quotas | id, tenant_id, workspace_id, dimension(ingest_bytes/query_count/storage_bytes), limit_value, period(daily/monthly), updated_at |
| usage_records | id, tenant_id, workspace_id, topic_id, day, ingest_bytes, ingest_count, query_count, storage_bytes, created_at |

#### 1.2.12 audit_logs（审计日志）

| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigint PK | — |
| tenant_id | bigint IDX | — |
| user_id | bigint | actor |
| action | varchar(64) | 如 `topic.create` / `endpoint.update` |
| target_type | varchar(32) | — |
| target_id | varchar(64) | — |
| context | json | — |
| ip | varchar(45) | — |
| user_agent | varchar(255) | — |
| created_at | datetime | — |

## 2. 接入点配置 Schema（JSON）

### 2.1 RabbitMQ 接入

```json
{
  "host": "rabbitmq.example.com",
  "port": 5672,
  "vhost": "/",
  "user": "consumer_user",
  "password": "secret",
  "queue": "app_logs",
  "exchange": "logs",
  "routing_key": "app.*",
  "exchange_type": "topic",
  "auto_ack": false,
  "prefetch_count": 1000,
  "tls": false
}
```

### 2.2 Kafka 接入

```json
{
  "brokers": ["kafka1:9092", "kafka2:9092"],
  "topic_pattern": "app-logs-*",
  "consumer_group": "easylog-consumer-app1",
  "sasl_mechanism": "PLAIN|SCRAM-SHA-512|GSSAPI",
  "sasl_user": "...",
  "sasl_password": "...",
  "tls": true,
  "ca_cert": "...",
  "from_offset": "earliest|latest",
  "auto_commit": true
}
```

### 2.3 Redis 接入

```json
{
  "mode": "stream|pubsub|list",
  "dsn": "redis://:password@redis:6379/0",
  "stream_key": "logs:stream",
  "consumer_group": "easylog",
  "consumer_name": "c1",
  "block_ms": 5000,
  "list_key": "logs:list",
  "batch_size": 1000
}
```

### 2.4 ActiveMQ (Stomp) 接入

```json
{
  "host": "activemq.example.com",
  "port": 61613,
  "login": "consumer_user",
  "passcode": "secret",
  "destination_type": "queue|topic",
  "destination": "app.logs",
  "ack": "client",
  "selector": "level='ERROR'",
  "tls": false
}
```

### 2.5 HTTP / SDK 接入

不需要 schema，业务方直接用 `app_id` + `app_secret` 调用 ingestion API。

## 3. API 设计

### 3.1 API 风格

- RESTful + JSON
- 鉴权：JWT（用户）+ AppID/AppSecret（业务方接入）
- 版本：`/api/v1/`
- 限流：基于 Redis 滑动窗口

### 3.2 接入 API（业务方使用，AppID 鉴权）

| Method | Path | 说明 |
|---|---|---|
| POST | `/api/v1/ingest` | 单条/批量日志写入（必带 `X-EasyLog-Topic`） |
| POST | `/api/v1/ingest/batch` | 高吞吐批量写入 |
| GET | `/api/v1/heartbeat` | SDK 健康检查 |

### 3.3 管理 API（控制台使用，JWT 鉴权）

#### 3.3.1 租户与工作空间

| Method | Path | 说明 |
|---|---|---|
| GET | `/api/v1/workspaces` | 列出当前租户的工作空间 |
| POST | `/api/v1/workspaces` | 创建工作空间 |
| GET | `/api/v1/workspaces/{uuid}` | 详情 |
| PATCH | `/api/v1/workspaces/{uuid}` | 更新 |
| DELETE | `/api/v1/workspaces/{uuid}` | 删除（软删） |

#### 3.3.2 主题

| Method | Path | 说明 |
|---|---|---|
| GET | `/api/v1/workspaces/{ws}/topics` | 列出主题 |
| POST | `/api/v1/workspaces/{ws}/topics` | 创建主题 |
| GET | `/api/v1/workspaces/{ws}/topics/{uuid}` | 详情 |
| PATCH | `/api/v1/workspaces/{ws}/topics/{uuid}` | 更新 |
| DELETE | `/api/v1/workspaces/{ws}/topics/{uuid}` | 删除 |
| GET | `/api/v1/workspaces/{ws}/topics/{uuid}/fields` | 字段列表 |
| POST | `/api/v1/workspaces/{ws}/topics/{uuid}/fields` | 增加字段索引 |
| PATCH | `/api/v1/workspaces/{ws}/topics/{uuid}/fields/{id}` | 更新 |
| DELETE | `/api/v1/workspaces/{ws}/topics/{uuid}/fields/{id}` | 删除 |

#### 3.3.3 接入点

| Method | Path | 说明 |
|---|---|---|
| GET | `/api/v1/workspaces/{ws}/endpoints` | 列出 |
| POST | `/api/v1/workspaces/{ws}/endpoints` | 创建（生成 app_id / app_secret） |
| GET | `/api/v1/workspaces/{ws}/endpoints/{uuid}` | 详情 |
| PATCH | `/api/v1/workspaces/{ws}/endpoints/{uuid}` | 更新 |
| POST | `/api/v1/workspaces/{ws}/endpoints/{uuid}/rotate-secret` | 轮换 secret |
| POST | `/api/v1/workspaces/{ws}/endpoints/{uuid}/test` | 测试连接（仅 MQ 类型） |
| DELETE | `/api/v1/workspaces/{ws}/endpoints/{uuid}` | 删除 |

#### 3.3.4 查询

| Method | Path | 说明 |
|---|---|---|
| POST | `/api/v1/workspaces/{ws}/search` | 跨主题搜索 |
| POST | `/api/v1/workspaces/{ws}/topics/{uuid}/search` | 主题内搜索 |
| POST | `/api/v1/workspaces/{ws}/topics/{uuid}/histogram` | 时间分布直方图 |
| POST | `/api/v1/workspaces/{ws}/topics/{uuid}/agg` | 聚合分析 |

搜索请求示例：
```json
{
  "query": "level:ERROR AND service:order",
  "from": "2026-09-12T00:00:00Z",
  "to": "2026-09-12T10:00:00Z",
  "size": 100,
  "sort": [{"ts": "desc"}],
  "fields": ["ts", "level", "service", "msg"]
}
```

#### 3.3.5 仪表盘 / 告警 / 投递 / 消费组

类似 3.3.x，CRUD + 启停操作。

#### 3.3.6 计量与配额

| Method | Path | 说明 |
|---|---|---|
| GET | `/api/v1/workspaces/{ws}/usage` | 用量统计 |
| GET | `/api/v1/workspaces/{ws}/quotas` | 配额查询 |
| PUT | `/api/v1/workspaces/{ws}/quotas` | 配额设置（仅 owner） |

#### 3.3.7 用户与审计

| Method | Path | 说明 |
|---|---|---|
| GET | `/api/v1/users` | 用户列表 |
| POST | `/api/v1/users/invite` | 邀请用户 |
| GET | `/api/v1/audit-logs` | 审计日志 |

### 3.4 内部消费组 API（业务方按 Kafka 协议消费）

可选：在网关上暴露 Kafka Wire Protocol（基于 `longboat` 或自研），让业务方用现有 Kafka 客户端直接消费 EasyLog 中的日志。MVP 不实现，v2.0 规划。

## 4. 模块详细设计

### 4.1 Ingestion HTTP Gateway（Swoole）

技术：Laravel Octane + Swoole 5

接口：`POST /api/v1/ingest[/batch]`

处理流程：

1. 中间件 `ResolveApp`：根据 `X-EasyLog-App` 找到 endpoint 配置；校验 secret。
2. 中间件 `RateLimit`：Redis 滑动窗口；超额返回 429。
3. 中间件 `QuotaCheck`：查询 per-day quota，超额返回 429。
4. 归一化：将日志补齐 `tenant_id / workspace_id / topic_id / received_at / agent_id` 字段。
5. 批量 Producer：将消息异步投递到内部 Kafka topic。
6. 返回 202 Accepted（异步保证）。

性能目标：单 4C 节点 ≥ 20k req/s（批量）。

### 4.2 MQ Consumer Hub（Laravel Queue）

每个 MQ 类型一个 Worker 类，都实现 `ConsumerInterface`：

```php
interface ConsumerInterface {
    public function consume(IngestionEndpoint $ep): void;
    public function validateConfig(array $config): array;
    public function testConnection(array $config): array;
}
```

实现：

- `RabbitMqConsumer`（基于 `php-amqplib`）
- `KafkaConsumer`（基于 `rdkafka`）
- `RedisStreamConsumer`（基于 `predis`）
- `ActiveMqConsumer`（基于 `stomp-php`）

每个 Worker：
1. 启动时加载所有 `source_type=kafka` 等的 endpoints。
2. 每个端点起一个消费循环。
3. 消息归一化后通过内部 Kafka Producer 投递。

水平扩：每个 Worker 类型可独立扩副本，多个副本共享消费组（除 ActiveMQ Stomp 队列消费需要协调）。

### 4.3 Writer（写入器）

技术：Laravel Console Command + Swoole 协程

逻辑：

1. 消费内部 Kafka topic。
2. 批量聚合：1000 条或 5 秒触发。
3. 调用 `StorageAdapter::bulkWrite()` 写入 ES / OpenObserve。
4. 失败重试 3 次，仍失败落 DLQ（MySQL 表 `write_failures`）。

`StorageAdapter` 接口：

```php
interface StorageAdapter {
    public function bulkWrite(string $indexName, array $docs): array;
    public function search(string $indexPattern, array $dsl): array;
    public function aggregate(string $indexPattern, array $dsl): array;
    public function createIndex(string $indexName, array $mapping): void;
    public function ensureMonthlyRoll(string $indexPrefix): void;
    public function deleteIndex(string $indexName): void;
}
```

实现：`ElasticsearchAdapter` / `OpenObserveAdapter` / `QuickwitAdapter`。

### 4.4 Query Service（查询服务）

技术：Swoole HTTP

接口：`POST /api/v1/workspaces/{ws}/search`

流程：

1. 鉴权 + 工作空间校验。
2. 解析 `query` 字符串（支持 Lucene / KQL 简化语法 / SQL）。
3. 路由到目标主题对应的索引模式。
4. 调用 `StorageAdapter::search()`。
5. 二次过滤：确保所有返回文档 `workspace_id` 与当前一致。
6. 返回结构化结果。

### 4.5 Alert Evaluator

定时任务 + 流式：

- **定时模式**（cron）：每分钟扫一次所有启用的告警规则，对过去 N 分钟数据做聚合判断。
- **流式模式**（v2）：消费内部 Kafka topic，对每条日志评估规则。

规则条件示例：
```json
{
  "metric": "count",
  "filter": "level:ERROR",
  "operator": ">",
  "threshold": 100,
  "window_sec": 60
}
```

通知渠道：Webhook / Email / 钉钉机器人 / 企业微信机器人 / Slack。

### 4.6 SDK

`easylog/sdk-php` 主要类：

```php
class EasyLogClient {
    public function __construct(string $appId, string $appSecret, string $endpoint);
    public function topic(string $topic): self;
    public function log(string $level, string $message, array $context = []): void;
    public function flush(): void;  // 强制刷盘
}

class MonologHandler implements \Monolog\Handler\HandlerInterface {
    // 桥接 Monolog 到 EasyLogClient
}
```

异步策略：内部维护内存缓冲，达到 `batch_size` 或 `buffer_max` 或 `flush_interval` 触发投递；进程退出时 hook 刷盘。

## 5. 安全设计

### 5.1 凭证管理

- `app_secret`：32 字节随机串，DB 中存 bcrypt hash，明文仅在创建/轮换时返回一次。
- JWT：用户登录后签发，2h 过期；refresh token 7 天。
- API Token：长期 token（SHA256 存储）。

### 5.2 多租户隔离强约束

- 所有 Eloquent 模型继承 `BelongsToTenant` trait，全局 scope 自动注入 `tenant_id` 条件。
- 跨租户访问抛 `TenantIsolationViolationException`，写入审计日志。
- Storage 层索引名包含 `workspace_id`；查询结果二次过滤。

### 5.3 网络安全

- HTTPS 强制；HSTS。
- 内部 MQ 与存储网络隔离（私有子网）。
- 接入端点测试连接通过沙箱执行，限制超时 5s。

## 6. 可观测性（自举）

- Prometheus exporter（`/metrics`）：接入 QPS / 延迟 / 错误率 / 队列积压。
- Grafana 仪表盘模板（内置）。
- 自身日志通过 EasyLog 自身接入（dogfooding）。

## 7. 部署清单

### 7.1 Docker Compose（开发）

```yaml
services:
  mysql: { image: mysql:8.0 }
  redis: { image: redis:7-alpine }
  kafka: { image: bitnami/kafka:3.7 } # KRaft 模式
  elasticsearch: { image: docker.elastic.co/elasticsearch/elasticsearch:8.13.0 }
  easylog-admin: { build: ./services/admin }
  easylog-ingestion: { build: ./services/ingestion }
  easylog-worker: { build: ./services/worker }
  easylog-writer: { build: ./services/writer }
  easylog-query: { build: ./services/query }
  nginx: { image: nginx:alpine }
```

### 7.2 Helm Chart（生产，摘要）

- Deployment: ingestion / worker-mq / worker-writer / query / admin / alert-evaluator
- Service: ingestion / query / admin
- HPA: ingestion & worker-mq 基于 CPU + 队列长度
- ConfigMap: easylog-config
- Secret: easylog-secrets（DB / Redis / Kafka 凭证）

## 8. 里程碑规划

| 版本 | 范围 |
|---|---|
| **v0.1 MVP** | HTTP 接入 + Kafka 接入 + ES 存储 + 全文检索 + 单租户 |
| **v0.5** | 多租户 + 4 种 MQ + 字段索引 + 简单仪表盘 |
| **v1.0** | 完整告警 + 投递 + 消费组 API + 计量 + SDK 三语言 |
| **v1.5** | OpenObserve 适配 + 异常检测 + SQL 查询 |
| **v2.0** | Kafka Wire Protocol 兼容对外暴露 |

## 9. 后续文档

- 原型：[prototype/index.html](../prototype/index.html)
