<?php
// ══════════════════════════════════════════════════════
// hq/dashboard.php — HQ Dashboard (konsolidasi lintas outlet)
// Sesuai brief HQ-Outlet Section 4.1
// ══════════════════════════════════════════════════════

$activePage = 'hq-dashboard';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

$db    = Database::get();
$tid   = (int)$hqTenant['id'];
$today = date('Y-m-d');
$thisMonth = date('Y-m');

// ── Konsolidasi metrics hari ini ──────────────────────
$todayStats = $db->prepare("
    SELECT
      COALESCE(SUM(total), 0) AS omset_today,
      COUNT(*) AS order_today,
      COALESCE(SUM(CASE WHEN status_bayar='lunas' THEN total ELSE 0 END), 0) AS terkumpul
    FROM hl_transaksi
    WHERE tenant_id = ? AND DATE(tanggal) = ?
");
$todayStats->execute([$tid, $today]);
$todayRow = $todayStats->fetch() ?: ['omset_today'=>0,'order_today'=>0,'terkumpul'=>0];

// ── Outlet aktif + karyawan + pelanggan total ─────────
$outletCnt = (int)$db->query("SELECT COUNT(*) FROM outlets WHERE tenant_id=$tid AND status IN ('trial','grace','active')")->fetchColumn();

$pelangganCnt = 0;
try {
    $pelangganCnt = (int)$db->query("SELECT COUNT(*) FROM hl_pelanggan WHERE tenant_id=$tid AND is_active=1")->fetchColumn();
} catch (Throwable $e) {
    error_log('[hq pelangganCnt] ' . $e->getMessage());
}

$karyawanCnt = 0;
try {
    $karyawanCnt = (int)$db->query("SELECT COUNT(DISTINCT karyawan_id) FROM hl_karyawan_outlet WHERE tenant_id=$tid AND is_active=1")->fetchColumn();
} catch (Throwable) {
    try {
        $karyawanCnt = (int)$db->query("SELECT COUNT(*) FROM hl_users WHERE tenant_id=$tid AND is_active=1")->fetchColumn();
    } catch (Throwable $e) {
        error_log('[hq karyawanCnt fallback] ' . $e->getMessage());
    }
}

// ── Per-outlet detail ─────────────────────────────────
// Pisah jadi 2 step utk hindari collation mismatch saat subquery JOIN.
// Step 1: ambil daftar outlet
// Step 2: untuk tiap outlet, hitung omset/order/karyawan terpisah
$outletsStmt = $db->prepare(
    "SELECT * FROM outlets
      WHERE tenant_id = ? AND status IN ('trial','grace','active')
      ORDER BY is_main DESC, nama_outlet ASC"
);
$outletsStmt->execute([$tid]);
$outlets = $outletsStmt->fetchAll();

foreach ($outlets as &$o) {
    $oid = (int)$o['id'];

    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(total),0) AS s, COUNT(*) AS c
                                FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal)=?");
        $stmt->execute([$tid, $oid, $today]);
        $r = $stmt->fetch();
        $o['omset_today'] = (int)$r['s'];
        $o['order_today'] = (int)$r['c'];
    } catch (Throwable) {
        $o['omset_today'] = 0; $o['order_today'] = 0;
    }

    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(total),0) AS s FROM hl_transaksi
                              WHERE tenant_id=? AND outlet_id=? AND DATE_FORMAT(tanggal,'%Y-%m')=?");
        $stmt->execute([$tid, $oid, $thisMonth]);
        $o['omset_month'] = (int)$stmt->fetchColumn();
    } catch (Throwable) {
        $o['omset_month'] = 0;
    }

    try {
        $stmt = $db->prepare("SELECT COUNT(DISTINCT karyawan_id) FROM hl_karyawan_outlet
                              WHERE tenant_id=? AND outlet_id=? AND is_active=1");
        $stmt->execute([$tid, $oid]);
        $o['karyawan_count'] = (int)$stmt->fetchColumn();
    } catch (Throwable) {
        // Fallback: hl_karyawan_outlet belum ada
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM hl_users WHERE tenant_id=? AND outlet_id=? AND is_active=1");
            $stmt->execute([$tid, $oid]);
            $o['karyawan_count'] = (int)$stmt->fetchColumn();
        } catch (Throwable) {
            $o['karyawan_count'] = 0;
        }
    }
}
unset($o);

