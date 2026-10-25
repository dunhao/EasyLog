# EasyLog Admin (Laravel 11)

EasyLog 管理面 + Admin API。这是后端服务的代码主基，其他服务（ingestion / worker / writer / query）共享相同的 Laravel 安装通过不同 entry point 启动。

## 安装

```bash
cd services/admin
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan db:seed --class=EasyLogSeeder
```

## 启动开发服务

### Admin API (Laravel Octane / Swoole)

```bash
php artisan octane:start --server=swoole --host=0.0.0.0 --port=9001
```

### Ingestion Gateway (HTTP 接入网关)

```bash
# 共享同一 Laravel 安装，不同端口启动
php artisan octane:start --server=swoole --host=0.0.0.0 --port=9514
```

### MQ 消费者

```bash
# 启动所有启用的消费者
php artisan easylog:consume

# 仅启动 Kafka
php artisan easylog:consume kafka
```

### 写入器

```bash
php artisan easylog:writer
```

### 告警评估器

```bash
php artisan easylog:alert-evaluator
```

### 索引滚动（每月执行）

```bash
php artisan easylog:rollover-index
# 或加入 crontab: 5 0 1 * * php artisan easylog:rollover-index
```

## 测试

```bash
composer test        # PHPUnit
composer lint        # Pint 检查
composer fix         # Pint 修复
```

## 路由列表

- `/api/v1/health` 健康检查
- `/api/v1/ingest` 单条日志写入（AppID 鉴权）
- `/api/v1/ingest/batch` 批量日志写入
- `/api/v1/ingest/heartbeat` SDK 心跳
- `/api/v1/workspaces` 工作空间 CRUD（JWT）
- `/api/v1/workspaces/{ws}/topics` 主题 CRUD
- `/api/v1/endpoints` 接入点 CRUD
- `/api/v1/workspaces/{ws}/search` 跨主题检索
- `/api/v1/topics/{topic}/search` 主题内检索
- `/api/v1/dashboards` 仪表盘
- `/api/v1/alert-rules` 告警规则
- `/api/v1/shipping-tasks` 投递任务
- `/api/v1/consumption-groups` 消费组
- `/api/v1/users` 用户
- `/api/v1/audit-logs` 审计

## 目录结构

```
app/
├── Console/Commands/        artisan 命令（easylog:consume / writer / alert-evaluator / rollover-index）
├── Facades/                 StorageAdapter / MessageQueue
├── Http/
│   ├── Controllers/         API 控制器
│   └── Middleware/          ResolveTenantContext / RateLimitByAppId / CheckAbility
├── Models/                  Eloquent 模型
├── Providers/               Service Providers
└── Services/
    ├── MQ/                  消息队列抽象 + 4 种 MQ 消费者
    ├── Storage/             StorageAdapter + Elasticsearch/OpenObserve/Quickwit 实现
    ├── Writer/              批量聚合写入器
    └── Alert/               告警评估器

config/
├── easylog.php              EasyLog 自有配置（接入、配额）
├── storage.php              存储后端（可切换）
└── message-queue.php        内部 Kafka + 外部 MQ 消费者

database/
├── migrations/              12 张表结构
└── seeders/                 EasyLogSeeder + RbacSeeder
```
