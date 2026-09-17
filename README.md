# Platform Jawa Dwipa Cooperative (JDC)

Satu repo, satu domain (`https://jdc.shfopis.com`), routing berbasis path:

| Path | Layanan | Kode |
|---|---|---|
| `/` | Company profile + pilihan layanan | [`compro/`](compro/) |
| `/account` | **JDC Account** — SSO (OpenID Connect) untuk semua layanan | [`apps/account/`](apps/account/) |
| `/koperasi` | **Koperasi Digital** — dokumen ini | [`application/`](application/), [`frontend/`](frontend/) |
| `/market` | **Marketplace** — bayar dengan saldo koperasi (Koperasi Pay) | [`apps/market/`](apps/market/), [`market-frontend/`](market-frontend/) |

Arsitektur SSO, integrasi, deploy, dan operasional: **[`DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md`](DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md)**.

---

# Koperasi Syariah Digital — REST API (CodeIgniter 3 + PHP 7.4)

Implementasi dari [`SYSTEM_FLOW_CI3_BLUEPRINT.md`](SYSTEM_FLOW_CI3_BLUEPRINT.md),
port dari backend Go ke CodeIgniter 3.1.13.

> **Sejak SSO:** login/registrasi/OTP pindah ke JDC Account; API koperasi kini di
> `/koperasi/api/v1` dengan cookie sesi (pola BFF), bukan token Bearer. Akun JDC baru
> harus **aktivasi keanggotaan** (setelah KYC) sebelum memakai simpanan/pembiayaan/emas.

**Status: Fase 0–5 selesai + seluruh endpoint admin & paginasi.**
27 endpoint aktif, 63 uji fungsional dan 11 uji konkurensi lulus.

---

## Yang berbeda dari blueprint

| Keputusan | Blueprint | Di sini | Alasan |
|---|---|---|---|
| Database | PostgreSQL | **MySQL 8** (varian §9.4) | PHP 7.4 di Laragon ini tidak punya ekstensi `pgsql`/`pdo_pgsql`, dan PostgreSQL server tidak terpasang |
| Presisi `deposit_requests.amount` | `(15,2)` | **`(19,4)`** | Blueprint §3.2 sendiri menyarankan menyeragamkan, jangan setengah-setengah |
| Cacat §20 | didokumentasikan | **diperbaiki** | lihat tabel di bawah |

Konsekuensi MySQL yang sudah ditangani: `RETURNING` → `insert_id()`,
`ON CONFLICT … DO UPDATE` → `ON DUPLICATE KEY UPDATE … VALUES()`,
partial index → index penuh, kode error `23505` → `1062`,
`TIMESTAMPTZ` → `TIMESTAMP` + `SET SESSION time_zone='+07:00'`.

## Perbaikan cacat §20 yang diterapkan

| # | Cacat | Perbaikan | Terverifikasi |
|---|---|---|---|
| 01 | Jual emas tanpa cek kepemilikan (**KRITIS**) | `Gold_model::net_holding()` dihitung di dalam transaction penjualan, setelah rekening terkunci | jual tanpa emas → 422 |
| 02 | Mint gagal broadcast tidak me-refund (**KRITIS**) | `refund_failed_transaction()` tersedia di `Gold_model`; pemanggilan dari worker menyusul di Fase 6 | ⚠ belum, worker belum ada |
| 03 | Grup admin tidak cek status akun | `Admin_Controller extends Auth_Controller` — cek status ikut terwarisi | admin non-aktif ditolak |
| 04 | Approve setoran dua transaction terpisah | `Deposit_request_model::review()` — satu transaction, baris permohonan `FOR UPDATE` | dua approve serentak → 200 + 422, saldo naik sekali |
| 05 | Review pembiayaan rawan double-approve | `UPDATE … WHERE id=? AND status='pending'` + cek `affected_rows` | approve kedua → 409 |
| 06 | Registrasi tidak atomik | Kegagalan rekening wajib kini **di-log** (di Go baris log-nya dikomentari) | — |
| 07 | Batas 100 gram → 500 di `/gold/buy` | `Api_exception::goldLimitExceeded()` memberi 400 di kedua endpoint | beli 101 gram → 400 |
| 08 | Tidak ada endpoint harga emas | `POST /admin/gold/price` + invalidasi cache Redis | harga baru langsung terbaca |
| 09 | Endpoint admin tanpa paginasi | `API_Controller::paging()`, default 50, maks 200 | response memuat `page`/`per_page`/`total` |
| 10 | Blocklist JWT memakai token utuh | `jwt_revoked:` + `sha256(token)` | logout → token lama 401 |

