> **Status (2026-09-03): diimplementasikan.** Fase 0–7 di dokumen ini sudah dikerjakan
> di [`frontend/`](../frontend/) — tema warna diambil dari logo Jawa Dwipa Cooperative,
> 32/32 endpoint tercakup, guard auth/admin sudah benar sejak awal. Lihat
> [`frontend/README.md`](../frontend/README.md) untuk cara menjalankan. Dokumen di bawah
> ini dipertahankan sebagai catatan keputusan arsitektur, bukan lagi rencana yang menunggu.

# Rencana Integrasi Frontend Vue.js ke `koperasi-market`

**Keputusan:** frontend baru dibangun dengan **Vue.js**, digabung ke **satu repo** yang sama dengan
backend CI3 ini (bukan repo terpisah seperti `koperasi-frontend` yang sekarang, dan bukan porting
dari Next.js — dibangun fresh).

Dokumen ini menjawab: struktur repo seperti apa, bagaimana satu domain bisa melayani API CI3 +
SPA Vue sekaligus, stack apa yang dipakai, dan urutan pengerjaan per modul.

---

## 1. Struktur Repo (Monorepo)

```
koperasi-market/
├── application/          ← backend CI3 (tidak berubah)
├── system/
├── database/
├── tests/
├── signer-service/
├── index.php              ← front controller CI3, TETAP hanya menjawab /api/v1/*
├── frontend/               ← 🆕 source Vue (folder baru)
│   ├── src/
│   │   ├── main.ts
│   │   ├── App.vue
│   │   ├── router/
│   │   ├── stores/          (Pinia)
│   │   ├── views/
│   │   ├── components/
│   │   ├── composables/     (padanan hooks React: useAuth, useSavings, dst)
│   │   └── lib/
│   │       └── api.ts        (axios instance)
│   ├── public/
│   ├── index.html
│   ├── vite.config.ts
│   ├── package.json
│   └── .env.development / .env.production
├── public_html/            ← 🆕 HASIL BUILD Vue (`frontend/dist` disalin/dibuild ke sini), di-.gitignore
└── .htaccess                ← 🆕 aturan di root: pisahkan trafik API vs SPA
```

**Kenapa folder `frontend/` terpisah dari `application/`?** Supaya siklus build Vue (`npm install`,
`npm run build`, `node_modules/`) tidak pernah bersentuhan dengan struktur CI3, dan supaya
`frontend/` bisa dihapus/di-`.gitignore`-kan sebagian (`node_modules/`, `dist/`) tanpa risiko
mengganggu backend.

---

## 2. Bagaimana "Satu Domain" Melayani API + SPA Sekaligus

Ini bagian yang sering disepelekan. CI3 saat ini **tidak punya `.htaccess` di root** — semua
request murni ditangani `index.php` sebagai API. Begitu SPA Vue ikut disajikan dari domain yang
sama, root harus tahu mana yang dikirim ke CI3 (`index.php`) dan mana yang dikirim ke file statis
hasil build Vue.

### Alur yang direkomendasikan (production)

```
Request masuk ke domain (mis. koperasi-market.test)
        │
        ▼
   .htaccess di root
        │
   URI diawali /api/v1/... ? ──ya──► index.php (CI3, seperti sekarang)
        │
        tidak
        ▼
   File ada di public_html/ (hasil build Vue)? ──ya──► sajikan file statis (JS/CSS/gambar)
        │
        tidak (mis. /dashboard/savings — route client-side Vue Router)
        ▼
   fallback ke public_html/index.html  (Vue Router yang ambil alih routing)
```

Contoh `.htaccess` di root:

