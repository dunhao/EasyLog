# 总体架构设计文档

> 项目：EasyLog
> 版本：v1.0
> 日期：2026-09-12
> 上一级文档：[00-research-and-decision.md](00-research-and-decision.md)

## 1. 设计目标

### 1.1 业务目标

1. **多租户 SaaS**：单实例部署、多项目/客户独立使用，元数据严格行级隔离。
2. **零侵入接入**：业务方仅需在自身配置文件中加 5 行配置即可接入。
3. **多源采集**：原生支持 RabbitMQ / Kafka / Redis / ActiveMQ（Stomp） 四种 MQ；外加 HTTP API / Syslog / SDK 直推三种直连方式。
4. **核心功能对齐 SLS / CLS**：日志集/主题、全文与字段索引、SQL 检索、仪表盘、告警、投递、实时消费、计量配额。
5. **PHP 友好**：管理面与控制面 100% PHP 实现，对外 SDK 同时提供 PHP/Java/Go 版本。

### 1.2 非功能目标

| 维度 | 目标 |
|---|---|
| 单实例接入吞吐 | ≥ 50k logs/s（4C8G 测试机、批量大小 1000） |
| 查询 P95 延迟 | < 1s（近 1 小时、单租户、≤ 100M 文档） |
| 多租户隔离 | 任一租户故障不影响其他租户的写入与查询 |
| 水平扩展 | 接入网关、消费者、存储均可独立水平扩 |
| 可用性 | 接入网关无状态、消费者多副本、存储主备/集群 |
| 部署 | 一键 Docker Compose 起 5 节点最小集群 |

## 2. 总体架构

```
┌────────────────────────────────────────────────────────────────────────┐
│                          业务方项目（多语言）                            │
│   PHP App   Java App   Go App   Node App   ...   网络设备              │
└─────────┬──────────┬──────────┬──────────┬──────────┬──────────┬───────┘
          │ SDK      │ HTTP API │ Syslog   │ 文件 Agent│ MQ 直推 │
          ▼          ▼          ▼          ▼          ▼          ▼
┌────────────────────────────────────────────────────────────────────────┐
│ L1 接入层 (Ingestion Gateway)            端口: 9514/HTTP, 9515/Syslog  │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │  Swoole Octane 异步 HTTP 服务（无状态、可水平扩）               │  │
│  │  - 鉴权 (AppID + AppSecret / Token)                             │  │
│  │  - 配额/限流（per app_id）                                       │  │
│  │  - 日志结构归一化 → 投递到内部 Kafka                            │  │
│  └──────────────────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │  MQ Consumer Hub (Laravel Queue Worker × N)                     │  │
│  │  - RabbitMQ Consumer   - Kafka Consumer                          │  │
│  │  - Redis Stream Consumer        - ActiveMQ Stomp Consumer         │  │
│  │  - 归一化后投递到内部 Kafka                                      │  │
│  └──────────────────────────────────────────────────────────────────┘  │
└───────────────────────────┬────────────────────────────────────────────┘
                            ▼
┌────────────────────────────────────────────────────────────────────────┐
│ L2 内部消息总线（统一 Kafka，作为内部标准）                          │
│  Topics: easylog.ingest.<workspace_id>.<topic_id>                    │
│  - 分区数 = workspace_id 哈希到固定桶                                │
│  - 保留 24h（容灾回放）                                                │
└───────────────────────────┬────────────────────────────────────────────┘
                            ▼
┌────────────────────────────────────────────────────────────────────────┐
│ L3 写入与索引层 (Writer × N)                                          │
│  - 批量聚合（默认 1000 条 / 5s 触发）                                 │
│  - 字段自动提取与索引映射                                             │
│  - 路由到 Storage 后端                                                │
│  ┌──────────────────────────────────────────────────────────────┐    │
│  │ Storage Adapter（可切换）                                     │    │
│  │  - Elasticsearch Adapter (默认)                              │    │
│  │  - OpenObserve Adapter (成本优先)                            │    │
│  │  - Quickwit Adapter (高吞吐低成本)                           │    │
│  └──────────────────────────────────────────────────────────────┘    │
└───────────────────────────┬────────────────────────────────────────────┘
                            ▼
┌────────────────────────────────────────────────────────────────────────┐
│ L4 存储与检索引擎（外部组件、可替换）                                 │
│  Elasticsearch 8 集群 / OpenObserve / Quickwit                       │
│  Index 命名：easylog-<workspace_id>-<topic_id>-<yyyymm>             │
└───────────────────────────┬────────────────────────────────────────────┘
                            ▼
┌────────────────────────────────────────────────────────────────────────┐
│ L5 查询层 (Query Service, Swoole)                                    │
│  - 鉴权 → 跨 Topic 路由 → 转译为 Storage 查询 DSL                    │
│  - 多 Topic 联邦查询                                                   │
│  - 结果二次过滤（保证 tenant 隔离）                                   │
└───────────────────────────┬────────────────────────────────────────────┘
                            ▼
┌────────────────────────────────────────────────────────────────────────┐
│ L6 控制面 / 管理面 (Admin API + Web)                                 │
│  Laravel 11 + Vue3 (前端)                                            │
│  功能：租户/项目/Topic/接入点/索引/告警/仪表盘/投递/计量/审计         │
└────────────────────────────────────────────────────────────────────────┘

辅助组件:
- MySQL: 元数据
- Redis: 缓存 + 限流计数 + WebSocket + 实时流
- Prometheus + Grafana: 自身可观测
```

