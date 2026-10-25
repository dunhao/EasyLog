# 使用说明文档

> 项目：EasyLog
> 版本：v1.0
> 日期：2026-09-13
> 适用：运维、开发、业务方接入同学

## 1. 部署 EasyLog 平台

### 1.1 开发环境（Docker Compose 一键启动）

```bash
git clone https://github.com/easylog/easylog.git
cd easylog
cp .env.example .env
# 编辑 .env，调整 DB_PASSWORD、STORAGE_DRIVER 等

docker compose up -d
```

启动后访问：`http://localhost:8080`

默认账号：`admin@easylog.dev` / `easylog123`

### 1.2 组件清单（开发环境）

| 服务 | 端口 | 镜像 |
|---|---|---|
| Nginx | 8080 | nginx:1.25-alpine |
| Admin API (Laravel) | 9001 | easylog/admin:latest |
| Ingestion Gateway (Swoole) | 9514 | easylog/ingestion:latest |
| Query Service (Swoole) | 9515 | easylog/query:latest |
| Worker (MQ Consumer) | — | easylog/worker:latest |
| Writer (Kafka Consumer) | — | easylog/writer:latest |
| MySQL | 3306 | mysql:8.0 |
| Redis | 6379 | redis:7-alpine |
| Kafka | 9092 | bitnami/kafka:3.7 |
| Elasticsearch | 9200 | elasticsearch:8.13.0 |

> 注：worker 在没有任何外部 MQ 接入点（endpoints）时启动即空闲退出（容器 Exited 0），属预期行为，
> 不是故障。创建 RabbitMQ / Kafka / Redis Stream / ActiveMQ 接入点并启动 worker 后即开始消费。

### 1.3 生产部署（K8s + Helm）

```bash
helm repo add easylog https://charts.easylog.dev
helm install easylog easylog/easylog \
  --namespace easylog --create-namespace \
  --values production-values.yaml
```

关键 values：
```yaml
ingestion:
  replicas: 3
  resources: { requests: { cpu: 500m, memory: 512Mi } }
writer:
  replicas: 3
elasticsearch:
  enabled: true
  cluster:
    nodeCount: 3
    persistence: { size: 100Gi }
kafka:
  replicaCount: 3
  sasl: { enabled: true }
```

### 1.4 升级存储引擎切换

修改 `.env`（compose 部署改 `deploy/compose/.env` 后在服务环境变量中体现）：

```
STORAGE_DRIVER=elasticsearch      # 默认，功能最全
STORAGE_DRIVER=openobserve         # 成本优先
STORAGE_DRIVER=quickwit            # 高吞吐低成本
```

无需改业务代码，`StorageAdapter` 抽象层自动路由。三个后端均完整实现（写入/检索/聚合/月度滚动/保留清理）。

**OpenObserve 专用变量**：

| 变量 | 默认 | 说明 |
|---|---|---|
| OO_URL | http://localhost:5080 | OpenObserve 地址 |
| OO_ORG | easylog | 组织 |
| OO_API_KEY / OO_USERNAME+OO_PASSWORD | — | 认证（二选一） |
| OO_BULK_SIZE | 1000 | 单批写入条数 |

约定：索引名 `easylog-{ws}-{topic}-{yyyymm}` 映射为 OO 流名（连字符转下划线）；
写入时自动注入 `_timestamp`（epoch 微秒，取自文档 ts 字段）供时间过滤与直方图使用。

**Quickwit 专用变量**：`QW_URL`（默认 http://localhost:7280）、`QW_INDEX`（索引前缀，默认 easylog）、`QW_BULK_SIZE`。
写入自动注入 `_ts`（epoch 秒）；索引为 dynamic doc mapping，缺失时按需自动创建。

注意事项：
- 三后端的聚合能力以 Elasticsearch 为基准；OO/QW 对直方图与 terms/指标聚合均已支持，更复杂聚合建议留在 ES
- 切换驱动只影响新写入，历史数据不迁移；检索只落在当前驱动上

## 2. 初始化平台（管理员）

### 2.1 首次登录

1. 访问 `http://localhost:8080`，用默认账号登录。
2. 顶部菜单 → 用户权限 → 修改默认密码。

