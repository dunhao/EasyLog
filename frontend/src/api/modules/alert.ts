import { request, type PageResult, type PageQuery } from '../http';

export type AlertSeverity = 'info' | 'warning' | 'critical';

// 通知渠道：对象结构（webhook → config.url；email → config.to）
export interface NotifyChannel {
  type: 'webhook' | 'email' | 'sms' | 'phone';
  config?: Record<string, any>;
}

export interface AlertRule {
  uuid: string;
  workspace_uuid?: string;
  topic_id: number;
  topic_uuid?: string | null;
  name: string;
  // 条件对象：{ metric: 'count' } 等，后端为自由结构
  condition: Record<string, any>;
  window_sec: number;
  threshold: number;
  severity: AlertSeverity;
  notify_channels: NotifyChannel[];
  status: 'active' | 'paused';
  last_fired_at?: string | null;
  created_at: string;
  updated_at: string;
}

export interface AlertRulePayload {
  name: string;
  topic_id: number;
  condition?: Record<string, any>;
  window_sec?: number;
  threshold?: number;
  severity?: AlertSeverity;
  notify_channels?: NotifyChannel[];
  status?: 'active' | 'paused';
}

export interface AlertEvent {
  uuid: string;
  rule_id: number;
  fired_at?: string | null;
  resolved_at?: string | null;
  severity: AlertSeverity;
  context: Record<string, any>;
  notify_status: 'pending' | 'sent' | 'failed';
}

export const alertApi = {
  rules: {
    list: (workspaceUuid: string, query: PageQuery = {}) =>
      request<PageResult<AlertRule>>({
        method: 'GET',
        url: `/workspaces/${workspaceUuid}/alert-rules`,
        params: query,
      }),
    create: (workspaceUuid: string, payload: AlertRulePayload) =>
      request<AlertRule>({
        method: 'POST',
        url: `/workspaces/${workspaceUuid}/alert-rules`,
        data: payload,
      }),
    update: (workspaceUuid: string, uuid: string, payload: Partial<AlertRulePayload>) =>
      request<AlertRule>({
        method: 'PUT',
        url: `/workspaces/${workspaceUuid}/alert-rules/${uuid}`,
        data: payload,
      }),
    delete: (workspaceUuid: string, uuid: string) =>
      request<void>({ method: 'DELETE', url: `/workspaces/${workspaceUuid}/alert-rules/${uuid}` }),
  },
  // 事件按规则维度查询：GET /workspaces/{ws}/alert-rules/{rule}/events
  events: {
    list: (workspaceUuid: string, ruleUuid: string, query: PageQuery = {}) =>
      request<PageResult<AlertEvent>>({
        method: 'GET',
        url: `/workspaces/${workspaceUuid}/alert-rules/${ruleUuid}/events`,
        params: query,
      }),
    // 标记已处理：POST .../events/{event}/resolve
    resolve: (workspaceUuid: string, ruleUuid: string, eventUuid: string) =>
      request<AlertEvent>({
        method: 'POST',
        url: `/workspaces/${workspaceUuid}/alert-rules/${ruleUuid}/events/${eventUuid}/resolve`,
      }),
  },
};
