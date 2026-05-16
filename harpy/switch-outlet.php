<?php
// ══════════════════════════════════════════════════════
// switch-outlet.php — Pindah outlet aktif untuk tenant ini
// Aturan:
//   - Outlet harus milik tenant aktif (anti-tampering)
//   - Outlet harus dalam status trial/grace/active (tidak closed/suspended)
//   - Setelah switch, redirect kembali ke dashboard
// ══════════════════════════════════════════════════════

define('ROOT', __DIR__);
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['tenant_id'])) {
    header('Location: /ERP/harpy/login.php?msg=not_logged_in');
    exit;
}

require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/TenantResolver.php';

$outletId = (int)($_GET['id'] ?? 0);
$tenantId = (int)$_SESSION['tenant_id'];

if ($outletId <= 0) {
    header('Location: /ERP/harpy/dashboard.php');
    exit;
}

$db = Database::get();
$stmt = $db->prepare(
    "SELECT id, status FROM outlets
     WHERE id = ? AND tenant_id = ?
       AND status IN ('trial','grace','active')
     LIMIT 1"
);
$stmt->execute([$outletId, $tenantId]);
$outlet = $stmt->fetch();

if (!$outlet) {
    // Outlet tidak ditemukan / bukan milik tenant ini / status tidak valid
    header('Location: /ERP/harpy/dashboard.php?switch_error=invalid_outlet');
    exit;
}

// Set outlet baru di session, keluar dari HQ mode
$_SESSION['outlet_id']  = (int)$outlet['id'];
$_SESSION['has_outlet'] = true;
$_SESSION['hq_mode']    = false;
TenantResolver::reset();

header('Location: /ERP/harpy/dashboard.php?switched=1');
exit;
