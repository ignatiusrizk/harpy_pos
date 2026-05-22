-- ══════════════════════════════════════════════════════
-- loyalty_migration.sql — Loyalty poin lintas outlet (Fase 2)
-- Poin dikumpulkan di semua cabang, ditukar di cabang mana saja.
-- ══════════════════════════════════════════════════════

-- Saldo poin di level pelanggan (account-level, lintas outlet)
ALTER TABLE hl_pelanggan ADD COLUMN IF NOT EXISTS poin_balance INT DEFAULT 0;

-- Setting loyalty per tenant
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS loyalty_enabled TINYINT(1) DEFAULT 0;
-- Rp belanja per 1 poin (earn). Contoh 1000 = tiap Rp1.000 dapat 1 poin
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS loyalty_rupiah_per_poin INT DEFAULT 1000;
-- Nilai tukar 1 poin saat redeem (Rp). Contoh 100 = 1 poin = Rp100
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS loyalty_poin_value INT DEFAULT 100;

-- Ledger poin (earn / redeem / adjust)
CREATE TABLE IF NOT EXISTS hl_loyalty_log (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id     INT NOT NULL,
  outlet_id     INT DEFAULT NULL,
  pelanggan_id  INT NOT NULL,
  transaksi_id  INT DEFAULT NULL,
  type          ENUM('earn','redeem','adjust') NOT NULL,
  poin          INT NOT NULL,             -- + untuk earn, - untuk redeem
  balance_after INT NOT NULL,
  keterangan    VARCHAR(255) DEFAULT NULL,
  created_by    INT DEFAULT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pel (tenant_id, pelanggan_id),
  INDEX idx_trx (tenant_id, transaksi_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
