<?php
// ══════════════════════════════════════════════════════
// hq/dashboard.php — HQ Dashboard Konsolidasi (REBUILD)
//
// Spec:
//   - Omzet hari ini gabungan + order aktif (in-progress)
//   - Highlight: outlet paling ramai vs paling sepi (best/worst)
//   - Chart tren omzet per outlet (multi-line, 7 hari)
//   - Card per outlet ("kartu kesehatan") — bukan table
//   - Alert: trial mau habis, grace, coin tipis
// ══════════════════════════════════════════════════════

$activePage = 'hq-dashboard';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

$db    = Database::get();
$tid   = (int)$hqTenant['id'];
$today = date('Y-m-d');
$thisMonth = date('Y-m');
$startTimeline = date('Y-m-d', strtotime('-6 days'));

// ── AJAX: chart data per outlet untuk N hari ─────────
if (($_GET['action'] ?? '') === 'chart_data') {
    header('Content-Type: application/json');
    $days = max(1, min(365, (int)($_GET['days'] ?? 30)));
    $start = date('Y-m-d', strtotime("-" . ($days-1) . " days"));
    $end   = date('Y-m-d');

    try {
        $oStmt = $db->prepare("SELECT id, nama_outlet FROM outlets
                                 WHERE tenant_id=? AND status IN ('trial','grace','active')
                                 ORDER BY is_main DESC, nama_outlet ASC");
        $oStmt->execute([$tid]);
        $outlets = $oStmt->fetchAll();

        $timeline = [];
        $tStmt = $db->prepare("SELECT outlet_id, DATE(tanggal) d, COALESCE(SUM(total),0) s
                                 FROM hl_transaksi
                                WHERE tenant_id=? AND DATE(tanggal) BETWEEN ? AND ?
                                GROUP BY outlet_id, DATE(tanggal)");
        $tStmt->execute([$tid, $start, $end]);
        foreach ($tStmt->fetchAll() as $row) {
            $timeline[(int)$row['outlet_id']][$row['d']] = (int)$row['s'];
        }

        $labels = [];
        for ($i = $days-1; $i >= 0; $i--) $labels[] = date('Y-m-d', strtotime("-$i days"));

        $colors = ['#35E8D5','#8B5CF6','#F59E0B','#EC4899','#3B82F6','#10B981','#EF4444','#F97316','#6366F1'];
        $datasets = [];
        foreach ($outlets as $i => $o) {
            $data = [];
            foreach ($labels as $d) $data[] = $timeline[(int)$o['id']][$d] ?? 0;
            $color = $colors[$i % count($colors)];
            $datasets[] = ['label'=>$o['nama_outlet'], 'data'=>$data, 'color'=>$color];
        }

        echo json_encode([
            'labels'   => array_map(fn($d) => date('d M', strtotime($d)), $labels),
            'datasets' => $datasets,
            'periode'  => ['start'=>$start, 'end'=>$end],
        ]);
    } catch (Throwable $e) {
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

// ── Konsolidasi metrics hari ini ──────────────────────
$todayStats = $db->prepare("
    SELECT COALESCE(SUM(total),0) AS omset_today,
           COUNT(*) AS order_today,
           COALESCE(SUM(CASE WHEN status_bayar='lunas' THEN total ELSE 0 END),0) AS terkumpul
      FROM hl_transaksi
     WHERE tenant_id = ? AND DATE(tanggal) = ?
");
$todayStats->execute([$tid, $today]);
$todayRow = $todayStats->fetch() ?: ['omset_today'=>0,'order_today'=>0,'terkumpul'=>0];

// ── Order AKTIF (masih dalam proses, bukan siap/selesai/batal) ──
$orderAktif = 0;
try {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM hl_transaksi
          WHERE tenant_id=? AND status_proses NOT IN ('siap','selesai','batal','dibatalkan')"
    );
    $stmt->execute([$tid]);
    $orderAktif = (int)$stmt->fetchColumn();
} catch (Throwable) {}

// ── Outlet aktif + karyawan + pelanggan ───────────────
$outletCnt = (int)$db->query("SELECT COUNT(*) FROM outlets WHERE tenant_id=$tid AND status IN ('trial','grace','active')")->fetchColumn();

$pelangganCnt = 0;
try {
    $pelangganCnt = (int)$db->query("SELECT COUNT(*) FROM hl_pelanggan WHERE tenant_id=$tid AND is_active=1")->fetchColumn();
} catch (Throwable) {}

$karyawanCnt = 0;
try {
    $karyawanCnt = (int)$db->query("SELECT COUNT(DISTINCT karyawan_id) FROM hl_karyawan_outlet WHERE tenant_id=$tid AND is_active=1")->fetchColumn();
} catch (Throwable) {
    try { $karyawanCnt = (int)$db->query("SELECT COUNT(*) FROM hl_users WHERE tenant_id=$tid AND is_active=1")->fetchColumn(); } catch (Throwable) {}
}

// ── Per-outlet metrics (omset hari ini, order aktif, omset bulan, karyawan, coin) ──
$outletsStmt = $db->prepare(
    "SELECT * FROM outlets
      WHERE tenant_id=? AND status IN ('trial','grace','active')
      ORDER BY is_main DESC, nama_outlet ASC"
);
$outletsStmt->execute([$tid]);
$outlets = $outletsStmt->fetchAll();

foreach ($outlets as &$o) {
    $oid = (int)$o['id'];

    // Omset & order hari ini
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(total),0) AS s, COUNT(*) AS c
                                FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal)=?");
        $stmt->execute([$tid, $oid, $today]);
        $r = $stmt->fetch();
        $o['omset_today'] = (int)$r['s'];
        $o['order_today'] = (int)$r['c'];
    } catch (Throwable) { $o['omset_today']=0; $o['order_today']=0; }

    // Order aktif
    $o['order_aktif'] = 0;
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM hl_transaksi
                                WHERE tenant_id=? AND outlet_id=?
                                  AND status_proses NOT IN ('siap','selesai','batal','dibatalkan')");
        $stmt->execute([$tid, $oid]);
        $o['order_aktif'] = (int)$stmt->fetchColumn();
    } catch (Throwable) {}

    // Omset bulan
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(total),0) FROM hl_transaksi
                                WHERE tenant_id=? AND outlet_id=? AND DATE_FORMAT(tanggal,'%Y-%m')=?");
        $stmt->execute([$tid, $oid, $thisMonth]);
        $o['omset_month'] = (int)$stmt->fetchColumn();
    } catch (Throwable) { $o['omset_month'] = 0; }

    // Karyawan count
    try {
        $stmt = $db->prepare("SELECT COUNT(DISTINCT karyawan_id) FROM hl_karyawan_outlet
                                WHERE tenant_id=? AND outlet_id=? AND is_active=1");
        $stmt->execute([$tid, $oid]);
        $o['karyawan_count'] = (int)$stmt->fetchColumn();
    } catch (Throwable) {
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM hl_users WHERE tenant_id=? AND outlet_id=? AND is_active=1");
            $stmt->execute([$tid, $oid]);
            $o['karyawan_count'] = (int)$stmt->fetchColumn();
        } catch (Throwable) { $o['karyawan_count'] = 0; }
    }
}
unset($o);

