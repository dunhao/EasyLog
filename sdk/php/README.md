# EasyLog PHP SDK

业务方（PHP 项目）通过本 SDK 直推日志到 EasyLog 平台。内部基于 cURL multi 异步投递，支持 Monolog 集成与裸客户端两种用法。

## 安装

```bash
composer require easylog/sdk-php
```

## 方式一：Monolog 集成（推荐）

```php
use Monolog\Logger;
use EasyLog\SDK\MonologHandler;

$logger = new Logger('order-service');
$logger->pushHandler(new MonologHandler([
    'app_id'       => 'app_x9f3k2',          // 控制台 → 接入点 → 创建后获得
    'app_secret'   => 'sec_xxxxxxxxxxxxxx',  // 创建时一次性返回，请妥善保存
    'endpoint'     => 'https://log.example.com',
    'topic'        => 'order-logs',
    'batch_size'   => 1000,                    // 每批最多 1000 条
    'buffer_max'   => 5000,                    // 内存缓冲上限
    'flush_sec'    => 5,                       // 最多每 5 秒 flush
    'timeout_ms'   => 2000,
    'service'      => 'order-service',         // 可选，作为 service 字段入库
]));

$logger->info('order created', ['order_id' => 'A123', 'amount' => 199.00]);
$logger->error('payment timeout', ['order_id' => 'A123']);
```

进程退出时 SDK 自动 `register_shutdown_function` 触发 flush；如需手动控制：

```php
$handler = $logger->getHandlers()[0]; // MonologHandler
$handler->flush();
```

## 方式二：裸客户端

适用于非 Monolog 项目，或希望自行管理批次：

```php
use EasyLog\SDK\Client;
use EasyLog\SDK\Config;

$client = new Client(new Config([
    'app_id'     => 'app_x9f3k2',
    'app_secret' => 'sec_xxxxxxxxxxxxxx',
    'endpoint'   => 'https://log.example.com',
    'topic'      => 'order-logs',
    'batch_size' => 500,
]));

$client->info('order created', ['order_id' => 'A123']);
$client->error('payment timeout', ['order_id' => 'A123']);
$client->flush();           // 主动 flush
```

业务有事件循环或常驻进程时，可定期 `tick()`：

```php
while (true) {
    doWork();
    $client->tick();  // 距上次 flush 超过 flush_sec 时触发
    usleep(100000);
}
```

## 配置项

| 字段 | 必填 | 默认值 | 说明 |
|---|---|---|---|
| `app_id` | ✅ | — | 接入点 ID |
| `app_secret` | ✅ | — | 接入点密钥 |
| `endpoint` | ✅ | — | EasyLog 服务地址，例如 `https://log.example.com` |
| `topic` | ✅ | — | 日志主题（控制台创建后获得） |
| `batch_size` | ❌ | 1000 | 每批最多多少条触发投递（1–10000） |
| `buffer_max` | ❌ | 5000 | 内存缓冲上限，超过立即强制 flush（必须 ≥ batch_size） |
| `flush_sec` | ❌ | 5 | 最多多少秒触发一次 flush（1–300） |
| `timeout_ms` | ❌ | 2000 | 单次 HTTP 请求超时（毫秒） |
| `fail_log_path` | ❌ | `/tmp/easylog-fail.log` | 重试 3 次仍失败的日志落盘文件 |
| `service` | ❌ | monolog channel 名 | 作为 `service` 字段入库，便于按服务筛选 |

## 异步与失败处理

- **异步**：HTTP 调用基于 cURL multi，`push()` 立即返回，不阻塞主流程；
- **shutdown**：构造时自动 `register_shutdown_function(flush)`，进程退出前强制 flush；
- **重试**：5xx / 429 / 网络错误重试 3 次，指数退避 0.5s → 1s → 2s；
- **DLQ**：重试耗尽后写入 `fail_log_path`，避免静默丢失；
- **4xx 不重试**：客户端错误（除 429 限流外）直接落 DLQ，避免无效重试。

## 心跳

```php
$res = $client->heartbeat();
// ['ok' => true, 'status' => 200, 'body' => '{"status":"ok"}']
```

## 字段约定

EasyLog 自动索引以下字段：

| 字段 | 来源 | 默认索引 |
|---|---|---|
| `ts` | 客户端时间，未填则服务端补齐 | ✅ date |
| `level` | DEBUG/INFO/WARN/ERROR/FATAL/TRACE | ✅ keyword |
| `msg` | 日志消息 | ✅ standard text |
| `service` | Monolog channel 或显式 `service` 配置 | ✅ keyword |
| 其他 | context / fields | 自动 JSON 提取（如开启） |

## License

MIT
