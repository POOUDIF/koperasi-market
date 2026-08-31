# Analisis Kesesuaian Implementasi vs `SYSTEM_FLOW_CI3_BLUEPRINT.md`

Dokumen ini membandingkan implementasi aktual di `c:\laragon\www\koperasi-market` dengan
spesifikasi di [`SYSTEM_FLOW_CI3_BLUEPRINT.md`](SYSTEM_FLOW_CI3_BLUEPRINT.md), disusun per
bagian blueprint. Setiap penyimpangan diberi label:

- **Deviasi disengaja/terdokumentasi** — sudah dicatat di `README.md`, biasanya perbaikan cacat
  (§20 blueprint) atau adaptasi platform (MySQL vs PostgreSQL).
- **Deviasi tak terdokumentasi tapi aman** — tidak disebut README, tapi tidak mengubah perilaku
  yang berdampak / merupakan penguatan wajar.
- **Hilang / belum dikerjakan** — bagian blueprint yang benar-benar belum ada di kode.

**Tanggal analisis:** 2026-09-01. Metodologi: 4 sub-analisis paralel (fondasi/infra, Auth-KYC-Savings,
Financing-Gold-Worker, Admin-Roadmap-Pengujian), masing-masing membaca blueprint per bagian dan
memverifikasi langsung ke source code + `Glob`/`Grep` filesystem (bukan hanya percaya README).

---

## Ringkasan Eksekutif

| Aspek | Status |
|---|---|
| Fondasi CI3 (config, MY_Controller, Money, Jwt, Redis, Ratelimit, Validator) | ✅ Sesuai, beberapa penguatan melampaui spesifikasi |
| Modul 1–5 (Auth, KYC, Savings, Financing, Gold) — 20 flow bisnis | ✅ Sesuai, termasuk kedua titik kritis race-condition |
| Cacat §20 (CACAT-01 s/d CACAT-12) | ✅ 9 dari 12 diperbaiki & diverifikasi benar; 3 belum (lihat detail) |
| Modul 6 — Worker emas & blockchain | ❌ **Belum ada sama sekali** (dikonfirmasi lewat Glob filesystem) |
| Endpoint & routing (§18) | ✅ 24 endpoint blueprint lengkap + 3 tambahan terdokumentasi = 27 total |
| Pengujian (§22) | ✅ 63 uji fungsional + 11 uji konkurensi, cakupan melebihi contoh blueprint; verifikasi ledger cron **belum ada** |
| Fase roadmap (§21) | Fase 0–5 selesai, Fase 6 belum, Fase 7 sebagian |

**Kesimpulan umum: implementasi sangat setia pada blueprint.** Hampir semua penyimpangan dari
teks blueprint literal adalah perbaikan cacat yang disengaja dan sudah didokumentasikan di
`README.md`, bukan bug baru. Satu gap besar yang nyata: **worker emas & integrasi blockchain
(Fase 6) 100% belum dibuat**, dan ini sudah diakui secara jujur oleh proyek sendiri.

---

## 1. Fondasi CodeIgniter 3 (Bagian B, §5–10)

### Langkah 0–1: Prasyarat & Struktur Direktori
- **Sesuai**: autoload Composer, `Dotenv::safeLoad()`, fungsi `env()`, timezone `Asia/Jakarta`
  (`application/config/config.php`), seluruh struktur direktori inti (`core/`, `libraries/`,
  `models/`, `controllers/api/v1/`).
- **Deviasi terdokumentasi**: ekstensi `mysqli` bukan `pgsql`/`pdo_pgsql` (README).
- **✅ Diperbaiki (2026-09-01)**: `application/libraries/Chain_client.php` dan
  `application/controllers/cli/Gold_worker.php` sudah dibuat dan diuji jalan (`once`/`recover`
  terverifikasi lewat CLI nyata, termasuk auto-refund saat `wallet_address` kosong). `Chain_client`
  memakai Opsi 2 blueprint (§16.4) — signer service Node.js terpisah di `signer-service/`, dijaga
  agar `OWNER_PRIVATE_KEY` **tidak pernah** masuk ke `.env` aplikasi CI3 ini (sesuai peringatan
  keamanan eksplisit blueprint), hanya ke `signer-service/.env` miliknya sendiri. Selama
  `signer-service` belum dijalankan dengan kontrak nyata (`SIGNER_SERVICE_URL`/`GOLD_CONTRACT_ADDRESS`
  kosong), worker berjalan aman di mode log-only — transaksi tetap `pending` dan di-requeue otomatis
  oleh `recover()`, bukan macet permanen seperti sebelumnya.
  **Catatan jujur**: `signer-service/server.js` adalah scaffold Opsi 2 (Express + ethers.js v6) yang
  belum pernah dites terhadap kontrak blockchain sungguhan — ABI-nya placeholder dan wajib diganti
  dengan ABI hasil compile kontrak CoopGold yang benar-benar di-deploy sebelum dipakai produksi.