Tambahan di luar §20: `hash_equals()` untuk perbandingan OTP (anti timing attack),
`password_verify` dummy saat email tidak ditemukan (waktu respons login seragam),
cek `status='active'` saat **mengajukan** setoran (bukan hanya saat approve),
dan 409 untuk NIK duplikat (di Go jatuh ke 500).

---

## Menjalankan

Langkah lengkap (tiga database, `.env` tiap layanan, kunci OIDC, migrasi user, build frontend, cron):
[`DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md` §6–§7](DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md#6-menjalankan-lokal).
Ringkasnya:

```bash
composer install && npm install
# .env, apps/account/.env, apps/market/.env  (salin dari .env.example masing-masing)
# skema: database/migrations/001..007 (koperasi), database/account, database/market
php account.php cli/keys generate
php account.php cli/clients sync
npm run build                       # → public_html/{compro,account,koperasi,market}
```

Sajikan folder repo lewat Apache (`mod_rewrite`, `mod_headers`) — `.htaccess` root memetakan path ke tiap
layanan. `php -S 127.0.0.1:8300 server.php` cukup untuk dev cepat, tetapi back-channel logout & webhook
butuh server multi-thread.

Cek: `curl http://<origin>/koperasi/api/v1/health` → `{"status":"ok", …}` (juga `/account/health`, `/market/api/v1/health`).

Tanpa `SMTP_HOST` di `apps/account/.env`, OTP & tautan reset sandi ditulis ke `apps/account/logs/`
sebagai `[EMAIL SIMULATION] …` — mode pengembangan.

### Menaikkan role

```sql
-- pengurus/admin koperasi (per layanan)
UPDATE users SET role = 'super_admin' WHERE email = 'admin@mail.com';   -- DB koperasi_digital
```
```bash
php account.php cli/users promote admin@mail.com    # admin platform JDC (blokir akun global)
php market.php  cli/admin promote admin@mail.com    # admin marketplace
```

### Uji

```bash
bash tests/sso_e2e_test.sh          # 118 assertion: OIDC, SSO lintas layanan, Koperasi Pay, marketplace
bash tests/smoke_test.sh            # 72 assertion, alur §22 lewat sesi SSO
bash tests/concurrency_test.sh      # 11 assertion, row locking (butuh Apache multi-thread)
npm run type-check
```

Uji membutuhkan Apache + MySQL + Redis lokal dan `RATE_LIMIT_SCALE=50` di ketiga `.env`
(banyak akun dibuat dari satu IP; diabaikan di produksi).

---

## Frontend

| Folder | Isi | Build → |
|---|---|---|
| [`frontend/`](frontend/) | SPA Koperasi Digital (Vue 3 + Vite + TS) | `public_html/koperasi/` |
| [`market-frontend/`](market-frontend/) | SPA Marketplace (Vue 3 + Vite + TS) | `public_html/market/` |
| [`compro/`](compro/) | Company profile statis (Vite multi-page) | `public_html/compro/` |
| [`apps/account/ui/`](apps/account/ui/) | CSS Tailwind halaman JDC Account | `public_html/account/assets/` |
| [`packages/ui/`](packages/ui/) | `@jdc/ui`: preset warna logo JDC, komponen CSS, `AppLogo`, `AppSwitcher` | — |

Semuanya satu npm workspace: `npm run build` di root membangun keempatnya; `npm run dev:koperasi`,
`dev:market`, `dev:compro` untuk hot reload.

---

## Peta endpoint

Semua path di bawah ini relatif terhadap **`/koperasi/api/v1`** (URL lama `/api/v1/*` dialihkan 308).
Endpoint SSO, keanggotaan, PIN, Koperasi Pay, dan API internal: lihat
[`DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md` §5](DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md#5-peta-endpoint).
"JWT" di tabel berarti sesi login (cookie SSO; Bearer hanya saat `AUTH_MODE` legacy/both).

| Method | Path | Auth |
|---|---|---|
| GET | `/api/v1/health` | — |
| POST | `/api/v1/register` | 410 saat `AUTH_MODE=sso` |
| POST | `/api/v1/login` | 410 saat `AUTH_MODE=sso` |
| POST | `/api/v1/verify-email` | 410 saat `AUTH_MODE=sso` |
| POST | `/api/v1/resend-otp` | 410 saat `AUTH_MODE=sso` |
| GET | `/api/v1/gold/price` | — |
| POST | `/api/v1/logout` | JWT |
| GET | `/api/v1/profile` | JWT |
| GET · PUT | `/api/v1/profile/kyc` | JWT |
| POST · GET | `/api/v1/savings/accounts` | JWT |
| GET | `/api/v1/savings/products` | JWT |
| POST | `/api/v1/savings/deposit` | JWT |
| GET | `/api/v1/savings/deposit-requests` | JWT |
| POST | `/api/v1/savings/withdraw` | JWT |
| GET | `/api/v1/savings/withdraw-requests` | JWT |
| POST | `/api/v1/financing/apply` | JWT |
| GET | `/api/v1/financing` | JWT |
| GET | `/api/v1/financing/:id/installments` | JWT |
| POST | `/api/v1/financing/installments/:id/pay` | JWT |
| POST | `/api/v1/gold/buy` · `/gold/sell` | JWT |
| GET | `/api/v1/gold/holding` | JWT |
| PUT | `/api/v1/admin/financing/:id/review` | pengurus+ |
| PUT | `/api/v1/admin/savings/deposit-requests/:id/review` | pengurus+ |
| GET | `/api/v1/admin/savings/deposit-requests` | pengurus+ |
| PUT | `/api/v1/admin/savings/withdraw-requests/:id/review` | pengurus+ |
| GET | `/api/v1/admin/savings/withdraw-requests` | pengurus+ |
| GET | `/api/v1/admin/users` | pengurus+ |
| GET | `/api/v1/admin/transactions/{financing,gold,saving}` | pengurus+ |
| POST | `/api/v1/admin/gold/price` | pengurus+ |

Tujuh di antaranya (`/resend-otp`, `/savings/products`, `/savings/withdraw`,
`/savings/withdraw-requests`, `/gold/holding`, `/admin/gold/price`,
`/admin/savings/withdraw-requests[/:id/review]`) tidak ada di sistem Go;
sisanya sepadan 1:1. `/savings/withdraw` memperbaiki CACAT-12 (§20 blueprint)
dan `/resend-otp` merealisasikan usulan perbaikan CACAT-06.

## Operasional: worker, audit, dan CLI (Fase 6-7)

```bash
# Worker emas (Fase 6) — jalankan sebagai proses long-running di bawah
# Supervisor/NSSM, plus cron 5 menit untuk recover:
php index.php cli/gold_worker start      # loop BLPOP tanpa henti
php index.php cli/gold_worker recover    # requeue pending + cek receipt (cron 5 menit)
php index.php cli/gold_worker once 42    # proses satu ID (debugging)

# Signer service terpisah (Opsi 2, §16.4) — memegang OWNER_PRIVATE_KEY,
# TIDAK pernah dibaca oleh proses PHP:
cd signer-service && cp .env.example .env   # isi RPC/private key/alamat kontrak
npm install && npm start

# Verifikasi integritas buku besar (§22) — jadwalkan harian via cron/Task
# Scheduler; exit code 1 bila ada anomali:
php index.php cli/ledger_audit run      # termasuk cek rekening penampung marketplace

# Koperasi Pay: retry webhook + kedaluwarsakan tagihan (cron tiap menit)
php index.php cli/webhooks run
```

---

## Arsitektur

```
routes.php → controllers/api/v1/*   HTTP: bind, validasi, serialisasi. Tanpa logika bisnis.
           → libraries/*_service    Aturan bisnis: margin, jadwal angsuran, batas transaksi.
           → models/*_model         SQL mentah, transaction, FOR UPDATE, terjemahan error driver.
```

Rantai middleware Gin ditiru oleh kelas di `application/core/MY_Controller.php`:
`API_Controller` → `Auth_Controller` → `Admin_Controller`, plus `Internal_Controller` untuk API antar layanan.
Kode generik (Redis, validator, Money, model dasar, klien OIDC, sesi BFF) ada di [`shared/`](shared/) dan dipakai
bersama JDC Account & Marketplace.
Controller yang mencampur endpoint publik dan terproteksi (`Gold`) memanggil
`require_member()` per method.

Empat aturan yang dipegang di seluruh kode dan **tidak boleh dilanggar**:

1. Uang dan gram selalu **string** + `bcmath` (`Money`), tidak pernah float —
   `Money::out()` baru mengubahnya ke number saat serialisasi JSON.
2. Setiap operasi saldo berada dalam satu transaction dengan `SELECT … FOR UPDATE`,
   dan saldo di-update dengan ekspresi relatif (`balance ± ?`), bukan nilai hitungan PHP.
3. Validasi dilakukan **setelah** baris terkunci, tidak sebelumnya.
4. Akses ke resource milik anggota lain dijawab **404**, bukan 403.

`reference_id` di `savings_transactions` adalah kunci korelasi antar modul dan
formatnya tidak boleh diubah: `cicilan_{id}`, `gold_buy_{id}`, `gold_sell_{id}`,
`gold_refund_{id}`, serta Koperasi Pay `market_pay_{intent}`, `market_settle_{intent}`,
`market_refund_{intent}`. Refund emas menemukan rekening asal lewat `gold_buy_{id}`.

---

## Belum dikerjakan

- **Fase 6 — worker emas & blockchain.** `Gold_model::refund_failed_transaction()`
  sudah ada dan atomik, tapi belum ada yang memanggilnya: `controllers/cli/Gold_worker.php`
  (`start`/`recover`/`once`) dan `libraries/Chain_client.php` belum dibuat.
  Konsekuensinya, transaksi `/gold/buy` berhenti di status `pending` selamanya —
  saldo sudah didebet tapi tidak ada mint dan tidak ada refund otomatis.
  Jangan aktifkan pembelian emas ke pengguna nyata sebelum fase ini selesai.
- **Fase 7 sisanya** — logging terstruktur, `display_errors=Off` untuk produksi.
  Paginasi dan endpoint harga emas sudah masuk lebih awal.
- Endpoint `resend-otp` (usulan perbaikan CACAT-06) belum ada; anggota yang
  OTP-nya kedaluwarsa saat ini harus dibantu manual.

## Catatan yang perlu diketahui

- **`firebase/php-jwt` 6.x** kena advisory CVE-2025-45769 (severity *low*).
  Perbaikannya ada di 7.0 yang **butuh PHP 8.0+**. Selama masih di PHP 7.4,
  6.x adalah versi tertinggi yang bisa dipasang. Risikonya kecil untuk HS256
  dengan secret kuat, tapi ini alasan konkret untuk naik ke PHP 8 nanti.
- **`net_holding()` men-scan riwayat transaksi emas** setiap penjualan. Pada
  volume besar ini akan lambat, dan `FOR UPDATE` atas hasil agregat tidak
  mengunci baris yang *belum ada*. Untuk produksi, ganti dengan tabel
  `gold_holdings(user_id, gram_balance)` yang dikunci per baris.
- **`due_date` memakai `+N month`** sehingga 31 Januari + 1 bulan = 3 Maret,
  sama seperti `AddDate` di Go. Kalau koperasi ingin "akhir bulan tetap akhir
  bulan", ubah di `Financing_service::generate_installments()` — dan sadari
  hasilnya akan berbeda dari sistem lama.
- **`pm.max_children` PHP-FPM ≤ `max_connections` MySQL** dikurangi cadangan
  untuk worker CLI. PHP tidak punya connection pool seperti Go (§19.5).
