<?php
// ══════════════════════════════════════════════════════
// superadmin/login.php — Super Admin Login
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/../master/config/db.php';
require_once SA_ROOT . '/../core/Database.php';

date_default_timezone_set('Asia/Jakarta');
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');

if (session_status() === PHP_SESSION_NONE) session_start();

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
}

// Sudah login → redirect
if (!empty($_SESSION['superadmin_id'])) {
    header('Location: dashboard.php');
    exit;
}

function saLoginCsrf(): string {
    if (empty($_SESSION['sa_csrf'])) {
        $_SESSION['sa_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['sa_csrf'];
}

$error = '';
$msg   = '';

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'logout') $msg = 'Anda berhasil logout.';
}

// ── PROSES LOGIN ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrfPost = $_POST['_csrf'] ?? '';

    if (!hash_equals(saLoginCsrf(), $csrfPost)) {
        $error = 'Request tidak valid. Silakan coba lagi.';
    } elseif (empty($username) || empty($password)) {
        $error = 'Username dan password wajib diisi.';
    } else {
        try {
            $stmt = Database::get()->prepare(
                "SELECT * FROM super_admins WHERE username = ? AND is_active = 1 LIMIT 1"
            );
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password'])) {
                // Update last_login
                Database::get()->prepare(
                    "UPDATE super_admins SET last_login = NOW() WHERE id = ?"
                )->execute([$admin['id']]);

                // Set session
                $_SESSION['superadmin_id'] = $admin['id'];
                $_SESSION['sa_user'] = [
                    'id'       => $admin['id'],
                    'username' => $admin['username'],
                    'name'     => $admin['name'],
                ];

                // Log login
                try {
                    Database::get()->prepare(
                        "INSERT INTO superadmin_logs (superadmin_id, action, description, ip_address)
                         VALUES (?, 'login', 'Super admin login berhasil', ?)"
                    )->execute([$admin['id'], $_SERVER['REMOTE_ADDR'] ?? '-']);
                } catch (Throwable) {}

                session_regenerate_id(true);
                header('Location: dashboard.php');
                exit;
            } else {
                sleep(1);
                $error = 'Username atau password salah.';
            }
        } catch (Throwable $e) {
            $error = 'Terjadi kesalahan sistem. Coba lagi.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Super Admin — LAMASY</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --sa:    #6366F1;
  --sa-d:  #4F46E5;
  --navy:  #1B2D5A;
  --navy-d:#0F1C3A;
  --white: #FFFFFF;
  --gray:  #6C7A8D;
  --red:   #EF4444;
  --green: #10B981;
  --font:  'Plus Jakarta Sans', sans-serif;
  --mono:  'DM Mono', monospace;
  --r:     12px;
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
  width: 420px; height: 420px;
  background: radial-gradient(circle, rgba(99,102,241,.2) 0%, transparent 70%);
  top: -100px; right: -100px;
  animation: float 10s ease-in-out infinite;
}
.orb2 {
  width: 340px; height: 340px;
  background: radial-gradient(circle, rgba(79,70,229,.18) 0%, transparent 70%);
  bottom: -80px; left: -80px;
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
  background: linear-gradient(135deg, var(--sa), var(--sa-d));
  border-radius: 16px;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 26px; margin-bottom: 14px;
  box-shadow: 0 8px 24px rgba(99,102,241,.35);
}
.login-logo h1 { font-size: 20px; font-weight: 800; color: var(--white); letter-spacing: .02em; }
.login-logo h1 span { color: var(--sa); }
.login-logo p {
  font-family: var(--mono); font-size: 11px; letter-spacing: .14em;
  color: rgba(255,255,255,.35); margin-top: 4px; text-transform: uppercase;
}
.sa-warning {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px; border-radius: 9px;
  background: rgba(99,102,241,.1); border: 1px solid rgba(99,102,241,.25);
  font-size: 12px; color: rgba(255,255,255,.5); margin-bottom: 20px;
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
.alert-error   { background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.25); color: #FCA5A5; }
.alert-success { background: rgba(16,185,129,.12); border: 1px solid rgba(16,185,129,.25); color: #6EE7B7; }
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
input:focus {
  border-color: var(--sa);
  background: rgba(99,102,241,.06);
  box-shadow: 0 0 0 3px rgba(99,102,241,.12);
}
input::placeholder { color: rgba(255,255,255,.25); }
.toggle-pw {
  position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
  background: none; border: none; color: rgba(255,255,255,.35);
  cursor: pointer; font-size: 16px; padding: 4px; transition: color .2s;
}
.toggle-pw:hover { color: rgba(255,255,255,.7); }
.btn-login {
  width: 100%; padding: 13px;
  background: linear-gradient(135deg, var(--sa), var(--sa-d));
  color: var(--white);
  font-family: var(--font); font-size: 15px; font-weight: 700;
  border: none; border-radius: var(--r);
  cursor: pointer; transition: all .2s;
  margin-top: 8px;
}
.btn-login:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(99,102,241,.4); }
.btn-login.loading { opacity: .7; pointer-events: none; }
.login-footer { text-align: center; margin-top: 24px; font-size: 12px; color: rgba(255,255,255,.25); }
.back-link { display: block; text-align: center; margin-top: 16px; font-size: 12.5px; color: rgba(255,255,255,.35); text-decoration: none; }
.back-link:hover { color: rgba(255,255,255,.7); }
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
    <div style="margin-bottom:14px;"><img src="/ERP/harpy/assets/logo.png" alt="LAMASY" style="height:48px;"></div>
    <h1>LAMASY <span>Admin</span></h1>
    <p>Super Admin Panel</p>
  </div>

  <div class="divider"></div>

  <div class="sa-warning">
    🔒 Akses terbatas — hanya untuk tim internal Harpy
  </div>

  <?php if ($error): ?>
  <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($msg): ?>
  <div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <form method="POST" id="loginForm">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(saLoginCsrf()) ?>"/>

    <div class="form-group">
      <label>Username</label>
      <div class="input-wrap">
        <span class="input-icon">👤</span>
        <input type="text" name="username" placeholder="Super admin username"
          value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
          autocomplete="username" required autofocus/>
      </div>
    </div>

    <div class="form-group">
      <label>Password</label>
      <div class="input-wrap">
        <span class="input-icon">🔒</span>
        <input type="password" name="password" id="pwInput"
          placeholder="Password"
          autocomplete="current-password" required/>
        <button type="button" class="toggle-pw" onclick="togglePw()" id="toggleBtn">👁️</button>
      </div>
    </div>

    <button type="submit" class="btn-login" id="loginBtn">
      Masuk ke Super Admin
    </button>
  </form>

  <a href="/ERP/harpy/login.php" class="back-link">← Kembali ke Tenant Login</a>

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
