<?php
// ══════════════════════════════════════════════════════
// harpy/register.php — Self-Registration Form (3 Steps)
// Menyimpan ke tabel registration_requests
// ══════════════════════════════════════════════════════

if (!defined('ROOT')) define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';

date_default_timezone_set('Asia/Jakarta');
if (session_status() === PHP_SESSION_NONE) session_start();

// ── CSRF helpers ──────────────────────────────────────
function regGetCsrf(): string {
    if (empty($_SESSION['reg_csrf'])) {
        $_SESSION['reg_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['reg_csrf'];
}
function regVerifyCsrf(): void {
    $tok = $_POST['_csrf'] ?? '';
    if (!hash_equals(regGetCsrf(), $tok)) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}
function regResetCsrf(): void {
    $_SESSION['reg_csrf'] = bin2hex(random_bytes(32));
}

// ── Wizard session init ───────────────────────────────
if (isset($_GET['reset']) || empty($_SESSION['reg_wizard'])) {
    $_SESSION['reg_wizard'] = ['step' => 1];
}

$w     = &$_SESSION['reg_wizard'];
$step  = (int)($w['step'] ?? 1);
$error = '';
$success = false;

// ── Paket definitions ─────────────────────────────────
$packages = [
    'trial' => [
        'nama'       => 'Trial Gratis',
        'setup_fee'  => 0,
        'coin_awal'  => 50000,
        'trial_days' => 30,
        'desc'       => 'Coba semua fitur selama 30 hari. Tidak perlu bayar apapun.',
        'badge'      => 'Direkomendasikan',
        'badge_color'=> 'teal',
    ],
    'starter' => [
        'nama'       => 'Starter',
        'setup_fee'  => 300000,
        'coin_awal'  => 100000,
        'trial_days' => 0,
        'desc'       => 'Setup fee Rp 300.000 untuk onboarding lengkap.',
        'badge'      => '',
        'badge_color'=> '',
    ],
    'professional' => [
        'nama'       => 'Professional',
        'setup_fee'  => 500000,
        'coin_awal'  => 300000,
        'trial_days' => 0,
        'desc'       => 'Setup premium + onboarding intensif + prioritas support.',
        'badge'      => 'Terbaik',
        'badge_color'=> 'blue',
    ],
];

// ── Handle POST ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    regVerifyCsrf();
    $pStep = (int)($_POST['step'] ?? 1);

    // Back button
    if (isset($_POST['back'])) {
        $backTo = max(1, $step - 1);
        $w['step'] = $backTo; $step = $backTo;
    }

    // Step 1: Info Bisnis
    elseif ($pStep === 1) {
        $w['nama_outlet']    = substr(trim(strip_tags($_POST['nama_outlet']    ?? '')), 0, 100);
        $w['nama_perusahaan']= substr(trim(strip_tags($_POST['nama_perusahaan']?? '')), 0, 100);
        $w['owner_name']     = substr(trim(strip_tags($_POST['owner_name']     ?? '')), 0, 100);
        $w['owner_wa']       = substr(trim(preg_replace('/[^0-9+]/', '', $_POST['owner_wa'] ?? '')), 0, 20);
        $w['kota']           = substr(trim(strip_tags($_POST['kota']           ?? '')), 0, 100);

        // Validasi wajib
        if (!$w['nama_outlet'] || !$w['owner_name'] || !$w['owner_wa']) {
            $error = 'Nama outlet, nama pemilik, dan nomor WhatsApp wajib diisi.';
        } elseif (strlen($w['owner_wa']) < 9) {
            $error = 'Nomor WhatsApp tidak valid.';
        } else {
            // Normalise WA: 08xx → 628xx
            if (substr($w['owner_wa'], 0, 2) === '08') {
                $w['owner_wa'] = '62' . substr($w['owner_wa'], 1);
            }
            $w['step'] = 2; $step = 2;
        }
    }

    // Step 2: Pilih Paket
    elseif ($pStep === 2) {
        $pkg = $_POST['paket'] ?? 'trial';
        if (!array_key_exists($pkg, $packages)) $pkg = 'trial';
        $w['paket']      = $pkg;
        $w['setup_fee']  = $packages[$pkg]['setup_fee'];
        $w['coin_awal']  = $packages[$pkg]['coin_awal'];
        $w['trial_days'] = $packages[$pkg]['trial_days'];
        $w['step'] = 3; $step = 3;
    }

    // Step 3: Konfirmasi & Submit
    elseif ($pStep === 3) {
        $w['agree_terms'] = !empty($_POST['agree_terms']);
        if (!$w['agree_terms']) {
            $error = 'Anda harus menyetujui syarat dan ketentuan untuk melanjutkan.';
            $step  = 3;
        } else {
            // Insert ke DB
            try {
                $db = Database::get();

                // Cek duplikat dalam 7 hari terakhir
                $chk = $db->prepare(
                    "SELECT COUNT(*) FROM registration_requests
                     WHERE owner_wa = ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
                );
                $chk->execute([$w['owner_wa']]);
                if ((int)$chk->fetchColumn() > 0) {
                    $error = 'Nomor WhatsApp ini sudah melakukan pendaftaran dalam 7 hari terakhir. Tim kami akan segera menghubungi Anda.';
                    $step  = 3;
                } else {
                    $db->prepare(
                        "INSERT INTO registration_requests
                         (source, nama_outlet, nama_perusahaan, owner_name, owner_wa, kota,
                          status, payment_status, setup_fee, coin_awal, trial_days, coin_mode, created_at)
                         VALUES ('self_service',?,?,?,?,?,'pending','pending',?,?,?,?,NOW())"
                    )->execute([
                        $w['nama_outlet'],
                        $w['nama_perusahaan'] ?? '',
                        $w['owner_name'],
                        $w['owner_wa'],
                        $w['kota'] ?? '',
                        (int)$w['setup_fee'],
                        (int)$w['coin_awal'],
                        (int)$w['trial_days'],
                        'shared',
                    ]);

                    // Sukses — set step 4 (success page)
                    $w['step'] = 4;
                    $w['submitted'] = true;
                    $step = 4;
                    $success = true;
                    regResetCsrf(); // Regenerate token setelah submit sukses
                }
            } catch (Throwable $e) {
                $error = 'Terjadi kesalahan sistem, silakan coba beberapa saat lagi.';
                // Log error internal, jangan expose ke user
                error_log('[register.php] DB error: ' . $e->getMessage());
            }
        }
    }
}

// Jika sudah di step 4 dari sebelumnya
if ($step === 4 && !$success && !empty($w['submitted'])) {
    $success = true;
}

$csrf = regGetCsrf();
$curPkg = $packages[$w['paket'] ?? 'trial'] ?? $packages['trial'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Daftar Harpy ERP — Mulai Gratis</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ══════════════════════════════════════════════════════
   Variables & Reset
══════════════════════════════════════════════════════ */
:root {
  --teal:   #35E8D5;
  --teal-d: #1BC4B3;
  --teal-l: rgba(53,232,213,.12);
  --navy:   #1B2D5A;
  --navy-d: #0F1C3A;
  --navy-m: #162348;
  --white:  #FFFFFF;
  --red:    #EF4444;
  --green:  #10B981;
  --font:   'Plus Jakarta Sans', sans-serif;
  --mono:   'DM Mono', monospace;
  --r:      12px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
  font-family: var(--font);
  background: var(--navy-d);
  color: var(--white);
  min-height: 100vh;
  display: flex; flex-direction: column;
  align-items: center; justify-content: flex-start;
  padding: 40px 20px 60px;
  position: relative; overflow-x: hidden;
}

/* ── Background ──────────────────────────────────── */
body::before {
  content: '';
  position: fixed; inset: 0;
  background-image:
    linear-gradient(rgba(255,255,255,.022) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.022) 1px, transparent 1px);
  background-size: 52px 52px;
  pointer-events: none; z-index: 0;
}
.orb {
  position: fixed; border-radius: 50%; filter: blur(90px);
  pointer-events: none; z-index: 0;
}
.orb1 {
  width: 450px; height: 450px; top: -80px; right: -80px;
  background: radial-gradient(circle, rgba(53,232,213,.16) 0%, transparent 70%);
  animation: orbFloat 14s ease-in-out infinite;
}
.orb2 {
  width: 350px; height: 350px; bottom: -60px; left: -80px;
  background: radial-gradient(circle, rgba(27,45,90,.5) 0%, transparent 70%);
  animation: orbFloat 18s ease-in-out infinite reverse;
}
@keyframes orbFloat {
  0%,100% { transform: translate(0,0); }
  50%      { transform: translate(25px,-25px); }
}

/* ── Topbar ──────────────────────────────────────── */
.reg-topbar {
  position: relative; z-index: 10;
  width: 100%; max-width: 560px;
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 32px;
}
.topbar-logo {
  display: flex; align-items: center; gap: 10px;
  font-size: 18px; font-weight: 800; color: var(--white);
  text-decoration: none;
}
.topbar-logo .lb {
  width: 34px; height: 34px; border-radius: 9px;
  background: linear-gradient(135deg, var(--teal), var(--teal-d));
  display: flex; align-items: center; justify-content: center;
  font-size: 17px;
  box-shadow: 0 4px 14px rgba(53,232,213,.25);
}
.topbar-logo span { color: var(--teal); }
.topbar-login {
  font-size: 13px; color: rgba(255,255,255,.5);
  text-decoration: none; transition: color .15s;
}
.topbar-login:hover { color: var(--teal); }

/* ── Registration Card ────────────────────────────── */
.reg-card {
  position: relative; z-index: 1;
  width: 100%; max-width: 560px;
  background: rgba(255,255,255,.04);
  backdrop-filter: blur(20px);
  border: 1px solid rgba(255,255,255,.1);
  border-radius: 20px;
  padding: 36px 32px;
  box-shadow: 0 32px 80px rgba(0,0,0,.4);
  animation: slideUp .5s cubic-bezier(.4,0,.2,1);
}
@keyframes slideUp {
  from { opacity:0; transform:translateY(24px); }
  to   { opacity:1; transform:translateY(0); }
}

/* ── Step Indicator ──────────────────────────────── */
.step-indicator {
  display: flex; align-items: center; gap: 0;
  margin-bottom: 28px;
}
.step-dot {
  display: flex; align-items: center; gap: 8px;
  flex-shrink: 0;
}
.step-num {
  width: 30px; height: 30px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 800;
  background: rgba(255,255,255,.06);
  border: 2px solid rgba(255,255,255,.1);
  color: rgba(255,255,255,.3);
  transition: all .3s;
}
.step-num.active {
  background: var(--teal-l);
  border-color: rgba(53,232,213,.4);
  color: var(--teal);
}
.step-num.done {
  background: rgba(16,185,129,.2);
  border-color: rgba(16,185,129,.4);
  color: #6EE7B7;
}
.step-label {
  font-size: 11px; font-weight: 700;
  color: rgba(255,255,255,.25);
  display: none;
}
.step-label.active { color: var(--white); display: block; }
.step-connector {
  flex: 1; height: 2px;
  background: rgba(255,255,255,.06); margin: 0 6px;
}
.step-connector.done { background: rgba(53,232,213,.3); }

/* ── Card Header ─────────────────────────────────── */
.card-title   { font-size: 20px; font-weight: 800; margin-bottom: 6px; }
.card-subtitle{ font-size: 13px; color: rgba(255,255,255,.4); margin-bottom: 24px; line-height: 1.55; }

/* ── Form Fields ─────────────────────────────────── */
.field { margin-bottom: 16px; }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 480px) { .field-row { grid-template-columns: 1fr; } }
.label {
  display: block;
  font-size: 11px; font-weight: 700; letter-spacing: .07em;
  text-transform: uppercase; color: rgba(255,255,255,.4);
  margin-bottom: 7px;
}
.label .req { color: var(--red); }
.input-wrap { position: relative; }
.input-icon {
  position: absolute; left: 14px; top: 50%;
  transform: translateY(-50%); font-size: 15px; pointer-events: none;
}
.input {
  width: 100%; padding: 12px 14px 12px 42px;
  background: rgba(255,255,255,.06);
  border: 1.5px solid rgba(255,255,255,.1);
  border-radius: 10px; color: var(--white);
  font-family: var(--font); font-size: 14px; outline: none;
  transition: border-color .15s, background .15s;
}
.input.no-icon { padding-left: 14px; }
.input:focus {
  border-color: var(--teal);
  background: rgba(53,232,213,.04);
  box-shadow: 0 0 0 3px rgba(53,232,213,.1);
}
.input::placeholder { color: rgba(255,255,255,.25); }
.input-hint { font-size: 11px; color: rgba(255,255,255,.3); margin-top: 5px; }

