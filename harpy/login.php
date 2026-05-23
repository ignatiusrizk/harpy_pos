<?php
// ══════════════════════════════════════════════════════
// login.php — Harpy SaaS Login
// ══════════════════════════════════════════════════════

if (!defined('ROOT')) define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/TenantResolver.php';
require_once ROOT . '/core/TenantQuery.php';
require_once ROOT . '/core/CoinLedger.php';

date_default_timezone_set('Asia/Jakarta');

ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.cookie_secure',   1);

if (session_status() === PHP_SESSION_NONE) session_start();

// Security headers
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
}

// Sudah login → langsung ke dashboard
if (!empty($_SESSION['user_id']) && !empty($_SESSION['tenant_id'])) {
    header('Location: dashboard.php');
    exit;
}

// ── CSRF ──────────────────────────────────────────────
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// ── IP Helper ─────────────────────────────────────────
function getClientIp(): string {
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded) {
        $first = trim(explode(',', $forwarded)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
    }
    return $_SERVER['REMOTE_ADDR'] ?? '-';
}

// ── Brute-force protection ────────────────────────────
function isLoginLocked(string $identifier, string $ip): bool {
    try {
        $since = date('Y-m-d H:i:s', time() - 15 * 60);
        $stmt  = Database::get()->prepare(
            "SELECT COUNT(*) FROM hl_login_attempts
             WHERE (identifier = ? OR ip_address = ?) AND attempted_at >= ?"
        );
        $stmt->execute([$identifier, $ip, $since]);
        return (int) $stmt->fetchColumn() >= 5;
    } catch (Exception $e) { return false; }
}

function recordLoginAttempt(string $identifier, string $ip): void {
    try {
        Database::get()->prepare(
            "INSERT INTO hl_login_attempts (identifier, ip_address) VALUES (?,?)"
        )->execute([$identifier, $ip]);
    } catch (Exception $e) {}
}

function clearLoginAttempts(string $identifier, string $ip): void {
    try {
        Database::get()->prepare(
            "DELETE FROM hl_login_attempts WHERE identifier = ? OR ip_address = ?"
        )->execute([$identifier, $ip]);
    } catch (Exception $e) {}
}

// ── Load permissions ke session ───────────────────────
function loadPermissions(array $user, int $tenantId): void {
    if ($user['role'] === 'superadmin') {
        $_SESSION['hl_permissions'] = ['*' => 'all'];
        return;
    }
    if (!empty($user['role_id'])) {
        try {
            $stmt = Database::get()->prepare(
                "SELECT p.kode, rp.filter_data
                 FROM hl_role_permissions rp
                 JOIN hl_permissions p ON p.id = rp.permission_id
                 WHERE rp.role_id = ? AND rp.tenant_id = ?"
            );
            $stmt->execute([$user['role_id'], $tenantId]);
            $perms = [];
            foreach ($stmt->fetchAll() as $row) {
                $perms[$row['kode']] = $row['filter_data'];
            }
            $_SESSION['hl_permissions'] = $perms;
            return;
        } catch (Exception $e) {}
    }
    $_SESSION['hl_permissions'] = [];
}

$error = '';
$msg   = '';

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'logout')          $msg   = 'Anda berhasil logout.';
    if ($_GET['msg'] === 'session_expired') $error = '⏰ Sesi telah berakhir. Silakan login kembali.';
    if ($_GET['msg'] === 'not_logged_in')   $error = '🔒 Anda harus login terlebih dahulu.';
}

