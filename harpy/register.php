<?php
// ══════════════════════════════════════════════════════
// harpy/register.php — Self-Registration Wizard (Rebuilt)
// Fase 2: Buat tenant + outlet + hl_users dalam 1 transaksi
// Email sebagai primary identifier, verifikasi token 24h
// ══════════════════════════════════════════════════════

define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/EmailVerification.php';
require_once ROOT . '/core/Mailer.php';
require_once ROOT . '/core/RateLimiter.php';
require_once ROOT . '/core/TenantProvisioner.php';

date_default_timezone_set('Asia/Jakarta');
if (session_status() === PHP_SESSION_NONE) session_start();

// Kalau sudah login → redirect ke dashboard
if (!empty($_SESSION['tenant_id']) && empty($_SESSION['pending_verify'])) {
    header('Location: /ERP/harpy/dashboard.php');
    exit;
}

// ── CSRF ──────────────────────────────────────────────
function regCsrf(): string {
    if (empty($_SESSION['reg_csrf'])) $_SESSION['reg_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['reg_csrf'];
}
function regVerifyCsrf(): void {
    if (!hash_equals(regCsrf(), $_POST['_csrf'] ?? '')) {
        http_response_code(403); die('CSRF mismatch.');
    }
}
function regResetCsrf(): void { $_SESSION['reg_csrf'] = bin2hex(random_bytes(32)); }

// ── Wizard session ────────────────────────────────────
if (isset($_GET['reset'])) {
    unset($_SESSION['reg']);
}
if (empty($_SESSION['reg'])) {
    $ca = random_int(10, 49);
    $cb = random_int(2, 19);
    $_SESSION['reg'] = [
        'step'        => 1,
        'data'        => [],
        'captcha_a'   => $ca,
        'captcha_b'   => $cb,
        'captcha_ans' => $ca + $cb,
    ];
}

$r    = &$_SESSION['reg'];
$step = (int)($r['step'] ?? 1);
$d    = &$r['data'];
$error = '';

// ── Helpers ───────────────────────────────────────────
function slugify(string $str): string {
    $str = strtolower(trim($str));
    $str = preg_replace('/[^a-z0-9\s-]/', '', $str);
    $str = preg_replace('/[\s-]+/', '_', $str);
    return preg_replace('/^_+|_+$/', '', $str) ?: 'outlet';
}
function normalizeWa(string $wa): string {
    $wa = preg_replace('/\D/', '', $wa);
    if (substr($wa, 0, 2) === '08') $wa = '628' . substr($wa, 2);
    if (substr($wa, 0, 1) === '8')  $wa = '62' . $wa;
    return $wa;
}
function uniqueSlug(string $base): string {
    $slug = slugify($base);
    $db = Database::get();
    $candidate = $slug;
    $i = 1;
    while (true) {
        $s = $db->prepare("SELECT id FROM tenants WHERE slug=? LIMIT 1");
        $s->execute([$candidate]);
        if (!$s->fetch()) return $candidate;
        $candidate = $slug . '_' . $i++;
    }
}

// ── POST handlers ─────────────────────────────────────

// ───────────── STEP 1 ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step1_submit'])) {
    regVerifyCsrf();
    $namaOutlet     = trim($_POST['nama_outlet']     ?? '');
    $namaPerusahaan = trim($_POST['nama_perusahaan'] ?? '');
    $kota           = trim($_POST['kota']            ?? '');

    if (strlen($namaOutlet) < 3) {
        $error = 'Nama outlet minimal 3 karakter.';
    } elseif (strlen($namaOutlet) > 80) {
        $error = 'Nama outlet terlalu panjang (maks 80 karakter).';
    } else {
        $d['nama_outlet']     = $namaOutlet;
        $d['nama_perusahaan'] = $namaPerusahaan;
        $d['kota']            = $kota;
        $r['step'] = 2; $step = 2;
        regResetCsrf();
    }
}

