import axios, { AxiosError, type AxiosInstance, type AxiosRequestConfig } from 'axios';

const baseURL = import.meta.env.VITE_API_BASE || '/api/v1';

export const http: AxiosInstance = axios.create({
  baseURL,
  timeout: 30000,
  headers: {
    'Content-Type': 'application/json',
  },
});

// 请求拦截：注入 JWT
http.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('easylog_token');
    if (token && !config.headers.Authorization) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    const ws = localStorage.getItem('easylog_workspace_uuid');
    if (ws) {
      config.headers['X-EasyLog-Workspace'] = ws;
    }
    return config;
  },
  (error) => Promise.reject(error)
);

// 响应拦截：统一错误处理
http.interceptors.response.use(
  (response) => response,
  (error: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
    const status = error.response?.status;
    const message =
      error.response?.data?.message ||
      (error.response?.data?.errors
        ? Object.values(error.response.data.errors).flat().join('; ')
        : error.message);

    if (status === 401) {
      // 清理 token，跳登录
      localStorage.removeItem('easylog_token');
      if (location.pathname !== '/login') {
        location.href = '/login?redirect=' + encodeURIComponent(location.pathname);
      }
    } else if (status === 429) {
      // 限流：友好提示
      console.warn('[EasyLog] Rate limited, please retry later');
    }

    return Promise.reject({
      status,
      message: message || 'Network Error',
      raw: error,
    });
  }
);

export type ApiError = {
  status?: number;
  message: string;
  raw?: unknown;
};

export type RequestOptions = AxiosRequestConfig;

// 通用分页（与后端 Controller 响应一致：data + meta）
export interface PageMeta {
  total: number;
  page: number;
  page_size: number;
}

export interface PageResult<T> {
  data: T[];
  meta: PageMeta;
}

export interface PageQuery {
  page?: number;
  page_size?: number;
  keyword?: string;
}

export async function request<T = any>(config: RequestOptions): Promise<T> {
  const res = await http.request<T>(config);
  return res.data;
}
