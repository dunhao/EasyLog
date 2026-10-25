/* ============================================================
   EasyLog Prototype · Mock Data & API Stubs
   All pages share this file. Returns Promises with 200ms delay.
   ============================================================ */

const MockData = {
  /* ---- 1. Workspaces ---- */
  workspaces: [
    { uuid: "ws_acme_prod", name: "Acme 生产", topics: 12, endpoints: 8,
      ingest_gb_today: 4.2, query_count_today: 1523, alerts_active: 2,
      retention_days: 30, status: "active", created_at: "2025-11-20" },
    { uuid: "ws_acme_staging", name: "Acme 预发", topics: 8, endpoints: 4,
      ingest_gb_today: 1.8, query_count_today: 423, alerts_active: 0,
      retention_days: 14, status: "active", created_at: "2025-11-20" },
    { uuid: "ws_marketing", name: "营销中台", topics: 5, endpoints: 3,
      ingest_gb_today: 0.6, query_count_today: 78, alerts_active: 1,
      retention_days: 30, status: "active", created_at: "2026-01-15" },
    { uuid: "ws_data_platform", name: "数据平台", topics: 24, endpoints: 12,
      ingest_gb_today: 12.3, query_count_today: 3521, alerts_active: 3,
      retention_days: 90, status: "active", created_at: "2026-03-08" },
    { uuid: "ws_legacy_archive", name: "归档仓库", topics: 3, endpoints: 1,
      ingest_gb_today: 0.1, query_count_today: 12, alerts_active: 0,
      retention_days: 365, status: "active", created_at: "2024-06-01" },
  ],

  /* ---- 2. Topics (across workspaces) ---- */
  topics: [
    { uuid: "tpc_order_logs", workspace_uuid: "ws_acme_prod", name: "order-logs",
      description: "订单服务日志", ingest_count_today: 12500000,
      storage_gb: 23.5, fields_count: 18, retention_days: 30, status: "active" },
    { uuid: "tpc_payment_logs", workspace_uuid: "ws_acme_prod", name: "payment-logs",
      description: "支付网关日志", ingest_count_today: 8200000,
      storage_gb: 18.7, fields_count: 22, retention_days: 90, status: "active" },
    { uuid: "tpc_user_action", workspace_uuid: "ws_acme_prod", name: "user-action",
      description: "用户行为埋点", ingest_count_today: 32000000,
      storage_gb: 45.2, fields_count: 12, retention_days: 30, status: "active" },
    { uuid: "tpc_nginx_access", workspace_uuid: "ws_acme_prod", name: "nginx-access",
      description: "Nginx 访问日志", ingest_count_today: 58000000,
      storage_gb: 32.1, fields_count: 24, retention_days: 14, status: "active" },
    { uuid: "tpc_app_errors", workspace_uuid: "ws_acme_prod", name: "app-errors",
      description: "应用异常日志", ingest_count_today: 245000,
      storage_gb: 2.8, fields_count: 16, retention_days: 60, status: "active" },
    { uuid: "tpc_inventory", workspace_uuid: "ws_acme_staging", name: "inventory-logs",
      description: "库存服务日志", ingest_count_today: 980000,
      storage_gb: 1.9, fields_count: 14, retention_days: 14, status: "active" },
    { uuid: "tpc_campaign", workspace_uuid: "ws_marketing", name: "campaign-logs",
      description: "营销活动日志", ingest_count_today: 560000,
      storage_gb: 0.8, fields_count: 10, retention_days: 30, status: "active" },
    { uuid: "tpc_spark_jobs", workspace_uuid: "ws_data_platform", name: "spark-jobs",
      description: "Spark 任务日志", ingest_count_today: 8900000,
      storage_gb: 56.3, fields_count: 20, retention_days: 90, status: "active" },
  ],

  /* ---- 3. Ingestion Endpoints ---- */
  endpoints: [
    { uuid: "ep_order_kafka", workspace_uuid: "ws_acme_prod", topic_uuid: "tpc_order_logs",
      name: "订单 Kafka 接入", source_type: "kafka", source_type_label: "Kafka 消费",
      app_id: "app_x9f3k2", app_secret_masked: "sec_************",
      rate_limit_per_sec: 5000, daily_quota_gb: 50, status: "active",
      last_active: "2026-09-12 14:30:00", ingest_count_today: 12500000 },
    { uuid: "ep_pay_http", workspace_uuid: "ws_acme_prod", topic_uuid: "tpc_payment_logs",
      name: "支付 HTTP 直推", source_type: "http", source_type_label: "HTTP API",
      app_id: "app_a8d2f1", app_secret_masked: "sec_************",
      rate_limit_per_sec: 2000, daily_quota_gb: 30, status: "active",
      last_active: "2026-09-12 14:31:22", ingest_count_today: 8200000 },
    { uuid: "ep_action_rabbit", workspace_uuid: "ws_acme_prod", topic_uuid: "tpc_user_action",
      name: "用户行为 RabbitMQ", source_type: "rabbitmq", source_type_label: "RabbitMQ 消费",
      app_id: "app_m3k9r2", app_secret_masked: "sec_************",
      rate_limit_per_sec: 8000, daily_quota_gb: 100, status: "active",
      last_active: "2026-09-12 14:29:11", ingest_count_today: 32000000 },
    { uuid: "ep_nginx_filebeat", workspace_uuid: "ws_acme_prod", topic_uuid: "tpc_nginx_access",
      name: "Nginx Filebeat→Redis", source_type: "redis", source_type_label: "Redis Stream",
      app_id: "app_n7f1d3", app_secret_masked: "sec_************",
      rate_limit_per_sec: 15000, daily_quota_gb: 200, status: "active",
      last_active: "2026-09-12 14:30:45", ingest_count_today: 58000000 },
    { uuid: "ep_legacy_activemq", workspace_uuid: "ws_legacy_archive", topic_uuid: "tpc_app_errors",
      name: "老系统 ActiveMQ", source_type: "activemq", source_type_label: "ActiveMQ Stomp",
      app_id: "app_l2o8k1", app_secret_masked: "sec_************",
      rate_limit_per_sec: 500, daily_quota_gb: 5, status: "active",
      last_active: "2026-09-12 14:28:00", ingest_count_today: 245000 },
    { uuid: "ep_sdk_php", workspace_uuid: "ws_acme_prod", topic_uuid: "tpc_order_logs",
      name: "PHP SDK 直推", source_type: "sdk", source_type_label: "SDK 直推",
      app_id: "app_s5p4k2", app_secret_masked: "sec_************",
      rate_limit_per_sec: 1000, daily_quota_gb: 20, status: "active",
      last_active: "2026-09-12 14:31:00", ingest_count_today: 3500000 },
  ],

  /* ---- 4. Log entries (for search page) ---- */
  logs: [
    { ts: "2026-09-12 14:32:15.234", level: "ERROR", service: "order-service",
      host: "pod-order-7d8-x4", request_id: "req_a8f3k2",
      msg: "Failed to charge order: timeout from payment gateway",
      extra: '{"order_id":"A1023","amount":199.00,"customer_id":"c_8821"}' },
    { ts: "2026-09-12 14:32:14.890", level: "WARN", service: "payment-service",
      host: "pod-pay-2d1-x2", request_id: "req_b7e1j9",
      msg: "Payment gateway latency 850ms, threshold 500ms", extra: '{"provider":"alipay"}' },
    { ts: "2026-09-12 14:32:14.123", level: "INFO", service: "order-service",
      host: "pod-order-7d8-x4", request_id: "req_a8f3k2",
      msg: "Order A1023 created, awaiting payment", extra: '{"amount":199.00}' },
    { ts: "2026-09-12 14:32:13.567", level: "DEBUG", service: "cart-service",
      host: "pod-cart-3f8-x1", request_id: "req_c3d5r7",
      msg: "Cart cache hit for user c_8821", extra: '{"items":3}' },
    { ts: "2026-09-12 14:32:12.345", level: "ERROR", service: "inventory-service",
      host: "pod-inv-9a2-x3", request_id: "req_d1f8k4",
      msg: "Stock check failed: SKU S-8801 not found in DB",
      extra: '{"sku":"S-8801"}' },
    { ts: "2026-09-12 14:32:11.012", level: "FATAL", service: "payment-service",
      host: "pod-pay-2d1-x2", request_id: "req_b7e1j9",
      msg: "DB connection pool exhausted (max=50), aborting",
      extra: '{"active":50,"waiting":12}' },
    { ts: "2026-09-12 14:32:10.456", level: "INFO", service: "nginx",
      host: "node-nginx-01", request_id: "req_e5g2t8",
      msg: "GET /api/orders/A1023 200 4ms", extra: '{"status":200,"bytes":1234}' },
    { ts: "2026-09-12 14:32:09.789", level: "TRACE", service: "order-service",
      host: "pod-order-7d8-x4", request_id: "req_a8f3k2",
      msg: "enter OrderService.charge(orderId=A1023)", extra: '{}' },
    { ts: "2026-09-12 14:32:08.123", level: "WARN", service: "cache-service",
      host: "node-cache-02", request_id: "req_f2h9j1",
      msg: "Redis memory usage 78%, near limit",
      extra: '{"used_mb":7800,"max_mb":10240}' },
    { ts: "2026-09-12 14:32:07.456", level: "INFO", service: "auth-service",
      host: "pod-auth-5b3-x1", request_id: "req_g8k3m2",
      msg: "User c_8821 logged in from 192.168.1.100",
      extra: '{"ip":"192.168.1.100","method":"oauth"}' },
    { ts: "2026-09-12 14:32:06.789", level: "DEBUG", service: "search-service",
      host: "pod-search-1c9-x2", request_id: "req_h1l4n3",
      msg: "ES query took 23ms, returned 50 hits",
      extra: '{"took_ms":23,"hits":50}' },
    { ts: "2026-09-12 14:32:05.234", level: "ERROR", service: "notification-service",
      host: "pod-notif-4e2-x3", request_id: "req_i5m6o4",
      msg: "SMS provider returned 500, retrying (attempt 2/3)",
      extra: '{"provider":"tencent","phone":"186****1234"}' },
    { ts: "2026-09-12 14:32:04.567", level: "INFO", service: "order-service",
      host: "pod-order-7d8-x4", request_id: "req_j2n7p5",
      msg: "Order A1024 created, awaiting payment", extra: '{"amount":299.00}' },
    { ts: "2026-09-12 14:32:03.890", level: "WARN", service: "rate-limiter",
      host: "edge-rl-01", request_id: "req_k8q1r6",
      msg: "Rate limit 80% reached for app_x9f3k2",
      extra: '{"app_id":"app_x9f3k2","current":4000,"limit":5000}' },
  ],

  /* ---- 5. Alert Rules & Events ---- */
  alert_rules: [
    { uuid: "al_error_spike", workspace_uuid: "ws_acme_prod", topic_uuid: "tpc_order_logs",
      name: "ERROR 日志激增", condition: "level:ERROR count > 100/min",
      severity: "critical", notify_channels: ["webhook", "email"],
      status: "active", last_fired: "2026-09-12 13:55",
      fired_count_24h: 3, created_at: "2026-08-01" },
    { uuid: "al_pay_timeout", workspace_uuid: "ws_acme_prod", topic_uuid: "tpc_payment_logs",
      name: "支付超时监控", condition: "msg:'timeout' count > 50/5min",
      severity: "warning", notify_channels: ["dingtalk"],
      status: "active", last_fired: "2026-09-12 09:12",
      fired_count_24h: 1, created_at: "2026-08-15" },
    { uuid: "al_inventory_missing", workspace_uuid: "ws_acme_staging", topic_uuid: "tpc_inventory",
      name: "库存缺失告警", condition: "msg:'not found' count > 10/min",
      severity: "warning", notify_channels: ["webhook"],
      status: "active", last_fired: "2026-09-11 22:30",
      fired_count_24h: 0, created_at: "2026-09-01" },
    { uuid: "al_spark_failure", workspace_uuid: "ws_data_platform", topic_uuid: "tpc_spark_jobs",
      name: "Spark 任务失败", condition: "level:ERROR count > 5/min",
      severity: "critical", notify_channels: ["webhook", "email", "wecom"],
      status: "active", last_fired: "2026-09-12 14:05",
      fired_count_24h: 7, created_at: "2026-04-20" },
    { uuid: "al_campaign_low", workspace_uuid: "ws_marketing", topic_uuid: "tpc_campaign",
      name: "活动日志量过低", condition: "count < 100/10min",
      severity: "info", notify_channels: ["email"],
      status: "paused", last_fired: null,
      fired_count_24h: 0, created_at: "2026-08-25" },
  ],

  alert_events: [
    { uuid: "evt_001", rule_uuid: "al_error_spike", fired_at: "2026-09-12 13:55:00",
      resolved_at: "2026-09-12 14:02:00", severity: "critical",
      context: "ERROR 日志 5 分钟内 523 条 (阈值 100)", notify_status: "sent" },
    { uuid: "evt_002", rule_uuid: "al_spark_failure", fired_at: "2026-09-12 14:05:00",
      resolved_at: null, severity: "critical",
      context: "ERROR 日志 1 分钟内 8 条 (阈值 5)", notify_status: "sent" },
    { uuid: "evt_003", rule_uuid: "al_pay_timeout", fired_at: "2026-09-12 09:12:00",
      resolved_at: "2026-09-12 09:18:00", severity: "warning",
      context: "timeout 关键词 5 分钟内 67 条 (阈值 50)", notify_status: "sent" },
    { uuid: "evt_004", rule_uuid: "al_error_spike", fired_at: "2026-09-12 11:42:00",
      resolved_at: "2026-09-12 11:48:00", severity: "critical",
      context: "ERROR 日志 5 分钟内 312 条 (阈值 100)", notify_status: "sent" },
  ],

  /* ---- 6. Dashboards ---- */
  dashboards: [
    { uuid: "db_order_health", workspace_uuid: "ws_acme_prod",
      name: "订单服务健康度", charts: 6, created_by: "admin@easylog.dev",
      created_at: "2026-07-15", updated_at: "2026-09-10" },
    { uuid: "db_pay_latency", workspace_uuid: "ws_acme_prod",
      name: "支付链路延迟", charts: 4, created_by: "ops@easylog.dev",
      created_at: "2026-08-01", updated_at: "2026-09-09" },
    { uuid: "db_nginx_overview", workspace_uuid: "ws_acme_prod",
      name: "Nginx 访问总览", charts: 8, created_by: "admin@easylog.dev",
      created_at: "2026-06-20", updated_at: "2026-09-12" },
    { uuid: "db_spark_kpi", workspace_uuid: "ws_data_platform",
      name: "Spark 任务 KPI", charts: 5, created_by: "data@easylog.dev",
      created_at: "2026-05-10", updated_at: "2026-09-11" },
  ],

  /* ---- 7. Shipping Tasks ---- */
  shipping_tasks: [
    { uuid: "ship_001", workspace_uuid: "ws_acme_prod", topic_uuid: "tpc_nginx_access",
      name: "归档到 OSS", target_type: "oss", target_label: "阿里云 OSS",
      filter: "status>=400", status: "active",
      last_run: "2026-09-12 14:00:00", shipped_gb: 12.4 },
    { uuid: "ship_002", workspace_uuid: "ws_acme_prod", topic_uuid: "tpc_payment_logs",
      name: "同步到下游 Kafka", target_type: "kafka", target_label: "下游 Kafka 集群",
      filter: "*", status: "active",
      last_run: "2026-09-12 14:31:00", shipped_gb: 8.7 },
    { uuid: "ship_003", workspace_uuid: "ws_data_platform", topic_uuid: "tpc_spark_jobs",
      name: "导出错误日志到 ES", target_type: "http", target_label: "下游 ES HTTP",
      filter: "level:ERROR", status: "active",
      last_run: "2026-09-12 14:30:00", shipped_gb: 2.3 },
    { uuid: "ship_004", workspace_uuid: "ws_marketing", topic_uuid: "tpc_campaign",
      name: "归档到 OSS", target_type: "oss", target_label: "阿里云 OSS",
      filter: "*", status: "paused",
      last_run: "2026-09-11 23:00:00", shipped_gb: 0.6 },
  ],

  /* ---- 8. Consumption Groups ---- */
  consumption_groups: [
    { uuid: "cg_001", workspace_uuid: "ws_acme_prod", topic_uuid: "tpc_order_logs",
      consumer_group: "audit-pipeline", auth_token_masked: "tok_************",
      members: 3, lag: 12, status: "active", created_at: "2026-07-01" },
    { uuid: "cg_002", workspace_uuid: "ws_acme_prod", topic_uuid: "tpc_user_action",
      consumer_group: "realtime-dashboard", auth_token_masked: "tok_************",
      members: 5, lag: 0, status: "active", created_at: "2026-08-10" },
    { uuid: "cg_003", workspace_uuid: "ws_data_platform", topic_uuid: "tpc_spark_jobs",
      consumer_group: "etl-spark-job", auth_token_masked: "tok_************",
      members: 2, lag: 1240, status: "active", created_at: "2026-05-22" },
    { uuid: "cg_004", workspace_uuid: "ws_marketing", topic_uuid: "tpc_campaign",
      consumer_group: "ml-feature-pipe", auth_token_masked: "tok_************",
      members: 1, lag: 0, status: "paused", created_at: "2026-08-30" },
  ],

  /* ---- 9. Usage (per workspace per day for 7 days) ---- */
  usage_series: {
    ingest_gb: [3.8, 4.2, 4.0, 4.5, 4.2, 4.6, 4.2],
    query_count: [1280, 1523, 1410, 1620, 1523, 1750, 1523],
    storage_gb: [420, 425, 430, 435, 440, 445, 450],
  },

  /* ---- 10. Users & Roles ---- */
  users: [
    { id: 1, email: "admin@easylog.dev", name: "Admin", role: "owner",
      status: "active", last_login: "2026-09-12 14:30", avatar_color: "#4F7CFF" },
    { id: 2, email: "ops@easylog.dev", name: "运维同学", role: "admin",
      status: "active", last_login: "2026-09-12 13:15", avatar_color: "#2EBA68" },
    { id: 3, email: "dev@easylog.dev", name: "开发同学", role: "viewer",
      status: "active", last_login: "2026-09-12 09:42", avatar_color: "#F5A623" },
    { id: 4, email: "data@easylog.dev", name: "数据同学", role: "admin",
      status: "active", last_login: "2026-09-11 18:30", avatar_color: "#A625FF" },
    { id: 5, email: "billing@easylog.dev", name: "财务同学", role: "billing",
      status: "active", last_login: "2026-09-10 11:00", avatar_color: "#F5455C" },
    { id: 6, email: "intern@easylog.dev", name: "实习生", role: "viewer",
      status: "suspended", last_login: "2026-09-01 14:00", avatar_color: "#6B7180" },
  ],

  /* ---- 11. Audit Logs ---- */
  audit_logs: [
    { ts: "2026-09-12 14:30:15", user: "admin@easylog.dev", action: "endpoint.create",
      target: "ep_order_kafka", context: "创建 Kafka 接入点", ip: "1.2.3.4" },
    { ts: "2026-09-12 14:05:00", user: "ops@easylog.dev", action: "alert_rule.update",
      target: "al_spark_failure", context: "调整阈值 5→8", ip: "1.2.3.4" },
    { ts: "2026-09-12 13:55:00", user: "system", action: "alert.fired",
      target: "al_error_spike", context: "触发告警", ip: "127.0.0.1" },
    { ts: "2026-09-12 11:20:00", user: "dev@easylog.dev", action: "search.execute",
      target: "tpc_order_logs", context: "查询 ERROR 日志", ip: "1.2.3.4" },
    { ts: "2026-09-12 09:12:00", user: "system", action: "alert.fired",
      target: "al_pay_timeout", context: "触发告警", ip: "127.0.0.1" },
    { ts: "2026-09-11 22:30:00", user: "ops@easylog.dev", action: "endpoint.rotate_secret",
      target: "ep_pay_http", context: "轮换 app_secret", ip: "1.2.3.4" },
  ],

  /* ---- 12. Topic Fields ---- */
  topic_fields: [
    { field: "ts", type: "date", indexed: true, analyzer: "—" },
    { field: "level", type: "keyword", indexed: true, analyzer: "keyword" },
    { field: "service", type: "keyword", indexed: true, analyzer: "keyword" },
    { field: "host", type: "keyword", indexed: true, analyzer: "keyword" },
    { field: "request_id", type: "keyword", indexed: true, analyzer: "keyword" },
    { field: "msg", type: "text", indexed: true, analyzer: "standard" },
    { field: "extra", type: "object", indexed: false, analyzer: "—" },
    { field: "order_id", type: "keyword", indexed: true, analyzer: "keyword" },
    { field: "amount", type: "double", indexed: true, analyzer: "—" },
    { field: "customer_id", type: "keyword", indexed: true, analyzer: "keyword" },
  ],
};

