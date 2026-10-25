import { request, type PageResult, type PageQuery } from '../http';

export type SourceType = 'http' | 'sdk' | 'kafka' | 'rabbitmq' | 'redis' | 'activemq' | 'syslog' | 'filebeat';

export interface IngestionEndpoint {
  uuid: string;
  workspace_uuid?: string;
  topic_id: number;
  topic_uuid?: string | null;
  name: string;
  source_type: SourceType;
  config: Record<string, any>;
  app_id: string;
  rate_limit_per_sec: number;
  daily_quota_gb: number;
  status: 'active' | 'disabled';
  created_at: string;
  updated_at: string;
}

export interface EndpointPayload {
  name: string;
  topic_id: number;
  source_type: SourceType;
  config?: Record<string, any>;
  rate_limit_per_sec?: number;
  daily_quota_gb?: number;
  status?: 'active' | 'disabled';
}

export interface TestConnectionResult {
  success: boolean;
  message?: string;
}

export const endpointApi = {
  list: (workspaceUuid: string, query: PageQuery = {}) =>
    request<PageResult<IngestionEndpoint>>({
      method: 'GET',
      url: `/workspaces/${workspaceUuid}/endpoints`,
      params: query,
    }),
  show: (workspaceUuid: string, uuid: string) =>
    request<IngestionEndpoint>({
      method: 'GET',
      url: `/workspaces/${workspaceUuid}/endpoints/${uuid}`,
    }),
  // app_secret 仅创建时明文返回一次
  create: (workspaceUuid: string, payload: EndpointPayload) =>
    request<IngestionEndpoint & { app_secret: string }>({
      method: 'POST',
      url: `/workspaces/${workspaceUuid}/endpoints`,
      data: payload,
    }),
  update: (workspaceUuid: string, uuid: string, payload: Partial<EndpointPayload>) =>
    request<IngestionEndpoint>({
      method: 'PUT',
      url: `/workspaces/${workspaceUuid}/endpoints/${uuid}`,
      data: payload,
    }),
  delete: (workspaceUuid: string, uuid: string) =>
    request<void>({ method: 'DELETE', url: `/workspaces/${workspaceUuid}/endpoints/${uuid}` }),
  // 测试连接按端点维度：POST /endpoints/{uuid}/test-connection
  testConnection: (workspaceUuid: string, uuid: string) =>
    request<TestConnectionResult>({
      method: 'POST',
      url: `/workspaces/${workspaceUuid}/endpoints/${uuid}/test-connection`,
    }),
  rotateSecret: (workspaceUuid: string, uuid: string) =>
    request<{ app_id: string; app_secret: string }>({
      method: 'POST',
      url: `/workspaces/${workspaceUuid}/endpoints/${uuid}/rotate-secret`,
    }),
};
