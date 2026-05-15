<?php
// ══════════════════════════════════════════════════════
// test-mailer.php — Test konfigurasi SMTP
// Akses: https://harpy.id/ERP/harpy/test-mailer.php
//
// ⚠️  HAPUS file ini setelah berhasil test!
// ══════════════════════════════════════════════════════

// Basic auth sederhana agar tidak bisa diakses sembarangan
$TEST_SECRET = 'lamasy_smtp_test_2024'; // ganti setelah test
if (($_GET['key'] ?? '') !== $TEST_SECRET) {
    http_response_code(403);
    die('Forbidden. Akses dengan ?key=<secret>');
}

define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Mailer.php';

$toEmail   = $_GET['to'] ?? '';
$result    = null;
$smtpError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $toEmail = trim($_POST['to'] ?? '');
    if (filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $result    = Mailer::sendTest($toEmail);
        $smtpError = Mailer::getLastError();
    }
}

$driver = defined('MAILER_DRIVER') ? MAILER_DRIVER : 'mail';
$smtpHost = defined('SMTP_HOST') ? SMTP_HOST : '-';
$smtpPort = defined('SMTP_PORT') ? SMTP_PORT : '-';
$smtpUser = defined('SMTP_USER') ? SMTP_USER : '-';
$smtpPass = defined('SMTP_PASS') ? (SMTP_PASS ? '●●●●●●' : '(kosong!)') : '-';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Test Mailer — LAMASY</title>
<style>
  body { font-family: 'Segoe UI', sans-serif; background: #0F1C3A; color: #fff;
         display: flex; flex-direction: column; align-items: center; padding: 40px 16px; }
  .card { background: #1a2d52; border-radius: 12px; padding: 32px; max-width: 560px; width: 100%; }
  h1 { color: #35E8D5; font-size: 1.3rem; margin: 0 0 20px; }
  table { width: 100%; font-size: 13px; margin-bottom: 24px; }
  td { padding: 7px 0; border-bottom: 1px solid rgba(255,255,255,.07); }
  td:first-child { color: rgba(255,255,255,.5); width: 40%; }
  td:last-child { font-weight: 600; font-family: monospace; word-break: break-all; }
  .ok { color: #34d399; } .warn { color: #f59e0b; } .err { color: #f87171; }
  input[type=email] { width: 100%; padding: 10px 14px; border: 1.5px solid rgba(255,255,255,.15);
    border-radius: 8px; background: rgba(255,255,255,.07); color: #fff; font-size: 14px;
    font-family: inherit; margin-bottom: 12px; outline: none; box-sizing: border-box; }
  button { background: #35E8D5; color: #0F1C3A; border: none; padding: 11px 24px;
    border-radius: 8px; font-weight: 700; font-size: 14px; cursor: pointer; }
  .result { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
  .result.ok  { background: rgba(52,211,153,.1); color: #34d399; border: 1px solid rgba(52,211,153,.2); }
  .result.err { background: rgba(239,68,68,.1);  color: #f87171; border: 1px solid rgba(239,68,68,.2); }
  .error-detail { background: rgba(0,0,0,.3); border: 1px solid rgba(248,113,113,.3);
    border-radius: 6px; padding: 10px 14px; margin-top: 8px; font-family: monospace;
    font-size: 12px; color: #fca5a5; word-break: break-all; line-height: 1.6; }
  .error-detail .label { color: rgba(255,255,255,.4); font-size: 11px; display: block;
    margin-bottom: 4px; font-family: 'Segoe UI', sans-serif; }
  .warn-box { background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.2);
    padding: 12px; border-radius: 8px; font-size: 12px; color: #fbbf24; margin-top: 20px; }
</style>
</head>
<body>
<div class="card">
  <h1>🧪 SMTP Test — LAMASY Mailer</h1>

  <table>
    <tr><td>Driver</td>
        <td class="<?= $driver === 'smtp' ? 'ok' : 'warn' ?>"><?= htmlspecialchars($driver) ?></td></tr>
    <tr><td>SMTP Host</td>
        <td><?= htmlspecialchars($smtpHost) ?></td></tr>
    <tr><td>Port / Encryption</td>
        <td><?= $smtpPort ?> / <?= defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : '-' ?></td></tr>
    <tr><td>User (From)</td>
        <td><?= htmlspecialchars($smtpUser) ?></td></tr>
    <tr><td>Password</td>
        <td class="<?= (defined('SMTP_PASS') && SMTP_PASS) ? 'ok' : 'err' ?>"><?= $smtpPass ?></td></tr>
    <tr><td>APP_URL</td>
        <td><?= defined('APP_URL') ? htmlspecialchars(APP_URL) : '-' ?></td></tr>
  </table>

  <?php if ($result === true): ?>
    <div class="result ok">✅ Email berhasil dikirim ke <strong><?= htmlspecialchars($toEmail) ?></strong>! Cek inbox (dan folder spam).</div>
  <?php elseif ($result === false): ?>
    <div class="result err">
      ❌ Email gagal dikirim.
      <?php if ($smtpError): ?>
        <div class="error-detail">
          <span class="label">Detail error:</span>
          <?= htmlspecialchars($smtpError) ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <form method="POST">
    <input type="hidden" name="key" value="<?= htmlspecialchars($_GET['key']) ?>">
    <label style="font-size:13px;color:rgba(255,255,255,.6);display:block;margin-bottom:6px">
      Kirim test email ke:
    </label>
    <input type="email" name="to" placeholder="email@kamu.com"
           value="<?= htmlspecialchars($toEmail) ?>" required>
    <button type="submit">📨 Kirim Test Email</button>
  </form>

  <div class="warn-box">
    ⚠️ <strong>Jangan lupa hapus file ini</strong> setelah selesai test.<br>
    <code>rm /path/to/harpy/test-mailer.php</code>
  </div>
</div>
</body>
</html>
