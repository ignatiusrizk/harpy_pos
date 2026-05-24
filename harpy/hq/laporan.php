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
require_once ROOT . '/core/AIInsight.php';
require_once ROOT . '/core/CoinLedger.php';

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

        // ── AOV (avg order value) ──
        $o['aov'] = $o['order_count'] > 0 ? (int)round($o['omset'] / $o['order_count']) : 0;

        // ── % order selesai tepat waktu ──
        // on-time = order yang sudah siap/diambil/selesai DAN
        //           (tidak punya estimasi ATAU diselesaikan <= estimasi_selesai)
        $o['ontime_pct'] = null;
        try {
            $s=$db->prepare("SELECT
                    COUNT(*) total_done,
                    SUM(CASE WHEN estimasi_selesai IS NULL
                              OR COALESCE(tgl_selesai, DATE(updated_at)) <= estimasi_selesai
                             THEN 1 ELSE 0 END) on_time
                  FROM hl_transaksi
                 WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal) BETWEEN ? AND ?
                   AND status_proses IN ('siap','diambil','selesai')");
            $s->execute([$tid,$oid,$start,$end]);
            $dr=$s->fetch();
            $totalDone=(int)$dr['total_done'];
            $o['ontime_pct'] = $totalDone > 0 ? round(((int)$dr['on_time'] / $totalDone) * 100, 1) : null;
        } catch (Throwable) {}

        // ── Growth vs bulan lalu (omset periode bulan ini vs bulan sebelumnya) ──
        $o['omset_prev_month'] = 0; $o['growth_pct'] = null;
        try {
            $prevMonthStart = date('Y-m-01', strtotime('first day of last month'));
            $prevMonthEnd   = date('Y-m-t', strtotime('last day of last month'));
            $thisMonthStart = date('Y-m-01');
            $s=$db->prepare("SELECT COALESCE(SUM(total),0) FROM hl_transaksi
                              WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal) BETWEEN ? AND ?");
            $s->execute([$tid,$oid,$prevMonthStart,$prevMonthEnd]);
            $prevM=(int)$s->fetchColumn();
            $o['omset_prev_month']=$prevM;

            $s->execute([$tid,$oid,$thisMonthStart,date('Y-m-d')]);
            $thisM=(int)$s->fetchColumn();
            $o['omset_this_month']=$thisM;
            $o['growth_pct'] = $prevM > 0 ? round((($thisM - $prevM) / $prevM) * 100, 1) : ($thisM > 0 ? 100 : null);
        } catch (Throwable) {}
    }
    unset($o);

    // ── Best & Worst performer MINGGU INI (Senin s/d sekarang) ──
    $weekRanking = null;
    try {
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $weekEnd   = date('Y-m-d');
        $wStmt = $db->prepare("SELECT o.id, o.nama_outlet,
                                      COALESCE(SUM(t.total),0) omset,
                                      COUNT(t.id) order_count
                                 FROM outlets o
                                 LEFT JOIN hl_transaksi t ON t.outlet_id=o.id
                                      AND DATE(t.tanggal) BETWEEN ? AND ?
                                WHERE o.tenant_id=? AND o.status IN ('trial','grace','active')
                                GROUP BY o.id, o.nama_outlet
                                ORDER BY omset DESC");
        $wStmt->execute([$weekStart, $weekEnd, $tid]);
        $weekRows = $wStmt->fetchAll();
        if (count($weekRows) >= 2) {
            $best  = $weekRows[0];
            $worst = end($weekRows);
            // Hanya tampilkan kalau best != worst (ada variasi)
            if ((int)$best['omset'] !== (int)$worst['omset']) {
                $weekRanking = [
                    'periode' => ['start'=>$weekStart, 'end'=>$weekEnd],
                    'best'  => ['nama'=>$best['nama_outlet'], 'omset'=>(int)$best['omset'], 'order'=>(int)$best['order_count']],
                    'worst' => ['nama'=>$worst['nama_outlet'], 'omset'=>(int)$worst['omset'], 'order'=>(int)$worst['order_count']],
                ];
            }
        }
    } catch (Throwable) {}

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

    // ── KAS KONSOLIDASI per kategori (masuk & keluar) ──
    $kasBreakdown = ['masuk'=>[], 'keluar'=>[], 'total_masuk'=>0, 'total_keluar'=>0];
    try {
        $sql = "SELECT tipe, kategori, COALESCE(SUM(jumlah),0) total, COUNT(*) cnt
                  FROM hl_kas
                 WHERE tenant_id=? AND tanggal BETWEEN ? AND ? $outletFilter
                 GROUP BY tipe, kategori ORDER BY total DESC";
        $s = $db->prepare($sql);
        $s->execute(array_merge([$tid,$start,$end], $extraParams));
        foreach ($s->fetchAll() as $row) {
            $entry = ['kategori'=>$row['kategori'] ?: '(tanpa kategori)', 'total'=>(int)$row['total'], 'cnt'=>(int)$row['cnt']];
            if ($row['tipe'] === 'masuk') { $kasBreakdown['masuk'][] = $entry; $kasBreakdown['total_masuk'] += (int)$row['total']; }
            else { $kasBreakdown['keluar'][] = $entry; $kasBreakdown['total_keluar'] += (int)$row['total']; }
        }
    } catch (Throwable) {}

    // ── OMSET per SEGMEN lintas outlet ──
    $segmenBreakdown = [];
    try {
        $segFilter = $oidArg > 0 ? " AND t.outlet_id=?" : "";
        $sql = "SELECT COALESCE(l.segmen,'lainnya') segmen,
                       COALESCE(SUM(ti.subtotal),0) total, COUNT(DISTINCT t.id) order_count
                  FROM hl_transaksi_item ti
                  JOIN hl_transaksi t ON t.id = ti.transaksi_id
                  LEFT JOIN hl_layanan l ON l.id = ti.layanan_id
                 WHERE t.tenant_id=? AND DATE(t.tanggal) BETWEEN ? AND ? $segFilter
                 GROUP BY COALESCE(l.segmen,'lainnya') ORDER BY total DESC";
        $s = $db->prepare($sql);
        $s->execute(array_merge([$tid,$start,$end], $extraParams));
        $segmenBreakdown = $s->fetchAll();
        foreach ($segmenBreakdown as &$sg) { $sg['total']=(int)$sg['total']; $sg['order_count']=(int)$sg['order_count']; }
        unset($sg);
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
        'week_ranking' => $weekRanking ?? null,
        'kas_breakdown' => $kasBreakdown,
        'segmen' => $segmenBreakdown,
    ]);
    exit;
}

