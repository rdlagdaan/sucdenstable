import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  base: '/',
  server: {
    proxy: {
      '/api': 'http://localhost:8686',
      '/sanctum': 'http://localhost:8686',
    },
  },
  resolve: {
    alias: {
      '@': '/src', // Direct path to src directory for the alias
    },
  },
  build: {
    chunkSizeWarningLimit: 1500, // Increase limit if needed
    rollupOptions: {
      output: {
        manualChunks: {
          react: ['react', 'react-dom'],
          handsontable: ['handsontable', '@handsontable/react'],
        },
      },
    },
  },
});