// ── PROSES LOGIN ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $clientIp = getClientIp();

    $csrfPost = $_POST['_csrf'] ?? '';
    if (!hash_equals(getCsrfToken(), $csrfPost)) {
        $error = 'Request tidak valid. Silakan coba lagi.';
    } elseif (empty($username) || empty($password)) {
        $error = 'Username dan password wajib diisi.';
    } elseif (isLoginLocked($username, $clientIp)) {
        $error = '🔒 Terlalu banyak percobaan login. Coba lagi 15 menit lagi.';
    } else {
        // Cari user by email ATAU username (email = self-registered owner, username = staff)
        $isEmail = filter_var($username, FILTER_VALIDATE_EMAIL);
        $stmt = Database::get()->prepare(
            "SELECT u.*, t.slug AS tenant_slug, t.nama_outlet,
                    t.status AS tenant_status, t.coin_balance,
                    t.verified_at,
                    COALESCE(r.nama, u.role) AS role_nama
             FROM hl_users u
             JOIN tenants t ON t.id = u.tenant_id
             LEFT JOIN hl_roles r ON r.id = u.role_id AND r.tenant_id = u.tenant_id
             WHERE (" . ($isEmail ? "u.email = ?" : "u.username = ?") . ")
               AND u.is_active = 1
             LIMIT 1"
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Cek status tenant
            $tenantStatus = $user['tenant_status'];
            if (in_array($tenantStatus, ['suspended', 'closed'])) {
                $error = '🔒 Akun ditangguhkan. Hubungi tim LAMASY untuk informasi lebih lanjut.';
            } elseif ($tenantStatus === 'pending_verification') {
                // Redirect ke pending-verify (set session minimal dulu)
                session_regenerate_id(true);
                $_SESSION['tenant_id']      = $user['tenant_id'];
                $_SESSION['pending_verify'] = true;
                header('Location: pending-verify.php');
                exit;
            } else {
                clearLoginAttempts($username, $clientIp);

                // Update last_login
                Database::get()->prepare(
                    "UPDATE hl_users SET last_login = NOW() WHERE id = ?"
                )->execute([$user['id']]);

                // Set session
                $_SESSION['user_id']              = $user['id'];
                $_SESSION['tenant_id']            = $user['tenant_id'];
                $_SESSION['tenant_slug']          = $user['tenant_slug'];
                $_SESSION['tenant_coin_balance']  = $user['coin_balance'];
                $_SESSION['hl_login_time']        = time();
                $_SESSION['hl_last_activity']     = time();
                $_SESSION['hl_user']              = [
                    'id'         => $user['id'],
                    'username'   => $user['username'],
                    'nama'       => $user['name'] ?? $user['nama'] ?? '',
                    'role'       => $user['role'],
                    'role_id'    => $user['role_id'],
                    'role_nama'  => $user['role_nama'],
                    'nama_outlet'=> $user['nama_outlet'],
                ];

                // Load permissions
                loadPermissions($user, $user['tenant_id']);

                session_regenerate_id(true);

                // ── Login flow per role (brief Akses Karyawan Section 6.2) ──
                $userRole = $user['role'] ?? 'staff';
                $isOwnerOrAdmin = in_array($userRole, ['owner','superadmin','admin','manager'], true);

                $db = Database::get();

                // ── MITRA (drop point) — portal terpisah ──
                if ($userRole === 'mitra') {
                    $dpId = (int)($user['drop_point_id'] ?? 0);
                    if (!$dpId) {
                        // Akun mitra tanpa drop_point_id (data corrupt) → tolak
                        session_destroy();
                        $loginError = 'Akun mitra belum terhubung ke drop point. Hubungi admin outlet.';
                    } else {
                        $_SESSION['outlet_id']     = (int)($user['outlet_id'] ?? 0);
                        $_SESSION['drop_point_id'] = $dpId;
                        $_SESSION['hq_mode']       = false;
                        try { logAuditLogin($user, 'login', 'auth', 'Login mitra drop point #'.$dpId); } catch (Throwable) {}
                        header('Location: droppoint/dashboard.php');
                        exit;
                    }
                }

                // Hitung outlet aktif tenant (total — utk owner/admin)
                $outletCount = $db->prepare(
                    "SELECT COUNT(*) FROM outlets WHERE tenant_id=? AND status IN ('trial','grace','active')"
                );
                $outletCount->execute([$user['tenant_id']]);
                $oCount = (int)$outletCount->fetchColumn();

                if ($isOwnerOrAdmin) {
                    // OWNER / MANAGER: default landing ke HQ view (sesuai brief
                    // 'Owner yang punya 1 outlet pun tetap dapat benefit HQ view')
                    if ($oCount === 0) {
                        // Belum punya outlet → dashboard.php yang otomatis render
                        // no-outlet onboarding state (hero CTA daftar outlet)
                        $_SESSION['outlet_id'] = 0;
                        $_SESSION['hq_mode']   = false;
                        $redirectTo = 'dashboard.php';
                    } else {
                        // Sudah punya outlet → masuk HQ. Set juga outlet_id default
                        // (main outlet) supaya kalau switch ke outlet view tidak perlu pilih.
                        $outletRow = $db->prepare(
                            "SELECT id FROM outlets
                              WHERE tenant_id=? AND status IN ('trial','grace','active')
                              ORDER BY is_main DESC, nama_outlet ASC LIMIT 1"
                        );
                        $outletRow->execute([$user['tenant_id']]);
                        $_SESSION['outlet_id'] = (int)$outletRow->fetchColumn();
                        $_SESSION['hq_mode']   = true;
                        $redirectTo = 'dashboard.php'; // dashboard.php route ke HQ via hq_mode
                    }
                } else {
                    // KASIR / STAFF / KURIR: scope ke hl_karyawan_outlet
                    try {
                        $aStmt = $db->prepare(
                            "SELECT o.id FROM hl_karyawan_outlet ko
                               JOIN outlets o ON o.id=ko.outlet_id AND o.tenant_id=ko.tenant_id
                              WHERE ko.tenant_id=? AND ko.karyawan_id=? AND ko.is_active=1
                                AND o.status IN ('trial','grace','active')"
                        );
                        $aStmt->execute([$user['tenant_id'], $user['id']]);
                        $assignedIds = $aStmt->fetchAll(PDO::FETCH_COLUMN);
                    } catch (Throwable $e) {
                        // Tabel belum ada — fallback ke hl_users.outlet_id
                        $assignedIds = $user['outlet_id'] > 0 ? [(int)$user['outlet_id']] : [];
                    }

                    if (count($assignedIds) === 0) {
                        // Belum ditugaskan → halaman info
                        $redirectTo = 'no-assignment.php';
                    } elseif (count($assignedIds) === 1) {
                        $_SESSION['outlet_id'] = (int)$assignedIds[0];
                        $redirectTo = 'dashboard.php';
                    } else {
                        // Multi-assignment → select-outlet (scoped sesuai assignment)
                        $redirectTo = 'select-outlet.php';
                    }
                    $_SESSION['hq_mode'] = false; // non-owner selalu outlet view
                }

                // Audit log
                try {
                    if (isset($_SESSION['outlet_id']) && $_SESSION['outlet_id'] > 0) {
                        TenantResolver::resolve();
                    }
                    logAuditLogin($user, 'login', 'auth', 'Login berhasil');
                } catch (Throwable $e) {}

                header('Location: ' . $redirectTo);
                exit;
            }
        } else {
            recordLoginAttempt($username, $clientIp);
            sleep(1);
            $error = 'Username atau password salah.';
        }
    }
}

