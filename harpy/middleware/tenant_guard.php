<?php
// ══════════════════════════════════════════════════════
// middleware/tenant_guard.php
// Include di baris PERTAMA setiap halaman operasional.
//
// Usage:
//   define('ROOT', dirname(__DIR__));   // sesuaikan jika berbeda
//   require_once ROOT . '/middleware/tenant_guard.php';
//
//   // Setelah ini langsung bisa pakai:
//   TenantQuery::fetch('hl_transaksi', 'status_proses = ?', ['masuk'])
//   CoinLedger::deduct('send_wa_notif')
//   TenantResolver::id()          // tenant_id saat ini
//   TenantResolver::namaOutlet()  // nama outlet
//   currentUser()                 // data user yang login
//   hasPermission('orders.edit')  // cek permission
// ══════════════════════════════════════════════════════

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Load config & core ────────────────────────────────
if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__));
}
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/TenantResolver.php';
require_once ROOT . '/core/TenantQuery.php';
require_once ROOT . '/core/CoinLedger.php';

// ── Cek login ─────────────────────────────────────────
if (empty($_SESSION['user_id']) || empty($_SESSION['tenant_id'])) {
    if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Sesi habis. Silakan login kembali.', 'redirect' => '/ERP/harpy/login.php']);
    } else {
        header('Location: /ERP/harpy/login.php?msg=session_expired');
    }
    exit;
}

// ── Cross-redirect: mitra TIDAK boleh akses outlet pages ──
// (brief acceptance #3 & #9)
if (($_SESSION['role'] ?? '') === 'mitra') {
    if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Akses ditolak — Anda mitra drop point.', 'redirect' => '/ERP/harpy/droppoint/dashboard.php']);
    } else {
        header('Location: /ERP/harpy/droppoint/dashboard.php');
    }
    exit;
}

// ── Resolve & validasi tenant ─────────────────────────
TenantResolver::resolve();

// ── Session timeout ───────────────────────────────────
$_now = time();
if (isset($_SESSION['hl_last_activity'])) {
    if ($_now - $_SESSION['hl_last_activity'] > SESSION_TIMEOUT) {
        session_destroy();
        if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Sesi habis. Silakan login kembali.', 'redirect' => '/ERP/harpy/login.php']);
        } else {
            header('Location: /ERP/harpy/login.php?msg=session_expired');
        }
        exit;
    }
}
if (isset($_SESSION['hl_login_time'])) {
    if ($_now - $_SESSION['hl_login_time'] > SESSION_LIFETIME) {
        session_destroy();
        header('Location: /ERP/harpy/login.php?msg=session_expired');
        exit;
    }
}
$_SESSION['hl_last_activity'] = $_now;

// ── Anomaly Detector + Daily Report (1x per 30 menit per session) ──
// Pseudo-cron: skip AJAX supaya tidak nambah latency response.
if (empty($_GET['action']) && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    if (!isset($_SESSION['last_anomaly_check']) || ($_now - $_SESSION['last_anomaly_check']) > 1800) {
        $_SESSION['last_anomaly_check'] = $_now;
        try {
            if (TenantResolver::hasOutlet()) {
                $_tid = (int)TenantResolver::id();
                $_oid = (int)TenantResolver::outletId();
                require_once ROOT . '/core/AnomalyDetector.php';
                AnomalyDetector::check($_tid, $_oid);
                require_once ROOT . '/core/DailyReport.php';
                DailyReport::maybeSend($_tid, $_oid);
                require_once ROOT . '/core/SegmentasiManager.php';
                SegmentasiManager::updateAll($_tid, $_oid);
            }
        } catch (Throwable $e) { error_log('[pseudocron] '.$e->getMessage()); }
    }
}

// ════════════════════════════════════════
// HELPER FUNCTIONS
// ════════════════════════════════════════

// ── User yang sedang login ────────────────────────────
function currentUser(): ?array
{
    return $_SESSION['hl_user'] ?? null;
}

