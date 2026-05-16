<?php
// ══════════════════════════════════════════════════════
// no-assignment.php — Halaman info karyawan belum ditugaskan
// Per brief Akses Karyawan Section 6.6
// ══════════════════════════════════════════════════════

define('ROOT', __DIR__);
if (session_status() === PHP_SESSION_NONE) session_start();

require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';

// Auth check
if (empty($_SESSION['user_id']) || empty($_SESSION['tenant_id'])) {
    header('Location: /ERP/harpy/login.php');
    exit;
}

$user      = $_SESSION['hl_user'] ?? [];
$userNama  = $user['nama']  ?? 'Anda';
$userRole  = $user['role']  ?? 'staff';
$tenantId  = (int)$_SESSION['tenant_id'];

// Cek lagi: kalau ternyata sudah ditugaskan, redirect kembali
try {
    $db = Database::get();
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM hl_karyawan_outlet
         WHERE tenant_id=? AND karyawan_id=? AND is_active=1"
    );
    $stmt->execute([$tenantId, $_SESSION['user_id']]);
    if ((int)$stmt->fetchColumn() > 0) {
        header('Location: /ERP/harpy/select-outlet.php');
        exit;
    }
    $tenantStmt = $db->prepare("SELECT nama_outlet, owner_wa FROM tenants WHERE id=? LIMIT 1");
    $tenantStmt->execute([$tenantId]);
    $tenant = $tenantStmt->fetch() ?: [];
} catch (Throwable) {
    $tenant = [];
}

$ownerWa  = $tenant['owner_wa'] ?? '6281234567890';
$tenantNm = $tenant['nama_outlet'] ?? 'admin Anda';
$waMsg    = urlencode("Halo, saya $userNama. Akun saya belum ditugaskan ke outlet. Mohon dibantu untuk assignment-nya.");
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Belum Ditugaskan — LAMASY</title>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',system-ui,sans-serif;background:#0F1C3A;color:#fff;
       min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
  .card{background:#1a2d52;border-radius:16px;padding:40px 32px;max-width:520px;width:100%;
        text-align:center;box-shadow:0 8px 40px rgba(0,0,0,.4)}
  .icon{font-size:64px;margin-bottom:18px}
  h1{font-size:1.3rem;font-weight:800;color:#FCD34D;margin-bottom:12px}
  p{color:rgba(255,255,255,.7);font-size:14px;line-height:1.7;margin-bottom:10px}
  .info{background:rgba(0,0,0,.3);border-radius:10px;padding:14px 18px;margin:20px 0;
        font-size:13px;color:rgba(255,255,255,.6);text-align:left}
  .info strong{color:#fff;font-weight:700}
  .btn{display:inline-block;padding:11px 22px;border-radius:10px;font-weight:700;
       font-size:14px;text-decoration:none;margin:6px 4px;transition:opacity .2s;font-family:inherit;
       border:none;cursor:pointer}
  .btn-wa{background:#25D366;color:#fff}
  .btn-logout{background:transparent;border:1.5px solid rgba(255,255,255,.15);color:rgba(255,255,255,.6);
              padding:8px 16px;font-size:13px;margin-top:20px}
  .btn:hover{opacity:.85}
</style>
</head>
<body>
<div class="card">
  <div class="icon">📋</div>
  <h1>Akun Anda Belum Ditugaskan</h1>
  <p>
    Halo <strong style="color:#fff"><?= htmlspecialchars($userNama) ?></strong>!
    Akun Anda saat ini belum ditugaskan ke outlet manapun.
  </p>
  <p>
    Hubungi admin/owner Anda untuk menugaskan Anda ke outlet sebelum bisa
    menggunakan sistem.
  </p>

  <div class="info">
    <div style="margin-bottom:6px"><strong>Akun:</strong> <?= htmlspecialchars($user['username'] ?? '-') ?></div>
    <div style="margin-bottom:6px"><strong>Role:</strong> <?= ucfirst(htmlspecialchars($userRole)) ?></div>
    <div><strong>Status:</strong> <span style="color:#FCD34D">Belum ada penugasan aktif</span></div>
  </div>

  <a href="https://wa.me/<?= htmlspecialchars($ownerWa) ?>?text=<?= $waMsg ?>"
     target="_blank" rel="noopener" class="btn btn-wa">
    💬 Hubungi Admin via WhatsApp
  </a>

  <div>
    <a href="/ERP/harpy/logout.php" class="btn btn-logout">Logout</a>
  </div>
</div>
</body>
</html>
