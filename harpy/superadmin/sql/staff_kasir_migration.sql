-- ══════════════════════════════════════════════════════
-- staff_kasir_migration.sql
-- Staff/Kasir POV — operasional harian (Kanban, timer, notes,
-- handover, foto, dll)
-- ══════════════════════════════════════════════════════

-- 1) estimasi_selesai: DATE → DATETIME (untuk timer countdown jam-an)
-- Data existing safe: DATE auto-cast ke DATETIME 00:00:00.
ALTER TABLE hl_transaksi MODIFY COLUMN estimasi_selesai DATETIME NULL DEFAULT NULL;

-- 2) estimasi_jam (untuk kalkulasi auto saat POS create)
ALTER TABLE hl_transaksi ADD COLUMN IF NOT EXISTS estimasi_jam INT DEFAULT 24;

-- 3) foto_masuk (path foto kondisi cucian saat terima)
ALTER TABLE hl_transaksi ADD COLUMN IF NOT EXISTS foto_masuk VARCHAR(255) NULL;

-- 4) Catatan internal multi-row (audit user + timestamp per note)
-- Kolom catatan_internal existing tetap dipertahankan untuk note awal.
CREATE TABLE IF NOT EXISTS hl_order_notes (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id    INT NOT NULL,
  outlet_id    INT NOT NULL,
  transaksi_id INT NOT NULL,
  user_id      INT NULL,
  user_nama    VARCHAR(100) NULL,
  catatan      TEXT NOT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_transaksi (transaksi_id, created_at),
  INDEX idx_tenant_outlet (tenant_id, outlet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5) Serah terima shift (optional, button di absensi.php)
CREATE TABLE IF NOT EXISTS hl_shift_handover (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id         INT NOT NULL,
  outlet_id         INT NOT NULL,
  user_id_keluar    INT NOT NULL,
  user_id_masuk     INT NULL,
  tanggal           DATE NOT NULL,
  shift             ENUM('pagi','sore','malam') NOT NULL,
  saldo_kas_akhir   INT DEFAULT 0,
  order_pending     INT DEFAULT 0,
  order_siap_ambil  INT DEFAULT 0,
  kondisi_mesin     TEXT NULL,
  catatan_khusus    TEXT NULL,
  foto_kondisi      VARCHAR(255) NULL,
  status            ENUM('draft','submitted','acknowledged') DEFAULT 'submitted',
  acknowledged_at   DATETIME NULL,
  acknowledged_by   INT NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_outlet_date (outlet_id, tanggal),
  INDEX idx_status (status, tanggal, outlet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
