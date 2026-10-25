import { request, type PageResult, type PageQuery } from '../http';

// 消费组：第三方拉取日志的凭证单元（auth_token 仅创建/轮换时明文返回一次）
export interface ConsumptionGroup {
  id: number;
  uuid: string;
  workspace_uuid?: string;
  topic_id: number;
  topic_uuid?: string | null;
  consumer_group: string;
  created_at: string;
  updated_at: string;
}

export interface ConsumptionGroupPayload {
  topic_id: number;
  consumer_group: string;
}

export const consumptionApi = {
  list: (workspaceUuid: string, query: PageQuery = {}) =>
    request<PageResult<ConsumptionGroup>>({
      method: 'GET',
      url: `/workspaces/${workspaceUuid}/consumption-groups`,
      params: query,
    }),
  // auth_token 仅创建时明文返回一次
  create: (workspaceUuid: string, payload: ConsumptionGroupPayload) =>
    request<ConsumptionGroup & { auth_token: string }>({
      method: 'POST',
      url: `/workspaces/${workspaceUuid}/consumption-groups`,
      data: payload,
    }),
  update: (workspaceUuid: string, id: number, payload: Partial<ConsumptionGroupPayload>) =>
    request<ConsumptionGroup>({
      method: 'PUT',
      url: `/workspaces/${workspaceUuid}/consumption-groups/${id}`,
      data: payload,
    }),
  delete: (workspaceUuid: string, id: number) =>
    request<void>({ method: 'DELETE', url: `/workspaces/${workspaceUuid}/consumption-groups/${id}` }),
  rotateToken: (workspaceUuid: string, id: number) =>
    request<{ consumer_group: string; auth_token: string }>({
      method: 'POST',
      url: `/workspaces/${workspaceUuid}/consumption-groups/${id}/rotate-token`,
    }),
};