// ───────────── STEP 2 ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step2_submit'])) {
    regVerifyCsrf();
    $ownerName = trim($_POST['owner_name'] ?? '');
    $ownerWa   = normalizeWa($_POST['owner_wa'] ?? '');
    $email     = strtolower(trim($_POST['email'] ?? ''));
    $password  = $_POST['password']         ?? '';
    $passConf  = $_POST['password_confirm'] ?? '';

    if (strlen($ownerName) < 2) {
        $error = 'Nama pemilik minimal 2 karakter.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        $error = 'Format email tidak valid.';
    } elseif (!preg_match('/^628\d{8,12}$/', $ownerWa)) {
        $error = 'Nomor WhatsApp tidak valid. Contoh: 08xxxxxxxxxx';
    } elseif (strlen($password) < 8) {
        $error = 'Password minimal 8 karakter.';
    } elseif ($password !== $passConf) {
        $error = 'Konfirmasi password tidak cocok.';
    } else {
        $d['owner_name'] = $ownerName;
        $d['owner_wa']   = $ownerWa;
        $d['email']      = $email;
        $d['password']   = $password;
        $r['step'] = 3; $step = 3;
        regResetCsrf();
    }
}

// ───────────── STEP 3: Final submit ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step3_submit'])) {
    regVerifyCsrf();
    $captchaInput = (int)($_POST['captcha_answer'] ?? -1);
    $termsChecked = isset($_POST['terms']);

    if (!$termsChecked) {
        $error = 'Kamu harus menyetujui syarat & ketentuan.';
    } elseif ($captchaInput !== (int)($r['captcha_ans'] ?? -99)) {
        $error = 'Jawaban captcha salah. Coba lagi.';
        $ca = random_int(10, 49); $cb = random_int(2, 19);
        $r['captcha_a'] = $ca; $r['captcha_b'] = $cb; $r['captcha_ans'] = $ca + $cb;
    } elseif (!RateLimiter::allowRegistration()) {
        $error = 'Terlalu banyak percobaan dari jaringan ini. Coba lagi dalam 24 jam.';
    } else {
        $db = Database::get();

        // Anti-fraud silent check — no info leakage
        $emailCheck = $db->prepare("SELECT id FROM tenants WHERE email=? LIMIT 1");
        $emailCheck->execute([$d['email']]);
        $waCheck = $db->prepare("SELECT id FROM tenants WHERE owner_wa=? LIMIT 1");
        $waCheck->execute([$d['owner_wa']]);

        if ($emailCheck->fetch() || $waCheck->fetch()) {
            // Generic message — tidak bocorkan apakah email/WA sudah ada
            $error = 'Terjadi kendala pada data yang dimasukkan. Jika sudah pernah daftar, silakan login atau hubungi support.';
        } else {
            $db->beginTransaction();
            try {
                $slug   = uniqueSlug($d['nama_outlet']);
                $pwHash = password_hash($d['password'], PASSWORD_BCRYPT, ['cost' => 11]);

                // 1. Tenant (outlet belum dibuat — dibuat terpisah via add-outlet.php)
                $db->prepare("
                    INSERT INTO tenants
                      (slug, db_name, email, nama_outlet, owner_name, owner_wa,
                       status, password_hash, registration_source, registered_at,
                       coin_balance, coin_mode, total_outlets)
                    VALUES (?,?,?,?,?,?,'pending_verification',?,'self_service',NOW(),0,'shared',0)
                ")->execute([
                    $slug, $slug,
                    $d['email'], $d['nama_outlet'],
                    $d['owner_name'], $d['owner_wa'],
                    $pwHash,
                ]);
                $tenantId = (int)$db->lastInsertId();

                // 2. Seed default roles + permissions (idempotent, best-effort)
                $ownerRoleId = TenantProvisioner::seedDefaultsForTenant($db, $tenantId);

                // 3. User owner — link role_id ke 'Owner' (kalau seed sukses)
                $db->prepare("
                    INSERT INTO hl_users
                      (tenant_id, outlet_id, username, email, password, nama, role, role_id, email_verified)
                    VALUES (?,0,?,?,?,?,'owner',?,0)
                ")->execute([
                    $tenantId,
                    'owner_' . $slug,
                    $d['email'], $pwHash,
                    $d['owner_name'],
                    $ownerRoleId,
                ]);

                // 4. Registration audit log
                $db->prepare("
                    INSERT INTO registration_requests
                      (source, email, nama_outlet, owner_name, owner_wa, kota,
                       status, tenant_id, captcha_passed)
                    VALUES ('self_service',?,?,?,?,?,'email_sent',?,1)
                ")->execute([
                    $d['email'], $d['nama_outlet'], $d['owner_name'],
                    $d['owner_wa'], $d['kota'] ?? null,
                    $tenantId,
                ]);

                $db->commit();

                // 6. Rate limit
                RateLimiter::recordRegistrationAttempt($d['email'], $d['owner_wa']);

                // 7. Kirim email verifikasi
                EmailVerification::create($tenantId, $d['email'], $d['nama_outlet'], $d['owner_name']);

                // 8. Session untuk pending-verify.php
                session_regenerate_id(true);
                $_SESSION['tenant_id']         = $tenantId;
                $_SESSION['pending_verify']    = true;
                $_SESSION['reg_email_sent']    = $d['email'];
                $_SESSION['reg_outlet_name']   = $d['nama_outlet'];

                unset($_SESSION['reg']);
                regResetCsrf();

                header('Location: /ERP/harpy/pending-verify.php');
                exit;

            } catch (Throwable $e) {
                $db->rollBack();
                error_log('[register.php] Error: ' . $e->getMessage());
                // TODO: ganti ke pesan generic sebelum production
                $error = '[DEBUG] ' . $e->getMessage();
            }
        }
    }
}

