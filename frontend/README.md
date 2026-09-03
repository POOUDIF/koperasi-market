# Frontend — Jawa Dwipa Cooperative

Vue 3 (Composition API + `<script setup>`, TypeScript) + Vite. Dibangun sebagai satu
repo dengan backend CI3 di `koperasi-market` — lihat
[`../DOCS/RENCANA_FRONTEND_VUE.md`](../DOCS/RENCANA_FRONTEND_VUE.md) untuk rencana
arsitektur lengkapnya (struktur repo, strategi penyajian satu domain, daftar fase).

## Stack

| | |
|---|---|
| Framework | Vue 3 + Vite |
| Routing | Vue Router 4, dengan guard `requiresAuth`/`requiresAdmin` yang memverifikasi lewat `GET /profile` (bukan cuma percaya state lokal) |
| State server | `@tanstack/vue-query` — caching, invalidate, polling status transaksi emas |
| State lokal | Pinia (`stores/auth.ts`) |
| HTTP | Axios, satu instance (`src/lib/api.ts`) dengan interceptor 401/403/429 global |
| Styling | Tailwind CSS, tema warna kustom di `tailwind.config.js` (`primary`=hijau, `secondary`=coklat, `gold`=emas, diambil dari logo) |
| Toast | vue-sonner |

## Menjalankan

```bash
npm install
npm run dev
```

Vite dev server jalan di `http://localhost:5173` dan mem-proxy `/api/v1/*` ke
`http://localhost:8080` (lihat `vite.config.ts` — override dengan env
`VITE_BACKEND_URL` bila backend jalan di port lain). Jalankan backend CI3 di
port itu (`php -S localhost:8080 server.php` dari root repo).

## Build production

```bash
npm run build
```

Output masuk ke `../public_html` (di luar folder ini, `.gitignore`-kan), yang
disajikan lewat `.htaccess` di root repo — satu domain untuk API CI3 dan SPA ini.
`npm run build` menjalankan type-check penuh (`vue-tsc -b`) sebelum build; pakai
`npm run build:skiptypecheck` hanya untuk iterasi cepat lokal, jangan untuk build
yang dideploy.

## Struktur

```
src/
├── router/          route + guard auth/admin
├── stores/           Pinia — sesi user (auth.ts)
├── composables/      satu file per modul backend (useAuth, useSavings, useFinancing, useGold, useKyc, useAdmin)
├── views/
│   ├── auth/          login, register, verify-otp
│   └── dashboard/     halaman anggota + dashboard/admin/ untuk panel admin
├── components/        DashboardShell (layout+sidebar), FormModal, ConfirmModal, StatusBadge, dll — dipakai lintas halaman
├── types/api.ts       tipe response, selaras 1:1 dengan application/config/routes.php backend
└── lib/               axios instance + util format (Rupiah, gram, tanggal, badge status)
```

## Yang wajib diketahui sebelum mengubah alur auth

- `POST /register` **tidak** mengembalikan token — backend mewajibkan verifikasi
  OTP dulu. Alurnya: register -> `/verify-otp?email=...` -> `POST /verify-email`
  baru dapat token (lihat `useVerifyEmail` di `composables/useAuth.ts`). Jangan
  ubah `RegisterView.vue` untuk menyimpan token langsung dari respons register —
  itu bug yang pernah terjadi di percobaan frontend sebelumnya (lihat
  `../DOCS/ANALISIS_FRONTEND.md`).
- Guard admin di router (`src/router/index.ts`) memanggil `GET /profile` secara
  langsung saat `authStore.user` masih kosong (mis. hard refresh ke URL admin),
  supaya proteksi tetap benar walau state Pinia belum terisi. Backend tetap
  sumber kebenaran otorisasi (401/403 di setiap request) — guard di sini murni UX.

## Cakupan endpoint

32/32 endpoint backend (`application/config/routes.php`) punya composable + UI,
termasuk modul yang sebelumnya belum pernah dibuat: KYC, withdraw + riwayatnya,
gold holding, resend-otp, dan seluruh panel admin (review setoran/penarikan,
manajemen anggota, riwayat transaksi, atur harga emas).
