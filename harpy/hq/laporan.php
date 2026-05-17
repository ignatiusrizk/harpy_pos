<?php
// ══════════════════════════════════════════════════════
// hq/laporan.php — Laporan Konsolidasi Lintas Outlet (REBUILD)
//
// Spec:
//   - Omzet per bulan + breakdown per outlet
//   - Biaya operasional (gaji + kas keluar)
//   - Profit estimasi (omzet - biaya)
//   - Pelanggan: pertumbuhan vs prev period, retensi
//   - Karyawan: total SDM, total gaji
//   - Coin usage per outlet
//   - Drill-down per outlet (filter)
//   - Export CSV
// ══════════════════════════════════════════════════════

$activePage = 'hq-laporan';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];
$action = $_GET['action'] ?? '';

$defaultStart = date('Y-m-01');
$defaultEnd   = date('Y-m-d');

function sanitizeDate(?string $d, string $fallback): string {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) ? $d : $fallback;
}

// ═══ EXPORT CSV ════════════════════════════════════════
if ($action === 'export') {
    $start  = sanitizeDate($_GET['start'] ?? null, $defaultStart);
    $end    = sanitizeDate($_GET['end']   ?? null, $defaultEnd);
    $oidArg = (int)($_GET['outlet_id'] ?? 0);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="laporan_konsolidasi_' . $start . '_' . $end . ($oidArg?'_outlet'.$oidArg:'') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    fputcsv($out, ["Laporan Konsolidasi LAMASY"]);
    fputcsv($out, ["Periode", "$start s.d. $end"]);
    fputcsv($out, ["Tenant", $hqTenant['nama_outlet'] ?? '-']);
    fputcsv($out, []);
    fputcsv($out, ["RINGKASAN PER OUTLET"]);
    fputcsv($out, ["Outlet","Status","Omset","Order","Biaya Gaji","Kas Keluar","Profit Estimasi","Karyawan Aktif","Coin Terpakai"]);

    $outletQ = "SELECT * FROM outlets WHERE tenant_id=? AND status != 'closed'";
    $oParams = [$tid];
    if ($oidArg > 0) { $outletQ .= " AND id=?"; $oParams[] = $oidArg; }
    $outletQ .= " ORDER BY is_main DESC, nama_outlet ASC";
    $oStmt = $db->prepare($outletQ);
    $oStmt->execute($oParams);

    $totalOmset=0;$totalOrder=0;$totalGaji=0;$totalKas=0;$totalCoin=0;
    foreach ($oStmt->fetchAll() as $o) {
        $oid = (int)$o['id'];
        $omset=0;$orderC=0;$gaji=0;$kas=0;$kary=0;$coin=0;
        try { $r=$db->prepare("SELECT COALESCE(SUM(total),0) s, COUNT(*) c FROM hl_transaksi
                                 WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal) BETWEEN ? AND ?");
              $r->execute([$tid,$oid,$start,$end]); $tr=$r->fetch();
              $omset=(int)$tr['s']; $orderC=(int)$tr['c']; } catch (Throwable) {}
        try { $r=$db->prepare("SELECT COALESCE(SUM(total),0) FROM hl_gaji WHERE tenant_id=? AND outlet_id=?
                                 AND bulan BETWEEN DATE_FORMAT(?,'%Y-%m') AND DATE_FORMAT(?,'%Y-%m')");
              $r->execute([$tid,$oid,$start,$end]); $gaji=(int)$r->fetchColumn(); } catch (Throwable) {}
        try { $r=$db->prepare("SELECT COALESCE(SUM(jumlah),0) FROM hl_kas WHERE tenant_id=? AND outlet_id=? AND tipe='keluar' AND tanggal BETWEEN ? AND ?");
              $r->execute([$tid,$oid,$start,$end]); $kas=(int)$r->fetchColumn(); } catch (Throwable) {}
        try { $r=$db->prepare("SELECT COUNT(DISTINCT karyawan_id) FROM hl_karyawan_outlet WHERE tenant_id=? AND outlet_id=? AND is_active=1");
              $r->execute([$tid,$oid]); $kary=(int)$r->fetchColumn(); } catch (Throwable) {}
        try { $r=$db->prepare("SELECT COALESCE(SUM(ABS(amount)),0) FROM coin_ledger
                                 WHERE tenant_id=? AND outlet_id=? AND type='debit' AND DATE(created_at) BETWEEN ? AND ?");
              $r->execute([$tid,$oid,$start,$end]); $coin=(int)$r->fetchColumn(); } catch (Throwable) {}
        $profit = $omset - $gaji - $kas;
        fputcsv($out, [$o['nama_outlet'],$o['status'],$omset,$orderC,$gaji,$kas,$profit,$kary,$coin]);
        $totalOmset+=$omset;$totalOrder+=$orderC;$totalGaji+=$gaji;$totalKas+=$kas;$totalCoin+=$coin;
    }
    fputcsv($out, ["TOTAL","-",$totalOmset,$totalOrder,$totalGaji,$totalKas,
                    $totalOmset-$totalGaji-$totalKas,"-",$totalCoin]);
    fclose($out);
    exit;
}

// ═══ DATA JSON ═════════════════════════════════════════
if ($action === 'data') {
    header('Content-Type: application/json');
    $start  = sanitizeDate($_GET['start'] ?? null, $defaultStart);
    $end    = sanitizeDate($_GET['end']   ?? null, $defaultEnd);
    $oidArg = (int)($_GET['outlet_id'] ?? 0);

    $startTs = strtotime($start);
    $endTs   = strtotime($end);
    $periodDays = max(1, (int)round(($endTs - $startTs) / 86400) + 1);
    $prevEnd   = date('Y-m-d', strtotime($start . ' -1 day'));
    $prevStart = date('Y-m-d', strtotime($prevEnd . " -" . ($periodDays - 1) . " days"));

    $outletFilter = $oidArg > 0 ? " AND outlet_id=?" : "";
    $extraParams  = $oidArg > 0 ? [$oidArg] : [];

    // Summary
    $sumStmt = $db->prepare(
        "SELECT COUNT(*) total_order, COALESCE(SUM(total),0) omset,
                COALESCE(SUM(CASE WHEN status_bayar='lunas' THEN total ELSE 0 END),0) lunas,
                COALESCE(SUM(CASE WHEN status_bayar!='lunas' THEN total-COALESCE(dp,0) ELSE 0 END),0) piutang
           FROM hl_transaksi WHERE tenant_id=? AND DATE(tanggal) BETWEEN ? AND ? $outletFilter"
    );
    $sumStmt->execute(array_merge([$tid,$start,$end], $extraParams));
    $summary = $sumStmt->fetch() ?: ['total_order'=>0,'omset'=>0,'lunas'=>0,'piutang'=>0];

    // Biaya
    $totalGaji = 0; $totalKasKeluar = 0;
    try {
        $g = $db->prepare("SELECT COALESCE(SUM(total),0) FROM hl_gaji WHERE tenant_id=?
                            AND bulan BETWEEN DATE_FORMAT(?,'%Y-%m') AND DATE_FORMAT(?,'%Y-%m') $outletFilter");
        $g->execute(array_merge([$tid,$start,$end], $extraParams));
        $totalGaji = (int)$g->fetchColumn();
    } catch (Throwable) {}
    try {
        $k = $db->prepare("SELECT COALESCE(SUM(jumlah),0) FROM hl_kas
                            WHERE tenant_id=? AND tipe='keluar' AND tanggal BETWEEN ? AND ? $outletFilter");
        $k->execute(array_merge([$tid,$start,$end], $extraParams));
        $totalKasKeluar = (int)$k->fetchColumn();
    } catch (Throwable) {}

    $omsetInt = (int)$summary['omset'];
    $profit = $omsetInt - $totalGaji - $totalKasKeluar;
    $profitMargin = $omsetInt > 0 ? round(($profit / $omsetInt) * 100, 1) : 0;

    // Pertumbuhan pelanggan
    $newCust = 0; $newCustPrev = 0;
    try {
        $s = $db->prepare("SELECT COUNT(*) FROM hl_pelanggan WHERE tenant_id=? AND DATE(created_at) BETWEEN ? AND ?");
        $s->execute([$tid,$start,$end]);   $newCust     = (int)$s->fetchColumn();
        $s->execute([$tid,$prevStart,$prevEnd]); $newCustPrev = (int)$s->fetchColumn();
    } catch (Throwable) {}
    $growth = $newCustPrev > 0 ? round((($newCust - $newCustPrev) / $newCustPrev) * 100, 1) : ($newCust > 0 ? 100 : 0);

    // Retensi
    $retention = ['repeat'=>0,'total_active'=>0,'rate'=>0];
    try {
        $r = $db->prepare(
            "SELECT COUNT(*) total, SUM(CASE WHEN cnt>1 THEN 1 ELSE 0 END) repeats FROM (
               SELECT pelanggan_id, COUNT(*) cnt FROM hl_transaksi
                WHERE tenant_id=? AND DATE(tanggal) BETWEEN ? AND ? AND pelanggan_id IS NOT NULL $outletFilter
                GROUP BY pelanggan_id
             ) x"
        );
        $r->execute(array_merge([$tid,$start,$end], $extraParams));
        $row = $r->fetch();
        $retention['total_active'] = (int)$row['total'];
        $retention['repeat'] = (int)$row['repeats'];
        $retention['rate'] = $row['total'] > 0 ? round(($row['repeats']/$row['total'])*100, 1) : 0;
    } catch (Throwable) {}

    // Karyawan total
    $totalKar = 0;
    try {
        if ($oidArg > 0) {
            $s = $db->prepare("SELECT COUNT(DISTINCT karyawan_id) FROM hl_karyawan_outlet
                                WHERE tenant_id=? AND outlet_id=? AND is_active=1");
            $s->execute([$tid,$oidArg]);
        } else {
            $s = $db->prepare("SELECT COUNT(DISTINCT karyawan_id) FROM hl_karyawan_outlet
                                WHERE tenant_id=? AND is_active=1");
            $s->execute([$tid]);
        }
        $totalKar = (int)$s->fetchColumn();
    } catch (Throwable) {
        try {
            $sql = "SELECT COUNT(*) FROM hl_users WHERE tenant_id=? AND is_active=1";
            $args = [$tid];
            if ($oidArg > 0) { $sql .= " AND outlet_id=?"; $args[] = $oidArg; }
            $s = $db->prepare($sql); $s->execute($args);
            $totalKar = (int)$s->fetchColumn();
        } catch (Throwable) {}
    }

    // Per outlet
    $outletQuery = "SELECT id, nama_outlet, status FROM outlets WHERE tenant_id=? AND status IN ('trial','grace','active')";
    $oArgs = [$tid];
    if ($oidArg > 0) { $outletQuery .= " AND id=?"; $oArgs[] = $oidArg; }
    $outletQuery .= " ORDER BY is_main DESC, nama_outlet ASC";
    $oStmt = $db->prepare($outletQuery);
    $oStmt->execute($oArgs);
    $outlets = $oStmt->fetchAll();

    foreach ($outlets as &$o) {
        $oid = (int)$o['id'];
        $o['omset']=0; $o['order_count']=0; $o['gaji']=0; $o['kas_keluar']=0; $o['karyawan']=0; $o['coin_used']=0;
        try { $s=$db->prepare("SELECT COUNT(*) c, COALESCE(SUM(total),0) s FROM hl_transaksi
                                WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal) BETWEEN ? AND ?");
              $s->execute([$tid,$oid,$start,$end]); $r=$s->fetch();
              $o['order_count']=(int)$r['c']; $o['omset']=(int)$r['s']; } catch (Throwable) {}
        try { $s=$db->prepare("SELECT COALESCE(SUM(total),0) FROM hl_gaji WHERE tenant_id=? AND outlet_id=?
                                AND bulan BETWEEN DATE_FORMAT(?,'%Y-%m') AND DATE_FORMAT(?,'%Y-%m')");
              $s->execute([$tid,$oid,$start,$end]); $o['gaji']=(int)$s->fetchColumn(); } catch (Throwable) {}
        try { $s=$db->prepare("SELECT COALESCE(SUM(jumlah),0) FROM hl_kas
                                WHERE tenant_id=? AND outlet_id=? AND tipe='keluar' AND tanggal BETWEEN ? AND ?");
              $s->execute([$tid,$oid,$start,$end]); $o['kas_keluar']=(int)$s->fetchColumn(); } catch (Throwable) {}
        try { $s=$db->prepare("SELECT COUNT(DISTINCT karyawan_id) FROM hl_karyawan_outlet
                                WHERE tenant_id=? AND outlet_id=? AND is_active=1");
              $s->execute([$tid,$oid]); $o['karyawan']=(int)$s->fetchColumn(); } catch (Throwable) {}
        try { $s=$db->prepare("SELECT COALESCE(SUM(ABS(amount)),0) FROM coin_ledger
                                WHERE tenant_id=? AND outlet_id=? AND type='debit' AND DATE(created_at) BETWEEN ? AND ?");
              $s->execute([$tid,$oid,$start,$end]); $o['coin_used']=(int)$s->fetchColumn(); } catch (Throwable) {}
        $o['profit'] = $o['omset'] - $o['gaji'] - $o['kas_keluar'];
    }
    unset($o);

    // Timeline
    $timeline = [];
    try {
        $sql = "SELECT DATE(tanggal) d, COALESCE(SUM(total),0) s FROM hl_transaksi
                 WHERE tenant_id=? AND DATE(tanggal) BETWEEN ? AND ? $outletFilter
                 GROUP BY DATE(tanggal) ORDER BY d ASC";
        $s = $db->prepare($sql);
        $s->execute(array_merge([$tid,$start,$end], $extraParams));
        $timeline = $s->fetchAll();
    } catch (Throwable) {}

    // Top customers
    $topCust = [];
    try {
        $outletFilterAlias = $oidArg > 0 ? " AND t.outlet_id=?" : "";
        $sql = "SELECT t.pelanggan_id, p.nama, p.telepon, COUNT(*) order_count, COALESCE(SUM(t.total),0) total_spend
                  FROM hl_transaksi t LEFT JOIN hl_pelanggan p ON p.id=t.pelanggan_id
                 WHERE t.tenant_id=? AND DATE(t.tanggal) BETWEEN ? AND ? AND t.pelanggan_id IS NOT NULL $outletFilterAlias
                 GROUP BY t.pelanggan_id, p.nama, p.telepon ORDER BY total_spend DESC LIMIT 10";
        $s = $db->prepare($sql);
        $s->execute(array_merge([$tid,$start,$end], $extraParams));
        $topCust = $s->fetchAll();
    } catch (Throwable) {}

    // Coin total
    $totalCoin = 0;
    try {
        $sql = "SELECT COALESCE(SUM(ABS(amount)),0) FROM coin_ledger
                 WHERE tenant_id=? AND type='debit' AND DATE(created_at) BETWEEN ? AND ? $outletFilter";
        $s = $db->prepare($sql);
        $s->execute(array_merge([$tid,$start,$end], $extraParams));
        $totalCoin = (int)$s->fetchColumn();
    } catch (Throwable) {}

    echo json_encode([
        'periode'  => ['start'=>$start,'end'=>$end,'days'=>$periodDays],
        'outlet_filter' => $oidArg,
        'summary'  => $summary,
        'biaya'    => ['gaji'=>$totalGaji,'kas_keluar'=>$totalKasKeluar,'total'=>$totalGaji+$totalKasKeluar],
        'profit'   => $profit,
        'profit_margin' => $profitMargin,
        'pelanggan'=> ['baru'=>$newCust,'baru_prev'=>$newCustPrev,'growth'=>$growth,'retention'=>$retention],
        'karyawan' => ['total'=>$totalKar,'gaji_total'=>$totalGaji],
        'coin_used'=> $totalCoin,
        'per_outlet' => $outlets,
        'timeline' => $timeline,
        'top_customers' => $topCust,
    ]);
    exit;
}

$allOutlets = $db->prepare("SELECT id, nama_outlet FROM outlets
                              WHERE tenant_id=? AND status IN ('trial','grace','active')
                              ORDER BY is_main DESC, nama_outlet ASC");
$allOutlets->execute([$tid]);
$outletOptions = $allOutlets->fetchAll();

$ownerNama  = $hqUser['nama'] ?? 'Owner';
$tenantNama = $hqTenant['nama_outlet'] ?? 'HQ';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HQ Laporan Konsolidasi — LAMASY</title>
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
  .hq-topbar a{color:rgba(255,255,255,.55);text-decoration:none;font-size:13px;padding:6px 10px;border-radius:6px}
  .hq-topbar a:hover{background:rgba(255,255,255,.08);color:#fff}
  .hq-topbar a.active{background:rgba(53,232,213,.15);color:#35E8D5}
  .hq-logout{border:1px solid rgba(255,255,255,.15);padding:6px 14px!important}

  .container{max-width:1320px;margin:24px auto;padding:0 20px 60px}
  h1{font-size:1.4rem;font-weight:800;color:#0F1C3A;margin-bottom:6px}
  h1 small{display:block;font-size:13px;font-weight:400;color:#6B7280;margin-top:2px}

  .filter-bar{background:#fff;border-radius:12px;padding:14px 18px;display:flex;gap:10px;
              flex-wrap:wrap;margin-bottom:16px;box-shadow:0 1px 6px rgba(0,0,0,.05);align-items:center}
  .filter-bar label{font-size:12px;color:#6B7280;font-weight:600;display:flex;align-items:center;gap:6px}
  .filter-bar input[type=date],.filter-bar select{padding:7px 10px;border:1.5px solid #E5E7EB;border-radius:6px;
                                                    font-size:13px;font-family:inherit;background:#fff}
  .filter-bar select{cursor:pointer;min-width:170px}
  .preset-btn{padding:7px 12px;background:#F3F4F6;border:1.5px solid transparent;
              border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;color:#374151;font-family:inherit}
  .preset-btn.active{background:#0F1C3A;color:#fff}
  .btn-export{margin-left:auto;background:#0F1C3A;color:#fff;border:none;padding:8px 16px;
              border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;
              display:inline-flex;align-items:center;gap:5px}
  .btn-export:hover{opacity:.9}

  .metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}
  .metric{background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 6px rgba(0,0,0,.05);border-top:3px solid #35E8D5}
  .metric.green{border-top-color:#34D399}.metric.red{border-top-color:#EF4444}
  .metric.purple{border-top-color:#8B5CF6}.metric.orange{border-top-color:#F59E0B}
  .metric.blue{border-top-color:#3B82F6}
  .metric-num{font-size:1.4rem;font-weight:800;color:#0F1C3A;font-family:monospace;margin-bottom:3px}
  .metric-label{font-size:12px;color:#6B7280;font-weight:600}
  .metric-sub{font-size:11px;color:#9CA3AF;margin-top:4px}
  .metric-growth{font-size:11px;font-weight:700;padding:2px 8px;border-radius:100px;display:inline-block;margin-top:4px}
  .gr-up{background:#D1FAE5;color:#065F46}.gr-down{background:#FEE2E2;color:#991B1B}.gr-flat{background:#F3F4F6;color:#6B7280}
  .profit-neg{color:#991B1B!important}.profit-pos{color:#065F46}

  .panel{background:#fff;border-radius:12px;padding:22px;box-shadow:0 1px 6px rgba(0,0,0,.05);margin-bottom:18px}
  .panel-title{font-size:14px;font-weight:700;color:#0F1C3A;margin-bottom:14px;
               display:flex;justify-content:space-between;align-items:center;gap:8px}
  .chart-box{height:280px;position:relative}

  table.outlets-tbl{width:100%;border-collapse:collapse;font-size:13px}
  table.outlets-tbl th{background:#F9FAFB;text-align:right;padding:9px 10px;font-size:10px;color:#6B7280;
                       font-weight:800;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #E5E7EB;white-space:nowrap}
  table.outlets-tbl th:first-child{text-align:left}
  table.outlets-tbl td{padding:12px 10px;border-bottom:1px solid #F3F4F6;text-align:right;
                       font-family:monospace;font-weight:700;color:#0F1C3A;white-space:nowrap}
  table.outlets-tbl td:first-child{text-align:left;font-family:inherit}
  table.outlets-tbl tr:last-child td{border-bottom:none}
  table.outlets-tbl tr.total-row{background:#F0FDFB}
  table.outlets-tbl tr.total-row td{border-top:2px solid #35E8D5;color:#0F1C3A;font-weight:800}
  .drill-btn{background:#0F1C3A;color:#fff;padding:5px 10px;border-radius:5px;font-size:11px;
             font-weight:700;border:none;cursor:pointer;text-decoration:none;font-family:inherit}
  .drill-btn:hover{background:#1a2d52}

  .top-row{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid #F3F4F6;font-size:13px;align-items:center}
  .top-row:last-child{border-bottom:none}
  .top-row .rank{background:#0F1C3A;color:#fff;width:20px;height:20px;border-radius:50%;
                 display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;margin-right:8px}
  .top-row .rank.r1{background:#F59E0B}.top-row .rank.r2{background:#94A3B8}.top-row .rank.r3{background:#D97706}
  .top-row .name strong{color:#0F1C3A;font-weight:700}
  .top-row .name small{display:block;color:#9CA3AF;font-size:11px;margin-top:1px}
  .top-row .amt{font-family:monospace;font-weight:700;color:#0F1C3A}

  .grid-2{display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:18px}

  @media(max-width:980px){
    .metrics{grid-template-columns:repeat(2,1fr)}
    .grid-2{grid-template-columns:1fr}
    table.outlets-tbl{font-size:12px}
    table.outlets-tbl th,table.outlets-tbl td{padding:7px 6px}
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
    <a href="/ERP/harpy/dashboard.php?to=hq">📊 Dashboard</a>
    <a href="/ERP/harpy/hq/outlet.php">🏪 Outlet</a>
    <a href="/ERP/harpy/hq/karyawan.php">👥 Karyawan</a>
    <a href="/ERP/harpy/hq/pelanggan.php">🧑‍🤝‍🧑 Pelanggan</a>
    <a href="/ERP/harpy/hq/promo.php">🎟️ Promo</a>
    <a href="/ERP/harpy/hq/laporan.php" class="active">📈 Laporan</a>
    <a href="/ERP/harpy/hq/roles.php">🔐 Role</a>
    <a href="/ERP/harpy/hq/settings.php">⚙️ Settings</a>
    <span><?= htmlspecialchars($ownerNama) ?></span>
    <a href="/ERP/harpy/dashboard.php?to=outlet">← Outlet View</a>
    <a href="/ERP/harpy/logout.php" class="hq-logout" onclick="return confirm('Yakin logout?')">Logout</a>
  </div>
</div>

<div class="container">
  <h1>📈 Laporan Konsolidasi
    <small>Lintas outlet · <?= htmlspecialchars($tenantNama) ?></small>
  </h1>

  <div class="filter-bar">
    <label>📅 <input type="date" id="dStart" value="<?= $defaultStart ?>"></label>
    <label>– <input type="date" id="dEnd" value="<?= $defaultEnd ?>"></label>
    <button class="preset-btn" onclick="setPreset('today',event)">Hari Ini</button>
    <button class="preset-btn" onclick="setPreset('week',event)">7 Hari</button>
    <button class="preset-btn active" onclick="setPreset('month',event)">Bulan Ini</button>
    <button class="preset-btn" onclick="setPreset('30d',event)">30 Hari</button>
    <select id="dOutlet" onchange="loadData()">
      <option value="0">📍 Semua Outlet</option>
      <?php foreach ($outletOptions as $o): ?>
        <option value="<?= (int)$o['id'] ?>">📍 <?= htmlspecialchars($o['nama_outlet']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="preset-btn" onclick="loadData()" style="background:#F0FDFB;color:#0891B2">↻ Refresh</button>
    <a id="exportBtn" href="#" class="btn-export">⬇️ Export CSV</a>
  </div>

  <!-- METRIC ROW 1 -->
  <div class="metrics">
    <div class="metric"><div class="metric-num" id="mOmset">-</div><div class="metric-label">Omset Total</div><div class="metric-sub" id="mOmsetSub">-</div></div>
    <div class="metric red"><div class="metric-num" id="mBiaya">-</div><div class="metric-label">Biaya Operasional</div><div class="metric-sub" id="mBiayaSub">Gaji + Kas keluar</div></div>
    <div class="metric green"><div class="metric-num" id="mProfit">-</div><div class="metric-label">Profit Estimasi</div><div class="metric-sub" id="mMargin">Margin: -</div></div>
    <div class="metric blue"><div class="metric-num" id="mPiutang">-</div><div class="metric-label">Piutang</div><div class="metric-sub">Belum lunas</div></div>
  </div>

  <!-- METRIC ROW 2 -->
  <div class="metrics">
    <div class="metric purple"><div class="metric-num" id="mNew">-</div><div class="metric-label">Pelanggan Baru</div><div id="mGrowth" class="metric-growth gr-flat">-</div></div>
    <div class="metric orange"><div class="metric-num" id="mRetention">-</div><div class="metric-label">Retensi Pelanggan</div><div class="metric-sub" id="mRetentionSub">-</div></div>
    <div class="metric"><div class="metric-num" id="mKaryawan">-</div><div class="metric-label">Total Karyawan</div><div class="metric-sub" id="mGaji">Gaji: -</div></div>
    <div class="metric blue"><div class="metric-num" id="mCoin">-</div><div class="metric-label">Coin Terpakai</div><div class="metric-sub">Lintas fitur</div></div>
  </div>

  <div class="grid-2">
    <div class="panel">
      <div class="panel-title">📊 Tren Omset Harian</div>
      <div class="chart-box"><canvas id="chartTimeline"></canvas></div>
    </div>
    <div class="panel">
      <div class="panel-title">🏆 Top Pelanggan</div>
      <div id="topCustList"><div style="color:#9CA3AF;font-size:12px">Memuat…</div></div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-title">
      📍 Breakdown per Outlet
      <span style="font-size:11px;font-weight:400;color:#9CA3AF">Klik 'Detail' untuk drill-down</span>
    </div>
    <div style="overflow-x:auto">
      <table class="outlets-tbl">
        <thead>
          <tr>
            <th>Outlet</th><th>Omset</th><th>Order</th><th>Gaji</th><th>Kas Keluar</th>
            <th>Profit</th><th>Karyawan</th><th>Coin</th><th style="text-align:right">Aksi</th>
          </tr>
        </thead>
        <tbody id="outletBreakdown">
          <tr><td colspan="9" style="text-align:center;color:#9CA3AF;padding:30px">Memuat…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
let chartT = null;
function escapeHtml(s){return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function fmtRp(n){return 'Rp ' + Number(n||0).toLocaleString('id-ID')}
function fmtShort(n){
  n = Number(n||0);
  if (n >= 1e9) return (n/1e9).toFixed(1)+'M';
  if (n >= 1e6) return (n/1e6).toFixed(1)+'jt';
  if (n >= 1e3) return (n/1e3).toFixed(0)+'rb';
  return n;
}

function setPreset(p, ev){
  document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
  if (ev && ev.target) ev.target.classList.add('active');
  const today = new Date();
  const fmt = d => d.toISOString().slice(0,10);
  let start, end = fmt(today);
  if (p === 'today')     start = fmt(today);
  else if (p === 'week') { const w = new Date(today); w.setDate(w.getDate()-6); start = fmt(w); }
  else if (p === 'month')start = today.getFullYear()+'-'+String(today.getMonth()+1).padStart(2,'0')+'-01';
  else if (p === '30d')  { const w = new Date(today); w.setDate(w.getDate()-29); start = fmt(w); }
  document.getElementById('dStart').value = start;
  document.getElementById('dEnd').value   = end;
  loadData();
}

async function loadData(){
  const start = document.getElementById('dStart').value;
  const end   = document.getElementById('dEnd').value;
  const oid   = document.getElementById('dOutlet').value;
  const params = `start=${start}&end=${end}&outlet_id=${oid}`;

  document.getElementById('exportBtn').href = '/ERP/harpy/hq/laporan.php?action=export&' + params;

  const r = await fetch('/ERP/harpy/hq/laporan.php?action=data&' + params);
  const d = await r.json();

  document.getElementById('mOmset').textContent = fmtRp(d.summary.omset);
  document.getElementById('mOmsetSub').textContent = `${Number(d.summary.total_order).toLocaleString('id-ID')} order · Lunas ${fmtRp(d.summary.lunas)}`;
  document.getElementById('mBiaya').textContent = fmtRp(d.biaya.total);
  document.getElementById('mBiayaSub').textContent = `Gaji ${fmtRp(d.biaya.gaji)} · Kas ${fmtRp(d.biaya.kas_keluar)}`;

  document.getElementById('mProfit').textContent = fmtRp(d.profit);
  document.getElementById('mProfit').className = 'metric-num ' + (d.profit < 0 ? 'profit-neg' : '');
  document.getElementById('mMargin').textContent = `Margin: ${d.profit_margin}%`;
  document.getElementById('mPiutang').textContent = fmtRp(d.summary.piutang);

  document.getElementById('mNew').textContent = d.pelanggan.baru;
  const gEl = document.getElementById('mGrowth');
  const g = d.pelanggan.growth;
  if (g > 0) { gEl.className = 'metric-growth gr-up';   gEl.textContent = '↑ +' + g + '% vs periode lalu'; }
  else if (g < 0) { gEl.className = 'metric-growth gr-down'; gEl.textContent = '↓ ' + g + '% vs periode lalu'; }
  else { gEl.className = 'metric-growth gr-flat'; gEl.textContent = d.pelanggan.baru_prev ? '— Sama dengan periode lalu' : '— No prev data'; }

  document.getElementById('mRetention').textContent = d.pelanggan.retention.rate + '%';
  document.getElementById('mRetentionSub').textContent = `${d.pelanggan.retention.repeat} repeat / ${d.pelanggan.retention.total_active} aktif`;

  document.getElementById('mKaryawan').textContent = d.karyawan.total;
  document.getElementById('mGaji').textContent = 'Total Gaji: ' + fmtRp(d.karyawan.gaji_total);

  document.getElementById('mCoin').textContent = Number(d.coin_used).toLocaleString('id-ID');

  const tbody = document.getElementById('outletBreakdown');
  if (d.per_outlet.length === 0) {
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#9CA3AF;padding:30px">Belum ada outlet</td></tr>';
  } else {
    let tO=0,tOr=0,tG=0,tK=0,tP=0,tKar=0,tC=0;
    let html = d.per_outlet.map(o => {
      tO+=+o.omset; tOr+=+o.order_count; tG+=+o.gaji; tK+=+o.kas_keluar;
      tP+=+o.profit; tKar+=+o.karyawan; tC+=+o.coin_used;
      return `
        <tr>
          <td><strong>📍 ${escapeHtml(o.nama_outlet)}</strong>
            <span style="font-size:9px;font-weight:700;padding:1px 6px;border-radius:100px;
                        background:${o.status==='trial'?'#DBEAFE':o.status==='grace'?'#FEF3C7':'#D1FAE5'};
                        color:${o.status==='trial'?'#1E40AF':o.status==='grace'?'#92400E':'#065F46'};
                        margin-left:5px;text-transform:uppercase">${o.status}</span>
          </td>
          <td>${fmtRp(o.omset)}</td>
          <td>${Number(o.order_count).toLocaleString('id-ID')}</td>
          <td>${fmtRp(o.gaji)}</td>
          <td>${fmtRp(o.kas_keluar)}</td>
          <td class="${o.profit<0?'profit-neg':'profit-pos'}">${fmtRp(o.profit)}</td>
          <td>${o.karyawan}</td>
          <td>${Number(o.coin_used).toLocaleString('id-ID')}</td>
          <td style="text-align:right">${d.outlet_filter == o.id ? '<span style="color:#9CA3AF;font-size:11px">(aktif)</span>' : `<button class="drill-btn" onclick="drillDown(${o.id})">Detail →</button>`}</td>
        </tr>
      `;
    }).join('');
    if (d.per_outlet.length > 1) {
      html += `<tr class="total-row">
        <td><strong>TOTAL</strong></td>
        <td>${fmtRp(tO)}</td>
        <td>${Number(tOr).toLocaleString('id-ID')}</td>
        <td>${fmtRp(tG)}</td>
        <td>${fmtRp(tK)}</td>
        <td class="${tP<0?'profit-neg':'profit-pos'}">${fmtRp(tP)}</td>
        <td>${tKar}</td>
        <td>${Number(tC).toLocaleString('id-ID')}</td>
        <td></td>
      </tr>`;
    }
    tbody.innerHTML = html;
  }

  document.getElementById('topCustList').innerHTML = d.top_customers.length === 0
    ? '<div style="color:#9CA3AF;font-size:13px;text-align:center;padding:20px">Belum ada data</div>'
    : d.top_customers.map((c, i) => `
        <div class="top-row">
          <div style="display:flex;align-items:center;min-width:0;flex:1">
            <div class="rank ${i<3?'r'+(i+1):''}">${i+1}</div>
            <div class="name" style="min-width:0">
              <strong>${escapeHtml(c.nama || '(tanpa nama)')}</strong>
              <small>${c.order_count} order${c.telepon ? ' · '+escapeHtml(c.telepon) : ''}</small>
            </div>
          </div>
          <div class="amt">${fmtRp(c.total_spend)}</div>
        </div>
      `).join('');

  const labels = d.timeline.map(t => new Date(t.d).toLocaleDateString('id-ID',{day:'2-digit',month:'short'}));
  const values = d.timeline.map(t => Number(t.s));
  if (chartT) chartT.destroy();
  const ctx = document.getElementById('chartTimeline').getContext('2d');
  chartT = new Chart(ctx, {
    type: 'line',
    data: { labels, datasets: [{ label:'Omset', data:values, borderColor:'#35E8D5',
      backgroundColor:'rgba(53,232,213,.15)', tension:0.3, fill:true, pointRadius:3 }] },
    options: {
      responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{display:false}, tooltip:{callbacks:{label: c => fmtRp(c.parsed.y)}} },
      scales:{ y:{ticks:{callback: v => 'Rp '+fmtShort(v)}}, x:{ticks:{maxRotation:0,autoSkip:true,maxTicksLimit:10}} }
    }
  });
}

function drillDown(outletId){
  document.getElementById('dOutlet').value = outletId;
  loadData();
  window.scrollTo({ top:0, behavior:'smooth' });
}

loadData();
</script>
</body>
</html>
