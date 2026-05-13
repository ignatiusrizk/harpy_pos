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
        echo json_encode(['error' => 'Sesi habis. Silakan login kembali.', 'redirect' => '/login']);
    } else {
        header('Location: /login?msg=session_expired');
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
            echo json_encode(['error' => 'Sesi habis. Silakan login kembali.', 'redirect' => '/login']);
        } else {
            header('Location: /login?msg=session_expired');
        }
        exit;
    }
}
if (isset($_SESSION['hl_login_time'])) {
    if ($_now - $_SESSION['hl_login_time'] > SESSION_LIFETIME) {
        session_destroy();
        header('Location: /login?msg=session_expired');
        exit;
    }
}
$_SESSION['hl_last_activity'] = $_now;

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
    $perms = $_SESSION['hl_permissions'] ?? [];
    if (isset($perms['*'])) return true;   // superadmin bypass
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
