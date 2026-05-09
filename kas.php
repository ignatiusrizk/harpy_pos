<?php
$activePage = 'kas';
require_once 'auth.php';
require_once 'components.php';
requireLogin();
$user = currentUser();
if (isset($_GET['logout'])) doLogout();

// ── API ───────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $pdo = getDB();

    // AUTO CREATE TABLE
    $pdo->exec("CREATE TABLE IF NOT EXISTS hl_kas (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        tanggal     DATE NOT NULL,
        tipe        ENUM('masuk','keluar') NOT NULL,
        kategori    VARCHAR(50) NOT NULL,
        keterangan  TEXT NOT NULL,
        jumlah      DECIMAL(12,2) NOT NULL,
        ref_order   VARCHAR(30) DEFAULT NULL,
        created_by  INT,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // LIST KAS
    if ($action === 'list') {
        $dari   = $_GET['dari']  ?? date('Y-m-01');
        $sampai = $_GET['sampai']?? date('Y-m-d');
        $tipe   = $_GET['tipe']  ?? '';
        $kat    = $_GET['kat']   ?? '';

        $where  = ['tanggal BETWEEN ? AND ?'];
        $params = [$dari, $sampai];
        if ($tipe) { $where[] = 'tipe=?';     $params[] = $tipe; }
        if ($kat)  { $where[] = 'kategori=?'; $params[] = $kat; }

        $sql = "SELECT * FROM hl_kas WHERE " . implode(' AND ',$where) . " ORDER BY tanggal DESC, id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Summary
        $smSQL = "SELECT
            COALESCE(SUM(CASE WHEN tipe='masuk'  THEN jumlah END),0) as total_masuk,
            COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah END),0) as total_keluar,
            COUNT(*) as total_transaksi
            FROM hl_kas WHERE " . implode(' AND ',$where);
        $sm = $pdo->prepare($smSQL);
        $sm->execute($params);
        $summary = $sm->fetch();

        echo json_encode(['data'=>$rows, 'summary'=>$summary]);
        exit;
    }

    // SAVE KAS
    if ($action === 'save' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('kas.create')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        $d = json_decode(file_get_contents('php://input'), true);
        if (empty($d['keterangan'])) { echo json_encode(['error'=>'Keterangan wajib diisi']); exit; }
        if (floatval($d['jumlah']) <= 0) { echo json_encode(['error'=>'Jumlah harus lebih dari 0']); exit; }

        if (!empty($d['id'])) {
            $pdo->prepare("UPDATE hl_kas SET tanggal=?,tipe=?,kategori=?,keterangan=?,jumlah=?,ref_order=? WHERE id=?")
                ->execute([$d['tanggal'],$d['tipe'],$d['kategori'],$d['keterangan'],$d['jumlah'],$d['ref_order']??null,$d['id']]);
        } else {
            $pdo->prepare("INSERT INTO hl_kas (tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (?,?,?,?,?,?,?)")
                ->execute([$d['tanggal'],$d['tipe'],$d['kategori'],$d['keterangan'],$d['jumlah'],$d['ref_order']??null,$user['id']]);
        }
        logAudit(!empty($d['id'])?'update':'create','kas',($d['tipe']??'').' Rp '.number_format($d['jumlah']??0,0,',','.').': '.($d['keterangan']??''));
        echo json_encode(['success'=>true]); exit;
    }

    // DELETE KAS
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('kas.delete')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        $d = json_decode(file_get_contents('php://input'), true);
        $pdo->prepare("DELETE FROM hl_kas WHERE id=?")->execute([$d['id']]);
        echo json_encode(['success'=>true]); exit;
    }

    // SUMMARY HARIAN
    if ($action === 'summary_harian') {
        $tgl = $_GET['tgl'] ?? date('Y-m-d');
        $kas = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN tipe='masuk'  THEN jumlah END),0) as kas_masuk,
            COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah END),0) as kas_keluar
            FROM hl_kas WHERE tanggal=?");
        $kas->execute([$tgl]);
        $kasData = $kas->fetch();

        $order = $pdo->prepare("SELECT
            COUNT(*) as total_order,
            COALESCE(SUM(total),0) as omset,
            COALESCE(SUM(dp),0) as terkumpul
            FROM hl_transaksi WHERE DATE(tanggal)=?");
        $order->execute([$tgl]);
        $orderData = $order->fetch();

        echo json_encode(array_merge($kasData, $orderData)); exit;
    }

    // KATEGORI LIST
    if ($action === 'kategori') {
        $rows = $pdo->query("SELECT DISTINCT kategori FROM hl_kas ORDER BY kategori")->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode($rows); exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Kas'); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--teal:#35E8D5;--teal-d:#1CC4B2;--teal-bg:#E8FBF9;--navy:#1B2D5A;--navy-d:#0F1C3A;--white:#fff;--off:#F7F8FC;--light:#EEF1F8;--gray:#6C7A8D;--dark:#1C1C2E;--red:#EF4444;--green:#10B981;--yellow:#F59E0B;--font:'Plus Jakarta Sans',sans-serif;--mono:'DM Mono',monospace;--r:10px;--r-lg:16px;--shadow:0 2px 12px rgba(27,45,90,.08);--shadow-lg:0 8px 32px rgba(27,45,90,.14)}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--off);color:var(--dark);min-height:100vh}



.topbar-brand span{color:var(--teal)}










.main{max-width:1100px;width:100%;margin:0 auto;padding:24px 20px}

/* SUMMARY CARDS */
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.sum-card{background:var(--white);border-radius:var(--r-lg);padding:18px 20px;border:1px solid rgba(27,45,90,.07);box-shadow:var(--shadow);position:relative;overflow:hidden}
.sum-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.sum-card.masuk::before{background:linear-gradient(90deg,var(--green),#34D399)}
.sum-card.keluar::before{background:linear-gradient(90deg,var(--red),#F87171)}
.sum-card.saldo::before{background:linear-gradient(90deg,var(--teal),var(--teal-d))}
.sum-card.order::before{background:linear-gradient(90deg,#8B5CF6,#A78BFA)}
.sum-num{font-size:1.4rem;font-weight:800;color:var(--navy);line-height:1;font-family:var(--mono);margin-bottom:4px}
.sum-num.green{color:var(--green)}
.sum-num.red{color:var(--red)}
.sum-num.teal{color:var(--teal-d)}
.sum-label{font-size:12px;color:var(--gray);font-weight:500}

/* DATE FILTER */
.date-filter{display:flex;gap:10px;align-items:center;margin-bottom:20px;flex-wrap:wrap;background:var(--white);padding:14px 18px;border-radius:var(--r-lg);border:1px solid rgba(27,45,90,.07);box-shadow:var(--shadow)}
.date-filter label{font-size:12px;font-weight:700;color:var(--navy);white-space:nowrap}
.date-filter input,.date-filter select{padding:8px 12px;border:1.5px solid rgba(27,45,90,.14);border-radius:var(--r);font-family:var(--font);font-size:13px;color:var(--dark);background:var(--off);outline:none;transition:all .2s}
.date-filter input:focus,.date-filter select:focus{border-color:var(--teal)}
.shortcut-btns{display:flex;gap:6px}
.sc-btn{padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;border:1.5px solid rgba(27,45,90,.12);background:var(--off);cursor:pointer;font-family:var(--font);transition:all .2s;color:var(--navy)}
.sc-btn:hover,.sc-btn.active{background:var(--teal);color:var(--navy-d);border-color:var(--teal)}

/* LAYOUT 2 COL */
.layout-2{display:grid;grid-template-columns:360px 1fr;gap:20px;align-items:start}
.layout-2 > div{min-width:0}

/* CARD */
.card{background:var(--white);border-radius:var(--r-lg);border:1px solid rgba(27,45,90,.07);box-shadow:var(--shadow);overflow:hidden;margin-bottom:0}
.card-header{padding:14px 18px;border-bottom:1px solid var(--light);display:flex;align-items:center;justify-content:space-between}
.card-title{font-size:14px;font-weight:700;color:var(--navy);display:flex;align-items:center;gap:7px}
.card-body{padding:18px}

/* FORM */
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:12px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
label{font-size:11px;font-weight:700;color:var(--navy);letter-spacing:.05em;text-transform:uppercase}
label .req{color:var(--red)}
input,select,textarea{padding:9px 12px;border:1.5px solid rgba(27,45,90,.14);border-radius:var(--r);font-family:var(--font);font-size:14px;color:var(--dark);background:var(--off);outline:none;transition:all .2s;width:100%}
input:focus,select:focus,textarea:focus{border-color:var(--teal);background:var(--white);box-shadow:0 0 0 3px rgba(53,232,213,.1)}
textarea{resize:vertical;min-height:64px}

/* TIPE TOGGLE */
.tipe-toggle{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px}
.tipe-btn{padding:12px;border-radius:var(--r);border:2px solid rgba(27,45,90,.12);background:var(--off);cursor:pointer;text-align:center;font-family:var(--font);font-weight:600;font-size:14px;transition:all .2s}
.tipe-btn.masuk.active{background:#D1FAE5;border-color:var(--green);color:#065F46}
.tipe-btn.keluar.active{background:#FEE2E2;border-color:var(--red);color:#991B1B}
.tipe-btn:not(.active):hover{border-color:var(--teal)}

/* TABLE */
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;font-size:13.5px}
thead tr{background:var(--navy-d)}
thead th{padding:10px 12px;text-align:left;font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.6);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--light);transition:background .15s}
tbody tr:hover{background:var(--off)}
tbody td{padding:10px 12px;vertical-align:middle}
.td-jumlah{font-family:var(--mono);font-weight:700;text-align:right;font-size:14px}
.td-masuk{color:var(--green)}
.td-keluar{color:var(--red)}
tfoot tr{background:var(--navy);color:var(--white)}
tfoot td{padding:12px 12px;font-weight:700;font-size:13px}
tfoot td.td-jumlah{font-family:var(--mono)}

/* BADGE */
.badge{display:inline-block;font-size:11px;font-weight:600;letter-spacing:.04em;padding:3px 9px;border-radius:100px;white-space:nowrap}
.b-masuk{background:#D1FAE5;color:#065F46}
.b-keluar{background:#FEE2E2;color:#991B1B}
.b-kat{background:var(--light);color:var(--gray)}

/* SALDO BOX */
.saldo-box{background:linear-gradient(135deg,var(--navy-d),var(--navy));border-radius:var(--r-lg);padding:20px;color:var(--white);margin-bottom:16px}
.sb-row{display:flex;justify-content:space-between;padding:5px 0;font-size:14px}
.sb-label{color:rgba(255,255,255,.6)}
.sb-value{font-family:var(--mono);font-weight:600}
.sb-value.green{color:#6EE7B7}
.sb-value.red{color:#FCA5A5}
.sb-divider{border:none;border-top:1px solid rgba(255,255,255,.15);margin:8px 0}
.sb-saldo{font-size:1.4rem;font-weight:800;color:var(--teal)}

/* BTN */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 16px;border-radius:var(--r);font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;border:none}
.btn-primary{background:var(--teal);color:var(--navy-d);width:100%;padding:12px;font-size:14px}
.btn-primary:hover{background:var(--teal-d)}
.btn-outline{background:transparent;color:var(--navy);border:1.5px solid rgba(27,45,90,.2)}
.btn-outline:hover{background:var(--light)}
.btn-danger{background:#FEE2E2;color:var(--red)}
.btn-danger:hover{background:var(--red);color:white}
.btn-sm{padding:5px 10px;font-size:12px}
.btn-green{background:#D1FAE5;color:#065F46}
.btn-green:hover{background:var(--green);color:white}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,28,58,.6);backdrop-filter:blur(4px);z-index:200;align-items:center;justify-content:center;padding:20px}
.modal-overlay.open{display:flex}
.modal{background:var(--white);border-radius:var(--r-lg);width:460px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-lg)}
.modal-header{padding:16px 20px;border-bottom:1px solid var(--light);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:var(--white);z-index:10}
.modal-title{font-size:15px;font-weight:700;color:var(--navy)}
.modal-close{background:none;border:none;font-size:18px;cursor:pointer;color:var(--gray)}
.modal-body{padding:20px}
.modal-footer{padding:14px 20px;border-top:1px solid var(--light);display:flex;gap:8px;justify-content:flex-end;position:sticky;bottom:0;background:var(--white)}

.empty{text-align:center;padding:40px;color:var(--gray);font-size:14px}
.toast{position:fixed;bottom:20px;right:20px;z-index:999;padding:13px 18px;border-radius:var(--r);font-size:14px;font-weight:600;color:white;transform:translateY(60px);opacity:0;transition:all .3s cubic-bezier(.4,0,.2,1);pointer-events:none;max-width:320px}
.toast.show{transform:translateY(0);opacity:1}
.toast.success{background:var(--green)}
.toast.error{background:var(--red)}

@media(max-width:860px){
  .layout-2{grid-template-columns:1fr}
  .summary-grid{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:680px){
  .main{padding:12px 10px 80px}
  .summary-grid{grid-template-columns:repeat(2,1fr);gap:10px}
  .sum-card{padding:14px 16px}
  .sum-num{font-size:1.2rem}
  .sum-label{font-size:11px}
  .shortcut-btns{flex-wrap:wrap}
  .sc-btn{font-size:11px;padding:5px 10px}
  .date-filter input,.date-filter select{min-width:0 !important;font-size:13px}
  .card-header{padding:12px 14px;flex-wrap:wrap;gap:6px}
  .card-body{padding:14px}
  .form-row{grid-template-columns:1fr}
  .modal{width:100%;max-width:100%;border-radius:var(--r-lg) var(--r-lg) 0 0}
  .modal-overlay{align-items:flex-end;padding:0}
  .table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
  thead th{font-size:11px;padding:8px 10px}
  tbody td{font-size:12px;padding:8px 10px}
}
@media(max-width:400px){
  .main{padding:8px 8px 80px}
  .summary-grid{grid-template-columns:repeat(2,1fr);gap:8px}
  .sum-card{padding:12px}
  .sum-num{font-size:1rem}
}
</style>
</head>
<body>
<?php renderTopbar('kas'); ?>
<?php renderToast(); ?>

<div class="main">

  <!-- SUMMARY -->
  <div class="summary-grid">
    <div class="sum-card masuk">
      <div class="sum-num green" id="sumMasuk">Rp 0</div>
      <div class="sum-label">💚 Total Kas Masuk</div>
    </div>
    <div class="sum-card keluar">
      <div class="sum-num red" id="sumKeluar">Rp 0</div>
      <div class="sum-label">❤️ Total Kas Keluar</div>
    </div>
    <div class="sum-card saldo">
      <div class="sum-num teal" id="sumSaldo">Rp 0</div>
      <div class="sum-label">💎 Saldo Bersih</div>
    </div>
    <div class="sum-card order">
      <div class="sum-num" id="sumOrder" style="color:#8B5CF6">0</div>
      <div class="sum-label">📋 Transaksi Kas</div>
    </div>
  </div>

  <!-- DATE FILTER -->
  <div class="hl-filter-collapsible">
    <button class="hl-filter-toggle-btn" id="kasFilterBtn" onclick="toggleFilter('kasFilter')">
      🔍 Filter Periode <span class="hl-filter-active-dot hl-filter-active-dot show" id="kasFilterDot"></span>
      <span class="hl-toggle-arrow">▼</span>
    </button>
    <div class="hl-filter-bar date-filter" id="kasFilter">
      <label>Dari</label>
      <input type="date" id="fDari" onchange="loadKas()"/>
      <label>s/d</label>
      <input type="date" id="fSampai" onchange="loadKas()"/>
      <select id="fTipe" onchange="loadKas()" style="width:auto">
        <option value="">Semua Tipe</option>
        <option value="masuk">Kas Masuk</option>
        <option value="keluar">Kas Keluar</option>
      </select>
      <div class="shortcut-btns">
        <button class="sc-btn" onclick="setRange('hari',this)">Hari Ini</button>
        <button class="sc-btn active" onclick="setRange('bulan',this)">Bulan Ini</button>
        <button class="sc-btn" onclick="setRange('minggu',this)">7 Hari</button>
      </div>
      <button class="btn btn-outline btn-sm" onclick="loadKas()" style="margin-left:auto">🔄</button>
    </div>
  </div>

  <div class="layout-2">

    <!-- KOLOM KIRI: Form Input -->
    <div>
      <div class="card">
        <div class="card-header">
          <div class="card-title" id="formTitle">➕ Input Kas</div>
        </div>
        <div class="card-body">

          <!-- TIPE TOGGLE -->
          <div class="tipe-toggle">
            <button class="tipe-btn masuk active" id="btnMasuk" onclick="setTipe('masuk')">
              💚 Kas Masuk
            </button>
            <button class="tipe-btn keluar" id="btnKeluar" onclick="setTipe('keluar')">
              ❤️ Kas Keluar
            </button>
          </div>
          <input type="hidden" id="f_tipe" value="masuk"/>
          <input type="hidden" id="f_id" value=""/>

          <div class="form-row">
            <div class="form-group">
              <label>Tanggal <span class="req">*</span></label>
              <input type="date" id="f_tanggal"/>
            </div>
            <div class="form-group">
              <label>Jumlah (Rp) <span class="req">*</span></label>
              <input type="number" id="f_jumlah" placeholder="0" min="0" step="500"/>
            </div>
          </div>

          <div class="form-group">
            <label>Kategori <span class="req">*</span></label>
            <select id="f_kategori">
              <option value="">— Pilih Kategori —</option>
              <!-- Kas Masuk -->
              <optgroup label="💚 Kas Masuk" id="optMasuk">
                <option value="Penjualan Laundry">Penjualan Laundry</option>
                <option value="Pelunasan Order">Pelunasan Order</option>
                <option value="Pendapatan Lain">Pendapatan Lain</option>
                <option value="Modal">Modal</option>
              </optgroup>
              <!-- Kas Keluar -->
              <optgroup label="❤️ Kas Keluar" id="optKeluar">
                <option value="Gaji Karyawan">Gaji Karyawan</option>
                <option value="Bahan & Deterjen">Bahan & Deterjen</option>
                <option value="Listrik & Air">Listrik & Air</option>
                <option value="Sewa Tempat">Sewa Tempat</option>
                <option value="Peralatan">Peralatan</option>
                <option value="Transportasi">Transportasi</option>
                <option value="Operasional">Operasional</option>
                <option value="Lain-lain">Lain-lain</option>
              </optgroup>
            </select>
          </div>

          <div class="form-group">
            <label>Keterangan <span class="req">*</span></label>
            <textarea id="f_keterangan" placeholder="Deskripsi transaksi kas..."></textarea>
          </div>

          <div class="form-group">
            <label>No. Order Terkait (opsional)</label>
            <input type="text" id="f_ref_order" placeholder="Contoh: HL-20260501-001"
              style="font-family:var(--mono);text-transform:uppercase;letter-spacing:.06em"
              oninput="this.value=this.value.toUpperCase()"/>
          </div>

          <!-- PREVIEW JUMLAH -->
          <div id="jumlahPreview" style="display:none;text-align:center;padding:12px;border-radius:var(--r);margin-bottom:12px;font-size:1.2rem;font-weight:800;font-family:var(--mono)"></div>

          <button class="btn btn-primary" onclick="saveKas()" id="btnSave">
            💾 Simpan
          </button>
          <button class="btn btn-outline" onclick="resetForm()" style="width:100%;margin-top:8px">
            ↺ Reset
          </button>
        </div>
      </div>

      <!-- SALDO BOX HARI INI -->
      <div class="saldo-box" style="margin-top:16px" id="saldoBox">
        <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.4);margin-bottom:12px">📊 Ringkasan Hari Ini</div>
        <div class="sb-row">
          <span class="sb-label">Order Masuk</span>
          <span class="sb-value" id="sbOrder">-</span>
        </div>
        <div class="sb-row">
          <span class="sb-label">Omset</span>
          <span class="sb-value green" id="sbOmset">-</span>
        </div>
        <div class="sb-row">
          <span class="sb-label">Terkumpul</span>
          <span class="sb-value green" id="sbTerkumpul">-</span>
        </div>
        <hr class="sb-divider"/>
        <div class="sb-row">
          <span class="sb-label">Kas Masuk</span>
          <span class="sb-value green" id="sbKasMasuk">-</span>
        </div>
        <div class="sb-row">
          <span class="sb-label">Kas Keluar</span>
          <span class="sb-value red" id="sbKasKeluar">-</span>
        </div>
        <hr class="sb-divider"/>
        <div class="sb-row">
          <span style="color:white;font-weight:700">Saldo Bersih</span>
          <span class="sb-saldo" id="sbSaldo">-</span>
        </div>
      </div>
    </div>

    <!-- KOLOM KANAN: Tabel -->
    <div>
      <div class="card">
        <div class="card-header">
          <div class="card-title">📋 Riwayat Kas</div>
          <span id="tableInfo" style="font-size:12px;color:var(--gray)"></span>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Tanggal</th>
                <th>Tipe</th>
                <th>Kategori</th>
                <th>Keterangan</th>
                <th>Ref Order</th>
                <th style="text-align:right">Jumlah</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="tableBody">
              <tr><td colspan="7" class="empty">⏳ Memuat...</td></tr>
            </tbody>
            <tfoot id="tableFoot" style="display:none">
              <tr>
                <td colspan="4" style="color:rgba(255,255,255,.6)">Total Periode</td>
                <td></td>
                <td class="td-jumlah" id="footTotal"></td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- MODAL EDIT -->
<div class="modal-overlay" id="modalEdit">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">✏️ Edit Kas</span>
      <button class="modal-close" onclick="closeEditModal()">✕</button>
    </div>
    <div class="modal-body" id="editBody"></div>
    <div class="modal-footer">
      <button class="btn btn-outline btn-sm" onclick="closeEditModal()">Batal</button>
      <button class="btn btn-primary btn-sm" onclick="saveEdit()">💾 Simpan</button>
    </div>
  </div>
</div>

<?php renderToast(); ?>

<script>
let editData = null;


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
  document.getElementById('f_tanggal').value = today;
  setRange('bulan');
  loadSaldoHarian();

  document.getElementById('f_jumlah').addEventListener('input', updateJumlahPreview);
});

// ── TIPE TOGGLE ───────────────────────────────────────
function setTipe(tipe) {
  document.getElementById('f_tipe').value = tipe;
  document.getElementById('btnMasuk').classList.toggle('active', tipe==='masuk');
  document.getElementById('btnKeluar').classList.toggle('active', tipe==='keluar');
  updateJumlahPreview();
}

function updateJumlahPreview() {
  const jumlah = parseFloat(document.getElementById('f_jumlah').value) || 0;
  const tipe   = document.getElementById('f_tipe').value;
  const el     = document.getElementById('jumlahPreview');
  if (jumlah <= 0) { el.style.display='none'; return; }
  el.style.display = 'block';
  el.style.background = tipe==='masuk' ? '#D1FAE5' : '#FEE2E2';
  el.style.color = tipe==='masuk' ? '#065F46' : '#991B1B';
  el.textContent = (tipe==='masuk' ? '+ ' : '- ') + 'Rp ' + jumlah.toLocaleString('id-ID');
}

// ── DATE SHORTCUTS ────────────────────────────────────
function setRange(type, el) {
  const now = new Date();
  let dari, sampai;
  sampai = localDateStr(now);

  if (type === 'hari') {
    dari = sampai;
  } else if (type === 'minggu') {
    const w = new Date(now); w.setDate(w.getDate()-6);
    dari = localDateStr(w);
  } else {
    dari = now.getFullYear() + '-' + String(now.getMonth()+1).padStart(2,'0') + '-01';
  }

  document.getElementById('fDari').value   = dari;
  document.getElementById('fSampai').value = sampai;
  document.querySelectorAll('.sc-btn').forEach(b=>b.classList.remove('active'));
  if (el) el.classList.add('active');
  loadKas();
}

// ── LOAD KAS ──────────────────────────────────────────
async function loadKas() {
  const dari   = document.getElementById('fDari').value;
  const sampai = document.getElementById('fSampai').value;
  const tipe   = document.getElementById('fTipe').value;

  document.getElementById('tableBody').innerHTML = '<tr><td colspan="7" class="empty">⏳ Memuat...</td></tr>';

  const r = await fetch(`kas.php?action=list&dari=${dari}&sampai=${sampai}&tipe=${tipe}`);
  const d = await r.json();

  // Update summary
  const sm = d.summary;
  const masuk  = parseFloat(sm.total_masuk);
  const keluar = parseFloat(sm.total_keluar);
  const saldo  = masuk - keluar;
  document.getElementById('sumMasuk').textContent  = 'Rp ' + masuk.toLocaleString('id-ID');
  document.getElementById('sumKeluar').textContent = 'Rp ' + keluar.toLocaleString('id-ID');
  document.getElementById('sumSaldo').textContent  = 'Rp ' + saldo.toLocaleString('id-ID');
  document.getElementById('sumOrder').textContent  = sm.total_transaksi;
  document.getElementById('sumSaldo').style.color  = saldo >= 0 ? 'var(--green)' : 'var(--red)';

  if (!d.data?.length) {
    document.getElementById('tableBody').innerHTML = '<tr><td colspan="7" class="empty">📭 Belum ada data kas untuk periode ini.</td></tr>';
    document.getElementById('tableFoot').style.display = 'none';
    document.getElementById('tableInfo').textContent = '';
    return;
  }

  document.getElementById('tableBody').innerHTML = d.data.map(row => `
    <tr>
      <td style="white-space:nowrap;font-size:13px">${fmtDate(row.tanggal)}</td>
      <td><span class="badge b-${row.tipe}">${row.tipe==='masuk'?'💚 Masuk':'❤️ Keluar'}</span></td>
      <td><span class="badge b-kat">${esc(row.kategori)}</span></td>
      <td style="font-size:13px;max-width:200px">${esc(row.keterangan)}</td>
      <td style="font-family:var(--mono);font-size:12px;color:var(--teal-d)">${row.ref_order||'-'}</td>
      <td class="td-jumlah ${row.tipe==='masuk'?'td-masuk':'td-keluar'}">
        ${row.tipe==='masuk'?'+':'-'} Rp ${parseFloat(row.jumlah).toLocaleString('id-ID')}
      </td>
      <td>
        <div style="display:flex;gap:4px">
          <button class="btn btn-outline btn-sm" onclick="editKas(${row.id})">✏️</button>
          <button class="btn btn-danger btn-sm" onclick="deleteKas(${row.id})">🗑️</button>
        </div>
      </td>
    </tr>`).join('');

  // Footer total saldo
  document.getElementById('tableFoot').style.display = '';
  document.getElementById('footTotal').innerHTML =
    `<span style="color:#6EE7B7">+ Rp ${masuk.toLocaleString('id-ID')}</span>` +
    ` / <span style="color:#FCA5A5">- Rp ${keluar.toLocaleString('id-ID')}</span>` +
    ` = <span style="color:${saldo>=0?'var(--teal)':'#FCA5A5'}">Rp ${saldo.toLocaleString('id-ID')}</span>`;

  document.getElementById('tableInfo').textContent = `${d.data.length} transaksi`;
}

// ── SALDO HARIAN ──────────────────────────────────────
async function loadSaldoHarian() {
  const tgl = localDateStr();
  const r   = await fetch('kas.php?action=summary_harian&tgl=' + tgl);
  const d   = await r.json();

  document.getElementById('sbOrder').textContent     = d.total_order + ' order';
  document.getElementById('sbOmset').textContent     = 'Rp ' + parseFloat(d.omset).toLocaleString('id-ID');
  document.getElementById('sbTerkumpul').textContent = 'Rp ' + parseFloat(d.terkumpul).toLocaleString('id-ID');
  document.getElementById('sbKasMasuk').textContent  = 'Rp ' + parseFloat(d.kas_masuk).toLocaleString('id-ID');
  document.getElementById('sbKasKeluar').textContent = 'Rp ' + parseFloat(d.kas_keluar).toLocaleString('id-ID');
  const saldo = parseFloat(d.kas_masuk) - parseFloat(d.kas_keluar);
  document.getElementById('sbSaldo').textContent = 'Rp ' + saldo.toLocaleString('id-ID');
  document.getElementById('sbSaldo').style.color = saldo >= 0 ? 'var(--teal)' : '#FCA5A5';
}

// ── SAVE KAS ──────────────────────────────────────────
async function saveKas() {
  const jumlah   = parseFloat(document.getElementById('f_jumlah').value) || 0;
  const ket      = document.getElementById('f_keterangan').value.trim();
  const kategori = document.getElementById('f_kategori').value;

  if (jumlah <= 0)   { showToast('⚠️ Jumlah harus lebih dari 0', 'error'); return; }
  if (!ket)          { showToast('⚠️ Keterangan wajib diisi', 'error'); return; }
  if (!kategori)     { showToast('⚠️ Pilih kategori', 'error'); return; }

  const btn = document.getElementById('btnSave');
  btn.disabled = true; btn.textContent = '⏳ Menyimpan...';

  const payload = {
    id:        document.getElementById('f_id').value || null,
    tanggal:   document.getElementById('f_tanggal').value,
    tipe:      document.getElementById('f_tipe').value,
    kategori,
    keterangan:ket,
    jumlah,
    ref_order: document.getElementById('f_ref_order').value || null,
  };

  const r = await fetch('kas.php?action=save', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify(payload)
  });
  const d = await r.json();

  if (d.success) {
    showToast('✅ Kas berhasil disimpan!', 'success');
    resetForm();
    loadKas();
    loadSaldoHarian();
  } else {
    showToast('❌ ' + (d.error||'Gagal'), 'error');
  }
  btn.disabled = false; btn.textContent = '💾 Simpan';
}

// ── EDIT KAS ──────────────────────────────────────────
async function editKas(id) {
  // Ambil data dari tabel yang sudah di-render
  const r = await fetch(`kas.php?action=list&dari=2020-01-01&sampai=2030-12-31`);
  const d = await r.json();
  const row = d.data.find(x => x.id == id);
  if (!row) return;

  // Isi form langsung
  document.getElementById('f_id').value          = row.id;
  document.getElementById('f_tanggal').value     = row.tanggal;
  document.getElementById('f_jumlah').value      = row.jumlah;
  document.getElementById('f_keterangan').value  = row.keterangan;
  document.getElementById('f_kategori').value    = row.kategori;
  document.getElementById('f_ref_order').value   = row.ref_order || '';
  setTipe(row.tipe);
  updateJumlahPreview();

  document.getElementById('formTitle').textContent = '✏️ Edit Kas #' + row.id;
  document.querySelector('#btnSave').textContent = '💾 Update';

  // Scroll ke form
  document.querySelector('.card').scrollIntoView({behavior:'smooth'});
  showToast('📝 Edit mode — ubah data lalu klik Simpan', 'success');
}

async function deleteKas(id) {
  if (!confirm('Hapus catatan kas ini?')) return;
  const r = await fetch('kas.php?action=delete', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id})
  });
  const d = await r.json();
  if (d.success) { showToast('🗑️ Dihapus', 'success'); loadKas(); loadSaldoHarian(); }
  else showToast('❌ Gagal hapus', 'error');
}

function closeEditModal() { document.getElementById('modalEdit').classList.remove('open'); }

// ── RESET FORM ────────────────────────────────────────
function resetForm() {
  document.getElementById('f_id').value          = '';
  document.getElementById('f_jumlah').value      = '';
  document.getElementById('f_keterangan').value  = '';
  document.getElementById('f_kategori').value    = '';
  document.getElementById('f_ref_order').value   = '';
  document.getElementById('f_tanggal').value     = localDateStr();
  document.getElementById('jumlahPreview').style.display = 'none';
  document.getElementById('formTitle').textContent = '➕ Input Kas';
  document.getElementById('btnSave').textContent  = '💾 Simpan';
  setTipe('masuk');
}

// ── HELPERS ───────────────────────────────────────────
function fmtDate(d){if(!d)return'-';return new Date(d).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function showToast(msg,type='success'){const t=document.getElementById('toast');t.textContent=msg;t.className='toast '+type+' show';setTimeout(()=>t.className='toast',3500)}
</script>
</body>
</html>