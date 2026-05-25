<?php
$activePage = 'promo';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
requirePermission('promo.view');

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();

    // LIST PROMO
    // Filter: outlet-scope (outlet_id=current) ATAU account-scope (assigned to current/all outlets)
    if ($action === 'list_promo') {
        try {
            $rows = TenantQuery::raw(
                "SELECT p.*,
                    (SELECT COUNT(*) FROM hl_voucher WHERE promo_id=p.id AND tenant_id=p.tenant_id) as total_voucher,
                    (SELECT COUNT(*) FROM hl_voucher WHERE promo_id=p.id AND tenant_id=p.tenant_id AND is_used=1) as used_voucher
                  FROM hl_promo p
                  WHERE p.tenant_id=?
                    AND (
                      (COALESCE(p.scope,'outlet')='outlet' AND p.outlet_id=?)
                      OR (
                        p.scope='account' AND COALESCE(p.target_mode,'all')='all'
                      )
                      OR (
                        p.scope='account' AND p.target_mode='include' AND EXISTS (
                          SELECT 1 FROM hl_promo_outlets po
                          WHERE po.tenant_id=p.tenant_id AND po.promo_id=p.id AND po.outlet_id=?
                        )
                      )
                      OR (
                        p.scope='account' AND p.target_mode='exclude' AND NOT EXISTS (
                          SELECT 1 FROM hl_promo_outlets po
                          WHERE po.tenant_id=p.tenant_id AND po.promo_id=p.id AND po.outlet_id=?
                        )
                      )
                    )
                  ORDER BY p.created_at DESC",
                [$tid, $oid, $oid, $oid]
            );
        } catch (Throwable $e) {
            // Fallback kalau kolom scope / tabel hl_promo_outlets belum ada (migration belum)
            error_log('[promo list_promo fallback] '.$e->getMessage());
            $rows = TenantQuery::raw(
                "SELECT p.*,
                    (SELECT COUNT(*) FROM hl_voucher WHERE promo_id=p.id AND tenant_id=p.tenant_id) as total_voucher,
                    (SELECT COUNT(*) FROM hl_voucher WHERE promo_id=p.id AND tenant_id=p.tenant_id AND is_used=1) as used_voucher
                    FROM hl_promo p WHERE p.tenant_id=? AND p.outlet_id=? ORDER BY p.created_at DESC",
                [$tid, $oid]
            );
        }
        echo json_encode($rows); exit;
    }

    // SAVE PROMO
    if ($action === 'save_promo' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('promo.create') && !hasPermission('promo.edit')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $nama = substr(trim(strip_tags($d['nama'] ?? '')), 0, 100);
        if (!$nama) { echo json_encode(['error'=>'Nama wajib diisi']); exit; }

        $data = [
            'nama'          => $nama,
            'deskripsi'     => substr(trim($d['deskripsi'] ?? ''), 0, 500),
            'tipe'          => in_array($d['tipe']??'', ['persen','nominal','free_item']) ? $d['tipe'] : 'persen',
            'nilai'         => floatval($d['nilai'] ?? 0),
            'min_transaksi' => floatval($d['min_transaksi'] ?? 0),
            'maks_diskon'   => floatval($d['maks_diskon'] ?? 0),
            'berlaku_dari'  => $d['berlaku_dari'] ?: null,
            'berlaku_sampai'=> $d['berlaku_sampai'] ?: null,
            'kuota'         => intval($d['kuota'] ?? 0),
            'is_active'     => intval($d['is_active'] ?? 1),
        ];
        if (!empty($d['id'])) {
            TenantQuery::update('hl_promo', $data, 'id = ?', [intval($d['id'])]);
        } else {
            $data['created_by'] = $user['id'];
            TenantQuery::insert('hl_promo', $data);
        }
        logAudit(!empty($d['id'])?'update':'create','promo',(!empty($d['id'])?'Edit':'Buat').' promo: '.$nama);
        echo json_encode(['success'=>true]); exit;
    }

    // DELETE PROMO
    if ($action === 'delete_promo' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('promo.delete')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        TenantQuery::update('hl_promo', ['is_active'=>0], 'id = ?', [intval($d['id'])]);
        echo json_encode(['success'=>true]); exit;
    }

    // GENERATE VOUCHER
    if ($action === 'generate_voucher' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('promo.create')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d       = json_decode(file_get_contents('php://input'), true);
        $promo_id= intval($d['promo_id']);
        $jumlah  = min(intval($d['jumlah'] ?? 1), 100);
        $prefix  = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($d['prefix'] ?? 'HRP')), 0, 6));
        $expired = $d['expired_at'] ?: null;
        $nama    = substr(trim($d['nama_penerima'] ?? ''), 0, 100) ?: null;
        $telp    = substr(trim($d['telepon'] ?? ''), 0, 20) ?: null;

        // Pastikan promo milik tenant ini
        if (!TenantQuery::exists('hl_promo', 'id = ?', [$promo_id])) {
            echo json_encode(['error'=>'Promo tidak ditemukan']); exit;
        }

        $generated = [];
        for ($i = 0; $i < $jumlah; $i++) {
            // Generate kode unik per tenant
            $kode = null;
            for ($try = 0; $try < 5; $try++) {
                $candidate = $prefix . '-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
                if (!TenantQuery::exists('hl_voucher', 'kode = ?', [$candidate])) {
                    $kode = $candidate;
                    break;
                }
            }
            if (!$kode) continue;
            TenantQuery::insert('hl_voucher', [
                'promo_id'     => $promo_id,
                'kode'         => $kode,
                'nama_penerima'=> $nama,
                'telepon'      => $telp,
                'expired_at'   => $expired,
            ]);
            $generated[] = $kode;
        }
        echo json_encode(['success'=>true, 'vouchers'=>$generated]); exit;
    }

    // LIST VOUCHER per promo
    if ($action === 'list_voucher') {
        $promo_id = intval($_GET['promo_id']);
        $rows = TenantQuery::raw(
            "SELECT * FROM hl_voucher WHERE tenant_id=? AND promo_id=? ORDER BY created_at DESC LIMIT 200",
            [$tid, $promo_id]
        );
        echo json_encode($rows); exit;
    }

    // VALIDATE VOUCHER / KODE PROMO (dipanggil dari POS)
    if ($action === 'validate') {
        $kode  = strtoupper(trim($_GET['kode'] ?? ''));
        $total = floatval($_GET['total'] ?? 0);

        // Cek apakah kode adalah voucher milik tenant ini
        $vRows = TenantQuery::raw(
            "SELECT v.*, p.nama as promo_nama, p.tipe, p.nilai, p.min_transaksi, p.maks_diskon,
                p.berlaku_sampai as promo_expired
                FROM hl_voucher v
                LEFT JOIN hl_promo p ON v.promo_id=p.id AND p.tenant_id=v.tenant_id
                WHERE v.tenant_id=? AND v.kode=?",
            [$tid, $kode]
        );
        $v = $vRows[0] ?? null;

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
                'valid'=>true, 'tipe'=>'voucher', 'voucher_id'=>$v['id'],
                'kode'=>$v['kode'], 'nama'=>$v['promo_nama']?:'Voucher', 'diskon'=>$diskon,
                'info'=>($v['tipe']==='persen'?$v['nilai'].'%':'Rp '.number_format($v['nilai'],0,',','.')).  ' off'
            ]); exit;
        }

        // Cek promo langsung (tanpa voucher) — include scope account assigned ke outlet ini
        try {
            $pRows = TenantQuery::raw(
                "SELECT * FROM hl_promo p
                  WHERE p.tenant_id=? AND (UPPER(p.nama)=? OR UPPER(p.deskripsi)=?)
                    AND p.is_active=1
                    AND (p.berlaku_sampai IS NULL OR p.berlaku_sampai >= CURDATE())
                    AND (p.kuota=0 OR p.terpakai < p.kuota)
                    AND (
                      (COALESCE(p.scope,'outlet')='outlet' AND p.outlet_id=?)
                      OR (
                        p.scope='account' AND COALESCE(p.target_mode,'all')='all'
                      )
                      OR (
                        p.scope='account' AND p.target_mode='include' AND EXISTS (
                          SELECT 1 FROM hl_promo_outlets po
                          WHERE po.tenant_id=p.tenant_id AND po.promo_id=p.id AND po.outlet_id=?
                        )
                      )
                      OR (
                        p.scope='account' AND p.target_mode='exclude' AND NOT EXISTS (
                          SELECT 1 FROM hl_promo_outlets po
                          WHERE po.tenant_id=p.tenant_id AND po.promo_id=p.id AND po.outlet_id=?
                        )
                      )
                    )",
                [$tid, $kode, $kode, $oid, $oid, $oid]
            );
        } catch (Throwable) {
            $pRows = TenantQuery::raw(
                "SELECT * FROM hl_promo WHERE tenant_id=? AND outlet_id=? AND (UPPER(nama)=? OR UPPER(deskripsi)=?)
                 AND is_active=1 AND (berlaku_sampai IS NULL OR berlaku_sampai >= CURDATE())
                 AND (kuota=0 OR terpakai < kuota)",
                [$tid, $oid, $kode, $kode]
            );
        }
        $p = $pRows[0] ?? null;
        if ($p) {
            if ($p['min_transaksi'] > 0 && $total < $p['min_transaksi']) {
                echo json_encode(['error'=>'Minimum transaksi Rp ' . number_format($p['min_transaksi'],0,',','.')]); exit;
            }
            $diskon = $p['tipe'] === 'persen'
                ? min($total * $p['nilai'] / 100, $p['maks_diskon'] > 0 ? $p['maks_diskon'] : PHP_INT_MAX)
                : floatval($p['nilai']);
            echo json_encode([
                'valid'=>true, 'tipe'=>'promo', 'promo_id'=>$p['id'],
                'kode'=>$kode, 'nama'=>$p['nama'], 'diskon'=>$diskon,
                'info'=>($p['tipe']==='persen'?$p['nilai'].'%':'Rp '.number_format($p['nilai'],0,',','.')).  ' off'
            ]); exit;
        }

        echo json_encode(['error'=>'Kode tidak ditemukan atau tidak berlaku']); exit;
    }

    // APPLY VOUCHER (mark as used — dipanggil saat save transaksi)
    if ($action === 'apply_voucher' && $_SERVER['REQUEST_METHOD']==='POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        if (!empty($d['voucher_id'])) {
            TenantQuery::update('hl_voucher',
                ['is_used'=>1, 'used_at'=>date('Y-m-d H:i:s'), 'used_by_order'=>$d['no_order']??null],
                'id = ?', [intval($d['voucher_id'])]
            );
        }
        if (!empty($d['promo_id'])) {
            $db = Database::get();
            $db->prepare("UPDATE hl_promo SET terpakai=terpakai+1 WHERE id=? AND tenant_id=?")
               ->execute([intval($d['promo_id']), $tid]);
        }
        echo json_encode(['success'=>true]); exit;
    }

    // STATS
    if ($action === 'stats') {
        $total_promo   = TenantQuery::count('hl_promo',   'is_active=1');
        $total_voucher = TenantQuery::count('hl_voucher');
        $used_voucher  = TenantQuery::count('hl_voucher', 'is_used=1');
        $total_diskon  = TenantQuery::sum('hl_transaksi', 'diskon', 'diskon > 0');
        echo json_encode(compact('total_promo','total_voucher','used_voucher','total_diskon')); exit;
    }

    echo json_encode(['error'=>'Unknown action']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Promo & Voucher'); ?>
<style>
/* TABS */
.tabs{display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid var(--light);padding-bottom:0}
.tab{padding:10px 20px;font-size:14px;font-weight:600;color:var(--gray);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .2s}
.tab:hover{color:var(--navy)}
.tab.active{color:var(--teal);border-bottom-color:var(--teal)}

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
.badge-inactive{position:absolute;top:12px;right:12px;font-size:10px;font-weight:700;background:#FEE2E2;color:#EF4444;padding:2px 8px;border-radius:100px}
.badge-active{position:absolute;top:12px;right:12px;font-size:10px;font-weight:700;background:#D1FAE5;color:#10B981;padding:2px 8px;border-radius:100px}

/* VOUCHER */
.voucher-list{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.voucher-chip{font-family:var(--mono);font-size:13px;font-weight:700;padding:8px 14px;border-radius:8px;border:2px dashed rgba(53,232,213,.4);background:var(--teal-bg);color:var(--navy);letter-spacing:.08em;cursor:pointer;transition:all .2s}
.voucher-chip:hover{border-color:var(--teal);background:var(--teal)}
.voucher-chip.used{border-color:rgba(27,45,90,.12);background:var(--off);color:var(--gray);text-decoration:line-through;cursor:default}
.td-kode{font-family:var(--mono);font-weight:700;font-size:13px;color:var(--navy);letter-spacing:.06em}
@media(max-width:900px){.promo-grid{grid-template-columns:repeat(auto-fill,minmax(260px,1fr))}}
@media(max-width:680px){
  .promo-grid{grid-template-columns:1fr}
  .hl-form-row{grid-template-columns:1fr !important}
}
</style>
</head>
<body>
<?php renderTopbar('promo'); ?>
<div class="hl-main">

  <div class="hl-stat-grid-4" style="margin-bottom:20px">
    <div class="hl-stat-card teal"><div class="hl-stat-num" id="sPromo">-</div><div class="hl-stat-label">🎯 Promo Aktif</div></div>
    <div class="hl-stat-card navy"><div class="hl-stat-num" id="sVoucher">-</div><div class="hl-stat-label">🎟️ Total Voucher</div></div>
    <div class="hl-stat-card green"><div class="hl-stat-num" id="sUsed">-</div><div class="hl-stat-label">✅ Voucher Terpakai</div></div>
    <div class="hl-stat-card purple"><div class="hl-stat-num" id="sDiskon">Rp 0</div><div class="hl-stat-label">💸 Total Diskon</div></div>
  </div>

  <div class="tabs">
    <div class="tab active" onclick="switchTab('promo',this)">🎯 Master Promo</div>
    <div class="tab" onclick="switchTab('voucher',this)">🎟️ Voucher</div>
  </div>

  <!-- TAB: PROMO -->
  <div id="tabPromo">
    <div class="hl-card" style="margin-bottom:16px">
      <div class="hl-card-header">
        <div class="hl-card-title">🎯 Daftar Promo</div>
        <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="openPromoModal()">+ Buat Promo</button>
      </div>
    </div>
    <div id="promoGrid" class="promo-grid">
      <div class="hl-loading">⏳ Memuat...</div>
    </div>
  </div>

  <!-- TAB: VOUCHER -->
  <div id="tabVoucher" style="display:none">
    <div class="hl-card" style="margin-bottom:16px">
      <div class="hl-card-header"><div class="hl-card-title">🎟️ Generate Voucher</div></div>
      <div style="padding:20px">
        <div class="hl-form-row" style="grid-template-columns:1fr 1fr 1fr">
          <div class="hl-form-group">
            <label class="hl-label">Pilih Promo <span class="req">*</span></label>
            <select id="vPromoId" class="hl-input"><option value="">— Pilih Promo —</option></select>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Jumlah Voucher</label>
            <input type="number" id="vJumlah" class="hl-input" value="1" min="1" max="100"/>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Prefix Kode</label>
            <input type="text" id="vPrefix" class="hl-input" value="HRP" maxlength="6" oninput="this.value=this.value.toUpperCase()"/>
          </div>
        </div>
        <div class="hl-form-row">
          <div class="hl-form-group">
            <label class="hl-label">Nama Penerima (opsional)</label>
            <input type="text" id="vNama" class="hl-input" placeholder="Untuk voucher personal"/>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Berlaku Sampai</label>
            <input type="date" id="vExpired" class="hl-input"/>
          </div>
        </div>
        <button class="hl-btn hl-btn-primary" onclick="generateVoucher()">✨ Generate Voucher</button>
      </div>
    </div>

    <div class="hl-card" id="voucherResultCard" style="display:none;margin-bottom:16px">
      <div class="hl-card-header">
        <div class="hl-card-title">🎟️ Voucher Baru</div>
        <button class="hl-btn hl-btn-sm" style="background:#D1FAE5;color:#065F46" onclick="copyAllVouchers()">📋 Copy Semua</button>
      </div>
      <div style="padding:16px">
        <p style="font-size:11px;color:var(--gray);margin-bottom:8px">Klik kode untuk copy · Bagikan ke pelanggan</p>
        <div class="voucher-list" id="voucherResult"></div>
      </div>
    </div>

    <div class="hl-card">
      <div class="hl-card-header">
        <div class="hl-card-title">📋 Daftar Voucher</div>
        <select id="filterVoucherPromo" class="hl-input" style="width:auto;font-size:13px;padding:6px 10px" onchange="loadVoucherList()">
          <option value="">Semua Promo</option>
        </select>
      </div>
      <div class="hl-table-wrap">
        <table class="hl-table hl-stack-mobile">
          <thead><tr><th>Kode</th><th>Promo</th><th>Penerima</th><th>Status</th><th>Digunakan di</th><th>Expired</th><th>Dibuat</th></tr></thead>
          <tbody id="voucherTable">
            <tr><td colspan="7" class="hl-empty">Pilih promo untuk lihat voucher</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<!-- MODAL BUAT/EDIT PROMO -->
<div class="hl-modal-overlay" id="modalPromo">
  <div class="hl-modal">
    <div class="hl-modal-header">
      <span class="hl-modal-title" id="promoModalTitle">🎯 Buat Promo Baru</span>
      <button class="hl-modal-close" onclick="closePromoModal()">✕</button>
    </div>
    <div class="hl-modal-body">
      <input type="hidden" id="p_id"/>
      <div class="hl-form-group">
        <label class="hl-label">Nama Promo <span class="req">*</span></label>
        <input type="text" id="p_nama" class="hl-input" placeholder="Contoh: Promo Lebaran, Diskon Member, dll"/>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Deskripsi</label>
        <textarea id="p_deskripsi" class="hl-input hl-textarea" placeholder="Syarat & ketentuan promo..."></textarea>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Tipe Diskon <span class="req">*</span></label>
          <select id="p_tipe" class="hl-input" onchange="toggleDiskonFields()">
            <option value="persen">% Persentase</option>
            <option value="nominal">Rp Nominal</option>
            <option value="free_item">Free Item</option>
          </select>
        </div>
        <div class="hl-form-group">
          <label class="hl-label" id="nilaiLabel">Nilai Diskon (%) <span class="req">*</span></label>
          <input type="number" id="p_nilai" class="hl-input" placeholder="0" min="0" step="1"/>
        </div>
      </div>
      <div class="hl-form-row" id="extraFields">
        <div class="hl-form-group">
          <label class="hl-label">Min. Transaksi (Rp)</label>
          <input type="number" id="p_min" class="hl-input" placeholder="0" min="0" step="1000"/>
        </div>
        <div class="hl-form-group" id="maksDiskontWrap">
          <label class="hl-label">Maks. Diskon (Rp) <span style="color:var(--gray);font-weight:400">(0=unlimited)</span></label>
          <input type="number" id="p_maks" class="hl-input" placeholder="0" min="0" step="1000"/>
        </div>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Berlaku Dari</label>
          <input type="date" id="p_dari" class="hl-input"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Berlaku Sampai</label>
          <input type="date" id="p_sampai" class="hl-input"/>
        </div>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Kuota <span style="color:var(--gray);font-weight:400">(0=unlimited)</span></label>
          <input type="number" id="p_kuota" class="hl-input" value="0" min="0"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Status</label>
          <select id="p_active" class="hl-input">
            <option value="1">✅ Aktif</option>
            <option value="0">⏸️ Non-aktif</option>
          </select>
        </div>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closePromoModal()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="savePromo()">💾 Simpan Promo</button>
    </div>
  </div>
</div>

<?php renderToast(); ?>
<script>
let promos = [];

function localDateStr(d) {
  const dt = d || new Date();
  return dt.getFullYear() + '-' + String(dt.getMonth()+1).padStart(2,'0') + '-' + String(dt.getDate()).padStart(2,'0');
}

document.addEventListener('DOMContentLoaded', () => {
  loadStats(); loadPromos();
  const d = new Date(); d.setDate(d.getDate()+30);
  document.getElementById('vExpired').value = localDateStr(d);
});

function switchTab(name, el) {
  document.getElementById('tabPromo').style.display   = name==='promo'   ? 'block' : 'none';
  document.getElementById('tabVoucher').style.display = name==='voucher' ? 'block' : 'none';
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
  el.classList.add('active');
}

async function loadStats() {
  const r = await fetch('promo.php?action=stats');
  const d = await r.json();
  document.getElementById('sPromo').textContent   = d.total_promo;
  document.getElementById('sVoucher').textContent = d.total_voucher;
  document.getElementById('sUsed').textContent    = d.used_voucher;
  document.getElementById('sDiskon').textContent  = 'Rp ' + parseFloat(d.total_diskon||0).toLocaleString('id-ID');
}

async function loadPromos() {
  const r = await fetch('promo.php?action=list_promo');
  promos = await r.json();
  renderPromos();
  populatePromoSelects();
}

function renderPromos() {
  const grid = document.getElementById('promoGrid');
  if (!promos.length) {
    grid.innerHTML = `<div style="grid-column:1/-1"><div class="hl-empty-v2">
      <div class="e-icon">🎯</div>
      <div class="e-title">Belum ada promo</div>
      <div class="e-sub">Buat promo & voucher untuk menarik pelanggan</div>
      <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="openPromoModal()">+ Buat Promo</button>
    </div></div>`;
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
        ${p.kuota>0?`<div class="promo-progress"><div class="promo-progress-bar" style="width:${pct}%"></div></div>
          <div style="font-size:11px;color:var(--gray);margin-bottom:8px">${p.terpakai}/${p.kuota} terpakai</div>`:
          p.terpakai>0?`<div style="font-size:11px;color:var(--gray);margin-bottom:8px">${p.terpakai}× terpakai</div>`:''}
        <div style="font-size:11px;color:var(--gray);margin-bottom:8px">🎟️ ${p.total_voucher} voucher · ${p.used_voucher} terpakai</div>
        <div class="promo-actions">
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="editPromo(${p.id})">✏️ Edit</button>
          <button class="hl-btn hl-btn-sm" style="background:#EDE9FE;color:#5B21B6" onclick="quickGenVoucher(${p.id})">🎟️ Gen Voucher</button>
          <button class="hl-btn hl-btn-sm" style="background:#FEE2E2;color:#991B1B" onclick="deletePromo(${p.id})">🗑️</button>
        </div>
      </div>`;
  }).join('');
}

function populatePromoSelects() {
  const opts = promos.map(p=>`<option value="${p.id}">${esc(p.nama)}</option>`).join('');
  document.getElementById('vPromoId').innerHTML = '<option value="">— Pilih Promo —</option>' + opts;
  document.getElementById('filterVoucherPromo').innerHTML = '<option value="">Semua Promo</option>' + opts;
}

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
function editPromo(id) { const p = promos.find(x=>x.id==id); if(p) openPromoModal(p); }
function closePromoModal() { document.getElementById('modalPromo').classList.remove('open'); }

function toggleDiskonFields() {
  const tipe = document.getElementById('p_tipe').value;
  const label = {persen:'Nilai Diskon (%)',nominal:'Nilai Diskon (Rp)',free_item:'Deskripsi Item Gratis'};
  document.getElementById('nilaiLabel').textContent = (label[tipe]||'Nilai') + ' *';
  document.getElementById('maksDiskontWrap').style.display = tipe==='persen' ? '' : 'none';
}

async function savePromo() {
  const nama = document.getElementById('p_nama').value.trim();
  const nilai = document.getElementById('p_nilai').value;
  if (!nama) { showToast('⚠️ Nama promo wajib diisi','error'); return; }
  if (!nilai) { showToast('⚠️ Nilai diskon wajib diisi','error'); return; }
  const r = await fetch('promo.php?action=save_promo', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({
      id: document.getElementById('p_id').value, nama, nilai,
      deskripsi:      document.getElementById('p_deskripsi').value,
      tipe:           document.getElementById('p_tipe').value,
      min_transaksi:  document.getElementById('p_min').value||0,
      maks_diskon:    document.getElementById('p_maks').value||0,
      berlaku_dari:   document.getElementById('p_dari').value,
      berlaku_sampai: document.getElementById('p_sampai').value,
      kuota:          document.getElementById('p_kuota').value||0,
      is_active:      document.getElementById('p_active').value,
    })
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Promo disimpan!','success'); closePromoModal(); loadPromos(); loadStats(); }
  else showToast('❌ '+(d.error||'Gagal'),'error');
}

async function deletePromo(id) {
  if (!confirm('Nonaktifkan promo ini?')) return;
  const r = await fetch('promo.php?action=delete_promo', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id})
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Promo dinonaktifkan','success'); loadPromos(); loadStats(); }
}

function quickGenVoucher(promoId) {
  switchTab('voucher', document.querySelectorAll('.tab')[1]);
  document.getElementById('vPromoId').value = promoId;
  showToast('💡 Pilih jumlah voucher lalu klik Generate','success');
}

async function generateVoucher() {
  const promo_id = document.getElementById('vPromoId').value;
  if (!promo_id) { showToast('⚠️ Pilih promo terlebih dahulu','error'); return; }
  const r = await fetch('promo.php?action=generate_voucher', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({
      promo_id,
      jumlah:       document.getElementById('vJumlah').value||1,
      prefix:       document.getElementById('vPrefix').value||'HRP',
      nama_penerima:document.getElementById('vNama').value,
      expired_at:   document.getElementById('vExpired').value,
    })
  });
  const d = await r.json();
  if (d.success) {
    showToast(`✅ ${d.vouchers.length} voucher berhasil dibuat!`,'success');
    document.getElementById('voucherResult').innerHTML =
      d.vouchers.map(k=>`<div class="voucher-chip" onclick="copyKode('${k}',this)" title="Klik untuk copy">${k}</div>`).join('');
    document.getElementById('voucherResultCard').style.display = 'block';
    loadVoucherList(); loadStats(); loadPromos();
  } else showToast('❌ '+(d.error||'Gagal generate'),'error');
}

async function loadVoucherList() {
  const promo_id = document.getElementById('filterVoucherPromo').value;
  if (!promo_id) {
    document.getElementById('voucherTable').innerHTML = '<tr><td colspan="7" class="hl-empty">Pilih promo untuk lihat voucher</td></tr>';
    return;
  }
  const r = await fetch('promo.php?action=list_voucher&promo_id='+promo_id);
  const d = await r.json();
  if (!d.length) {
    document.getElementById('voucherTable').innerHTML = '<tr><td colspan="7" class="hl-empty">Belum ada voucher</td></tr>';
    return;
  }
  const promo = promos.find(p=>p.id==promo_id);
  document.getElementById('voucherTable').innerHTML = d.map(v => `
    <tr>
      <td data-lbl="Kode"><span class="td-kode" onclick="copyKode('${v.kode}',this)" style="cursor:pointer;${v.is_used?'text-decoration:line-through;color:var(--gray)':''}">${v.kode}</span></td>
      <td data-lbl="Promo" style="font-size:13px">${esc(promo?.nama||'-')}</td>
      <td data-lbl="Penerima" style="font-size:13px">${esc(v.nama_penerima||'-')}</td>
      <td data-lbl="Status"><span class="hl-badge" style="${v.is_used?'background:#F3F4F6;color:#374151':'background:#D1FAE5;color:#065F46'}">${v.is_used?'✓ Terpakai':'○ Belum'}</span></td>
      <td data-lbl="Order" style="font-family:var(--mono);font-size:12px">${v.used_by_order||'-'}</td>
      <td data-lbl="Expired" style="font-size:12px;color:var(--gray)">${v.expired_at?fmtDate(v.expired_at):'-'}</td>
      <td data-lbl="Dibuat" style="font-size:12px;color:var(--gray)">${fmtDate(v.created_at)}</td>
    </tr>`).join('');
}

function copyAllVouchers() {
  const chips = document.querySelectorAll('#voucherResult .voucher-chip');
  navigator.clipboard.writeText(Array.from(chips).map(c=>c.textContent.trim()).join('\n'))
    .then(()=>showToast('✅ Semua kode disalin!','success'));
}

function copyKode(kode, el) {
  navigator.clipboard.writeText(kode).then(()=>{
    const orig = el.textContent; el.textContent = '✓ Copied!';
    setTimeout(()=>el.textContent=orig, 1500);
  });
}

function fmtDate(d){if(!d)return'-';return new Date(d).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')}
</script>
</body>
</html>
