# Analisis Setup Payment Gateway — Koperasi Digital

Dokumen ini menganalisis apa yang perlu disiapkan untuk payment gateway yang proper di
`koperasi-market`, dengan kondisi saat ini: **belum ada kerja sama dengan pihak ketiga
(PG provider) apa pun**. Fokusnya bukan "cara integrasi API si X", tapi peta jalan lengkap:
apa yang bisa dikerjakan sekarang (tanpa PG), apa yang harus disiapkan sebelum bisa
mendaftar ke PG, dan bagaimana arsitektur kode disiapkan supaya penambahan PG nanti tidak
menjadi rombak ulang.

**Tanggal analisis:** 2026-09-14.

---

## 1. Kondisi Saat Ini (As-Is)

Hasil pemeriksaan kode aktual (`deposit_requests`, `withdraw_requests`,
`Deposit_request_model`, `Withdraw_request_model`, blueprint §13.3–13.5):

- Setoran (deposit) dan penarikan (withdraw) **100% manual**: anggota mengisi
  `payment_method` (saat ini cuma nilai bebas seperti `manual_transfer`) + upload
  `proof_image_url` (bukti transfer), lalu status masuk `pending`.
- **Tidak ada verifikasi otomatis apa pun** — admin yang melihat bukti transfer secara visual
  dan menekan approve/reject lewat `review()`.
- Saldo baru berubah setelah admin approve, di dalam satu DB transaction (`FOR UPDATE` +
  ledger) — ini bagian yang **sudah bagus** dan tidak perlu diubah walau PG masuk nanti,
  karena PG asli pun butuh pola "pending → credit saldo → catat ledger" yang identik, hanya
  pemicunya beda (webhook, bukan admin klik).
- Tidak ada tabel, kolom, atau library apa pun yang menyinggung payment gateway — ini
  murni pekerjaan baru, bukan pekerjaan setengah jadi.

**Kesimpulan:** sistem saat ini adalah *manual bank transfer confirmation*, bukan payment
gateway. Ini valid sebagai MVP, tapi tidak scalable (admin jadi bottleneck, rawan human
error, tidak real-time).

---

## 2. Kenapa Payment Gateway Butuh Pihak Ketiga (dan Tidak Bisa Di-skip)

Ini poin yang sering disalahpahami: payment gateway bukan sekadar "kode yang menerima uang",
tapi **jembatan legal + teknis ke sistem pembayaran** (bank, kartu, e-wallet, QRIS). Beberapa
alasan koperasi tidak bisa membangun ini sendiri tanpa pihak ketiga:

1. **Regulasi.** Di Indonesia, pihak yang boleh memproses transaksi pembayaran (menerbitkan
   virtual account, terima kartu, QRIS, dsb) adalah **PJP (Penyelenggara Jasa Pembayaran)**
   berlisensi Bank Indonesia — bukan sembarang badan usaha. Koperasi pada umumnya **tidak**
   punya lisensi ini, sehingga secara legal wajib menumpang ke PJP/PG resmi (Midtrans, Xendit,
   Doku, dsb) atau kerja sama langsung dengan bank. *(Ini area yang wajib dikonfirmasi ke
   legal/konsultan koperasi — jangan jadikan dokumen ini sebagai nasihat hukum final.)*
2. **PCI-DSS.** Kalau nanti mau terima kartu kredit/debit, menyimpan atau bahkan hanya
   *menyentuh* nomor kartu (PAN) di server sendiri menuntut sertifikasi PCI-DSS yang mahal
   dan berat. PG pihak ketiga menyediakan **hosted page / tokenization** supaya PAN tidak
   pernah singgah di server koperasi.
3. **Rekonsiliasi bank real-time.** Untuk tahu "duit benar-benar masuk" secara otomatis
   (bukan menerka dari foto bukti transfer), butuh akses ke mutasi rekening — ini yang
   disediakan PG lewat virtual account/callback, bukan sesuatu yang bisa dibuat sendiri tanpa
   kerja sama bank.
