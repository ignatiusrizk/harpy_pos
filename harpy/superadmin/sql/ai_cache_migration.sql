-- ══════════════════════════════════════════════════════
-- ai_cache_migration.sql
-- Cache AI insight 24 jam — hemat token & response cepat
--
-- Jalankan SEKALI di phpMyAdmin / mysql client
-- ══════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS hl_ai_cache (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id     INT NOT NULL,
  outlet_id     INT NULL,
  cache_key     VARCHAR(128) NOT NULL,
  prompt_hash   CHAR(64) NOT NULL,
  response_json JSON NOT NULL,
  tokens_in     INT DEFAULT 0,
  tokens_out    INT DEFAULT 0,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  expires_at    DATETIME NOT NULL,
  INDEX idx_lookup (tenant_id, cache_key, expires_at),
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional: cleanup job (jalankan via cron tiap hari)
-- DELETE FROM hl_ai_cache WHERE expires_at < NOW();
