# EasyLog Go SDK

EasyLog 日志平台 Go 接入 SDK，行为与 [PHP SDK](../php) 对齐：内存缓冲、满批/定时投递、
失败重试 3 次（5xx/429/网络错误指数退避 0.5s/1s/2s，4xx 不重试），重试耗尽落本地文件。

## 安装

```bash
go get github.com/dunhao/EasyLog/sdk/go
```

## 快速开始

```go
package main

import (
    "github.com/dunhao/EasyLog/sdk/go"
)

func main() {
    cfg, err := easylog.NewConfig(
        "app_xxxxxxxx",             // AppID
        "your-app-secret",          // AppSecret
        "http://log.example.com:9514",  // ingestion 网关地址
        "tpic-uuid-of-topic",       // 目标主题 uuid
    )
    if err != nil {
        panic(err)
    }

    client := easylog.NewClient(cfg)
    defer client.Close() // 进程退出前投递剩余缓冲

    client.Info("order created", map[string]any{
        "order_id": "A123",
        "user_id":  42,
    })
    client.Error("payment failed", map[string]any{"reason": "timeout"})
}
```

## 桥接 slog（Go 1.21+）

```go
import (
    "log/slog"
    "github.com/dunhao/EasyLog/sdk/go"
)

logger := slog.New(easylog.NewSlogHandler(client, slog.LevelInfo)).
    With("service", "order-api")

logger.Info("order created", "order_id", "A123")
logger.WithGroup("db").Error("timeout", "table", "orders")
// → fields: {"service": "order-api", "db.table": "orders", ...}
```

## 配置项

| 项 | 默认 | 说明 |
|---|---|---|
| BatchSize | 1000 | 满多少条触发投递（1~10000） |
| BufferMax | 5000 | 缓冲上限，超限同步 flush 保护（>= BatchSize） |
| FlushSec | 5 | 定时投递间隔秒（1~300） |
| Timeout | 2s | 单次请求超时 |
| FailLogPath | /tmp/easylog-fail.log | 重试耗尽后的落盘路径 |

链式设置：`cfg.WithBatchSize(100)` 等，校验失败返回 error。

## 测试

```bash
go test -race ./...
```
