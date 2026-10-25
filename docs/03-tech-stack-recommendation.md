# 技术栈选型搭配推荐

> 项目：EasyLog
> 版本：v1.0
> 日期：2026-09-13
> 上一级文档：[02-detailed-design.md](02-detailed-design.md)

## 1. 总体选型原则

1. **PHP 优先**：管理面、控制面、接入网关均用 PHP，贴合您全栈背景。
2. **成熟开源复用**：存储引擎不自研，集成 ES / OpenObserve。
3. **可切换抽象**：关键依赖（存储、MQ）通过接口抽象，可独立替换。
4. **生产可部署**：所有组件都有 Docker 官方镜像，K8s Helm Chart 友好。
5. **PHP 异步优先**：FPM 不适合高吞吐接入，统一用 Swoole 异步运行时。

## 2. 最优搭配（推荐方案 A）

### 2.1 后端

| 层 | 组件 | 版本 | 用途 | 选型理由 |
|---|---|---|---|---|
| 语言 | PHP | 8.2 LTS | 全栈后端 | 您熟悉；8.2 提供 readonly/enum；生态成熟 |
| 框架 | Laravel | 11.x | Admin API + 控制面 | 生态最完整（Queue/Events/Auth/Tenancy）；学习曲线最低 |
| 异步运行时 | Swoole | 5.1.x | 接入网关 + 查询服务 | 协程异步；PHP 性能上限最高；与 Octane 无缝集成 |
| 应用服务器 | Laravel Octane | 1.x | Swoole HTTP server | 官方支持；自动管理协程上下文 |
| 队列抽象 | Laravel Queue | 11.x 内置 | 统一 MQ 消费 | 4 种 MQ 统一 Worker 抽象 |
| MQ Producer/Consumer | rdkafka | 6.x（librdkafka 2.x） | Kafka | 性能最高；官方维护 |
| | php-amqplib | 4.x | RabbitMQ | 纯 PHP 实现；无需 C 扩展；社区主流 |
| | predis | 2.4 | Redis | 纯 PHP；流式消费支持好 |
| | stomp-php | 6.x | ActiveMQ | Stomp 协议官方客户端 |

### 2.2 存储与消息中间件

| 组件 | 版本 | 用途 | 选型理由 |
|---|---|---|---|
| MySQL | 8.0 | 元数据 | 行级隔离强；事务可靠；运维熟悉 |
| Redis | 7.4 | 缓存 / 限流 / WebSocket | Streams 消费组原生支持；pub/sub 实时推送 |
| Kafka | 3.7 (KRaft) | 内部消息总线 | 持久化、回放、多消费者；KRaft 免 ZooKeeper |
| Elasticsearch | 8.13 | 默认存储引擎 | 全文检索、字段索引、SQL 全支持；生态最广 |
| OpenObserve | 0.11+ | 备选存储（成本优先场景） | Apache 2.0；存储成本仅 ES 的 1/140 |

### 2.3 前端

| 组件 | 版本 | 用途 | 选型理由 |
|---|---|---|---|
| Vue | 3.4 | SPA 框架 | 与 Laravel 生态亲和；学习曲线低 |
| Vite | 5.x | 构建 | 速度最快；与 Vue 3 原生集成 |
| Element Plus | 2.7+ | UI 库 | 国内文档最全；与 SLS/CLS 风格匹配 |
| ECharts | 5.5 | 可视化 | 国产；功能最强；原型已采用 |
| Pinia | 2.x | 状态管理 | Vue 3 官方推荐 |

### 2.4 可观测性自举

| 组件 | 用途 |
|---|---|
| Prometheus + Grafana | 自身指标监控 |
| EasyLog 自身接入 | 业务日志 dogfooding |

### 2.5 部署与基础设施

