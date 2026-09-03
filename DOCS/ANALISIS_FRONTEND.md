# Analisis Status Frontend — Koperasi Digital

**Tanggal analisis:** 2026-09-03.
**Pertanyaan:** Apakah sistem ini masih backend-only, atau sudah ada frontend?

---

## Kesimpulan Singkat

**Sudah ada frontend**, tapi berada di **repository terpisah** dari `koperasi-market` ini:

| | |
|---|---|
| Backend (repo ini) | `c:\laragon\www\koperasi-market` — CodeIgniter 3 / PHP, REST API `/api/v1/*` |
| Frontend | `c:\Daffa\koperasi-frontend` — Next.js 15 (App Router) + TypeScript + TanStack Query + Axios + Tailwind, repo Git sendiri |

Frontend **tidak disebut sama sekali** di `DOCS/SYSTEM_FLOW_CI3_BLUEPRINT.md` maupun `DOCS/ANALISIS_KESESUAIAN_BLUEPRINT.md` — kedua dokumen itu murni membahas backend. Repo frontend punya dokumentasi sendiri: `IMPLEMENTATION_PLAN.md` dan `FRONTEND_API_REFERENCE.md`, keduanya bertanggal **11 Agustus 2026** — yaitu **sebelum** empat endpoint backend terbaru dibuat (2026-09-01: withdraw, resend-otp, `/gold/holding`, `POST /admin/gold/price`). Rencana frontend jadi sudah kadaluarsa terhadap kontrak backend saat ini.

Commit terakhir frontend: `27599fb Fixed Export Suspense` — checklist internalnya sendiri mengklaim **semua 6 fase "✅ Selesai"**, tapi klaim itu tidak akurat setelah diverifikasi ke kode (lihat gap di bawah) dan tidak pernah diperbarui untuk endpoint baru backend.

---

## Cakupan Endpoint: Frontend vs 27 Endpoint Backend

Backend saat ini punya **27 endpoint** aktif (lihat `ANALISIS_KESESUAIAN_BLUEPRINT.md` §10). Diverifikasi langsung ke kode frontend (`src/hooks/*.ts`, `src/app/**/page.tsx`) — bukan ke checklist markdown-nya:

| # | Endpoint Backend | Dipakai Frontend? |
|---|---|---|
| 1 | `GET /health` | — (tidak perlu UI) |
| 2 | `POST /register` | ⚠️ Dipanggil, **tapi salah** — lihat Gap #1 |
| 3 | `POST /login` | ✅ `login/page.tsx` |
| 4 | `POST /verify-email` | ❌ **Tidak ada sama sekali** |
| — | `POST /resend-otp` (baru) | ❌ Tidak ada |
| 5 | `GET /gold/price` | ✅ `useGoldPrice()` |
| 6 | `GET /profile` | ✅ `useProfile()` |
| 7 | `POST /logout` | ✅ `useLogout()` |
| 8 | `GET /profile/kyc` | ❌ Tidak ada |
| 9 | `PUT /profile/kyc` | ❌ Tidak ada |
| 10 | `POST /savings/accounts` | ✅ `useOpenSavingsAccount()` |
| 11 | `GET /savings/accounts` | ✅ `useSavingsAccounts()` |
| 12 | `POST /savings/deposit` | ✅ `useDeposit()` |
| 13 | `GET /savings/deposit-requests` | ❌ Tidak ada (riwayat pengajuan setoran sendiri) |
| — | `POST /savings/withdraw` (baru) | ❌ Tidak ada |
| 14 | `POST /financing/apply` | ✅ `useApplyFinancing()` |
| 15 | `GET /financing` | ✅ `useFinancings()` |
| 16 | `GET /financing/:id/installments` | ✅ `useInstallments()` |
| 17 | `POST /financing/installments/:id/pay` | ✅ `usePayInstallment()` |
| 18 | `POST /gold/buy` | ✅ `useBuyGold()` |
| 19 | `POST /gold/sell` | ✅ `useSellGold()` |
| — | `GET /gold/holding` (baru) | ❌ Tidak ada |
| 20 | `PUT /admin/financing/:id/review` | ✅ `useReviewFinancing()` |
| 21 | `PUT /admin/savings/deposit-requests/:id/review` | ❌ Tidak ada |
| 22 | `GET /admin/savings/deposit-requests` | ❌ Tidak ada |
| 23 | `GET /admin/users` | ❌ Tidak ada |
| 24 | `GET /admin/transactions/{financing,gold,saving}` | ❌ Tidak ada |
| — | `POST /admin/gold/price` (baru) | ❌ Tidak ada |

**Ringkasan:** dari 27 endpoint backend, **12 terintegrasi dengan benar**, **1 terintegrasi tapi salah** (register), **14 belum ada UI-nya sama sekali** — termasuk seluruh modul KYC dan sebagian besar panel admin.

---

## Gap Kritis (Bug, Bukan Sekadar "Belum Ada")

### 1. Alur registrasi frontend tidak cocok dengan kontrak backend
`src/app/register/page.tsx` mengasumsikan `POST /register` mengembalikan `{ token, user }` lalu langsung menyimpan `data.token` ke cookie dan redirect ke `/dashboard`.

Kenyataan di backend (`application/controllers/api/v1/Auth.php:38-41`): `/register` mengembalikan **`{ message, user_id }`** — **tanpa token** — karena alur yang benar adalah *register → OTP dikirim → verifikasi OTP (`/verify-email`) → baru dapat token (auto-login)*.

