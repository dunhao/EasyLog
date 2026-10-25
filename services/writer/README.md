# EasyLog Writer

写入器：消费内部 Kafka → 批量聚合 → 写入存储后端（ES / OpenObserve / Quickwit）。

## 设计

- **共享 Laravel 11 安装**：位于 `services/admin/`
- **入口**：`php artisan easylog:writer`
- **多副本**：通过 Kafka consumer group 自动负载均衡
- **失败处理**：3 次重试，仍失败落 DLQ（MySQL `write_failures` 表）

## 启动

```bash
cd services/admin
php artisan easylog:writer
# 或自定义参数
php artisan easylog:writer --group=easylog-writer-1 --batch-size=2000 --flush-seconds=10
```

## 处理流程

1. 订阅内部 Kafka topic `easylog.ingest.*`
2. 内存缓冲：
   - 默认 1000 条触发
   - 或 5 秒强制触发（避免低流量时延迟过高）
3. 批量写入：
   - 调用 `StorageAdapter::bulkWrite($indexName, $docs)`
   - 索引名按月滚动：`easylog-{workspace_uuid}-{topic_uuid}-{yyyymm}`
4. 失败重试：
   - 单条失败重试 3 次
   - 仍失败落 `write_failures` 表（含 `_raw`、错误信息、可手动重投）
5. 提交 Kafka offset

## 监控指标

通过 `/metrics` Prometheus exporter 暴露：

| 指标 | 说明 |
|---|---|
| `easylog_writer_consumed_total` | 已消费日志数 |
| `easylog_writer_writes_total` | 已写入存储数 |
| `easylog_writer_errors_total` | 写入失败数 |
| `easylog_writer_lag` | Kafka consumer lag |
| `easylog_writer_buffer_size` | 当前缓冲大小 |
| `easylog_writer_bulk_size_bytes` | 单批写入字节数 |

## 关联代码

- 命令：`services/admin/app/Console/Commands/WriterCommand.php`
- 服务：`services/admin/app/Services/Writer/WriterService.php`
- 存储适配器：`services/admin/app/Services/Storage/{ElasticsearchAdapter,OpenObserveAdapter,QuickwitAdapter}.php`
