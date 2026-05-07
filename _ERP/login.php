<?php
require_once 'auth.php';

// Kalau sudah login, langsung ke dashboard
if (isLoggedIn()) {
    header('Location: pos.php');
    exit;
}

$error = '';
$msg   = '';

// Handle pesan dari logout
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'logout')         $msg = 'Anda berhasil logout.';
    if ($_GET['msg'] === 'session_expired') $error = '⏰ Sesi Anda telah berakhir karena tidak aktif. Silakan login kembali.';
    if ($_GET['msg'] === 'not_logged_in')  $error = '🔒 Anda harus login terlebih dahulu.';
}

// ── PROSES LOGIN ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Username dan password wajib diisi.';
    } else {
        initAuthTable();
        $stmt = getDB()->prepare("SELECT * FROM hl_users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Update last login
            getDB()->prepare("UPDATE hl_users SET last_login = NOW() WHERE id = ?")
                ->execute([$user['id']]);

            // Set session — load role dari hl_roles jika ada
            $roleName = $user['role']; // fallback ke role lama
            $roleId   = $user['role_id'] ?? null;
            if ($roleId) {
                try {
                    $rStmt = getDB()->prepare("SELECT nama FROM hl_roles WHERE id=? AND is_active=1");
                    $rStmt->execute([$roleId]);
                    $rRow = $rStmt->fetch();
                    if ($rRow) $roleName = $rRow['nama'];
                } catch(Exception $e) {}
            }

            $_SESSION['hl_user'] = [
                'id'        => $user['id'],
                'username'  => $user['username'],
                'nama'      => $user['nama'],
                'role'      => $user['role'],      // role lama untuk backward compat
                'role_id'   => $roleId,
                'role_nama' => $roleName,          // nama role dari hl_roles
            ];
            $_SESSION['hl_login_time']    = time();
            $_SESSION['hl_last_activity'] = time();

            session_regenerate_id(true);
            header('Location: dashboard.php');
            exit;
        } else {
            // Delay untuk prevent brute force
            sleep(1);
            $error = 'Username atau password salah.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Login — Harpy Laundry Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --teal:   #35E8D5;
  --teal-d: #1CC4B2;
  --navy:   #1B2D5A;
  --navy-d: #0F1C3A;
  --white:  #FFFFFF;
  --off:    #F7F8FC;
  --light:  #EEF1F8;
  --gray:   #6C7A8D;
  --dark:   #1C1C2E;
  --red:    #EF4444;
  --green:  #10B981;
  --font:   'Plus Jakarta Sans', sans-serif;
  --mono:   'DM Mono', monospace;
  --r:      12px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body {
  height: 100%;
  font-family: var(--font);
}
body {
  background: var(--navy-d);
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  padding: 20px;
  position: relative;
  overflow: hidden;
}

/* BG pattern */
body::before {
  content: '';
  position: absolute; inset: 0;
  background-image:
    linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
  background-size: 48px 48px;
  pointer-events: none;
}

/* Orbs */
.orb {
  position: absolute; border-radius: 50%;
  filter: blur(80px); pointer-events: none;
}
.orb1 {
  width: 500px; height: 500px;
  background: radial-gradient(circle, rgba(53,232,213,.18) 0%, transparent 70%);
  top: -100px; right: -100px;
  animation: float 10s ease-in-out infinite;
}
.orb2 {
  width: 400px; height: 400px;
  background: radial-gradient(circle, rgba(27,77,143,.3) 0%, transparent 70%);
  bottom: -80px; left: -80px;
  animation: float 13s ease-in-out infinite reverse;
}
@keyframes float {
  0%,100% { transform: translate(0,0); }
  50%      { transform: translate(20px,-20px); }
}

/* CARD */
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

/* LOGO */
.login-logo {
  text-align: center; margin-bottom: 32px;
}
.logo-icon {
  width: 60px; height: 60px;
  background: linear-gradient(135deg, var(--teal), var(--teal-d));
  border-radius: 16px;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 26px;
  margin-bottom: 14px;
  box-shadow: 0 8px 24px rgba(53,232,213,.3);
}
.login-logo h1 {
  font-size: 20px; font-weight: 800; color: var(--white);
  letter-spacing: .02em;
}
.login-logo h1 span { color: var(--teal); }
.login-logo p {
  font-family: var(--mono);
  font-size: 11px; letter-spacing: .14em;
  color: rgba(255,255,255,.35);
  margin-top: 4px; text-transform: uppercase;
}

/* DIVIDER */
.divider {
  height: 1px;
  background: linear-gradient(to right, transparent, rgba(255,255,255,.12), transparent);
  margin-bottom: 28px;
}

/* MESSAGES */
.alert {
  padding: 12px 16px;
  border-radius: var(--r);
  font-size: 13.5px; font-weight: 500;
  margin-bottom: 20px;
  display: flex; align-items: center; gap: 8px;
}
.alert-error {
  background: rgba(239,68,68,.12);
  border: 1px solid rgba(239,68,68,.25);
  color: #FCA5A5;
}
.alert-success {
  background: rgba(16,185,129,.12);
  border: 1px solid rgba(16,185,129,.25);
  color: #6EE7B7;
}

/* FORM */
.form-group {
  display: flex; flex-direction: column; gap: 7px;
  margin-bottom: 18px;
}
label {
  font-size: 12px; font-weight: 600; letter-spacing: .06em;
  text-transform: uppercase;
  color: rgba(255,255,255,.5);
}
.input-wrap { position: relative; }
.input-icon {
  position: absolute; left: 14px; top: 50%;
  transform: translateY(-50%);
  font-size: 16px; pointer-events: none;
}
input[type="text"],
input[type="password"] {
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

/* TOGGLE PASSWORD */
.toggle-pw {
  position: absolute; right: 14px; top: 50%;
  transform: translateY(-50%);
  background: none; border: none;
  color: rgba(255,255,255,.35); cursor: pointer;
  font-size: 16px; padding: 4px;
  transition: color .2s;
}
.toggle-pw:hover { color: rgba(255,255,255,.7); }

/* SUBMIT */
.btn-login {
  width: 100%;
  padding: 13px;
  background: linear-gradient(135deg, var(--teal), var(--teal-d));
  color: var(--navy-d);
  font-family: var(--font);
  font-size: 15px; font-weight: 700;
  border: none; border-radius: var(--r);
  cursor: pointer;
  transition: all .2s;
  margin-top: 8px;
  position: relative; overflow: hidden;
}
.btn-login:hover {
  transform: translateY(-1px);
  box-shadow: 0 8px 24px rgba(53,232,213,.35);
}
.btn-login:active { transform: translateY(0); }
.btn-login.loading { opacity: .7; pointer-events: none; }

/* FOOTER */
.login-footer {
  text-align: center; margin-top: 24px;
  font-size: 12px; color: rgba(255,255,255,.25);
}
.login-footer a {
  color: rgba(255,255,255,.4);
  text-decoration: none;
}
.login-footer a:hover { color: var(--teal); }
</style>
</head>
<body>
<div class="orb orb1"></div>
<div class="orb orb2"></div>

<div class="login-card">
  <div class="login-logo">
    <div class="logo-icon">🫧</div>
    <h1>Harpy <span>Laundry</span></h1>
    <p>Admin Panel · Laundry Masuk</p>
  </div>

  <div class="divider"></div>

  <?php if ($error): ?>
  <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($msg): ?>
  <div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <form method="POST" id="loginForm">
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
      Masuk ke Admin Panel
    </button>
  </form>

  <div class="login-footer">
    <a href="https://harpy.id" target="_blank">← Kembali ke harpy.id</a>
    &nbsp;·&nbsp;
    PT Harpy Sinergi Mandiri
  </div>
</div>

<script>
function togglePw() {
  const input = document.getElementById('pwInput');
  const btn   = document.getElementById('toggleBtn');
  if (input.type === 'password') {
    input.type = 'text';
    btn.textContent = '🙈';
  } else {
    input.type = 'password';
    btn.textContent = '👁️';
  }
}

document.getElementById('loginForm').addEventListener('submit', function() {
  const btn = document.getElementById('loginBtn');
  btn.textContent = '⏳ Masuk...';
  btn.classList.add('loading');
});
</script>
</body>
</html>