- **Deviasi wajar tak terdokumentasi**: migrasi dikonsolidasi jadi `001_schema.sql` + `002_seed.sql`
  (bukan 10 file terpisah per tabel seperti blueprint); ada tambahan `MY_Model.php` dan
  `Notfound.php` (untuk `404_override`) yang tidak disebut blueprint tapi memperkuat struktur.

### Langkah 2: Konfigurasi Inti
- `koperasi.php`, `hooks.php`, `Cors.php` — **sesuai penuh** dengan blueprint, plus tambahan
  `page_size_default`/`page_size_max` untuk paginasi (CACAT-09).
- `database.php` — **sesuai struktural** (`pconnect=FALSE`, `db_debug=FALSE`); `dbdriver=mysqli`,
  `port=3306`, `char_set=utf8mb4` adalah deviasi platform terdokumentasi.
- Timezone/isolation level — implementasi menyatukan `SET SESSION time_zone` dan
  `transaction_isolation='READ-COMMITTED'` dalam satu tempat (`MY_Controller.php:27`), lebih rapi
  dari blueprint yang memisahkannya di dua bagian berbeda.

### Langkah 3: Skema Database (varian MySQL §9.4)
- **Sesuai** dengan seluruh padanan MySQL yang disyaratkan blueprint: `BIGINT UNSIGNED AUTO_INCREMENT`,
  `ENUM` inline, `ON UPDATE CURRENT_TIMESTAMP` menggantikan trigger, index penuh menggantikan partial index.
- **Deviasi terdokumentasi**: `deposit_requests.amount` `DECIMAL(19,4)` bukan `(15,2)` — README
  menjustifikasi ini dengan rujukan ke saran blueprint sendiri (§3.2) untuk menyeragamkan presisi.
- **Catatan tak terdokumentasi**: tabel `email_verification` di skema Go blueprint tidak ada di
  MySQL — OTP disimpan di Redis (`otp:<email>`), konsisten dengan pola state-sementara di seluruh
  sistem (rate limit, JWT blocklist), tapi tidak disebutkan eksplisit sebagai keputusan di README.

### Langkah 4: Komponen Fondasi Lintas-Modul
Semua 12 komponen (§10.1–10.12) — `Money`, `Api_exception`, `MY_Controller`, `Api_response`,
`Jwt_service`, `Redisx`, `Ratelimit`, `Validator`, pola transaction+row-locking, unique constraint
handling, `insert_id()`, `Email_service` — **sesuai dan pada beberapa titik melampaui spesifikasi**:

- `Money::clean()` menangani notasi ilmiah/nilai non-numerik yang blueprint tidak sebutkan eksplisit.
- `MY_Model::atomic()` memusatkan pola transaction (5 aturan wajib §10.9) alih-alih diulang di
  tiap model seperti contoh blueprint.
- `MY_Model::truthy()` menangani boolean Postgres (`'t'`/`'true'`) **dan** MySQL (`1`/`0'`) sekaligus
  — penerapan defensif dari jebakan §19.2.
- `Jwt_service` menambah validasi panjang secret minimal 32 karakter dengan log warning.
- `Email_service` menambah `Timeout=10` detik — penerapan konkret §19.4 (jangan panggil operasi
  jaringan tanpa timeout).

### §18 Routing & §19 Jebakan Migrasi Go→PHP
- Seluruh 24 route blueprint **cocok 1:1**, plus 3 route tambahan terdokumentasi.
- Dari 11 jebakan migrasi (§19.1–19.11): **9 sesuai** (presisi uang, boolean, decimal-as-string,
  defer-rollback, month-addition, `random_int`, header Authorization, `exit` di `fail()`, connection
  pool dicatat di README). **2 belum diverifikasi/belum ada**:
  - §19.4 (tidak ada goroutine, RPC blockchain harus async) — **✅ diikuti** oleh desain
    `Gold_worker` yang dibangun (Pilihan A §16.2: kirim transaksi lalu keluar, tidak menunggu
    receipt sinkron di loop utama; `_check_receipt()` dijalankan terpisah lewat `recover()`).
  - §19.7 (timeout request eksplisit / `max_execution_time`) — **✅ Diperbaiki (2026-09-01)**:
    `application/config/config.php` kini memanggil `ini_set('max_execution_time', 15)` untuk SAPI
    non-CLI (worker CLI tetap `set_time_limit(0)` seperti disyaratkan). Verifikasi: server dev tetap
    merespons `/api/v1/health` normal setelah perubahan. Konfigurasi level web server
    (`fastcgi_read_timeout`/`Timeout`) tetap di luar cakupan repo ini — itu ada di config
    Apache/nginx Laragon, bukan file proyek.

