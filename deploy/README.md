# EasyLog 部署

本目录包含本地开发与生产部署所需的所有配置。

## 三档按需启动

档位由 `COMPOSE_PROFILES` 决定（`deploy/compose/.env` 默认 `lite`），推荐用 `start.sh` 切换：

| 档位 | 启动方式 | 容器 | 元数据 | 日志存储 | 总线 | 适用 |
|---|---|---|---|---|---|---|
| **lite**（默认） | `./start.sh` | app + frontend（2 个） | SQLite | SQLite（LIKE 检索，Lucene 子集/SQL 子集/直方图/聚合可用） | direct 直写 | 个人/开发/小团队，零外部依赖，内存 ~200MB |
| **core** | `./start.sh core` | redis/es/zk/kafka + admin/ingestion/query/writer + frontend（9 个） | 宿主机 MySQL | Elasticsearch | Kafka | 团队/生产单机，需要 MySQL 与 vm.max_map_count≥262144 |
| **full** | `./start.sh full` | core + rabbitmq + worker（11 个） | 同 core | 同 core | Kafka | 全量：外部 MQ 接入（Kafka/RabbitMQ/Redis Stream/ActiveMQ）+ 命令行告警巡检 |

业务项目接入（三档通用）：HTTP 接入 API + AppID/Secret ——
[sdk/agent](../sdk/agent/README.md) 采集已有日志文件（免改代码）；`sdk/php|go|java`
代码内实时推送。接入地址：lite 档 `:9515`，core/full 档独立网关 `:9514`。

说明：

- 档位互斥（lite 的 app 与 core 的 admin 都占用宿主 9515），切档先 `./start.sh stop`。
- lite 与 core/full 是**场景选择**而非升级关系：lite 的日志在 SQLite、core 的在 ES，数据不自动迁移。
- lite 档告警巡检默认关闭，需要时在 `.env` 设 `EASYLOG_ALERT_LOOPS=1`。
- 裸 `docker compose up -d` 使用 `.env` 里的 `COMPOSE_PROFILES`（默认 lite）；`start.sh` 会按档位自动覆盖 `COMPOSE_PROFILES` 与 frontend 的 `API_UPSTREAM`。

## 目录结构

```
deploy/
├── compose/                       # docker-compose 编排（三档 profile）
│   ├── docker-compose.yml         # 主编排（lite 默认 / core / full）
│   ├── start.sh                   # 一键启动脚本（自动生成 .env、按档等待就绪）
│   ├── docker-compose.override.yml.example  # 开发覆盖示例
│   └── .env.example               # 编排环境变量模板（COMPOSE_PROFILES=lite）
├── docker/                        # 镜像构建相关
│   ├── Dockerfile                 # PHP 8.2 + Swoole 应用镜像（多角色共用，SQLite 支持内置）
│   ├── Dockerfile.frontend        # 前端镜像（nginx + envsubst 模板，API_UPSTREAM 可配）
│   ├── php.ini                    # PHP 运行时配置
│   ├── entrypoint.sh              # 容器入口（按 SERVICE_ROLE 切换；admin 支持 SQLite 迁移）
│   ├── nginx-frontend.conf        # frontend 反代模板（${API_UPSTREAM}）
│   ├── supervisord.conf           # 单容器多进程配置（可选）
│   └── nginx/                     # Nginx 反代配置（生产）
└── k8s/                           # K8s 清单（core 形态）
```

## 快速开始（lite 档）

```bash
cd deploy/compose
./start.sh          # 自动生成 .env + 随机 APP_KEY，起 app + frontend
```

- 控制台：http://localhost:9516 （默认账号 `admin@easylog.local`，密码 `.env` 的 `EASYLOG_ADMIN_PASSWORD`，默认 easylog@2026）
- 管理/接入 API 同端口：http://localhost:9515 （接入 `POST /api/v1/ingest`，AppID + Secret 鉴权）
- 数据持久化：SQLite 文件在 `easylog_lite_storage` 卷（容器 `/var/www/html/storage/app/easylog/`）

## core / full 档

前置条件：

1. 宿主机 MySQL 可用（`.env` 的 `EASYLOG_DB_HOST/EASYLOG_DB_USERNAME/EASYLOG_DB_PASSWORD`）；
2. `vm.max_map_count >= 262144`（ES 前置，`start.sh` 会检查并提示）。

```bash
cd deploy/compose
./start.sh core     # 或 ./start.sh full（额外起 rabbitmq + worker）
```

端口全景（core/full）：

| 服务 | 端口 | 说明 |
|---|---|---|
| Ingestion | 9514 | 业务方推送日志入口 |
| Admin | 9515 | 控制台管理 API |
| Query | 9517 | 检索入口（容器内 9515） |
| Frontend | 9516 | 控制台 |
| MySQL | 3306（宿主机） | 元数据库 |
| Redis | 6379 | 缓存/限流 |
| Elasticsearch | 9200 | 日志存储 |
| Kafka | 9092（内部）/ 29092（外部） | 内部消息总线 |
| RabbitMQ | 5672 / 15672 | 外部 MQ 测试（full 档，管理台 easylog/easylog） |

初始化数据由 `admin` 容器首启自动完成（`migrate --force` + seed）。手动执行：

```bash
docker compose exec admin php artisan migrate --force
docker compose exec admin php artisan db:seed --force
```

## 镜像构建

```bash
docker build -f deploy/docker/Dockerfile -t easylog/app:dev .
docker build -f deploy/docker/Dockerfile.frontend -t easylog/frontend:dev .
```

镜像通过 `SERVICE_ROLE` 环境变量切换角色（lite 的 app 容器即 `admin` 角色，直写总线 + SQLite）：

- `admin` - Octane Swoole HTTP，端口 9515，含 migrate（MySQL 或 SQLite）
- `ingestion` - Octane Swoole HTTP，端口 9514
- `query` - Octane Swoole HTTP，端口 9515
- `writer` - 长驻进程 `php artisan easylog:writer`
- `worker` - 长驻进程 `php artisan easylog:consume`

关键环境变量（应用层）：

| 变量 | 取值 | 说明 |
|---|---|---|
| `MQ_BUS_DRIVER` | `direct` / `rdkafka` / `log` / `inmemory` | 内部总线；lite 用 direct 直写 |
| `STORAGE_DRIVER` | `sqlite` / `elasticsearch` / `openobserve` / `quickwit` | 日志存储；lite 用 sqlite |
| `DB_CONNECTION` | `sqlite` / `mysql` | 元数据；lite 用 sqlite（无 DB_HOST） |
| `CACHE_STORE` | `file` / `redis` | 缓存/限流；lite 用 file（无 Redis） |
| `CONSUMER_*_ENABLED` | bool | 外部 MQ 消费者开关（worker 角色） |

## 生产部署

生产推荐：
1. 独立托管 MySQL / Redis / Elasticsearch / Kafka（云托管服务或专用集群）；
2. 应用镜像 `easylog/app:prod` 由 CI 构建，推送至镜像仓库；
3. 通过 K8s 或 Nomad 部署多副本，前置 Ingress 反代；
4. 前端 `pnpm build` 产物由 Nginx 静态托管，`/api/*` 反代到 query 服务。

K8s 清单见 `deploy/k8s/`（core 形态，中间件自备）。

## CI 凭据

构建推送相关访问令牌请在代码托管平台控制台创建与轮换，**不要写入仓库文件**。
安全报告与生产加固自查清单见 [SECURITY.md](../SECURITY.md)。
