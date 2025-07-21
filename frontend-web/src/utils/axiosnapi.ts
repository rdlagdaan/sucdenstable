import axios, { AxiosHeaders } from 'axios';
import Cookies from 'js-cookie';
import type {  InternalAxiosRequestConfig } from 'axios';

const napi = axios.create({
  baseURL: 'http://localhost:8686',
  withCredentials: true,
});

napi.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  // ✅ Ensure headers is of correct type
  if (!config.headers || !(config.headers instanceof AxiosHeaders)) {
    config.headers = new AxiosHeaders();
  }

  const xsrfToken = Cookies.get('XSRF-TOKEN');
  if (xsrfToken) {
    config.headers.set('X-XSRF-TOKEN', xsrfToken);
  }

  const token = localStorage.getItem('token');
  if (token) {
    config.headers.set('Authorization', `Bearer ${token}`);
  }

  return config;
});

export default napi;