4. **Kepercayaan pengguna & dispute handling.** PG menyediakan mekanisme chargeback, refund,
   dan audit trail yang standar industri.

Jadi arah kerjanya bukan "hindari pihak ketiga", tapi **"siapkan semuanya supaya begitu
pihak ketiga dipilih, integrasinya tinggal plug-in"** — plus perbaiki alur manual yang ada
sekarang selagi menunggu.

---

## 3. Opsi yang Tersedia Sekarang (Sebelum Ada PG)

| Opsi | Deskripsi | Cocok untuk |
|---|---|---|
| **A. Perbaiki alur manual** | Tambah kode unik nominal (mis. Rp500.000 + 3 digit unik = Rp500.237) supaya admin/sistem bisa cocokkan mutasi rekening tanpa menerka dari foto | Sekarang juga, tanpa daftar ke mana pun |
| **B. Daftar ke PG pihak ketiga** | Midtrans, Xendit, Doku, iPaymu, Tripay, Duitku, dll — mendukung VA, e-wallet, QRIS | Setelah legalitas koperasi lengkap (lihat §5) |
| **C. Kerja sama langsung dengan bank (H2H)** | Direct host-to-host dengan bank untuk VA sendiri | Skala besar, butuh volume transaksi tinggi & negosiasi korporat — biasanya belum relevan di tahap ini |

### Opsi A — perbaikan yang bisa dikerjakan *hari ini*, tanpa pihak ketiga

Ini yang paling actionable sekarang karena tidak menunggu siapa pun:

- **Kode unik nominal**: tiap `deposit_request` diberi 3 digit unik acak yang ditambahkan ke
  `amount` saat generate instruksi transfer, lalu dicocokkan balik saat verifikasi. Ini
  mengurangi kesalahan admin mencocokkan mutasi secara manual, walau approve tetap manual.
- **Auto-expire permohonan pending**: `deposit_requests`/`withdraw_requests` yang tidak
  direspons dalam N jam otomatis `expired` (mengurangi antrian basi, sudah lazim di alur
  manual transfer manapun).
- **SLA & notifikasi admin**: pakai `Notification_model` yang sudah ada untuk memberi tahu
  admin ada permohonan baru, dan memberi tahu anggota saat status berubah — mengurangi waktu
  tunggu tanpa perlu otomatisasi penuh.
- **Audit log lebih ketat** pada `review()`: sudah ada `reviewed_by`/`reviewed_at`, pastikan
  juga dicatat alasan reject (kolom `rejection_reason` kalau belum ada) untuk transparansi ke
  anggota.

Semua ini murni perbaikan sistem yang sudah ada, dan **tidak akan sia-sia** ketika PG asli
masuk — auto-expire dan notifikasi tetap dipakai, kode unik jadi tidak relevan lagi karena
VA dari PG sudah unik per transaksi secara native.

---

## 4. Menyiapkan Arsitektur Kode Supaya "PG-Ready"

Bagian ini yang paling penting dari sisi teknis: supaya nanti tinggal pasang provider apa
pun (Midtrans, Xendit, atau lainnya) tanpa bongkar ulang alur `Saving_model::credit()` +
ledger yang sudah teruji.

### 4.1 Lapisan abstraksi (interface), bukan panggil SDK provider langsung

```
application/libraries/payment/
├── Payment_gateway_interface.php   // kontrak: createCharge(), checkStatus(), verifyWebhook()
├── Manual_transfer_gateway.php     // implementasi saat ini (bungkus alur existing)
└── (nanti) Midtrans_gateway.php / Xendit_gateway.php  // tinggal tambah, tidak ganti caller
```

Controller/model **tidak boleh** memanggil SDK Midtrans/Xendit langsung — selalu lewat
interface ini. Ini pola Strategy yang standar untuk kasus "provider akan berganti/bertambah".

### 4.2 Tabel baru: `payment_gateway_transactions`

Alih-alih menambah kolom provider-spesifik ke `deposit_requests`, buat tabel terpisah yang
menyimpan jejak mentah dari PG, dan tautkan ke `deposit_requests` yang sudah ada:

