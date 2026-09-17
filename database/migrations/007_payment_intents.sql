-- =====================================================================
-- 007 — Koperasi Pay: pembayaran marketplace dari saldo simpanan (§4.3)
--
-- Alur dana:  rekening pembeli ──(held)──▶ rekening penampung
--                                   ├──(settled)──▶ rekening penjual
--                                   └──(refunded)─▶ rekening pembeli
--
-- Setiap perpindahan tercatat di savings_transactions (buku besar yang sama),
-- dengan reference_id: market_pay_{id}, market_settle_{id}, market_refund_{id}.
-- cli/ledger_audit memeriksa: saldo penampung = SUM(amount) intent 'held'.
--
-- CATATAN SYARIAH: akad rekening penampung (wadiah yad dhamanah) dan skema
-- fee marketplace WAJIB dikonfirmasi Dewan Pengawas Syariah sebelum go-live.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- -------------------------------------------------------- akun sistem
INSERT INTO savings_products (name, akad_type, min_deposit, profit_sharing_ratio, is_mandatory, is_system)
VALUES ('Rekening Penampung Marketplace', 'Wadiah', 0.0000, 0.0000, 0, 1)
ON DUPLICATE KEY UPDATE is_system = 1, is_mandatory = 0;

-- User sistem: tanpa sso_sub & tanpa password → tidak bisa login dengan cara apa pun.
INSERT INTO users (nama_lengkap, email, password_hash, role, status, is_email_verified)
VALUES ('Sistem — Penampung Marketplace', 'escrow.marketplace@system.internal', NULL, 'anggota', 'inactive', 1)
ON DUPLICATE KEY UPDATE status = 'inactive', password_hash = NULL;

INSERT INTO savings_accounts (user_id, savings_product_id, balance, status)
SELECT u.id, p.id, 0, 'active'
  FROM users u
  JOIN savings_products p ON p.name = 'Rekening Penampung Marketplace'
 WHERE u.email = 'escrow.marketplace@system.internal'
   AND NOT EXISTS (SELECT 1 FROM savings_accounts a WHERE a.user_id = u.id AND a.savings_product_id = p.id);

-- ---------------------------------------------------- payment_intents
CREATE TABLE IF NOT EXISTS payment_intents (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id         VARCHAR(40) NOT NULL,
  client_id         VARCHAR(64) NOT NULL,
  idempotency_key   VARCHAR(100) NOT NULL,
  request_hash      CHAR(64) NOT NULL,
  merchant_ref      VARCHAR(100) NOT NULL,
  payer_user_id     BIGINT UNSIGNED NOT NULL,
  payee_user_id     BIGINT UNSIGNED NOT NULL,
  amount            DECIMAL(19,4) NOT NULL,
  description       VARCHAR(255) NOT NULL,
  return_url        VARCHAR(500) NOT NULL,
  status            ENUM('requires_confirmation','held','settled','refunded','cancelled','expired')
                    NOT NULL DEFAULT 'requires_confirmation',
  source_account_id BIGINT UNSIGNED NULL DEFAULT NULL,
  payee_account_id  BIGINT UNSIGNED NULL DEFAULT NULL,
  expires_at        TIMESTAMP NOT NULL,
  held_at           TIMESTAMP NULL DEFAULT NULL,
  settled_at        TIMESTAMP NULL DEFAULT NULL,
  refunded_at       TIMESTAMP NULL DEFAULT NULL,
  cancelled_at      TIMESTAMP NULL DEFAULT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payment_intents_public (public_id),
  UNIQUE KEY uq_payment_intents_idem (client_id, idempotency_key),
  KEY idx_payment_intents_payer (payer_user_id, created_at),
  KEY idx_payment_intents_status (status, expires_at),
  CONSTRAINT fk_payment_intents_payer  FOREIGN KEY (payer_user_id) REFERENCES users(id),
  CONSTRAINT fk_payment_intents_payee  FOREIGN KEY (payee_user_id) REFERENCES users(id),
  CONSTRAINT fk_payment_intents_source FOREIGN KEY (source_account_id) REFERENCES savings_accounts(id),
  CONSTRAINT fk_payment_intents_payee_acc FOREIGN KEY (payee_account_id) REFERENCES savings_accounts(id),
  CONSTRAINT chk_payment_intents_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------- webhook_deliveries
-- Outbox: baris ditulis DALAM transaction yang sama dengan perubahan status
-- intent, jadi event tidak pernah hilang walau proses mati setelah commit.
CREATE TABLE IF NOT EXISTS webhook_deliveries (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id        VARCHAR(40) NOT NULL,
  intent_id       BIGINT UNSIGNED NOT NULL,
  client_id       VARCHAR(64) NOT NULL,
  event_type      VARCHAR(50) NOT NULL,
  payload         TEXT NOT NULL,
  attempts        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  delivered_at    TIMESTAMP NULL DEFAULT NULL,
  failed_at       TIMESTAMP NULL DEFAULT NULL,
  last_error      VARCHAR(500) NULL DEFAULT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_webhook_event (event_id),
  KEY idx_webhook_pending (delivered_at, failed_at, next_attempt_at),
  CONSTRAINT fk_webhook_intent FOREIGN KEY (intent_id) REFERENCES payment_intents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
