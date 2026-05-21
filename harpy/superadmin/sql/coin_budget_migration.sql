-- ══════════════════════════════════════════════════════
-- coin_budget_migration.sql
-- Budget coin bulanan per outlet (0 = unlimited)
-- ══════════════════════════════════════════════════════

ALTER TABLE outlets ADD COLUMN IF NOT EXISTS coin_budget_monthly INT DEFAULT 0;