/* ============================================================
   Mock API - returns Promises with delay
   ============================================================ */
const MockAPI = {
  _delay: (ms = 200) => new Promise(r => setTimeout(r, ms)),

  async listWorkspaces() { await this._delay(); return MockData.workspaces.slice(); },
  async getWorkspace(uuid) { await this._delay(); return MockData.workspaces.find(w => w.uuid === uuid); },

  async listTopics(workspaceUuid) {
    await this._delay();
    return workspaceUuid
      ? MockData.topics.filter(t => t.workspace_uuid === workspaceUuid)
      : MockData.topics.slice();
  },

  async listEndpoints(workspaceUuid) {
    await this._delay();
    return workspaceUuid
      ? MockData.endpoints.filter(e => e.workspace_uuid === workspaceUuid)
      : MockData.endpoints.slice();
  },

  async searchLogs(query = "*", level = "ALL", size = 50) {
    await this._delay(300);
    let logs = MockData.logs.slice();
    if (level !== "ALL") logs = logs.filter(l => l.level === level);
    if (query && query !== "*") {
      const q = query.toLowerCase();
      logs = logs.filter(l =>
        l.msg.toLowerCase().includes(q) ||
        l.service.toLowerCase().includes(q) ||
        l.extra.toLowerCase().includes(q)
      );
    }
    return logs.slice(0, size);
  },

  async listAlertRules() { await this._delay(); return MockData.alert_rules.slice(); },
  async listAlertEvents() { await this._delay(); return MockData.alert_events.slice(); },

  async listDashboards() { await this._delay(); return MockData.dashboards.slice(); },

  async listShippingTasks() { await this._delay(); return MockData.shipping_tasks.slice(); },

  async listConsumptionGroups() { await this._delay(); return MockData.consumption_groups.slice(); },

  async getUsageSeries() { await this._delay(); return MockData.usage_series; },

  async listUsers() { await this._delay(); return MockData.users.slice(); },

  async listAuditLogs() { await this._delay(); return MockData.audit_logs.slice(); },

  async listTopicFields() { await this._delay(); return MockData.topic_fields.slice(); },

  /* Dummy create methods return fake uuids */
  async createWorkspace(name) {
    await this._delay();
    return { uuid: "ws_" + Math.random().toString(36).slice(2, 8), name };
  },

  async testConnection(source_type, config) {
    await this._delay(800);
    const ok = config.host && config.host.length > 3;
    return { success: ok, message: ok ? "连接成功，发现 1 个 queue/topic" : "连接失败：主机不可达" };
  },
};

