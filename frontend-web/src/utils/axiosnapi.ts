// frontend-web/src/utils/axiosnapi.ts
import axios, { AxiosHeaders } from 'axios';
import type { InternalAxiosRequestConfig } from 'axios';
import { clearAuth } from './auth';

const napi = axios.create({
  // IMPORTANT: relative so it works behind Nginx at :3001 and in dev
  baseURL: '/',
  headers: { 'X-Requested-With': 'XMLHttpRequest' },
});

napi.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const url = String(config?.url ?? '');
  const skipAuth = url.includes('/api/login') || url.includes('/sanctum/csrf-cookie');

  if (!skipAuth) {
    const token = localStorage.getItem('token');
    if (token) {
      if (!config.headers || !(config.headers instanceof AxiosHeaders)) {
        config.headers = new AxiosHeaders(config.headers || {});
      }
      (config.headers as AxiosHeaders).set('Authorization', `Bearer ${token}`);
    }
  } else {
    if (config.headers instanceof AxiosHeaders) {
      config.headers.delete('Authorization');
    } else if (config.headers) {
      delete (config.headers as any)['Authorization'];
      delete (config.headers as any)['authorization'];
    }
  }
  return config;
});

napi.interceptors.response.use(
  (resp) => resp,
  (error) => {
    const status = error?.response?.status;
    const url = String(error?.config?.url ?? '');
    if ((status === 401 || status === 419) && !url.includes('/api/login')) {
      clearAuth();
      if (typeof window !== 'undefined') window.location.assign('/login');
    }
    return Promise.reject(error);
  }
);

export default napi;

// Backward-compat no-op (safe to remove later)
export async function ensureCsrf(): Promise<void> {
  return;
}
