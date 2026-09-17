# Company Profile — Jawa Dwipa Cooperative

Situs statis multi-halaman (Vite + Tailwind, preset `@jdc/ui`) di **`/`**. Beranda berisi
pilihan layanan: Koperasi Digital (`/koperasi/dashboard`) dan Marketplace (`/market/`).

```bash
npm run dev:compro      # dari root repo — http://localhost:5175/
npm run build:compro    # → ../public_html/compro
```

- Header/footer/head dibagi lewat `<!--#include nama-->` → `partials/nama.html` (plugin kecil di `vite.config.js`).
- Halaman: `index.html`, `tentang/`, `kontak/`, `syarat-ketentuan/`, `kebijakan-privasi/`, `404.html`
  (dipakai `ErrorDocument` Apache).
- SEO: meta description, canonical, Open Graph, JSON-LD Organization, `public/sitemap.xml`, `public/robots.txt`.
  Bila domain berubah, perbarui URL absolut di file-file tersebut.
- **Sebelum publikasi**: isi semua penanda `[… — diisi pengurus]` (kelas `.todo`) dan tinjau halaman legal berstatus DRAF.
