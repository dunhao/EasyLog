import { request, type PageResult, type PageQuery } from '../http';

export type ShippingTargetType = 'oss' | 'file' | 'rabbitmq' | 'kafka' | 'redis' | 'activemq' | 'http';

export interface ShippingTask {
  id: number;
  uuid: string;
  workspace_uuid?: string;
  topic_id: number;
  topic_uuid?: string | null;
  name: string;
  target_type: ShippingTargetType;
  target_config: Record<string, any>;
  filter: Record<string, any> | null;
  start_from?: string | null;
  status: 'active' | 'paused' | 'completed';
  created_at: string;
  updated_at: string;
}

export interface ShippingTaskPayload {
  name: string;
  topic_id: number;
  target_type: ShippingTargetType;
  target_config?: Record<string, any>;
  filter?: Record<string, any> | null;
  start_from?: string | null;
  status?: 'active' | 'paused' | 'completed';
}

export const shippingApi = {
  list: (workspaceUuid: string, query: PageQuery = {}) =>
    request<PageResult<ShippingTask>>({
      method: 'GET',
      url: `/workspaces/${workspaceUuid}/shipping-tasks`,
      params: query,
    }),
  create: (workspaceUuid: string, payload: ShippingTaskPayload) =>
    request<ShippingTask>({
      method: 'POST',
      url: `/workspaces/${workspaceUuid}/shipping-tasks`,
      data: payload,
    }),
  update: (workspaceUuid: string, id: number, payload: Partial<ShippingTaskPayload>) =>
    request<ShippingTask>({
      method: 'PUT',
      url: `/workspaces/${workspaceUuid}/shipping-tasks/${id}`,
      data: payload,
    }),
  delete: (workspaceUuid: string, id: number) =>
    request<void>({ method: 'DELETE', url: `/workspaces/${workspaceUuid}/shipping-tasks/${id}` }),
};