// ── Back navigation ───────────────────────────────────
if (isset($_POST['go_back'])) {
    $r['step'] = max(1, $step - 1);
    $step      = $r['step'];
    regResetCsrf();
}

$csrf     = regCsrf();
$captchaA = $r['captcha_a'] ?? 10;
$captchaB = $r['captcha_b'] ?? 5;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Daftar Gratis — LAMASY</title>
<meta name="description" content="Daftarkan outlet laundry kamu di LAMASY. Gratis 7 hari, tidak perlu kartu kredit.">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --teal:  #35E8D5; --navy: #0F1C3A; --panel: #1a2d52;
    --muted: rgba(255,255,255,.55); --border: rgba(255,255,255,.1);
    --red: #f87171; --green: #34d399;
  }
  body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    background: var(--navy); color: #fff;
    min-height: 100vh; display: flex; flex-direction: column;
    align-items: center; padding: 24px 16px 48px;
  }
  .brand-bar {
    width: 100%; max-width: 520px;
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 28px;
  }
  .brand { font-size: 20px; font-weight: 800; color: var(--teal); letter-spacing: -.5px; }
  .brand small { display: block; font-size: 11px; color: var(--muted); font-weight: 400; }
  .brand-bar a { font-size: 13px; color: var(--muted); text-decoration: none; }
  .brand-bar a:hover { color: #fff; }
  .stepper {
    display: flex; align-items: center;
    margin-bottom: 28px; width: 100%; max-width: 520px;
  }
  .step-item {
    display: flex; flex-direction: column; align-items: center;
    flex: 1; position: relative;
  }
  .step-item:not(:last-child)::after {
    content: ''; position: absolute; top: 16px; left: 50%;
    width: 100%; height: 2px; background: var(--border); z-index: 0;
  }
  .step-item.done:not(:last-child)::after { background: var(--teal); }
  .step-dot {
    width: 32px; height: 32px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700; position: relative; z-index: 1;
    background: var(--panel); border: 2px solid var(--border); color: var(--muted);
    transition: all .3s;
  }
  .step-item.active .step-dot { background: var(--teal); color: var(--navy); border-color: var(--teal); }
  .step-item.done   .step-dot { background: transparent; color: var(--teal); border-color: var(--teal); }
  .step-label { font-size: 11px; margin-top: 6px; color: var(--muted); text-align: center; }
  .step-item.active .step-label { color: var(--teal); font-weight: 600; }
  .card {
    background: var(--panel); border-radius: 16px;
    padding: 36px 32px 28px; width: 100%; max-width: 520px;
    box-shadow: 0 8px 40px rgba(0,0,0,.35);
  }
  .card-title { font-size: 1.35rem; font-weight: 700; margin-bottom: 6px; }
  .card-sub   { font-size: 14px; color: var(--muted); margin-bottom: 28px; line-height: 1.5; }
  .field { margin-bottom: 18px; }
  label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: rgba(255,255,255,.8); }
  .req { color: var(--teal); } .opt { color: var(--muted); font-weight: 400; font-size: 12px; }
  input[type=text],input[type=email],input[type=tel],input[type=password],input[type=number] {
    width: 100%; padding: 11px 14px;
    background: rgba(255,255,255,.07); border: 1.5px solid var(--border);
    border-radius: 8px; color: #fff; font-size: 14px; font-family: inherit;
    transition: border-color .2s; outline: none;
  }
  input:focus { border-color: var(--teal); background: rgba(53,232,213,.06); }
  input::placeholder { color: rgba(255,255,255,.25); }
  .hint { font-size: 12px; color: var(--muted); margin-top: 5px; }
  .alert-error {
    background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3);
    color: var(--red); padding: 12px 16px; border-radius: 8px;
    font-size: 14px; margin-bottom: 20px; line-height: 1.5;
  }
  .btn-row { display: flex; gap: 10px; margin-top: 24px; }
  .btn {
    flex: 1; padding: 13px 20px; border-radius: 10px; font-weight: 700;
    font-size: 15px; cursor: pointer; border: none; transition: opacity .2s, transform .1s;
    font-family: inherit;
  }
  .btn:active { transform: scale(.98); }
  .btn-primary { background: var(--teal); color: var(--navy); }
  .btn-primary:hover { opacity: .9; }
  .btn-ghost {
    background: transparent; border: 1.5px solid var(--border);
    color: var(--muted); flex: 0 0 auto; padding: 13px 16px; font-size: 14px;
  }
  .btn-ghost:hover { border-color: rgba(255,255,255,.3); color: #fff; }
  .review-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 14px;
  }
  .review-row:last-child { border-bottom: none; }
  .rv-label { color: var(--muted); }
  .rv-val   { font-weight: 600; text-align: right; max-width: 60%; word-break: break-all; }
  .trial-badge {
    background: rgba(53,232,213,.08); border: 1px solid rgba(53,232,213,.2);
    border-radius: 10px; padding: 14px 16px; margin-bottom: 20px;
    font-size: 13px; color: rgba(255,255,255,.75); line-height: 1.6;
  }
  .trial-badge strong { color: var(--teal); }
  .terms-check {
    display: flex; align-items: flex-start; gap: 10px; padding: 14px;
    background: rgba(255,255,255,.04); border: 1.5px solid var(--border);
    border-radius: 10px; cursor: pointer; transition: border-color .2s; margin-top: 16px;
  }
  .terms-check:has(input:checked) { border-color: var(--teal); background: rgba(53,232,213,.05); }
  .terms-check input { width: 16px; height: 16px; accent-color: var(--teal); flex-shrink: 0; margin-top: 2px; }
  .terms-check span  { font-size: 13px; color: var(--muted); line-height: 1.5; }
  .terms-check a     { color: var(--teal); }
  .captcha-box {
    background: rgba(255,255,255,.04); border: 1.5px solid var(--border);
    border-radius: 10px; padding: 16px; display: flex; align-items: center; gap: 12px;
  }
  .captcha-q { font-size: 20px; font-weight: 700; color: var(--teal); white-space: nowrap; }
  .captcha-box input[type=number] { width: 90px; text-align: center; font-size: 20px; font-weight: 700; padding: 8px; }
  .pw-bar { height: 4px; border-radius: 2px; margin-top: 6px; background: var(--border); overflow: hidden; }
  .pw-bar-fill { height: 100%; width: 0; border-radius: 2px; transition: width .3s, background .3s; }
  @media (max-width:540px) { .card{padding:28px 20px 24px;} .step-label{display:none;} }
