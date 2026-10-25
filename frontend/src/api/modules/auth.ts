import { request } from '../http';

export interface LoginPayload {
  email: string;
  password: string;
}

export interface ConsoleUser {
  id: number;
  name: string;
  email: string;
  roles: string[];
  status: 'active' | 'suspended';
  last_login_at?: string | null;
}

export interface LoginResult {
  token: string;
  expires_in: number | null;
  user: ConsoleUser;
}

export const authApi = {
  login: (payload: LoginPayload) =>
    request<LoginResult>({ method: 'POST', url: '/auth/login', data: payload }),
  logout: () =>
    request<void>({ method: 'POST', url: '/auth/logout' }),
  me: () =>
    request<ConsoleUser>({ method: 'GET', url: '/auth/me' }),
};
