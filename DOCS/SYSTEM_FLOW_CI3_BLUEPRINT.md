# Blueprint Sistem Koperasi Digital — Analisis Flow & Panduan Port ke CodeIgniter 3 (PHP 7.4)

> **Sumber kebenaran**: hasil pembacaan langsung seluruh source Go pada repo ini
> (`main.go`, `internal/{config,database,middleware,handler,service,repository,model,worker,blockchain,util}`,
> `db/migrations/001..010`, `contracts/CoopGold.sol`).
> Semua angka, nama kolom, urutan langkah, dan kode status HTTP di bawah diambil dari kode yang benar-benar berjalan.
>
> **Tujuan**: menjadi satu-satunya dokumen yang perlu dibuka saat menulis ulang sistem ini
> di CodeIgniter 3 + PHP 7.4 — dari `composer install` sampai worker emas jalan.

---

## Daftar Isi

**BAGIAN A — ANALISIS SISTEM EKSISTING**
1. [Peta Sistem & Inventaris Fitur](#1-peta-sistem--inventaris-fitur)
2. [Arsitektur Berlapis & Siklus Hidup Request](#2-arsitektur-berlapis--siklus-hidup-request)
3. [Model Data Lengkap](#3-model-data-lengkap)
4. [State Machine Semua Entitas](#4-state-machine-semua-entitas)

**BAGIAN B — FONDASI CODEIGNITER 3**

5. [Pemetaan Arsitektur Go → CI3](#5-pemetaan-arsitektur-go--ci3)
6. [Langkah 0: Persiapan Lingkungan](#6-langkah-0-persiapan-lingkungan)
7. [Langkah 1: Struktur Direktori Final](#7-langkah-1-struktur-direktori-final)
8. [Langkah 2: Konfigurasi Inti](#8-langkah-2-konfigurasi-inti)
9. [Langkah 3: Skema Database](#9-langkah-3-skema-database)
10. [Langkah 4: Komponen Fondasi Lintas-Modul](#10-langkah-4-komponen-fondasi-lintas-modul)

**BAGIAN C — FLOW PER MODUL (STEP BY STEP)**

11. [Modul 1: Autentikasi & Akun](#11-modul-1-autentikasi--akun)
12. [Modul 2: Profil KYC](#12-modul-2-profil-kyc)
13. [Modul 3: Simpanan Syariah](#13-modul-3-simpanan-syariah)
14. [Modul 4: Pembiayaan Murabahah](#14-modul-4-pembiayaan-murabahah)
15. [Modul 5: Emas Digital](#15-modul-5-emas-digital)
16. [Modul 6: Worker Emas & Blockchain](#16-modul-6-worker-emas--blockchain)
17. [Modul 7: Dashboard Admin & Health](#17-modul-7-dashboard-admin--health)

**BAGIAN D — PENUTUP**

18. [Peta Routing Lengkap](#18-peta-routing-lengkap)
19. [Jebakan Migrasi Go → PHP](#19-jebakan-migrasi-go--php)
20. [Cacat Sistem Eksisting yang Harus Diperbaiki](#20-cacat-sistem-eksisting-yang-harus-diperbaiki)
21. [Roadmap Implementasi Bertahap](#21-roadmap-implementasi-bertahap)
22. [Verifikasi Manual (Skenario Uji)](#22-verifikasi-manual-skenario-uji)

---
---

# BAGIAN A — ANALISIS SISTEM EKSISTING

## 1. Peta Sistem & Inventaris Fitur

### 1.1 Apa yang dibangun sistem ini

Backend REST API untuk **koperasi syariah digital** dengan empat pilar bisnis:

| Pilar | Akad | Inti mekanisme |
|---|---|---|
| **Simpanan** | Wadiah (titipan) & Mudharabah (bagi hasil) | Rekening bersaldo + buku besar append-only |
| **Pembiayaan** | Murabahah (jual-beli + margin) | Margin dikunci di awal, angsuran flat bulanan |
| **Emas Digital** | Jual-beli emas per gram | Saldo simpanan didebet, token ERC-20 di-mint di Polygon |
| **Keanggotaan** | — | Registrasi + OTP email + KYC + RBAC 4 tingkat |

### 1.2 Inventaris endpoint (24 endpoint aktif)

Prefix global: **`/api/v1`**

| # | Method | Path | Auth | Peran | Modul |
|---|---|---|---|---|---|
| 1 | GET | `/health` | — | publik | Sistem |
| 2 | POST | `/register` | rate-limit | publik | Auth |
| 3 | POST | `/login` | rate-limit | publik | Auth |
| 4 | POST | `/verify-email` | rate-limit | publik | Auth |
| 5 | GET | `/gold/price` | — | publik | Emas |
| 6 | GET | `/profile` | JWT + aktif | anggota | Akun |
| 7 | POST | `/logout` | JWT + aktif | anggota | Auth |
| 8 | GET | `/profile/kyc` | JWT + aktif | anggota | KYC |
| 9 | PUT | `/profile/kyc` | JWT + aktif | anggota | KYC |
| 10 | POST | `/savings/accounts` | JWT + aktif | anggota | Simpanan |
| 11 | GET | `/savings/accounts` | JWT + aktif | anggota | Simpanan |
| 12 | POST | `/savings/deposit` | JWT + aktif | anggota | Simpanan |
| 13 | GET | `/savings/deposit-requests` | JWT + aktif | anggota | Simpanan |
| 14 | POST | `/financing/apply` | JWT + aktif | anggota | Pembiayaan |
| 15 | GET | `/financing` | JWT + aktif | anggota | Pembiayaan |
| 16 | GET | `/financing/:id/installments` | JWT + aktif | anggota | Pembiayaan |
| 17 | POST | `/financing/installments/:id/pay` | JWT + aktif | anggota | Pembiayaan |
| 18 | POST | `/gold/buy` | JWT + aktif | anggota | Emas |
| 19 | POST | `/gold/sell` | JWT + aktif | anggota | Emas |
| 20 | PUT | `/admin/financing/:id/review` | JWT + role | pengurus+ | Admin |
| 21 | PUT | `/admin/savings/deposit-requests/:id/review` | JWT + role | pengurus+ | Admin |
| 22 | GET | `/admin/savings/deposit-requests` | JWT + role | pengurus+ | Admin |
| 23 | GET | `/admin/users` | JWT + role | pengurus+ | Admin |
| 24 | GET | `/admin/transactions/{financing,gold,saving}` | JWT + role | pengurus+ | Admin |

> Catatan: grup `/admin` memakai `RequireAuth + RequireRole`, **tanpa** `RequireActiveUserDB`.
> Artinya admin ber-status `banned` masih bisa mengakses endpoint admin. Ini cacat — lihat §20.

### 1.3 Dependensi runtime

| Komponen | Peran di sistem | Wajib? |
|---|---|---|
| PostgreSQL | Sumber kebenaran seluruh data keuangan | **Ya** — server `log.Fatal` jika gagal |
| Redis | 1) OTP registrasi, 2) blocklist JWT logout, 3) cache harga emas, 4) antrian `queue:gold_mint` | **Ya** — server `log.Fatal` jika gagal |
| SMTP | Kirim OTP; jika host kosong → mode simulasi (log saja) | Tidak |
| Polygon RPC | Mint token CGLD | Tidak — worker jalan mode *log-only* |

---

## 2. Arsitektur Berlapis & Siklus Hidup Request

### 2.1 Empat lapisan

```
HTTP Request
    │
    ▼
┌─────────────────────────────────────────────────────────┐
│ MIDDLEWARE   CORS → Recovery → Logger → RateLimit       │
│              → RequireAuth → RequireActiveUserDB        │
│              → RequireRole (grup admin saja)            │
└─────────────────────────────────────────────────────────┘
    │  konteks: user_id (int64), email (string), role (string)
    ▼
┌─────────────────────────────────────────────────────────┐
│ HANDLER      Bind + validasi payload                    │
│              Terjemahkan sentinel error → HTTP status   │
│              Serialisasi JSON. TIDAK ada logika bisnis. │
└─────────────────────────────────────────────────────────┘
    │  struct request
    ▼
┌─────────────────────────────────────────────────────────┐
│ SERVICE      Aturan bisnis: hitung margin, generate     │
│              nomor akad, validasi kepemilikan, susun    │
│              jadwal angsuran, batas transaksi.          │
│              TIDAK menyentuh *sql.DB.                   │
└─────────────────────────────────────────────────────────┘
    │  argumen primitif / model
    ▼
┌─────────────────────────────────────────────────────────┐
│ REPOSITORY   SQL mentah, DB transaction, row locking,   │
│              cache-aside Redis, terjemahan error driver │
└─────────────────────────────────────────────────────────┘
    │
    ▼
PostgreSQL + Redis
```

**Aturan yang dipegang konsisten di seluruh kode Go dan wajib dipertahankan di CI3:**

1. Setiap lapisan hanya bergantung pada **interface** lapisan di bawahnya.
2. Error dilempar sebagai **sentinel error** (variabel error bernama), bukan string. Handler mencocokkan sentinel → status HTTP.
3. **Semua operasi yang menyentuh saldo dijalankan dalam satu DB transaction dengan `SELECT ... FOR UPDATE`.** Tidak ada pengecualian.
4. Kesalahan otorisasi (akses resource milik orang lain) dikembalikan sebagai **404, bukan 403** — untuk mencegah enumerasi ID.

### 2.2 Urutan bootstrap aplikasi (`main.go`)

```
1.  godotenv.Load()                  → .env opsional
2.  config.Load()                    → seluruh ENV jadi struct typed
3.  database.New(cfg.DB)             → pool: MaxOpen 25, MaxIdle 10, Lifetime 300s
4.  database.NewRedisClient()        → pool 10, dial 5s, r/w 3s
5.  blockchain.NewEVMClient()        → opsional; gagal = warning, bukan fatal
6.  context.WithCancel()             → sinyal shutdown untuk worker
7.  setupRouter()                    → rakit repo → service → handler → route
8.  goldWorker.Recover(ctx)          → SINKRON, sebelum server melayani request
9.  go goldWorker.Start(ctx)         → BLPop loop di goroutine
10. srv.ListenAndServe()             → Read/Write 15s, Idle 60s
11. tunggu SIGINT/SIGTERM
12. cancel() worker → srv.Shutdown(30s)
```

Langkah **8 sengaja sinkron**: transaksi emas yang tertinggal akibat crash harus di-requeue *sebelum* request baru masuk.

### 2.3 Rantai dependency injection

```
db, rdb
 ├── userRepo      ──┬── userSvc      ──┬── userH ── /register /login /verify-email
 │                   │   (+ emailSvc,   │            /profile /logout /admin/users
 │                   │      jwtSecret,  │
 │                   │      jwtTTL,rdb) │
 │                   └── goldWorker ────┘ (butuh wallet_address anggota)
 ├── savingRepo    ──── savingSvc     ──┬── savingH ── /savings/*
 │                                      └── userH   (auto-buka rekening wajib saat register)
 ├── financingRepo ──── financingSvc  ──── financingH ── /financing/*
 └── goldRepo(+rdb)──┬─ goldSvc(+rdb) ──── goldH ── /gold/*
                     └─ goldWorker
```

`userH` menerima **dua** service (`userService` + `savingService`) karena registrasi memicu pembukaan rekening wajib.

---

## 3. Model Data Lengkap

### 3.1 Diagram relasi

```
                        ┌──────────────┐
                        │    users     │
                        │  id (PK)     │
                        └──────┬───────┘
         ┌─────────────┬───────┼───────────┬──────────────┐
         │             │       │           │              │
   ┌─────▼───────┐ ┌───▼──────┐│  ┌────────▼───────┐ ┌────▼──────────────┐
   │user_profiles│ │savings_  ││  │   financing    │ │ gold_transactions │
   │ user_id(PK) │ │accounts  ││  │  id (PK)       │ │  id (PK)          │
   └─────────────┘ │ id (PK)  ││  └────────┬───────┘ └───────────────────┘
                   └────┬─────┘│           │
        ┌───────────────┼──────┘   ┌───────▼───────────────┐
        │               │          │financing_installments │
┌───────▼──────────┐ ┌──▼───────────────┐ UNIQUE(fin_id,no)│
│savings_          │ │deposit_requests  │ └──────────────────┘
│transactions      │ │ reviewed_by→users│
│ (APPEND-ONLY)    │ └──────────────────┘
└──────────────────┘

  savings_products ──> savings_accounts.savings_product_id
  gold_prices (tabel mandiri, tanpa FK)
```

### 3.2 Tabel per tabel

#### `users` — fondasi seluruh sistem

| Kolom | Tipe | Constraint | Catatan |
|---|---|---|---|
| `id` | BIGSERIAL | PK | |
| `nama_lengkap` | VARCHAR(150) | NOT NULL | sesuai KTP |
| `email` | VARCHAR(255) | NOT NULL **UNIQUE** | dipakai sebagai username |
| `password_hash` | VARCHAR(255) | NOT NULL | bcrypt **cost 12** |
| `role` | VARCHAR(20) | DEFAULT `'anggota'` | `anggota\|pengurus\|admin\|super_admin` |
| `wallet_address` | VARCHAR(42) | **UNIQUE**, NULL | alamat EVM; NULL = belum connect |
| `status` | ENUM `user_status` | DEFAULT `'active'` | `active\|inactive\|banned` |
| `is_email_verified` | BOOLEAN | DEFAULT FALSE | |
| `created_at`/`updated_at` | TIMESTAMPTZ | trigger auto-update | |

> Migrasi 001 membuat `CREATE TYPE user_role AS ENUM(...)` lalu migrasi 004 menambah kolom `role`
> sebagai `VARCHAR(20) + CHECK`. Tipe enum `user_role` **tidak terpakai**. Di CI3, cukup VARCHAR + CHECK.

#### `savings_products` — katalog produk

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | BIGSERIAL | |
| `name` | VARCHAR(100) | |
| `akad_type` | VARCHAR(20) | CHECK `Wadiah\|Mudharabah` |
| `min_deposit` | DECIMAL(19,4) | divalidasi saat request setoran |
| `profit_sharing_ratio` | DECIMAL(19,4) | 0.00–1.00 |
| `is_mandatory` | BOOLEAN | **memicu auto-buka rekening saat register** |

Seed wajib:

| name | akad | min_deposit | nisbah | mandatory |
|---|---|---|---|---|
| Simpanan Pokok | Wadiah | 50 000 | 0.00 | **TRUE** |
| Simpanan Wajib | Wadiah | 10 000 | 0.00 | **TRUE** |
| Simpanan Sukarela | Mudharabah | 10 000 | 0.60 | FALSE |

#### `savings_accounts` — rekening

`balance DECIMAL(19,4) CHECK (balance >= 0)`, `status active|frozen|closed`.
Saldo **hanya** boleh berubah lewat repository di dalam transaction.

#### `savings_transactions` — buku besar (APPEND-ONLY)

| Kolom | Catatan |
|---|---|
| `type` | `deposit` \| `withdraw` — arah uang; `amount` selalu positif |
| `reference_id` | VARCHAR(100) DEFAULT `''` — **kunci korelasi antar modul** |
| `created_at` | tidak ada `updated_at`; baris tidak pernah diubah |

**Konvensi `reference_id` yang dipakai kode (wajib dipertahankan persis):**

| Format | Ditulis oleh | Arti |
|---|---|---|
| *(dari user)* | approve deposit | nomor bukti transfer manual |
| `cicilan_{installment_id}` | bayar angsuran | debit untuk cicilan |
| `gold_buy_{gold_tx_id}` | beli emas | debit pembelian emas |
| `gold_sell_{gold_tx_id}` | jual emas | kredit penjualan emas |
| `gold_refund_{gold_tx_id}` | refund worker | kredit balik saat mint gagal |

> `gold_buy_{id}` **bukan sekadar catatan** — `RefundFailedTransaction` memakainya untuk
> menemukan rekening mana yang harus di-refund. Ubah format ini dan refund akan rusak.

#### `deposit_requests` — antrian verifikasi setoran

`amount NUMERIC(15,2)`, `payment_method`, `proof_image_url`, `status` (default `pending`),
`reference_id`, `reviewed_by → users`, `reviewed_at`, timestamps.

> Perhatikan presisi **(15,2)** — berbeda dari tabel lain yang **(19,4)**. Seragamkan ke (19,4)
> saat port, atau pertahankan konsisten; jangan setengah-setengah.

#### `financing` — master akad

| Kolom | Catatan |
|---|---|
| `financing_number` | VARCHAR(50) **UNIQUE**, format `FIN-MRB-{unixnano}-{attempt}` |
| `akad` | CHECK hanya `'murabahah'` |
| `principal_amount` | > 0 |
| `margin_amount` | >= 0 — **dikunci selamanya setelah akad** |
| `total_payable` | = principal + margin, di-persist |
| `duration_months` | 1–360 |
| `status` | `pending\|approved\|active\|paid\|rejected` |
| `reviewed_by` / `reviewed_at` | NULL selama pending |

> `active` ada di CHECK constraint tapi **tidak pernah di-set kode manapun**. Alur nyata:
> `pending → approved → paid` atau `pending → rejected`.

#### `financing_installments` — jadwal angsuran

`UNIQUE(financing_id, installment_number)`, `amount_due > 0`, `amount_paid >= 0`,
`due_date DATE`, `status unpaid|paid`, `paid_at TIMESTAMPTZ NULL`.

#### `gold_prices` & `gold_transactions`

`gold_prices`: `buy_price_per_gram` (harga anggota membeli), `sell_price_per_gram` (harga anggota menjual),
`updated_at` — baris terbaru ditentukan **`ORDER BY updated_at DESC`, bukan `id`**.

`gold_transactions`: `type buy|sell`, `gram_amount DECIMAL(10,4)`, `price_per_gram`, `total_rupiah`,
`tx_hash VARCHAR(100) NULL`, `status pending|processing|success|failed`.

#### `user_profiles` — KYC

PK = `user_id` (1:1, ON DELETE CASCADE). `nik VARCHAR(16) UNIQUE`, `phone_number`, `address`,
`job_title`, `monthly_income DECIMAL(15,2)`, `emergency_contact_name`, `emergency_contact_phone`.

---

## 4. State Machine Semua Entitas

### 4.1 Akun pengguna

```
        register
           │
           ▼
  [is_email_verified = FALSE]  ──login──> 403 "email belum diverifikasi"
           │
      verify-email (OTP benar)
           │
           ▼
  [is_email_verified = TRUE, status='active'] ──login──> 200 + JWT
           │
      admin nonaktifkan
           ▼
  [status='inactive'|'banned'] ──login──> 403 "akun tidak aktif"
                                ──request ber-JWT──> 403 (RequireActiveUserDB)
```

### 4.2 Permohonan setoran

```
POST /savings/deposit
        │
        ▼
    [pending] ──admin reject──> [rejected]   (saldo tidak berubah)
        │
        └──admin approve──> [approved] + saldo bertambah + 1 baris ledger 'deposit'

    review ulang atas non-pending → 422 "sudah direview sebelumnya"
```

### 4.3 Pembiayaan

```
POST /financing/apply
        │
        ▼
    [pending] ──admin reject──> [rejected]  (terminal)
        │
        └──admin approve──> [approved] + N baris angsuran ter-generate (atomik)
                                │
                       bayar angsuran satu per satu
                                │
                    saat COUNT(unpaid)=0 ──> [paid]
```

### 4.4 Angsuran

```
[unpaid] ──POST pay (saldo cukup)──> [paid] (+amount_paid, +paid_at)
[unpaid] ──POST pay (saldo kurang)──> tetap unpaid, 422
[paid]   ──POST pay──> 409 "sudah dibayar"
```

### 4.5 Transaksi emas (paling kompleks)

```
POST /gold/buy
   │ (atomik: debit saldo + insert tx)
   ▼
[pending] ──RPush queue:gold_mint──┐
   │                               │
   │  worker BLPop                 │
   ▼                               │
 wallet_address NULL? ──ya──> REFUND ──> [failed]
   │ tidak
   ▼
 blockchain nonaktif? ──ya──> berhenti, tetap [pending]  ⚠️ menggantung
   │ tidak
   ▼
 mint() error broadcast? ──ya──> [failed]  ⚠️ TANPA refund (BUG, lihat §20)
   │ tidak
   ▼
[processing] + tx_hash ──WaitMined──┬── receipt=1 ──> [success]
                                    └── receipt=0 ──> REFUND ──> [failed]

POST /gold/sell → langsung [success] (murni off-chain, tidak menyentuh blockchain)
```

Recovery saat startup:
- `status='pending'` → di-RPush ulang ke antrian.
- `status='processing' AND tx_hash IS NOT NULL` → `TransactionByHash` + lanjut `WaitMined`.

---
---

# BAGIAN B — FONDASI CODEIGNITER 3

## 5. Pemetaan Arsitektur Go → CI3

| Konsep Go | Padanan CodeIgniter 3 | Berkas |
|---|---|---|
| `main.go` bootstrap | `index.php` + `config/*.php` | — |
| `gin.Engine` route group | `config/routes.php` | route regex |
| Middleware `RequireAuth` | `MY_Controller` → `Auth_Controller` | `core/MY_Controller.php` |
| Middleware `RequireRole` | `Admin_Controller extends Auth_Controller` | idem |
| Middleware CORS | **hook** `pre_system` | `hooks/Cors.php` |
| Middleware RateLimit | library `Ratelimit` dipanggil di controller publik | `libraries/Ratelimit.php` |
| `handler/*.go` | `controllers/api/v1/*.php` | |
| `service/*.go` | `libraries/*_service.php` | logika bisnis |
| `repository/*.go` | `models/*_model.php` | SQL + transaction |
| `model/*.go` (struct) | array asosiatif + aturan validasi | |
| Sentinel error (`errors.New`) | `Api_exception` static factory | `libraries/Api_exception.php` |
| `context.Context` | — (tidak ada padanan; abaikan) | |
| goroutine + `BLPop` | CLI controller di-supervisi | `controllers/cli/Gold_worker.php` |
| `sql.Tx` + `FOR UPDATE` | `$this->db->trans_begin()` + `query('... FOR UPDATE')` | |
| `bcrypt.GenerateFromPassword(.., 12)` | `password_hash($p, PASSWORD_BCRYPT, ['cost'=>12])` | |
| `golang-jwt/jwt/v5` | `firebase/php-jwt` | composer |
| `redis/go-redis/v9` | `predis/predis` | composer |
| `float64` untuk uang | **`bcmath` + string** (WAJIB, lihat §19.1) | |

### 5.1 Keputusan arsitektur yang harus diambil di awal

| Keputusan | Rekomendasi | Alasan |
|---|---|---|
| Database | **Tetap PostgreSQL** | Semua SQL, `RETURNING`, `ON CONFLICT`, partial index bisa dipakai apa adanya. Migrasi ke MySQL = menulis ulang ~15 query. (DDL MySQL tetap disediakan di §9.4) |
| Struktur controller | Sub-direktori `controllers/api/v1/` | Meniru prefix `/api/v1` tanpa route hack |
| Base controller | 3 tingkat: `API_Controller` → `Auth_Controller` → `Admin_Controller` | Persis meniru rantai middleware |
| Uang | `bcmath`, scale 4, dihitung sebagai **string** | Menghindari galat presisi float PHP |
| Worker emas | CLI controller + Supervisor (Linux) / NSSM (Windows) | PHP tidak punya goroutine |
| Blockchain | **Microservice signer terpisah** (Node/ethers.js) atau pertahankan worker Go | `web3.php` untuk signing raw tx rapuh & minim pemeliharaan |

---

## 6. Langkah 0: Persiapan Lingkungan

### 6.1 Prasyarat

```
PHP         7.4.x    (ekstensi: pgsql, pdo_pgsql, bcmath, mbstring, openssl, curl, json)
CodeIgniter 3.1.13   (versi terakhir; PHP 7.4-safe)
PostgreSQL  13+      (atau MySQL 8 — lihat §9.4)
Redis       6+
Composer    2.x
```

Cek ekstensi:

```bash
php -m | grep -E "pgsql|bcmath|mbstring|openssl|curl"
php -r "echo bcadd('0.1','0.2',4);"   # harus 0.3000
```

### 6.2 Inisialisasi proyek

```bash
composer create-project codeigniter/framework koperasi-ci3 3.1.13
cd koperasi-ci3
composer require firebase/php-jwt:^6.0 predis/predis:^2.0 phpmailer/phpmailer:^6.8 vlucas/phpdotenv:^5.4
```

### 6.3 `.env`

```ini
APP_ENV=development
SERVER_PORT=8080

JWT_SECRET=ganti-dengan-secret-minimal-32-karakter
JWT_TOKEN_TTL_HOURS=24

DB_DRIVER=postgre
DB_HOST=localhost
DB_PORT=5432
DB_USER=postgres
DB_PASSWORD=rahasia
DB_NAME=koperasi_digital

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

FRONTEND_URL=http://localhost:3000

MURABAHAH_MARGIN_RATE=0.10
GOLD_MAX_GRAM_PER_TX=100
GOLD_PRICE_CACHE_TTL=900
OTP_TTL_SECONDS=900

SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=
SMTP_PASSWORD=
SMTP_FROM_EMAIL=noreply@koperasi-digital.com

POLYGON_RPC_URL=
OWNER_PRIVATE_KEY=
GOLD_CONTRACT_ADDRESS=
SIGNER_SERVICE_URL=http://127.0.0.1:3100
```

Muat di `application/config/config.php` paling atas:

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$dotenv = Dotenv\Dotenv::createImmutable(FCPATH);
$dotenv->safeLoad();

if ( ! function_exists('env')) {
    function env($key, $default = null) {
        $v = $_ENV[$key] ?? getenv($key);
        return ($v === false || $v === '') ? $default : $v;
    }
}
```

> `vendor/autoload.php` harus termuat: `$config['composer_autoload'] = FCPATH.'vendor/autoload.php';`

---

## 7. Langkah 1: Struktur Direktori Final

```
koperasi-ci3/
├── .env
├── composer.json
├── index.php
├── vendor/
├── database/
│   └── migrations/                     # SQL mentah, dijalankan via psql
│       └── 001_users.sql ... 010_deposit_requests.sql
└── application/
    ├── config/
    │   ├── config.php                  # + composer_autoload, env(), timezone, hooks
    │   ├── database.php                # driver postgre, dari env()
    │   ├── routes.php                  # 24 route
    │   ├── hooks.php                   # aktifkan Cors
    │   ├── autoload.php                # libraries + config koperasi
    │   └── koperasi.php                # konstanta bisnis
    ├── core/
    │   └── MY_Controller.php           # API_ / Auth_ / Admin_ Controller
    ├── hooks/
    │   └── Cors.php
    ├── libraries/
    │   ├── Api_response.php
    │   ├── Api_exception.php
    │   ├── Jwt_service.php
    │   ├── Redisx.php
    │   ├── Ratelimit.php
    │   ├── Money.php
    │   ├── Validator.php
    │   ├── Email_service.php
    │   ├── User_service.php
    │   ├── Saving_service.php
    │   ├── Financing_service.php
    │   ├── Gold_service.php
    │   └── Chain_client.php
    ├── models/
    │   ├── User_model.php
    │   ├── User_profile_model.php
    │   ├── Saving_model.php
    │   ├── Deposit_request_model.php
    │   ├── Financing_model.php
    │   └── Gold_model.php
    └── controllers/
        ├── api/v1/
        │   ├── Health.php
        │   ├── Auth.php
        │   ├── Profile.php
        │   ├── Savings.php
        │   ├── Financing.php
        │   ├── Gold.php
        │   └── Admin.php
        └── cli/
            └── Gold_worker.php
```

---

## 8. Langkah 2: Konfigurasi Inti

### 8.1 `application/config/koperasi.php`

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$config['jwt_secret']    = env('JWT_SECRET');
$config['jwt_ttl_hours'] = (int) env('JWT_TOKEN_TTL_HOURS', 24);
$config['jwt_issuer']    = 'koperasi-digital';

$config['murabahah_margin_rate'] = (string) env('MURABAHAH_MARGIN_RATE', '0.10');
$config['financing_max_months']  = 360;

$config['gold_max_gram_per_tx'] = (string) env('GOLD_MAX_GRAM_PER_TX', '100');
$config['gold_min_gram']        = '0.0001';
$config['gold_price_cache_key'] = 'gold:current_price';
$config['gold_price_cache_ttl'] = (int) env('GOLD_PRICE_CACHE_TTL', 900);
$config['gold_mint_queue_key']  = 'queue:gold_mint';
$config['gold_decimals']        = 4;

$config['otp_ttl_seconds'] = (int) env('OTP_TTL_SECONDS', 900);
$config['bcrypt_cost']     = 12;
$config['money_scale']     = 4;

$config['rate_limit_burst'] = 5;

$config['roles_admin'] = ['pengurus', 'admin', 'super_admin'];
```

Autoload: `$autoload['config'] = ['koperasi'];`

### 8.2 `application/config/database.php`

```php
$db['default'] = [
    'dsn'      => '',
    'hostname' => env('DB_HOST', 'localhost'),
    'port'     => env('DB_PORT', 5432),
    'username' => env('DB_USER', 'postgres'),
    'password' => env('DB_PASSWORD', ''),
    'database' => env('DB_NAME', 'koperasi_digital'),
    'dbdriver' => env('DB_DRIVER', 'postgre'),
    'dbprefix' => '',
    'pconnect' => FALSE,           // WAJIB FALSE — lihat catatan
    'db_debug' => FALSE,           // kita tangani error manual
    'cache_on' => FALSE,
    'char_set' => 'utf8',
    'swap_pre' => '',
    'encrypt'  => FALSE,
    'compress' => FALSE,
    'stricton' => FALSE,
    'failover' => [],
    'save_queries' => (env('APP_ENV') !== 'production'),
];
```

> **`pconnect => FALSE` itu wajib.** Dengan koneksi persisten, transaction yang gagal rollback
> pada satu request bisa "menempel" ke request berikutnya dan mengunci baris rekening.
>
> **`db_debug => FALSE`** supaya query error tidak langsung `show_error()` dan mematikan
> proses di tengah transaction — kita periksa `$this->db->error()` sendiri.

### 8.3 `application/config/hooks.php`

```php
$hook['pre_system'] = [
    'class'    => 'Cors',
    'function' => 'handle',
    'filename' => 'Cors.php',
    'filepath' => 'hooks',
];
```

Aktifkan di `config.php`: `$config['enable_hooks'] = TRUE;`

### 8.4 `application/hooks/Cors.php`

Meniru konfigurasi `gin-contrib/cors`: satu origin eksplisit + credentials + preflight cache 12 jam.

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Cors {
    public function handle() {
        $allowed = getenv('FRONTEND_URL') ?: ($_ENV['FRONTEND_URL'] ?? 'http://localhost:3000');
        $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';

        // Satu origin eksplisit — bukan '*'. Wildcard tidak kompatibel dengan credentials.
        if ($origin === $allowed) {
            header('Access-Control-Allow-Origin: ' . $allowed);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Expose-Headers: Content-Length');
        header('Access-Control-Max-Age: 43200');          // 12 jam

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            header('HTTP/1.1 204 No Content');
            exit;
        }
    }
}
```

### 8.5 Timezone

Go memakai `TIMESTAMPTZ` + `NOW()`. Di PHP:

```php
date_default_timezone_set('Asia/Jakarta');
```

Dan **selalu pakai `NOW()` milik database** untuk kolom waktu, jangan `date('Y-m-d H:i:s')` dari PHP —
sumber waktu tunggal tetap di DB, sama seperti kode Go.

---

## 9. Langkah 3: Skema Database

### 9.1 Urutan eksekusi

```bash
psql -U postgres -c "CREATE DATABASE koperasi_digital;"
for f in database/migrations/*.sql; do psql -U postgres -d koperasi_digital -f "$f"; done
```

### 9.2 Skema PostgreSQL

Gunakan **persis** `db/migrations/001..010` dari repo Go — tidak ada yang perlu diubah.
Urutan: users → savings → financing → kolom review → paid_at → gold → wallet/status →
user_profiles → email_verification → deposit_requests.

Yang wajib ada dan sering terlupa saat menulis ulang:

```sql
-- trigger updated_at (dipakai users & user_profiles)
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE TRIGGER users_updated_at BEFORE UPDATE ON users
FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- partial index yang mempercepat worker & query admin
CREATE INDEX idx_gold_transactions_status ON gold_transactions(status)
    WHERE status IN ('pending','processing');
CREATE INDEX idx_users_wallet_address ON users(wallet_address)
    WHERE wallet_address IS NOT NULL;
CREATE INDEX idx_financing_reviewed_by ON financing(reviewed_by)
    WHERE reviewed_by IS NOT NULL;
```

### 9.3 Seed wajib

```sql
INSERT INTO savings_products (name, akad_type, min_deposit, profit_sharing_ratio, is_mandatory)
VALUES ('Simpanan Pokok','Wadiah',50000,0.00,TRUE),
       ('Simpanan Wajib','Wadiah',10000,0.00,TRUE),
       ('Simpanan Sukarela','Mudharabah',10000,0.60,FALSE)
ON CONFLICT DO NOTHING;

INSERT INTO gold_prices (buy_price_per_gram, sell_price_per_gram, updated_at)
VALUES (1698000.0000, 1672000.0000, NOW());
```

Tanpa seed `gold_prices`, `GET /gold/price` mengembalikan **503**, dan beli/jual emas ikut gagal.

### 9.4 Varian MySQL 8 (jika terpaksa)

| PostgreSQL | MySQL 8 |
|---|---|
| `BIGSERIAL PRIMARY KEY` | `BIGINT AUTO_INCREMENT PRIMARY KEY` |
| `TIMESTAMPTZ` | `TIMESTAMP` (+ `SET time_zone='+07:00'`) |
| `DECIMAL(19,4)` | sama |
| `CREATE TYPE ... AS ENUM` | `ENUM('a','b')` inline di kolom |
| `INSERT ... RETURNING id` | `INSERT` lalu `$this->db->insert_id()` |
| `INSERT ... ON CONFLICT (k) DO UPDATE SET` | `INSERT ... ON DUPLICATE KEY UPDATE` |
| Partial index `WHERE ...` | tidak ada — buat index penuh |
| `SELECT ... FOR UPDATE` | sama (InnoDB) |
| Kode unique violation `23505` | `1062` |
| Trigger `updated_at` | `ON UPDATE CURRENT_TIMESTAMP` |

Contoh `users` versi MySQL:

```sql
CREATE TABLE users (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  nama_lengkap VARCHAR(150) NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('anggota','pengurus','admin','super_admin') NOT NULL DEFAULT 'anggota',
  wallet_address VARCHAR(42) UNIQUE NULL,
  status ENUM('active','inactive','banned') NOT NULL DEFAULT 'active',
  is_email_verified TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_email (email)
) ENGINE=InnoDB;
```

Setel isolation agar sepadan dengan Go:

```php
$this->db->query("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
```

---

## 10. Langkah 4: Komponen Fondasi Lintas-Modul

Bagian ini tulang punggung. **Semua modul di Bagian C bergantung padanya** — selesaikan §10
sepenuhnya sebelum menyentuh satu pun endpoint bisnis.

### 10.1 `Money` — aritmetika uang dengan bcmath

Padanan `util.RoundTo4Decimals`, tapi bebas galat presisi.

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Semua nilai uang & gram diperlakukan sebagai STRING desimal.
 * Jangan kembalikan ke float sebelum dikirim sebagai JSON.
 */
class Money {
    const SCALE = 4;

    public static function norm($v)       { return bcadd((string)$v, '0', self::SCALE); }
    public static function add($a, $b)    { return bcadd((string)$a, (string)$b, self::SCALE); }
    public static function sub($a, $b)    { return bcsub((string)$a, (string)$b, self::SCALE); }
    public static function mul($a, $b)    { return bcmul((string)$a, (string)$b, self::SCALE); }
    public static function div($a, $b)    { return bcdiv((string)$a, (string)$b, self::SCALE); }
    public static function cmp($a, $b)    { return bccomp((string)$a, (string)$b, self::SCALE); }
    public static function gt($a, $b)     { return self::cmp($a, $b) === 1; }
    public static function lt($a, $b)     { return self::cmp($a, $b) === -1; }

    /** Untuk response JSON: kirim sebagai number agar kompatibel frontend lama. */
    public static function out($v)        { return (float) self::norm($v); }
}
```

> **Kenapa string?** `0.1 + 0.2 !== 0.3` di PHP maupun Go. Kode Go menutupinya dengan
> `math.Round(x*10000)/10000` di setiap titik — rapuh dan mudah terlewat. bcmath
> menghilangkan kelas bug ini seluruhnya.

### 10.2 `Api_exception` — padanan sentinel error

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Api_exception extends Exception {
    public $status;      // HTTP status
    public $code_name;   // kode mesin, mis. INSUFFICIENT_BALANCE

    public function __construct($code_name, $message, $status = 400) {
        parent::__construct($message);
        $this->code_name = $code_name;
        $this->status    = $status;
    }

    // --- Katalog sentinel error, 1:1 dengan var error di kode Go ---
    public static function invalidCredentials() { return new self('INVALID_CREDENTIALS', 'email atau password tidak valid', 401); }
    public static function emailExists()        { return new self('EMAIL_EXISTS', 'email sudah terdaftar', 409); }
    public static function userNotFound()       { return new self('USER_NOT_FOUND', 'akun tidak ditemukan', 404); }
    public static function emailNotVerified()   { return new self('EMAIL_NOT_VERIFIED', 'email belum diverifikasi, periksa kotak masuk Anda', 403); }
    public static function accountSuspended()   { return new self('ACCOUNT_SUSPENDED', 'akun tidak aktif atau diblokir, hubungi admin koperasi', 403); }

    public static function savingsAccountNotFound() { return new self('SAVINGS_ACCOUNT_NOT_FOUND', 'rekening simpanan tidak ditemukan', 404); }
    public static function savingsProductNotFound() { return new self('SAVINGS_PRODUCT_NOT_FOUND', 'produk simpanan tidak ditemukan', 404); }
    public static function accountNotActive()       { return new self('ACCOUNT_NOT_ACTIVE', 'rekening simpanan tidak aktif', 422); }
    public static function depositBelowMinimum($m)  { return new self('DEPOSIT_BELOW_MINIMUM', 'jumlah setoran di bawah minimum produk: minimum Rp ' . number_format((float)$m, 0, ',', '.'), 422); }
    public static function depositRequestNotFound() { return new self('DEPOSIT_REQUEST_NOT_FOUND', 'permohonan setoran tidak ditemukan', 404); }
    public static function depositAlreadyReviewed() { return new self('DEPOSIT_ALREADY_REVIEWED', 'permohonan setoran sudah direview sebelumnya', 422); }

    public static function financingNotFound()      { return new self('FINANCING_NOT_FOUND', 'pengajuan pembiayaan tidak ditemukan', 404); }
    public static function financingNotPending()    { return new self('FINANCING_NOT_PENDING', 'pengajuan sudah pernah diproses sebelumnya', 409); }
    public static function installmentNotFound()    { return new self('INSTALLMENT_NOT_FOUND', 'cicilan tidak ditemukan', 404); }
    public static function installmentAlreadyPaid() { return new self('INSTALLMENT_ALREADY_PAID', 'cicilan sudah dibayar sebelumnya', 409); }
    public static function insufficientBalance()    { return new self('INSUFFICIENT_BALANCE', 'saldo rekening tidak mencukupi', 422); }

    public static function goldPriceUnavailable()    { return new self('GOLD_PRICE_UNAVAILABLE', 'harga emas belum tersedia, hubungi admin koperasi', 503); }
    public static function goldLimitExceeded($max)   { return new self('GOLD_LIMIT_EXCEEDED', "maksimal transaksi emas adalah {$max} gram per transaksi", 400); }
    public static function goldInsufficientHolding() { return new self('GOLD_INSUFFICIENT_HOLDING', 'saldo emas Anda tidak mencukupi untuk penjualan ini', 422); }

    public static function unauthorized($msg = 'sesi tidak valid, silakan login kembali') { return new self('UNAUTHORIZED', $msg, 401); }
    public static function forbidden($msg = 'akses ditolak: hak akses tidak mencukupi')   { return new self('FORBIDDEN', $msg, 403); }
    public static function badRequest($msg)  { return new self('BAD_REQUEST', $msg, 400); }
    public static function tooManyRequests() { return new self('TOO_MANY_REQUESTS', 'terlalu banyak permintaan, silakan coba lagi beberapa saat kemudian', 429); }
    public static function server()          { return new self('SERVER_ERROR', 'terjadi kesalahan pada server', 500); }
}
```

### 10.3 `MY_Controller` — pengganti rantai middleware

Komponen paling penting di seluruh port. Tiga kelas bertingkat, persis seperti grup route Gin.
Letakkan **ketiganya dalam satu berkas** `application/core/MY_Controller.php`.

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * API_Controller — lapisan dasar semua endpoint.
 * Padanan: gin.New() + Recovery() + Logger() + parser JSON body.
 */
class API_Controller extends CI_Controller {

    protected $body = [];    // payload JSON hasil decode

    public function __construct() {
        parent::__construct();
        $this->load->library(['Api_response', 'Validator']);
        $this->_parse_json_body();
    }

    private function _parse_json_body() {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === FALSE) { $this->body = []; return; }
        $decoded = json_decode($raw, TRUE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->fail(Api_exception::badRequest('body bukan JSON yang valid'));
        }
        $this->body = is_array($decoded) ? $decoded : [];
    }

    protected function ok($data, $status = 200) {
        return $this->api_response->send($data, $status);
    }

    /** Kirim response error dan HENTIKAN eksekusi (meniru c.AbortWithStatusJSON). */
    protected function fail(Api_exception $e) {
        $this->api_response->send(['error' => $e->getMessage()], $e->status);
        exit;
    }

    /**
     * Bungkus aksi controller: sentinel error → status yang benar,
     * error tak dikenal → 500 generik + log (pola handler Go).
     */
    protected function run(callable $fn) {
        try {
            return $fn();
        } catch (Api_exception $e) {
            $this->fail($e);
        } catch (Throwable $t) {
            log_message('error', '[api] ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine());
            $this->fail(Api_exception::server());
        }
    }

    /** Parse :id dari URI segment — meniru strconv.ParseInt + cek <= 0. */
    protected function param_id($raw, $label = 'id') {
        if ( ! ctype_digit((string)$raw) || (int)$raw <= 0) {
            throw Api_exception::badRequest("parameter {$label} tidak valid");
        }
        return (int) $raw;
    }
}

/**
 * Auth_Controller — padanan RequireAuth + RequireActiveUserDB.
 */
class Auth_Controller extends API_Controller {

    protected $user_id;
    protected $user_email;
    protected $raw_token;

    public function __construct() {
        parent::__construct();
        $this->load->library(['Jwt_service', 'Redisx']);
        $this->load->model('User_model');

        $this->_require_auth();
        $this->_require_active_user();
    }

    /** Langkah 1–4 middleware.RequireAuth */
    private function _require_auth() {
        $header = $this->input->get_request_header('Authorization', TRUE);
        if (empty($header)) {
            $this->fail(Api_exception::unauthorized('header Authorization tidak ada'));
        }

        $parts = explode(' ', $header, 2);
        if (count($parts) !== 2 || strcasecmp($parts[0], 'Bearer') !== 0 || $parts[1] === '') {
            $this->fail(Api_exception::unauthorized("format Authorization harus 'Bearer <token>'"));
        }
        $token = $parts[1];

        // Blocklist logout (Redis key: jwt_revoked:<token>)
        try {
            if ($this->redisx->exists('jwt_revoked:' . $token)) {
                $this->fail(Api_exception::unauthorized('sesi telah diakhiri, silakan login kembali'));
            }
        } catch (Throwable $e) {
            log_message('error', '[auth] cek blocklist gagal: ' . $e->getMessage());
            // fail-open seperti kode Go: err != nil → lanjut verifikasi token
        }

        $claims = $this->jwt_service->verify($token);
        if ($claims === NULL) {
            $this->fail(Api_exception::unauthorized('token tidak valid atau sudah kadaluarsa'));
        }

        $this->raw_token  = $token;
        $this->user_id    = (int) $claims['user_id'];
        $this->user_email = $claims['email'];
    }

    /** Padanan RequireActiveUserDB: satu query ringan per request. */
    private function _require_active_user() {
        $status = $this->User_model->get_status($this->user_id);
        if ($status === NULL) {
            $this->fail(Api_exception::unauthorized('akun tidak ditemukan, silakan login kembali'));
        }
        if ($status !== 'active') {
            $this->fail(Api_exception::forbidden('akun tidak aktif atau diblokir, hubungi admin koperasi'));
        }
    }
}

/**
 * Admin_Controller — padanan RequireAuth + RequireRole.
 *
 * CATATAN: berbeda dari Go, pemeriksaan status akun TETAP jalan di sini
 * (mewarisi Auth_Controller). Ini perbaikan disengaja — lihat §20 CACAT-02.
 */
class Admin_Controller extends Auth_Controller {

    protected $role;

    public function __construct() {
        parent::__construct();

        $allowed    = $this->config->item('roles_admin');
        $this->role = $this->User_model->get_role($this->user_id);

        if ($this->role === NULL) {
            $this->fail(Api_exception::unauthorized('akun tidak ditemukan, silakan login kembali'));
        }
        if ( ! in_array($this->role, $allowed, TRUE)) {
            $this->fail(Api_exception::forbidden());
        }
    }
}
```

### 10.4 `Api_response`

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Api_response {
    public function send($data, $status = 200) {
        $CI =& get_instance();
        return $CI->output
            ->set_status_header($status)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
```

### 10.5 `Jwt_service`

Klaim **identik** dengan Go agar token lama tetap valid saat migrasi bertahap:
`{user_id, email, iat, exp, iss:"koperasi-digital"}`, algoritma **HS256**.

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Jwt_service {
    private $secret, $ttl, $issuer;

    public function __construct() {
        $CI =& get_instance();
        $this->secret = $CI->config->item('jwt_secret');
        $this->ttl    = ((int) $CI->config->item('jwt_ttl_hours')) * 3600;
        $this->issuer = $CI->config->item('jwt_issuer');
    }

    public function issue($user_id, $email) {
        $now = time();
        return JWT::encode([
            'user_id' => (int) $user_id,
            'email'   => $email,
            'iat'     => $now,
            'exp'     => $now + $this->ttl,
            'iss'     => $this->issuer,
        ], $this->secret, 'HS256');
    }

    /** @return array|null klaim, atau null jika tidak valid */
    public function verify($token) {
        try {
            return (array) JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (Throwable $e) {
            return NULL;   // signature salah, kedaluwarsa, alg mismatch — semuanya invalid
        }
    }

    public function ttl_seconds() { return $this->ttl; }
}
```

> `firebase/php-jwt` hanya menerima algoritma yang disebut di `Key`, jadi serangan `alg: none`
> dan pergantian ke RS256 sudah tertutup — setara pengecekan `*jwt.SigningMethodHMAC` di Go.

### 10.6 `Redisx`

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Redisx {
    private $c;

    public function __construct($params = []) {
        $this->c = new Predis\Client([
            'scheme' => 'tcp',
            'host'   => env('REDIS_HOST', '127.0.0.1'),
            'port'   => (int) env('REDIS_PORT', 6379),
            // 0 = blokir selamanya; WAJIB untuk BLPOP di worker.
            'read_write_timeout' => ( ! empty($params['blocking'])) ? 0 : 3,
        ]);
    }

    public function client()                { return $this->c; }
    public function setex($k, $ttl, $v)     { return $this->c->setex($k, $ttl, $v); }
    public function get($k)                 { return $this->c->get($k); }
    public function del($k)                 { return $this->c->del([$k]); }
    public function exists($k)              { return (int) $this->c->exists($k) > 0; }
    public function rpush($k, $v)           { return $this->c->rpush($k, [$v]); }
    public function blpop($k, $timeout = 0) { return $this->c->blpop([$k], $timeout); }
    public function incr($k)                { return $this->c->incr($k); }
    public function expire($k, $s)          { return $this->c->expire($k, $s); }
}
```

**Semua operasi Redis harus tahan-gagal.** Di Go, kegagalan `RPush` hanya di-log (non-fatal)
karena transaksi DB sudah commit. Tiru sikap itu: bungkus panggilan Redis non-kritis dalam
try/catch dan log, jangan gagalkan request.

### 10.7 `Ratelimit`

Go memakai token bucket **in-memory per proses** (3 req/detik, burst 5). PHP-FPM punya banyak
proses, jadi state harus di Redis.

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Ratelimit {
    /** Maks $burst request per $window detik, per IP per bucket. */
    public function check($bucket, $burst = NULL, $window = 1) {
        $CI =& get_instance();
        $CI->load->library('Redisx');

        $burst = $burst ?? (int) $CI->config->item('rate_limit_burst');
        $ip    = $CI->input->ip_address();
        $key   = sprintf('rl:%s:%s:%d', $bucket, $ip, (int) floor(time() / $window));

        try {
            $hits = (int) $CI->redisx->incr($key);
            if ($hits === 1) { $CI->redisx->expire($key, $window + 1); }
        } catch (Throwable $e) {
            log_message('error', '[ratelimit] Redis gagal, request diloloskan: ' . $e->getMessage());
            return;   // fail-open: jangan matikan login hanya karena Redis tumbang
        }

        if ($hits > $burst) {
            throw Api_exception::tooManyRequests();
        }
    }
}
```

Pemakaian di controller publik: `$this->ratelimit->check('auth');`

### 10.8 `Validator` — padanan tag `binding` Gin

Aturan yang wajib ditiru persis (diambil dari tag `binding:` di `internal/model/`):

| Payload | Field | Aturan Go | Aturan PHP |
|---|---|---|---|
| Register | `nama_lengkap` | `required,min=3` | required, panjang ≥ 3 |
| | `email` | `required,email` | required, `FILTER_VALIDATE_EMAIL` |
| | `password` | `required,min=8` | required, panjang ≥ 8 |
| Login | `email` / `password` | `required,email` / `required` | idem |
| VerifyEmail | `email` | `required,email` | idem |
| | `otp` | `required,len=6` | required, **tepat** 6 karakter |
| UpdateProfile | `nik` | `required,len=16` | tepat 16 |
| | `phone_number` | `required,min=10,max=15` | 10–15 |
| | `address`,`job_title` | `required` | required |
| | `monthly_income` | `required,min=0` | numerik ≥ 0 |
| | `emergency_contact_name` | `required` | required |
| | `emergency_contact_phone` | `required,min=10,max=15` | 10–15 |
| OpenAccount | `savings_product_id` | `required,gt=0` | int > 0 |
| Deposit | `account_id` | `required,gt=0` | int > 0 |
| | `amount` | `required,gt=0` | numerik > 0 |
| | `payment_method` | `required` | required |
| | `proof_image_url`,`reference_id` | opsional | opsional, default `''` |
| ApplyFinancing | `principal_amount` | `required,gt=0` | numerik > 0 |
| | `duration_months` | `required,min=1,max=360` | int 1–360 |
| ReviewFinancing | `action` | `required,oneof=approve reject` | in `[approve,reject]` |
| PayInstallment | `savings_account_id` | `required,gt=0` | int > 0 |
| BuyGold / SellGold | `gram_amount` | `required,min=0.0001` | numerik ≥ 0.0001 |
| | `savings_account_id` | `required,gt=0` | int > 0 |
| ReviewDeposit | `action` | `required,oneof=approve reject` | in `[approve,reject]` |

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Validator {
    /**
     * @param array $body   payload JSON
     * @param array $rules  ['field' => ['required','email','min:8', ...]]
     * @return array        nilai yang sudah dibersihkan
     * @throws Api_exception 400 pada pelanggaran pertama (meniru ShouldBindJSON)
     */
    public function check(array $body, array $rules) {
        $out = [];
        foreach ($rules as $field => $set) {
            $val      = $body[$field] ?? NULL;
            $required = in_array('required', $set, TRUE);

            if ($required && ($val === NULL || $val === '')) {
                throw Api_exception::badRequest("field '{$field}' wajib diisi");
            }
            if ( ! $required && ($val === NULL || $val === '')) { $out[$field] = ''; continue; }

            foreach ($set as $rule) {
                if ($rule === 'required') continue;
                $parts = array_pad(explode(':', $rule, 2), 2, NULL);
                $name  = $parts[0];
                $arg   = $parts[1];

                switch ($name) {
                    case 'email':
                        if ( ! filter_var($val, FILTER_VALIDATE_EMAIL))
                            throw Api_exception::badRequest("field '{$field}' bukan email yang valid");
                        break;
                    case 'min':
                        if (mb_strlen((string)$val) < (int)$arg)
                            throw Api_exception::badRequest("field '{$field}' minimal {$arg} karakter");
                        break;
                    case 'max':
                        if (mb_strlen((string)$val) > (int)$arg)
                            throw Api_exception::badRequest("field '{$field}' maksimal {$arg} karakter");
                        break;
                    case 'len':
                        if (mb_strlen((string)$val) !== (int)$arg)
                            throw Api_exception::badRequest("field '{$field}' harus tepat {$arg} karakter");
                        break;
                    case 'int_gt':
                        if ( ! ctype_digit((string)$val) || (int)$val <= (int)$arg)
                            throw Api_exception::badRequest("field '{$field}' harus bilangan bulat lebih dari {$arg}");
                        $val = (int) $val;
                        break;
                    case 'int_between':
                        $b  = explode(',', $arg);
                        if ( ! is_numeric($val) || (int)$val < (int)$b[0] || (int)$val > (int)$b[1])
                            throw Api_exception::badRequest("field '{$field}' harus antara {$b[0]} dan {$b[1]}");
                        $val = (int) $val;
                        break;
                    case 'num_gt':
                        if ( ! is_numeric($val) || Money::cmp($val, $arg) !== 1)
                            throw Api_exception::badRequest("field '{$field}' harus lebih dari {$arg}");
                        $val = Money::norm($val);
                        break;
                    case 'num_gte':
                        if ( ! is_numeric($val) || Money::cmp($val, $arg) === -1)
                            throw Api_exception::badRequest("field '{$field}' minimal {$arg}");
                        $val = Money::norm($val);
                        break;
                    case 'in':
                        if ( ! in_array($val, explode(',', $arg), TRUE))
                            throw Api_exception::badRequest("field '{$field}' harus salah satu dari: {$arg}");
                        break;
                }
            }
            $out[$field] = $val;
        }
        return $out;
    }
}
```

### 10.9 Pola DB transaction + row locking (WAJIB dihafal)

Setiap operasi saldo memakai pola identik. Ini templatnya:

```php
/**
 * Template transaksi atomik CI3 — padanan:
 *   tx, _ := db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
 *   defer tx.Rollback()
 *   ... SELECT FOR UPDATE ... UPDATE ... INSERT ...
 *   tx.Commit()
 */
public function contoh_operasi_saldo($account_id, $amount, $user_id) {

    $this->db->trans_strict(FALSE);   // kita kendalikan commit/rollback sendiri
    $this->db->trans_begin();

    try {
        // --- 1. Kunci baris rekening. FOR UPDATE menahan transaksi lain sampai
        //        kita commit/rollback. Tanpa ini dua request paralel bisa
        //        sama-sama membaca saldo lama → double-spend.
        $row = $this->db->query(
            "SELECT user_id, balance, status
               FROM savings_accounts
              WHERE id = ?
              FOR UPDATE", [$account_id])->row_array();

        if ( ! $row)                                 throw Api_exception::savingsAccountNotFound();
        if ((int)$row['user_id'] !== (int)$user_id)  throw Api_exception::savingsAccountNotFound(); // 404, bukan 403
        if ($row['status'] !== 'active')             throw Api_exception::accountNotActive();
        if (Money::lt($row['balance'], $amount))     throw Api_exception::insufficientBalance();

        // --- 2. Update saldo dengan ekspresi relatif (balance - ?), bukan nilai absolut.
        //        DB yang menghitung → tidak ada lost update.
        $this->db->query(
            "UPDATE savings_accounts
                SET balance = balance - ?, updated_at = NOW()
              WHERE id = ?", [$amount, $account_id]);

        // --- 3. Catat di buku besar (append-only)
        $this->db->query(
            "INSERT INTO savings_transactions (savings_account_id, type, amount, reference_id)
             VALUES (?, 'withdraw', ?, ?)", [$account_id, $amount, 'ref_xxx']);

        // --- 4. Commit hanya jika SEMUA langkah lolos
        if ($this->db->trans_status() === FALSE) {
            $this->db->trans_rollback();
            throw Api_exception::server();
        }
        $this->db->trans_commit();

    } catch (Throwable $e) {
        $this->db->trans_rollback();     // safety net, padanan `defer tx.Rollback()`
        throw $e;
    }
}
```

**Lima aturan yang tidak boleh dilanggar:**

1. `trans_begin()` bukan `trans_start()` — `trans_start()` auto-commit dan menyembunyikan kegagalan.
2. `trans_strict(FALSE)` supaya satu transaction gagal tidak mematikan transaction berikutnya di request yang sama.
3. **Validasi selalu SETELAH `FOR UPDATE`**, tidak sebelum. Membaca status sebelum mengunci = membaca data basi.
4. `UPDATE ... SET balance = balance ± ?` — jangan pernah `SET balance = ?` dengan nilai yang dihitung di PHP.
5. Setiap `throw` di dalam blok harus melewati `trans_rollback()` — pakai try/catch, bukan `return` telanjang.

### 10.10 Menangkap pelanggaran unique constraint

Padanan `pq.Error.Code == "23505"`:

```php
/** @return bool TRUE jika error terakhir adalah pelanggaran unique constraint */
protected function is_unique_violation() {
    $err = $this->db->error();               // ['code' => ..., 'message' => ...]
    return in_array((string) $err['code'], ['23505', '1062'], TRUE)
        || stripos($err['message'], 'duplicate key')  !== FALSE
        || stripos($err['message'], 'Duplicate entry') !== FALSE;
}
```

Ini hanya bekerja jika `db_debug = FALSE` (§8.2), sehingga CI3 tidak menghentikan eksekusi sendiri.

### 10.11 Mengambil `id` hasil INSERT

```php
// PostgreSQL — satu round-trip, aman dari race
$row = $this->db->query(
    "INSERT INTO gold_transactions (user_id, type, gram_amount, price_per_gram, total_rupiah, status)
     VALUES (?, 'buy', ?, ?, ?, 'pending')
     RETURNING id, created_at",
    [$user_id, $gram, $price, $total])->row_array();
$gold_tx_id = (int) $row['id'];

// MySQL — dua langkah
$this->db->query("INSERT INTO gold_transactions (...) VALUES (...)", [...]);
$gold_tx_id = (int) $this->db->insert_id();
```

### 10.12 `Email_service`

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use PHPMailer\PHPMailer\PHPMailer;

class Email_service {
    /**
     * Padanan smtpEmailService.SendOTPEmail.
     * SMTP_HOST kosong → mode simulasi (tulis ke log), TIDAK melempar error.
     * Kegagalan kirim email TIDAK boleh menggagalkan registrasi.
     */
    public function send_otp($to, $otp) {
        $host = env('SMTP_HOST');
        if (empty($host)) {
            log_message('info', "[EMAIL SIMULATION] OTP {$otp} untuk {$to}");
            return TRUE;
        }

        try {
            $m = new PHPMailer(TRUE);
            $m->isSMTP();
            $m->Host       = $host;
            $m->Port       = (int) env('SMTP_PORT', 587);
            $m->SMTPAuth   = TRUE;
            $m->Username   = env('SMTP_USER');
            $m->Password   = env('SMTP_PASSWORD');
            $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $m->CharSet    = 'UTF-8';

            $m->setFrom(env('SMTP_FROM_EMAIL', 'noreply@koperasi-digital.com'), 'Koperasi Digital');
            $m->addAddress($to);
            $m->isHTML(TRUE);
            $m->Subject = 'Kode Verifikasi Koperasi Digital';
            $m->Body    = '<h2>Selamat Datang di Koperasi Digital</h2>'
                        . '<p>Berikut adalah kode verifikasi OTP Anda:</p>'
                        . '<h1 style="color:blue;">' . htmlspecialchars($otp) . '</h1>'
                        . '<p>Kode ini berlaku selama 15 menit. Jangan berikan kode ini kepada siapa pun.</p>';
            $m->send();
            return TRUE;

        } catch (Throwable $e) {
            log_message('error', "[email] gagal mengirim OTP ke {$to}: " . $e->getMessage());
            return FALSE;   // non-fatal, sama seperti kode Go
        }
    }
}
```

---
---

# BAGIAN C — FLOW PER MODUL (STEP BY STEP)

Setiap flow ditulis dengan format seragam:
**Endpoint → Guard → Payload → Algoritma langkah-demi-langkah → SQL/transaction →
Response sukses → Tabel error → Catatan implementasi CI3.**

---

## 11. Modul 1: Autentikasi & Akun

### 11.1 FLOW-01 — Registrasi Anggota

**Endpoint** `POST /api/v1/register`
**Guard** Rate limit (3 rps, burst 5 per IP). Tanpa JWT.

**Payload**

```json
{ "nama_lengkap": "Budi Santoso", "email": "budi@mail.com", "password": "rahasia123" }
```

**Algoritma (persis seperti `userService.Register` + `UserHandler.Register`)**

| # | Langkah | Detail | Kegagalan |
|---|---|---|---|
| 1 | Rate limit | per IP | 429 |
| 2 | Validasi | nama ≥3, email valid, password ≥8 | 400 |
| 3 | Hash password | bcrypt **cost 12** | 500 |
| 4 | INSERT users | `RETURNING id, role, wallet_address, status, is_email_verified, created_at, updated_at` | unique violation → **409** |
| 5 | Generate OTP | 6 digit acak **kriptografis**, zero-padded (`000123` valid) | 500 |
| 6 | Simpan OTP di Redis | key `otp:{email}`, TTL **900 detik** | 500 |
| 7 | Kirim email OTP | **non-fatal** — gagal hanya di-log | — |
| 8 | Buka rekening wajib | untuk setiap produk `is_mandatory = TRUE`, buat rekening `balance=0, status='active'` | **non-fatal** di Go; sebaiknya di-log |
| 9 | Response | `201` | |

**Response 201**

```json
{
  "message": "Registrasi berhasil, OTP telah dikirim ke email Anda. Silakan verifikasi untuk login.",
  "user_id": 12
}
```

> **Tidak ada token di response registrasi.** Token baru terbit setelah `verify-email`.

**Tabel error**

| Kondisi | Status | Body |
|---|---|---|
| Validasi gagal | 400 | pesan field |
| Email sudah ada | 409 | `email sudah terdaftar` |
| Redis mati saat simpan OTP | 500 | `terjadi kesalahan pada server` ⚠️ user sudah tersimpan — lihat CACAT-03 |
| Rate limit | 429 | |

**Implementasi CI3**

`libraries/User_service.php`:

```php
public function register(array $in) {
    $CI =& get_instance();

    // 3. Hash password — cost 12 sama dengan Go
    $hash = password_hash($in['password'], PASSWORD_BCRYPT, [
        'cost' => (int) $CI->config->item('bcrypt_cost'),
    ]);

    // 4. Insert user (melempar Api_exception::emailExists() saat unique violation)
    $user = $CI->User_model->insert($in['nama_lengkap'], $in['email'], $hash);

    // 5. OTP 6 digit kriptografis, zero-padded
    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // 6. Simpan di Redis (TTL 15 menit)
    try {
        $CI->redisx->setex('otp:' . $in['email'], (int) $CI->config->item('otp_ttl_seconds'), $otp);
    } catch (Throwable $e) {
        log_message('error', '[register] gagal menyimpan OTP: ' . $e->getMessage());
        throw Api_exception::server();
    }

    // 7. Kirim email — non-fatal
    $CI->email_service->send_otp($in['email'], $otp);

    return $user;
}
```

`libraries/Saving_service.php`:

```php
/** Padanan OpenMandatoryAccounts — buka rekening untuk semua produk is_mandatory. */
public function open_mandatory_accounts($user_id) {
    $CI =& get_instance();
    foreach ($CI->Saving_model->get_mandatory_products() as $p) {
        $CI->Saving_model->create_account($user_id, (int) $p['id']);
    }
}
```

`controllers/api/v1/Auth.php`:

```php
public function register() {
    $this->run(function () {
        $this->ratelimit->check('auth');

        $in = $this->validator->check($this->body, [
            'nama_lengkap' => ['required', 'min:3'],
            'email'        => ['required', 'email'],
            'password'     => ['required', 'min:8'],
        ]);

        $user = $this->user_service->register($in);

        // 8. Rekening wajib — kegagalan tidak membatalkan registrasi
        try {
            $this->saving_service->open_mandatory_accounts((int) $user['id']);
        } catch (Throwable $e) {
            log_message('error', '[register] gagal membuat rekening wajib user_id=' . $user['id'] . ': ' . $e->getMessage());
        }

        return $this->ok([
            'message' => 'Registrasi berhasil, OTP telah dikirim ke email Anda. Silakan verifikasi untuk login.',
            'user_id' => (int) $user['id'],
        ], 201);
    });
}
```

---

### 11.2 FLOW-02 — Verifikasi Email (OTP)

**Endpoint** `POST /api/v1/verify-email` · rate-limited · tanpa JWT

**Payload** `{ "email": "budi@mail.com", "otp": "482913" }`

**Algoritma**

| # | Langkah | Kegagalan |
|---|---|---|
| 1 | Validasi (`otp` tepat 6 karakter) | 400 |
| 2 | `GET otp:{email}` dari Redis | key tidak ada → **400** `kode OTP sudah kedaluwarsa atau tidak valid` |
| 3 | Bandingkan dengan input | tidak cocok → **400** `kode OTP salah` |
| 4 | `DEL otp:{email}` — OTP sekali pakai | — |
| 5 | Ambil user by email | tidak ada → **404** |
| 6 | `UPDATE users SET is_email_verified = TRUE, updated_at = NOW()` | 500 |
| 7 | Terbitkan JWT (**auto-login**) | 500 |

**Response 200** `{ "token": "eyJ...", "user": { ...tanpa password_hash... } }`

**Implementasi**

```php
public function verify_email() {
    $this->run(function () {
        $this->ratelimit->check('auth');

        $in = $this->validator->check($this->body, [
            'email' => ['required', 'email'],
            'otp'   => ['required', 'len:6'],
        ]);

        $key    = 'otp:' . $in['email'];
        $stored = $this->redisx->get($key);

        if ($stored === NULL) {
            throw Api_exception::badRequest('kode OTP sudah kedaluwarsa atau tidak valid');
        }
        // hash_equals mencegah timing attack — perbaikan atas perbandingan '!=' di Go
        if ( ! hash_equals((string) $stored, (string) $in['otp'])) {
            throw Api_exception::badRequest('kode OTP salah');
        }
        $this->redisx->del($key);

        $user = $this->User_model->find_by_email($in['email']);
        if ( ! $user) { throw Api_exception::userNotFound(); }

        $this->User_model->mark_email_verified((int) $user['id']);
        $user['is_email_verified'] = TRUE;

        return $this->ok([
            'token' => $this->jwt_service->issue($user['id'], $user['email']),
            'user'  => $this->User_model->to_public($user),
        ], 200);
    });
}
```

> **`to_public()` wajib** — buang `password_hash` dari setiap payload user. Di Go itu otomatis
> lewat tag `json:"-"`; di PHP harus eksplisit, kalau tidak hash bcrypt bocor ke frontend.

---

### 11.3 FLOW-03 — Login

**Endpoint** `POST /api/v1/login` · rate-limited

**Urutan pemeriksaan (urutannya penting dan harus dipertahankan)**

| # | Cek | Gagal → |
|---|---|---|
| 1 | Validasi payload | 400 |
| 2 | Cari user by email | **401 generik** (jangan bocorkan email tidak terdaftar) |
| 3 | `password_verify` | **401 generik** |
| 4 | `is_email_verified` | **403** `email belum diverifikasi...` |
| 5 | `status === 'active'` | **403** `akun tidak aktif atau diblokir...` |
| 6 | Terbitkan JWT | 200 |

> Perhatikan: password diverifikasi **sebelum** cek verifikasi email dan status. Ini disengaja —
> tanpa password yang benar, penyerang tidak boleh tahu apakah suatu akun ada, terverifikasi, atau diblokir.

```php
public function login() {
    $this->run(function () {
        $this->ratelimit->check('auth');

        $in = $this->validator->check($this->body, [
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = $this->User_model->find_by_email($in['email']);
        if ( ! $user)                                                  throw Api_exception::invalidCredentials();
        if ( ! password_verify($in['password'], $user['password_hash'])) throw Api_exception::invalidCredentials();
        if ( ! $this->User_model->truthy($user['is_email_verified']))  throw Api_exception::emailNotVerified();
        if ($user['status'] !== 'active')                              throw Api_exception::accountSuspended();

        return $this->ok([
            'token' => $this->jwt_service->issue($user['id'], $user['email']),
            'user'  => $this->User_model->to_public($user),
        ], 200);
    });
}
```

> `truthy()` diperlukan karena PostgreSQL mengembalikan boolean sebagai `'t'`/`'f'` dan MySQL sebagai `1`/`0`:
> ```php
> public function truthy($v) { return $v === TRUE || $v === 1 || $v === '1' || $v === 't' || $v === 'true'; }
> ```
> Ini sumber bug klasik saat port: `if ($user['is_email_verified'])` bernilai TRUE untuk string `'f'`.

---

### 11.4 FLOW-04 — Logout (blocklist token)

**Endpoint** `POST /api/v1/logout` · JWT + akun aktif

JWT bersifat stateless, jadi logout diimplementasikan dengan **blocklist Redis**:
token disimpan sebagai key `jwt_revoked:{token}` dengan TTL = sisa masa berlaku token
(kode Go memakai TTL penuh 24 jam — cukup, karena setelah exp token invalid dengan sendirinya).

```php
public function logout() {
    $this->run(function () {
        // $this->raw_token sudah diambil Auth_Controller
        $this->redisx->setex('jwt_revoked:' . $this->raw_token, $this->jwt_service->ttl_seconds(), 'revoked');
        return $this->ok(['message' => 'logout berhasil'], 200);
    });
}
```

Pemeriksaan blocklist ada di `Auth_Controller::_require_auth()` — dijalankan **sebelum**
verifikasi signature, sama seperti Go.

---

### 11.5 FLOW-05 — Profil Pengguna

**Endpoint** `GET /api/v1/profile` · JWT + akun aktif

```php
public function index() {
    $this->run(function () {
        $user = $this->User_model->find_by_id($this->user_id);
        if ( ! $user)                     throw Api_exception::userNotFound();
        if ($user['status'] !== 'active') throw Api_exception::accountSuspended();
        return $this->ok($this->User_model->to_public($user), 200);
    });
}
```

`User_model` inti:

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class User_model extends CI_Model {

    const COLS = 'id, nama_lengkap, email, password_hash, role, wallet_address,
                  status, is_email_verified, created_at, updated_at';

    public function insert($nama, $email, $hash) {
        $sql = "INSERT INTO users (nama_lengkap, email, password_hash)
                VALUES (?, ?, ?)
                RETURNING id, role, wallet_address, status, is_email_verified, created_at, updated_at";
        $q = $this->db->query($sql, [$nama, $email, $hash]);

        if ($q === FALSE) {
            if ($this->is_unique_violation()) { throw Api_exception::emailExists(); }
            log_message('error', '[user_model] insert gagal: ' . json_encode($this->db->error()));
            throw Api_exception::server();
        }
        $row = $q->row_array();
        return array_merge($row, ['nama_lengkap' => $nama, 'email' => $email]);
    }

    public function find_by_email($email) {
        return $this->db->query("SELECT " . self::COLS . " FROM users WHERE email = ? LIMIT 1", [$email])->row_array();
    }

    public function find_by_id($id) {
        return $this->db->query("SELECT " . self::COLS . " FROM users WHERE id = ? LIMIT 1", [$id])->row_array();
    }

    /** Query super-ringan untuk middleware. */
    public function get_status($id) {
        $r = $this->db->query("SELECT status FROM users WHERE id = ? LIMIT 1", [$id])->row_array();
        return $r ? $r['status'] : NULL;
    }

    public function get_role($id) {
        $r = $this->db->query("SELECT role FROM users WHERE id = ? LIMIT 1", [$id])->row_array();
        return $r ? $r['role'] : NULL;
    }

    public function mark_email_verified($id) {
        $this->db->query("UPDATE users SET is_email_verified = TRUE, updated_at = NOW() WHERE id = ?", [$id]);
    }

    public function get_all() {
        $rows = $this->db->query("SELECT " . self::COLS . " FROM users ORDER BY created_at DESC")->result_array();
        return array_map([$this, 'to_public'], $rows);
    }

    /** Buang password_hash + normalisasi tipe. Padanan tag json:"-" di Go. */
    public function to_public(array $u) {
        unset($u['password_hash']);
        $u['id']                = (int) $u['id'];
        $u['is_email_verified'] = $this->truthy($u['is_email_verified']);
        return $u;
    }

    public function truthy($v) {
        return $v === TRUE || $v === 1 || $v === '1' || $v === 't' || $v === 'true';
    }

    private function is_unique_violation() {
        $e = $this->db->error();
        return in_array((string) $e['code'], ['23505', '1062'], TRUE)
            || stripos($e['message'], 'duplicate') !== FALSE;
    }
}
```

---

## 12. Modul 2: Profil KYC

### 12.1 FLOW-06 — Simpan/Perbarui KYC

**Endpoint** `PUT /api/v1/profile/kyc` · JWT + akun aktif

Operasi **upsert** — satu endpoint untuk create dan update, memanfaatkan `ON CONFLICT (user_id) DO UPDATE`.

**Payload** (semua wajib)

```json
{
  "nik": "3201234567890123",
  "phone_number": "081234567890",
  "address": "Jl. Merdeka No. 1, Bandung",
  "job_title": "Wiraswasta",
  "monthly_income": 7500000,
  "emergency_contact_name": "Siti",
  "emergency_contact_phone": "081298765432"
}
```

```php
public function update_kyc() {
    $this->run(function () {
        $in = $this->validator->check($this->body, [
            'nik'                     => ['required', 'len:16'],
            'phone_number'            => ['required', 'min:10', 'max:15'],
            'address'                 => ['required'],
            'job_title'               => ['required'],
            'monthly_income'          => ['required', 'num_gte:0'],
            'emergency_contact_name'  => ['required'],
            'emergency_contact_phone' => ['required', 'min:10', 'max:15'],
        ]);

        $this->User_profile_model->upsert($this->user_id, $in);
        return $this->ok(['message' => 'profil KYC berhasil disimpan'], 200);
    });
}
```

```php
public function upsert($user_id, array $p) {
    $sql = "INSERT INTO user_profiles
              (user_id, nik, phone_number, address, job_title,
               monthly_income, emergency_contact_name, emergency_contact_phone)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (user_id) DO UPDATE SET
              nik = EXCLUDED.nik,
              phone_number = EXCLUDED.phone_number,
              address = EXCLUDED.address,
              job_title = EXCLUDED.job_title,
              monthly_income = EXCLUDED.monthly_income,
              emergency_contact_name = EXCLUDED.emergency_contact_name,
              emergency_contact_phone = EXCLUDED.emergency_contact_phone,
              updated_at = NOW()";

    $ok = $this->db->query($sql, [
        $user_id, $p['nik'], $p['phone_number'], $p['address'], $p['job_title'],
        $p['monthly_income'], $p['emergency_contact_name'], $p['emergency_contact_phone'],
    ]);

    if ($ok === FALSE) {
        // NIK UNIQUE — dipakai anggota lain
        $e = $this->db->error();
        if (in_array((string)$e['code'], ['23505','1062'], TRUE)) {
            throw new Api_exception('NIK_TAKEN', 'NIK sudah terdaftar pada akun lain', 409);
        }
        throw Api_exception::server();
    }
}
```

> Kode Go **tidak** menangani unique violation pada `nik` — pelanggarannya jatuh ke 500.
> Penanganan 409 di atas adalah perbaikan yang layak dibawa ke versi PHP.

**MySQL**: ganti klausa `ON CONFLICT ... DO UPDATE SET x = EXCLUDED.x` menjadi
`ON DUPLICATE KEY UPDATE x = VALUES(x)`.

### 12.2 FLOW-07 — Baca KYC

**Endpoint** `GET /api/v1/profile/kyc`

Perilaku khusus yang **wajib dipertahankan**: bila profil belum pernah diisi, kembalikan
**200 dengan objek kosong**, bukan 404 — frontend memakai response ini untuk merender form kosong.

```php
public function get_kyc() {
    $this->run(function () {
        $p = $this->User_profile_model->find($this->user_id);
        if ( ! $p) {
            return $this->ok([
                'user_id' => 0, 'nik' => '', 'phone_number' => '', 'address' => '',
                'job_title' => '', 'monthly_income' => 0,
                'emergency_contact_name' => '', 'emergency_contact_phone' => '',
                'created_at' => NULL, 'updated_at' => NULL,
            ], 200);
        }
        return $this->ok($p, 200);
    });
}
```

---

## 13. Modul 3: Simpanan Syariah

### 13.1 FLOW-08 — Buka Rekening

**Endpoint** `POST /api/v1/savings/accounts` · JWT + aktif
**Payload** `{ "savings_product_id": 3 }`

| # | Langkah | Gagal |
|---|---|---|
| 1 | Validasi `savings_product_id` int > 0 | 400 |
| 2 | Pastikan produk ada | 404 `produk simpanan tidak ditemukan` |
| 3 | INSERT rekening `balance = 0, status = 'active'` | 500 |
| 4 | Response `201` berisi objek rekening | |

> Tidak ada pencegahan rekening ganda: satu anggota **boleh** punya beberapa rekening pada
> produk yang sama. Ini perilaku Go saat ini; jika ingin dibatasi, tambahkan
> `UNIQUE(user_id, savings_product_id)` — tapi ingat, itu akan menggagalkan
> `open_mandatory_accounts` bila dijalankan dua kali.

```php
public function create_account($user_id, $product_id) {
    $sql = "INSERT INTO savings_accounts (user_id, savings_product_id, balance, status)
            VALUES (?, ?, 0, 'active')
            RETURNING id, balance, status, created_at, updated_at";
    $row = $this->db->query($sql, [$user_id, $product_id])->row_array();
    if ( ! $row) { throw Api_exception::server(); }

    return [
        'id'                 => (int) $row['id'],
        'user_id'            => (int) $user_id,
        'savings_product_id' => (int) $product_id,
        'balance'            => Money::out($row['balance']),
        'status'             => $row['status'],
        'created_at'         => $row['created_at'],
        'updated_at'         => $row['updated_at'],
    ];
}
```

### 13.2 FLOW-09 — Daftar Rekening

**Endpoint** `GET /api/v1/savings/accounts`
Query: `WHERE user_id = ? ORDER BY created_at DESC`.
Response: `{ "accounts": [ ... ] }` — **array kosong**, bukan `null`, jika belum ada rekening.

```php
public function get_accounts_by_user($user_id) {
    $rows = $this->db->query(
        "SELECT id, user_id, savings_product_id, balance, status, created_at, updated_at
           FROM savings_accounts WHERE user_id = ? ORDER BY created_at DESC", [$user_id])->result_array();

    return array_map(function ($a) {
        $a['id']                 = (int) $a['id'];
        $a['user_id']            = (int) $a['user_id'];
        $a['savings_product_id'] = (int) $a['savings_product_id'];
        $a['balance']            = Money::out($a['balance']);
        return $a;
    }, $rows);
}
```

### 13.3 FLOW-10 — Ajukan Setoran (Deposit Request)

**Endpoint** `POST /api/v1/savings/deposit` · JWT + aktif

**Penting**: endpoint ini **tidak menambah saldo**. Ia hanya membuat *permohonan* berstatus
`pending` yang menunggu verifikasi admin. Saldo baru berubah di FLOW-11.

**Payload**

```json
{
  "account_id": 5,
  "amount": 100000,
  "payment_method": "manual_transfer",
  "proof_image_url": "https://.../bukti.jpg",
  "reference_id": "TRF-20260823-001"
}
```

| # | Langkah | Gagal |
|---|---|---|
| 1 | Validasi payload | 400 |
| 2 | Ambil rekening by id | 404 |
| 3 | **Cek kepemilikan** `account.user_id === user_id` | 404 (bukan 403) |
| 4 | Ambil produk rekening, cek `amount >= min_deposit` | 422 + nominal minimum |
| 5 | INSERT `deposit_requests` status `pending` | 500 |
| 6 | Response `201` objek permohonan | |

```php
public function deposit_fund($user_id, array $in) {
    $CI =& get_instance();

    $acc = $CI->Saving_model->get_account_by_id((int) $in['account_id']);
    if ( ! $acc)                                    throw Api_exception::savingsAccountNotFound();
    if ((int) $acc['user_id'] !== (int) $user_id)   throw Api_exception::savingsAccountNotFound();

    $product = $CI->Saving_model->find_product((int) $acc['savings_product_id']);
    if ( ! $product)                                throw Api_exception::savingsProductNotFound();

    if (Money::lt($in['amount'], $product['min_deposit'])) {
        throw Api_exception::depositBelowMinimum($product['min_deposit']);
    }

    return $CI->Deposit_request_model->insert([
        'user_id'            => $user_id,
        'savings_account_id' => (int) $in['account_id'],
        'amount'             => $in['amount'],
        'payment_method'     => $in['payment_method'],
        'proof_image_url'    => $in['proof_image_url'] ?? '',
        'reference_id'       => $in['reference_id'] ?? '',
    ]);
}
```

> Kode Go **tidak** memeriksa `account.status === 'active'` di titik ini (hanya nanti saat approve).
> Sebaiknya tambahkan pemeriksaan di sini juga agar anggota tidak mengajukan setoran
> ke rekening beku dan baru ditolak berhari-hari kemudian.

### 13.4 FLOW-11 — Admin Verifikasi Setoran ⚠️ TITIK KRITIS

**Endpoint** `PUT /api/v1/admin/savings/deposit-requests/:id/review` · JWT + role pengurus+
**Payload** `{ "action": "approve" }` atau `{ "action": "reject" }`

**Alur di kode Go (dan cacatnya)**

```
1. Ambil deposit_request by id            → 404 jika tidak ada
2. Jika status != 'pending'               → 422 "sudah direview sebelumnya"
3a. action = reject:
      UPDATE deposit_requests SET status='rejected', reviewed_by, reviewed_at, updated_at
3b. action = approve:
      [TRANSAKSI A]  savingRepo.Deposit():
          SELECT status FROM savings_accounts WHERE id=? FOR UPDATE
          jika status != 'active' → error
          UPDATE savings_accounts SET balance = balance + amount
          INSERT savings_transactions (deposit, amount, reference_id)
          COMMIT
      [TRANSAKSI B]  UPDATE deposit_requests SET status='approved', ...
```

⚠️ **Dua transaksi terpisah.** Jika proses mati di antara A dan B, saldo bertambah tapi
permohonan tetap `pending` — admin bisa meng-approve lagi dan saldo bertambah **dua kali**.

**Di versi CI3, satukan menjadi satu transaction:**

```php
public function review_deposit($admin_id, $request_id, $action) {
    $this->db->trans_strict(FALSE);
    $this->db->trans_begin();

    try {
        // --- 1. Kunci baris permohonan. FOR UPDATE mencegah dua admin
        //        meng-approve permohonan yang sama secara bersamaan.
        $req = $this->db->query(
            "SELECT id, savings_account_id, amount, status, reference_id
               FROM deposit_requests WHERE id = ? FOR UPDATE", [$request_id])->row_array();

        if ( ! $req)                      throw Api_exception::depositRequestNotFound();
        if ($req['status'] !== 'pending') throw Api_exception::depositAlreadyReviewed();

        if ($action === 'reject') {
            $this->db->query(
                "UPDATE deposit_requests
                    SET status='rejected', reviewed_by=?, reviewed_at=NOW(), updated_at=NOW()
                  WHERE id=?", [$admin_id, $request_id]);
        } else {
            // --- 2. Kunci rekening & validasi status
            $acc = $this->db->query(
                "SELECT status FROM savings_accounts WHERE id = ? FOR UPDATE",
                [$req['savings_account_id']])->row_array();

            if ( ! $acc)                     throw Api_exception::savingsAccountNotFound();
            if ($acc['status'] !== 'active') throw Api_exception::accountNotActive();

            // --- 3. Tambah saldo (ekspresi relatif)
            $this->db->query(
                "UPDATE savings_accounts SET balance = balance + ?, updated_at = NOW() WHERE id = ?",
                [$req['amount'], $req['savings_account_id']]);

            // --- 4. Catat di buku besar
            $this->db->query(
                "INSERT INTO savings_transactions (savings_account_id, type, amount, reference_id)
                 VALUES (?, 'deposit', ?, ?)",
                [$req['savings_account_id'], $req['amount'], (string) $req['reference_id']]);

            // --- 5. Tandai permohonan approved — DALAM TRANSACTION YANG SAMA
            $this->db->query(
                "UPDATE deposit_requests
                    SET status='approved', reviewed_by=?, reviewed_at=NOW(), updated_at=NOW()
                  WHERE id=?", [$admin_id, $request_id]);
        }

        if ($this->db->trans_status() === FALSE) {
            $this->db->trans_rollback();
            throw Api_exception::server();
        }
        $this->db->trans_commit();

    } catch (Throwable $e) {
        $this->db->trans_rollback();
        throw $e;
    }
}
```

**Response 200** `{ "message": "review setoran berhasil disimpan" }`

**Tabel error**

| Kondisi | Status |
|---|---|
| ID tidak valid | 400 |
| `action` bukan approve/reject | 400 |
| Permohonan tidak ada | 404 |
| Sudah direview | 422 |
| Rekening tidak aktif | 422 |

### 13.5 FLOW-12 — Riwayat Permohonan Setoran

- Anggota: `GET /savings/deposit-requests` → `WHERE user_id = ? ORDER BY created_at DESC`
- Admin: `GET /admin/savings/deposit-requests` → semua baris, `ORDER BY created_at DESC`

Keduanya membungkus hasil dalam `{ "deposit_requests": [...] }`.

---

## 14. Modul 4: Pembiayaan Murabahah

### 14.1 FLOW-13 — Pengajuan Pembiayaan

**Endpoint** `POST /api/v1/financing/apply` · JWT + aktif
**Payload** `{ "principal_amount": 10000000, "duration_months": 12 }`

**Perhitungan (inti akad murabahah)**

```
margin_amount  = round4(principal_amount × MURABAHAH_MARGIN_RATE)    # default 0.10
total_payable  = round4(principal_amount + margin_amount)
```

Contoh: principal 10 000 000, rate 0.10 → margin 1 000 000, total 11 000 000.

**Nomor akad**: `FIN-MRB-{unix_nano}-{attempt}` dengan **retry maksimal 3×** bila terjadi
unique violation. PHP tidak punya nanosecond presisi seperti Go; gunakan gabungan
`hrtime()` + `random_int` yang tetap monoton-ish dan sangat kecil peluang bentroknya.

```php
public function apply_murabahah($user_id, array $in) {
    $CI   =& get_instance();
    $rate = (string) $CI->config->item('murabahah_margin_rate');

    $margin = Money::mul($in['principal_amount'], $rate);
    $total  = Money::add($in['principal_amount'], $margin);

    // Retry 3× untuk kasus tabrakan financing_number (sangat jarang).
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $number = sprintf('FIN-MRB-%d-%d-%d', time(), hrtime(TRUE) % 1000000, $attempt);
        try {
            return $CI->Financing_model->create([
                'financing_number' => $number,
                'user_id'          => $user_id,
                'akad'             => 'murabahah',
                'principal_amount' => $in['principal_amount'],
                'margin_amount'    => $margin,
                'total_payable'    => $total,
                'duration_months'  => (int) $in['duration_months'],
                'status'           => 'pending',
            ]);
        } catch (Api_exception $e) {
            if ($e->code_name !== 'DUPLICATE_FINANCING_NUMBER') { throw $e; }
            // tabrakan → ulangi dengan nomor baru
        }
    }
    throw new Api_exception('FINANCING_NUMBER_BUSY',
        'sistem sibuk membuat nomor pembiayaan, silakan coba lagi', 503);
}
```

**Response 201** — objek financing lengkap dengan `status: "pending"`, `reviewed_by: null`.

> Handler Go memetakan **semua** error dari service ke 500 di endpoint ini. Versi PHP di atas
> lebih baik: mengembalikan 503 untuk kasus nomor sibuk.

### 14.2 FLOW-14 — Daftar Pembiayaan Saya

`GET /api/v1/financing` → `WHERE user_id = ? ORDER BY created_at DESC`,
dibungkus `{ "financings": [...] }`, array kosong bila belum ada.

### 14.3 FLOW-15 — Admin Review Pembiayaan ⚠️ TITIK KRITIS

**Endpoint** `PUT /api/v1/admin/financing/:id/review` · JWT + role pengurus+
**Payload** `{ "action": "approve" }` / `{ "action": "reject" }`

| # | Langkah | Gagal |
|---|---|---|
| 1 | Parse `:id` (int > 0) | 400 |
| 2 | Validasi `action` | 400 |
| 3 | Ambil financing | 404 |
| 4 | `status === 'pending'`? | **409** `pengajuan sudah pernah diproses sebelumnya` |
| 5a | reject → `UPDATE status='rejected', reviewed_by, reviewed_at` | 500 |
| 5b | approve → **satu transaction**: update status + INSERT N angsuran | 500 |
| 6 | Ambil ulang data terbaru, response 200 | |

**Algoritma penjadwalan angsuran (`generateInstallments`)**

```
n              = duration_months
perInstallment = round4(total_payable / n)

untuk i = 0 .. n-1:
    amount = perInstallment
    jika i == n-1:                             # angsuran terakhir menyerap sisa pembulatan
        sudah  = round4(perInstallment × (n-1))
        amount = round4(total_payable - sudah)

    installment_number = i + 1
    due_date           = tanggal_approve + (i+1) bulan
    amount_paid        = 0
    status             = 'unpaid'
```

Contoh: total 11 000 000 / 3 bulan → 3 666 666.6667 · 3 666 666.6667 · **3 666 666.6666**
(angsuran terakhir menyerap selisih 0.0001 agar jumlahnya persis 11 000 000).

**Perhatikan `due_date`**: Go memakai `now.AddDate(0, i+1, 0)`. Padanan PHP yang **benar**
adalah `strtotime('+N month')`, tapi keduanya sama-sama punya perilaku *overflow*
(31 Januari + 1 bulan = 3 Maret). Jika koperasi menginginkan "akhir bulan tetap akhir bulan",
gunakan `DateTime::modify('last day of next month')` — dan sadari bahwa itu **berbeda**
dari perilaku Go saat ini.

```php
public function generate_installments(array $f, $approved_at = NULL) {
    $n     = (int) $f['duration_months'];
    $total = Money::norm($f['total_payable']);
    $per   = Money::div($total, (string) $n);

    $base  = new DateTimeImmutable($approved_at ?: 'now');
    $rows  = [];

    for ($i = 0; $i < $n; $i++) {
        $amount = $per;
        if ($i === $n - 1) {
            // Angsuran terakhir = sisa, agar total persis sama dengan total_payable.
            $amount = Money::sub($total, Money::mul($per, (string) ($n - 1)));
        }
        $rows[] = [
            'installment_number' => $i + 1,
            'amount_due'         => $amount,
            'amount_paid'        => '0.0000',
            'due_date'           => $base->modify('+' . ($i + 1) . ' month')->format('Y-m-d'),
            'status'             => 'unpaid',
        ];
    }
    return $rows;
}
```

**Transaction approve:**

```php
public function approve_with_installments($financing_id, $admin_id, array $installments) {
    $this->db->trans_strict(FALSE);
    $this->db->trans_begin();

    try {
        // 1. Update status + audit reviewer
        $this->db->query(
            "UPDATE financing
                SET status='approved', reviewed_by=?, reviewed_at=NOW()
              WHERE id=? AND status='pending'", [$admin_id, $financing_id]);

        // Guard race: jika 0 baris, admin lain sudah memproses lebih dulu.
        if ($this->db->affected_rows() === 0) {
            throw Api_exception::financingNotPending();
        }

        // 2. Insert seluruh jadwal angsuran
        foreach ($installments as $ins) {
            $this->db->query(
                "INSERT INTO financing_installments
                   (financing_id, installment_number, amount_due, amount_paid, due_date, status)
                 VALUES (?, ?, ?, ?, ?, 'unpaid')",
                [$financing_id, $ins['installment_number'], $ins['amount_due'],
                 $ins['amount_paid'], $ins['due_date']]);
        }

        if ($this->db->trans_status() === FALSE) {
            $this->db->trans_rollback();
            throw Api_exception::server();
        }
        $this->db->trans_commit();

    } catch (Throwable $e) {
        $this->db->trans_rollback();
        throw $e;
    }
}
```

> Tambahan `AND status='pending'` pada UPDATE adalah **perbaikan** atas kode Go: di sana
> pengecekan status dilakukan di service (di luar transaction), sehingga dua admin yang
> menekan Approve bersamaan berpotensi menghasilkan **dua set angsuran**.

### 14.4 FLOW-16 — Lihat Jadwal Angsuran

**Endpoint** `GET /api/v1/financing/:id/installments` · JWT + aktif

Otorisasi: ambil financing, pastikan `financing.user_id === user_id`, kalau tidak → **404**
(bukan 403, untuk mencegah enumerasi). Kemudian:
`SELECT ... WHERE financing_id = ? ORDER BY installment_number ASC` → `{ "installments": [...] }`.

### 14.5 FLOW-17 — Bayar Angsuran ⚠️ TITIK KRITIS

**Endpoint** `POST /api/v1/financing/installments/:id/pay` · JWT + aktif
**Payload** `{ "savings_account_id": 5 }`

Anggota membayar **satu** angsuran penuh; nominalnya diambil dari `amount_due`, bukan dari input.

**Pra-validasi di service (di luar transaction)**

| # | Cek | Gagal |
|---|---|---|
| 1 | Angsuran ada | 404 |
| 2 | Financing induk ada | 404 |
| 3 | `financing.user_id === user_id` | 404 |
| 4 | `installment.status !== 'paid'` | 409 |

**Transaction (7 langkah, semua atomik)**

```php
public function pay_installment($installment_id, $financing_id, $amount_due, $account_id, $user_id) {
    $this->db->trans_strict(FALSE);
    $this->db->trans_begin();

    try {
        // --- a. Kunci rekening + validasi kepemilikan, status, saldo
        $acc = $this->db->query(
            "SELECT user_id, balance, status FROM savings_accounts WHERE id = ? FOR UPDATE",
            [$account_id])->row_array();

        if ( ! $acc)                                    throw Api_exception::savingsAccountNotFound();
        if ((int)$acc['user_id'] !== (int)$user_id)     throw Api_exception::savingsAccountNotFound();
        if ($acc['status'] !== 'active')                throw Api_exception::accountNotActive();
        if (Money::lt($acc['balance'], $amount_due))    throw Api_exception::insufficientBalance();

        // --- b. Debit saldo
        $this->db->query(
            "UPDATE savings_accounts SET balance = balance - ?, updated_at = NOW() WHERE id = ?",
            [$amount_due, $account_id]);

        // --- c. Catat mutasi debit; reference_id menghubungkan ledger ke angsuran
        $this->db->query(
            "INSERT INTO savings_transactions (savings_account_id, type, amount, reference_id)
             VALUES (?, 'withdraw', ?, ?)",
            [$account_id, $amount_due, 'cicilan_' . $installment_id]);

        // --- d. Tandai angsuran lunas.
        //        Klausa AND status='unpaid' adalah proteksi race: jika request lain
        //        sudah melunasinya, affected_rows = 0 dan kita rollback.
        $this->db->query(
            "UPDATE financing_installments
                SET status='paid', amount_paid = ?, paid_at = NOW()
              WHERE id = ? AND financing_id = ? AND status = 'unpaid'",
            [$amount_due, $installment_id, $financing_id]);

        if ($this->db->affected_rows() === 0) {
            throw Api_exception::installmentAlreadyPaid();
        }

        // --- e. Semua angsuran lunas? tutup pembiayaan
        $left = (int) $this->db->query(
            "SELECT COUNT(*) AS c FROM financing_installments
              WHERE financing_id = ? AND status = 'unpaid'", [$financing_id])->row()->c;

        if ($left === 0) {
            $this->db->query("UPDATE financing SET status = 'paid' WHERE id = ?", [$financing_id]);
        }

        if ($this->db->trans_status() === FALSE) {
            $this->db->trans_rollback();
            throw Api_exception::server();
        }
        $this->db->trans_commit();

    } catch (Throwable $e) {
        $this->db->trans_rollback();
        throw $e;
    }
}
```

**Response 200** `{ "message": "pembayaran cicilan berhasil" }`

**Tabel error**

| Kondisi | Status | Pesan |
|---|---|---|
| Angsuran tidak ada / bukan milik user | 404 | `cicilan tidak ditemukan` |
| Rekening tidak ada / bukan milik user | 404 | `rekening simpanan tidak ditemukan` |
| Sudah dibayar | 409 | `cicilan sudah dibayar sebelumnya` |
| Saldo kurang | 422 | `saldo rekening tidak mencukupi` |
| Rekening beku/tutup | 422 | `rekening simpanan tidak aktif` |

---

## 15. Modul 5: Emas Digital

### 15.1 FLOW-18 — Harga Emas (cache-aside)

**Endpoint** `GET /api/v1/gold/price` · **publik**, tanpa JWT, tanpa rate limit

```
1. GET gold:current_price dari Redis
     ├─ HIT  + JSON valid  → kembalikan
     ├─ HIT  + JSON rusak  → log warning, lanjut ke DB
     └─ MISS / Redis error → lanjut ke DB
2. SELECT id, buy_price_per_gram, sell_price_per_gram, updated_at
     FROM gold_prices ORDER BY updated_at DESC LIMIT 1
     └─ kosong → 503 "harga emas belum tersedia"
3. SETEX gold:current_price 900 <json>     (gagal = non-fatal)
4. Kembalikan
```

```php
public function get_current_price() {
    $CI  =& get_instance();
    $key = $CI->config->item('gold_price_cache_key');

    // 1. Cache
    try {
        $cached = $CI->redisx->get($key);
        if ($cached !== NULL) {
            $p = json_decode($cached, TRUE);
            if (json_last_error() === JSON_ERROR_NONE && isset($p['buy_price_per_gram'])) {
                return $p;
            }
            log_message('warning', '[gold] cache korupsi, fallback ke DB');
        }
    } catch (Throwable $e) {
        log_message('warning', '[gold] Redis get gagal (non-fatal): ' . $e->getMessage());
    }

    // 2. DB
    $row = $CI->Gold_model->latest_price();
    if ( ! $row) { throw Api_exception::goldPriceUnavailable(); }

    // 3. Isi cache — kegagalan tidak menggagalkan request
    try {
        $CI->redisx->setex($key, (int) $CI->config->item('gold_price_cache_ttl'), json_encode($row));
    } catch (Throwable $e) {
        log_message('warning', '[gold] Redis setex gagal (non-fatal): ' . $e->getMessage());
    }

    return $row;
}
```

> **Invalidasi cache**: setiap kali admin memperbarui `gold_prices`, hapus key `gold:current_price`.
> Belum ada endpoint admin untuk itu di sistem Go (lihat CACAT-07) — tambahkan di versi PHP.

### 15.2 FLOW-19 — Beli Emas ⚠️ TITIK KRITIS

**Endpoint** `POST /api/v1/gold/buy` · JWT + aktif
**Payload** `{ "gram_amount": 0.5, "savings_account_id": 5 }`

| # | Langkah | Gagal |
|---|---|---|
| 1 | Validasi (`gram_amount >= 0.0001`, account int > 0) | 400 |
| 2 | `gram_amount <= 100` | **400** `maksimal transaksi emas adalah 100 gram per transaksi` |
| 3 | Ambil harga terkini | 503 |
| 4 | `total = round4(gram × buy_price_per_gram)` | |
| 5 | **Transaction**: kunci rekening → validasi → debit → insert gold_tx → insert ledger | 404/422 |
| 6 | `RPUSH queue:gold_mint <gold_tx_id>` — **non-fatal** | log saja |
| 7 | Response `201` transaksi berstatus `pending` | |

```php
public function buy_with_debit($user_id, $account_id, $gram, $price_per_gram, $total) {
    $this->db->trans_strict(FALSE);
    $this->db->trans_begin();

    try {
        // 1. Kunci rekening
        $acc = $this->db->query(
            "SELECT user_id, balance, status FROM savings_accounts WHERE id = ? FOR UPDATE",
            [$account_id])->row_array();

        if ( ! $acc)                                 throw Api_exception::savingsAccountNotFound();
        if ((int)$acc['user_id'] !== (int)$user_id)  throw Api_exception::savingsAccountNotFound();
        if ($acc['status'] !== 'active')             throw Api_exception::accountNotActive();
        if (Money::lt($acc['balance'], $total))      throw Api_exception::insufficientBalance();

        // 2. Debit saldo
        $this->db->query(
            "UPDATE savings_accounts SET balance = balance - ?, updated_at = NOW() WHERE id = ?",
            [$total, $account_id]);

        // 3. Insert transaksi emas — butuh id-nya untuk reference_id ledger
        $g = $this->db->query(
            "INSERT INTO gold_transactions (user_id, type, gram_amount, price_per_gram, total_rupiah, status)
             VALUES (?, 'buy', ?, ?, ?, 'pending')
             RETURNING id, created_at",
            [$user_id, $gram, $price_per_gram, $total])->row_array();

        $gold_tx_id = (int) $g['id'];

        // 4. Ledger debit. Format reference_id WAJIB 'gold_buy_{id}' —
        //    dipakai proses refund untuk menemukan rekening asal.
        $this->db->query(
            "INSERT INTO savings_transactions (savings_account_id, type, amount, reference_id)
             VALUES (?, 'withdraw', ?, ?)",
            [$account_id, $total, 'gold_buy_' . $gold_tx_id]);

        if ($this->db->trans_status() === FALSE) {
            $this->db->trans_rollback();
            throw Api_exception::server();
        }
        $this->db->trans_commit();

        return [
            'id'             => $gold_tx_id,
            'user_id'        => (int) $user_id,
            'type'           => 'buy',
            'gram_amount'    => (float) $gram,
            'price_per_gram' => Money::out($price_per_gram),
            'total_rupiah'   => Money::out($total),
            'tx_hash'        => NULL,
            'status'         => 'pending',
            'created_at'     => $g['created_at'],
        ];

    } catch (Throwable $e) {
        $this->db->trans_rollback();
        throw $e;
    }
}
```

Service, setelah transaction commit:

```php
// 6. Dorong ke antrian worker — non-fatal, transaksi sudah aman di DB
try {
    $CI->redisx->rpush($CI->config->item('gold_mint_queue_key'), $tx['id']);
} catch (Throwable $e) {
    log_message('error', '[gold] RPush gagal untuk ID=' . $tx['id'] . ': ' . $e->getMessage()
              . ' — transaksi akan diambil oleh recovery worker');
}
```

> Karena worker punya *startup recovery* yang me-requeue semua `status='pending'`,
> kegagalan RPush tidak kehilangan transaksi — hanya menundanya sampai worker restart.
> Untuk sistem produksi, tambahkan cron `gold_worker recover` tiap 5 menit.

**Perbaikan yang harus dibawa**: handler Go untuk `/gold/buy` **tidak** menangani
`ErrExceedsTransactionLimit`, sehingga pembelian > 100 gram mengembalikan **500**, bukan 400
(handler `/gold/sell` menanganinya dengan benar). Di CI3, `Api_exception::goldLimitExceeded()`
otomatis memberi 400 di kedua endpoint.

### 15.3 FLOW-20 — Jual Emas

**Endpoint** `POST /api/v1/gold/sell` · JWT + aktif
**Payload** `{ "gram_amount": 0.25, "savings_account_id": 5 }`

Alur lebih pendek — **tidak menyentuh blockchain sama sekali**, langsung `status = 'success'`:

```
1. Validasi + batas 100 gram              → 400
2. Ambil harga → pakai sell_price_per_gram → 503 jika tidak ada
3. total = round4(gram × sell_price_per_gram)
4. Transaction:
     SELECT user_id, status FROM savings_accounts WHERE id=? FOR UPDATE
     validasi kepemilikan + status active
     UPDATE savings_accounts SET balance = balance + total
     INSERT gold_transactions (type='sell', status='success')
     INSERT savings_transactions (deposit, ref='gold_sell_{id}')
     COMMIT
5. 201
```

⚠️ **Cacat serius**: tidak ada validasi bahwa anggota **memiliki** emas sebanyak itu.
Siapa pun bisa "menjual" 100 gram emas yang tidak dimiliki dan saldonya bertambah.
Lihat CACAT-01 — di versi PHP, tambahkan pemeriksaan kepemilikan sebelum kredit:

```php
// Hitung kepemilikan emas bersih dari buku transaksi (di dalam transaction yang sama)
$holding = $this->db->query(
    "SELECT COALESCE(SUM(CASE WHEN type='buy'  AND status='success' THEN gram_amount ELSE 0 END), 0)
          - COALESCE(SUM(CASE WHEN type='sell' AND status='success' THEN gram_amount ELSE 0 END), 0)
              AS net_gram
       FROM gold_transactions
      WHERE user_id = ?", [$user_id])->row()->net_gram;

if (Money::lt($holding, $gram)) {
    throw Api_exception::goldInsufficientHolding();
}
```

Untuk skala besar, ganti agregasi ini dengan tabel saldo emas ber-`FOR UPDATE`
(`gold_holdings(user_id, gram_balance)`) agar tidak men-scan seluruh riwayat setiap penjualan.

---

## 16. Modul 6: Worker Emas & Blockchain

### 16.1 Apa yang worker Go lakukan

```
Start()
  loop selamanya:
      BLPOP queue:gold_mint 0        ← blokir sampai ada ID masuk
      processTransaction(id)

Recover()  ← dijalankan sinkron sebelum server menerima request
  recoverPending():     SELECT ... WHERE status='pending'                  → RPUSH ulang
  recoverProcessing():  SELECT ... WHERE status='processing' AND tx_hash IS NOT NULL
                        → TransactionByHash + lanjut WaitMined

processTransaction(id):
  1. Ambil gold_transactions by id
  2. status != 'pending' → skip (idempotensi terhadap ID ganda di antrian)
  3. Ambil users.wallet_address
       kosong → RefundFailedTransaction(id) → status 'failed', selesai
  4. Blockchain tidak dikonfigurasi → log-only, keluar (status tetap 'pending')
  5. mint(wallet, gram × 10^4, goldTxID)
       error broadcast → status 'failed' (⚠️ TANPA refund — BUG)
       sukses          → status 'processing' + tx_hash
  6. Goroutine terpisah: WaitMined
       receipt.Status == 1 → validasi event GoldMinted → status 'success'
       receipt.Status == 0 → RefundFailedTransaction → status 'failed'
```

**Konversi satuan**: kontrak `CoopGold` memakai **4 desimal**.
`1 gram = 10 000 unit`, `0.5 gram = 5 000 unit`, minimum `0.0001 gram = 1 unit`.
Konsisten dengan `DECIMAL(10,4)` di `gold_transactions`.

**Refund atomik (`RefundFailedTransaction`)**

```
BEGIN
  SELECT user_id, total_rupiah, status FROM gold_transactions WHERE id=? FOR UPDATE
  jika status bukan 'pending'/'processing' → skip (idempoten), COMMIT
  SELECT savings_account_id FROM savings_transactions
    WHERE reference_id = 'gold_buy_{id}' LIMIT 1        ← inilah gunanya konvensi reference_id
  UPDATE savings_accounts SET balance = balance + total_rupiah
  INSERT savings_transactions (deposit, ref='gold_refund_{id}')
  UPDATE gold_transactions SET status='failed'
COMMIT
```

### 16.2 Worker versi PHP

PHP tidak punya goroutine, jadi arsitekturnya:

```
┌──────────────────────────────────────────────────────────────┐
│ Proses 1: php index.php cli/gold_worker start                │
│   loop: BLPOP (timeout 0, predis read_write_timeout=0)       │
│         → proses satu transaksi sampai selesai (SINKRON)     │
│   dijalankan di bawah Supervisor / NSSM, auto-restart        │
├──────────────────────────────────────────────────────────────┤
│ Proses 2 (cron tiap 5 menit): php index.php cli/gold_worker  │
│   recover  → requeue 'pending', cek receipt 'processing'     │
└──────────────────────────────────────────────────────────────┘
```

Perbedaan penting dari Go: `WaitMined` di Go berjalan di goroutine terpisah sehingga loop
tidak terblokir. Di PHP, **jangan** menunggu receipt secara sinkron di dalam loop utama —
itu akan memblokir antrian selama beberapa detik per transaksi. Dua pilihan:

- **Pilihan A (disarankan)**: worker hanya mengirim transaksi dan menyetel `processing` + `tx_hash`.
  Cron `recover` yang memeriksa receipt secara berkala. Sederhana, tidak ada proses paralel.
- **Pilihan B**: dua antrian — `queue:gold_mint` (kirim) dan `queue:gold_receipt` (cek receipt),
  masing-masing dengan proses worker sendiri.

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CLI worker emas.
 *   php index.php cli/gold_worker start     — loop BLPOP (di bawah supervisor)
 *   php index.php cli/gold_worker recover   — requeue pending + cek receipt processing (cron)
 *   php index.php cli/gold_worker once 42   — proses satu ID (debugging)
 */
class Gold_worker extends CI_Controller {

    private $queue_key;

    public function __construct() {
        parent::__construct();
        if ( ! is_cli()) { show_404(); }

        set_time_limit(0);
        $this->load->model(['Gold_model', 'User_model']);
        $this->load->library(['Chain_client']);
        // read_write_timeout = 0 → BLPOP boleh menunggu selamanya
        $this->load->library('Redisx', ['blocking' => TRUE], 'redisq');

        $this->queue_key = $this->config->item('gold_mint_queue_key');
    }

    public function start() {
        $this->_log('worker dimulai — mode event-driven (BLPOP)');
        $this->recover();

        while (TRUE) {
            try {
                $res = $this->redisq->blpop($this->queue_key, 0);   // [key, value]
                if ( ! $res || count($res) < 2) { continue; }

                $tx_id = (int) $res[1];
                $this->_log("menerima ID transaksi dari queue: {$tx_id}");
                $this->_process($tx_id);

            } catch (Throwable $e) {
                $this->_log('loop error: ' . $e->getMessage(), 'error');
                sleep(2);        // jangan spin ketat saat Redis tumbang
            }
        }
    }

    /** Startup + cron recovery. */
    public function recover() {
        foreach ($this->Gold_model->find_by_status('pending') as $t) {
            try {
                $this->redisq->rpush($this->queue_key, $t['id']);
                $this->_log("requeue pending ID={$t['id']}");
            } catch (Throwable $e) {
                $this->_log("gagal requeue ID={$t['id']}: " . $e->getMessage(), 'error');
            }
        }
        foreach ($this->Gold_model->find_processing_with_hash() as $t) {
            $this->_check_receipt((int) $t['id'], $t['tx_hash']);
        }
    }

    public function once($tx_id) { $this->_process((int) $tx_id); }

    // ----------------------------------------------------------------

    private function _process($tx_id) {
        $tx = $this->Gold_model->find_by_id($tx_id);
        if ( ! $tx) { $this->_log("transaksi ID={$tx_id} tidak ditemukan", 'error'); return; }

        // Idempotensi: ID yang sama bisa masuk antrian dua kali.
        if ($tx['status'] !== 'pending') {
            $this->_log("ID={$tx_id} status='{$tx['status']}' (bukan pending), dilewati");
            return;
        }

        $user = $this->User_model->find_by_id((int) $tx['user_id']);
        $wallet = $user['wallet_address'] ?? NULL;

        // Wallet belum diisi → token tidak bisa dikirim → refund
        if (empty($wallet)) {
            $this->_log("user ID={$tx['user_id']} belum set wallet_address — refund ID={$tx_id}", 'warning');
            $this->Gold_model->refund_failed_transaction($tx_id);
            return;
        }

        if ( ! $this->chain_client->is_ready()) {
            $this->_log("[log-only] ID={$tx_id} — blockchain belum dikonfigurasi | wallet: {$wallet}");
            return;   // status tetap 'pending', akan di-requeue saat recovery
        }

        // gram → unit on-chain (4 desimal)
        $units = bcmul(Money::norm($tx['gram_amount']), '10000', 0);

        try {
            $hash = $this->chain_client->mint($wallet, $units, $tx_id);
        } catch (Throwable $e) {
            $this->_log("GAGAL mint ID={$tx_id}: " . $e->getMessage(), 'error');
            // PERBAIKAN atas kode Go: saldo sudah didebet, jadi WAJIB refund.
            $this->Gold_model->refund_failed_transaction($tx_id);
            return;
        }

        $this->Gold_model->update_status_and_hash($tx_id, 'processing', $hash);
        $this->_log("ID={$tx_id} dikirim ke chain — tx_hash: {$hash}");
    }

    private function _check_receipt($tx_id, $hash) {
        try {
            $receipt = $this->chain_client->get_receipt($hash);
        } catch (Throwable $e) {
            $this->_log("gagal ambil receipt ID={$tx_id}: " . $e->getMessage(), 'error');
            return;                    // biarkan 'processing', coba lagi siklus berikutnya
        }

        if ($receipt === NULL) { return; }   // belum ter-mine

        if ((int) $receipt['status'] === 1) {
            $this->Gold_model->update_status($tx_id, 'success');
            $this->_log("✓ ID={$tx_id} dikonfirmasi on-chain — success");
        } else {
            $this->_log("ID={$tx_id} di-REVERT oleh EVM — refund...", 'warning');
            $this->Gold_model->refund_failed_transaction($tx_id);
        }
    }

    private function _log($msg, $level = 'info') {
        $line = '[' . date('Y-m-d H:i:s') . "] [gold-worker] {$msg}";
        fwrite($level === 'error' ? STDERR : STDOUT, $line . PHP_EOL);
        log_message($level, $line);
    }
}
```

### 16.3 `refund_failed_transaction` di PHP

```php
public function refund_failed_transaction($gold_tx_id) {
    $this->db->trans_strict(FALSE);
    $this->db->trans_begin();

    try {
        // 1. Kunci transaksi emas
        $g = $this->db->query(
            "SELECT user_id, total_rupiah, status FROM gold_transactions WHERE id = ? FOR UPDATE",
            [$gold_tx_id])->row_array();

        if ( ! $g) { throw new Exception("transaksi emas ID={$gold_tx_id} tidak ditemukan"); }

        // Idempoten: hanya pending/processing yang boleh di-refund
        if ( ! in_array($g['status'], ['pending', 'processing'], TRUE)) {
            $this->db->trans_commit();
            log_message('info', "[refund] ID={$gold_tx_id} sudah final ({$g['status']}), skip");
            return;
        }

        // 2. Temukan rekening asal lewat konvensi reference_id
        $ref = 'gold_buy_' . $gold_tx_id;
        $log = $this->db->query(
            "SELECT savings_account_id FROM savings_transactions WHERE reference_id = ? LIMIT 1",
            [$ref])->row_array();

        if ( ! $log) { throw new Exception("ledger untuk {$ref} tidak ditemukan"); }
        $account_id = (int) $log['savings_account_id'];

        // 3. Kredit balik
        $this->db->query(
            "UPDATE savings_accounts SET balance = balance + ?, updated_at = NOW() WHERE id = ?",
            [$g['total_rupiah'], $account_id]);

        // 4. Catat refund di buku besar
        $this->db->query(
            "INSERT INTO savings_transactions (savings_account_id, type, amount, reference_id)
             VALUES (?, 'deposit', ?, ?)",
            [$account_id, $g['total_rupiah'], 'gold_refund_' . $gold_tx_id]);

        // 5. Tandai gagal
        $this->db->query("UPDATE gold_transactions SET status = 'failed' WHERE id = ?", [$gold_tx_id]);

        if ($this->db->trans_status() === FALSE) { $this->db->trans_rollback(); throw new Exception('refund gagal commit'); }
        $this->db->trans_commit();

        log_message('info', "[refund] berhasil ID={$gold_tx_id} akun={$account_id} nominal={$g['total_rupiah']}");

    } catch (Throwable $e) {
        $this->db->trans_rollback();
        log_message('error', "[refund] KRITIS gagal ID={$gold_tx_id}: " . $e->getMessage());
        throw $e;
    }
}
```

### 16.4 Menandatangani transaksi blockchain dari PHP

Ini bagian tersulit dari port. Tiga opsi, berurut dari yang paling direkomendasikan:

**Opsi 1 — Pertahankan worker Go sebagai sidecar (paling praktis)**
Aplikasi CI3 menangani seluruh HTTP API; binary Go tetap jalan hanya sebagai worker emas,
berbagi PostgreSQL dan Redis yang sama. Tidak ada kode kripto yang perlu ditulis ulang.

**Opsi 2 — Microservice signer Node.js (paling bersih untuk tim PHP)**
Layanan kecil ±60 baris dengan `ethers.js`, hanya mendengar di `127.0.0.1`:

```
POST /mint      { "to": "0x...", "units": "5000", "goldTxId": 42 }  → { "txHash": "0x..." }
GET  /receipt?hash=0x...                                            → { "status": 1|0 } | 204
```

`Chain_client` di CI3 memanggilnya via cURL:

```php
class Chain_client {
    private $base, $ready;

    public function __construct() {
        $this->base  = rtrim((string) env('SIGNER_SERVICE_URL', ''), '/');
        $this->ready = ($this->base !== '' && env('GOLD_CONTRACT_ADDRESS'));
    }

    public function is_ready() { return $this->ready; }

    public function mint($to, $units, $gold_tx_id) {
        $res = $this->_post('/mint', ['to' => $to, 'units' => (string) $units, 'goldTxId' => (int) $gold_tx_id]);
        if (empty($res['txHash'])) { throw new Exception('signer tidak mengembalikan txHash'); }
        return $res['txHash'];
    }

    /** @return array|null null jika belum ter-mine */
    public function get_receipt($hash) {
        $ch = curl_init($this->base . '/receipt?hash=' . urlencode($hash));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => TRUE, CURLOPT_TIMEOUT => 20]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 204) { return NULL; }
        if ($code !== 200) { throw new Exception("signer /receipt HTTP {$code}"); }
        return json_decode($body, TRUE);
    }

    private function _post($path, array $payload) {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_POST           => TRUE,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 60,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) { throw new Exception("signer {$path} HTTP {$code}: {$body}"); }
        return json_decode($body, TRUE);
    }
}
```

**Opsi 3 — Murni PHP** (`web3p/web3.php` + `kornrunner/ethereum-offline-raw-tx`).
Bisa jalan di PHP 7.4, tapi harus mengurus nonce, gas estimation, encoding ABI, dan
retry secara manual. Hanya pilih ini jika penambahan runtime lain benar-benar tidak boleh.

> **Keamanan `OWNER_PRIVATE_KEY`**: kunci ini bisa mencetak token emas tanpa batas.
> Jangan pernah menaruhnya di `.env` yang terbaca web server. Di Opsi 1/2, kunci hanya
> ada di proses worker/signer, tidak pernah menyentuh PHP-FPM.

---

## 17. Modul 7: Dashboard Admin & Health

### 17.1 Endpoint admin (semua read-only)

| Endpoint | Query | Bungkus response |
|---|---|---|
| `GET /admin/users` | `SELECT ... FROM users ORDER BY created_at DESC` | `{ "users": [...] }` |
| `GET /admin/transactions/saving` | `SELECT ... FROM savings_transactions ORDER BY created_at DESC` | `{ "transactions": [...] }` |
| `GET /admin/transactions/financing` | `SELECT ... FROM financing ORDER BY created_at DESC` | `{ "financings": [...] }` |
| `GET /admin/transactions/gold` | `SELECT ... FROM gold_transactions ORDER BY created_at DESC` | `{ "transactions": [...] }` |
| `GET /admin/savings/deposit-requests` | `SELECT ... FROM deposit_requests ORDER BY created_at DESC` | `{ "deposit_requests": [...] }` |

⚠️ **Semuanya tanpa paginasi.** Pada 100 000 baris transaksi, satu request akan menarik
seluruh tabel ke memori PHP dan kemungkinan besar kena `memory_limit`. Tambahkan
`?page=&per_page=` (default 50, maks 200) sejak hari pertama — jauh lebih mudah daripada
menambahkannya setelah frontend terlanjur mengandalkan array penuh.

```php
public function users() {
    $this->run(function () {
        $page = max(1, (int) $this->input->get('page'));
        $per  = min(200, max(1, (int) ($this->input->get('per_page') ?: 50)));
        return $this->ok([
            'users' => $this->User_model->get_all_paged($per, ($page - 1) * $per),
            'page'  => $page,
            'per_page' => $per,
        ], 200);
    });
}
```

**Jangan lupa `to_public()`** pada `/admin/users` — tanpa itu, hash bcrypt seluruh anggota
terkirim ke frontend.

### 17.2 Health check

**Endpoint** `GET /api/v1/health` — tanpa auth, dipanggil load balancer.

```php
public function index() {
    $services = [];
    $status   = 'ok';
    $http     = 200;

    try {
        $this->db->query('SELECT 1');
        $services['database'] = 'ok';
    } catch (Throwable $e) {
        $services['database'] = 'unreachable: ' . $e->getMessage();
        $status = 'degraded';
        $http   = 503;
    }

    // Tambahan yang tidak ada di Go tapi layak: cek Redis juga,
    // karena login/logout/OTP bergantung padanya.
    try {
        $this->redisx->client()->ping();
        $services['redis'] = 'ok';
    } catch (Throwable $e) {
        $services['redis'] = 'unreachable';
        $status = 'degraded';
        $http   = 503;
    }

    return $this->ok([
        'status'    => $status,
        'timestamp' => gmdate('c'),
        'services'  => $services,
    ], $http);
}
```

---
---

# BAGIAN D — PENUTUP

## 18. Peta Routing Lengkap

### 18.1 `application/config/routes.php`

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$route['default_controller'] = 'api/v1/health';
$route['404_override']       = '';
$route['translate_uri_dashes'] = FALSE;

$api = 'api/v1';

// ---------- Publik ----------
$route[$api . '/health']['get']        = 'api/v1/health/index';
$route[$api . '/register']['post']     = 'api/v1/auth/register';
$route[$api . '/login']['post']        = 'api/v1/auth/login';
$route[$api . '/verify-email']['post'] = 'api/v1/auth/verify_email';
$route[$api . '/gold/price']['get']    = 'api/v1/gold/price';

// ---------- Terproteksi (JWT + akun aktif) ----------
$route[$api . '/logout']['post']    = 'api/v1/auth/logout';
$route[$api . '/profile']['get']    = 'api/v1/profile/index';
$route[$api . '/profile/kyc']['get'] = 'api/v1/profile/get_kyc';
$route[$api . '/profile/kyc']['put'] = 'api/v1/profile/update_kyc';

$route[$api . '/savings/accounts']['post']        = 'api/v1/savings/open_account';
$route[$api . '/savings/accounts']['get']         = 'api/v1/savings/accounts';
$route[$api . '/savings/deposit']['post']         = 'api/v1/savings/deposit';
$route[$api . '/savings/deposit-requests']['get'] = 'api/v1/savings/deposit_requests';

$route[$api . '/financing/apply']['post']                       = 'api/v1/financing/apply';
$route[$api . '/financing']['get']                              = 'api/v1/financing/index';
$route[$api . '/financing/(:num)/installments']['get']          = 'api/v1/financing/installments/$1';
$route[$api . '/financing/installments/(:num)/pay']['post']     = 'api/v1/financing/pay/$1';

$route[$api . '/gold/buy']['post']  = 'api/v1/gold/buy';
$route[$api . '/gold/sell']['post'] = 'api/v1/gold/sell';

// ---------- Admin (JWT + role pengurus|admin|super_admin) ----------
$route[$api . '/admin/financing/(:num)/review']['put']                = 'api/v1/admin/review_financing/$1';
$route[$api . '/admin/savings/deposit-requests/(:num)/review']['put'] = 'api/v1/admin/review_deposit/$1';
$route[$api . '/admin/savings/deposit-requests']['get']               = 'api/v1/admin/deposit_requests';
$route[$api . '/admin/users']['get']                                  = 'api/v1/admin/users';
$route[$api . '/admin/transactions/financing']['get']                 = 'api/v1/admin/tx_financing';
$route[$api . '/admin/transactions/gold']['get']                      = 'api/v1/admin/tx_gold';
$route[$api . '/admin/transactions/saving']['get']                    = 'api/v1/admin/tx_saving';
```

> **Wajib**: `$config['uri_protocol'] = 'REQUEST_URI';` dan hapus `index.php` dari URL
> lewat `.htaccess` (Apache/Laragon) atau `try_files` (nginx), agar path `/api/v1/...` cocok.

`.htaccess` di root:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php/$1 [L]
```

### 18.2 Matriks endpoint → controller → service → model

| Endpoint | Controller::method | Base class | Service | Model |
|---|---|---|---|---|
| POST `/register` | `Auth::register` | API_ | User_, Saving_ | User_, Saving_ |
| POST `/login` | `Auth::login` | API_ | User_ | User_ |
| POST `/verify-email` | `Auth::verify_email` | API_ | User_ | User_ |
| POST `/logout` | `Auth::logout` | Auth_ | User_ | — |
| GET `/profile` | `Profile::index` | Auth_ | User_ | User_ |
| GET `/profile/kyc` | `Profile::get_kyc` | Auth_ | User_ | User_profile_ |
| PUT `/profile/kyc` | `Profile::update_kyc` | Auth_ | User_ | User_profile_ |
| POST `/savings/accounts` | `Savings::open_account` | Auth_ | Saving_ | Saving_ |
| GET `/savings/accounts` | `Savings::accounts` | Auth_ | Saving_ | Saving_ |
| POST `/savings/deposit` | `Savings::deposit` | Auth_ | Saving_ | Saving_, Deposit_request_ |
| GET `/savings/deposit-requests` | `Savings::deposit_requests` | Auth_ | Saving_ | Deposit_request_ |
| POST `/financing/apply` | `Financing::apply` | Auth_ | Financing_ | Financing_ |
| GET `/financing` | `Financing::index` | Auth_ | Financing_ | Financing_ |
| GET `/financing/:id/installments` | `Financing::installments` | Auth_ | Financing_ | Financing_ |
| POST `/financing/installments/:id/pay` | `Financing::pay` | Auth_ | Financing_ | Financing_, Saving_ |
| GET `/gold/price` | `Gold::price` | API_ | Gold_ | Gold_ |
| POST `/gold/buy` | `Gold::buy` | Auth_ | Gold_ | Gold_, Saving_ |
| POST `/gold/sell` | `Gold::sell` | Auth_ | Gold_ | Gold_, Saving_ |
| PUT `/admin/financing/:id/review` | `Admin::review_financing` | Admin_ | Financing_ | Financing_ |
| PUT `/admin/savings/deposit-requests/:id/review` | `Admin::review_deposit` | Admin_ | Saving_ | Deposit_request_, Saving_ |
| GET `/admin/*` | `Admin::*` | Admin_ | masing-masing | masing-masing |
| GET `/health` | `Health::index` | CI_Controller | — | — |

---

## 19. Jebakan Migrasi Go → PHP

### 19.1 Presisi uang — jebakan paling mahal

```php
// SALAH — hasil bisa 11000000.000000002
$total = $principal + ($principal * 0.10);

// SALAH juga — round() PHP memakai float internal
$total = round($principal * 1.1, 4);

// BENAR
$margin = Money::mul($principal, '0.10');
$total  = Money::add($principal, $margin);
```

Terutama pada perbandingan saldo: `if ($balance < $amount)` dengan float bisa keliru
untuk selisih 0.0001. Selalu `Money::lt($balance, $amount)`.

### 19.2 Boolean PostgreSQL

`is_email_verified` kembali sebagai string `'t'`/`'f'` di driver postgre CI3.
`if ($row['is_email_verified'])` bernilai **TRUE untuk `'f'`** — akun yang belum verifikasi bisa login.
Selalu lewat helper `truthy()`.

### 19.3 Angka desimal kembali sebagai string

`DECIMAL(19,4)` di-return sebagai string `"100000.0000"`. Itu **bagus** untuk bcmath, tapi
saat serialisasi JSON harus dikonversi ke number agar frontend lama tetak bekerja:
`Money::out($row['balance'])`.

### 19.4 Tidak ada goroutine

Semua yang di Go berjalan `go func(){}` — `awaitReceipt`, dan seterusnya — harus dipindahkan
ke proses terpisah (worker/cron). Jangan pernah memanggil operasi jaringan lambat
(RPC blockchain, SMTP) secara sinkron di dalam request HTTP tanpa timeout ketat.

### 19.5 Tidak ada connection pool

Go memakai pool (25 koneksi). PHP-FPM membuka satu koneksi per request dan menutupnya.
Konsekuensi: **jumlah koneksi DB = jumlah worker PHP-FPM**. Setel `pm.max_children`
di PHP-FPM ≤ `max_connections` PostgreSQL dikurangi cadangan untuk worker CLI.

### 19.6 `defer tx.Rollback()` tidak ada padanan otomatis

Di Go, rollback dijamin oleh `defer`. Di PHP, satu `return` atau `throw` yang lolos dari
try/catch akan meninggalkan transaction terbuka sampai koneksi ditutup — dan dengan
`FOR UPDATE`, baris rekening tetap terkunci selama itu. **Selalu bungkus dengan try/catch**.

### 19.7 Timeout request

Go menyetel `ReadTimeout/WriteTimeout` 15 detik. Padanan PHP:

```ini
max_execution_time = 15      ; php.ini untuk FPM (worker CLI diset 0 lewat set_time_limit)
```

Plus `fastcgi_read_timeout 20s;` di nginx atau `Timeout 20` di Apache.

### 19.8 Perbedaan penambahan bulan

`now.AddDate(0, 1, 0)` (Go) pada 31 Januari menghasilkan **3 Maret**.
`strtotime('+1 month')` (PHP) pada 31 Januari menghasilkan **3 Maret** juga — kebetulan sama.
Namun `DateTime::modify('+1 month')` pada tahun kabisat punya kasus tepi berbeda.
Jika jadwal angsuran harus persis sama dengan sistem lama, uji dengan tanggal 29/30/31.

### 19.9 `random_int` vs `crypto/rand`

Go memakai `crypto/rand` untuk OTP. Padanan PHP yang benar adalah `random_int()`
(CSPRNG), **bukan** `rand()` atau `mt_rand()`. Dan jangan lupa zero-padding:
`str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)` — tanpa itu, OTP `000123`
menjadi `123` dan verifikasi `len:6` gagal.

### 19.10 Header `Authorization` hilang

Pada Apache + CGI/FastCGI, header `Authorization` sering tidak diteruskan ke PHP.
Tambahkan di `.htaccess`:

```apache
RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

Gejalanya: semua request terproteksi mengembalikan 401 "header Authorization tidak ada",
padahal frontend mengirimnya.

### 19.11 `exit` di dalam `fail()`

`API_Controller::fail()` memanggil `exit` setelah menulis output. Itu meniru
`c.AbortWithStatusJSON`, tetapi berarti kode setelahnya tidak jalan — pastikan tidak ada
transaction yang masih terbuka saat `fail()` dipanggil. Karena itu semua `throw` di model
harus melewati blok catch yang melakukan rollback terlebih dahulu.

---

## 20. Cacat Sistem Eksisting yang Harus Diperbaiki

Temuan dari pembacaan kode Go. Nomor urut berdasarkan tingkat risiko.

### CACAT-01 — Jual emas tanpa cek kepemilikan (KRITIS, kerugian finansial)

`goldService.SellGold` → `goldRepo.SellWithCredit` hanya memvalidasi kepemilikan dan status
**rekening simpanan**. Tidak ada satu pun query yang memeriksa apakah anggota benar-benar
punya emas. Anggota baru dengan saldo 0 bisa memanggil `/gold/sell` dengan 100 gram dan
saldo rupiahnya bertambah ±167 juta.
**Perbaikan**: hitung kepemilikan bersih (atau pelihara tabel `gold_holdings`) di dalam
transaction yang sama sebelum kredit — lihat §15.3.

### CACAT-02 — Mint gagal broadcast tidak memicu refund (KRITIS)

`GoldWorker.mintOnChain`: bila `coopGold.Mint(...)` mengembalikan error, status di-set
`failed` **tanpa** memanggil `RefundFailedTransaction`. Komentar di kode berbunyi
"tidak perlu refund karena tx tidak sempat di-broadcast" — tetapi saldo anggota **sudah didebet**
di `BuyWithDebit` sebelum antrian. Uang anggota hilang.
**Perbaikan**: panggil refund di jalur error ini (sudah diterapkan di §16.2).

### CACAT-03 — Grup admin tidak memeriksa status akun (TINGGI)

`v1.Group("/admin", RequireAuth, RequireRole)` — tidak ada `RequireActiveUserDB`.
Admin yang di-`banned` tetap bisa approve pembiayaan dan setoran selama token belum kedaluwarsa.
**Perbaikan**: `Admin_Controller extends Auth_Controller` sudah menutup ini secara struktural.

### CACAT-04 — Approve setoran memakai dua transaction terpisah (TINGGI)

Lihat §13.4. Crash di antara transaksi A dan B → saldo bertambah, permohonan tetap `pending`,
approve kedua menambah saldo lagi. **Perbaikan**: satu transaction, plus `FOR UPDATE` pada
baris `deposit_requests`.

### CACAT-05 — Review pembiayaan rawan double-approve (SEDANG)

`ReviewFinancing` memeriksa `status == 'pending'` di service, lalu `ApproveWithInstallments`
melakukan UPDATE tanpa klausa status. Dua request bersamaan bisa lolos pengecekan bersamaan
dan menghasilkan **dua set angsuran** untuk satu akad.
**Perbaikan**: `UPDATE ... WHERE id=? AND status='pending'` + cek `affected_rows`.

### CACAT-06 — Registrasi tidak atomik (SEDANG)

Bila Redis gagal menyimpan OTP (langkah 6), service mengembalikan error 500 padahal baris
`users` sudah ter-commit. Pengguna tidak bisa mendaftar ulang (email sudah ada) dan tidak
punya OTP. Rekening wajib (langkah 8) juga bisa gagal diam-diam — error-nya bahkan tidak di-log
(baris `slog.Error` dikomentari di `user_handler.go`).
**Perbaikan**: bungkus insert user + rekening wajib dalam satu DB transaction; bila Redis gagal,
sediakan endpoint `resend-otp` alih-alih meninggalkan akun tanpa jalan keluar.

### CACAT-07 — Batas 100 gram mengembalikan 500 pada `/gold/buy` (RENDAH)

`GoldHandler.BuyGold` tidak punya `case errors.Is(err, service.ErrExceedsTransactionLimit)`,
sehingga jatuh ke `default` → 500. `SellGold` menanganinya dengan 400.
**Perbaikan**: otomatis benar dengan `Api_exception`.

### CACAT-08 — Tidak ada endpoint manajemen harga emas (RENDAH)

`gold_prices` hanya diisi lewat seed migrasi. Tidak ada cara admin memperbarui harga selain
INSERT manual di database, dan cache Redis 15 menit tidak diinvalidasi.
**Perbaikan**: tambah `POST /admin/gold/price` yang INSERT baris baru + `DEL gold:current_price`.

### CACAT-09 — Tidak ada paginasi di endpoint admin (RENDAH sekarang, TINGGI nanti)

Lihat §17.1.

### CACAT-10 — Blocklist JWT memakai token penuh sebagai key (RENDAH)

`jwt_revoked:<token-lengkap>` — key Redis bisa >500 byte. Lebih hemat memakai
`jwt_revoked:` + `sha256(token)`. Sekaligus menghindari menyimpan token utuh di Redis.

### CACAT-11 — Status `active` pada financing tidak pernah dipakai (kosmetik)

CHECK constraint mengizinkan `active`, dokumentasi menyebut `approved → active → paid`,
tetapi kode langsung `approved → paid`. Putuskan: hapus dari CHECK, atau implementasikan
tahap pencairan dana yang mengubahnya menjadi `active`.

### CACAT-12 — Tidak ada penarikan dana (withdraw) untuk anggota

Tabel `savings_transactions` mendukung `type='withdraw'`, dan tipe itu dipakai oleh
pembayaran cicilan serta pembelian emas — tetapi **tidak ada endpoint** bagi anggota untuk
menarik simpanannya sendiri. Ini kemungkinan besar fitur yang belum dibuat, bukan bug;
rencanakan `POST /savings/withdraw` (dengan alur persetujuan seperti deposit) di versi PHP.

---

## 21. Roadmap Implementasi Bertahap

Urutan ini disusun agar setiap fase menghasilkan sesuatu yang **bisa diuji end-to-end**.

### Fase 0 — Fondasi (perkiraan 1–2 hari)

- [ ] Proyek CI3 + composer + `.env` + `env()` helper
- [ ] `config/koperasi.php`, `database.php` (`pconnect=FALSE`, `db_debug=FALSE`)
- [ ] Hook CORS + `.htaccess` (termasuk penerusan header `Authorization`)
- [ ] Database dibuat, 10 migrasi dijalankan, seed produk + harga emas masuk
- [ ] Library: `Money`, `Api_exception`, `Api_response`, `Validator`, `Redisx`
- [ ] `MY_Controller` (3 kelas)
- [ ] `GET /api/v1/health` hijau

**Selesai bila**: `curl localhost/api/v1/health` mengembalikan `{"status":"ok",...}` dengan
`database: ok` dan `redis: ok`.

### Fase 1 — Autentikasi (1–2 hari)

- [ ] `User_model` (insert, find_by_email/id, get_status, get_role, to_public, truthy)
- [ ] `Jwt_service`, `Ratelimit`, `Email_service`
- [ ] `User_service::register / login / verify_email`
- [ ] Controller `Auth` (4 endpoint) + `Profile::index`

**Selesai bila**: register → OTP muncul di log → verify-email menghasilkan token →
`GET /profile` dengan token itu mengembalikan data user **tanpa** `password_hash` →
login sebelum verifikasi mengembalikan 403 → logout membuat token yang sama ditolak 401.

### Fase 2 — Simpanan (2 hari)

- [ ] `Saving_model` (produk, rekening, ledger, template transaction)
- [ ] `Deposit_request_model`
- [ ] `Saving_service` termasuk `open_mandatory_accounts`
- [ ] Controller `Savings` (4 endpoint) + `Admin::review_deposit` (satu transaction)

**Selesai bila**: registrasi otomatis membuat 2 rekening bersaldo 0 → ajukan setoran 100 000 →
admin approve → saldo jadi 100 000 dan ada satu baris `savings_transactions` bertipe `deposit` →
approve kedua kali ditolak 422.

### Fase 3 — KYC (½ hari)

- [ ] `User_profile_model` (upsert + find)
- [ ] `Profile::get_kyc` (objek kosong bila belum ada) & `update_kyc`

### Fase 4 — Pembiayaan (2–3 hari)

- [ ] `Financing_model` (create, find, approve_with_installments, pay_installment)
- [ ] `Financing_service` (perhitungan margin + generator angsuran)
- [ ] Controller `Financing` (4 endpoint) + `Admin::review_financing`

**Selesai bila**: apply 12 000 000 / 12 bulan → margin 1 200 000, total 13 200 000 →
approve menghasilkan 12 angsuran yang **jumlah `amount_due`-nya persis 13 200 000** →
bayar angsuran mendebet saldo dan menulis ledger `cicilan_{id}` → setelah angsuran ke-12,
`financing.status` menjadi `paid`.

### Fase 5 — Emas off-chain (2 hari)

- [ ] `Gold_model` (harga + cache-aside, buy_with_debit, sell_with_credit, refund)
- [ ] `Gold_service` (batas 100 gram, perhitungan total, push antrian)
- [ ] Controller `Gold` (3 endpoint)
- [ ] **Perbaiki CACAT-01**: validasi kepemilikan emas saat jual

**Selesai bila**: beli 0.5 gram mendebet 849 000 (pada harga seed) dan membuat baris
`gold_transactions` berstatus `pending` + ledger `gold_buy_{id}` → jual melebihi kepemilikan ditolak 422.

### Fase 6 — Worker & blockchain (2–4 hari)

- [ ] `Gold_worker` CLI (start / recover / once)
- [ ] `Chain_client` + signer service (atau pertahankan worker Go)
- [ ] Supervisor/NSSM + cron `recover` tiap 5 menit
- [ ] Uji jalur refund: wallet kosong, mint gagal, receipt revert

**Selesai bila**: `php index.php cli/gold_worker once <id>` pada user tanpa `wallet_address`
mengembalikan saldo penuh dan menyetel status `failed` + ledger `gold_refund_{id}`.

### Fase 7 — Admin & pengerasan (1–2 hari)

- [ ] Endpoint admin + **paginasi**
- [ ] `POST /admin/gold/price` + invalidasi cache
- [ ] Rate limit di semua endpoint publik
- [ ] Logging terstruktur, `display_errors=Off` di production
- [ ] Audit: pastikan tidak ada endpoint yang membocorkan `password_hash`

---

## 22. Verifikasi Manual (Skenario Uji)

Jalankan berurutan; setiap langkah bergantung pada hasil sebelumnya.

```bash
BASE=http://localhost/api/v1

# 1) Health
curl -s $BASE/health | jq
# harap: {"status":"ok","services":{"database":"ok","redis":"ok"}}

# 2) Registrasi
curl -s -X POST $BASE/register -H 'Content-Type: application/json' \
  -d '{"nama_lengkap":"Budi Santoso","email":"budi@mail.com","password":"rahasia123"}' | jq
# harap 201 + user_id. Ambil OTP dari application/logs/log-*.php (mode simulasi)

# 3) Registrasi ulang dengan email sama → 409
curl -s -o /dev/null -w '%{http_code}\n' -X POST $BASE/register \
  -H 'Content-Type: application/json' \
  -d '{"nama_lengkap":"Budi Santoso","email":"budi@mail.com","password":"rahasia123"}'

# 4) Login sebelum verifikasi → 403
curl -s -X POST $BASE/login -H 'Content-Type: application/json' \
  -d '{"email":"budi@mail.com","password":"rahasia123"}' | jq

# 5) Verifikasi email (ganti OTP)
TOKEN=$(curl -s -X POST $BASE/verify-email -H 'Content-Type: application/json' \
  -d '{"email":"budi@mail.com","otp":"123456"}' | jq -r .token)
echo $TOKEN

# 6) Profil — pastikan TIDAK ada password_hash
curl -s $BASE/profile -H "Authorization: Bearer $TOKEN" | jq

# 7) Rekening wajib otomatis — harus ada 2 rekening bersaldo 0
curl -s $BASE/savings/accounts -H "Authorization: Bearer $TOKEN" | jq

# 8) Setoran di bawah minimum → 422
curl -s -X POST $BASE/savings/deposit -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"account_id":1,"amount":1000,"payment_method":"manual_transfer"}' | jq

# 9) Setoran valid → 201, status pending
curl -s -X POST $BASE/savings/deposit -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"account_id":1,"amount":5000000,"payment_method":"manual_transfer","reference_id":"TRF-001"}' | jq

# 10) Admin approve (butuh token admin; naikkan role lewat SQL untuk uji)
#     UPDATE users SET role='super_admin' WHERE email='admin@mail.com';
curl -s -X PUT $BASE/admin/savings/deposit-requests/1/review \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H 'Content-Type: application/json' \
  -d '{"action":"approve"}' | jq

# 11) Approve kedua kalinya → 422
curl -s -o /dev/null -w '%{http_code}\n' -X PUT $BASE/admin/savings/deposit-requests/1/review \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H 'Content-Type: application/json' \
  -d '{"action":"approve"}'

# 12) Saldo bertambah?
curl -s $BASE/savings/accounts -H "Authorization: Bearer $TOKEN" | jq '.accounts[0].balance'

# 13) Ajukan pembiayaan
curl -s -X POST $BASE/financing/apply -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"principal_amount":12000000,"duration_months":12}' | jq
# harap margin_amount = 1200000, total_payable = 13200000

# 14) Admin approve pembiayaan
curl -s -X PUT $BASE/admin/financing/1/review -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H 'Content-Type: application/json' -d '{"action":"approve"}' | jq

# 15) Jadwal angsuran — jumlah amount_due harus PERSIS 13200000
curl -s $BASE/financing/1/installments -H "Authorization: Bearer $TOKEN" \
  | jq '[.installments[].amount_due] | add'

# 16) Bayar angsuran pertama
curl -s -X POST $BASE/financing/installments/1/pay -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"savings_account_id":1}' | jq

# 17) Bayar lagi angsuran yang sama → 409
curl -s -o /dev/null -w '%{http_code}\n' -X POST $BASE/financing/installments/1/pay \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"savings_account_id":1}'

# 18) Harga emas (publik)
curl -s $BASE/gold/price | jq

# 19) Beli 0.5 gram
curl -s -X POST $BASE/gold/buy -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"gram_amount":0.5,"savings_account_id":1}' | jq

# 20) Beli 101 gram → 400 (BUKAN 500)
curl -s -o /dev/null -w '%{http_code}\n' -X POST $BASE/gold/buy \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"gram_amount":101,"savings_account_id":1}'

# 21) Jual lebih dari kepemilikan → 422 (perbaikan CACAT-01)
curl -s -X POST $BASE/gold/sell -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"gram_amount":50,"savings_account_id":1}' | jq

# 22) Logout, lalu pakai token lama → 401
curl -s -X POST $BASE/logout -H "Authorization: Bearer $TOKEN" | jq
curl -s -o /dev/null -w '%{http_code}\n' $BASE/profile -H "Authorization: Bearer $TOKEN"

# 23) Akses admin dengan token anggota → 403
curl -s -o /dev/null -w '%{http_code}\n' $BASE/admin/users -H "Authorization: Bearer $TOKEN"
```

### Uji konkurensi (wajib sebelum go-live)

Ini yang membedakan sistem keuangan yang benar dari yang tampak benar:

```bash
# Dua pembayaran angsuran yang sama secara bersamaan.
# Harapan: satu 200, satu 409. Saldo hanya berkurang SEKALI.
for i in 1 2; do
  curl -s -o /dev/null -w "%{http_code}\n" -X POST $BASE/financing/installments/2/pay \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
    -d '{"savings_account_id":1}' &
done; wait

# Dua pembelian emas bersamaan dengan saldo hanya cukup untuk satu.
# Harapan: satu 201, satu 422. Saldo TIDAK boleh minus.
```

Bila kedua uji ini lolos, pola `FOR UPDATE` sudah dipasang dengan benar.

### Verifikasi integritas buku besar

Query ini harus selalu mengembalikan **nol baris**. Jadikan cron harian:

```sql
-- Saldo rekening wajib sama dengan agregat buku besarnya
SELECT a.id, a.balance,
       COALESCE(SUM(CASE WHEN t.type='deposit'  THEN t.amount ELSE 0 END), 0)
     - COALESCE(SUM(CASE WHEN t.type='withdraw' THEN t.amount ELSE 0 END), 0) AS ledger_balance
  FROM savings_accounts a
  LEFT JOIN savings_transactions t ON t.savings_account_id = a.id
 GROUP BY a.id, a.balance
HAVING a.balance <> COALESCE(SUM(CASE WHEN t.type='deposit'  THEN t.amount ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN t.type='withdraw' THEN t.amount ELSE 0 END), 0);

-- Total angsuran wajib sama dengan total_payable
SELECT f.id, f.total_payable, SUM(i.amount_due) AS jumlah_angsuran
  FROM financing f
  JOIN financing_installments i ON i.financing_id = f.id
 GROUP BY f.id, f.total_payable
HAVING f.total_payable <> SUM(i.amount_due);

-- Transaksi emas menggantung > 1 jam (worker mati?)
SELECT id, status, created_at FROM gold_transactions
 WHERE status IN ('pending','processing') AND created_at < NOW() - INTERVAL '1 hour';
```

---

## Ringkasan Satu Halaman

| Aspek | Nilai |
|---|---|
| Endpoint | 24 (5 publik, 12 anggota, 7 admin) |
| Tabel | 9 + 1 tabel legacy tak terpakai (`simpanan`) |
| Operasi atomik kritis | 6 — approve deposit, approve financing, bayar angsuran, beli emas, jual emas, refund emas |
| Kunci Redis | `otp:{email}`, `jwt_revoked:{token}`, `gold:current_price`, `queue:gold_mint` |
| Konvensi `reference_id` | `cicilan_{id}`, `gold_buy_{id}`, `gold_sell_{id}`, `gold_refund_{id}` |
| Konstanta bisnis | margin 10%, maks 100 gram/tx, OTP 15 menit, JWT 24 jam, bcrypt cost 12, cache harga 15 menit |
| Presisi | Uang `DECIMAL(19,4)`, emas `DECIMAL(10,4)`, token on-chain 4 desimal (1 gram = 10 000 unit) |
| Cacat wajib diperbaiki | CACAT-01 (jual emas tanpa kepemilikan), CACAT-02 (mint gagal tanpa refund), CACAT-03 (admin banned lolos), CACAT-04 (approve setoran tak atomik) |

**Tiga hal yang paling menentukan berhasil atau tidaknya port ini:**

1. **Setiap perubahan saldo dalam satu transaction dengan `SELECT ... FOR UPDATE`.**
   Bukan sebagian — semua enam operasi di atas.
2. **Uang tidak pernah disentuh sebagai float.** bcmath dari input sampai sebelum serialisasi JSON.
3. **`password_hash` tidak pernah keluar dari model.** Di Go itu gratis lewat tag `json:"-"`;
   di PHP harus disengaja pada setiap jalur, termasuk `/admin/users`.