### 2.2 创建租户

仅 owner 角色可创建。MVP 单实例默认自带 `default` 租户，可在系统设置里改名。

### 2.3 创建工作空间

工作空间 = 项目/产品线。

1. 左侧导航 → 工作空间 → [+ 新建工作空间]
2. 填写：名称（如 `Acme 生产`）、描述、保留天数（默认 30）、Kafka 分区数（默认 6）
3. 创建后会自动生成 `workspace_id`，作为后续接入的命名空间隔离维度。

### 2.4 创建日志主题

1. 工作空间详情 → [+ 新建主题]
2. 填写：主题名（如 `order-logs`）、描述、保留天数、是否开启全文索引、是否启用 JSON 字段自动提取
3. 创建后自动生成索引前缀：`easylog-<ws_uuid>-<topic_uuid>-<yyyymm>`

### 2.5 配置字段索引（可选）

1. 主题列表 → 字段配置按钮
2. 系统自动从首批日志中提取字段；可手动添加字段并设置类型（text/keyword/long/double/date/ip）
3. 默认开启 `ts`、`level`、`service` 索引；`msg` 默认 standard 分词器

## 3. 业务方接入（4 种方式）

### 3.1 方式一：SDK 直推（推荐 PHP 项目）

#### 安装

```bash
composer require easylog/sdk-php
```

#### Monolog 集成

```php
use Monolog\Logger;
use EasyLog\SDK\MonologHandler;

$logger = new Logger('order-service');
$logger->pushHandler(new MonologHandler([
    'app_id'       => 'app_x9f3k2',         // 控制台 → 接入点 → 创建后获得
    'app_secret'   => 'sec_xxxxxxxxxxxxxx', // 创建时一次性返回，请妥善保存
    'endpoint'     => 'https://log.example.com',
    'topic'        => 'order-logs',          // 已创建的日志主题
    'batch_size'   => 1000,                   // 每批最多 1000 条
    'buffer_max'   => 5000,                   // 内存缓冲上限
    'flush_sec'    => 5,                      // 最多每 5 秒 flush
    'timeout_ms'   => 2000,
]));

$logger->info('order created', ['order_id' => 'A123', 'amount' => 199.00]);
$logger->error('payment timeout', ['order_id' => 'A123']);
```

#### 异步特性

- SDK 内部维护内存缓冲，达到 `batch_size` 或 `flush_sec` 触发投递。
- HTTP 调用是非阻塞的（基于 cURL multi）。
- 注册 `register_shutdown_function` 保证进程退出前 flush。
- 失败重试 3 次，仍失败落本地文件 `/tmp/easylog-fail.log`。

### 3.2 方式二：HTTP API 直推（任意语言）

```bash
curl -X POST https://log.example.com/api/v1/ingest/batch \
  -H "Authorization: Bearer <app_secret>" \
  -H "X-EasyLog-App: <app_id>" \
  -H "X-EasyLog-Topic: order-logs" \
  -H "Content-Type: application/json" \
  -d '{
    "logs": [
      {"ts":"2026-09-13T10:00:00Z","level":"INFO","msg":"order created","order_id":"A123","amount":199.00},
      {"ts":"2026-09-13T10:00:01Z","level":"ERROR","msg":"payment timeout","order_id":"A123"}
    ]
  }'
```

返回：
```json
{ "accepted": 2, "rejected": 0, "request_id": "req_a8f3k2" }
```

#### 必填字段

| 字段 | 必填 | 默认值 |
|---|---|---|
| `msg` | ✅ | — |
| `level` | ❌ | INFO |
| `ts` | ❌ | 服务端接收时间 |
| 其他业务字段 | ❌ | 自动 JSON 提取（如开启） |

#### 限流

- 每个 `app_id` 默认 1000 req/s，超额返回 429。
- 每日配额默认 50GB，超额返回 429 + `X-EasyLog-Quota-Exceeded: true`。

### 3.3 方式三：MQ 消费（无需改造业务方）

适用场景：业务方已有 RabbitMQ / Kafka / Redis / ActiveMQ，日志已写入。

#### 操作步骤

