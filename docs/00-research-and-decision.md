# 市场调研与选型决策报告

> 项目：EasyLog — 开源多租户 SaaS 日志服务平台
> 作者：基于 PHP 全栈技术背景
> 日期：2026-09-12
> 版本：v1.0

## 1. 调研目标

在启动自研日志服务之前，先回答两个问题：

1. 当前开源生态中是否已有满足"多租户 SaaS + 4 种 MQ 接入（RabbitMQ/Kafka/Redis/ActiveMQ）+ 参考 SLS/CLS 完整功能集 + PHP 技术栈友好"的项目？
2. 若没有完全匹配的项目，是选成熟存储引擎做二开，还是从零自研控制面 + 复用存储引擎？

## 2. 主流开源日志服务对比

| 项目 | 主语言 | 部署形态 | 多租户 | 支持的 MQ 接入 | SaaS 管理面 | 查询语言 | 许可证 | 适配评分 |
|---|---|---|---|---|---|---|---|---|
| **ELK Stack** | Java | 自建/Cloud | ❌（需自行实现） | Logstash Beats 为主，MQ 需自定义 | 仅 Kibana 检索面，无管理面 | Lucene/KQL | Elastic License（非真正开源） | ★★☆ |
| **Grafana Loki** | Go | 自建/Cloud | ✅ 原生支持 | Promtail/Fluent Bit，不直连 MQ | 仅查询面，无租户/项目/SaaS 管理 | LogQL | AGPLv3 | ★★★ |
| **OpenObserve** | Rust | 单二进制/Helm | ✅ 组织级隔离 | OTel Collector，HTTP/OTLP 为主 | 有完整管理面但面向单组织 | SQL + ES 兼容 API | Apache 2.0 | ★★★★ |
| **Graylog** | Java | 自建 | ❌ | 4 种 MQ 均可作 Input | 仅日志管理面 | Lucene | GPLv3 | ★★★ |
| **SigNoz** | Go | 自建/Cloud | ❌ | OTel only | 单组织管理面 | SQL/PromQL | MIT/EE | ★★ |
| **Quickwit** | Rust | 自建 | ❌ | 仅 OTel/HTTP | 仅检索面 | SQL | AGPLv3 | ★★ |
| **LCA (log-collect-ai-analytics)** | Go | Docker Compose | ❌（单租户） | 仅 Kafka | 有 Web 后台但仅日志检索 | 关键字 | 开源（未明确） | ★★★ |
| **Fluentd / Fluent Bit** | C/Ruby | 采集器 | — | 多种 MQ 输入输出 | 无 | — | Apache 2.0 | 仅作采集层 |

### 关键发现

- **没有任何一个开源项目同时满足**：多租户 SaaS + 4 种 MQ 接入 + 完整 SLS/CLS 功能集 + PHP 技术栈。
- **ELK** 已变更许可证为 Elastic License，对 SaaS 二次开发不友好；且 JVM 资源占用高。
- **Loki** 多租户是"标签隔离"级别，无独立的租户管理面、计量、配额。
- **OpenObserve** 是存储引擎，单组织视角，不支持"一套部署、多个客户独立使用"。
- **LCA** 单租户、单 MQ（Kafka），架构理念接近但范围远小于目标需求。
- **PHP 生态**：Monolog + rdkafka/phpamqplib/predis 是成熟接入库，但**无任何 PHP 实现的开源日志服务平台**。

## 3. 商业参考：腾讯云 CLS / 阿里云 SLS 功能矩阵

| 功能域 | 腾讯云 CLS | 阿里云 SLS | 本项目需对齐 |
|---|---|---|---|
| 日志集 / 日志主题 | ✅ Logset/Topic | ✅ Project/Logstore | ✅ Workspace/Topic |
| 多租户隔离 | ✅ | ✅ | ✅（SaaS 核心需求） |
| 采集方式 | 机器 Agent / API / Kafka 协议消费 | Logtail / API / Kafka 协议 / Data Import | ✅ HTTP API + 4 种 MQ + SDK |
| 索引与检索 | 全文索引 + 字段索引 + SQL92 | 全文 + 字段 + SQL | ✅ 全文 + 字段 + SQL |
| 仪表盘 | ✅ | ✅ | ✅ |
| 告警 | ✅ 触发条件/通知渠道 | ✅ | ✅ |
| 投递（Shipping） | ✅ COS/CKafka | ✅ OSS/ODPS | ✅ 文件/对象存储/转发 |
| 实时消费 | ✅ Kafka 协议消费 | ✅ 消费组获取 | ✅ 通过 MQ 转发 |
| 计量配额 | ✅ | ✅ | ✅（SaaS 计费基础） |
| IAM 子账号 | ✅ | ✅ | ✅ |

