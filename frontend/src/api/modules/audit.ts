import { request, type PageResult, type PageQuery } from '../http';

export interface AuditLog {
  id: number;
  user_id: number;
  action: string;
  target_type: string;
  target_id?: string | null;
  context: Record<string, any> | null;
  ip?: string | null;
  created_at: string;
}

export interface AuditLogQuery extends PageQuery {
  action?: string;
  target_type?: string;
  from?: string;
  to?: string;
}

export const auditApi = {
  list: (query: AuditLogQuery = {}) =>
    request<PageResult<AuditLog>>({ method: 'GET', url: '/audit-logs', params: query }),
};
