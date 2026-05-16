<?php
// ══════════════════════════════════════════════════════
// hq/pelanggan.php — Database Pelanggan Lintas Outlet (HQ View)
// Brief HQ-Outlet Section 4.3
//
// Fitur:
//   - List semua pelanggan tenant + total visit & order
//   - Search by nama / HP / alamat
//   - Detail: info pelanggan + riwayat order lintas outlet
//   - Edit info pelanggan
// ══════════════════════════════════════════════════════

$activePage = 'hq-pelanggan';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];
$uid  = (int)($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');

    if ($action === 'list') {
        $q = trim($_GET['q'] ?? '');
        $params = [$tid];
        $whereExtra = '';
        if ($q !== '') {
            $whereExtra = " AND (p.nama LIKE ? OR p.telepon LIKE ? OR p.alamat LIKE ?)";
            $like = "%$q%";
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        try {
            $stmt = $db->prepare(
                "SELECT p.id, p.nama, p.telepon, p.alamat, p.tipe, p.is_active,
                        p.total_order, p.total_visit_count, p.registered_outlet_id,
                        p.created_at,
                        (SELECT nama_outlet FROM outlets WHERE id=p.registered_outlet_id) AS registered_outlet_name,
                        (SELECT MAX(tanggal) FROM hl_transaksi t
                          WHERE t.tenant_id=p.tenant_id AND t.pelanggan_id=p.id) AS last_order_at,
                        (SELECT COALESCE(SUM(total),0) FROM hl_transaksi t
                          WHERE t.tenant_id=p.tenant_id AND t.pelanggan_id=p.id) AS total_spend
                   FROM hl_pelanggan p
                  WHERE p.tenant_id = ? $whereExtra
                  ORDER BY p.total_visit_count DESC, p.nama ASC
                  LIMIT 200"
            );
            $stmt->execute($params);
            echo json_encode($stmt->fetchAll());
        } catch (Throwable $e) {
            // Fallback kalau kolom registered_outlet_id / total_visit_count belum ada
            error_log('[hq pelanggan list] '.$e->getMessage());
            $stmt = $db->prepare(
                "SELECT p.id, p.nama, p.telepon, p.alamat, p.tipe, p.is_active,
                        p.total_order, p.created_at
                   FROM hl_pelanggan p
                  WHERE p.tenant_id = ? $whereExtra
                  ORDER BY p.nama ASC LIMIT 200"
            );
            $stmt->execute($params);
            echo json_encode($stmt->fetchAll());
        }
        exit;
    }

    if ($action === 'detail') {
        $pid = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM hl_pelanggan WHERE id=? AND tenant_id=? LIMIT 1");
        $stmt->execute([$pid, $tid]);
        $p = $stmt->fetch();
        if (!$p) { echo json_encode(['error'=>'Pelanggan tidak ditemukan']); exit; }

        // Outlet registrasi
        if (!empty($p['registered_outlet_id'])) {
            $rOut = $db->prepare("SELECT nama_outlet FROM outlets WHERE id=?");
            $rOut->execute([$p['registered_outlet_id']]);
            $p['registered_outlet_name'] = $rOut->fetchColumn();
        }

        // Riwayat order lintas outlet
        $orders = [];
        try {
            $oStmt = $db->prepare(
                "SELECT t.id, t.no_order, t.tanggal, t.total, t.status_bayar, t.status_proses,
                        t.outlet_id,
                        (SELECT nama_outlet FROM outlets WHERE id=t.outlet_id) AS nama_outlet
                   FROM hl_transaksi t
                  WHERE t.tenant_id=? AND t.pelanggan_id=?
                  ORDER BY t.tanggal DESC LIMIT 50"
            );
            $oStmt->execute([$tid, $pid]);
            $orders = $oStmt->fetchAll();
        } catch (Throwable) { /* table issue */ }

        // Breakdown per outlet
        $breakdown = [];
        try {
            $bStmt = $db->prepare(
                "SELECT t.outlet_id,
                        (SELECT nama_outlet FROM outlets WHERE id=t.outlet_id) AS nama_outlet,
                        COUNT(*) AS order_count,
                        COALESCE(SUM(t.total),0) AS total_spend
                   FROM hl_transaksi t
                  WHERE t.tenant_id=? AND t.pelanggan_id=?
                  GROUP BY t.outlet_id
                  ORDER BY total_spend DESC"
            );
            $bStmt->execute([$tid, $pid]);
            $breakdown = $bStmt->fetchAll();
        } catch (Throwable) { /* table issue */ }

        echo json_encode([
            'pelanggan' => $p,
            'orders'    => $orders,
            'breakdown' => $breakdown,
        ]);
        exit;
    }

    if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        verifyCsrf();
        $pid = (int)($d['id'] ?? 0);
        if (!$pid) { echo json_encode(['error'=>'ID invalid']); exit; }

        $nama    = substr(trim(strip_tags($d['nama'] ?? '')), 0, 100);
        $telepon = substr(preg_replace('/[^0-9+\-\s]/', '', $d['telepon'] ?? ''), 0, 20);
        $alamat  = substr(trim(strip_tags($d['alamat'] ?? '')), 0, 300);
        $catatan = substr(trim(strip_tags($d['catatan'] ?? '')), 0, 300);
        $tipe    = in_array($d['tipe'] ?? '', ['retail','b2b']) ? $d['tipe'] : 'retail';
        $isActive = (int)(!empty($d['is_active']) ? 1 : 0);

        if (!$nama) { echo json_encode(['error'=>'Nama wajib diisi']); exit; }

        // Cek duplicate telepon (kecuali pelanggan ini sendiri)
        if (!empty($telepon)) {
            $chk = $db->prepare("SELECT id FROM hl_pelanggan WHERE tenant_id=? AND telepon=? AND id!=? LIMIT 1");
            $chk->execute([$tid, $telepon, $pid]);
            if ($chk->fetchColumn()) {
                echo json_encode(['error'=>'Nomor HP sudah dipakai pelanggan lain']); exit;
            }
        }

        try {
            $db->prepare(
                "UPDATE hl_pelanggan
                    SET nama=?, telepon=?, alamat=?, catatan=?, tipe=?, is_active=?
                  WHERE id=? AND tenant_id=?"
            )->execute([$nama, $telepon ?: null, $alamat ?: null, $catatan ?: null, $tipe, $isActive, $pid, $tid]);
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            error_log('[hq pelanggan update] '.$e->getMessage());
            echo json_encode(['error'=>'Gagal update: '.$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error'=>'Unknown action']); exit;
}

// Aggregate stats untuk header
$totalP = 0; $newP = 0;
try {
    $totalP = (int)$db->query("SELECT COUNT(*) FROM hl_pelanggan WHERE tenant_id=$tid AND is_active=1")->fetchColumn();
    $newP   = (int)$db->query("SELECT COUNT(*) FROM hl_pelanggan WHERE tenant_id=$tid AND DATE_FORMAT(created_at,'%Y-%m')='" . date('Y-m') . "'")->fetchColumn();
} catch (Throwable) {}

$ownerNama  = $hqUser['nama'] ?? 'Owner';
$tenantNama = $hqTenant['nama_outlet'] ?? 'HQ';
$csrf       = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HQ Pelanggan — LAMASY</title>
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

  .stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px}
  .stat-card{background:#fff;border-radius:12px;padding:16px 18px;box-shadow:0 1px 6px rgba(0,0,0,.05);border-top:3px solid #35E8D5}
  .stat-num{font-size:1.5rem;font-weight:800;color:#0F1C3A;font-family:monospace}
  .stat-label{font-size:12px;color:#6B7280;font-weight:600}

  .toolbar{background:#fff;border-radius:12px;padding:14px 18px;display:flex;gap:10px;align-items:center;
           flex-wrap:wrap;margin-bottom:16px;box-shadow:0 1px 6px rgba(0,0,0,.05)}
  .toolbar input{flex:1;min-width:200px;padding:9px 14px;border:1.5px solid #E5E7EB;border-radius:8px;font-size:14px;outline:none}
  .toolbar input:focus{border-color:#35E8D5}

  .pl-grid{display:grid;grid-template-columns:1fr;gap:8px}
  .pl-card{background:#fff;border-radius:10px;padding:13px 16px;display:grid;
           grid-template-columns:2fr 1.5fr 1fr 1fr auto;gap:14px;align-items:center;
           box-shadow:0 1px 4px rgba(0,0,0,.04);transition:box-shadow .2s;cursor:pointer}
  .pl-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);background:#FAFBFC}
  .pl-name{font-weight:700;color:#0F1C3A;font-size:14px}
  .pl-name small{display:block;color:#6B7280;font-weight:400;font-size:12px;margin-top:2px}
  .pl-tipe{font-size:9px;font-weight:700;padding:2px 7px;border-radius:100px;text-transform:uppercase;display:inline-block;margin-top:3px}
  .tipe-retail{background:#DBEAFE;color:#1E40AF}
  .tipe-b2b{background:#FED7AA;color:#9A3412}
  .pl-outlet{font-size:11px;color:#6B7280}
  .pl-outlet strong{color:#0F1C3A;display:block;font-size:12px}
  .pl-num{font-family:monospace;font-weight:700;color:#0F1C3A;font-size:13px;text-align:right}
  .pl-num small{display:block;color:#9CA3AF;font-weight:400;font-size:10px;text-transform:uppercase}
  .pl-actions{font-size:12px;color:#0891B2;font-weight:700}

  .empty{text-align:center;padding:48px 20px;color:#9CA3AF;background:#fff;border-radius:12px}
  .empty .ico{font-size:48px;margin-bottom:10px}

  /* Modal */
  .modal-backdrop{position:fixed;inset:0;background:rgba(15,28,58,.75);z-index:999;display:none;
                  align-items:center;justify-content:center;padding:20px}
  .modal-backdrop.open{display:flex}
  .modal{background:#fff;border-radius:14px;max-width:720px;width:100%;max-height:90vh;overflow:auto;
         padding:24px 26px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
  .modal-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px}
  .modal-title{font-size:1.1rem;font-weight:800;color:#0F1C3A}
  .modal-close{background:none;border:none;font-size:24px;cursor:pointer;color:#9CA3AF;line-height:1}
  .modal-close:hover{color:#0F1C3A}
  .section{margin-bottom:18px}
  .section-label{font-size:11px;color:#6B7280;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px}
  .info-row{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #F3F4F6;font-size:13px}
  .info-row:last-child{border-bottom:none}
  .info-row .lbl{color:#6B7280}
  .info-row .val{font-weight:600;color:#0F1C3A}

  .order-item{display:grid;grid-template-columns:1fr 1fr auto;gap:8px;padding:9px 13px;background:#F9FAFB;
              border-radius:6px;margin-bottom:4px;font-size:12px;align-items:center}
  .order-item .ono{font-weight:700;color:#0F1C3A}
  .order-item .meta{color:#6B7280}
  .order-item .amt{font-family:monospace;font-weight:700;color:#0F1C3A;text-align:right}
  .order-outlet-tag{background:#F0FDFB;color:#0891B2;font-size:10px;font-weight:700;padding:1px 6px;
                    border-radius:4px;margin-left:5px}

  .bd-row{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid #F3F4F6;font-size:13px}
  .bd-row strong{color:#0F1C3A}
  .bd-row .bd-money{font-family:monospace;font-weight:700;color:#0F1C3A}

  .btn{padding:8px 14px;border-radius:8px;font-weight:700;font-size:13px;border:none;cursor:pointer;
       font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:5px}
  .btn-primary{background:#35E8D5;color:#0F1C3A}
  .btn-light{background:#F3F4F6;color:#0F1C3A}

  .form-grid{display:grid;gap:12px}
  .form-grid label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:4px}
  .form-grid select,.form-grid input,.form-grid textarea{
    width:100%;padding:9px 12px;border:1.5px solid #E5E7EB;border-radius:8px;
    font-size:14px;outline:none;font-family:inherit;box-sizing:border-box
  }
  .form-grid select:focus,.form-grid input:focus,.form-grid textarea:focus{border-color:#35E8D5}
  .alert{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:12px}
  .alert.error{background:#FEE2E2;color:#991B1B;border:1px solid #FECACA}
  .alert.success{background:#D1FAE5;color:#065F46;border:1px solid #6EE7B7}

  @media(max-width:780px){
    .pl-card{grid-template-columns:1fr;gap:6px}
    .stats{grid-template-columns:repeat(2,1fr)}
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
    <a href="/ERP/harpy/hq/pelanggan.php" class="active">🧑‍🤝‍🧑 Pelanggan</a>
    <a href="/ERP/harpy/hq/promo.php">🎟️ Promo</a>
    <a href="/ERP/harpy/hq/laporan.php">📈 Laporan</a>
    <span><?= htmlspecialchars($ownerNama) ?></span>
    <a href="/ERP/harpy/dashboard.php?to=outlet">← Outlet View</a>
    <a href="/ERP/harpy/logout.php" class="hq-logout" onclick="return confirm('Yakin logout?')">Logout</a>
  </div>
</div>

<div class="container">
  <div class="header">
    <h1>🧑‍🤝‍🧑 Database Pelanggan
      <small>Lintas outlet · <?= htmlspecialchars($tenantNama) ?></small>
    </h1>
  </div>

  <div class="stats">
    <div class="stat-card">
      <div class="stat-num"><?= number_format($totalP) ?></div>
      <div class="stat-label">Total Pelanggan Aktif</div>
    </div>
    <div class="stat-card" style="border-top-color:#34D399">
      <div class="stat-num"><?= number_format($newP) ?></div>
      <div class="stat-label">Baru Bulan Ini</div>
    </div>
    <div class="stat-card" style="border-top-color:#F59E0B">
      <div class="stat-num" id="topCustomer">-</div>
      <div class="stat-label">Top Customer (Visit)</div>
    </div>
  </div>

  <div class="toolbar">
    <input type="search" id="searchInput" placeholder="🔍 Cari nama, nomor HP, alamat…" oninput="loadList()">
    <span id="totalCount" style="font-size:12px;color:#6B7280;font-weight:600"></span>
  </div>

  <div class="pl-grid" id="pelangganGrid">
    <div class="empty"><div class="ico">⏳</div><p>Memuat…</p></div>
  </div>
</div>

<!-- Detail Modal -->
<div class="modal-backdrop" id="detailModal" onclick="if(event.target===this)closeModal('detailModal')">
  <div class="modal" id="detailContent"></div>
</div>

<!-- Edit Modal -->
<div class="modal-backdrop" id="editModal" onclick="if(event.target===this)closeModal('editModal')">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <div class="modal-title">✏️ Edit Pelanggan</div>
      <button class="modal-close" onclick="closeModal('editModal')">×</button>
    </div>
    <div id="editAlert"></div>
    <div class="form-grid">
      <input type="hidden" id="edId">
      <div><label>Nama</label><input type="text" id="edNama" maxlength="100"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div><label>Telepon</label><input type="tel" id="edTelepon" maxlength="20"></div>
        <div>
          <label>Tipe</label>
          <select id="edTipe">
            <option value="retail">Retail</option>
            <option value="b2b">B2B / Korporat</option>
          </select>
        </div>
      </div>
      <div><label>Alamat</label><textarea id="edAlamat" rows="2" maxlength="300"></textarea></div>
      <div><label>Catatan</label><textarea id="edCatatan" rows="2" maxlength="300" placeholder="Preferensi, alergi, dll…"></textarea></div>
      <div>
        <label style="display:flex;align-items:center;gap:8px;font-weight:600;cursor:pointer">
          <input type="checkbox" id="edActive" style="width:auto;margin:0"> Aktif
        </label>
      </div>
      <button class="btn btn-primary" style="padding:12px;font-size:14px" onclick="submitEdit()">
        💾 Simpan Perubahan
      </button>
    </div>
  </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
function escapeHtml(s){return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function fmtRp(n){return 'Rp ' + Number(n||0).toLocaleString('id-ID')}
function fmtDate(s){if(!s)return '-';const d=new Date(s);return d.toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}

async function loadList(){
  const q = document.getElementById('searchInput').value;
  const r = await fetch('hq/pelanggan.php?action=list&q=' + encodeURIComponent(q));
  const rows = await r.json();
  document.getElementById('totalCount').textContent = rows.length + ' pelanggan';
  if (rows.length) {
    document.getElementById('topCustomer').textContent = rows[0].total_visit_count
      ? (rows[0].total_visit_count + 'x') : '-';
  } else {
    document.getElementById('topCustomer').textContent = '-';
  }

  const grid = document.getElementById('pelangganGrid');
  if (rows.length === 0) {
    grid.innerHTML = '<div class="empty"><div class="ico">🧑‍🤝‍🧑</div><p>Belum ada pelanggan' +
      (q?' yang cocok':'') + '</p></div>';
    return;
  }

  grid.innerHTML = rows.map(r => `
    <div class="pl-card" onclick="showDetail(${r.id})">
      <div>
        <div class="pl-name">${escapeHtml(r.nama)}
          <small>${r.telepon ? '📞 '+escapeHtml(r.telepon) : '(tanpa HP)'}</small>
        </div>
        <span class="pl-tipe tipe-${r.tipe||'retail'}">${r.tipe||'retail'}</span>
      </div>
      <div class="pl-outlet">
        <strong>${r.registered_outlet_name ? '📍 '+escapeHtml(r.registered_outlet_name) : '<span style="color:#9CA3AF">Outlet tidak diketahui</span>'}</strong>
        Outlet pertama daftar
      </div>
      <div class="pl-num">${r.total_visit_count || r.total_order || 0}<small>Visit</small></div>
      <div class="pl-num">${fmtRp(r.total_spend || 0)}<small>Total Belanja</small></div>
      <div class="pl-actions">Detail →</div>
    </div>
  `).join('');
}

async function showDetail(id){
  const r = await fetch('hq/pelanggan.php?action=detail&id=' + id);
  const d = await r.json();
  if (d.error) { alert(d.error); return; }
  const p = d.pelanggan;
  const orders = d.orders || [];
  const breakdown = d.breakdown || [];

  const breakdownHtml = breakdown.length === 0
    ? '<div style="color:#9CA3AF;font-size:13px;font-style:italic">Belum ada transaksi.</div>'
    : breakdown.map(b => `
        <div class="bd-row">
          <span>📍 <strong>${escapeHtml(b.nama_outlet)}</strong> · ${b.order_count} order</span>
          <span class="bd-money">${fmtRp(b.total_spend)}</span>
        </div>
      `).join('');

  const ordersHtml = orders.length === 0
    ? '<div style="color:#9CA3AF;font-size:13px;font-style:italic">Belum ada riwayat order.</div>'
    : orders.map(o => `
        <div class="order-item">
          <div>
            <span class="ono">${escapeHtml(o.no_order)}</span>
            <span class="order-outlet-tag">${escapeHtml(o.nama_outlet || '?')}</span>
          </div>
          <div class="meta">${fmtDate(o.tanggal)} · ${escapeHtml(o.status_bayar)}</div>
          <div class="amt">${fmtRp(o.total)}</div>
        </div>
      `).join('');

  document.getElementById('detailContent').innerHTML = `
    <div class="modal-header">
      <div>
        <div class="modal-title">${escapeHtml(p.nama)}</div>
        <div style="font-size:12px;color:#6B7280;margin-top:3px">
          ${p.telepon ? '📞 '+escapeHtml(p.telepon)+' · ' : ''}
          <span class="pl-tipe tipe-${p.tipe||'retail'}">${p.tipe||'retail'}</span>
        </div>
      </div>
      <div style="display:flex;gap:6px">
        <button class="btn btn-light" onclick="openEdit(${p.id})">✏️ Edit</button>
        <button class="modal-close" onclick="closeModal('detailModal')">×</button>
      </div>
    </div>

    <div class="section">
      <div class="section-label">Info Pelanggan</div>
      <div class="info-row"><span class="lbl">Alamat</span><span class="val">${escapeHtml(p.alamat || '-')}</span></div>
      <div class="info-row"><span class="lbl">Catatan</span><span class="val">${escapeHtml(p.catatan || '-')}</span></div>
      <div class="info-row"><span class="lbl">Outlet pertama daftar</span><span class="val">${escapeHtml(p.registered_outlet_name || '-')}</span></div>
      <div class="info-row"><span class="lbl">Bergabung</span><span class="val">${fmtDate(p.created_at)}</span></div>
      <div class="info-row"><span class="lbl">Total Visit</span><span class="val">${p.total_visit_count || p.total_order || 0}x</span></div>
      <div class="info-row"><span class="lbl">Status</span><span class="val">${p.is_active==1?'✓ Aktif':'⚠️ Non-aktif'}</span></div>
    </div>

    <div class="section">
      <div class="section-label">📍 Breakdown per Outlet</div>
      ${breakdownHtml}
    </div>

    <div class="section">
      <div class="section-label">📋 Riwayat Order (50 terakhir)</div>
      ${ordersHtml}
    </div>
  `;
  openModal('detailModal');
}

async function openEdit(id){
  closeModal('detailModal');
  const r = await fetch('hq/pelanggan.php?action=detail&id=' + id);
  const d = await r.json();
  if (d.error) { alert(d.error); return; }
  const p = d.pelanggan;
  document.getElementById('edId').value = p.id;
  document.getElementById('edNama').value = p.nama || '';
  document.getElementById('edTelepon').value = p.telepon || '';
  document.getElementById('edAlamat').value = p.alamat || '';
  document.getElementById('edCatatan').value = p.catatan || '';
  document.getElementById('edTipe').value = p.tipe || 'retail';
  document.getElementById('edActive').checked = p.is_active == 1;
  document.getElementById('editAlert').innerHTML = '';
  openModal('editModal');
}

async function submitEdit(){
  const alertEl = document.getElementById('editAlert');
  alertEl.innerHTML = '';
  const data = {
    id: document.getElementById('edId').value,
    nama: document.getElementById('edNama').value.trim(),
    telepon: document.getElementById('edTelepon').value.trim(),
    alamat: document.getElementById('edAlamat').value.trim(),
    catatan: document.getElementById('edCatatan').value.trim(),
    tipe: document.getElementById('edTipe').value,
    is_active: document.getElementById('edActive').checked ? 1 : 0,
  };
  if (!data.nama) { alertEl.innerHTML = '<div class="alert error">Nama wajib diisi</div>'; return; }

  const r = await fetch('hq/pelanggan.php?action=update', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
    body: JSON.stringify(data),
  });
  const j = await r.json();
  if (j.error) { alertEl.innerHTML = '<div class="alert error">'+escapeHtml(j.error)+'</div>'; return; }
  alertEl.innerHTML = '<div class="alert success">✓ Tersimpan</div>';
  setTimeout(() => { closeModal('editModal'); loadList(); }, 700);
}

function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}

loadList();
</script>
</body>
</html>
