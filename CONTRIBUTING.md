# 贡献指南

感谢关注 EasyLog！欢迎以任何形式参与：提 Issue、修 Bug、新功能、完善文档。

## 快速上手（本地开发）

```bash
# 后端（PHP 8.2+）
cd services/admin
composer install
cp .env.example .env && php artisan key:generate
php vendor/bin/phpunit          # 内存驱动，无需外部服务

# 前端（Node 20）
cd frontend && npm ci && npm run build

# Agent 采集器（零依赖 standalone 测试）
php sdk/agent/tests/run.php
```

完整运行环境推荐用 lite 档（2 容器、零外部依赖）：

```bash
cd deploy/compose && ./start.sh      # http://localhost:9516
```

详见 [README](README.md) 与 [部署指南](docs/05-deployment-guide.md)。

## 提交规范

- 提交信息使用约定式前缀：`feat:` / `fix:` / `docs:` / `chore:` / `refactor:` / `test:`
  （中英文均可，一行说清改了什么、为什么）
- 一个 PR 只做一件事；请保持改动最小、可回滚
- 新增功能请附带测试；修 Bug 请先写复现用例

## PR 检查清单

- [ ] 本地测试通过（后端 phpunit / 相关 SDK 测试 / agent 测试）
- [ ] 涉及部署的改动已更新 `deploy/` 与 `docs/05-deployment-guide.md`
- [ ] 不包含任何真实凭据、内网地址、密钥（demo 凭据除外）
- [ ] 新配置项已写入对应 `.env.example` 并有注释说明

## 架构速览（改哪里）

| 目录 | 内容 |
|---|---|
| `services/admin/app/Services/Storage/` | 存储适配器（ES / OpenObserve / Quickwit / SQLite）与 DSL 编译器 |
| `services/admin/app/Services/MQ/` | 内部总线（rdkafka / direct / log / inmemory）与外部 MQ 消费者 |
| `services/admin/app/Http/Controllers/` | 接入 API / 检索 / 管理面 |
| `services/admin/app/Services/Query/` | Lucene 子集与 SQL 子集查询翻译器 |
| `frontend/src/` | Vue3 控制台 |
| `sdk/` | php / go / java SDK 与文件采集 agent |
| `deploy/` | compose 三档编排 / 镜像 / k8s 清单 |

## 安全问题

请勿以公开 Issue 报告安全漏洞，参见 [SECURITY.md](SECURITY.md)。
