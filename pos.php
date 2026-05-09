<?php
$activePage = 'pos';
require_once 'auth.php';
require_once 'components.php';
requireLogin();
$user = currentUser();
if (isset($_GET['logout'])) doLogout();

// ── API HANDLER ───────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $pdo = getDB();

    // GET layanan list
    if ($action === 'get_layanan') {
        $rows = $pdo->query("SELECT * FROM hl_layanan WHERE is_active=1 ORDER BY kategori,urutan")->fetchAll();
        echo json_encode($rows); exit;
    }

    // SEARCH pelanggan
    if ($action === 'search_pelanggan') {
        $q = '%' . ($_GET['q'] ?? '') . '%';
        $rows = $pdo->prepare("SELECT * FROM hl_pelanggan WHERE (nama LIKE ? OR telepon LIKE ?) AND is_active=1 LIMIT 8");
        $rows->execute([$q, $q]);
        echo json_encode($rows->fetchAll()); exit;
    }

    // SAVE transaksi
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hasPermission('pos.create')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $data  = json_decode(file_get_contents('php://input'), true);
        $items = $data['items'] ?? [];
        if (empty($items)) { echo json_encode(['error'=>'Minimal 1 item']); exit; }
        if (count($items) > 30) { echo json_encode(['error'=>'Maksimal 30 item per order']); exit; }

        // Validasi field utama
        $errors = validateInputs([
            'nama_pelanggan' => ['Nama pelanggan', false, 100],
            'telepon'        => ['Telepon',        false, 20],
            'catatan'        => ['Catatan',        false, 500],
        ], $data);
        if ($errors) { echo json_encode(['error' => implode(' ', $errors)]); exit; }

        // Sanitize
        $data['nama_pelanggan'] = sanitizeStr($data['nama_pelanggan'] ?? '', 100);
        $data['telepon']        = sanitizeStr(preg_replace('/[^0-9+\-\s]/', '', $data['telepon'] ?? ''), 20);
        $data['catatan']        = sanitizeStr($data['catatan'] ?? '', 500);

        // Validasi setiap item
        foreach ($items as $i => $item) {
            if (floatval($item['jumlah'] ?? 0) <= 0)       { echo json_encode(['error'=>'Jumlah item harus lebih dari 0']); exit; }
            if (floatval($item['harga_satuan'] ?? 0) < 0)  { echo json_encode(['error'=>'Harga tidak boleh negatif']); exit; }
            if (empty($item['nama_layanan']))               { echo json_encode(['error'=>'Nama layanan tidak boleh kosong']); exit; }
        }

        $pdo->beginTransaction();
        try {
            // Generate no order
            $prefix = 'HL-' . date('Ymd') . '-';
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE no_order LIKE ?");
            $cnt->execute([$prefix . '%']);
            $no = $prefix . str_pad((int)$cnt->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);

            // Hitung total
            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += floatval($item['jumlah']) * floatval($item['harga_satuan']);
            }
            $diskon    = floatval($data['diskon'] ?? 0);
            $total     = $subtotal - $diskon;
            $dp        = floatval($data['dp'] ?? 0);
            $sisa      = $total - $dp;
            $status_b  = $dp >= $total ? 'lunas' : ($dp > 0 ? 'dp' : 'belum_bayar');

            // Upsert pelanggan
            $pel_id = null;
            $nama   = trim($data['nama_pelanggan'] ?? '');
            $telp   = trim($data['telepon'] ?? '');
            if ($nama) {
                $existing = $pdo->prepare("SELECT id FROM hl_pelanggan WHERE nama=? AND telepon=?");
                $existing->execute([$nama, $telp]);
                $pel = $existing->fetch();
                if ($pel) {
                    $pel_id = $pel['id'];
                    $pdo->prepare("UPDATE hl_pelanggan SET total_order=total_order+1 WHERE id=?")->execute([$pel_id]);
                } else {
                    $pdo->prepare("INSERT INTO hl_pelanggan (nama,telepon,tipe) VALUES (?,?,?)")
                        ->execute([$nama, $telp, 'retail']);
                    $pel_id = $pdo->lastInsertId();
                }
            }

            // Insert transaksi header
            $stmt = $pdo->prepare("INSERT INTO hl_transaksi
                (no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,
                 subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,
                 status_proses,estimasi_selesai,catatan,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $no, $data['tanggal'], $pel_id, $nama, $telp,
                $subtotal, $diskon, $total, $dp, $sisa,
                $data['metode_bayar'] ?? 'cash', $status_b,
                'masuk', $data['estimasi'] ?? null,
                $data['catatan'] ?? '', $user['id']
            ]);
            $trx_id = $pdo->lastInsertId();

            // Insert items
            $istmt = $pdo->prepare("INSERT INTO hl_transaksi_item
                (transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal,catatan_item)
                VALUES (?,?,?,?,?,?,?,?)");
            foreach ($items as $item) {
                $sub = floatval($item['jumlah']) * floatval($item['harga_satuan']);
                $istmt->execute([
                    $trx_id,
                    $item['layanan_id'] ?: null,
                    $item['nama_layanan'],
                    $item['satuan'],
                    $item['jumlah'],
                    $item['harga_satuan'],
                    $sub,
                    $item['catatan_item'] ?? ''
                ]);
            }

            // Log status
            $pdo->prepare("INSERT INTO hl_proses_log (transaksi_id,status_baru,oleh) VALUES (?,?,?)")
                ->execute([$trx_id, 'masuk', $user['nama']]);

            // ── AUTO INSERT KAS jika ada pembayaran DP/Lunas ──
            if ($dp > 0) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS hl_kas (
                    id         INT AUTO_INCREMENT PRIMARY KEY,
                    tanggal    DATE NOT NULL,
                    tipe       ENUM('masuk','keluar') NOT NULL,
                    kategori   VARCHAR(50) NOT NULL,
                    keterangan TEXT NOT NULL,
                    jumlah     DECIMAL(12,2) NOT NULL,
                    ref_order  VARCHAR(30) DEFAULT NULL,
                    created_by INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $metode      = $data['metode_bayar'] ?? 'cash';
                $metodeLabel = ['cash'=>'Cash','transfer'=>'Transfer','qris'=>'QRIS'][$metode] ?? 'Cash';
                $isPaid      = $dp >= $total;
                $kasKet      = ($isPaid ? 'Pembayaran LUNAS' : 'DP/Uang Muka') .
                               ' order ' . $no .
                               ' - ' . trim($data['nama_pelanggan'] ?? '') .
                               ' via ' . $metodeLabel;

                $pdo->prepare("INSERT INTO hl_kas (tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (?,?,?,?,?,?,?)")
                    ->execute([
                        $data['tanggal'],
                        'masuk',
                        $isPaid ? 'Penjualan Laundry' : 'Penjualan Laundry',
                        $kasKet,
                        $dp,
                        $no,
                        $user['id']
                    ]);
            }

            $pdo->commit();
            logAudit('create', 'orders', 'Buat order baru: ' . $no . ' - ' . ($data['nama_pelanggan']??''), $no);
            echo json_encode(['success'=>true, 'no_order'=>$no, 'id'=>$trx_id,
                'total'=>$total, 'sisa'=>$sisa]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // GET detail transaksi (untuk print)
    if ($action === 'get_detail') {
        $id  = intval($_GET['id']);
        $trx = $pdo->prepare("SELECT * FROM hl_transaksi WHERE id=?");
        $trx->execute([$id]);
        $t = $trx->fetch();
        if (!$t) { echo json_encode(['error'=>'Not found']); exit; }
        $items = $pdo->prepare("SELECT * FROM hl_transaksi_item WHERE transaksi_id=? ORDER BY id");
        $items->execute([$id]);
        $t['items'] = $items->fetchAll();
        echo json_encode($t); exit;
    }

    echo json_encode(['error'=>'Unknown action']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('POS'); ?>
<style>
:root {
  --teal:#35E8D5;--teal-d:#1CC4B2;--teal-bg:#E8FBF9;
  --navy:#1B2D5A;--navy-d:#0F1C3A;
  --white:#fff;--off:#F7F8FC;--light:#EEF1F8;
  --gray:#6C7A8D;--dark:#1C1C2E;
  --red:#EF4444;--green:#10B981;--yellow:#F59E0B;--blue:#3B82F6;
  --font:'Plus Jakarta Sans',sans-serif;--mono:'DM Mono',monospace;
  --r:10px;--r-lg:16px;
  --shadow:0 2px 12px rgba(27,45,90,.08);
  --shadow-lg:0 8px 32px rgba(27,45,90,.14);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:15px}
body{font-family:var(--font);background:var(--off);color:var(--dark);min-height:100vh}



.topbar-brand span{color:var(--teal)}










/* LAYOUT */
.main{max-width:1100px;width:100%;margin:0 auto;padding:24px 20px}
.grid-2{display:grid;grid-template-columns:1.1fr .9fr;gap:20px;align-items:start}

/* CARD */
.card{background:var(--white);border-radius:var(--r-lg);border:1px solid rgba(27,45,90,.07);box-shadow:var(--shadow);overflow:hidden;margin-bottom:20px}
.card-header{padding:16px 20px;border-bottom:1px solid var(--light);display:flex;align-items:center;justify-content:space-between}
.card-title{font-size:14px;font-weight:700;color:var(--navy);display:flex;align-items:center;gap:8px}
.card-body{padding:20px}

/* FORM */
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.form-row.cols3{grid-template-columns:1fr 1fr 1fr}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.full{grid-column:1/-1}
label{font-size:11px;font-weight:700;color:var(--navy);letter-spacing:.05em;text-transform:uppercase}
label .req{color:var(--red)}
input,select,textarea{padding:9px 12px;border:1.5px solid rgba(27,45,90,.14);border-radius:var(--r);font-family:var(--font);font-size:14px;color:var(--dark);background:var(--off);outline:none;transition:all .2s;width:100%}
input:focus,select:focus,textarea:focus{border-color:var(--teal);background:var(--white);box-shadow:0 0 0 3px rgba(53,232,213,.1)}
textarea{resize:vertical;min-height:64px}

/* AUTOCOMPLETE */
.autocomplete-wrap{position:relative}
.autocomplete-list{position:absolute;top:100%;left:0;right:0;background:var(--white);border:1.5px solid rgba(53,232,213,.3);border-radius:var(--r);z-index:50;max-height:200px;overflow-y:auto;box-shadow:var(--shadow-lg);display:none}
.autocomplete-list.open{display:block}
.ac-item{padding:10px 14px;cursor:pointer;font-size:14px;border-bottom:1px solid var(--light);transition:background .15s}
.ac-item:hover{background:var(--teal-bg)}
.ac-item .ac-sub{font-size:11px;color:var(--gray);margin-top:2px}

/* ITEMS GRID */
.items-table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:12px}
.items-table thead tr{background:var(--navy-d)}
.items-table thead th{padding:9px 10px;text-align:left;font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.6);white-space:nowrap}
.items-table tbody tr{border-bottom:1px solid var(--light)}
.items-table tbody td{padding:6px 6px;vertical-align:middle}
.items-table tbody tr:last-child{border-bottom:none}
.item-input{padding:7px 9px;font-size:13px}
.item-subtotal{font-family:var(--mono);font-weight:600;color:var(--navy);text-align:right;white-space:nowrap;font-size:13px;min-width:90px}
.btn-remove{background:#FEE2E2;color:var(--red);border:none;border-radius:6px;padding:5px 9px;cursor:pointer;font-size:13px;transition:all .2s}
.btn-remove:hover{background:var(--red);color:white}

/* SUMMARY BOX */
.summary-box{background:linear-gradient(135deg,var(--navy-d),var(--navy));border-radius:var(--r-lg);padding:20px;color:var(--white);margin-top:4px}
.sum-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;font-size:14px}
.sum-row.total{border-top:1px solid rgba(255,255,255,.15);margin-top:8px;padding-top:12px}
.sum-label{color:rgba(255,255,255,.6)}
.sum-value{font-family:var(--mono);font-weight:700}
.sum-value.big{font-size:1.4rem;color:var(--teal)}
.sum-value.sisa{color:#FCA5A5}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:10px 18px;border-radius:var(--r);font-family:var(--font);font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;border:none}
.btn-primary{background:var(--teal);color:var(--navy-d);padding:13px 28px;font-size:15px;width:100%}
.btn-primary:hover{background:var(--teal-d);box-shadow:0 4px 16px rgba(53,232,213,.3)}
.btn-outline{background:transparent;color:var(--navy);border:1.5px solid rgba(27,45,90,.2)}
.btn-outline:hover{background:var(--light)}
.btn-teal-sm{background:var(--teal-bg);color:var(--teal-d);border:1px solid rgba(53,232,213,.3);font-size:13px;padding:7px 14px}
.btn-teal-sm:hover{background:var(--teal);color:var(--navy-d)}
.btn-green{background:#D1FAE5;color:#065F46}
.btn-green:hover{background:var(--green);color:white}
.btn-actions{display:flex;gap:10px;margin-top:16px}
.btn:disabled{opacity:.5;pointer-events:none}

/* LAYANAN GRID (quick pick) */
.layanan-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:12px;max-height:220px;overflow-y:auto}
.layanan-btn{padding:8px 6px;background:var(--off);border:1.5px solid rgba(27,45,90,.1);border-radius:8px;cursor:pointer;text-align:left;transition:all .2s;font-family:var(--font)}
.layanan-btn:hover{border-color:var(--teal);background:var(--teal-bg)}
.layanan-btn .l-nama{font-size:12px;font-weight:600;color:var(--navy);line-height:1.3}
.layanan-btn .l-harga{font-size:11px;color:var(--teal-d);font-family:var(--mono);margin-top:2px}
.layanan-btn .l-kat{font-size:10px;color:var(--gray);margin-bottom:2px}
.layanan-search{margin-bottom:8px}

/* TOAST */
.toast{position:fixed;bottom:20px;right:20px;z-index:999;padding:13px 18px;border-radius:var(--r);font-size:14px;font-weight:600;color:white;transform:translateY(60px);opacity:0;transition:all .3s cubic-bezier(.4,0,.2,1);pointer-events:none;max-width:320px}
.toast.show{transform:translateY(0);opacity:1}
.toast.success{background:var(--green)}
.toast.error{background:var(--red)}

/* VOUCHER */
.voucher-applied {
  background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
  border: 1.5px solid #6EE7B7;
  border-radius: var(--r);
  padding: 10px 14px;
  font-size: 13px;
  color: #065F46;
}
.voucher-applied strong { font-family: var(--mono); letter-spacing: .08em; }

/* PRINT MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,28,58,.6);backdrop-filter:blur(4px);z-index:200;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:var(--white);border-radius:var(--r-lg);padding:0;width:380px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-lg)}
.modal-header{padding:16px 20px;border-bottom:1px solid var(--light);display:flex;justify-content:space-between;align-items:center}
.modal-title{font-size:15px;font-weight:700;color:var(--navy)}
.modal-close{background:none;border:none;font-size:18px;cursor:pointer;color:var(--gray);padding:4px}
.modal-body{padding:20px}
.modal-footer{padding:16px 20px;border-top:1px solid var(--light);display:flex;gap:10px;justify-content:flex-end}

/* STRUK THERMAL */
.struk{font-family:'Courier New',monospace;font-size:12px;line-height:1.6;color:#000;width:72mm;margin:0 auto}
.struk-header{text-align:center;border-bottom:1px dashed #000;padding-bottom:8px;margin-bottom:8px}
.struk-header h2{font-size:14px;font-weight:bold;letter-spacing:.04em}
.struk-header p{font-size:10px}
.struk-row{display:flex;justify-content:space-between;font-size:11px}
.struk-row.bold{font-weight:bold;font-size:12px}
.struk-item{margin:4px 0;font-size:11px}
.struk-divider{border:none;border-top:1px dashed #000;margin:6px 0}
.struk-total{border-top:2px solid #000;margin-top:6px;padding-top:6px}
.struk-footer{text-align:center;margin-top:8px;font-size:10px;border-top:1px dashed #000;padding-top:8px}

@media print{
  body *{visibility:hidden}
  #strukPrint,#strukPrint *{visibility:visible}
  #strukPrint{
    position:fixed;left:0;top:0;
    width:80mm;padding:4mm;
    background:white;
  }
  @page{
    size:80mm auto;
    margin:0;
  }
}

@media print {
  body * { visibility: hidden }
  #strukPrint, #strukPrint * { visibility: visible }
  #strukPrint { position: fixed; left: 0; top: 0; width: 80mm }
}

@keyframes spin { from{transform:rotate(0deg)} to{transform:rotate(360deg)} }

/* ── RESPONSIVE — TABLET (≤ 800px) ── */
@media(max-width:800px){
  .main{padding:16px 14px}
  .grid-2{grid-template-columns:1fr}
  .layanan-grid{grid-template-columns:repeat(2,1fr)}
  .form-row{grid-template-columns:1fr}
  .form-row.cols3{grid-template-columns:1fr 1fr}
  .items-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .btn-actions{flex-wrap:wrap}
}

/* ── RESPONSIVE — MOBILE (≤ 680px) ── */
@media(max-width:680px){
  .main{padding:12px 10px 80px}
  .card-header{padding:12px 14px;flex-wrap:wrap;gap:8px}
  .card-title{font-size:13px}
  .card-body{padding:14px}
  .form-row{grid-template-columns:1fr;gap:8px;margin-bottom:8px}
  .form-row.cols3{grid-template-columns:1fr 1fr}
  .layanan-grid{grid-template-columns:repeat(2,1fr);gap:5px;max-height:180px}
  .layanan-btn{padding:7px 5px}
  .layanan-btn .l-nama{font-size:11px}
  .layanan-btn .l-harga{font-size:10px}
  .items-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100%}
  .items-table{table-layout:fixed !important;width:100% !important}
  .items-table th{min-width:0 !important}
  .items-table th:nth-child(1){width:auto !important}
  .items-table th:nth-child(2){width:56px !important}
  .items-table th:nth-child(3){width:58px !important}
  .items-table th:nth-child(4){width:82px !important}
  .items-table th:nth-child(5),.items-table td:nth-child(5){display:none !important}
  .items-table th:nth-child(6),.items-table td:nth-child(6){display:none !important}
  .items-table th:nth-child(7){width:34px !important}
  .items-table td input,.items-table td select{width:100% !important;min-width:0 !important}
  .summary-box{padding:14px}
  .sum-row{font-size:13px}
  .sum-value.big{font-size:1.2rem}
  .btn-actions{flex-direction:column;gap:8px}
  .btn-actions .btn{width:100%}
  .btn-primary{padding:12px 20px;font-size:14px}
  .modal{width:100%;max-width:100%;border-radius:var(--r-lg) var(--r-lg) 0 0}
  .modal-overlay{align-items:flex-end;padding:0}
}

/* ── RESPONSIVE — SMALL MOBILE (≤ 400px) ── */
@media(max-width:400px){
  .main{padding:8px 8px 80px}
  .card-body{padding:12px}
  .card-header{padding:10px 12px}
  .form-row.cols3{grid-template-columns:1fr}
  .layanan-grid{grid-template-columns:1fr 1fr;gap:4px}
  .summary-box{padding:12px}
}
</style>
</head>
<body>
<?php renderTopbar('pos'); ?>
<?php renderToast(); ?>

<div class="main">
  <div class="grid-2">

    <!-- KOLOM KIRI: Form + Items -->
    <div>

      <!-- INFO PELANGGAN -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">👤 Informasi Pelanggan</div>
          <span id="noOrderBadge" style="font-family:var(--mono);font-size:12px;color:var(--teal)"></span>
        </div>
        <div class="card-body">
          <div class="form-row">
            <div class="form-group">
              <label>Tanggal <span class="req">*</span></label>
              <input type="date" id="f_tanggal"/>
            </div>
            <div class="form-group">
              <label>Estimasi Selesai</label>
              <input type="date" id="f_estimasi"/>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group full">
              <label>Nama Pelanggan <span class="req">*</span></label>
              <div class="autocomplete-wrap">
                <input type="text" id="f_nama" placeholder="Ketik nama atau cari pelanggan..."
                  autocomplete="off" oninput="searchPelanggan(this.value)"/>
                <div class="autocomplete-list" id="acList"></div>
              </div>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>No. Telepon <span class="req">*</span></label>
              <input type="tel" id="f_telepon" placeholder="08xxxxxxxxxx" required/>
            </div>
            <div class="form-group full">
              <label>Catatan Order</label>
              <textarea id="f_catatan" placeholder="Warna, permintaan khusus, kondisi pakaian, dll..." style="min-height:80px"></textarea>
            </div>
          </div>
        </div>
      </div>

      <!-- ITEM LAYANAN -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">🧺 Layanan yang Digunakan</div>
          <button class="btn btn-teal-sm" onclick="addEmptyRow()">+ Tambah Baris</button>
        </div>
        <div class="card-body" style="padding-bottom:12px">

          <!-- Quick pick layanan -->
          <div style="margin-bottom:12px">
            <input type="text" class="layanan-search" id="layananSearch"
              placeholder="🔍 Cari layanan..." oninput="filterLayanan(this.value)"
              style="margin-bottom:8px"/>
            <div class="layanan-grid" id="layananGrid">
              <div style="color:var(--gray);font-size:13px;padding:8px">Memuat layanan...</div>
            </div>
          </div>

          <!-- Table items -->
          <div class="items-table-wrap" style="overflow-x:auto">
            <table class="items-table">
              <thead>
                <tr>
                  <th style="min-width:130px">Layanan</th>
                  <th style="width:60px">Satuan</th>
                  <th style="width:70px">Jumlah</th>
                  <th style="width:100px">Harga/Sat</th>
                  <th style="width:90px">Subtotal</th>
                  <th style="width:80px">Catatan</th>
                  <th style="width:36px"></th>
                </tr>
              </thead>
              <tbody id="itemsBody">
                <!-- rows injected by JS -->
              </tbody>
            </table>
          </div>
          <div id="emptyItems" style="text-align:center;padding:20px;color:var(--gray);font-size:14px">
            Pilih layanan di atas atau klik "+ Tambah Baris"
          </div>
        </div>
      </div>

    </div>

    <!-- KOLOM KANAN: Summary + Bayar -->
    <div style="position:sticky;top:72px">

      <!-- SUMMARY -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">💰 Ringkasan Pembayaran</div>
        </div>
        <div class="card-body">
          <div class="summary-box">
            <div class="sum-row">
              <span class="sum-label">Subtotal</span>
              <span class="sum-value" id="sumSubtotal">Rp 0</span>
            </div>
            <div class="sum-row">
              <span class="sum-label">Diskon</span>
              <span class="sum-value" style="color:#FCA5A5">- Rp <span id="sumDiskon">0</span></span>
            </div>
            <div class="sum-row total">
              <span style="font-weight:700;color:white">TOTAL</span>
              <span class="sum-value big" id="sumTotal">Rp 0</span>
            </div>
            <div class="sum-row" style="margin-top:8px">
              <span class="sum-label">DP / Bayar</span>
              <span class="sum-value" id="sumDP">Rp 0</span>
            </div>
            <div class="sum-row">
              <span class="sum-label">Sisa Bayar</span>
              <span class="sum-value sisa" id="sumSisa">Rp 0</span>
            </div>
          </div>

          <div style="margin-top:16px;display:flex;flex-direction:column;gap:10px">

            <!-- VOUCHER / KODE PROMO -->
            <div style="display:flex;gap:8px;align-items:flex-end">
              <div class="form-group" style="flex:1;margin-bottom:0">
                <label>🎟️ Kode Voucher / Promo</label>
                <input type="text" id="f_voucher" placeholder="Masukkan kode..." 
                  style="text-transform:uppercase;letter-spacing:.08em;font-family:var(--mono)"
                  oninput="this.value=this.value.toUpperCase()"/>
              </div>
              <button type="button" class="btn btn-teal-sm" onclick="applyVoucher()" style="margin-bottom:1px;white-space:nowrap">
                ✓ Pakai
              </button>
            </div>
            <div id="voucherInfo" style="display:none;background:#D1FAE5;border-radius:8px;padding:10px 14px;font-size:13px;display:none">
              <div id="voucherInfoText"></div>
              <button type="button" onclick="removeVoucher()" style="background:none;border:none;color:var(--red);font-size:12px;cursor:pointer;margin-top:4px;padding:0">✕ Hapus kode</button>
            </div>

            <div class="form-row cols3">
              <div class="form-group">
                <label>Diskon (Rp)</label>
                <input type="number" id="f_diskon" value="0" min="0" oninput="recalc()"/>
              </div>
              <div class="form-group">
                <label>DP / Bayar</label>
                <input type="number" id="f_dp" value="0" min="0" oninput="recalc()"/>
              </div>
              <div class="form-group">
                <label>Metode</label>
                <select id="f_metode">
                  <option value="cash">💵 Cash</option>
                  <option value="transfer">🏦 Transfer</option>
                  <option value="qris">📱 QRIS</option>
                </select>
              </div>
            </div>

            <!-- STATUS BAYAR indicator -->
            <div id="statusBayarInfo" style="text-align:center;font-size:13px;font-weight:600;padding:8px;border-radius:8px;background:var(--light);color:var(--gray)">
              Belum ada item
            </div>

            <button class="btn btn-primary" id="btnSave" onclick="saveTransaksi()" disabled>
              💾 Simpan & Print Struk
            </button>
            <button class="btn btn-outline" onclick="resetForm()">
              ↺ Reset Form
            </button>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- AI UPSELLING WIDGET — dipindah ke floating bubble -->

</div>

<!-- FLOATING AI CHATBOT -->
<div id="aiFloating" style="display:none">

  <!-- Bubble toggle button -->
  <button id="aiBubbleBtn" onclick="toggleAIChat()"
    style="position:fixed;bottom:24px;right:24px;z-index:1000;
           width:52px;height:52px;border-radius:50%;border:none;cursor:pointer;
           background:linear-gradient(135deg,#667eea,#764ba2);
           color:white;font-size:20px;box-shadow:0 4px 20px rgba(102,126,234,.5);
           transition:all .3s;display:flex;align-items:center;justify-content:center">
    ✨
  </button>

  <!-- Notif dot — muncul saat ada rekomendasi baru -->
  <div id="aiNotifDot" style="display:none;position:fixed;bottom:66px;right:24px;z-index:1001;
    width:12px;height:12px;background:var(--red);border-radius:50%;border:2px solid white"></div>

  <!-- Chat panel -->
  <div id="aiChatPanel"
    style="display:none;position:fixed;bottom:88px;right:24px;z-index:999;
           width:340px;max-height:520px;
           background:white;border-radius:16px;
           box-shadow:0 8px 40px rgba(27,45,90,.2);
           border:1px solid rgba(139,92,246,.2);
           display:none;flex-direction:column;overflow:hidden">

    <!-- Header -->
    <div style="background:linear-gradient(135deg,#667eea,#764ba2);padding:14px 16px;
                display:flex;align-items:center;justify-content:space-between">
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:32px;height:32px;background:rgba(255,255,255,.2);border-radius:50%;
                    display:flex;align-items:center;justify-content:center;font-size:16px">✨</div>
        <div>
          <div style="color:white;font-weight:700;font-size:14px">AI Assistant</div>
          <div style="color:rgba(255,255,255,.7);font-size:11px" id="aiStatusText">Pilih customer dulu</div>
        </div>
      </div>
      <div style="display:flex;gap:6px">
        <button onclick="loadAIRekomendasi()" id="btnRefreshAI"
          style="background:rgba(255,255,255,.2);border:none;color:white;border-radius:8px;
                 padding:5px 10px;cursor:pointer;font-size:12px;font-weight:600">↻</button>
        <button onclick="toggleAIChat()"
          style="background:rgba(255,255,255,.2);border:none;color:white;border-radius:8px;
                 padding:5px 10px;cursor:pointer;font-size:14px">✕</button>
      </div>
    </div>

    <!-- Content area -->
    <div id="aiContent" style="flex:1;overflow-y:auto;padding:14px;font-size:13px;
                                max-height:420px;background:var(--off)">
      <div style="text-align:center;padding:32px 16px;color:var(--gray)">
        <div style="font-size:2rem;margin-bottom:8px;opacity:.4">✨</div>
        <div style="font-size:13px;font-weight:600;margin-bottom:4px">AI Upselling Assistant</div>
        <div style="font-size:12px">Pilih customer di form untuk mendapatkan rekomendasi layanan</div>
      </div>
    </div>

  </div>
</div>

<!-- MODAL STRUK -->
<div class="modal-overlay" id="modalStruk">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">🧾 Struk Pembayaran</span>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
      <div id="strukPrint"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal()">Tutup</button>
      <button class="btn btn-green" onclick="printStruk()">🖨️ Print Struk</button>
      <button class="btn btn-teal-sm" onclick="window.location.href='orders.php'">📋 Lihat Orders</button>
    </div>
  </div>
</div>

<?php renderToast(); ?>

<script>
// ── STATE ──────────────────────────────────────────────
let items = [];
let layananAll = [];
let lastSaved = null;
let acTimeout = null;

// ── INIT ──────────────────────────────────────────────

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
  // Estimasi 2 hari ke depan default
  const est = new Date(); est.setDate(est.getDate()+2);
  document.getElementById('f_estimasi').value = localDateStr(est);
  loadLayanan();
});

// ── LOAD LAYANAN ───────────────────────────────────────
async function loadLayanan() {
  const res = await fetch('pos.php?action=get_layanan');
  layananAll = await res.json();
  renderLayananGrid(layananAll);
}

function renderLayananGrid(list) {
  const grid = document.getElementById('layananGrid');
  if (!list.length) {
    grid.innerHTML = '<div style="color:var(--gray);font-size:13px;padding:8px;grid-column:1/-1">Tidak ada layanan</div>';
    return;
  }
  grid.innerHTML = list.map(l => `
    <button class="layanan-btn" onclick="addLayananItem(${l.id},'${esc(l.nama)}','${l.satuan}',${l.harga})">
      <div class="l-kat">${esc(l.kategori||'')}</div>
      <div class="l-nama">${esc(l.nama)}</div>
      <div class="l-harga">Rp ${parseFloat(l.harga).toLocaleString('id-ID')}/${l.satuan}</div>
    </button>`).join('');
}

function filterLayanan(q) {
  const filtered = q
    ? layananAll.filter(l => l.nama.toLowerCase().includes(q.toLowerCase()) || (l.kategori||'').toLowerCase().includes(q.toLowerCase()))
    : layananAll;
  renderLayananGrid(filtered);
}

// ── ADD ITEM ──────────────────────────────────────────
function addLayananItem(id, nama, satuan, harga) {
  // Cari item yang sama tanpa catatan
  const existIdx = items.findIndex(i => i.layanan_id == id && !i.catatan_item);

  if (existIdx >= 0) {
    // Sudah ada & tidak ada catatan → tambah quantity
    items[existIdx].jumlah += 1;
    renderItems();
    recalc();
    showToast('Quantity ' + nama + ' +1', 'success');
    return;
  }

  // Cek apakah ada item yang sama DENGAN catatan
  const existWithNote = items.findIndex(i => i.layanan_id == id && i.catatan_item);
  if (existWithNote >= 0) {
    // Ada tapi punya catatan — tanya user
    if (confirm(nama + ' sudah ada di daftar.\n\nOK = Tambah baris baru\nBatal = Tidak jadi')) {
      items.push({ layanan_id:id, nama_layanan:nama, satuan, jumlah:1, harga_satuan:harga, catatan_item:'' });
      renderItems();
      recalc();
    }
    return;
  }

  // Belum ada — tambah baru
  items.push({ layanan_id:id, nama_layanan:nama, satuan, jumlah:1, harga_satuan:harga, catatan_item:'' });
  renderItems();
  recalc();
}

function addEmptyRow() {
  items.push({ layanan_id:null, nama_layanan:'', satuan:'kg', jumlah:1, harga_satuan:0, catatan_item:'' });
  renderItems();
}

function removeItem(idx) {
  items.splice(idx, 1);
  renderItems();
  recalc();
}

// ── RENDER ITEMS TABLE ────────────────────────────────
function renderItems() {
  const tbody = document.getElementById('itemsBody');
  const empty = document.getElementById('emptyItems');

  if (!items.length) {
    tbody.innerHTML = '';
    empty.style.display = 'block';
    document.getElementById('btnSave').disabled = true;
    return;
  }
  empty.style.display = 'none';
  document.getElementById('btnSave').disabled = false;

  tbody.innerHTML = items.map((item, i) => `
    <tr>
      <td>
        <input class="item-input" style="width:100%;min-width:120px" value="${esc(item.nama_layanan)}"
          placeholder="Nama layanan"
          oninput="items[${i}].nama_layanan=this.value;recalc()"/>
      </td>
      <td>
        <select class="item-input" style="width:64px" onchange="items[${i}].satuan=this.value">
          ${['kg','pcs','set','pasang'].map(s=>`<option value="${s}" ${item.satuan===s?'selected':''}>${s}</option>`).join('')}
        </select>
      </td>
      <td>
        <input class="item-input" type="number" value="${item.jumlah}" min="0.1" step="0.1" style="width:64px"
          oninput="items[${i}].jumlah=parseFloat(this.value)||0;recalc()"/>
      </td>
      <td>
        <input class="item-input" type="number" value="${item.harga_satuan}" min="0" step="500" style="width:96px"
          oninput="items[${i}].harga_satuan=parseFloat(this.value)||0;recalc()"/>
      </td>
      <td class="item-subtotal">Rp ${(item.jumlah*item.harga_satuan).toLocaleString('id-ID')}</td>
      <td>
        <input class="item-input" value="${esc(item.catatan_item)}" placeholder="..."
          style="width:72px" oninput="items[${i}].catatan_item=this.value"/>
      </td>
      <td>
        <button class="btn-remove" onclick="removeItem(${i})">✕</button>
      </td>
    </tr>`).join('');
}

// ── RECALC ────────────────────────────────────────────
function recalc() {
  const subtotal = items.reduce((s,i) => s + i.jumlah*i.harga_satuan, 0);
  const diskon   = parseFloat(document.getElementById('f_diskon').value)||0;
  const total    = Math.max(subtotal - diskon, 0);
  const dp       = parseFloat(document.getElementById('f_dp').value)||0;
  const sisa     = total - dp;

  document.getElementById('sumSubtotal').textContent = 'Rp ' + subtotal.toLocaleString('id-ID');
  document.getElementById('sumDiskon').textContent   = diskon.toLocaleString('id-ID');
  document.getElementById('sumTotal').textContent    = 'Rp ' + total.toLocaleString('id-ID');
  document.getElementById('sumDP').textContent       = 'Rp ' + dp.toLocaleString('id-ID');
  document.getElementById('sumSisa').textContent     = 'Rp ' + sisa.toLocaleString('id-ID');

  // Update subtotals di tabel
  const cells = document.querySelectorAll('.item-subtotal');
  items.forEach((item, i) => {
    if (cells[i]) cells[i].textContent = 'Rp ' + (item.jumlah*item.harga_satuan).toLocaleString('id-ID');
  });

  // Status bayar indicator
  const info = document.getElementById('statusBayarInfo');
  if (!items.length) {
    info.textContent = 'Belum ada item'; info.style.background='var(--light)'; info.style.color='var(--gray)';
  } else if (dp >= total) {
    info.textContent = '✅ LUNAS'; info.style.background='#D1FAE5'; info.style.color='#065F46';
  } else if (dp > 0) {
    info.textContent = '⚡ DP — Sisa Rp ' + sisa.toLocaleString('id-ID'); info.style.background='#FEF3C7'; info.style.color='#92400E';
  } else {
    info.textContent = '⏳ Belum Bayar'; info.style.background='#FEE2E2'; info.style.color='#991B1B';
  }
}

// ── AUTOCOMPLETE PELANGGAN ────────────────────────────
function searchPelanggan(q) {
  clearTimeout(acTimeout);
  const list = document.getElementById('acList');
  if (q.length < 2) { list.classList.remove('open'); return; }
  acTimeout = setTimeout(async () => {
    const res = await fetch('pos.php?action=search_pelanggan&q=' + encodeURIComponent(q));
    const data = await res.json();
    if (!data.length) { list.classList.remove('open'); return; }
    list.innerHTML = data.map(p => `
      <div class="ac-item" onclick="selectPelanggan(${p.id},'${esc(p.nama)}','${esc(p.telepon||'')}')">
        <div>${esc(p.nama)}</div>
        <div class="ac-sub">${p.telepon||'No telepon'} · ${p.tipe} · ${p.total_order} order</div>
      </div>`).join('');
    list.classList.add('open');
  }, 300);
}

let currentPelangganId = null;
let aiChatOpen = false;

function selectPelanggan(id, nama, telp) {
  currentPelangganId = id;
  document.getElementById('f_nama').value     = nama;
  document.getElementById('f_telepon').value  = telp;
  document.getElementById('acList').classList.remove('open');

  // Tampilkan floating button — user klik sendiri untuk buka
  document.getElementById('aiFloating').style.display = 'block';
  document.getElementById('aiStatusText').textContent = nama;
  document.getElementById('aiNotifDot').style.display = 'block';
}

function toggleAIChat() {
  aiChatOpen = !aiChatOpen;
  const panel = document.getElementById('aiChatPanel');
  const btn   = document.getElementById('aiBubbleBtn');
  panel.style.display = aiChatOpen ? 'flex' : 'none';
  btn.style.transform = aiChatOpen ? 'scale(0.9)' : 'scale(1)';
  btn.textContent     = aiChatOpen ? '✕' : '✨';
  if (aiChatOpen) document.getElementById('aiNotifDot').style.display = 'none';
}

// ── AI UPSELLING ──────────────────────────────────────
async function loadAIRekomendasi() {
  if (!currentPelangganId) return;

  const btn = document.getElementById('btnRefreshAI');
  btn.disabled = true; btn.textContent = '⏳';
  document.getElementById('aiStatusText').textContent = 'Sedang menganalisis...';

  document.getElementById('aiContent').innerHTML = `
    <div style="text-align:center;padding:24px;color:var(--gray)">
      <div style="font-size:1.5rem;margin-bottom:8px;animation:spin 1s linear infinite;display:inline-block">⚙️</div>
      <div style="font-size:13px">Menganalisis histori customer...</div>
    </div>`;

  try {
    const r = await fetch('ai.php?action=upselling', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
      body: JSON.stringify({
        pelanggan_id:  currentPelangganId,
        current_items: items,
      })
    });
    const d = await r.json();

    if (d.error) {
      document.getElementById('aiContent').innerHTML =
        `<div style="color:var(--red);font-size:13px;padding:12px">❌ ${d.error}</div>`;
      return;
    }

    const data   = d.data;
    const segmen = { new:'Baru', regular:'Regular', vip:'VIP' }[data.segmen] || data.segmen;
    const segmenColor = { new:'var(--blue)', regular:'var(--teal-d)', vip:'#F59E0B' }[data.segmen] || 'var(--gray)';

    document.getElementById('aiStatusText').textContent = segmen + ' · ' + (data.rekomendasi?.length||0) + ' rekomendasi';
    document.getElementById('aiNotifDot').style.display = aiChatOpen ? 'none' : 'block';

    document.getElementById('aiContent').innerHTML = `
      <div style="margin-bottom:12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span style="background:${segmenColor};color:white;font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px">${segmen}</span>
        <span style="font-size:12px;color:var(--gray);font-style:italic">"${esc(data.insight)}"</span>
      </div>
      ${(data.rekomendasi||[]).map((r,i) => `
      <div style="background:${i===0?'#F5F3FF':'white'};border-radius:10px;padding:12px;margin-bottom:8px;border:1.5px solid ${i===0?'rgba(139,92,246,.25)':'rgba(27,45,90,.08)'}">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px">
          <div style="font-size:13px;font-weight:700;color:var(--navy)">${i===0?'⭐ ':''}${esc(r.layanan)}</div>
          <span style="font-size:10px;font-weight:700;background:var(--teal-bg);color:var(--teal-d);padding:2px 6px;border-radius:100px;white-space:nowrap;flex-shrink:0;margin-left:6px">+${esc(r.potensi_revenue)}</span>
        </div>
        <div style="font-size:11px;color:var(--gray);margin-bottom:7px">${esc(r.alasan)}</div>
        <div style="background:var(--off);border-radius:7px;padding:7px 10px;font-size:11px;color:var(--navy);border-left:3px solid var(--teal);font-style:italic line-height:1.5">
          "${esc(r.script)}"
        </div>
      </div>`).join('')}
      <div style="font-size:10px;color:var(--gray);text-align:right;margin-top:4px">
        AI · ${new Date().toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'})}
      </div>`;

  } catch(e) {
    document.getElementById('aiContent').innerHTML =
      `<div style="color:var(--red);font-size:13px;padding:12px">❌ Error: ${e.message}</div>`;
    document.getElementById('aiStatusText').textContent = 'Error';
  } finally {
    btn.disabled = false; btn.textContent = '↻';
  }
}

document.addEventListener('click', e => {
  if (!e.target.closest('.autocomplete-wrap'))
    document.getElementById('acList').classList.remove('open');
});

// ── SAVE ─────────────────────────────────────────────
async function saveTransaksi() {
  const nama = document.getElementById('f_nama').value.trim();
  const telp = document.getElementById('f_telepon').value.trim();
  if (!nama) { showToast('⚠️ Nama pelanggan wajib diisi', 'error'); return; }
  if (!telp) { showToast('⚠️ Nomor HP wajib diisi', 'error'); return; }
  if (!items.length) { showToast('⚠️ Minimal 1 item layanan', 'error'); return; }

  const btn = document.getElementById('btnSave');
  btn.disabled = true; btn.textContent = '⏳ Menyimpan...';

  const payload = {
    tanggal:       document.getElementById('f_tanggal').value,
    estimasi:      document.getElementById('f_estimasi').value,
    nama_pelanggan:nama,
    telepon:       document.getElementById('f_telepon').value,
    catatan:       document.getElementById('f_catatan').value,
    diskon:        document.getElementById('f_diskon').value,
    dp:            document.getElementById('f_dp').value,
    metode_bayar:  document.getElementById('f_metode').value,
    items
  };

  try {
    const res  = await fetch('pos.php?action=save', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.success) {
      // Apply voucher jika ada
      if (appliedVoucher) {
        await fetch('promo.php?action=apply_voucher', {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({
            voucher_id: appliedVoucher.voucher_id || null,
            promo_id:   appliedVoucher.promo_id   || null,
            no_order:   data.no_order
          })
        });
      }
      showToast('✅ Order ' + data.no_order + ' tersimpan!', 'success');
      lastSaved = data;
      await showStruk(data.id);
      resetForm();
    } else {
      showToast('❌ ' + (data.error||'Gagal menyimpan'), 'error');
    }
  } catch(e) {
    showToast('❌ Error: ' + e.message, 'error');
  }
  btn.disabled = false; btn.textContent = '💾 Simpan & Print Struk';
}

// ── STRUK ─────────────────────────────────────────────
async function showStruk(id) {
  const res  = await fetch('pos.php?action=get_detail&id=' + id);
  const data = await res.json();
  if (data.error) return;

  const isFull    = parseFloat(data.dp) >= parseFloat(data.total);
  const metodeTxt = {'cash':'Cash','transfer':'Transfer Bank','qris':'QRIS'}[data.metode_bayar]||data.metode_bayar;
  const trackUrl  = 'https://harpy.id/ERP/track.php?order=' + encodeURIComponent(data.no_order);

  const itemRows = (data.items||[]).map(item => `
    <div class="struk-item">
      ${item.nama_layanan}${item.catatan_item?' ('+item.catatan_item+')':''}
      <br>&nbsp;&nbsp;${parseFloat(item.jumlah).toLocaleString('id-ID')} ${item.satuan} x Rp ${parseFloat(item.harga_satuan).toLocaleString('id-ID')}
    </div>
    <div class="struk-row">
      <span></span>
      <span>Rp ${parseFloat(item.subtotal).toLocaleString('id-ID')}</span>
    </div>`).join('');

  document.getElementById('strukPrint').innerHTML = `
    <div class="struk">
      <div class="struk-header">
        <h2>HARPY LAUNDRY</h2>
        <p>Jl. Rawa Selatan IV No.1, Johar Baru</p>
        <p>Jakarta Pusat | +62 896-1525-9302</p>
        <p>harpy.id</p>
      </div>
      <div class="struk-row"><span>No. Order</span><span>${data.no_order}</span></div>
      <div class="struk-row"><span>Tanggal</span><span>${formatDate(data.tanggal)}</span></div>
      <div class="struk-row"><span>Pelanggan</span><span>${data.nama_pelanggan}</span></div>
      ${data.telepon?`<div class="struk-row"><span>Telp</span><span>${data.telepon}</span></div>`:''}
      ${data.estimasi_selesai?`<div class="struk-row"><span>Est. Selesai</span><span>${formatDate(data.estimasi_selesai)}</span></div>`:''}
      <hr class="struk-divider"/>
      ${itemRows}
      <hr class="struk-divider"/>
      <div class="struk-row"><span>Subtotal</span><span>Rp ${parseFloat(data.subtotal).toLocaleString('id-ID')}</span></div>
      ${parseFloat(data.diskon)>0?`<div class="struk-row"><span>Diskon${appliedVoucher?' ('+esc(appliedVoucher.kode)+')':''}</span><span>- Rp ${parseFloat(data.diskon).toLocaleString('id-ID')}</span></div>`:''}
      <div class="struk-total">
        <div class="struk-row bold"><span>TOTAL</span><span>Rp ${parseFloat(data.total).toLocaleString('id-ID')}</span></div>
        <div class="struk-row"><span>Bayar (${metodeTxt})</span><span>Rp ${parseFloat(data.dp).toLocaleString('id-ID')}</span></div>
        ${!isFull?`<div class="struk-row bold"><span>SISA BAYAR</span><span>Rp ${parseFloat(data.sisa_bayar).toLocaleString('id-ID')}</span></div>`:''}
      </div>
      ${data.catatan?`<hr class="struk-divider"/><div style="font-size:11px">Catatan: ${data.catatan}</div>`:''}
      <div class="struk-footer">
        <p>${isFull?'** LUNAS **':'** BELUM LUNAS **'}</p>
        <div style="margin:8px auto;width:80px;height:80px" id="qrcode"></div>
        <p style="font-size:9px">Scan untuk cek status</p>
        <p>Terima kasih telah mempercayakan</p>
        <p>cucian Anda kepada Harpy Laundry!</p>
      </div>
    </div>`;

  // Generate QR code
  const qrEl = document.getElementById('qrcode');
  if (qrEl) {
    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' + encodeURIComponent(trackUrl);
    qrEl.innerHTML = `<img src="${qrUrl}" width="80" height="80" style="display:block"/>`;
  }

  document.getElementById('modalStruk').classList.add('open');
}

function printStruk() { window.print(); }
function closeModal()  { document.getElementById('modalStruk').classList.remove('open'); }

// ── RESET ─────────────────────────────────────────────
// ── VOUCHER STATE ────────────────────────────────────
let appliedVoucher = null; // { tipe, voucher_id, promo_id, kode, nama, diskon }

async function applyVoucher() {
  const kode = document.getElementById('f_voucher').value.trim().toUpperCase();
  if (!kode) { showToast('⚠️ Masukkan kode voucher/promo', 'error'); return; }

  const subtotal = items.reduce((s,i) => s + i.jumlah*i.harga_satuan, 0);
  if (subtotal <= 0) { showToast('⚠️ Tambahkan item terlebih dahulu', 'error'); return; }

  try {
    const r = await fetch('promo.php?action=validate&kode=' + encodeURIComponent(kode) + '&total=' + subtotal);
    const d = await r.json();

    if (d.valid) {
      appliedVoucher = d;
      document.getElementById('f_diskon').value = Math.round(d.diskon);
      recalc();
      // Tampilkan info voucher
      const infoEl = document.getElementById('voucherInfo');
      infoEl.style.display = 'block';
      infoEl.className = 'voucher-applied';
      document.getElementById('voucherInfoText').innerHTML =
        '✅ <strong>' + esc(d.kode) + '</strong> — ' + esc(d.nama) +
        ' <span style="background:#065F46;color:white;padding:2px 8px;border-radius:100px;font-size:11px;font-weight:700">' + esc(d.info) + '</span>' +
        '<br><span style="font-size:12px;opacity:.8">Diskon: Rp ' + Math.round(d.diskon).toLocaleString('id-ID') + '</span>';
      document.getElementById('f_voucher').disabled = true;
      showToast('✅ Voucher berhasil dipakai! Diskon Rp ' + Math.round(d.diskon).toLocaleString('id-ID'), 'success');
    } else {
      showToast('❌ ' + (d.error||'Kode tidak valid'), 'error');
    }
  } catch(e) {
    showToast('❌ Error: ' + e.message, 'error');
  }
}

function removeVoucher() {
  appliedVoucher = null;
  document.getElementById('f_voucher').value    = '';
  document.getElementById('f_voucher').disabled = false;
  document.getElementById('f_diskon').value     = '0';
  document.getElementById('voucherInfo').style.display = 'none';
  recalc();
  showToast('🎟️ Kode voucher dihapus', 'success');
}

function resetForm() {
  items = [];
  appliedVoucher = null;
  renderItems();
  recalc();
  document.getElementById('f_nama').value    = '';
  document.getElementById('f_telepon').value = '';
  document.getElementById('f_catatan').value = '';
  document.getElementById('f_diskon').value  = '0';
  document.getElementById('f_dp').value      = '0';
  document.getElementById('f_metode').value  = 'cash';
  document.getElementById('f_voucher').value    = '';
  document.getElementById('f_voucher').disabled = false;
  document.getElementById('voucherInfo').style.display = 'none';
  const today = localDateStr();
  document.getElementById('f_tanggal').value = today;
  const est = new Date(); est.setDate(est.getDate()+2);
  document.getElementById('f_estimasi').value = localDateStr(est);
}

// ── HELPERS ───────────────────────────────────────────
function formatDate(d) {
  if (!d) return '-';
  return new Date(d).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'});
}
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
function showToast(msg, type='success') {
  const t = document.getElementById('toast');
  t.textContent = msg; t.className = 'toast '+type+' show';
  setTimeout(() => t.className='toast', 3500);
}
</script>
</body>
</html>