```apache
RewriteEngine On

# 1) Semua /api/v1/* tetap ke CI3 seperti sekarang
RewriteCond %{REQUEST_URI} ^/api/v1/
RewriteRule ^ index.php [L]

# 2) File statis hasil build Vue (JS/CSS/gambar/font) — sajikan langsung
RewriteCond %{REQUEST_URI} ^/(assets|favicon\.ico|robots\.txt)
RewriteRule ^(.*)$ public_html/$1 [L]

# 3) Sisanya (semua route SPA: /login, /dashboard, /dashboard/gold, dst) → index.html Vue
RewriteRule ^ public_html/index.html [L]
```

> Alternatif yang lebih rapi kalau Laragon-nya pakai Nginx: dua `location` block (`/api/v1` proxy
> ke PHP-FPM/CI3, `/` sajikan `public_html` dengan `try_files $uri /index.html`). Prinsipnya sama.

### Alur dev (development)

Jangan pakai `.htaccess` di atas saat development — terlalu lambat untuk hot-reload. Pakai **dua
proses berjalan bersamaan**:

1. `php -S localhost:8080` (atau Apache Laragon) → CI3, hanya `/api/v1/*`
2. `npm run dev` di `frontend/` → Vite dev server di `localhost:5173` dengan **proxy**:

```ts
// frontend/vite.config.ts
export default defineConfig({
  server: {
    proxy: {
      '/api/v1': { target: 'http://localhost:8080', changeOrigin: true },
    },
  },
});
```

Dengan proxy ini, kode frontend selalu memanggil path relatif `/api/v1/...` — **kode yang sama
persis jalan di dev maupun production**, tidak perlu env var `VITE_API_URL` yang beda-beda per
environment kalau tidak mau (base URL relatif kosong sudah cukup karena satu origin).

---

## 3. Stack Teknis yang Direkomendasikan

| Kebutuhan | Pilihan | Alasan |
|---|---|---|
| Framework | **Vue 3** (Composition API + `<script setup>`) | Standar saat ini, TypeScript-friendly |
| Build tool | **Vite** | Resmi direkomendasikan Vue, dev server cepat, proxy API built-in |
| Routing | **Vue Router 4** | Wajib untuk SPA multi-halaman + route guard (auth & role) |
| State/cache server | **@tanstack/vue-query** (bukan Pinia untuk data server) | Padanan langsung TanStack Query yang dipakai frontend Next.js lama — caching, invalidate, retry, polling (dibutuhkan untuk status transaksi emas `pending→processing→success`) sudah beres tanpa nulis ulang |
| State lokal/UI | **Pinia** | Untuk state yang bukan hasil fetch server: user session ringkas, toggle UI, dsb |
| HTTP client | **Axios** | Interceptor request (sisipkan JWT) & response (401/403/429 global) — pola sama seperti `axios.ts` lama, tinggal port logikanya |
| Styling | **Tailwind CSS** | Frontend lama sudah pakai ini — desainnya bisa dipindah langsung |
| Toast/notifikasi | **vue-sonner** atau **vue-toastification** | Padanan `react-hot-toast` |
| Form/validasi | **VeeValidate + Zod**, atau validasi manual sederhana (form-nya tidak kompleks) | Opsional — proyek ini form-nya sedikit, boleh manual dulu |

**TypeScript**: pakai (`vite --template vue-ts`). Backend responsnya konsisten dan sudah
terdokumentasi lengkap di `FRONTEND_API_REFERENCE.md` (repo `koperasi-frontend` lama) — definisikan
`types/api.ts` di awal, sama seperti pola lama, supaya semua composable/komponen type-safe.

---

## 4. Prinsip Desain Composable (padanan `hooks/` React lama)

Satu file composable per modul, mengikuti pola yang sudah terbukti di frontend Next.js lama:

```
frontend/src/composables/
├── useAuth.ts        → login, register, verifyEmail, resendOtp, logout, profile
├── useKyc.ts          → getKyc, updateKyc                              🆕 belum pernah ada
├── useSavings.ts     → accounts, products, deposit, depositRequests,
│                         withdraw, withdrawRequests                     🆕 withdraw belum pernah ada
├── useFinancing.ts   → apply, list, installments, pay
├── useGold.ts        → price, buy, sell, holding                       🆕 holding belum pernah ada
└── useAdmin.ts        → reviewFinancing, reviewDeposit, reviewWithdraw,  🆕 sebagian besar baru
                          listDepositRequests, listWithdrawRequests,
                          listUsers, txFinancing/txGold/txSaving, setGoldPrice
```

**Route guard** (`frontend/src/router/index.ts`) — ini yang **tidak ada** di frontend lama dan
harus benar sejak awal di sini:

```ts
router.beforeEach((to) => {
  const token = getToken();
  if (to.meta.requiresAuth && !token) return '/login';
  if (to.meta.requiresRole && !to.meta.requiresRole.includes(getRoleFromToken())) return '/dashboard';
});
```

> Catatan: guard di frontend hanya untuk **UX** (sembunyikan menu/halaman) — backend tetap sumber
> kebenaran otorisasi (401/403). Jangan pernah percaya role dari token tanpa verifikasi server saat
> aksi sensitif dilakukan; itu sudah beres di backend, tinggal jangan dilonggarkan di frontend.

---

## 5. Fase Pengerjaan (mengikuti urutan modul backend)

### Fase 0 — Fondasi
- `npm create vite@latest frontend -- --template vue-ts`
- Install dependency (§3), setup Tailwind, setup `frontend/src/lib/api.ts` (axios + interceptor
  401/403/429 seperti pola lama), setup `vue-query` provider di `main.ts`.
- Setup Vue Router dasar + route guard kosong.
- `.htaccess` root + folder `public_html/` (§2), skrip `npm run build` di `frontend/package.json`
  diarahkan `outDir: '../public_html'`.