</style>
</head>
<body>

<div class="brand-bar">
  <div class="brand">LAMASY <small>Laundry Management System</small></div>
  <a href="/ERP/harpy/login.php">Sudah punya akun? Login →</a>
</div>

<div class="stepper">
  <?php
  $stepsMeta = [1=>'Info Outlet', 2=>'Akun Kamu', 3=>'Konfirmasi'];
  foreach ($stepsMeta as $sn => $sl):
    $cls = $sn < $step ? 'done' : ($sn === $step ? 'active' : '');
    $dot = $sn < $step ? '✓' : $sn;
  ?>
    <div class="step-item <?= $cls ?>">
      <div class="step-dot"><?= $dot ?></div>
      <div class="step-label"><?= $sl ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
<?php if ($error): ?>
  <div class="alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php // ═══ STEP 1 ══════════════════════════════════════
if ($step === 1): ?>

  <div class="card-title">🏪 Info Outlet</div>
  <p class="card-sub">Ceritakan sedikit tentang outlet laundry kamu</p>

  <form method="POST" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
    <div class="field">
      <label>Nama Outlet <span class="req">*</span></label>
      <input type="text" name="nama_outlet" required maxlength="80"
             value="<?= htmlspecialchars($d['nama_outlet'] ?? '') ?>"
             placeholder="cth: Laundry Bersih Jaya" autocomplete="off">
      <div class="hint">Muncul di nota & notifikasi pelanggan</div>
    </div>
    <div class="field">
      <label>Nama Perusahaan / Brand <span class="opt">(opsional)</span></label>
      <input type="text" name="nama_perusahaan" maxlength="100"
             value="<?= htmlspecialchars($d['nama_perusahaan'] ?? '') ?>"
             placeholder="cth: PT Bersih Jaya Group">
    </div>
    <div class="field">
      <label>Kota <span class="opt">(opsional)</span></label>
      <input type="text" name="kota" maxlength="100"
             value="<?= htmlspecialchars($d['kota'] ?? '') ?>"
             placeholder="cth: Bandung">
    </div>
    <div class="trial-badge">
      🎁 <strong>Gratis 7 hari trial!</strong>
      Dapat 1.000 coin untuk coba fitur AI & notifikasi WA.
      Tidak perlu kartu kredit.
    </div>
    <div class="btn-row">
      <button type="submit" name="step1_submit" class="btn btn-primary">Lanjut →</button>
    </div>
  </form>

