-- ══════════════════════════════════════════════════════
-- layanan_master_migration.sql
-- Master katalog layanan terpusat (HQ) + link ke baris per-outlet
-- ══════════════════════════════════════════════════════

-- Master katalog di level tenant (dikelola dari HQ)
CREATE TABLE IF NOT EXISTS hl_layanan_master (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id        INT NOT NULL,
  nama             VARCHAR(100) NOT NULL,
  kategori         VARCHAR(50)  NOT NULL,
  satuan           VARCHAR(30)  NOT NULL DEFAULT 'kg',
  harga_default    DECIMAL(12,2) DEFAULT 0,
  urutan           INT          DEFAULT 0,
  is_active        TINYINT(1)   DEFAULT 1,
  allow_override   TINYINT(1)   DEFAULT 0,    -- outlet boleh adjust harga?
  override_max_pct DECIMAL(5,2) DEFAULT 0,    -- batas adjust (mis. 10 = ±10%)
  created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tenant (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Link baris layanan per-outlet ke master + flag override
ALTER TABLE hl_layanan ADD COLUMN IF NOT EXISTS master_id INT NULL;
ALTER TABLE hl_layanan ADD COLUMN IF NOT EXISTS harga_overridden TINYINT(1) DEFAULT 0;
ALTER TABLE hl_layanan ADD INDEX IF NOT EXISTS idx_master (master_id);

-- ── Segmen omset (kiloan / self_service / b2b / satuan / lainnya) ──
ALTER TABLE hl_layanan_master ADD COLUMN IF NOT EXISTS segmen VARCHAR(30) DEFAULT 'kiloan';
ALTER TABLE hl_layanan        ADD COLUMN IF NOT EXISTS segmen VARCHAR(30) DEFAULT 'kiloan';