---

## 2. Model Data & State Machine (Bagian A §3–4)

Skema `database/migrations/001_schema.sql` cocok kolom-per-kolom dengan blueprint §3.2 untuk
seluruh 10 tabel (`users`, `user_profiles`, `savings_products`, `savings_accounts`,
`savings_transactions`, `deposit_requests`, `financing`, `financing_installments`, `gold_prices`,
`gold_transactions`), termasuk constraint (`CHECK balance >= 0`, `CHECK` nisbah 0–1, dll).
Satu-satunya deviasi adalah presisi `deposit_requests.amount` (terdokumentasi, lihat di atas).

State machine akun pengguna, permohonan setoran, pembiayaan, dan angsuran diverifikasi cocok
di level kode pada flow-flow di bawah.

---

## 3. Modul 1 — Autentikasi & Akun (FLOW-01–05)

| Flow | Status | Catatan |
|---|---|---|
| FLOW-01 Registrasi | ✅ Sesuai | 2 rekening wajib otomatis, OTP `random_int` 6 digit, hash bcrypt cost 12. Kegagalan `open_mandatory_accounts` kini **di-log** (CACAT-06 blueprint, baris log yang di Go dikomentari, di PHP tidak) |
| FLOW-02 Verifikasi OTP | ✅ Sesuai | `hash_equals()` anti timing-attack, OTP dihapus sekali pakai, auto-login |
| FLOW-03 Login | ✅ Sesuai | `password_verify` dummy saat email tak ditemukan (waktu respons seragam) — penambahan disengaja di luar blueprint literal, didokumentasikan README |
| FLOW-04 Logout/Blocklist | ✅ Sesuai + perbaikan | Key blocklist `jwt_revoked:sha256(token)` bukan token utuh (**CACAT-10 diperbaiki**); fail-open saat Redis error dipertahankan sesuai blueprint |
| FLOW-05 Profil | ✅ Sesuai | Cek status aktif dipindah ke layer `Auth_Controller` (middleware), bukan inline di method — hasil akhir sama |

**Hilang**: endpoint `resend-otp` yang diusulkan blueprint sebagai perbaikan CACAT-06 — **belum ada**
(diakui eksplisit di README).

---

## 4. Modul 2 — Profil KYC (FLOW-06–07)

- **FLOW-06 Simpan/Update**: `ON DUPLICATE KEY UPDATE` (padanan `ON CONFLICT`), plus **perbaikan
  disengaja**: NIK duplikat dilempar sebagai 409 (`nikTaken()`), persis rekomendasi blueprint
  baris 1892-1893.
- **FLOW-07 Baca KYC**: sesuai persis — 200 dengan objek kosong (bukan 404) saat profil belum diisi.

Tidak ada gap di modul ini.

---

## 5. Modul 3 — Simpanan Syariah (FLOW-08–12)

| Flow | Status | Catatan |
|---|---|---|
| FLOW-08 Buka Rekening | ✅ Sesuai | Tanpa pencegahan rekening ganda — sesuai perilaku Go yang disengaja dipertahankan |
| FLOW-09 Daftar Rekening | ✅ Sesuai | + JOIN `savings_products` untuk `product_name`/`akad_type` |
| FLOW-10 Ajukan Setoran | ✅ Sesuai + perbaikan | Menambahkan cek `account.status='active'` saat request (bukan hanya saat approve) — **persis rekomendasi blueprint** yang di Go tidak diterapkan |
| **FLOW-11 Admin Verifikasi Setoran (TITIK KRITIS)** | ✅ **Diperbaiki & terverifikasi benar** | Satu transaction: `SELECT ... FOR UPDATE` pada baris permohonan **dan** baris rekening, validasi status setelah lock, kredit pakai ekspresi relatif `balance = balance + ?`. Ini memperbaiki **CACAT-04** (Go: dua transaction terpisah). Uji konkurensi mengonfirmasi: dua approve serentak → 200 + 422, saldo naik sekali |
| FLOW-12 Riwayat Permohonan | ✅ Sesuai + paginasi | CACAT-09 |

