# EasyLog

[![CI](https://github.com/dunhao/EasyLog/actions/workflows/ci.yml/badge.svg)](https://github.com/dunhao/EasyLog/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/License-Apache_2.0-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4.svg)](https://www.php.net/)

一个 PHP 友好的开源多租户 SaaS 日志服务平台。

- 三档按需启动：默认 lite（2 容器、SQLite、零外部依赖），core / full 按配置升级
- 一套部署，多项目/客户独立使用
- 支持 HTTP API / SDK / Kafka / RabbitMQ / Redis / ActiveMQ 多种接入
- 集成 Elasticsearch / OpenObserve / Quickwit / SQLite 作为存储后端（可切换）
- 参考 腾讯云 CLS / 阿里云 SLS 功能集

## 项目结构

```
log-service/
├── docs/                       设计文档
│   ├── 00-research-and-decision.md
│   ├── 01-architecture.md
│   ├── 02-detailed-design.md
│   ├── 03-tech-stack-recommendation.md
│   └── 04-usage-guide.md
├── prototype/                  高保真交互原型（纯静态 HTML）
├── services/                   后端服务（单一 Laravel 应用按 SERVICE_ROLE 裂变）
│   ├── admin/                  Laravel 12 应用主体（管理面 + 接入 + 检索 + writer/worker 命令）
│   ├── ingestion/              接入网关角色入口（core 档独立部署时使用）
│   ├── worker/                 MQ 消费者角色入口（4 种 MQ）
│   ├── writer/                 Kafka 消费者角色入口（→ 批量写入存储）
│   └── query/                  查询服务角色入口
├── sdk/                        客户端 SDK（三语言，行为对齐）
│   ├── php/                    PHP SDK（Monolog Handler）
│   ├── go/                     Go SDK（slog Handler）
│   └── java/                   Java SDK（Logback Appender）
├── frontend/                   Vue3 + Vite + Element Plus 控制台
├── deploy/                     部署清单
│   ├── compose/                Docker Compose（三档 profile：lite 默认 / core / full）
│   ├── docker/                 镜像构建（Dockerfile + php.ini + entrypoint）
│   └── k8s/                    K8s 清单（core 形态：Deployment/Service/Ingress）
├── .env.example                项目根级环境变量模板
└── README.md
```

## 快速开始

### 1. lite 档（默认，2 容器零依赖）

```bash
cd deploy/compose && ./start.sh
```

只起 app + frontend 两个容器：元数据与日志都存 SQLite（卷持久化）、direct 直写总线、
file 缓存 —— 不需要 MySQL / Redis / ES / Kafka，内存 ~200MB。控制台与接入 API 同端口（9515），
搜索/直方图/聚合/Lucene 子集/SQL 子集全部可用。

### 2. core / full 档（全家桶，按需）

```bash
cd deploy/compose && ./start.sh core    # +redis/es/zk/kafka + 5 应用角色（需宿主机 MySQL）
cd deploy/compose && ./start.sh full    # core + rabbitmq + worker（外部 MQ 消费 + 命令行告警巡检）
```

core 档端口：控制台 9516，Ingestion 9514，Admin 9515，Query 9517，MySQL 3306（宿主机），
Redis 6379，Kafka 9092/29092，ES 9200；full 档另有 RabbitMQ 5672/15672。

> 三档互斥（9515 端口冲突），切档先 `./start.sh stop`；lite 与 core 是场景选择，数据不自动迁移。

### 3. 本地开发（不使用 Docker）

详见 [docs/04-usage-guide.md](docs/04-usage-guide.md)

## 业务项目如何接入

三档共用同一套 HTTP 接入 API（lite 档 `http://<host>:9515`，core/full 档独立网关
`http://<host>:9514`），两种接入方式任选：

**方式一：日志文件采集（免改代码，推荐存量项目）** —— 把 `sdk/agent/` 的单文件
采集器放到目标机器，配置项目日志目录（如 ThinkPHP `runtime/log/`、Laravel
`storage/logs/`），常驻运行即可自动 tail → 解析（thinkphp/laravel/json/regex/plain
多行合并）→ 批量推送。断点续采、轮转自适应、支持 cron 单轮模式：

```bash
php sdk/agent/easylog-agent test  -c easylog-agent.json   # 干跑验证解析
php sdk/agent/easylog-agent start -c easylog-agent.json   # 常驻采集
```

详见 [sdk/agent/README.md](sdk/agent/README.md)。

**方式二：代码内集成（实时性最好）** —— `sdk/php`（Monolog Handler）、`sdk/go`
（slog）、`sdk/java`（Logback Appender）直接在应用里推日志。

接入凭据（AppID/AppSecret）在控制台"工作空间 → 接入点"创建。

## 文档导航

- [市场调研与选型决策](docs/00-research-and-decision.md)
- [总体架构设计](docs/01-architecture.md)
- [详细设计](docs/02-detailed-design.md)
- [技术栈选型搭配](docs/03-tech-stack-recommendation.md)
- [使用说明](docs/04-usage-guide.md)
- [部署指南（服务器配置/环境要求/两种部署方式）](docs/05-deployment-guide.md)

## 实现进度

### 核心链路（已完成，196 tests / 470 assertions 全绿）

- ✅ 接入 API：单条/批量写入、AppID + Secret 鉴权、限流、日志归一化、用量计量与日配额（429）
- ✅ 内部消息：MessageQueue 抽象（direct 直写 / kafka / log / inmemory 四驱动）、topic 路由
- ✅ Writer：批量落盘、失败 DLQ、月度索引滚动与保留清理
- ✅ 查询：检索 / 直方图 / 聚合 + Lucene 语法转译（QueryTranslator）+ SQL 子集（SELECT/WHERE/LIKE/IN/BETWEEN/IS NULL/GROUP BY/聚合，SqlQueryTranslator）
- ✅ 外部 MQ 消费：Kafka / RabbitMQ / Redis Stream / ActiveMQ（`easylog:consume`；Kafka 支持通配符 pattern 自动发现新 topic；ActiveMQ 走 stomp-php 纯 PHP 包）
- ✅ 管理 API：workspaces / topics / endpoints（含密钥轮换、测试连接）/ fields / alert-rules（含事件与标记处理）/ dashboards / shipping-tasks / consumption-groups / audit-logs / usage / users（角色管理）
- ✅ 控制台认证：`/auth/login|me|logout`（Sanctum 个人访问令牌）
- ✅ 告警：定时评估（固定阈值 + 基线窗口 σ 偏离的异常检测，spike/drop/both 方向）+ 通知投递（webhook + 邮件 SMTP，`easylog:dispatch-alert-notifications`；worker 容器随消费进程自动拉起）
- ✅ CI：GitHub Actions（`.github/workflows/ci.yml`）五条并行流水线——后端 phpunit / 前端构建 / Go SDK / Java SDK / Agent 采集器
- ✅ 配额管理：endpoint → workspace → tenant 三级日配额，`GET/PUT /workspaces/{ws}/quota`、`GET/PUT /tenant/quota`，超限 429
- ✅ 审计：管理写操作留痕（AuditMiddleware，敏感字段脱敏）
- ✅ 定时任务：`easylog:rollover-index`
- ✅ 存储适配器：Elasticsearch / OpenObserve / Quickwit / SQLite 四后端完整实现（`STORAGE_DRIVER` 切换；OO 走 SQL 引擎，QW 走 query string + 聚合透传，SQLite 为 lite 档内置零依赖方案）
- ✅ 前端控制台（frontend/）：Vue3 + Element Plus + ECharts，14 个页面全覆盖；`deploy/compose/start.sh` 一键起栈后访问 `http://<HOST>:9516/`，默认账号 `admin@easylog.local`（AdminUserSeeder 种子，密码取 `.env` 的 `EASYLOG_ADMIN_PASSWORD`，默认 easylog@2026）

### 待实现

- ⬜ OpenObserve / Quickwit 存储适配器
- ✅ SDK 三语言（php / go / java，`.cnb.yml` CI 全量门禁）

> 运行测试需 PHP 8.2：`/opt/homebrew/opt/php@8.2/bin/php vendor/bin/phpunit`（在 services/admin 下）

## 技术栈

- **后端**: PHP 8.3 运行时（源码兼容 ≥8.2）+ Laravel 12 + Swoole 5 (Octane)
- **存储**: Elasticsearch 8 / OpenObserve / Quickwit / SQLite（lite 档，可切换）
- **消息**: Kafka 3.7（core/full 档；lite 档直写免中间件）
- **元数据**: MySQL 8 + Redis 7（lite 档 SQLite + file 缓存零依赖）
- **前端**: Vue 3.4 + Vite 5 + Element Plus + ECharts

详见 [技术栈选型搭配](docs/03-tech-stack-recommendation.md)

## 路线图

- v0.1 MVP: HTTP 接入 + Kafka 接入 + ES 存储 + 全文检索 + 单租户
- v0.5: 多租户 + 4 种 MQ + 字段索引 + 简单仪表盘
- v1.0: 完整告警 + 投递 + 消费组 API + 计量 + SDK 三语言（✅ 已发布）
- v1.x: 文件采集 Agent（✅）、syslog 接入、更多存储后端
- v2.0: Kafka Wire Protocol 对外暴露

## 贡献

欢迎 Issue / PR，参与方式见 [CONTRIBUTING.md](CONTRIBUTING.md)；安全问题请私下报告（[SECURITY.md](SECURITY.md)）。

## License

Apache-2.0，详见 [LICENSE](LICENSE)。