// ── Best vs Worst (berdasarkan omset bulan) ───────────
$bestOutlet = null; $worstOutlet = null;
if (count($outlets) >= 2) {
    $sorted = $outlets;
    usort($sorted, fn($a, $b) => $b['omset_month'] <=> $a['omset_month']);
    $bestOutlet  = $sorted[0];
    $worstOutlet = end($sorted);
    // Jangan tampilkan worst jika sama dengan best (semua omset 0)
    if ((int)$bestOutlet['omset_month'] === (int)$worstOutlet['omset_month']) {
        $bestOutlet = $worstOutlet = null;
    }
}

// ── Timeline data per outlet (7 hari terakhir) untuk chart multi-line ──
$timelineByOutlet = [];
try {
    $stmt = $db->prepare(
        "SELECT outlet_id, DATE(tanggal) AS d, COALESCE(SUM(total),0) AS s
           FROM hl_transaksi
          WHERE tenant_id=? AND DATE(tanggal) BETWEEN ? AND ?
          GROUP BY outlet_id, DATE(tanggal)
          ORDER BY d ASC"
    );
    $stmt->execute([$tid, $startTimeline, $today]);
    foreach ($stmt->fetchAll() as $row) {
        $oid = (int)$row['outlet_id'];
        $timelineByOutlet[$oid][$row['d']] = (int)$row['s'];
    }
} catch (Throwable) {}

// Generate 7 hari label
$dayLabels = [];
for ($i = 6; $i >= 0; $i--) {
    $dayLabels[] = date('Y-m-d', strtotime("-$i days"));
}