**Akibat nyata:** setiap pengguna baru yang mendaftar lewat frontend ini akan mendapat cookie berisi `"undefined"`, lalu di-bounce ke halaman login oleh interceptor 401 saat halaman dashboard memanggil `GET /profile`. **Registrasi via UI saat ini tidak berfungsi sama sekali** terhadap backend yang sekarang berjalan.

### 2. Tidak ada halaman verifikasi OTP
Konsekuensi langsung dari #1 — bahkan jika alurnya diperbaiki, tidak ada UI untuk memasukkan kode OTP 6-digit. `grep` untuk `verify-email`/`otp`/`OTP` di seluruh `src/` frontend: **nihil**. Tanpa halaman ini, tidak ada jalan bagi pengguna untuk menyelesaikan pendaftaran dari UI.

### 3. Tidak ada guard/proteksi role untuk route admin
`IMPLEMENTATION_PLAN.md` Fase 5 Step 5.1 secara eksplisit meminta *"middleware atau guard di layout dashboard: cek `user.role`"* agar `/dashboard/admin/*` tidak bisa diakses `anggota`. Diverifikasi: **tidak ada `middleware.ts`**, dan `DashboardShell.tsx` tidak melakukan pengecekan role apa pun. Halaman `/dashboard/admin/financing` bisa dibuka oleh siapa saja yang login (backend akan menolak dengan 403 saat request API dikirim, tapi **halamannya sendiri tetap ter-render** — bukan proteksi UX yang baik, dan berpotensi bocor informasi struktur UI admin ke anggota biasa).

---

## Modul yang Sepenuhnya Belum Ada di Frontend

1. **KYC** (`GET/PUT /profile/kyc`) — tidak ada halaman isi/lihat data KYC (NIK, alamat, pekerjaan, dll). Padahal ini modul wajib di blueprint (Modul 2).
2. **Riwayat & verifikasi setoran**
   - Anggota tidak bisa melihat riwayat pengajuan setoran sendiri (`GET /savings/deposit-requests`).
   - Admin tidak punya panel untuk approve/reject setoran (`GET`/`PUT /admin/savings/deposit-requests/...`) — padahal ini salah satu dari 2 titik kritis race-condition di backend (FLOW-11).
3. **Penarikan dana (withdraw)** — endpoint `POST /savings/withdraw` (ditambahkan backend 2026-09-01, CACAT-12) sama sekali belum ada di rencana maupun kode frontend.
4. **Kepemilikan emas** — `GET /gold/holding` tidak dipakai; halaman emas kemungkinan hanya menampilkan harga & form beli/jual tanpa menampilkan saldo gram yang dimiliki user.
5. **Panel admin: manajemen pengguna** (`GET /admin/users`) — tidak ada.
6. **Panel admin: riwayat transaksi lintas modul** (`GET /admin/transactions/{financing,gold,saving}`) — tidak ada.
7. **Panel admin: atur harga emas** (`POST /admin/gold/price`) — tidak ada UI, padahal tanpa ini harga emas hanya bisa di-set lewat API langsung (curl/Postman).
8. **Resend OTP** — endpoint backend `POST /resend-otp` (fitur baru CACAT-06 follow-up) tidak dipakai; kalau OTP kedaluwarsa/hilang, pengguna dari UI tidak punya jalan keluar.

---

## Rekomendasi Prioritas

1. **Perbaiki alur registrasi (Gap #1 + #2) — blocker tertinggi.** Tanpa ini tidak ada anggota baru yang bisa masuk lewat UI sama sekali:
   - Ubah `register/page.tsx` agar tidak mengharapkan token dari `/register`.
   - Buat halaman `/verify-email` (form OTP 6 digit + tombol "kirim ulang" ke `/resend-otp`) yang menyimpan token dari respons `verify-email` lalu redirect ke dashboard.
2. **Tambahkan guard role admin di layout** (`middleware.ts` atau pengecekan di `DashboardShell`), sesuai yang sudah direncanakan sendiri di `IMPLEMENTATION_PLAN.md` Fase 5.1 tapi belum dieksekusi.
3. **Modul KYC** — perlu ada agar anggota bisa mengajukan pembiayaan dengan data lengkap (blueprint mengasumsikan KYC terisi sebelum pengajuan pembiayaan disetujui secara operasional).
4. **Panel admin untuk verifikasi setoran** — ini titik kritis bisnis (approve/reject uang masuk); saat ini kemungkinan admin masih memverifikasi manual lewat API/Postman.
5. **Withdraw, gold holding, resend-otp, admin users/transactions/gold-price** — lengkapi menyusul, sesuai urutan dampak bisnis di atas.
6. **Perbarui `IMPLEMENTATION_PLAN.md` & `FRONTEND_API_REFERENCE.md`** di repo frontend — keduanya per 11 Agustus 2026, sudah tidak mencerminkan 4 endpoint baru backend maupun gap yang ditemukan di sini.

---

*Catatan metodologi: analisis ini dibaca langsung dari kode (`src/hooks/*.ts`, `src/app/**/page.tsx`, `application/controllers/api/v1/Auth.php`), bukan hanya dari checklist di `IMPLEMENTATION_PLAN.md` — checklist tersebut mengklaim semua fase selesai, namun tidak akurat terhadap kode maupun kontrak backend aktual.*