```sql
CREATE TABLE payment_gateway_transactions (
    id                BIGINT PRIMARY KEY AUTO_INCREMENT,
    deposit_request_id BIGINT NOT NULL,      -- FK ke deposit_requests
    provider          VARCHAR(30) NOT NULL,  -- 'manual', 'midtrans', 'xendit', dst
    external_id       VARCHAR(100) NOT NULL, -- order_id / reference dari PG
    status            VARCHAR(20) NOT NULL,  -- pending, paid, expired, failed
    raw_payload       JSON NOT NULL,         -- webhook body mentah, untuk audit & debug
    signature_valid   TINYINT(1) NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL,
    UNIQUE KEY uq_provider_external (provider, external_id)
);
```

Kenapa disimpan `raw_payload` mentah: kalau ada dispute atau bug pemetaan status, ini satu-
satunya sumber kebenaran yang tidak bisa didebat. Jangan cuma simpan hasil parse-nya.

### 4.3 Endpoint webhook — checklist keamanan wajib

Ini bagian yang paling sering jadi lubang keamanan kalau tergesa-gesa:

- **Verifikasi signature/HMAC** dari tiap provider (masing-masing punya cara beda —
  Midtrans pakai `signature_key` SHA512, Xendit pakai `X-CALLBACK-TOKEN` header). **Tolak
  request kalau signature tidak valid** — jangan proses dulu baru cek.
  Endpoint yang menerima dana sebelum verifikasi signature sama saja membuka pintu bagi siapa
  pun mengirim POST palsu "sudah dibayar" dan mengkredit saldo gratis.
- **Idempotency**: webhook bisa terkirim berkali-kali (retry dari provider). Gunakan
  `UNIQUE KEY (provider, external_id)` di atas + cek status sebelum proses ulang — pola ini
  sudah ada persis di `review()` yang sekarang (`FOR UPDATE` + cek status `pending`), tinggal
  dipakai ulang untuk trigger webhook, bukan trigger klik admin.
- **Jangan pernah percaya nominal dari payload webhook mentah-mentah** — selalu bandingkan
  dengan nominal yang tercatat di `deposit_requests` milik sistem sendiri sebelum credit.
- **Endpoint webhook harus publik (bisa diakses PG dari luar)** tapi **tidak boleh butuh JWT
  anggota** — ini beda dari pola `Auth_Controller` yang ada sekarang. Perlu Controller/rute
  terpisah dengan autentikasinya sendiri (whitelist IP provider jika didukung + signature).
- **Rate limit & logging** tetap dipasang (sudah ada `Ratelimit` di codebase) supaya endpoint
  ini tidak jadi target brute-force signature.
- **HTTPS wajib** di URL webhook yang didaftarkan ke provider — kebanyakan PG menolak
  mendaftarkan URL `http://`.

### 4.4 Mode sandbox

Semua PG di Indonesia menyediakan sandbox/testing environment tanpa perlu akun bisnis penuh
terverifikasi. Ini bisa mulai dicoba **sebelum** legalitas koperasi selesai, untuk validasi
arsitektur di atas (interface, webhook, idempotency) memakai kredensial sandbox gratis.
Rekomendasi: siapkan `PAYMENT_GATEWAY_PROVIDER=manual|midtrans_sandbox|...` di `.env`
mengikuti pola `SIGNER_SERVICE_URL` yang sudah ada — mudah dimatikan/diganti tanpa redeploy
kode.

---

## 5. Yang Harus Disiapkan Sebelum Bisa Mendaftar ke PG Manapun

Semua PG (Midtrans, Xendit, Doku, iPaymu, Tripay, Duitku, dll) mensyaratkan **KYB (Know Your
Business)** sebelum akun bisa dipakai untuk transaksi nyata (bukan sandbox). Dokumen yang
umum diminta:

- **Legalitas badan usaha koperasi**: akta pendirian + pengesahan Kemenkumham/Dinas Koperasi,
  NIB (Nomor Induk Berusaha), SIUP/izin usaha jika masih diminta.
