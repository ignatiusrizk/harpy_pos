<?php
// ══════════════════════════════════════════════════════
// superadmin/superadmin_components.php
// Shared head & nav for Super Admin Panel
// ══════════════════════════════════════════════════════

function saRenderHead(string $title = 'Super Admin'): void {
    $csrf = saGetCsrf(); ?>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>"/>
    <title><?= htmlspecialchars($title) ?> — LAMASY Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/ERP/harpy/harpy-erp.css?v=<?= date('Ymd') ?>">
    <style>
    :root {
      --sa: #6366F1;
      --sa-d: #4F46E5;
      --sa-l: rgba(99,102,241,.15);
      --sa-glow: rgba(99,102,241,.3);
      --navy: #1B2D5A;
      --navy-d: #0F1C3A;
      --navy-m: #162348;
      --white: #FFFFFF;
      --gray: #6C7A8D;
      --red: #EF4444;
      --green: #10B981;
      --yellow: #F59E0B;
      --font: 'Plus Jakarta Sans', sans-serif;
      --mono: 'DM Mono', monospace;
      --r: 10px;
      --sidebar: 220px;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; font-family: var(--font); background: var(--navy-d); color: var(--white); }

    /* ── Sidebar ─────────────────────────────── */
    .sa-layout { display: flex; min-height: 100vh; }

    .sa-sidebar {
      width: var(--sidebar);
      background: var(--navy-m);
      border-right: 1px solid rgba(255,255,255,.07);
      display: flex; flex-direction: column;
      position: fixed; top: 0; left: 0; height: 100vh;
      z-index: 100; transition: transform .25s;
    }
    .sa-sidebar-brand {
      padding: 20px 18px 16px;
      display: flex; align-items: center; gap: 10px;
      border-bottom: 1px solid rgba(255,255,255,.07);
    }
    .sa-sidebar-brand .logo-icon {
      width: 36px; height: 36px;
      background: linear-gradient(135deg, var(--sa), var(--sa-d));
      border-radius: 9px;
      display: flex; align-items: center; justify-content: center;
      font-size: 18px;
      box-shadow: 0 4px 12px var(--sa-glow);
      flex-shrink: 0;
    }
    .sa-sidebar-brand .brand-text { font-size: 13px; font-weight: 800; color: var(--white); letter-spacing: .02em; line-height: 1.2; }
    .sa-sidebar-brand .brand-text small { font-size: 9px; font-family: var(--mono); letter-spacing: .1em; color: var(--sa); text-transform: uppercase; display: block; }

    .sa-sidebar-nav { flex: 1; overflow-y: auto; padding: 12px 0; }
    .sa-nav-section { font-size: 9px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
      color: rgba(255,255,255,.25); padding: 14px 18px 4px; }
    .sa-nav-link {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 18px; font-size: 13.5px; font-weight: 500;
      color: rgba(255,255,255,.55); text-decoration: none;
      border-radius: 0; transition: all .15s;
      position: relative;
    }
    .sa-nav-link .icon { font-size: 16px; width: 20px; text-align: center; flex-shrink: 0; }
    .sa-nav-link:hover { color: var(--white); background: rgba(255,255,255,.05); }
    .sa-nav-link.active {
      color: var(--white);
      background: linear-gradient(90deg, var(--sa-l), transparent);
    }
    .sa-nav-link.active::before {
      content: '';
      position: absolute; left: 0; top: 0; bottom: 0;
      width: 3px; background: var(--sa); border-radius: 0 2px 2px 0;
    }

    .sa-sidebar-footer {
      padding: 12px 18px;
      border-top: 1px solid rgba(255,255,255,.07);
    }
    .sa-admin-info { font-size: 12px; color: rgba(255,255,255,.4); margin-bottom: 10px; }
    .sa-admin-info strong { display: block; color: rgba(255,255,255,.8); font-size: 13px; }
    .sa-logout-btn {
      display: block; width: 100%;
      padding: 8px 12px;
      background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.2);
      color: #FCA5A5; font-size: 12.5px; font-weight: 600;
      border-radius: var(--r); text-align: center; text-decoration: none;
      transition: all .15s; cursor: pointer;
    }
    .sa-logout-btn:hover { background: rgba(239,68,68,.2); color: #fff; }

    /* ── Main content ────────────────────────── */
    .sa-main {
      flex: 1;
      margin-left: var(--sidebar);
      min-height: 100vh;
      display: flex; flex-direction: column;
    }
    .sa-topbar {
      height: 56px;
      background: rgba(255,255,255,.025);
      border-bottom: 1px solid rgba(255,255,255,.07);
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 24px;
      position: sticky; top: 0; z-index: 50;
      backdrop-filter: blur(12px);
    }
    .sa-topbar-title { font-size: 15px; font-weight: 700; color: var(--white); }
    .sa-topbar-right { display: flex; align-items: center; gap: 12px; font-size: 13px; color: rgba(255,255,255,.5); }
    .sa-hamburger {
      display: none; background: none; border: none; color: var(--white);
      font-size: 22px; cursor: pointer; padding: 4px;
    }

    .sa-content { flex: 1; padding: 24px; }

    /* ── Cards & Stats ───────────────────────── */
    .sa-page-header { margin-bottom: 24px; }
    .sa-page-header h1 { font-size: 22px; font-weight: 800; color: var(--white); }
    .sa-page-header p { font-size: 13px; color: rgba(255,255,255,.4); margin-top: 4px; }

    .sa-stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
    .sa-stat-card {
      background: rgba(255,255,255,.04);
      border: 1px solid rgba(255,255,255,.08);
      border-radius: 14px; padding: 18px;
      position: relative; overflow: hidden;
      transition: transform .15s;
    }
    .sa-stat-card:hover { transform: translateY(-2px); }
    .sa-stat-card .label { font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: rgba(255,255,255,.4); margin-bottom: 8px; }
    .sa-stat-card .value { font-size: 26px; font-weight: 800; color: var(--white); font-family: var(--mono); }
    .sa-stat-card .sub { font-size: 11px; color: rgba(255,255,255,.35); margin-top: 4px; }
    .sa-stat-card .icon-bg {
      position: absolute; right: 14px; top: 14px;
      font-size: 28px; opacity: .15;
    }
    .sa-stat-card.indigo { border-color: rgba(99,102,241,.25); background: rgba(99,102,241,.07); }
    .sa-stat-card.green  { border-color: rgba(16,185,129,.25);  background: rgba(16,185,129,.07); }
    .sa-stat-card.yellow { border-color: rgba(245,158,11,.25);  background: rgba(245,158,11,.07); }
    .sa-stat-card.red    { border-color: rgba(239,68,68,.25);   background: rgba(239,68,68,.07); }
    .sa-stat-card.blue   { border-color: rgba(59,130,246,.25);  background: rgba(59,130,246,.07); }

    /* ── Tables ──────────────────────────────── */
    .sa-card {
      background: rgba(255,255,255,.03);
      border: 1px solid rgba(255,255,255,.08);
      border-radius: 14px; overflow: hidden;
      margin-bottom: 24px;
    }
    .sa-card-header {
      padding: 16px 20px;
      border-bottom: 1px solid rgba(255,255,255,.07);
      display: flex; align-items: center; justify-content: space-between;
    }
    .sa-card-header h3 { font-size: 14px; font-weight: 700; color: var(--white); }
    .sa-card-body { padding: 20px; }

    .sa-table-wrap { overflow-x: auto; }
    .sa-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .sa-table th {
      padding: 10px 14px; text-align: left;
      font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
      color: rgba(255,255,255,.35);
      border-bottom: 1px solid rgba(255,255,255,.07);
    }
    .sa-table td {
      padding: 12px 14px;
      border-bottom: 1px solid rgba(255,255,255,.04);
      color: rgba(255,255,255,.8);
      vertical-align: middle;
    }
    .sa-table tr:last-child td { border-bottom: none; }
    .sa-table tr:hover td { background: rgba(255,255,255,.025); }

    /* ── Badges ──────────────────────────────── */
    .sa-badge {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 3px 10px; border-radius: 20px;
      font-size: 11px; font-weight: 600; letter-spacing: .03em;
    }
    .sa-badge-active  { background: rgba(16,185,129,.15); color: #6EE7B7; border: 1px solid rgba(16,185,129,.25); }
    .sa-badge-trial   { background: rgba(59,130,246,.15); color: #93C5FD; border: 1px solid rgba(59,130,246,.25); }
    .sa-badge-suspended { background: rgba(239,68,68,.15); color: #FCA5A5; border: 1px solid rgba(239,68,68,.25); }
    .sa-badge-indigo  { background: rgba(99,102,241,.15); color: #A5B4FC; border: 1px solid rgba(99,102,241,.25); }
    .sa-badge-yellow  { background: rgba(245,158,11,.15); color: #FCD34D; border: 1px solid rgba(245,158,11,.25); }
    .sa-badge-red     { background: rgba(239,68,68,.15); color: #FCA5A5; border: 1px solid rgba(239,68,68,.25); }

    /* ── Buttons ─────────────────────────────── */
    .sa-btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 7px 14px; border-radius: var(--r);
      font-family: var(--font); font-size: 12.5px; font-weight: 600;
      border: none; cursor: pointer; text-decoration: none;
      transition: all .15s; white-space: nowrap;
    }
    .sa-btn-primary { background: linear-gradient(135deg, var(--sa), var(--sa-d)); color: var(--white); }
    .sa-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 14px var(--sa-glow); }
    .sa-btn-outline { background: transparent; border: 1.5px solid rgba(255,255,255,.15); color: rgba(255,255,255,.7); }
    .sa-btn-outline:hover { border-color: var(--sa); color: var(--white); background: var(--sa-l); }
    .sa-btn-sm { padding: 4px 10px; font-size: 11.5px; border-radius: 7px; }
    .sa-btn-danger { background: rgba(239,68,68,.15); border: 1px solid rgba(239,68,68,.25); color: #FCA5A5; }
    .sa-btn-danger:hover { background: rgba(239,68,68,.3); color: #fff; }
    .sa-btn-green { background: rgba(16,185,129,.15); border: 1px solid rgba(16,185,129,.25); color: #6EE7B7; }
    .sa-btn-green:hover { background: rgba(16,185,129,.3); color: #fff; }
    .sa-btn-wa { background: rgba(37,211,102,.15); border: 1px solid rgba(37,211,102,.25); color: #86efac; }
    .sa-btn-wa:hover { background: rgba(37,211,102,.3); color: #fff; }

    /* ── Filter bar ──────────────────────────── */
    .sa-filter-bar {
      display: flex; flex-wrap: wrap; gap: 10px;
      padding: 16px 20px;
      background: rgba(255,255,255,.02);
      border-bottom: 1px solid rgba(255,255,255,.07);
    }
    .sa-filter-bar input, .sa-filter-bar select {
      padding: 8px 12px;
      background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1);
      border-radius: var(--r); color: var(--white);
      font-family: var(--font); font-size: 13px; outline: none;
    }
    .sa-filter-bar input:focus, .sa-filter-bar select:focus {
      border-color: var(--sa); box-shadow: 0 0 0 2px rgba(99,102,241,.15);
    }
    .sa-filter-bar input::placeholder { color: rgba(255,255,255,.25); }
    .sa-filter-bar select option { background: var(--navy); color: var(--white); }

    /* ── Modals ──────────────────────────────── */
    .sa-modal-overlay {
      display: none; position: fixed; inset: 0; z-index: 1000;
      background: rgba(0,0,0,.6); backdrop-filter: blur(4px);
      align-items: center; justify-content: center;
    }
    .sa-modal-overlay.open { display: flex; }
    .sa-modal {
      background: #162348; border: 1px solid rgba(255,255,255,.1);
      border-radius: 16px; padding: 28px; width: 100%; max-width: 480px;
      margin: 20px; box-shadow: 0 24px 60px rgba(0,0,0,.5);
      animation: saModalIn .2s ease;
    }
    @keyframes saModalIn { from { opacity:0; transform: scale(.96) translateY(10px); } }
    .sa-modal h3 { font-size: 16px; font-weight: 800; color: var(--white); margin-bottom: 18px; }
    .sa-modal .form-group { display: flex; flex-direction: column; gap: 7px; margin-bottom: 16px; }
    .sa-modal label { font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: rgba(255,255,255,.45); }
    .sa-modal input, .sa-modal textarea, .sa-modal select {
      padding: 10px 14px;
      background: rgba(255,255,255,.06); border: 1.5px solid rgba(255,255,255,.1);
      border-radius: var(--r); color: var(--white);
      font-family: var(--font); font-size: 14px; outline: none;
      transition: border-color .15s;
    }
    .sa-modal input:focus, .sa-modal textarea:focus, .sa-modal select:focus {
      border-color: var(--sa); box-shadow: 0 0 0 3px rgba(99,102,241,.12);
    }
    .sa-modal textarea { resize: vertical; min-height: 90px; }
    .sa-modal select option { background: var(--navy); }
    .sa-modal-footer { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }

    /* ── Toast ───────────────────────────────── */
    .sa-toast {
      position: fixed; bottom: 24px; right: 24px; z-index: 9999;
      padding: 12px 20px; border-radius: var(--r);
      font-size: 13.5px; font-weight: 600;
      opacity: 0; transform: translateY(12px);
      transition: all .25s; pointer-events: none;
      max-width: 360px;
    }
    .sa-toast.show { opacity: 1; transform: translateY(0); }
    .sa-toast.success { background: rgba(16,185,129,.9); color: #fff; }
    .sa-toast.error   { background: rgba(239,68,68,.9); color: #fff; }
    .sa-toast.info    { background: rgba(99,102,241,.9); color: #fff; }

    /* ── Alert list ──────────────────────────── */
    .sa-alert-item {
      display: flex; align-items: center; gap: 12px;
      padding: 12px 16px; border-radius: 10px;
      background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.07);
      margin-bottom: 8px; font-size: 13px;
    }
    .sa-alert-item .alert-icon { font-size: 20px; flex-shrink: 0; }
    .sa-alert-item .alert-text { flex: 1; color: rgba(255,255,255,.75); }
    .sa-alert-item .alert-action { font-size: 12px; }

    /* ── Tabs ────────────────────────────────── */
    .sa-tabs { display: flex; gap: 4px; border-bottom: 1px solid rgba(255,255,255,.07); margin-bottom: 24px; overflow-x: auto; }
    .sa-tab {
      padding: 10px 16px; font-size: 13px; font-weight: 600;
      color: rgba(255,255,255,.4); border: none; background: none;
      cursor: pointer; white-space: nowrap; border-bottom: 2px solid transparent;
      transition: all .15s; margin-bottom: -1px;
    }
    .sa-tab:hover { color: var(--white); }
    .sa-tab.active { color: var(--sa); border-bottom-color: var(--sa); }

    .sa-tab-panel { display: none; }
    .sa-tab-panel.active { display: block; }

    /* ── Grid helpers ────────────────────────── */
    .sa-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .sa-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
    .sa-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }

    /* ── Pagination ──────────────────────────── */
    .sa-pagination { display: flex; align-items: center; gap: 6px; padding: 16px 20px; justify-content: flex-end; }
    .sa-pagination .sa-btn-sm.disabled { opacity: .4; pointer-events: none; }

    /* ── Onboarding steps ────────────────────── */
    .step-done { color: #6EE7B7; font-size: 16px; }
    .step-fail { color: #FCA5A5; font-size: 16px; }

    /* ── Coin color ──────────────────────────── */
    .coin-kritis { color: #FCA5A5; font-weight: 700; }
    .coin-rendah  { color: #FCD34D; font-weight: 600; }
    .coin-ok      { color: #6EE7B7; }

    /* ── Risk badges ─────────────────────────── */
    .sa-risk-badge {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 2px 8px; border-radius: 20px;
      font-size: 10.5px; font-weight: 600; margin: 2px;
    }
    .risk-tidak-login { background: rgba(239,68,68,.15); color: #FCA5A5; border: 1px solid rgba(239,68,68,.2); }
    .risk-coin        { background: rgba(245,158,11,.15); color: #FCD34D; border: 1px solid rgba(245,158,11,.2); }
    .risk-trial       { background: rgba(99,102,241,.15); color: #A5B4FC; border: 1px solid rgba(99,102,241,.2); }
    .risk-no-topup    { background: rgba(107,114,128,.15); color: #D1D5DB; border: 1px solid rgba(107,114,128,.2); }
    .risk-order-turun { background: rgba(239,68,68,.12); color: #FCA5A5; border: 1px solid rgba(239,68,68,.2); }

    /* ── Mobile ──────────────────────────────── */
    @media (max-width: 900px) {
      .sa-sidebar { transform: translateX(-100%); }
      .sa-sidebar.open { transform: translateX(0); }
      .sa-main { margin-left: 0; }
      .sa-hamburger { display: flex; }
      .sa-content { padding: 16px; }
      .sa-stats-grid { grid-template-columns: repeat(2, 1fr); }
      .sa-grid-2, .sa-grid-3, .sa-grid-4 { grid-template-columns: 1fr; }
    }
    @media (max-width: 480px) {
      .sa-stats-grid { grid-template-columns: 1fr; }
    }

    .sa-overlay-mobile {
      display: none; position: fixed; inset: 0;
      background: rgba(0,0,0,.5); z-index: 99;
    }
    .sa-overlay-mobile.open { display: block; }
    </style>
    <?php
}

function saRenderNav(string $activePage = '', string $pageTitle = ''): void {
    $admin = saCurrentAdmin();
    ?>
    <div class="sa-overlay-mobile" id="saOverlay" onclick="saCloseNav()"></div>

    <aside class="sa-sidebar" id="saSidebar">
      <div class="sa-sidebar-brand">
        <img src="/ERP/harpy/assets/logo.png" alt="LAMASY" style="height:28px; flex-shrink:0;">
        <div class="brand-text">
          LAMASY <span style="color:var(--sa)">Admin</span>
          <small>Super Admin Panel</small>
        </div>
      </div>

      <nav class="sa-sidebar-nav">
        <div class="sa-nav-section">Platform</div>
        <a href="/ERP/harpy/superadmin/dashboard.php" class="sa-nav-link <?= $activePage === 'dashboard' ? 'active' : '' ?>">
          <span class="icon">🏠</span> Dashboard
        </a>
        <a href="/ERP/harpy/superadmin/clients.php" class="sa-nav-link <?= $activePage === 'clients' ? 'active' : '' ?>">
          <span class="icon">🏪</span> Clients
        </a>

        <div class="sa-nav-section">CS & Growth</div>
        <a href="/ERP/harpy/superadmin/registrations.php" class="sa-nav-link <?= $activePage === 'registrations' ? 'active' : '' ?>">
          <span class="icon">&#x1F4DD;</span> Registrasi
        </a>
        <a href="/ERP/harpy/superadmin/onboarding.php" class="sa-nav-link <?= $activePage === 'onboarding' ? 'active' : '' ?>">
          <span class="icon">&#x1F680;</span> Onboarding
        </a>
        <a href="/ERP/harpy/superadmin/churn_risk.php" class="sa-nav-link <?= $activePage === 'churn_risk' ? 'active' : '' ?>">
          <span class="icon">⚠️</span> Churn Risk
        </a>
        <a href="/ERP/harpy/superadmin/broadcast.php" class="sa-nav-link <?= $activePage === 'broadcast' ? 'active' : '' ?>">
          <span class="icon">📣</span> Broadcast
        </a>

        <div class="sa-nav-section">Finance</div>
        <a href="/ERP/harpy/superadmin/billing.php" class="sa-nav-link <?= $activePage === 'billing' ? 'active' : '' ?>">
          <span class="icon">💳</span> Billing
        </a>
      </nav>

      <div class="sa-sidebar-footer">
        <div class="sa-admin-info">
          <strong><?= htmlspecialchars($admin['name'] ?? 'Admin') ?></strong>
          <?= htmlspecialchars($admin['username'] ?? '') ?>
        </div>
        <a href="/ERP/harpy/superadmin/logout.php" class="sa-logout-btn"
           onclick="return confirm('Yakin logout?')">🚪 Logout</a>
      </div>
    </aside>

    <div class="sa-main">
      <div class="sa-topbar">
        <div style="display:flex;align-items:center;gap:12px;">
          <button class="sa-hamburger" onclick="saOpenNav()">☰</button>
          <span class="sa-topbar-title"><?= htmlspecialchars($pageTitle) ?></span>
        </div>
        <div class="sa-topbar-right">
          <span><?= htmlspecialchars($admin['name'] ?? '') ?></span>
          <a href="/ERP/harpy/superadmin/logout.php" style="color:#FCA5A5;font-size:12px;text-decoration:none;"
             onclick="return confirm('Yakin logout?')">Logout</a>
        </div>
      </div>
      <div class="sa-content">
    <?php
}

function saRenderNavClose(): void { ?>
      </div><!-- /.sa-content -->
    </div><!-- /.sa-main -->

    <div class="sa-toast" id="saToast"></div>
    <script>
    function saCsrf(){ return document.querySelector('meta[name="csrf-token"]')?.content||''; }
    function saShowToast(msg, type='success'){
      const t=document.getElementById('saToast');
      t.textContent=msg; t.className='sa-toast '+type+' show';
      setTimeout(()=>{t.className='sa-toast';},3500);
    }
    function saOpenNav(){
      document.getElementById('saSidebar').classList.add('open');
      document.getElementById('saOverlay').classList.add('open');
      document.body.style.overflow='hidden';
    }
    function saCloseNav(){
      document.getElementById('saSidebar').classList.remove('open');
      document.getElementById('saOverlay').classList.remove('open');
      document.body.style.overflow='';
    }
    function saFetch(url, opts={}){
      return fetch(url, {
        headers: { 'X-CSRF-Token': saCsrf(), 'X-Requested-With': 'XMLHttpRequest', ...(opts.headers||{}) },
        ...opts
      });
    }
    function saPost(url, data){
      const fd = new FormData();
      fd.append('_csrf', saCsrf());
      Object.entries(data).forEach(([k,v])=>fd.append(k,v));
      return fetch(url, { method:'POST', body: fd,
        headers:{ 'X-Requested-With': 'XMLHttpRequest' }
      });
    }
    </script>
    <?php
}
