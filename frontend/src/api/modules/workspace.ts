import { request, type PageResult, type PageQuery } from '../http';

export interface Workspace {
  uuid: string;
  name: string;
  description?: string | null;
  retention_days: number;
  shard_count: number;
  daily_quota_gb: number; // 0 = 不限
  status: 'active' | 'frozen';
  created_at: string;
  updated_at: string;
}

export interface WorkspacePayload {
  name: string;
  description?: string;
  retention_days?: number;
  shard_count?: number;
  daily_quota_gb?: number;
}

export const workspaceApi = {
  list: (query: PageQuery = {}) =>
    request<PageResult<Workspace>>({ method: 'GET', url: '/workspaces', params: query }),
  show: (uuid: string) =>
    request<Workspace>({ method: 'GET', url: `/workspaces/${uuid}` }),
  create: (payload: WorkspacePayload) =>
    request<Workspace>({ method: 'POST', url: '/workspaces', data: payload }),
  update: (uuid: string, payload: Partial<WorkspacePayload>) =>
    request<Workspace>({ method: 'PUT', url: `/workspaces/${uuid}`, data: payload }),
  delete: (uuid: string) =>
    request<void>({ method: 'DELETE', url: `/workspaces/${uuid}` }),
};
