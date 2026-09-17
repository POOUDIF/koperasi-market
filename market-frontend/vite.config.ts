import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// SPA Marketplace di /market/ (routing berbasis path satu domain,
// DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md §2.1).
const backend = process.env.VITE_BACKEND_URL ?? 'http://127.0.0.1:8300';

export default defineConfig({
  base: '/market/',
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5174,
    proxy: {
      '/market/api': { target: backend },
      '/account': { target: backend },
      '/koperasi': { target: backend },
    },
  },
  build: {
    outDir: '../public_html/market',
    emptyOutDir: true,
  },
});
