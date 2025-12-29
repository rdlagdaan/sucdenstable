import axios, { AxiosHeaders } from 'axios';
import type { InternalAxiosRequestConfig, AxiosError } from 'axios';
import Cookies from 'js-cookie';
import { clearAuth } from './auth';

const napi = axios.create({
  baseURL: '/api',
  withCredentials: true,
  headers: { 'X-Requested-With': 'XMLHttpRequest' },
});



// Let axios also do its normal xsrf behavior
(napi.defaults as any).xsrfCookieName = 'XSRF-TOKEN';
(napi.defaults as any).xsrfHeaderName = 'X-XSRF-TOKEN';

napi.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const url = String(config?.url ?? '');
  const skipAuth =
    url.includes('/api/login') ||
    url === '/login' ||
    url.includes('/sanctum/csrf-cookie');

  // normalize headers object
  if (!config.headers || !(config.headers instanceof AxiosHeaders)) {
    config.headers = new AxiosHeaders(config.headers || {});
  }

  // ---- CRITICAL: explicitly set CSRF headers from cookie (decoded) ----
  // Laravel's validator expects the decoded token in the header.
  const xsrfCookie = Cookies.get('XSRF-TOKEN');
  if (xsrfCookie) {
    let decoded = xsrfCookie;
    try { decoded = decodeURIComponent(xsrfCookie); } catch {}
    (config.headers as AxiosHeaders).set('X-XSRF-TOKEN', decoded);
    // Some stacks read X-CSRF-TOKEN; setting both is harmless.
    (config.headers as AxiosHeaders).set('X-CSRF-TOKEN', decoded);
  }

  // Authorization (bearer) for everything except login/csrf endpoints
  if (!skipAuth) {
    const token = localStorage.getItem('token');
    if (token) (config.headers as AxiosHeaders).set('Authorization', `Bearer ${token}`);
  } else {
    (config.headers as AxiosHeaders).delete('Authorization');
  }

  // Always attach active company id (many endpoints require it)
  try {
    const fromLS = localStorage.getItem('company_id');
    const fromAuth = JSON.parse(localStorage.getItem('auth') || '{}')?.company_id;
    const companyId = fromLS ?? fromAuth ?? undefined;
    if (companyId) (config.headers as AxiosHeaders).set('X-Company-ID', String(companyId));
  } catch { /* ignore */ }

  return config;
});

// 419 handler: prime CSRF then retry once (no redirect)
napi.interceptors.response.use(
  (resp) => resp,
  async (error: AxiosError) => {
    const status = error?.response?.status;
    const cfg: any = error?.config;

    // If the session rotated, grab fresh cookies and retry once
    if (status === 419 && cfg && !cfg.__retriedAfterCsrf) {
      try {
        await axios.get('/api/csrf-cookie', { withCredentials: true });

        // clean any stale token headers before retry
        if (cfg.headers instanceof AxiosHeaders) {
          cfg.headers.delete('X-XSRF-TOKEN');
          cfg.headers.delete('x-xsrf-token');
          cfg.headers.delete('X-CSRF-TOKEN');
          cfg.headers.delete('x-csrf-token');
        } else if (cfg.headers) {
          delete cfg.headers['X-XSRF-TOKEN'];
          delete cfg.headers['x-xsrf-token'];
          delete cfg.headers['X-CSRF-TOKEN'];
          delete cfg.headers['x-csrf-token'];
        }

        // mark to avoid loops and retry via the same client
        cfg.__retriedAfterCsrf = true;
        return napi.request(cfg);
      } catch {
        // fall through
      }
    }

    // Leave 419 un-redirected for debugging
    if (status === 401) {
      clearAuth();
    }

    return Promise.reject(error);
  }
);

export default napi;

// Optional helper if you want to explicitly prime elsewhere
export async function ensureCsrf(): Promise<void> {
  await axios.get('/api/csrf-cookie', { withCredentials: true });
}

