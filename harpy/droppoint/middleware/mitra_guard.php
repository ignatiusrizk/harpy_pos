<?php
// ══════════════════════════════════════════════════════
// droppoint/middleware/mitra_guard.php
// Guard untuk portal mitra drop point.
// Include di baris PERTAMA setiap halaman /droppoint/.
//
// Usage:
//   define('ROOT', dirname(__DIR__, 2));
//   require_once __DIR__ . '/middleware/mitra_guard.php';
//
//   // Setelah ini tersedia:
//   $mitraId   = $mitra['drop_point_id'];
//   $tenantId  = $mitra['tenant_id'];
//   $outletId  = $mitra['outlet_id'];
//   $userId    = $mitra['user_id'];
//   $dropPoint = $mitra['dp']; // row hl_drop_points
// ══════════════════════════════════════════════════════

if (session_status() === PHP_SESSION_NONE) session_start();

if (!defined('ROOT')) define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';

// ── Validasi session ──
$role  = $_SESSION['role'] ?? ($_SESSION['hl_user']['role'] ?? null);
$uId   = (int)($_SESSION['user_id'] ?? 0);
$dpId  = (int)($_SESSION['drop_point_id'] ?? 0);
$tId   = (int)($_SESSION['tenant_id'] ?? 0);
$oId   = (int)($_SESSION['outlet_id'] ?? 0);

if ($role !== 'mitra' || !$uId || !$dpId || !$tId) {
    if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['error'=>'Sesi mitra tidak valid.', 'redirect'=>'/ERP/harpy/login.php']);
    } else {
        header('Location: /ERP/harpy/login.php?error=akses_ditolak');
    }
    exit;
}

// ── Session timeout ──
$_now = time();
if (isset($_SESSION['hl_last_activity']) && ($_now - $_SESSION['hl_last_activity']) > 1800) {
    session_destroy();
    header('Location: /ERP/harpy/login.php?msg=session_expired');
    exit;
}
$_SESSION['hl_last_activity'] = $_now;

// ── Verifikasi mitra masih aktif & match (anti-tamper) ──
try {
    $db = Database::get();
    $stmt = $db->prepare("
        SELECT dp.*, u.nama AS user_nama
          FROM hl_drop_points dp
          JOIN hl_users u ON u.id=? AND u.drop_point_id=dp.id
                        AND u.tenant_id=dp.tenant_id AND u.role='mitra' AND u.is_active=1
         WHERE dp.id=? AND dp.tenant_id=? AND dp.outlet_id=? AND dp.status='aktif'
         LIMIT 1
    ");
    $stmt->execute([$uId, $dpId, $tId, $oId]);
    $dp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dp) {
        session_destroy();
        header('Location: /ERP/harpy/login.php?error=mitra_nonaktif');
        exit;
    }
} catch (Throwable $e) {
    error_log('[mitra_guard] ' . $e->getMessage());
    http_response_code(500);
    die('Sistem error. Hubungi admin.');
}

// ── Context global untuk halaman ──
$mitra = [
    'user_id'       => $uId,
    'user_nama'     => $dp['user_nama'],
    'tenant_id'     => $tId,
    'outlet_id'     => $oId,
    'drop_point_id' => $dpId,
    'dp'            => $dp,           // row hl_drop_points (nama_mitra, komisi_*, dll)
];

// ── Helper umum portal mitra ──
function mitraEsc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function mitraDb(): PDO { return Database::get(); }

/** CSRF khusus portal mitra */
function mitraCsrf(): string {
    if (empty($_SESSION['mitra_csrf'])) $_SESSION['mitra_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['mitra_csrf'];
}
function mitraVerifyCsrf(): void {
    $t = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(mitraCsrf(), $t)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error'=>'CSRF tidak valid.']);
        exit;
    }
}
