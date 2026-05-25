<?php
$activePage = 'customer';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
requirePermission('customer.view');

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $oid = TenantResolver::outletId();

    if ($action === 'list') {
        $q       = $_GET['q'] ?? '';
        $tipe    = $_GET['tipe'] ?? '';
        $segmen  = $_GET['segmen'] ?? '';
        $tier    = $_GET['tier'] ?? '';
        $page    = max(1, intval($_GET['page'] ?? 1));
        $limit   = 24;
        $offset  = ($page - 1) * $limit;

        $where = ['p.tenant_id = ?', 'p.outlet_id = ?']; $params = [$tid, $oid];
        if ($q) { $where[] = '(p.nama LIKE ? OR p.telepon LIKE ? OR p.alamat LIKE ?)'; $like="%$q%"; $params=array_merge($params,[$like,$like,$like]); }
        if ($tipe)   { $where[] = 'p.tipe=?';   $params[] = $tipe; }
        if ($segmen && in_array($segmen, ['baru','regular','vip','dormant'], true)) {
            $where[] = 'p.segmen=?'; $params[] = $segmen;
        }
        if ($tier && in_array($tier, ['regular','silver','gold','platinum'], true)) {
            $where[] = 'p.tier=?'; $params[] = $tier;
        }

        $whereStr = implode(' AND ', $where);

        $countRows = TenantQuery::raw("SELECT COUNT(DISTINCT p.id) as c FROM hl_pelanggan p WHERE $whereStr", $params);
        $total = intval($countRows[0]['c'] ?? 0);

        $dataParams = array_merge($params, []);
        $rows = TenantQuery::raw(
            "SELECT p.*,
                COUNT(t.id) as total_order,
                COALESCE(SUM(t.total),0) as total_omset,
                MAX(t.tanggal) as last_order
                FROM hl_pelanggan p
                LEFT JOIN hl_transaksi t ON t.pelanggan_id = p.id AND t.tenant_id = p.tenant_id AND t.outlet_id = p.outlet_id
                WHERE $whereStr
                GROUP BY p.id
                ORDER BY p.nama
                LIMIT {$limit} OFFSET {$offset}",
            $dataParams
        );

        echo json_encode([
            'data'        => $rows,
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => ceil($total / $limit),
        ]); exit;
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD']==='POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        if (!empty($d['id']) && !hasPermission('customer.edit')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        if (empty($d['id']) && !hasPermission('customer.create')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();

        $metodeBayar = $d['metode_bayar'] ?? 'langsung';
        $nama    = substr(trim(strip_tags($d['nama'] ?? '')), 0, 100);
        $telepon = substr(trim(strip_tags(preg_replace('/[^0-9+\-\s]/', '', $d['telepon'] ?? ''))), 0, 20);
        $alamat  = substr(trim(strip_tags($d['alamat'] ?? '')), 0, 300);
        $catatan = substr(trim(strip_tags($d['catatan'] ?? '')), 0, 300);
        $tipe    = in_array($d['tipe'] ?? '', ['retail','b2b']) ? $d['tipe'] : 'retail';

        if (!$nama) { echo json_encode(['error'=>'Nama wajib diisi']); exit; }

        if (!empty($d['id'])) {
            TenantQuery::update('hl_pelanggan', [
                'nama'        => $nama,
                'telepon'     => $telepon,
                'alamat'      => $alamat,
                'tipe'        => $tipe,
                'catatan'     => $catatan,
                'metode_bayar'=> $metodeBayar,
                'is_active'   => intval($d['is_active'] ?? 1),
            ], 'id = ?', [intval($d['id'])]);
        } else {
            // Cek duplikat telepon (TENANT-SCOPED — lintas outlet)
            // Sesuai brief: 1 nomor HP unique per tenant
            if (!empty($telepon)) {
                if (TenantQuery::exists('hl_pelanggan', 'telepon = ?', [$telepon])) {
                    echo json_encode(['error'=>'Nomor HP sudah terdaftar di akun ini (cek di outlet lain)']);
                    exit;
                }
            }
            $currentOid = TenantResolver::outletId();
            TenantQuery::insert('hl_pelanggan', [
                'nama'                 => $nama,
                'telepon'              => $telepon,
                'alamat'               => $alamat,
                'tipe'                 => $tipe,
                'catatan'              => $catatan,
                'metode_bayar'         => $metodeBayar,
                'registered_outlet_id' => $currentOid, // catat outlet pertama daftar
                'outlet_id'            => $currentOid, // legacy compat
            ]);
        }
        logAudit(!empty($d['id'])?'update':'create','customer',(!empty($d['id'])?'Edit':'Tambah').' customer: '.$nama);
        echo json_encode(['success'=>true]); exit;
    }

    if ($action === 'get_orders') {
        $id = intval($_GET['id']);
        $rows = TenantQuery::raw(
            "SELECT t.no_order,t.tanggal,t.total,t.status_proses,t.status_bayar,
                GROUP_CONCAT(i.nama_layanan SEPARATOR ', ') as layanan
                FROM hl_transaksi t
                LEFT JOIN hl_transaksi_item i ON i.transaksi_id=t.id AND i.tenant_id=t.tenant_id AND i.outlet_id=t.outlet_id
                WHERE t.tenant_id = ? AND t.outlet_id = ? AND t.pelanggan_id = ?
                GROUP BY t.id ORDER BY t.tanggal DESC LIMIT 20",
            [$tid, $oid, $id]
        );
        echo json_encode($rows); exit;
    }

    if ($action === 'stats') {
        $total = TenantQuery::count('hl_pelanggan', 'is_active=1');
        $b2b   = TenantQuery::count('hl_pelanggan', "tipe='b2b' AND is_active=1");
        $baru  = TenantQuery::count('hl_pelanggan', 'MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())');
        echo json_encode(['total'=>$total,'b2b'=>$b2b,'baru'=>$baru]); exit;
    }

    // Save preferensi pelanggan
    if ($action === 'save_preferensi' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hasPermission('customer.edit')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d  = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        if (!$id) { echo json_encode(['error'=>'id wajib']); exit; }
        try {
            Database::get()
                ->prepare("UPDATE hl_pelanggan
                              SET preferensi_parfum=?, preferensi_suhu=?, catatan_tetap=?
                            WHERE id=? AND tenant_id=?")
                ->execute([
                    substr(trim($d['parfum'] ?? ''), 0, 50),
                    substr(trim($d['suhu'] ?? ''), 0, 20),
                    substr(trim($d['catatan_tetap'] ?? ''), 0, 1000),
                    $id, $tid,
                ]);
            logAudit('update_preferensi', 'customer#'.$id, '');
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>$e->getMessage()]);
        }
        exit;
    }

    // Stats agregat per-segmen
    if ($action === 'segmen_stats') {
        require_once ROOT . '/core/SegmentasiManager.php';
        echo json_encode(['ok'=>true, 'stats'=>SegmentasiManager::stats($tid)]);
        exit;
    }

    // Force re-run segmentasi (untuk tombol manual refresh)
    if ($action === 'segmen_refresh' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hasPermission('customer.edit') && !hasPermission('customer.view')) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
        require_once ROOT . '/core/SegmentasiManager.php';
        $changed = SegmentasiManager::updateAll($tid, $oid, true);
        echo json_encode(['ok'=>true, 'changed'=>$changed]);
        exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Customer'); ?>
<style>
.cust-card{background:var(--white);border-radius:var(--r-lg);border:1px solid rgba(27,45,90,.07);padding:18px;transition:all .2s;cursor:pointer}
.cust-card:hover{box-shadow:var(--shadow-lg);transform:translateY(-1px);border-color:var(--teal)}
.cust-nama{font-size:15px;font-weight:700;color:var(--navy);margin-bottom:4px}
.cust-telp{font-size:13px;color:var(--gray);margin-bottom:8px}
.cust-stats{display:flex;gap:12px;font-size:12px;color:var(--gray)}
.cust-stat{display:flex;flex-direction:column;align-items:center}
.cust-stat strong{font-size:15px;font-weight:800;color:var(--navy);font-family:var(--mono)}

/* LIST VIEW */
.cust-list-item{background:var(--white);border-radius:var(--r-lg);border:1px solid rgba(27,45,90,.07);padding:14px 18px;display:flex;align-items:center;gap:16px;transition:all .2s;cursor:pointer}
.cust-list-item:hover{box-shadow:var(--shadow-lg);border-color:var(--teal)}
.cust-list-avatar{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,var(--navy),var(--teal-d));color:white;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;flex-shrink:0}
.cust-list-info{flex:1;min-width:0}
.cust-list-nama{font-size:14px;font-weight:700;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cust-list-telp{font-size:12px;color:var(--gray)}
.cust-list-stats{display:flex;gap:20px;flex-shrink:0}
.cust-list-stat{text-align:right;font-size:12px;color:var(--gray)}
.cust-list-stat strong{display:block;font-size:13px;font-weight:700;color:var(--navy);font-family:var(--mono)}

/* TOGGLE BTN */
.view-toggle{display:flex;gap:4px;background:var(--light);border-radius:8px;padding:3px}
.view-btn{padding:5px 10px;border-radius:6px;border:none;cursor:pointer;font-size:13px;background:transparent;transition:all .2s;color:var(--gray)}
.view-btn.active{background:var(--white);color:var(--navy);box-shadow:0 1px 4px rgba(27,45,90,.1)}
@media(max-width:680px){
  .cust-list-stats{gap:12px}
  .cust-list-stat strong{font-size:12px}
  .cust-card{padding:14px}
}
@media(max-width:400px){
  .cust-list-stats{display:none}
}
</style>
</head>
<body>
<?php renderTopbar('customer'); ?>
<div class="hl-main">

  <div class="hl-stat-grid-4" style="margin-bottom:20px">
    <div class="hl-stat-card teal"><div class="hl-stat-num" id="sTotal">-</div><div class="hl-stat-label">👥 Total Customer</div></div>
    <div class="hl-stat-card navy"><div class="hl-stat-num" id="sB2B">-</div><div class="hl-stat-label">🏢 B2B / Korporat</div></div>
    <div class="hl-stat-card green"><div class="hl-stat-num" id="sBaru">-</div><div class="hl-stat-label">✨ Baru Bulan Ini</div></div>
    <div class="hl-stat-card purple">
      <button class="hl-btn hl-btn-primary hl-btn-full" onclick="openModal()" style="margin-top:4px">+ Tambah Customer</button>
    </div>
  </div>

  <!-- SEGMEN STATS -->
  <div id="segmenStatsBar" style="display:none;background:#fff;border:1px solid rgba(27,45,90,.07);border-radius:12px;padding:10px 14px;margin-bottom:16px">
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <span style="font-size:12px;color:var(--gray);font-weight:700">SEGMEN:</span>
      <div class="seg-pill" data-seg="baru" onclick="filterSegmen('baru')" style="cursor:pointer;background:#DBEAFE;color:#1E40AF;padding:6px 11px;border-radius:8px;font-size:12px;font-weight:600">🆕 Baru <strong id="segCntBaru">0</strong></div>
      <div class="seg-pill" data-seg="regular" onclick="filterSegmen('regular')" style="cursor:pointer;background:#F1F5F9;color:#475569;padding:6px 11px;border-radius:8px;font-size:12px;font-weight:600">Regular <strong id="segCntRegular">0</strong></div>
      <div class="seg-pill" data-seg="vip" onclick="filterSegmen('vip')" style="cursor:pointer;background:#FEF3C7;color:#92400E;padding:6px 11px;border-radius:8px;font-size:12px;font-weight:600">⭐ VIP <strong id="segCntVip">0</strong></div>
      <div class="seg-pill" data-seg="dormant" onclick="filterSegmen('dormant')" style="cursor:pointer;background:#FEE2E2;color:#991B1B;padding:6px 11px;border-radius:8px;font-size:12px;font-weight:600">😴 Dormant <strong id="segCntDormant">0</strong></div>
      <span style="margin-left:auto;font-size:11px;color:var(--gray)">Update otomatis 1x/hari</span>
    </div>
  </div>

  <div class="hl-filter-collapsible">
    <button class="hl-filter-toggle-btn" id="custFilterBtn" onclick="toggleFilter('custFilter')">
      🔍 Filter &amp; Pencarian <span class="hl-filter-active-dot" id="custFilterDot"></span>
      <span class="hl-toggle-arrow">▼</span>
    </button>
    <div class="hl-filter-bar" id="custFilter">
      <input type="text" id="fSearch" class="hl-input" placeholder="Cari nama, telepon, alamat..." oninput="debounce()" style="flex:1;max-width:320px"/>
      <select id="fTipe" class="hl-input" style="width:auto" onchange="loadCustomer(1)">
        <option value="">Semua Tipe</option>
        <option value="retail">Retail</option>
        <option value="b2b">B2B / Korporat</option>
      </select>
      <select id="fSegmen" class="hl-input" style="width:auto" onchange="loadCustomer(1)">
        <option value="">Semua Segmen</option>
        <option value="baru">🆕 Baru</option>
        <option value="regular">Regular</option>
        <option value="vip">⭐ VIP</option>
        <option value="dormant">😴 Dormant</option>
      </select>
      <select id="fTier" class="hl-input" style="width:auto" onchange="loadCustomer(1)">
        <option value="">Semua Tier</option>
        <option value="silver">🥈 Silver</option>
        <option value="gold">🥇 Gold</option>
        <option value="platinum">💎 Platinum</option>
      </select>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadCustomer()">↻</button>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="refreshSegmen()" title="Re-hitung segmen sekarang">🔄 Segmen</button>
      <span id="custInfo" style="font-size:12px;color:var(--gray);margin-left:auto"></span>
      <div class="view-toggle">
        <button class="view-btn active" id="btnGrid" onclick="setView('grid')" title="Grid">⊞</button>
        <button class="view-btn" id="btnList" onclick="setView('list')" title="List">☰</button>
      </div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px" id="custGrid">
    <div class="hl-loading">⏳ Memuat...</div>
  </div>
</div>
<div id="custPaging"></div>

<!-- MODAL TAMBAH/EDIT -->
<div class="hl-modal-overlay" id="modalCust">
  <div class="hl-modal">
    <div class="hl-modal-header">
      <span class="hl-modal-title" id="custModalTitle">➕ Tambah Customer</span>
      <button class="hl-modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="hl-modal-body">
      <input type="hidden" id="f_id"/>
      <div class="hl-form-group">
        <label class="hl-label">Nama <span class="req">*</span></label>
        <input type="text" id="f_nama" class="hl-input" placeholder="Nama lengkap / perusahaan"/>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">No. Telepon</label>
          <input type="tel" id="f_telepon" class="hl-input" placeholder="08xxxxxxxxxx"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Tipe</label>
          <select id="f_tipe" class="hl-input">
            <option value="retail">Retail</option>
            <option value="b2b">B2B / Korporat</option>
          </select>
        </div>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Alamat</label>
        <textarea id="f_alamat" class="hl-input hl-textarea" placeholder="Alamat lengkap..."></textarea>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Catatan</label>
        <input type="text" id="f_catatan" class="hl-input" placeholder="Preferensi, info tambahan..."/>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Metode Pembayaran</label>
          <select id="f_metode_bayar" class="hl-input">
            <option value="langsung">Bayar Langsung</option>
            <option value="bulanan">Tagihan Bulanan</option>
          </select>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Status</label>
          <select id="f_active" class="hl-input">
            <option value="1">✅ Aktif</option>
            <option value="0">⏸️ Nonaktif</option>
          </select>
        </div>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeModal()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveCustomer()">💾 Simpan</button>
    </div>
  </div>
</div>

<!-- MODAL DETAIL CUSTOMER -->
<div class="hl-modal-overlay" id="modalDetail">
  <div class="hl-modal hl-modal-lg" style="max-height:90vh">
    <div class="hl-modal-header">
      <span class="hl-modal-title" id="detailTitle">Detail Customer</span>
      <button class="hl-modal-close" onclick="closeDetail()">✕</button>
    </div>
    <div class="hl-modal-body" id="detailBody"></div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeDetail()">Tutup</button>
      <button class="hl-btn hl-btn-primary" id="btnEditFromDetail" onclick="editFromDetail()">✏️ Edit</button>
    </div>
  </div>
</div>

<?php renderToast(); ?>
<script>
let allCustomer = [];
let searchTimer = null;
let currentDetailId = null;

document.addEventListener('DOMContentLoaded', () => { initFilter('custFilter'); loadCustomer(); loadStats(); loadSegmenStats(); });

async function loadStats() {
  const r = await fetch('customer.php?action=stats');
  const d = await r.json();
  document.getElementById('sTotal').textContent = d.total;
  document.getElementById('sB2B').textContent   = d.b2b;
  document.getElementById('sBaru').textContent  = d.baru;
}

async function loadSegmenStats() {
  try {
    const r = await fetch('customer.php?action=segmen_stats');
    const d = await r.json();
    if (!d.ok) return;
    const s = d.stats || {};
    document.getElementById('segCntBaru').textContent    = s.baru || 0;
    document.getElementById('segCntRegular').textContent = s.regular || 0;
    document.getElementById('segCntVip').textContent     = s.vip || 0;
    document.getElementById('segCntDormant').textContent = s.dormant || 0;
    document.getElementById('segmenStatsBar').style.display = 'block';
  } catch(e){}
}

function filterSegmen(seg) {
  document.getElementById('fSegmen').value = seg;
  loadCustomer(1);
}

async function refreshSegmen() {
  if (!confirm('Re-hitung segmen semua pelanggan sekarang?')) return;
  showToast('⏳ Menghitung segmen...', 'success');
  try {
    const r = await fetch('customer.php?action=segmen_refresh', {
      method:'POST', headers:{'X-CSRF-Token':csrfToken()}
    });
    const d = await r.json();
    if (d.error) { showToast(d.error, 'error'); return; }
    showToast('✓ Segmen di-update: ' + (d.changed || 0) + ' pelanggan berubah', 'success');
    loadCustomer(1); loadSegmenStats();
  } catch(e) { showToast('Network error', 'error'); }
}

// Helpers untuk badge
const TIER_BADGES   = {silver:'🥈',gold:'🥇',platinum:'💎'};
const SEGMEN_BADGES = {baru:['🆕','#1E40AF','#DBEAFE'], vip:['⭐','#92400E','#FEF3C7'], dormant:['😴','#991B1B','#FEE2E2']};
function tierBadge(t) {
  if (!t || !TIER_BADGES[t]) return '';
  return `<span style="font-size:11px;font-weight:600;color:#475569;margin-left:4px">${TIER_BADGES[t]} ${t}</span>`;
}
function segmenBadge(s) {
  if (!s || !SEGMEN_BADGES[s]) return '';
  const [emo, fg, bg] = SEGMEN_BADGES[s];
  return `<span style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:8px;background:${bg};color:${fg};margin-left:4px">${emo} ${s.toUpperCase()}</span>`;
}

let currentPage = 1;
let totalPages  = 1;
let totalCustomer = 0;

async function loadCustomer(page=1) {
  currentPage = page;
  const q      = document.getElementById('fSearch').value;
  const tipe   = document.getElementById('fTipe').value;
  const segmen = document.getElementById('fSegmen')?.value || '';
  const tier   = document.getElementById('fTier')?.value || '';
  // Skeleton 6 cards
  document.getElementById('custGrid').innerHTML = Array.from({length:6}).map(()=>`
    <div class="hl-skel-card" style="padding:18px">
      <span class="hl-skel lg" style="width:65%;display:block"></span>
      <span class="hl-skel" style="width:45%;display:block;margin-top:8px"></span>
      <div style="display:flex;gap:6px;margin-top:12px">
        <span class="hl-skel" style="width:70px"></span>
        <span class="hl-skel" style="width:50px"></span>
      </div>
      <div style="display:flex;gap:14px;margin-top:14px;padding-top:12px;border-top:1px solid var(--light)">
        <span class="hl-skel" style="width:30%"></span>
        <span class="hl-skel" style="width:30%"></span>
        <span class="hl-skel" style="width:30%"></span>
      </div>
    </div>`).join('');

  const r = await fetch(`customer.php?action=list&q=${encodeURIComponent(q)}&tipe=${tipe}&segmen=${segmen}&tier=${tier}&page=${page}`);
  const d = await r.json();
  allCustomer   = d.data;
  totalPages    = d.total_pages;
  totalCustomer = d.total;

  document.getElementById('custInfo').textContent = `${d.total} customer`;
  renderCustomer();
  renderPaging();
}

let currentView = 'grid';

function setView(view) {
  currentView = view;
  document.getElementById('btnGrid').classList.toggle('active', view==='grid');
  document.getElementById('btnList').classList.toggle('active', view==='list');
  const grid = document.getElementById('custGrid');
  if (view === 'grid') {
    grid.style.display = 'grid';
    grid.style.gridTemplateColumns = 'repeat(auto-fill,minmax(280px,1fr))';
    grid.style.flexDirection = '';
  } else {
    grid.style.display = 'flex';
    grid.style.flexDirection = 'column';
    grid.style.gridTemplateColumns = '';
  }
  renderCustomer();
}

function renderCustomer() {
  const grid = document.getElementById('custGrid');
  if (!allCustomer.length) {
    grid.innerHTML = `<div style="grid-column:1/-1"><div class="hl-empty-v2">
      <div class="e-icon">👥</div>
      <div class="e-title">Belum ada customer</div>
      <div class="e-sub">Tambahkan customer pertamamu atau cek filter pencarian</div>
      <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="openModal()">+ Tambah Customer</button>
    </div></div>`;
    return;
  }

  if (currentView === 'list') {
    grid.innerHTML = allCustomer.map(c => `
      <div class="cust-list-item" onclick="openDetail(${c.id})">
        <div class="cust-list-avatar">${esc(c.nama).charAt(0).toUpperCase()}</div>
        <div class="cust-list-info">
          <div class="cust-list-nama">${esc(c.nama)} ${tierBadge(c.tier)} ${segmenBadge(c.segmen)}</div>
          <div class="cust-list-telp">${c.telepon||'No telepon'} ·
            <span class="hl-badge ${c.tipe==='b2b'?'hl-badge-navy':'hl-badge-teal'}" style="font-size:10px">${c.tipe==='b2b'?'B2B':'Retail'}</span>
            ${c.metode_bayar==='bulanan'?'<span class="hl-badge" style="background:#FEF3C7;color:#92400E;font-size:10px;margin-left:4px">Bulanan</span>':''}
            ${parseInt(c.poin_balance||0) > 0 ? '<span class="hl-badge" style="background:#F0FDFB;color:#0F766E;font-size:10px;margin-left:4px">⭐ '+parseInt(c.poin_balance)+' poin</span>' : ''}
          </div>
        </div>
        <div class="cust-list-stats">
          <div class="cust-list-stat"><strong>${c.total_order||0}</strong>Order</div>
          <div class="cust-list-stat"><strong>Rp ${parseFloat(c.total_omset||0).toLocaleString('id-ID')}</strong>Omset</div>
          <div class="cust-list-stat"><strong>${c.last_order?fmtDate(c.last_order):'-'}</strong>Terakhir</div>
        </div>
      </div>`).join('');
  } else {
    grid.innerHTML = allCustomer.map(c => `
      <div class="cust-card" onclick="openDetail(${c.id})">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
          <div>
            <div class="cust-nama">${esc(c.nama)}</div>
            <div class="cust-telp">${c.telepon||'No telepon'}</div>
            <div style="margin-top:5px;display:flex;gap:4px;flex-wrap:wrap">
              ${tierBadge(c.tier)} ${segmenBadge(c.segmen)}
              ${parseInt(c.poin_balance||0) > 0 ? '<span class="hl-badge" style="background:#F0FDFB;color:#0F766E;font-size:10px">⭐ '+parseInt(c.poin_balance)+'</span>' : ''}
            </div>
          </div>
          <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px">
            <span class="hl-badge ${c.tipe==='b2b'?'hl-badge-navy':'hl-badge-teal'}" style="font-size:10px">${c.tipe==='b2b'?'B2B':'Retail'}</span>
            ${c.metode_bayar==='bulanan'?'<span class="hl-badge" style="background:#FEF3C7;color:#92400E;font-size:10px">Bulanan</span>':''}
          </div>
        </div>
        ${c.alamat?`<div style="font-size:12px;color:var(--gray);margin-bottom:10px;line-height:1.4">${esc(c.alamat)}</div>`:''}
        <div class="cust-stats">
          <div class="cust-stat"><strong>${c.total_order||0}</strong><span>Order</span></div>
          <div class="cust-stat"><strong style="font-size:12px">Rp ${parseFloat(c.total_omset||0).toLocaleString('id-ID')}</strong><span>Omset</span></div>
          <div class="cust-stat"><strong style="font-size:11px">${c.last_order?fmtDate(c.last_order):'-'}</strong><span>Terakhir</span></div>
        </div>
      </div>`).join('');
  }
}

async function openDetail(id) {
  currentDetailId = id;
  const c = allCustomer.find(x=>x.id==id);
  if (!c) return;
  document.getElementById('detailTitle').textContent = '👤 ' + c.nama;
  document.getElementById('detailBody').innerHTML = '<div class="hl-loading">⏳ Memuat riwayat...</div>';
  document.getElementById('modalDetail').classList.add('open');

  const r = await fetch('customer.php?action=get_orders&id='+id);
  const orders = await r.json();

  // Hitung next reward untuk progress bar
  const poin = parseInt(c.poin_balance||0);
  const nextThr = poin < 100 ? 100 : poin < 200 ? 200 : poin < 500 ? 500 : 0;
  const prevThr = poin >= 500 ? 500 : poin >= 200 ? 200 : poin >= 100 ? 100 : 0;
  const pct = nextThr ? Math.round(((poin - prevThr) / (nextThr - prevThr)) * 100) : 100;
  const nextLabel = nextThr === 100 ? 'Silver' : nextThr === 200 ? 'Gold' : nextThr === 500 ? 'Platinum' : 'Max tier';

  document.getElementById('detailBody').innerHTML = `
    <div style="background:linear-gradient(135deg,#0F1C3A,#1E3A8A);color:#fff;border-radius:14px;padding:16px 18px;margin-bottom:16px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:8px">
        <div>
          <div style="font-size:1.3rem;font-weight:800">${esc(c.nama)}</div>
          <div style="font-size:12px;opacity:.8;margin-top:3px">📞 ${c.telepon||'-'} · Sejak ${fmtDate(c.created_at)}</div>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          ${c.tier && c.tier!=='regular' ? `<span style="background:rgba(255,255,255,.15);font-size:11px;font-weight:700;padding:4px 10px;border-radius:100px">${{silver:'🥈 Silver',gold:'🥇 Gold',platinum:'💎 Platinum'}[c.tier]||c.tier}</span>` : ''}
          ${c.segmen && c.segmen!=='regular' ? `<span style="background:rgba(255,255,255,.15);font-size:11px;font-weight:700;padding:4px 10px;border-radius:100px">${{baru:'🆕 Baru',vip:'⭐ VIP',dormant:'😴 Dormant'}[c.segmen]||c.segmen}</span>` : ''}
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px;padding-top:14px;border-top:1px solid rgba(255,255,255,.15)">
        <div><div style="font-size:11px;opacity:.7">Total Order</div><div style="font-size:1.2rem;font-weight:800">${c.total_order||0}</div></div>
        <div><div style="font-size:11px;opacity:.7">Total Spending</div><div style="font-size:1.2rem;font-weight:800">Rp ${parseFloat(c.total_omset||0).toLocaleString('id-ID')}</div></div>
      </div>
    </div>

    <!-- POIN PROGRESS -->
    <div style="background:linear-gradient(90deg,#F0FDFB,#ECFDF5);border:1px solid #B6F0E6;border-radius:12px;padding:14px 16px;margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div>
          <div style="font-size:11px;color:#0F766E;font-weight:700;text-transform:uppercase;letter-spacing:.06em">⭐ Saldo Poin</div>
          <div style="font-size:1.4rem;font-weight:800;color:#0F1C3A">${poin.toLocaleString('id-ID')} <span style="font-size:13px;color:var(--gray);font-weight:500">poin</span></div>
        </div>
        ${nextThr ? `<div style="text-align:right;font-size:11px;color:#0F766E">
          ${nextThr - poin} poin lagi<br><strong>→ ${nextLabel}</strong>
        </div>` : '<div style="font-size:11px;color:#0F766E">💎 Tier tertinggi!</div>'}
      </div>
      ${nextThr ? `<div style="height:8px;background:#fff;border-radius:100px;overflow:hidden">
        <div style="height:100%;width:${pct}%;background:linear-gradient(90deg,#10B981,#06B6D4);transition:width .3s"></div>
      </div>` : ''}
    </div>

    <!-- PREFERENSI -->
    <div style="background:#fff;border:1px solid rgba(27,45,90,.08);border-radius:12px;padding:14px 16px;margin-bottom:14px" id="prefBox">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div style="font-size:11px;color:var(--gray);font-weight:700;text-transform:uppercase;letter-spacing:.06em">🌸 Preferensi</div>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="togglePrefEdit()" id="prefEditBtn">✏️ Edit</button>
      </div>
      <div id="prefDisplay">
        <div style="font-size:13px;line-height:1.7">
          Parfum: <strong>${c.preferensi_parfum||'-'}</strong>
          &nbsp;·&nbsp;Suhu: <strong>${c.preferensi_suhu||'-'}</strong>
        </div>
        ${c.catatan_tetap ? `<div style="font-size:13px;color:#475569;margin-top:5px;background:#F8FAFC;padding:7px 10px;border-radius:8px;border-left:3px solid var(--teal)">📝 ${esc(c.catatan_tetap)}</div>` : '<div style="font-size:12px;color:var(--gray);font-style:italic;margin-top:5px">Belum ada catatan tetap</div>'}
      </div>
      <div id="prefEdit" style="display:none">
        <div class="hl-form-row" style="margin-bottom:8px">
          <div class="hl-form-group" style="margin:0">
            <label class="hl-label">Parfum</label>
            <input type="text" id="pf_parfum" class="hl-input" placeholder="Lavender / Vanilla / dll" value="${esc(c.preferensi_parfum||'')}"/>
          </div>
          <div class="hl-form-group" style="margin:0">
            <label class="hl-label">Suhu Cuci</label>
            <select id="pf_suhu" class="hl-input">
              <option value="">- Default -</option>
              <option value="Normal" ${c.preferensi_suhu==='Normal'?'selected':''}>Normal</option>
              <option value="Hangat" ${c.preferensi_suhu==='Hangat'?'selected':''}>Hangat</option>
              <option value="Panas"  ${c.preferensi_suhu==='Panas'?'selected':''}>Panas</option>
              <option value="Dingin" ${c.preferensi_suhu==='Dingin'?'selected':''}>Dingin</option>
            </select>
          </div>
        </div>
        <div class="hl-form-group" style="margin-bottom:10px">
          <label class="hl-label">Catatan Tetap (auto-load ke POS saat pelanggan ini dipilih)</label>
          <textarea id="pf_catatan" class="hl-input hl-textarea" placeholder="Baju putih pisah, jangan setrika kerah, dll">${esc(c.catatan_tetap||'')}</textarea>
        </div>
        <div style="display:flex;gap:6px">
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="togglePrefEdit()">Batal</button>
          <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="savePreferensi(${c.id})">💾 Simpan</button>
        </div>
      </div>
    </div>

    <div style="background:var(--off);border-radius:var(--r);padding:14px 16px;margin-bottom:16px;display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:14px">
      <div><span style="color:var(--gray)">Tipe: </span><strong>${c.tipe==='b2b'?'B2B / Korporat':'Retail'}</strong></div>
      <div><span style="color:var(--gray)">Last Transaksi: </span><strong>${c.last_transaksi?fmtDate(c.last_transaksi):'-'}</strong></div>
      ${c.alamat?`<div style="grid-column:1/-1"><span style="color:var(--gray)">Alamat: </span>${esc(c.alamat)}</div>`:''}
      ${c.catatan?`<div style="grid-column:1/-1"><span style="color:var(--gray)">Catatan: </span>${esc(c.catatan)}</div>`:''}
    </div>
    <div style="font-size:12px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px">Riwayat Order (20 terakhir)</div>
    ${orders.length ? `<div class="hl-table-wrap"><table class="hl-table">
      <thead><tr><th>No Order</th><th>Tanggal</th><th>Layanan</th><th>Status</th><th style="text-align:right">Total</th></tr></thead>
      <tbody>${orders.map(o=>`<tr>
        <td style="font-family:var(--mono);font-size:12px;color:var(--teal-d)">${o.no_order}</td>
        <td style="font-size:12px">${fmtDate(o.tanggal)}</td>
        <td style="font-size:12px;color:var(--gray);max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(o.layanan||'-')}</td>
        <td>${statusBayarBadge(o.status_bayar)}</td>
        <td style="font-family:var(--mono);font-size:12px;text-align:right;font-weight:600">Rp ${parseFloat(o.total).toLocaleString('id-ID')}</td>
      </tr>`).join('')}</tbody>
    </table></div>` : '<div class="hl-empty">Belum ada order</div>'}`;
}

function togglePrefEdit(){
  const disp = document.getElementById('prefDisplay');
  const edit = document.getElementById('prefEdit');
  const btn  = document.getElementById('prefEditBtn');
  const showEdit = edit.style.display === 'none';
  disp.style.display = showEdit ? 'none' : '';
  edit.style.display = showEdit ? 'block' : 'none';
  btn.textContent    = showEdit ? '✕ Batal' : '✏️ Edit';
}

async function savePreferensi(pid){
  const body = {
    id: pid,
    parfum:         document.getElementById('pf_parfum').value,
    suhu:           document.getElementById('pf_suhu').value,
    catatan_tetap:  document.getElementById('pf_catatan').value,
  };
  try {
    const r = await fetch('customer.php?action=save_preferensi', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify(body)
    });
    const d = await r.json();
    if (d.error) { showToast(d.error,'error'); return; }
    showToast('✓ Preferensi tersimpan','success');
    // Update cached row + re-open detail dengan data segar
    const c = allCustomer.find(x => x.id == pid);
    if (c) {
      c.preferensi_parfum = body.parfum;
      c.preferensi_suhu   = body.suhu;
      c.catatan_tetap     = body.catatan_tetap;
    }
    openDetail(pid);
  } catch(e){ showToast('Network error','error'); }
}