<?php // ═══ STEP 2 ══════════════════════════════════════
elseif ($step === 2): ?>

  <div class="card-title">👤 Akun Kamu</div>
  <p class="card-sub">Data login dan kontak untuk akun LAMASY kamu</p>

  <form method="POST" autocomplete="off" id="form2">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
    <div class="field">
      <label>Nama Pemilik / PIC <span class="req">*</span></label>
      <input type="text" name="owner_name" required maxlength="100"
             value="<?= htmlspecialchars($d['owner_name'] ?? '') ?>"
             placeholder="Nama lengkap kamu">
    </div>
    <div class="field">
      <label>Nomor WhatsApp <span class="req">*</span></label>
      <input type="tel" name="owner_wa" required maxlength="15"
             value="<?= htmlspecialchars(
               !empty($d['owner_wa']) ? preg_replace('/^628/', '08', $d['owner_wa']) : ''
             ) ?>"
             placeholder="08xxxxxxxxxx">
      <div class="hint">Untuk notifikasi aktivasi & info akun</div>
    </div>
    <div class="field">
      <label>Email <span class="req">*</span></label>
      <input type="email" name="email" required maxlength="150"
             value="<?= htmlspecialchars($d['email'] ?? '') ?>"
             placeholder="email@kamu.com" autocomplete="email">
      <div class="hint">Link verifikasi akan dikirim ke email ini</div>
    </div>
    <div class="field">
      <label>Password <span class="req">*</span></label>
      <input type="password" name="password" required minlength="8" maxlength="72"
             placeholder="Minimal 8 karakter" id="pw" autocomplete="new-password">
      <div class="pw-bar"><div class="pw-bar-fill" id="pwFill"></div></div>
      <div class="hint" id="pwHint">Minimal 8 karakter</div>
    </div>
    <div class="field">
      <label>Konfirmasi Password <span class="req">*</span></label>
      <input type="password" name="password_confirm" required minlength="8" maxlength="72"
             placeholder="Ulangi password" id="pwc" autocomplete="new-password">
      <div class="hint" id="pwcHint"></div>
    </div>
    <div class="btn-row">
      <button type="submit" name="go_back" class="btn btn-ghost">← Kembali</button>
      <button type="submit" name="step2_submit" class="btn btn-primary">Lanjut →</button>
    </div>
  </form>

  <script>
  (function(){
    var pw=document.getElementById('pw'),fill=document.getElementById('pwFill'),
        hint=document.getElementById('pwHint'),pwc=document.getElementById('pwc'),
        ch=document.getElementById('pwcHint');
    function str(s){
      var n=0;
      if(s.length>=8)n++;if(s.length>=12)n++;
      if(/[A-Z]/.test(s))n++;if(/[0-9]/.test(s))n++;if(/[^A-Za-z0-9]/.test(s))n++;
      return n;
    }
    pw.oninput=function(){
      var s=str(pw.value);
      fill.style.width=['0%','25%','40%','65%','85%','100%'][s];
      fill.style.background=['transparent','#ef4444','#f97316','#eab308','#22c55e','#35E8D5'][s];
      hint.textContent=['','Terlalu pendek','Lemah','Cukup','Kuat','Sangat Kuat'][s]||'Minimal 8 karakter';
      hint.style.color=fill.style.background||'var(--muted)';
    };
    pwc.oninput=function(){
      if(!pwc.value){ch.textContent='';return;}
      if(pwc.value===pw.value){ch.textContent='✓ Password cocok';ch.style.color='#34d399';}
      else{ch.textContent='Password tidak cocok';ch.style.color='#f87171';}
    };
  })();
  </script>

