# EasyLog 原型设计契约 (Design Contract)

> 版本：v1.0 | 日期：2026-09-12
> 用途：所有原型页面必须严格遵循本契约的视觉规范、App Shell、导航与 Mock Schema。

## 1. 技术栈与交付类型

- **Tech Stack**：plain HTML + CSS + vanilla JavaScript（无构建依赖）
- **Delivery Type**：pure-static（离线可打开，相对路径引用）
- **图表库**：ECharts 5.x（CDN）
- **图标库**：Lucide Icons（CDN）

## 2. 风格基调

- **Style Tier**：tech-dark（深色技术风，适合日志/监控类管理后台）
- **基调**：专业、克制、信息密度高、强调可读性
- **参考**：阿里云 SLS / 腾讯云 CLS / Grafana 暗色主题

## 3. 设计 Tokens

### 3.1 色彩系统

```
--bg-base:       #0B0E14  /* 全局背景 */
--bg-surface:    #11151F  /* 卡片/面板背景 */
--bg-elevated:   #1A1F2C  /* 次级悬浮 */
--bg-hover:      #232938  /* hover 状态 */
--bg-active:     #2A3142  /* 选中 */

--border-base:   #232938
--border-subtle: #1A1F2C
--border-strong: #2F3548

--text-primary:   #E4E7EF
--text-secondary: #9CA3B0
--text-muted:     #6B7180
--text-inverse:   #0B0E14

--accent-primary: #4F7CFF  /* 主蓝，链接/主按钮 */
--accent-hover:   #6B91FF
--accent-active:  #3A60E6
--accent-bg:       rgba(79, 124, 255, 0.12)

--success: #2EBA68  /* INFO/正常 */
--warning: #F5A623  /* WARN */
--danger:  #F5455C  /* ERROR */
--critical:#A625FF  /* FATAL */
--debug:   #8E96A8  /* DEBUG */
--trace:   #5B6173  /* TRACE */
```

### 3.2 字号阶

```
--text-xs:   11px  /* 表格次要信息 */
--text-sm:   12px  /* 表格、输入框 */
--text-base: 13px  /* 正文（信息密度高，base 偏小） */
--text-md:   14px  /* 主标题 */
--text-lg:   16px  /* 页面标题 */
--text-xl:   20px  /* Hero 数字 */
--text-2xl:  28px  /* 大型指标 */
```

### 3.3 圆角、间距、阴影

```
--radius-sm: 4px
--radius-md: 6px
--radius-lg: 8px
--radius-xl: 12px

--space-1: 4px
--space-2: 8px
--space-3: 12px
--space-4: 16px
--space-5: 24px
--space-6: 32px

--shadow-sm: 0 1px 2px rgba(0,0,0,0.3)
--shadow-md: 0 2px 8px rgba(0,0,0,0.4)
--shadow-lg: 0 4px 16px rgba(0,0,0,0.5)
```

### 3.4 字体

```
--font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC",
             "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
--font-mono: "JetBrains Mono", "SF Mono", Menlo, Monaco, Consolas, monospace;
```

## 4. 组件规范

### 4.1 按钮

- 主按钮：背景 `--accent-primary`，文字 `--text-inverse`，圆角 `--radius-md`，padding `6px 16px`。
- 次按钮：背景透明，边框 `1px solid --border-strong`，文字 `--text-primary`。
- 危险按钮：背景 `--danger`，文字白色。
- 尺寸：small (24px 高)、default (32px 高)、large (40px 高)。

### 4.2 输入框

- 背景 `--bg-base`，边框 `1px solid --border-base`，圆角 `--radius-md`，padding `6px 12px`，focus 边框变 `--accent-primary`。

### 4.3 表格

- 表头背景 `--bg-elevated`，文字 `--text-secondary`，`--text-xs`，大写。
- 行 hover 背景 `--bg-hover`。
- 行高 36px。
- 斑马纹：偶数行 `rgba(255,255,255,0.02)`。

### 4.4 卡片

- 背景 `--bg-surface`，圆角 `--radius-lg`，padding `--space-4`，边框 `1px solid --border-base`。

### 4.5 模态框

- 覆盖层 `rgba(0,0,0,0.6)` + backdrop-blur(4px)。
- 模态宽 480px / 720px / 960px（按内容大小）。
- 圆角 `--radius-xl`，背景 `--bg-surface`。

### 4.6 标签 / Badge

- 4px 圆角，`--text-xs`，padding `2px 6px`。
- 状态色：success/warning/danger/critical 各对应背景透明度 12% + 文字色。

### 4.7 日志级别配色

```
DEBUG  -> bg: rgba(142,150,168,0.12)  text: #8E96A8
INFO   -> bg: rgba(46,186,104,0.12)   text: #2EBA68
WARN   -> bg: rgba(245,166,35,0.12)   text: #F5A623
ERROR  -> bg: rgba(245,69,92,0.12)     text: #F5455C
FATAL  -> bg: rgba(166,37,255,0.12)   text: #A625FF
TRACE  -> bg: rgba(91,97,115,0.12)    text: #5B6173
```

## 5. App Shell + Canonical Nav（所有页面必须原样粘贴）

### 5.1 布局

固定左侧 220px 导航 + 顶部 56px 顶栏 + 主内容区。