1. 控制台 → 接入点 → [+ 新建接入点]
2. 选择来源类型（Kafka / RabbitMQ / Redis / ActiveMQ）
3. 填写连接配置（详见下表）
4. 点击 [测试连接] 验证
5. 保存后，EasyLog 自动启动消费者，从你的 MQ 拉取日志并归一化入库

#### Kafka 配置示例

```json
{
  "brokers": ["kafka1:9092", "kafka2:9092"],
  "topic_pattern": "app-logs-*",
  "consumer_group": "easylog-consumer-app1",
  "sasl_mechanism": "PLAIN",
  "sasl_user": "consumer_user",
  "sasl_password": "secret",
  "tls": true,
  "from_offset": "latest"
}
```

#### RabbitMQ 配置示例

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
  "prefetch_count": 1000
}
```

#### Redis Stream 配置示例

```json
{
  "mode": "stream",
  "dsn": "redis://:password@redis:6379/0",
  "stream_key": "logs:stream",
  "consumer_group": "easylog",
  "consumer_name": "c1",
  "block_ms": 5000,
  "batch_size": 1000
}
```

#### ActiveMQ Stomp 配置示例

```json
{
  "host": "activemq.example.com",
  "port": 61613,
  "login": "consumer_user",
  "passcode": "secret",
  "destination_type": "queue",
  "destination": "app.logs",
  "ack": "client",
  "selector": "level='ERROR'",
  "tls": false
}
```

#### 日志消息格式

MQ 消息体应为 JSON 字符串，至少包含 `msg` 字段。例如：
```json
{"ts":"2026-09-13T10:00:00Z","level":"INFO","service":"order","msg":"order created","order_id":"A123"}
```

如消息不是 JSON，按字符串处理为 `msg` 字段，附加 `_raw` 字段保留原始内容。

### 3.4 方式四：文件 Agent（Filebeat / Fluent Bit）

适用场景：容器、服务器、Nginx/Apache 访问日志文件。

#### Filebeat 示例

```yaml
filebeat.inputs:
  - type: log
    paths: /var/log/nginx/access.log
    fields:
      easylog_topic: nginx-access
      easylog_app_id: app_x9f3k2
    fields_under_root: true

output.kafka:
  hosts: ["kafka:9092"]
  topic: easylog-filebeat
  partition.round_robin: { reachable_only: true }
```

在 EasyLog 控制台创建 Kafka 接入点消费 `easylog-filebeat`，topic 选择 `nginx-access`。

## 4. 日志检索与查询

### 4.1 控制台检索

1. 左侧导航 → 日志检索
2. 选择主题或"全部主题"
3. 输入查询（支持 Lucene / KQL 风格）：
   ```
   level:ERROR AND service:order-service
   ```
4. 选择时间范围
5. 点击搜索，结果带时间分布直方图，按级别堆叠
6. 点击单条日志展开，查看完整字段、`request_id`、`host` 等

### 4.2 高级查询语法

| 语法 | 说明 | 示例 |
|---|---|---|
| `field:value` | 字段匹配 | `level:ERROR` |
| `AND` / `OR` / `NOT` | 逻辑组合 | `level:ERROR AND service:order*` |
| `*` | 通配符 | `service:order-*` |
| `field:[a TO b]` | 范围 | `ts:[2026-09-13T00:00 TO 2026-09-13T10:00]` |
| `"phrase"` | 短语匹配 | `"payment timeout"` |
| `field:/regex/` | 正则 | `order_id:/A\d{4}/` |

### 4.3 通过 SQL 查询（v1.5）

检索接口的 `query` 参数以 `SELECT` 开头时自动走 SQL 转译（其余仍为 Lucene 语法），
**无需换端点**——控制台检索框和 search / topics/{uuid}/search / histogram / agg 四个接口都支持。

```bash
curl -X POST https://log.example.com/api/v1/workspaces/ws_acme_prod/search \
  -H "Authorization: Bearer <jwt_token>" \
  -H "Content-Type: application/json" \
  -d '{
    "query": "SELECT * FROM logs WHERE level = '"'"'ERROR'"'"' AND service LIKE '"'"'order-%'"'"' LIMIT 50",
    "from": "2026-09-13T00:00:00Z",
    "to": "2026-09-13T10:00:00Z"
  }'
