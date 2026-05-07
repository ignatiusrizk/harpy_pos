<?php
$activePage = 'promo';
require_once 'auth.php';
require_once 'components.php';
requireLogin();
$user = currentUser();
if (isset($_GET['logout'])) doLogout();
requirePermission('promo.view');

// ── API ───────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $pdo = getDB();

    // AUTO CREATE TABLES
    $pdo->exec("CREATE TABLE IF NOT EXISTS hl_promo (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        nama         VARCHAR(100) NOT NULL,
        deskripsi    TEXT,
        tipe         ENUM('persen','nominal','free_item') DEFAULT 'persen',
        nilai        DECIMAL(12,2) DEFAULT 0,
        min_transaksi DECIMAL(12,2) DEFAULT 0,
        maks_diskon  DECIMAL(12,2) DEFAULT 0,
        berlaku_dari DATE,
        berlaku_sampai DATE,
        kuota        INT DEFAULT 0,
        terpakai     INT DEFAULT 0,
        is_active    TINYINT(1) DEFAULT 1,
        created_by   INT,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hl_voucher (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        promo_id     INT,
        kode         VARCHAR(20) NOT NULL UNIQUE,
        nama_penerima VARCHAR(100),
        telepon      VARCHAR(20),
        is_used      TINYINT(1) DEFAULT 0,
        used_at      TIMESTAMP NULL,
        used_by_order VARCHAR(30),
        expired_at   DATE,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (promo_id) REFERENCES hl_promo(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // LIST PROMO
    if ($action === 'list_promo') {
        $rows = $pdo->query("SELECT p.*,
            (SELECT COUNT(*) FROM hl_voucher WHERE promo_id=p.id) as total_voucher,
            (SELECT COUNT(*) FROM hl_voucher WHERE promo_id=p.id AND is_used=1) as used_voucher
            FROM hl_promo p ORDER BY created_at DESC")->fetchAll();
        echo json_encode($rows); exit;
    }

    // SAVE PROMO
    if ($action === 'save_promo' && $_SERVER['REQUEST_METHOD']==='POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        if (isset($d['id']) && $d['id']) {
            $pdo->prepare("UPDATE hl_promo SET nama=?,deskripsi=?,tipe=?,nilai=?,min_transaksi=?,maks_diskon=?,berlaku_dari=?,berlaku_sampai=?,kuota=?,is_active=? WHERE id=?")
                ->execute([$d['nama'],$d['deskripsi'],$d['tipe'],$d['nilai'],$d['min_transaksi'],$d['maks_diskon'],$d['berlaku_dari']?:null,$d['berlaku_sampai']?:null,$d['kuota'],$d['is_active'],$d['id']]);
        } else {
            $pdo->prepare("INSERT INTO hl_promo (nama,deskripsi,tipe,nilai,min_transaksi,maks_diskon,berlaku_dari,berlaku_sampai,kuota,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$d['nama'],$d['deskripsi'],$d['tipe'],$d['nilai'],$d['min_transaksi'],$d['maks_diskon'],$d['berlaku_dari']?:null,$d['berlaku_sampai']?:null,$d['kuota'],$user['id']]);
        }
        echo json_encode(['success'=>true]); exit;
    }

    // DELETE PROMO
    if ($action === 'delete_promo' && $_SERVER['REQUEST_METHOD']==='POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $pdo->prepare("UPDATE hl_promo SET is_active=0 WHERE id=?")->execute([$d['id']]);
        echo json_encode(['success'=>true]); exit;
    }

    // GENERATE VOUCHER
    if ($action === 'generate_voucher' && $_SERVER['REQUEST_METHOD']==='POST') {
        $d       = json_decode(file_get_contents('php://input'), true);
        $promo_id= intval($d['promo_id']);
        $jumlah  = min(intval($d['jumlah'] ?? 1), 100);
        $prefix  = strtoupper($d['prefix'] ?? 'HRP');
        $expired = $d['expired_at'] ?? null;
        $nama    = $d['nama_penerima'] ?? null;
        $telp    = $d['telepon'] ?? null;

        $generated = [];
        $stmt = $pdo->prepare("INSERT INTO hl_voucher (promo_id,kode,nama_penerima,telepon,expired_at) VALUES (?,?,?,?,?)");
        for ($i = 0; $i < $jumlah; $i++) {
            $kode = $prefix . '-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
            // Pastikan unik
            $exists = $pdo->prepare("SELECT id FROM hl_voucher WHERE kode=?");
            $exists->execute([$kode]);
            if ($exists->fetch()) {
                $kode = $prefix . '-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
            }
            $stmt->execute([$promo_id, $kode, $nama, $telp, $expired]);
            $generated[] = $kode;
        }
        echo json_encode(['success'=>true, 'vouchers'=>$generated]); exit;
    }

    // LIST VOUCHER per promo
    if ($action === 'list_voucher') {
        $promo_id = intval($_GET['promo_id']);
        $rows = $pdo->prepare("SELECT * FROM hl_voucher WHERE promo_id=? ORDER BY created_at DESC LIMIT 200");
        $rows->execute([$promo_id]);
        echo json_encode($rows->fetchAll()); exit;
    }

    // VALIDATE VOUCHER / KODE PROMO (dipanggil dari POS)
    if ($action === 'validate') {
        $kode  = strtoupper(trim($_GET['kode'] ?? ''));
        $total = floatval($_GET['total'] ?? 0);

        // Cek apakah kode adalah voucher
        $vou = $pdo->prepare("SELECT v.*, p.nama as promo_nama, p.tipe, p.nilai, p.min_transaksi, p.maks_diskon, p.berlaku_sampai as promo_expired
            FROM hl_voucher v LEFT JOIN hl_promo p ON v.promo_id=p.id
            WHERE v.kode=?");
        $vou->execute([$kode]);
        $v = $vou->fetch();

        if ($v) {
            if ($v['is_used']) { echo json_encode(['error'=>'Voucher sudah digunakan']); exit; }
            if ($v['expired_at'] && $v['expired_at'] < date('Y-m-d')) { echo json_encode(['error'=>'Voucher sudah expired']); exit; }
            if ($v['promo_expired'] && $v['promo_expired'] < date('Y-m-d')) { echo json_encode(['error'=>'Promo sudah berakhir']); exit; }
            if ($v['min_transaksi'] > 0 && $total < $v['min_transaksi']) {
                echo json_encode(['error'=>'Minimum transaksi Rp ' . number_format($v['min_transaksi'],0,',','.')]); exit;
            }
            $diskon = $v['tipe'] === 'persen'
                ? min($total * $v['nilai'] / 100, $v['maks_diskon'] > 0 ? $v['maks_diskon'] : PHP_INT_MAX)
                : floatval($v['nilai']);
            echo json_encode([
                'valid'      => true,
                'tipe'       => 'voucher',
                'voucher_id' => $v['id'],
                'kode'       => $v['kode'],
                'nama'       => $v['promo_nama'] ?: 'Voucher',
                'diskon'     => $diskon,
                'info'       => ($v['tipe']==='persen' ? $v['nilai'].'%' : 'Rp '.number_format($v['nilai'],0,',','.')) . ' off'
            ]); exit;
        }

        // Cek apakah kode adalah nama promo langsung (tanpa voucher)
        $promo = $pdo->prepare("SELECT * FROM hl_promo WHERE (UPPER(nama)=? OR UPPER(deskripsi)=?) AND is_active=1 AND (berlaku_sampai IS NULL OR berlaku_sampai >= CURDATE()) AND (kuota=0 OR terpakai < kuota)");
        $promo->execute([$kode, $kode]);
        $p = $promo->fetch();
        if ($p) {
            if ($p['min_transaksi'] > 0 && $total < $p['min_transaksi']) {
                echo json_encode(['error'=>'Minimum transaksi Rp ' . number_format($p['min_transaksi'],0,',','.')]); exit;
            }
            $diskon = $p['tipe'] === 'persen'
                ? min($total * $p['nilai'] / 100, $p['maks_diskon'] > 0 ? $p['maks_diskon'] : PHP_INT_MAX)
                : floatval($p['nilai']);
            echo json_encode([
                'valid'    => true,
                'tipe'     => 'promo',
                'promo_id' => $p['id'],
                'kode'     => $kode,
                'nama'     => $p['nama'],
                'diskon'   => $diskon,
                'info'     => ($p['tipe']==='persen' ? $p['nilai'].'%' : 'Rp '.number_format($p['nilai'],0,',','.')) . ' off'
            ]); exit;
        }

        echo json_encode(['error'=>'Kode tidak ditemukan atau tidak berlaku']); exit;
    }

    // APPLY VOUCHER (mark as used — dipanggil saat save transaksi)
    if ($action === 'apply_voucher' && $_SERVER['REQUEST_METHOD']==='POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        if (!empty($d['voucher_id'])) {
            $pdo->prepare("UPDATE hl_voucher SET is_used=1, used_at=NOW(), used_by_order=? WHERE id=?")
                ->execute([$d['no_order'], $d['voucher_id']]);
        }
        if (!empty($d['promo_id'])) {
            $pdo->prepare("UPDATE hl_promo SET terpakai=terpakai+1 WHERE id=?")->execute([$d['promo_id']]);
        }
        echo json_encode(['success'=>true]); exit;
    }

    // STATS promo
    if ($action === 'stats') {
        $total_promo   = $pdo->query("SELECT COUNT(*) FROM hl_promo WHERE is_active=1")->fetchColumn();
        $total_voucher = $pdo->query("SELECT COUNT(*) FROM hl_voucher")->fetchColumn();
        $used_voucher  = $pdo->query("SELECT COUNT(*) FROM hl_voucher WHERE is_used=1")->fetchColumn();
        $total_diskon  = $pdo->query("SELECT COALESCE(SUM(diskon),0) FROM hl_transaksi WHERE diskon > 0")->fetchColumn();
        echo json_encode(compact('total_promo','total_voucher','used_voucher','total_diskon')); exit;
    }

    echo json_encode(['error'=>'Unknown action']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Promo & Voucher'); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="harpy-erp.css"/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--teal:#35E8D5;--teal-d:#1CC4B2;--teal-bg:#E8FBF9;--navy:#1B2D5A;--navy-d:#0F1C3A;--white:#fff;--off:#F7F8FC;--light:#EEF1F8;--gray:#6C7A8D;--dark:#1C1C2E;--red:#EF4444;--green:#10B981;--yellow:#F59E0B;--purple:#8B5CF6;--font:'Plus Jakarta Sans',sans-serif;--mono:'DM Mono',monospace;--r:10px;--r-lg:16px;--shadow:0 2px 12px rgba(27,45,90,.08);--shadow-lg:0 8px 32px rgba(27,45,90,.14)}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--off);color:var(--dark);min-height:100vh}
.topbar{background:var(--navy-d);padding:0 24px;height:56px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;border-bottom:1px solid rgba(53,232,213,.15)}

.topbar-brand span{color:var(--teal)}









.main{max-width:1200px;margin:0 auto;padding:24px 20px}

/* STATS */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.stat-card{background:var(--white);border-radius:var(--r-lg);padding:18px 20px;border:1px solid rgba(27,45,90,.07);box-shadow:var(--shadow)}
.stat-num{font-size:1.6rem;font-weight:800;color:var(--navy);line-height:1;font-family:var(--mono)}
.stat-label{font-size:12px;color:var(--gray);margin-top:4px;font-weight:500}

/* TABS */
.tabs{display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid var(--light);padding-bottom:0}
.tab{padding:10px 20px;font-size:14px;font-weight:600;color:var(--gray);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .2s}
.tab:hover{color:var(--navy)}
.tab.active{color:var(--teal);border-bottom-color:var(--teal)}

/* CARD */
.card{background:var(--white);border-radius:var(--r-lg);border:1px solid rgba(27,45,90,.07);box-shadow:var(--shadow);overflow:hidden;margin-bottom:20px}
.card-header{padding:16px 20px;border-bottom:1px solid var(--light);display:flex;align-items:center;justify-content:space-between}
.card-title{font-size:14px;font-weight:700;color:var(--navy);display:flex;align-items:center;gap:8px}
.card-body{padding:20px}

/* PROMO GRID */
.promo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
.promo-card{background:var(--white);border-radius:var(--r-lg);border:1px solid rgba(27,45,90,.08);padding:20px;transition:all .2s;position:relative;overflow:hidden}
.promo-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px}
.promo-card.persen::before{background:linear-gradient(90deg,var(--teal),var(--teal-d))}
.promo-card.nominal::before{background:linear-gradient(90deg,var(--purple),#A78BFA)}
.promo-card.free_item::before{background:linear-gradient(90deg,var(--green),#34D399)}
.promo-card:hover{box-shadow:var(--shadow-lg);transform:translateY(-2px)}
.promo-card.inactive{opacity:.55}
.promo-nilai{font-size:2rem;font-weight:800;color:var(--navy);line-height:1;margin:8px 0 4px}
.promo-nama{font-size:15px;font-weight:700;color:var(--navy);margin-bottom:6px}
.promo-desc{font-size:13px;color:var(--gray);line-height:1.5;margin-bottom:12px}
.promo-meta{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
.promo-tag{font-size:11px;font-weight:600;padding:3px 9px;border-radius:100px;background:var(--off)}
.promo-progress{background:var(--light);border-radius:100px;height:6px;margin-bottom:8px;overflow:hidden}
.promo-progress-bar{height:100%;border-radius:100px;background:var(--teal);transition:width .5s}
.promo-actions{display:flex;gap:8px;margin-top:12px}
.badge-inactive{position:absolute;top:12px;right:12px;font-size:10px;font-weight:700;background:#FEE2E2;color:var(--red);padding:2px 8px;border-radius:100px}
.badge-active{position:absolute;top:12px;right:12px;font-size:10px;font-weight:700;background:#D1FAE5;color:var(--green);padding:2px 8px;border-radius:100px}

/* TABLE */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13.5px}
thead tr{background:var(--navy-d)}
thead th{padding:10px 12px;text-align:left;font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.6);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--light);transition:background .15s}
tbody tr:hover{background:var(--off)}
tbody td{padding:10px 12px;vertical-align:middle}
.td-kode{font-family:var(--mono);font-weight:700;font-size:13px;color:var(--navy);letter-spacing:.06em}

/* FORM */
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.form-row.cols3{grid-template-columns:1fr 1fr 1fr}
label{font-size:11px;font-weight:700;color:var(--navy);letter-spacing:.05em;text-transform:uppercase}
label .req{color:var(--red)}
input,select,textarea{padding:9px 12px;border:1.5px solid rgba(27,45,90,.14);border-radius:var(--r);font-family:var(--font);font-size:14px;color:var(--dark);background:var(--off);outline:none;transition:all .2s;width:100%}
input:focus,select:focus,textarea:focus{border-color:var(--teal);background:var(--white);box-shadow:0 0 0 3px rgba(53,232,213,.1)}

/* VOUCHER LIST */
.voucher-list{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.voucher-chip{font-family:var(--mono);font-size:13px;font-weight:700;padding:8px 14px;border-radius:8px;border:2px dashed rgba(53,232,213,.4);background:var(--teal-bg);color:var(--navy);letter-spacing:.08em;cursor:pointer;transition:all .2s}
.voucher-chip:hover{border-color:var(--teal);background:var(--teal)}
.voucher-chip.used{border-color:rgba(27,45,90,.12);background:var(--off);color:var(--gray);text-decoration:line-through;cursor:default}
.copy-hint{font-size:11px;color:var(--gray);margin-top:4px}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,28,58,.6);backdrop-filter:blur(4px);z-index:200;align-items:center;justify-content:center;padding:20px}
.modal-overlay.open{display:flex}
.modal{background:var(--white);border-radius:var(--r-lg);width:520px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-lg)}
.modal-header{padding:18px 20px;border-bottom:1px solid var(--light);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:var(--white);z-index:10}
.modal-title{font-size:15px;font-weight:700;color:var(--navy)}
.modal-close{background:none;border:none;font-size:18px;cursor:pointer;color:var(--gray)}
.modal-body{padding:20px}
.modal-footer{padding:16px 20px;border-top:1px solid var(--light);display:flex;gap:10px;justify-content:flex-end;position:sticky;bottom:0;background:var(--white)}

/* BTN */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 16px;border-radius:var(--r);font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;border:none}
.btn-primary{background:var(--teal);color:var(--navy-d)}
.btn-primary:hover{background:var(--teal-d)}
.btn-outline{background:transparent;color:var(--navy);border:1.5px solid rgba(27,45,90,.2)}
.btn-outline:hover{background:var(--light)}
.btn-danger{background:#FEE2E2;color:var(--red)}
.btn-danger:hover{background:var(--red);color:white}
.btn-purple{background:#EDE9FE;color:var(--purple)}
.btn-purple:hover{background:var(--purple);color:white}
.btn-sm{padding:6px 12px;font-size:12px}
.btn-green{background:#D1FAE5;color:#065F46}
.btn-green:hover{background:var(--green);color:white}

.empty{text-align:center;padding:48px;color:var(--gray);font-size:14px}
.empty-icon{font-size:2.5rem;margin-bottom:10px;opacity:.4}
.toast{position:fixed;bottom:20px;right:20px;z-index:999;padding:13px 18px;border-radius:var(--r);font-size:14px;font-weight:600;color:white;transform:translateY(60px);opacity:0;transition:all .3s cubic-bezier(.4,0,.2,1);pointer-events:none;max-width:320px}
.toast.show{transform:translateY(0);opacity:1}
.toast.success{background:var(--green)}
.toast.error{background:var(--red)}
</style>
</head>
<body>
<?php renderTopbar('promo'); ?>

<?php require_once 'components.php'; ?>

<div class="main">

  <!-- STATS -->
  <div class="stats">
    <div class="stat-card">
      <div class="stat-num" id="sPromo">-</div>
      <div class="stat-label">🎯 Promo Aktif</div>
    </div>
    <div class="stat-card">
      <div class="stat-num" id="sVoucher">-</div>
      <div class="stat-label">🎟️ Total Voucher</div>
    </div>
    <div class="stat-card">
      <div class="stat-num" id="sUsed">-</div>
      <div class="stat-label">✅ Voucher Terpakai</div>
    </div>
    <div class="stat-card">
      <div class="stat-num" id="sDiskon">Rp 0</div>
      <div class="stat-label">💸 Total Diskon Diberikan</div>
    </div>
  </div>

  <!-- TABS -->
  <div class="tabs">
    <div class="tab active" onclick="switchTab('promo',this)">🎯 Master Promo</div>
    <div class="tab" onclick="switchTab('voucher',this)">🎟️ Voucher</div>
  </div>

  <!-- TAB: PROMO -->
  <div id="tabPromo">
    <div class="card">
      <div class="card-header">
        <div class="card-title">🎯 Daftar Promo</div>
        <button class="btn btn-primary btn-sm" onclick="openPromoModal()">+ Buat Promo</button>
      </div>
    </div>
    <div id="promoGrid" class="promo-grid">
      <div class="empty"><div class="empty-icon">🎯</div><p>Memuat promo...</p></div>
    </div>
  </div>

  <!-- TAB: VOUCHER -->
  <div id="tabVoucher" style="display:none">
    <div class="card">
      <div class="card-header">
        <div class="card-title">🎟️ Generate Voucher</div>
      </div>
      <div class="card-body">
        <div class="form-row cols3">
          <div class="form-group">
            <label>Pilih Promo <span class="req">*</span></label>
            <select id="vPromoId">
              <option value="">— Pilih Promo —</option>
            </select>
          </div>
          <div class="form-group">
            <label>Jumlah Voucher</label>
            <input type="number" id="vJumlah" value="1" min="1" max="100"/>
          </div>
          <div class="form-group">
            <label>Prefix Kode</label>
            <input type="text" id="vPrefix" value="HRP" maxlength="6" placeholder="HRP"
              oninput="this.value=this.value.toUpperCase()"/>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Nama Penerima (opsional)</label>
            <input type="text" id="vNama" placeholder="Untuk voucher personal"/>
          </div>
          <div class="form-group">
            <label>Berlaku Sampai</label>
            <input type="date" id="vExpired"/>
          </div>
        </div>
        <button class="btn btn-primary" onclick="generateVoucher()">✨ Generate Voucher</button>
      </div>
    </div>

    <div class="card" id="voucherResultCard" style="display:none">
      <div class="card-header">
        <div class="card-title">🎟️ Voucher Baru</div>
        <button class="btn btn-green btn-sm" onclick="copyAllVouchers()">📋 Copy Semua</button>
      </div>
      <div class="card-body">
        <p class="copy-hint">Klik kode untuk copy · Bagikan ke pelanggan</p>
        <div class="voucher-list" id="voucherResult"></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title">📋 Daftar Voucher</div>
        <select id="filterVoucherPromo" onchange="loadVoucherList()" style="width:auto;font-size:13px;padding:6px 10px">
          <option value="">Semua Promo</option>
        </select>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Kode</th>
              <th>Promo</th>
              <th>Penerima</th>
              <th>Status</th>
              <th>Digunakan di</th>
              <th>Expired</th>
              <th>Dibuat</th>
            </tr>
          </thead>
          <tbody id="voucherTable">
            <tr><td colspan="7" class="empty">Pilih promo untuk lihat voucher</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<!-- MODAL BUAT/EDIT PROMO -->
<div class="modal-overlay" id="modalPromo">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title" id="promoModalTitle">🎯 Buat Promo Baru</span>
      <button class="modal-close" onclick="closePromoModal()">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="p_id"/>

      <div class="form-group">
        <label>Nama Promo <span class="req">*</span></label>
        <input type="text" id="p_nama" placeholder="Contoh: Promo Lebaran, Diskon Member, dll"/>
      </div>
      <div class="form-group">
        <label>Deskripsi</label>
        <textarea id="p_deskripsi" placeholder="Syarat & ketentuan promo..." style="min-height:72px"></textarea>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Tipe Diskon <span class="req">*</span></label>
          <select id="p_tipe" onchange="toggleDiskonFields()">
            <option value="persen">% Persentase</option>
            <option value="nominal">Rp Nominal</option>
            <option value="free_item">Free Item</option>
          </select>
        </div>
        <div class="form-group">
          <label id="nilaiLabel">Nilai Diskon (%) <span class="req">*</span></label>
          <input type="number" id="p_nilai" placeholder="0" min="0" step="1"/>
        </div>
      </div>

      <div class="form-row" id="extraFields">
        <div class="form-group">
          <label>Min. Transaksi (Rp)</label>
          <input type="number" id="p_min" placeholder="0" min="0" step="1000"/>
        </div>
        <div class="form-group" id="maksDiskontWrap">
          <label>Maks. Diskon (Rp) <span style="color:var(--gray);font-weight:400">(0=unlimited)</span></label>
          <input type="number" id="p_maks" placeholder="0" min="0" step="1000"/>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Berlaku Dari</label>
          <input type="date" id="p_dari"/>
        </div>
        <div class="form-group">
          <label>Berlaku Sampai</label>
          <input type="date" id="p_sampai"/>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Kuota <span style="color:var(--gray);font-weight:400">(0=unlimited)</span></label>
          <input type="number" id="p_kuota" value="0" min="0"/>
        </div>
        <div class="form-group">
          <label>Status</label>
          <select id="p_active">
            <option value="1">✅ Aktif</option>
            <option value="0">⏸️ Non-aktif</option>
          </select>
        </div>
      </div>

      <!-- PREVIEW -->
      <div id="promoPreview" style="background:var(--off);border-radius:var(--r);padding:14px;border-left:4px solid var(--teal);margin-top:4px;font-size:13px;display:none">
        <div style="font-weight:700;color:var(--navy);margin-bottom:4px">👁️ Preview</div>
        <div id="previewText" style="color:var(--gray)"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closePromoModal()">Batal</button>
      <button class="btn btn-primary" onclick="savePromo()">💾 Simpan Promo</button>
    </div>
  </div>
</div>

<?php renderToast(); ?>

<script>
let promos = [];


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
  loadStats();
  loadPromos();
  // Set default expired voucher 30 hari dari sekarang
  const d = new Date(); d.setDate(d.getDate()+30);
  document.getElementById('vExpired').value = localDateStr(d);
});

// ── TABS ──────────────────────────────────────────────
function switchTab(name, el) {
  document.getElementById('tabPromo').style.display   = name==='promo'   ? 'block' : 'none';
  document.getElementById('tabVoucher').style.display = name==='voucher' ? 'block' : 'none';
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
  el.classList.add('active');
}

// ── STATS ─────────────────────────────────────────────
async function loadStats() {
  const r = await fetch('promo.php?action=stats');
  const d = await r.json();
  document.getElementById('sPromo').textContent   = d.total_promo;
  document.getElementById('sVoucher').textContent = d.total_voucher;
  document.getElementById('sUsed').textContent    = d.used_voucher;
  document.getElementById('sDiskon').textContent  = 'Rp ' + parseFloat(d.total_diskon).toLocaleString('id-ID');
}

// ── LOAD PROMOS ───────────────────────────────────────
async function loadPromos() {
  const r = await fetch('promo.php?action=list_promo');
  promos = await r.json();
  renderPromos();
  populatePromoSelects();
}

function renderPromos() {
  const grid = document.getElementById('promoGrid');
  if (!promos.length) {
    grid.innerHTML = '<div class="empty" style="grid-column:1/-1"><div class="empty-icon">🎯</div><p>Belum ada promo. Klik "+ Buat Promo" untuk mulai.</p></div>';
    return;
  }
  grid.innerHTML = promos.map(p => {
    const pct = p.kuota > 0 ? Math.min((p.terpakai/p.kuota)*100,100) : 0;
    const nilaiStr = p.tipe==='persen' ? p.nilai+'%' : (p.tipe==='nominal' ? 'Rp '+parseFloat(p.nilai).toLocaleString('id-ID') : 'Free Item');
    const isActive = p.is_active==1 && (!p.berlaku_sampai || p.berlaku_sampai >= localDateStr());
    return `
      <div class="promo-card ${p.tipe} ${!isActive?'inactive':''}">
        <span class="${isActive?'badge-active':'badge-inactive'}">${isActive?'AKTIF':'NONAKTIF'}</span>
        <div style="font-size:11px;color:var(--gray);text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px">${p.tipe==='persen'?'Diskon %':p.tipe==='nominal'?'Diskon Nominal':'Free Item'}</div>
        <div class="promo-nilai">${nilaiStr}</div>
        <div class="promo-nama">${esc(p.nama)}</div>
        ${p.deskripsi?`<div class="promo-desc">${esc(p.deskripsi)}</div>`:''}
        <div class="promo-meta">
          ${p.min_transaksi>0?`<span class="promo-tag">Min Rp ${parseFloat(p.min_transaksi).toLocaleString('id-ID')}</span>`:''}
          ${p.maks_diskon>0?`<span class="promo-tag">Maks Rp ${parseFloat(p.maks_diskon).toLocaleString('id-ID')}</span>`:''}
          ${p.berlaku_dari?`<span class="promo-tag">📅 ${fmtDate(p.berlaku_dari)}</span>`:''}
          ${p.berlaku_sampai?`<span class="promo-tag">s/d ${fmtDate(p.berlaku_sampai)}</span>`:''}
        </div>
        ${p.kuota>0?`
          <div class="promo-progress"><div class="promo-progress-bar" style="width:${pct}%"></div></div>
          <div style="font-size:11px;color:var(--gray);margin-bottom:8px">${p.terpakai}/${p.kuota} terpakai</div>
        `:p.terpakai>0?`<div style="font-size:11px;color:var(--gray);margin-bottom:8px">${p.terpakai}× terpakai</div>`:''}
        <div style="font-size:11px;color:var(--gray);margin-bottom:8px">
          🎟️ ${p.total_voucher} voucher · ${p.used_voucher} terpakai
        </div>
        <div class="promo-actions">
          <button class="btn btn-outline btn-sm" onclick="editPromo(${p.id})">✏️ Edit</button>
          <button class="btn btn-purple btn-sm" onclick="quickGenVoucher(${p.id},'${esc(p.nama)}')">🎟️ Gen Voucher</button>
          <button class="btn btn-danger btn-sm" onclick="deletePromo(${p.id})">🗑️</button>
        </div>
      </div>`;
  }).join('');
}

function populatePromoSelects() {
  const opts = promos.map(p=>`<option value="${p.id}">${esc(p.nama)}</option>`).join('');
  document.getElementById('vPromoId').innerHTML = '<option value="">— Pilih Promo —</option>' + opts;
  document.getElementById('filterVoucherPromo').innerHTML = '<option value="">Semua Promo</option>' + opts;
}

// ── PROMO MODAL ───────────────────────────────────────
function openPromoModal(data=null) {
  document.getElementById('p_id').value        = data?.id || '';
  document.getElementById('p_nama').value      = data?.nama || '';
  document.getElementById('p_deskripsi').value = data?.deskripsi || '';
  document.getElementById('p_tipe').value      = data?.tipe || 'persen';
  document.getElementById('p_nilai').value     = data?.nilai || '';
  document.getElementById('p_min').value       = data?.min_transaksi || '0';
  document.getElementById('p_maks').value      = data?.maks_diskon || '0';
  document.getElementById('p_dari').value      = data?.berlaku_dari || '';
  document.getElementById('p_sampai').value    = data?.berlaku_sampai || '';
  document.getElementById('p_kuota').value     = data?.kuota || '0';
  document.getElementById('p_active').value    = data?.is_active ?? '1';
  document.getElementById('promoModalTitle').textContent = data ? '✏️ Edit Promo' : '🎯 Buat Promo Baru';
  toggleDiskonFields();
  document.getElementById('modalPromo').classList.add('open');
}

function editPromo(id) {
  const p = promos.find(x=>x.id==id);
  if (p) openPromoModal(p);
}

function closePromoModal() {
  document.getElementById('modalPromo').classList.remove('open');
}

function toggleDiskonFields() {
  const tipe = document.getElementById('p_tipe').value;
  const label = {persen:'Nilai Diskon (%)',nominal:'Nilai Diskon (Rp)',free_item:'Deskripsi Item Gratis'};
  document.getElementById('nilaiLabel').textContent = label[tipe] + ' *';
  document.getElementById('maksDiskontWrap').style.display = tipe==='persen' ? 'flex' : 'none';
}

async function savePromo() {
  const nama = document.getElementById('p_nama').value.trim();
  const nilai = document.getElementById('p_nilai').value;
  if (!nama) { showToast('⚠️ Nama promo wajib diisi', 'error'); return; }
  if (!nilai) { showToast('⚠️ Nilai diskon wajib diisi', 'error'); return; }

  const payload = {
    id: document.getElementById('p_id').value,
    nama, nilai,
    deskripsi:     document.getElementById('p_deskripsi').value,
    tipe:          document.getElementById('p_tipe').value,
    min_transaksi: document.getElementById('p_min').value || 0,
    maks_diskon:   document.getElementById('p_maks').value || 0,
    berlaku_dari:  document.getElementById('p_dari').value,
    berlaku_sampai:document.getElementById('p_sampai').value,
    kuota:         document.getElementById('p_kuota').value || 0,
    is_active:     document.getElementById('p_active').value,
  };

  const r = await fetch('promo.php?action=save_promo', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  });
  const d = await r.json();
  if (d.success) {
    showToast('✅ Promo berhasil disimpan!', 'success');
    closePromoModal();
    loadPromos();
    loadStats();
  } else {
    showToast('❌ ' + (d.error||'Gagal'), 'error');
  }
}

async function deletePromo(id) {
  if (!confirm('Nonaktifkan promo ini?')) return;
  const r = await fetch('promo.php?action=delete_promo', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({id})
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Promo dinonaktifkan', 'success'); loadPromos(); loadStats(); }
}

// ── VOUCHER ───────────────────────────────────────────
function quickGenVoucher(promoId, promoNama) {
  switchTab('voucher', document.querySelectorAll('.tab')[1]);
  document.getElementById('vPromoId').value = promoId;
  showToast('💡 Pilih jumlah voucher lalu klik Generate', 'success');
}

async function generateVoucher() {
  const promo_id = document.getElementById('vPromoId').value;
  if (!promo_id) { showToast('⚠️ Pilih promo terlebih dahulu', 'error'); return; }

  const payload = {
    promo_id,
    jumlah:       document.getElementById('vJumlah').value || 1,
    prefix:       document.getElementById('vPrefix').value || 'HRP',
    nama_penerima:document.getElementById('vNama').value,
    expired_at:   document.getElementById('vExpired').value,
  };

  const r = await fetch('promo.php?action=generate_voucher', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  });
  const d = await r.json();

  if (d.success) {
    showToast(`✅ ${d.vouchers.length} voucher berhasil dibuat!`, 'success');
    const wrap = document.getElementById('voucherResult');
    wrap.innerHTML = d.vouchers.map(k=>`
      <div class="voucher-chip" onclick="copyKode('${k}',this)" title="Klik untuk copy">${k}</div>`).join('');
    document.getElementById('voucherResultCard').style.display = 'block';
    loadVoucherList();
    loadStats();
    loadPromos();
  } else {
    showToast('❌ ' + (d.error||'Gagal generate'), 'error');
  }
}

async function loadVoucherList() {
  const promo_id = document.getElementById('filterVoucherPromo').value;
  if (!promo_id) {
    document.getElementById('voucherTable').innerHTML =
      '<tr><td colspan="7" class="empty" style="padding:20px;text-align:center;color:var(--gray)">Pilih promo untuk lihat voucher</td></tr>';
    return;
  }
  const r = await fetch('promo.php?action=list_voucher&promo_id=' + promo_id);
  const d = await r.json();

  if (!d.length) {
    document.getElementById('voucherTable').innerHTML =
      '<tr><td colspan="7" class="empty" style="padding:20px;text-align:center;color:var(--gray)">Belum ada voucher untuk promo ini</td></tr>';
    return;
  }

  const promo = promos.find(p=>p.id==promo_id);
  document.getElementById('voucherTable').innerHTML = d.map(v => `
    <tr>
      <td><span class="td-kode ${v.is_used?'':''}">
        <span onclick="copyKode('${v.kode}',this)" style="cursor:pointer;${v.is_used?'text-decoration:line-through;color:var(--gray)':''}">${v.kode}</span>
      </span></td>
      <td style="font-size:13px">${esc(promo?.nama||'-')}</td>
      <td style="font-size:13px">${esc(v.nama_penerima||'-')}</td>
      <td><span class="badge" style="${v.is_used?'background:#F3F4F6;color:#374151':'background:#D1FAE5;color:#065F46'}">${v.is_used?'✓ Terpakai':'○ Belum'}</span></td>
      <td style="font-family:var(--mono);font-size:12px">${v.used_by_order||'-'}</td>
      <td style="font-size:12px;color:var(--gray)">${v.expired_at?fmtDate(v.expired_at):'-'}</td>
      <td style="font-size:12px;color:var(--gray)">${fmtDate(v.created_at)}</td>
    </tr>`).join('');
}

function copyAllVouchers() {
  const chips = document.querySelectorAll('#voucherResult .voucher-chip');
  const kodes = Array.from(chips).map(c=>c.textContent.trim()).join('\n');
  navigator.clipboard.writeText(kodes).then(()=>showToast('✅ Semua kode disalin!','success'));
}

function copyKode(kode, el) {
  navigator.clipboard.writeText(kode).then(()=>{
    const orig = el.textContent;
    el.textContent = '✓ Copied!';
    setTimeout(()=>el.textContent=orig, 1500);
  });
}

// ── HELPERS ───────────────────────────────────────────
function fmtDate(d){if(!d)return'-';return new Date(d).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')}
function showToast(msg,type='success'){const t=document.getElementById('toast');t.textContent=msg;t.className='toast '+type+' show';setTimeout(()=>t.className='toast',3500)}
</script>
</body>
</html>
