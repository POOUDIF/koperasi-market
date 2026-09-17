-- =====================================================================
-- JDC Account (Identity Provider OIDC) — skema MySQL 8
-- Database terpisah dari koperasi (lihat DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md §9).
--
--   mysql -u root -e "CREATE DATABASE jdc_account CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
--   mysql -u root jdc_account < database/account/001_schema.sql
--
-- Prinsip: token/ID sesi TIDAK PERNAH disimpan mentah, hanya SHA-256/HMAC-nya.
-- Kebocoran dump DB tidak memberi penyerang sesi, code, atau refresh token.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- ---------------------------------------------------------------- users
-- id = `sub` di token. Tidak pernah dipakai ulang; user lama koperasi
-- dimigrasi dengan id yang sama (§7).
CREATE TABLE IF NOT EXISTS users (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                VARCHAR(150) NOT NULL,
  email               VARCHAR(255) NOT NULL,
  password_hash       VARCHAR(255) NOT NULL,
  email_verified_at   TIMESTAMP NULL DEFAULT NULL,
  status              ENUM('active','banned') NOT NULL DEFAULT 'active',
  is_platform_admin   TINYINT(1) NOT NULL DEFAULT 0,
  password_changed_at TIMESTAMP NULL DEFAULT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------- email_tokens
-- OTP verifikasi email (6 digit) dan token reset password (256 bit).
-- token_hash = HMAC-SHA256(APP_KEY, purpose|user_id|token).
CREATE TABLE IF NOT EXISTS email_tokens (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  purpose     ENUM('verify_email','reset_password') NOT NULL,
  token_hash  CHAR(64) NOT NULL,
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at  TIMESTAMP NOT NULL,
  consumed_at TIMESTAMP NULL DEFAULT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_email_tokens_user (user_id, purpose, created_at),
  KEY idx_email_tokens_hash (token_hash),
  CONSTRAINT fk_email_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------- idp_sessions
-- Sesi SSO. `sid` publik (masuk klaim token & logout_token); `token_hash`
-- adalah hash nilai cookie.
CREATE TABLE IF NOT EXISTS idp_sessions (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sid           CHAR(32) NOT NULL,
  token_hash    CHAR(64) NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  auth_time     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  remember      TINYINT(1) NOT NULL DEFAULT 0,
  ip            VARCHAR(45) NOT NULL DEFAULT '',
  user_agent    VARCHAR(255) NOT NULL DEFAULT '',
  last_seen_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    TIMESTAMP NOT NULL,
  revoked_at    TIMESTAMP NULL DEFAULT NULL,
  revoke_reason VARCHAR(50) NULL DEFAULT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_idp_sessions_sid (sid),
  UNIQUE KEY uq_idp_sessions_token (token_hash),
  KEY idx_idp_sessions_user (user_id, revoked_at),
  CONSTRAINT fk_idp_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------- oauth_clients
CREATE TABLE IF NOT EXISTS oauth_clients (
  id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id                 VARCHAR(64) NOT NULL,
  name                      VARCHAR(100) NOT NULL,
  secret_hash               CHAR(64) NOT NULL,
  redirect_uris             TEXT NOT NULL,          -- JSON array
  post_logout_redirect_uris TEXT NOT NULL,          -- JSON array
  backchannel_logout_uri    VARCHAR(500) NULL DEFAULT NULL,
  grant_types               TEXT NOT NULL,          -- JSON array
  scopes                    TEXT NOT NULL,          -- JSON array
  audience                  VARCHAR(100) NULL DEFAULT NULL,
  is_first_party            TINYINT(1) NOT NULL DEFAULT 1,
  is_active                 TINYINT(1) NOT NULL DEFAULT 1,
  created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_oauth_clients_client_id (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------- idp_session_clients
-- Client mana saja yang pernah dimasuki sebuah sesi → target back-channel logout.
CREATE TABLE IF NOT EXISTS idp_session_clients (
  session_id     BIGINT UNSIGNED NOT NULL,
  client_id      VARCHAR(64) NOT NULL,
  first_login_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (session_id, client_id),
  CONSTRAINT fk_session_clients_session FOREIGN KEY (session_id) REFERENCES idp_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------- oauth_auth_codes
CREATE TABLE IF NOT EXISTS oauth_auth_codes (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code_hash      CHAR(64) NOT NULL,
  client_id      VARCHAR(64) NOT NULL,
  user_id        BIGINT UNSIGNED NOT NULL,
  session_id     BIGINT UNSIGNED NOT NULL,
  redirect_uri   VARCHAR(500) NOT NULL,
  scope          VARCHAR(500) NOT NULL,
  nonce          VARCHAR(255) NOT NULL,
  code_challenge VARCHAR(128) NOT NULL,
  expires_at     TIMESTAMP NOT NULL,
  used_at        TIMESTAMP NULL DEFAULT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_auth_codes_hash (code_hash),
  KEY idx_auth_codes_expires (expires_at),
  CONSTRAINT fk_auth_codes_user    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_auth_codes_session FOREIGN KEY (session_id) REFERENCES idp_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------- oauth_refresh_tokens
-- Rotasi: setiap pemakaian menandai used_at dan menerbitkan token baru dalam
-- family_id yang sama. Token used yang dipakai lagi = indikasi pencurian →
-- seluruh family dicabut.
CREATE TABLE IF NOT EXISTS oauth_refresh_tokens (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token_hash   CHAR(64) NOT NULL,
  family_id    CHAR(32) NOT NULL,
  client_id    VARCHAR(64) NOT NULL,
  user_id      BIGINT UNSIGNED NOT NULL,
  session_id   BIGINT UNSIGNED NOT NULL,
  auth_code_id BIGINT UNSIGNED NULL DEFAULT NULL,
  scope        VARCHAR(500) NOT NULL,
  expires_at   TIMESTAMP NOT NULL,
  used_at      TIMESTAMP NULL DEFAULT NULL,
  revoked_at   TIMESTAMP NULL DEFAULT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_refresh_tokens_hash (token_hash),
  KEY idx_refresh_tokens_family (family_id),
  KEY idx_refresh_tokens_session (session_id),
  KEY idx_refresh_tokens_code (auth_code_id),
  CONSTRAINT fk_refresh_tokens_user    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_refresh_tokens_session FOREIGN KEY (session_id) REFERENCES idp_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------- backchannel_jobs
-- Outbox back-channel logout. Dikirim langsung saat logout; yang gagal
-- diulang `php account.php cli/backchannel run` (cron tiap menit).
CREATE TABLE IF NOT EXISTS backchannel_jobs (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id       VARCHAR(64) NOT NULL,
  sid             CHAR(32) NOT NULL,
  sub             VARCHAR(64) NOT NULL,
  attempts        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  delivered_at    TIMESTAMP NULL DEFAULT NULL,
  failed_at       TIMESTAMP NULL DEFAULT NULL,
  last_error      VARCHAR(500) NULL DEFAULT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_backchannel_pending (delivered_at, failed_at, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------- audit_logs
-- subject_hash = SHA-256 email (lowercase) untuk menghitung kegagalan login
-- per email tanpa menyimpan email dari percobaan yang gagal.
CREATE TABLE IF NOT EXISTS audit_logs (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event        VARCHAR(50) NOT NULL,
  user_id      BIGINT UNSIGNED NULL DEFAULT NULL,
  subject_hash CHAR(64) NULL DEFAULT NULL,
  client_id    VARCHAR(64) NULL DEFAULT NULL,
  ip           VARCHAR(45) NOT NULL DEFAULT '',
  user_agent   VARCHAR(255) NOT NULL DEFAULT '',
  meta         TEXT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_subject (event, subject_hash, created_at),
  KEY idx_audit_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
