<?php
// ══════════════════════════════════════════════════════
// core/TenantProvisioner.php — Daftarkan tenant baru
// Single-database approach: tidak CREATE DATABASE,
// cukup INSERT ke tabel tenants + seed data default
// ══════════════════════════════════════════════════════

class TenantProvisioner
{
    // ── Provision tenant baru ─────────────────────────
    // Return: ['success'=>true, 'tenant_id'=>int, 'slug'=>string, 'password'=>string]
    //      OR ['success'=>false, 'error'=>string]
    public static function provision(array $data): array
    {
        $slug = self::generateSlug($data['nama_outlet']);
        $db   = Database::get();

        $db->beginTransaction();
        try {
            // ── Step 1: Insert tenant ─────────────────
            $trialEnd = date('Y-m-d H:i:s', strtotime('+30 days'));
            $db->prepare("
                INSERT INTO tenants
                  (slug, nama_outlet, owner_name, owner_wa,
                   status, coin_balance, trial_ends_at, provisioned_at)
                VALUES (?, ?, ?, ?, 'trial', 50000, ?, NOW())
            ")->execute([
                $slug,
                $data['nama_outlet'],
                $data['owner_name'] ?? '',
                $data['owner_wa']   ?? '',
                $trialEnd,
            ]);
            $tenantId = (int) $db->lastInsertId();

            // ── Step 2: Seed roles default ────────────
            $roleIds = self::seedRoles($db, $tenantId);

            // ── Step 3: Seed permissions + mapping ────
            self::seedPermissions($db, $tenantId, $roleIds);

            // ── Step 4: Seed layanan default ──────────
            self::seedLayanan($db, $tenantId);

            // ── Step 5: Buat user owner ───────────────
            $tempPassword = self::generatePassword();
            $db->prepare("
                INSERT INTO hl_users
                  (tenant_id, username, password, nama, role, role_id, is_active, created_at)
                VALUES (?, ?, ?, ?, 'superadmin', ?, 1, NOW())
            ")->execute([
                $tenantId,
                self::generateUsername($data['owner_name'] ?? $slug),
                password_hash($tempPassword, PASSWORD_BCRYPT),
                $data['owner_name'] ?? 'Owner',
                $roleIds['owner'],
            ]);

            $db->commit();

            // ── Step 6: Kirim WA selamat datang ───────
            self::sendWelcomeWA($data['owner_wa'] ?? '', [
                'nama'     => $data['owner_name'] ?? 'Owner',
                'outlet'   => $data['nama_outlet'],
                'url'      => APP_URL . '/login',
                'password' => $tempPassword,
                'trial'    => '30 hari',
            ]);

