<?php
// ══════════════════════════════════════════════════════
// core/EmailVerification.php — Token-based email verification
//
// Flow:
//   1. create()    → buat token, simpan ke DB, kirim email
//   2. verify()    → validasi token → tandai verified
//   3. resend()    → kirim ulang, max 3x per session token
//
// CARA PAKAI:
//   // Saat registrasi:
//   $ok = EmailVerification::create($tenantId, $email, $namaOutlet, $ownerName);
//
//   // Di verify-email.php:
//   $result = EmailVerification::verify($token);
//   if ($result['ok']) { /* aktifkan akun */ }
//
//   // Resend:
//   $result = EmailVerification::resend($tenantId, $email);
// ══════════════════════════════════════════════════════

class EmailVerification
{
    const TOKEN_EXPIRY_HOURS = 24;
    const MAX_RESENDS        = 3;

    // ── Buat token baru & kirim email verifikasi ─────────
    // Jika token aktif sudah ada → resend (increment counter)
    // Returns: ['ok'=>bool, 'message'=>string]
    public static function create(
        int    $tenantId,
        string $email,
        string $namaOutlet = '',
        string $ownerName  = '',
        string $type       = 'registration'
    ): array {
        $db = Database::get();

        // Cek apakah sudah ada token aktif yang belum expired & belum used
        $existing = $db->prepare("
            SELECT id, token, resend_count
            FROM email_verifications
            WHERE tenant_id = ? AND email = ? AND type = ?
              AND used_at IS NULL
              AND expires_at > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $existing->execute([$tenantId, $email, $type]);
        $row = $existing->fetch();

        if ($row) {
            // Token masih valid — resend dengan token yang sama
            if ((int)$row['resend_count'] >= self::MAX_RESENDS) {
                return [
                    'ok'      => false,
                    'message' => 'Batas pengiriman ulang tercapai. Coba lagi dalam 24 jam.',
                ];
            }

            $db->prepare("
                UPDATE email_verifications
                SET resend_count = resend_count + 1
                WHERE id = ?
            ")->execute([$row['id']]);

            $sent = Mailer::sendVerification($email, $ownerName ?: 'Pelanggan', $row['token'], $namaOutlet);
            return [
                'ok'      => $sent,
                'message' => $sent
                    ? 'Email verifikasi sudah dikirim ulang.'
                    : 'Gagal mengirim email. Coba beberapa saat lagi.',
            ];
        }

        // Buat token baru
        $token     = self::generateToken();
        $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_EXPIRY_HOURS * 3600);

        $db->prepare("
            INSERT INTO email_verifications
              (tenant_id, email, token, type, expires_at, resend_count)
            VALUES (?, ?, ?, ?, ?, 0)
        ")->execute([$tenantId, $email, $token, $type, $expiresAt]);

        $sent = Mailer::sendVerification($email, $ownerName ?: 'Pelanggan', $token, $namaOutlet);

        return [
            'ok'      => $sent,
            'message' => $sent
                ? 'Email verifikasi sudah dikirim ke ' . $email
                : 'Akun berhasil dibuat, tapi gagal kirim email. Gunakan fitur kirim ulang.',
            'token'   => $token, // hanya untuk testing/debug, jangan tampilkan ke user
        ];
    }

    // ── Verifikasi token dari link ───────────────────────
    // Returns: ['ok'=>bool, 'tenant_id'=>int, 'email'=>string, 'message'=>string]
    public static function verify(string $token): array
    {
        if (empty($token) || strlen($token) !== 64) {
            return ['ok' => false, 'message' => 'Token tidak valid.'];
        }

        $db = Database::get();

        $stmt = $db->prepare("
            SELECT * FROM email_verifications
            WHERE token = ?
            LIMIT 1
        ");
        $stmt->execute([trim($token)]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['ok' => false, 'message' => 'Link verifikasi tidak ditemukan.'];
        }

        if ($row['used_at'] !== null) {
            return ['ok' => false, 'message' => 'Link verifikasi sudah pernah digunakan.', 'already_used' => true];
        }

        if (strtotime($row['expires_at']) < time()) {
            return ['ok' => false, 'message' => 'Link verifikasi sudah kadaluarsa. Minta kirim ulang.', 'expired' => true];
        }

        // Tandai sebagai sudah digunakan
        $db->prepare("
            UPDATE email_verifications SET used_at = NOW() WHERE id = ?
        ")->execute([$row['id']]);

        // Update tenant: tandai verified (status → active, bukan trial)
        // Trial adalah konsep outlet, bukan tenant
        $db->prepare("
            UPDATE tenants
            SET verified_at = NOW(),
                status = CASE
                    WHEN status = 'pending_verification' THEN 'active'
                    ELSE status
                END
            WHERE id = ? AND email = ?
        ")->execute([$row['tenant_id'], $row['email']]);

        // Fetch nama outlet untuk welcome email
        $tenantStmt = $db->prepare("SELECT nama_outlet, owner_name FROM tenants WHERE id = ? LIMIT 1");
        $tenantStmt->execute([$row['tenant_id']]);
        $tenant = $tenantStmt->fetch();

        // Kirim welcome email
        if ($tenant) {
            Mailer::sendWelcome(
                $row['email'],
                $tenant['owner_name'] ?? 'Pelanggan',
                $tenant['nama_outlet'] ?? ''
            );
        }

        return [
            'ok'        => true,
            'tenant_id' => (int)$row['tenant_id'],
            'email'     => $row['email'],
            'type'      => $row['type'],
            'message'   => 'Email berhasil diverifikasi!',
        ];
    }

    // ── Kirim ulang verifikasi ───────────────────────────
    // Dipanggil dari halaman "resend verification"
    public static function resend(int $tenantId, string $email): array
    {
        $db = Database::get();

        // Ambil data tenant untuk nama
        $stmt = $db->prepare("SELECT nama_outlet, owner_name FROM tenants WHERE id = ? AND email = ? LIMIT 1");
        $stmt->execute([$tenantId, $email]);
        $tenant = $stmt->fetch();

        if (!$tenant) {
            return ['ok' => false, 'message' => 'Data akun tidak ditemukan.'];
        }

        return self::create(
            $tenantId,
            $email,
            $tenant['nama_outlet'] ?? '',
            $tenant['owner_name']  ?? '',
            'registration'
        );
    }

    // ── Cek apakah tenant sudah terverifikasi ────────────
    public static function isVerified(int $tenantId): bool
    {
        $stmt = Database::get()->prepare(
            "SELECT verified_at FROM tenants WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();
        return $row && $row['verified_at'] !== null;
    }

    // ── Hapus token expired (untuk cron) ─────────────────
    public static function cleanup(): void
    {
        Database::get()->prepare("
            DELETE FROM email_verifications
            WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ")->execute();
    }

    // ── Generate secure random token ─────────────────────
    private static function generateToken(): string
    {
        return bin2hex(random_bytes(32)); // 64 karakter hex
    }
}
