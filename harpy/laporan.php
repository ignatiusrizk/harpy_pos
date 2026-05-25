<?php
$activePage = 'laporan';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/AIInsight.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
requirePermission('laporan.view_harian');

// ── API ───────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $oid = TenantResolver::outletId();

    // ── LAPORAN HARIAN ────────────────────────────────
    if ($action === 'harian') {
        if (!hasPermission('laporan.view_harian')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        $tgl = substr(trim($_GET['tgl'] ?? date('Y-m-d')), 0, 10);

        $orderData = TenantQuery::rawOne(
            "SELECT COUNT(*) as total_order,
             COALESCE(SUM(total),0) as omset,
             COALESCE(SUM(dp),0) as terkumpul,
             COALESCE(SUM(diskon),0) as total_diskon,
             SUM(CASE WHEN status_bayar='lunas' THEN 1 ELSE 0 END) as lunas,
             SUM(CASE WHEN status_bayar='dp' THEN 1 ELSE 0 END) as dp_count,
             SUM(CASE WHEN status_bayar='belum_bayar' THEN 1 ELSE 0 END) as belum_bayar
             FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal)=?",
            [$tid, $oid, $tgl]
        );

        $layananData = TenantQuery::raw(
            "SELECT i.nama_layanan,
             SUM(i.jumlah) as total_jumlah,
             COUNT(*) as total_order,
             SUM(i.subtotal) as total_omset
             FROM hl_transaksi_item i
             JOIN hl_transaksi t ON t.id=i.transaksi_id AND t.tenant_id=i.tenant_id AND t.outlet_id=i.outlet_id
             WHERE i.tenant_id=? AND i.outlet_id=? AND DATE(t.tanggal)=?
             GROUP BY i.nama_layanan ORDER BY total_omset DESC LIMIT 10",
            [$tid, $oid, $tgl]
        );

        $kasData = TenantQuery::rawOne(
            "SELECT COALESCE(SUM(CASE WHEN tipe='masuk'  THEN jumlah END),0) as kas_masuk,
             COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah END),0) as kas_keluar
             FROM hl_kas WHERE tenant_id=? AND outlet_id=? AND tanggal=?",
            [$tid, $oid, $tgl]
        );

        $orderList = TenantQuery::raw(
            "SELECT t.no_order,t.nama_pelanggan,t.total,t.dp,t.sisa_bayar,
             t.status_proses,t.status_bayar,t.metode_bayar,
             GROUP_CONCAT(i.nama_layanan SEPARATOR ', ') as layanan_list
             FROM hl_transaksi t
             LEFT JOIN hl_transaksi_item i ON i.transaksi_id=t.id AND i.tenant_id=t.tenant_id AND i.outlet_id=t.outlet_id
             WHERE t.tenant_id=? AND t.outlet_id=? AND DATE(t.tanggal)=?
             GROUP BY t.id ORDER BY t.created_at DESC",
            [$tid, $oid, $tgl]
        );

        echo json_encode([
            'order'   => $orderData,
            'layanan' => $layananData,
            'kas'     => $kasData,
            'orders'  => $orderList,
        ]); exit;
    }

    // ── LAPORAN BULANAN ───────────────────────────────
    if ($action === 'bulanan') {
        if (!hasPermission('laporan.view_bulanan')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        $bulan  = substr(trim($_GET['bulan'] ?? date('Y-m')), 0, 7);
        [$y,$m] = explode('-', $bulan);
        $dari   = "$y-$m-01";
        $sampai = date('Y-m-t', strtotime($dari));

        $dailyData = TenantQuery::raw(
            "SELECT DATE(tanggal) as tgl,
             COUNT(*) as total_order,
             COALESCE(SUM(total),0) as omset,
             COALESCE(SUM(dp),0) as terkumpul
             FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND tanggal BETWEEN ? AND ?
             GROUP BY DATE(tanggal) ORDER BY tgl",
            [$tid, $oid, $dari, $sampai]
        );

        $sumData = TenantQuery::rawOne(
            "SELECT COUNT(*) as total_order,
             COALESCE(SUM(total),0) as omset,
             COALESCE(SUM(dp),0) as terkumpul,
             COALESCE(SUM(diskon),0) as total_diskon,
             COALESCE(SUM(sisa_bayar),0) as total_piutang,
             SUM(CASE WHEN status_bayar='lunas' THEN 1 ELSE 0 END) as lunas,
             SUM(CASE WHEN status_proses='diambil' THEN 1 ELSE 0 END) as selesai
             FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND tanggal BETWEEN ? AND ?",
            [$tid, $oid, $dari, $sampai]
        );

        $topLayananData = TenantQuery::raw(
            "SELECT i.nama_layanan,
             SUM(i.jumlah) as total_jumlah,
             COUNT(DISTINCT t.id) as total_order,
             SUM(i.subtotal) as total_omset
             FROM hl_transaksi_item i
             JOIN hl_transaksi t ON t.id=i.transaksi_id AND t.tenant_id=i.tenant_id AND t.outlet_id=i.outlet_id
             WHERE i.tenant_id=? AND i.outlet_id=? AND t.tanggal BETWEEN ? AND ?
             GROUP BY i.nama_layanan ORDER BY total_omset DESC LIMIT 10",
            [$tid, $oid, $dari, $sampai]
        );

        $kasData = TenantQuery::rawOne(
            "SELECT COALESCE(SUM(CASE WHEN tipe='masuk'  THEN jumlah END),0) as kas_masuk,
             COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah END),0) as kas_keluar
             FROM hl_kas WHERE tenant_id=? AND outlet_id=? AND tanggal BETWEEN ? AND ?",
            [$tid, $oid, $dari, $sampai]
        );

        $pengeluaranData = TenantQuery::raw(
            "SELECT kategori, SUM(jumlah) as total, COUNT(*) as count
             FROM hl_kas WHERE tenant_id=? AND outlet_id=? AND tipe='keluar' AND tanggal BETWEEN ? AND ?
             GROUP BY kategori ORDER BY total DESC",
            [$tid, $oid, $dari, $sampai]
        );

        $pemasukanData = TenantQuery::raw(
            "SELECT kategori, SUM(jumlah) as total, COUNT(*) as count
             FROM hl_kas WHERE tenant_id=? AND outlet_id=? AND tipe='masuk' AND tanggal BETWEEN ? AND ?
             GROUP BY kategori ORDER BY total DESC",
            [$tid, $oid, $dari, $sampai]
        );

        echo json_encode([
            'daily'       => $dailyData,
            'summary'     => $sumData,
            'top_layanan' => $topLayananData,
            'kas'         => $kasData,
            'pengeluaran' => $pengeluaranData,
            'pemasukan'   => $pemasukanData,
            'periode'     => ['dari'=>$dari,'sampai'=>$sampai,'bulan'=>$bulan],
        ]); exit;
    }

    // ── LAPORAN L/R ───────────────────────────────────
    if ($action === 'lr') {
        if (!hasPermission('laporan.view_lr')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        $dari   = substr(trim($_GET['dari']   ?? date('Y-m-01')), 0, 10);
        $sampai = substr(trim($_GET['sampai'] ?? date('Y-m-d')), 0, 10);

        $pendData = TenantQuery::rawOne(
            "SELECT COALESCE(SUM(dp),0) as pendapatan_terkumpul,
             COALESCE(SUM(total),0) as pendapatan_total,
             COALESCE(SUM(diskon),0) as total_diskon,
             COUNT(*) as total_order
             FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND tanggal BETWEEN ? AND ?",
            [$tid, $oid, $dari, $sampai]
        );

        $kasMasukData = TenantQuery::raw(
            "SELECT kategori, SUM(jumlah) as total
             FROM hl_kas WHERE tenant_id=? AND outlet_id=? AND tipe='masuk' AND tanggal BETWEEN ? AND ?
             GROUP BY kategori ORDER BY total DESC",
            [$tid, $oid, $dari, $sampai]
        );
        $totalKasMasuk = array_sum(array_column($kasMasukData, 'total'));

        $bebanData = TenantQuery::raw(
            "SELECT kategori, SUM(jumlah) as total, COUNT(*) as count
             FROM hl_kas WHERE tenant_id=? AND outlet_id=? AND tipe='keluar' AND tanggal BETWEEN ? AND ?
             GROUP BY kategori ORDER BY total DESC",
            [$tid, $oid, $dari, $sampai]
        );
        $totalBeban = array_sum(array_column($bebanData, 'total'));

        $totalPendapatan = floatval($pendData['pendapatan_terkumpul']) + $totalKasMasuk;
        $labaRugi = $totalPendapatan - $totalBeban;

        $trendData = TenantQuery::raw(
            "SELECT DATE_FORMAT(tanggal,'%Y-%m') as bulan,
             COUNT(*) as total_order,
             COALESCE(SUM(total),0) as omset,
             COALESCE(SUM(dp),0) as terkumpul
             FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND tanggal BETWEEN ? AND ?
             GROUP BY DATE_FORMAT(tanggal,'%Y-%m') ORDER BY bulan",
            [$tid, $oid, $dari, $sampai]
        );

        echo json_encode([
            'pendapatan'       => $pendData,
            'kas_masuk'        => $kasMasukData,
            'total_kas_masuk'  => $totalKasMasuk,
            'beban'            => $bebanData,
            'total_beban'      => $totalBeban,
            'total_pendapatan' => $totalPendapatan,
            'laba_rugi'        => $labaRugi,
            'trend'            => $trendData,
            'periode'          => ['dari'=>$dari,'sampai'=>$sampai],
        ]); exit;
    }

    // ── AI INSIGHT ────────────────────────────────────
    if ($action === 'ai_insight') {
        if (!CoinLedger::canAfford('ai_insight_laporan')) {
            echo json_encode(['error' => 'Coin tidak cukup. Butuh 100 coin, saldo: ' . TenantResolver::coinBalance()]);
            exit;
        }

        $dari   = substr(trim($_GET['dari']   ?? date('Y-m-01')), 0, 10);
        $sampai = substr(trim($_GET['sampai'] ?? date('Y-m-d')), 0, 10);
        $periodeLabel = date('d M Y', strtotime($dari)) . ' — ' . date('d M Y', strtotime($sampai));

        // Summary
        $sum = TenantQuery::rawOne(
            "SELECT COUNT(*) total_order, COALESCE(SUM(total),0) omset
              FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal) BETWEEN ? AND ?",
            [$tid, $oid, $dari, $sampai]
        );
        $omset = (int)($sum['omset'] ?? 0);
        $orderCnt = (int)($sum['total_order'] ?? 0);
        $avgTicket = $orderCnt > 0 ? (int)round($omset / $orderCnt) : 0;

        // Omset periode sebelumnya
        $days = max(1, (int)((strtotime($sampai) - strtotime($dari)) / 86400) + 1);
        $prevSampai = date('Y-m-d', strtotime($dari . ' -1 day'));
        $prevDari   = date('Y-m-d', strtotime($prevSampai . " -" . ($days - 1) . " days"));
        $omsetPrev = 0;
        try {
            $prev = TenantQuery::rawOne(
                "SELECT COALESCE(SUM(total),0) omset FROM hl_transaksi
                  WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal) BETWEEN ? AND ?",
                [$tid, $oid, $prevDari, $prevSampai]
            );
            $omsetPrev = (int)($prev['omset'] ?? 0);
        } catch (Throwable) {}

        // Top layanan
        $topLayanan = [];
        try {
            $rows = TenantQuery::raw(
                "SELECT ti.nama_layanan AS nama, COUNT(*) qty, COALESCE(SUM(ti.subtotal),0) total
                  FROM hl_transaksi_item ti
                  JOIN hl_transaksi t ON t.id = ti.transaksi_id
                 WHERE t.tenant_id=? AND t.outlet_id=? AND DATE(t.tanggal) BETWEEN ? AND ?
                 GROUP BY ti.nama_layanan ORDER BY total DESC LIMIT 5",
                [$tid, $oid, $dari, $sampai]
            );
            $topLayanan = $rows;
        } catch (Throwable) {}

        $aiData = [
            'periode_label' => $periodeLabel,
            'scope'         => 'outlet',
            'omset'         => $omset,
            'omset_prev'    => $omsetPrev,
            'order_count'   => $orderCnt,
            'avg_ticket'    => $avgTicket,
            'top_layanan'   => $topLayanan,
            'top_karyawan'  => [],
            'per_outlet'    => [],
        ];

        try {
            $insight = AIInsight::analyzeLaporan($aiData, $tid, $oid);
            if (empty($insight['from_cache'])) {
                try { CoinLedger::deduct('ai_insight_laporan'); } catch (Throwable) {}
                try { logAudit('ai_insight', 'laporan', "AI insight outlet $periodeLabel"); } catch (Throwable) {}
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

    // ── PRODUKTIVITAS KARYAWAN ────────────────────────
    if ($action === 'produktivitas') {
        $bulan = preg_match('/^\d{4}-\d{2}$/', $_GET['bulan'] ?? '') ? $_GET['bulan'] : date('Y-m');
        $start = $bulan.'-01';
        $end   = date('Y-m-t', strtotime($start));

        // Defensive: cek kolom handled_by (kalau migration owner_visibility belum jalan)
        static $hasHandledBy = null;
        if ($hasHandledBy === null) {
            try { Database::get()->query("SELECT handled_by FROM hl_transaksi LIMIT 1"); $hasHandledBy = true; }
            catch (Throwable) { $hasHandledBy = false; }
        }

        try {
            // Hari kerja efektif (jumlah hari sudah lewat dalam periode, capped today)
            $todayStr = date('Y-m-d');
            $endEff = ($end > $todayStr) ? $todayStr : $end;
            $hariEff = (int)((strtotime($endEff) - strtotime($start))/86400) + 1;

            // Ambil semua karyawan aktif di outlet
            $kStmt = TenantQuery::raw(
                "SELECT u.id, u.nama, u.jabatan FROM hl_users u
                  JOIN hl_karyawan_outlet ko ON ko.karyawan_id=u.id AND ko.tenant_id=u.tenant_id
                       AND ko.outlet_id=? AND ko.is_active=1
                 WHERE u.tenant_id=? AND u.is_active=1
                 ORDER BY u.nama",
                [$oid, $tid]
            );

            $rows = [];
            foreach ($kStmt as $u) {
                $uid = (int)$u['id'];
                // Absensi
                $a = TenantQuery::raw(
                    "SELECT
                        SUM(status='hadir') hadir,
                        SUM(status='izin')  izin,
                        SUM(status='sakit') sakit,
                        SUM(status='alpha') alpha,
                        SUM(CASE WHEN status='hadir' AND jam_masuk IS NOT NULL
                                 AND jam_masuk > COALESCE((SELECT jam_buka FROM outlets WHERE id=?),'08:00:00')
                                 THEN 1 ELSE 0 END) telat
                       FROM hl_absensi
                      WHERE tenant_id=? AND outlet_id=? AND user_id=?
                        AND tanggal BETWEEN ? AND ?",
                    [$oid, $tid, $oid, $uid, $start, $endEff]
                );
                $ar = $a[0] ?? [];

                // Order handle (handled_by = uid) — skip kalau kolom belum ada
                $oh = 0;
                if ($hasHandledBy) {
                    try {
                        $oArr = TenantQuery::raw(
                            "SELECT COUNT(*) c FROM hl_transaksi
                              WHERE tenant_id=? AND outlet_id=? AND handled_by=?
                                AND DATE(tanggal) BETWEEN ? AND ?",
                            [$tid, $oid, $uid, $start, $end]
                        );
                        $oh = (int)($oArr[0]['c'] ?? 0);
                    } catch (Throwable) {}
                }

                $rows[] = [
                    'user_id' => $uid,
                    'nama'    => $u['nama'],
                    'jabatan' => $u['jabatan'] ?? '-',
                    'hadir'   => (int)($ar['hadir'] ?? 0),
                    'izin'    => (int)($ar['izin'] ?? 0),
                    'sakit'   => (int)($ar['sakit'] ?? 0),
                    'alpha'   => (int)($ar['alpha'] ?? 0),
                    'telat'   => (int)($ar['telat'] ?? 0),
                    'order_handle' => $oh,
                    'hari_eff' => $hariEff,
                ];
            }
            echo json_encode(['ok'=>true, 'bulan'=>$bulan, 'hari_eff'=>$hariEff, 'rows'=>$rows]);
        } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Laporan'); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
/* TABS */
.page-tabs{display:flex;gap:4px;background:var(--white);border-radius:var(--r-lg);padding:6px;box-shadow:var(--shadow);margin-bottom:24px;border:1px solid rgba(27,45,90,.07)}
.ptab{flex:1;padding:10px 16px;border-radius:var(--r);font-size:14px;font-weight:600;color:var(--gray);cursor:pointer;text-align:center;transition:all .2s;border:none;background:transparent;font-family:var(--font)}
.ptab:hover{color:var(--navy)}
.ptab.active{background:var(--navy);color:var(--white)}

/* STAT CARDS */
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
.stat-card{background:var(--white);border-radius:var(--r-lg);padding:18px 20px;border:1px solid rgba(27,45,90,.07);box-shadow:var(--shadow);position:relative;overflow:hidden}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.sc-green::before{background:linear-gradient(90deg,var(--green),#34D399)}
.sc-teal::before{background:linear-gradient(90deg,var(--teal),var(--teal-d))}
.sc-red::before{background:linear-gradient(90deg,var(--red),#F87171)}
.sc-purple::before{background:linear-gradient(90deg,var(--purple),#A78BFA)}
.sc-navy::before{background:linear-gradient(90deg,var(--navy),#2D4A8A)}
.sc-yellow::before{background:linear-gradient(90deg,var(--yellow),#FCD34D)}
.stat-num{font-size:1.4rem;font-weight:800;color:var(--navy);font-family:var(--mono);margin-bottom:4px;line-height:1}
.stat-label{font-size:12px;color:var(--gray);font-weight:500}

/* CARD */
.card{background:var(--white);border-radius:var(--r-lg);border:1px solid rgba(27,45,90,.07);box-shadow:var(--shadow);overflow:hidden;margin-bottom:18px}
.card-header{padding:14px 18px;border-bottom:1px solid var(--light);display:flex;align-items:center;justify-content:space-between}
.card-title{font-size:14px;font-weight:700;color:var(--navy);display:flex;align-items:center;gap:7px}
.card-body{padding:18px}

/* GRID */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px}

/* TABLE */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px}
thead tr{background:var(--navy-d)}
thead th{padding:9px 12px;text-align:left;font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.6);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--light)}
tbody tr:hover{background:var(--off)}
tbody td{padding:9px 12px;vertical-align:middle}
tfoot tr{background:var(--light)}
tfoot td{padding:9px 12px;font-weight:700;font-size:13px}
.td-num{font-family:var(--mono);font-weight:600;text-align:right}
.td-green{color:var(--green)}
.td-red{color:var(--red)}

/* BADGE */
.badge{display:inline-block;font-size:11px;font-weight:600;padding:3px 9px;border-radius:100px}
.b-masuk{background:#D1FAE5;color:#065F46}
.b-keluar{background:#FEE2E2;color:#991B1B}
.b-lunas{background:#D1FAE5;color:#065F46}
.b-dp{background:#FEF3C7;color:#92400E}
.b-belum_bayar{background:#FEE2E2;color:#991B1B}

/* L/R BOX */
.lr-box{border-radius:var(--r-lg);padding:24px 28px;margin-bottom:18px}
.lr-box.laba{background:linear-gradient(135deg,#064E3B,#065F46);border:1px solid #6EE7B7}
.lr-box.rugi{background:linear-gradient(135deg,#7F1D1D,#991B1B);border:1px solid #FCA5A5}
.lr-title{font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:8px;opacity:.7;color:white}
.lr-num{font-size:2.2rem;font-weight:900;font-family:var(--mono);color:white;line-height:1}
.lr-sub{font-size:13px;margin-top:6px;opacity:.7;color:white}
.lr-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(27,45,90,.07);font-size:14px}
.lr-row:last-child{border-bottom:none}
.lr-label{color:var(--gray)}
.lr-value{font-family:var(--mono);font-weight:600}
.lr-section{background:var(--white);border-radius:var(--r-lg);padding:18px;border:1px solid rgba(27,45,90,.07);margin-bottom:12px}
.lr-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;margin-bottom:12px;display:flex;align-items:center;gap:6px}
.lr-total{border-top:2px solid var(--light);margin-top:8px;padding-top:10px;font-weight:700}

/* CHART */
.chart-wrap{position:relative;height:260px}

/* PRINT */
@media print{
  .topbar,.page-tabs,.hl-filter-collapsible,.no-print{display:none!important}
  .hl-main{padding:0}
  .card{box-shadow:none;border:1px solid #ddd;break-inside:avoid}
}

.empty{text-align:center;padding:32px;color:var(--gray);font-size:14px}
.loading{text-align:center;padding:24px;color:var(--gray);font-size:14px}

@media(max-width:900px){
  .stat-grid{grid-template-columns:repeat(2,1fr)}
  .grid-2,.grid-3{grid-template-columns:1fr}
}
@media(max-width:680px){
  .stat-grid{grid-template-columns:repeat(2,1fr);gap:10px}
  .table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
  thead th{font-size:11px;padding:8px 8px}
  tbody td{font-size:12px;padding:8px 8px}
}
</style>
</head>
<body>
<?php renderTopbar('laporan'); ?>

<div class="hl-main">

  <!-- PAGE TABS -->
  <div class="page-tabs">
    <?php if (hasPermission('laporan.view_harian')): ?>
    <button class="ptab active" onclick="switchTab('harian',this)">📅 Harian</button>
    <?php endif; ?>
    <?php if (hasPermission('laporan.view_bulanan')): ?>
    <button class="ptab" onclick="switchTab('bulanan',this)">📆 Bulanan</button>
    <?php endif; ?>
    <?php if (hasPermission('laporan.view_lr')): ?>
    <button class="ptab" onclick="switchTab('lr',this)">📈 Laba / Rugi</button>
    <?php endif; ?>
    <button class="ptab" onclick="switchTab('produktivitas',this)">👥 Produktivitas Karyawan</button>
  </div>

  <!-- ══ TAB HARIAN ═══════════════════════════════════ -->
  <?php if (hasPermission('laporan.view_harian')): ?>
  <div id="tabHarian">
    <div class="hl-filter-collapsible no-print">
      <button class="hl-filter-toggle-btn" id="harianFilterBtn" onclick="toggleFilter('harianFilter')">
        📅 Pilih Tanggal <span class="hl-toggle-arrow">▼</span>
      </button>
      <div class="hl-filter-bar" id="harianFilter">
        <label>Tanggal</label>
        <input type="date" id="hTgl"/>
        <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="loadHarian()">🔍 Tampilkan</button>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="window.print()">🖨️ Print</button>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="exportCSV('harian')">📥 Export CSV</button>
      </div>
    </div>

    <div class="stat-grid" id="hStatGrid">
      <div class="stat-card sc-teal"><div class="stat-num" id="hOmset">-</div><div class="stat-label">💎 Total Omset</div></div>
      <div class="stat-card sc-green"><div class="stat-num" id="hTerkumpul">-</div><div class="stat-label">💚 Terkumpul</div></div>
      <div class="stat-card sc-navy"><div class="stat-num" id="hOrder">-</div><div class="stat-label">📋 Total Order</div></div>
      <div class="stat-card sc-purple"><div class="stat-num" id="hDiskon">-</div><div class="stat-label">🎟️ Total Diskon</div></div>
    </div>

    <div class="grid-2">
      <div class="card">
        <div class="card-header"><div class="card-title">🧺 Layanan Hari Ini</div></div>
        <div class="table-wrap">
          <table class="hl-stack-mobile">
            <thead><tr><th>Layanan</th><th style="text-align:right">Jml</th><th style="text-align:right">Order</th><th style="text-align:right">Omset</th></tr></thead>
            <tbody id="hLayananBody"><tr><td colspan="4" class="loading">⏳</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="card">
        <div class="card-header"><div class="card-title">💰 Kas Hari Ini</div></div>
        <div class="card-body" id="hKasBody"><div class="loading">⏳</div></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title">📋 Daftar Order Hari Ini</div>
        <span id="hOrderCount" style="font-size:12px;color:var(--gray)"></span>
      </div>
      <div class="table-wrap">
        <table class="hl-stack-mobile">
          <thead><tr><th>No Order</th><th>Pelanggan</th><th>Layanan</th><th>Status</th><th>Bayar</th><th style="text-align:right">Total</th><th style="text-align:right">Terkumpul</th></tr></thead>
          <tbody id="hOrderBody"><tr><td colspan="7" class="loading">⏳</td></tr></tbody>
          <tfoot id="hOrderFoot" style="display:none">
            <tr>
              <td colspan="5" style="color:var(--gray)">Total</td>
              <td class="td-num" id="hFootTotal"></td>
              <td class="td-num" id="hFootTerkumpul"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══ TAB BULANAN ══════════════════════════════════ -->
  <?php if (hasPermission('laporan.view_bulanan')): ?>
  <div id="tabBulanan" style="display:none">
    <div class="hl-filter-collapsible no-print">
      <button class="hl-filter-toggle-btn" id="bulananFilterBtn" onclick="toggleFilter('bulananFilter')">
        📅 Pilih Bulan <span class="hl-toggle-arrow">▼</span>
      </button>
      <div class="hl-filter-bar" id="bulananFilter">
        <label>Bulan</label>
        <input type="month" id="bBulan"/>
        <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="loadBulanan()">🔍 Tampilkan</button>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="window.print()">🖨️ Print</button>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="exportCSV('bulanan')">📥 Export CSV</button>
      </div>
    </div>

    <div class="stat-grid">
      <div class="stat-card sc-teal"><div class="stat-num" id="bOmset">-</div><div class="stat-label">💎 Total Omset</div></div>
      <div class="stat-card sc-green"><div class="stat-num" id="bTerkumpul">-</div><div class="stat-label">💚 Terkumpul</div></div>
      <div class="stat-card sc-navy"><div class="stat-num" id="bOrder">-</div><div class="stat-label">📋 Total Order</div></div>
      <div class="stat-card sc-red"><div class="stat-num" id="bPiutang">-</div><div class="stat-label">⚠️ Total Piutang</div></div>
    </div>

    <!-- CHART OMSET -->
    <div class="card">
      <div class="card-header"><div class="card-title">📈 Grafik Omset Harian</div></div>
      <div class="card-body"><div class="chart-wrap"><canvas id="chartOmset"></canvas></div></div>
    </div>

    <div class="grid-2">
      <!-- Layanan terlaris -->
      <div class="card">
        <div class="card-header"><div class="card-title">🏆 Layanan Terlaris</div></div>
        <div class="table-wrap">
          <table class="hl-stack-mobile">
            <thead><tr><th>#</th><th>Layanan</th><th style="text-align:right">Order</th><th style="text-align:right">Omset</th></tr></thead>
            <tbody id="bLayananBody"><tr><td colspan="4" class="loading">⏳</td></tr></tbody>
          </table>
        </div>
      </div>

      <!-- Kas & Pengeluaran -->
      <div>
        <div class="card">
          <div class="card-header"><div class="card-title">💰 Ringkasan Kas</div></div>
          <div class="card-body" id="bKasBody"><div class="loading">⏳</div></div>
        </div>
        <div class="card">
          <div class="card-header"><div class="card-title">❤️ Pengeluaran per Kategori</div></div>
          <div class="table-wrap">
            <table class="hl-stack-mobile">
              <thead><tr><th>Kategori</th><th style="text-align:right">Jml</th><th style="text-align:right">Total</th></tr></thead>
              <tbody id="bPengeluaranBody"><tr><td colspan="3" class="loading">⏳</td></tr></tbody>
              <tfoot><tr><td colspan="2" style="font-weight:700">Total Pengeluaran</td><td class="td-num" id="bPengeluaranTotal">-</td></tr></tfoot>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══ TAB LABA RUGI ════════════════════════════════ -->
  <?php if (hasPermission('laporan.view_lr')): ?>
  <div id="tabLR" style="display:none">
    <div class="hl-filter-collapsible no-print">
      <button class="hl-filter-toggle-btn" id="lrFilterBtn" onclick="toggleFilter('lrFilter')">
        📅 Pilih Periode <span class="hl-toggle-arrow">▼</span>
      </button>
      <div class="hl-filter-bar" id="lrFilter">
        <label>Dari</label>
        <input type="date" id="lrDari"/>
        <label>s/d</label>
        <input type="date" id="lrSampai"/>
        <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="loadLR()">🔍 Hitung L/R</button>
        <button class="hl-btn hl-btn-sm" onclick="loadAiInsightOutlet()"
                style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none">
          ✨ AI Insight
        </button>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="window.print()">🖨️ Print</button>
      </div>
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
          <div id="aiInsightTitle" style="font-size:14px;color:rgba(255,255,255,.6)">Analisa periode L/R</div>
        </div>
        <button onclick="document.getElementById('aiInsightPanel').style.display='none'"
                style="background:rgba(255,255,255,.08);border:none;color:#fff;width:28px;height:28px;
                       border-radius:6px;cursor:pointer;font-size:14px">✕</button>
      </div>
      <div id="aiInsightLoading" style="display:none;text-align:center;padding:30px 20px">
        <div style="font-size:32px;animation:aiSpin 1.5s linear infinite">⏳</div>
        <div style="font-size:13px;color:rgba(255,255,255,.6);margin-top:8px">Claude sedang menganalisa…</div>
      </div>
      <div id="aiInsightContent" style="display:none">
        <div id="aiSummary" style="font-size:14px;line-height:1.65;color:rgba(255,255,255,.92);
             background:rgba(255,255,255,.05);padding:14px 16px;border-radius:10px;
             border-left:3px solid #35E8D5;margin-bottom:18px"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div>
            <div style="font-size:11px;font-weight:800;color:#35E8D5;letter-spacing:.08em;margin-bottom:8px">📊 HIGHLIGHTS</div>
            <ul id="aiHighlights" style="list-style:none;padding:0;margin:0;font-size:13px;color:rgba(255,255,255,.85);line-height:1.7"></ul>
          </div>
          <div>
            <div style="font-size:11px;font-weight:800;color:#F59E0B;letter-spacing:.08em;margin-bottom:8px">💡 REKOMENDASI</div>
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

    <div id="lrContent"><div class="empty">Pilih periode lalu klik "Hitung L/R"</div></div>
  </div>
  <?php endif; ?>

  <!-- TAB PRODUKTIVITAS KARYAWAN -->
  <div id="tabProd" style="display:none">
    <div class="hl-card">
      <div class="hl-card-header">
        <div class="hl-card-title">👥 Produktivitas Karyawan</div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <label style="font-size:12px;color:var(--gray);font-weight:600">Bulan:</label>
          <input type="month" id="prodBulan" value="<?= date('Y-m') ?>" onchange="loadProd()"
                 style="padding:7px 10px;border:1px solid #E5E9F2;border-radius:7px;font-family:inherit">
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="exportCSV('produktivitas')">📥 Export CSV</button>
        </div>
      </div>
      <div class="hl-card-body" id="prodContent" style="padding:14px">
        <div class="empty">⏳ Memuat…</div>
      </div>
    </div>
  </div>

  <!-- AI ANALYST WIDGET -->
  <div class="hl-card" style="margin-top:20px;border:2px solid rgba(139,92,246,.15)">
    <div class="hl-card-header" style="background:linear-gradient(135deg,#F5F3FF,#EDE9FE)">
      <div class="hl-card-title" style="display:flex;align-items:center;gap:8px">
        <span style="background:linear-gradient(135deg,#667eea,#764ba2);color:white;font-size:10px;font-weight:800;padding:3px 8px;border-radius:100px;letter-spacing:.06em">AI</span>
        Tanya AI Tentang Laporan Ini
      </div>
      <span style="font-size:12px;color:var(--gray)">Powered by Claude</span>
    </div>
    <div class="hl-card-body">

      <!-- Quick questions — disesuaikan role -->
      <div style="margin-bottom:14px">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--gray);margin-bottom:8px">Pertanyaan cepat:</div>
        <div style="display:flex;flex-wrap:wrap;gap:8px" id="quickQuestions">
          <?php
          $role = $user['role'] ?? 'staff';
          if ($role === 'superadmin' || $role === 'admin'):
          ?>
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="askAI(this.textContent)">Berikan ringkasan eksekutif</button>
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="askAI(this.textContent)">Bagaimana tren profit periode ini?</button>
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="askAI(this.textContent)">Layanan mana yang paling menguntungkan?</button>
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="askAI(this.textContent)">Rekomendasi untuk meningkatkan revenue</button>
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="askAI(this.textContent)">Prediksi dan proyeksi ke depan</button>
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="askAI(this.textContent)">Apakah ada risiko bisnis yang perlu diwaspadai?</button>
          <?php else: ?>
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="askAI(this.textContent)">Order apa yang harus diprioritaskan?</button>
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="askAI(this.textContent)">Customer mana yang perlu dihubungi?</button>
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="askAI(this.textContent)">Apa yang perlu saya selesaikan hari ini?</button>
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="askAI(this.textContent)">Layanan apa yang paling banyak diminta?</button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Input pertanyaan custom -->
      <div style="display:flex;gap:8px;margin-bottom:16px">
        <input type="text" id="aiQuestion" class="hl-input" style="flex:1"
          placeholder="Tanya apa saja tentang data laporan ini... (Enter untuk kirim)"
          onkeydown="if(event.key==='Enter') askAI()"/>
        <button class="hl-btn hl-btn-primary" onclick="askAI()" id="btnAskAI" style="white-space:nowrap">
          Tanya AI
        </button>
      </div>

      <!-- Chat history -->
      <div id="aiChatHistory" style="display:none;margin-bottom:12px;max-height:400px;overflow-y:auto"></div>

      <!-- Loading / Response area -->
      <div id="aiResponse" style="display:none;background:linear-gradient(135deg,#F5F3FF,#EDE9FE);border-radius:var(--r);padding:16px;border-left:3px solid #764ba2"></div>

    </div>
  </div>

</div>

<script>
let chartOmsetInstance = null;
let harianData  = null;
let bulananData = null;

function localDateStr(d) {
  const dt = d || new Date();
  return dt.getFullYear() + '-' +
    String(dt.getMonth()+1).padStart(2,'0') + '-' +
    String(dt.getDate()).padStart(2,'0');
}
function localMonthStr(d) {
  const dt = d || new Date();
  return dt.getFullYear() + '-' + String(dt.getMonth()+1).padStart(2,'0');
}

document.addEventListener('DOMContentLoaded', () => {
  initFilter('harianFilter');
  initFilter('bulananFilter');
  initFilter('lrFilter');
  const today = localDateStr();
  const bulan = today.substring(0,7);
  document.getElementById('hTgl').value    = today;
  document.getElementById('bBulan').value  = bulan;
  document.getElementById('lrDari').value  = bulan + '-01';
  document.getElementById('lrSampai').value= today;
  loadHarian();
});

// ── TABS ──────────────────────────────────────────────
function switchTab(name, el) {
  ['tabHarian','tabBulanan','tabLR','tabProd'].forEach(id => {
    const el2 = document.getElementById(id);
    if (el2) el2.style.display = 'none';
  });
  const tabMap = {'harian':'tabHarian','bulanan':'tabBulanan','lr':'tabLR','produktivitas':'tabProd'};
  const target = document.getElementById(tabMap[name]);
  if (target) target.style.display = 'block';
  document.querySelectorAll('.ptab').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  if (name==='bulanan' && !bulananData) loadBulanan();
  if (name==='produktivitas') loadProd();
}

// ── HARIAN ────────────────────────────────────────────
async function loadHarian() {
  const tgl = document.getElementById('hTgl').value;
  if (!tgl) return;

  const r = await fetch('laporan.php?action=harian&tgl=' + tgl);
  harianData = await r.json();
  const d = harianData;

  const omset     = parseFloat(d.order.omset||0);
  const terkumpul = parseFloat(d.order.terkumpul||0);
  document.getElementById('hOmset').textContent    = 'Rp ' + omset.toLocaleString('id-ID');
  document.getElementById('hTerkumpul').textContent= 'Rp ' + terkumpul.toLocaleString('id-ID');
  document.getElementById('hOrder').textContent    = d.order.total_order + ' order';
  document.getElementById('hDiskon').textContent   = 'Rp ' + parseFloat(d.order.total_diskon||0).toLocaleString('id-ID');

  document.getElementById('hLayananBody').innerHTML = d.layanan.length
    ? d.layanan.map(l => `<tr>
        <td data-lbl="Layanan">${esc(l.nama_layanan)}</td>
        <td data-lbl="Jml" class="td-num">${parseFloat(l.total_jumlah).toLocaleString('id-ID')}</td>
        <td data-lbl="Order" class="td-num">${l.total_order}</td>
        <td data-lbl="Omset" class="td-num td-green">Rp ${parseFloat(l.total_omset).toLocaleString('id-ID')}</td>
      </tr>`).join('')
    : '<tr><td colspan="4" class="empty">Belum ada layanan hari ini</td></tr>';

  const kasMasuk  = parseFloat(d.kas.kas_masuk||0);
  const kasKeluar = parseFloat(d.kas.kas_keluar||0);
  const kasSaldo  = kasMasuk - kasKeluar;
  document.getElementById('hKasBody').innerHTML = `
    <div style="display:flex;flex-direction:column;gap:10px">
      <div style="display:flex;justify-content:space-between;padding:10px;background:#D1FAE5;border-radius:var(--r)">
        <span style="color:#065F46;font-weight:600">💚 Kas Masuk</span>
        <span style="font-family:var(--mono);font-weight:700;color:#065F46">Rp ${kasMasuk.toLocaleString('id-ID')}</span>
      </div>
      <div style="display:flex;justify-content:space-between;padding:10px;background:#FEE2E2;border-radius:var(--r)">
        <span style="color:#991B1B;font-weight:600">❤️ Kas Keluar</span>
        <span style="font-family:var(--mono);font-weight:700;color:#991B1B">Rp ${kasKeluar.toLocaleString('id-ID')}</span>
      </div>
      <div style="display:flex;justify-content:space-between;padding:12px;background:${kasSaldo>=0?'var(--navy-d)':'#7F1D1D'};border-radius:var(--r)">
        <span style="color:rgba(255,255,255,.7);font-weight:700">💎 Saldo Bersih</span>
        <span style="font-family:var(--mono);font-weight:800;font-size:1.1rem;color:var(--teal)">Rp ${kasSaldo.toLocaleString('id-ID')}</span>
      </div>
      <div style="padding:10px;background:var(--off);border-radius:var(--r);font-size:13px">
        <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap">
          <span class="badge b-lunas">${d.order.lunas} Lunas</span>
          <span class="badge b-dp">${d.order.dp_count} DP</span>
          <span class="badge b-belum_bayar">${d.order.belum_bayar} Belum Bayar</span>
        </div>
      </div>
    </div>`;

  let totalOmset = 0, totalTerkumpul = 0;
  document.getElementById('hOrderBody').innerHTML = d.orders.length
    ? d.orders.map(o => {
        totalOmset     += parseFloat(o.total||0);
        totalTerkumpul += parseFloat(o.dp||0);
        return `<tr>
          <td data-lbl="No Order" style="font-family:var(--mono);font-size:12px;color:var(--teal-d)">${o.no_order}</td>
          <td data-lbl="Pelanggan" style="font-weight:600">${esc(o.nama_pelanggan)}</td>
          <td data-lbl="Layanan" style="font-size:12px;color:var(--gray);max-width:150px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(o.layanan_list||'-')}</td>
          <td data-lbl="Status"><span class="badge" style="background:var(--light);color:var(--gray);font-size:10px">${statusLabel(o.status_proses)}</span></td>
          <td data-lbl="Bayar"><span class="badge b-${o.status_bayar}">${bayarLabel(o.status_bayar)}</span></td>
          <td data-lbl="Total" class="td-num">Rp ${parseFloat(o.total).toLocaleString('id-ID')}</td>
          <td data-lbl="Terkumpul" class="td-num td-green">Rp ${parseFloat(o.dp||0).toLocaleString('id-ID')}</td>
        </tr>`;
      }).join('')
    : '<tr><td colspan="7" class="empty">Tidak ada order hari ini</td></tr>';

  document.getElementById('hOrderFoot').style.display     = d.orders.length ? '' : 'none';
  document.getElementById('hFootTotal').textContent       = 'Rp ' + totalOmset.toLocaleString('id-ID');
  document.getElementById('hFootTerkumpul').textContent   = 'Rp ' + totalTerkumpul.toLocaleString('id-ID');
  document.getElementById('hOrderCount').textContent      = d.orders.length + ' order';
}

// ── BULANAN ───────────────────────────────────────────
async function loadBulanan() {
  const bulan = document.getElementById('bBulan').value;
  if (!bulan) return;

  const r = await fetch('laporan.php?action=bulanan&bulan=' + bulan);
  bulananData = await r.json();
  const d = bulananData;

  document.getElementById('bOmset').textContent    = 'Rp ' + parseFloat(d.summary.omset||0).toLocaleString('id-ID');
  document.getElementById('bTerkumpul').textContent= 'Rp ' + parseFloat(d.summary.terkumpul||0).toLocaleString('id-ID');
  document.getElementById('bOrder').textContent    = d.summary.total_order + ' order';
  document.getElementById('bPiutang').textContent  = 'Rp ' + parseFloat(d.summary.total_piutang||0).toLocaleString('id-ID');

  if (chartOmsetInstance) { chartOmsetInstance.destroy(); chartOmsetInstance = null; }
  const labels       = d.daily.map(x => x.tgl.substring(8));
  const omsetData    = d.daily.map(x => parseFloat(x.omset));
  const terkumpulData= d.daily.map(x => parseFloat(x.terkumpul));
  chartOmsetInstance = new Chart(document.getElementById('chartOmset'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label:'Omset',     data:omsetData,     backgroundColor:'rgba(27,45,90,.7)',   borderRadius:4 },
        { label:'Terkumpul', data:terkumpulData, backgroundColor:'rgba(53,232,213,.6)', borderRadius:4 },
      ]
    },
    options: {
      responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{ position:'top' } },
      scales:{ y:{ beginAtZero:true, ticks:{ callback: v => 'Rp '+v.toLocaleString('id-ID') } } }
    }
  });

  document.getElementById('bLayananBody').innerHTML = d.top_layanan.length
    ? d.top_layanan.map((l,i) => `<tr>
        <td data-lbl="#" style="color:var(--gray);font-size:12px">${i+1}</td>
        <td data-lbl="Layanan">${esc(l.nama_layanan)}</td>
        <td data-lbl="Order" class="td-num">${l.total_order}</td>
        <td data-lbl="Omset" class="td-num td-green">Rp ${parseFloat(l.total_omset).toLocaleString('id-ID')}</td>
      </tr>`).join('')
    : '<tr><td colspan="4" class="empty">Belum ada data</td></tr>';

  const kasMasuk  = parseFloat(d.kas.kas_masuk||0);
  const kasKeluar = parseFloat(d.kas.kas_keluar||0);
  document.getElementById('bKasBody').innerHTML = `
    <div style="display:flex;flex-direction:column;gap:8px;font-size:14px">
      <div style="display:flex;justify-content:space-between"><span style="color:var(--gray)">💚 Kas Masuk</span><span style="font-family:var(--mono);font-weight:600;color:var(--green)">Rp ${kasMasuk.toLocaleString('id-ID')}</span></div>
      <div style="display:flex;justify-content:space-between"><span style="color:var(--gray)">❤️ Kas Keluar</span><span style="font-family:var(--mono);font-weight:600;color:var(--red)">Rp ${kasKeluar.toLocaleString('id-ID')}</span></div>
      <div style="border-top:2px solid var(--light);padding-top:8px;margin-top:4px;display:flex;justify-content:space-between">
        <span style="font-weight:700">💎 Saldo Bersih</span>
        <span style="font-family:var(--mono);font-weight:800;color:${kasMasuk-kasKeluar>=0?'var(--green)':'var(--red)'}">Rp ${(kasMasuk-kasKeluar).toLocaleString('id-ID')}</span>
      </div>
    </div>`;

  let totalPeng = 0;
  document.getElementById('bPengeluaranBody').innerHTML = d.pengeluaran.length
    ? d.pengeluaran.map(p => {
        totalPeng += parseFloat(p.total);
        return `<tr>
          <td data-lbl="Kategori">${esc(p.kategori)}</td>
          <td data-lbl="Jml" class="td-num">${p.count}×</td>
          <td data-lbl="Total" class="td-num td-red">Rp ${parseFloat(p.total).toLocaleString('id-ID')}</td>
        </tr>`;
      }).join('')
    : '<tr><td colspan="3" class="empty">Tidak ada pengeluaran</td></tr>';
  document.getElementById('bPengeluaranTotal').textContent = 'Rp ' + totalPeng.toLocaleString('id-ID');
}

// ── AI INSIGHT ────────────────────────────────────────
async function loadAiInsightOutlet(){
  const dari   = document.getElementById('lrDari').value;
  const sampai = document.getElementById('lrSampai').value;
  if (!dari || !sampai) { showToast('Pilih periode dulu', 'error'); return; }

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
  titleEl.textContent = `Periode ${dari} → ${sampai}`;

  try {
    const r = await fetch(`/ERP/harpy/laporan.php?action=ai_insight&dari=${dari}&sampai=${sampai}`);
    const d = await r.json();
    loading.style.display = 'none';

    if (d.error) {
      errBox.textContent = d.error;
      errBox.style.display = 'block';
      return;
    }

    document.getElementById('aiSummary').textContent = d.summary || '(Tidak ada ringkasan)';
    const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    document.getElementById('aiHighlights').innerHTML =
      (d.highlights || []).map(h => `<li>${esc(h)}</li>`).join('') || '<li>—</li>';
    document.getElementById('aiRecommendations').innerHTML =
      (d.recommendations || []).map(h => `<li>${esc(h)}</li>`).join('') || '<li>—</li>';

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

// ── LABA RUGI ─────────────────────────────────────────
async function loadLR() {
  const dari   = document.getElementById('lrDari').value;
  const sampai = document.getElementById('lrSampai').value;
  if (!dari || !sampai) return;

  document.getElementById('lrContent').innerHTML = `
    <div class="hl-skel-card"><span class="hl-skel xl" style="width:55%"></span>
      <div style="margin-top:14px">
        <span class="hl-skel" style="width:80%;display:block"></span>
        <span class="hl-skel" style="width:60%;display:block;margin-top:8px"></span>
        <span class="hl-skel" style="width:75%;display:block;margin-top:8px"></span>
      </div>
    </div>`;

  const r = await fetch(`laporan.php?action=lr&dari=${dari}&sampai=${sampai}`);
  const d = await r.json();

  const isLaba  = d.laba_rugi >= 0;
  const pend    = d.pendapatan;
  const totalP  = parseFloat(d.total_pendapatan);
  const totalB  = parseFloat(d.total_beban);
  const lr      = parseFloat(d.laba_rugi);
  const fmtDari   = fmtDate(dari);
  const fmtSampai = fmtDate(sampai);

  let trendHTML = '';
  if (d.trend.length > 1) {
    trendHTML = `<div class="card">
      <div class="card-header"><div class="card-title">📈 Trend Omset per Bulan</div></div>
      <div class="card-body"><div class="chart-wrap"><canvas id="chartLR"></canvas></div></div>
    </div>`;
  }

  document.getElementById('lrContent').innerHTML = `
    <div class="lr-box ${isLaba?'laba':'rugi'}">
      <div class="lr-title">Laporan Laba / Rugi · ${fmtDari} — ${fmtSampai}</div>
      <div class="lr-num">${isLaba?'LABA':'RUGI'} Rp ${Math.abs(lr).toLocaleString('id-ID')}</div>
      <div class="lr-sub">Margin: ${totalP > 0 ? ((lr/totalP)*100).toFixed(1) : 0}% dari total pendapatan</div>
    </div>

    <div class="grid-2">
      <div>
        <div class="lr-section">
          <div class="lr-section-title" style="color:var(--green)">💚 PENDAPATAN</div>
          <div class="lr-row">
            <span class="lr-label">Dari Order Laundry (terkumpul)</span>
            <span class="lr-value td-green">Rp ${parseFloat(pend.pendapatan_terkumpul||0).toLocaleString('id-ID')}</span>
          </div>
          <div class="lr-row">
            <span class="lr-label" style="font-size:12px;color:var(--gray)">  ↳ Total order (termasuk piutang)</span>
            <span class="lr-value" style="font-size:12px;color:var(--gray)">Rp ${parseFloat(pend.pendapatan_total||0).toLocaleString('id-ID')}</span>
          </div>
          ${d.kas_masuk.map(k=>`
          <div class="lr-row">
            <span class="lr-label">${esc(k.kategori)}</span>
            <span class="lr-value td-green">Rp ${parseFloat(k.total).toLocaleString('id-ID')}</span>
          </div>`).join('')}
          <div class="lr-row lr-total">
            <span>Total Pendapatan</span>
            <span class="lr-value td-green" style="font-size:1.1rem">Rp ${totalP.toLocaleString('id-ID')}</span>
          </div>
        </div>
      </div>

      <div>
        <div class="lr-section">
          <div class="lr-section-title" style="color:var(--red)">❤️ BEBAN & PENGELUARAN</div>
          ${d.beban.length ? d.beban.map(b=>`
          <div class="lr-row">
            <span class="lr-label">${esc(b.kategori)} <span style="font-size:11px;color:var(--gray)">(${b.count}×)</span></span>
            <span class="lr-value td-red">Rp ${parseFloat(b.total).toLocaleString('id-ID')}</span>
          </div>`).join('') : '<div class="lr-row"><span class="lr-label" style="color:var(--gray)">Belum ada pengeluaran</span><span>-</span></div>'}
          <div class="lr-row lr-total">
            <span>Total Beban</span>
            <span class="lr-value td-red" style="font-size:1.1rem">Rp ${totalB.toLocaleString('id-ID')}</span>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title">📊 Ringkasan L/R</div></div>
      <div class="card-body">
        <table style="width:100%;font-size:14px">
          <tr style="border-bottom:1px solid var(--light)"><td style="padding:10px 0;color:var(--gray)">Total Pendapatan</td><td style="text-align:right;font-family:var(--mono);font-weight:600;color:var(--green)">Rp ${totalP.toLocaleString('id-ID')}</td></tr>
          <tr style="border-bottom:1px solid var(--light)"><td style="padding:10px 0;color:var(--gray)">Total Beban</td><td style="text-align:right;font-family:var(--mono);font-weight:600;color:var(--red)">- Rp ${totalB.toLocaleString('id-ID')}</td></tr>
          <tr style="border-top:2px solid var(--navy)"><td style="padding:14px 0;font-weight:800;font-size:15px;color:var(--navy)">${isLaba?'LABA BERSIH':'RUGI BERSIH'}</td>
          <td style="text-align:right;font-family:var(--mono);font-weight:800;font-size:1.3rem;color:${isLaba?'var(--green)':'var(--red)'}">Rp ${Math.abs(lr).toLocaleString('id-ID')}</td></tr>
        </table>
      </div>
    </div>

    ${trendHTML}`;

  if (d.trend.length > 1) {
    setTimeout(() => {
      new Chart(document.getElementById('chartLR'), {
        type: 'line',
        data: {
          labels: d.trend.map(x => x.bulan),
          datasets: [
            { label:'Omset',     data:d.trend.map(x=>parseFloat(x.omset)),     borderColor:'#1B2D5A', backgroundColor:'rgba(27,45,90,.1)', tension:.4, fill:true },
            { label:'Terkumpul', data:d.trend.map(x=>parseFloat(x.terkumpul)), borderColor:'#35E8D5', backgroundColor:'rgba(53,232,213,.1)', tension:.4, fill:true },
          ]
        },
        options: {
          responsive:true, maintainAspectRatio:false,
          plugins:{ legend:{ position:'top' } },
          scales:{ y:{ beginAtZero:true, ticks:{ callback: v => 'Rp '+v.toLocaleString('id-ID') } } }
        }
      });
    }, 100);
  }
}

// ── EXPORT CSV ────────────────────────────────────────
function exportCSV(type) {
  if (type === 'harian' && harianData) {
    const rows = [['No Order','Pelanggan','Layanan','Status','Bayar','Total','Terkumpul']];
    harianData.orders.forEach(o => rows.push([o.no_order,o.nama_pelanggan,o.layanan_list||'',o.status_proses,o.status_bayar,o.total,o.dp||0]));
    downloadCSV(rows, 'laporan_harian_' + document.getElementById('hTgl').value + '.csv');
  } else if (type === 'bulanan' && bulananData) {
    const rows = [['Tanggal','Total Order','Omset','Terkumpul']];
    bulananData.daily.forEach(d => rows.push([d.tgl,d.total_order,d.omset,d.terkumpul]));
    downloadCSV(rows, 'laporan_bulanan_' + document.getElementById('bBulan').value + '.csv');
  } else if (type === 'produktivitas') {
    exportProdCSV();
  }
}
async function exportProdCSV(){
  const bulan = document.getElementById('prodBulan').value;
  try {
    const r = await fetch('laporan.php?action=produktivitas&bulan='+bulan);
    const d = await r.json();
    if (d.error){ alert('⚠️ '+d.error); return; }
    const rows = [['Karyawan','Jabatan','Hari Efektif','Hadir','Telat','Izin','Sakit','Alpha','Order Handle','Skor %']];
    d.rows.forEach(r => {
      const skor = d.hari_eff > 0 ? Math.round((r.hadir - r.telat*0.5 - r.alpha) / d.hari_eff * 100) : 0;
      rows.push([r.nama, r.jabatan||'', d.hari_eff, r.hadir, r.telat, r.izin, r.sakit, r.alpha, r.order_handle, skor]);
    });
    downloadCSV(rows, 'produktivitas_'+bulan+'.csv');
  } catch(e){ alert('Gagal: '+e.message); }
}

function downloadCSV(rows, filename) {
  const csv  = rows.map(r => r.map(c => '"' + String(c).replace(/"/g,'""') + '"').join(',')).join('\n');
  const blob = new Blob(['﻿'+csv], {type:'text/csv;charset=utf-8'});
  const a    = document.createElement('a');
  a.href     = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
}

// ── PRODUKTIVITAS KARYAWAN ────────────────────────────
async function loadProd(){
  const bulan = document.getElementById('prodBulan').value;
  const box = document.getElementById('prodContent');
  box.innerHTML = '<div class="empty">⏳ Memuat…</div>';
  try {
    const r = await fetch('laporan.php?action=produktivitas&bulan='+bulan);
    const d = await r.json();
    if (d.error){ box.innerHTML = `<div class="empty">⚠️ ${d.error}</div>`; return; }
    if (!d.rows.length){ box.innerHTML = '<div class="empty">Tidak ada karyawan aktif di outlet.</div>'; return; }

    const hariEff = d.hari_eff;
    let html = `<div style="font-size:12px;color:#6B7280;margin-bottom:10px">Bulan ${bulan} · ${hariEff} hari efektif s/d hari ini</div>`;
    html += '<div style="overflow-x:auto"><table class="hl-table hl-stack-mobile"><thead><tr><th>Karyawan</th><th>Jabatan</th><th style="text-align:center">Hadir</th><th style="text-align:center">Telat</th><th style="text-align:center">Izin</th><th style="text-align:center">Sakit</th><th style="text-align:center">Alpha</th><th style="text-align:right">Order Handle</th><th style="text-align:center">Skor</th></tr></thead><tbody>';
    d.rows.forEach(r => {
      const skor = hariEff > 0 ? Math.round((r.hadir - r.telat*0.5 - r.alpha) / hariEff * 100) : 0;
      const pillClass = skor>=90?'background:#D1FAE5;color:#065F46':(skor>=70?'background:#FEF3C7;color:#92400E':'background:#FEE2E2;color:#991B1B');
      html += `<tr>
        <td data-lbl="Karyawan"><strong>${r.nama}</strong></td>
        <td data-lbl="Jabatan"><small style="color:#6B7280">${r.jabatan||'-'}</small></td>
        <td data-lbl="Hadir" style="text-align:center">${r.hadir}/${hariEff}</td>
        <td data-lbl="Telat" style="text-align:center">${r.telat>0?`<span style="background:#FEF3C7;color:#92400E;font-size:11px;font-weight:700;padding:2px 7px;border-radius:100px">${r.telat}</span>`:'0'}</td>
        <td data-lbl="Izin" style="text-align:center">${r.izin}</td>
        <td data-lbl="Sakit" style="text-align:center">${r.sakit}</td>
        <td data-lbl="Alpha" style="text-align:center">${r.alpha>0?`<span style="background:#FEE2E2;color:#991B1B;font-size:11px;font-weight:700;padding:2px 7px;border-radius:100px">${r.alpha}</span>`:'0'}</td>
        <td data-lbl="Order Handle" style="text-align:right;font-family:monospace;font-weight:700">${r.order_handle}</td>
        <td data-lbl="Skor" style="text-align:center"><span style="${pillClass};font-size:11px;font-weight:700;padding:2px 9px;border-radius:100px">${skor}%</span></td>
      </tr>`;
    });
    html += '</tbody></table></div>';
    html += '<div style="font-size:11px;color:#9CA3AF;margin-top:8px">Skor disiplin = (Hadir − Telat×0.5 − Alpha) ÷ Hari Efektif × 100. Order Handle dihitung dari kolom <code>handled_by</code> (di-set otomatis saat status pertama kali keluar dari "masuk").</div>';
    box.innerHTML = html;
  } catch(e){ box.innerHTML = `<div class="empty">⚠️ ${e.message}</div>`; }
}

// ── AI ANALYST ────────────────────────────────────────
let chatHistory = [];

function getCurrentData() {
  const activeTab = document.querySelector('.ptab.active')?.textContent?.trim() || '';
  if (activeTab.includes('Harian')) {
    return { tipe:'harian', tgl:document.getElementById('hTgl').value, data:harianData };
  } else if (activeTab.includes('Bulanan')) {
    return { tipe:'bulanan', bulan:document.getElementById('bBulan').value, data:bulananData };
  } else {
    return { tipe:'lr', dari:document.getElementById('lrDari').value, sampai:document.getElementById('lrSampai').value, data:null };
  }
}

async function askAI(quickQuestion = null) {
  const pertanyaan = quickQuestion || document.getElementById('aiQuestion').value.trim();
  if (!pertanyaan) { document.getElementById('aiQuestion').focus(); return; }

  const ctx = getCurrentData();
  if (!ctx.data && ctx.tipe !== 'lr') {
    showToast('Muat laporan terlebih dahulu', 'error'); return;
  }

  document.getElementById('aiQuestion').value = '';

  const histEl = document.getElementById('aiChatHistory');
  histEl.style.display = 'block';
  histEl.innerHTML += `
    <div style="display:flex;justify-content:flex-end;margin-bottom:10px">
      <div style="background:var(--navy);color:white;border-radius:12px 12px 4px 12px;padding:10px 14px;max-width:80%;font-size:14px">${esc(pertanyaan)}</div>
    </div>`;
  histEl.scrollTop = histEl.scrollHeight;

  const respEl = document.getElementById('aiResponse');
  respEl.style.display = 'block';
  respEl.innerHTML = '<div style="color:#5B21B6;font-size:13px">⚙️ AI sedang menganalisis data laporan...</div>';

  const btn = document.getElementById('btnAskAI');
  btn.disabled = true; btn.textContent = '⏳';

  try {
    const r = await fetch('ai.php?action=laporan_analyze', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
      body: JSON.stringify({
        pertanyaan,
        tipe:    ctx.tipe,
        periode: ctx.tgl || ctx.bulan || (ctx.dari + ' s/d ' + ctx.sampai),
        data:    ctx.data,
        history: chatHistory.slice(-4),
      })
    });
    const d = await r.json();

    if (d.error) {
      respEl.innerHTML = `<div style="color:var(--red);font-size:13px">❌ ${esc(d.error)}</div>`;
      return;
    }

    const jawaban = d.jawaban || '';
    respEl.style.display = 'none';
    respEl.innerHTML = '';

    histEl.innerHTML += `
      <div style="display:flex;justify-content:flex-start;margin-bottom:12px;gap:8px">
        <span style="background:linear-gradient(135deg,#667eea,#764ba2);color:white;font-size:10px;font-weight:800;padding:3px 8px;border-radius:100px;white-space:nowrap;margin-top:2px;flex-shrink:0">AI</span>
        <div style="background:#F5F3FF;border:1px solid #DDD6FE;border-radius:4px 12px 12px 12px;padding:10px 14px;max-width:85%;font-size:13px;color:var(--dark);line-height:1.6">
          ${formatAIResponse(jawaban)}
          <div style="font-size:11px;color:var(--gray);text-align:right;margin-top:6px">${new Date().toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'})}</div>
        </div>
      </div>`;
    histEl.scrollTop = histEl.scrollHeight;

    chatHistory.push({ role:'user', content:pertanyaan });
    chatHistory.push({ role:'assistant', content:jawaban });

  } catch(e) {
    respEl.innerHTML = `<div style="color:var(--red);font-size:13px">❌ Error: ${e.message}</div>`;
  } finally {
    btn.disabled = false; btn.textContent = 'Tanya AI';
  }
}

function formatAIResponse(text) {
  return text
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.*?)\*/g, '<em>$1</em>')
    .replace(/\n\n/g, '</p><p style="margin-top:8px">')
    .replace(/\n/g, '<br>')
    .replace(/^/, '<p>').replace(/$/, '</p>');
}

// ── HELPERS ───────────────────────────────────────────
function statusLabel(s){return{'masuk':'Masuk','cuci':'Cuci','kering':'Kering','setrika':'Setrika','siap':'Siap','diambil':'Diambil'}[s]||s}
function bayarLabel(s){return{'lunas':'✅ Lunas','dp':'⚡ DP','belum_bayar':'⏳ Belum'}[s]||s}
function fmtDate(d){if(!d)return'-';return new Date(d+'T00:00:00').toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function showToast(msg,type='success'){const t=document.getElementById('toast');t.textContent=msg;t.className='hl-toast '+type+' show';setTimeout(()=>t.className='hl-toast',3500)}
</script>
<?php renderToast(); ?>
</body>
</html>
