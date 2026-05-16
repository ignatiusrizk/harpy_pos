<?php
// ══════════════════════════════════════════════════════
// hq/promo.php — Promo Lintas Outlet (HQ View)
// Brief HQ-Outlet Section 4.4
//
// Konsep scope:
//   - scope='outlet': promo lama, hanya berlaku 1 outlet (dibuat di outlet view)
//   - scope='account': dibuat di HQ, di-assign ke outlet via hl_promo_outlets
//     * Tidak ada row di hl_promo_outlets → fallback: berlaku semua outlet
//     * Ada row dengan outlet_id=0 → berlaku semua outlet
//     * Ada row outlet_id spesifik → berlaku hanya outlet itu
// ══════════════════════════════════════════════════════

$activePage = 'hq-promo';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];
$uid  = (int)($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? '';

// ── AJAX actions ──────────────────────────────────────
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'list') {
        $q = trim($_GET['q'] ?? '');
        $params = [$tid];
        $whereExtra = '';
        if ($q !== '') {
            $whereExtra = " AND (p.nama LIKE ? OR p.deskripsi LIKE ?)";
            $like = "%$q%";
            $params[] = $like; $params[] = $like;
        }

        try {
            $stmt = $db->prepare(
                "SELECT p.id, p.nama, p.deskripsi, p.tipe, p.nilai,
                        p.min_transaksi, p.maks_diskon, p.kuota, p.terpakai,
                        p.berlaku_dari, p.berlaku_sampai, p.is_active,
                        p.scope, p.outlet_id AS source_outlet_id,
                        p.created_at,
                        (SELECT nama_outlet FROM outlets WHERE id=p.outlet_id) AS source_outlet_name
                   FROM hl_promo p
                  WHERE p.tenant_id=? $whereExtra
                  ORDER BY p.is_active DESC, p.created_at DESC"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('[hq promo list] '.$e->getMessage());
            echo json_encode(['error'=>$e->getMessage()]); exit;
        }

        // Untuk account scope, ambil outlet assignment
        foreach ($rows as &$r) {
            $r['target_outlets'] = [];
            $r['target_all'] = false;
            if ($r['scope'] === 'account') {
                try {
                    $a = $db->prepare(
                        "SELECT po.outlet_id, o.nama_outlet
                           FROM hl_promo_outlets po
                           LEFT JOIN outlets o ON o.id = po.outlet_id
                          WHERE po.tenant_id=? AND po.promo_id=?"
                    );
                    $a->execute([$tid, $r['id']]);
                    $assigns = $a->fetchAll();
                    if (empty($assigns)) {
                        $r['target_all'] = true; // tidak ada assignment = berlaku semua
                    } else {
                        foreach ($assigns as $x) {
                            if ((int)$x['outlet_id'] === 0) { $r['target_all'] = true; break; }
                            $r['target_outlets'][] = $x;
                        }
                    }
                } catch (Throwable) {}
            }
        }
        unset($r);
        echo json_encode($rows); exit;
    }

    if ($action === 'detail') {
        $pid = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM hl_promo WHERE id=? AND tenant_id=? LIMIT 1");
        $stmt->execute([$pid, $tid]);
        $p = $stmt->fetch();
        if (!$p) { echo json_encode(['error'=>'Promo tidak ditemukan']); exit; }

        $assigned = [];
        try {
            $a = $db->prepare("SELECT outlet_id FROM hl_promo_outlets WHERE tenant_id=? AND promo_id=?");
            $a->execute([$tid, $pid]);
            $assigned = array_map('intval', $a->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {}

        $allOutlets = $db->prepare(
            "SELECT id, nama_outlet, status FROM outlets
              WHERE tenant_id=? AND status IN ('trial','grace','active')
              ORDER BY is_main DESC, nama_outlet ASC"
        );
        $allOutlets->execute([$tid]);

        echo json_encode([
            'promo'       => $p,
            'assigned'    => $assigned,
            'all_outlets' => $allOutlets->fetchAll(),
        ]);
        exit;
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        verifyCsrf();

        $id    = (int)($d['id'] ?? 0);
        $nama  = substr(trim(strip_tags($d['nama'] ?? '')), 0, 100);
        $desk  = substr(trim($d['deskripsi'] ?? ''), 0, 500);
        $tipe  = in_array($d['tipe'] ?? '', ['persen','nominal','free_item'], true) ? $d['tipe'] : 'persen';
        $nilai = floatval($d['nilai'] ?? 0);
        $minTx = floatval($d['min_transaksi'] ?? 0);
        $maks  = floatval($d['maks_diskon'] ?? 0);
        $dari  = $d['berlaku_dari']   ?: null;
        $sampai= $d['berlaku_sampai'] ?: null;
        $kuota = intval($d['kuota'] ?? 0);
        $active= (int)(!empty($d['is_active']) ? 1 : 0);

        // Target mode: 'all' = semua outlet, 'specific' = pilih outlet
        $targetMode    = $d['target_mode']    ?? 'all';
        $targetOutlets = array_map('intval', $d['target_outlets'] ?? []);

        if (!$nama)  { echo json_encode(['error'=>'Nama promo wajib diisi']); exit; }
        if ($nilai <= 0) { echo json_encode(['error'=>'Nilai promo harus > 0']); exit; }
        if ($targetMode === 'specific' && empty($targetOutlets)) {
            echo json_encode(['error'=>'Pilih minimal 1 outlet target']); exit;
        }

        // Validasi outlet ownership
        if (!empty($targetOutlets)) {
            $ph = implode(',', array_fill(0, count($targetOutlets), '?'));
            $vO = $db->prepare("SELECT COUNT(*) FROM outlets
                                 WHERE tenant_id=? AND id IN ($ph)");
            $vO->execute(array_merge([$tid], $targetOutlets));
            if ((int)$vO->fetchColumn() !== count($targetOutlets)) {
                echo json_encode(['error'=>'Outlet target invalid']); exit;
            }
        }

        $db->beginTransaction();
        try {
            if ($id) {
                // Update existing — pastikan promo milik tenant + scope=account
                $vP = $db->prepare("SELECT scope FROM hl_promo WHERE id=? AND tenant_id=?");
                $vP->execute([$id, $tid]);
                $exScope = $vP->fetchColumn();
                if (!$exScope) { throw new Exception('Promo tidak ditemukan'); }
                if ($exScope !== 'account') { throw new Exception('Hanya promo HQ yang bisa diedit dari sini'); }

                $db->prepare(
                    "UPDATE hl_promo
                        SET nama=?, deskripsi=?, tipe=?, nilai=?, min_transaksi=?, maks_diskon=?,
                            berlaku_dari=?, berlaku_sampai=?, kuota=?, is_active=?
                      WHERE id=? AND tenant_id=? AND scope='account'"
                )->execute([$nama, $desk, $tipe, $nilai, $minTx, $maks, $dari, $sampai, $kuota, $active, $id, $tid]);

                $db->prepare("DELETE FROM hl_promo_outlets WHERE tenant_id=? AND promo_id=?")
                   ->execute([$tid, $id]);

                $promoId = $id;
            } else {
                // INSERT new — scope=account, outlet_id=0 (sentinel)
                $db->prepare(
                    "INSERT INTO hl_promo
                       (tenant_id, outlet_id, scope, nama, deskripsi, tipe, nilai,
                        min_transaksi, maks_diskon, berlaku_dari, berlaku_sampai,
                        kuota, terpakai, is_active, created_at)
                     VALUES (?, 0, 'account', ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW())"
                )->execute([$tid, $nama, $desk, $tipe, $nilai, $minTx, $maks, $dari, $sampai, $kuota, $active]);
                $promoId = (int)$db->lastInsertId();
            }

            // Insert outlet assignment
            if ($targetMode === 'all') {
                // Tidak insert apapun → fallback: berlaku semua. Tapi explicit insert outlet_id=0 lebih clear.
                $db->prepare("INSERT INTO hl_promo_outlets (tenant_id, promo_id, outlet_id) VALUES (?,?,0)")
                   ->execute([$tid, $promoId]);
            } else {
                $ins = $db->prepare("INSERT INTO hl_promo_outlets (tenant_id, promo_id, outlet_id) VALUES (?,?,?)");
                foreach ($targetOutlets as $oid) {
                    $ins->execute([$tid, $promoId, $oid]);
                }
            }

            $db->commit();
            echo json_encode(['success'=>true, 'id'=>$promoId]);
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[hq promo save] '.$e->getMessage());
            echo json_encode(['error'=>'Gagal simpan: '.$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        verifyCsrf();
        $id = (int)($d['id'] ?? 0);
        if (!$id) { echo json_encode(['error'=>'ID invalid']); exit; }

        try {
            // Soft delete: set is_active=0 (jaga history)
            $r = $db->prepare("UPDATE hl_promo SET is_active=0
                                 WHERE id=? AND tenant_id=? AND scope='account'");
            $r->execute([$id, $tid]);
            if ($r->rowCount() === 0) {
                echo json_encode(['error'=>'Promo tidak ditemukan atau bukan promo HQ']);
                exit;
            }
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            error_log('[hq promo delete] '.$e->getMessage());
            echo json_encode(['error'=>'Gagal hapus: '.$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error'=>'Unknown action']); exit;
}

$ownerNama  = $hqUser['nama'] ?? 'Owner';
$tenantNama = $hqTenant['nama_outlet'] ?? 'HQ';
$csrf       = getCsrfToken();

// Cek apakah tabel hl_promo_outlets sudah ada
$migrationOk = true;
try { $db->query("SELECT 1 FROM hl_promo_outlets LIMIT 1"); }
catch (Throwable) { $migrationOk = false; }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HQ Promo — LAMASY</title>
<meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
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

  .container{max-width:1280px;margin:24px auto;padding:0 20px 60px}
  .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:12px}
  h1{font-size:1.4rem;font-weight:800;color:#0F1C3A}
  h1 small{display:block;font-size:13px;font-weight:400;color:#6B7280;margin-top:2px}

  .toolbar{background:#fff;border-radius:12px;padding:14px 18px;display:flex;gap:10px;align-items:center;
           flex-wrap:wrap;margin-bottom:16px;box-shadow:0 1px 6px rgba(0,0,0,.05)}
  .toolbar input{flex:1;min-width:200px;padding:9px 14px;border:1.5px solid #E5E7EB;border-radius:8px;font-size:14px;outline:none}
  .toolbar input:focus{border-color:#35E8D5}

  .pm-grid{display:grid;grid-template-columns:1fr;gap:10px}
  .pm-card{background:#fff;border-radius:12px;padding:16px 18px;display:grid;
           grid-template-columns:1fr 2fr 1fr auto;gap:16px;align-items:center;
           box-shadow:0 1px 6px rgba(0,0,0,.05);transition:box-shadow .2s}
  .pm-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08)}
  .pm-card.inactive{opacity:.6}
  .pm-name{font-weight:700;color:#0F1C3A;font-size:14px}
  .pm-name small{display:block;color:#6B7280;font-weight:400;font-size:12px;margin-top:2px}
  .pm-scope{font-size:9px;font-weight:800;padding:2px 8px;border-radius:100px;text-transform:uppercase;
            display:inline-block;margin-top:5px;letter-spacing:.05em}
  .scope-account{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff}
  .scope-outlet{background:#DBEAFE;color:#1E40AF}
  .pm-nilai{font-family:monospace;font-weight:700;color:#0F1C3A;font-size:15px}
  .pm-nilai small{display:block;color:#9CA3AF;font-weight:400;font-size:10px;text-transform:uppercase}
  .pm-target{font-size:11px;color:#6B7280}
  .pm-target strong{color:#0F1C3A;display:block;font-size:12px;margin-bottom:3px}
  .target-tag{background:#F0FDFB;color:#0891B2;font-size:10px;font-weight:600;
              padding:2px 7px;border-radius:4px;margin-right:3px;display:inline-block;margin-bottom:2px}
  .target-all{background:#FEF3C7;color:#92400E;font-weight:700}
  .pm-actions{display:flex;gap:5px}

  .btn{padding:6px 11px;border-radius:7px;font-weight:700;font-size:12px;border:none;cursor:pointer;
       font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:4px}
  .btn-primary{background:#35E8D5;color:#0F1C3A}
  .btn-light{background:#F3F4F6;color:#0F1C3A}
  .btn-light:hover{background:#E5E7EB}
  .btn-danger{background:#FEE2E2;color:#991B1B}
  .btn-danger:hover{background:#FECACA}
  .btn-big{padding:11px 20px;font-size:14px}

  .empty{text-align:center;padding:48px 20px;color:#9CA3AF;background:#fff;border-radius:12px}
  .empty .ico{font-size:48px;margin-bottom:10px}

  .modal-backdrop{position:fixed;inset:0;background:rgba(15,28,58,.75);z-index:999;display:none;
                  align-items:center;justify-content:center;padding:20px}
  .modal-backdrop.open{display:flex}
  .modal{background:#fff;border-radius:14px;max-width:640px;width:100%;max-height:90vh;overflow:auto;
         padding:24px 26px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
  .modal-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px}
  .modal-title{font-size:1.1rem;font-weight:800;color:#0F1C3A}
  .modal-close{background:none;border:none;font-size:24px;cursor:pointer;color:#9CA3AF;line-height:1}
  .modal-close:hover{color:#0F1C3A}

  .form-grid{display:grid;gap:12px}
  .form-grid label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:4px}
  .form-grid input,.form-grid select,.form-grid textarea{
    width:100%;padding:9px 12px;border:1.5px solid #E5E7EB;border-radius:8px;
    font-size:14px;outline:none;font-family:inherit;box-sizing:border-box
  }
  .form-grid input:focus,.form-grid select:focus,.form-grid textarea:focus{border-color:#35E8D5}

  .target-radio{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px}
  .target-radio label{display:flex;align-items:center;gap:8px;padding:11px 14px;border:1.5px solid #E5E7EB;
                      border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;color:#374151;
                      transition:all .2s}
  .target-radio label:has(input:checked){border-color:#35E8D5;background:#F0FDFB;color:#0F1C3A}
  .target-radio input{width:auto;margin:0}

  .alert{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:12px}
  .alert.error{background:#FEE2E2;color:#991B1B;border:1px solid #FECACA}
  .alert.success{background:#D1FAE5;color:#065F46;border:1px solid #6EE7B7}
  .alert.warn{background:#FEF3C7;color:#92400E;border:1px solid #FDE68A}

  @media(max-width:780px){
    .pm-card{grid-template-columns:1fr;gap:6px}
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
    <a href="/ERP/harpy/hq/karyawan.php">👥 Karyawan</a>
    <a href="/ERP/harpy/hq/pelanggan.php">🧑‍🤝‍🧑 Pelanggan</a>
    <a href="/ERP/harpy/hq/promo.php" class="active">🎟️ Promo</a>
    <a href="/ERP/harpy/hq/laporan.php">📈 Laporan</a>
    <a href="/ERP/harpy/hq/settings.php">⚙️ Settings</a>
    <span><?= htmlspecialchars($ownerNama) ?></span>
    <a href="/ERP/harpy/dashboard.php?to=outlet">← Outlet View</a>
    <a href="/ERP/harpy/logout.php" class="hq-logout" onclick="return confirm('Yakin logout?')">Logout</a>
  </div>
</div>

<div class="container">
  <?php if (!$migrationOk): ?>
  <div class="alert warn" style="margin-bottom:14px">
    ⚠️ <strong>Migration SQL belum dijalankan.</strong>
    Jalankan SQL <code>CREATE TABLE hl_promo_outlets</code> di phpMyAdmin sebelum pakai fitur promo HQ.
  </div>
  <?php endif; ?>

  <div class="header">
    <h1>🎟️ Promo Lintas Outlet
      <small>Buat & kelola promo dari HQ · <?= htmlspecialchars($tenantNama) ?></small>
    </h1>
    <button class="btn btn-primary btn-big" onclick="openCreate()">+ Buat Promo HQ</button>
  </div>

  <div class="toolbar">
    <input type="search" id="searchInput" placeholder="🔍 Cari nama atau deskripsi promo…" oninput="loadList()">
    <span id="totalCount" style="font-size:12px;color:#6B7280;font-weight:600"></span>
  </div>

  <div class="pm-grid" id="promoGrid">
    <div class="empty"><div class="ico">⏳</div><p>Memuat…</p></div>
  </div>
</div>

<!-- Create/Edit Modal -->
<div class="modal-backdrop" id="formModal" onclick="if(event.target===this)closeModal('formModal')">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="formTitle">+ Buat Promo HQ</div>
      <button class="modal-close" onclick="closeModal('formModal')">×</button>
    </div>
    <div id="formAlert"></div>
    <div class="form-grid">
      <input type="hidden" id="fId">
      <div>
        <label>Nama Promo <span style="color:#EF4444">*</span></label>
        <input type="text" id="fNama" maxlength="100" placeholder="cth: Promo Lebaran 20%">
      </div>
      <div>
        <label>Deskripsi</label>
        <textarea id="fDesk" rows="2" maxlength="500" placeholder="Detail promo, syarat, dll"></textarea>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div>
          <label>Tipe Diskon</label>
          <select id="fTipe">
            <option value="persen">Persen (%)</option>
            <option value="nominal">Nominal (Rp)</option>
            <option value="free_item">Item Gratis</option>
          </select>
        </div>
        <div>
          <label>Nilai <span style="color:#EF4444">*</span></label>
          <input type="number" id="fNilai" min="0" step="0.01" placeholder="20">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div>
          <label>Min Transaksi (Rp)</label>
          <input type="number" id="fMin" min="0" placeholder="0">
        </div>
        <div>
          <label>Maks Diskon (Rp)</label>
          <input type="number" id="fMaks" min="0" placeholder="0 = no limit">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div><label>Berlaku Dari</label><input type="date" id="fDari"></div>
        <div><label>Berlaku Sampai</label><input type="date" id="fSampai"></div>
      </div>
      <div>
        <label>Kuota Pemakaian <span style="font-weight:400;color:#9CA3AF">(0 = unlimited)</span></label>
        <input type="number" id="fKuota" min="0" placeholder="0">
      </div>

      <div>
        <label style="margin-bottom:8px">🎯 Target Outlet</label>
        <div class="target-radio">
          <label>
            <input type="radio" name="targetMode" value="all" checked onchange="toggleTarget()">
            <span>📍 Semua Outlet</span>
          </label>
          <label>
            <input type="radio" name="targetMode" value="specific" onchange="toggleTarget()">
            <span>✓ Pilih Outlet Tertentu</span>
          </label>
        </div>
        <div id="outletPicker" style="display:none;background:#F9FAFB;border:1.5px solid #E5E7EB;
             border-radius:8px;padding:10px;max-height:160px;overflow-y:auto"></div>
      </div>

      <div>
        <label style="display:flex;align-items:center;gap:8px;font-weight:600;cursor:pointer">
          <input type="checkbox" id="fActive" checked style="width:auto;margin:0">
          Aktifkan promo
        </label>
      </div>
      <button class="btn btn-primary" style="padding:12px;font-size:14px" onclick="submitForm()">
        💾 Simpan Promo
      </button>
    </div>
  </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
let allOutletsCache = [];

function escapeHtml(s){return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function fmtRp(n){return 'Rp ' + Number(n||0).toLocaleString('id-ID')}
function fmtDate(s){if(!s)return '-';const d=new Date(s);return d.toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}

function nilaiText(p){
  if (p.tipe === 'persen') return p.nilai + '%';
  if (p.tipe === 'nominal') return fmtRp(p.nilai);
  return 'Item Gratis';
}

function periodeText(p){
  if (!p.berlaku_dari && !p.berlaku_sampai) return 'Tanpa batas waktu';
  return (p.berlaku_dari?fmtDate(p.berlaku_dari):'-') + ' → ' + (p.berlaku_sampai?fmtDate(p.berlaku_sampai):'-');
}

async function loadList(){
  const q = document.getElementById('searchInput').value;
  const r = await fetch('hq/promo.php?action=list&q=' + encodeURIComponent(q));
  const rows = await r.json();
  if (rows.error) { document.getElementById('promoGrid').innerHTML =
    `<div class="alert error">${escapeHtml(rows.error)}</div>`; return; }

  document.getElementById('totalCount').textContent = rows.length + ' promo';
  const grid = document.getElementById('promoGrid');

  if (rows.length === 0) {
    grid.innerHTML = '<div class="empty"><div class="ico">🎟️</div><p>Belum ada promo. Klik <strong>+ Buat Promo HQ</strong> untuk mulai.</p></div>';
    return;
  }

  grid.innerHTML = rows.map(r => {
    const scopeBadge = r.scope === 'account'
      ? '<span class="pm-scope scope-account">🏢 HQ</span>'
      : '<span class="pm-scope scope-outlet">📍 OUTLET</span>';

    let target;
    if (r.scope === 'outlet') {
      target = `<strong>📍 ${escapeHtml(r.source_outlet_name || '?')}</strong><span style="font-size:11px;color:#9CA3AF">Dibuat di outlet view</span>`;
    } else if (r.target_all) {
      target = `<strong>🌐 Semua Outlet</strong><span class="target-tag target-all">SEMUA</span>`;
    } else if (r.target_outlets.length > 0) {
      target = `<strong>${r.target_outlets.length} outlet</strong>` +
        r.target_outlets.map(o => `<span class="target-tag">📍 ${escapeHtml(o.nama_outlet || '?')}</span>`).join('');
    } else {
      target = `<strong style="color:#9CA3AF">Belum ada target</strong>`;
    }

    const editBtn = r.scope === 'account'
      ? `<button class="btn btn-light" onclick="openEdit(${r.id})">✏️ Edit</button>
         <button class="btn btn-danger" onclick="deletePromo(${r.id},'${escapeHtml(r.nama)}')">🗑️</button>`
      : `<span style="font-size:11px;color:#9CA3AF;padding:6px 0">Edit di outlet view</span>`;

    return `
      <div class="pm-card ${r.is_active==0?'inactive':''}">
        <div>
          <div class="pm-name">${escapeHtml(r.nama)}
            <small>${escapeHtml(r.deskripsi || periodeText(r))}</small>
          </div>
          ${scopeBadge}
          ${r.is_active==0 ? '<span class="pm-scope" style="background:#F3F4F6;color:#6B7280;margin-left:5px">NON-AKTIF</span>' : ''}
        </div>
        <div class="pm-target">${target}</div>
        <div class="pm-nilai">${nilaiText(r)}<small>${r.tipe}</small></div>
        <div class="pm-actions">${editBtn}</div>
      </div>
    `;
  }).join('');
}

function openCreate(){
  document.getElementById('formTitle').textContent = '+ Buat Promo HQ';
  document.getElementById('fId').value = '';
  document.getElementById('fNama').value = '';
  document.getElementById('fDesk').value = '';
  document.getElementById('fTipe').value = 'persen';
  document.getElementById('fNilai').value = '';
  document.getElementById('fMin').value = '';
  document.getElementById('fMaks').value = '';
  document.getElementById('fDari').value = '';
  document.getElementById('fSampai').value = '';
  document.getElementById('fKuota').value = '';
  document.getElementById('fActive').checked = true;
  document.querySelector('input[name="targetMode"][value="all"]').checked = true;
  document.getElementById('formAlert').innerHTML = '';
  loadOutletPicker([]);
  toggleTarget();
  openModal('formModal');
}

async function openEdit(id){
  const r = await fetch('hq/promo.php?action=detail&id=' + id);
  const d = await r.json();
  if (d.error) { alert(d.error); return; }
  const p = d.promo;

  document.getElementById('formTitle').textContent = '✏️ Edit Promo: ' + p.nama;
  document.getElementById('fId').value = p.id;
  document.getElementById('fNama').value = p.nama;
  document.getElementById('fDesk').value = p.deskripsi || '';
  document.getElementById('fTipe').value = p.tipe;
  document.getElementById('fNilai').value = p.nilai;
  document.getElementById('fMin').value = p.min_transaksi || 0;
  document.getElementById('fMaks').value = p.maks_diskon || 0;
  document.getElementById('fDari').value = p.berlaku_dari || '';
  document.getElementById('fSampai').value = p.berlaku_sampai || '';
  document.getElementById('fKuota').value = p.kuota || 0;
  document.getElementById('fActive').checked = p.is_active == 1;

  // Set target mode
  const hasAll = d.assigned.includes(0);
  const hasSpecific = d.assigned.filter(o => o > 0).length > 0;
  if (hasAll || d.assigned.length === 0) {
    document.querySelector('input[name="targetMode"][value="all"]').checked = true;
  } else {
    document.querySelector('input[name="targetMode"][value="specific"]').checked = true;
  }

  allOutletsCache = d.all_outlets;
  loadOutletPicker(d.assigned.filter(o => o > 0));
  toggleTarget();
  document.getElementById('formAlert').innerHTML = '';
  openModal('formModal');
}

async function loadOutletPicker(checkedIds){
  if (allOutletsCache.length === 0) {
    // First load
    try {
      const r = await fetch('hq/promo.php?action=detail&id=0');
      // ignore - this won't work
    } catch {}
  }
  if (allOutletsCache.length === 0) {
    // Fetch via list of outlets from any detail call ... simpler: do separate call
    // We'll embed in PHP next reload — for now, fallback to listing from detail of first promo
  }
  const picker = document.getElementById('outletPicker');
  if (allOutletsCache.length === 0) {
    picker.innerHTML = '<div style="color:#9CA3AF;font-size:12px">Memuat daftar outlet…</div>';
    // Force load by calling list (we'll re-trigger)
    await loadAllOutlets();
  }
  picker.innerHTML = allOutletsCache.length === 0
    ? '<div style="color:#9CA3AF;font-size:12px">Belum ada outlet aktif.</div>'
    : allOutletsCache.map(o => `
        <label style="display:flex;align-items:center;gap:8px;padding:5px 0;cursor:pointer;font-size:13px;color:#374151">
          <input type="checkbox" class="picker-cb" value="${o.id}" ${checkedIds.includes(o.id)?'checked':''} style="width:auto;margin:0">
          📍 ${escapeHtml(o.nama_outlet)}
        </label>
      `).join('');
}

async function loadAllOutlets(){
  if (allOutletsCache.length) return;
  // Hack: pakai endpoint detail untuk dapat all_outlets list
  // Atau: kita tambahkan endpoint khusus. Untuk sekarang, panggil list dan ambil dari mana saja.
  // Solusi cepat: query langsung via PHP — kita embed di bawah
}

function toggleTarget(){
  const mode = document.querySelector('input[name="targetMode"]:checked').value;
  document.getElementById('outletPicker').style.display = mode === 'specific' ? 'block' : 'none';
}

async function submitForm(){
  const alertEl = document.getElementById('formAlert');
  alertEl.innerHTML = '';
  const targetMode = document.querySelector('input[name="targetMode"]:checked').value;
  const data = {
    id: document.getElementById('fId').value,
    nama: document.getElementById('fNama').value.trim(),
    deskripsi: document.getElementById('fDesk').value.trim(),
    tipe: document.getElementById('fTipe').value,
    nilai: parseFloat(document.getElementById('fNilai').value),
    min_transaksi: parseFloat(document.getElementById('fMin').value || 0),
    maks_diskon: parseFloat(document.getElementById('fMaks').value || 0),
    berlaku_dari: document.getElementById('fDari').value,
    berlaku_sampai: document.getElementById('fSampai').value,
    kuota: parseInt(document.getElementById('fKuota').value || 0),
    is_active: document.getElementById('fActive').checked ? 1 : 0,
    target_mode: targetMode,
    target_outlets: Array.from(document.querySelectorAll('.picker-cb:checked')).map(c => parseInt(c.value)),
  };

  if (!data.nama)     { alertEl.innerHTML = '<div class="alert error">Nama wajib diisi</div>'; return; }
  if (!(data.nilai > 0)) { alertEl.innerHTML = '<div class="alert error">Nilai harus lebih dari 0</div>'; return; }
  if (targetMode === 'specific' && data.target_outlets.length === 0) {
    alertEl.innerHTML = '<div class="alert error">Pilih minimal 1 outlet target</div>'; return;
  }

  const r = await fetch('hq/promo.php?action=save', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
    body: JSON.stringify(data),
  });
  const j = await r.json();
  if (j.error) { alertEl.innerHTML = '<div class="alert error">'+escapeHtml(j.error)+'</div>'; return; }
  alertEl.innerHTML = '<div class="alert success">✓ Tersimpan</div>';
  setTimeout(() => { closeModal('formModal'); loadList(); }, 700);
}

async function deletePromo(id, nama){
  if (!confirm(`Non-aktifkan promo "${nama}"?\n(Promo akan tetap di history, tidak terhapus permanen)`)) return;
  const r = await fetch('hq/promo.php?action=delete', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
    body: JSON.stringify({id}),
  });
  const j = await r.json();
  if (j.error) { alert(j.error); return; }
  loadList();
}

function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}

// Pre-load semua outlet untuk picker
(async () => {
  // Trigger via detail action di promo dummy — atau lebih simple, embed di PHP
  // Embed langsung dari PHP:
  allOutletsCache = <?php
    try {
        $oStmt = $db->prepare("SELECT id, nama_outlet, status FROM outlets
                                WHERE tenant_id=? AND status IN ('trial','grace','active')
                                ORDER BY is_main DESC, nama_outlet ASC");
        $oStmt->execute([$tid]);
        echo json_encode($oStmt->fetchAll());
    } catch (Throwable) { echo '[]'; }
  ?>;
})();

loadList();
</script>
</body>
</html>
