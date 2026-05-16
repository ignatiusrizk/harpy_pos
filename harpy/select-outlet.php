<?php
// ══════════════════════════════════════════════════════
// select-outlet.php — Pilih outlet setelah login
// Tidak pakai tenant_guard (outlet belum dipilih),
// tapi WAJIB ada session user_id dan tenant_id.
//
// Role-aware (per brief Akses Karyawan Section 6.5):
//   - Owner/Manager/Superadmin → semua outlet aktif tenant
//   - Kasir/Staff/Kurir → hanya outlet di hl_karyawan_outlet (assigned)
//   - Non-owner tanpa assignment → halaman "belum ditugaskan"
// ══════════════════════════════════════════════════════

if (!defined('ROOT')) define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/TenantResolver.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['tenant_id'])) {
    header('Location: /ERP/harpy/login.php'); exit;
}

$tid  = (int)$_SESSION['tenant_id'];
$uid  = (int)$_SESSION['user_id'];
$role = $_SESSION['hl_user']['role'] ?? '';
$isOwnerOrManager = in_array($role, ['owner','manager','superadmin','admin'], true);

// ── Ambil outlet sesuai role ──────────────────────────
// getAssignedOutlets() handle owner vs non-owner automatically
$outlets = TenantResolver::getAssignedOutlets();

// Non-owner tanpa assignment → halaman "belum ditugaskan"
if (empty($outlets) && !$isOwnerOrManager) {
    header('Location: /ERP/harpy/no-assignment.php');
    exit;
}

// ── Handle outlet selection POST ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['outlet_id'])) {
    $oid = (int)$_POST['outlet_id'];
    // Validasi: outlet harus ada di list yang user diizinkan akses
    $allowedIds = array_column($outlets, 'id');
    if (in_array($oid, $allowedIds)) {
        $_SESSION['outlet_id']  = $oid;
        $_SESSION['has_outlet'] = true;
        $_SESSION['hq_mode']    = false;
        header('Location: /ERP/harpy/dashboard.php'); exit;
    }
    $postError = 'Outlet tidak valid atau Anda tidak ditugaskan ke outlet ini.';
}

// Tambah orders_today untuk display
$db = Database::get();
foreach ($outlets as &$o) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE outlet_id=? AND DATE(tanggal)=CURDATE()");
    $stmt->execute([$o['id']]);
    $o['orders_today'] = (int)$stmt->fetchColumn();
}
unset($o);

// Auto-redirect jika hanya 1 outlet
if (count($outlets) === 1) {
    $_SESSION['outlet_id']  = $outlets[0]['id'];
    $_SESSION['has_outlet'] = true;
    $_SESSION['hq_mode']    = false;
    header('Location: /ERP/harpy/dashboard.php'); exit;
}

// Nama tenant untuk display
$tenantRow = Database::get()->prepare("SELECT nama_outlet FROM tenants WHERE id=? LIMIT 1");
$tenantRow->execute([$tid]);
$tenantNama = $tenantRow->fetchColumn() ?: 'Akun Anda';

$user = $_SESSION['hl_user'] ?? [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Pilih Outlet — LAMASY</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet"/>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body {
  min-height: 100vh;
  font-family: 'Plus Jakarta Sans', sans-serif;
  background: #0F1C3A;
  color: #fff;
}

/* ── Background ─────────────────────────────────── */
.bg-grad {
  position: fixed; inset: 0; z-index: 0;
  background: radial-gradient(ellipse 80% 60% at 20% 10%, rgba(53,232,213,.12) 0%, transparent 60%),
              radial-gradient(ellipse 60% 50% at 80% 90%, rgba(27,45,90,.5) 0%, transparent 70%),
              #0F1C3A;
}

/* ── Layout ──────────────────────────────────────── */
.page {
  position: relative; z-index: 1;
  min-height: 100vh;
  display: flex; flex-direction: column;
  align-items: center;
  padding: 40px 20px;
}

