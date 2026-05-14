-- ══════════════════════════════════════════════════════
-- outlet_migration.sql — Harpy Multi-Tenant Migration
-- Jalankan SEKALI di phpMyAdmin.
-- Aman dijalankan ulang (semua pakai IF NOT EXISTS / IF EXISTS).
-- ══════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 0;

-- ════════════════════════════════════════
-- BAGIAN 1 — Master tables (super admin)
-- ════════════════════════════════════════

CREATE TABLE IF NOT EXISTS super_admins (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  username   VARCHAR(50)  NOT NULL UNIQUE,
  password   VARCHAR(255) NOT NULL,
  name       VARCHAR(100) NOT NULL,
  is_active  TINYINT(1)   DEFAULT 1,
  last_login TIMESTAMP    NULL,
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS superadmin_logs (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  superadmin_id    INT         NOT NULL,
  action           VARCHAR(100),
  target_tenant_id INT         NULL,
  description      TEXT,
  ip_address       VARCHAR(45),
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS support_tickets (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id     INT NOT NULL,
  superadmin_id INT NOT NULL,
  channel       ENUM('wa','email','call','system') DEFAULT 'wa',
  subject       VARCHAR(200),
  message       TEXT,
  type          ENUM('onboarding','billing','support','churn_risk','info') DEFAULT 'support',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant (tenant_id),
  INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tenant_notes (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id     INT NOT NULL,
  superadmin_id INT NOT NULL,
  note          TEXT NOT NULL,
  is_pinned     TINYINT DEFAULT 0,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ════════════════════════════════════════
-- BAGIAN 2 — tenants table
-- ════════════════════════════════════════

CREATE TABLE IF NOT EXISTS tenants (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  slug           VARCHAR(50)  UNIQUE NOT NULL,
  db_name        VARCHAR(100) NOT NULL DEFAULT 'u269895997_harpy_master',
  nama_outlet    VARCHAR(100) NOT NULL,
  owner_name     VARCHAR(100) DEFAULT NULL,
  owner_wa       VARCHAR(20)  DEFAULT NULL,
  status         ENUM('trial','active','suspended') DEFAULT 'trial',
  coin_balance   INT          DEFAULT 50000,
  coin_mode      ENUM('shared','per_outlet') DEFAULT 'shared',
  total_outlets  INT          DEFAULT 0,
  trial_ends_at  DATETIME     DEFAULT NULL,
  provisioned_at DATETIME     DEFAULT NULL,
  created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_slug   (slug),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE tenants ADD COLUMN IF NOT EXISTS coin_mode ENUM('shared','per_outlet') DEFAULT 'shared';
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS total_outlets INT DEFAULT 0;
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS db_name VARCHAR(100) NOT NULL DEFAULT 'u269895997_harpy_master';

-- ════════════════════════════════════════
-- BAGIAN 3 — outlets table
-- ════════════════════════════════════════

CREATE TABLE IF NOT EXISTS outlets (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id    INT NOT NULL,
  nama_outlet  VARCHAR(100) NOT NULL,
  slug         VARCHAR(80) UNIQUE NOT NULL,
  alamat       TEXT NULL,
  kota         VARCHAR(100) NULL,
  telepon      VARCHAR(20) NULL,
  status       ENUM('active','inactive','suspended') DEFAULT 'active',
  coin_balance INT DEFAULT 0,
  is_main      TINYINT DEFAULT 0,
  setup_done   TINYINT DEFAULT 0,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant (tenant_id),
  INDEX idx_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO outlets (id, tenant_id, nama_outlet, slug, status, coin_balance, is_main, setup_done)
VALUES (1, 1, 'Harpy Laundry Johar', 'harpy_johar_outlet1', 'active', 0, 1, 1);

UPDATE tenants SET total_outlets = 1, coin_mode = 'shared' WHERE id = 1;

-- ════════════════════════════════════════
-- BAGIAN 4 — payments & coin_ledger
-- ════════════════════════════════════════

CREATE TABLE IF NOT EXISTS payments (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id   INT NOT NULL,
  outlet_id   INT NULL,
  type        ENUM('setup_fee','coin_topup','subscription') NOT NULL,
  amount      INT NOT NULL,
  coin_amount INT DEFAULT 0,
  gateway_ref VARCHAR(100) DEFAULT NULL,
  notes       TEXT DEFAULT NULL,
  status      ENUM('pending','success','failed') DEFAULT 'pending',
  paid_at     DATETIME DEFAULT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant (tenant_id),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE payments ADD COLUMN IF NOT EXISTS outlet_id INT NULL AFTER tenant_id;

CREATE TABLE IF NOT EXISTS coin_ledger (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id     INT NOT NULL,
  outlet_id     INT NULL,
  type          ENUM('topup','deduct') NOT NULL,
  amount        INT NOT NULL,
  feature_used  VARCHAR(50)  DEFAULT NULL,
  description   TEXT         DEFAULT NULL,
  balance_after INT          NOT NULL,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant  (tenant_id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE coin_ledger ADD COLUMN IF NOT EXISTS outlet_id INT NULL AFTER tenant_id;

-- ════════════════════════════════════════
-- BAGIAN 5 — login attempts
-- ════════════════════════════════════════

CREATE TABLE IF NOT EXISTS hl_login_attempts (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  identifier   VARCHAR(100) NOT NULL,
  ip_address   VARCHAR(45)  NOT NULL,
  attempted_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_identifier (identifier),
  INDEX idx_ip         (ip_address),
  INDEX idx_time       (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ════════════════════════════════════════
-- BAGIAN 6 — tambah tenant_id ke hl_* tables
-- Catatan: hl_karyawan TIDAK ADA — karyawan pakai hl_users
-- ════════════════════════════════════════

ALTER TABLE hl_users            ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1;
ALTER TABLE hl_users            ADD COLUMN IF NOT EXISTS role_id   INT DEFAULT NULL;

-- Roles & permissions (hanya tenant_id, bukan outlet-scoped)
ALTER TABLE hl_roles            ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1;
ALTER TABLE hl_permissions      ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1;
ALTER TABLE hl_role_permissions ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1;

-- Tabel operasional (tenant_id + akan dapat outlet_id juga)
ALTER TABLE hl_pelanggan        ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1;
ALTER TABLE hl_layanan          ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1;
ALTER TABLE hl_transaksi        ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1;
ALTER TABLE hl_transaksi_item   ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1;
ALTER TABLE hl_kas              ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1;
ALTER TABLE hl_audit_log        ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1;

-- Tabel yang mungkin ada
ALTER TABLE hl_absensi          ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1;
ALTER TABLE hl_izin             ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1;
ALTER TABLE hl_gaji             ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1;
ALTER TABLE hl_promo            ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1;

-- hl_voucher mungkin ada atau tidak
CREATE TABLE IF NOT EXISTS hl_voucher (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id       INT          NOT NULL DEFAULT 1,
  promo_id        INT          DEFAULT NULL,
  kode            VARCHAR(20)  NOT NULL UNIQUE,
  nama_penerima   VARCHAR(100) DEFAULT NULL,
  telepon         VARCHAR(20)  DEFAULT NULL,
  is_used         TINYINT(1)   DEFAULT 0,
  used_at         TIMESTAMP    NULL,
  used_by_order   VARCHAR(30)  DEFAULT NULL,
  expired_at      DATE         DEFAULT NULL,
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE hl_voucher ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1;

-- hl_absensi, hl_izin, hl_gaji — buat jika belum ada
CREATE TABLE IF NOT EXISTS hl_absensi (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id       INT NOT NULL DEFAULT 1,
  outlet_id       INT NOT NULL DEFAULT 1,
  user_id         INT NOT NULL,
  tanggal         DATE NOT NULL,
  jam_masuk       TIME DEFAULT NULL,
  jam_keluar      TIME DEFAULT NULL,
  durasi_menit    INT DEFAULT NULL,
  lokasi_masuk    VARCHAR(255) DEFAULT NULL,
  lokasi_keluar   VARCHAR(255) DEFAULT NULL,
  catatan         VARCHAR(255) DEFAULT NULL,
  status          ENUM('hadir','izin','sakit','alpha') DEFAULT 'hadir',
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_user_date (user_id, tanggal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_izin (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id       INT NOT NULL DEFAULT 1,
  outlet_id       INT NOT NULL DEFAULT 1,
  user_id         INT NOT NULL,
  dari_tanggal    DATE NOT NULL,
  sampai_tanggal  DATE NOT NULL,
  tipe            ENUM('izin','sakit','cuti') DEFAULT 'izin',
  alasan          TEXT DEFAULT NULL,
  status          ENUM('pending','approved','rejected') DEFAULT 'pending',
  approved_by     INT DEFAULT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_gaji (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id   INT NOT NULL DEFAULT 1,
  outlet_id   INT NOT NULL DEFAULT 1,
  user_id     INT NOT NULL,
  bulan       VARCHAR(7) NOT NULL,
  gaji_pokok  DECIMAL(12,2) DEFAULT 0,
  bonus       DECIMAL(12,2) DEFAULT 0,
  potongan    DECIMAL(12,2) DEFAULT 0,
  total       DECIMAL(12,2) DEFAULT 0,
  status      ENUM('pending','dibayar') DEFAULT 'pending',
  catatan     TEXT DEFAULT NULL,
  dibayar_at  TIMESTAMP NULL,
  created_by  INT DEFAULT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_user_bulan (user_id, bulan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_promo (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id       INT NOT NULL DEFAULT 1,
  outlet_id       INT NOT NULL DEFAULT 1,
  nama            VARCHAR(100) NOT NULL,
  deskripsi       TEXT DEFAULT NULL,
  tipe            ENUM('persen','nominal','free_item') DEFAULT 'persen',
  nilai           DECIMAL(12,2) DEFAULT 0,
  min_transaksi   DECIMAL(12,2) DEFAULT 0,
  maks_diskon     DECIMAL(12,2) DEFAULT 0,
  berlaku_dari    DATE DEFAULT NULL,
  berlaku_sampai  DATE DEFAULT NULL,
  kuota           INT DEFAULT 0,
  terpakai        INT DEFAULT 0,
  is_active       TINYINT(1) DEFAULT 1,
  created_by      INT DEFAULT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ════════════════════════════════════════
-- BAGIAN 7 — tambah outlet_id ke tabel operasional
-- ════════════════════════════════════════

ALTER TABLE hl_transaksi      ADD COLUMN IF NOT EXISTS outlet_id INT NOT NULL DEFAULT 1;
ALTER TABLE hl_transaksi_item ADD COLUMN IF NOT EXISTS outlet_id INT NOT NULL DEFAULT 1;
ALTER TABLE hl_pelanggan      ADD COLUMN IF NOT EXISTS outlet_id INT NOT NULL DEFAULT 1;
ALTER TABLE hl_kas            ADD COLUMN IF NOT EXISTS outlet_id INT NOT NULL DEFAULT 1;
ALTER TABLE hl_absensi        ADD COLUMN IF NOT EXISTS outlet_id INT NOT NULL DEFAULT 1;
ALTER TABLE hl_layanan        ADD COLUMN IF NOT EXISTS outlet_id INT NOT NULL DEFAULT 1;
ALTER TABLE hl_izin           ADD COLUMN IF NOT EXISTS outlet_id INT NOT NULL DEFAULT 1;
ALTER TABLE hl_gaji           ADD COLUMN IF NOT EXISTS outlet_id INT NOT NULL DEFAULT 1;
ALTER TABLE hl_promo          ADD COLUMN IF NOT EXISTS outlet_id INT NOT NULL DEFAULT 1;
ALTER TABLE hl_audit_log      ADD COLUMN IF NOT EXISTS outlet_id INT NULL;

-- Index untuk performa
ALTER TABLE hl_transaksi ADD INDEX IF NOT EXISTS idx_outlet (outlet_id);
ALTER TABLE hl_transaksi ADD INDEX IF NOT EXISTS idx_tenant (tenant_id);
ALTER TABLE hl_users     ADD INDEX IF NOT EXISTS idx_tenant (tenant_id);

-- ════════════════════════════════════════
-- BAGIAN 8 — registration & onboarding
-- ════════════════════════════════════════

CREATE TABLE IF NOT EXISTS registration_requests (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  source          ENUM('self_service','assisted') DEFAULT 'assisted',
  nama_perusahaan VARCHAR(100) NULL,
  nama_outlet     VARCHAR(100) NOT NULL,
  owner_name      VARCHAR(100) NOT NULL,
  owner_wa        VARCHAR(20)  NOT NULL,
  kota            VARCHAR(100) NULL,
  status          ENUM('pending','payment_pending','provisioning','completed','failed','cancelled') DEFAULT 'pending',
  payment_id      INT NULL,
  payment_status  ENUM('pending','paid','failed') DEFAULT 'pending',
  setup_fee       INT DEFAULT 300000,
  coin_awal       INT DEFAULT 50000,
  trial_days      INT DEFAULT 30,
  coin_mode       ENUM('shared','per_outlet') DEFAULT 'shared',
  tenant_id       INT NULL,
  outlet_id       INT NULL,
  handled_by      INT NULL,
  notes           TEXT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status),
  INDEX idx_wa     (owner_wa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS onboarding_progress (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id    INT NOT NULL,
  outlet_id    INT NOT NULL,
  step         VARCHAR(50) NOT NULL,
  completed_at DATETIME NULL,
  UNIQUE KEY uk_outlet_step (outlet_id, step),
  INDEX idx_outlet (outlet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ════════════════════════════════════════
-- BAGIAN 9 — backfill data existing ke tenant 1 outlet 1
-- ════════════════════════════════════════

UPDATE hl_users            SET tenant_id = 1 WHERE tenant_id = 0 OR tenant_id IS NULL;
UPDATE hl_roles            SET tenant_id = 1 WHERE tenant_id = 0 OR tenant_id IS NULL;
UPDATE hl_permissions      SET tenant_id = 1 WHERE tenant_id = 0 OR tenant_id IS NULL;
UPDATE hl_role_permissions SET tenant_id = 1 WHERE tenant_id = 0 OR tenant_id IS NULL;

UPDATE hl_pelanggan  SET tenant_id = 1, outlet_id = 1 WHERE tenant_id = 0 OR tenant_id IS NULL;
UPDATE hl_layanan    SET tenant_id = 1, outlet_id = 1 WHERE tenant_id = 0 OR tenant_id IS NULL;
UPDATE hl_transaksi  SET tenant_id = 1, outlet_id = 1 WHERE tenant_id = 0 OR tenant_id IS NULL;
UPDATE hl_transaksi_item SET tenant_id = 1, outlet_id = 1 WHERE tenant_id = 0 OR tenant_id IS NULL;
UPDATE hl_kas        SET tenant_id = 1, outlet_id = 1 WHERE tenant_id = 0 OR tenant_id IS NULL;
UPDATE hl_audit_log  SET tenant_id = 1             WHERE tenant_id = 0 OR tenant_id IS NULL;
UPDATE hl_absensi    SET tenant_id = 1, outlet_id = 1 WHERE tenant_id = 0 OR tenant_id IS NULL;
UPDATE hl_izin       SET tenant_id = 1, outlet_id = 1 WHERE tenant_id = 0 OR tenant_id IS NULL;
UPDATE hl_gaji       SET tenant_id = 1, outlet_id = 1 WHERE tenant_id = 0 OR tenant_id IS NULL;
UPDATE hl_promo      SET tenant_id = 1, outlet_id = 1 WHERE tenant_id = 0 OR tenant_id IS NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- ════════════════════════════════════════
-- SELESAI — cek super_admins punya min 1 user
-- Test: /ERP/harpy/login.php + /ERP/harpy/superadmin/login.php
-- ════════════════════════════════════════
