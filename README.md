# Koperasi Syariah Digital — REST API (CodeIgniter 3 + PHP 7.4)

Implementasi dari [`SYSTEM_FLOW_CI3_BLUEPRINT.md`](SYSTEM_FLOW_CI3_BLUEPRINT.md),
port dari backend Go ke CodeIgniter 3.1.13.

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

### Prasyarat
PHP 7.4 (`bcmath`, `mysqli`, `mbstring`, `openssl`, `curl`, `json`),
MySQL 8, Redis 5+, Composer 2.

### Langkah

```bash
composer install
cp .env.example .env          # lalu isi JWT_SECRET (min. 32 karakter) & kredensial DB

# skema + seed
mysql -u root -e "CREATE DATABASE koperasi_digital CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root koperasi_digital < database/migrations/001_schema.sql
mysql -u root koperasi_digital < database/migrations/002_seed.sql

# pengembangan
php -S 127.0.0.1:8099 server.php

# produksi: arahkan document root ke folder ini; .htaccess sudah menangani
# rewrite + penerusan header Authorization (§19.10)
```

Cek: `curl http://127.0.0.1:8099/api/v1/health` → `{"status":"ok", …}`.

Tanpa `SMTP_HOST`, OTP tidak dikirim tapi ditulis ke `application/logs/log-*.php`
sebagai `[EMAIL SIMULATION] OTP 123456 untuk …` — itu mode pengembangan.

### Menaikkan role admin

```sql
UPDATE users SET role = 'super_admin' WHERE email = 'admin@mail.com';
```

### Uji

```bash
bash tests/smoke_test.sh                      # 63 assertion, alur §22
php -S 127.0.0.1:8098 server.php &            # instance kedua, wajib untuk uji berikut
bash tests/concurrency_test.sh 8099 8098      # 11 assertion, row locking
```

Uji konkurensi butuh **dua** instance karena `php -S` single-threaded —
satu instance akan menyerialkan request dan uji itu jadi tidak berarti.

---

## Peta endpoint

| Method | Path | Auth |
|---|---|---|
| GET | `/api/v1/health` | — |
| POST | `/api/v1/register` | rate-limit |
| POST | `/api/v1/login` | rate-limit |
| POST | `/api/v1/verify-email` | rate-limit |
| GET | `/api/v1/gold/price` | — |
| POST | `/api/v1/logout` | JWT |
| GET | `/api/v1/profile` | JWT |
| GET · PUT | `/api/v1/profile/kyc` | JWT |
| POST · GET | `/api/v1/savings/accounts` | JWT |
| GET | `/api/v1/savings/products` | JWT |
| POST | `/api/v1/savings/deposit` | JWT |
| GET | `/api/v1/savings/deposit-requests` | JWT |
| POST | `/api/v1/financing/apply` | JWT |
| GET | `/api/v1/financing` | JWT |
| GET | `/api/v1/financing/:id/installments` | JWT |
| POST | `/api/v1/financing/installments/:id/pay` | JWT |
| POST | `/api/v1/gold/buy` · `/gold/sell` | JWT |
| GET | `/api/v1/gold/holding` | JWT |
| PUT | `/api/v1/admin/financing/:id/review` | pengurus+ |
| PUT | `/api/v1/admin/savings/deposit-requests/:id/review` | pengurus+ |
| GET | `/api/v1/admin/savings/deposit-requests` | pengurus+ |
| GET | `/api/v1/admin/users` | pengurus+ |
| GET | `/api/v1/admin/transactions/{financing,gold,saving}` | pengurus+ |
| POST | `/api/v1/admin/gold/price` | pengurus+ |

Tiga di antaranya (`/savings/products`, `/gold/holding`, `/admin/gold/price`)
tidak ada di sistem Go; sisanya sepadan 1:1.

---

## Arsitektur

```
routes.php → controllers/api/v1/*   HTTP: bind, validasi, serialisasi. Tanpa logika bisnis.
           → libraries/*_service    Aturan bisnis: margin, jadwal angsuran, batas transaksi.
           → models/*_model         SQL mentah, transaction, FOR UPDATE, terjemahan error driver.
```

Rantai middleware Gin ditiru oleh tiga kelas di `application/core/MY_Controller.php`:
`API_Controller` → `Auth_Controller` → `Admin_Controller`.
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
`gold_refund_{id}`. Refund emas menemukan rekening asal lewat `gold_buy_{id}`.

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
