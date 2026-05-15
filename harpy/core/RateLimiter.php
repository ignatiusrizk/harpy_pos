<?php
// ══════════════════════════════════════════════════════
// core/RateLimiter.php — Rate limiting untuk self-registration
//
// Mencegah spam pendaftaran dari satu IP.
// Default: max 3 percobaan per 24 jam per IP.
//
// CARA PAKAI:
//   if (!RateLimiter::allowRegistration()) {
//       // Tolak, tampilkan pesan error
//   }
//   // ... proses registrasi ...
//   RateLimiter::recordRegistrationAttempt($email, $wa);
// ══════════════════════════════════════════════════════

class RateLimiter
{
    const MAX_REGISTRATION_PER_DAY = 3;   // max pendaftaran per IP per 24 jam
    const WINDOW_SECONDS           = 86400; // 24 jam dalam detik

    // ── Cek apakah IP boleh daftar ───────────────────────
    // Returns true jika masih boleh, false jika limit terlampaui
    public static function allowRegistration(?string $ip = null): bool
    {
        $ip = $ip ?? self::getClientIp();
        $count = self::countRecentAttempts($ip);
        return $count < self::MAX_REGISTRATION_PER_DAY;
    }

    // ── Hitung sisa percobaan ────────────────────────────
    public static function remainingAttempts(?string $ip = null): int
    {
        $ip = $ip ?? self::getClientIp();
        $used = self::countRecentAttempts($ip);
        return max(0, self::MAX_REGISTRATION_PER_DAY - $used);
    }

    // ── Catat percobaan pendaftaran ──────────────────────
    public static function recordRegistrationAttempt(
        string $email   = '',
        string $ownerWa = '',
        ?string $ip     = null
    ): void {
        $ip = $ip ?? self::getClientIp();

        Database::get()->prepare("
            INSERT INTO registration_attempts (ip_address, email, owner_wa)
            VALUES (?, ?, ?)
        ")->execute([
            $ip,
            $email   ?: null,
            $ownerWa ?: null,
        ]);
    }

    // ── Jumlah percobaan dalam window ───────────────────
    private static function countRecentAttempts(string $ip): int
    {
        $stmt = Database::get()->prepare("
            SELECT COUNT(*) FROM registration_attempts
            WHERE ip_address = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$ip, self::WINDOW_SECONDS]);
        return (int)$stmt->fetchColumn();
    }

    // ── Bersihkan data lama (untuk cron harian) ──────────
    public static function cleanup(): void
    {
        Database::get()->prepare("
            DELETE FROM registration_attempts
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ")->execute();
    }

    // ── Login brute-force protection ─────────────────────
    // Menggunakan hl_login_attempts yang sudah ada
    public static function allowLogin(string $identifier, ?string $ip = null): bool
    {
        $ip = $ip ?? self::getClientIp();

        $stmt = Database::get()->prepare("
            SELECT COUNT(*) FROM hl_login_attempts
            WHERE (identifier = ? OR ip_address = ?)
              AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute([$identifier, $ip]);
        return (int)$stmt->fetchColumn() < 5; // max 5 percobaan per 15 menit
    }

    public static function recordLoginAttempt(string $identifier, ?string $ip = null): void
    {
        $ip = $ip ?? self::getClientIp();

        Database::get()->prepare("
            INSERT INTO hl_login_attempts (identifier, ip_address) VALUES (?, ?)
        ")->execute([$identifier, $ip]);
    }

    public static function clearLoginAttempts(string $identifier, ?string $ip = null): void
    {
        $ip = $ip ?? self::getClientIp();

        Database::get()->prepare("
            DELETE FROM hl_login_attempts
            WHERE identifier = ? OR ip_address = ?
        ")->execute([$identifier, $ip]);
    }

    // ── Ambil IP client ───────────────────────────────────
    // Pertimbangkan proxy/load balancer
    public static function getClientIp(): string
    {
        $candidates = [
            'HTTP_CF_CONNECTING_IP',  // Cloudflare
            'HTTP_X_FORWARDED_FOR',   // Load balancer / proxy
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($candidates as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        // Fallback — termasuk IP lokal/private
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