// ── Cek permission ────────────────────────────────────
function hasPermission(string $kode): bool
{
    // Delegasi ke TenantResolver::can() supaya alias permission konsisten
    if (class_exists('TenantResolver') && method_exists('TenantResolver', 'can')) {
        return TenantResolver::can($kode);
    }
    // Fallback kalau resolver belum loaded
    $perms = $_SESSION['hl_permissions'] ?? [];
    if (isset($perms['*'])) return true;
    $role = $_SESSION['hl_user']['role'] ?? '';
    if (in_array($role, ['owner','superadmin'], true)) return true;
    return isset($perms[$kode]);
}

function requirePermission(string $kode): void
{
    if (!hasPermission($kode)) {
        if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Akses ditolak — permission tidak cukup.']);
        } else {
            http_response_code(403);
            die('<div style="font-family:sans-serif;padding:40px;text-align:center;background:#0F1C3A;color:#fff;min-height:100vh">
                <h2 style="color:#35E8D5">🔒 Akses Ditolak</h2>
                <p style="color:rgba(255,255,255,.6)">Anda tidak memiliki izin untuk halaman ini.</p>
                <a href="javascript:history.back()" style="color:#35E8D5">← Kembali</a>
            </div>');
        }
        exit;
    }
}

// ── Grace mode: blokir operasi tulis ─────────────────
// Dipanggil di action handler yang memodifikasi data.
// Di grace period, user hanya boleh baca (view), tidak bisa create/update/delete.
function requireNotGrace(string $message = ''): void
{
    if (!TenantResolver::isGraceMode()) return;

    $daysLeft = TenantResolver::graceDaysLeft();
    $msg = $message ?: "Outlet dalam grace period ($daysLeft hari tersisa). "
                     . "Operasi ini tidak tersedia. Aktifkan outlet untuk melanjutkan.";

    if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $msg, 'grace_mode' => true]);
    } else {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:40px;text-align:center;background:#0F1C3A;color:#fff;min-height:100vh">
            <h2 style="color:#F59E0B">⏰ Grace Period</h2>
            <p style="color:rgba(255,255,255,.6);max-width:380px;margin:0 auto 24px">' . htmlspecialchars($msg) . '</p>
            <a href="/ERP/harpy/billing.php" style="background:#35E8D5;color:#0F1C3A;padding:12px 28px;border-radius:8px;font-weight:700;text-decoration:none">Aktifkan Outlet</a>
            &nbsp;
            <a href="javascript:history.back()" style="color:#35E8D5;margin-left:12px">← Kembali</a>
        </div>');
    }
    exit;
}

// ── Shorthand DB (untuk backward compat) ─────────────
function getDB(): PDO
{
    return Database::get();
}

// ── Info tenant saat ini ──────────────────────────────
function currentTenant(): array
{
    return TenantResolver::get();
}

// ── Info outlet saat ini ──────────────────────────────
function currentOutlet(): array
{
    return TenantResolver::getOutlet();
}

// ── Coin balance (dari session, tanpa query DB) ────────
function tenantCoinBalance(): int
{
    return (int) ($_SESSION['tenant_coin_balance'] ?? 0);
}

// ── Audit log ─────────────────────────────────────────
function logAudit(
    string  $aksi,
    string  $modul,
    string  $keterangan = '',
    ?string $refId      = null
): void {
    $user = currentUser();
    try {
        TenantQuery::insert('hl_audit_log', [
            'user_id'    => $user['id']        ?? null,
            'user_nama'  => $user['nama']      ?? null,
            'user_role'  => $user['role_nama'] ?? $user['role'] ?? null,
            'modul'      => $modul,
            'aksi'       => $aksi,
            'keterangan' => $keterangan,
            'ref_id'     => $refId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 255),
        ]);
    } catch (Throwable) {
        // Jangan gagalkan request karena audit gagal
    }
}

// ── CSRF ──────────────────────────────────────────────
function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(getCsrfToken(), $token)) {
        http_response_code(403);
        if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'CSRF token tidak valid.']);
        } else {
            die('Request tidak valid (CSRF).');
        }
        exit;
    }
}
