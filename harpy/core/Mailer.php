<?php
// ══════════════════════════════════════════════════════
// core/Mailer.php — LAMASY Email Sender
//
// Driver diatur via konstanta di master/config/db.php:
//   MAILER_DRIVER = 'smtp'  → pakai SMTP (recommended)
//   MAILER_DRIVER = 'mail'  → pakai PHP native mail()
//
// SMTP config (Hostinger):
//   SMTP_HOST       = 'smtp.hostinger.com'
//   SMTP_PORT       = 465
//   SMTP_ENCRYPTION = 'ssl'
//   SMTP_USER       = 'noreply@harpy.id'
//   SMTP_PASS       = '...'
//
// CARA PAKAI:
//   Mailer::send('user@email.com', 'Nama', 'Subject', $htmlBody);
//   Mailer::sendVerification('user@email.com', 'Nama', $token);
// ══════════════════════════════════════════════════════

class Mailer
{
    // ── Fallback constants jika config belum di-set ───────
    const DEFAULT_FROM_EMAIL = 'noreply@harpy.id';
    const DEFAULT_FROM_NAME  = 'LAMASY by Harpy';
    const DEFAULT_APP_URL    = 'https://harpy.id';
    const BRAND_COLOR        = '#35E8D5';
    const BRAND_DARK         = '#0F1C3A';

    /** Pesan error terakhir dari sendSmtp() — untuk debugging */
    private static string $lastError = '';

    /** Ambil pesan error terakhir setelah send() mengembalikan false */
    public static function getLastError(): string { return self::$lastError; }

    private static function setError(string $msg): false
    {
        self::$lastError = $msg;
        error_log("[Mailer] $msg");
        return false;
    }

    private static function fromEmail(): string { return defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : self::DEFAULT_FROM_EMAIL; }
    private static function fromName():  string { return defined('SMTP_FROM_NAME')  ? SMTP_FROM_NAME  : self::DEFAULT_FROM_NAME; }
    private static function appUrl():    string { return defined('APP_URL')          ? APP_URL          : self::DEFAULT_APP_URL; }
    private static function driver():    string { return defined('MAILER_DRIVER')    ? MAILER_DRIVER    : 'mail'; }