| 组件 | 版本 | 用途 |
|---|---|---|
| Docker | 24+ | 容器化 |
| Docker Compose | 2.x | 开发/小规模部署 |
| Nginx | 1.25 | 反向代理 + TLS |
| Helm | 3.x | K8s 生产部署（可选） |

## 3. 备选方案 B（成本优先）

适用场景：预算紧、存储量大、可接受功能简化。

| 项 | 推荐方案 | 备选方案 B | 备选方案 C |
|---|---|---|---|
| 存储引擎 | Elasticsearch 8 | **OpenObserve** | Quickwit |
| 内部总线 | Kafka | Redis Streams（单租户） | NATS JetStream |
| 异步运行时 | Swoole | RoadRunner | FrankenPHP |
| 框架 | Laravel 11 | Hyperf 3 | — |

> 备选方案 B 仅在 v1.5 之后引入；MVP 必须先用方案 A 保证稳定。

## 4. 为什么选这套搭配

### 4.1 为什么不用 Hyperf 而是 Laravel

| 维度 | Laravel 11 | Hyperf 3 |
|---|---|---|
| 生态完整度 | ★★★★★（Queue/Events/Auth/Tenancy 全套） | ★★★ |
| 学习曲线 | 最低（您已有经验） | 中 |
| 异步性能 | 通过 Swoole + Octane 抹平差距 | 原生 |
| 团队上手 | 快 | 需重新学习协程 |

→ 选 Laravel + Octane。MVP 阶段追求交付速度；后续可在 ingestion 子模块用 Hyperf 局部替换。

### 4.2 为什么用 Swoole 而不是 RoadRunner/FrankenPHP

| 维度 | Swoole 5 | RoadRunner | FrankenPHP |
|---|---|---|---|
| 协程异步 | ✅ 原生 | ❌ 基于 worker 池 | ❌ |
| 与 rdkafka 配合 | ✅ 协程化 Producer/Consumer | 需阻塞调用 | 需阻塞调用 |
| Laravel Octane 支持 | ✅ 官方 | ✅ | ✅ |
| PHP 扩展要求 | 需编译安装 | 二进制即可 | Caddy 集成 |

→ 高吞吐接入网关场景必须异步，选 Swoole。FPM 降级模式仍保留作为兼容方案。

### 4.3 为什么内部总线强制 Kafka 而不是 Redis Streams

| 维度 | Kafka | Redis Streams |
|---|---|---|
| 持久化 | 磁盘 + 副本，可回放 | RDB/AOF，回放受限 |
| 多消费者独立消费 | ✅ Consumer Group | ✅ |
| 吞吐上限 | 100k+/s | 单实例 ~10k/s |
| 保留时长 | 24h+（可调） | 容量上限受内存限制 |

→ 写入路径不能丢消息、要支持告警/投递/消费组多订阅并行，选 Kafka。

### 4.4 为什么存储默认 Elasticsearch 而不是 OpenObserve

| 维度 | Elasticsearch 8 | OpenObserve |
|---|---|---|
| 功能完整度 | 全文索引 + 字段索引 + SQL + 聚合 | SQL + ES 兼容 API，全文检索稍弱 |
| 生态 | 最广（SDK / 工具 / 文档） | 新生态 |
| 资源消耗 | 高（JVM 堆） | 低（Rust 单二进制） |
| 成本 | 高 | 仅 ES 的 1/140 |
| 稳定性 | 生产验证 10+ 年 | 较新 |

→ MVP 默认 ES 保证功能完整；通过 `StorageAdapter` 抽象层，1.5 版本引入 OpenObserve 作为成本优化选项。

## 5. 版本锁定建议

