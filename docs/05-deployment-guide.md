# EasyLog 部署指南

> 版本：v1.5（三档按需启动：lite 默认零依赖 / core / full；240 tests 全绿）
> 日期：2026-10-25
> 适用：运维 / 部署负责人

## 0. 启动档位（先选档）

| 档位 | 启动 | 依赖 | 场景 |
|---|---|---|---|
| **lite**（默认） | `cd deploy/compose && ./start.sh` | **无**（SQLite + 直写总线 + file 缓存，2 容器 ~200MB） | 个人/开发/小团队，10 分钟跑通 |
| **core** | `./start.sh core` | 宿主机 MySQL + vm.max_map_count≥262144（compose 起 Redis/ES/ZK/Kafka） | 团队/生产单机 |
| **full** | `./start.sh full` | 同 core（另起 RabbitMQ + worker） | 外部 MQ 接入 + 命令行告警巡检 |

> 业务项目接入三档通用：`sdk/agent/`（采集已有日志文件，免改代码）或
> `sdk/php|go|java`（代码内推送），HTTP 接入 API + AppID/Secret
> （lite `:9515` / core、full `:9514`）。

- 档位互斥：lite 的 `app` 与 core 的 `admin` 都占宿主 9515，切档先 `./start.sh stop`。
- lite → core 是场景选择而非数据升级（SQLite 与 ES 的日志数据不互相迁移）。
- lite 档控制台/管理 API/接入 API 都在 9515（单容器），检索功能（Lucene 子集/SQL 子集/直方图/聚合）完整可用。

## 1. 组件清单与版本（core / full 档；lite 档仅 PHP 镜像 + frontend）

| 组件 | 用途 | 版本 | 端口 |
|---|---|---|---|
| PHP（CLI + FPM） | 应用运行时 | **8.2+**（必须，^8.2） | — |
| Swoole 扩展 | Octane HTTP 服务（admin/ingestion/query） | pecl swoole（4.8+/5.x） | — |
| MySQL | 元数据（租户/工作空间/主题/端点/告警/用量） | 8.0 | 3306 |
| Redis | 缓存/限流/会话 | 7.x（compose 用 7.4） | 6379 |
| Kafka | 内部消息总线（ingest → writer） | 3.x（confluent 7.5 镜像） | 9092 |
| Elasticsearch | 日志存储（默认后端） | **8.x**（compose 用 8.13） | 9200 |
| RabbitMQ | 外部 MQ 接入（可选，业务方已有 MQ 时用） | 3.13 | 5672 / 15672 |
| Node.js | 前端控制台构建 | 20 LTS | — |

应用端口：

| 服务 | 端口 | 说明 |
|---|---|---|
| Ingestion 网关 | 9514 | 业务方推送日志（`/api/v1/ingest`） |
| Admin / Query API | 9515 | 控制台调用（同一 Octane 实例，路由按 `api/v1` 前缀区分） |
| 前端控制台 | 9516 | Vue3 + Vite dev server |

## 2. 服务器配置建议

### 2.1 MVP / 试用（单机）

| 项 | 建议 |
|---|---|
| CPU | 4 核（8 核更稳，Swoole worker + Kafka + ES 都在本机） |
| 内存 | **8 GB**（ES 512m~1g + Kafka 1g + PHP worker 4×256m + MySQL/Redis） |
| 磁盘 | **100 GB SSD**（日志数据在 ES 索引里，按月滚动可清理；Kafka 落盘 24h 默认保留） |
| 网络 | 1 Gbps 内网（无强要求） |

### 2.2 生产（多机）

| 节点 | 建议配置 | 承载 |
|---|---|---|
| 应用节点 ×2 | 8C16G SSD | admin/ingestion/query/writer/worker（compose 或裸机） |
| 元数据节点 | 4C8G SSD | MySQL + Redis（可独立或合到存储节点） |
| Kafka 节点 ×3 | 4C8G SSD×2 | 内部消息总线（replication=3 时） |
| ES 节点 ×3 | 8C32G SSD×2 | 日志数据（按 30 天保留 100Gi/天 估算，1 天 1 亿条 ≈ 80GB 原始 + 副本） |
| 告警/定时节点 | 2C4G | alert-evaluator / dispatch / rollover（可并入应用节点） |

> 存储估算：日志按 1KB/条、日写入 1000 万条 ≈ 10GB/天原始数据。ES 建议预留 1.5×（segment 膨胀）。

## 3. 部署方式 A：Docker Compose（推荐，最快跑通）

```bash
# 1. 克隆仓库
git clone <repo-url> EasyLog
cd EasyLog

# 2. lite 档（默认）：零依赖，一条命令
cd deploy/compose && ./start.sh
# start.sh 自动：生成 .env + 随机 APP_KEY → 起 app + frontend → 等待就绪

# 3. core / full 档（需要宿主机 MySQL，.env 的 EASYLOG_DB_*）
./start.sh core     # redis/es/zk/kafka + admin/ingestion/query/writer + frontend
./start.sh full     # core + rabbitmq + worker（外部 MQ 消费 + 命令行告警巡检）
```

