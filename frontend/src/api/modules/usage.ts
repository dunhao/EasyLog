import { request } from '../http';

export interface UsagePoint {
  date: string;
  bytes: number;
  count: number;
}

export interface UsageByEndpoint {
  endpoint_uuid?: string | null;
  endpoint_name?: string | null;
  bytes: number;
  count: number;
}

export const usageApi = {
  // 按天聚合：GET /workspaces/{ws}/usage?from&to
  usage: (workspaceUuid: string, from: string, to: string) =>
    request<{ data: UsagePoint[] }>({
      method: 'GET',
      url: `/workspaces/${workspaceUuid}/usage`,
      params: { from, to },
    }),
  // 按接入点聚合：GET /workspaces/{ws}/usage/endpoints?from&to
  byEndpoint: (workspaceUuid: string, from: string, to: string) =>
    request<{ data: UsageByEndpoint[] }>({
      method: 'GET',
      url: `/workspaces/${workspaceUuid}/usage/endpoints`,
      params: { from, to },
    }),
};
