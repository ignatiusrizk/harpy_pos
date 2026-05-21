-- ══════════════════════════════════════════════════════
-- checklist_migration.sql
-- Checklist harian outlet (dibuat HQ, diisi staff tiap outlet)
-- ══════════════════════════════════════════════════════

-- Template checklist (dibuat di HQ)
CREATE TABLE IF NOT EXISTS hl_checklist_template (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id   INT NOT NULL,
  judul       VARCHAR(150) NOT NULL,
  deskripsi   VARCHAR(500) DEFAULT NULL,
  items_json  JSON NOT NULL,                 -- [{"text":"...","required":1}, ...]
  frequency   VARCHAR(20) NOT NULL DEFAULT 'daily',  -- daily / weekly
  is_active   TINYINT(1)  DEFAULT 1,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tenant (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Submission per outlet per hari
CREATE TABLE IF NOT EXISTS hl_checklist_submission (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id        INT NOT NULL,
  outlet_id        INT NOT NULL,
  template_id      INT NOT NULL,
  tanggal          DATE NOT NULL,
  answers_json     JSON NOT NULL,            -- {"0":{"checked":1,"note":""}, ...}
  total_items      INT DEFAULT 0,
  checked_items    INT DEFAULT 0,
  submitted_by     INT DEFAULT NULL,
  submitted_by_nama VARCHAR(100) DEFAULT NULL,
  submitted_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_sub (tenant_id, outlet_id, template_id, tanggal),
  INDEX idx_monitor (tenant_id, tanggal, template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
