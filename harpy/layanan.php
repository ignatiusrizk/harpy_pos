<?php
$activePage = 'layanan';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/ServiceCatalog.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
requirePermission('layanan.view');

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $oid = TenantResolver::outletId();

    if ($action === 'list') {
        // JOIN master untuk expose aturan override (kalau kolom master ada)
        try {
            $rows = TenantQuery::raw(
                "SELECT l.*, m.allow_override, m.override_max_pct, m.harga_default
                   FROM hl_layanan l
                   LEFT JOIN hl_layanan_master m ON m.id = l.master_id
                  WHERE l.tenant_id=? AND l.outlet_id=? ORDER BY l.kategori,l.urutan,l.nama",
                [$tid, $oid]
            );
        } catch (Throwable) {
            // Fallback kalau migration master belum dijalankan
            $rows = TenantQuery::raw(
                "SELECT * FROM hl_layanan WHERE tenant_id=? AND outlet_id=? ORDER BY kategori,urutan,nama",
                [$tid, $oid]
            );
        }
        echo json_encode($rows); exit;
    }

    // ── Override harga layanan dari master (outlet adjust ±max_pct) ──
    if ($action === 'override_harga' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.edit')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $masterId = (int)($d['master_id'] ?? 0);
        $harga    = (float)($d['harga'] ?? 0);
        if (!$masterId) { echo json_encode(['error'=>'Layanan bukan dari master']); exit; }
        try {
            ServiceCatalog::setOutletOverride($tid, $oid, $masterId, $harga);
            logAudit('override','layanan',"Adjust harga layanan master #$masterId jadi Rp ".number_format($harga,0,',','.'));
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>$e->getMessage()]);
        }
        exit;
    }
    if ($action === 'save' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.create') && !hasPermission('layanan.edit')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $nama    = substr(trim(strip_tags($d['nama'] ?? '')), 0, 100);
        $kategori= substr(trim(strip_tags($d['kategori'] ?? '')), 0, 50);
        if (!$nama) { echo json_encode(['error'=>'Nama wajib diisi']); exit; }
        // Layanan dari master katalog: nama/kategori/satuan dikunci HQ.
        // Harga harus lewat override (action=override_harga).
        if (!empty($d['id'])) {
            try {
                $chk = TenantQuery::raw("SELECT master_id FROM hl_layanan WHERE id=? AND tenant_id=? AND outlet_id=?",
                    [intval($d['id']), $tid, $oid]);
                if (!empty($chk[0]['master_id'])) {
                    echo json_encode(['error'=>'Layanan ini dari master katalog HQ. Hanya harga yang bisa di-adjust (jika diizinkan).']);
                    exit;
                }
            } catch (Throwable) {}
        }
        if (!empty($d['id'])) {
            TenantQuery::update('hl_layanan', [
                'nama'     => $nama,
                'kategori' => $kategori,
                'satuan'   => $d['satuan'] ?? 'kg',
                'harga'    => floatval($d['harga'] ?? 0),
                'is_active'=> intval($d['is_active'] ?? 1),
                'urutan'   => intval($d['urutan'] ?? 0),
            ], 'id = ?', [intval($d['id'])]);
        } else {
            TenantQuery::insert('hl_layanan', [
                'nama'     => $nama,
                'kategori' => $kategori,
                'satuan'   => $d['satuan'] ?? 'kg',
                'harga'    => floatval($d['harga'] ?? 0),
                'urutan'   => intval($d['urutan'] ?? 0),
                'is_active'=> 1,
            ]);
        }
        logAudit(!empty($d['id'])?'update':'create','layanan',(!empty($d['id'])?'Edit':'Tambah').' layanan: '.$nama);
        echo json_encode(['success'=>true]); exit;
    }
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.delete')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        TenantQuery::update('hl_layanan', ['is_active'=>0], 'id = ?', [intval($d['id'])]);
        echo json_encode(['success'=>true]); exit;
    }
    if ($action === 'toggle' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.edit')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        TenantQuery::update('hl_layanan', ['is_active'=>intval($d['is_active'])], 'id = ?', [intval($d['id'])]);
        echo json_encode(['success'=>true]); exit;
    }
    if ($action === 'stats') {
        $total    = TenantQuery::count('hl_layanan', 'is_active=1');
        $kat      = TenantQuery::raw("SELECT COUNT(DISTINCT kategori) as c FROM hl_layanan WHERE tenant_id=? AND is_active=1", [$tid]);
        $terlaris = TenantQuery::raw(
            "SELECT i.nama_layanan, COUNT(*) as c FROM hl_transaksi_item i
             WHERE i.tenant_id=? GROUP BY i.nama_layanan ORDER BY c DESC LIMIT 1",
            [$tid]
        );
        echo json_encode([
            'total'    => $total,
            'kategori' => intval($kat[0]['c'] ?? 0),
            'terlaris' => $terlaris[0]['nama_layanan'] ?? '-',
        ]); exit;
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
.lyn-badge{font-size:9px;font-weight:700;padding:2px 7px;border-radius:100px;margin-left:4px;white-space:nowrap}
.lyn-badge.adj{background:#E0F2FE;color:#0369A1}
.lyn-badge.lock{background:#F3F4F6;color:#6B7280}
.lyn-badge.ov{background:#FEF3C7;color:#92400E}
.layanan-nama{font-size:15px;font-weight:700;color:var(--navy);margin-bottom:4px}
.layanan-kat{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--gray);margin-bottom:10px}
.layanan-actions{display:flex;gap:6px;margin-top:12px}
.toggle-switch{position:relative;width:40px;height:22px;cursor:pointer}
.toggle-switch input{opacity:0;width:0;height:0}
.toggle-slider{position:absolute;inset:0;background:#CBD5E1;border-radius:100px;transition:.3s}
.toggle-slider::before{content:'';position:absolute;width:16px;height:16px;left:3px;bottom:3px;background:white;border-radius:50%;transition:.3s}
input:checked + .toggle-slider{background:var(--green)}
input:checked + .toggle-slider::before{transform:translateX(18px)}
@media(max-width:680px){
  .layanan-grid{grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px}
  .layanan-harga{font-size:1.1rem}
}
@media(max-width:400px){.layanan-grid{grid-template-columns:1fr}}
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

  <div class="hl-filter-collapsible">
    <button class="hl-filter-toggle-btn" id="layananFilterBtn" onclick="toggleFilter('layananFilter')">
      🔍 Filter &amp; Pencarian <span class="hl-filter-active-dot" id="layananFilterDot"></span>
      <span class="hl-toggle-arrow">▼</span>
    </button>
    <div class="hl-filter-bar collapsed" id="layananFilter">
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
  if (!list.length) { grid.innerHTML = `<div style="grid-column:1/-1"><div class="hl-empty-v2">
    <div class="e-icon">🧺</div>
    <div class="e-title">Belum ada layanan</div>
    <div class="e-sub">Tambah layanan supaya bisa dipakai di POS</div>
  </div></div>`; return; }

  grid.innerHTML = list.map(l => {
    const isMaster = !!l.master_id;
    const canAdjust = isMaster && String(l.allow_override) === '1';
    const isOverridden = String(l.harga_overridden) === '1';

    // Badge sumber
    let badge = '';
    if (isMaster) {
      badge = canAdjust
        ? `<span class="lyn-badge adj" title="Dari HQ, boleh adjust ±${l.override_max_pct}%">🏢 HQ · ±${l.override_max_pct}%</span>`
        : `<span class="lyn-badge lock" title="Harga dikunci HQ">🔒 HQ</span>`;
    }
    const ovTag = isOverridden ? `<span class="lyn-badge ov">harga custom</span>` : '';

    // Tombol aksi: master → adjust/locked; non-master → edit/delete penuh
    let actions;
    if (isMaster) {
      actions = canAdjust
        ? `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick='openAdjust(${JSON.stringify(l)})'>💲 Adjust Harga</button>`
        : `<span style="font-size:11px;color:var(--gray)">dikelola HQ</span>`;
    } else {
      actions = `
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="editLayanan(${l.id})">✏️ Edit</button>
        <button class="hl-btn hl-btn-danger hl-btn-sm" onclick="deleteLayanan(${l.id})">🗑️</button>`;
    }

    return `
    <div class="layanan-card ${l.is_active==1?'':'inactive'}">
      <div class="layanan-kat">${esc(l.kategori||'Umum')} ${badge} ${ovTag}</div>
      <div class="layanan-nama">${esc(l.nama)}</div>
      <div class="layanan-harga">Rp ${parseFloat(l.harga).toLocaleString('id-ID')} <span style="font-size:13px;font-weight:400;color:var(--gray)">/ ${l.satuan}</span></div>
      ${canAdjust ? `<div style="font-size:11px;color:var(--gray);margin-top:2px">Default HQ: Rp ${parseFloat(l.harga_default).toLocaleString('id-ID')}</div>` : ''}
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px">
        <label class="toggle-switch" title="${l.is_active==1?'Nonaktifkan':'Aktifkan'}">
          <input type="checkbox" ${l.is_active==1?'checked':''} onchange="toggleLayanan(${l.id},this.checked)"/>
          <span class="toggle-slider"></span>
        </label>
        <div class="layanan-actions">${actions}</div>
      </div>
    </div>`;
  }).join('');
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

// ── Adjust harga (override) untuk layanan dari master ──
async function openAdjust(l){
  const base = parseFloat(l.harga_default) || 0;
  const pct  = parseFloat(l.override_max_pct) || 0;
  const min = base > 0 && pct > 0 ? Math.round(base * (1 - pct/100)) : 0;
  const max = base > 0 && pct > 0 ? Math.round(base * (1 + pct/100)) : 0;
  const rangeTxt = (min && max)
    ? `Rentang diizinkan: Rp ${min.toLocaleString('id-ID')} – Rp ${max.toLocaleString('id-ID')} (±${pct}%)`
    : `Default HQ: Rp ${base.toLocaleString('id-ID')}`;

  const harga = prompt(
    `Adjust harga "${l.nama}"\n${rangeTxt}\n\nHarga sekarang: Rp ${parseFloat(l.harga).toLocaleString('id-ID')}\nMasukkan harga baru:`,
    l.harga
  );
  if (harga === null) return;
  const val = parseFloat(harga);
  if (isNaN(val) || val < 0) { showToast('⚠️ Harga tidak valid','error'); return; }

  const r = await fetch('layanan.php?action=override_harga', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({ master_id: l.master_id, harga: val })
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Harga di-adjust!','success'); loadLayanan(); }
  else showToast('❌ '+(d.error||'Gagal'),'error');
}

async function saveLayanan() {
  const nama  = document.getElementById('f_nama').value.trim();
  const harga = document.getElementById('f_harga').value;
  if (!nama)  { showToast('⚠️ Nama wajib diisi','error'); return; }
  if (!harga) { showToast('⚠️ Harga wajib diisi','error'); return; }

  const r = await fetch('layanan.php?action=save', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({
      id: document.getElementById('f_id').value, nama, harga,
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
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id, is_active: active ? 1 : 0})
  });
  loadLayanan(); loadStats();
}

async function deleteLayanan(id) {
  if (!confirm('Nonaktifkan layanan ini?')) return;
  await fetch('layanan.php?action=delete', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id})
  });
  showToast('✅ Layanan dinonaktifkan','success'); loadLayanan(); loadStats();
}

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
</script>
</body>
</html>
