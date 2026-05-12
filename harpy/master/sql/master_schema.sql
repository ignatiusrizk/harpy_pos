-- ══════════════════════════════════════════════════════
-- master_schema.sql — Schema database harpy_master
-- Jalankan sekali saat setup awal
-- ══════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS `harpy_master`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `harpy_master`;

-- ── Daftar semua tenant ───────────────────────────────
CREATE TABLE IF NOT EXISTS tenants (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  slug            VARCHAR(50)  UNIQUE NOT NULL,
  db_name         VARCHAR(100) NOT NULL,
  nama_outlet     VARCHAR(100) NOT NULL,
  owner_name      VARCHAR(100),
  owner_wa        VARCHAR(20),
  status          ENUM('trial','active','suspended') DEFAULT 'trial',
  coin_balance    INT          DEFAULT 50000,
  trial_ends_at   DATETIME     DEFAULT NULL,
  provisioned_at  DATETIME     DEFAULT NULL,
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_slug   (slug),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Semua transaksi coin masuk & keluar ───────────────
CREATE TABLE IF NOT EXISTS coin_ledger (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id     INT          NOT NULL,
  type          ENUM('topup','deduct') NOT NULL,
  amount        INT          NOT NULL,
  feature_used  VARCHAR(50)  DEFAULT NULL,   -- 'ai_briefing', 'send_wa', 'generate_nota'
  description   TEXT         DEFAULT NULL,
  balance_after INT          NOT NULL,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant   (tenant_id),
  INDEX idx_created  (created_at),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Histori pembayaran ────────────────────────────────
CREATE TABLE IF NOT EXISTS payments (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id     INT          NOT NULL,
  type          ENUM('setup_fee','coin_topup','subscription') NOT NULL,
  amount        INT          NOT NULL,           -- dalam Rupiah
  coin_amount   INT          DEFAULT 0,          -- coin yang dibeli (jika coin_topup)
  gateway_ref   VARCHAR(100) DEFAULT NULL,
  notes         TEXT         DEFAULT NULL,
  status        ENUM('pending','success','failed') DEFAULT 'pending',
  paid_at       DATETIME     DEFAULT NULL,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant  (tenant_id),
  INDEX idx_status  (status),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Super admin users (internal Harpy) ───────────────
CREATE TABLE IF NOT EXISTS superadmins (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  username   VARCHAR(50)  NOT NULL UNIQUE,
  password   VARCHAR(255) NOT NULL,
  nama       VARCHAR(100) NOT NULL,
  is_active  TINYINT(1)   DEFAULT 1,
  last_login TIMESTAMP    NULL,
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Audit log master (aksi superadmin) ───────────────
CREATE TABLE IF NOT EXISTS master_audit_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  admin_id    INT          DEFAULT NULL,
  admin_nama  VARCHAR(100) DEFAULT NULL,
  tenant_id   INT          DEFAULT NULL,
  aksi        VARCHAR(100) NOT NULL,
  keterangan  TEXT         DEFAULT NULL,
  ip_address  VARCHAR(45)  DEFAULT NULL,
  created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant  (tenant_id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
