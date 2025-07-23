import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  base: '/', // ✅ ensure this is set to `/`
  server: {
    proxy: {
      '/api': 'http://localhost:8686',
      '/sanctum': 'http://localhost:8686',
    },
  },
});