/* ── Package Cards ───────────────────────────────── */
.pkg-grid { display: flex; flex-direction: column; gap: 10px; }
.pkg-option {
  position: relative;
  display: flex; align-items: flex-start; gap: 14px;
  padding: 16px 18px;
  background: rgba(255,255,255,.03);
  border: 2px solid rgba(255,255,255,.08);
  border-radius: 13px; cursor: pointer;
  transition: all .2s;
}
.pkg-option:hover {
  border-color: rgba(53,232,213,.25);
  background: rgba(53,232,213,.04);
}
.pkg-option.selected {
  border-color: rgba(53,232,213,.45);
  background: var(--teal-l);
}
.pkg-option input[type=radio] {
  margin-top: 3px; flex-shrink: 0;
  accent-color: var(--teal);
  width: 16px; height: 16px;
}
.pkg-body { flex: 1; }
.pkg-header { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
.pkg-name { font-size: 15px; font-weight: 700; }
.pkg-badge {
  font-size: 10px; font-weight: 700; letter-spacing: .05em;
  padding: 2px 10px; border-radius: 100px;
}
.pkg-badge.teal { background: rgba(53,232,213,.18); color: var(--teal); border: 1px solid rgba(53,232,213,.3); }
.pkg-badge.blue { background: rgba(99,102,241,.18); color: #A5B4FC; border: 1px solid rgba(99,102,241,.3); }
.pkg-desc   { font-size: 12.5px; color: rgba(255,255,255,.5); line-height: 1.5; margin-bottom: 8px; }
.pkg-meta   { display: flex; gap: 16px; flex-wrap: wrap; }
.pkg-meta-item {
  font-size: 11px; font-weight: 600;
  color: rgba(255,255,255,.4);
  display: flex; align-items: center; gap: 5px;
}
.pkg-meta-item .val { color: var(--teal); }
.pkg-price {
  text-align: right; flex-shrink: 0;
  font-family: var(--mono);
}
.pkg-price .amount { font-size: 16px; font-weight: 800; color: var(--white); }
.pkg-price .free   { font-size: 16px; font-weight: 800; color: var(--teal); }
.pkg-price .label2 { font-size: 10px; color: rgba(255,255,255,.35); }

/* ── Coin Accordion ──────────────────────────────── */
.coin-info {
  margin-top: 14px;
  border: 1px solid rgba(53,232,213,.15);
  border-radius: 10px; overflow: hidden;
}
.coin-toggle {
  display: flex; align-items: center; justify-content: space-between;
  padding: 12px 16px; cursor: pointer;
  font-size: 13px; font-weight: 600; color: rgba(255,255,255,.6);
  background: rgba(53,232,213,.04);
  transition: background .15s;
  user-select: none;
}
.coin-toggle:hover { background: rgba(53,232,213,.08); color: var(--white); }
.coin-toggle .arrow { transition: transform .2s; font-size: 11px; }
.coin-toggle.open .arrow { transform: rotate(180deg); }
.coin-body {
  display: none; padding: 14px 16px;
  font-size: 13px; color: rgba(255,255,255,.55); line-height: 1.7;
  border-top: 1px solid rgba(53,232,213,.1);
}
.coin-body.open { display: block; }
.coin-body strong { color: var(--teal); }

/* ── Review Table ────────────────────────────────── */
.review-block {
  background: rgba(255,255,255,.03);
  border: 1px solid rgba(255,255,255,.07);
  border-radius: 13px; overflow: hidden;
  margin-bottom: 20px;
}
.review-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 12px 16px;
  border-bottom: 1px solid rgba(255,255,255,.05);
}
.review-row:last-child { border-bottom: none; }
.review-row .rk { font-size: 12.5px; color: rgba(255,255,255,.4); font-weight: 600; }
.review-row .rv { font-size: 13px; color: var(--white); font-weight: 600; text-align: right; }
.review-section-header {
  padding: 10px 16px;
  font-size: 10px; font-weight: 700; letter-spacing: .1em;
  text-transform: uppercase; color: rgba(255,255,255,.3);
  background: rgba(255,255,255,.02);
  border-bottom: 1px solid rgba(255,255,255,.05);
}

/* ── Checkbox Terms ──────────────────────────────── */
.terms-wrap {
  display: flex; align-items: flex-start; gap: 12px;
  padding: 16px; border-radius: 12px;
  border: 1.5px solid rgba(255,255,255,.08);
  background: rgba(255,255,255,.02);
  cursor: pointer; transition: border-color .15s;
  margin-bottom: 20px;
}
.terms-wrap:hover { border-color: rgba(53,232,213,.2); }
.terms-wrap input[type=checkbox] { margin-top: 2px; accent-color: var(--teal); flex-shrink: 0; }
.terms-text { font-size: 13px; color: rgba(255,255,255,.55); line-height: 1.55; }
.terms-text a { color: var(--teal); }

/* ── Alert ───────────────────────────────────────── */
.alert {
  padding: 13px 16px; border-radius: 10px;
  font-size: 13px; line-height: 1.5;
  margin-bottom: 20px;
  display: flex; align-items: flex-start; gap: 10px;
}
.alert-error   { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.25); color: #FCA5A5; }
.alert-success { background: rgba(16,185,129,.1); border: 1px solid rgba(16,185,129,.25); color: #6EE7B7; }

/* ── Buttons ─────────────────────────────────────── */
.form-footer {
  display: flex; justify-content: space-between; align-items: center;
  margin-top: 24px; gap: 12px;
}
.btn {
  padding: 12px 26px;
  font-family: var(--font); font-size: 14px; font-weight: 700;
  border-radius: 10px; cursor: pointer; border: none;
  transition: all .2s; display: inline-flex; align-items: center; gap: 8px;
}
.btn-primary {
  color: var(--navy-d);
  background: linear-gradient(135deg, var(--teal), var(--teal-d));
  box-shadow: 0 4px 18px rgba(53,232,213,.25);
}
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 24px rgba(53,232,213,.4); }
.btn-outline {
  color: rgba(255,255,255,.65);
  background: rgba(255,255,255,.05);
  border: 1.5px solid rgba(255,255,255,.12) !important;
}
.btn-outline:hover { color: var(--white); background: rgba(255,255,255,.1); }
.btn-wa {
  color: var(--white);
  background: #25D366;
  box-shadow: 0 4px 18px rgba(37,211,102,.3);
  padding: 13px 28px; font-size: 15px;
}
.btn-wa:hover { transform: translateY(-1px); box-shadow: 0 6px 24px rgba(37,211,102,.45); }
.btn-home {
  color: rgba(255,255,255,.6);
  background: rgba(255,255,255,.06);
  border: 1.5px solid rgba(255,255,255,.1) !important;
  padding: 12px 24px;
}
.btn-home:hover { color: var(--white); }

/* ── Success Screen ──────────────────────────────── */
.success-screen { text-align: center; padding: 10px 0 4px; }
.success-icon {
  font-size: 64px; margin-bottom: 20px; display: block;
  animation: bounceIn .6s cubic-bezier(.34,1.56,.64,1);
}
@keyframes bounceIn {
  0% { transform: scale(0.5); opacity: 0; }
  100% { transform: scale(1); opacity: 1; }
}
.success-title { font-size: 24px; font-weight: 800; margin-bottom: 10px; }
.success-sub   { font-size: 14px; color: rgba(255,255,255,.5); margin-bottom: 28px; line-height: 1.65; }
.wa-number-box {
  display: inline-flex; align-items: center; gap: 10px;
  padding: 10px 18px; border-radius: 10px;
  background: rgba(53,232,213,.08);
  border: 1px solid rgba(53,232,213,.2);
  font-family: var(--mono); font-size: 16px; font-weight: 700;
  color: var(--teal); margin-bottom: 28px;
}
.info-list {
  list-style: none; text-align: left;
  background: rgba(255,255,255,.03);
  border: 1px solid rgba(255,255,255,.07);
  border-radius: 13px; overflow: hidden;
  margin-bottom: 28px;
}
.info-list li {
  display: flex; align-items: flex-start; gap: 12px;
  padding: 13px 16px;
  border-bottom: 1px solid rgba(255,255,255,.05);
  font-size: 13.5px; color: rgba(255,255,255,.65);
}
.info-list li:last-child { border-bottom: none; }
.info-list li .info-icon { font-size: 16px; flex-shrink: 0; margin-top: 1px; }
.success-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; }