// Build chart datasets
$chartColors = ['#35E8D5','#8B5CF6','#F59E0B','#EC4899','#3B82F6','#10B981','#EF4444','#F97316','#6366F1'];
$chartDatasets = [];
foreach ($outlets as $i => $o) {
    $oid = (int)$o['id'];
    $data = [];
    foreach ($dayLabels as $d) {
        $data[] = $timelineByOutlet[$oid][$d] ?? 0;
    }
    $color = $chartColors[$i % count($chartColors)];
    $chartDatasets[] = [
        'label' => $o['nama_outlet'],
        'data'  => $data,
        'borderColor' => $color,
        'backgroundColor' => $color . '22',
        'tension' => 0.3,
        'pointRadius' => 3,
        'fill' => false,
    ];
}

// ── Alerts ────────────────────────────────────────────
$alerts = [];
foreach ($outlets as $o) {
    if ($o['status'] === 'trial' && !empty($o['trial_ends_at'])) {
        $daysLeft = (int)floor((strtotime($o['trial_ends_at']) - time()) / 86400);
        if ($daysLeft <= 3 && $daysLeft >= 0) {
            $alerts[] = ['level'=>'warning','msg'=>"⏰ Trial outlet <strong>{$o['nama_outlet']}</strong> berakhir dalam <strong>$daysLeft hari</strong>"];
        }
    }
    if ($o['status'] === 'grace') {
        $alerts[] = ['level'=>'danger','msg'=>"⚠️ Outlet <strong>{$o['nama_outlet']}</strong> dalam grace period — segera aktivasi"];
    }
    if ((int)($o['trial_coin_balance'] ?? 0) < 200 && $o['status'] === 'trial') {
        $alerts[] = ['level'=>'info','msg'=>"🪙 Coin trial outlet <strong>{$o['nama_outlet']}</strong> tinggal " . number_format((int)$o['trial_coin_balance'])];
    }
    if ($o['status'] === 'active' && (int)($o['coin_balance'] ?? 0) < 1000) {
        $alerts[] = ['level'=>'info','msg'=>"🪙 Coin outlet <strong>{$o['nama_outlet']}</strong> tinggal " . number_format((int)$o['coin_balance'])];
    }
}

