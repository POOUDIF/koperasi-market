-- =====================================================================
-- Koperasi Syariah Digital - Skema MySQL 8 (§9.2 / §9.4)
-- Port dari db/migrations/001..010 versi PostgreSQL.
--
-- Perubahan yang disengaja terhadap sumber Go:
--   * deposit_requests.amount dinaikkan (15,2) -> (19,4) agar presisi uang
--     seragam di seluruh sistem (§3.2).
--   * Partial index PostgreSQL menjadi index penuh (MySQL tidak punya).
--   * TIMESTAMPTZ -> TIMESTAMP; session time_zone dipaksa '+07:00'.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- ---------------------------------------------------------------- users
CREATE TABLE IF NOT EXISTS users (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nama_lengkap      VARCHAR(150) NOT NULL,
  email             VARCHAR(255) NOT NULL,
  password_hash     VARCHAR(255) NOT NULL,
  role              ENUM('anggota','pengurus','admin','super_admin') NOT NULL DEFAULT 'anggota',
  wallet_address    VARCHAR(42) NULL,
  status            ENUM('active','inactive','banned') NOT NULL DEFAULT 'active',
  is_email_verified TINYINT(1) NOT NULL DEFAULT 0,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_wallet_address (wallet_address),
  KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------ savings_products
CREATE TABLE IF NOT EXISTS savings_products (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                 VARCHAR(100) NOT NULL,
  akad_type            ENUM('Wadiah','Mudharabah') NOT NULL,
  min_deposit          DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
  profit_sharing_ratio DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
  is_mandatory         TINYINT(1) NOT NULL DEFAULT 0,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_savings_products_name (name),
  KEY idx_savings_products_mandatory (is_mandatory),
  CONSTRAINT chk_products_ratio CHECK (profit_sharing_ratio >= 0 AND profit_sharing_ratio <= 1),
  CONSTRAINT chk_products_min_deposit CHECK (min_deposit >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------ savings_accounts
CREATE TABLE IF NOT EXISTS savings_accounts (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id            BIGINT UNSIGNED NOT NULL,
  savings_product_id BIGINT UNSIGNED NOT NULL,
  balance            DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
  status             ENUM('active','frozen','closed') NOT NULL DEFAULT 'active',
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_savings_accounts_user (user_id),
  KEY idx_savings_accounts_product (savings_product_id),
  CONSTRAINT fk_savings_accounts_user    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_savings_accounts_product FOREIGN KEY (savings_product_id) REFERENCES savings_products(id),
  -- Saldo tidak pernah boleh negatif. Jaring pengaman terakhir di level DB:
  -- kalau ada jalur kode yang lolos validasi, UPDATE-nya tetap ditolak.
  CONSTRAINT chk_savings_accounts_balance CHECK (balance >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------- savings_transactions
-- BUKU BESAR APPEND-ONLY. Baris tidak pernah di-UPDATE atau di-DELETE;
-- karena itu tidak ada kolom updated_at.
CREATE TABLE IF NOT EXISTS savings_transactions (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  savings_account_id BIGINT UNSIGNED NOT NULL,
  type               ENUM('deposit','withdraw') NOT NULL,
  amount             DECIMAL(19,4) NOT NULL,
  reference_id       VARCHAR(100) NOT NULL DEFAULT '',
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_savings_tx_account (savings_account_id),
  KEY idx_savings_tx_created (created_at),
  -- Dipakai refund emas untuk menemukan rekening asal lewat 'gold_buy_{id}' (§3.2).
  KEY idx_savings_tx_reference (reference_id),
  CONSTRAINT fk_savings_tx_account FOREIGN KEY (savings_account_id) REFERENCES savings_accounts(id),
  CONSTRAINT chk_savings_tx_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------ deposit_requests
CREATE TABLE IF NOT EXISTS deposit_requests (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id            BIGINT UNSIGNED NOT NULL,
  savings_account_id BIGINT UNSIGNED NOT NULL,
  amount             DECIMAL(19,4) NOT NULL,
  payment_method     VARCHAR(50) NOT NULL,
  proof_image_url    VARCHAR(255) NOT NULL DEFAULT '',
  reference_id       VARCHAR(100) NOT NULL DEFAULT '',
  status             ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by        BIGINT UNSIGNED NULL,
  reviewed_at        TIMESTAMP NULL DEFAULT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_deposit_requests_user (user_id),
  KEY idx_deposit_requests_status (status),
  KEY idx_deposit_requests_created (created_at),
  CONSTRAINT fk_deposit_requests_user     FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_deposit_requests_account  FOREIGN KEY (savings_account_id) REFERENCES savings_accounts(id),
  CONSTRAINT fk_deposit_requests_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id),
  CONSTRAINT chk_deposit_requests_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------- financing
CREATE TABLE IF NOT EXISTS financing (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  financing_number VARCHAR(50) NOT NULL,
  user_id          BIGINT UNSIGNED NOT NULL,
  akad             ENUM('murabahah') NOT NULL DEFAULT 'murabahah',
  principal_amount DECIMAL(19,4) NOT NULL,
  -- Dikunci selamanya setelah akad. Inti kepatuhan syariah murabahah:
  -- margin tidak boleh berubah mengikuti waktu atau keterlambatan bayar.
  margin_amount    DECIMAL(19,4) NOT NULL,
  total_payable    DECIMAL(19,4) NOT NULL,
  duration_months  INT NOT NULL,
  -- CACAT-11: 'active' dihapus dari daftar status — kode hanya pernah
  -- mentransisikan pending->approved->paid (atau ->rejected). Tidak ada
  -- tahap pencairan dana terpisah yang memakainya.
  status           ENUM('pending','approved','paid','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by      BIGINT UNSIGNED NULL,
  reviewed_at      TIMESTAMP NULL DEFAULT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_financing_number (financing_number),
  KEY idx_financing_user (user_id),
  KEY idx_financing_status (status),
  KEY idx_financing_reviewed_by (reviewed_by),
  CONSTRAINT fk_financing_user     FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_financing_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id),
  CONSTRAINT chk_financing_principal CHECK (principal_amount > 0),
  CONSTRAINT chk_financing_margin    CHECK (margin_amount >= 0),
  CONSTRAINT chk_financing_duration  CHECK (duration_months >= 1 AND duration_months <= 360)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------ financing_installments
CREATE TABLE IF NOT EXISTS financing_installments (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  financing_id       BIGINT UNSIGNED NOT NULL,
  installment_number INT NOT NULL,
  amount_due         DECIMAL(19,4) NOT NULL,
  amount_paid        DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
  due_date           DATE NOT NULL,
  status             ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
  paid_at            TIMESTAMP NULL DEFAULT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- Mencegah dua set angsuran untuk satu akad bila approve terjadi ganda.
  UNIQUE KEY uq_installment_no (financing_id, installment_number),
  KEY idx_installments_status (financing_id, status),
  CONSTRAINT fk_installments_financing FOREIGN KEY (financing_id) REFERENCES financing(id) ON DELETE CASCADE,
  CONSTRAINT chk_installments_due  CHECK (amount_due > 0),
  CONSTRAINT chk_installments_paid CHECK (amount_paid >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------- gold_prices
CREATE TABLE IF NOT EXISTS gold_prices (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  buy_price_per_gram  DECIMAL(19,4) NOT NULL,
  sell_price_per_gram DECIMAL(19,4) NOT NULL,
  -- Baris terbaru ditentukan ORDER BY updated_at DESC, BUKAN id (§3.2).
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_gold_prices_updated (updated_at),
  CONSTRAINT chk_gold_prices CHECK (buy_price_per_gram > 0 AND sell_price_per_gram > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------- gold_transactions
CREATE TABLE IF NOT EXISTS gold_transactions (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        BIGINT UNSIGNED NOT NULL,
  type           ENUM('buy','sell') NOT NULL,
  -- 4 desimal, sepadan dengan kontrak CoopGold: 1 gram = 10.000 unit on-chain.
  gram_amount    DECIMAL(10,4) NOT NULL,
  price_per_gram DECIMAL(19,4) NOT NULL,
  total_rupiah   DECIMAL(19,4) NOT NULL,
  tx_hash        VARCHAR(100) NULL,
  status         ENUM('pending','processing','success','failed') NOT NULL DEFAULT 'pending',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_gold_tx_user (user_id),
  KEY idx_gold_tx_status (status),
  KEY idx_gold_tx_holding (user_id, type, status),
  KEY idx_gold_tx_created (created_at),
  CONSTRAINT fk_gold_tx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_gold_tx_gram CHECK (gram_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------- user_profiles
CREATE TABLE IF NOT EXISTS user_profiles (
  user_id                 BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  nik                     VARCHAR(16) NOT NULL,
  phone_number            VARCHAR(20) NOT NULL,
  address                 TEXT NOT NULL,
  job_title               VARCHAR(100) NOT NULL,
  monthly_income          DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
  emergency_contact_name  VARCHAR(150) NOT NULL,
  emergency_contact_phone VARCHAR(20) NOT NULL,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_profiles_nik (nik),
  CONSTRAINT fk_user_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_user_profiles_income CHECK (monthly_income >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
