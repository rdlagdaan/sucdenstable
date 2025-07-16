import axios from 'axios';
import Cookies from 'js-cookie';

const api = axios.create({
  baseURL: 'http://localhost:8686',
  withCredentials: true,
});

api.interceptors.request.use((config) => {
  // ✅ Make sure headers object exists
  config.headers = config.headers ?? {};

  // ✅ Set CSRF token from cookie
  const xsrfToken = Cookies.get('XSRF-TOKEN');
  if (xsrfToken) {
    config.headers['X-XSRF-TOKEN'] = xsrfToken;
  }

  // ✅ Optionally set Authorization if using token auth (not Sanctum session-based)
  const bearer = localStorage.getItem('token');
  if (bearer) {
    config.headers['Authorization'] = `Bearer ${bearer}`;
  }

  return config;
});

export default api;
