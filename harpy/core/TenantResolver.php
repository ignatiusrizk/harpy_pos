<?php
// ══════════════════════════════════════════════════════
// core/TenantResolver.php — Identifikasi & validasi tenant + outlet
// Single-database multi-tenant: setiap request harus punya
// tenant_id DAN outlet_id di session.
//
// Status tenant: pending_verification | trial | active | suspended | closed
// Status outlet: trial | grace | active | suspended | closed
//
// Special flows:
//   outlet_id=0   → tenant belum punya outlet, arahkan ke add-outlet.php
//   pending_verif → tenant belum verifikasi email, arahkan ke pending-verify.php
//   grace outlet  → outlet di grace period, read-only mode
// ══════════════════════════════════════════════════════

class TenantResolver
{
    private static ?array $tenant = null;
    private static ?array $outlet = null;
    private static bool   $graceModeActive = false;

    // ── Resolve tenant & outlet dari session ──────────
    // Dipanggil oleh tenant_guard.php di setiap request
    public static function resolve(): void
    {
        if (!isset($_SESSION['tenant_id'])) {
            http_response_code(403);
            die(self::errorPage('Tenant tidak teridentifikasi. Silakan login kembali.'));
        }

        // Hindari query ulang jika sudah di-resolve di request ini
        if (self::$tenant !== null) return;

        $db = Database::get();

        // ── Load tenant ───────────────────────────────
        $stmt = $db->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['tenant_id']]);
        $tenant = $stmt->fetch();

        if (!$tenant) {
            session_destroy();
            header('Location: /ERP/harpy/login.php?error=tenant_not_found');
            exit;
        }

        // ── Status checks tenant ──────────────────────

        // Belum verifikasi email
        if ($tenant['status'] === 'pending_verification') {
            // Izinkan akses ke halaman verifikasi saja
            $allowed = [
                '/ERP/harpy/pending-verify.php',
                '/ERP/harpy/verify-email.php',
                '/ERP/harpy/resend-verify.php',
                '/ERP/harpy/logout.php',
            ];
            $currentPath = $_SERVER['PHP_SELF'] ?? '';
            if (!in_array($currentPath, $allowed)) {
                if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'error'    => 'Email belum diverifikasi.',
                        'redirect' => '/ERP/harpy/pending-verify.php',
                    ]);
                    exit;
                }
                header('Location: /ERP/harpy/pending-verify.php');
                exit;
            }
            self::$tenant = $tenant;
            return;
        }

        // Akun ditutup / suspended → redirect ke halaman info khusus
        if (in_array($tenant['status'], ['suspended', 'closed'])) {
            $allowed = ['/ERP/harpy/account-suspended.php', '/ERP/harpy/logout.php'];
            $currentPath = $_SERVER['PHP_SELF'] ?? '';
            if (!in_array($currentPath, $allowed)) {
                header('Location: /ERP/harpy/account-suspended.php');
                exit;
            }
            self::$tenant = $tenant;
            return;
        }

        self::$tenant = $tenant;

        // ── Resolve outlet ────────────────────────────

        // Tidak ada outlet_id di session
        if (!isset($_SESSION['outlet_id']) || (int)$_SESSION['outlet_id'] === 0) {
            // Cek apakah tenant punya outlet sama sekali
            $cntStmt = $db->prepare(
                "SELECT COUNT(*) FROM outlets WHERE tenant_id = ? AND status != 'closed'"
            );
            $cntStmt->execute([$tenant['id']]);
            $outletCount = (int)$cntStmt->fetchColumn();

            if ($outletCount === 0) {
                // Tenant belum punya outlet — arahkan ke wizard tambah outlet
                // Izinkan halaman: add-outlet.php, dashboard.php (untuk hero empty state), logout
                $allowed = [
                    '/ERP/harpy/add-outlet.php',
                    '/ERP/harpy/dashboard.php',
                    '/ERP/harpy/logout.php',
                ];
                $currentPath = $_SERVER['PHP_SELF'] ?? '';
                if (!in_array($currentPath, $allowed)) {
                    if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'error'    => 'Belum ada outlet.',
                            'redirect' => '/ERP/harpy/add-outlet.php',
                        ]);
                        exit;
                    }
                    header('Location: /ERP/harpy/add-outlet.php');
                    exit;
                }
                // Di halaman yang diizinkan — set flag no-outlet untuk dashboard
                $_SESSION['has_outlet'] = false;
                self::$outlet = null;
                return;
            }

            // Ada outlet tapi belum dipilih — pilih outlet pertama aktif
            $firstOutlet = $db->prepare(
                "SELECT * FROM outlets WHERE tenant_id = ? AND status != 'closed'
                 ORDER BY is_main DESC, created_at ASC LIMIT 1"
            );
            $firstOutlet->execute([$tenant['id']]);
            $outlet = $firstOutlet->fetch();

            if ($outlet) {
                $_SESSION['outlet_id'] = $outlet['id'];
                $_SESSION['has_outlet'] = true;
            } else {
                header('Location: /ERP/harpy/add-outlet.php');
                exit;
            }
        }

        // Load outlet dari session
        $oStmt = $db->prepare(
            "SELECT * FROM outlets WHERE id = ? AND tenant_id = ? LIMIT 1"
        );
        $oStmt->execute([$_SESSION['outlet_id'], $tenant['id']]);
        $outlet = $oStmt->fetch();

        if (!$outlet) {
            // Outlet tidak valid — reset dan minta pilih ulang
            unset($_SESSION['outlet_id']);
            if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode([
                    'error'    => 'Outlet tidak valid.',
                    'redirect' => '/ERP/harpy/add-outlet.php',
                ]);
                exit;
            }
            header('Location: /ERP/harpy/add-outlet.php');
            exit;
        }

        // ── Status checks outlet ──────────────────────

        // Outlet closed → treat as no-outlet (user bisa daftar outlet baru)
        // Sesuai brief: outlet closed tidak bisa diaktivasi ulang
        if ($outlet['status'] === 'closed') {
            unset($_SESSION['outlet_id']);
            $_SESSION['has_outlet'] = false;
            self::$outlet = null;
            return;
        }

        // Outlet suspended → redirect ke halaman aktivasi
        if ($outlet['status'] === 'suspended') {
            $allowed = ['/ERP/harpy/outlet-suspended.php', '/ERP/harpy/switch-outlet.php',
                        '/ERP/harpy/add-outlet.php', '/ERP/harpy/logout.php'];
            $currentPath = $_SERVER['PHP_SELF'] ?? '';
            if (!in_array($currentPath, $allowed)) {
                header('Location: /ERP/harpy/outlet-suspended.php');
                exit;
            }
            self::$outlet = $outlet;
            return;
        }

        // Grace period — cek apakah sudah expired
        if ($outlet['status'] === 'grace' && $outlet['grace_ends_at']) {
            if (strtotime($outlet['grace_ends_at']) < time()) {
                // Grace berakhir → suspend outlet
                $db->prepare(
                    "UPDATE outlets SET status = 'suspended' WHERE id = ?"
                )->execute([$outlet['id']]);
                $outlet['status'] = 'suspended';
                self::showSuspendedPage($tenant, $outlet);
                exit;
            }
            // Masih dalam grace — set read-only mode
            self::$graceModeActive = true;
        }

        // Trial period — cek apakah expired, masuk ke grace
        if ($outlet['status'] === 'trial' && $outlet['trial_ends_at']) {
            if (strtotime($outlet['trial_ends_at']) < time()) {
                // Trial expired → masuk grace period (7 hari)
                $graceEndsAt = date('Y-m-d H:i:s', time() + 7 * 86400);
                $purgeAt     = date('Y-m-d H:i:s', time() + 37 * 86400); // grace 7d + suspended 30d
                $db->prepare(
                    "UPDATE outlets
                     SET status = 'grace', grace_ends_at = ?, purge_at = ?
                     WHERE id = ?"
                )->execute([$graceEndsAt, $purgeAt, $outlet['id']]);
                $outlet['status']        = 'grace';
                $outlet['grace_ends_at'] = $graceEndsAt;
                $outlet['purge_at']      = $purgeAt;
                self::$graceModeActive   = true;
            }
        }

        self::$outlet = $outlet;
        $_SESSION['has_outlet'] = true;

        // ── Sinkronkan coin balance ke session ────────
        // Prioritas: trial_coin_balance (jika outlet trial) → regular balance
        if ($outlet['status'] === 'trial' && (int)($outlet['trial_coin_balance'] ?? 0) > 0) {
            $_SESSION['tenant_coin_balance'] = (int)$outlet['trial_coin_balance'];
        } elseif (self::isSharedCoin()) {
            $_SESSION['tenant_coin_balance'] = (int)$tenant['coin_balance'];
        } else {
            $_SESSION['tenant_coin_balance'] = (int)$outlet['coin_balance'];
        }
    }

    // ── Refresh data dari DB (setelah update coin dll) ─
    public static function refresh(): void
    {
        self::$tenant           = null;
        self::$outlet           = null;
        self::$graceModeActive  = false;
        self::resolve();
    }

    // ── Reset (untuk testing / CLI) ───────────────────
    public static function reset(): void
    {
        self::$tenant          = null;
        self::$outlet          = null;
        self::$graceModeActive = false;
    }

    // ── Getters ───────────────────────────────────────

    /** Backward compat alias untuk tenantId() */
    public static function id(): int
    {
        return (int)(self::$tenant['id'] ?? $_SESSION['tenant_id'] ?? 0);
    }

    public static function tenantId(): int
    {
        return self::id();
    }

    public static function outletId(): int
    {
        return (int)(self::$outlet['id'] ?? $_SESSION['outlet_id'] ?? 0);
    }

    /** Backward compat */
    public static function get(): array
    {
        return self::$tenant ?? [];
    }

    public static function getTenant(): array
    {
        return self::$tenant ?? [];
    }

    public static function getOutlet(): array
    {
        return self::$outlet ?? [];
    }

    public static function slug(): string
    {
        return self::$outlet['slug'] ?? self::$tenant['slug'] ?? '';
    }

    /** Nama OUTLET (bukan tenant) */
    public static function namaOutlet(): string
    {
        return self::$outlet['nama_outlet'] ?? self::$tenant['nama_outlet'] ?? '';
    }

    public static function status(): string
    {
        return self::$tenant['status'] ?? '';
    }

    public static function outletStatus(): string
    {
        return self::$outlet['status'] ?? '';
    }

    /** Apakah outlet sedang di grace period (mode baca-saja) */
    public static function isGraceMode(): bool
    {
        return self::$graceModeActive;
    }

    /** Apakah outlet sedang di trial */
    public static function isTrial(): bool
    {
        return (self::$outlet['status'] ?? '') === 'trial';
    }

    // ══ Role & Permission helpers (per brief Akses Karyawan 6.7) ══

    /** Role user aktif: owner | superadmin | manager | admin | staff | kasir | kurir */
    public static function getRole(): string
    {
        return $_SESSION['hl_user']['role'] ?? '';
    }

    /** Cek apakah user role-nya owner atau superadmin (akses HQ penuh) */
    public static function isOwnerOrAdmin(): bool
    {
        return in_array(self::getRole(), ['owner', 'superadmin'], true);
    }

    /** Cek apakah user boleh akses HQ view (owner/manager/superadmin) */
    public static function canAccessHq(): bool
    {
        return in_array(self::getRole(), ['owner', 'manager', 'superadmin'], true);
    }

    /**
     * Mapping permission key brief (Section 6.8 snake_case) ke key existing
     * codebase (module.action dot-notation). Owner punya bypass jadi check
     * ini hanya relevan untuk manager/kasir/staff/kurir.
     */
    private static array $permissionAlias = [
        'view_dashboard'      => null,                  // null = always allowed (any login user)
        'create_order'        => 'pos.create',
        'view_orders'         => 'orders.view_all',
        'update_order_status' => 'orders.update_status',
        'view_kas'            => 'kas.view',
        'create_kas'          => 'kas.create',
        'view_laporan'        => 'laporan.view',
        'export_laporan'      => 'laporan.export',
        'manage_layanan'      => 'layanan.edit',
        'manage_promo'        => 'promo.create',
        'view_customer'       => 'pelanggan.view',
        'manage_customer'     => 'pelanggan.edit',
        'view_karyawan'       => 'karyawan.view',
        'manage_karyawan'     => 'karyawan.edit',
        'view_absensi'        => 'absensi.view',
        'clock_inout'         => 'absensi.clock',
        'manage_absensi'      => 'absensi.approve',
        'view_settings'       => 'settings.roles',
        'manage_settings'     => 'settings.roles',
        'view_audit'          => 'audit.view',
        // 'access_hq', 'manage_outlets', 'manage_coin' → role-based di hq_guard, bukan permission table
    ];

    /** Cek single permission (compatible dengan hasPermission() di tenant_guard) */
    public static function can(string $perm): bool
    {
        $perms = $_SESSION['hl_permissions'] ?? [];
        if (isset($perms['*'])) return true;                   // superadmin wildcard
        if (self::isOwnerOrAdmin()) return true;               // owner = full access (brief 6.8)

        // Try alias mapping (brief naming → codebase naming)
        if (array_key_exists($perm, self::$permissionAlias)) {
            $aliased = self::$permissionAlias[$perm];
            if ($aliased === null) return true; // permission yang always-allowed
            if (isset($perms[$aliased])) return true;
        }
        return isset($perms[$perm]);
    }

    /** Return semua permission key user aktif */
    public static function getPermissions(): array
    {
        return array_keys($_SESSION['hl_permissions'] ?? []);
    }

    /**
     * Outlet yang ditugaskan ke user aktif (via hl_karyawan_outlet)
     * Untuk owner/superadmin → return SEMUA outlet aktif tenant.
     * Untuk role lain → hanya yang ada di hl_karyawan_outlet WHERE is_active=1.
     */
    public static function getAssignedOutlets(): array
    {
        $tid = self::id();
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if (!$tid || !$uid) return [];

        $db = Database::get();

        // Owner/superadmin → akses semua outlet aktif tenant
        if (self::isOwnerOrAdmin()) {
            $stmt = $db->prepare(
                "SELECT id, nama_outlet, status FROM outlets
                 WHERE tenant_id = ? AND status IN ('trial','grace','active')
                 ORDER BY is_main DESC, nama_outlet ASC"
            );
            $stmt->execute([$tid]);
            return $stmt->fetchAll();
        }

        // Non-owner → cek hl_karyawan_outlet
        try {
            $stmt = $db->prepare(
                "SELECT o.id, o.nama_outlet, o.status
                   FROM hl_karyawan_outlet ko
                   JOIN outlets o ON o.id = ko.outlet_id AND o.tenant_id = ko.tenant_id
                  WHERE ko.tenant_id = ?
                    AND ko.karyawan_id = ?
                    AND ko.is_active = 1
                    AND o.status IN ('trial','grace','active')
                  ORDER BY o.is_main DESC, o.nama_outlet ASC"
            );
            $stmt->execute([$tid, $uid]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            // Tabel belum ada (fresh deploy) — fallback ke outlet_id di hl_users
            error_log('[getAssignedOutlets] ' . $e->getMessage());
            $stmt = $db->prepare(
                "SELECT o.id, o.nama_outlet, o.status FROM outlets o
                  JOIN hl_users u ON u.outlet_id = o.id AND u.tenant_id = o.tenant_id
                 WHERE u.id = ? AND o.status IN ('trial','grace','active')"
            );
            $stmt->execute([$uid]);
            return $stmt->fetchAll();
        }
    }

    /** Jumlah outlet yang ditugaskan ke user aktif */
    public static function assignedOutletCount(): int
    {
        return count(self::getAssignedOutlets());
    }

    /** Sisa hari trial outlet */
    public static function trialDaysLeft(): int
    {
        if (!self::isTrial() || empty(self::$outlet['trial_ends_at'])) return 0;
        $diff = strtotime(self::$outlet['trial_ends_at']) - time();
        return max(0, (int)ceil($diff / 86400));
    }

    /** Sisa hari grace period */
    public static function graceDaysLeft(): int
    {
        if (!(self::$outlet['status'] === 'grace') || empty(self::$outlet['grace_ends_at'])) return 0;
        $diff = strtotime(self::$outlet['grace_ends_at']) - time();
        return max(0, (int)ceil($diff / 86400));
    }

    /** Tenant punya outlet? */
    public static function hasOutlet(): bool
    {
        return self::$outlet !== null && !empty(self::$outlet['id']);
    }

    public static function isSharedCoin(): bool
    {
        return (self::$tenant['coin_mode'] ?? 'shared') === 'shared';
    }

    public static function coinBalance(): int
    {
        // Trial coin balance jika outlet sedang trial
        if (self::isTrial() && (int)(self::$outlet['trial_coin_balance'] ?? 0) > 0) {
            return (int)(self::$outlet['trial_coin_balance'] ?? 0);
        }

        if (!self::$tenant) return (int)($_SESSION['tenant_coin_balance'] ?? 0);

        return self::isSharedCoin()
            ? (int)(self::$tenant['coin_balance'] ?? 0)
            : (int)(self::$outlet['coin_balance'] ?? 0);
    }

    /** Berapa saldo trial coin outlet */
    public static function trialCoinBalance(): int
    {
        return (int)(self::$outlet['trial_coin_balance'] ?? 0);
    }

    // ── Set session saat login ────────────────────────
    // Dipanggil dari login handler setelah auth berhasil
    public static function setSession(array $tenant, int $outletId = 0): void
    {
        $_SESSION['tenant_id']   = $tenant['id'];
        $_SESSION['tenant_slug'] = $tenant['slug'] ?? '';
        if ($outletId > 0) {
            $_SESSION['outlet_id'] = $outletId;
        }
    }

    // ── Banner data untuk views ───────────────────────
    // Kembalikan array info banner yang harus ditampilkan
    public static function getBannerInfo(): array
    {
        $banners = [];

        if (self::isGraceMode()) {
            $days = self::graceDaysLeft();
            $banners[] = [
                'type'    => 'warning',
                'message' => "⚠️ Outlet dalam grace period. Sisa <strong>$days hari</strong> sebelum ditangguhkan. "
                           . "<a href='/ERP/harpy/billing.php'>Aktifkan sekarang →</a>",
            ];
        } elseif (self::isTrial()) {
            $days      = self::trialDaysLeft();
            $trialCoin = self::trialCoinBalance();
            if ($days <= 3) {
                $banners[] = [
                    'type'    => 'warning',
                    'message' => "⏰ Trial berakhir dalam <strong>$days hari</strong>. "
                               . "<a href='/ERP/harpy/billing.php'>Upgrade sekarang →</a>",
                ];
            }
            if ($trialCoin < 1000) {
                $banners[] = [
                    'type'    => 'info',
                    'message' => "🪙 Sisa coin trial: <strong>$trialCoin coin</strong>. "
                               . "Topup untuk fitur unlimited.",
                ];
            }
        }

        return $banners;
    }

    // ── Halaman suspended ─────────────────────────────
    private static function showSuspendedPage(array $tenant, ?array $outlet = null): void
    {
        $name     = $outlet['nama_outlet'] ?? $tenant['nama_outlet'] ?? 'Outlet';
        $isGrace  = ($outlet['status'] ?? '') === 'grace';
        $isClosed = in_array($tenant['status'] ?? '', ['closed']) ||
                    in_array($outlet['status'] ?? '', ['closed']);

        http_response_code($isClosed ? 410 : 402);
        echo '<!DOCTYPE html><html lang="id"><head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Akun Ditangguhkan — LAMASY</title>
        <style>
          body{font-family:sans-serif;background:#0F1C3A;color:#fff;display:flex;
               align-items:center;justify-content:center;min-height:100vh;margin:0}
          .box{text-align:center;padding:40px;max-width:480px}
          h1{font-size:2rem;color:#35E8D5;margin-bottom:16px}
          p{color:rgba(255,255,255,.6);line-height:1.6;margin-bottom:24px}
          .btn{color:#0F1C3A;text-decoration:none;padding:12px 28px;border-radius:8px;
               display:inline-block;font-weight:700;background:#35E8D5;margin:4px}
          .btn-outline{background:transparent;color:#35E8D5;border:1px solid #35E8D5}
        </style></head><body>
        <div class="box">
          <div style="font-size:3rem;margin-bottom:16px">' .
          ($isGrace ? '⏰' : ($isClosed ? '🚫' : '🔒')) . '</div>
          <h1>' . ($isGrace ? 'Grace Period' : ($isClosed ? 'Akun Ditutup' : 'Akun Ditangguhkan')) . '</h1>
          <p>Outlet <strong>' . htmlspecialchars($name) . '</strong>
          ' . ($isGrace
                ? 'dalam masa grace period. Lakukan pembayaran untuk melanjutkan.'
                : ($isClosed
                    ? 'sudah ditutup. Hubungi tim Harpy jika ini keliru.'
                    : 'sementara tidak bisa diakses karena masa aktif habis.')
              ) . '
          <br>Hubungi tim Harpy untuk informasi lebih lanjut.</p>
          <a href="https://wa.me/6281234567890" class="btn">💬 Hubungi Support</a>
          <a href="/ERP/harpy/logout.php" class="btn btn-outline">Keluar</a>
        </div></body></html>';
    }

    private static function errorPage(string $msg): string
    {
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error — LAMASY</title></head>
        <body style="font-family:sans-serif;text-align:center;padding:60px;background:#0F1C3A;color:#fff">
        <h2>' . htmlspecialchars($msg) . '</h2>
        <a href="/ERP/harpy/login.php" style="color:#35E8D5">Kembali ke Login</a></body></html>';
    }
}
