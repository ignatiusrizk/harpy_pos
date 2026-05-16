<?php
// ══════════════════════════════════════════════════════
// outlet-suspended.php — Outlet di-suspend (trial expired tidak aktivasi)
// Owner masih bisa: bayar untuk reaktivasi (sampai purge_at),
// pindah ke outlet lain milik tenant (jika ada), atau buat outlet baru
// ══════════════════════════════════════════════════════

define('ROOT', __DIR__);
if (session_status() === PHP_SESSION_NONE) session_start();

require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';

$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
$outletId = (int)($_SESSION['outlet_id'] ?? 0);

if (!$tenantId || !$outletId) {
    header('Location: /ERP/harpy/login.php');
    exit;
}

$db = Database::get();
$stmt = $db->prepare(
    "SELECT id, nama_outlet, status, trial_ends_at, grace_ends_at, purge_at
     FROM outlets WHERE id=? AND tenant_id=? LIMIT 1"
);
$stmt->execute([$outletId, $tenantId]);
$outlet = $stmt->fetch();

if (!$outlet || $outlet['status'] !== 'suspended') {
    // Outlet tidak suspended → balik ke dashboard
    header('Location: /ERP/harpy/dashboard.php');
    exit;
}

// Hitung hari tersisa sebelum purge
$daysLeft = 0;
if (!empty($outlet['purge_at'])) {
    $daysLeft = max(0, (int)floor((strtotime($outlet['purge_at']) - time()) / 86400));
}

// Cari outlet lain yang masih aktif (kalau ada — bisa switch)
$otherStmt = $db->prepare(
    "SELECT id, nama_outlet, status FROM outlets
     WHERE tenant_id=? AND id!=? AND status IN ('trial','grace','active')
     ORDER BY is_main DESC, nama_outlet ASC LIMIT 5"
);
$otherStmt->execute([$tenantId, $outletId]);
$otherOutlets = $otherStmt->fetchAll();

$supportWa = '6281234567890';
$waMsg     = urlencode("Halo Tim LAMASY, saya mau aktivasi ulang outlet '" . ($outlet['nama_outlet'] ?? '') . "'.");
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Outlet Ditangguhkan — LAMASY</title>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',system-ui,sans-serif;background:#0F1C3A;color:#fff;
       min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
  .card{background:#1a2d52;border-radius:16px;padding:36px 28px;max-width:540px;width:100%;
        box-shadow:0 8px 40px rgba(0,0,0,.4)}
  .icon{font-size:56px;text-align:center;margin-bottom:14px}
  h1{font-size:1.35rem;font-weight:800;color:#F59E0B;text-align:center;margin-bottom:8px}
  .outlet-name{color:#fff;font-weight:700}
  p{color:rgba(255,255,255,.7);font-size:14px;line-height:1.7;text-align:center;margin-bottom:10px}
  .countdown{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);
             border-radius:10px;padding:14px;text-align:center;margin:18px 0;font-size:13px;color:#FCD34D}
  .countdown strong{font-size:20px;color:#F59E0B;display:block;margin-top:4px}
  .info{background:rgba(0,0,0,.3);border-radius:10px;padding:12px 16px;margin-bottom:16px;
        font-size:12.5px;color:rgba(255,255,255,.55)}
  .info-row{display:flex;justify-content:space-between;padding:4px 0}
  .info-row strong{color:#fff;font-weight:600}
  .btn{display:block;width:100%;padding:13px 20px;border-radius:10px;font-weight:700;font-size:14px;
       text-decoration:none;text-align:center;margin-bottom:10px;border:none;cursor:pointer;font-family:inherit}
  .btn-primary{background:#35E8D5;color:#0F1C3A}
  .btn-wa{background:#25D366;color:#fff}
  .btn-secondary{background:rgba(255,255,255,.08);border:1.5px solid rgba(255,255,255,.15);color:#fff}
  .btn-link{background:transparent;color:rgba(255,255,255,.5);text-decoration:underline;
            padding:6px;font-size:12px;margin-top:8px}
  .other-outlet{background:rgba(255,255,255,.04);border-radius:8px;padding:10px 14px;
                margin-bottom:6px;display:flex;justify-content:space-between;align-items:center;
                text-decoration:none;color:#fff;font-size:13px}
  .other-outlet:hover{background:rgba(255,255,255,.08)}
  .badge{font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px;text-transform:uppercase}
  .badge-trial{background:rgba(53,232,213,.2);color:#35E8D5}
  .badge-grace{background:rgba(245,158,11,.2);color:#F59E0B}
  .badge-active{background:rgba(52,211,153,.2);color:#34D399}
  hr{border:none;border-top:1px solid rgba(255,255,255,.08);margin:18px 0}
  .label{font-size:11px;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px}
</style>
</head>
<body>
<div class="card">
  <div class="icon">⏰</div>
  <h1>Outlet Ditangguhkan</h1>
  <p>
    Outlet <span class="outlet-name"><?= htmlspecialchars($outlet['nama_outlet']) ?></span>
    saat ini dalam status <strong style="color:#F59E0B">suspended</strong> karena masa trial
    & grace sudah berakhir tanpa aktivasi.
  </p>

  <div class="countdown">
    Data outlet masih disimpan selama
    <strong><?= $daysLeft ?> hari</strong>
    sebelum dihapus permanen.
  </div>

  <div class="info">
    <div class="info-row">
      <span>Trial berakhir:</span>
      <strong><?= $outlet['trial_ends_at'] ? date('d M Y', strtotime($outlet['trial_ends_at'])) : '-' ?></strong>
    </div>
    <div class="info-row">
      <span>Grace berakhir:</span>
      <strong><?= $outlet['grace_ends_at'] ? date('d M Y', strtotime($outlet['grace_ends_at'])) : '-' ?></strong>
    </div>
    <div class="info-row">
      <span>Data akan dihapus:</span>
      <strong><?= $outlet['purge_at'] ? date('d M Y', strtotime($outlet['purge_at'])) : '-' ?></strong>
    </div>
  </div>

  <a href="https://wa.me/<?= $supportWa ?>?text=<?= $waMsg ?>"
     target="_blank" rel="noopener" class="btn btn-wa">
    💳 Aktivasi Outlet Sekarang
  </a>
  <div style="font-size:11px;color:rgba(255,255,255,.4);text-align:center;margin:-4px 0 16px">
    Bayar setup fee untuk mengaktifkan ulang & memulihkan data
  </div>

  <?php if (!empty($otherOutlets)): ?>
  <hr>
  <div class="label">Atau pindah ke outlet lain</div>
  <?php foreach ($otherOutlets as $o): ?>
  <a href="switch-outlet.php?id=<?= (int)$o['id'] ?>" class="other-outlet">
    <span>📍 <?= htmlspecialchars($o['nama_outlet']) ?></span>
    <span class="badge badge-<?= $o['status'] ?>"><?= $o['status'] ?></span>
  </a>
  <?php endforeach; ?>
  <?php endif; ?>

  <a href="add-outlet.php" class="btn btn-secondary" style="margin-top:14px">
    🏪 Buat Outlet Baru
  </a>
  <a href="/ERP/harpy/logout.php" class="btn btn-link">Logout</a>
</div>
</body>
</html>
