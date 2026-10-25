# EasyLog Frontend

EasyLog 日志服务管理控制台。基于 Vue 3 + Vite 5 + TypeScript + Element Plus + ECharts。

## 开发

```bash
cd frontend
pnpm install   # 或 npm install / yarn
pnpm dev       # 默认 9516 端口
```

开发模式默认代理 `/api` 到 `http://127.0.0.1:9515`（query 服务），可在 `.env.local` 中覆盖 `VITE_API_PROXY_TARGET`。

## 目录结构

```
frontend/
├── public/                  # 静态资源（favicon 等）
├── src/
│   ├── api/                 # API 客户端
│   │   ├── http.ts          # axios 实例 + 拦截器
│   │   ├── index.ts         # 统一出口 + 通用类型
│   │   └── modules/         # 按业务域拆分（auth/workspace/topic/endpoint/search/alert/dashboard/user）
│   ├── components/          # 通用组件（PageHeader / EmptyState …）
│   ├── layouts/
│   │   └── DefaultLayout.vue# 主布局（侧边栏 + 顶栏 + 主区）
│   ├── router/
│   │   └── index.ts         # 路由配置，与原型页面一一对应
│   ├── stores/              # Pinia stores
│   │   ├── auth.ts          # 登录态、token
│   │   └── workspace.ts    # 当前工作空间上下文
│   ├── styles/
│   │   ├── variables.scss   # CSS 变量 + 日志级别配色
│   │   └── index.scss       # 全局样式 + 工具类
│   ├── views/               # 页面组件（13 个，对应原型）
│   ├── App.vue              # 根组件
│   ├── main.ts              # 应用入口
│   └── vite-env.d.ts        # 类型声明
├── .env.example             # 环境变量模板
├── .gitignore
├── index.html
├── package.json
├── tsconfig.json
├── tsconfig.node.json
└── vite.config.ts           # Vite 配置（含 Element Plus 按需引入）
```

## 页面清单

| 路由 | 文件 | 说明 |
|---|---|---|
| `/dashboard` | DashboardView | 仪表盘（QPS / 存储量 / 活跃告警） |
| `/search` | SearchView | 日志检索（Lucene/KQL 语法 + 直方图 + 结果列表） |
| `/topics` | TopicsView | 日志主题（字段配置 / 保留策略） |
| `/endpoints` | EndpointsView | 接入点（4 种 MQ + HTTP API + SDK） |
| `/alerts` | AlertsView | 告警（规则 + 事件） |
| `/dashboards` | DashboardsView | 自定义仪表盘 |
| `/workspaces` | WorkspacesView | 工作空间管理 |
| `/users` | UsersView | 用户与权限（RBAC） |
| `/usage` | UsageView | 用量统计 |
| `/consumption` | ConsumptionView | 消费明细 |
| `/shipping` | ShippingView | 投递与导出 |
| `/settings` | SettingsView | 平台设置 |
| `/login` | LoginView | 登录页 |
| `/:pathMatch(.*)*` | NotFoundView | 404 |

## 构建产物

`pnpm build` 输出到 `dist/`，可直接由 Nginx 托管。生产环境通常配合 admin 服务的 `public/` 目录或独立静态站点部署，所有 `/api/*` 请求通过反代到 query 服务（9515）。
