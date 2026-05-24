<?php
// ══════════════════════════════════════════════════════
// components.php — UI Components Harpy SaaS
// Pastikan tenant_guard.php sudah di-include sebelum file ini.
// ══════════════════════════════════════════════════════

function renderHead(string $title = 'Harpy'): void {
    $csrf = getCsrfToken(); ?>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>"/>
    <title><?= htmlspecialchars($title) ?> — LAMASY</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/ERP/harpy/harpy-erp.css?v=<?= date('Ymd') ?>">
    <?php
}

function renderTopbar(string $activePage = '', bool $minimalMode = false): void {
    $user   = currentUser();
    $tenant = currentTenant();
    if (!$user) return;

    // Menu per role — sesuai brief 'Akses Karyawan Saat Login' Section 6.3
    //
    // Role mapping:
    //   owner/superadmin → full akses
    //   manager/admin    → ops + analytics (tidak settings/audit/manage_karyawan)
    //   kasir            → POS focused (Dashboard, POS, Orders, Customer)
    //   staff            → produksi (Dashboard, Orders, Absensi)
    //   kurir            → delivery (Dashboard, Orders, Absensi)
    $navGroups = [
        'dashboard' => [
            'label' => 'Dashboard',
            'items' => [
                'dashboard' => ['label'=>'Dashboard', 'url'=>'dashboard.php',
                                'roles'=>['owner','superadmin','admin','manager','kasir','staff','kurir']],
            ],
        ],
        'operasional' => [
            'label' => 'Operasional',
            'items' => [
                'pos'    => ['label'=>'POS',   'url'=>'pos.php',
                             'roles'=>['owner','superadmin','admin','manager','kasir']],
                'orders' => ['label'=>'Order', 'url'=>'orders.php',
                             'roles'=>['owner','superadmin','admin','manager','kasir','staff','kurir']],
                'kas'    => ['label'=>'Kas',   'url'=>'kas.php',
                             'roles'=>['owner','superadmin','admin','manager']],
                'checklist' => ['label'=>'Checklist', 'url'=>'checklist.php',
                             'roles'=>['owner','superadmin','admin','manager','kasir','staff','kurir']],
            ],
        ],
        'keuangan' => [
            'label' => 'Keuangan',
            'items' => [
                'laporan' => ['label'=>'Laporan', 'url'=>'laporan.php',
                              'roles'=>['owner','superadmin','admin','manager']],
                'piutang' => ['label'=>'Piutang B2B', 'url'=>'piutang.php',
                              'roles'=>['owner','superadmin','admin','manager']],
            ],
        ],
        'master' => [
            'label' => 'Master',
            'items' => [
                'layanan'  => ['label'=>'Layanan',  'url'=>'layanan.php',
                               'roles'=>['owner','superadmin','admin','manager']],
                'promo'    => ['label'=>'Promo',    'url'=>'promo.php',
                               'roles'=>['owner','superadmin','admin','manager']],
                'customer' => ['label'=>'Customer', 'url'=>'customer.php',
                               'roles'=>['owner','superadmin','admin','manager','kasir']],
            ],
        ],
        'hr' => [
            'label' => 'HR',
            'items' => [
                'karyawan' => ['label'=>'Karyawan', 'url'=>'karyawan.php',
                               'roles'=>['owner','superadmin','admin','manager']],
                // Absensi: kasir NOT included per brief 6.3 (kasir clock via dashboard ringkas)
                'absensi'  => ['label'=>'Absensi',  'url'=>'absensi.php',
                               'roles'=>['owner','superadmin','admin','manager','staff','kurir']],
                'droppoint'=> ['label'=>'Drop Point', 'url'=>'droppoint_manager.php',
                               'roles'=>['owner','superadmin','admin','manager']],
            ],
        ],
        'settings' => [
            'label' => 'Settings',
            'items' => [
                'settings'     => ['label'=>'Role & Permission', 'url'=>'settings.php',
                                   'roles'=>['owner','superadmin']],
                // Audit: manager BISA lihat (brief 6.3 manager view_audit ✅, manage tidak)
                'audit'        => ['label'=>'Audit Log',         'url'=>'audit.php',
                                   'roles'=>['owner','superadmin','admin','manager']],
                'owner_report' => ['label'=>'Notifikasi Owner',  'url'=>'owner_report.php',
                                   'roles'=>['owner','superadmin','admin','manager']],
            ],
        ],
    ];

    function groupVisible(array $group, string $role): bool {
        foreach ($group['items'] as $item) {
            if (in_array($role, $item['roles'])) return true;
        }
        return false;
    }
    function groupHasActive(array $group, string $activePage): bool {
        return array_key_exists($activePage, $group['items']);
    }
    ?>

    <?php
    // ════════════════════════════════════════════════════════
    // Outlet Shell — sidebar + topbar tipis (Section 11.3)
    // ════════════════════════════════════════════════════════
    $outletNama = $tenant['nama_outlet'] ?? 'Outlet';
    $emphasisKeys = ['pos','orders']; // nav yang ditandai (POS/Order)
    $iconMap = [
      'dashboard'=>'🏠','pos'=>'🛒','orders'=>'📋','kas'=>'💰',
      'laporan'=>'📊','layanan'=>'🧺','promo'=>'🎟️','customer'=>'👥',
      'karyawan'=>'👤','absensi'=>'📅','settings'=>'⚙️','audit'=>'🔍',
      'checklist'=>'✅','droppoint'=>'📦','owner_report'=>'📨','piutang'=>'💼',
    ];
    ?>
    <div class="ol-shell" id="olShell">

      <!-- ── SIDEBAR ── -->
      <aside class="ol-side">
        <div class="ol-side-brand">
          <div class="ol-side-logo">LAMASY</div>
          <div class="ol-side-sub" title="<?= htmlspecialchars($outletNama) ?>">
            <?= htmlspecialchars($outletNama) ?>
          </div>
        </div>

        <?php if (!$minimalMode): ?>
        <nav class="ol-side-nav">
          <?php foreach ($navGroups as $groupKey => $group):
            if (!groupVisible($group, $user['role'])) continue;
            $visibleItems = array_filter($group['items'], fn($i) => in_array($user['role'], $i['roles']));
            if (!$visibleItems) continue;
          ?>
          <div class="ol-side-label"><?= htmlspecialchars($group['label']) ?></div>
          <?php foreach ($visibleItems as $key => $item):
            $isEmph = in_array($key, $emphasisKeys, true);
            $isActive = $activePage === $key;
          ?>
          <a href="<?= $item['url'] ?>"
             class="ol-side-link <?= $isEmph ? 'emphasis' : '' ?> <?= $isActive ? 'active' : '' ?>">
            <span class="ico"><?= $iconMap[$key] ?? '•' ?></span> <?= htmlspecialchars($item['label']) ?>
          </a>
          <?php endforeach; ?>
          <?php endforeach; ?>
        </nav>
        <?php endif; ?>
      </aside>

      <!-- ── MAIN AREA ── -->
      <div class="ol-main">
        <header class="ol-top">
          <div class="ol-top-left">
            <?php if (!$minimalMode): ?>
            <button class="ol-side-toggle" type="button"
                    onclick="document.getElementById('olShell').classList.toggle('open')">☰</button>
            <?php endif; ?>
            <span class="ol-top-badge">📍 OUTLET</span>
            <span class="ol-top-title"><?= htmlspecialchars($outletNama) ?></span>
          </div>
          <div class="ol-top-right">
            <?php
            // ── Trial / Grace / Coin chip ──
            if (!$minimalMode && TenantResolver::hasOutlet()):
              $isTrial   = TenantResolver::isTrial();
              $trialDays = $isTrial ? TenantResolver::trialDaysLeft() : 0;
              $coin      = TenantResolver::coinBalance();
              $isGrace   = TenantResolver::isGraceMode();
              $coinFmt   = number_format($coin, 0, ',', '.');
            ?>
              <?php if ($isTrial): ?>
                <span class="ol-top-chip warn" title="Trial outlet">⏰ Trial: <?= $trialDays ?>h</span>
              <?php elseif ($isGrace): ?>
                <span class="ol-top-chip danger" title="Grace period">⚠️ Grace: <?= TenantResolver::graceDaysLeft() ?>h</span>
              <?php endif; ?>
              <span class="ol-top-chip" title="Saldo coin">🪙 <?= $coinFmt ?></span>
            <?php endif; ?>

            <?php
            // ── Outlet switcher ──
            if (!$minimalMode && TenantResolver::hasOutlet()):
              $currentOutletId = TenantResolver::outletId();
              $currentOutletNm = TenantResolver::namaOutlet();
              $tdb  = Database::get();
              $stmt = $tdb->prepare(
                "SELECT id, nama_outlet, status FROM outlets
                 WHERE tenant_id = ? AND status IN ('trial','grace','active')
                 ORDER BY is_main DESC, nama_outlet ASC"
              );
              $stmt->execute([TenantResolver::id()]);
              $allOutlets = $stmt->fetchAll();
              $hasMulti = count($allOutlets) > 1;
            ?>
            <div class="hl-outlet-switch" style="position:relative">
              <button class="ol-top-chip" type="button"
                      onclick="this.nextElementSibling.classList.toggle('open')"
                      style="border:none;cursor:pointer;font-family:inherit">
                <span><?= htmlspecialchars($currentOutletNm) ?></span>
                <span style="font-size:9px;opacity:.6">▼</span>
              </button>
              <div class="hl-outlet-dropdown" style="display:none;position:absolute;top:calc(100% + 6px);
                           right:0;background:#fff;border:1px solid #D5DAE8;border-radius:10px;
                           box-shadow:0 8px 24px rgba(27,45,90,.12);min-width:240px;z-index:1000;
                           padding:6px;max-height:380px;overflow-y:auto">
                <div style="font-size:10px;color:var(--gray);font-weight:700;padding:8px 12px 4px;
                            text-transform:uppercase;letter-spacing:.06em">
                  <?= $hasMulti ? 'Pilih Outlet' : 'Outlet Aktif' ?>
                </div>
                <?php foreach ($allOutlets as $o):
                  $isActive = (int)$o['id'] === $currentOutletId;
                ?>
                <a href="switch-outlet.php?id=<?= (int)$o['id'] ?>"
                   style="display:block;padding:8px 12px;border-radius:6px;text-decoration:none;
                          color:<?= $isActive ? 'var(--navy)' : 'var(--dark)' ?>;font-size:13px;
                          font-weight:<?= $isActive ? '700' : '500' ?>;
                          background:<?= $isActive ? 'var(--teal-bg)' : 'transparent' ?>">
                  <?= $isActive ? '✓ ' : '' ?><?= htmlspecialchars($o['nama_outlet']) ?>
                  <span style="float:right;font-size:10px;color:var(--gray);text-transform:uppercase">
                    <?= $o['status'] ?>
                  </span>
                </a>
                <?php endforeach; ?>
                <div style="border-top:1px solid #F3F4F6;margin:6px 0 4px"></div>
                <?php if (in_array($user['role'] ?? '', ['owner','superadmin'], true)): ?>
                <a href="add-outlet.php"
                   style="display:block;padding:8px 12px;border-radius:6px;text-decoration:none;
                          color:var(--teal-d);font-size:13px;font-weight:700">
                  + Tambah Outlet Baru
                </a>
                <?php endif; ?>
              </div>
              <script>
              document.addEventListener('click',function(e){
                if(!e.target.closest('.hl-outlet-switch')){
                  document.querySelectorAll('.hl-outlet-dropdown.open').forEach(function(el){el.classList.remove('open')});
                }
              });
              </script>
              <style>.hl-outlet-dropdown.open{display:block!important}</style>
            </div>
            <?php endif; ?>

            <span class="ol-top-user"><?= htmlspecialchars($user['nama']) ?></span>
            <?php if (!$minimalMode && in_array($user['role'] ?? '', ['owner','manager','superadmin','admin'], true)): ?>
              <a href="/ERP/harpy/dashboard.php?to=hq" class="ol-top-switch"
                 title="Pindah ke HQ konsolidasi">HQ →</a>
            <?php endif; ?>
            <a href="logout.php" class="ol-top-logout"
               onclick="return confirm('Yakin logout?')">Logout</a>
          </div>
        </header>

        <main class="ol-content">
          <div class="ol-content-inner">
    <?php // Konten page mulai di sini — ditutup di renderToast(). ?>

    <?php
}