## 4. 技术选型决策

### 4.1 核心结论

**自研控制面 + 集成成熟开源存储引擎。** 不二开任何已有项目，但**深度集成** OpenObserve 或 Elasticsearch 作为存储后端（可切换）。

### 4.2 决策理由

- 已有项目重写比例 ≥ 70%（要加多租户、4 MQ、SaaS 管理面、计量、配额、IAM 全套），二开不如重写。
- 存储引擎**复用**而非自研：自研列式存储、倒排索引、查询引擎的工作量是数千人月级别，性价比极低。
- PHP 生态在"控制面 + API 网关 + 调度"层有压倒性优势（Laravel 生态、Composer 包、Swoole 高性能），适合做 SaaS 平台。
- 接入层（SDK + 4 种 MQ 适配器）在 PHP 中已有成熟库支持，可做到接入侧零侵入。

### 4.3 推荐技术栈

| 层 | 技术选型 | 备选/可切换 |
|---|---|---|
| **管理后端** | PHP 8.2 + Laravel 11 | Hyperf（更高性能但生态弱） |
| **接入网关** | Swoole 5 + Laravel Octane | RoadRunner |
| **MQ 消费器** | php-amqplib / rdkafka / predis / stomp-php | 通过 Laravel Queue 统一抽象 |
| **存储引擎** | Elasticsearch 8（默认） | OpenObserve（成本优先）/ Quickwit |
| **元数据存储** | MySQL 8.0 | PostgreSQL 16 |
| **缓存 / 实时流** | Redis 7（streams + pub/sub） | — |
| **前端** | Vue 3 + Vite + Element Plus | Naive UI |
| **实时推送** | WebSocket（Laravel Reverb / Swoole） | SSE |
| **部署** | Docker Compose（小规模）/ K8s + Helm（生产） | — |
| **可观测性自举** | Prometheus + Grafana | — |

### 4.4 为什么不选 Hyperf 而是 Laravel

- 生态：Laravel 的队列、事件、Auth、租户包（tenancy/multitenancy）、Admin 工具链更完整。
- 学习曲线：PHP 全栈开发者普遍更熟悉 Laravel。
- 性能：通过 Swoole + Octane 抹平性能差距，FPM 模式仍可降级使用。
- Hyperf 仅在"纯异步、超高并发 ingestion 网关"层作为可选替代。

## 5. 项目命名与定位

- **项目代号**：`EasyLog`
- **Tagline**：一个 PHP 友好的开源 SaaS 日志服务平台
- **目标用户**：
  - 中小 SaaS 厂商自建可观测性
  - 多业务线企业内部统一日志
  - PHP/Java/Go 技术栈项目零侵入接入
- **不替代**：单租户大规模日志检索（用 Loki/OpenObserve 直接部署更合适）

## 6. 风险与缓解

| 风险 | 缓解 |
|---|---|
| Elasticsearch 资源消耗大 | 默认 OpenObserve 镜像，存储后端可配置切换 |
| PHP 在超高并发下吞吐受限 | 接入网关用 Swoole 异步；批量写入；MQ 削峰 |
| 多租户数据隔离 | 存储层用 `tenant_id` 字段强制过滤 + 索引分片策略；元数据用 MySQL 严格行级隔离 |
| 开源运营成本 | MVP 先聚焦 PHP 社区，文档完善后再扩大；提供 Docker 一键部署 |

## 7. 下一步

1. 总体架构设计文档（[01-architecture.md](01-architecture.md)）
2. 详细设计文档（[02-detailed-design.md](02-detailed-design.md)）
3. 产品原型（位于 `/prototype/`）
