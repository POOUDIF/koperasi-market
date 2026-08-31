-- =====================================================================
-- CACAT-11 (kosmetik) — 'active' dihapus dari financing.status.
--
-- Kode hanya pernah mentransisikan pending -> approved -> paid (atau
-- -> rejected); tidak ada tahap pencairan dana terpisah yang pernah
-- menyetel 'active'. Aman dijalankan di data yang sudah ada karena tidak
-- ada baris yang bisa berstatus 'active' (kalau ada, MODIFY di bawah akan
-- gagal dengan data truncated — itu sinyal untuk diselidiki, bukan diabaikan).
-- =====================================================================

ALTER TABLE financing
  MODIFY COLUMN status ENUM('pending','approved','paid','rejected') NOT NULL DEFAULT 'pending';