首次启动流程（entrypoint.sh 自动完成）：
1. 应用镜像内 `composer install`（admin 容器，vendor 卷共享给全部角色容器；lite 的 app 容器镜像已预装）
2. admin 角色生成 `APP_KEY` 并执行 `php artisan migrate` + `db:seed`（MySQL 或 SQLite 均支持）
3. 按 `SERVICE_ROLE` 启动各进程（admin→octane:9515，ingestion→octane:9514，writer→`easylog:writer`，worker→`easylog:consume`；lite 的 app→admin 角色 + direct 直写总线）

验证（lite 档）：

```bash
curl -s localhost:9515/api/v1/health                        # {"status":"ok"}
open http://localhost:9516/                                 # 控制台（admin@easylog.local）
```

验证（core 档）：

```bash
# 数据层健康
curl -s localhost:9200/_cluster/health | jq .status        # green/yellow

# 应用健康
curl -s localhost:9515/api/v1/health                        # {"status":"ok"}
curl -s localhost:9514/api/v1/ingest/heartbeat              # {"status":"ok",...}
```

> 注意：compose 的 `admin` 与 `query` 都监听 9515（同一代码、同一路由），二者是冗余角色，可只保留一个。

## 4. 部署方式 B：裸机部署

### 4.1 软件环境要求（逐项核对）

**PHP 8.2**（系统自带可能为 8.0/8.1，务必确认）：

```bash
php -v    # 必须 >= 8.2
```

**PHP 扩展**（与镜像内一致）：

| 扩展 | 来源 | 必需性 |
|---|---|---|
| pdo_mysql, mysqli | 系统包 | 必需（元数据存 MySQL） |
| pcntl, sockets | 系统包 | 必需（Octane/命令信号处理） |
| intl, zip, opcache, bcmath | 系统包 | 必需 |
| **swoole** | `pecl install swoole` | 必需（Octane HTTP 服务） |
| **rdkafka** | `pecl install rdkafka`（需 librdkafka） | **生产必需**（内部 Kafka 总线）；缺省时 `MQ_BUS_DRIVER=log` 降级（仅开发） |
| redis | `pecl install redis` | 推荐（会话/缓存走 Redis） |
| stomp | `pecl install stomp` | 仅 ActiveMQ 接入时 |

**外部组件**（同 §1 版本）：MySQL 8.0、Redis 7.x、Kafka 3.x、Elasticsearch 8.x（可选 RabbitMQ 3.13）。

### 4.2 初始化应用

```bash
cd services/admin
cp .env.example .env
php artisan key:generate

# 编辑 .env（关键项，见 §5）
vim .env

# 元数据迁移 + 基础数据（租户/工作空间/主题/管理员）
php artisan migrate --force
php artisan db:seed --force
```

### 4.3 启动各服务进程（建议用 systemd/supervisord 托管）

| 进程 | 命令 | 说明 |
|---|---|---|
| API 网关 | `php artisan octane:start --server=swoole --host=0.0.0.0 --port=9515 --workers=4` | admin + query 共用，监听 9515 |
| Ingestion | `php artisan octane:start --server=swoole --host=0.0.0.0 --port=9514 --workers=4` | 或 `php artisan easylog:ingest-server` |
| Writer | `php artisan easylog:writer` | 常驻：消费内部 Kafka → 批量写 ES |
| MQ 消费者 | `php artisan easylog:consume` | 常驻：外部 MQ → 内部 Kafka（可选，按需启） |
| 告警评估 | `php artisan easylog:alert-evaluator --interval=60` | 常驻：每分钟扫描规则 |
| 通知投递 | `php artisan easylog:dispatch-alert-notifications --interval=30` | 常驻（或 CRON `--once` 每 30s） |

**Cron**（每月 1 日滚动索引）：

```cron
0 5 1 * *  cd /path/services/admin && /usr/bin/php artisan easylog:rollover-index >> /var/log/easylog-rollover.log 2>&1
```

**Nginx 反代**（参考 `deploy/docker/nginx/easylog.conf`）：9514/9515 对外统一 80/443。

### 4.4 systemd 单元示例（writer）

```ini
[Unit]
Description=EasyLog Writer
After=network.target kafka.service elasticsearch.service

[Service]
User=www-data
WorkingDirectory=/path/services/admin
ExecStart=/usr/bin/php artisan easylog:writer
Restart=always
RestartSec=5
Environment=APP_ENV=production

[Install]
WantedBy=multi-user.target
```

## 5. 环境变量清单（services/admin/.env）

