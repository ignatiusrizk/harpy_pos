<?php
$activePage = 'laporan';
require_once 'auth.php';
require_once 'components.php';
requireLogin();
$user = currentUser();
if (isset($_GET['logout'])) doLogout();

// ── AKSES KONTROL — pakai RBAC permission ──────────
requirePermission('laporan.view_harian'); // minimal harus punya salah satu akses laporan

// ── API ───────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $pdo = getDB();

    // ── LAPORAN HARIAN ────────────────────────────────
    if ($action === 'harian') {
        if (!hasPermission('laporan.view_harian')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        $tgl = $_GET['tgl'] ?? date('Y-m-d');

        // Order summary
        $order = $pdo->prepare("SELECT
            COUNT(*) as total_order,
            COALESCE(SUM(total),0) as omset,
            COALESCE(SUM(dp),0) as terkumpul,
            COALESCE(SUM(diskon),0) as total_diskon,
            SUM(CASE WHEN status_bayar='lunas' THEN 1 ELSE 0 END) as lunas,
            SUM(CASE WHEN status_bayar='dp' THEN 1 ELSE 0 END) as dp_count,
            SUM(CASE WHEN status_bayar='belum_bayar' THEN 1 ELSE 0 END) as belum_bayar
            FROM hl_transaksi WHERE DATE(tanggal)=?");
        $order->execute([$tgl]);
        $orderData = $order->fetch();

        // Layanan terlaris hari ini
        $layanan = $pdo->prepare("SELECT i.nama_layanan,
            SUM(i.jumlah) as total_jumlah,
            COUNT(*) as total_order,
            SUM(i.subtotal) as total_omset
            FROM hl_transaksi_item i
            JOIN hl_transaksi t ON t.id=i.transaksi_id
            WHERE DATE(t.tanggal)=?
            GROUP BY i.nama_layanan ORDER BY total_omset DESC LIMIT 10");
        $layanan->execute([$tgl]);
        $layananData = $layanan->fetchAll();

        // Kas hari ini
        $kas = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN tipe='masuk'  THEN jumlah END),0) as kas_masuk,
            COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah END),0) as kas_keluar
            FROM hl_kas WHERE tanggal=?");
        $kas->execute([$tgl]);
        $kasData = $kas->fetch();

        // List order hari ini
        $orders = $pdo->prepare("SELECT t.no_order,t.nama_pelanggan,t.total,t.dp,t.sisa_bayar,
            t.status_proses,t.status_bayar,t.metode_bayar,
            GROUP_CONCAT(i.nama_layanan SEPARATOR ', ') as layanan_list
            FROM hl_transaksi t
            LEFT JOIN hl_transaksi_item i ON i.transaksi_id=t.id
            WHERE DATE(t.tanggal)=?
            GROUP BY t.id ORDER BY t.created_at DESC");
        $orders->execute([$tgl]);
        $orderList = $orders->fetchAll();

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
        $bulan = $_GET['bulan'] ?? date('Y-m');
        [$y,$m] = explode('-', $bulan);
        $dari   = "$y-$m-01";
        $sampai = date('Y-m-t', strtotime($dari));

        // Omset per hari (untuk chart)
        $daily = $pdo->prepare("SELECT DATE(tanggal) as tgl,
            COUNT(*) as total_order,
            COALESCE(SUM(total),0) as omset,
            COALESCE(SUM(dp),0) as terkumpul
            FROM hl_transaksi WHERE tanggal BETWEEN ? AND ?
            GROUP BY DATE(tanggal) ORDER BY tgl");
        $daily->execute([$dari,$sampai]);
        $dailyData = $daily->fetchAll();

        // Summary bulan
        $sum = $pdo->prepare("SELECT
            COUNT(*) as total_order,
            COALESCE(SUM(total),0) as omset,
            COALESCE(SUM(dp),0) as terkumpul,
            COALESCE(SUM(diskon),0) as total_diskon,
            COALESCE(SUM(sisa_bayar),0) as total_piutang,
            SUM(CASE WHEN status_bayar='lunas' THEN 1 ELSE 0 END) as lunas,
            SUM(CASE WHEN status_proses='diambil' THEN 1 ELSE 0 END) as selesai
            FROM hl_transaksi WHERE tanggal BETWEEN ? AND ?");
        $sum->execute([$dari,$sampai]);
        $sumData = $sum->fetch();

        // Layanan terlaris bulan ini
        $topLayanan = $pdo->prepare("SELECT i.nama_layanan,
            SUM(i.jumlah) as total_jumlah,
            COUNT(DISTINCT t.id) as total_order,
            SUM(i.subtotal) as total_omset
            FROM hl_transaksi_item i
            JOIN hl_transaksi t ON t.id=i.transaksi_id
            WHERE t.tanggal BETWEEN ? AND ?
            GROUP BY i.nama_layanan ORDER BY total_omset DESC LIMIT 10");
        $topLayanan->execute([$dari,$sampai]);
        $topLayananData = $topLayanan->fetchAll();

        // Kas bulan ini
        $kas = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN tipe='masuk'  THEN jumlah END),0) as kas_masuk,
            COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah END),0) as kas_keluar
            FROM hl_kas WHERE tanggal BETWEEN ? AND ?");
        $kas->execute([$dari,$sampai]);
        $kasData = $kas->fetch();

        // Pengeluaran per kategori
        $pengeluaran = $pdo->prepare("SELECT kategori,
            SUM(jumlah) as total,
            COUNT(*) as count
            FROM hl_kas WHERE tipe='keluar' AND tanggal BETWEEN ? AND ?
            GROUP BY kategori ORDER BY total DESC");
        $pengeluaran->execute([$dari,$sampai]);
        $pengeluaranData = $pengeluaran->fetchAll();

        // Pemasukan per kategori
        $pemasukan = $pdo->prepare("SELECT kategori,
            SUM(jumlah) as total,
            COUNT(*) as count
            FROM hl_kas WHERE tipe='masuk' AND tanggal BETWEEN ? AND ?
            GROUP BY kategori ORDER BY total DESC");
        $pemasukan->execute([$dari,$sampai]);
        $pemasukanData = $pemasukan->fetchAll();

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
        $dari   = $_GET['dari']   ?? date('Y-m-01');
        $sampai = $_GET['sampai'] ?? date('Y-m-d');

        // Pendapatan dari order
        $pend = $pdo->prepare("SELECT
            COALESCE(SUM(dp),0) as pendapatan_terkumpul,
            COALESCE(SUM(total),0) as pendapatan_total,
            COALESCE(SUM(diskon),0) as total_diskon,
            COUNT(*) as total_order
            FROM hl_transaksi WHERE tanggal BETWEEN ? AND ?");
        $pend->execute([$dari,$sampai]);
        $pendData = $pend->fetch();

        // Pendapatan dari kas masuk (non-order)
        $kasMasuk = $pdo->prepare("SELECT kategori, SUM(jumlah) as total
            FROM hl_kas WHERE tipe='masuk' AND tanggal BETWEEN ? AND ?
            GROUP BY kategori ORDER BY total DESC");
        $kasMasuk->execute([$dari,$sampai]);
        $kasMasukData = $kasMasuk->fetchAll();

        $totalKasMasuk = array_sum(array_column($kasMasukData, 'total'));

        // Beban/pengeluaran
        $beban = $pdo->prepare("SELECT kategori, SUM(jumlah) as total, COUNT(*) as count
            FROM hl_kas WHERE tipe='keluar' AND tanggal BETWEEN ? AND ?
            GROUP BY kategori ORDER BY total DESC");
        $beban->execute([$dari,$sampai]);
        $bebanData = $beban->fetchAll();

        $totalBeban = array_sum(array_column($bebanData, 'total'));

        // Hitung L/R
        $totalPendapatan = floatval($pendData['pendapatan_terkumpul']) + $totalKasMasuk;
        $labaRugi = $totalPendapatan - $totalBeban;

        // Omset per bulan untuk trend (max 12 bulan)
        $trend = $pdo->prepare("SELECT
            DATE_FORMAT(tanggal,'%Y-%m') as bulan,
            COUNT(*) as total_order,
            COALESCE(SUM(total),0) as omset,
            COALESCE(SUM(dp),0) as terkumpul
            FROM hl_transaksi WHERE tanggal BETWEEN ? AND ?
            GROUP BY DATE_FORMAT(tanggal,'%Y-%m') ORDER BY bulan");
        $trend->execute([$dari,$sampai]);
        $trendData = $trend->fetchAll();

        echo json_encode([
            'pendapatan'      => $pendData,
            'kas_masuk'       => $kasMasukData,
            'total_kas_masuk' => $totalKasMasuk,
            'beban'           => $bebanData,
            'total_beban'     => $totalBeban,
            'total_pendapatan'=> $totalPendapatan,
            'laba_rugi'       => $labaRugi,
            'trend'           => $trendData,
            'periode'         => ['dari'=>$dari,'sampai'=>$sampai],
        ]); exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Laporan'); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="harpy-erp.css"/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
:root{--teal:#35E8D5;--teal-d:#1CC4B2;--teal-bg:#E8FBF9;--navy:#1B2D5A;--navy-d:#0F1C3A;--white:#fff;--off:#F7F8FC;--light:#EEF1F8;--gray:#6C7A8D;--dark:#1C1C2E;--red:#EF4444;--green:#10B981;--yellow:#F59E0B;--purple:#8B5CF6;--font:'Plus Jakarta Sans',sans-serif;--mono:'DM Mono',monospace;--r:10px;--r-lg:16px;--shadow:0 2px 12px rgba(27,45,90,.08);--shadow-lg:0 8px 32px rgba(27,45,90,.14)}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--off);color:var(--dark);min-height:100vh}
.topbar{background:var(--navy-d);padding:0 24px;height:56px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;border-bottom:1px solid rgba(53,232,213,.15)}

.topbar-brand span{color:var(--teal)}









.main{max-width:1200px;margin:0 auto;padding:24px 20px}

/* TABS */
.page-tabs{display:flex;gap:4px;background:var(--white);border-radius:var(--r-lg);padding:6px;box-shadow:var(--shadow);margin-bottom:24px;border:1px solid rgba(27,45,90,.07)}
.ptab{flex:1;padding:10px 16px;border-radius:var(--r);font-size:14px;font-weight:600;color:var(--gray);cursor:pointer;text-align:center;transition:all .2s;border:none;background:transparent;font-family:var(--font)}
.ptab:hover{color:var(--navy)}
.ptab.active{background:var(--navy);color:var(--white)}

/* FILTER BAR */
.filter-bar{display:flex;gap:10px;align-items:center;background:var(--white);padding:14px 18px;border-radius:var(--r-lg);border:1px solid rgba(27,45,90,.07);box-shadow:var(--shadow);margin-bottom:20px;flex-wrap:wrap}
.filter-bar label{font-size:12px;font-weight:700;color:var(--navy)}
.filter-bar input,.filter-bar select{padding:8px 12px;border:1.5px solid rgba(27,45,90,.14);border-radius:var(--r);font-family:var(--font);font-size:13px;background:var(--off);outline:none;transition:all .2s}
.filter-bar input:focus,.filter-bar select:focus{border-color:var(--teal)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 16px;border-radius:var(--r);font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;border:none}
.btn-primary{background:var(--teal);color:var(--navy-d)}
.btn-primary:hover{background:var(--teal-d)}
.btn-outline{background:transparent;color:var(--navy);border:1.5px solid rgba(27,45,90,.2)}
.btn-outline:hover{background:var(--light)}
.btn-sm{padding:6px 12px;font-size:12px}

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
  .topbar,.page-tabs,.filter-bar,.btn,.no-print{display:none!important}
  .main{padding:0}
  .card{box-shadow:none;border:1px solid #ddd;break-inside:avoid}
}

.empty{text-align:center;padding:32px;color:var(--gray);font-size:14px}
.loading{text-align:center;padding:24px;color:var(--gray);font-size:14px}
.toast{position:fixed;bottom:20px;right:20px;z-index:999;padding:13px 18px;border-radius:var(--r);font-size:14px;font-weight:600;color:white;transform:translateY(60px);opacity:0;transition:all .3s;pointer-events:none}
.toast.show{transform:translateY(0);opacity:1}
.toast.success{background:var(--green)}
.toast.error{background:var(--red)}

@media(max-width:900px){.stat-grid{grid-template-columns:repeat(2,1fr)}.grid-2,.grid-3{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php renderTopbar('laporan'); ?>

<?php require_once 'components.php'; ?>

<div class="main">

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
  </div>

  <!-- ══ TAB HARIAN ═══════════════════════════════════ -->
  <?php if (hasPermission('laporan.view_harian')): ?>
  <div id="tabHarian">
    <div class="filter-bar no-print">
      <label>Tanggal</label>
      <input type="date" id="hTgl"/>
      <button class="btn btn-primary btn-sm" onclick="loadHarian()">🔍 Tampilkan</button>
      <button class="btn btn-outline btn-sm" onclick="window.print()">🖨️ Print</button>
      <button class="btn btn-outline btn-sm" onclick="exportCSV('harian')">📥 Export CSV</button>
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
          <table>
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
        <table>
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
    <div class="filter-bar no-print">
      <label>Bulan</label>
      <input type="month" id="bBulan"/>
      <button class="btn btn-primary btn-sm" onclick="loadBulanan()">🔍 Tampilkan</button>
      <button class="btn btn-outline btn-sm" onclick="window.print()">🖨️ Print</button>
      <button class="btn btn-outline btn-sm" onclick="exportCSV('bulanan')">📥 Export CSV</button>
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
          <table>
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
            <table>
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
    <div class="filter-bar no-print">
      <label>Dari</label>
      <input type="date" id="lrDari"/>
      <label>s/d</label>
      <input type="date" id="lrSampai"/>
      <button class="btn btn-primary btn-sm" onclick="loadLR()">🔍 Hitung L/R</button>
      <button class="btn btn-outline btn-sm" onclick="window.print()">🖨️ Print</button>
    </div>

    <div id="lrContent"><div class="empty">Pilih periode lalu klik "Hitung L/R"</div></div>
  </div>
  <?php endif; ?>

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

<?php renderToast(); ?>

<script>
let chartOmsetInstance = null;
let harianData = null;
let bulananData = null;


// Helper: ambil tanggal lokal (bukan UTC)
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
  ['tabHarian','tabBulanan','tabLR'].forEach(id =>
    document.getElementById(id).style.display = 'none');
  const tabMap = {'harian':'tabHarian','bulanan':'tabBulanan','lr':'tabLR'};
  document.getElementById(tabMap[name]).style.display = 'block';
  document.querySelectorAll('.ptab').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  if (name==='bulanan' && !bulananData) loadBulanan();
}

// ── HARIAN ────────────────────────────────────────────
async function loadHarian() {
  const tgl = document.getElementById('hTgl').value;
  if (!tgl) return;

  const r = await fetch('laporan.php?action=harian&tgl=' + tgl);
  harianData = await r.json();
  const d = harianData;

  // Stats
  const omset    = parseFloat(d.order.omset||0);
  const terkumpul= parseFloat(d.order.terkumpul||0);
  document.getElementById('hOmset').textContent    = 'Rp ' + omset.toLocaleString('id-ID');
  document.getElementById('hTerkumpul').textContent= 'Rp ' + terkumpul.toLocaleString('id-ID');
  document.getElementById('hOrder').textContent    = d.order.total_order + ' order';
  document.getElementById('hDiskon').textContent   = 'Rp ' + parseFloat(d.order.total_diskon||0).toLocaleString('id-ID');

  // Layanan
  document.getElementById('hLayananBody').innerHTML = d.layanan.length
    ? d.layanan.map(l => `<tr>
        <td>${esc(l.nama_layanan)}</td>
        <td class="td-num">${parseFloat(l.total_jumlah).toLocaleString('id-ID')}</td>
        <td class="td-num">${l.total_order}</td>
        <td class="td-num td-green">Rp ${parseFloat(l.total_omset).toLocaleString('id-ID')}</td>
      </tr>`).join('')
    : '<tr><td colspan="4" class="empty">Belum ada layanan hari ini</td></tr>';

  // Kas
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
        <div style="display:flex;justify-content:space-between;color:var(--gray)"><span>Status Bayar:</span></div>
        <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap">
          <span class="badge b-lunas">${d.order.lunas} Lunas</span>
          <span class="badge b-dp">${d.order.dp_count} DP</span>
          <span class="badge b-belum_bayar">${d.order.belum_bayar} Belum Bayar</span>
        </div>
      </div>
    </div>`;

  // Order list
  let totalOmset = 0, totalTerkumpul = 0;
  document.getElementById('hOrderBody').innerHTML = d.orders.length
    ? d.orders.map(o => {
        totalOmset     += parseFloat(o.total||0);
        totalTerkumpul += parseFloat(o.dp||0);
        return `<tr>
          <td style="font-family:var(--mono);font-size:12px;color:var(--teal-d)">${o.no_order}</td>
          <td style="font-weight:600">${esc(o.nama_pelanggan)}</td>
          <td style="font-size:12px;color:var(--gray);max-width:150px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(o.layanan_list||'-')}</td>
          <td><span class="badge" style="background:var(--light);color:var(--gray);font-size:10px">${statusLabel(o.status_proses)}</span></td>
          <td><span class="badge b-${o.status_bayar}">${bayarLabel(o.status_bayar)}</span></td>
          <td class="td-num">Rp ${parseFloat(o.total).toLocaleString('id-ID')}</td>
          <td class="td-num td-green">Rp ${parseFloat(o.dp||0).toLocaleString('id-ID')}</td>
        </tr>`;
      }).join('')
    : '<tr><td colspan="7" class="empty">Tidak ada order hari ini</td></tr>';

  document.getElementById('hOrderFoot').style.display = d.orders.length ? '' : 'none';
  document.getElementById('hFootTotal').textContent      = 'Rp ' + totalOmset.toLocaleString('id-ID');
  document.getElementById('hFootTerkumpul').textContent  = 'Rp ' + totalTerkumpul.toLocaleString('id-ID');
  document.getElementById('hOrderCount').textContent     = d.orders.length + ' order';
}

// ── BULANAN ───────────────────────────────────────────
async function loadBulanan() {
  const bulan = document.getElementById('bBulan').value;
  if (!bulan) return;

  const r = await fetch('laporan.php?action=bulanan&bulan=' + bulan);
  bulananData = await r.json();
  const d = bulananData;

  // Stats
  document.getElementById('bOmset').textContent    = 'Rp ' + parseFloat(d.summary.omset||0).toLocaleString('id-ID');
  document.getElementById('bTerkumpul').textContent= 'Rp ' + parseFloat(d.summary.terkumpul||0).toLocaleString('id-ID');
  document.getElementById('bOrder').textContent    = d.summary.total_order + ' order';
  document.getElementById('bPiutang').textContent  = 'Rp ' + parseFloat(d.summary.total_piutang||0).toLocaleString('id-ID');

  // Chart omset harian
  if (chartOmsetInstance) { chartOmsetInstance.destroy(); chartOmsetInstance = null; }
  const labels = d.daily.map(x => x.tgl.substring(8)); // tanggal saja
  const omsetData   = d.daily.map(x => parseFloat(x.omset));
  const terkumpulData = d.daily.map(x => parseFloat(x.terkumpul));
  chartOmsetInstance = new Chart(document.getElementById('chartOmset'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label:'Omset', data:omsetData, backgroundColor:'rgba(27,45,90,.7)', borderRadius:4 },
        { label:'Terkumpul', data:terkumpulData, backgroundColor:'rgba(53,232,213,.6)', borderRadius:4 },
      ]
    },
    options: {
      responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{ position:'top' } },
      scales:{ y:{ beginAtZero:true, ticks:{ callback: v => 'Rp '+v.toLocaleString('id-ID') } } }
    }
  });

  // Layanan terlaris
  document.getElementById('bLayananBody').innerHTML = d.top_layanan.length
    ? d.top_layanan.map((l,i) => `<tr>
        <td style="color:var(--gray);font-size:12px">${i+1}</td>
        <td>${esc(l.nama_layanan)}</td>
        <td class="td-num">${l.total_order}</td>
        <td class="td-num td-green">Rp ${parseFloat(l.total_omset).toLocaleString('id-ID')}</td>
      </tr>`).join('')
    : '<tr><td colspan="4" class="empty">Belum ada data</td></tr>';

  // Kas
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

  // Pengeluaran
  let totalPeng = 0;
  document.getElementById('bPengeluaranBody').innerHTML = d.pengeluaran.length
    ? d.pengeluaran.map(p => {
        totalPeng += parseFloat(p.total);
        return `<tr>
          <td>${esc(p.kategori)}</td>
          <td class="td-num">${p.count}×</td>
          <td class="td-num td-red">Rp ${parseFloat(p.total).toLocaleString('id-ID')}</td>
        </tr>`;
      }).join('')
    : '<tr><td colspan="3" class="empty">Tidak ada pengeluaran</td></tr>';
  document.getElementById('bPengeluaranTotal').textContent = 'Rp ' + totalPeng.toLocaleString('id-ID');
}

// ── LABA RUGI ─────────────────────────────────────────
async function loadLR() {
  const dari   = document.getElementById('lrDari').value;
  const sampai = document.getElementById('lrSampai').value;
  if (!dari || !sampai) return;

  document.getElementById('lrContent').innerHTML = '<div class="loading">⏳ Menghitung L/R...</div>';

  const r = await fetch(`laporan.php?action=lr&dari=${dari}&sampai=${sampai}`);
  const d = await r.json();

  const isLaba  = d.laba_rugi >= 0;
  const pend    = d.pendapatan;
  const totalP  = parseFloat(d.total_pendapatan);
  const totalB  = parseFloat(d.total_beban);
  const lr      = parseFloat(d.laba_rugi);
  const fmtDari   = fmtDate(dari);
  const fmtSampai = fmtDate(sampai);

  // Trend chart data
  let trendHTML = '';
  if (d.trend.length > 1) {
    trendHTML = `<div class="card">
      <div class="card-header"><div class="card-title">📈 Trend Omset per Bulan</div></div>
      <div class="card-body"><div class="chart-wrap"><canvas id="chartLR"></canvas></div></div>
    </div>`;
  }

  document.getElementById('lrContent').innerHTML = `
    <!-- HEADER L/R -->
    <div class="lr-box ${isLaba?'laba':'rugi'}">
      <div class="lr-title">Laporan Laba / Rugi · ${fmtDari} — ${fmtSampai}</div>
      <div class="lr-num">${isLaba?'LABA':'RUGI'} Rp ${Math.abs(lr).toLocaleString('id-ID')}</div>
      <div class="lr-sub">Margin: ${totalP > 0 ? ((lr/totalP)*100).toFixed(1) : 0}% dari total pendapatan</div>
    </div>

    <div class="grid-2">
      <!-- PENDAPATAN -->
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

      <!-- BEBAN -->
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

    <!-- SUMMARY TABLE -->
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

  // Render trend chart
  if (d.trend.length > 1) {
    setTimeout(() => {
      new Chart(document.getElementById('chartLR'), {
        type: 'line',
        data: {
          labels: d.trend.map(x => x.bulan),
          datasets: [
            { label:'Omset', data:d.trend.map(x=>parseFloat(x.omset)), borderColor:'#1B2D5A', backgroundColor:'rgba(27,45,90,.1)', tension:.4, fill:true },
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
  }
}

function downloadCSV(rows, filename) {
  const csv = rows.map(r => r.map(c => '"' + String(c).replace(/"/g,'""') + '"').join(',')).join('\n');
  const blob = new Blob(['\ufeff'+csv], {type:'text/csv;charset=utf-8'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
}

// ── AI ANALYST ────────────────────────────────────────
let chatHistory = [];

function getCurrentData() {
  // Ambil data yang sedang aktif
  const activeTab = document.querySelector('.ptab.active')?.textContent?.trim() || '';
  if (activeTab.includes('Harian')) {
    return { tipe: 'harian', tgl: document.getElementById('hTgl').value, data: harianData };
  } else if (activeTab.includes('Bulanan')) {
    return { tipe: 'bulanan', bulan: document.getElementById('bBulan').value, data: bulananData };
  } else {
    return {
      tipe: 'lr',
      dari: document.getElementById('lrDari').value,
      sampai: document.getElementById('lrSampai').value,
      data: null // L/R data loaded inline
    };
  }
}

async function askAI(quickQuestion = null) {
  const pertanyaan = quickQuestion || document.getElementById('aiQuestion').value.trim();
  if (!pertanyaan) { document.getElementById('aiQuestion').focus(); return; }

  const ctx = getCurrentData();
  if (!ctx.data && ctx.tipe !== 'lr') {
    showToast('Muat laporan terlebih dahulu', 'error'); return;
  }

  // Clear input
  document.getElementById('aiQuestion').value = '';

  // Add to chat history display
  const histEl = document.getElementById('aiChatHistory');
  histEl.style.display = 'block';
  histEl.innerHTML += `
    <div style="display:flex;justify-content:flex-end;margin-bottom:10px">
      <div style="background:var(--navy);color:white;border-radius:12px 12px 4px 12px;padding:10px 14px;max-width:80%;font-size:14px">${esc(pertanyaan)}</div>
    </div>`;
  histEl.scrollTop = histEl.scrollHeight;

  // Loading
  const respEl = document.getElementById('aiResponse');
  respEl.style.display = 'block';
  respEl.innerHTML = '<div style="color:#5B21B6;font-size:13px;display:flex;align-items:center;gap:8px"><span style="animation:spin 1s linear infinite;display:inline-block">⚙️</span> AI sedang menganalisis data laporan...</div>';

  const btn = document.getElementById('btnAskAI');
  btn.disabled = true; btn.textContent = '⏳';

  try {
    const r = await fetch('ai.php?action=laporan_analyze', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        pertanyaan,
        tipe:    ctx.tipe,
        periode: ctx.tgl || ctx.bulan || (ctx.dari + ' s/d ' + ctx.sampai),
        data:    ctx.data,
        history: chatHistory.slice(-4), // 4 pesan terakhir untuk context
      })
    });
    const d = await r.json();

    if (d.error) {
      respEl.innerHTML = `<div style="color:var(--red);font-size:13px">❌ ${esc(d.error)}</div>`;
      return;
    }

    const jawaban = d.jawaban || '';
    respEl.innerHTML = `
      <div style="display:flex;align-items:flex-start;gap:10px">
        <span style="background:linear-gradient(135deg,#667eea,#764ba2);color:white;font-size:10px;font-weight:800;padding:3px 8px;border-radius:100px;white-space:nowrap;margin-top:2px">AI</span>
        <div style="font-size:14px;color:var(--dark);line-height:1.7;flex:1">${formatAIResponse(jawaban)}</div>
      </div>
      <div style="font-size:11px;color:var(--gray);text-align:right;margin-top:10px">
        ${new Date().toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'})}
      </div>`;

    // Add to history
    histEl.innerHTML += `
      <div style="display:flex;justify-content:flex-start;margin-bottom:10px">
        <div style="background:#F5F3FF;border:1px solid #DDD6FE;border-radius:4px 12px 12px 12px;padding:10px 14px;max-width:85%;font-size:13px;color:var(--dark);line-height:1.6">${formatAIResponse(jawaban)}</div>
      </div>`;
    histEl.scrollTop = histEl.scrollHeight;

    // Save to chat history
    chatHistory.push({ role: 'user', content: pertanyaan });
    chatHistory.push({ role: 'assistant', content: jawaban });

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
function showToast(msg,type='success'){const t=document.getElementById('toast');t.textContent=msg;t.className='toast '+type+' show';setTimeout(()=>t.className='toast',3500)}
</script>
</body>
</html>