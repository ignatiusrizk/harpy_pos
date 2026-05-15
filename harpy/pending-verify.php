<?php
// ══════════════════════════════════════════════════════
// pending-verify.php — Halaman "cek email kamu"
// Ditampilkan setelah registrasi, sebelum klik link verif
// ══════════════════════════════════════════════════════

session_start();

require_once __DIR__ . '/master/config/db.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/EmailVerification.php';
require_once __DIR__ . '/core/Mailer.php';

// Harus ada tenant_id di session (dari proses registrasi)
if (!isset($_SESSION['tenant_id'])) {
    header('Location: /ERP/harpy/login.php');
    exit;
}

$tenantId = (int)$_SESSION['tenant_id'];

// Ambil data tenant
$stmt = Database::get()->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();

if (!$tenant) {
    session_destroy();
    header('Location: /ERP/harpy/login.php?error=tenant_not_found');
    exit;
}

// Kalau sudah verified, redirect ke dashboard
if ($tenant['verified_at'] !== null) {
    header('Location: /ERP/harpy/dashboard.php');
    exit;
}

$email      = $tenant['email'] ?? '';
$ownerName  = $tenant['owner_name'] ?? 'Pengguna';
$namaOutlet = $tenant['nama_outlet'] ?? '';

// ── Handle resend request ─────────────────────────
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend') {
    $result = EmailVerification::resend($tenantId, $email);
    if ($result['ok']) {
        $flash = 'success:Email verifikasi sudah dikirim ulang ke ' . htmlspecialchars($email);
    } else {
        $flash = 'error:' . htmlspecialchars($result['message']);
    }
}

[$flashType, $flashMsg] = $flash ? explode(':', $flash, 2) : ['', ''];

// Mask email untuk display: riz***@gmail.com
function maskEmail(string $email): string {
    [$local, $domain] = explode('@', $email . '@');
    $masked = substr($local, 0, 3) . str_repeat('*', max(0, strlen($local) - 3));
    return $masked . '@' . $domain;
}
$maskedEmail = $email ? maskEmail($email) : '***';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cek Email Kamu — LAMASY</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'Segoe UI', sans-serif;
    background: #0F1C3A;
    color: #fff;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    padding: 20px;
  }
  .brand {
    font-size: 22px;
    font-weight: 800;
    color: #35E8D5;
    margin-bottom: 32px;
    letter-spacing: -.5px;
  }
  .brand small { display: block; font-size: 12px; color: rgba(255,255,255,.4); font-weight: 400; }
  .card {
    background: #1a2d52;
    border-radius: 16px;
    padding: 48px 40px;
    max-width: 480px;
    width: 100%;
    text-align: center;
    box-shadow: 0 8px 32px rgba(0,0,0,.3);
  }
  .envelope { font-size: 64px; margin-bottom: 20px; animation: bounce 2s ease-in-out infinite; }
  @keyframes bounce {
    0%,100% { transform: translateY(0); }
    50%      { transform: translateY(-8px); }
  }
  h1 { font-size: 1.6rem; margin-bottom: 12px; color: #35E8D5; }
  .subtitle { color: rgba(255,255,255,.6); line-height: 1.65; margin-bottom: 28px; font-size: 15px; }
  .email-badge {
    display: inline-block;
    background: rgba(53,232,213,.1);
    border: 1px solid rgba(53,232,213,.25);
    color: #35E8D5;
    padding: 8px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 15px;
    margin-bottom: 28px;
    word-break: break-all;
  }
  .steps {
    background: rgba(255,255,255,.04);
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 28px;
    text-align: left;
  }
  .step {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 12px;
    font-size: 14px;
    color: rgba(255,255,255,.7);
  }
  .step:last-child { margin-bottom: 0; }
  .step-num {
    width: 22px;
    height: 22px;
    background: #35E8D5;
    color: #0F1C3A;
    border-radius: 50%;
    font-weight: 800;
    font-size: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-top: 1px;
  }
  .divider { border: none; border-top: 1px solid rgba(255,255,255,.08); margin: 24px 0; }
  .resend-form { margin-bottom: 16px; }
  .btn {
    display: inline-block;
    padding: 12px 28px;
    border-radius: 10px;
    font-weight: 700;
    text-decoration: none;
    font-size: 14px;
    cursor: pointer;
    border: none;
    transition: opacity .2s;
    margin: 4px;
  }
  .btn:hover { opacity: .85; }
  .btn-outline {
    background: transparent;
    border: 1px solid rgba(255,255,255,.2);
    color: rgba(255,255,255,.6);
    font-size: 13px;
  }
  .btn-resend { background: rgba(53,232,213,.15); color: #35E8D5; border: 1px solid rgba(53,232,213,.3); }
  .flash {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 14px;
    font-weight: 500;
  }
  .flash.success { background: rgba(52,211,153,.1); color: #34d399; border: 1px solid rgba(52,211,153,.2); }
  .flash.error   { background: rgba(239,68,68,.1);   color: #f87171; border: 1px solid rgba(239,68,68,.2); }
  .logout { font-size: 12px; color: rgba(255,255,255,.3); margin-top: 24px; }
  .logout a { color: rgba(255,255,255,.4); }
</style>
</head>
<body>

<div class="brand">
  LAMASY
  <small>Laundry Management System by Harpy</small>
</div>

<div class="card">
  <div class="envelope">📬</div>
  <h1>Cek Email Kamu!</h1>
  <p class="subtitle">
    Kami sudah kirim link verifikasi ke:
  </p>
  <div class="email-badge">📧 <?= htmlspecialchars($maskedEmail) ?></div>

  <?php if ($flashMsg): ?>
    <div class="flash <?= htmlspecialchars($flashType) ?>"><?= htmlspecialchars($flashMsg) ?></div>
  <?php endif; ?>

  <div class="steps">
    <div class="step">
      <div class="step-num">1</div>
      <div>Buka aplikasi email kamu dan cari email dari <strong>LAMASY</strong></div>
    </div>
    <div class="step">
      <div class="step-num">2</div>
      <div>Klik tombol <strong>"Verifikasi Email Sekarang"</strong> di dalam email</div>
    </div>
    <div class="step">
      <div class="step-num">3</div>
      <div>Akun kamu langsung aktif dan bisa digunakan!</div>
    </div>
  </div>

  <p style="font-size:13px;color:rgba(255,255,255,.4);margin-bottom:20px">
    Tidak menemukan email? Cek folder <strong>Spam / Promosi</strong> juga.
    Link berlaku <strong>24 jam</strong>.
  </p>

  <hr class="divider">

  <div class="resend-form">
    <p style="font-size:13px;color:rgba(255,255,255,.45);margin-bottom:12px">
      Email tidak masuk?
    </p>
    <form method="POST">
      <input type="hidden" name="action" value="resend">
      <button type="submit" class="btn btn-resend">📨 Kirim Ulang Email</button>
    </form>
  </div>

  <a href="https://wa.me/6281234567890?text=Halo+Tim+LAMASY%2C+saya+butuh+bantuan+verifikasi+email+untuk+outlet+<?= urlencode($namaOutlet) ?>"
     class="btn btn-outline" target="_blank">
    💬 Hubungi Support WhatsApp
  </a>
</div>

<div class="logout">
  Daftar dengan email salah?
  <a href="/ERP/harpy/logout.php">Keluar & daftar ulang</a>
</div>

</body>
</html>