```

**支持的语法子集**：

```sql
SELECT * | 字段[, 字段...] FROM <任意表名>
[WHERE 条件 [AND|OR ...]]
[GROUP BY 字段]
[ORDER BY 字段 [ASC|DESC], ...]
[LIMIT n]
```

WHERE 条件：`= != > >= < <=`、`LIKE 'a%b_'`（% → *，_ → ?）、`IN ('a','b')`、
`BETWEEN a AND b`、`IS [NOT] NULL`、`AND / OR / NOT` 与括号分组。

聚合：`count(*) / count(DISTINCT f) / avg / sum / min / max / cardinality(f)`，
含聚合函数或 GROUP BY 时返回 `aggregations` 结构（`total` 为桶数或标量值，`hits` 为空）。

**限制**：JOIN / HAVING / 子查询 / 多表不支持；`LIMIT` 上限 1000；
语法错误返回 422 并带具体原因。

### 4.4 通过 API 查询

```bash
curl -X POST https://log.example.com/api/v1/workspaces/ws_acme_prod/search \
  -H "Authorization: Bearer <jwt_token>" \
  -H "Content-Type: application/json" \
  -d '{
    "query": "level:ERROR AND service:order-service",
    "from": "2026-09-13T00:00:00Z",
    "to": "2026-09-13T10:00:00Z",
    "size": 100,
    "sort": [{"ts": "desc"}]
  }'
