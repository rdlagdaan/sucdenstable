// frontend-web/src/utils/axiosnapi.ts
import axios, { AxiosHeaders } from 'axios';
import type { InternalAxiosRequestConfig } from 'axios';
import Cookies from 'js-cookie';
import { clearAuth } from './auth';

const napi = axios.create({
  baseURL: '/',                    // same-origin via :3001 proxy
  withCredentials: true,           // <-- allow browser to send/receive cookies
  headers: { 'X-Requested-With': 'XMLHttpRequest' },
});

// Let Axios auto-map cookie -> header
(napi.defaults as any).xsrfCookieName = 'XSRF-TOKEN';
(napi.defaults as any).xsrfHeaderName = 'X-XSRF-TOKEN';

// Belt-and-suspenders: also set header manually from cookie
napi.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const url = String(config?.url ?? '');
  const skipAuth =
    url.includes('/api/login') ||
    url.includes('/sanctum/csrf-cookie');

  // Attach CSRF header if cookie exists
  const xsrf = Cookies.get('XSRF-TOKEN');
  if (xsrf) {
    if (!config.headers || !(config.headers instanceof AxiosHeaders)) {
      config.headers = new AxiosHeaders(config.headers || {});
    }
    (config.headers as AxiosHeaders).set('X-XSRF-TOKEN', xsrf);
  }

  // Bearer (except for login and csrf-cookie)
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

// Call this before any unsafe request (POST/PUT/PATCH/DELETE)
export async function ensureCsrf(): Promise<void> {
  // Important: same-origin call through :3001 so cookies are set for 3001
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}
