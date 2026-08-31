-- =====================================================================
-- CACAT-12 — Penarikan dana (withdraw) anggota, belum ada di sistem Go.
-- Pola identik dengan deposit_requests: anggota mengajukan, admin
-- menyetujui dalam satu transaction terkunci (Withdraw_request_model::review()).
-- =====================================================================

CREATE TABLE IF NOT EXISTS withdraw_requests (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id              BIGINT UNSIGNED NOT NULL,
  savings_account_id   BIGINT UNSIGNED NOT NULL,
  amount               DECIMAL(19,4) NOT NULL,
  destination_account  VARCHAR(100) NOT NULL,
  reference_id         VARCHAR(100) NOT NULL DEFAULT '',
  status               ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by          BIGINT UNSIGNED NULL,
  reviewed_at          TIMESTAMP NULL DEFAULT NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_withdraw_requests_user (user_id),
  KEY idx_withdraw_requests_status (status),
  KEY idx_withdraw_requests_created (created_at),
  CONSTRAINT fk_withdraw_requests_user     FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_withdraw_requests_account  FOREIGN KEY (savings_account_id) REFERENCES savings_accounts(id),
  CONSTRAINT fk_withdraw_requests_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id),
  CONSTRAINT chk_withdraw_requests_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
