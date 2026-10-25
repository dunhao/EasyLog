import { request, type PageResult, type PageQuery } from '../http';

export interface User {
  id: number;
  name: string;
  email: string;
  roles: string[];
  status: 'active' | 'suspended';
  last_login_at?: string | null;
  created_at: string;
}

export interface UserPayload {
  name: string;
  email: string;
  password?: string;
  roles?: string[];
  status?: 'active' | 'suspended';
}

export const userApi = {
  list: (query: PageQuery = {}) =>
    request<PageResult<User>>({ method: 'GET', url: '/users', params: query }),
  show: (id: number) =>
    request<User>({ method: 'GET', url: `/users/${id}` }),
  create: (payload: UserPayload & { password: string }) =>
    request<User>({ method: 'POST', url: '/users', data: payload }),
  update: (id: number, payload: Partial<UserPayload>) =>
    request<User>({ method: 'PUT', url: `/users/${id}`, data: payload }),
  delete: (id: number) =>
    request<void>({ method: 'DELETE', url: `/users/${id}` }),
  resetPassword: (id: number, password: string) =>
    request<{ message: string }>({
      method: 'POST',
      url: `/users/${id}/reset-password`,
      data: { password },
    }),
};
