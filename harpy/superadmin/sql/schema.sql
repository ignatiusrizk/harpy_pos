-- ══════════════════════════════════════════════════════
-- Super Admin Panel — New Tables
-- Database: u269895997_harpy_master
-- ══════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS support_tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  superadmin_id INT NOT NULL,
  channel ENUM('wa','email','call','system') DEFAULT 'wa',
  subject VARCHAR(200),
  message TEXT,
  type ENUM('onboarding','billing','support','churn_risk','info') DEFAULT 'support',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant (tenant_id),
  INDEX idx_date (created_at)
);

CREATE TABLE IF NOT EXISTS tenant_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  superadmin_id INT NOT NULL,
  note TEXT NOT NULL,
  is_pinned TINYINT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant (tenant_id)
);

CREATE TABLE IF NOT EXISTS superadmin_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  superadmin_id INT NOT NULL,
  action VARCHAR(100),
  target_tenant_id INT NULL,
  description TEXT,
  ip_address VARCHAR(45),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_date (created_at)
);
