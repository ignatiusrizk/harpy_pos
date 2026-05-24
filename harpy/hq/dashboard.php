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
require_once ROOT . '/core/AIInsight.php';
require_once ROOT . '/core/CoinLedger.php';

$db    = Database::get();
$tid   = (int)$hqTenant['id'];
$today = date('Y-m-d');
$thisMonth = date('Y-m');
$startTimeline = date('Y-m-d', strtotime('-6 days'));

// ── AJAX: AI Briefing HQ ─────────────────────────────
if (($_GET['action'] ?? '') === 'ai_briefing') {
    // ── Hardening: jangan biarkan PHP warning/notice/fatal leak HTML ke client ──
    @error_reporting(E_ALL);
    @ini_set('display_errors', '0');
    if (ob_get_level() === 0) ob_start();
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            // Buang output HTML yang sudah keluar, ganti dengan JSON error
            while (ob_get_level() > 0) ob_end_clean();
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json');
            }
            echo json_encode(['error' => 'Server error: ' . $err['message']]);
        }
    });
    header('Content-Type: application/json');

    // Cek cache dulu (gratis kalau sudah ada hari ini) — peek tanpa generate
    $peekOnly = !empty($_GET['peek']);

    // Kumpulkan data konsolidasi hari ini
    $tg = $db->prepare("SELECT COALESCE(SUM(total),0) omset, COUNT(*) cnt
                          FROM hl_transaksi WHERE tenant_id=? AND DATE(tanggal)=?");
    $tg->execute([$tid, $today]);
    $tgRow = $tg->fetch() ?: ['omset'=>0,'cnt'=>0];

    $aktif = 0; $siap = 0; $selesai = 0;
    try { $s=$db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=? AND status_proses NOT IN ('siap','selesai','batal','dibatalkan')"); $s->execute([$tid]); $aktif=(int)$s->fetchColumn(); } catch (Throwable) {}
    try { $s=$db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=? AND status_proses='siap'"); $s->execute([$tid]); $siap=(int)$s->fetchColumn(); } catch (Throwable) {}
    try { $s=$db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=? AND status_proses IN ('diambil','selesai') AND DATE(tanggal)=?"); $s->execute([$tid,$today]); $selesai=(int)$s->fetchColumn(); } catch (Throwable) {}

    // Per outlet ringkas
    $outletsBrief = [];
    try {
        $os = $db->prepare("SELECT id, nama_outlet FROM outlets WHERE tenant_id=? AND status IN ('trial','grace','active') ORDER BY is_main DESC, nama_outlet");
        $os->execute([$tid]);
        foreach ($os->fetchAll() as $o) {
            $oid = (int)$o['id'];
            $om = $db->prepare("SELECT COALESCE(SUM(total),0) s, COUNT(*) c FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal)=?");
            $om->execute([$tid,$oid,$today]); $omr = $om->fetch();
            $oa = $db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND status_proses NOT IN ('siap','selesai','batal','dibatalkan')");
            $oa->execute([$tid,$oid]);
            $outletsBrief[] = [
                'nama'        => $o['nama_outlet'],
                'omset_today' => (int)$omr['s'],
                'order_today' => (int)$omr['c'],
                'order_aktif' => (int)$oa->fetchColumn(),
            ];
        }
    } catch (Throwable) {}

    // Alert operasional ringkas (untuk konteks AI)
    $briefAlerts = [];
    foreach ($outletsBrief as $ob) {
        // skip — alert detail dihitung di body, briefing pakai ringkas saja
    }

    $aiData = [
        'tanggal_label'   => date('l, d F Y'),
        'omset_today'     => (int)$tgRow['omset'],
        'order_today'     => (int)$tgRow['cnt'],
        'order_aktif'     => $aktif,
        'pipeline_siap'   => $siap,
        'pipeline_selesai'=> $selesai,
        'outlets'         => $outletsBrief,
        'alerts'          => $briefAlerts,
    ];

    // Kalau peek & tidak ada cache → jangan generate (hindari auto-charge)
    if ($peekOnly) {
        $cached = AIInsight::peekCache($tid, AIInsight::briefingCacheKey());
        if ($cached === null) {
            echo json_encode(['ok'=>true, 'cached'=>false]);
            exit;
        }
        echo json_encode(['ok'=>true, 'cached'=>true,
            'briefing'=>$cached['briefing'] ?? '', 'generated_at'=>$cached['generated_at'] ?? '']);
        exit;
    }

    // HQ adalah tenant-level, bukan outlet-level → query coin langsung dari tabel tenants
    $costBriefing = CoinLedger::COSTS['ai_briefing_hq'] ?? 80;
    $tenantCoin = (int)($hqTenant['coin_balance'] ?? 0);
    if ($tenantCoin < $costBriefing) {
        echo json_encode(['error' => "Coin tenant tidak cukup. Butuh $costBriefing coin, saldo: $tenantCoin"]);
        exit;
    }

    try {
        $b = AIInsight::briefingHQ($aiData, $tid);
        if (empty($b['from_cache'])) {
            // Deduct tenant-scoped: update tenants.coin_balance + catat ledger
            try {
                $db->beginTransaction();
                $st = $db->prepare("SELECT coin_balance FROM tenants WHERE id=? FOR UPDATE");
                $st->execute([$tid]);
                $cur = (int)$st->fetchColumn();
                if ($cur >= $costBriefing) {
                    $newBal = $cur - $costBriefing;
                    $db->prepare("UPDATE tenants SET coin_balance=? WHERE id=?")
                       ->execute([$newBal, $tid]);
                    $db->prepare("INSERT INTO coin_ledger
                        (tenant_id, outlet_id, type, amount, feature_used, description, balance_after, ref_id)
                        VALUES (?, NULL, 'deduct', ?, ?, ?, ?, ?)")
                       ->execute([$tid, $costBriefing, 'ai_briefing_hq',
                                  'AI Briefing HQ ' . date('Y-m-d'), $newBal, 'briefing_hq_'.date('Y-m-d')]);
                    $db->commit();
                } else {
                    $db->rollBack();
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('[hq/ai_briefing] deduct gagal: ' . $e->getMessage());
            }
            try { logAudit('ai_briefing', 'dashboard', 'Generate briefing HQ ' . date('Y-m-d')); } catch (Throwable) {}
        }
        // Buang accidental output (warning/notice yang lolos) sebelum kirim JSON
        while (ob_get_level() > 0) { $junk = ob_get_clean(); if (!empty($junk)) error_log('[hq/ai_briefing] stray output: '.substr($junk,0,300)); }
        echo json_encode([
            'ok'           => true,
            'briefing'     => $b['briefing'],
            'from_cache'   => $b['from_cache'] ?? false,
            'generated_at' => $b['generated_at'] ?? date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        error_log('[hq/ai_briefing] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        echo json_encode(['error' => 'Briefing gagal: ' . $e->getMessage()]);
    }
    exit;
}

// ── AJAX: live metrics (polling real-time) ───────────
if (($_GET['action'] ?? '') === 'live') {
    header('Content-Type: application/json');
    $today = date('Y-m-d');
    try {
        $tg = $db->prepare("SELECT COALESCE(SUM(total),0) omset, COUNT(*) cnt
                              FROM hl_transaksi WHERE tenant_id=? AND DATE(tanggal)=?");
        $tg->execute([$tid, $today]); $tgr = $tg->fetch();

        $aktif=0;$siap=0;$selesai=0;
        $s=$db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=? AND status_proses NOT IN ('siap','selesai','batal','dibatalkan')");
        $s->execute([$tid]); $aktif=(int)$s->fetchColumn();
        $s=$db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=? AND status_proses='siap'");
        $s->execute([$tid]); $siap=(int)$s->fetchColumn();
        $s=$db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=? AND status_proses IN ('diambil','selesai') AND DATE(tanggal)=?");
        $s->execute([$tid,$today]); $selesai=(int)$s->fetchColumn();

        // Per outlet omset hari ini
        $outletsLive = [];
        $os=$db->prepare("SELECT o.id, COALESCE(SUM(t.total),0) omset, COUNT(t.id) cnt
                            FROM outlets o
                            LEFT JOIN hl_transaksi t ON t.outlet_id=o.id AND t.tenant_id=o.tenant_id AND DATE(t.tanggal)=?
                           WHERE o.tenant_id=? AND o.status IN ('trial','grace','active')
                           GROUP BY o.id");
        $os->execute([$today, $tid]);
        foreach ($os->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $outletsLive[(int)$r['id']] = ['omset'=>(int)$r['omset'], 'cnt'=>(int)$r['cnt']];
        }

        echo json_encode([
            'ok'=>true,
            'omset_today'=>(int)$tgr['omset'], 'order_today'=>(int)$tgr['cnt'],
            'order_aktif'=>$aktif, 'pipeline'=>['proses'=>$aktif,'siap'=>$siap,'selesai'=>$selesai],
            'outlets'=>$outletsLive,
            'ts'=>date('H:i:s'),
        ]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

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

// ── PIPELINE KONSOLIDASI (proses / siap diambil / selesai hari ini) ──
$pipeline = ['proses' => $orderAktif, 'siap' => 0, 'selesai_today' => 0];
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM hl_transaksi
                            WHERE tenant_id=? AND status_proses='siap'");
    $stmt->execute([$tid]);
    $pipeline['siap'] = (int)$stmt->fetchColumn();
} catch (Throwable) {}
try {
    // Selesai hari ini = diambil/selesai dengan tanggal hari ini
    $stmt = $db->prepare("SELECT COUNT(*) FROM hl_transaksi
                            WHERE tenant_id=? AND status_proses IN ('diambil','selesai')
                              AND DATE(tanggal)=?");
    $stmt->execute([$tid, $today]);
    $pipeline['selesai_today'] = (int)$stmt->fetchColumn();
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

    // Omset KEMARIN (untuk delta ranking)
    $o['omset_yesterday'] = 0;
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(total),0) FROM hl_transaksi
                                WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal)=?");
        $stmt->execute([$tid, $oid, date('Y-m-d', strtotime('-1 day'))]);
        $o['omset_yesterday'] = (int)$stmt->fetchColumn();
    } catch (Throwable) {}
    // Delta % hari ini vs kemarin
    $oy = $o['omset_yesterday'];
    $o['omset_delta_pct'] = $oy > 0
        ? round((($o['omset_today'] - $oy) / $oy) * 100, 1)
        : ($o['omset_today'] > 0 ? 100 : 0);

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

// ── Ranking HARI INI (sorted by omset_today desc, untuk delta view) ──
$rankToday = $outlets;
usort($rankToday, fn($a, $b) => $b['omset_today'] <=> $a['omset_today']);

// ── HEATMAP aktivitas per jam per outlet (14 hari terakhir) ──
$heatStartHour = 6;
$heatEndHour   = 22;
$heatmap = [];      // [outlet_id][hour] = count
$heatMax = 0;
try {
    $hStmt = $db->prepare("SELECT outlet_id, HOUR(created_at) jam, COUNT(*) c
                             FROM hl_transaksi
                            WHERE tenant_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                            GROUP BY outlet_id, HOUR(created_at)");
    $hStmt->execute([$tid]);
    foreach ($hStmt->fetchAll() as $row) {
        $oid = (int)$row['outlet_id'];
        $jam = (int)$row['jam'];
        $cnt = (int)$row['c'];
        $heatmap[$oid][$jam] = $cnt;
        if ($cnt > $heatMax) $heatMax = $cnt;
    }
} catch (Throwable) {}

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

// ── Alert OPERASIONAL lintas outlet ───────────────────
foreach ($outlets as $o) {
    $oid = (int)$o['id'];

    // 1. Order estimasi terlewat (estimasi_selesai < hari ini, belum siap/diambil/selesai)
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM hl_transaksi
                                WHERE tenant_id=? AND outlet_id=?
                                  AND estimasi_selesai IS NOT NULL
                                  AND estimasi_selesai < ?
                                  AND status_proses NOT IN ('siap','diambil','selesai','batal','dibatalkan')");
        $stmt->execute([$tid, $oid, $today]);
        $terlewat = (int)$stmt->fetchColumn();
        if ($terlewat > 0) {
            $alerts[] = ['level'=>'danger',
                'msg'=>"⏱️ Outlet <strong>{$o['nama_outlet']}</strong>: <strong>$terlewat order</strong> estimasi selesai terlewat"];
        }
    } catch (Throwable) {}

    // 2. Kas belum diinput hari ini
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM hl_kas
                                WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal)=?");
        $stmt->execute([$tid, $oid, $today]);
        $kasToday = (int)$stmt->fetchColumn();
        // Hanya warning kalau outlet ada transaksi hari ini tapi kas kosong
        if ($kasToday === 0 && (int)($o['order_today'] ?? 0) > 0) {
            $alerts[] = ['level'=>'warning',
                'msg'=>"📒 Outlet <strong>{$o['nama_outlet']}</strong>: kas belum diinput hari ini (ada {$o['order_today']} order)"];
        }
    } catch (Throwable) {}
}

$tenantCoin = (int)($hqTenant['coin_balance'] ?? 0);
$ownerNama  = $hqUser['nama'] ?? 'Owner';
$tenantNm   = $hqTenant['nama_outlet'] ?? 'HQ';
$greeting   = (date('H') < 11 ? 'Selamat pagi' : (date('H') < 15 ? 'Selamat siang' : (date('H') < 19 ? 'Selamat sore' : 'Selamat malam')));
?>
<?php
$pageTitle  = 'Dashboard Eksekutif';
$activePage = 'hq-dashboard';
require __DIR__ . '/_layout_open.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
  /* Page-specific styles — sidebar/topbar di harpy-hq.css */
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

  .ai-brief{background:linear-gradient(135deg,#1a1340,#2d1f5e);border-radius:14px;padding:20px 24px;margin-bottom:18px;position:relative;overflow:hidden}
  .ai-brief:before{content:'';position:absolute;top:-40px;right:-20px;width:180px;height:180px;background:radial-gradient(circle,rgba(139,92,246,.25),transparent);border-radius:50%}
  .ai-brief-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;position:relative}
  .ai-brief-title{font-size:13px;font-weight:800;color:#C4B5FD;letter-spacing:.05em}
  .ai-brief-btn{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none;padding:7px 16px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit}
  .ai-brief-btn:hover{opacity:.92}
  .ai-brief-btn:disabled{opacity:.5;cursor:wait}
  .ai-brief-body{position:relative}
  .ai-brief-empty{font-size:13px;color:rgba(255,255,255,.6);line-height:1.5}
  .ai-brief-loading{font-size:13px;color:rgba(255,255,255,.7)}
  .ai-brief-text{font-size:14px;line-height:1.7;color:rgba(255,255,255,.95);white-space:pre-wrap}
  .ai-brief-meta{font-size:10px;color:rgba(255,255,255,.4);margin-top:10px;letter-spacing:.04em}
  .heatmap-wrap{overflow-x:auto}
  .heatmap{border-collapse:collapse;width:100%;font-size:11px}
  .heatmap th{font-weight:700;color:#9CA3AF;padding:4px 6px;text-align:center;font-size:10px}
  .heatmap th.hm-outlet-h{text-align:left;min-width:120px}
  .heatmap td.hm-outlet{font-weight:700;color:#0F1C3A;font-size:12px;padding:6px 8px;white-space:nowrap}
  .hm-cell{text-align:center;padding:7px 4px;font-weight:700;font-family:monospace;border-radius:4px;min-width:26px;border:1px solid #fff}
  .rank-today{display:flex;flex-direction:column;gap:6px}
  .rt-row{display:grid;grid-template-columns:40px 1fr auto auto;gap:12px;align-items:center;padding:10px 12px;border-radius:8px;background:#F9FAFB}
  .rt-row:hover{background:#F3F4F6}
  .rt-rank{font-size:16px;font-weight:800;text-align:center;color:#6B7280}
  .rt-name{font-weight:700;color:#0F1C3A;font-size:13px}
  .rt-name small{display:block;font-size:11px;font-weight:400;color:#9CA3AF;margin-top:1px}
  .rt-omset{font-family:monospace;font-weight:800;color:#0F1C3A;font-size:14px}
  .rt-delta{font-size:12px;font-weight:700;text-align:right;min-width:90px}
  .rt-delta small{display:block;font-size:10px;font-weight:400;margin-top:1px}
  @media(max-width:640px){.rt-row{grid-template-columns:32px 1fr auto}.rt-delta{grid-column:2/-1;text-align:left}}
  .pipeline{background:#fff;border-radius:12px;padding:18px 22px;box-shadow:0 1px 6px rgba(0,0,0,.05);margin-bottom:18px}
  .pipeline-title{font-size:13px;font-weight:700;color:#0F1C3A;margin-bottom:14px}
  .pipeline-title{display:flex;align-items:center}
  @keyframes livePulse{0%,100%{opacity:1}50%{opacity:.25}}
  .pipeline-flow{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .flash{animation:flashUpd .8s ease}
  @keyframes flashUpd{0%{background:#FEF9C3}100%{background:transparent}}
  .pl-stage{flex:1;min-width:140px;text-align:center;padding:16px 12px;border-radius:10px;background:#F9FAFB;border:1px solid #F0F1F4}
  .pl-stage.proses{background:linear-gradient(135deg,#EFF6FF,#fff);border-color:#BFDBFE}
  .pl-stage.siap{background:linear-gradient(135deg,#F0FDF4,#fff);border-color:#BBF7D0}
  .pl-stage.selesai{background:linear-gradient(135deg,#F9FAFB,#fff);border-color:#E5E7EB}
  .pl-num{font-size:1.9rem;font-weight:800;color:#0F1C3A;font-family:monospace;line-height:1}
  .pl-label{font-size:12px;color:#6B7280;font-weight:600;margin-top:6px}
  .pl-arrow{font-size:20px;color:#CBD5E1;font-weight:800}
  @media(max-width:640px){.pl-arrow{display:none}.pl-stage{min-width:100%}}
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
  }
</style>

  <div class="hero">
    <div>
      <h1><?= $greeting ?>, <?= htmlspecialchars($ownerNama) ?>! 👋</h1>
      <p>HQ <strong style="color:#35E8D5"><?= htmlspecialchars($tenantNm) ?></strong>
         · <?= $outletCnt ?> outlet aktif · <?= date('l, d F Y') ?></p>
    </div>
    <?php if ($hqCanManageOutlet): ?><a href="/ERP/harpy/add-outlet.php" class="btn btn-primary">🏪 Tambah Outlet</a><?php endif; ?>
  </div>

  <!-- AI BRIEFING HQ -->
  <div class="ai-brief" id="aiBrief">
    <div class="ai-brief-head">
      <div class="ai-brief-title">✨ AI Briefing Pagi</div>
      <button class="ai-brief-btn" id="aiBriefBtn" onclick="generateBriefing()">Generate Briefing</button>
    </div>
    <div class="ai-brief-body" id="aiBriefBody">
      <div class="ai-brief-empty" id="aiBriefEmpty">
        Klik "Generate Briefing" untuk ringkasan kondisi semua outlet hari ini. <span style="opacity:.7">(80 coin, 1x per hari)</span>
      </div>
      <div class="ai-brief-loading" id="aiBriefLoading" style="display:none">⏳ Menyusun briefing…</div>
      <div class="ai-brief-text" id="aiBriefText" style="display:none"></div>
      <div class="ai-brief-meta" id="aiBriefMeta" style="display:none"></div>
    </div>
  </div>

  <!-- 4 METRIC CARDS -->
  <div class="metrics">
    <div class="metric">
      <div class="metric-num" id="liveOmset">Rp <?= number_format((int)$todayRow['omset_today'], 0, ',', '.') ?></div>
      <div class="metric-label">Omset Hari Ini</div>
      <div class="metric-sub">Lintas <?= $outletCnt ?> outlet · <span id="liveOrderToday"><?= number_format((int)$todayRow['order_today']) ?></span> order</div>
    </div>
    <div class="metric blue">
      <div class="metric-num" id="liveAktif"><?= number_format($orderAktif) ?></div>
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

  <!-- PIPELINE KONSOLIDASI -->
  <div class="pipeline">
    <div class="pipeline-title">📦 Pipeline Order Lintas Outlet
      <span id="liveDot" style="font-size:11px;font-weight:400;color:#10B981;margin-left:auto">
        <span style="display:inline-block;width:7px;height:7px;background:#10B981;border-radius:50%;animation:livePulse 2s infinite;vertical-align:middle"></span>
        live · <span id="liveTs">—</span>
      </span>
    </div>
    <div class="pipeline-flow">
      <div class="pl-stage proses">
        <div class="pl-num" id="livePilProses"><?= number_format($pipeline['proses']) ?></div>
        <div class="pl-label">🔄 Dalam Proses</div>
      </div>
      <div class="pl-arrow">→</div>
      <div class="pl-stage siap">
        <div class="pl-num" id="livePilSiap"><?= number_format($pipeline['siap']) ?></div>
        <div class="pl-label">✅ Siap Diambil</div>
      </div>
      <div class="pl-arrow">→</div>
      <div class="pl-stage selesai">
        <div class="pl-num" id="livePilSelesai"><?= number_format($pipeline['selesai_today']) ?></div>
        <div class="pl-label">📦 Selesai Hari Ini</div>
      </div>
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

  <!-- RANKING HARI INI (delta vs kemarin) -->
  <?php if (count($outlets) >= 1): ?>
  <div class="panel">
    <div class="panel-title">🏁 Ranking Hari Ini <span style="font-size:11px;font-weight:400;color:#9CA3AF">vs kemarin</span></div>
    <div class="rank-today">
      <?php foreach ($rankToday as $i => $o):
        $delta = (float)($o['omset_delta_pct'] ?? 0);
        $deltaUp = $delta > 0; $deltaFlat = abs($delta) < 0.1;
        $deltaColor = $deltaFlat ? '#9CA3AF' : ($deltaUp ? '#10B981' : '#EF4444');
        $deltaIcon  = $deltaFlat ? '→' : ($deltaUp ? '▲' : '▼');
        $medal = $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : '#'.($i+1)));
      ?>
      <div class="rt-row">
        <div class="rt-rank"><?= $medal ?></div>
        <div class="rt-name"><?= htmlspecialchars($o['nama_outlet']) ?>
          <small><?= (int)$o['order_today'] ?> order</small>
        </div>
        <div class="rt-omset">Rp <?= number_format((int)$o['omset_today'], 0, ',', '.') ?></div>
        <div class="rt-delta" style="color:<?= $deltaColor ?>">
          <?= $deltaIcon ?> <?= $deltaFlat ? '0%' : abs($delta).'%' ?>
          <small style="color:#9CA3AF">kmrn Rp <?= number_format((int)$o['omset_yesterday'], 0, ',', '.') ?></small>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- HEATMAP AKTIVITAS PER JAM -->
  <?php if (!empty($heatmap)): ?>
  <div class="panel">
    <div class="panel-title">🔥 Heatmap Aktivitas per Jam
      <span style="font-size:11px;font-weight:400;color:#9CA3AF">14 hari terakhir · jam tersibuk tiap outlet</span>
    </div>
    <div class="heatmap-wrap">
      <table class="heatmap">
        <thead>
          <tr>
            <th class="hm-outlet-h">Outlet</th>
            <?php for ($h = $heatStartHour; $h <= $heatEndHour; $h++): ?>
              <th><?= $h ?></th>
            <?php endfor; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($outlets as $o):
            $oid = (int)$o['id'];
            if (empty($heatmap[$oid])) continue;
          ?>
          <tr>
            <td class="hm-outlet"><?= htmlspecialchars($o['nama_outlet']) ?></td>
            <?php for ($h = $heatStartHour; $h <= $heatEndHour; $h++):
              $cnt = $heatmap[$oid][$h] ?? 0;
              $intensity = $heatMax > 0 ? $cnt / $heatMax : 0;
              // teal scale
              $bg = $cnt === 0 ? '#F9FAFB' : 'rgba(53,232,213,' . round(0.15 + $intensity * 0.75, 2) . ')';
              $fg = $intensity > 0.5 ? '#0F1C3A' : '#6B7280';
            ?>
              <td class="hm-cell" style="background:<?= $bg ?>;color:<?= $fg ?>"
                  title="<?= htmlspecialchars($o['nama_outlet']) ?> · jam <?= $h ?>:00 — <?= $cnt ?> order">
                <?= $cnt > 0 ? $cnt : '' ?>
              </td>
            <?php endfor; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="font-size:11px;color:#9CA3AF;margin-top:8px">Warna lebih pekat = lebih ramai. Angka = jumlah order masuk pada jam tsb.</div>
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
        <div class="ocard-main-num" data-live-omset="<?= (int)$o['id'] ?>">Rp <?= number_format((int)$o['omset_today'], 0, ',', '.') ?></div>
        <div class="ocard-main-label">OMSET HARI INI · <span data-live-order="<?= (int)$o['id'] ?>"><?= (int)$o['order_today'] ?></span> order</div>
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

// ── Real-time polling (30 detik) ──
const fmtRpLive = n => 'Rp ' + Number(n||0).toLocaleString('id-ID');
function setLive(el, val){
  if (!el) return;
  if (el.textContent !== val){ el.textContent = val; el.classList.remove('flash'); void el.offsetWidth; el.classList.add('flash'); }
}
async function pollLive(){
  try {
    const r = await fetch('/ERP/harpy/hq/dashboard.php?action=live');
    const d = await r.json();
    if (!d.ok) return;
    setLive(document.getElementById('liveOmset'), fmtRpLive(d.omset_today));
    setLive(document.getElementById('liveOrderToday'), Number(d.order_today).toLocaleString('id-ID'));
    setLive(document.getElementById('liveAktif'), Number(d.order_aktif).toLocaleString('id-ID'));
    setLive(document.getElementById('livePilProses'), Number(d.pipeline.proses).toLocaleString('id-ID'));
    setLive(document.getElementById('livePilSiap'), Number(d.pipeline.siap).toLocaleString('id-ID'));
    setLive(document.getElementById('livePilSelesai'), Number(d.pipeline.selesai).toLocaleString('id-ID'));
    const ts = document.getElementById('liveTs'); if (ts) ts.textContent = d.ts;
    // Per outlet
    Object.entries(d.outlets || {}).forEach(([oid, v]) => {
      setLive(document.querySelector(`[data-live-omset="${oid}"]`), fmtRpLive(v.omset));
      setLive(document.querySelector(`[data-live-order="${oid}"]`), Number(v.cnt).toLocaleString('id-ID'));
    });
  } catch(e){}
}
setInterval(pollLive, 30000);
// Pause polling saat tab tidak aktif, resume + refresh saat balik
document.addEventListener('visibilitychange', () => { if (!document.hidden) pollLive(); });

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

// ── AI Briefing ──────────────────────────────────────
function showBriefing(text, generatedAt, fromCache){
  document.getElementById('aiBriefEmpty').style.display = 'none';
  document.getElementById('aiBriefLoading').style.display = 'none';
  const t = document.getElementById('aiBriefText');
  t.textContent = text;
  t.style.display = 'block';
  const meta = document.getElementById('aiBriefMeta');
  meta.textContent = (fromCache ? '⚡ Cache hari ini' : '✨ Baru di-generate') + (generatedAt ? ' · ' + generatedAt : '');
  meta.style.display = 'block';
  document.getElementById('aiBriefBtn').textContent = 'Re-generate';
}

async function generateBriefing(){
  const btn = document.getElementById('aiBriefBtn');
  btn.disabled = true;
  document.getElementById('aiBriefEmpty').style.display = 'none';
  document.getElementById('aiBriefText').style.display = 'none';
  document.getElementById('aiBriefMeta').style.display = 'none';
  document.getElementById('aiBriefLoading').style.display = 'block';

  try {
    const r = await fetch('/ERP/harpy/hq/dashboard.php?action=ai_briefing');
    const txt = await r.text();
    let d;
    try { d = JSON.parse(txt); }
    catch (parseErr) {
      btn.disabled = false;
      document.getElementById('aiBriefLoading').style.display = 'none';
      document.getElementById('aiBriefEmpty').style.display = 'block';
      const peek = txt.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').substring(0,180);
      document.getElementById('aiBriefEmpty').innerHTML =
        '<div style="background:#FEF3C7;border-left:4px solid #F59E0B;padding:10px 12px;border-radius:8px;font-size:12px;color:#92400E;text-align:left">'
        + '<div style="font-weight:700;margin-bottom:4px">⚠️ Server return format tidak valid (HTTP ' + r.status + ')</div>'
        + '<div style="font-size:11px;opacity:.8">Cuplikan response: <code>' + (peek || '(kosong)') + '</code></div>'
        + '<div style="margin-top:6px;font-size:11px">Cek <code>error_log</code> di hosting untuk detail.</div>'
        + '</div>';
      return;
    }
    btn.disabled = false;
    if (d.error) {
      document.getElementById('aiBriefLoading').style.display = 'none';
      document.getElementById('aiBriefEmpty').style.display = 'block';
      document.getElementById('aiBriefEmpty').textContent = '⚠️ ' + d.error;
      return;
    }
    showBriefing(d.briefing, d.generated_at, d.from_cache);
  } catch (e) {
    btn.disabled = false;
    document.getElementById('aiBriefLoading').style.display = 'none';
    document.getElementById('aiBriefEmpty').style.display = 'block';
    document.getElementById('aiBriefEmpty').textContent = '⚠️ Gagal: ' + e.message;
  }
}

// Auto-peek: kalau briefing hari ini sudah ada di cache, tampilkan tanpa charge
(async function peekBriefing(){
  try {
    const r = await fetch('/ERP/harpy/hq/dashboard.php?action=ai_briefing&peek=1');
    const d = await r.json();
    if (d.ok && d.cached && d.briefing) {
      showBriefing(d.briefing, d.generated_at, true);
    }
  } catch (e) {}
})();
</script>
<?php require __DIR__ . '/_layout_close.php'; ?>
