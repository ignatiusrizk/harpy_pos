<?php
// ══════════════════════════════════════════════
// auth.php — Harpy Laundry Auth System
// Include file ini di semua halaman yang dilindungi
// ══════════════════════════════════════════════

require_once __DIR__ . '/config.local.php';

// Timezone Indonesia WIB
date_default_timezone_set('Asia/Jakarta');

// ── SESSION CONFIG ────────────────────────────
define('SESSION_TIMEOUT',  1 * 60 * 60);   // idle 1 jam
define('SESSION_LIFETIME', 10 * 60 * 60);  // maks 10 jam sejak login

ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.cookie_secure', 1);   // hanya kirim via HTTPS

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── SECURITY HEADERS ─────────────────────────
function sendSecurityHeaders(): void {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// ── DATABASE ──────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE         => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            $pdo->exec("SET time_zone = '+07:00'");
        } catch (PDOException $e) {
            // Jangan expose detail koneksi ke browser
            error_log('DB connection error: ' . $e->getMessage());
            http_response_code(500);
            die('<div style="font-family:sans-serif;padding:40px">Koneksi database gagal. Hubungi administrator.</div>');
        }
    }
    return $pdo;
}

// ── Auto create users table & seed default admin ──
function initAuthTable(): void {
    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS hl_users (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        username   VARCHAR(50)  NOT NULL UNIQUE,
        password   VARCHAR(255) NOT NULL,
        nama       VARCHAR(100) NOT NULL,
        role       ENUM('superadmin','admin','staff') DEFAULT 'staff',
        is_active  TINYINT(1) DEFAULT 1,
        last_login TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $count = $pdo->query("SELECT COUNT(*) FROM hl_users")->fetchColumn();
    if ($count == 0) {
        $hash = password_hash('HarpyAdmin2025!', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO hl_users (username, password, nama, role) VALUES (?,?,?,?)")
            ->execute(['admin', $hash, 'Administrator', 'superadmin']);
    }
}

// ── LOGIN ATTEMPT / BRUTE FORCE PROTECTION ────
function initLoginAttemptsTable(): void {
    getDB()->exec("CREATE TABLE IF NOT EXISTS hl_login_attempts (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(100) NOT NULL,
        ip_address VARCHAR(45)  NOT NULL,
        attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_identifier (identifier),
        INDEX idx_ip (ip_address),
        INDEX idx_time (attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Return true jika identifier/IP sedang dikunci
function isLoginLocked(string $identifier, string $ip): bool {
    try {
        initLoginAttemptsTable();
        $pdo   = getDB();
        $since = date('Y-m-d H:i:s', time() - 15 * 60); // window 15 menit
        $stmt  = $pdo->prepare("SELECT COUNT(*) FROM hl_login_attempts
            WHERE (identifier=? OR ip_address=?) AND attempted_at >= ?");
        $stmt->execute([$identifier, $ip, $since]);
        return (int)$stmt->fetchColumn() >= 5;
    } catch (Exception $e) { return false; }
}

function recordLoginAttempt(string $identifier, string $ip): void {
    try {
        initLoginAttemptsTable();
        getDB()->prepare("INSERT INTO hl_login_attempts (identifier, ip_address) VALUES (?,?)")
            ->execute([$identifier, $ip]);
    } catch (Exception $e) {}
}

function clearLoginAttempts(string $identifier, string $ip): void {
    try {
        getDB()->prepare("DELETE FROM hl_login_attempts WHERE identifier=? OR ip_address=?")
            ->execute([$identifier, $ip]);
    } catch (Exception $e) {}
}

// ── CSRF ─────────────────────────────────────
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    $token = $_POST['_csrf']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';
    if (!hash_equals(getCsrfToken(), $token)) {
        http_response_code(403);
        if (!empty($_GET['action'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'CSRF token tidak valid.']);
        } else {
            die('Request tidak valid (CSRF).');
        }
        exit;
    }
}

// ── IP ADDRESS (safe) ─────────────────────────
function getClientIp(): string {
    // Ambil IP pertama dari X-Forwarded-For (jika di balik proxy)
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded) {
        $first = trim(explode(',', $forwarded)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
    }
    return $_SERVER['REMOTE_ADDR'] ?? '-';
}

// ── Cek & enforce session timeout ────────────
function checkSessionTimeout(): void {
    $now = time();
    if (isset($_SESSION['hl_last_activity'])) {
        if ($now - $_SESSION['hl_last_activity'] > SESSION_TIMEOUT) doLogout('timeout');
    }
    if (isset($_SESSION['hl_login_time'])) {
        if ($now - $_SESSION['hl_login_time'] > SESSION_LIFETIME) doLogout('timeout');
    }
    $_SESSION['hl_last_activity'] = $now;
}

// ── Require login ─────────────────────────────
function requireLogin(): void {
    sendSecurityHeaders();
    initAuthTable();
    if (empty($_SESSION['hl_user'])) {
        header('Location: login.php?msg=not_logged_in');
        exit;
    }
    checkSessionTimeout();
}

function isLoggedIn(): bool {
    return !empty($_SESSION['hl_user']);
}

function currentUser(): ?array {
    return $_SESSION['hl_user'] ?? null;
}

// ── Logout ──────────────────────────────────
function doLogout(string $reason = 'logout'): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    $msg = $reason === 'timeout' ? 'session_expired' : 'logout';
    header('Location: login.php?msg=' . $msg);
    exit;
}

// ══════════════════════════════════════════════════════
// RBAC — Role-Based Access Control
// ══════════════════════════════════════════════════════

function loadUserPermissions(): void {
    if (isset($_SESSION['hl_permissions'])) return;
    $user = currentUser();
    if (!$user) return;

    if ($user['role'] === 'superadmin') {
        $_SESSION['hl_permissions'] = ['*' => 'all'];
        return;
    }

    if (!empty($user['role_id'])) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT p.kode, rp.filter_data
                FROM hl_role_permissions rp
                JOIN hl_permissions p ON p.id = rp.permission_id
                WHERE rp.role_id = ?");
            $stmt->execute([$user['role_id']]);
            $perms = [];
            foreach ($stmt->fetchAll() as $row) {
                $perms[$row['kode']] = $row['filter_data'];
            }
            $_SESSION['hl_permissions'] = $perms;
            return;
        } catch (Exception $e) {}
    }

    $defaultPerms = [
        'admin' => ['pos.view','pos.create','orders.view_all','orders.create','orders.edit',
            'orders.update_status','orders.update_payment','kas.view','kas.create','kas.edit',
            'laporan.view_harian','laporan.view_bulanan','customer.view','customer.create',
            'customer.edit','karyawan.view','promo.view','promo.create','promo.edit',
            'layanan.view','layanan.create','layanan.edit','absensi.clock_inout',
            'absensi.view_own','absensi.view_all','absensi.approve_izin'],
        'staff' => ['pos.view','pos.create','orders.view_own','orders.create',
            'orders.update_status','orders.update_payment','customer.view','customer.create',
            'layanan.view','promo.view','absensi.clock_inout','absensi.view_own'],
    ];
    $perms = [];
    foreach ($defaultPerms[$user['role']] ?? [] as $kode) {
        $perms[$kode] = str_contains($kode, 'view_own') ? 'own' : 'all';
    }
    $_SESSION['hl_permissions'] = $perms;
}

function hasPermission(string $kode): string|false {
    loadUserPermissions();
    $perms = $_SESSION['hl_permissions'] ?? [];
    if (isset($perms['*'])) return 'all';
    return $perms[$kode] ?? false;
}

function requirePermission(string $kode): void {
    if (!hasPermission($kode)) {
        if (!empty($_GET['action'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Akses ditolak']);
        } else {
            http_response_code(403);
            die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>403</title>
            <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#F7F8FC;margin:0}
            .box{text-align:center;padding:40px;background:#fff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.08)}
            </style></head><body><div class="box"><div style="font-size:3rem">🔒</div>
            <h2 style="color:#1B2D5A">Akses Ditolak</h2>
            <p style="color:#6C7A8D">Anda tidak memiliki akses ke fitur ini.</p>
            <a href="pos.php" style="color:#35E8D5">← Kembali</a></div></body></html>');
        }
        exit;
    }
}

function getDataFilter(string $kode): string {
    $filter = hasPermission($kode);
    return $filter ?: 'own';
}

function clearPermissionCache(): void {
    unset($_SESSION['hl_permissions']);
}

function reloadUserSession(): void {
    $user = currentUser();
    if (!$user) return;
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT u.id, u.username, u.nama, u.role, u.role_id,
            COALESCE(r.nama, u.role) as role_nama
            FROM hl_users u
            LEFT JOIN hl_roles r ON r.id = u.role_id
            WHERE u.id=?");
        $stmt->execute([$user['id']]);
        $fresh = $stmt->fetch();
        if ($fresh) {
            $_SESSION['hl_user'] = $fresh;
            clearPermissionCache();
        }
    } catch (Exception $e) {}
}

// ── AUDIT LOG ─────────────────────────────────────────
function logAudit(string $aksi, string $modul, string $keterangan = '', $refId = null): void {
    try {
        $pdo  = getDB();
        $user = currentUser();

        $pdo->exec("CREATE TABLE IF NOT EXISTS hl_audit_log (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            user_id     INT DEFAULT NULL,
            user_nama   VARCHAR(100) DEFAULT NULL,
            user_role   VARCHAR(50) DEFAULT NULL,
            modul       VARCHAR(50) NOT NULL,
            aksi        VARCHAR(100) NOT NULL,
            keterangan  TEXT,
            ref_id      VARCHAR(100) DEFAULT NULL,
            ip_address  VARCHAR(45) DEFAULT NULL,
            user_agent  VARCHAR(255) DEFAULT NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_modul (modul),
            INDEX idx_user (user_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 255);

        $pdo->prepare("INSERT INTO hl_audit_log
            (user_id, user_nama, user_role, modul, aksi, keterangan, ref_id, ip_address, user_agent)
            VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([
                $user['id']        ?? null,
                $user['nama']      ?? null,
                $user['role_nama'] ?? $user['role'] ?? null,
                $modul, $aksi, $keterangan, $refId,
                getClientIp(),
                $ua,
            ]);
    } catch (Exception $e) {}
}
