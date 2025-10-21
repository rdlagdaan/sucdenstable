// frontend-web/src/setupApi.ts
import axios from 'axios';

// Force same-origin for any requests (works behind Nginx proxy at :3001)
axios.defaults.baseURL = '/';

// Defensive: if any code still passes an absolute http://localhost:8686 URL,
// rewrite it to a relative path so it goes through Nginx.
axios.interceptors.request.use((config) => {
  if (typeof config.url === 'string') {
    const s = config.url;
    // Strip absolute localhost/backend URLs -> make them relative
    if (/^https?:\/\/localhost:8686\//i.test(s) || /^https?:\/\/192\.168\.3\.\d+:8686\//i.test(s)) {
      const u = new URL(s);
      config.url = u.pathname + u.search + u.hash; // e.g. /api/companies
    }
  }
  return config;
});