- **NPWP** atas nama koperasi (bukan NPWP pribadi pengurus).
- **KTP & NPWP pengurus/penanggung jawab** yang akan jadi kontak legal ke PG.
- **Rekening bank atas nama koperasi** (bukan rekening pribadi) — dana settlement dari PG
  akan masuk ke sini.
- **Website/aplikasi yang sudah live** dengan Syarat & Ketentuan serta Kebijakan Privasi yang
  jelas — banyak PG melakukan review manual terhadap produk yang akan menggunakan gateway
  mereka (business model review), khususnya untuk sektor keuangan seperti koperasi simpan
  pinjam/syariah.
- **Deskripsi model bisnis** yang jelas untuk proses review — koperasi simpan pinjam kadang
  masuk kategori "regulated/high-risk" di mata sebagian PG, sehingga proses approval bisa
  lebih lama dan sebagian provider mungkin menolak kategori ini. **Sebaiknya hubungi tim
  sales/onboarding tiap kandidat PG di awal untuk konfirmasi apakah kategori koperasi simpan
  pinjam diterima**, sebelum menghabiskan waktu di satu provider tertentu.

### Pertimbangan khusus syariah

Karena produk ini "Koperasi Syariah Digital", ada pertanyaan tambahan yang baiknya
dikonsultasikan ke Dewan Pengawas Syariah (DPS) koperasi: payment gateway sendiri umumnya
netral (hanya jasa penyaluran dana, bukan akad), tapi perlu dipastikan:
- Tidak ada bunga/denda keterlambatan otomatis yang dikenakan PG di luar kendali koperasi.
- Mekanisme refund/dispute PG tidak bertentangan dengan akad yang dipakai (murabahah, dll).

Ini bukan masalah teknis, tapi baiknya masuk checklist sebelum tanda tangan kontrak dengan PG
manapun.

---

## 6. Peta Jalan yang Disarankan

| Fase | Yang dikerjakan | Butuh pihak ketiga? |
|---|---|---|
| **Fase 0 (sekarang)** | Perbaiki alur manual (§3 Opsi A): kode unik, auto-expire, notifikasi, audit log | Tidak |
| **Fase 1** | Bangun lapisan abstraksi `Payment_gateway_interface` + bungkus alur manual jadi `Manual_transfer_gateway` sebagai implementasi pertama (tidak mengubah perilaku, hanya refactor) | Tidak |
| **Fase 2** | Uji coba integrasi dengan **sandbox** salah satu PG (pilih 1, mulai dari yang paling umum dipakai UMKM/koperasi di Indonesia) untuk memvalidasi webhook + idempotency + signature verification di atas | Sandbox saja, tanpa kontrak bisnis |
| **Fase 3** | Lengkapi legalitas (§5), daftar akun bisnis PG, review DPS untuk aspek syariah, lalu go-live production dengan monitoring & rekonsiliasi harian | Ya, penuh |

Fase 0 dan 1 bisa dikerjakan **mulai sekarang tanpa menunggu siapa pun**, dan keduanya adalah
prasyarat teknis yang tetap dibutuhkan terlepas PG mana yang akhirnya dipilih di Fase 3.

---

## 7. Ringkasan Rekomendasi

1. Jangan menunggu pilihan PG untuk mulai kerja — perbaiki alur manual (Fase 0) dan bangun
   lapisan abstraksi (Fase 1) sekarang; keduanya tidak sia-sia apa pun provider yang dipilih
   nanti.
2. Mulai kumpulkan dokumen legalitas koperasi (§5) secara paralel — ini biasanya jadi
   bottleneck waktu terlama, bukan coding-nya.
3. Hubungi tim onboarding 2–3 kandidat PG lebih awal hanya untuk menanyakan **apakah kategori
   koperasi simpan pinjam/syariah diterima** dan dokumen apa yang mereka minta secara spesifik
   — jangan asumsikan semua provider punya kebijakan sama.
4. Saat integrasi sungguhan dimulai, jangan skip checklist keamanan webhook di §4.3 — ini
   bagian yang paling sering jadi celah kalau terburu-buru menuju demo/launch.