/* ── Responsive ──────────────────────────────────── */
@media (max-width: 480px) {
  body          { padding: 24px 16px 40px; }
  .reg-card     { padding: 26px 20px; border-radius: 16px; }
  .step-label   { display: none !important; }
  .form-footer  { flex-direction: column; }
  .form-footer .btn { width: 100%; justify-content: center; }
  .success-actions .btn { width: 100%; justify-content: center; }
}
</style>
</head>
<body>
<div class="orb orb1"></div>
<div class="orb orb2"></div>

<!-- ── Top Bar ─────────────────────────────────────── -->
<div class="reg-topbar">
  <a href="landing.php" class="topbar-logo">
    <div class="lb">🧺</div>
    Har<span>py</span>
  </a>
  <a href="login.php" class="topbar-login">Sudah punya akun? Masuk →</a>
</div>

<!-- ── Registration Card ──────────────────────────── -->
<div class="reg-card">

  <?php if ($step < 4): ?>
  <!-- Step Indicator -->
  <div class="step-indicator">
    <?php
    $stepLabels = ['Info Bisnis', 'Pilih Paket', 'Konfirmasi'];
    for ($i = 1; $i <= 3; $i++):
      $cls = $i < $step ? 'done' : ($i === $step ? 'active' : '');
    ?>
      <?php if ($i > 1): ?>
        <div class="step-connector <?= $i <= $step ? 'done' : '' ?>"></div>
      <?php endif; ?>
      <div class="step-dot">
        <div class="step-num <?= $cls ?>">
          <?= $i < $step ? '&#x2713;' : $i ?>
        </div>
        <div class="step-label <?= $cls === 'active' ? 'active' : '' ?>"><?= $stepLabels[$i-1] ?></div>
      </div>
    <?php endfor; ?>
  </div>
  <?php endif; ?>

  <?php if ($error): ?>
  <div class="alert alert-error">&#9888; <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════
       STEP 1 — Info Bisnis
  ══════════════════════════════════════════════ -->
  <?php if ($step === 1): ?>
  <div class="card-title">Informasi Bisnis Anda</div>
  <div class="card-subtitle">Ceritakan tentang outlet laundry Anda. Kami akan menghubungi Anda via WhatsApp.</div>

  <form method="POST" id="step1Form">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>"/>
    <input type="hidden" name="step" value="1"/>

    <div class="field-row">
      <div class="field">
        <label class="label">Nama Outlet <span class="req">*</span></label>
        <div class="input-wrap">
          <span class="input-icon">🏪</span>
          <input type="text" name="nama_outlet" class="input" required
                 placeholder="Harpy Laundry Semarang"
                 value="<?= htmlspecialchars($w['nama_outlet'] ?? '') ?>"/>
        </div>
      </div>
      <div class="field">
        <label class="label">Nama Perusahaan / Brand</label>
        <div class="input-wrap">
          <span class="input-icon">🏢</span>
          <input type="text" name="nama_perusahaan" class="input"
                 placeholder="Opsional"
                 value="<?= htmlspecialchars($w['nama_perusahaan'] ?? '') ?>"/>
        </div>
      </div>
    </div>

    <div class="field">
      <label class="label">Nama Pemilik <span class="req">*</span></label>
      <div class="input-wrap">
        <span class="input-icon">👤</span>
        <input type="text" name="owner_name" class="input" required
               placeholder="Budi Santoso"
               value="<?= htmlspecialchars($w['owner_name'] ?? '') ?>"/>
      </div>
    </div>

    <div class="field-row">
      <div class="field">
        <label class="label">No WhatsApp <span class="req">*</span></label>
        <div class="input-wrap">
          <span class="input-icon">📱</span>
          <input type="tel" name="owner_wa" class="input" required
                 placeholder="081234567890"
                 value="<?= htmlspecialchars($w['owner_wa'] ?? '') ?>"/>
        </div>
        <div class="input-hint">Format 08xx atau 62xx</div>
      </div>
      <div class="field">
        <label class="label">Kota / Kabupaten</label>
        <div class="input-wrap">
          <span class="input-icon">📍</span>
          <input type="text" name="kota" class="input"
                 placeholder="Semarang"
                 value="<?= htmlspecialchars($w['kota'] ?? '') ?>"/>
        </div>
      </div>
    </div>

    <div class="form-footer">
      <a href="landing.php" class="btn btn-outline">&#8592; Kembali</a>
      <button type="submit" class="btn btn-primary">Lanjut &#8594;</button>
    </div>
  </form>

  <!-- ══════════════════════════════════════════════
       STEP 2 — Pilih Paket
  ══════════════════════════════════════════════ -->
  <?php elseif ($step === 2): ?>
  <div class="card-title">Pilih Paket</div>
  <div class="card-subtitle">Mulai gratis tanpa risiko, atau langsung aktifkan akun penuh dengan setup fee.</div>

  <form method="POST" id="step2Form">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>"/>
    <input type="hidden" name="step" value="2"/>

    <div class="pkg-grid" id="pkgGrid">
      <?php foreach ($packages as $pkgKey => $pkg): ?>
        <?php $sel = ($w['paket'] ?? 'trial') === $pkgKey; ?>
        <label class="pkg-option <?= $sel ? 'selected' : '' ?>" onclick="selectPkg(this,'<?= $pkgKey ?>')">
          <input type="radio" name="paket" value="<?= $pkgKey ?>" <?= $sel ? 'checked' : '' ?>/>
          <div class="pkg-body">
            <div class="pkg-header">
              <span class="pkg-name"><?= htmlspecialchars($pkg['nama']) ?></span>
              <?php if ($pkg['badge']): ?>
                <span class="pkg-badge <?= $pkg['badge_color'] ?>"><?= htmlspecialchars($pkg['badge']) ?></span>
              <?php endif; ?>
            </div>
            <div class="pkg-desc"><?= htmlspecialchars($pkg['desc']) ?></div>
            <div class="pkg-meta">
              <div class="pkg-meta-item">&#9733; Coin awal: <span class="val"><?= number_format($pkg['coin_awal'], 0, ',', '.') ?></span></div>
              <?php if ($pkg['trial_days']): ?>
                <div class="pkg-meta-item">&#9201; Trial: <span class="val"><?= $pkg['trial_days'] ?> hari</span></div>
              <?php endif; ?>
            </div>
          </div>
          <div class="pkg-price">
            <?php if ($pkg['setup_fee'] === 0): ?>
              <div class="free">Gratis</div>
            <?php else: ?>
              <div class="amount">Rp <?= number_format($pkg['setup_fee'], 0, ',', '.') ?></div>
            <?php endif; ?>
            <div class="label2">setup fee</div>
          </div>
        </label>
      <?php endforeach; ?>
    </div>

    <!-- Coin Info Accordion -->
    <div class="coin-info">
      <div class="coin-toggle" id="coinToggle" onclick="toggleCoinInfo()">
        &#9432; Apa itu Coin?
        <span class="arrow">&#9650;</span>
      </div>
      <div class="coin-body" id="coinBody">
        <strong>Coin</strong> adalah kredit yang digunakan untuk mengirim notifikasi WhatsApp ke pelanggan Anda secara otomatis.<br><br>
        Setiap notifikasi (misal: "cucian Anda sudah siap") menggunakan <strong>1 coin</strong>. Coin dapat ditambah (topup) kapanpun melalui panel admin.<br><br>
        Dengan paket Trial, Anda mendapat <strong>50.000 coin gratis</strong> untuk digunakan selama masa trial.
      </div>
    </div>

    <div class="form-footer">
      <button type="submit" name="back" value="1" class="btn btn-outline">&#8592; Kembali</button>
      <button type="submit" class="btn btn-primary">Lanjut &#8594;</button>
    </div>
  </form>

  <!-- ══════════════════════════════════════════════
       STEP 3 — Konfirmasi
  ══════════════════════════════════════════════ -->
  <?php elseif ($step === 3): ?>
  <div class="card-title">Konfirmasi Pendaftaran</div>
  <div class="card-subtitle">Periksa kembali data Anda sebelum submit.</div>

  <?php $selPkg = $packages[$w['paket'] ?? 'trial']; ?>
  <div class="review-block">
    <div class="review-section-header">Informasi Bisnis</div>
    <div class="review-row">
      <span class="rk">Nama Outlet</span>
      <span class="rv"><?= htmlspecialchars($w['nama_outlet'] ?? '-') ?></span>
    </div>
    <?php if (!empty($w['nama_perusahaan'])): ?>
    <div class="review-row">
      <span class="rk">Nama Perusahaan</span>
      <span class="rv"><?= htmlspecialchars($w['nama_perusahaan']) ?></span>
    </div>
    <?php endif; ?>
    <div class="review-row">
      <span class="rk">Nama Pemilik</span>
      <span class="rv"><?= htmlspecialchars($w['owner_name'] ?? '-') ?></span>
    </div>
    <div class="review-row">
      <span class="rk">WhatsApp</span>
      <span class="rv"><?= htmlspecialchars($w['owner_wa'] ?? '-') ?></span>
    </div>
    <?php if (!empty($w['kota'])): ?>
    <div class="review-row">
      <span class="rk">Kota</span>
      <span class="rv"><?= htmlspecialchars($w['kota']) ?></span>
    </div>
    <?php endif; ?>
    <div class="review-section-header">Paket Dipilih</div>
    <div class="review-row">
      <span class="rk">Paket</span>
      <span class="rv"><?= htmlspecialchars($selPkg['nama']) ?></span>
    </div>
    <div class="review-row">
      <span class="rk">Setup Fee</span>
      <span class="rv" style="color:var(--teal)">
        <?= $selPkg['setup_fee'] === 0 ? 'Gratis' : 'Rp ' . number_format($selPkg['setup_fee'], 0, ',', '.') ?>
      </span>
    </div>
    <div class="review-row">
      <span class="rk">Coin Awal</span>
      <span class="rv"><?= number_format($selPkg['coin_awal'], 0, ',', '.') ?> coin</span>
    </div>
    <?php if ($selPkg['trial_days']): ?>
    <div class="review-row">
      <span class="rk">Trial</span>
      <span class="rv"><?= $selPkg['trial_days'] ?> hari</span>
    </div>
    <?php endif; ?>
  </div>

  <form method="POST" id="step3Form">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>"/>
    <input type="hidden" name="step" value="3"/>

    <label class="terms-wrap">
      <input type="checkbox" name="agree_terms" value="1" <?= !empty($w['agree_terms']) ? 'checked' : '' ?>/>
      <span class="terms-text">
        Saya setuju dengan <a href="#" target="_blank">Syarat & Ketentuan</a> dan
        <a href="#" target="_blank">Kebijakan Privasi</a> Harpy ERP.
        Tim Harpy akan menghubungi saya via WhatsApp untuk verifikasi akun.
      </span>
    </label>

    <div class="form-footer">
      <button type="submit" name="back" value="1" class="btn btn-outline">&#8592; Kembali</button>
      <button type="submit" class="btn btn-primary" id="submitBtn">
        &#128640; Daftar Sekarang
      </button>
    </div>
  </form>

  <!-- ══════════════════════════════════════════════
       STEP 4 — SUCCESS
  ══════════════════════════════════════════════ -->
  <?php elseif ($step === 4 && $success): ?>
  <div class="success-screen">
    <span class="success-icon">&#127881;</span>
    <div class="success-title">Pendaftaran Berhasil!</div>
    <div class="success-sub">
      Permintaan Anda sudah kami terima. Tim Harpy akan menghubungi Anda di nomor WhatsApp:
    </div>
    <div class="wa-number-box">
      &#128172; <?= htmlspecialchars($w['owner_wa'] ?? '-') ?>
    </div>

    <ul class="info-list">
      <li>
        <span class="info-icon">&#9203;</span>
        <span>Tim kami akan menghubungi Anda dalam <strong>1×24 jam</strong> untuk verifikasi dan aktivasi akun.</span>
      </li>
      <li>
        <span class="info-icon">&#128172;</span>
        <span>Setelah akun aktif, Anda akan mendapat username & password via WhatsApp untuk login ke dashboard.</span>
      </li>
      <li>
        <span class="info-icon">&#127381;</span>
        <span>Paket <strong><?= htmlspecialchars($packages[$w['paket'] ?? 'trial']['nama']) ?></strong> dengan <?= number_format($packages[$w['paket'] ?? 'trial']['coin_awal'], 0, ',', '.') ?> coin sudah disiapkan untuk outlet Anda.</span>
      </li>
    </ul>

    <div class="success-actions">
      <?php
        $waNum = preg_replace('/[^0-9]/', '', $w['owner_wa'] ?? '6281234567890');
        $waMsgEnc = urlencode("Halo Tim Harpy, saya sudah mendaftar atas nama *{$w['owner_name']}* (outlet: {$w['nama_outlet']}). Mohon proses pendaftaran saya. Terima kasih!");
      ?>
      <a href="https://wa.me/6281234567890?text=<?= $waMsgEnc ?>" target="_blank" class="btn btn-wa">
        &#128172; Chat Tim Harpy
      </a>
      <a href="landing.php" class="btn btn-home">&#8592; Kembali ke Beranda</a>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /.reg-card -->

<script>
// ── Package selection ─────────────────────────────
function selectPkg(label, val) {
  document.querySelectorAll('.pkg-option').forEach(el => el.classList.remove('selected'));
  label.classList.add('selected');
  const radio = label.querySelector('input[type=radio]');
  if (radio) radio.checked = true;
}

// ── Coin info accordion ───────────────────────────
function toggleCoinInfo() {
  const body   = document.getElementById('coinBody');
  const toggle = document.getElementById('coinToggle');
  if (!body || !toggle) return;
  const isOpen = body.classList.toggle('open');
  toggle.classList.toggle('open', isOpen);
}

// ── Prevent double submit ─────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  const forms = document.querySelectorAll('form');
  forms.forEach(function(form) {
    form.addEventListener('submit', function(e) {
      const submitBtn = form.querySelector('button[type=submit]:not([name=back])');
      if (!submitBtn) return;
      // If back button clicked, don't disable
      if (document.activeElement && document.activeElement.name === 'back') return;
      setTimeout(() => {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Memproses...';
      }, 50);
    });
  });
});
</script>
</body>
</html>