/* ── Header ──────────────────────────────────────── */
.header {
  text-align: center;
  margin-bottom: 40px;
}
.brand {
  display: inline-flex; align-items: center; gap: 12px;
  margin-bottom: 24px;
}
.brand-icon {
  width: 44px; height: 44px;
  background: linear-gradient(135deg, #35E8D5, #1CC9B7);
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px;
  box-shadow: 0 6px 20px rgba(53,232,213,.3);
}
.brand-name {
  font-size: 22px; font-weight: 800;
  background: linear-gradient(135deg, #35E8D5, #fff);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
  background-clip: text;
}

.header h1 {
  font-size: 26px; font-weight: 800;
  color: #fff; margin-bottom: 8px;
}
.header p {
  font-size: 14px; color: rgba(255,255,255,.5); line-height: 1.5;
}

/* ── Outlet grid ─────────────────────────────────── */
.outlet-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 16px;
  width: 100%;
  max-width: 860px;
}

/* ── Outlet card ─────────────────────────────────── */
.outlet-card {
  background: rgba(255,255,255,.05);
  border: 1.5px solid rgba(255,255,255,.08);
  border-radius: 16px;
  padding: 24px 20px;
  cursor: pointer;
  transition: all .2s cubic-bezier(.4,0,.2,1);
  text-align: left;
  position: relative;
  overflow: hidden;
}
.outlet-card::before {
  content: '';
  position: absolute; inset: 0;
  background: linear-gradient(135deg, rgba(53,232,213,.05), transparent);
  opacity: 0; transition: opacity .2s;
}
.outlet-card:hover {
  border-color: rgba(53,232,213,.4);
  background: rgba(53,232,213,.06);
  transform: translateY(-3px);
  box-shadow: 0 12px 32px rgba(0,0,0,.25), 0 0 0 1px rgba(53,232,213,.2);
}
.outlet-card:hover::before { opacity: 1; }
.outlet-card:active { transform: translateY(-1px); }

.outlet-icon {
  width: 48px; height: 48px;
  background: rgba(53,232,213,.12);
  border: 1.5px solid rgba(53,232,213,.25);
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px;
  margin-bottom: 14px;
}
.outlet-card.is-main .outlet-icon {
  background: rgba(53,232,213,.2);
  border-color: rgba(53,232,213,.4);
}

.outlet-name {
  font-size: 16px; font-weight: 700; color: #fff;
  margin-bottom: 6px; line-height: 1.3;
}
.outlet-city {
  font-size: 12px; color: rgba(255,255,255,.4);
  margin-bottom: 14px;
  display: flex; align-items: center; gap: 5px;
}

.outlet-stats {
  display: flex; gap: 12px;
}
.outlet-stat {
  background: rgba(255,255,255,.05);
  border-radius: 8px;
  padding: 6px 10px;
  flex: 1; text-align: center;
}
.outlet-stat .stat-val {
  font-size: 18px; font-weight: 800;
  color: #35E8D5; font-family: 'DM Mono', monospace;
  display: block;
}
.outlet-stat .stat-lbl {
  font-size: 10px; color: rgba(255,255,255,.35); font-weight: 600;
  text-transform: uppercase; letter-spacing: .06em;
  display: block; margin-top: 2px;
}

.main-badge {
  position: absolute; top: 14px; right: 14px;
  background: rgba(53,232,213,.15); border: 1px solid rgba(53,232,213,.3);
  color: #35E8D5; font-size: 10px; font-weight: 700;
  padding: 3px 9px; border-radius: 20px;
  letter-spacing: .06em; text-transform: uppercase;
}

.arrow-icon {
  position: absolute; bottom: 20px; right: 20px;
  font-size: 18px; color: rgba(255,255,255,.2);
  transition: all .2s;
}
.outlet-card:hover .arrow-icon {
  color: #35E8D5;
  transform: translateX(3px);
}

/* ── Error ───────────────────────────────────────── */
.error-msg {
  background: rgba(239,68,68,.12);
  border: 1px solid rgba(239,68,68,.25);
  color: #FCA5A5; font-size: 13px;
  padding: 12px 16px; border-radius: 10px;
  margin-bottom: 20px; max-width: 860px; width: 100%;
  text-align: center;
}

/* ── Footer link ─────────────────────────────────── */
.footer-link {
  margin-top: 32px; text-align: center;
}
.footer-link a {
  color: rgba(255,255,255,.35); font-size: 12px;
  text-decoration: none; transition: color .15s;
}
.footer-link a:hover { color: #35E8D5; }

/* ── Empty state ─────────────────────────────────── */
.empty-state {
  text-align: center; padding: 60px 20px;
  max-width: 400px;
}
.empty-state .icon { font-size: 48px; margin-bottom: 16px; opacity: .5; }
.empty-state h3 { font-size: 18px; font-weight: 700; color: rgba(255,255,255,.7); margin-bottom: 8px; }
.empty-state p { font-size: 13px; color: rgba(255,255,255,.35); line-height: 1.6; }

/* ── Mobile ──────────────────────────────────────── */
@media (max-width: 600px) {
  .outlet-grid { grid-template-columns: 1fr; }
  .header h1 { font-size: 22px; }
}
</style>
</head>
<body>
<div class="bg-grad"></div>
<div class="page">

  <div class="header">
    <div class="brand">
      <img src="/ERP/harpy/assets/logo.png" alt="LAMASY" style="height:44px; vertical-align:middle;">
      <div class="brand-name">LAMASY</div>
    </div>
    <h1>Pilih Outlet</h1>
    <p>Halo, <strong><?= htmlspecialchars($user['nama'] ?? 'User') ?></strong>!<br>
       Silakan pilih outlet yang akan Anda kelola sekarang.</p>
  </div>

  <?php if (!empty($postError)): ?>
    <div class="error-msg"><?= htmlspecialchars($postError) ?></div>
  <?php endif; ?>

  <?php if (empty($outlets)): ?>
    <div class="empty-state">
      <div class="icon">&#x1F3EA;</div>
      <h3>Belum ada outlet</h3>
      <p>Akun Anda (<strong><?= htmlspecialchars($tenantNama) ?></strong>) belum memiliki outlet aktif.<br>
         Hubungi admin Harpy untuk setup outlet Anda.</p>
    </div>
  <?php else: ?>
    <div class="outlet-grid">
      <?php foreach ($outlets as $o): ?>
      <form method="POST" action="">
        <input type="hidden" name="outlet_id" value="<?= (int)$o['id'] ?>"/>
        <button type="submit" class="outlet-card <?= $o['is_main'] ? 'is-main' : '' ?>">
          <?php if ($o['is_main']): ?>
            <span class="main-badge">Utama</span>
          <?php endif; ?>

          <div class="outlet-icon">&#x1F3EA;</div>
          <div class="outlet-name"><?= htmlspecialchars($o['nama_outlet']) ?></div>
          <?php if ($o['kota']): ?>
            <div class="outlet-city">
              <span>&#x1F4CD;</span>
              <?= htmlspecialchars($o['kota']) ?>
            </div>
          <?php else: ?>
            <div class="outlet-city" style="margin-bottom:14px"></div>
          <?php endif; ?>

          <div class="outlet-stats">
            <div class="outlet-stat">
              <span class="stat-val"><?= (int)$o['orders_today'] ?></span>
              <span class="stat-lbl">Order Hari Ini</span>
            </div>
            <div class="outlet-stat">
              <span class="stat-val" style="font-size:12px;padding-top:3px;display:block">
                <?= $o['status'] === 'active' ? '<span style="color:#6EE7B7">Aktif</span>' : '<span style="color:#FCA5A5">Tidak Aktif</span>' ?>
              </span>
              <span class="stat-lbl">Status</span>
            </div>
          </div>

          <span class="arrow-icon">&#x2192;</span>
        </button>
      </form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="footer-link">
    <a href="/ERP/harpy/logout.php" onclick="return confirm('Yakin ingin logout?')">
      &#x2190; Logout
    </a>
  </div>

</div>
</body>
</html>
