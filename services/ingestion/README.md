# EasyLog Ingestion Service

HTTP 接入网关，业务方通过 SDK / HTTP API 直推日志。

## 设计

- **共享 Laravel 11 安装**：位于 `services/admin/`
- **不同 entry point**：通过 Octane 启动 Swoole HTTP 服务器，监听独立端口
- **无状态**：可水平扩，前置 Nginx LB
- **职责**：鉴权 AppID/AppSecret → 限流/配额 → 归一化 → 投递到内部 Kafka

## 启动

```bash
# 假设已 cd 到 services/admin/ 并完成 composer install
php artisan octane:start --server=swoole --host=0.0.0.0 --port=9514
```

或通过 docker-compose：

```bash
docker compose -f deploy/compose/docker-compose.yml up -d ingestion
```

## 关键端点

| Method | Path | 鉴权 | 说明 |
|---|---|---|---|
| POST | `/api/v1/ingest` | AppID | 单条日志写入 |
| POST | `/api/v1/ingest/batch` | AppID | 批量写入（最多 10000 条） |
| GET | `/api/v1/ingest/heartbeat` | AppID | SDK 健康检查 |
| GET | `/api/v1/health` | 无 | 平台健康检查 |

## 性能目标

| 配置 | 单节点吞吐 |
|---|---|
| 4C8G + Swoole 4 worker | ≥ 20k req/s（批量写入） |
| 8C16G + Swoole 8 worker | ≥ 50k req/s（批量写入） |

## 水平扩

无状态，前置 Nginx LB，按 `app_id` 哈希到固定节点（保持 Kafka 分区局部性）。

## 关联代码

- 控制器：`services/admin/app/Http/Controllers/IngestController.php`
- 中间件：`services/admin/app/Http/Middleware/RateLimitByAppId.php`
- 路由：`services/admin/routes/api.php`
- MQ 抽象：`services/admin/app/Services/MQ/MessageQueueManager.php`