## 3. 核心分层职责

### L1 接入层

- **HTTP 接入网关**（Swoole）：业务方直接 POST 日志，支持单条 / 批量。
- **MQ 消费者组**（Laravel Queue Worker）：从 RabbitMQ / Kafka / Redis / ActiveMQ 拉取日志，转换为内部统一格式后投递到内部 Kafka。
- **SDK 直推**：PHP/Java/Go SDK 直接走 HTTP 网关。
- **文件 Agent**（可选）：基于 Filebeat / Fluent Bit 抽象的轻量采集器，输出到任意一种外部 MQ。

### L2 内部总线

- 强制使用 Kafka 作为内部总线，**理由**：
  - 内部需要持久化、回放、多消费者订阅（写入 / 投递 / 实时消费 / 告警并行消费）。
  - PHP 端用 rdkafka 扩展实现高性能 Producer / Consumer。
- Topic 命名规范：`easylog.ingest.{workspace_id}.{topic_id}`。

### L3 写入与索引层

- 消费内部 Kafka → 批量聚合 → Storage Adapter。
- 字段自动提取：JSON / 正则 / KV / 自定义 Grok。
- 索引策略：默认开全文索引；字段索引按需配置。

### L4 存储引擎

- 可切换抽象层；通过 `.env` 配置 `STORAGE_DRIVER=elasticsearch|openobserve|quickwit`。
- 索引命名：`easylog-<workspace_id>-<topic_id>-<yyyymm>`，按月滚动。

### L5 查询层

- 接收用户查询 → 鉴权 → 跨多个 Topic 联邦查询 → 转译为 Storage DSL → 二次过滤保证租户隔离。

### L6 控制面 / 管理面

- Laravel 11 提供 Admin REST API。
- Vue 3 单页应用（Element Plus）作为控制台。

## 4. 多租户隔离模型

### 4.1 三级层次

```
Tenant (租户)         ─── 商业客户
  └ Workspace (工作空间) ─── 项目/产品线
       └ Topic (日志主题) ─── 日志分类
            └ Index (索引) ─── 按月滚动
```

- **租户**：商业隔离单元，独立的配额、计量、计费、用户体系。
- **工作空间**：租户内的逻辑分组，对应一个项目/产品。
- **主题**：日志数据分类粒度，类似 SLS 的 Logstore / CLS 的 Topic。
- **索引**：按月物理滚动，便于冷热分离与归档。

### 4.2 隔离机制

| 层 | 隔离方式 |
|---|---|
| 元数据 (MySQL) | `tenant_id` 强制 WHERE 条件，使用全局查询拦截器 |
| 接入网关 | AppID 绑定 `tenant_id + workspace_id`，请求上下文注入 |
| 内部 Kafka Topic 命名 | 包含 `workspace_id`，消费组按 workspace 区分 |
| 存储索引 | 物理索引按 `workspace_id` 命名，Query Layer 二次过滤 |
| 资源配额 | 限流计数器 per `app_id`，超额 429 |

## 5. 部署形态

### 5.1 单机部署（开发/演示）

```
docker-compose up
```

组件：MySQL + Redis + Elasticsearch (single node) + Kafka (KRaft mode) + EasyLog (admin + ingestion + worker + query) + Nginx

### 5.2 集群部署（生产）

- **接入网关**：2+ 副本（Nginx LB）
- **消费者**：每个 MQ 类型 ≥ 2 副本
- **写入器**：3+ 副本，按 workspace 分区
- **查询器**：2+ 副本
- **管理面**：2+ 副本（可选主备）
- **存储引擎**：Elasticsearch 集群（≥ 3 节点）或 OpenObserve 集群
- **Kafka 集群**：≥ 3 节点（KRaft 模式，无需 ZooKeeper）

