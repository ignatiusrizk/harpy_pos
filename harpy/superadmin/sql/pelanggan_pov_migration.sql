-- ══════════════════════════════════════════════════════
-- pelanggan_pov_migration.sql
-- Pelanggan POV — Loyalti, Tier, Segmen, Preferensi, Reward
--
-- KEEPS existing:
--   - hl_pelanggan.poin_balance (single INT, di-manage core/Loyalty.php)
--   - hl_loyalty_log (ledger earn/redeem/adjust)
--   - tenants.loyalty_enabled / loyalty_rupiah_per_poin / loyalty_poin_value
--
-- ADDS:
--   - hl_pelanggan: tier, segmen, preferensi_*, last_transaksi, segmen_updated_at
--   - hl_loyalty_log: reward_id, expired_at
--   - hl_poin_reward (table baru — katalog reward)
--   - hl_notif_log (table baru — anti-spam dormant reminder)
--   - tenants.loyalty_expiry_months (configurable expiry)
--
-- IDEMPOTENT: aman dijalankan ulang.
-- ══════════════════════════════════════════════════════

-- 1) hl_pelanggan — kolom baru
ALTER TABLE hl_pelanggan
  ADD COLUMN IF NOT EXISTS tier
    ENUM('regular','silver','gold','platinum') DEFAULT 'regular',
  ADD COLUMN IF NOT EXISTS segmen
    ENUM('baru','regular','vip','dormant') DEFAULT 'baru',
  ADD COLUMN IF NOT EXISTS segmen_updated_at DATE NULL,
  ADD COLUMN IF NOT EXISTS last_transaksi DATE NULL,
  ADD COLUMN IF NOT EXISTS preferensi_parfum VARCHAR(50) NULL,
  ADD COLUMN IF NOT EXISTS preferensi_suhu VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS catatan_tetap TEXT NULL;

-- Index untuk filter segmen/tier di customer.php
-- Pakai IF NOT EXISTS (MariaDB 10.5+) — kalau MariaDB lama error #1061
-- duplicate, abaikan saja.
CREATE INDEX IF NOT EXISTS idx_pelanggan_segmen ON hl_pelanggan(tenant_id, segmen);
CREATE INDEX IF NOT EXISTS idx_pelanggan_tier   ON hl_pelanggan(tenant_id, tier);

-- 2) hl_loyalty_log — track reward redeem + poin expiry
ALTER TABLE hl_loyalty_log
  ADD COLUMN IF NOT EXISTS reward_id INT NULL AFTER transaksi_id,
  ADD COLUMN IF NOT EXISTS expired_at DATE NULL AFTER keterangan;

CREATE INDEX IF NOT EXISTS idx_loyalty_expired ON hl_loyalty_log(tenant_id, expired_at);

-- 3) hl_poin_reward — katalog reward yang bisa diredeem
CREATE TABLE IF NOT EXISTS hl_poin_reward (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id            INT NOT NULL,
  outlet_id            INT NOT NULL,
  nama_reward          VARCHAR(100) NOT NULL,
  deskripsi            TEXT NULL,
  poin_dibutuhkan      INT NOT NULL,
  tipe                 ENUM('diskon_nominal','diskon_persen','gratis_layanan') NOT NULL,
  nilai                INT NOT NULL,
  min_transaksi        INT DEFAULT 0,
  max_redeem_per_bulan INT DEFAULT 0,
  is_active            TINYINT(1) DEFAULT 1,
  created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant_outlet (tenant_id, outlet_id, is_active),
  INDEX idx_poin_needed   (poin_dibutuhkan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) hl_notif_log — REUSE existing table dari Owner POV (owner_visibility_migration)
-- Tambah kolom pelanggan_id supaya bisa di-pakai untuk dormant reminder per-pelanggan.
ALTER TABLE hl_notif_log
  ADD COLUMN IF NOT EXISTS pelanggan_id INT NULL AFTER outlet_id;

-- Index untuk pencarian dormant reminder per pelanggan + daily quota check
CREATE INDEX IF NOT EXISTS idx_notif_pel_type ON hl_notif_log(pelanggan_id, type, sent_at);
CREATE INDEX IF NOT EXISTS idx_notif_outlet_type ON hl_notif_log(tenant_id, outlet_id, type, sent_at);

-- 5) tenants — config expiry poin
ALTER TABLE tenants
  ADD COLUMN IF NOT EXISTS loyalty_expiry_months INT DEFAULT 12;

-- 6) Seed 5 reward default — hanya untuk tenant yang BELUM punya reward apapun
INSERT INTO hl_poin_reward
  (tenant_id, outlet_id, nama_reward, poin_dibutuhkan, tipe, nilai)
SELECT t.id, o.id, r.nama, r.poin, r.tipe, r.nilai
FROM tenants t
JOIN outlets o ON o.tenant_id=t.id AND o.is_main=1
CROSS JOIN (
  SELECT 'Diskon Rp 10.000'              AS nama,  50 AS poin, 'diskon_nominal' AS tipe, 10000 AS nilai UNION ALL
  SELECT 'Gratis Cuci 1 kg',                       100,        'gratis_layanan',         1            UNION ALL
  SELECT 'Diskon Rp 25.000',                       200,        'diskon_nominal',     25000            UNION ALL
  SELECT 'Gratis Cuci + Setrika 3 kg',             300,        'gratis_layanan',         3            UNION ALL
  SELECT 'Gratis Cuci 5 kg + Parfum',              500,        'gratis_layanan',         5
) r
WHERE NOT EXISTS (
  SELECT 1 FROM hl_poin_reward x WHERE x.tenant_id=t.id
);

-- 7) Backfill last_transaksi dari data existing (sekali jalan)
UPDATE hl_pelanggan p
SET last_transaksi = (
  SELECT MAX(DATE(t.tanggal))
    FROM hl_transaksi t
   WHERE t.pelanggan_id = p.id AND t.tenant_id = p.tenant_id
)
WHERE p.last_transaksi IS NULL;
