-- ══════════════════════════════════════════════════════
-- _verify_migrations.sql — cek semua tabel & kolom sesi HQ
-- Jalankan di phpMyAdmin. Kolom "status" harus semua OK.
-- ══════════════════════════════════════════════════════
SET @db := DATABASE();

SELECT 'TABEL' AS jenis, t.nama AS objek,
       IF(EXISTS(SELECT 1 FROM information_schema.tables
                 WHERE table_schema=@db AND table_name=t.nama), '✅ OK', '❌ MISSING') AS status
FROM (
  SELECT 'hl_ai_cache' nama UNION ALL
  SELECT 'hl_ai_outreach_log' UNION ALL
  SELECT 'hl_layanan_master' UNION ALL
  SELECT 'hl_checklist_template' UNION ALL
  SELECT 'hl_checklist_submission' UNION ALL
  SELECT 'hl_broadcast' UNION ALL
  SELECT 'hl_broadcast_recipient'
) t

UNION ALL

SELECT 'KOLOM', CONCAT(c.tbl,'.',c.kol),
       IF(EXISTS(SELECT 1 FROM information_schema.columns
                 WHERE table_schema=@db AND table_name=c.tbl AND column_name=c.kol), '✅ OK', '❌ MISSING')
FROM (
  SELECT 'hl_layanan' tbl, 'master_id' kol UNION ALL
  SELECT 'hl_layanan', 'harga_overridden' UNION ALL
  SELECT 'hl_layanan', 'segmen' UNION ALL
  SELECT 'hl_layanan_master', 'segmen' UNION ALL
  SELECT 'outlets', 'coin_budget_monthly'
) c
ORDER BY jenis, objek;