## 6. 模块划分

| 模块 | 类型 | 职责 |
|---|---|---|
| `easylog/admin` | Laravel App | 管理面 REST API + Vue 前端 |
| `easylog/ingestion` | Swoole App | HTTP 接入网关 |
| `easylog/consumer` | Laravel Worker | 4 种 MQ 消费者 + 内部 Kafka 消费者 |
| `easylog/query` | Swoole App | 查询服务（联邦检索） |
| `easylog/sdk-php` | Composer Package | PHP 业务方 SDK |
| `easylog/sdk-java` | Maven Package | Java SDK |
| `easylog/sdk-go` | Go Module | Go SDK |
| `easylog/agent-config` | 配置示例 | Filebeat / Fluent Bit 模板 |
| `easylog/deploy` | Docker / Helm | 部署清单 |

## 7. 数据流主路径

### 7.1 写入路径（典型）

```
PHP App --SDK--> Ingestion HTTP Gateway
                    │
                    ▼ (验证 + 归一化)
              内部 Kafka Topic
                    │
                    ▼
              Writer (批量聚合)
                    │
                    ▼
              Elasticsearch / OpenObserve (按月索引)
```

### 7.2 查询路径

```
Vue Web ──> Admin API ──> Query Service
                              │
                              ▼ (鉴权 + DSL 转译)
                        Storage Engine
                              │
                              ▼ (结果二次过滤)
                        前端结果
```

### 7.3 告警路径

```
内部 Kafka ──> Alert Evaluator (定时 / 流式)
                   │
                   ▼ (命中规则)
              告警事件表 (MySQL)
                   │
                   ▼
              通知发送器 (Webhook / 邮件 / 企业微信 / 钉钉)
```

## 8. 接入示例（业务方视角）

### 8.1 PHP 业务方接入（Monolog Handler）

```php
// composer require easylog/sdk-php
$logger = new \Monolog\Logger('order-service');
$logger->pushHandler(new \EasyLog\SDK\Handler([
    'app_id'     => 'app_xxx',
    'app_secret' => 'sec_xxx',
    'endpoint'   => 'https://log.example.com',
    'topic'      => 'order-logs',
    'batch_size' => 1000,
    'buffer_max' => 5000,
]));
$logger->info('order created', ['order_id' => 'A123']);
```

### 8.2 通过 Kafka 接入（无 SDK 场景）

业务方现有日志已写入自家 Kafka，仅需在 EasyLog 控制台配置"消费组"信息：

```
Workspace: my-team
Topic: payment-logs
Source Type: kafka
Brokers: kafka1:9092,kafka2:9092
Topic Pattern: payment-logs-*
Group ID: easylog-consumer-payment
```

### 8.3 通过 HTTP API 直推

```bash
curl -X POST https://log.example.com/api/v1/ingest \
  -H "Authorization: Bearer <app_secret>" \
  -H "X-EasyLog-App: <app_id>" \
  -H "X-EasyLog-Topic: order-logs" \
  -H "Content-Type: application/json" \
  -d '{"logs":[{"ts":"2026-09-12T10:00:00Z","level":"INFO","msg":"order","oid":"A123"}]}'
```

## 9. 与 SLS / CLS 功能对齐

| 功能 | 本项目实现方式 |
|---|---|
| Project / Logstore | Workspace / Topic |
| Logtail | Filebeat / Fluent Bit 模板（开箱即用配置） |
| Data Import | MQ 接入配置（控制台可视化配置 4 种 MQ） |
| 索引 | 默认全文索引 + 字段索引按需配置 |
| 查询 | 全文 + 字段 + SQL（基于 ES SQL 或 OpenObserve SQL） |
| 仪表盘 | 内置可视化构建器（基于 ECharts） |
| 告警 | 规则 + 多渠道通知 |
| 投递 | 投递任务（Output: OSS / 文件 / 二次 MQ） |
| 消费组 | 内部 Kafka 消费者 + 对外暴露 Kafka 协议（可选） |
| 计量配额 | per app_id 限流 + 每月配额 + 审计日志 |
| IAM | 多用户 + 角色 + AppID/AppSecret 凭证 |

## 10. 后续文档

- 详细设计：[02-detailed-design.md](02-detailed-design.md)
- 数据模型 / API / 模块细节
- 原型：`/prototype/` 目录