$tenantCoin = (int)($hqTenant['coin_balance'] ?? 0);
$ownerNama  = $hqUser['nama'] ?? 'Owner';
$tenantNm   = $hqTenant['nama_outlet'] ?? 'HQ';
$greeting   = (date('H') < 11 ? 'Selamat pagi' : (date('H') < 15 ? 'Selamat siang' : (date('H') < 19 ? 'Selamat sore' : 'Selamat malam')));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HQ Dashboard — LAMASY</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
  .hq-topbar a.active{background:rgba(53,232,213,.15);color:#35E8D5}
  .hq-logout{border:1px solid rgba(255,255,255,.15);padding:6px 14px!important}

  .container{max-width:1320px;margin:24px auto;padding:0 20px 60px}

  .hero{background:linear-gradient(135deg,#0F1C3A,#1a2d52);color:#fff;border-radius:14px;
        padding:24px 28px;margin-bottom:20px;display:flex;justify-content:space-between;
        align-items:center;flex-wrap:wrap;gap:14px}
  .hero h1{font-size:1.35rem;font-weight:800;margin-bottom:4px}
  .hero p{color:rgba(255,255,255,.65);font-size:13px}

  .btn{padding:10px 18px;border-radius:9px;font-weight:700;font-size:13px;
       text-decoration:none;display:inline-flex;align-items:center;gap:6px;border:none;cursor:pointer;font-family:inherit}
  .btn-primary{background:#35E8D5;color:#0F1C3A}
  .btn-light{background:#fff;color:#0F1C3A;border:1px solid #E5E7EB}
  .btn-sm{padding:6px 12px;font-size:11px}

  .metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}
  .metric{background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 6px rgba(0,0,0,.05);
          border-top:3px solid #35E8D5}
  .metric.blue{border-top-color:#3B82F6}.metric.purple{border-top-color:#8B5CF6}
  .metric.orange{border-top-color:#F59E0B}
  .metric-num{font-size:1.6rem;font-weight:800;color:#0F1C3A;font-family:'Courier New',monospace;margin-bottom:2px}
  .metric-label{font-size:12px;color:#6B7280;font-weight:600}
  .metric-sub{font-size:11px;color:#9CA3AF;margin-top:3px}

  .alerts{margin-bottom:18px;display:flex;flex-direction:column;gap:8px}
  .alert{padding:10px 16px;border-radius:10px;font-size:13px}
  .alert.warning{background:#FEF3C7;color:#92400E;border:1px solid #FDE68A}
  .alert.danger{background:#FEE2E2;color:#991B1B;border:1px solid #FECACA}
  .alert.info{background:#DBEAFE;color:#1E40AF;border:1px solid #BFDBFE}

  /* Best/Worst highlight */
  .ranking{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
  .rank-card{background:#fff;border-radius:14px;padding:20px 22px;box-shadow:0 1px 6px rgba(0,0,0,.05);
             position:relative;overflow:hidden}
  .rank-card.best{border-left:4px solid #F59E0B;background:linear-gradient(135deg,#FFFBEB,#fff)}
  .rank-card.worst{border-left:4px solid #6B7280;background:linear-gradient(135deg,#F9FAFB,#fff)}
  .rank-label{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px}
  .rank-label.best{color:#D97706}
  .rank-label.worst{color:#6B7280}
  .rank-outlet{font-size:1.2rem;font-weight:800;color:#0F1C3A;margin-bottom:3px}
  .rank-money{font-size:1.3rem;font-weight:800;color:#0F1C3A;font-family:monospace;margin-top:6px}
  .rank-money small{display:block;font-size:11px;font-weight:400;color:#9CA3AF;margin-top:2px}

  /* Chart panel */
  .panel{background:#fff;border-radius:12px;padding:22px;box-shadow:0 1px 6px rgba(0,0,0,.05);margin-bottom:18px}
  .panel-title{font-size:14px;font-weight:700;color:#0F1C3A;margin-bottom:14px;display:flex;
               justify-content:space-between;align-items:center}
  .chart-box{height:320px;position:relative}

  /* Outlet cards grid */
  .outlet-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;margin-bottom:18px}
  .ocard{background:#fff;border-radius:14px;padding:20px 22px;box-shadow:0 1px 6px rgba(0,0,0,.05);
         position:relative;transition:box-shadow .2s,transform .2s;border-top:3px solid #E5E7EB}
  .ocard:hover{box-shadow:0 4px 18px rgba(0,0,0,.08);transform:translateY(-2px)}
  .ocard.s-trial{border-top-color:#3B82F6}
  .ocard.s-grace{border-top-color:#F59E0B}
  .ocard.s-active{border-top-color:#10B981}
  .ocard.is-best{background:linear-gradient(135deg,#FFFBEB,#fff)}
  .ocard-header{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:14px}
  .ocard-name{font-size:1.05rem;font-weight:800;color:#0F1C3A;line-height:1.2}
  .ocard-name small{display:block;font-size:11px;font-weight:500;color:#6B7280;margin-top:3px}
  .ocard-status{font-size:9px;font-weight:800;padding:3px 9px;border-radius:100px;text-transform:uppercase;
                white-space:nowrap}
  .st-trial{background:#DBEAFE;color:#1E40AF}
  .st-grace{background:#FEF3C7;color:#92400E}
  .st-active{background:#D1FAE5;color:#065F46}
  .ocard-main{margin-bottom:14px;padding:12px 14px;background:#F9FAFB;border-radius:10px}
  .ocard-main-num{font-size:1.55rem;font-weight:800;color:#0F1C3A;font-family:monospace;line-height:1}
  .ocard-main-label{font-size:11px;color:#6B7280;font-weight:600;margin-top:4px}
  .ocard-stats{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;font-size:12px}
  .ocard-stat{background:#F9FAFB;border-radius:8px;padding:9px 11px}
  .ocard-stat-num{font-weight:800;color:#0F1C3A;font-family:monospace;font-size:14px}
  .ocard-stat-label{color:#9CA3AF;font-size:10px;text-transform:uppercase;letter-spacing:.04em;font-weight:600;margin-top:2px}
  .ocard-foot{display:flex;gap:6px;align-items:center;justify-content:space-between}
  .ocard-coin{font-size:11px;color:#6B7280}
  .ocard-coin strong{color:#0F1C3A;font-family:monospace}
  .ocard-coin.low{color:#92400E;background:#FEF3C7;border:1px solid #FDE68A;padding:4px 8px;border-radius:6px;font-weight:700}
  .ocard-coin.low strong{color:#92400E}
  .ocard-coin.crit{color:#991B1B;background:#FEE2E2;border:1px solid #FCA5A5;padding:4px 8px;border-radius:6px;font-weight:700;animation:coinPulse 2s ease-in-out infinite}
  .ocard-coin.crit strong{color:#991B1B}
  @keyframes coinPulse{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.4)}50%{box-shadow:0 0 0 6px rgba(239,68,68,0)}}
  .badge-trial-coin{background:rgba(245,158,11,.15);color:#92400E;font-size:9px;padding:1px 6px;
                    border-radius:4px;font-weight:700;margin-left:3px}
  .top-badge{position:absolute;top:14px;right:14px;background:#F59E0B;color:#fff;font-size:9px;
             font-weight:800;padding:3px 9px;border-radius:100px;letter-spacing:.05em}

  .empty-state{text-align:center;padding:48px 20px;color:#6B7280;background:#fff;border-radius:14px}
  .empty-state .ico{font-size:56px;margin-bottom:12px}

  @media(max-width:980px){
    .metrics{grid-template-columns:repeat(2,1fr)}
    .ranking{grid-template-columns:1fr}
  }
  @media(max-width:640px){
    .metrics{grid-template-columns:1fr}
    .container{padding:0 14px 40px}
  }
</style>
</head>
<body>

<?php require __DIR__ . '/_topbar.php'; ?>
</div>

<div class="container">

  <div class="hero">
    <div>
      <h1><?= $greeting ?>, <?= htmlspecialchars($ownerNama) ?>! 👋</h1>
      <p>HQ <strong style="color:#35E8D5"><?= htmlspecialchars($tenantNm) ?></strong>
         · <?= $outletCnt ?> outlet aktif · <?= date('l, d F Y') ?></p>
    </div>
    <?php if ($hqCanManageOutlet): ?><a href="/ERP/harpy/add-outlet.php" class="btn btn-primary">🏪 Tambah Outlet</a><?php endif; ?>
  </div>

  <!-- 4 METRIC CARDS -->
  <div class="metrics">
    <div class="metric">
      <div class="metric-num">Rp <?= number_format((int)$todayRow['omset_today'], 0, ',', '.') ?></div>
      <div class="metric-label">Omset Hari Ini</div>
      <div class="metric-sub">Lintas <?= $outletCnt ?> outlet · Terkumpul Rp <?= number_format((int)$todayRow['terkumpul'], 0, ',', '.') ?></div>
    </div>
    <div class="metric blue">
      <div class="metric-num"><?= number_format($orderAktif) ?></div>
      <div class="metric-label">Order Aktif</div>
      <div class="metric-sub">Masih dalam proses pengerjaan</div>
    </div>
    <div class="metric purple">
      <div class="metric-num"><?= number_format($karyawanCnt) ?></div>
      <div class="metric-label">Karyawan Aktif</div>
      <div class="metric-sub">Lintas semua outlet</div>
    </div>
    <div class="metric orange">
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

  <!-- BEST vs WORST -->
  <?php if ($bestOutlet && $worstOutlet): ?>
  <div class="ranking">
    <div class="rank-card best">
      <div class="rank-label best">🏆 Top Performer Bulan Ini</div>
      <div class="rank-outlet">📍 <?= htmlspecialchars($bestOutlet['nama_outlet']) ?></div>
      <div class="rank-money">Rp <?= number_format((int)$bestOutlet['omset_month'], 0, ',', '.') ?>
        <small><?= (int)$bestOutlet['order_today'] ?> order hari ini · <?= (int)$bestOutlet['karyawan_count'] ?> karyawan</small>
      </div>
    </div>
    <div class="rank-card worst">
      <div class="rank-label worst">📉 Perlu Perhatian</div>
      <div class="rank-outlet">📍 <?= htmlspecialchars($worstOutlet['nama_outlet']) ?></div>
      <div class="rank-money">Rp <?= number_format((int)$worstOutlet['omset_month'], 0, ',', '.') ?>
        <small>Omset terendah bulan ini · review strategi cabang ini</small>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- CHART TREN OMZET PER OUTLET (filter periode + tipe) -->
  <div class="panel">
    <div class="panel-title">
      <span>📊 Tren Omzet per Outlet</span>
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <div style="display:flex;gap:3px;background:#F3F4F6;padding:3px;border-radius:8px">
          <button type="button" class="chart-preset" data-days="7"  onclick="setChartPeriod(7,event)"
                  style="border:none;background:transparent;padding:5px 10px;font-size:11px;font-weight:700;border-radius:5px;cursor:pointer;color:#0F1C3A;font-family:inherit">7H</button>
          <button type="button" class="chart-preset active" data-days="30" onclick="setChartPeriod(30,event)"
                  style="border:none;background:#0F1C3A;color:#fff;padding:5px 10px;font-size:11px;font-weight:700;border-radius:5px;cursor:pointer;font-family:inherit">30H</button>
          <button type="button" class="chart-preset" data-days="90" onclick="setChartPeriod(90,event)"
                  style="border:none;background:transparent;padding:5px 10px;font-size:11px;font-weight:700;border-radius:5px;cursor:pointer;color:#0F1C3A;font-family:inherit">3 Bulan</button>
        </div>
        <div style="display:flex;gap:3px;background:#F3F4F6;padding:3px;border-radius:8px">
          <button type="button" class="chart-type active" data-type="line" onclick="setChartType('line',event)"
                  style="border:none;background:#0F1C3A;color:#fff;padding:5px 10px;font-size:11px;font-weight:700;border-radius:5px;cursor:pointer;font-family:inherit">📈 Line</button>
          <button type="button" class="chart-type" data-type="bar" onclick="setChartType('bar',event)"
                  style="border:none;background:transparent;padding:5px 10px;font-size:11px;font-weight:700;border-radius:5px;cursor:pointer;color:#0F1C3A;font-family:inherit">📊 Bar</button>
        </div>
        <span id="chartRange" style="font-size:11px;font-weight:400;color:#9CA3AF"></span>
      </div>
    </div>
    <div class="chart-box" style="height:320px"><canvas id="chartOmset"></canvas></div>
  </div>

  <!-- OUTLET HEALTH CARDS -->
  <div class="panel-title" style="padding:0 4px;margin-bottom:10px">📍 Kartu Kesehatan Outlet</div>
  <?php if (empty($outlets)): ?>
  <div class="empty-state">
    <div class="ico">🏪</div>
    <p>Belum ada outlet aktif. <a href="/ERP/harpy/add-outlet.php" style="color:#0891B2;font-weight:700">Daftarkan outlet pertama →</a></p>
  </div>
  <?php else: ?>
  <div class="outlet-grid">
    <?php foreach ($outlets as $o):
      $isBest = $bestOutlet && (int)$o['id'] === (int)$bestOutlet['id'];
      $coinShow = $o['status'] === 'trial' && (int)$o['trial_coin_balance'] > 0
        ? number_format((int)$o['trial_coin_balance']) . ' <span class="badge-trial-coin">TRIAL</span>'
        : number_format((int)$o['coin_balance']);
      // Visual highlight saat coin tipis
      $coinAmt = $o['status'] === 'trial' ? (int)$o['trial_coin_balance'] : (int)$o['coin_balance'];
      $coinLowThreshold = $o['status'] === 'trial' ? 200 : 1000;
      $coinCritThreshold = $o['status'] === 'trial' ? 50 : 300;
      $coinClass = '';
      if ($o['status'] === 'active' || ($o['status'] === 'trial' && $coinAmt > 0)) {
        if ($coinAmt <= $coinCritThreshold) $coinClass = ' crit';
        elseif ($coinAmt <= $coinLowThreshold) $coinClass = ' low';
      }
    ?>
    <div class="ocard s-<?= $o['status'] ?> <?= $isBest ? 'is-best' : '' ?>">
      <?php if ($isBest): ?><div class="top-badge">🏆 TOP</div><?php endif; ?>

      <div class="ocard-header">
        <div class="ocard-name">
          <?= htmlspecialchars($o['nama_outlet']) ?>
          <small><?= htmlspecialchars($o['kota'] ?: 'Tanpa kota') ?>
            <?php if ((int)$o['is_main'] === 1): ?>· <strong style="color:#0891B2">UTAMA</strong><?php endif; ?>
          </small>
        </div>
        <span class="ocard-status st-<?= $o['status'] ?>"><?= $o['status'] ?></span>
      </div>

      <div class="ocard-main">
        <div class="ocard-main-num">Rp <?= number_format((int)$o['omset_today'], 0, ',', '.') ?></div>
        <div class="ocard-main-label">OMSET HARI INI · <?= (int)$o['order_today'] ?> order</div>
      </div>

      <div class="ocard-stats">
        <div class="ocard-stat">
          <div class="ocard-stat-num"><?= (int)$o['order_aktif'] ?></div>
          <div class="ocard-stat-label">Order Aktif</div>
        </div>
        <div class="ocard-stat">
          <div class="ocard-stat-num"><?= (int)$o['karyawan_count'] ?></div>
          <div class="ocard-stat-label">Karyawan</div>
        </div>
        <div class="ocard-stat" style="grid-column:1/-1">
          <div class="ocard-stat-num">Rp <?= number_format((int)$o['omset_month'], 0, ',', '.') ?></div>
          <div class="ocard-stat-label">Omset Bulan Ini</div>
        </div>
      </div>

      <div class="ocard-foot">
        <div class="ocard-coin<?= $coinClass ?>" <?= $coinClass ? 'title="Coin tipis — segera topup"' : '' ?>>
          <?= $coinClass === ' crit' ? '⚠️' : '🪙' ?> <strong><?= $coinShow ?></strong> coin<?= $coinClass === ' crit' ? ' · KRITIS' : ($coinClass === ' low' ? ' · TIPIS' : '') ?>
        </div>
        <a href="/ERP/harpy/switch-outlet.php?id=<?= (int)$o['id'] ?>" class="btn btn-primary btn-sm">Masuk →</a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Quick action -->
  <div class="panel" style="margin-top:8px">
    <div class="panel-title">⚡ Aksi Cepat</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">
      <?php if ($hqCanManageOutlet): ?><a href="/ERP/harpy/add-outlet.php" class="btn btn-light" style="justify-content:center">🏪 Tambah Outlet</a><?php endif; ?>
      <a href="/ERP/harpy/hq/outlet.php" class="btn btn-light" style="justify-content:center">🏢 Manajemen Outlet</a>
      <?php if ($hqCanBilling): ?>
      <button type="button" onclick="openTopupModal()" class="btn btn-light"
              style="justify-content:center;cursor:pointer;font-family:inherit;font-size:13px">
        🪙 Topup Coin Outlet
      </button>
      <?php endif; ?>
      <a href="/ERP/harpy/hq/karyawan.php" class="btn btn-light" style="justify-content:center">👥 Karyawan Lintas Outlet</a>
      <a href="/ERP/harpy/hq/pelanggan.php" class="btn btn-light" style="justify-content:center">🧑‍🤝‍🧑 Pelanggan Lintas Outlet</a>
      <a href="/ERP/harpy/hq/laporan.php" class="btn btn-light" style="justify-content:center">📈 Laporan Konsolidasi</a>
      <a href="/ERP/harpy/hq/roles.php" class="btn btn-light" style="justify-content:center">🔐 Role & Akses</a>
      <a href="/ERP/harpy/hq/settings.php" class="btn btn-light" style="justify-content:center">⚙️ Pengaturan Akun</a>
    </div>
  </div>

</div>

<?php if ($hqCanBilling): ?>
<!-- Topup Modal -->
<div id="topupModal" style="display:none;position:fixed;inset:0;background:rgba(15,28,58,.75);z-index:1000;
                              align-items:center;justify-content:center;padding:20px"
     onclick="if(event.target===this) document.getElementById('topupModal').style.display='none'">
  <div style="background:#fff;border-radius:14px;max-width:480px;width:100%;padding:24px 26px;box-shadow:0 20px 60px rgba(0,0,0,.4)">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px">
      <div>
        <div style="font-size:1.1rem;font-weight:800;color:#0F1C3A">🪙 Topup Coin Outlet</div>
        <div style="font-size:12px;color:#6B7280;margin-top:3px">Pilih outlet, kirim request via WhatsApp</div>
      </div>
      <button onclick="document.getElementById('topupModal').style.display='none'"
              style="background:none;border:none;font-size:24px;cursor:pointer;color:#9CA3AF;line-height:1">×</button>
    </div>
    <div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;padding:10px 14px;border-radius:8px;
                font-size:12px;margin-bottom:14px;line-height:1.5">
      🚧 Payment gateway sedang dikembangkan. Topup sementara via chat WA dengan tim LAMASY.
    </div>
    <?php foreach ($outlets as $o): ?>
    <a href="https://wa.me/6281234567890?text=<?= urlencode('Halo Tim LAMASY, saya mau topup coin untuk outlet "' . $o['nama_outlet'] . '" (tenant: ' . ($hqTenant['email'] ?? '-') . ')') ?>"
       target="_blank" rel="noopener"
       style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;
              background:#F9FAFB;border:1.5px solid #E5E7EB;border-radius:8px;margin-bottom:6px;
              text-decoration:none;color:#0F1C3A;transition:all .15s"
       onmouseover="this.style.background='#F0FDFB';this.style.borderColor='#35E8D5'"
       onmouseout="this.style.background='#F9FAFB';this.style.borderColor='#E5E7EB'">
      <div>
        <div style="font-weight:700;font-size:13px">📍 <?= htmlspecialchars($o['nama_outlet']) ?></div>
        <div style="font-size:11px;color:#6B7280;margin-top:2px">
          🪙 <strong><?= $o['status']==='trial' && (int)$o['trial_coin_balance']>0
                ? number_format((int)$o['trial_coin_balance']) . ' (trial)'
                : number_format((int)$o['coin_balance']) ?></strong>
          · <?= $o['status'] ?>
        </div>
      </div>
      <span style="background:#25D366;color:#fff;font-size:11px;font-weight:700;padding:6px 12px;border-radius:6px">
        💬 Topup
      </span>
    </a>
    <?php endforeach; ?>
  </div>
</div>

<script>
function openTopupModal(){ document.getElementById('topupModal').style.display='flex'; }
</script>
<?php endif; ?>

<script>
function fmtRp(n){return 'Rp ' + Number(n||0).toLocaleString('id-ID')}
function fmtShort(n){
  n = Number(n||0);
  if (n >= 1e9) return (n/1e9).toFixed(1)+'M';
  if (n >= 1e6) return (n/1e6).toFixed(1)+'jt';
  if (n >= 1e3) return (n/1e3).toFixed(0)+'rb';
  return n;
}

let chartInstance = null;
let currentDays = 30;
let currentChartType = 'line';

function setChartPeriod(days, ev){
  document.querySelectorAll('.chart-preset').forEach(b => {
    b.style.background = 'transparent'; b.style.color = '#0F1C3A'; b.classList.remove('active');
  });
  if (ev && ev.target) {
    ev.target.style.background = '#0F1C3A'; ev.target.style.color = '#fff';
    ev.target.classList.add('active');
  }
  currentDays = days;
  loadChart();
}

function setChartType(type, ev){
  document.querySelectorAll('.chart-type').forEach(b => {
    b.style.background = 'transparent'; b.style.color = '#0F1C3A'; b.classList.remove('active');
  });
  if (ev && ev.target) {
    ev.target.style.background = '#0F1C3A'; ev.target.style.color = '#fff';
    ev.target.classList.add('active');
  }
  currentChartType = type;
  loadChart();
}

async function loadChart(){
  const r = await fetch(`/ERP/harpy/hq/dashboard.php?action=chart_data&days=${currentDays}`);
  const d = await r.json();
  if (d.error) return;

  document.getElementById('chartRange').textContent =
    new Date(d.periode.start).toLocaleDateString('id-ID',{day:'2-digit',month:'short'})
    + ' → ' + new Date(d.periode.end).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'});

  const isBar = currentChartType === 'bar';
  const datasets = d.datasets.map(ds => ({
    label: ds.label,
    data:  ds.data,
    borderColor: ds.color,
    backgroundColor: isBar ? ds.color : ds.color + '22',
    tension: 0.3,
    pointRadius: currentDays > 60 ? 0 : 3,
    fill: false,
    borderRadius: 4, // hanya untuk bar
  }));

  if (chartInstance) chartInstance.destroy();
  const ctx = document.getElementById('chartOmset').getContext('2d');
  chartInstance = new Chart(ctx, {
    type: currentChartType,
    data: { labels: d.labels, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 12, padding: 14, font: { size: 12 } } },
        tooltip: { callbacks: { label: c => c.dataset.label + ': ' + fmtRp(c.parsed.y) } }
      },
      scales: {
        y: { beginAtZero: true, ticks: { callback: v => 'Rp ' + fmtShort(v) } },
        x: { ticks: { font: { size: 11 }, maxRotation: 0, autoSkip: true, maxTicksLimit: currentDays > 60 ? 12 : 15 } }
      }
    }
  });
}

loadChart();
</script>
</body>
</html>