// ── Alert: trial mau habis, coin tipis ────────────────
$alerts = [];
foreach ($outlets as $o) {
    if ($o['status'] === 'trial' && !empty($o['trial_ends_at'])) {
        $daysLeft = (int)floor((strtotime($o['trial_ends_at']) - time()) / 86400);
        if ($daysLeft <= 3) {
            $alerts[] = [
                'level' => 'warning',
                'msg'   => "⏰ Trial outlet <strong>{$o['nama_outlet']}</strong> berakhir dalam <strong>$daysLeft hari</strong>",
            ];
        }
    }
    if ($o['status'] === 'grace') {
        $alerts[] = [
            'level' => 'danger',
            'msg'   => "⚠️ Outlet <strong>{$o['nama_outlet']}</strong> dalam grace period — segera aktivasi",
        ];
    }
    if ((int)($o['trial_coin_balance'] ?? 0) < 200 && $o['status'] === 'trial') {
        $alerts[] = [
            'level' => 'info',
            'msg'   => "🪙 Coin trial outlet <strong>{$o['nama_outlet']}</strong> tinggal " . number_format((int)$o['trial_coin_balance']),
        ];
    }
}

$tenantCoin = (int)($hqTenant['coin_balance'] ?? 0);
$ownerNama  = $hqUser['nama'] ?? 'Owner';
$tenantNama = $hqTenant['nama_outlet'] ?? 'HQ';

