import axios from 'axios';
import Cookies from 'js-cookie';

const api = axios.create({
  baseURL: 'http://localhost:8686',
  withCredentials: true,
  headers: {
    'X-XSRF-TOKEN': Cookies.get('XSRF-TOKEN') || '',
  },
});

api.interceptors.request.use((config) => {
  config.headers = {
    ...config.headers,
    'X-XSRF-TOKEN': Cookies.get('XSRF-TOKEN') ?? '',
  };

  const token = localStorage.getItem('token');
  if (token) {
    config.headers['Authorization'] = `Bearer ${token}`;
  }

  return config;
});

export default api;
