<?php
// ══════════════════════════════════════════════════════
// core/TenantResolver.php — Identifikasi & validasi tenant
// Single-database approach: tenant diidentifikasi via
// tenant_id yang disimpan di $_SESSION setelah login
// ══════════════════════════════════════════════════════

class TenantResolver
{
    private static ?array $current = null;

    // ── Resolve tenant dari session ───────────────────
    // Dipanggil oleh tenant_guard.php di setiap request
    public static function resolve(): void
    {
        if (!isset($_SESSION['tenant_id'])) {
            http_response_code(403);
            die(self::errorPage('Tenant tidak teridentifikasi. Silakan login kembali.'));
        }

        // Hindari query ulang jika sudah di-resolve di request ini
        if (self::$current !== null) return;

        $stmt = Database::get()->prepare(
            "SELECT * FROM tenants WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$_SESSION['tenant_id']]);
        $tenant = $stmt->fetch();

        if (!$tenant) {
            session_destroy();
            header('Location: /login?error=tenant_not_found');
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

        self::$current = $tenant;

        // Sinkronkan coin balance ke session
        $_SESSION['tenant_coin_balance'] = $tenant['coin_balance'];
    }

    // ── Refresh data dari DB (setelah update coin dll) ─
    public static function refresh(): void
    {
        self::$current = null;
        self::resolve();
    }

    // ── Getters ───────────────────────────────────────
    public static function id(): int
    {
        return (int)(self::$current['id'] ?? $_SESSION['tenant_id'] ?? 0);
    }

    public static function get(): array
    {
        return self::$current ?? [];
    }

    public static function slug(): string
    {
        return self::$current['slug'] ?? '';
    }

    public static function namaOutlet(): string
    {
        return self::$current['nama_outlet'] ?? '';
    }

    public static function coinBalance(): int
    {
        return (int)(self::$current['coin_balance'] ?? 0);
    }

    public static function status(): string
    {
        return self::$current['status'] ?? '';
    }

    // ── Set session saat login ────────────────────────
    // Dipanggil dari login handler setelah auth berhasil
    public static function setSession(array $tenant): void
    {
        $_SESSION['tenant_id']   = $tenant['id'];
        $_SESSION['tenant_slug'] = $tenant['slug'];
    }

    // ── Reset (untuk testing / CLI) ───────────────────
    public static function reset(): void
    {
        self::$current = null;
    }

    // ── Halaman suspended ─────────────────────────────
    private static function showSuspendedPage(array $tenant): void
    {
        http_response_code(402);
        echo '<!DOCTYPE html><html lang="id"><head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Akun Ditangguhkan — Harpy</title>
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
          <div style="font-size:3rem;margin-bottom:16px">🔒</div>
          <h1>Akun Ditangguhkan</h1>
          <p>Outlet <strong>' . htmlspecialchars($tenant['nama_outlet']) . '</strong>
          sementara tidak bisa diakses.<br>
          Hubungi tim Harpy untuk informasi lebih lanjut.</p>
          <a href="https://wa.me/6281234567890">Hubungi Support</a>
        </div></body></html>';
    }

    private static function errorPage(string $msg): string
    {
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error — Harpy</title></head>
        <body style="font-family:sans-serif;text-align:center;padding:60px;background:#0F1C3A;color:#fff">
        <h2>⚠️ ' . htmlspecialchars($msg) . '</h2>
        <a href="/login" style="color:#35E8D5">Kembali ke Login</a></body></html>';
    }
}