    // ── Kirim email ──────────────────────────────────────
    // Returns true jika sukses, false jika gagal
    public static function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody = ''
    ): bool {
        $toEmail = filter_var(trim($toEmail), FILTER_SANITIZE_EMAIL);
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            error_log("[Mailer] Email tidak valid: $toEmail");
            return false;
        }

        $toName  = htmlspecialchars_decode(strip_tags($toName));
        $subject = strip_tags($subject);

        if (empty($textBody)) {
            $textBody = strip_tags(str_replace(
                ['<br>', '<br/>', '<br />', '</p>', '</div>'],
                "\n", $htmlBody
            ));
            $textBody = preg_replace('/\n{3,}/', "\n\n", $textBody);
        }

        if (self::driver() === 'smtp') {
            return self::sendSmtp($toEmail, $toName, $subject, $htmlBody, $textBody);
        }
        return self::sendNativeMail($toEmail, $toName, $subject, $htmlBody, $textBody);
    }

    // ── SMTP via PHP streams (tanpa library) ─────────────
    private static function sendSmtp(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody
    ): bool {
        $host       = defined('SMTP_HOST')       ? SMTP_HOST       : 'smtp.hostinger.com';
        $port       = defined('SMTP_PORT')       ? (int)SMTP_PORT  : 465;
        $encryption = defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'ssl';
        $user       = defined('SMTP_USER')       ? SMTP_USER       : '';
        $pass       = defined('SMTP_PASS')       ? SMTP_PASS       : '';

        self::$lastError = '';

        if (empty($user) || empty($pass)) {
            return self::setError("SMTP credentials belum diset (SMTP_USER / SMTP_PASS kosong di config).");
        }

        $socketHost = ($encryption === 'ssl') ? "ssl://$host" : $host;
        $errno = 0; $errstr = '';

        $socket = @fsockopen($socketHost, $port, $errno, $errstr, 10);
        if (!$socket) {
            return self::setError("Gagal konek ke $socketHost:$port — ($errno) $errstr");
        }

        try {
            $boundary  = 'LAMASY_' . md5(uniqid((string)mt_rand(), true));
            $fromEmail = self::fromEmail();
            $fromName  = self::fromName();

            // ── Helpers ───────────────────────────────────
            // Baca response dari server (handle multi-line "250-..." sampai "250 ")
            $read = function() use ($socket): string {
                $resp = '';
                while ($line = fgets($socket, 512)) {
                    $resp .= $line;
                    // Response selesai jika karakter ke-4 adalah spasi (bukan '-')
                    if (strlen($line) >= 4 && $line[3] === ' ') break;
                    if (strlen($line) < 4) break;
                }
                return $resp;
            };

            // Kirim command + baca response
            $cmd = function(string $command) use ($socket, $read): string {
                fwrite($socket, $command . "\r\n");
                return $read();
            };

            // ── 1. Baca banner server (jangan kirim apa-apa dulu) ──
            $banner = $read();
            if (strpos($banner, '220') === false) {
                fclose($socket);
                return self::setError("Banner SMTP tidak valid (bukan 220): " . trim($banner));
            }

            // ── 2. EHLO ───────────────────────────────────
            $ehlo = $cmd("EHLO " . gethostname());
            if (strpos($ehlo, '250') === false) {
                $ehlo = $cmd("HELO " . gethostname()); // fallback
            }

            // ── 3. STARTTLS jika diperlukan ───────────────
            if ($encryption === 'tls') {
                $cmd("STARTTLS");
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $cmd("EHLO " . gethostname()); // EHLO ulang setelah TLS
            }

            // ── 4. AUTH LOGIN ─────────────────────────────
            $authChallenge = $cmd("AUTH LOGIN");
            if (strpos($authChallenge, '334') === false) {
                fclose($socket);
                return self::setError("AUTH LOGIN tidak didukung server: " . trim($authChallenge));
            }
            $cmd(base64_encode($user));             // kirim username
            $authResp = $cmd(base64_encode($pass)); // kirim password

            if (strpos($authResp, '235') === false) {
                fclose($socket);
                return self::setError("Autentikasi SMTP gagal (user/pass salah?): " . trim($authResp));
            }

            // ── 5. MAIL FROM ──────────────────────────────
            $fromResp = $cmd("MAIL FROM:<$fromEmail>");
            if (strpos($fromResp, '250') === false) {
                fclose($socket);
                return self::setError("MAIL FROM ditolak server: " . trim($fromResp));
            }

            // ── 6. RCPT TO ────────────────────────────────
            $rcptResp = $cmd("RCPT TO:<$toEmail>");
            if (strpos($rcptResp, '250') === false && strpos($rcptResp, '251') === false) {
                fclose($socket);
                return self::setError("RCPT TO ditolak server: " . trim($rcptResp));
            }

            // ── 7. DATA ───────────────────────────────────
            $dataStart = $cmd("DATA"); // server balas 354
            if (strpos($dataStart, '354') === false) {
                fclose($socket);
                return self::setError("DATA tidak diterima server: " . trim($dataStart));
            }

            // Build MIME body
            $mime  = "MIME-Version: 1.0\r\n";
            $mime .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
            $mime .= "\r\n";
            $mime .= "--$boundary\r\n";
            $mime .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $mime .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $mime .= quoted_printable_encode($textBody) . "\r\n";
            $mime .= "--$boundary\r\n";
            $mime .= "Content-Type: text/html; charset=UTF-8\r\n";
            $mime .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $mime .= quoted_printable_encode($htmlBody) . "\r\n";
            $mime .= "--$boundary--\r\n";

            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $encodedFrom    = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
            $encodedTo      = '=?UTF-8?B?' . base64_encode($toName)   . '?=';

            $headers  = "From: $encodedFrom <$fromEmail>\r\n";
            $headers .= "To: $encodedTo <$toEmail>\r\n";
            $headers .= "Subject: $encodedSubject\r\n";
            $headers .= "Date: " . date('r') . "\r\n";
            $headers .= "Message-ID: <" . md5(uniqid()) . "@harpy.id>\r\n";
            $headers .= "X-Mailer: LAMASY/1.0\r\n";

            fwrite($socket, $headers . $mime . "\r\n.\r\n");
            $dataResp = $read(); // baca konfirmasi 250 setelah "."
            $cmd("QUIT");
            fclose($socket);

            if (strpos($dataResp, '250') === false) {
                return self::setError("Email ditolak server setelah DATA: " . trim($dataResp));
            }
            return true;

        } catch (Throwable $e) {
            if (is_resource($socket)) fclose($socket);
            return self::setError("Exception: " . $e->getMessage());
        }
    }

    // ── PHP native mail() fallback ────────────────────────
    private static function sendNativeMail(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody
    ): bool {
        $boundary    = 'LAMASY_' . md5(uniqid((string)mt_rand(), true));
        $fromEmail   = self::fromEmail();
        $fromName    = self::fromName();

        $headers  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$fromEmail>\r\n";
        $headers .= "Reply-To: $fromEmail\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
        $headers .= "X-Mailer: LAMASY/1.0\r\n";

        $body  = "--$boundary\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($textBody) . "\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($htmlBody) . "\r\n";
        $body .= "--$boundary--\r\n";

        $encodedSubject = "=?UTF-8?B?" . base64_encode($subject) . "?=";
        $result = @mail("$toName <$toEmail>", $encodedSubject, $body, $headers);

        if (!$result) error_log("[Mailer] PHP mail() gagal ke $toEmail — $subject");
        return (bool)$result;
    }

    // ── Templates ─────────────────────────────────────────

    public static function sendVerification(
        string $toEmail,
        string $toName,
        string $token,
        string $namaOutlet = ''
    ): bool {
        $link   = self::appUrl() . '/ERP/harpy/verify-email.php?token=' . urlencode($token);
        $outlet = htmlspecialchars($namaOutlet ?: $toName);

        $html = self::baseTemplate('Verifikasi Email LAMASY', "
            <h2 style='color:" . self::BRAND_DARK . ";margin:0 0 8px'>Halo, " . htmlspecialchars($toName) . "! 👋</h2>
            <p style='color:#555;margin:0 0 24px;line-height:1.65'>
                Terima kasih sudah mendaftar <strong>LAMASY</strong>
                untuk outlet <strong>$outlet</strong>.<br>
                Klik tombol di bawah untuk mengaktifkan akun kamu.
            </p>
            <div style='text-align:center;margin:32px 0'>
                <a href='$link'
                   style='background:" . self::BRAND_COLOR . ";color:" . self::BRAND_DARK . ";
                          font-weight:700;text-decoration:none;padding:14px 32px;
                          border-radius:8px;display:inline-block;font-size:16px'>
                    ✅ Verifikasi Email Sekarang
                </a>
            </div>
            <p style='color:#888;font-size:13px;text-align:center'>
                Link berlaku <strong>24 jam</strong>.
                Jika bukan kamu yang daftar, abaikan email ini.
            </p>
            <hr style='border:none;border-top:1px solid #eee;margin:24px 0'>
            <p style='color:#aaa;font-size:12px;text-align:center;word-break:break-all'>
                Atau salin: <a href='$link' style='color:" . self::BRAND_COLOR . "'>$link</a>
            </p>
        ");

        return self::send($toEmail, $toName, 'Verifikasi Email LAMASY', $html);
    }

    public static function sendPasswordReset(
        string $toEmail,
        string $toName,
        string $token
    ): bool {
        $link = self::appUrl() . '/ERP/harpy/reset-password.php?token=' . urlencode($token);

        $html = self::baseTemplate('Reset Password LAMASY', "
            <h2 style='color:" . self::BRAND_DARK . ";margin:0 0 8px'>Reset Password</h2>
            <p style='color:#555;margin:0 0 24px;line-height:1.65'>
                Permintaan reset password diterima untuk akun <strong>$toEmail</strong>.
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
                Link berlaku <strong>1 jam</strong>.
                Jika tidak meminta reset, abaikan email ini.
            </p>
        ");

        return self::send($toEmail, $toName, 'Reset Password LAMASY', $html);
    }

    public static function sendWelcome(
        string $toEmail,
        string $toName,
        string $namaOutlet
    ): bool {
        $loginUrl = self::appUrl() . '/ERP/harpy/login.php';
        $outlet   = htmlspecialchars($namaOutlet);

        $html = self::baseTemplate('Selamat Datang di LAMASY! 🎉', "
            <h2 style='color:" . self::BRAND_DARK . ";margin:0 0 8px'>Akun kamu sudah aktif! 🎉</h2>
            <p style='color:#555;margin:0 0 8px;line-height:1.65'>
                Selamat datang di <strong>LAMASY</strong>, " . htmlspecialchars($toName) . "!<br>
                Outlet <strong>$outlet</strong> siap digunakan.
            </p>
            <div style='background:#f0fdfb;border-left:4px solid " . self::BRAND_COLOR . ";
                        padding:16px;border-radius:0 8px 8px 0;margin:24px 0'>
                <strong style='color:" . self::BRAND_DARK . "'>Langkah pertama:</strong>
                <ul style='color:#555;margin:8px 0 0;padding-left:20px;line-height:1.9'>
                    <li>Tambah layanan laundry &amp; harga</li>
                    <li>Daftarkan karyawan</li>
                    <li>Buat transaksi pertama</li>
                    <li>Aktifkan notifikasi WhatsApp otomatis</li>
                </ul>
            </div>
            <div style='text-align:center;margin:32px 0'>
                <a href='$loginUrl'
                   style='background:" . self::BRAND_COLOR . ";color:" . self::BRAND_DARK . ";
                          font-weight:700;text-decoration:none;padding:14px 32px;
                          border-radius:8px;display:inline-block;font-size:16px'>
                    🚀 Mulai Kelola Laundry
                </a>
            </div>
            <p style='color:#888;font-size:13px;text-align:center'>
                Ada pertanyaan? Chat kami di
                <a href='https://wa.me/6281234567890' style='color:" . self::BRAND_COLOR . "'>WhatsApp</a>
            </p>
        ");

        return self::send($toEmail, $toName, 'Selamat Datang di LAMASY! 🎉', $html);
    }

    // ── Test: kirim email uji coba ────────────────────────
    // Panggil dari browser: /ERP/harpy/core/Mailer.php?test=1&to=kamu@email.com
    // HAPUS blok ini di production!
    public static function sendTest(string $toEmail): bool {
        return self::send(
            $toEmail,
            'Test User',
            'Test Email dari LAMASY',
            self::baseTemplate('Test Email', "
                <h2 style='color:#0F1C3A'>✅ Email berhasil terkirim!</h2>
                <p style='color:#555'>Konfigurasi SMTP LAMASY berfungsi dengan baik.</p>
                <p style='color:#aaa;font-size:12px'>Dikirim: " . date('d M Y H:i:s') . "</p>
            ")
        );
    }

    // ── Base HTML template ────────────────────────────────
    private static function baseTemplate(string $title, string $content): string
    {
        $year      = date('Y');
        $appUrl    = self::appUrl();
        $teal      = self::BRAND_COLOR;
        $dark      = self::BRAND_DARK;

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title>
</head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:'Segoe UI',Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;padding:40px 20px">
<tr><td align="center">
<table width="100%" style="max-width:560px;background:#fff;border-radius:12px;
       overflow:hidden;box-shadow:0 2px 20px rgba(0,0,0,.06)">
  <tr><td style="background:{$dark};padding:28px 32px;text-align:center">
    <span style="font-size:22px;font-weight:800;color:{$teal};letter-spacing:-0.5px">LAMASY</span>
    <span style="font-size:11px;color:rgba(255,255,255,.45);display:block;margin-top:2px">
      Laundry Management System by Harpy
    </span>
  </td></tr>
  <tr><td style="padding:32px">{$content}</td></tr>
  <tr><td style="background:#f8fafc;padding:20px 32px;text-align:center;border-top:1px solid #eee">
    <p style="margin:0;color:#bbb;font-size:12px">
      &copy; {$year} LAMASY by Harpy &nbsp;&middot;&nbsp;
      <a href="{$appUrl}/ERP/harpy/landing.php" style="color:#bbb">Beranda</a>
      &nbsp;&middot;&nbsp;
      <a href="mailto:support@harpy.id" style="color:#bbb">Bantuan</a>
    </p>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
    }
}
