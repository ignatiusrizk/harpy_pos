<?php
// ══════════════════════════════════════════════════════
// hq/_layout_open.php — HQ shell opener
//
// Usage di setiap HQ page:
//   $activePage = 'hq-dashboard';
//   $pageTitle  = 'Dashboard Eksekutif';
//   require __DIR__ . '/_layout_open.php';
//   // ... isi konten ...
//   require __DIR__ . '/_layout_close.php';
// ══════════════════════════════════════════════════════

$_aPage      = $activePage ?? '';
$_pageTitle  = $pageTitle ?? 'HQ';
$_ownerNama  = $hqUser['nama'] ?? ($ownerNama ?? 'Owner');
$_tenantNama = $hqTenant['nama_perusahaan'] ?? ($hqTenant['nama_outlet'] ?? 'Kantor Pusat');

// Group active state
$_inTim = in_array($_aPage, ['hq-karyawan','hq-mutasi','hq-sdm','hq-penggajian','hq-roles'], true);
$_inCrm = in_array($_aPage, ['hq-pelanggan','hq-promo'], true);

// Switch button visibility (owner & manager only)
$_canSwitch = !empty($hqIsOwner) || !empty($hqIsManager);
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($_pageTitle) ?> · LaMaSy HQ</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/ERP/harpy/harpy-erp.css?v=<?= @filemtime(dirname(__DIR__).'/harpy-erp.css') ?: date('Ymd') ?>">
  <link rel="stylesheet" href="/ERP/harpy/harpy-hq.css?v=<?= @filemtime(dirname(__DIR__).'/harpy-hq.css') ?: date('Ymd') ?>">
  <?php if (function_exists('getCsrfToken')): ?>
  <meta name="csrf-token" content="<?= htmlspecialchars(getCsrfToken()) ?>">
  <?php endif; ?>
</head>
<body>

