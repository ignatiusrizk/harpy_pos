<?php
// ══════════════════════════════════════════════════════
// components.php — UI Components Harpy Laundry ERP
//
// CARA PAKAI:
//   require_once 'components.php';
//   renderTopbar('pos');
//   renderToast();
//   renderFooter();
//
// Pastikan auth.php sudah di-include sebelum file ini.
// ══════════════════════════════════════════════════════

// ── TOPBAR ────────────────────────────────────────────
function renderTopbar(string $activePage = ''): void {
    $user = currentUser();
    if (!$user) return;

    // Definisi grup menu — tambah item baru di sini
    $navGroups = [
        'dashboard' => [
            'label' => 'Dashboard',
            'items' => [
                'dashboard' => ['label'=>'Dashboard', 'url'=>'dashboard.php', 'roles'=>['superadmin','admin','staff']],
            ],
        ],
        'operasional' => [
            'label' => 'Operasional',
            'items' => [
                'pos'    => ['label'=>'POS',   'url'=>'pos.php',    'roles'=>['superadmin','admin','staff']],
                'orders' => ['label'=>'Order', 'url'=>'orders.php', 'roles'=>['superadmin','admin','staff']],
                'kas'    => ['label'=>'Kas',   'url'=>'kas.php',    'roles'=>['superadmin','admin']],
            ],
        ],
        'keuangan' => [
            'label' => 'Keuangan',
            'items' => [
                'laporan' => ['label'=>'Laporan', 'url'=>'laporan.php', 'roles'=>['superadmin','admin']],
            ],
        ],
        'master' => [
            'label' => 'Master',
            'items' => [
                'layanan'  => ['label'=>'Layanan',  'url'=>'layanan.php',  'roles'=>['superadmin','admin']],
                'promo'    => ['label'=>'Promo',    'url'=>'promo.php',    'roles'=>['superadmin','admin']],
                'customer' => ['label'=>'Customer', 'url'=>'customer.php', 'roles'=>['superadmin','admin','staff']],
            ],
        ],
        'hr' => [
            'label' => 'HR',
            'items' => [
                'karyawan' => ['label'=>'Karyawan', 'url'=>'karyawan.php', 'roles'=>['superadmin','admin']],
                'absensi'  => ['label'=>'Absensi',  'url'=>'absensi.php',  'roles'=>['superadmin','admin','staff']],
            ],
        ],
        'settings' => [
            'label' => 'Settings',
            'items' => [
                'settings' => ['label'=>'Role & Permission', 'url'=>'settings.php', 'roles'=>['superadmin']],
                'audit'    => ['label'=>'Audit Log',         'url'=>'audit.php',    'roles'=>['superadmin','admin']],
            ],
        ],
    ];

    // Cek apakah group punya minimal 1 item yang boleh diakses user
    function groupVisible(array $group, string $role): bool {
        foreach ($group['items'] as $item) {
            if (in_array($role, $item['roles'])) return true;
        }
        return false;
    }

    // Cek apakah activePage ada di dalam group
    function groupHasActive(array $group, string $activePage): bool {
        return array_key_exists($activePage, $group['items']);
    }
    ?>
    <div class="hl-topbar">
      <div class="hl-topbar-left">
        <a href="pos.php" class="hl-brand">Harpy <span>Laundry</span></a>
        <nav class="hl-nav">
          <?php foreach ($navGroups as $groupKey => $group):
            if (!groupVisible($group, $user['role'])) continue;
            $items        = $group['items'];
            $hasActive    = groupHasActive($group, $activePage);
            $isSingleItem = count(array_filter($items, fn($i) => in_array($user['role'], $i['roles']))) === 1;

            // Kalau hanya 1 item visible dan itu link langsung (misal Laporan, Settings)
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
      </div>
      <div class="hl-topbar-right">
        <span class="hl-user-nama"><?= htmlspecialchars($user['nama']) ?></span>
        <span class="hl-user-role"><?= strtoupper($user['role_nama'] ?? $user['role']) ?></span>
        <a href="?logout=1" class="hl-btn-logout"
           onclick="return confirm('Yakin logout?')">Logout</a>
      </div>
    </div>
    <script>
    (function() {
      var groups = document.querySelectorAll('.hl-nav-group');
      groups.forEach(function(group) {
        var btn      = group.querySelector('.hl-nav-group-btn');
        var dropdown = group.querySelector('.hl-nav-dropdown');
        if (!btn || !dropdown) return;

        btn.addEventListener('click', function(e) {
          e.stopPropagation();
          var isOpen = group.classList.contains('open');
          // Tutup semua
          groups.forEach(function(g) { g.classList.remove('open'); });
          // Toggle yang diklik
          if (!isOpen) {
            var rect = btn.getBoundingClientRect();
            dropdown.style.top  = (rect.bottom + 6) + 'px';
            dropdown.style.left = rect.left + 'px';
            group.classList.add('open');
          }
        });
      });

      // Tutup semua jika klik di luar
      document.addEventListener('click', function() {
        groups.forEach(function(g) { g.classList.remove('open'); });
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
    </script>
    <?php
}

// ── HEAD ASSETS ───────────────────────────────────────
// Panggil di dalam <head> — inject font + CSS global
function renderHead(string $title = 'Harpy Laundry'): void { ?>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="csrf-token" content="<?= htmlspecialchars(getCsrfToken()) ?>"/>
    <title><?= htmlspecialchars($title) ?> — Harpy Laundry</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="harpy-erp.css"/>
    <?php
}

// ── STAT CARD ─────────────────────────────────────────
// $color: green | teal | red | navy | purple | yellow
function renderStatCard(string $id, string $label, string $color = 'teal', string $defaultValue = '-'): void { ?>
    <div class="hl-stat-card <?= $color ?>">
      <div class="hl-stat-num" id="<?= $id ?>"><?= $defaultValue ?></div>
      <div class="hl-stat-label"><?= $label ?></div>
    </div>
    <?php
}

// ── BADGE ─────────────────────────────────────────────
function renderBadge(string $text, string $type = 'gray'): string {
    return '<span class="hl-badge hl-badge-' . $type . '">' . htmlspecialchars($text) . '</span>';
}

// ── STATUS PROSES BADGE ───────────────────────────────
function statusProsesBadge(string $status): string {
    $map = [
        'masuk'   => ['Masuk',           'masuk'],
        'cuci'    => ['🫧 Cuci',          'cuci'],
        'kering'  => ['💨 Kering',        'kering'],
        'setrika' => ['👔 Setrika',       'setrika'],
        'siap'    => ['✅ Siap',           'siap'],
        'diambil' => ['📦 Diambil',       'diambil'],
    ];
    [$label, $type] = $map[$status] ?? [$status, 'gray'];
    return renderBadge($label, $type);
}

// ── STATUS BAYAR BADGE ────────────────────────────────
function statusBayarBadge(string $status): string {
    $map = [
        'lunas'       => ['✅ Lunas',       'lunas'],
        'dp'          => ['⚡ DP',           'dp'],
        'belum_bayar' => ['⏳ Belum Bayar', 'belum'],
    ];
    [$label, $type] = $map[$status] ?? [$status, 'gray'];
    return renderBadge($label, $type);
}

// ── FORMAT RUPIAH ─────────────────────────────────────
function formatRupiah(float $amount): string {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

// ── FORMAT TANGGAL ────────────────────────────────────
function formatTanggal(string $date, bool $withDay = false): string {
    if (!$date) return '-';
    $fmt = $withDay ? 'l, d M Y' : 'd M Y';
    return date($fmt, strtotime($date));
}