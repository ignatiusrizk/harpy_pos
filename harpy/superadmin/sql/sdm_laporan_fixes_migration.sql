-- ══════════════════════════════════════════════════════
-- sdm_laporan_fixes_migration.sql
-- Fix #1: tgl_selesai untuk % tepat waktu akurat
-- Fix #3: jam_buka per outlet untuk patokan telat absensi
-- ══════════════════════════════════════════════════════

-- #1: stamp tanggal order selesai (saat status → siap/diambil/selesai)
ALTER TABLE hl_transaksi ADD COLUMN IF NOT EXISTS tgl_selesai DATE DEFAULT NULL;

-- #3: jam buka outlet (patokan keterlambatan)
ALTER TABLE outlets ADD COLUMN IF NOT EXISTS jam_buka TIME DEFAULT '08:00:00';