---

## 6. Modul 4 — Pembiayaan Murabahah (FLOW-13–17)

| Flow | Status | Catatan |
|---|---|---|
| FLOW-13 Pengajuan | ✅ Sesuai | Margin/total via `Money`, retry 3× nomor akad, `Api_exception::financingNumberBusy()` (503) |
| FLOW-14 Daftar Saya | ✅ Sesuai + paginasi | |
| **FLOW-15 Admin Review (TITIK KRITIS)** | ✅ **Diperbaiki & terverifikasi benar** | `UPDATE ... WHERE id=? AND status='pending'` + cek `affected_rows()===0` → 409 (**CACAT-05 diperbaiki**), plus `UNIQUE KEY` pada nomor angsuran sebagai lapis pertahanan kedua |
| FLOW-16 Jadwal Angsuran | ✅ Sesuai | 404 (bukan 403) untuk kepemilikan orang lain |
| **FLOW-17 Bayar Angsuran (TITIK KRITIS)** | ✅ Sesuai | Transaction 7-langkah dengan `FOR UPDATE`, guard `AND status='unpaid'`, auto-close `financing.status='paid'` saat lunas |

---

## 7. Modul 5 — Emas Digital (FLOW-18–20)

| Flow | Status | Catatan |
|---|---|---|
| FLOW-18 Harga Emas (cache-aside) | ✅ Sesuai persis | Cache hit/miss/corrupt/DB-error semua ditangani sesuai alur blueprint. **CACAT-08 diperbaiki**: `POST /admin/gold/price` + invalidasi cache Redis |
| **FLOW-19 Beli Emas (TITIK KRITIS)** | ✅ Sesuai | Batas gram via `Api_exception::goldLimitExceeded()` → 400 (**CACAT-07 diperbaiki**, blueprint: Go mengembalikan 500), transaction atomik untuk debit+insert, RPUSH ke queue non-fatal setelah commit |
| **FLOW-20 Jual Emas (TITIK KRITIS)** | ✅ **Diperbaiki & terverifikasi tepat lokasinya** | `net_holding()` dihitung **di dalam transaction yang sama, setelah** baris rekening terkunci — persis lokasi yang disyaratkan blueprint (**CACAT-01, klasifikasi KRITIS, diperbaiki**) |

---

## 8. Modul 6 — Worker Emas & Blockchain ❌ BELUM ADA

**Terverifikasi lewat pencarian filesystem langsung, bukan hanya membaca README:**
- `Glob application/controllers/cli/*.php` → **tidak ada hasil**, direktori `cli/` bahkan tidak eksis.
- `Glob **/Chain_client.php` → **tidak ada di seluruh repo**.
- `Glob **/*orker*` → **tidak ada hasil**.

**Yang sudah ada**: `Gold_model::refund_failed_transaction()` — atomik, idempoten, sudah sesuai
spesifikasi §16.3, tapi **tidak pernah dipanggil** karena tidak ada worker.

**Konsekuensi nyata (diverifikasi, bukan asumsi)**: `Gold_service::buy()` melakukan `RPUSH` ke
`queue:gold_mint` tapi tidak ada consumer sama sekali di kode — transaksi `/gold/buy` macet di
status `pending` selamanya, saldo sudah terdebet tanpa mint maupun refund otomatis. README secara
eksplisit memperingatkan: **jangan aktifkan pembelian emas untuk pengguna nyata sebelum fase ini selesai.**

Skema DB (`gold_transactions.tx_hash`, status ENUM) sudah siap menerima hasil kerja worker — tidak
ada gap skema, murni gap kode worker + integrasi blockchain.

---

## 9. Status 12 Cacat Blueprint (§20)

