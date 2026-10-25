import { request, type PageResult, type PageQuery } from '../http';

// 后端 layout 为自由 JSON 数组；前端约定面板结构如下
export interface DashboardPanel {
  type: 'line' | 'bar' | 'pie' | 'stat' | 'table';
  title: string;
  query: string;
  topic_uuid: string;
  field?: string;
  agg_type?: 'terms' | 'date_histogram' | 'avg' | 'sum' | 'max' | 'min' | 'cardinality';
  bucket?: 'minute' | 'hour' | 'day';
  span?: number; // 栅格列宽 1-24
}

export interface Dashboard {
  id: number;
  uuid: string;
  name: string;
  layout: DashboardPanel[];
  created_by: number;
  created_at: string;
  updated_at: string;
}

export interface DashboardPayload {
  name: string;
  layout: DashboardPanel[];
}

export const dashboardApi = {
  list: (workspaceUuid: string, query: PageQuery = {}) =>
    request<PageResult<Dashboard>>({
      method: 'GET',
      url: `/workspaces/${workspaceUuid}/dashboards`,
      params: query,
    }),
  show: (workspaceUuid: string, id: number) =>
    request<Dashboard>({ method: 'GET', url: `/workspaces/${workspaceUuid}/dashboards/${id}` }),
  create: (workspaceUuid: string, payload: DashboardPayload) =>
    request<Dashboard>({
      method: 'POST',
      url: `/workspaces/${workspaceUuid}/dashboards`,
      data: payload,
    }),
  update: (workspaceUuid: string, id: number, payload: Partial<DashboardPayload>) =>
    request<Dashboard>({
      method: 'PUT',
      url: `/workspaces/${workspaceUuid}/dashboards/${id}`,
      data: payload,
    }),
  delete: (workspaceUuid: string, id: number) =>
    request<void>({ method: 'DELETE', url: `/workspaces/${workspaceUuid}/dashboards/${id}` }),
};