```
┌──────────────────────────────────────────────────┐
│ TopBar (56px): Logo + 工作空间切换 + 搜索 + 用户 │
├──────┬───────────────────────────────────────────┤
│      │                                           │
│ Side │           Main Content                    │
│ Nav  │                                           │
│ 220  │                                           │
│      │                                           │
└──────┴───────────────────────────────────────────┘
```

### 5.2 侧边栏导航分组

```
概览
  └ 仪表盘 (index.html)

日志管理
  └ 工作空间 (workspaces.html)
  └ 日志主题 (topics.html)
  └ 日志检索 (search.html)
  └ 仪表盘库 (dashboards.html)

接入与流转
  └ 接入点 (endpoints.html)
  └ 投递任务 (shipping.html)
  └ 消费组 (consumption.html)

监控告警
  └ 告警规则 (alerts.html)
  └ 用量配额 (usage.html)

管理
  └ 用户权限 (users.html)
  └ 系统设置 (settings.html)
```

### 5.3 标记当前激活项规则

在 `<a>` 元素上加 `class="nav-link active"`，CSS 用左侧 3px `--accent-primary` 边条 + 背景 `--accent-bg` 标识。

### 5.4 顶栏规则

- 左侧：Logo "EasyLog" + 工作空间下拉（mock：acme-prod）
- 中部：全局搜索框（占位"检索日志 ⌘K"）
- 右侧：环境切换（dev/staging/prod mock）、通知图标、用户头像菜单（mock：admin@easylog.dev）

## 6. 页面清单

| 页面 | 文件 | 职责 |
|---|---|---|
| 仪表盘 | `index.html` | 全局总览：写入QPS、查询QPS、存储量、活跃告警、Top主题、近24h趋势 |
| 工作空间 | `workspaces.html` | 工作空间列表 + 创建 + 详情入口 |
| 日志主题 | `topics.html` | 主题列表 + 详情（含字段索引配置） |
| 日志检索 | `search.html` | 跨主题/单主题检索、时间分布直方图、上下文、字段过滤 |
| 仪表盘库 | `dashboards.html` | 自定义仪表盘列表 + 预览（含 ECharts mock） |
| 接入点 | `endpoints.html` | 接入点列表 + 4 种 MQ 配置表单 + HTTP/SDK 凭证 |
| 投递任务 | `shipping.html` | 投递任务列表 + 创建表单 |
| 消费组 | `consumption.html` | Kafka 协议消费组列表 + 凭证 |
| 告警规则 | `alerts.html` | 告警规则列表 + 创建表单 + 近期事件 |
| 用量配额 | `usage.html` | 用量统计 + 配额设置 |
| 用户权限 | `users.html` | 用户列表 + 角色 + 邀请 |
| 系统设置 | `settings.html` | 系统参数、存储引擎、备份、审计日志 |

## 7. Mock Schema（统一假数据结构）

### 7.1 工作空间

```js
{ uuid: "ws_acme_prod", name: "Acme 生产", topics: 12, endpoints: 8,
  ingest_gb_today: 4.2, query_count_today: 1523, alerts_active: 2,
  retention_days: 30, status: "active" }
```

### 7.2 主题

```js
{ uuid: "tpc_order_logs", workspace_uuid: "ws_acme_prod", name: "order-logs",
  description: "订单服务日志", ingest_count_today: 12500000,
  storage_gb: 23.5, fields_count: 18, retention_days: 30, status: "active" }
```

### 7.3 日志条目

```js
{ ts: "2026-09-12 14:32:15.234", level: "ERROR", service: "order-service",
  host: "pod-order-7d8-x4", request_id: "req_a8f3k2",
  msg: "Failed to charge order: timeout from payment gateway",
  extra: { order_id: "A1023", amount: 199.00, customer_id: "c_8821" } }
```

### 7.4 接入点

```js
{ uuid: "ep_order_kafka", workspace_uuid: "ws_acme_prod", topic_uuid: "tpc_order_logs",
  name: "订单 Kafka 接入", source_type: "kafka",
  source_type_label: "Kafka 消费",
  app_id: "app_x9f3k2", app_secret_masked: "sec_********",
  rate_limit_per_sec: 5000, daily_quota_gb: 50, status: "active",
  last_active: "2026-09-12 14:30:00", ingest_count_today: 12500000 }
```

### 7.5 告警规则

```js
{ uuid: "al_error_spike", workspace_uuid: "ws_acme_prod", topic_uuid: "tpc_order_logs",
  name: "ERROR 日志激增", condition: "level:ERROR count > 100/min",
  severity: "critical", notify_channels: ["webhook","email"],
  status: "active", last_fired: "2026-09-12 13:55" }
```

## 8. API Stub 约定

- 所有交互使用 `MockAPI.xxx()` 静态方法返回 Promise，延迟 200ms 模拟网络。
- 不实现真实后端；所有数据来自 `mock.js`。

## 9. 文件结构

```
prototype/
├── index.html              (仪表盘)
├── workspaces.html
├── topics.html
├── search.html
├── dashboards.html
├── endpoints.html
├── shipping.html
├── consumption.html
├── alerts.html
├── usage.html
├── users.html
├── settings.html
├── assets/
│   ├── styles.css          (全局样式 + App Shell)
│   ├── mock.js             (Mock 数据 + API)
│   └── charts.js           (ECharts 公共配置)
```