| # | Cacat | Status | Bukti |
|---|---|---|---|
| 01 | Jual emas tanpa cek kepemilikan (KRITIS) | ✅ Diperbaiki | `Gold_model::sell_with_credit()` — `net_holding()` dalam transaction setelah lock |
| 02 | Mint gagal tidak refund (KRITIS) | ⚠️ Sebagian | Fungsi refund ada & atomik, tapi **tidak ada pemanggil** (worker belum ada) |
| 03 | Admin group tidak cek status akun | ✅ Diperbaiki | `Admin_Controller extends Auth_Controller` mewarisi cek status |
| 04 | Approve setoran 2 transaction terpisah | ✅ Diperbaiki | Satu transaction + `FOR UPDATE` pada baris permohonan |
| 05 | Review pembiayaan rawan double-approve | ✅ Diperbaiki | `WHERE status='pending'` + `affected_rows` check |
| 06 | Registrasi tidak atomik (log hilang) | ✅ Diperbaiki | Kegagalan rekening wajib kini di-log |
| 07 | Batas 100→500 gram di `/gold/buy` | ✅ Diperbaiki | 400 konsisten di kedua endpoint |
| 08 | Tidak ada endpoint harga emas | ✅ Diperbaiki | `POST /admin/gold/price` + invalidasi cache |
| 09 | Endpoint admin tanpa paginasi | ✅ Diperbaiki | `paging()` di semua endpoint admin/list |
| 10 | Blocklist JWT pakai token utuh | ✅ Diperbaiki | `sha256(token)` sebagai key |
| 11 | Status `active` financing tak pernah dipakai (kosmetik) | ❌ Belum | Masih ada di ENUM skema, tidak pernah ditransisikan ke status itu — blueprint sendiri menyerahkan ini ke keputusan pengembang |
| 12 | Tidak ada endpoint withdraw | ❌ Belum | Tidak ada di peta endpoint README/routes.php |

---

## 10. Endpoint, Roadmap, & Pengujian

### Inventaris Endpoint
24 endpoint blueprint (§1.2) **lengkap semua**, dipetakan 1:1 ke `routes.php` dan controller yang
benar-benar ada. Tambahan 3 endpoint terdokumentasi README: `GET /savings/products`,
`GET /gold/holding`, `POST /admin/gold/price` → **total 27**, cocok klaim README.

### Status Fase Roadmap (§21)

| Fase | Status |
|---|---|
| 0 — Fondasi | ✅ Selesai |
| 1 — Autentikasi | ✅ Selesai |
| 2 — Simpanan | ✅ Selesai |
| 3 — KYC | ✅ Selesai |
| 4 — Pembiayaan | ✅ Selesai |
| 5 — Emas off-chain | ✅ Selesai |
| 6 — Worker & blockchain | ❌ **Belum** |
| 7 — Admin & pengerasan | ⚠️ Sebagian (paginasi ✅, harga emas ✅, rate limit ✅; logging terstruktur & `display_errors=Off` produksi ❌) |

### Pengujian (§22)
- `tests/smoke_test.sh`: 63 assertion, mencakup **semua 23 langkah** skenario manual blueprint §22.
- `tests/concurrency_test.sh`: 11 assertion, mencakup **4 skenario** (melebihi 2 contoh eksplisit
  blueprint — ditambah double-approve setoran dan double-sell emas).
- **Tidak tercakup**: "Verifikasi integritas buku besar" (§22, baris 3527-3552) sebagai cron job
  harian — tidak ditemukan file/scheduled job untuk ini. Gap operasional, konsisten dengan Fase 6
  yang belum ada (poin "transaksi emas menggantung >1 jam" baru relevan setelah ada worker).

Semua klaim kuantitatif README ("27 endpoint", "63 uji fungsional", "11 uji konkurensi") **terverifikasi akurat**.

---

## 11. Rekomendasi Prioritas

1. **Prioritas tertinggi — Fase 6 (worker emas & blockchain)**: buat
   `application/controllers/cli/Gold_worker.php` (start/recover/once) dan
   `application/libraries/Chain_client.php`, lalu sambungkan ke `refund_failed_transaction()` yang
   sudah ada. Tanpa ini, `/gold/buy` tidak boleh dipakai produksi (sudah diperingatkan README).
   Tambahkan juga `POLYGON_RPC_URL`/`OWNER_PRIVATE_KEY` ke `.env.example`.
2. **§19.7 Timeout request**: tetapkan `max_execution_time` eksplisit (php.ini atau per-request) —
   belum ditemukan diterapkan di manapun.
3. **Verifikasi integritas buku besar (§22)**: jadikan cron/scheduled job, terutama setelah worker
   emas ada (deteksi transaksi menggantung).
4. **Fase 7 sisanya**: logging terstruktur, `display_errors=Off` untuk produksi.
5. **CACAT-12** (endpoint withdraw) dan **resend-otp**: masih belum ada — putuskan apakah masuk
   cakupan proyek saat ini atau didokumentasikan sebagai out-of-scope resmi.
6. CACAT-11 (status `active` financing tak terpakai) bersifat kosmetik, bisa diabaikan atau
   dibersihkan dari ENUM jika tidak akan dipakai.
