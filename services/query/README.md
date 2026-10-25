# EasyLog Query Service

查询服务：接收用户查询 → 鉴权 → 跨主题联邦检索 → DSL 转译 → 结果二次过滤保证租户隔离。

## 设计

- **共享 Laravel 11 安装**：位于 `services/admin/`
- **入口**：通过 Octane Swoole 监听独立端口
- **多副本**：无状态，可水平扩

## 启动

```bash
cd services/admin
php artisan octane:start --server=swoole --host=0.0.0.0 --port=9515
```

## 关键端点

| Method | Path | 鉴权 | 说明 |
|---|---|---|---|
| POST | `/api/v1/workspaces/{ws}/search` | JWT | 跨主题检索 |
| POST | `/api/v1/topics/{topic}/search` | JWT | 主题内检索 |
| POST | `/api/v1/topics/{topic}/histogram` | JWT | 时间分布直方图 |
| POST | `/api/v1/topics/{topic}/agg` | JWT | 聚合分析 |

## 处理流程

1. **鉴权**：JWT → 用户 + 工作空间 + 角色校验
2. **查询解析**：
   - Lucene/KQL 风格 → Elasticsearch Query DSL
   - SQL → ES SQL API
3. **路由**：根据 `topics` 参数（单个或多个）确定索引模式
4. **存储调用**：`StorageAdapter::search($indexPattern, $dsl)`
5. **二次过滤**：确保返回的每个文档 `workspace_id` 与当前一致（防越权）
6. **结果聚合**：跨主题联邦检索时合并结果

## 关联代码

- 控制器：`services/admin/app/Http/Controllers/SearchController.php`
- 服务：`services/admin/app/Services/Query/QueryTranslator.php`