function statusBayarBadge(s){
  const m={lunas:'<span class="hl-badge hl-badge-lunas">✅ Lunas</span>',dp:'<span class="hl-badge hl-badge-dp">⚡ DP</span>',belum_bayar:'<span class="hl-badge hl-badge-belum">⏳ Belum</span>'};
  return m[s]||s;
}

function editFromDetail() {
  const c = allCustomer.find(x=>x.id==currentDetailId);
  if (c) { closeDetail(); openModal(c); }
}
function closeDetail() { document.getElementById('modalDetail').classList.remove('open'); }

function openModal(data=null) {
  document.getElementById('f_id').value       = data?.id||'';
  document.getElementById('f_nama').value     = data?.nama||'';
  document.getElementById('f_telepon').value  = data?.telepon||'';
  document.getElementById('f_tipe').value         = data?.tipe||'retail';
  document.getElementById('f_metode_bayar').value = data?.metode_bayar||'langsung';
  document.getElementById('f_alamat').value        = data?.alamat||'';
  document.getElementById('f_catatan').value  = data?.catatan||'';
  document.getElementById('f_active').value   = data?.is_active??1;
  document.getElementById('custModalTitle').textContent = data ? '✏️ Edit Customer' : '➕ Tambah Customer';
  document.getElementById('modalCust').classList.add('open');
}
function closeModal() { document.getElementById('modalCust').classList.remove('open'); }

