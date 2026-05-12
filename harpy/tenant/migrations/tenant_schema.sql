-- ══════════════════════════════════════════════════════
-- tenant_schema.sql — Template schema tiap tenant baru
-- Di-eksekusi oleh TenantProvisioner::provision()
-- Tidak perlu USE — provisioner sudah connect ke DB target
-- ══════════════════════════════════════════════════════

-- ── Users & Auth ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS hl_users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password      VARCHAR(255) NOT NULL,
  nama          VARCHAR(100) NOT NULL,
  role          ENUM('superadmin','admin','staff') DEFAULT 'staff',
  role_id       INT          DEFAULT NULL,
  jabatan       VARCHAR(100) DEFAULT NULL,
  telepon       VARCHAR(20)  DEFAULT NULL,
  alamat        TEXT         DEFAULT NULL,
  tgl_masuk     DATE         DEFAULT NULL,
  gaji_pokok    DECIMAL(12,2) DEFAULT 0,
  is_active     TINYINT(1)   DEFAULT 1,
  last_login    TIMESTAMP    NULL,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_login_attempts (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  identifier   VARCHAR(100) NOT NULL,
  ip_address   VARCHAR(45)  NOT NULL,
  attempted_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_identifier (identifier),
  INDEX idx_ip         (ip_address),
  INDEX idx_time       (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_rate_limits (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT          NOT NULL,
  endpoint     VARCHAR(50)  NOT NULL,
  requested_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_endpoint (user_id, endpoint),
  INDEX idx_time          (requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Roles & Permissions ───────────────────────────────
CREATE TABLE IF NOT EXISTS hl_roles (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  nama        VARCHAR(50)  NOT NULL,
  deskripsi   TEXT         DEFAULT NULL,
  is_system   TINYINT(1)   DEFAULT 0,
  is_active   TINYINT(1)   DEFAULT 1,
  created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_permissions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  kode        VARCHAR(80)  NOT NULL UNIQUE,
  modul       VARCHAR(40)  NOT NULL,
  aksi        VARCHAR(40)  NOT NULL,
  deskripsi   VARCHAR(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_role_permissions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  role_id       INT          NOT NULL,
  permission_id INT          NOT NULL,
  filter_data   VARCHAR(50)  DEFAULT NULL,
  UNIQUE KEY unique_role_perm (role_id, permission_id),
  FOREIGN KEY (role_id)       REFERENCES hl_roles(id)       ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES hl_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Pelanggan ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hl_pelanggan (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nama          VARCHAR(100) NOT NULL,
  telepon       VARCHAR(20)  DEFAULT NULL,
  alamat        TEXT         DEFAULT NULL,
  tipe          ENUM('retail','korporat','bulanan') DEFAULT 'retail',
  metode_bayar  ENUM('langsung','bulanan') DEFAULT 'langsung',
  catatan       TEXT         DEFAULT NULL,
  total_order   INT          DEFAULT 0,
  is_active     TINYINT(1)   DEFAULT 1,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_telepon (telepon),
  INDEX idx_nama    (nama)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Layanan & Harga ───────────────────────────────────
CREATE TABLE IF NOT EXISTS hl_layanan (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  nama        VARCHAR(100) NOT NULL,
  kategori    VARCHAR(50)  NOT NULL,
  satuan      VARCHAR(30)  NOT NULL DEFAULT 'kg',
  harga       DECIMAL(12,2) DEFAULT 0,
  urutan      INT          DEFAULT 0,
  is_active   TINYINT(1)   DEFAULT 1,
  created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_kategori (kategori),
  INDEX idx_urutan   (urutan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Transaksi (Orders) ────────────────────────────────
CREATE TABLE IF NOT EXISTS hl_transaksi (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  no_order         VARCHAR(30)  NOT NULL UNIQUE,
  tanggal          DATE         NOT NULL,
  pelanggan_id     INT          DEFAULT NULL,
  nama_pelanggan   VARCHAR(100) NOT NULL,
  telepon          VARCHAR(20)  DEFAULT NULL,
  subtotal         DECIMAL(12,2) DEFAULT 0,
  diskon           DECIMAL(12,2) DEFAULT 0,
  total            DECIMAL(12,2) DEFAULT 0,
  dp               DECIMAL(12,2) DEFAULT 0,
  sisa_bayar       DECIMAL(12,2) DEFAULT 0,
  metode_bayar     VARCHAR(30)  DEFAULT 'cash',
  status_bayar     ENUM('belum_bayar','dp','lunas') DEFAULT 'belum_bayar',
  status_proses    ENUM('masuk','cuci','kering','setrika','siap','diambil') DEFAULT 'masuk',
  estimasi_selesai DATE         DEFAULT NULL,
  catatan          TEXT         DEFAULT NULL,
  bukti_bayar      VARCHAR(255) DEFAULT NULL,
  promo_id         INT          DEFAULT NULL,
  voucher_kode     VARCHAR(20)  DEFAULT NULL,
  created_by       INT          DEFAULT NULL,
  updated_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_no_order      (no_order),
  INDEX idx_tanggal       (tanggal),
  INDEX idx_status_proses (status_proses),
  INDEX idx_status_bayar  (status_bayar),
  INDEX idx_pelanggan     (pelanggan_id),
  FOREIGN KEY (pelanggan_id) REFERENCES hl_pelanggan(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_transaksi_item (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  transaksi_id  INT          NOT NULL,
  layanan_id    INT          DEFAULT NULL,
  nama_layanan  VARCHAR(100) NOT NULL,
  satuan        VARCHAR(30)  DEFAULT 'kg',
  jumlah        DECIMAL(10,2) DEFAULT 0,
  harga_satuan  DECIMAL(12,2) DEFAULT 0,
  subtotal      DECIMAL(12,2) DEFAULT 0,
  catatan_item  TEXT          DEFAULT NULL,
  FOREIGN KEY (transaksi_id) REFERENCES hl_transaksi(id) ON DELETE CASCADE,
  FOREIGN KEY (layanan_id)   REFERENCES hl_layanan(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Kas & Keuangan ────────────────────────────────────
CREATE TABLE IF NOT EXISTS hl_kas (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  tanggal     DATE         NOT NULL,
  tipe        ENUM('masuk','keluar') NOT NULL,
  kategori    VARCHAR(50)  NOT NULL,
  keterangan  TEXT         NOT NULL,
  jumlah      DECIMAL(12,2) NOT NULL,
  ref_order   VARCHAR(30)  DEFAULT NULL,
  created_by  INT          DEFAULT NULL,
  created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tanggal (tanggal),
  INDEX idx_tipe    (tipe)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Absensi ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hl_absensi (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT          NOT NULL,
  tanggal         DATE         NOT NULL,
  jam_masuk       TIME         DEFAULT NULL,
  jam_keluar      TIME         DEFAULT NULL,
  durasi_menit    INT          DEFAULT NULL,
  lokasi_masuk    VARCHAR(255) DEFAULT NULL,
  lokasi_keluar   VARCHAR(255) DEFAULT NULL,
  catatan         VARCHAR(255) DEFAULT NULL,
  status          ENUM('hadir','izin','sakit','alpha') DEFAULT 'hadir',
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_user_date (user_id, tanggal),
  FOREIGN KEY (user_id) REFERENCES hl_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_izin (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT          NOT NULL,
  dari_tanggal    DATE         NOT NULL,
  sampai_tanggal  DATE         NOT NULL,
  tipe            ENUM('izin','sakit','cuti') DEFAULT 'izin',
  alasan          TEXT         DEFAULT NULL,
  status          ENUM('pending','approved','rejected') DEFAULT 'pending',
  approved_by     INT          DEFAULT NULL,
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES hl_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Penggajian ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hl_gaji (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT          NOT NULL,
  bulan       VARCHAR(7)   NOT NULL,           -- format: YYYY-MM
  gaji_pokok  DECIMAL(12,2) DEFAULT 0,
  bonus       DECIMAL(12,2) DEFAULT 0,
  potongan    DECIMAL(12,2) DEFAULT 0,
  total       DECIMAL(12,2) DEFAULT 0,
  status      ENUM('pending','dibayar') DEFAULT 'pending',
  catatan     TEXT         DEFAULT NULL,
  dibayar_at  TIMESTAMP    NULL,
  created_by  INT          DEFAULT NULL,
  created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_user_bulan (user_id, bulan),
  FOREIGN KEY (user_id) REFERENCES hl_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Promo & Voucher ───────────────────────────────────
CREATE TABLE IF NOT EXISTS hl_promo (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  nama            VARCHAR(100) NOT NULL,
  deskripsi       TEXT         DEFAULT NULL,
  tipe            ENUM('persen','nominal','free_item') DEFAULT 'persen',
  nilai           DECIMAL(12,2) DEFAULT 0,
  min_transaksi   DECIMAL(12,2) DEFAULT 0,
  maks_diskon     DECIMAL(12,2) DEFAULT 0,
  berlaku_dari    DATE         DEFAULT NULL,
  berlaku_sampai  DATE         DEFAULT NULL,
  kuota           INT          DEFAULT 0,
  terpakai        INT          DEFAULT 0,
  is_active       TINYINT(1)   DEFAULT 1,
  created_by      INT          DEFAULT NULL,
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_voucher (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  promo_id        INT          DEFAULT NULL,
  kode            VARCHAR(20)  NOT NULL UNIQUE,
  nama_penerima   VARCHAR(100) DEFAULT NULL,
  telepon         VARCHAR(20)  DEFAULT NULL,
  is_used         TINYINT(1)   DEFAULT 0,
  used_at         TIMESTAMP    NULL,
  used_by_order   VARCHAR(30)  DEFAULT NULL,
  expired_at      DATE         DEFAULT NULL,
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (promo_id) REFERENCES hl_promo(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Audit Log ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hl_audit_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT          DEFAULT NULL,
  user_nama   VARCHAR(100) DEFAULT NULL,
  user_role   VARCHAR(50)  DEFAULT NULL,
  modul       VARCHAR(50)  NOT NULL,
  aksi        VARCHAR(100) NOT NULL,
  keterangan  TEXT         DEFAULT NULL,
  ref_id      VARCHAR(100) DEFAULT NULL,
  ip_address  VARCHAR(45)  DEFAULT NULL,
  user_agent  VARCHAR(255) DEFAULT NULL,
  created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_modul   (modul),
  INDEX idx_user    (user_id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