/* ============================================================
   Common helpers (shared by all pages)
   ============================================================ */
const EasyLogUI = {
  /* Render the standard App Shell into the current document body.
     Each page passes:
     - activeNavKey: matches a key in NAV_ITEMS
     - breadcrumbHTML: optional breadcrumb string
     - pageTitle: page heading text
     - actionsHTML: optional action buttons HTML
     - mainHTML: the page's main content
  */
  render({ activeNavKey = "", pageTitle = "", breadcrumbHTML = "", actionsHTML = "", mainHTML = "" } = {}) {
    document.body.innerHTML = `
      <div class="app-shell">
        ${EasyLogUI.sidebarHTML(activeNavKey)}
        <div class="main-area">
          ${EasyLogUI.topbarHTML()}
          <main class="main-content">
            <div class="page-header">
              <div class="page-title-group">
                <h1>${pageTitle}</h1>
                ${breadcrumbHTML ? `<div class="breadcrumb">${breadcrumbHTML}</div>` : ""}
              </div>
              ${actionsHTML ? `<div class="page-actions">${actionsHTML}</div>` : ""}
            </div>
            ${mainHTML}
          </main>
        </div>
      </div>
    `;
    if (window.lucide) lucide.createIcons();
  },

  sidebarHTML(activeKey) {
    const groups = [
      { title: "概览", items: [
        { key: "dashboard", label: "仪表盘", icon: "layout-dashboard", href: "index.html" },
      ]},
      { title: "日志管理", items: [
        { key: "workspaces", label: "工作空间", icon: "folder", href: "workspaces.html" },
        { key: "topics", label: "日志主题", icon: "file-text", href: "topics.html" },
        { key: "search", label: "日志检索", icon: "search", href: "search.html" },
        { key: "dashboards", label: "仪表盘库", icon: "bar-chart-3", href: "dashboards.html" },
      ]},
      { title: "接入与流转", items: [
        { key: "endpoints", label: "接入点", icon: "plug", href: "endpoints.html" },
        { key: "shipping", label: "投递任务", icon: "send", href: "shipping.html" },
        { key: "consumption", label: "消费组", icon: "users", href: "consumption.html" },
      ]},
      { title: "监控告警", items: [
        { key: "alerts", label: "告警规则", icon: "bell", href: "alerts.html" },
        { key: "usage", label: "用量配额", icon: "activity", href: "usage.html" },
      ]},
      { title: "管理", items: [
        { key: "users", label: "用户权限", icon: "shield", href: "users.html" },
        { key: "settings", label: "系统设置", icon: "settings", href: "settings.html" },
      ]},
    ];
    const groupHTML = groups.map(g => `
      <div class="nav-group">
        <div class="nav-group-title">${g.title}</div>
        ${g.items.map(it => `
          <a class="nav-link ${it.key === activeKey ? "active" : ""}" href="${it.href}">
            <i data-lucide="${it.icon}"></i>
            <span>${it.label}</span>
          </a>
        `).join("")}
      </div>
    `).join("");
    return `
      <aside class="sidebar">
        <div class="sidebar-logo">
          <div class="sidebar-logo-mark">E</div>
          <span>EasyLog</span>
        </div>
        <nav class="sidebar-nav">${groupHTML}</nav>
      </aside>
    `;
  },

  topbarHTML() {
    return `
      <header class="topbar">
        <div class="topbar-left">
          <div class="workspace-switch">
            <i data-lucide="building-2" style="width:14px;height:14px;"></i>
            <span>Acme 生产</span>
            <i data-lucide="chevrons-up-down" style="width:12px;height:12px;"></i>
          </div>
          <div class="global-search">
            <i data-lucide="search"></i>
            <input type="text" placeholder="检索日志 ⌘K" />
          </div>
        </div>
        <div class="topbar-right">
          <span class="env-badge env-prod">PROD</span>
          <button class="icon-btn"><i data-lucide="bell"></i></button>
          <button class="icon-btn"><i data-lucide="question-circle"></i></button>
          <div class="user-chip">
            <div class="user-avatar">AD</div>
            <span class="user-name">Admin</span>
            <i data-lucide="chevron-down" style="width:12px;height:12px;"></i>
          </div>
        </div>
      </header>
    `;
  },

  /* Render helper for a stat card */
  statCard(label, value, trend = "", trendClass = "trend-flat", sub = "") {
    return `
      <div class="stat-card">
        <div class="stat-label">${label}</div>
        <div class="stat-value">${value}</div>
        ${trend ? `<div class="stat-trend ${trendClass}"><i data-lucide="${trendClass === 'trend-up' ? 'trending-up' : (trendClass === 'trend-down' ? 'trending-down' : 'minus')}"></i>${trend}</div>` : ""}
        ${sub ? `<div class="text-xs text-muted mt-2">${sub}</div>` : ""}
      </div>
    `;
  },

  /* Level badge */
  levelBadge(level) {
    return `<span class="lvl lvl-${level}">${level}</span>`;
  },

  /* Status badge */
  statusBadge(status) {
    const map = { active: "success", paused: "warning", suspended: "muted", disabled: "muted" };
    return `<span class="badge badge-${map[status] || "muted"}">${({active:"运行中", paused:"已暂停", suspended:"已停用", disabled:"已禁用"})[status] || status}</span>`;
  },

  /* Source type badge */
  sourceBadge(type) {
    const icons = { kafka: "zap", rabbitmq: "rabbit", redis: "database", activemq: "message-square", http: "globe", sdk: "code", syslog: "server", filebeat: "file-text" };
    const labels = { kafka: "Kafka", rabbitmq: "RabbitMQ", redis: "Redis", activemq: "ActiveMQ", http: "HTTP", sdk: "SDK", syslog: "Syslog", filebeat: "Filebeat" };
    return `<span class="badge badge-info"><i data-lucide="${icons[type] || 'circle'}" style="width:10px;height:10px;"></i>${labels[type] || type}</span>`;
  },

  formatNumber(n) {
    if (n >= 1e9) return (n / 1e9).toFixed(2) + "B";
    if (n >= 1e6) return (n / 1e6).toFixed(2) + "M";
    if (n >= 1e3) return (n / 1e3).toFixed(1) + "k";
    return String(n);
  },
};
