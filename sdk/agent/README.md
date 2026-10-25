# EasyLog Agent（日志文件采集器）

免依赖的**单文件 PHP 采集器**：放进目标项目（或直接放到服务器），配置日志目录，
常驻运行即可把已有的日志文件（如 ThinkPHP 的 `runtime/log/`、Laravel 的
`storage/logs/`）tail → 解析 → 批量推送到 EasyLog。**业务代码零改动**。

- 单文件、无 composer 依赖，PHP 7.2+ CLI 即可运行（PHP 项目机器天然满足）
- EasyLog lite / core / full 三档通用（只需接入地址与 AppID/Secret）
- 断点续采（state 文件记录 inode + 偏移，重启不重发）
- 轮转/截断自适应；多行堆栈自动合并为一条日志
- 批量上报 + 指数退避重试；缓冲上限保护防内存打爆

> 代码内集成（不走文件）请用现有 SDK：`sdk/php`（Monolog）、`sdk/go`（slog）、
> `sdk/java`（Logback）。Agent 面向"已有日志文件、不想改代码"的场景。

## 快速开始

1. 在 EasyLog 控制台创建接入点，拿到 `AppID` 和 `AppSecret`（工作空间 → 接入点）。
2. 把 `easylog-agent` 和配置文件放到目标机器（任意目录）：

```bash
cp sdk/agent/easylog-agent /opt/easylog/
cp sdk/agent/easylog-agent.json.example /opt/easylog/easylog-agent.json
vim /opt/easylog/easylog-agent.json     # 改 server.url / app_id / app_secret / watches
```

3. 先干跑验证解析效果（只打印不上报）：

```bash
php /opt/easylog/easylog-agent test -c /opt/easylog/easylog-agent.json
```

4. 正式常驻：

```bash
php /opt/easylog/easylog-agent start -c /opt/easylog/easylog-agent.json
```

接入地址按档位：lite 档 `http://<host>:9515/api/v1`；core/full 档独立接入网关
`http://<host>:9514/api/v1`。

## 配置说明

| 键 | 默认 | 说明 |
|---|---|---|
| `server.url` | 必填 | EasyLog 接入基址（含 `/api/v1`） |
| `server.app_id` / `server.app_secret` | 必填 | 接入点凭据（控制台创建） |
| `server.timeout` | 10 | HTTP 超时（秒） |
| `topic` | `""` | 默认 topic uuid（空 = 接入点的默认 topic） |
| `state_file` | 脚本同目录 | 采集进度文件 |
| `batch_size` / `flush_interval_ms` | 500 / 2000 | 批量条数 / 最长滞留时间 |
| `scan_interval_ms` | 2000 | 文件扫描间隔 |
| `max_buffer` | 100000 | 发送失败时缓冲上限（超出丢最旧并计数） |
| `start_position` | `end` | 新文件起点：`end` 只采增量；`beginning` 采集历史 |
| `host_field` | `host` | 附加的主机名字段名（空串关闭） |

### watches[] 每项

| 键 | 说明 |
|---|---|
| `path` | 日志**目录**（配合 `recursive`/`pattern`）或 **glob 模式**（如 `runtime/log/*.log`、`/data/services/*/logs/app-*.log`） |
| `recursive` | 目录是否递归（默认 false） |
| `pattern` | 目录内文件名匹配（默认 `*.log`） |
| `parser` | 解析器：`thinkphp` / `laravel` / `json` / `regex` / `plain` |
| `line_pattern` | `regex` 解析器的命名分组正则，需含 `(?<ts>...) (?<level>...) (?<msg>...)`（level/ts 可缺省） |
| `topic` | 覆盖全局 topic（topic uuid） |
| `start_position` | 覆盖全局起点 |
| `source_prefix` | `source` 字段前缀（多项目同日志名时区分用） |
| `fields` | 附加静态字段（如 `{"env":"prod"}`） |

### 解析器

| parser | 适配格式 | 多行合并 |
|---|---|---|
| `thinkphp` | TP5/TP6：`[2026-10-25 10:00:00][info]...`、`[ 2026-10-25T10:00:00+08:00 ] [ info ] ...`，自动跳过 TP5 分隔线 | ✅（堆栈续行并入上一条） |
| `laravel` | `[2026-10-25 10:00:00] prod.ERROR: ...` | ✅ |
| `json` | 每行一个 JSON（`msg/message`、`level`、`ts/time/datetime` 自动映射，其余字段透传） | 解析失败作为续行 |
| `regex` | 自定义 `line_pattern` 命名分组 | 未匹配作为续行 |
| `plain` | 无格式，整行作 msg，level=INFO | ❌（逐行） |

级别自动归一到平台枚举（TRACE/DEBUG/INFO/WARN/ERROR/FATAL）。

每条日志自动附加：`source`（文件名，可加前缀）、`host`（主机名）、watch 的
`fields`——都可在控制台检索过滤。

## 常驻方式

**systemd**（推荐）：

```ini
# /etc/systemd/system/easylog-agent.service
[Unit]
Description=EasyLog Agent
After=network.target

[Service]
WorkingDirectory=/opt/easylog
ExecStart=/usr/bin/php /opt/easylog/easylog-agent start -c /opt/easylog/easylog-agent.json --quiet
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

**crontab**（无 root/常驻权限时，每分钟单轮）：

```cron
* * * * * php /opt/easylog/easylog-agent start -c /opt/easylog/easylog-agent.json --once --quiet
```

**supervisor**：

```ini
[program:easylog-agent]
command=php /opt/easylog/easylog-agent start -c /opt/easylog/easylog-agent.json --quiet
autostart=true
autorestart=true
```

## 命令

```
php easylog-agent start -c <conf> [--once] [--dry-run] [--quiet] [-v]
php easylog-agent test  -c <conf>     # = start --once --dry-run，验证配置与解析
php easylog-agent reset -c <conf>     # 清空进度，下次从头采集
php easylog-agent version
```

- `--once`：单轮采集并清空缓冲后退出（cron 模式）
- `--dry-run`：只解析打印，不上报
- `--quiet`：静默（仅错误输出 stderr）

## 运维说明

- 进度按**文件 inode + 偏移**记录：重启、断电不重发；`copytruncate` 式轮转
  （文件变短）自动重读；`rename + 新文件` 式轮转按 `start_position` 处理新文件。
- 鉴权失败（401/403）会**立即退出**（exit 2）避免无效死循环，检查凭据后由
  systemd/supervisor 拉起。
- 配置文件含 `app_secret`，请收紧权限：`chmod 600 easylog-agent.json`。
- 需要重新采集历史：`php easylog-agent reset -c <conf>`（配合 `start_position: beginning`）。

## 测试

```bash
php sdk/agent/tests/run.php
```
