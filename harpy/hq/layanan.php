<?php
// ══════════════════════════════════════════════════════
// hq/layanan.php — Master Katalog Layanan Terpusat
//
// HQ kelola master layanan + harga default, lalu push ke outlet.
// ══════════════════════════════════════════════════════

$activePage = 'hq-layanan';
$pageTitle  = 'Master Katalog Layanan';

define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';
require_once ROOT . '/core/ServiceCatalog.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];
$action = $_GET['action'] ?? '';

// ── API: list master + coverage summary ─────────────
if ($action === 'list') {
    header('Content-Type: application/json');
    try {
        $master = ServiceCatalog::listMaster($tid);
        // Hitung berapa outlet yang sudah punya tiap master
        foreach ($master as &$m) {
            $cov = ServiceCatalog::coverage($tid, (int)$m['id']);
            $m['outlet_total'] = count($cov);
            $m['outlet_has']   = count(array_filter($cov, fn($c) => $c['has']));
            $m['outlet_override'] = count(array_filter($cov, fn($c) => $c['harga_overridden'] === 1));
        }
        unset($m);
        echo json_encode(['ok'=>true, 'master'=>$master]);
    } catch (Throwable $e) {
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

// ── API: coverage detail 1 master ────────────────────
if ($action === 'coverage') {
    header('Content-Type: application/json');
    $mid = (int)($_GET['master_id'] ?? 0);
    try {
        echo json_encode(['ok'=>true, 'coverage'=>ServiceCatalog::coverage($tid, $mid)]);
    } catch (Throwable $e) {
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

// ── API: save master ─────────────────────────────────
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (empty($hqIsOwner) && empty($hqIsManager)) {
        echo json_encode(['error'=>'Akses ditolak']); exit;
    }
    try {
        $id = (int)($_POST['id'] ?? 0) ?: null;
        $newId = ServiceCatalog::saveMaster($tid, $_POST, $id);
        try { logAudit($id?'update':'create', 'layanan_master', "Master layanan: ".($_POST['nama']??''), (string)$newId); } catch (Throwable) {}
        echo json_encode(['ok'=>true, 'id'=>$newId]);
    } catch (Throwable $e) {
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

// ── API: delete master ───────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (empty($hqIsOwner) && empty($hqIsManager)) {
        echo json_encode(['error'=>'Akses ditolak']); exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    $removeOutlets = !empty($_POST['remove_outlets']);
    try {
        ServiceCatalog::deleteMaster($tid, $id, $removeOutlets);
        try { logAudit('delete', 'layanan_master', "Hapus master layanan", (string)$id); } catch (Throwable) {}
        echo json_encode(['ok'=>true]);
    } catch (Throwable $e) {
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

// ── API: push ke outlet ──────────────────────────────
if ($action === 'push' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (empty($hqIsOwner) && empty($hqIsManager)) {
        echo json_encode(['error'=>'Akses ditolak']); exit;
    }
    $mid = (int)($_POST['master_id'] ?? 0);
    $outletIds = array_map('intval', (array)($_POST['outlet_ids'] ?? []));
    $overwrite = !empty($_POST['overwrite_overrides']);
    if (!$mid || !$outletIds) {
        echo json_encode(['error'=>'Pilih minimal 1 outlet.']); exit;
    }
    try {
        $res = ServiceCatalog::pushToOutlets($tid, $mid, $outletIds, $overwrite);
        try { logAudit('push', 'layanan_master', "Push layanan ke ".count($outletIds)." outlet", (string)$mid); } catch (Throwable) {}
        echo json_encode(['ok'=>true, 'result'=>$res]);
    } catch (Throwable $e) {
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

// Outlets untuk push selector
$outlets = $db->prepare("SELECT id, nama_outlet FROM outlets WHERE tenant_id=? AND status IN ('trial','grace','active') ORDER BY is_main DESC, nama_outlet");
$outlets->execute([$tid]);
$outletList = $outlets->fetchAll(PDO::FETCH_ASSOC);

$canEdit = !empty($hqIsOwner) || !empty($hqIsManager);

require __DIR__ . '/_layout_open.php';
?>
<style>
.lyn-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px}
.lyn-head h1{font-size:1.3rem;font-weight:800;color:#0F1C3A}
.lyn-head p{font-size:13px;color:#6B7280;margin-top:3px}
.btn{padding:9px 16px;border-radius:9px;font-weight:700;font-size:13px;border:none;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.btn-primary{background:#0F1C3A;color:#fff}
.btn-primary:hover{background:#1a2d52}
.btn-light{background:#fff;color:#0F1C3A;border:1px solid #E5E9F2}
.btn-sm{padding:6px 11px;font-size:12px}

.lyn-table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.05)}
.lyn-table th{background:#F7F8FC;color:#6B7280;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:11px 14px;text-align:left}
.lyn-table td{padding:12px 14px;border-top:1px solid #F0F1F4;font-size:13px;color:#1F2937}
.lyn-table tr:hover td{background:#FAFBFC}
.lyn-nama{font-weight:700;color:#0F1C3A}
.lyn-kat{font-size:11px;color:#6B7280}
.lyn-harga{font-family:monospace;font-weight:800;color:#0F1C3A}
.lyn-cov{font-size:12px}
.lyn-cov-bar{display:inline-block;background:#E5E9F2;border-radius:100px;height:6px;width:60px;overflow:hidden;vertical-align:middle;margin-right:6px}
.lyn-cov-fill{height:100%;background:#35E8D5}
.tag{font-size:10px;font-weight:700;padding:2px 7px;border-radius:100px}
.tag-override{background:#FEF3C7;color:#92400E}
.tag-off{background:#F3F4F6;color:#9CA3AF}

.modal-bg{display:none;position:fixed;inset:0;background:rgba(15,28,58,.5);z-index:1000;align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto}
.modal-bg.open{display:flex}
.modal{background:#fff;border-radius:14px;width:100%;max-width:480px;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.modal h3{font-size:16px;font-weight:800;color:#0F1C3A;margin-bottom:16px}
.fld{margin-bottom:14px}
.fld label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}
.fld input,.fld select{width:100%;padding:9px 12px;border:1px solid #E5E9F2;border-radius:8px;font-family:inherit;font-size:14px}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.chk-row{display:flex;align-items:center;gap:8px;font-size:13px;color:#374151}
.modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:20px}

.push-outlets{display:flex;flex-direction:column;gap:6px;max-height:240px;overflow-y:auto;margin-bottom:10px}
.push-outlet{display:flex;align-items:center;gap:8px;padding:9px 11px;border:1px solid #E5E9F2;border-radius:8px;font-size:13px;cursor:pointer}
.push-outlet:hover{background:#F7F8FC}
.push-outlet .ov{margin-left:auto;font-size:10px}
.empty{text-align:center;padding:50px 20px;color:#6B7280;background:#fff;border-radius:14px}
.empty .ico{font-size:48px;margin-bottom:12px}
</style>

<div class="lyn-head">
  <div>
    <h1>🧺 Master Katalog Layanan</h1>
    <p>Kelola layanan & harga default dari pusat, lalu push ke outlet yang dipilih.</p>
  </div>
  <?php if ($canEdit): ?>
  <button class="btn btn-primary" onclick="openForm()">+ Layanan Baru</button>
  <?php endif; ?>
</div>

<div id="lynListWrap">
  <div class="empty"><div class="ico">⏳</div>Memuat katalog…</div>
</div>

<!-- FORM MODAL -->
<div class="modal-bg" id="formModal">
  <div class="modal">
    <h3 id="formTitle">Layanan Baru</h3>
    <input type="hidden" id="fId">
    <div class="fld">
      <label>Nama Layanan</label>
      <input type="text" id="fNama" placeholder="cuci Kiloan Reguler">
    </div>
    <div class="fld-row">
      <div class="fld">
        <label>Kategori</label>
        <input type="text" id="fKategori" placeholder="Kiloan" value="Umum">
      </div>
      <div class="fld">
        <label>Satuan</label>
        <input type="text" id="fSatuan" placeholder="kg" value="kg">
      </div>
    </div>
    <div class="fld">
      <label>Segmen</label>
      <select id="fSegmen" style="width:100%;padding:9px 12px;border:1px solid #E5E9F2;border-radius:8px;font-family:inherit;font-size:14px">
        <option value="kiloan">Kiloan</option>
        <option value="self_service">Self-Service</option>
        <option value="b2b">B2B</option>
        <option value="satuan">Satuan</option>
        <option value="lainnya">Lainnya</option>
      </select>
    </div>
    <div class="fld-row">
      <div class="fld">
        <label>Harga Default (Rp)</label>
        <input type="number" id="fHarga" placeholder="7000" min="0">
      </div>
      <div class="fld">
        <label>Urutan</label>
        <input type="number" id="fUrutan" value="0" min="0">
      </div>
    </div>
    <div class="fld">
      <label class="chk-row"><input type="checkbox" id="fAllowOverride"> Izinkan outlet adjust harga</label>
    </div>
    <div class="fld" id="overridePctWrap" style="display:none">
      <label>Batas Adjust (±%)</label>
      <input type="number" id="fOverridePct" value="10" min="0" max="100" step="0.5">
    </div>
    <div class="modal-actions">
      <button class="btn btn-light" onclick="closeModal('formModal')">Batal</button>
      <button class="btn btn-primary" onclick="saveMaster()">Simpan</button>
    </div>
  </div>
</div>

<!-- PUSH MODAL -->
<div class="modal-bg" id="pushModal">
  <div class="modal">
    <h3>📤 Push ke Outlet</h3>
    <p style="font-size:13px;color:#6B7280;margin-bottom:14px" id="pushSubtitle"></p>
    <input type="hidden" id="pushMasterId">
    <div style="display:flex;justify-content:space-between;margin-bottom:8px">
      <button class="btn btn-light btn-sm" onclick="toggleAllOutlets(true)">Pilih Semua</button>
      <button class="btn btn-light btn-sm" onclick="toggleAllOutlets(false)">Kosongkan</button>
    </div>
    <div class="push-outlets" id="pushOutlets"></div>
    <label class="chk-row" style="margin-bottom:14px">
      <input type="checkbox" id="pushOverwrite">
      Timpa harga walaupun outlet sudah override
    </label>
    <div class="modal-actions">
      <button class="btn btn-light" onclick="closeModal('pushModal')">Batal</button>
      <button class="btn btn-primary" onclick="doPush()">📤 Push Sekarang</button>
    </div>
  </div>
</div>

<script>
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const fmtRp = n => 'Rp ' + Number(n||0).toLocaleString('id-ID');
const CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
const OUTLETS = <?= json_encode($outletList) ?>;

async function loadList(){
  const wrap = document.getElementById('lynListWrap');
  try {
    const r = await fetch('/ERP/harpy/hq/layanan.php?action=list');
    const d = await r.json();
    if (d.error) { wrap.innerHTML = `<div class="empty"><div class="ico">⚠️</div>${esc(d.error)}</div>`; return; }
    if (!d.master || d.master.length === 0) {
      wrap.innerHTML = `<div class="empty"><div class="ico">🧺</div><div>Belum ada layanan di master katalog</div>
        ${CAN_EDIT?'<div style="margin-top:10px"><button class="btn btn-primary" onclick="openForm()">+ Buat Layanan Pertama</button></div>':''}</div>`;
      return;
    }
    wrap.innerHTML = `
      <table class="lyn-table">
        <thead><tr>
          <th>Layanan</th><th>Harga Default</th><th>Override</th><th>Coverage Outlet</th><th style="text-align:right">Aksi</th>
        </tr></thead>
        <tbody>${d.master.map(rowHtml).join('')}</tbody>
      </table>`;
  } catch (e) {
    wrap.innerHTML = `<div class="empty"><div class="ico">⚠️</div>Gagal: ${esc(e.message)}</div>`;
  }
}

function rowHtml(m){
  const pct = m.outlet_total > 0 ? Math.round(m.outlet_has / m.outlet_total * 100) : 0;
  const overrideTag = m.allow_override == 1
    ? `<span class="tag tag-override">±${m.override_max_pct}%</span>`
    : `<span class="tag tag-off">terkunci</span>`;
  return `
    <tr>
      <td>
        <div class="lyn-nama">${esc(m.nama)} ${m.is_active==0?'<span class="tag tag-off">nonaktif</span>':''}</div>
        <div class="lyn-kat">${esc(m.kategori)} · per ${esc(m.satuan)}</div>
      </td>
      <td class="lyn-harga">${fmtRp(m.harga_default)}</td>
      <td>${overrideTag}</td>
      <td class="lyn-cov">
        <span class="lyn-cov-bar"><span class="lyn-cov-fill" style="width:${pct}%"></span></span>
        ${m.outlet_has}/${m.outlet_total} outlet
        ${m.outlet_override>0?`<div style="font-size:10px;color:#92400E;margin-top:2px">${m.outlet_override} override harga</div>`:''}
      </td>
      <td style="text-align:right;white-space:nowrap">
        ${CAN_EDIT?`
        <button class="btn btn-light btn-sm" onclick='openPush(${m.id}, ${JSON.stringify(m.nama)})'>📤 Push</button>
        <button class="btn btn-light btn-sm" onclick='openForm(${JSON.stringify(m)})'>✏️</button>
        <button class="btn btn-light btn-sm" onclick="delMaster(${m.id}, ${JSON.stringify(m.nama).replace(/"/g,'&quot;')})">🗑️</button>
        `:'<span style="color:#9CA3AF;font-size:12px">read-only</span>'}
      </td>
    </tr>`;
}

function openForm(m){
  document.getElementById('formTitle').textContent = m ? 'Edit Layanan' : 'Layanan Baru';
  document.getElementById('fId').value = m ? m.id : '';
  document.getElementById('fNama').value = m ? m.nama : '';
  document.getElementById('fKategori').value = m ? m.kategori : 'Umum';
  document.getElementById('fSatuan').value = m ? m.satuan : 'kg';
  document.getElementById('fHarga').value = m ? m.harga_default : '';
  document.getElementById('fUrutan').value = m ? m.urutan : 0;
  document.getElementById('fSegmen').value = m ? (m.segmen || 'kiloan') : 'kiloan';
  document.getElementById('fAllowOverride').checked = m ? m.allow_override == 1 : false;
  document.getElementById('fOverridePct').value = m ? m.override_max_pct : 10;
  toggleOverridePct();
  document.getElementById('formModal').classList.add('open');
}
function toggleOverridePct(){
  document.getElementById('overridePctWrap').style.display =
    document.getElementById('fAllowOverride').checked ? 'block' : 'none';
}
document.getElementById('fAllowOverride').addEventListener('change', toggleOverridePct);

function closeModal(id){ document.getElementById(id).classList.remove('open'); }

async function saveMaster(){
  const fd = new FormData();
  fd.append('id', document.getElementById('fId').value);
  fd.append('nama', document.getElementById('fNama').value);
  fd.append('kategori', document.getElementById('fKategori').value);
  fd.append('satuan', document.getElementById('fSatuan').value);
  fd.append('harga_default', document.getElementById('fHarga').value);
  fd.append('urutan', document.getElementById('fUrutan').value);
  fd.append('segmen', document.getElementById('fSegmen').value);
  fd.append('is_active', 1);
  fd.append('allow_override', document.getElementById('fAllowOverride').checked ? 1 : 0);
  fd.append('override_max_pct', document.getElementById('fOverridePct').value);
  try {
    const r = await fetch('/ERP/harpy/hq/layanan.php?action=save', {method:'POST', body:fd});
    const d = await r.json();
    if (d.error) { alert('⚠️ ' + d.error); return; }
    closeModal('formModal');
    loadList();
  } catch (e) { alert('Gagal: ' + e.message); }
}

async function delMaster(id, nama){
  if (!confirm(`Hapus layanan "${nama}" dari master?\nLayanan di outlet juga akan dinonaktifkan.`)) return;
  const fd = new FormData();
  fd.append('id', id); fd.append('remove_outlets', 1);
  try {
    const r = await fetch('/ERP/harpy/hq/layanan.php?action=delete', {method:'POST', body:fd});
    const d = await r.json();
    if (d.error) { alert('⚠️ ' + d.error); return; }
    loadList();
  } catch (e) { alert('Gagal: ' + e.message); }
}

// ── PUSH ──
function openPush(mid, nama){
  document.getElementById('pushMasterId').value = mid;
  document.getElementById('pushSubtitle').textContent = `Layanan: ${nama}`;
  const wrap = document.getElementById('pushOutlets');
  wrap.innerHTML = OUTLETS.map(o => `
    <label class="push-outlet">
      <input type="checkbox" class="push-cb" value="${o.id}" checked>
      ${esc(o.nama_outlet)}
    </label>`).join('');
  document.getElementById('pushOverwrite').checked = false;
  document.getElementById('pushModal').classList.add('open');
}
function toggleAllOutlets(state){
  document.querySelectorAll('.push-cb').forEach(cb => cb.checked = state);
}
async function doPush(){
  const mid = document.getElementById('pushMasterId').value;
  const ids = [...document.querySelectorAll('.push-cb:checked')].map(cb => cb.value);
  if (ids.length === 0) { alert('Pilih minimal 1 outlet'); return; }
  const fd = new FormData();
  fd.append('master_id', mid);
  ids.forEach(id => fd.append('outlet_ids[]', id));
  fd.append('overwrite_overrides', document.getElementById('pushOverwrite').checked ? 1 : 0);
  try {
    const r = await fetch('/ERP/harpy/hq/layanan.php?action=push', {method:'POST', body:fd});
    const d = await r.json();
    if (d.error) { alert('⚠️ ' + d.error); return; }
    const res = d.result;
    alert(`✅ Push selesai!\nBaru: ${res.created} · Update: ${res.updated} · Skip (override): ${res.skipped_override}`);
    closeModal('pushModal');
    loadList();
  } catch (e) { alert('Gagal: ' + e.message); }
}

loadList();
</script>

<?php require __DIR__ . '/_layout_close.php'; ?>