            return [
                'success'   => true,
                'tenant_id' => $tenantId,
                'slug'      => $slug,
                'password'  => $tempPassword,
            ];

        } catch (Throwable $e) {
            $db->rollBack();
            self::logError($data, $e->getMessage());
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    // ── Suspend tenant ────────────────────────────────
    public static function suspend(int $tenantId, string $reason = ''): void
    {
        Database::get()->prepare(
            "UPDATE tenants SET status = 'suspended' WHERE id = ?"
        )->execute([$tenantId]);
    }

    // ── Aktifkan tenant ───────────────────────────────
    public static function activate(int $tenantId): void
    {
        Database::get()->prepare(
            "UPDATE tenants SET status = 'active' WHERE id = ?"
        )->execute([$tenantId]);
    }

    // ── Internal: seed roles ──────────────────────────
    /**
     * Public: seed roles + permissions default untuk tenant baru.
     * Dipanggil dari register.php (self-registration) supaya owner punya
     * role_id terhubung ke hl_roles, dan role lain (admin/kasir/karyawan)
     * siap di-assign tanpa setup manual.
     *
     * @return int|null role_id 'owner' kalau berhasil seed, null kalau gagal.
     */
    public static function seedDefaultsForTenant(PDO $db, int $tenantId): ?int
    {
        try {
            // Cek apakah sudah pernah di-seed (idempotent)
            $check = $db->prepare("SELECT id FROM hl_roles WHERE tenant_id=? AND nama='Owner' LIMIT 1");
            $check->execute([$tenantId]);
            $existingOwner = $check->fetchColumn();
            if ($existingOwner) return (int)$existingOwner;

            $roleIds = self::seedRoles($db, $tenantId);
            self::seedPermissions($db, $tenantId, $roleIds);
            return $roleIds['owner'] ?? null;
        } catch (Throwable $e) {
            error_log('[seedDefaultsForTenant] ' . $e->getMessage());
            return null;
        }
    }

    private static function seedRoles(PDO $db, int $tenantId): array
    {
        $roles = [
            'owner'    => ['Owner',    'Akses penuh ke semua fitur',          1],
            'admin'    => ['Admin',    'Kelola order, kas, laporan, karyawan', 1],
            'kasir'    => ['Kasir',    'Input order & pembayaran saja',        1],
            'karyawan' => ['Karyawan', 'Absensi & update status order',        1],
        ];

        $stmt   = $db->prepare("INSERT INTO hl_roles (tenant_id, nama, deskripsi, is_system) VALUES (?,?,?,?)");
        $result = [];
        foreach ($roles as $key => [$nama, $desc, $sys]) {
            $stmt->execute([$tenantId, $nama, $desc, $sys]);
            $result[$key] = (int) $db->lastInsertId();
        }
        return $result;
    }

    // ── Internal: seed permissions & role mapping ─────
    private static function seedPermissions(PDO $db, int $tenantId, array $roleIds): void
    {
        $permissions = [
            ['pos.view',             'pos',       'view',          'Lihat halaman POS'],
            ['pos.create',           'pos',       'create',        'Buat order baru via POS'],
            ['orders.view_all',      'orders',    'view_all',      'Lihat semua order'],
            ['orders.view_own',      'orders',    'view_own',      'Lihat order milik sendiri'],
            ['orders.create',        'orders',    'create',        'Buat order baru'],
            ['orders.edit',          'orders',    'edit',          'Edit detail order'],
            ['orders.update_status', 'orders',    'update_status', 'Update status proses'],
            ['orders.bayar',         'orders',    'bayar',         'Update pembayaran order'],
            ['orders.delete',        'orders',    'delete',        'Hapus order'],
            ['kas.view',             'kas',       'view',          'Lihat halaman kas'],
            ['kas.create',           'kas',       'create',        'Input kas masuk/keluar'],
            ['kas.delete',           'kas',       'delete',        'Hapus entri kas'],
            ['laporan.view',         'laporan',   'view',          'Lihat laporan'],
            ['laporan.export',       'laporan',   'export',        'Export laporan'],
            ['karyawan.view',        'karyawan',  'view',          'Lihat data karyawan'],
            ['karyawan.create',      'karyawan',  'create',        'Tambah karyawan'],
            ['karyawan.edit',        'karyawan',  'edit',          'Edit data karyawan'],
            ['karyawan.delete',      'karyawan',  'delete',        'Hapus karyawan'],
            ['karyawan.gaji',        'karyawan',  'gaji',          'Kelola penggajian'],
            ['absensi.view',         'absensi',   'view',          'Lihat data absensi'],
            ['absensi.clock',        'absensi',   'clock',         'Clock in/out'],
            ['absensi.approve',      'absensi',   'approve',       'Approve izin karyawan'],
            ['pelanggan.view',       'pelanggan', 'view',          'Lihat data pelanggan'],
            ['pelanggan.create',     'pelanggan', 'create',        'Tambah pelanggan'],
            ['pelanggan.edit',       'pelanggan', 'edit',          'Edit pelanggan'],
            ['layanan.view',         'layanan',   'view',          'Lihat katalog layanan'],
            ['layanan.create',       'layanan',   'create',        'Tambah layanan'],
            ['layanan.edit',         'layanan',   'edit',          'Edit layanan'],
            ['layanan.delete',       'layanan',   'delete',        'Hapus layanan'],
            ['promo.view',           'promo',     'view',          'Lihat promo & voucher'],
            ['promo.create',         'promo',     'create',        'Buat promo baru'],
            ['promo.delete',         'promo',     'delete',        'Hapus promo'],
            ['settings.roles',       'settings',  'roles',         'Kelola role & permission'],
            ['settings.outlet',      'settings',  'outlet',        'Edit info outlet'],
            ['audit.view',           'audit',     'view',          'Lihat audit log'],
        ];

        $stmtPerm = $db->prepare(
            "INSERT INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi) VALUES (?,?,?,?,?)"
        );
        $stmtMap  = $db->prepare(
            "INSERT INTO hl_role_permissions (tenant_id, role_id, permission_id) VALUES (?,?,?)"
        );

        $ownerExclude   = [];
        $adminExclude   = ['settings.roles', 'audit.view', 'karyawan.delete'];
        $kasirInclude   = ['pos.view','pos.create','orders.view_all','orders.create',
                           'orders.update_status','orders.bayar','pelanggan.view',
                           'pelanggan.create','absensi.clock','absensi.view','layanan.view'];
        $karyawanInclude = ['absensi.clock','absensi.view','orders.view_own','orders.update_status'];

        foreach ($permissions as [$kode, $modul, $aksi, $desc]) {
            $stmtPerm->execute([$tenantId, $kode, $modul, $aksi, $desc]);
            $permId = (int) $db->lastInsertId();

            // Owner: semua
            $stmtMap->execute([$tenantId, $roleIds['owner'], $permId]);

            // Admin: semua kecuali daftar excluded
            if (!in_array($kode, $adminExclude)) {
                $stmtMap->execute([$tenantId, $roleIds['admin'], $permId]);
            }

            // Kasir: hanya yang included
            if (in_array($kode, $kasirInclude)) {
                $stmtMap->execute([$tenantId, $roleIds['kasir'], $permId]);
            }

            // Karyawan: hanya yang included
            if (in_array($kode, $karyawanInclude)) {
                $stmtMap->execute([$tenantId, $roleIds['karyawan'], $permId]);
            }
        }
    }

    // ── Internal: seed layanan default ────────────────
    private static function seedLayanan(PDO $db, int $tenantId): void
    {
        $layanan = [
            ['Cuci + Kering Reguler',  'Reguler', 'kg',   5000,  1],
            ['Cuci + Kering Express',  'Express', 'kg',   8000,  2],
            ['Cuci + Setrika Reguler', 'Reguler', 'kg',   8000,  3],
            ['Cuci + Setrika Express', 'Express', 'kg',  12000,  4],
            ['Setrika Saja',           'Satuan',  'kg',   4000,  5],
            ['Cuci Saja',              'Satuan',  'kg',   4000,  6],
            ['Selimut / Bed Cover',    'Khusus',  'pcs', 25000,  7],
            ['Sepatu',                 'Khusus',  'pcs', 35000,  8],
            ['Tas',                    'Khusus',  'pcs', 30000,  9],
            ['Dry Cleaning Jas',       'Premium', 'pcs', 75000, 10],
        ];

        $stmt = $db->prepare(
            "INSERT INTO hl_layanan (tenant_id, nama, kategori, satuan, harga, urutan, created_at)
             VALUES (?,?,?,?,?,?,NOW())"
        );
        foreach ($layanan as [$nama, $kat, $sat, $harga, $urut]) {
            $stmt->execute([$tenantId, $nama, $kat, $sat, $harga, $urut]);
        }
    }

    // ── Internal helpers ──────────────────────────────
    private static function generateSlug(string $namaOutlet): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $namaOutlet));
        $slug = trim(preg_replace('/_+/', '_', $slug), '_');
        $base = $slug;
        $i    = 1;
        while (self::slugExists($slug)) {
            $slug = $base . '_' . $i++;
        }
        return $slug;
    }

    private static function slugExists(string $slug): bool
    {
        $stmt = Database::get()->prepare(
            "SELECT id FROM tenants WHERE slug = ? LIMIT 1"
        );
        $stmt->execute([$slug]);
        return (bool) $stmt->fetch();
    }

    private static function generateUsername(string $name): string
    {
        $parts = explode(' ', strtolower(trim($name)));
        $base  = implode('.', array_slice($parts, 0, 2));
        $base  = preg_replace('/[^a-z0-9.]/', '', $base) ?: 'owner';
        return substr($base, 0, 40);
    }

    private static function generatePassword(int $length = 10): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#';
        $pw    = '';
        for ($i = 0; $i < $length; $i++) {
            $pw .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $pw;
    }

    private static function sendWelcomeWA(string $phone, array $info): void
    {
        $msg = "Halo {$info['nama']}! 👋\n\n"
             . "Selamat datang di *Harpy Laundry System* 🎉\n\n"
             . "Outlet: *{$info['outlet']}*\n"
             . "Login: {$info['url']}\n"
             . "Password: *{$info['password']}*\n\n"
             . "Trial gratis {$info['trial']} sudah aktif.\n"
             . "Saldo coin awal: *50.000 coin*\n\n"
             . "Segera ganti password setelah login pertama. 🔐";

        // Log untuk sekarang — ganti dengan WA API saat siap
        $logFile = ROOT . '/logs/wa_outbox.log';
        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }
        file_put_contents(
            $logFile,
            date('[Y-m-d H:i:s]') . " TO:{$phone}\n{$msg}\n---\n",
            FILE_APPEND
        );
    }

    private static function logError(array $data, string $error): void
    {
        $logFile = ROOT . '/logs/provision_errors.log';
        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }
        file_put_contents(
            $logFile,
            date('[Y-m-d H:i:s]') . ' FAILED: ' . json_encode($data) . "\nError: {$error}\n---\n",
            FILE_APPEND
        );
    }
}