function renderToast(): void { ?>
          </div><!-- /.ol-content-inner -->
        </main><!-- /.ol-content -->
      </div><!-- /.ol-main -->
    </div><!-- /.ol-shell -->
    <div class="hl-toast" id="toast"></div>
    <script>
    function csrfToken(){return document.querySelector('meta[name="csrf-token"]')?.content||'';}
    function toggleFilter(id){
      var bar=document.getElementById(id),btn=document.getElementById(id+'Btn');
      if(!bar||!btn)return;
      var collapsed=bar.classList.toggle('collapsed');
      btn.classList.toggle('open',!collapsed);
      try{localStorage.setItem('hlFilter_'+id,collapsed?'0':'1');}catch(e){}
    }
    function initFilter(id,defaultOpen){
      var bar=document.getElementById(id),btn=document.getElementById(id+'Btn');
      if(!bar||!btn)return;
      var saved=null;
      try{saved=localStorage.getItem('hlFilter_'+id);}catch(e){}
      var open=saved!==null?saved==='1':(defaultOpen!==false);
      if(open){btn.classList.add('open');}else{bar.classList.add('collapsed');}
    }
    function showToast(msg,type='success'){
      const t=document.getElementById('toast');
      t.textContent=msg;t.className='hl-toast '+type+' show';
      setTimeout(()=>t.className='hl-toast',3500);
    }
    </script>
    <?php
}

