-- ══════════════════════════════════════════════════════
-- droppoint_migration.sql — Drop Point Management System
-- Mitra eksternal (warung, kos, salon, dll) sebagai titik intake
-- untuk outlet laundry. Mitra punya akun login terbatas.
-- ══════════════════════════════════════════════════════

-- 1) Extend ENUM role hl_users
-- Tambah 'mitra' + role lain yang sudah dipakai di kode tapi belum di ENUM
ALTER TABLE hl_users MODIFY COLUMN role
  ENUM('superadmin','admin','owner','manager','staff','kasir','kurir','mitra')
  DEFAULT 'staff';

-- 2) Kolom outlet_id (defensif kalau belum ada) + drop_point_id di hl_users
ALTER TABLE hl_users ADD COLUMN IF NOT EXISTS outlet_id INT NULL AFTER tenant_id;
ALTER TABLE hl_users ADD COLUMN IF NOT EXISTS drop_point_id INT NULL AFTER outlet_id;
ALTER TABLE hl_users ADD INDEX IF NOT EXISTS idx_drop_point (drop_point_id);

-- 3) Master mitra drop point
CREATE TABLE IF NOT EXISTS hl_drop_points (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id       INT NOT NULL,
  outlet_id       INT NOT NULL,                 -- outlet laundry yang menaungi
  nama_mitra      VARCHAR(100) NOT NULL,
  alamat          TEXT NULL,
  wa              VARCHAR(20) NULL,
  komisi_model    ENUM('per_kg','persen','flat','kombinasi') DEFAULT 'per_kg',
  komisi_per_kg   INT DEFAULT 0,                -- Rp per kg
  komisi_persen   DECIMAL(5,2) DEFAULT 0,       -- % dari omset
  komisi_flat     INT DEFAULT 0,                -- Rp per order
  periode_rekap   ENUM('mingguan','bulanan') DEFAULT 'bulanan',
  status          ENUM('aktif','nonaktif') DEFAULT 'aktif',
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tenant_outlet (tenant_id, outlet_id),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) Rekap komisi (idempotent per periode)
CREATE TABLE IF NOT EXISTS hl_komisi_rekap (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id       INT NOT NULL,
  outlet_id       INT NOT NULL,
  drop_point_id   INT NOT NULL,
  periode_start   DATE NOT NULL,
  periode_end     DATE NOT NULL,
  total_order     INT DEFAULT 0,
  total_kg        DECIMAL(10,2) DEFAULT 0,
  total_omset     INT DEFAULT 0,
  total_komisi    INT DEFAULT 0,
  status          ENUM('pending','dibayar') DEFAULT 'pending',
  dibayar_at      DATETIME NULL,
  kas_id          INT NULL,                     -- referensi ke hl_kas saat komisi dibayar
  catatan         TEXT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_rekap (tenant_id, drop_point_id, periode_start, periode_end),
  INDEX idx_drop_point (drop_point_id),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5) Tandai order yang berasal dari drop point
ALTER TABLE hl_transaksi ADD COLUMN IF NOT EXISTS drop_point_id INT NULL AFTER outlet_id;
ALTER TABLE hl_transaksi ADD INDEX IF NOT EXISTS idx_drop_point (drop_point_id);
