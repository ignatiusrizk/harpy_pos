<?php
// ══════════════════════════════════════════════════════
// account-suspended.php — Akun (tenant) di-suspend Super Admin
// Tidak ada self-recovery; harus kontak support
// ══════════════════════════════════════════════════════

define('ROOT', __DIR__);
if (session_status() === PHP_SESSION_NONE) session_start();

require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';

// Minimal data dari session
$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
$tenantNama = '';
$tenantEmail = '';

if ($tenantId) {
    $db   = Database::get();
    $stmt = $db->prepare("SELECT nama_outlet, email FROM tenants WHERE id=? AND status IN ('suspended','closed') LIMIT 1");
    $stmt->execute([$tenantId]);
    $t = $stmt->fetch();
    if ($t) {
        $tenantNama  = $t['nama_outlet'] ?? '';
        $tenantEmail = $t['email'] ?? '';
    } else {
        // Tidak suspended → kembalikan ke dashboard
        header('Location: /ERP/harpy/dashboard.php');
        exit;
    }
}

$supportWa    = '6281234567890';
$supportEmail = 'support@harpy.id';
$waMessage    = urlencode("Halo Tim LAMASY, akun saya ($tenantEmail) statusnya suspended. Mohon bantuan untuk reaktivasi.");
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Akun Ditangguhkan — LAMASY</title>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',system-ui,sans-serif;background:#0F1C3A;color:#fff;
       min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
  .card{background:#1a2d52;border-radius:16px;padding:40px 32px;max-width:520px;width:100%;
        text-align:center;box-shadow:0 8px 40px rgba(0,0,0,.4)}
  .icon{font-size:64px;margin-bottom:20px;line-height:1}
  h1{font-size:1.4rem;font-weight:800;color:#F87171;margin-bottom:12px}
  p{color:rgba(255,255,255,.7);font-size:14px;line-height:1.7;margin-bottom:8px}
  .info{background:rgba(0,0,0,.3);border-radius:10px;padding:14px 18px;margin:20px 0;
        font-size:13px;color:rgba(255,255,255,.6);text-align:left}
  .info strong{color:#fff;font-weight:700}
  .btn{display:inline-block;padding:12px 24px;border-radius:10px;font-weight:700;
       font-size:14px;text-decoration:none;margin:6px 4px;transition:opacity .2s}
  .btn-wa{background:#25D366;color:#fff}
  .btn-email{background:rgba(53,232,213,.15);border:1.5px solid rgba(53,232,213,.4);color:#35E8D5}
  .btn-logout{background:transparent;border:1.5px solid rgba(255,255,255,.15);color:rgba(255,255,255,.6);
              padding:8px 16px;font-size:13px;margin-top:24px}
  .btn:hover{opacity:.85}
</style>
</head>
<body>
<div class="card">
  <div class="icon">🔒</div>
  <h1>Akun Anda Ditangguhkan</h1>
  <p>
    Akun <strong style="color:#fff"><?= htmlspecialchars($tenantNama ?: $tenantEmail) ?></strong>
    saat ini dalam status <strong style="color:#F87171">suspended</strong>.
  </p>
  <p>
    Akses ke sistem telah dinonaktifkan oleh tim LAMASY. Hubungi support kami untuk informasi
    lebih lanjut dan proses reaktivasi.
  </p>

  <div class="info">
    <div style="margin-bottom:6px"><strong>Email akun:</strong> <?= htmlspecialchars($tenantEmail) ?></div>
    <div><strong>Status:</strong> Suspended (oleh Super Admin)</div>
  </div>

  <div>
    <a href="https://wa.me/<?= $supportWa ?>?text=<?= $waMessage ?>"
       target="_blank" rel="noopener" class="btn btn-wa">
      💬 Chat WhatsApp Support
    </a>
    <a href="mailto:<?= $supportEmail ?>?subject=Reaktivasi%20Akun%20<?= urlencode($tenantEmail) ?>"
       class="btn btn-email">
      ✉️ Email Support
    </a>
  </div>

  <a href="/ERP/harpy/logout.php" class="btn btn-logout">Logout</a>
</div>
</body>
</html>