| 组件 | 推荐版本 | 锁定理由 |
|---|---|---|
| PHP | 8.2.x（不升 8.4） | 8.4 部分 SDK 兼容性未稳定 |
| Laravel | 11.x LTS | 长期支持至 2026+ |
| Swoole | 5.1.x | 5.0 协程 API 稳定；6.0 未发 |
| Elasticsearch | 8.13.x | 8.x API 稳定；避免 7.x（已停止维护） |
| Kafka | 3.7.x KRaft | 3.6+ 默认 KRaft，无需 ZooKeeper |
| MySQL | 8.0.x | LTS；避免 9.x（创新版） |
| Redis | 7.4.x | 7.x Streams + ACL 成熟 |
| Vue | 3.4.x | Composition API 稳定 |
| Vite | 5.x | 与 Vue 3 原生集成 |

## 6. 依赖清单（composer.json 关键包）

```json
{
  "require": {
    "php": "^8.2",
    "laravel/framework": "^11.0",
    "laravel/octane": "^1.8",
    "swoole/swoole": "^5.1",
    "predis/predis": "^2.4",
    "php-amqplib/php-amqplib": "^4.0",
    "stomp-php/stomp-php": "^6.0",
    "elasticsearch/elasticsearch": "^8.0",
    "pda/pheanstalk": "^5.0",
    "laravel/sanctum": "^4.0",
    "laravel/reverb": "^1.0",
    "spatie/laravel-permission": "^6.0",
    "tenancy/multitenancy": "^4.0"
  },
  "require-dev": {
    "laravel/pint": "^1.13",
    "phpunit/phpunit": "^11.0",
    "mockery/mockery": "^1.6"
  }
}
```

rdkafka 通过 PECL 安装（C 扩展，非 Composer 包）：
```bash
pecl install rdkafka
echo "extension=rdkafka.so" >> php.ini
```

## 7. 前端依赖清单（package.json 关键）

```json
{
  "dependencies": {
    "vue": "^3.4.0",
    "vue-router": "^4.3.0",
    "pinia": "^2.1.7",
    "element-plus": "^2.7.0",
    "@element-plus/icons-vue": "^2.3.1",
    "echarts": "^5.5.0",
    "axios": "^1.6.0",
    "dayjs": "^1.11.10"
  },
  "devDependencies": {
    "vite": "^5.2.0",
    "@vitejs/plugin-vue": "^5.0.0",
    "unplugin-auto-import": "^0.17.0",
    "unplugin-vue-components": "^0.27.0"
  }
}
```

## 8. 部署形态对照

| 形态 | 规模 | 节点数 | 推荐组件 |
|---|---|---|---|
| 开发/演示 | 1-3 人 | 1 台 | Docker Compose（MySQL+Redis+ES+Kafka 单节点） |
| 小规模生产 | 1-10 个租户 | 3 台 | Docker Compose（ES + Kafka 集群） |
| 中规模生产 | 10-100 租户 | 5-10 台 | K8s + Helm（接入/写入/查询独立扩） |
| 大规模 | 100+ 租户 | 20+ 台 | K8s + ES 集群（3 master + N data） |

## 9. 选型风险与缓解

| 风险 | 缓解方案 |
|---|---|
| Elasticsearch 资源消耗大 | 提供 OpenObserve 配置切换；冷热分离；按月滚动索引 |
| Swoole 与某些 Laravel 包不兼容 | Octane 已处理 99% 兼容；不兼容包列表见官方文档 |
| rdkafka 扩展编译困难 | 提供 Docker 镜像（内置编译）；备选 longlang/rdkafka-wrap（纯 PHP fallback） |
| Kafka 运维复杂 | KRaft 模式免 ZooKeeper；v1.0 后提供 Redis Streams 单租户降级方案 |

## 10. 不采用的技术与理由

| 不采用 | 理由 |
|---|---|
| Java/Spring | 与 PHP 全栈背景不符 |
| Go | 生态较新；PHP 已能覆盖所有需求 |
| Node.js | 同上 |
| MongoDB | 全文检索弱；不适合日志分析 |
| ClickHouse | OLAP 为主，全文检索弱；可作为投递目标 |
| ELK 全套二开 | Elastic License 非 OSS；重写比例 ≥ 70% |