```

## 5. 仪表盘与可视化

### 5.1 内置仪表盘

仪表盘库 → 选择 → 预览，包含：
- 订单服务健康度
- 支付链路延迟
- Nginx 访问总览
- Spark 任务 KPI

### 5.2 自定义仪表盘

1. 仪表盘库 → [+ 新建仪表盘]
2. 添加图表 → 选择查询主题 → 选图表类型（折线 / 柱状 / 饼图 / 表格）
3. 设置聚合维度（按时间分桶 / 按字段分组 / Top N）
4. 拖拽布局 → 保存

## 6. 告警配置

### 6.1 创建告警规则

1. 告警 → [+ 新建规则]
2. 填写：
   - 名称
   - 监控主题
   - 指标：**日志条数**（窗口计数超阈值）或 **异常检测**（v1.5，基线偏离告警）
   - 触发阈值：条数指标为窗口内条数上限；异常检测为 σ 倍数（偏离基线多少个标准差）
   - 统计窗口：秒（如 60）
   - 严重级别：critical / warning / info
   - 通知渠道：webhook（填 URL）/ email（填收件人，逗号分隔），可多选
   - 启用开关

**异常检测（v1.5）**：对当前窗口计数与之前 N 个基线窗口（默认 10，可配 3~60）做统计，
偏离超过 σ 倍标准差即触发；方向可选 双向 / 仅突增 / 仅骤降。
适用"长期平稳、突变更敏感"的场景（如长期零错误突然出现错误）。
平坦基线（历史窗口计数恒定）时退化为绝对量判定：偏离至少达到 σ 条才触发，避免单条毛刺轰炸。

### 6.2 通知渠道配置

渠道在**每条规则的表单里配置**（无需全局预设页）：

- **Webhook**：填 URL（Slack / 钉钉 / 企微机器人等均为 Incoming Webhook URL 变体，
  消息体为 `{text, severity, fired_at, context}` JSON POST）
- **邮件**：填收件人列表（逗号分隔）；SMTP 由全局环境变量提供：
  `MAIL_MAILER=smtp`、`MAIL_HOST`、`MAIL_PORT`、`MAIL_USERNAME`、`MAIL_PASSWORD`、`MAIL_FROM_ADDRESS`
  （compose 部署在 `deploy/compose/.env` 中配置，默认 `log` 驱动即邮件内容写日志不真发）。
  单条规则也可在渠道 `config.smtp` 中覆盖 host/port/账号。

通知由 worker 容器内的投递进程消费 pending 事件发出（30s 一轮）；
投递结果记录在事件的 `notify_status`（pending / sent / failed）。

### 6.3 告警事件管理

告警列表 → 「事件」按规则查看触发记录（分页），支持：
- 查看触发时间 / 级别 / 通知状态 / 上下文（计数、基线统计等）
- 「标记处理」：写入 `resolved_at`（POST …/alert-events/{uuid}/resolve）
- 暂停规则：规则编辑中关闭启用开关（评估器跳过 paused 规则）

同一规则在一个窗口内只触发一次（last_fired_at 幂等），避免重复轰炸。

## 7. 投递任务（数据流转）

### 7.1 创建投递任务

1. 投递任务 → [+ 新建投递]
2. 选择源主题
3. 选择目标类型：OSS / 文件 / RabbitMQ / Kafka / Redis / ActiveMQ / HTTP
4. 填写目标配置
5. 设置过滤条件：`level:ERROR AND status>=400`（仅投递满足条件的日志）
6. 启动

### 7.2 常见场景

- **归档到 OSS**：所有日志长期归档，降低 ES 存储压力
- **同步下游 Kafka**：让 BI 团队消费原始日志
- **HTTP 转发**：对接第三方安全分析平台

## 8. 消费组（业务方按 Kafka 协议消费）

适用于：业务方需要按 Kafka 协议实时消费 EasyLog 中的日志，用于 ETL、实时大屏、二次分析。

1. 消费组 → [+ 新建消费组]
2. 填写消费组名、目标主题
3. 系统生成 Auth Token（仅显示一次，请保存）
4. 业务方按 Kafka 协议消费：
   ```bash
   kafka-console-consumer.sh \
     --bootstrap-server log.example.com:9094 \
     --topic easylog.ws_acme_prod.tpc_order_logs \
     --consumer-group audit-pipeline \
     --consumer.config client.properties
   ```
   `client.properties`:
   ```
   security.protocol=SASL_SSL
   sasl.mechanism=PLAIN
   sasl.jaas.config=org.apache.kafka.common.security.plain.PlainLoginModule required \
     username="cg_001" password="tok_xxxxxxxxxxxx";
   ```

> 注：消费组 API 在 MVP（v0.1）不提供，v1.0 版本实现。

## 9. 用量配额管理

### 9.1 查看用量

用量配额 → 用量统计 tab：
- 今日写入 GB、查询次数、存储占用、估算费用
- 近 7 天 / 30 天 / 本月趋势图
- 各工作空间用量明细

### 9.2 配额设置

用量配额 → 配额设置 tab：
- 按工作空间设置配额：写入 GB/日、查询次数/日、存储 GB 上限
- 超额行为可选：拒绝 / 告警 / 限制查询

## 10. 用户与权限

### 10.1 角色体系

| 角色 | 权限范围 |
|---|---|
| owner | 租户所有者：管理所有工作空间、用户、计费 |
| admin | 工作空间管理员：管理本工作空间的主题、接入点、告警、仪表盘 |
| viewer | 只读：查询、查看仪表盘、订阅告警 |
| billing | 计费：仅查看用量配额、计费记录 |

### 10.2 邀请用户

用户权限 → [+ 邀请用户] → 邮箱 + 角色 + 工作空间多选 → 发送邀请邮件。

### 10.3 审计日志

用户权限 → 审计日志 tab，记录所有敏感操作：
- 接入点创建/编辑/删除/轮换 secret
- 告警规则更新
- 用户角色变更
- 查询执行记录（可选开启）

## 11. 系统设置

### 11.1 通用设置

- 站点名、默认保留天数、时区、语言（中/英）

### 11.2 存储引擎切换

设置 → 存储引擎 → 选择 Elasticsearch / OpenObserve / Quickwit → 填写连接配置 → 测试连接 → 保存。

### 11.3 接入安全

- IP 白名单：限制接入端点来源 IP（CIDR）
- Token 有效期：默认 90 天，到期前提醒
- 强制 HTTPS：开启后所有 API 强制 TLS

### 11.4 备份归档

设置 → 备份归档 → 选择目标（OSS / S3 / 本地）→ 频率（每日 / 每周）→ 保留份数。

## 12. SDK 多语言支持

### 12.1 PHP SDK

见 §3.1，已稳定。

### 12.2 Java SDK（已提供，sdk/java）

JDK 17+，Maven 坐标 `cool.cnb.devindun:easylog-sdk`，含 Logback Appender。

```java
try (EasyLogClient client = new EasyLogClient(
        EasyLogConfig.builder("app_x9f3k2", "sec_xxx",
                        "http://log.example.com:9514", "<topic-uuid>")
                .batchSize(100).flushSec(1).build())) {
    client.info("order created", Map.of("order_id", "A123"));
}
```

Logback 桥接见 `sdk/java/README.md`；集成测试设 `EASYLOG_ENDPOINT/APP_ID/APP_SECRET/TOPIC` 环境变量后 `mvn test`。

### 12.3 Go SDK（已提供，sdk/go）

Go 1.21+，模块 `github.com/dunhao/EasyLog/sdk/go`，含 slog Handler。

```go
cfg, _ := easylog.NewConfig("app_x9f3k2", "sec_xxx", "http://log.example.com:9514", "<topic-uuid>")
client := easylog.NewClient(cfg)
defer client.Close()

