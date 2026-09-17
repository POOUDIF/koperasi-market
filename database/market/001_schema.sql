-- =====================================================================
-- Marketplace JDC — skema MySQL 8 (database terpisah: jdc_market)
--
--   mysql -u root -e "CREATE DATABASE jdc_market CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
--   mysql -u root jdc_market < database/market/001_schema.sql
--
-- Marketplace TIDAK pernah menyentuh saldo koperasi: pembayaran lewat API
-- internal Koperasi Pay (DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md §4).
-- Uang = DECIMAL(19,4) + bcmath (Money), sama dengan koperasi.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- ------------------------------------------------------------ customers
-- Proyeksi lokal akun JDC (JIT provisioning saat login SSO).
CREATE TABLE IF NOT EXISTS customers (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sso_sub    VARCHAR(64) NOT NULL,
  name       VARCHAR(150) NOT NULL,
  email      VARCHAR(255) NOT NULL,
  role       ENUM('customer','admin') NOT NULL DEFAULT 'customer',
  status     ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_customers_sub (sso_sub),
  KEY idx_customers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- stores
-- Satu akun = maksimal satu toko. Pemilik wajib anggota koperasi ber-KYC.
CREATE TABLE IF NOT EXISTS stores (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_customer_id BIGINT UNSIGNED NOT NULL,
  name              VARCHAR(100) NOT NULL,
  slug              VARCHAR(120) NOT NULL,
  description       TEXT NULL,
  city              VARCHAR(100) NOT NULL DEFAULT '',
  status            ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stores_owner (owner_customer_id),
  UNIQUE KEY uq_stores_slug (slug),
  CONSTRAINT fk_stores_owner FOREIGN KEY (owner_customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------- products
CREATE TABLE IF NOT EXISTS products (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id     BIGINT UNSIGNED NOT NULL,
  name         VARCHAR(150) NOT NULL,
  description  TEXT NULL,
  price        DECIMAL(19,4) NOT NULL,
  member_price DECIMAL(19,4) NULL DEFAULT NULL,
  stock        INT UNSIGNED NOT NULL DEFAULT 0,
  weight_gram  INT UNSIGNED NOT NULL DEFAULT 0,
  image_url    VARCHAR(500) NOT NULL DEFAULT '',
  status       ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_products_store (store_id, status),
  KEY idx_products_status_created (status, created_at),
  CONSTRAINT fk_products_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT chk_products_price CHECK (price > 0),
  CONSTRAINT chk_products_member_price CHECK (member_price IS NULL OR (member_price > 0 AND member_price <= price))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------- cart_items
CREATE TABLE IF NOT EXISTS cart_items (
  customer_id BIGINT UNSIGNED NOT NULL,
  product_id  BIGINT UNSIGNED NOT NULL,
  qty         INT UNSIGNED NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (customer_id, product_id),
  CONSTRAINT fk_cart_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_cart_product  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT chk_cart_qty CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- orders
-- Satu order = satu toko (settlement ke satu penjual).
--
-- payout_action/payout_status: pencairan ke penjual (settle) atau
-- pengembalian ke pembeli (refund) dicatat DULU di sini, lalu dieksekusi
-- lewat API koperasi. Yang gagal diulang `cli/orders maintenance`.
CREATE TABLE IF NOT EXISTS orders (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_number      VARCHAR(30) NOT NULL,
  buyer_customer_id BIGINT UNSIGNED NOT NULL,
  store_id          BIGINT UNSIGNED NOT NULL,
  status            ENUM('pending_payment','paid','shipped','completed','cancelled') NOT NULL DEFAULT 'pending_payment',
  subtotal          DECIMAL(19,4) NOT NULL,
  shipping_fee      DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
  total             DECIMAL(19,4) NOT NULL,
  recipient_name    VARCHAR(150) NOT NULL,
  recipient_phone   VARCHAR(20) NOT NULL,
  shipping_address  TEXT NOT NULL,
  buyer_note        VARCHAR(255) NOT NULL DEFAULT '',
  tracking_number   VARCHAR(100) NULL DEFAULT NULL,
  payment_method    ENUM('koperasi_pay') NOT NULL DEFAULT 'koperasi_pay',
  payment_intent_id VARCHAR(40) NULL DEFAULT NULL,
  payment_attempt   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  payout_action     ENUM('none','settle','refund') NOT NULL DEFAULT 'none',
  payout_status     ENUM('none','pending','done','failed') NOT NULL DEFAULT 'none',
  payout_error      VARCHAR(255) NULL DEFAULT NULL,
  cancel_reason     VARCHAR(255) NULL DEFAULT NULL,
  cancelled_by      ENUM('buyer','seller','admin','system') NULL DEFAULT NULL,
  paid_at           TIMESTAMP NULL DEFAULT NULL,
  shipped_at        TIMESTAMP NULL DEFAULT NULL,
  completed_at      TIMESTAMP NULL DEFAULT NULL,
  cancelled_at      TIMESTAMP NULL DEFAULT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_orders_number (order_number),
  KEY idx_orders_buyer (buyer_customer_id, created_at),
  KEY idx_orders_store (store_id, status, created_at),
  KEY idx_orders_intent (payment_intent_id),
  KEY idx_orders_status (status, created_at),
  KEY idx_orders_payout (payout_status),
  CONSTRAINT fk_orders_buyer FOREIGN KEY (buyer_customer_id) REFERENCES customers(id),
  CONSTRAINT fk_orders_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT chk_orders_total CHECK (total > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- order_items
-- Snapshot nama & harga saat checkout — perubahan produk tidak mengubah riwayat.
CREATE TABLE IF NOT EXISTS order_items (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id     BIGINT UNSIGNED NOT NULL,
  product_id   BIGINT UNSIGNED NOT NULL,
  product_name VARCHAR(150) NOT NULL,
  unit_price   DECIMAL(19,4) NOT NULL,
  qty          INT UNSIGNED NOT NULL,
  line_total   DECIMAL(19,4) NOT NULL,
  KEY idx_order_items_order (order_id),
  CONSTRAINT fk_order_items_order   FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------- webhook_events
-- Deduplikasi webhook Koperasi Pay (retry resmi mengirim event yang sama).
CREATE TABLE IF NOT EXISTS webhook_events (
  event_id    VARCHAR(40) NOT NULL PRIMARY KEY,
  event_type  VARCHAR(50) NOT NULL,
  received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
