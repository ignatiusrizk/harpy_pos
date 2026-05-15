<?php
// ══════════════════════════════════════════════════════
// verify-email.php — Verifikasi token email
// URL: /ERP/harpy/verify-email.php?token=XXXX
// ══════════════════════════════════════════════════════

session_start();

require_once __DIR__ . '/master/config/db.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/EmailVerification.php';
require_once __DIR__ . '/core/Mailer.php';

$token  = trim($_GET['token'] ?? '');
$result = ['ok' => false, 'message' => 'Token tidak valid.'];

if (!empty($token)) {
    $result = EmailVerification::verify($token);
}

$ok      = $result['ok'] ?? false;
$message = $result['message'] ?? 'Terjadi kesalahan.';
$expired = $result['expired'] ?? false;
$alreadyUsed = $result['already_used'] ?? false;
$tenantId    = $result['tenant_id'] ?? 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $ok ? 'Email Terverifikasi' : 'Verifikasi Gagal' ?> — LAMASY</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'Segoe UI', sans-serif;
    background: #0F1C3A;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    padding: 20px;
  }
  .card {
    background: #1a2d52;
    border-radius: 16px;
    padding: 48px 40px;
    max-width: 480px;
    width: 100%;
    text-align: center;
    box-shadow: 0 8px 32px rgba(0,0,0,.3);
  }
  .icon { font-size: 64px; margin-bottom: 20px; }
  h1 { font-size: 1.75rem; margin-bottom: 12px; color: #35E8D5; }
  p { color: rgba(255,255,255,.65); line-height: 1.65; margin-bottom: 24px; }
  .btn {
    display: inline-block;
    padding: 14px 32px;
    border-radius: 10px;
    font-weight: 700;
    text-decoration: none;
    font-size: 15px;
    transition: opacity .2s;
    margin: 4px;
  }
  .btn:hover { opacity: .85; }
  .btn-primary { background: #35E8D5; color: #0F1C3A; }
  .btn-outline { border: 1px solid rgba(255,255,255,.25); color: #fff; }
  .badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 99px;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 20px;
    background: <?= $ok ? 'rgba(52,211,153,.15)' : 'rgba(239,68,68,.15)' ?>;
    color: <?= $ok ? '#34d399' : '#f87171' ?>;
    border: 1px solid <?= $ok ? 'rgba(52,211,153,.3)' : 'rgba(239,68,68,.3)' ?>;
  }
</style>
</head>
<body>
<div class="card">
  <?php if ($ok): ?>
    <div class="icon">✅</div>
    <div class="badge">Verifikasi Berhasil</div>
    <h1>Email Terverifikasi!</h1>
    <p>Akun LAMASY kamu sudah aktif. Selamat menggunakan platform manajemen laundry terbaik!<br>
    Kami juga sudah kirim email sambutan ke kotak masuk kamu.</p>
    <a href="/ERP/harpy/login.php" class="btn btn-primary">🚀 Login Sekarang</a>

  <?php elseif ($expired): ?>
    <div class="icon">⏰</div>
    <div class="badge">Link Kadaluarsa</div>
    <h1>Link Sudah Kadaluarsa</h1>
    <p>Link verifikasi ini sudah tidak berlaku (expired setelah 24 jam).<br>
    Minta kirim ulang untuk mendapatkan link baru.</p>
    <?php if ($tenantId): ?>
      <form method="POST" action="/ERP/harpy/resend-verify.php" style="display:inline">
        <input type="hidden" name="tenant_id" value="<?= (int)$tenantId ?>">
        <button type="submit" class="btn btn-primary">📨 Kirim Ulang Verifikasi</button>
      </form>
    <?php else: ?>
      <a href="/ERP/harpy/register.php" class="btn btn-primary">Daftar Ulang</a>
    <?php endif; ?>
    <a href="/ERP/harpy/login.php" class="btn btn-outline">Sudah punya akun</a>

  <?php elseif ($alreadyUsed): ?>
    <div class="icon">ℹ️</div>
    <div class="badge">Sudah Digunakan</div>
    <h1>Link Sudah Digunakan</h1>
    <p>Email kamu sudah terverifikasi sebelumnya.<br>
    Silakan login langsung.</p>
    <a href="/ERP/harpy/login.php" class="btn btn-primary">Login Sekarang</a>

  <?php else: ?>
    <div class="icon">❌</div>
    <div class="badge">Verifikasi Gagal</div>
    <h1>Verifikasi Gagal</h1>
    <p><?= htmlspecialchars($message) ?></p>
    <a href="/ERP/harpy/register.php" class="btn btn-primary">Daftar Baru</a>
    <a href="/ERP/harpy/login.php" class="btn btn-outline">Login</a>
  <?php endif; ?>

  <p style="margin-top:32px;font-size:12px;color:rgba(255,255,255,.3)">
    Butuh bantuan?
    <a href="https://wa.me/6281234567890" style="color:#35E8D5">Chat Tim LAMASY</a>
  </p>
</div>
</body>
</html>