<div class="hq-shell" id="hqShell">
  <div class="hq-shell-backdrop" onclick="document.getElementById('hqShell').classList.remove('open')"></div>

  <!-- ── SIDEBAR ── -->
  <aside class="hq-side">
    <div class="hq-side-brand">
      <div class="hq-side-logo">LAMASY</div>
      <div class="hq-side-sub" title="<?= htmlspecialchars($_tenantNama) ?>">
        <?= htmlspecialchars($_tenantNama) ?>
      </div>
    </div>

    <nav class="hq-side-nav">
      <div class="hq-side-label">Eksekutif</div>

      <a href="/ERP/harpy/dashboard.php?to=hq"
         class="hq-side-link <?= $_aPage === 'hq-dashboard' ? 'active' : '' ?>">
        <span class="ico">📊</span> Dashboard
      </a>
      <a href="/ERP/harpy/hq/outlet.php"
         class="hq-side-link <?= $_aPage === 'hq-outlet' ? 'active' : '' ?>">
        <span class="ico">🏪</span> Outlet
      </a>
      <a href="/ERP/harpy/hq/droppoint.php"
         class="hq-side-link <?= $_aPage === 'hq-droppoint' ? 'active' : '' ?>">
        <span class="ico">📦</span> Drop Point
      </a>
      <a href="/ERP/harpy/hq/layanan.php"
         class="hq-side-link <?= $_aPage === 'hq-layanan' ? 'active' : '' ?>">
        <span class="ico">🧺</span> Layanan & Harga
      </a>

      <div class="hq-side-divider"></div>
      <div class="hq-side-label">Tim & Pelanggan</div>

      <!-- Tim & Akses group -->
      <div class="hq-side-group <?= $_inTim ? 'open' : '' ?>">
        <button type="button" class="hq-side-link hq-side-group-btn <?= $_inTim ? 'active' : '' ?>"
                onclick="this.parentElement.classList.toggle('open')">
          <span class="ico">👥</span> Tim & Akses
          <span class="arr">▼</span>
        </button>
        <div class="hq-side-submenu">
          <a href="/ERP/harpy/hq/karyawan.php"
             class="hq-side-link <?= $_aPage === 'hq-karyawan' ? 'active' : '' ?>">Karyawan</a>
          <a href="/ERP/harpy/hq/mutasi.php"
             class="hq-side-link <?= $_aPage === 'hq-mutasi' ? 'active' : '' ?>">Riwayat Mutasi</a>
          <a href="/ERP/harpy/hq/sdm.php"
             class="hq-side-link <?= $_aPage === 'hq-sdm' ? 'active' : '' ?>">SDM Analytics</a>
          <a href="/ERP/harpy/hq/penggajian.php"
             class="hq-side-link <?= $_aPage === 'hq-penggajian' ? 'active' : '' ?>">Penggajian</a>
          <a href="/ERP/harpy/hq/roles.php"
             class="hq-side-link <?= $_aPage === 'hq-roles' ? 'active' : '' ?>">Role & Akses</a>
        </div>
      </div>

      <!-- Pelanggan & Promo group -->
      <div class="hq-side-group <?= $_inCrm ? 'open' : '' ?>">
        <button type="button" class="hq-side-link hq-side-group-btn <?= $_inCrm ? 'active' : '' ?>"
                onclick="this.parentElement.classList.toggle('open')">
          <span class="ico">🛍️</span> CRM
          <span class="arr">▼</span>
        </button>
        <div class="hq-side-submenu">
          <a href="/ERP/harpy/hq/pelanggan.php"
             class="hq-side-link <?= $_aPage === 'hq-pelanggan' ? 'active' : '' ?>">Pelanggan</a>
          <a href="/ERP/harpy/hq/promo.php"
             class="hq-side-link <?= $_aPage === 'hq-promo' ? 'active' : '' ?>">Promo & Voucher</a>
        </div>
      </div>

      <div class="hq-side-divider"></div>
      <div class="hq-side-label">Analitik</div>

      <a href="/ERP/harpy/hq/laporan.php"
         class="hq-side-link <?= $_aPage === 'hq-laporan' ? 'active' : '' ?>">
        <span class="ico">📈</span> Laporan
      </a>
      <a href="/ERP/harpy/hq/billing.php"
         class="hq-side-link <?= $_aPage === 'hq-billing' ? 'active' : '' ?>">
        <span class="ico">💳</span> Coin & Billing
      </a>
      <a href="/ERP/harpy/hq/checklist.php"
         class="hq-side-link <?= $_aPage === 'hq-checklist' ? 'active' : '' ?>">
        <span class="ico">✅</span> Checklist
      </a>
      <a href="/ERP/harpy/hq/broadcast.php"
         class="hq-side-link <?= $_aPage === 'hq-broadcast' ? 'active' : '' ?>">
        <span class="ico">📢</span> Broadcast
      </a>
      <a href="/ERP/harpy/hq/audit.php"
         class="hq-side-link <?= $_aPage === 'hq-audit' ? 'active' : '' ?>">
        <span class="ico">📋</span> Audit
      </a>

      <div class="hq-side-divider"></div>
      <div class="hq-side-label">AI Tools</div>

      <a href="/ERP/harpy/hq/ai-chat.php"
         class="hq-side-link <?= $_aPage === 'hq-ai-chat' ? 'active' : '' ?>">
        <span class="ico">✨</span> AI Chat
      </a>
      <a href="/ERP/harpy/hq/ai-churning.php"
         class="hq-side-link <?= $_aPage === 'hq-ai-churning' ? 'active' : '' ?>">
        <span class="ico">🎯</span> Smart Notif
      </a>

      <div class="hq-side-divider"></div>

      <a href="/ERP/harpy/hq/settings.php"
         class="hq-side-link <?= $_aPage === 'hq-settings' ? 'active' : '' ?>">
        <span class="ico">⚙️</span> Settings
      </a>
    </nav>
  </aside>

  <!-- ── MAIN AREA ── -->
  <div class="hq-main">
    <!-- Topbar -->
    <div class="hq-top">
      <div class="hq-top-left">
        <button type="button" class="hq-side-toggle"
                onclick="document.getElementById('hqShell').classList.toggle('open')">☰</button>
        <span class="hq-top-badge">🏢 HQ</span>
        <span class="hq-top-title"><?= htmlspecialchars($_pageTitle) ?></span>
      </div>
      <div class="hq-top-right">
        <span class="hq-top-user"><?= htmlspecialchars($_ownerNama) ?></span>
        <?php if ($_canSwitch): ?>
          <a href="/ERP/harpy/dashboard.php?to=outlet" class="hq-top-switch" title="Pindah ke Outlet View">Ke Outlet →</a>
        <?php endif; ?>
        <a href="/ERP/harpy/logout.php" class="hq-top-logout" onclick="return confirm('Yakin logout?')">Logout</a>
      </div>
    </div>

    <!-- Content area starts -->
    <main class="hq-content">
      <div class="hq-content-inner">