async function saveCustomer() {
  const nama = document.getElementById('f_nama').value.trim();
  if (!nama) { showToast('⚠️ Nama wajib diisi','error'); return; }
  const r = await fetch('customer.php?action=save', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({
      id:       document.getElementById('f_id').value,
      nama,
      telepon:  document.getElementById('f_telepon').value,
      tipe:         document.getElementById('f_tipe').value,
      metode_bayar: document.getElementById('f_metode_bayar').value,
      alamat:       document.getElementById('f_alamat').value,
      catatan:  document.getElementById('f_catatan').value,
      is_active:document.getElementById('f_active').value,
    })
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Customer disimpan!','success'); closeModal(); loadCustomer(); loadStats(); }
  else showToast('❌ '+(d.error||'Gagal'),'error');
}

function renderPaging() {
  const el = document.getElementById('custPaging');
  if (!el) return;
  if (totalPages <= 1) { el.innerHTML = ''; return; }

  let html = '<div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:20px;flex-wrap:wrap">';
  html += `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadCustomer(${currentPage-1})" ${currentPage===1?'disabled':''}>← Prev</button>`;

  const start = Math.max(1, currentPage - 2);
  const end   = Math.min(totalPages, currentPage + 2);

  if (start > 1) html += `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadCustomer(1)">1</button>`;
  if (start > 2) html += `<span style="color:var(--gray);padding:0 4px">...</span>`;

  for (let i = start; i <= end; i++) {
    html += `<button class="hl-btn ${i===currentPage?'hl-btn-primary':'hl-btn-outline'} hl-btn-sm" onclick="loadCustomer(${i})">${i}</button>`;
  }

  if (end < totalPages - 1) html += `<span style="color:var(--gray);padding:0 4px">...</span>`;
  if (end < totalPages) html += `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadCustomer(${totalPages})">${totalPages}</button>`;

  html += `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadCustomer(${currentPage+1})" ${currentPage===totalPages?'disabled':''}>Next →</button>`;
  html += `<span style="font-size:12px;color:var(--gray);margin-left:8px">Halaman ${currentPage} dari ${totalPages}</span>`;
  html += '</div>';

  el.innerHTML = html;
}

function debounce(){ clearTimeout(searchTimer); searchTimer=setTimeout(()=>loadCustomer(1),400); }
function fmtDate(d){if(!d)return'-';return new Date(d+'T00:00:00').toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
</script>
</body>
</html>
