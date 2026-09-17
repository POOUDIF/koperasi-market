-- =====================================================================
-- 006 — SSO, keanggotaan terpisah dari akun, PIN transaksi
-- (DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md §3.6, §5.1)
--
-- * Identitas (email, password, verifikasi) kini milik JDC Account (IdP).
--   users di sini adalah PROYEKSI lokal yang ditautkan lewat sso_sub.
-- * Punya akun JDC ≠ anggota koperasi. member_since NULL = belum aktivasi
--   (rekening wajib belum dibuka). Anggota lama dianggap anggota sejak dibuat.
-- * password_hash dipertahankan (nullable) sampai masa transisi selesai
--   supaya rollback ke AUTH_MODE=legacy tetap mungkin (§6 Fase 4).
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

ALTER TABLE users
  ADD COLUMN sso_sub             VARCHAR(64) NULL DEFAULT NULL AFTER id,
  ADD COLUMN member_since        TIMESTAMP NULL DEFAULT NULL AFTER status,
  ADD COLUMN pin_hash            VARCHAR(255) NULL DEFAULT NULL AFTER member_since,
  ADD COLUMN pin_failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER pin_hash,
  ADD COLUMN pin_locked_until    TIMESTAMP NULL DEFAULT NULL AFTER pin_failed_attempts,
  MODIFY COLUMN password_hash    VARCHAR(255) NULL DEFAULT NULL,
  ADD UNIQUE KEY uq_users_sso_sub (sso_sub);

UPDATE users SET member_since = created_at WHERE member_since IS NULL;

-- Produk sistem (rekening penampung) tidak boleh terlihat / dibuka anggota.
ALTER TABLE savings_products
  ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER is_mandatory;
