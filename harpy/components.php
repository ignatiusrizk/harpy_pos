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

    $navGroups = [
        'dashboard' => [
            'label' => 'Dashboard',
            'items' => [
                'dashboard' => ['label'=>'Dashboard', 'url'=>'dashboard.php', 'roles'=>['owner','superadmin','admin','staff']],
            ],
        ],
        'operasional' => [
            'label' => 'Operasional',
            'items' => [
                'pos'    => ['label'=>'POS',   'url'=>'pos.php',    'roles'=>['owner','superadmin','admin','staff']],
                'orders' => ['label'=>'Order', 'url'=>'orders.php', 'roles'=>['owner','superadmin','admin','staff']],
                'kas'    => ['label'=>'Kas',   'url'=>'kas.php',    'roles'=>['owner','superadmin','admin']],
            ],
        ],
        'keuangan' => [
            'label' => 'Keuangan',
            'items' => [
                'laporan' => ['label'=>'Laporan', 'url'=>'laporan.php', 'roles'=>['owner','superadmin','admin']],
            ],
        ],
        'master' => [
            'label' => 'Master',
            'items' => [
                'layanan'  => ['label'=>'Layanan',  'url'=>'layanan.php',  'roles'=>['owner','superadmin','admin']],
                'promo'    => ['label'=>'Promo',    'url'=>'promo.php',    'roles'=>['owner','superadmin','admin']],
                'customer' => ['label'=>'Customer', 'url'=>'customer.php', 'roles'=>['owner','superadmin','admin','staff']],
            ],
        ],
        'hr' => [
            'label' => 'HR',
            'items' => [
                'karyawan' => ['label'=>'Karyawan', 'url'=>'karyawan.php', 'roles'=>['owner','superadmin','admin']],
                'absensi'  => ['label'=>'Absensi',  'url'=>'absensi.php',  'roles'=>['owner','superadmin','admin','staff']],
            ],
        ],
        'settings' => [
            'label' => 'Settings',
            'items' => [
                'settings' => ['label'=>'Role & Permission', 'url'=>'settings.php', 'roles'=>['superadmin']],
                'audit'    => ['label'=>'Audit Log',         'url'=>'audit.php',    'roles'=>['owner','superadmin','admin']],
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