$greeting = (date('H') < 11 ? 'Selamat pagi' : (date('H') < 15 ? 'Selamat siang' : (date('H') < 19 ? 'Selamat sore' : 'Selamat malam')));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HQ Dashboard — LAMASY</title>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',system-ui,sans-serif;background:#F4F7FB;color:#0F1C3A;min-height:100vh}
  .hq-topbar{background:#0F1C3A;color:#fff;padding:14px 24px;display:flex;justify-content:space-between;
             align-items:center;flex-wrap:wrap;gap:12px;box-shadow:0 1px 8px rgba(0,0,0,.15)}
  .hq-brand{display:flex;align-items:center;gap:10px;font-size:16px;font-weight:700;color:#35E8D5}
  .hq-brand-sub{color:rgba(255,255,255,.5);font-size:11px;font-weight:400;margin-left:4px}
  .hq-badge{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;font-size:10px;font-weight:800;
            padding:3px 10px;border-radius:100px;letter-spacing:.06em}
  .hq-topbar-right{display:flex;align-items:center;gap:14px;font-size:13px;color:rgba(255,255,255,.85)}
  .hq-topbar-right .coin{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);
                         padding:5px 12px;border-radius:8px;font-weight:600}
  .hq-topbar a{color:rgba(255,255,255,.55);text-decoration:none;font-size:13px;padding:6px 10px;border-radius:6px}
  .hq-topbar a:hover{background:rgba(255,255,255,.08);color:#fff}
  .hq-logout{border:1px solid rgba(255,255,255,.15);padding:6px 14px!important}

  .container{max-width:1280px;margin:28px auto;padding:0 20px 60px}
  .hero{background:linear-gradient(135deg,#0F1C3A,#1a2d52);color:#fff;border-radius:16px;
        padding:28px 32px;margin-bottom:24px;display:flex;justify-content:space-between;
        align-items:flex-start;flex-wrap:wrap;gap:18px}
  .hero h1{font-size:1.5rem;font-weight:800;margin-bottom:6px}
  .hero p{color:rgba(255,255,255,.65);font-size:14px}
  .hero .ts{color:rgba(255,255,255,.4);font-size:12px;margin-top:6px}
  .hero-cta{display:flex;gap:10px;flex-wrap:wrap}
  .btn{padding:10px 18px;border-radius:10px;font-weight:700;font-size:13px;
       text-decoration:none;display:inline-flex;align-items:center;gap:6px;border:none;cursor:pointer}
  .btn-primary{background:#35E8D5;color:#0F1C3A}
  .btn-secondary{background:rgba(255,255,255,.08);color:#fff;border:1px solid rgba(255,255,255,.15)}
  .btn-light{background:#fff;color:#0F1C3A;border:1px solid #E5E7EB}
  .btn-link{background:transparent;color:#0891B2;padding:6px 12px;font-size:12px}

  .metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
  .metric-card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 1px 8px rgba(0,0,0,.05);
               border-top:3px solid #35E8D5}
  .metric-card.green{border-top-color:#34D399}
  .metric-card.purple{border-top-color:#8B5CF6}
  .metric-card.orange{border-top-color:#F59E0B}
  .metric-num{font-size:1.7rem;font-weight:800;color:#0F1C3A;font-family:'Courier New',monospace;margin-bottom:2px}
  .metric-label{font-size:13px;color:#6B7280;font-weight:600}
  .metric-sub{font-size:11px;color:#9CA3AF;margin-top:4px}

  .alerts{margin-bottom:24px;display:flex;flex-direction:column;gap:8px}
  .alert{padding:11px 16px;border-radius:10px;font-size:13px;display:flex;align-items:center;gap:8px}
  .alert.warning{background:#FEF3C7;color:#92400E;border:1px solid #FDE68A}
  .alert.danger{background:#FEE2E2;color:#991B1B;border:1px solid #FECACA}
  .alert.info{background:#DBEAFE;color:#1E40AF;border:1px solid #BFDBFE}

  .section{background:#fff;border-radius:14px;padding:24px;box-shadow:0 1px 8px rgba(0,0,0,.05);margin-bottom:20px}
  .section-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px}
  .section-title{font-size:15px;font-weight:700;color:#0F1C3A}
  table.outlets-tbl{width:100%;border-collapse:collapse;font-size:13px}
  table.outlets-tbl th{background:#F9FAFB;text-align:left;padding:10px 12px;font-size:11px;
                       color:#6B7280;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
                       border-bottom:1px solid #E5E7EB}
  table.outlets-tbl td{padding:14px 12px;border-bottom:1px solid #F3F4F6}
  table.outlets-tbl tr:last-child td{border-bottom:none}
  .ol-name{font-weight:700;color:#0F1C3A}
  .ol-name small{display:block;color:#9CA3AF;font-weight:400;font-size:11px;margin-top:2px}
  .ol-money{font-family:'Courier New',monospace;font-weight:700;color:#0F1C3A}
  .ol-status{font-size:10px;font-weight:700;padding:3px 10px;border-radius:100px;text-transform:uppercase;display:inline-block}
  .ol-status.trial{background:#DBEAFE;color:#1E40AF}
  .ol-status.grace{background:#FEF3C7;color:#92400E}
  .ol-status.active{background:#D1FAE5;color:#065F46}
  .ol-actions{display:flex;gap:6px;justify-content:flex-end}
  .ol-actions .btn{padding:6px 12px;font-size:11px}
  .ol-actions .btn-secondary{background:#F3F4F6;color:#0F1C3A;border:1px solid #E5E7EB}

  .empty-state{text-align:center;padding:32px 20px;color:#6B7280}
  .empty-state .ico{font-size:48px;margin-bottom:10px}

  @media(max-width:900px){.metrics{grid-template-columns:repeat(2,1fr)}}
  @media(max-width:640px){
    .metrics{grid-template-columns:1fr}
    .container{padding:0 14px 40px}
    .hero{padding:20px}
    table.outlets-tbl{font-size:12px}
    table.outlets-tbl th,table.outlets-tbl td{padding:8px 6px}
  }
</style>
</head>
<body>

<div class="hq-topbar">
  <div class="hq-brand">
    <img src="/ERP/harpy/assets/logo.png" alt="LAMASY" style="height:28px">
    LAMASY <span class="hq-brand-sub">by Harpy</span>
    <span class="hq-badge">🏢 HQ</span>
  </div>
  <div class="hq-topbar-right">
    <div class="coin">🪙 <?= number_format($tenantCoin, 0, ',', '.') ?></div>
    <span><?= htmlspecialchars($ownerNama) ?></span>
    <a href="/ERP/harpy/hq/karyawan.php">👥 Karyawan</a>
    <a href="/ERP/harpy/hq/pelanggan.php">🧑‍🤝‍🧑 Pelanggan</a>
    <a href="/ERP/harpy/hq/promo.php">🎟️ Promo</a>
    <a href="/ERP/harpy/hq/laporan.php">📈 Laporan</a>
    <a href="/ERP/harpy/dashboard.php?to=outlet" title="Kembali ke outlet view">← Outlet View</a>
    <a href="/ERP/harpy/logout.php" class="hq-logout"
       onclick="return confirm('Yakin logout?')">Logout</a>
  </div>
</div>

<div class="container">

  <div class="hero">
    <div>
      <h1><?= $greeting ?>, <?= htmlspecialchars($ownerNama) ?>! 👋</h1>
      <p>Dashboard HQ <strong style="color:#35E8D5"><?= htmlspecialchars($tenantNama) ?></strong>
         — pandangan konsolidasi <?= $outletCnt ?> outlet aktif</p>
      <div class="ts"><?= date('l, d F Y · H:i') ?></div>
    </div>
    <div class="hero-cta">
      <a href="/ERP/harpy/add-outlet.php" class="btn btn-primary">🏪 Tambah Outlet</a>
    </div>
  </div>

  <!-- METRICS KONSOLIDASI -->
  <div class="metrics">
    <div class="metric-card">
      <div class="metric-num">Rp <?= number_format((int)$todayRow['omset_today'], 0, ',', '.') ?></div>
      <div class="metric-label">Omset Hari Ini</div>
      <div class="metric-sub">Lintas semua outlet</div>
    </div>
    <div class="metric-card green">
      <div class="metric-num"><?= number_format((int)$todayRow['order_today']) ?></div>
      <div class="metric-label">Total Order Hari Ini</div>
      <div class="metric-sub">Terkumpul Rp <?= number_format((int)$todayRow['terkumpul'], 0, ',', '.') ?></div>
    </div>
    <div class="metric-card purple">
      <div class="metric-num"><?= $karyawanCnt ?></div>
      <div class="metric-label">Karyawan Aktif</div>
      <div class="metric-sub">Lintas <?= $outletCnt ?> outlet</div>
    </div>
    <div class="metric-card orange">
      <div class="metric-num"><?= number_format($pelangganCnt) ?></div>
      <div class="metric-label">Pelanggan Terdaftar</div>
      <div class="metric-sub">Database tenant</div>
    </div>
  </div>

  <!-- ALERTS -->
  <?php if (!empty($alerts)): ?>
  <div class="alerts">
    <?php foreach ($alerts as $a): ?>
    <div class="alert <?= $a['level'] ?>"><?= $a['msg'] ?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- PER OUTLET -->
  <div class="section">
    <div class="section-head">
      <div class="section-title">📍 Outlet Aktif</div>
      <a href="/ERP/harpy/add-outlet.php" class="btn btn-link">+ Tambah Outlet</a>
    </div>

    <?php if (empty($outlets)): ?>
    <div class="empty-state">
      <div class="ico">🏪</div>
      <p>Belum ada outlet. <a href="/ERP/harpy/add-outlet.php" style="color:#0891B2;font-weight:700">Daftarkan outlet pertama →</a></p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="outlets-tbl">
      <thead>
        <tr>
          <th>Outlet</th>
          <th>Status</th>
          <th style="text-align:right">Omset Hari Ini</th>
          <th style="text-align:right">Order</th>
          <th style="text-align:right">Omset Bulan Ini</th>
          <th style="text-align:right">Karyawan</th>
          <th style="text-align:right">Coin</th>
          <th style="text-align:right">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($outlets as $o):
          $coinShow = $o['status'] === 'trial'
            ? (int)$o['trial_coin_balance'] . ' <small style="color:#9CA3AF">trial</small>'
            : number_format((int)$o['coin_balance']);
        ?>
        <tr>
          <td>
            <div class="ol-name">
              <?= htmlspecialchars($o['nama_outlet']) ?>
              <?php if ((int)$o['is_main'] === 1): ?>
              <span style="background:#F0FDFB;color:#0891B2;font-size:9px;font-weight:700;padding:2px 6px;border-radius:4px;margin-left:4px">UTAMA</span>
              <?php endif; ?>
              <?php if (!empty($o['kota'])): ?>
              <small><?= htmlspecialchars($o['kota']) ?></small>
              <?php endif; ?>
            </div>
          </td>
          <td><span class="ol-status <?= $o['status'] ?>"><?= $o['status'] ?></span></td>
          <td style="text-align:right"><span class="ol-money">Rp <?= number_format((int)$o['omset_today'], 0, ',', '.') ?></span></td>
          <td style="text-align:right"><?= (int)$o['order_today'] ?></td>
          <td style="text-align:right"><span class="ol-money">Rp <?= number_format((int)$o['omset_month'], 0, ',', '.') ?></span></td>
          <td style="text-align:right"><?= (int)$o['karyawan_count'] ?></td>
          <td style="text-align:right"><?= $coinShow ?></td>
          <td>
            <div class="ol-actions">
              <a href="/ERP/harpy/switch-outlet.php?id=<?= (int)$o['id'] ?>" class="btn btn-primary">Masuk →</a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- QUICK LINKS (placeholder untuk Fase 5+) -->
  <div class="section">
    <div class="section-title" style="margin-bottom:14px">⚡ Aksi Cepat</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">
      <a href="/ERP/harpy/add-outlet.php" class="btn btn-light" style="justify-content:center">🏪 Tambah Outlet</a>
      <a href="/ERP/harpy/hq/karyawan.php" class="btn btn-light" style="justify-content:center">👥 Karyawan Lintas Outlet</a>
      <a href="/ERP/harpy/hq/pelanggan.php" class="btn btn-light" style="justify-content:center">🧑‍🤝‍🧑 Pelanggan Lintas Outlet</a>
      <a href="/ERP/harpy/hq/laporan.php" class="btn btn-light" style="justify-content:center">📈 Laporan Konsolidasi</a>
      <a href="#" class="btn btn-light" style="justify-content:center;opacity:.5;pointer-events:none">⚙️ Pengaturan Akun <small style="color:#9CA3AF;margin-left:4px">(Fase 7)</small></a>
    </div>
  </div>

</div>
</body>
</html>