function logAuditLogin(array $user, string $aksi, string $modul, string $ket): void {
    try {
        Database::get()->prepare(
            "INSERT INTO hl_audit_log
               (tenant_id, user_id, user_nama, user_role, modul, aksi, keterangan, ip_address, user_agent)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([
            $user['tenant_id'],
            $user['id'],
            $user['nama'],
            $user['role_nama'] ?? $user['role'],
            $modul, $aksi, $ket,
            getClientIp(),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 255),
        ]);
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Login — LAMASY</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --teal:   #35E8D5;
  --teal-d: #1CC4B2;
  --navy:   #1B2D5A;
  --navy-d: #0F1C3A;
  --white:  #FFFFFF;
  --gray:   #6C7A8D;
  --red:    #EF4444;
  --green:  #10B981;
  --font:   'Plus Jakarta Sans', sans-serif;
  --mono:   'DM Mono', monospace;
  --r:      12px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; font-family: var(--font); }
body {
  background: var(--navy-d);
  display: flex; align-items: center; justify-content: center;
  min-height: 100vh; padding: 20px;
  position: relative; overflow-x: hidden;
}
body::before {
  content: '';
  position: absolute; inset: 0;
  background-image:
    linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
  background-size: 48px 48px;
  pointer-events: none;
}
.orb { position: absolute; border-radius: 50%; filter: blur(80px); pointer-events: none; }
.orb1 {
  width: 400px; height: 400px;
  background: radial-gradient(circle, rgba(53,232,213,.18) 0%, transparent 70%);
  top: -80px; right: -80px;
  animation: float 10s ease-in-out infinite;
}
.orb2 {
  width: 320px; height: 320px;
  background: radial-gradient(circle, rgba(27,77,143,.3) 0%, transparent 70%);
  bottom: -60px; left: -60px;
  animation: float 13s ease-in-out infinite reverse;
}
@keyframes float {
  0%,100% { transform: translate(0,0); }
  50%      { transform: translate(20px,-20px); }
}
.login-card {
  position: relative; z-index: 1;
  background: rgba(255,255,255,.04);
  backdrop-filter: blur(20px);
  border: 1px solid rgba(255,255,255,.1);
  border-radius: 20px;
  padding: 44px 40px;
  width: 100%; max-width: 400px;
  box-shadow: 0 32px 80px rgba(0,0,0,.4);
  animation: slideUp .5s cubic-bezier(.4,0,.2,1);
}
@keyframes slideUp {
  from { opacity:0; transform: translateY(24px); }
  to   { opacity:1; transform: translateY(0); }
}
.login-logo { text-align: center; margin-bottom: 32px; }
.logo-icon {
  width: 60px; height: 60px;
  background: linear-gradient(135deg, var(--teal), var(--teal-d));
  border-radius: 16px;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 26px; margin-bottom: 14px;
  box-shadow: 0 8px 24px rgba(53,232,213,.3);
}
.login-logo h1 { font-size: 20px; font-weight: 800; color: var(--white); letter-spacing: .02em; }
.login-logo h1 span { color: var(--teal); }
.login-logo p {
  font-family: var(--mono); font-size: 11px; letter-spacing: .14em;
  color: rgba(255,255,255,.35); margin-top: 4px; text-transform: uppercase;
}
.divider {
  height: 1px;
  background: linear-gradient(to right, transparent, rgba(255,255,255,.12), transparent);
  margin-bottom: 28px;
}
.alert {
  padding: 12px 16px; border-radius: var(--r);
  font-size: 13.5px; font-weight: 500;
  margin-bottom: 20px;
  display: flex; align-items: flex-start; gap: 8px;
}
.alert-error  { background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.25); color: #FCA5A5; }
.alert-success{ background: rgba(16,185,129,.12); border: 1px solid rgba(16,185,129,.25); color: #6EE7B7; }
.form-group { display: flex; flex-direction: column; gap: 7px; margin-bottom: 18px; }
label { font-size: 12px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: rgba(255,255,255,.5); }
.input-wrap { position: relative; }
.input-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); font-size: 16px; pointer-events: none; }
input[type="text"], input[type="password"] {
  width: 100%;
  padding: 12px 14px 12px 42px;
  background: rgba(255,255,255,.06);
  border: 1.5px solid rgba(255,255,255,.1);
  border-radius: var(--r);
  font-family: var(--font); font-size: 15px; color: var(--white);
  outline: none; transition: all .2s;
}
input[type="text"]::placeholder,
input[type="password"]::placeholder { color: rgba(255,255,255,.25); }
input[type="text"]:focus,
input[type="password"]:focus {
  border-color: var(--teal);
  background: rgba(53,232,213,.06);
  box-shadow: 0 0 0 3px rgba(53,232,213,.1);
}
.toggle-pw {
  position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
  background: none; border: none; color: rgba(255,255,255,.35);
  cursor: pointer; font-size: 16px; padding: 4px; transition: color .2s;
}
.toggle-pw:hover { color: rgba(255,255,255,.7); }
.btn-login {
  width: 100%; padding: 13px;
  background: linear-gradient(135deg, var(--teal), var(--teal-d));
  color: var(--navy-d);
  font-family: var(--font); font-size: 15px; font-weight: 700;
  border: none; border-radius: var(--r);
  cursor: pointer; transition: all .2s;
  margin-top: 8px;
}
.btn-login:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(53,232,213,.35); }
.btn-login:active { transform: translateY(0); }
.btn-login.loading { opacity: .7; pointer-events: none; }
.login-footer { text-align: center; margin-top: 24px; font-size: 12px; color: rgba(255,255,255,.25); }
.login-footer a { color: rgba(255,255,255,.4); text-decoration: none; }
.login-footer a:hover { color: var(--teal); }

