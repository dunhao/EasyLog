import { request, type PageResult, type PageQuery } from '../http';

export interface Topic {
  id: number;
  uuid: string;
  workspace_uuid: string;
  name: string;
  description?: string | null;
  retention_days: number;
  auto_field_index: boolean;
  enable_full_text: boolean;
  json_extract: boolean;
  status: 'active' | 'disabled';
  created_at: string;
  updated_at: string;
}

export interface TopicPayload {
  name: string;
  description?: string;
  retention_days?: number;
  auto_field_index?: boolean;
  enable_full_text?: boolean;
  json_extract?: boolean;
}

// 注意：show/update/delete 走 shallow 路由 /topics/{uuid}（不带 workspace 前缀）
export const topicApi = {
  list: (workspaceUuid: string, query: PageQuery = {}) =>
    request<PageResult<Topic>>({
      method: 'GET',
      url: `/workspaces/${workspaceUuid}/topics`,
      params: query,
    }),
  show: (uuid: string) =>
    request<Topic>({ method: 'GET', url: `/topics/${uuid}` }),
  create: (workspaceUuid: string, payload: TopicPayload) =>
    request<Topic>({
      method: 'POST',
      url: `/workspaces/${workspaceUuid}/topics`,
      data: payload,
    }),
  update: (uuid: string, payload: Partial<TopicPayload> & { status?: 'active' | 'disabled' }) =>
    request<Topic>({ method: 'PUT', url: `/topics/${uuid}`, data: payload }),
  delete: (uuid: string) =>
    request<void>({ method: 'DELETE', url: `/topics/${uuid}` }),
};

export type FieldType = 'text' | 'keyword' | 'long' | 'double' | 'date' | 'ip' | 'geo';

export interface TopicField {
  id: number;
  field_name: string;
  field_type: FieldType;
  indexed: boolean;
  analyzer: string;
  created_at: string;
  updated_at: string;
}

export interface TopicFieldPayload {
  field_name: string;
  field_type: FieldType;
  indexed?: boolean;
  analyzer?: string;
}

// 字段索引 CRUD：/topics/{topicUuid}/fields
export const topicFieldApi = {
  list: (topicUuid: string) =>
    request<{ data: TopicField[] }>({
      method: 'GET',
      url: `/topics/${topicUuid}/fields`,
    }),
  create: (topicUuid: string, payload: TopicFieldPayload) =>
    request<TopicField>({
      method: 'POST',
      url: `/topics/${topicUuid}/fields`,
      data: payload,
    }),
  update: (topicUuid: string, fieldId: number, payload: Partial<TopicFieldPayload>) =>
    request<TopicField>({
      method: 'PUT',
      url: `/topics/${topicUuid}/fields/${fieldId}`,
      data: payload,
    }),
  delete: (topicUuid: string, fieldId: number) =>
    request<void>({
      method: 'DELETE',
      url: `/topics/${topicUuid}/fields/${fieldId}`,
    }),
};
