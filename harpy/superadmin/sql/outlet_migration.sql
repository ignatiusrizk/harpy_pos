-- ══════════════════════════════════════════════════════
-- outlet_migration.sql
-- Jalankan SEKALI di phpMyAdmin sebagai super admin.
-- Menambahkan lapisan Outlet antara Tenant dan data operasional.
-- ══════════════════════════════════════════════════════

-- 1. Add coin_mode and total_outlets to tenants
ALTER TABLE tenants
  ADD COLUMN IF NOT EXISTS coin_mode ENUM('shared','per_outlet') DEFAULT 'shared' AFTER coin_balance,
  ADD COLUMN IF NOT EXISTS total_outlets INT DEFAULT 0 AFTER coin_mode;

-- 2. Create outlets table
CREATE TABLE IF NOT EXISTS outlets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  nama_outlet VARCHAR(100) NOT NULL,
  slug VARCHAR(80) UNIQUE NOT NULL,
  alamat TEXT NULL,
  kota VARCHAR(100) NULL,
  telepon VARCHAR(20) NULL,
  status ENUM('active','inactive','suspended') DEFAULT 'active',
  coin_balance INT DEFAULT 0,
  is_main TINYINT DEFAULT 0,
  setup_done TINYINT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant (tenant_id),
  INDEX idx_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Create outlet for existing Harpy Johar tenant (tenant_id=1)
INSERT IGNORE INTO outlets (id, tenant_id, nama_outlet, slug, status, coin_balance, is_main, setup_done)
VALUES (1, 1, 'Harpy Laundry Johar', 'harpy_johar', 'active', 0, 1, 1);

-- Update tenant total_outlets
UPDATE tenants SET total_outlets = 1 WHERE id = 1;

-- 4. Add outlet_id to operational tables
ALTER TABLE hl_transaksi ADD COLUMN IF NOT EXISTS outlet_id INT NOT NULL DEFAULT 1 AFTER tenant_id;
ALTER TABLE hl_transaksi ADD INDEX IF NOT EXISTS idx_outlet (outlet_id);
ALTER TABLE hl_transaksi_item ADD COLUMN IF NOT EXISTS outlet_id INT NOT NULL DEFAULT 1 AFTER tenant_id;
ALTER TABLE hl_pelanggan ADD COLUMN IF NOT EXISTS outlet_id INT NOT NULL DEFAULT 1 AFTER tenant_id;
ALTER TABLE hl_karyawan ADD COLUMN IF NOT EXISTS outlet_id INT NOT NULL DEFAULT 1 AFTER tenant_id;
ALTER TABLE hl_kas ADD COLUMN IF NOT EXISTS outlet_id INT NOT NULL DEFAULT 1 AFTER tenant_id;
ALTER TABLE hl_absensi ADD COLUMN IF NOT EXISTS outlet_id INT NOT NULL DEFAULT 1 AFTER tenant_id;
ALTER TABLE hl_layanan ADD COLUMN IF NOT EXISTS outlet_id INT NOT NULL DEFAULT 1 AFTER tenant_id;
ALTER TABLE hl_gaji ADD COLUMN IF NOT EXISTS outlet_id INT NOT NULL DEFAULT 1 AFTER tenant_id;
ALTER TABLE hl_izin ADD COLUMN IF NOT EXISTS outlet_id INT NOT NULL DEFAULT 1 AFTER tenant_id;
ALTER TABLE hl_promo ADD COLUMN IF NOT EXISTS outlet_id INT NOT NULL DEFAULT 1 AFTER tenant_id;
ALTER TABLE hl_audit_log ADD COLUMN IF NOT EXISTS outlet_id INT NULL AFTER tenant_id;

-- Add outlet_id to coin_ledger
ALTER TABLE coin_ledger ADD COLUMN IF NOT EXISTS outlet_id INT NULL AFTER tenant_id;

-- 5. Update existing data to outlet_id = 1
UPDATE hl_transaksi SET outlet_id = 1 WHERE outlet_id = 0 OR outlet_id IS NULL;
UPDATE hl_transaksi_item SET outlet_id = 1 WHERE outlet_id = 0 OR outlet_id IS NULL;
UPDATE hl_pelanggan SET outlet_id = 1 WHERE outlet_id = 0 OR outlet_id IS NULL;
UPDATE hl_karyawan SET outlet_id = 1 WHERE outlet_id = 0 OR outlet_id IS NULL;
UPDATE hl_kas SET outlet_id = 1 WHERE outlet_id = 0 OR outlet_id IS NULL;
UPDATE hl_absensi SET outlet_id = 1 WHERE outlet_id = 0 OR outlet_id IS NULL;
UPDATE hl_layanan SET outlet_id = 1 WHERE outlet_id = 0 OR outlet_id IS NULL;
UPDATE hl_gaji SET outlet_id = 1 WHERE outlet_id = 0 OR outlet_id IS NULL;
UPDATE hl_izin SET outlet_id = 1 WHERE outlet_id = 0 OR outlet_id IS NULL;
UPDATE hl_promo SET outlet_id = 1 WHERE outlet_id = 0 OR outlet_id IS NULL;

-- 6. New tables
CREATE TABLE IF NOT EXISTS registration_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  source ENUM('self_service','assisted') DEFAULT 'assisted',
  nama_perusahaan VARCHAR(100) NULL,
  nama_outlet VARCHAR(100) NOT NULL,
  owner_name VARCHAR(100) NOT NULL,
  owner_wa VARCHAR(20) NOT NULL,
  kota VARCHAR(100) NULL,
  status ENUM('pending','payment_pending','provisioning','completed','failed','cancelled') DEFAULT 'pending',
  payment_id INT NULL,
  payment_status ENUM('pending','paid','failed') DEFAULT 'pending',
  setup_fee INT DEFAULT 300000,
  coin_awal INT DEFAULT 50000,
  trial_days INT DEFAULT 30,
  coin_mode ENUM('shared','per_outlet') DEFAULT 'shared',
  tenant_id INT NULL,
  outlet_id INT NULL,
  handled_by INT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status),
  INDEX idx_wa (owner_wa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS onboarding_progress (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  step VARCHAR(50) NOT NULL,
  completed_at DATETIME NULL,
  UNIQUE KEY uk_outlet_step (outlet_id, step),
  INDEX idx_outlet (outlet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