function statusProsesBadge(string $status): string {
    $map = [
        'masuk'   => ['Masuk',       'masuk'],
        'cuci'    => ['🫧 Cuci',     'cuci'],
        'kering'  => ['💨 Kering',   'kering'],
        'setrika' => ['👔 Setrika',  'setrika'],
        'siap'    => ['✅ Siap',      'siap'],
        'diambil' => ['📦 Diambil',  'diambil'],
    ];
    [$label, $type] = $map[$status] ?? [$status, 'gray'];
    return '<span class="hl-badge hl-badge-' . $type . '">' . htmlspecialchars($label) . '</span>';
}

function statusBayarBadge(string $status): string {
    $map = [
        'lunas'       => ['✅ Lunas',      'lunas'],
        'dp'          => ['⚡ DP',          'dp'],
        'belum_bayar' => ['⏳ Belum Bayar','belum'],
    ];
    [$label, $type] = $map[$status] ?? [$status, 'gray'];
    return '<span class="hl-badge hl-badge-' . $type . '">' . htmlspecialchars($label) . '</span>';
}

function formatRupiah(float $amount): string {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function formatTanggal(string $date, bool $withDay = false): string {
    if (!$date) return '-';
    return date($withDay ? 'l, d M Y' : 'd M Y', strtotime($date));
}
