<?php
// ══════════════════════════════════════════════════════
// core/TenantResolver.php — Identifikasi & validasi tenant + outlet
// Single-database multi-tenant: setiap request harus punya
// tenant_id DAN outlet_id di session.
// ══════════════════════════════════════════════════════

class TenantResolver
{
    private static ?array $tenant = null;
    private static ?array $outlet = null;

    // ── Resolve tenant & outlet dari session ──────────
    // Dipanggil oleh tenant_guard.php di setiap request
    public static function resolve(): void
    {
        if (!isset($_SESSION['tenant_id'])) {
            http_response_code(403);
            die(self::errorPage('Tenant tidak teridentifikasi. Silakan login kembali.'));
        }

        if (!isset($_SESSION['outlet_id'])) {
            // Redirect ke halaman pilih outlet
            if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Outlet belum dipilih.', 'redirect' => '/ERP/harpy/select-outlet.php']);
                exit;
            }
            header('Location: /ERP/harpy/select-outlet.php');
            exit;
        }

        // Hindari query ulang jika sudah di-resolve di request ini
        if (self::$tenant !== null && self::$outlet !== null) return;

        // Load tenant
        $stmt = Database::get()->prepare(
            "SELECT * FROM tenants WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$_SESSION['tenant_id']]);
        $tenant = $stmt->fetch();

        if (!$tenant) {
            session_destroy();
            header('Location: /ERP/harpy/login.php?error=tenant_not_found');
            exit;
        }

        // Trial expired → auto-suspend
        if ($tenant['status'] === 'trial' && $tenant['trial_ends_at']) {
            if (strtotime($tenant['trial_ends_at']) < time()) {
                Database::get()->prepare(
                    "UPDATE tenants SET status = 'suspended' WHERE id = ?"
                )->execute([$tenant['id']]);
                $tenant['status'] = 'suspended';
            }
        }

        if ($tenant['status'] === 'suspended') {
            self::showSuspendedPage($tenant);
            exit;
        }

        // Load outlet dan verifikasi milik tenant ini
        $oStmt = Database::get()->prepare(
            "SELECT * FROM outlets WHERE id = ? AND tenant_id = ? LIMIT 1"
        );
        $oStmt->execute([$_SESSION['outlet_id'], $tenant['id']]);
        $outlet = $oStmt->fetch();

        if (!$outlet) {
            // Outlet tidak valid — minta pilih ulang
            unset($_SESSION['outlet_id']);
            if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Outlet tidak valid.', 'redirect' => '/ERP/harpy/select-outlet.php']);
                exit;
            }
            header('Location: /ERP/harpy/select-outlet.php');
            exit;
        }

        if ($outlet['status'] === 'suspended') {
            self::showSuspendedPage($tenant, $outlet);
            exit;
        }

        self::$tenant = $tenant;
        self::$outlet = $outlet;

        // Sinkronkan coin balance ke session (sesuai coin_mode)
        if (self::isSharedCoin()) {
            $_SESSION['tenant_coin_balance'] = (int)$tenant['coin_balance'];
        } else {
            $_SESSION['tenant_coin_balance'] = (int)$outlet['coin_balance'];
        }
    }

    // ── Refresh data dari DB (setelah update coin dll) ─
    public static function refresh(): void
    {
        self::$tenant = null;
        self::$outlet = null;
        self::resolve();
    }

    // ── Reset (untuk testing / CLI) ───────────────────
    public static function reset(): void
    {
        self::$tenant = null;
        self::$outlet = null;
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

    /** Mengembalikan nama OUTLET (bukan tenant) */
    public static function namaOutlet(): string
    {
        return self::$outlet['nama_outlet'] ?? self::$tenant['nama_outlet'] ?? '';
    }

    public static function status(): string
    {
        return self::$tenant['status'] ?? '';
    }

    public static function isSharedCoin(): bool
    {
        return (self::$tenant['coin_mode'] ?? 'shared') === 'shared';
    }

    public static function coinBalance(): int
    {
        if (!self::$tenant) return (int)($_SESSION['tenant_coin_balance'] ?? 0);
        return self::isSharedCoin()
            ? (int)(self::$tenant['coin_balance'] ?? 0)
            : (int)(self::$outlet['coin_balance'] ?? 0);
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

    // ── Halaman suspended ─────────────────────────────
    private static function showSuspendedPage(array $tenant, ?array $outlet = null): void
    {
        $name = $outlet['nama_outlet'] ?? $tenant['nama_outlet'] ?? 'Outlet';
        http_response_code(402);
        echo '<!DOCTYPE html><html lang="id"><head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Akun Ditangguhkan — LAMASY</title>
        <style>
          body{font-family:sans-serif;background:#0F1C3A;color:#fff;display:flex;
               align-items:center;justify-content:center;min-height:100vh;margin:0}
          .box{text-align:center;padding:40px;max-width:480px}
          h1{font-size:2rem;color:#35E8D5;margin-bottom:16px}
          p{color:rgba(255,255,255,.6);line-height:1.6;margin-bottom:24px}
          a{color:#35E8D5;text-decoration:none;border:1px solid #35E8D5;
            padding:10px 24px;border-radius:8px}
        </style></head><body>
        <div class="box">
          <div style="font-size:3rem;margin-bottom:16px">&#x1F512;</div>
          <h1>Akun Ditangguhkan</h1>
          <p>Outlet <strong>' . htmlspecialchars($name) . '</strong>
          sementara tidak bisa diakses.<br>
          Hubungi tim Harpy untuk informasi lebih lanjut.</p>
          <a href="https://wa.me/6281234567890">Hubungi Support</a>
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