<?php // ═══ STEP 3 ══════════════════════════════════════
elseif ($step === 3): ?>

  <div class="card-title">✅ Konfirmasi</div>
  <p class="card-sub">Periksa kembali data sebelum menyelesaikan pendaftaran</p>

  <div style="margin-bottom:20px">
    <div class="review-row">
      <span class="rv-label">Nama Outlet</span>
      <span class="rv-val"><?= htmlspecialchars($d['nama_outlet'] ?? '-') ?></span>
    </div>
    <?php if (!empty($d['kota'])): ?>
    <div class="review-row">
      <span class="rv-label">Kota</span>
      <span class="rv-val"><?= htmlspecialchars($d['kota']) ?></span>
    </div>
    <?php endif; ?>
    <div class="review-row">
      <span class="rv-label">Pemilik</span>
      <span class="rv-val"><?= htmlspecialchars($d['owner_name'] ?? '-') ?></span>
    </div>
    <div class="review-row">
      <span class="rv-label">WhatsApp</span>
      <span class="rv-val"><?= htmlspecialchars(preg_replace('/^628/', '08', $d['owner_wa'] ?? '')) ?></span>
    </div>
    <div class="review-row">
      <span class="rv-label">Email</span>
      <span class="rv-val"><?= htmlspecialchars($d['email'] ?? '-') ?></span>
    </div>
    <div class="review-row">
      <span class="rv-label">Paket</span>
      <span class="rv-val" style="color:var(--teal)">🎁 Trial 7 Hari Gratis</span>
    </div>
    <div class="review-row">
      <span class="rv-label">Trial Coin</span>
      <span class="rv-val">1.000 coin</span>
    </div>
  </div>

  <form method="POST">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">

    <div class="field">
      <label>Verifikasi Manusia 🤖</label>
      <div class="captcha-box">
        <span class="captcha-q"><?= $captchaA ?> + <?= $captchaB ?> =</span>
        <input type="number" name="captcha_answer" required min="0" max="999" placeholder="?" autocomplete="off">
      </div>
    </div>

    <label class="terms-check">
      <input type="checkbox" name="terms" required>
      <span>
        Saya menyetujui
        <a href="/ERP/harpy/landing.php#terms" target="_blank">Syarat &amp; Ketentuan</a>
        dan <a href="/ERP/harpy/landing.php#privacy" target="_blank">Kebijakan Privasi</a>
        LAMASY.
      </span>
    </label>

    <div class="btn-row">
      <button type="submit" name="go_back" class="btn btn-ghost">← Kembali</button>
      <button type="submit" name="step3_submit" class="btn btn-primary">🚀 Daftar Sekarang</button>
    </div>
  </form>

<?php endif; ?>

</div><!-- /card -->

<p style="margin-top:24px;font-size:12px;color:rgba(255,255,255,.3);text-align:center">
  Butuh bantuan?
  <a href="https://wa.me/6281234567890" style="color:rgba(53,232,213,.7)">Chat Tim LAMASY</a>
</p>

</body>
</html>
