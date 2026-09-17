# Frontend Koperasi Digital — Jawa Dwipa Cooperative

SPA Vue 3 (Composition API + `<script setup>`, TypeScript) + Vite, disajikan di
**`/koperasi/`**. Bagian dari platform JDC — lihat
[`../DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md`](../DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md).

## Stack

| | |
|---|---|
| Framework | Vue 3 + Vite, `base: '/koperasi/'` |
| Routing | Vue Router 4 — guard `requiresAuth` / `requiresMember` / `requiresAdmin`, diverifikasi lewat `GET /profile` |
| State server | `@tanstack/vue-query` |
| State lokal | Pinia (`stores/auth.ts`, cache profil saja) |
| HTTP | Axios (`src/lib/api.ts`): cookie sesi `withCredentials`, header `X-Requested-With` (CSRF), interceptor 401/403/429 |
| Styling | Tailwind + preset & komponen bersama `@jdc/ui` (`packages/ui`) |

## Menjalankan

Dari **root repo** (npm workspace):

```bash
npm install
npm run dev:koperasi      # http://localhost:5173/koperasi/ — proksi /koperasi/api & /account ke VITE_BACKEND_URL (default http://127.0.0.1:8300)
npm run build:koperasi    # vue-tsc + vite build → ../public_html/koperasi
```

Redirect SSO memakai `APP_URL` backend; untuk alur login penuh paling mudah build lalu buka lewat Apache.

## Struktur

```
src/
├── router/          route + guard (auth, keanggotaan, admin)
├── stores/          Pinia — cache user dari GET /profile
├── composables/     satu file per modul backend (useSavings, useFinancing, useGold, useKyc, useAdmin,
│                    useMembership, usePayments, useAuth = profil & logout)
├── views/
│   ├── auth/         AuthErrorView (kegagalan SSO) + AuthLayout
│   ├── dashboard/    halaman anggota, MembershipView, SecurityView (PIN), admin/
│   └── pay/          PaymentConfirmView — konfirmasi Koperasi Pay dari Marketplace
├── components/       DashboardShell (sidebar + AppSwitcher), FormModal, ConfirmModal, dll
├── types/api.ts      tipe response, selaras dengan application/config/routes.php
└── lib/              api.ts (axios), sso.ts (URL login/redirect), utils.ts (format)
```

## Yang wajib diketahui sebelum mengubah alur auth

- **Tidak ada token di frontend.** Login dimulai dengan navigasi penuh ke
  `/koperasi/api/v1/sso/login?return_to=…` (`lib/sso.ts`); backend yang bicara dengan
  JDC Account dan memasang cookie `HttpOnly`. Jangan menyimpan apa pun terkait
  autentikasi di `localStorage`/cookie JavaScript.
- Semua request mengubah data WAJIB membawa `X-Requested-With: XMLHttpRequest`
  (sudah default di instance axios) — backend menolaknya dengan 403 bila tidak.
- `401` → redirect ke login SSO; `401 REAUTH_REQUIRED` → login ulang (`prompt=login`)
  untuk aksi sensitif (ubah PIN); `403 MEMBERSHIP_REQUIRED` → halaman aktivasi.
- Logout = `POST /sso/logout` lalu navigasi ke `redirect_url` (end_session JDC Account)
  — mengeluarkan pengguna dari **semua** layanan JDC.
- Guard router tetap hanya UX; backend sumber kebenaran otorisasi di setiap request.
