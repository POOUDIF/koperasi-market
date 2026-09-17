import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { defineConfig } from 'vite';

/**
 * Company profile statis multi-halaman (§2.3: HTML statis per halaman → SEO).
 * Header/footer dibagi lewat komentar <!--#include nama--> yang diganti isi
 * compro/partials/nama.html saat build — tanpa dependensi templating.
 */
const root = resolve(__dirname);

function htmlIncludes() {
  return {
    name: 'jdc-html-includes',
    transformIndexHtml: {
      // 'pre': sisipkan partial SEBELUM Vite memproses URL aset di HTML.
      order: 'pre',
      handler: (html) =>
        html.replace(/<!--#include ([a-z-]+)-->/g, (_, name) =>
          readFileSync(resolve(root, 'partials', `${name}.html`), 'utf8')),
    },
  };
}

export default defineConfig({
  base: '/',
  plugins: [htmlIncludes()],
  build: {
    outDir: '../public_html/compro',
    emptyOutDir: true,
    rollupOptions: {
      input: {
        index: resolve(root, 'index.html'),
        tentang: resolve(root, 'tentang/index.html'),
        kontak: resolve(root, 'kontak/index.html'),
        syarat: resolve(root, 'syarat-ketentuan/index.html'),
        privasi: resolve(root, 'kebijakan-privasi/index.html'),
        notfound: resolve(root, '404.html'),
      },
    },
  },
  server: {
    port: 5175,
  },
});
