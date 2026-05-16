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
            ],
        ],
        'keuangan' => [
            'label' => 'Keuangan',
            'items' => [
                'laporan' => ['label'=>'Laporan', 'url'=>'laporan.php',
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
            ],
        ],
        'settings' => [
            'label' => 'Settings',
            'items' => [
                'settings' => ['label'=>'Role & Permission', 'url'=>'settings.php',
                               'roles'=>['owner','superadmin']],
                // Audit: manager BISA lihat (brief 6.3 manager view_audit ✅, manage tidak)
                'audit'    => ['label'=>'Audit Log',         'url'=>'audit.php',
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

    <?php if (!$minimalMode): ?>
    <!-- MOBILE DRAWER OVERLAY -->
    <div class="hl-nav-drawer-overlay" id="navOverlay" onclick="closeDrawer()"></div>

    <!-- MOBILE DRAWER -->
    <div class="hl-nav-drawer" id="navDrawer">
      <div class="hl-nav-drawer-header">
        <span class="hl-nav-drawer-brand"><img src="/ERP/harpy/assets/logo.png" alt="LAMASY" style="height:28px; vertical-align:middle; margin-right:8px;">LAMASY <span>by Harpy</span></span>
        <button class="hl-nav-drawer-close" onclick="closeDrawer()">✕</button>
      </div>
      <div class="hl-nav-drawer-user">
        <div class="hl-nav-drawer-user-info">
          <div class="hl-nav-drawer-user-nama"><?= htmlspecialchars($user['nama']) ?></div>
          <div class="hl-nav-drawer-user-role"><?= htmlspecialchars($tenant['nama_outlet'] ?? '') ?> · <?= strtoupper($user['role_nama'] ?? $user['role']) ?></div>
        </div>
      </div>
      <div class="hl-nav-drawer-body">
        <?php
        $drawerIcons = [
          'dashboard'=>'🏠','pos'=>'🛒','orders'=>'📋','kas'=>'💰',
          'laporan'=>'📊','layanan'=>'🧺','promo'=>'🎟️','customer'=>'👥',
          'karyawan'=>'👤','absensi'=>'📅','settings'=>'⚙️','audit'=>'🔍',
        ];
        foreach ($navGroups as $groupKey => $group):
          if (!groupVisible($group, $user['role'])) continue;
          $visibleItems = array_filter($group['items'], fn($i) => in_array($user['role'], $i['roles']));
          if (!$visibleItems) continue;
        ?>
        <div class="hl-nav-drawer-section"><?= $group['label'] ?></div>
        <?php foreach ($visibleItems as $key => $item): ?>
        <a href="<?= $item['url'] ?>"
           class="hl-nav-drawer-item <?= $activePage === $key ? 'active' : '' ?>">
          <?= $drawerIcons[$key] ?? '•' ?> <?= $item['label'] ?>
        </a>
        <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
      <div class="hl-nav-drawer-footer">
        <a href="logout.php" class="hl-nav-drawer-logout"
           onclick="return confirm('Yakin logout?')">🚪 Logout</a>
      </div>
    </div>
    <?php endif; // !$minimalMode — end drawer ?>

    <!-- TOPBAR -->
    <div class="hl-topbar">
      <div class="hl-topbar-left">
        <?php if (!$minimalMode): ?>
        <button class="hl-nav-hamburger" onclick="openDrawer()">☰</button>
        <?php endif; ?>
        <a href="dashboard.php" class="hl-brand"><img src="/ERP/harpy/assets/logo.png" alt="LAMASY" style="height:32px; vertical-align:middle; margin-right:8px;">LAMASY <span>by Harpy</span></a>
        <?php if (!$minimalMode): ?>
        <nav class="hl-nav">
          <?php foreach ($navGroups as $groupKey => $group):
            if (!groupVisible($group, $user['role'])) continue;
            $items        = $group['items'];
            $hasActive    = groupHasActive($group, $activePage);
            $isSingleItem = count(array_filter($items, fn($i) => in_array($user['role'], $i['roles']))) === 1;
            if ($isSingleItem):
              foreach ($items as $key => $item):
                if (!in_array($user['role'], $item['roles'])) continue;
              ?>
              <a href="<?= $item['url'] ?>"
                 class="hl-nav-link <?= $activePage === $key ? 'active' : '' ?>">
                <?= $item['label'] ?>
              </a>
              <?php endforeach;
            else: ?>
            <div class="hl-nav-group <?= $hasActive ? 'active' : '' ?>">
              <button class="hl-nav-group-btn <?= $hasActive ? 'active' : '' ?>">
                <?= $group['label'] ?> <span class="hl-nav-arrow">&#9660;</span>
              </button>
              <div class="hl-nav-dropdown">
                <?php foreach ($items as $key => $item):
                  if (!in_array($user['role'], $item['roles'])) continue;
                ?>
                <a href="<?= $item['url'] ?>"
                   class="hl-nav-dropdown-item <?= $activePage === $key ? 'active' : '' ?>">
                  <?= $item['label'] ?>
                </a>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </nav>
        <?php endif; ?>
      </div>
      <div class="hl-topbar-right">
        <?php
        // ── Trial countdown + Coin balance (hanya jika ada outlet aktif) ──
        if (!$minimalMode && TenantResolver::hasOutlet()):
          $isTrial   = TenantResolver::isTrial();
          $trialDays = $isTrial ? TenantResolver::trialDaysLeft() : 0;
          $coin      = TenantResolver::coinBalance();
          $isGrace   = TenantResolver::isGraceMode();
          $coinFmt   = number_format($coin, 0, ',', '.');
        ?>
          <?php if ($isTrial): ?>
          <div title="Trial outlet aktif"
               style="background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.3);
                      color:#F59E0B;font-size:12px;font-weight:700;padding:5px 11px;
                      border-radius:8px;display:flex;align-items:center;gap:5px;white-space:nowrap">
            ⏰ Trial: <?= $trialDays ?>h
          </div>
          <?php elseif ($isGrace): ?>
          <div title="Outlet dalam grace period"
               style="background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);
                      color:#EF4444;font-size:12px;font-weight:700;padding:5px 11px;
                      border-radius:8px;display:flex;align-items:center;gap:5px;white-space:nowrap">
            ⚠️ Grace: <?= TenantResolver::graceDaysLeft() ?>h
          </div>
          <?php endif; ?>
          <div title="Saldo coin"
               style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);
                      color:#fff;font-size:12px;font-weight:700;padding:5px 11px;
                      border-radius:8px;display:flex;align-items:center;gap:5px;white-space:nowrap">
            🪙 <?= $coinFmt ?><?= $isTrial ? ' <span style="font-size:10px;opacity:.6;font-weight:600">trial</span>' : '' ?>
          </div>
        <?php endif; ?>

        <?php
        // ── Outlet indicator + switcher (hanya jika ada outlet aktif) ──
        if (!$minimalMode && TenantResolver::hasOutlet()):
          $currentOutletId = TenantResolver::outletId();
          $currentOutletNm = TenantResolver::namaOutlet();

          $tdb = Database::get();
          $stmt = $tdb->prepare(
            "SELECT id, nama_outlet, status FROM outlets
             WHERE tenant_id = ? AND status IN ('trial','grace','active')
             ORDER BY is_main DESC, nama_outlet ASC"
          );
          $stmt->execute([TenantResolver::id()]);
          $allOutlets = $stmt->fetchAll();
          $hasMulti   = count($allOutlets) > 1;
        ?>
        <div class="hl-outlet-switch" style="position:relative">
          <button class="hl-outlet-btn" type="button"
                  onclick="this.nextElementSibling.classList.toggle('open')"
                  style="background:rgba(53,232,213,.1);border:1px solid rgba(53,232,213,.25);
                         color:#35E8D5;font-size:13px;font-weight:600;padding:6px 12px;
                         border-radius:8px;cursor:pointer;
                         display:flex;align-items:center;gap:6px;font-family:inherit">
            <span>📍</span>
            <span style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <?= htmlspecialchars($currentOutletNm) ?>
            </span>
            <span style="font-size:10px;opacity:.7">▼</span>
          </button>
          <div class="hl-outlet-dropdown" style="display:none;position:absolute;top:calc(100% + 6px);
                       right:0;background:#fff;border:1px solid #E5E7EB;border-radius:10px;
                       box-shadow:0 8px 24px rgba(0,0,0,.12);min-width:260px;z-index:1000;
                       padding:6px;max-height:380px;overflow-y:auto">
            <?php if ($hasMulti): ?>
            <div style="font-size:11px;color:#9CA3AF;font-weight:600;padding:8px 12px 4px;
                        text-transform:uppercase;letter-spacing:.05em">Pilih Outlet</div>
            <?php else: ?>
            <div style="font-size:11px;color:#9CA3AF;font-weight:600;padding:8px 12px 4px;
                        text-transform:uppercase;letter-spacing:.05em">Outlet Aktif</div>
            <?php endif; ?>
            <?php foreach ($allOutlets as $o):
              $isActive  = (int)$o['id'] === $currentOutletId;
              $statusBg  = $o['status'] === 'active' ? '#D1FAE5' : ($o['status'] === 'trial' ? '#DBEAFE' : '#FEF3C7');
              $statusFg  = $o['status'] === 'active' ? '#065F46' : ($o['status'] === 'trial' ? '#1E40AF' : '#92400E');
            ?>
            <a href="switch-outlet.php?id=<?= (int)$o['id'] ?>"
               style="display:flex;align-items:center;justify-content:space-between;gap:8px;
                      padding:8px 12px;border-radius:6px;text-decoration:none;
                      background:<?= $isActive ? '#F0FDFB' : 'transparent' ?>;
                      color:<?= $isActive ? '#0F1C3A' : '#374151' ?>;font-size:13px;
                      <?= $isActive ? 'font-weight:700' : '' ?>"
               onmouseover="if(this.dataset.active!=='1')this.style.background='#F9FAFB'"
               onmouseout ="if(this.dataset.active!=='1')this.style.background='transparent'"
               data-active="<?= $isActive ? '1' : '0' ?>">
              <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= $isActive ? '✓ ' : '' ?><?= htmlspecialchars($o['nama_outlet']) ?>
              </span>
              <span style="background:<?= $statusBg ?>;color:<?= $statusFg ?>;font-size:10px;
                           font-weight:700;padding:2px 7px;border-radius:100px;text-transform:uppercase">
                <?= $o['status'] ?>
              </span>
            </a>
            <?php endforeach; ?>
            <!-- Divider + Mode HQ + Tambah outlet -->
            <div style="border-top:1px solid #F3F4F6;margin:6px 0 4px"></div>
            <?php if (in_array($user['role'] ?? '', ['owner','manager','superadmin'], true)): ?>
            <a href="/ERP/harpy/hq/dashboard.php"
               style="display:flex;align-items:center;gap:8px;padding:9px 12px;border-radius:6px;
                      text-decoration:none;color:#0F1C3A;font-size:13px;font-weight:700;
                      background:linear-gradient(135deg,rgba(102,126,234,.08),rgba(118,75,162,.08))"
               onmouseover="this.style.background='linear-gradient(135deg,rgba(102,126,234,.15),rgba(118,75,162,.15))'"
               onmouseout ="this.style.background='linear-gradient(135deg,rgba(102,126,234,.08),rgba(118,75,162,.08))'">
              🏢 Mode HQ (konsolidasi semua outlet)
            </a>
            <?php endif; ?>
            <?php if (in_array($user['role'] ?? '', ['owner','superadmin'], true)): ?>
            <a href="add-outlet.php"
               style="display:flex;align-items:center;gap:8px;padding:9px 12px;border-radius:6px;
                      text-decoration:none;color:#0891B2;font-size:13px;font-weight:700"
               onmouseover="this.style.background='#F0F9FF'"
               onmouseout ="this.style.background='transparent'">
              <span style="font-size:16px;line-height:1">+</span> Tambah Outlet Baru
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

        <span class="hl-user-nama"><?= htmlspecialchars($user['nama']) ?></span>
        <?php if (!$minimalMode): ?>
        <span class="hl-user-role"><?= strtoupper($user['role_nama'] ?? $user['role']) ?></span>
        <?php endif; ?>
        <a href="logout.php" class="hl-btn-logout"
           onclick="return confirm('Yakin logout?')">Logout</a>
      </div>
    </div>

    <script>
    function openDrawer(){
      document.getElementById('navDrawer').classList.add('open');
      document.getElementById('navOverlay').classList.add('open');
      document.body.style.overflow='hidden';
    }
    function closeDrawer(){
      document.getElementById('navDrawer').classList.remove('open');
      document.getElementById('navOverlay').classList.remove('open');
      document.body.style.overflow='';
    }
    (function(){
      var groups=document.querySelectorAll('.hl-nav-group');
      groups.forEach(function(group){
        var btn=group.querySelector('.hl-nav-group-btn');
        var dropdown=group.querySelector('.hl-nav-dropdown');
        if(!btn||!dropdown)return;
        btn.addEventListener('click',function(e){
          e.stopPropagation();
          var isOpen=group.classList.contains('open');
          groups.forEach(function(g){g.classList.remove('open');});
          if(!isOpen){
            var rect=btn.getBoundingClientRect();
            dropdown.style.top=(rect.bottom+6)+'px';
            dropdown.style.left=rect.left+'px';
            group.classList.add('open');
          }
        });
      });
      document.addEventListener('click',function(){
        groups.forEach(function(g){g.classList.remove('open');});
      });
    })();
    </script>
    <?php
}

function renderToast(): void { ?>
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
