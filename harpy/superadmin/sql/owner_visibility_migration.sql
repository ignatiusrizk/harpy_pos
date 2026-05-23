-- ══════════════════════════════════════════════════════
-- owner_visibility_migration.sql
-- Owner POV — daily report, anomali, target, piutang B2B, produktivitas
-- ══════════════════════════════════════════════════════

-- 1) Target omset per outlet
ALTER TABLE outlets ADD COLUMN IF NOT EXISTS target_omset_harian  INT DEFAULT 0;
ALTER TABLE outlets ADD COLUMN IF NOT EXISTS target_omset_bulanan INT DEFAULT 0;

-- 2) Log notifikasi (dedup + rate-limit + in-app feed)
CREATE TABLE IF NOT EXISTS hl_notif_log (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id     INT NOT NULL,
  outlet_id     INT NOT NULL,
  type          VARCHAR(50) NOT NULL,
  channel       ENUM('email','wa','inapp') NOT NULL DEFAULT 'email',
  target        VARCHAR(150) NULL,
  subject       VARCHAR(200) NULL,
  body_summary  TEXT NULL,
  status        ENUM('sent','failed') DEFAULT 'sent',
  error_msg     VARCHAR(255) NULL,
  read_at       DATETIME NULL,
  sent_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_outlet_type (outlet_id, type, sent_at),
  INDEX idx_inapp_unread (tenant_id, channel, read_at, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Piutang B2B
CREATE TABLE IF NOT EXISTS hl_piutang (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id       INT NOT NULL,
  outlet_id       INT NOT NULL,
  pelanggan_id    INT NOT NULL,
  periode_start   DATE NOT NULL,
  periode_end     DATE NOT NULL,
  jatuh_tempo     DATE NOT NULL,
  total_order     INT DEFAULT 0,
  total_tagihan   INT DEFAULT 0,
  total_dibayar   INT DEFAULT 0,
  sisa_tagihan    INT GENERATED ALWAYS AS (total_tagihan - total_dibayar) STORED,
  status          ENUM('belum_tagih','sudah_tagih','sebagian','lunas') DEFAULT 'belum_tagih',
  invoice_sent_at DATETIME NULL,
  lunas_at        DATETIME NULL,
  kas_id          INT NULL,
  catatan         TEXT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_periode (tenant_id, pelanggan_id, periode_start, periode_end),
  INDEX idx_outlet (tenant_id, outlet_id),
  INDEX idx_pelanggan (pelanggan_id),
  INDEX idx_status (status),
  INDEX idx_jatuh_tempo (jatuh_tempo, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) Tipe bayar pelanggan (langsung walk-in vs B2B/bulanan)
ALTER TABLE hl_pelanggan ADD COLUMN IF NOT EXISTS tipe_bayar
  ENUM('langsung','bulanan') DEFAULT 'langsung';

-- 5) Karyawan yang menangani order (untuk produktivitas)
ALTER TABLE hl_transaksi ADD COLUMN IF NOT EXISTS handled_by INT NULL AFTER outlet_id;
ALTER TABLE hl_transaksi ADD INDEX IF NOT EXISTS idx_handled_by (handled_by);
