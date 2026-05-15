<?php
// ══════════════════════════════════════════════════════
// core/Mailer.php — LAMASY Email Sender
//
// Saat ini menggunakan PHP native mail().
// Ganti MAILER_DRIVER ke 'smtp' dan isi config SMTP
// untuk production (e.g. Mailgun, Brevo, Gmail SMTP).
//
// CARA PAKAI:
//   Mailer::send('user@email.com', 'Nama User', 'Subject', $htmlBody);
//   Mailer::sendVerification('user@email.com', 'Nama', $token);
// ══════════════════════════════════════════════════════

class Mailer
{
    // ── Config — override via config.php jika ada ────────
    const FROM_EMAIL = 'noreply@lamasy.id';
    const FROM_NAME  = 'LAMASY by Harpy';
    const APP_URL    = 'https://harpy.id';
    const BRAND_COLOR = '#35E8D5';

    // ── Send plain email ─────────────────────────────────
    // Returns true jika sukses, false jika gagal
    public static function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody = ''
    ): bool {
        // Sanitasi
        $toEmail = filter_var($toEmail, FILTER_SANITIZE_EMAIL);
        $toName  = htmlspecialchars($toName, ENT_QUOTES);
        $subject = htmlspecialchars($subject, ENT_QUOTES);

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            error_log("[Mailer] Email tidak valid: $toEmail");
            return false;
        }

        // Fallback text body
        if (empty($textBody)) {
            $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));
        }

        // Boundary untuk multipart/alternative
        $boundary = 'LAMASY_' . md5(uniqid((string)mt_rand(), true));

        $headers  = "From: " . self::FROM_NAME . " <" . self::FROM_EMAIL . ">\r\n";
        $headers .= "Reply-To: " . self::FROM_EMAIL . "\r\n";
        $headers .= "To: $toName <$toEmail>\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
        $headers .= "X-Mailer: LAMASY/1.0\r\n";
        $headers .= "X-Priority: 1\r\n";

        $body  = "--$boundary\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($textBody) . "\r\n";

        $body .= "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($htmlBody) . "\r\n";

        $body .= "--$boundary--\r\n";

        $result = @mail(
            "$toName <$toEmail>",
            $subject,
            $body,
            $headers
        );

        if (!$result) {
            error_log("[Mailer] Gagal kirim ke $toEmail — subject: $subject");
        }

        return $result;
    }

    // ── Template: Verifikasi Email ───────────────────────
    public static function sendVerification(
        string $toEmail,
        string $toName,
        string $token,
        string $namaOutlet = ''
    ): bool {
        $link    = self::APP_URL . '/ERP/harpy/verify-email.php?token=' . urlencode($token);
        $subject = 'Verifikasi Email LAMASY';
        $outlet  = htmlspecialchars($namaOutlet ?: $toName);

        $html = self::baseTemplate($subject, "
            <h2 style='color:#0F1C3A;margin:0 0 8px'>Halo, $toName! 👋</h2>
            <p style='color:#555;margin:0 0 24px'>
                Terima kasih sudah mendaftar <strong>LAMASY</strong> untuk outlet <strong>$outlet</strong>.
                <br>Satu langkah lagi — verifikasi email kamu untuk mengaktifkan akun.
            </p>
            <div style='text-align:center;margin:32px 0'>
                <a href='$link'
                   style='background:" . self::BRAND_COLOR . ";color:#0F1C3A;font-weight:700;
                          text-decoration:none;padding:14px 32px;border-radius:8px;
                          display:inline-block;font-size:16px'>
                    ✅ Verifikasi Email Sekarang
                </a>
            </div>
            <p style='color:#888;font-size:13px;text-align:center'>
                Link ini berlaku selama <strong>24 jam</strong>.
                <br>Jika kamu tidak mendaftar LAMASY, abaikan email ini.
            </p>
            <hr style='border:none;border-top:1px solid #eee;margin:24px 0'>
            <p style='color:#aaa;font-size:12px;text-align:center;word-break:break-all'>
                Atau salin link ini ke browser:<br>
                <a href='$link' style='color:" . self::BRAND_COLOR . "'>$link</a>
            </p>
        ");

        return self::send($toEmail, $toName, $subject, $html);
    }

    // ── Template: Password Reset ──────────────────────────
    public static function sendPasswordReset(
        string $toEmail,
        string $toName,
        string $token
    ): bool {
        $link    = self::APP_URL . '/ERP/harpy/reset-password.php?token=' . urlencode($token);
        $subject = 'Reset Password LAMASY';

        $html = self::baseTemplate($subject, "
            <h2 style='color:#0F1C3A;margin:0 0 8px'>Reset Password</h2>
            <p style='color:#555;margin:0 0 24px'>
                Permintaan reset password diterima untuk akun <strong>$toEmail</strong>.
                <br>Klik tombol di bawah untuk membuat password baru.
            </p>
            <div style='text-align:center;margin:32px 0'>
                <a href='$link'
                   style='background:#EF4444;color:#fff;font-weight:700;
                          text-decoration:none;padding:14px 32px;border-radius:8px;
                          display:inline-block;font-size:16px'>
                    🔑 Reset Password
                </a>
            </div>
            <p style='color:#888;font-size:13px;text-align:center'>
                Link ini berlaku selama <strong>1 jam</strong>.
                <br>Jika kamu tidak meminta reset password, abaikan email ini.
            </p>
        ");

        return self::send($toEmail, $toName, $subject, $html);
    }

    // ── Template: Selamat Datang (setelah verifikasi) ─────
    public static function sendWelcome(
        string $toEmail,
        string $toName,
        string $namaOutlet,
        string $loginUrl = ''
    ): bool {
        if (!$loginUrl) $loginUrl = self::APP_URL . '/ERP/harpy/login.php';
        $subject = 'Selamat Datang di LAMASY! 🎉';
        $outlet  = htmlspecialchars($namaOutlet);

        $html = self::baseTemplate($subject, "
            <h2 style='color:#0F1C3A;margin:0 0 8px'>Akun kamu sudah aktif! 🎉</h2>
            <p style='color:#555;margin:0 0 8px'>
                Selamat datang di <strong>LAMASY</strong>, $toName!
                <br>Outlet <strong>$outlet</strong> sudah siap digunakan.
            </p>
            <div style='background:#f0fdfb;border-left:4px solid " . self::BRAND_COLOR . ";
                        padding:16px;border-radius:0 8px 8px 0;margin:24px 0'>
                <strong style='color:#0F1C3A'>Yang bisa kamu lakukan sekarang:</strong>
                <ul style='color:#555;margin:8px 0 0;padding-left:20px;line-height:1.8'>
                    <li>Tambah layanan laundry & harga</li>
                    <li>Daftarkan karyawan</li>
                    <li>Buat transaksi pertama</li>
                    <li>Aktifkan notifikasi WhatsApp otomatis</li>
                </ul>
            </div>
            <div style='text-align:center;margin:32px 0'>
                <a href='$loginUrl'
                   style='background:" . self::BRAND_COLOR . ";color:#0F1C3A;font-weight:700;
                          text-decoration:none;padding:14px 32px;border-radius:8px;
                          display:inline-block;font-size:16px'>
                    🚀 Mulai Kelola Laundry
                </a>
            </div>
            <p style='color:#888;font-size:13px;text-align:center'>
                Ada pertanyaan? Chat tim kami di WhatsApp:<br>
                <a href='https://wa.me/6281234567890' style='color:" . self::BRAND_COLOR . "'>wa.me/6281234567890</a>
            </p>
        ");

        return self::send($toEmail, $toName, $subject, $html);
    }

    // ── Base HTML email template ─────────────────────────
    private static function baseTemplate(string $title, string $content): string
    {
        return '<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . htmlspecialchars($title) . '</title>
</head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:\'Segoe UI\',Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;padding:40px 20px">
<tr><td align="center">
<table width="100%" style="max-width:560px;background:#fff;border-radius:12px;
       overflow:hidden;box-shadow:0 2px 20px rgba(0,0,0,.06)">
  <!-- Header -->
  <tr><td style="background:#0F1C3A;padding:28px 32px;text-align:center">
    <span style="font-size:22px;font-weight:800;color:#35E8D5;letter-spacing:-0.5px">LAMASY</span>
    <span style="font-size:11px;color:rgba(255,255,255,.5);display:block;margin-top:2px">
      Laundry Management System by Harpy
    </span>
  </td></tr>
  <!-- Body -->
  <tr><td style="padding:32px">
    ' . $content . '
  </td></tr>
  <!-- Footer -->
  <tr><td style="background:#f8fafc;padding:20px 32px;text-align:center;
                 border-top:1px solid #eee">
    <p style="margin:0;color:#bbb;font-size:12px">
      © ' . date('Y') . ' LAMASY by Harpy &nbsp;·&nbsp;
      <a href="' . self::APP_URL . '/ERP/harpy/landing.php" style="color:#bbb">Beranda</a>
      &nbsp;·&nbsp;
      <a href="mailto:support@lamasy.id" style="color:#bbb">Bantuan</a>
    </p>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>';
    }
}