@media (max-width: 480px) {
  .login-card { padding: 32px 24px; border-radius: 16px; }
}
</style>
</head>
<body>
<div class="orb orb1"></div>
<div class="orb orb2"></div>

<div class="login-card">
  <div class="login-logo">
    <div style="margin-bottom:14px;"><img src="/ERP/harpy/assets/logo.png" alt="LAMASY" style="height:60px;"></div>
    <h1>LAMASY</h1>
    <p>Laundry Management System</p>
  </div>

  <div class="divider"></div>

  <?php if ($error): ?>
  <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($msg): ?>
  <div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <form method="POST" id="loginForm">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(getCsrfToken()) ?>"/>

    <div class="form-group">
      <label>Username</label>
      <div class="input-wrap">
        <span class="input-icon">👤</span>
        <input type="text" name="username" placeholder="Masukkan username"
          value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
          autocomplete="username" required autofocus/>
      </div>
    </div>

    <div class="form-group">
      <label>Password</label>
      <div class="input-wrap">
        <span class="input-icon">🔒</span>
        <input type="password" name="password" id="pwInput"
          placeholder="Masukkan password"
          autocomplete="current-password" required/>
        <button type="button" class="toggle-pw" onclick="togglePw()" id="toggleBtn">👁️</button>
      </div>
    </div>

    <button type="submit" class="btn-login" id="loginBtn">
      Masuk ke Dashboard
    </button>
  </form>

  <div class="login-footer">
    &copy; <?= date('Y') ?> PT Harpy Sinergi Mandiri
  </div>
</div>

<script>
function togglePw() {
  const input = document.getElementById('pwInput');
  const btn   = document.getElementById('toggleBtn');
  input.type  = input.type === 'password' ? 'text' : 'password';
  btn.textContent = input.type === 'password' ? '👁️' : '🙈';
}
document.getElementById('loginForm').addEventListener('submit', function() {
  const btn = document.getElementById('loginBtn');
  btn.textContent = '⏳ Masuk...';
  btn.classList.add('loading');
});
</script>
</body>
</html>
