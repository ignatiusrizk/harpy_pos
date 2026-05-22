-- ══════════════════════════════════════════════════════
-- gaji_multioutlet_migration.sql
-- Konsolidasi gaji multi-outlet: 1 slip per (outlet, karyawan, bulan)
-- supaya karyawan yang kerja di 2+ outlet bisa di-split proporsional.
-- ══════════════════════════════════════════════════════

-- Ganti unique (user_id, bulan) → (tenant_id, outlet_id, user_id, bulan)
-- Catatan: kalau nama index berbeda, sesuaikan. Jalankan satu per satu.

ALTER TABLE hl_gaji DROP INDEX unique_user_bulan;
ALTER TABLE hl_gaji ADD UNIQUE KEY uniq_gaji_outlet (tenant_id, outlet_id, user_id, bulan);