- **Definisikan `types/api.ts`** dari `FRONTEND_API_REFERENCE.md` (sudah ada, tinggal disalin &
  disesuaikan dengan 5 endpoint baru: withdraw, withdraw-requests, gold/holding, admin/gold/price,
  admin/users, admin/transactions/*, resend-otp).

### Fase 1 — Auth lengkap (perbaiki bug frontend lama sejak awal)
- `/register` — kirim `POST /register`, **response TIDAK berisi token** (hanya `{message, user_id}`)
  — jangan simpan cookie di sini, langsung arahkan ke halaman verifikasi.
- `/verify-otp` — 🆕 halaman baru: input email (prefill dari state register) + 6 digit OTP →
  `POST /verify-email` → baru simpan token → redirect dashboard. Tombol "Kirim ulang OTP" →
  `POST /resend-otp`.
- `/login` — pola sama seperti lama (pesan 401 digabung, jangan bedakan email/password).
- Logout, `GET /profile`, interceptor 401/403 global.

### Fase 2 — Simpanan (lengkap, termasuk yang belum ada di versi lama)
- List & buka rekening, setor dana (sudah pernah ada, port logikanya).
- 🆕 Riwayat pengajuan setoran milik sendiri (`GET /savings/deposit-requests`).
- 🆕 Form penarikan dana (`POST /savings/withdraw`) + riwayat (`GET /savings/withdraw-requests`).
- `GET /savings/products` untuk dropdown pilih produk saat buka rekening (dulu di-hardcode/asumsi).

### Fase 3 — KYC 🆕 (modul yang sama sekali belum ada)
- Halaman lihat/isi profil KYC (`GET`/`PUT /profile/kyc`): NIK, no HP, alamat, pekerjaan,
  penghasilan bulanan, kontak darurat.
- 200 dengan objek kosong saat KYC belum diisi (bukan 404) — tampilkan sebagai form kosong, bukan
  error.

### Fase 4 — Pembiayaan
- Ajukan, list, jadwal cicilan, bayar cicilan — port pola lama (sudah terbukti benar).

### Fase 5 — Emas Digital
- Harga (polling/cache 15 menit), beli, jual — port pola lama.
- 🆕 Tampilkan kepemilikan emas (`GET /gold/holding`) di halaman gold, bukan hanya form beli/jual.
- Polling status transaksi `pending`/`processing` tiap 30 detik (sudah direncanakan di plan lama,
  pastikan benar-benar diimplementasi kali ini, pakai `refetchInterval` bawaan vue-query).

### Fase 6 — Panel Admin (paling besar gap-nya dari versi lama)
- Guard role (`pengurus|admin|super_admin`) di level route, bukan hanya sembunyikan tombol.
- Review pembiayaan (sudah pernah ada, port).
- 🆕 Review setoran (`GET`/`PUT /admin/savings/deposit-requests/...`) — **titik kritis bisnis**,
  prioritaskan setelah auth.
- 🆕 Review penarikan (`GET`/`PUT /admin/savings/withdraw-requests/...`).
- 🆕 Manajemen harga emas (`POST /admin/gold/price`).
- 🆕 Daftar pengguna (`GET /admin/users`).
- 🆕 Riwayat transaksi lintas modul (`GET /admin/transactions/{financing,gold,saving}`).

### Fase 7 — Polish & Deploy
- Loading skeleton, empty state, format Rupiah, error message konsisten (bisa port helper dari
  frontend lama, itu bagian yang sudah bagus).
- Build production (`npm run build` → `public_html/`), verifikasi `.htaccess` di server dev
  Laragon (Apache) benar-benar memisahkan `/api/v1/*` vs SPA.
- Uji manual seluruh 32 endpoint dari UI (checklist terpisah, jangan cuma percaya "✅ Selesai" di
  markdown seperti kesalahan yang terjadi di `IMPLEMENTATION_PLAN.md` repo lama — verifikasi ke kode
  jalan sungguhan).

---

## 6. Yang HARUS Berbeda dari Percobaan Frontend Sebelumnya

Diringkas dari [`ANALISIS_FRONTEND.md`](ANALISIS_FRONTEND.md) — jangan mengulang kesalahan yang
sama:

1. **Alur register→OTP→login harus benar sejak Fase 1**, bukan diasumsikan register langsung
   memberi token.
2. **Route guard admin wajib ada sejak Fase 6 dibuat**, bukan "direncanakan tapi tidak dikerjakan".
3. **Checklist progres di markdown harus diverifikasi ke kode**, jangan ditandai selesai berdasarkan
   asumsi.
4. **Semua endpoint backend terbaru** (withdraw, withdraw-requests, resend-otp, gold/holding,
   admin/gold/price, admin/users, admin/transactions/*) masuk rencana sejak awal — jangan sampai
   frontend baru ini juga langsung tertinggal seperti yang lama.

---

## 7. Ringkasan Cakupan Target

| Modul | Endpoint | Status target |
|---|---|---|
| Auth | register, login, verify-email, resend-otp, logout, profile | Fase 1 |
| KYC | get/update kyc | Fase 3 |
| Simpanan | accounts (list/open), products, deposit, deposit-requests, withdraw, withdraw-requests | Fase 2 |
| Pembiayaan | apply, list, installments, pay | Fase 4 |
| Emas | price, buy, sell, holding | Fase 5 |
| Admin | review financing/deposit/withdraw, list deposit/withdraw-requests, users, transactions ×3, set gold price | Fase 6 |

**Total 32 endpoint** (angka terbaru dari `application/config/routes.php`, lebih banyak dari 27
yang tercatat di `ANALISIS_KESESUAIAN_BLUEPRINT.md` — sudah bertambah `savings/products`,
`savings/withdraw-requests`, `admin/savings/withdraw-requests` ×2 sejak dokumen itu ditulis).
Target: **32/32 tercakup**, dibanding pencapaian frontend lama yang hanya 12/27.

---

*Lihat catatan status di bagian atas dokumen — sudah diimplementasikan per 2026-09-03.*
