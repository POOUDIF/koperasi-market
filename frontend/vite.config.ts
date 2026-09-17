import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// SPA Koperasi Digital disajikan di /koperasi/ (routing berbasis path satu domain,
// DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md §2.1). Saat dev, API koperasi dan
// JDC Account (/account) diproksikan ke backend Apache supaya alur SSO berbasis
// cookie tetap satu origin — setel APP_URL backend ke origin vite bila perlu.
const backend = process.env.VITE_BACKEND_URL ?? 'http://127.0.0.1:8300';

export default defineConfig({
  base: '/koperasi/',
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/koperasi/api': { target: backend },
      '/account': { target: backend },
    },
  },
  build: {
    outDir: '../public_html/koperasi',
    emptyOutDir: true,
  },
});
