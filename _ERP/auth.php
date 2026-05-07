<?php
// ══════════════════════════════════════════════
// auth.php — Harpy Laundry Auth System
// Include file ini di semua halaman yang dilindungi
// ══════════════════════════════════════════════

define('DB_HOST', 'localhost');
define('DB_NAME', 'u269895997_Laundry_Masuk');
define('DB_USER', 'u269895997_HL_Admin');
define('DB_PASS', '1Kq7um&p*b@');

// Timezone Indonesia WIB
date_default_timezone_set('Asia/Jakarta');

// ── SESSION CONFIG ────────────────────────────
define('SESSION_TIMEOUT',  1 * 60 * 60);  // 8 jam — auto logout jika tidak aktif
define('SESSION_LIFETIME', 10 * 60 * 60); // 12 jam — maksimal sejak login

ini_set('session.cookie_httponly', 1);     // Cegah akses cookie via JS
ini_set('session.use_strict_mode', 1);     // Tolak session ID yang tidak valid
ini_set('session.cookie_samesite', 'Strict');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── DATABASE ──────────────────────────────────
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            $pdo->exec("SET time_zone = '+07:00'");
        } catch (PDOException $e) {
            die('<div style="font-family:sans-serif;padding:40px;color:red">Database error: ' . $e->getMessage() . '</div>');
        }
    }
    return $pdo;
}

// ── Auto create users table & seed default admin ──
function initAuthTable() {
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

    // Seed default admin jika belum ada user
    $count = $pdo->query("SELECT COUNT(*) FROM hl_users")->fetchColumn();
    if ($count == 0) {
        $hash = password_hash('HarpyAdmin2025!', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO hl_users (username, password, nama, role) VALUES (?,?,?,?)")
            ->execute(['admin', $hash, 'Administrator', 'superadmin']);
    }
}

// ── Cek & enforce session timeout ────────────
function checkSessionTimeout() {
    $now = time();

    // Cek idle timeout (tidak ada aktivitas)
    if (isset($_SESSION['hl_last_activity'])) {
        if ($now - $_SESSION['hl_last_activity'] > SESSION_TIMEOUT) {
            doLogout('timeout');
        }
    }

    // Cek lifetime (maksimal sejak login)
    if (isset($_SESSION['hl_login_time'])) {
        if ($now - $_SESSION['hl_login_time'] > SESSION_LIFETIME) {
            doLogout('timeout');
        }
    }

    // Update last activity
    $_SESSION['hl_last_activity'] = $now;
}

// ── Require login — redirect jika belum login ──
function requireLogin() {
    initAuthTable();
    if (empty($_SESSION['hl_user'])) {
        header('Location: login.php?msg=not_logged_in');
        exit;
    }
    checkSessionTimeout();
}

// ── Cek login (tanpa redirect) ──
function isLoggedIn() {
    return !empty($_SESSION['hl_user']);
}

// ── Get current user ──
function currentUser() {
    return $_SESSION['hl_user'] ?? null;
}

// ── Logout ──
function doLogout($reason = 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600,
            $p['path'], $p['domain'],
            $p['secure'], $p['httponly']
        );
    }
    session_destroy();
    $msg = $reason === 'timeout' ? 'session_expired' : 'logout';
    header('Location: login.php?msg=' . $msg);
    exit;
}
// ══════════════════════════════════════════════════════
// RBAC — Role-Based Access Control
// ══════════════════════════════════════════════════════

// ── Cache permissions di session ─────────────────────
function loadUserPermissions(): void {
    if (isset($_SESSION['hl_permissions'])) return;
    $user = currentUser();
    if (!$user) return;

    // Superadmin lama → selalu full access
    if ($user['role'] === 'superadmin') {
        $_SESSION['hl_permissions'] = ['*' => 'all'];
        return;
    }

    // Cek dari role_id (RBAC baru)
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

    // Fallback: map role lama ke permission set default
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

// ── Cek permission ────────────────────────────────────
// $kode  : 'orders.view_all' | 'kas.create' | dst
// return : false | 'all' | 'own' | 'today'
function hasPermission(string $kode): string|false {
    loadUserPermissions();
    $perms = $_SESSION['hl_permissions'] ?? [];

    // Wildcard — superadmin / owner
    if (isset($perms['*'])) return 'all';

    return $perms[$kode] ?? false;
}

// ── Require permission — die jika tidak punya akses ──
function requirePermission(string $kode): void {
    if (!hasPermission($kode)) {
        if (!empty($_GET['action'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Akses ditolak — tidak punya permission: ' . $kode]);
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

// ── Get filter data untuk query ───────────────────────
// Return: 'all' | 'own' | 'today'
function getDataFilter(string $kode): string {
    $filter = hasPermission($kode);
    return $filter ?: 'own';
}

// ── Clear permission cache (saat role berubah) ────────
function clearPermissionCache(): void {
    unset($_SESSION['hl_permissions']);
}

// ── Reload user session dari DB ───────────────────────
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

        // Auto-create tabel jika belum ada
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

        $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '-';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 255);

        $pdo->prepare("INSERT INTO hl_audit_log
            (user_id, user_nama, user_role, modul, aksi, keterangan, ref_id, ip_address, user_agent)
            VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([
                $user['id']        ?? null,
                $user['nama']      ?? null,
                $user['role_nama'] ?? $user['role'] ?? null,
                $modul,
                $aksi,
                $keterangan,
                $refId,
                $ip,
                $ua,
            ]);
    } catch (Exception $e) {
        // Jangan sampai audit log error ganggu flow utama
    }
}
