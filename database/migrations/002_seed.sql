-- =====================================================================
-- Seed WAJIB (§9.3).
-- Tanpa baris gold_prices, GET /gold/price mengembalikan 503 dan seluruh
-- transaksi beli/jual emas ikut gagal.
-- =====================================================================

SET time_zone = '+07:00';

INSERT INTO savings_products (name, akad_type, min_deposit, profit_sharing_ratio, is_mandatory) VALUES
  ('Simpanan Pokok',    'Wadiah',     50000.0000, 0.0000, 1),
  ('Simpanan Wajib',    'Wadiah',     10000.0000, 0.0000, 1),
  ('Simpanan Sukarela', 'Mudharabah', 10000.0000, 0.6000, 0)
ON DUPLICATE KEY UPDATE
  akad_type            = VALUES(akad_type),
  min_deposit          = VALUES(min_deposit),
  profit_sharing_ratio = VALUES(profit_sharing_ratio),
  is_mandatory         = VALUES(is_mandatory);

-- id dipatok 1 supaya re-run seed tidak pernah menimpa harga terbaru yang
-- sudah diinput admin lewat POST /admin/gold/price.
INSERT INTO gold_prices (id, buy_price_per_gram, sell_price_per_gram, updated_at)
VALUES (1, 1698000.0000, 1672000.0000, NOW())
ON DUPLICATE KEY UPDATE id = id;