| 分组 | 变量 | 默认 | 说明 |
|---|---|---|---|
| 应用 | APP_ENV | local | 生产设 `production` |
| | APP_KEY | （必填） | `php artisan key:generate` |
| 数据库 | DB_CONNECTION | sqlite | 生产改 `mysql` |
| | DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD | mysql:3306/easylog | MySQL 连接 |
| Redis | REDIS_HOST / REDIS_PORT / REDIS_PASSWORD | redis:6379 | 会话/缓存/限流 |
| 存储 | STORAGE_DRIVER | elasticsearch | `elasticsearch` / `openobserve` / `quickwit` |
| | ES_HOSTS | http://elasticsearch:9200 | ES 地址（逗号分隔多节点） |
| | ES_USERNAME / ES_PASSWORD / ES_API_KEY | 空 | ES 认证（xpack 开启时） |
| | ES_INDEX_PREFIX | easylog- | 索引前缀 |
| | ES_BULK_SIZE | 1000 | 批量写入条数 |
| 内部消息 | MQ_BUS_DRIVER | log | **生产设 `rdkafka`**（需 ext-rdkafka） |
| | KAFKA_BROKERS | kafka:9092 | broker 列表 |
| | KAFKA_SASL_ENABLED / KAFKA_SASL_* | false | Kafka 认证 |
| | KAFKA_RETENTION_HOURS | 24 | 内部总线保留时长 |
| 外部消费者 | CONSUMER_KAFKA_ENABLED 等 | true | 4 类 MQ 消费者开关/worker 数 |
| Octane | OCTANE_WORKERS | 4 | worker 进程数（按 CPU 核数调） |
| | OCTANE_PORT | 8000 | 网关端口 |

## 6. 初始化与业务验证

### 6.1 首次登录与管理数据

seed 后使用管理员账号登录控制台（`auth:sanctum` 发 token），按序创建：
租户 → 工作空间 → 日志主题 → 接入端点（记录返回的 `app_id` / `app_secret`）。

### 6.2 推送日志冒烟

```bash
# 单条
curl -s -X POST localhost:9514/api/v1/ingest \
  -H "X-EasyLog-App: <app_id>" \
  -H "Authorization: Bearer <app_secret>" \
  -H "Content-Type: application/json" \
  -d '{"msg":"hello easylog","level":"INFO"}'
# → {"accepted":1,"rejected":0,"request_id":"..."}

# 批量
curl -s -X POST localhost:9514/api/v1/ingest/batch \
  -H "X-EasyLog-App: <app_id>" \
  -H "Authorization: Bearer <app_secret>" \
  -H "Content-Type: application/json" \
  -d '{"logs":[{"msg":"a"},{"msg":"b"}]}'
```

### 6.3 检索验证

```bash
curl -s -X POST localhost:9515/api/v1/topics/<topic_uuid>/search \
  -H "Authorization: Bearer <user_token>" \
  -H "Content-Type: application/json" \
  -d '{"query":"hello","from":"2026-09-14T00:00:00Z","to":"2026-09-15T00:00:00Z"}'
```

### 6.4 链路自检顺序（数据不落盘时按序排查）

1. ingest 返回 202 → 网关正常
2. `php artisan easylog:writer` 日志无报错 → 内部 Kafka 投递 + ES 写入正常
3. 检索命中 → Writer → ES 全链路通

## 7. 容量与调优要点

- **ES 内存**：`ES_JAVA_OPTS=-Xms/-Xmx` 建议 ≤ 物理内存 50%（compose 默认 512m，生产按数据量上调）
- **Kafka 保留**：`KAFKA_RETENTION_HOURS=24` 控制内部总线积压上限；Writer 消费跟不上时调大 worker 或减小 `INGEST_BATCH_SIZE`
- **Swoole worker 数**：`OCTANE_WORKERS` = CPU 核数 × 1.5 左右；`OCTANE_TASK_WORKERS` 留给耗时任务
- **索引滚动**：默认按月 `easylog-{ws}-{topic}-{yyyymm}`，`retention_days`（主题/工作空间）决定清理周期，配合 §4.3 cron 每月执行
- **限流与配额**：端点级 `rate_limit_per_sec`（1000 默认）与 `daily_quota_gb`（0 = 不限），超限分别 429 拒绝

## 8. 常见问题

| 现象 | 原因 | 处理 |
|---|---|---|
| `artisan` 报 PHP 版本错 | 系统 PHP < 8.2 | 安装 8.2，或直接用 `php8.2` 二进制 |
| ingest 202 但 ES 无数据 | `MQ_BUS_DRIVER=log`（开发降级） | 生产装 ext-rdkafka 并设 `MQ_BUS_DRIVER=rdkafka` |
| 命令显示为 stub 描述 | 误注册同名 `Artisan::command` | 确认 `routes/console.php` 只保留 `easylog:ingest-server` |
| ES 内存不足启动失败 | bootstrap.memory_lock | 调小 `ES_JAVA_OPTS` 或加大内存 |
| 前端连不上 API | VITE_API_PROXY_TARGET 指向的 query 端口不对 | 改 `deploy/compose/.env` 或前端 `.env` |

## 9. 关联文档

- [使用说明（业务接入）](04-usage-guide.md)
- [总体架构设计](01-architecture.md)
- [部署编排文件](../deploy/README.md)
