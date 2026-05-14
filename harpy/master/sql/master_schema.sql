-- ══════════════════════════════════════════════════════
-- master_schema.sql — Schema lengkap harpy_master
-- Untuk fresh install. Jika upgrade dari existing,
-- gunakan superadmin/sql/outlet_migration.sql
-- ══════════════════════════════════════════════════════

-- ── Tenants ───────────────────────────────────────────
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

-- ── Outlets ───────────────────────────────────────────
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

-- ── Coin Ledger ───────────────────────────────────────
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

-- ── Payments ──────────────────────────────────────────
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

-- ── Super Admin Users ─────────────────────────────────
-- PENTING: nama tabel "super_admins" (dengan underscore)
-- PENTING: kolom "name" (bukan "nama")
CREATE TABLE IF NOT EXISTS super_admins (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  username   VARCHAR(50)  NOT NULL UNIQUE,
  password   VARCHAR(255) NOT NULL,
  name       VARCHAR(100) NOT NULL,
  is_active  TINYINT(1)   DEFAULT 1,
  last_login TIMESTAMP    NULL,
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Superadmin Action Log ─────────────────────────────
CREATE TABLE IF NOT EXISTS superadmin_logs (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  superadmin_id    INT NOT NULL,
  action           VARCHAR(100),
  target_tenant_id INT NULL,
  description      TEXT,
  ip_address       VARCHAR(45),
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Support Tickets ───────────────────────────────────
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
  INDEX idx_date   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Tenant Notes ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS tenant_notes (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id     INT NOT NULL,
  superadmin_id INT NOT NULL,
  note          TEXT NOT NULL,
  is_pinned     TINYINT DEFAULT 0,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Registration Requests ─────────────────────────────
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

-- ── Onboarding Progress ───────────────────────────────
CREATE TABLE IF NOT EXISTS onboarding_progress (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id    INT NOT NULL,
  outlet_id    INT NOT NULL,
  step         VARCHAR(50) NOT NULL,
  completed_at DATETIME NULL,
  UNIQUE KEY uk_outlet_step (outlet_id, step),
  INDEX idx_outlet (outlet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
