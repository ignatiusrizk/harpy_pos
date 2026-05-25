<?php
// ══════════════════════════════════════════════════════
// middleware/hq_guard.php — Guard untuk halaman HQ view
//
// HQ view = mode konsolidasi lintas outlet (kantor pusat).
// Berbeda dengan tenant_guard (yang scope-nya outlet aktif).
//
// Usage:
//   define('ROOT', dirname(__DIR__));
//   require_once ROOT . '/middleware/hq_guard.php';
//
// Yang di-provide:
//   $hqTenant   — data tenant aktif
//   $hqUser     — data user yang login
//   hqCsrf()    — token CSRF
//   verifyHqCsrf()
//   currentTenant() — alias agar components.php tetap kompatibel
//   currentUser()
// ══════════════════════════════════════════════════════

if (session_status() === PHP_SESSION_NONE) session_start();

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/TenantResolver.php';

// ── Auth check ────────────────────────────────────────
if (empty($_SESSION['user_id']) || empty($_SESSION['tenant_id'])) {
    if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['error'=>'Sesi habis. Silakan login kembali.', 'redirect'=>'/ERP/harpy/login.php']);
    } else {
        header('Location: /ERP/harpy/login.php?msg=not_logged_in');
    }
    exit;
}

// ── Session timeout (sama dengan tenant_guard) ────────
$_now = time();
if (isset($_SESSION['hl_last_activity'])
    && defined('SESSION_TIMEOUT')
    && ($_now - $_SESSION['hl_last_activity'] > SESSION_TIMEOUT)) {
    session_destroy();
    if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['error'=>'Sesi habis. Silakan login kembali.', 'redirect'=>'/ERP/harpy/login.php']);
    } else {
        header('Location: /ERP/harpy/login.php?msg=session_expired');
    }
    exit;
}
if (isset($_SESSION['hl_login_time'])
    && defined('SESSION_LIFETIME')
    && ($_now - $_SESSION['hl_login_time'] > SESSION_LIFETIME)) {
    session_destroy();
    header('Location: /ERP/harpy/login.php?msg=session_expired');
    exit;
}
$_SESSION['hl_last_activity'] = $_now;

$db = Database::get();

// ── Load tenant ───────────────────────────────────────
$_stmt = $db->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
$_stmt->execute([$_SESSION['tenant_id']]);
$hqTenant = $_stmt->fetch();

if (!$hqTenant) {
    session_destroy();
    header('Location: /ERP/harpy/login.php?error=tenant_not_found');
    exit;
}

// ── Tenant status check ───────────────────────────────
if ($hqTenant['status'] === 'pending_verification') {
    header('Location: /ERP/harpy/pending-verify.php');
    exit;
}

if (in_array($hqTenant['status'], ['suspended', 'closed'])) {
    header('Location: /ERP/harpy/account-suspended.php');
    exit;
}

// ── Role check: owner, manager, superadmin (brief 6.7 point 2) ──
$hqUser = $_SESSION['hl_user'] ?? [];
$hqRole = $hqUser['role'] ?? '';

if (!in_array($hqRole, ['owner', 'manager', 'superadmin'])) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:60px;text-align:center;background:#0F1C3A;color:#fff;min-height:100vh">
        <h2 style="color:#35E8D5">🔒 Akses HQ Ditolak</h2>
        <p style="color:rgba(255,255,255,.6)">HQ view hanya untuk Owner & Manager.</p>
        <a href="/ERP/harpy/dashboard.php" style="color:#35E8D5">← Kembali ke Dashboard</a>
    </div>');
}

// ── Set HQ mode flag di session ───────────────────────
$_SESSION['hq_mode'] = true;

// ── Permission helpers untuk HQ (brief 3.2 & 6.8) ─────
// Manager: bisa akses HQ tapi terbatas — tidak boleh billing,
// tidak boleh manage outlets, tidak boleh ubah account settings sensitif.
$hqIsOwner   = in_array($hqRole, ['owner','superadmin'], true);
$hqIsManager = $hqRole === 'manager';
$hqCanBilling      = $hqIsOwner;   // topup, coin mode, paket, settings password
$hqCanManageOutlet = $hqIsOwner;   // tambah outlet, edit outlet, set main
$hqCanManageRole   = $hqIsOwner;   // create/edit/delete role
$hqCanViewAudit    = true;          // owner + manager boleh lihat audit

// ── Helpers (compatible dengan components.php) ────────
if (!function_exists('currentUser')) {
    function currentUser(): ?array {
        return $_SESSION['hl_user'] ?? null;
    }
}
if (!function_exists('currentTenant')) {
    function currentTenant(): array {
        global $hqTenant;
        return $hqTenant ?? [];
    }
}
if (!function_exists('getCsrfToken')) {
    function getCsrfToken(): string {
        if (empty($_SESSION['hl_csrf'])) {
            $_SESSION['hl_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['hl_csrf'];
    }
}
if (!function_exists('verifyCsrf')) {
    function verifyCsrf(): void {
        $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['hl_csrf'] ?? '', $token)) {
            http_response_code(403);
            die('CSRF mismatch');
        }
    }
}
