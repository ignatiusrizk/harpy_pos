-- ══════════════════════════════════════════════════════
-- ai_outreach_migration.sql
-- Track AI-generated outreach messages (churning notif, dll)
-- ══════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS hl_ai_outreach_log (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id     INT NOT NULL,
  outlet_id     INT NULL,
  pelanggan_id  INT NOT NULL,
  campaign_type VARCHAR(50) NOT NULL,          -- 'churn_winback','birthday','upsell',...
  message_text  TEXT NOT NULL,
  status        ENUM('generated','sent','skipped','dismissed') NOT NULL DEFAULT 'generated',
  generated_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  sent_at       DATETIME NULL,
  by_user_id    INT NULL,
  meta_json     JSON NULL,                     -- score, days_overdue, dll
  INDEX idx_lookup (tenant_id, pelanggan_id, campaign_type),
  INDEX idx_status (tenant_id, status, generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
