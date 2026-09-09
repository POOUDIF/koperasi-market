-- =====================================================================
-- Notifikasi anggota (fitur baru — bukan bagian dari blueprint 24 endpoint).
-- Diisi otomatis saat admin mereview setoran/penarikan/pembiayaan.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

CREATE TABLE IF NOT EXISTS notifications (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT UNSIGNED NOT NULL,
  category   ENUM('simpanan','pinjaman','emas','sistem') NOT NULL DEFAULT 'sistem',
  title      VARCHAR(150) NOT NULL,
  message    VARCHAR(500) NOT NULL,
  is_read    TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notifications_user_created (user_id, created_at),
  KEY idx_notifications_user_unread (user_id, is_read),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