// ══════════════════════════════════════════════════════
// AI INSIGHT — analisa laporan dengan Claude
// ══════════════════════════════════════════════════════
if ($action === 'ai_insight') {
    header('Content-Type: application/json');

    // Coin check
    $coinBalance = (int)($hqTenant['coin_balance'] ?? 0);
    if ($coinBalance < AIInsight::COIN_PER_INSIGHT) {
        echo json_encode(['error' => 'Coin tidak cukup. Butuh ' . AIInsight::COIN_PER_INSIGHT . ' coin per insight, saldo: ' . $coinBalance]);
        exit;
    }

    $start  = sanitizeDate($_GET['start'] ?? null, $defaultStart);
    $end    = sanitizeDate($_GET['end']   ?? null, $defaultEnd);
    $oidArg = (int)($_GET['outlet_id'] ?? 0);

    $outletFilter = $oidArg > 0 ? " AND outlet_id=?" : "";
    $extraParams  = $oidArg > 0 ? [$oidArg] : [];

    // Periode label
    $periodeLabel = date('d M Y', strtotime($start)) . ' — ' . date('d M Y', strtotime($end));

    // Summary
    $sumStmt = $db->prepare(
        "SELECT COUNT(*) total_order, COALESCE(SUM(total),0) omset
           FROM hl_transaksi WHERE tenant_id=? AND DATE(tanggal) BETWEEN ? AND ? $outletFilter"
    );
    $sumStmt->execute(array_merge([$tid,$start,$end], $extraParams));
    $summary = $sumStmt->fetch() ?: ['total_order'=>0,'omset'=>0];

    $omsetInt = (int)$summary['omset'];
    $orderCnt = (int)$summary['total_order'];
    $avgTicket = $orderCnt > 0 ? (int)round($omsetInt / $orderCnt) : 0;

    // Omset periode sebelumnya
    $startTs = strtotime($start);
    $endTs   = strtotime($end);
    $periodDays = max(1, (int)round(($endTs - $startTs) / 86400) + 1);
    $prevEnd   = date('Y-m-d', strtotime($start . ' -1 day'));
    $prevStart = date('Y-m-d', strtotime($prevEnd . " -" . ($periodDays - 1) . " days"));
    $omsetPrev = 0;
    try {
        $s = $db->prepare("SELECT COALESCE(SUM(total),0) FROM hl_transaksi
                            WHERE tenant_id=? AND DATE(tanggal) BETWEEN ? AND ? $outletFilter");
        $s->execute(array_merge([$tid,$prevStart,$prevEnd], $extraParams));
        $omsetPrev = (int)$s->fetchColumn();
    } catch (Throwable) {}

    // Top layanan
    $topLayanan = [];
    try {
        $sql = "SELECT ti.nama_layanan AS nama, COUNT(*) qty, COALESCE(SUM(ti.subtotal),0) total
                  FROM hl_transaksi_item ti
                  JOIN hl_transaksi t ON t.id = ti.transaksi_id
                 WHERE t.tenant_id=? AND DATE(t.tanggal) BETWEEN ? AND ?
                 " . ($oidArg > 0 ? " AND t.outlet_id=?" : "") . "
                 GROUP BY ti.nama_layanan ORDER BY total DESC LIMIT 5";
        $s = $db->prepare($sql);
        $s->execute(array_merge([$tid,$start,$end], $extraParams));
        $topLayanan = $s->fetchAll();
    } catch (Throwable) {}

    // Top karyawan
    $topKaryawan = [];
    try {
        $sql = "SELECT u.nama, COUNT(*) order_count
                  FROM hl_transaksi t
                  LEFT JOIN hl_users u ON u.id = t.user_id
                 WHERE t.tenant_id=? AND DATE(t.tanggal) BETWEEN ? AND ? AND t.user_id IS NOT NULL
                 " . ($oidArg > 0 ? " AND t.outlet_id=?" : "") . "
                 GROUP BY u.nama ORDER BY order_count DESC LIMIT 3";
        $s = $db->prepare($sql);
        $s->execute(array_merge([$tid,$start,$end], $extraParams));
        $rows = $s->fetchAll();
        foreach ($rows as $r) {
            $topKaryawan[] = ['nama' => $r['nama'] ?? '?', 'order' => (int)$r['order_count']];
        }
    } catch (Throwable) {}

    // Per outlet (HQ only)
    $perOutlet = [];
    if ($oidArg === 0) {
        try {
            $sql = "SELECT o.nama_outlet, COUNT(t.id) order_count, COALESCE(SUM(t.total),0) omset
                      FROM outlets o
                      LEFT JOIN hl_transaksi t ON t.outlet_id=o.id AND DATE(t.tanggal) BETWEEN ? AND ?
                     WHERE o.tenant_id=? AND o.status IN ('trial','grace','active')
                     GROUP BY o.id, o.nama_outlet ORDER BY omset DESC";
            $s = $db->prepare($sql);
            $s->execute([$start,$end,$tid]);
            $rows = $s->fetchAll();
            foreach ($rows as $r) {
                $perOutlet[] = [
                    'nama'  => $r['nama_outlet'],
                    'order' => (int)$r['order_count'],
                    'omset' => (int)$r['omset'],
                ];
            }
        } catch (Throwable) {}
    }

    // Build data untuk AI
    $aiData = [
        'periode_label' => $periodeLabel,
        'scope'         => $oidArg ? 'outlet' : 'hq',
        'omset'         => $omsetInt,
        'omset_prev'    => $omsetPrev,
        'order_count'   => $orderCnt,
        'avg_ticket'    => $avgTicket,
        'top_layanan'   => $topLayanan,
        'top_karyawan'  => $topKaryawan,
        'per_outlet'    => $perOutlet,
    ];

    try {
        $insight = AIInsight::analyzeLaporan($aiData, $tid, $oidArg ?: null);

        // Deduct coin kalau bukan dari cache
        if (empty($insight['from_cache'])) {
            try {
                CoinLedger::deduct('ai_insight_laporan', AIInsight::COIN_PER_INSIGHT,
                    "AI Insight laporan: $periodeLabel");
            } catch (Throwable $e) {
                error_log('[ai_insight coin deduct] ' . $e->getMessage());
            }

            try {
                logAudit('ai_insight', 'laporan', "Generate AI insight laporan $periodeLabel");
            } catch (Throwable) {}
        }

        echo json_encode([
            'ok'              => true,
            'summary'         => $insight['summary'],
            'highlights'      => $insight['highlights'],
            'recommendations' => $insight['recommendations'],
            'from_cache'      => $insight['from_cache'] ?? false,
            'tokens_used'     => $insight['tokens_used'] ?? 0,
            'generated_at'    => $insight['generated_at'] ?? date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'AI Insight gagal: ' . $e->getMessage()]);
    }
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
<?php
$pageTitle  = 'Laporan Konsolidasi';
$activePage = 'hq-laporan';
require __DIR__ . '/_layout_open.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
  h1{font-size:1.4rem;font-weight:800;color:#0F1C3A;margin-bottom:6px}
  h1 small{display:block;font-size:13px;font-weight:400;color:#6B7280;margin-top:2px}

  /* Print header (hanya tampil saat cetak) */
  .print-header{display:none}

  @media print{
    /* Sembunyikan kerangka HQ + kontrol interaktif */
    .hq-side, .hq-top, .filter-bar, #exportBtn, .preset-btn, .drill-btn,
    .btn-export, #aiInsightPanel { display:none !important; }
    .hq-content, .hq-content-inner { padding:0 !important; margin:0 !important; max-width:100% !important; }
    body, .hq-shell, .hq-main { background:#fff !important; }
    .panel, .wk-card { box-shadow:none !important; border:1px solid #ddd !important; break-inside:avoid; }
    .print-header{display:block;margin-bottom:14px;border-bottom:2px solid #0F1C3A;padding-bottom:8px}
    .print-header h2{font-size:18px;font-weight:800;color:#0F1C3A}
    .print-header p{font-size:12px;color:#444;margin-top:2px}
    @page{margin:1.2cm}
  }

  /* Mode PDF (saat html2pdf jalan) — mirror print CSS */
  body.pdf-mode .hq-side,
  body.pdf-mode .hq-top,
  body.pdf-mode .filter-bar,
  body.pdf-mode #exportBtn,
  body.pdf-mode .preset-btn,
  body.pdf-mode .drill-btn,
  body.pdf-mode .btn-export,
  body.pdf-mode #aiInsightPanel { display:none !important; }
  body.pdf-mode .hq-content, body.pdf-mode .hq-content-inner { padding:0 !important; max-width:100% !important; }
  body.pdf-mode, body.pdf-mode .hq-shell, body.pdf-mode .hq-main { background:#fff !important; }
  body.pdf-mode .panel, body.pdf-mode .wk-card { box-shadow:none !important; border:1px solid #ddd !important; }
  body.pdf-mode .print-header { display:block !important; margin-bottom:14px;
    border-bottom:2px solid #0F1C3A; padding-bottom:8px }
  body.pdf-mode .print-header h2 { font-size:18px; font-weight:800; color:#0F1C3A }
  body.pdf-mode .print-header p { font-size:12px; color:#444; margin-top:2px }

  .pdf-overlay{position:fixed;inset:0;background:rgba(15,28,58,.85);color:#fff;
    display:none;align-items:center;justify-content:center;z-index:9999;font-size:15px;font-weight:700}
  .pdf-overlay.show{display:flex}
  .pdf-overlay .spin{display:inline-block;animation:spin 1.2s linear infinite;margin-right:10px;font-size:20px}
  @keyframes spin{to{transform:rotate(360deg)}}

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
  .wk-card{background:#fff;border-radius:12px;padding:18px 20px;box-shadow:0 1px 6px rgba(0,0,0,.05)}
  .wk-card.best{border-left:4px solid #F59E0B;background:linear-gradient(135deg,#FFFBEB,#fff)}
  .wk-card.worst{border-left:4px solid #6B7280;background:linear-gradient(135deg,#F9FAFB,#fff)}
  .wk-label{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
  .wk-name{font-size:1.15rem;font-weight:800;color:#0F1C3A;margin-bottom:4px}
  .wk-omset{font-size:1.2rem;font-weight:800;color:#0F1C3A;font-family:monospace}
  .wk-omset small{display:block;font-size:11px;font-weight:400;color:#9CA3AF;margin-top:2px}
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

  <h1>📈 Laporan Konsolidasi
    <small>Lintas outlet · <?= htmlspecialchars($tenantNama) ?></small>
  </h1>

  <!-- Print-only header -->
  <div class="print-header">
    <h2>Laporan Keuangan Konsolidasi — <?= htmlspecialchars($tenantNama) ?></h2>
    <p id="printPeriode">Periode: -</p>
  </div>

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
    <button class="preset-btn" onclick="loadAiInsight()"
            style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border-color:transparent">
      ✨ AI Insight
    </button>
    <a id="exportBtn" href="#" class="btn-export">⬇️ Export CSV</a>
    <button class="btn-export" onclick="downloadPdf()" style="background:#DC2626">📄 Unduh PDF</button>
    <button class="btn-export" onclick="window.print()" style="background:#0891B2">🖨️ Cetak</button>
  </div>

  <!-- AI INSIGHT PANEL -->
  <div id="aiInsightPanel" style="display:none;margin-bottom:18px;background:linear-gradient(135deg,#0F1C3A,#1a2d52);
       border-radius:14px;padding:24px 28px;color:#fff;position:relative;overflow:hidden">
    <div style="position:absolute;top:-30px;right:-30px;width:200px;height:200px;
                background:radial-gradient(circle,rgba(53,232,213,.15),transparent);border-radius:50%"></div>
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;position:relative">
      <div>
        <div style="font-size:11px;font-weight:800;letter-spacing:.08em;color:#35E8D5;margin-bottom:4px">
          ✨ AI INSIGHT
        </div>
        <div id="aiInsightTitle" style="font-size:14px;color:rgba(255,255,255,.6)">Analisa periode terpilih</div>
      </div>
      <button onclick="document.getElementById('aiInsightPanel').style.display='none'"
              style="background:rgba(255,255,255,.08);border:none;color:#fff;width:28px;height:28px;
                     border-radius:6px;cursor:pointer;font-size:14px">✕</button>
    </div>
    <div id="aiInsightLoading" style="display:none;text-align:center;padding:30px 20px">
      <div style="font-size:32px;animation:aiSpin 1.5s linear infinite">⏳</div>
      <div style="font-size:13px;color:rgba(255,255,255,.6);margin-top:8px">Claude sedang menganalisa data…</div>
    </div>
    <div id="aiInsightContent" style="display:none">
      <div id="aiSummary" style="font-size:14px;line-height:1.65;color:rgba(255,255,255,.92);
           background:rgba(255,255,255,.05);padding:14px 16px;border-radius:10px;
           border-left:3px solid #35E8D5;margin-bottom:18px"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div>
          <div style="font-size:11px;font-weight:800;color:#35E8D5;letter-spacing:.08em;margin-bottom:8px">
            📊 HIGHLIGHTS
          </div>
          <ul id="aiHighlights" style="list-style:none;padding:0;margin:0;font-size:13px;color:rgba(255,255,255,.85);line-height:1.7"></ul>
        </div>
        <div>
          <div style="font-size:11px;font-weight:800;color:#F59E0B;letter-spacing:.08em;margin-bottom:8px">
            💡 REKOMENDASI
          </div>
          <ul id="aiRecommendations" style="list-style:none;padding:0;margin:0;font-size:13px;color:rgba(255,255,255,.85);line-height:1.7"></ul>
        </div>
      </div>
      <div id="aiMeta" style="margin-top:14px;font-size:10px;color:rgba(255,255,255,.4);letter-spacing:.05em"></div>
    </div>
    <div id="aiInsightError" style="display:none;padding:20px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);
         border-radius:10px;font-size:13px;color:#FCA5A5"></div>
  </div>
  <style>@keyframes aiSpin{from{transform:rotate(0)}to{transform:rotate(360deg)}}
  #aiHighlights li, #aiRecommendations li{padding:5px 0 5px 16px;position:relative}
  #aiHighlights li:before{content:'▸';position:absolute;left:0;color:#35E8D5}
  #aiRecommendations li:before{content:'→';position:absolute;left:0;color:#F59E0B}</style>

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

  <!-- BEST & WORST MINGGU INI -->
  <div id="weekRankingWrap" style="display:none;margin-bottom:18px">
    <div class="panel-title" style="margin-bottom:10px">🗓️ Performer Minggu Ini
      <span id="weekPeriode" style="font-size:11px;font-weight:400;color:#9CA3AF"></span>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div class="wk-card best">
        <div class="wk-label" style="color:#D97706">🏆 Terbaik Minggu Ini</div>
        <div class="wk-name" id="wkBestName">-</div>
        <div class="wk-omset" id="wkBestOmset">-</div>
      </div>
      <div class="wk-card worst">
        <div class="wk-label" style="color:#6B7280">📉 Perlu Perhatian</div>
        <div class="wk-name" id="wkWorstName">-</div>
        <div class="wk-omset" id="wkWorstOmset">-</div>
      </div>
    </div>
  </div>

  <!-- P&L KONSOLIDASI -->
  <div class="panel" id="pnlPanel">
    <div class="panel-title">📒 Laba Rugi (P&amp;L) Konsolidasi
      <span id="pnlScope" style="font-size:11px;font-weight:400;color:#9CA3AF"></span>
    </div>
    <div id="pnlBox"><div style="color:#9CA3AF;font-size:13px;text-align:center;padding:20px">-</div></div>
  </div>

  <!-- OMSET PER SEGMEN + KAS KONSOLIDASI -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px" id="finGrid">
    <div class="panel" style="margin:0">
      <div class="panel-title">🧷 Omset per Segmen</div>
      <div id="segmenBox"><div style="color:#9CA3AF;font-size:13px;text-align:center;padding:20px">-</div></div>
    </div>
    <div class="panel" style="margin:0">
      <div class="panel-title">💵 Kas Konsolidasi per Kategori</div>
      <div id="kasBox"><div style="color:#9CA3AF;font-size:13px;text-align:center;padding:20px">-</div></div>
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
            <th>Outlet</th><th>Omset</th><th>Order</th><th>AOV</th><th>% Tepat Waktu</th>
            <th>Growth</th><th>Profit</th><th>Coin</th><th style="text-align:right">Aksi</th>
          </tr>
        </thead>
        <tbody id="outletBreakdown">
          <tr><td colspan="9" style="text-align:center;color:#9CA3AF;padding:30px">Memuat…</td></tr>
        </tbody>
      </table>
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

async function loadAiInsight(){
  const start = document.getElementById('dStart').value;
  const end   = document.getElementById('dEnd').value;
  const oid   = document.getElementById('dOutlet').value;

  const panel    = document.getElementById('aiInsightPanel');
  const loading  = document.getElementById('aiInsightLoading');
  const content  = document.getElementById('aiInsightContent');
  const errBox   = document.getElementById('aiInsightError');
  const titleEl  = document.getElementById('aiInsightTitle');

  panel.style.display = 'block';
  loading.style.display = 'block';
  content.style.display = 'none';
  errBox.style.display = 'none';
  panel.scrollIntoView({behavior:'smooth', block:'start'});

  const oidName = document.getElementById('dOutlet').options[document.getElementById('dOutlet').selectedIndex].text;
  titleEl.textContent = `Periode ${start} → ${end} · ${oidName}`;

  try {
    const r = await fetch(`/ERP/harpy/hq/laporan.php?action=ai_insight&start=${start}&end=${end}&outlet_id=${oid}`);
    const d = await r.json();
    loading.style.display = 'none';

    if (d.error) {
      errBox.textContent = d.error;
      errBox.style.display = 'block';
      return;
    }

    document.getElementById('aiSummary').textContent = d.summary || '(Tidak ada ringkasan)';

    const hlUl = document.getElementById('aiHighlights');
    hlUl.innerHTML = (d.highlights || []).map(h => `<li>${escapeHtml(h)}</li>`).join('') || '<li>—</li>';

    const recUl = document.getElementById('aiRecommendations');
    recUl.innerHTML = (d.recommendations || []).map(h => `<li>${escapeHtml(h)}</li>`).join('') || '<li>—</li>';

    const meta = [];
    if (d.from_cache) meta.push('⚡ Dari cache (24 jam)');
    else meta.push(`💬 ${d.tokens_used || 0} tokens · 100 coin terpotong`);
    if (d.generated_at) meta.push(`🕒 ${d.generated_at}`);
    document.getElementById('aiMeta').textContent = meta.join(' · ');

    content.style.display = 'block';
  } catch (e) {
    loading.style.display = 'none';
    errBox.textContent = 'Gagal koneksi: ' + e.message;
    errBox.style.display = 'block';
  }
}

// ── P&L Konsolidasi ──
function renderPnl(d){
  const box = document.getElementById('pnlBox');
  const omset = Number(d.summary.omset) || 0;
  const gaji  = Number(d.biaya.gaji) || 0;
  const kasKeluar = (d.kas_breakdown && d.kas_breakdown.keluar) ? d.kas_breakdown.keluar : [];
  const totalKasKeluar = Number(d.biaya.kas_keluar) || 0;
  const totalBeban = gaji + totalKasKeluar;
  const labaBersih = omset - totalBeban;
  const margin = omset > 0 ? Math.round(labaBersih/omset*1000)/10 : 0;

  const row = (label, val, opt={}) => `
    <div style="display:flex;justify-content:space-between;padding:${opt.indent?'5px 0 5px 18px':'7px 0'};
                font-size:${opt.bold?'14px':'13px'};
                ${opt.border?'border-top:1px solid #EEF1F8;margin-top:4px;padding-top:10px':''};
                color:${opt.color||'#374151'};font-weight:${opt.bold?'800':opt.head?'700':'400'}">
      <span>${label}</span>
      <span style="font-family:monospace;font-weight:${opt.bold?'800':'600'}">${fmtRp(val)}</span>
    </div>`;

  let html = '';
  html += '<div style="font-size:11px;font-weight:800;color:#10B981;letter-spacing:.05em;margin-bottom:2px">PENDAPATAN</div>';
  html += row('Pendapatan Jasa (Omset)', omset, {indent:true});
  html += row('Total Pendapatan', omset, {head:true});

  html += '<div style="font-size:11px;font-weight:800;color:#EF4444;letter-spacing:.05em;margin:12px 0 2px">BEBAN OPERASIONAL</div>';
  html += row('Gaji Karyawan', gaji, {indent:true});
  if (kasKeluar.length){
    kasKeluar.forEach(k => html += row(escapeHtml(k.kategori), k.total, {indent:true}));
  } else {
    html += row('Beban operasional lain', totalKasKeluar, {indent:true});
  }
  html += row('Total Beban', totalBeban, {head:true});

  html += row('LABA BERSIH', labaBersih,
    {bold:true, border:true, color: labaBersih>=0 ? '#065F46' : '#991B1B'});
  html += `<div style="display:flex;justify-content:space-between;font-size:12px;color:#6B7280;padding-top:4px">
    <span>Margin Laba</span>
    <span style="font-weight:700;color:${margin>=0?'#10B981':'#EF4444'}">${margin}%</span></div>`;
  html += `<div style="font-size:10px;color:#9CA3AF;margin-top:8px">* Laba estimasi berbasis omset (accrual) − gaji − kas keluar. Kas masuk non-penjualan dilihat di panel Kas Konsolidasi.</div>`;

  box.innerHTML = html;
}

// ── Omset per Segmen ──
const SEGMEN_LABEL = {kiloan:'🧺 Kiloan', self_service:'🪙 Self-Service', b2b:'🏢 B2B', satuan:'👕 Satuan', lainnya:'📦 Lainnya'};
function renderSegmen(seg){
  const box = document.getElementById('segmenBox');
  if (!seg.length){ box.innerHTML = '<div style="color:#9CA3AF;font-size:13px;text-align:center;padding:20px">Belum ada data segmen</div>'; return; }
  const total = seg.reduce((a,s)=>a+Number(s.total),0) || 1;
  box.innerHTML = seg.map(s => {
    const pct = Math.round(Number(s.total)/total*100);
    return `<div style="margin-bottom:10px">
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:3px">
        <span style="font-weight:600;color:#0F1C3A">${SEGMEN_LABEL[s.segmen]||escapeHtml(s.segmen)}</span>
        <span style="font-family:monospace;font-weight:700">${fmtRp(s.total)} <span style="color:#9CA3AF;font-weight:400">(${pct}%)</span></span>
      </div>
      <div style="background:#EEF1F8;border-radius:100px;height:7px;overflow:hidden">
        <div style="background:#35E8D5;height:100%;width:${pct}%"></div>
      </div>
      <div style="font-size:10px;color:#9CA3AF;margin-top:2px">${s.order_count} order</div>
    </div>`;
  }).join('');
}

// ── Kas Konsolidasi ──
function renderKas(kas){
  const box = document.getElementById('kasBox');
  if (!kas || (!kas.masuk.length && !kas.keluar.length)){
    box.innerHTML = '<div style="color:#9CA3AF;font-size:13px;text-align:center;padding:20px">Belum ada data kas</div>'; return;
  }
  const sec = (title, rows, color, total) => `
    <div style="font-size:12px;font-weight:700;color:${color};margin:6px 0 4px">${title} · ${fmtRp(total)}</div>
    ${rows.length ? rows.map(r=>`
      <div style="display:flex;justify-content:space-between;font-size:12px;padding:3px 0;color:#374151">
        <span>${escapeHtml(r.kategori)} <span style="color:#9CA3AF">(${r.cnt})</span></span>
        <span style="font-family:monospace">${fmtRp(r.total)}</span>
      </div>`).join('') : '<div style="font-size:11px;color:#9CA3AF">—</div>'}`;
  box.innerHTML = sec('⬇️ Kas Masuk', kas.masuk, '#10B981', kas.total_masuk)
    + '<div style="height:8px"></div>'
    + sec('⬆️ Kas Keluar', kas.keluar, '#EF4444', kas.total_keluar);
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

  // Print header periode
  if (d.periode){
    const pp = document.getElementById('printPeriode');
    if (pp) pp.textContent = `Periode: ${d.periode.start} s/d ${d.periode.end}` +
      (d.outlet_filter ? ' · Outlet terpilih' : ' · Semua outlet');
  }
  // P&L konsolidasi
  renderPnl(d);
  // Omset per segmen
  renderSegmen(d.segmen || []);
  // Kas konsolidasi
  renderKas(d.kas_breakdown || null);

  // Best/Worst minggu ini
  const wkWrap = document.getElementById('weekRankingWrap');
  if (d.week_ranking) {
    const w = d.week_ranking;
    document.getElementById('weekPeriode').textContent =
      `${new Date(w.periode.start).toLocaleDateString('id-ID',{day:'2-digit',month:'short'})} – ${new Date(w.periode.end).toLocaleDateString('id-ID',{day:'2-digit',month:'short'})}`;
    document.getElementById('wkBestName').textContent = '📍 ' + w.best.nama;
    document.getElementById('wkBestOmset').innerHTML = fmtRp(w.best.omset) + `<small>${w.best.order} order minggu ini</small>`;
    document.getElementById('wkWorstName').textContent = '📍 ' + w.worst.nama;
    document.getElementById('wkWorstOmset').innerHTML = fmtRp(w.worst.omset) + `<small>${w.worst.order} order minggu ini</small>`;
    wkWrap.style.display = 'block';
  } else {
    wkWrap.style.display = 'none';
  }

  const tbody = document.getElementById('outletBreakdown');
  if (d.per_outlet.length === 0) {
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#9CA3AF;padding:30px">Belum ada outlet</td></tr>';
  } else {
    let tO=0,tOr=0,tP=0,tC=0;
    const fmtOntime = p => p === null || p === undefined
      ? '<span style="color:#9CA3AF">—</span>'
      : `<span style="font-weight:700;color:${p>=90?'#10B981':p>=70?'#F59E0B':'#EF4444'}">${p}%</span>`;
    const fmtGrowth = g => {
      if (g === null || g === undefined) return '<span style="color:#9CA3AF">—</span>';
      if (g > 0) return `<span style="color:#10B981;font-weight:700">▲ ${g}%</span>`;
      if (g < 0) return `<span style="color:#EF4444;font-weight:700">▼ ${Math.abs(g)}%</span>`;
      return '<span style="color:#9CA3AF">→ 0%</span>';
    };
    let html = d.per_outlet.map(o => {
      tO+=+o.omset; tOr+=+o.order_count; tP+=+o.profit; tC+=+o.coin_used;
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
          <td>${fmtRp(o.aov)}</td>
          <td>${fmtOntime(o.ontime_pct)}</td>
          <td>${fmtGrowth(o.growth_pct)}</td>
          <td class="${o.profit<0?'profit-neg':'profit-pos'}">${fmtRp(o.profit)}</td>
          <td>${Number(o.coin_used).toLocaleString('id-ID')}</td>
          <td style="text-align:right">${d.outlet_filter == o.id ? '<span style="color:#9CA3AF;font-size:11px">(aktif)</span>' : `<button class="drill-btn" onclick="drillDown(${o.id})">Detail →</button>`}</td>
        </tr>
      `;
    }).join('');
    if (d.per_outlet.length > 1) {
      const tAov = tOr > 0 ? Math.round(tO / tOr) : 0;
      html += `<tr class="total-row">
        <td><strong>TOTAL</strong></td>
        <td>${fmtRp(tO)}</td>
        <td>${Number(tOr).toLocaleString('id-ID')}</td>
        <td>${fmtRp(tAov)}</td>
        <td></td>
        <td></td>
        <td class="${tP<0?'profit-neg':'profit-pos'}">${fmtRp(tP)}</td>
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

// ── Download PDF native (html2pdf.js) ──
async function downloadPdf(){
  if (typeof html2pdf === 'undefined') { alert('Library PDF belum dimuat. Coba refresh halaman.'); return; }
  const target = document.querySelector('.hq-content-inner');
  if (!target) return;

  // Overlay loading
  let overlay = document.getElementById('pdfOverlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.id = 'pdfOverlay'; overlay.className = 'pdf-overlay';
    overlay.innerHTML = '<span class="spin">⏳</span> Membuat PDF…';
    document.body.appendChild(overlay);
  }
  overlay.classList.add('show');
  document.body.classList.add('pdf-mode');

  const start = document.getElementById('dStart').value;
  const end   = document.getElementById('dEnd').value;
  const oidSel = document.getElementById('dOutlet');
  const oidName = oidSel.options[oidSel.selectedIndex].text.replace(/[^\w-]/g,'_');
  const filename = `Laporan_${start}_${end}_${oidName}.pdf`;

  try {
    await html2pdf().from(target).set({
      margin: [10, 8, 10, 8],
      filename,
      image: { type: 'jpeg', quality: 0.95 },
      html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
      jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
      pagebreak: { mode: ['avoid-all','css','legacy'] }
    }).save();
  } catch(e){ alert('Gagal generate PDF: ' + e.message); }
  finally {
    document.body.classList.remove('pdf-mode');
    overlay.classList.remove('show');
  }
}

loadData();
</script>
<?php require __DIR__ . '/_layout_close.php'; ?>