client.Info("order created", map[string]any{"order_id": "A123"})
```

slog 桥接见 `sdk/go/README.md`；集成测试设同样四个环境变量后 `go test`。

三个 SDK 行为对齐：内存缓冲 → 满批/定时投递 → 失败重试 3 次（5xx/429/网络错误退避，4xx 不重试）→ 重试耗尽落本地文件。

## 13. 故障排查

| 现象 | 排查方向 |
|---|---|
| SDK 投递失败 | 检查 app_id/app_secret、网络；查看 `/tmp/easylog-fail.log` |
| HTTP 429 | 限流或配额超限，查看响应头 `X-EasyLog-Quota-Exceeded` |
| MQ 消费停滞 | 接入点详情查看消费状态、Kafka lag、消费组 offset |
| 查询无结果 | 检查时间范围、查询语法、字段索引是否建立 |
| 告警未触发 | 检查规则状态、阈值、时间窗口、查询条件是否匹配 |
| 存储写入失败 | Writer 日志、ES 集群健康、磁盘空间 |

## 14. 常见问题

### Q1: EasyLog 与 ELK 的区别？

EasyLog 是**多租户 SaaS 平台**（一套部署、多个客户独立使用），ELK 是单组织日志分析引擎。EasyLog 用 ES 作存储后端，但提供 SaaS 管理面、计量、配额、多租户隔离。

### Q2: 接入 EasyLog 需要修改业务代码吗？

- **SDK 方式**：PHP 项目加 1 个 Monolog Handler，约 5 行代码。
- **MQ 方式**：业务方已有 MQ 输出日志，**零代码改造**，仅在控制台配置消费者。
- **HTTP API 方式**：任意语言，约 10 行调用代码。

### Q3: 一个租户能创建多少主题？

默认上限 100 个主题，可在系统设置调整。每个主题独立索引、独立保留策略。

### Q4: 存储成本如何控制？

1. 设置保留天数，到期自动清理（按月滚动索引）
2. 切换到 OpenObserve（成本仅 ES 的 1/140）
3. 配置投递任务，将冷数据归档到 OSS，仅 ES 保留近 7 天热数据
4. 字段索引按需开启，避免无用字段占用空间

### Q5: EasyLog 自身日志怎么收集？

EasyLog 自身日志通过内置 SDK 接入到自身，默认写入 `easylog-internal-logs` 主题，可在仪表盘中查看。这是 dogfooding。

## 15. 链接与社区

- GitHub：https://github.com/easylog/easylog
- 文档：https://docs.easylog.dev
- Slack：https://easylog-community.slack.com
- Discord：https://discord.gg/easylog
- Issue 反馈：https://github.com/easylog/easylog/issues

## 16. 关联文档

- [00-research-and-decision.md](00-research-and-decision.md) — 调研与选型决策
- [01-architecture.md](01-architecture.md) — 总体架构
- [02-detailed-design.md](02-detailed-design.md) — 详细设计
- [03-tech-stack-recommendation.md](03-tech-stack-recommendation.md) — 技术栈选型搭配
