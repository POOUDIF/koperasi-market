import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// Proxy /api/v1 ke backend CI3 saat dev, supaya kode frontend selalu
// memanggil path relatif yang sama persis di dev maupun production
// (satu domain di production lewat .htaccess — lihat DOCS/RENCANA_FRONTEND_VUE.md).
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api/v1': {
        target: process.env.VITE_BACKEND_URL ?? 'http://localhost:8080',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: '../public_html',
    emptyOutDir: true,
  },
});
