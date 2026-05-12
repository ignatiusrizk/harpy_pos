-- ══════════════════════════════════════════════════════
-- default_data.sql — Data default untuk tenant baru
-- Di-eksekusi setelah tenant_schema.sql
-- Password admin default di-set via TenantProvisioner
-- ══════════════════════════════════════════════════════

-- ── Default Roles ─────────────────────────────────────
INSERT INTO hl_roles (nama, deskripsi, is_system, is_active) VALUES
  ('Owner',     'Akses penuh ke semua fitur',               1, 1),
  ('Admin',     'Kelola order, kas, laporan, karyawan',      1, 1),
  ('Kasir',     'Input order & pembayaran saja',             1, 1),
  ('Karyawan',  'Absensi & update status order',             1, 1);

-- ── Default Permissions ───────────────────────────────
INSERT INTO hl_permissions (kode, modul, aksi, deskripsi) VALUES
  -- POS
  ('pos.view',              'pos',       'view',       'Lihat halaman POS'),
  ('pos.create',            'pos',       'create',     'Buat order baru via POS'),
  -- Orders
  ('orders.view_all',       'orders',    'view_all',   'Lihat semua order'),
  ('orders.view_own',       'orders',    'view_own',   'Lihat order milik sendiri'),
  ('orders.create',         'orders',    'create',     'Buat order baru'),
  ('orders.edit',           'orders',    'edit',       'Edit detail order'),
  ('orders.update_status',  'orders',    'update_status','Update status proses'),
  ('orders.bayar',          'orders',    'bayar',      'Update pembayaran order'),
  ('orders.delete',         'orders',    'delete',     'Hapus order'),
  -- Kas
  ('kas.view',              'kas',       'view',       'Lihat halaman kas'),
  ('kas.create',            'kas',       'create',     'Input kas masuk/keluar'),
  ('kas.delete',            'kas',       'delete',     'Hapus entri kas'),
  -- Laporan
  ('laporan.view',          'laporan',   'view',       'Lihat laporan'),
  ('laporan.export',        'laporan',   'export',     'Export laporan'),
  -- Karyawan
  ('karyawan.view',         'karyawan',  'view',       'Lihat data karyawan'),
  ('karyawan.create',       'karyawan',  'create',     'Tambah karyawan'),
  ('karyawan.edit',         'karyawan',  'edit',       'Edit data karyawan'),
  ('karyawan.delete',       'karyawan',  'delete',     'Hapus karyawan'),
  ('karyawan.gaji',         'karyawan',  'gaji',       'Kelola penggajian'),
  -- Absensi
  ('absensi.view',          'absensi',   'view',       'Lihat data absensi'),
  ('absensi.clock',         'absensi',   'clock',      'Clock in/out'),
  ('absensi.approve',       'absensi',   'approve',    'Approve izin karyawan'),
  -- Pelanggan
  ('pelanggan.view',        'pelanggan', 'view',       'Lihat data pelanggan'),
  ('pelanggan.create',      'pelanggan', 'create',     'Tambah pelanggan'),
  ('pelanggan.edit',        'pelanggan', 'edit',       'Edit pelanggan'),
  -- Layanan
  ('layanan.view',          'layanan',   'view',       'Lihat katalog layanan'),
  ('layanan.create',        'layanan',   'create',     'Tambah layanan'),
  ('layanan.edit',          'layanan',   'edit',       'Edit layanan'),
  ('layanan.delete',        'layanan',   'delete',     'Hapus layanan'),
  -- Promo
  ('promo.view',            'promo',     'view',       'Lihat promo & voucher'),
  ('promo.create',          'promo',     'create',     'Buat promo baru'),
  ('promo.delete',          'promo',     'delete',     'Hapus promo'),
  -- Settings
  ('settings.roles',        'settings',  'roles',      'Kelola role & permission'),
  ('settings.outlet',       'settings',  'outlet',     'Edit info outlet'),
  -- Audit
  ('audit.view',            'audit',     'view',       'Lihat audit log');

-- ── Owner role: semua permission ─────────────────────
INSERT INTO hl_role_permissions (role_id, permission_id, filter_data)
  SELECT 1, id, 'all' FROM hl_permissions;

-- ── Admin role: semua kecuali settings.roles & audit ─
INSERT INTO hl_role_permissions (role_id, permission_id, filter_data)
  SELECT 2, id, 'all' FROM hl_permissions
  WHERE kode NOT IN ('settings.roles','audit.view','karyawan.delete');

-- ── Kasir role: POS + orders + absensi ───────────────
INSERT INTO hl_role_permissions (role_id, permission_id, filter_data)
  SELECT 3, id, 'all' FROM hl_permissions
  WHERE kode IN (
    'pos.view','pos.create',
    'orders.view_all','orders.create','orders.update_status','orders.bayar',
    'pelanggan.view','pelanggan.create',
    'absensi.clock','absensi.view',
    'layanan.view'
  );

-- ── Karyawan role: absensi + update status ────────────
INSERT INTO hl_role_permissions (role_id, permission_id, filter_data)
  SELECT 4, id, 'all' FROM hl_permissions
  WHERE kode IN (
    'absensi.clock','absensi.view',
    'orders.view_own','orders.update_status',
    'pos.view','pos.create'
  );

-- ── Default Layanan ───────────────────────────────────
INSERT INTO hl_layanan (nama, kategori, satuan, harga, urutan) VALUES
  ('Cuci + Kering Reguler',  'Reguler',  'kg',  5000,  1),
  ('Cuci + Kering Express',  'Express',  'kg',  8000,  2),
  ('Cuci + Setrika Reguler', 'Reguler',  'kg',  8000,  3),
  ('Cuci + Setrika Express', 'Express',  'kg', 12000,  4),
  ('Setrika Saja',            'Satuan',  'kg',  4000,  5),
  ('Cuci Saja',               'Satuan',  'kg',  4000,  6),
  ('Selimut / Bed Cover',     'Khusus', 'pcs', 25000,  7),
  ('Sepatu',                  'Khusus', 'pcs', 35000,  8),
  ('Tas',                     'Khusus', 'pcs', 30000,  9),
  ('Dry Cleaning Jas',        'Premium','pcs', 75000, 10);
