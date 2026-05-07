<?php
$activePage = 'layanan';
require_once 'auth.php';
require_once 'components.php';
requireLogin();
$user = currentUser();
if (isset($_GET['logout'])) doLogout();

requirePermission('layanan.view');

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $pdo = getDB();

    if ($action === 'list') {
        $rows = $pdo->query("SELECT * FROM hl_layanan ORDER BY kategori,urutan,nama")->fetchAll();
        echo json_encode($rows); exit;
    }
    if ($action === 'save' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.create') && !hasPermission('layanan.edit')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        $d = json_decode(file_get_contents('php://input'), true);
        if (!empty($d['id'])) {
            $pdo->prepare("UPDATE hl_layanan SET nama=?,kategori=?,satuan=?,harga=?,is_active=?,urutan=? WHERE id=?")
                ->execute([$d['nama'],$d['kategori'],$d['satuan'],$d['harga'],$d['is_active'],$d['urutan'],$d['id']]);
        } else {
            $pdo->prepare("INSERT INTO hl_layanan (nama,kategori,satuan,harga,urutan,is_active) VALUES (?,?,?,?,?,1)")
                ->execute([$d['nama'],$d['kategori'],$d['satuan'],$d['harga'],$d['urutan']??0]);
        }
        logAudit(!empty($d['id'])?'update':'create','layanan',(!empty($d['id'])?'Edit':'Tambah').' layanan: '.($d['nama']??''));
        echo json_encode(['success'=>true]); exit;
    }
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.delete')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        $d = json_decode(file_get_contents('php://input'), true);
        $pdo->prepare("UPDATE hl_layanan SET is_active=0 WHERE id=?")->execute([$d['id']]);
        echo json_encode(['success'=>true]); exit;
    }
    if ($action === 'toggle' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.edit')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        $d = json_decode(file_get_contents('php://input'), true);
        $pdo->prepare("UPDATE hl_layanan SET is_active=? WHERE id=?")->execute([$d['is_active'],$d['id']]);
        echo json_encode(['success'=>true]); exit;
    }
    if ($action === 'stats') {
        $pdo2 = getDB();
        $total   = $pdo2->query("SELECT COUNT(*) FROM hl_layanan WHERE is_active=1")->fetchColumn();
        $kat     = $pdo2->query("SELECT COUNT(DISTINCT kategori) FROM hl_layanan WHERE is_active=1")->fetchColumn();
        $terlaris= $pdo2->query("SELECT nama_layanan,COUNT(*) as c FROM hl_transaksi_item GROUP BY nama_layanan ORDER BY c DESC LIMIT 1")->fetch();
        echo json_encode(['total'=>$total,'kategori'=>$kat,'terlaris'=>$terlaris['nama_layanan']??'-']); exit;
    }
    echo json_encode(['error'=>'Unknown']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Master Layanan'); ?>
<style>
.layanan-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.layanan-card{background:var(--white);border-radius:var(--r-lg);border:2px solid rgba(27,45,90,.07);padding:18px;transition:all .2s;position:relative}
.layanan-card:hover{box-shadow:var(--shadow-lg);transform:translateY(-2px)}
.layanan-card.inactive{opacity:.5}
.layanan-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:var(--r-lg) var(--r-lg) 0 0;background:var(--teal)}
.layanan-harga{font-family:var(--mono);font-size:1.3rem;font-weight:800;color:var(--navy);margin:6px 0 4px}
.layanan-nama{font-size:15px;font-weight:700;color:var(--navy);margin-bottom:4px}
.layanan-kat{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--gray);margin-bottom:10px}
.layanan-actions{display:flex;gap:6px;margin-top:12px}
.toggle-switch{position:relative;width:40px;height:22px;cursor:pointer}
.toggle-switch input{opacity:0;width:0;height:0}
.toggle-slider{position:absolute;inset:0;background:#CBD5E1;border-radius:100px;transition:.3s}
.toggle-slider::before{content:'';position:absolute;width:16px;height:16px;left:3px;bottom:3px;background:white;border-radius:50%;transition:.3s}
input:checked + .toggle-slider{background:var(--green)}
input:checked + .toggle-slider::before{transform:translateX(18px)}
</style>
</head>
<body>
<?php renderTopbar('layanan'); ?>
<div class="hl-main">

  <div class="hl-stat-grid-4" style="margin-bottom:20px">
    <div class="hl-stat-card teal"><div class="hl-stat-num" id="sTotal">-</div><div class="hl-stat-label">🧺 Layanan Aktif</div></div>
    <div class="hl-stat-card navy"><div class="hl-stat-num" id="sKat">-</div><div class="hl-stat-label">📂 Kategori</div></div>
    <div class="hl-stat-card green"><div class="hl-stat-num" id="sTerlaris" style="font-size:1rem">-</div><div class="hl-stat-label">🏆 Terlaris</div></div>
    <div class="hl-stat-card purple">
      <button class="hl-btn hl-btn-primary hl-btn-full" onclick="openModal()" style="margin-top:4px">+ Tambah Layanan</button>
    </div>
  </div>

  <div class="hl-filter-bar">
    <span class="hl-filter-label">Filter</span>
    <select id="fKat" class="hl-input" style="width:auto" onchange="renderLayanan()">
      <option value="">Semua Kategori</option>
    </select>
    <select id="fStatus" class="hl-input" style="width:auto" onchange="renderLayanan()">
      <option value="">Semua Status</option>
      <option value="1">Aktif</option>
      <option value="0">Nonaktif</option>
    </select>
    <input type="text" id="fSearch" class="hl-input" placeholder="🔍 Cari layanan..." style="max-width:240px" oninput="renderLayanan()"/>
    <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadLayanan()">↻</button>
  </div>

  <div class="layanan-grid" id="layananGrid">
    <div class="hl-loading">⏳ Memuat...</div>
  </div>
</div>

<!-- MODAL -->
<div class="hl-modal-overlay" id="modalLayanan">
  <div class="hl-modal hl-modal-sm">
    <div class="hl-modal-header">
      <span class="hl-modal-title" id="modalTitle">➕ Tambah Layanan</span>
      <button class="hl-modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="hl-modal-body">
      <input type="hidden" id="f_id"/>
      <div class="hl-form-group">
        <label class="hl-label">Nama Layanan <span class="req">*</span></label>
        <input type="text" id="f_nama" class="hl-input" placeholder="Contoh: Kiloan Reguler"/>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Kategori <span class="req">*</span></label>
          <input type="text" id="f_kat" class="hl-input" placeholder="Kiloan, Satuan, dll" list="katList"/>
          <datalist id="katList"></datalist>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Satuan</label>
          <select id="f_satuan" class="hl-input">
            <option value="kg">kg</option>
            <option value="pcs">pcs</option>
            <option value="set">set</option>
            <option value="pasang">pasang</option>
          </select>
        </div>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Harga / Satuan (Rp) <span class="req">*</span></label>
          <input type="number" id="f_harga" class="hl-input" placeholder="0" min="0" step="500"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Urutan Tampil</label>
          <input type="number" id="f_urutan" class="hl-input" value="0" min="0"/>
        </div>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Status</label>
        <select id="f_active" class="hl-input">
          <option value="1">✅ Aktif</option>
          <option value="0">⏸️ Nonaktif</option>
        </select>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeModal()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveLayanan()">💾 Simpan</button>
    </div>
  </div>
</div>
<?php renderToast(); ?>
<script>
let allLayanan = [];

document.addEventListener('DOMContentLoaded', () => { loadLayanan(); loadStats(); });

async function loadStats() {
  const r = await fetch('layanan.php?action=stats');
  const d = await r.json();
  document.getElementById('sTotal').textContent    = d.total;
  document.getElementById('sKat').textContent      = d.kategori;
  document.getElementById('sTerlaris').textContent = d.terlaris;
}

async function loadLayanan() {
  const r = await fetch('layanan.php?action=list');
  allLayanan = await r.json();

  // Populate kategori filter
  const kats = [...new Set(allLayanan.map(l=>l.kategori).filter(Boolean))].sort();
  const fKat = document.getElementById('fKat');
  fKat.innerHTML = '<option value="">Semua Kategori</option>' + kats.map(k=>`<option>${k}</option>`).join('');
  document.getElementById('katList').innerHTML = kats.map(k=>`<option value="${k}">`).join('');
  renderLayanan();
}

function renderLayanan() {
  const q      = document.getElementById('fSearch').value.toLowerCase();
  const kat    = document.getElementById('fKat').value;
  const status = document.getElementById('fStatus').value;

  let list = allLayanan;
  if (q)      list = list.filter(l => l.nama.toLowerCase().includes(q) || (l.kategori||'').toLowerCase().includes(q));
  if (kat)    list = list.filter(l => l.kategori === kat);
  if (status !== '') list = list.filter(l => String(l.is_active) === status);

  const grid = document.getElementById('layananGrid');
  if (!list.length) {
    grid.innerHTML = '<div class="hl-empty">📭 Tidak ada layanan ditemukan.</div>';
    return;
  }

  grid.innerHTML = list.map(l => `
    <div class="layanan-card ${l.is_active==1?'':'inactive'}">
      <div class="layanan-kat">${esc(l.kategori||'Umum')}</div>
      <div class="layanan-nama">${esc(l.nama)}</div>
      <div class="layanan-harga">Rp ${parseFloat(l.harga).toLocaleString('id-ID')} <span style="font-size:13px;font-weight:400;color:var(--gray)">/ ${l.satuan}</span></div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px">
        <label class="toggle-switch" title="${l.is_active==1?'Nonaktifkan':'Aktifkan'}">
          <input type="checkbox" ${l.is_active==1?'checked':''} onchange="toggleLayanan(${l.id},this.checked)"/>
          <span class="toggle-slider"></span>
        </label>
        <div class="layanan-actions">
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="editLayanan(${l.id})">✏️ Edit</button>
          <button class="hl-btn hl-btn-danger hl-btn-sm" onclick="deleteLayanan(${l.id})">🗑️</button>
        </div>
      </div>
    </div>`).join('');
}

function openModal(data=null) {
  document.getElementById('f_id').value     = data?.id || '';
  document.getElementById('f_nama').value   = data?.nama || '';
  document.getElementById('f_kat').value    = data?.kategori || '';
  document.getElementById('f_satuan').value = data?.satuan || 'kg';
  document.getElementById('f_harga').value  = data?.harga || '';
  document.getElementById('f_urutan').value = data?.urutan || 0;
  document.getElementById('f_active').value = data?.is_active ?? 1;
  document.getElementById('modalTitle').textContent = data ? '✏️ Edit Layanan' : '➕ Tambah Layanan';
  document.getElementById('modalLayanan').classList.add('open');
}
function editLayanan(id) { openModal(allLayanan.find(l=>l.id==id)); }
function closeModal() { document.getElementById('modalLayanan').classList.remove('open'); }

async function saveLayanan() {
  const nama  = document.getElementById('f_nama').value.trim();
  const harga = document.getElementById('f_harga').value;
  if (!nama)  { showToast('⚠️ Nama wajib diisi','error'); return; }
  if (!harga) { showToast('⚠️ Harga wajib diisi','error'); return; }

  const r = await fetch('layanan.php?action=save', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      id: document.getElementById('f_id').value,
      nama, harga,
      kategori: document.getElementById('f_kat').value,
      satuan:   document.getElementById('f_satuan').value,
      urutan:   document.getElementById('f_urutan').value,
      is_active:document.getElementById('f_active').value,
    })
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Layanan disimpan!','success'); closeModal(); loadLayanan(); loadStats(); }
  else showToast('❌ '+(d.error||'Gagal'),'error');
}

async function toggleLayanan(id, active) {
  await fetch('layanan.php?action=toggle', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({id, is_active: active ? 1 : 0})
  });
  loadLayanan(); loadStats();
}

async function deleteLayanan(id) {
  if (!confirm('Nonaktifkan layanan ini?')) return;
  await fetch('layanan.php?action=delete', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({id})
  });
  showToast('✅ Layanan dinonaktifkan','success'); loadLayanan(); loadStats();
}

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function showToast(msg,type='success'){const t=document.getElementById('toast');t.textContent=msg;t.className='hl-toast '+type+' show';setTimeout(()=>t.className='hl-toast',3500)}
</script>
</body>
</html>
