<?php
// ══════════════════════════════════════════════════════
// hq/_topbar.php — Shared HQ topbar partial
//
// Usage:
//   $activePage = 'hq-dashboard';  // atau hq-outlet, hq-karyawan, dst
//   require __DIR__ . '/_topbar.php';
//
// Group menu:
//   - 📊 Dashboard       (standalone)
//   - 🏪 Outlet          (standalone)
//   - 👥 Tim & Akses ▼   (Karyawan, Role)
//   - 🛍️ Pelanggan       ▼   (Pelanggan, Promo)
//   - 📈 Laporan         (standalone)
//   - ⚙️ Settings         (standalone)
// ══════════════════════════════════════════════════════

$_aPage     = $activePage ?? '';
$_ownerNama = $hqUser['nama'] ?? ($ownerNama ?? 'Owner');

// Tentukan kelompok aktif (untuk highlight dropdown parent)
$_inTim = in_array($_aPage, ['hq-karyawan','hq-roles'], true);
$_inCrm = in_array($_aPage, ['hq-pelanggan','hq-promo'], true);
?>
<style>
  .hq-topbar{background:#0F1C3A;color:#fff;padding:12px 24px;display:flex;justify-content:space-between;
             align-items:center;flex-wrap:wrap;gap:12px;box-shadow:0 1px 8px rgba(0,0,0,.15)}
  .hq-brand{display:flex;align-items:center;gap:10px;font-size:16px;font-weight:700;color:#35E8D5}
  .hq-brand-sub{color:rgba(255,255,255,.5);font-size:11px;font-weight:400;margin-left:4px}
  .hq-badge{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;font-size:10px;font-weight:800;
            padding:3px 10px;border-radius:100px;letter-spacing:.06em}
  .hq-nav{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:13px}
  .hq-nav .hq-link{color:rgba(255,255,255,.6);text-decoration:none;padding:7px 11px;border-radius:6px;
                   transition:all .15s;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;
                   background:transparent;border:none;font-family:inherit;font-size:13px;font-weight:400;cursor:pointer}
  .hq-nav button.hq-link{appearance:none;-webkit-appearance:none}
  .hq-nav .hq-link:hover{background:rgba(255,255,255,.08);color:#fff}
  .hq-nav .hq-link.active{background:rgba(53,232,213,.15);color:#35E8D5}
  .hq-group{position:relative}
  .hq-group-btn{cursor:pointer}
  .hq-group-btn .arr{font-size:9px;opacity:.7;margin-left:1px}
  .hq-dropdown{position:absolute;top:calc(100% + 6px);left:0;min-width:200px;background:#fff;
               border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.3);padding:6px;
               display:none;z-index:1000}
  .hq-group.open .hq-dropdown{display:block}
  .hq-dropdown a{display:flex;align-items:center;gap:8px;padding:9px 12px;color:#0F1C3A;
                 text-decoration:none;font-size:13px;border-radius:6px;transition:background .15s}
  .hq-dropdown a:hover{background:#F0FDFB;color:#0891B2}
  .hq-dropdown a.active{background:#F0FDFB;color:#0891B2;font-weight:700}
  .hq-dropdown a small{display:block;color:#9CA3AF;font-size:10px;font-weight:400;margin-top:1px}
  .hq-user{font-size:13px;color:rgba(255,255,255,.85);margin-left:6px}
  .hq-logout{border:1px solid rgba(255,255,255,.15);padding:6px 14px;color:rgba(255,255,255,.7);
             text-decoration:none;border-radius:6px;font-size:12px;font-weight:600;margin-left:4px}
  .hq-logout:hover{background:rgba(255,255,255,.08);color:#fff}
  .hq-back{color:rgba(255,255,255,.55);text-decoration:none;font-size:12px;padding:6px 10px;border-radius:6px;border:1px solid rgba(255,255,255,.1)}
  .hq-back:hover{background:rgba(255,255,255,.08);color:#fff}
  @media(max-width:900px){
    .hq-nav{order:3;width:100%;overflow-x:auto;justify-content:flex-start}
    .hq-dropdown{position:fixed;left:8px;right:8px;min-width:0}
  }
</style>

<div class="hq-topbar">
  <div class="hq-brand">
    <img src="/ERP/harpy/assets/logo.png" alt="LAMASY" style="height:28px">
    LAMASY <span class="hq-brand-sub">by Harpy</span>
    <span class="hq-badge">🏢 HQ</span>
  </div>

  <nav class="hq-nav">
    <a href="/ERP/harpy/dashboard.php?to=hq"
       class="hq-link <?= $_aPage === 'hq-dashboard' ? 'active' : '' ?>">📊 Dashboard</a>

    <a href="/ERP/harpy/hq/outlet.php"
       class="hq-link <?= $_aPage === 'hq-outlet' ? 'active' : '' ?>">🏪 Outlet</a>

    <!-- Tim & Akses dropdown -->
    <div class="hq-group">
      <button type="button"
              class="hq-link hq-group-btn <?= $_inTim ? 'active' : '' ?>"
              onclick="this.parentElement.classList.toggle('open')">
        👥 Tim & Akses <span class="arr">▼</span>
      </button>
      <div class="hq-dropdown">
        <a href="/ERP/harpy/hq/karyawan.php" class="<?= $_aPage === 'hq-karyawan' ? 'active' : '' ?>">
          👥 Karyawan
          <small style="margin-left:4px">·</small>
        </a>
        <a href="/ERP/harpy/hq/roles.php" class="<?= $_aPage === 'hq-roles' ? 'active' : '' ?>">
          🔐 Role & Akses
        </a>
      </div>
    </div>

    <!-- Pelanggan & Promo dropdown -->
    <div class="hq-group">
      <button type="button"
              class="hq-link hq-group-btn <?= $_inCrm ? 'active' : '' ?>"
              onclick="this.parentElement.classList.toggle('open')">
        🛍️ Pelanggan & Promo <span class="arr">▼</span>
      </button>
      <div class="hq-dropdown">
        <a href="/ERP/harpy/hq/pelanggan.php" class="<?= $_aPage === 'hq-pelanggan' ? 'active' : '' ?>">
          🧑‍🤝‍🧑 Database Pelanggan
        </a>
        <a href="/ERP/harpy/hq/promo.php" class="<?= $_aPage === 'hq-promo' ? 'active' : '' ?>">
          🎟️ Promo & Voucher
        </a>
      </div>
    </div>

    <a href="/ERP/harpy/hq/laporan.php"
       class="hq-link <?= $_aPage === 'hq-laporan' ? 'active' : '' ?>">📈 Laporan</a>

    <a href="/ERP/harpy/hq/settings.php"
       class="hq-link <?= $_aPage === 'hq-settings' ? 'active' : '' ?>">⚙️ Settings</a>
  </nav>

  <div style="display:flex;align-items:center;gap:8px">
    <span class="hq-user"><?= htmlspecialchars($_ownerNama) ?></span>
    <a href="/ERP/harpy/dashboard.php?to=outlet" class="hq-back" title="Kembali ke outlet view">← Outlet</a>
    <a href="/ERP/harpy/logout.php" class="hq-logout" onclick="return confirm('Yakin logout?')">Logout</a>
  </div>
</div>

<script>
// Tutup dropdown saat klik di luar
document.addEventListener('click', function(e){
  if (!e.target.closest('.hq-group')) {
    document.querySelectorAll('.hq-group.open').forEach(g => g.classList.remove('open'));
  }
});
</script>
