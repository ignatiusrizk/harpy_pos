-- ══════════════════════════════════════════════════════
-- broadcast_migration.sql
-- Broadcast SOP/instruksi dari HQ ke staff outlet via WA
-- ══════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS hl_broadcast (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id   INT NOT NULL,
  judul       VARCHAR(150) NOT NULL,
  pesan       TEXT NOT NULL,
  target_json JSON DEFAULT NULL,           -- outlet_ids yang dituju
  created_by  INT DEFAULT NULL,
  created_by_nama VARCHAR(100) DEFAULT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_broadcast_recipient (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  broadcast_id INT NOT NULL,
  tenant_id    INT NOT NULL,
  outlet_id    INT DEFAULT NULL,
  user_id      INT DEFAULT NULL,
  nama         VARCHAR(100) DEFAULT NULL,
  telepon      VARCHAR(20)  DEFAULT NULL,
  status       ENUM('pending','sent') NOT NULL DEFAULT 'pending',
  sent_at      DATETIME DEFAULT NULL,
  INDEX idx_bc (broadcast_id),
  INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
