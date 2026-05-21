<?php
// ══════════════════════════════════════════════════════
// hq/checklist.php — Checklist Harian + Monitor Compliance
// ══════════════════════════════════════════════════════

$activePage = 'hq-checklist';
$pageTitle  = 'Checklist & Compliance';

define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';
require_once ROOT . '/core/Checklist.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];
$action = $_GET['action'] ?? '';
$canEdit = !empty($hqIsOwner) || !empty($hqIsManager);

// ── API: list templates ──────────────────────────────
if ($action === 'templates') {
    header('Content-Type: application/json');
    try { echo json_encode(['ok'=>true, 'templates'=>Checklist::listTemplates($tid)]); }
    catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: save template ───────────────────────────────
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$canEdit) { echo json_encode(['error'=>'Akses ditolak']); exit; }
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    try {
        $id = (int)($d['id'] ?? 0) ?: null;
        $newId = Checklist::saveTemplate($tid, $d, $id);
        try { logAudit($id?'update':'create', 'checklist', 'Template: '.($d['judul']??''), (string)$newId); } catch (Throwable) {}
        echo json_encode(['ok'=>true, 'id'=>$newId]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: delete template ─────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$canEdit) { echo json_encode(['error'=>'Akses ditolak']); exit; }
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    try {
        Checklist::deleteTemplate($tid, (int)($d['id'] ?? 0));
        echo json_encode(['ok'=>true]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: compliance matrix ───────────────────────────
if ($action === 'compliance') {
    header('Content-Type: application/json');
    $tgl = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['tanggal'] ?? '') ? $_GET['tanggal'] : date('Y-m-d');
    try { echo json_encode(['ok'=>true, 'tanggal'=>$tgl, 'data'=>Checklist::compliance($tid, $tgl)]); }
    catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

require __DIR__ . '/_layout_open.php';
?>
<style>
.cl-tabs{display:flex;gap:6px;margin-bottom:18px}
.cl-tab{padding:9px 18px;border-radius:9px;font-size:13px;font-weight:700;border:1px solid #E5E9F2;background:#fff;color:#6B7280;cursor:pointer;font-family:inherit}
.cl-tab.active{background:#0F1C3A;color:#fff;border-color:#0F1C3A}
.btn{padding:9px 16px;border-radius:9px;font-weight:700;font-size:13px;border:none;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.btn-primary{background:#0F1C3A;color:#fff}.btn-primary:hover{background:#1a2d52}
.btn-light{background:#fff;color:#0F1C3A;border:1px solid #E5E9F2}
.btn-sm{padding:6px 11px;font-size:12px}
.panel{background:#fff;border-radius:12px;padding:20px;box-shadow:0 1px 6px rgba(0,0,0,.05);margin-bottom:16px}
.tpl-card{border:1px solid #EEF1F8;border-radius:10px;padding:16px;margin-bottom:10px}
.tpl-card h4{font-size:14px;font-weight:800;color:#0F1C3A;margin-bottom:4px}
.tpl-card .desc{font-size:12px;color:#6B7280;margin-bottom:8px}
.tpl-items{font-size:12px;color:#374151;line-height:1.7}
.tpl-items .req{color:#EF4444;font-weight:700}
.head{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.head h1{font-size:1.3rem;font-weight:800;color:#0F1C3A}

.cmp-table{width:100%;border-collapse:collapse;font-size:13px}
.cmp-table th{background:#F7F8FC;padding:10px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;color:#6B7280}
.cmp-table td{padding:10px;border-top:1px solid #F0F1F4}
.cmp-cell{text-align:center;border-radius:6px;padding:6px;font-size:11px;font-weight:700}
.cmp-yes{background:#D1FAE5;color:#065F46}
.cmp-no{background:#FEE2E2;color:#991B1B}
.cmp-partial{background:#FEF3C7;color:#92400E}

.modal-bg{display:none;position:fixed;inset:0;background:rgba(15,28,58,.5);z-index:1000;align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto}
.modal-bg.open{display:flex}
.modal{background:#fff;border-radius:14px;width:100%;max-width:540px;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.modal h3{font-size:16px;font-weight:800;color:#0F1C3A;margin-bottom:16px}
.fld{margin-bottom:14px}
.fld label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}
.fld input[type=text],.fld textarea{width:100%;padding:9px 12px;border:1px solid #E5E9F2;border-radius:8px;font-family:inherit;font-size:14px}
.item-row{display:flex;gap:8px;align-items:center;margin-bottom:6px}
.item-row input[type=text]{flex:1}
.item-row label{font-size:11px;color:#6B7280;white-space:nowrap;display:flex;align-items:center;gap:3px}
.modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:18px}
.empty{text-align:center;padding:40px;color:#6B7280}
</style>

<div class="head">
  <h1>📋 Checklist & Compliance</h1>
  <?php if ($canEdit): ?><button class="btn btn-primary" onclick="openTpl()">+ Template Baru</button><?php endif; ?>
</div>

<div class="cl-tabs">
  <button class="cl-tab active" id="tabTpl" onclick="switchTab('tpl')">📝 Template</button>
  <button class="cl-tab" id="tabCmp" onclick="switchTab('cmp')">📊 Monitor Compliance</button>
</div>

<!-- TAB: TEMPLATE -->
<div id="paneTpl">
  <div id="tplList"><div class="empty">⏳ Memuat…</div></div>
</div>

<!-- TAB: COMPLIANCE -->
<div id="paneCmp" style="display:none">
  <div class="panel">
    <div style="display:flex;gap:10px;align-items:center;margin-bottom:14px">
      <label style="font-size:13px;font-weight:600;color:#374151">Tanggal:</label>
      <input type="date" id="cmpDate" value="<?= date('Y-m-d') ?>" onchange="loadCompliance()"
             style="padding:7px 12px;border:1px solid #E5E9F2;border-radius:8px;font-family:inherit">
      <button class="btn btn-light btn-sm" onclick="loadCompliance()">↻ Refresh</button>
    </div>
    <div id="cmpContent"><div class="empty">⏳ Memuat…</div></div>
  </div>
</div>

<!-- TEMPLATE MODAL -->
<div class="modal-bg" id="tplModal">
  <div class="modal">
    <h3 id="tplTitle">Template Baru</h3>
    <input type="hidden" id="tId">
    <div class="fld">
      <label>Judul Checklist</label>
      <input type="text" id="tJudul" placeholder="Checklist Buka Toko Pagi">
    </div>
    <div class="fld">
      <label>Deskripsi (opsional)</label>
      <input type="text" id="tDesk" placeholder="Diisi sebelum mulai operasional">
    </div>
    <div class="fld">
      <label>Item Checklist</label>
      <div id="itemsWrap"></div>
      <button class="btn btn-light btn-sm" onclick="addItem()" style="margin-top:6px">+ Tambah Item</button>
    </div>
    <div class="modal-actions">
      <button class="btn btn-light" onclick="closeTpl()">Batal</button>
      <button class="btn btn-primary" onclick="saveTpl()">Simpan</button>
    </div>
  </div>
</div>

<script>
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const CAN_EDIT = <?= $canEdit ? 'true':'false' ?>;

function switchTab(t){
  document.getElementById('tabTpl').classList.toggle('active', t==='tpl');
  document.getElementById('tabCmp').classList.toggle('active', t==='cmp');
  document.getElementById('paneTpl').style.display = t==='tpl'?'block':'none';
  document.getElementById('paneCmp').style.display = t==='cmp'?'block':'none';
  if (t==='cmp') loadCompliance();
}

// ── Templates ──
async function loadTemplates(){
  const wrap = document.getElementById('tplList');
  try {
    const r = await fetch('/ERP/harpy/hq/checklist.php?action=templates');
    const d = await r.json();
    if (d.error){ wrap.innerHTML = `<div class="empty">⚠️ ${esc(d.error)}</div>`; return; }
    if (!d.templates.length){ wrap.innerHTML = `<div class="panel empty">Belum ada template checklist.${CAN_EDIT?'<br><button class="btn btn-primary" style="margin-top:10px" onclick="openTpl()">+ Buat Template</button>':''}</div>`; return; }
    wrap.innerHTML = '<div class="panel">' + d.templates.map(t => `
      <div class="tpl-card">
        <h4>${esc(t.judul)} ${t.is_active==0?'<span style="font-size:10px;color:#9CA3AF">(nonaktif)</span>':''}</h4>
        ${t.deskripsi?`<div class="desc">${esc(t.deskripsi)}</div>`:''}
        <div class="tpl-items">${t.items.map((it,i)=>`${i+1}. ${esc(it.text)} ${it.required?'<span class="req">*wajib</span>':''}`).join('<br>')}</div>
        ${CAN_EDIT?`<div style="margin-top:10px;display:flex;gap:6px">
          <button class="btn btn-light btn-sm" onclick='openTpl(${JSON.stringify(t)})'>✏️ Edit</button>
          <button class="btn btn-light btn-sm" onclick="delTpl(${t.id})">🗑️</button>
        </div>`:''}
      </div>`).join('') + '</div>';
  } catch(e){ wrap.innerHTML = `<div class="empty">⚠️ ${esc(e.message)}</div>`; }
}

function openTpl(t){
  document.getElementById('tplTitle').textContent = t ? 'Edit Template':'Template Baru';
  document.getElementById('tId').value = t?t.id:'';
  document.getElementById('tJudul').value = t?t.judul:'';
  document.getElementById('tDesk').value = t?(t.deskripsi||''):'';
  const wrap = document.getElementById('itemsWrap');
  wrap.innerHTML = '';
  const items = t && t.items.length ? t.items : [{text:'',required:0}];
  items.forEach(it => addItem(it.text, it.required));
  document.getElementById('tplModal').classList.add('open');
}
function addItem(text='', required=0){
  const div = document.createElement('div');
  div.className = 'item-row';
  div.innerHTML = `
    <input type="text" class="item-text" placeholder="Item checklist…" value="${esc(text)}">
    <label><input type="checkbox" class="item-req" ${required?'checked':''}> wajib</label>
    <button class="btn btn-light btn-sm" onclick="this.parentElement.remove()">✕</button>`;
  document.getElementById('itemsWrap').appendChild(div);
}
function closeTpl(){ document.getElementById('tplModal').classList.remove('open'); }

async function saveTpl(){
  const items = [...document.querySelectorAll('#itemsWrap .item-row')].map(row => ({
    text: row.querySelector('.item-text').value.trim(),
    required: row.querySelector('.item-req').checked ? 1 : 0,
  })).filter(it => it.text);
  if (!items.length){ alert('Minimal 1 item'); return; }
  const body = {
    id: document.getElementById('tId').value,
    judul: document.getElementById('tJudul').value,
    deskripsi: document.getElementById('tDesk').value,
    items, is_active: 1,
  };
  try {
    const r = await fetch('/ERP/harpy/hq/checklist.php?action=save', {method:'POST', body:JSON.stringify(body)});
    const d = await r.json();
    if (d.error){ alert('⚠️ '+d.error); return; }
    closeTpl(); loadTemplates();
  } catch(e){ alert('Gagal: '+e.message); }
}
async function delTpl(id){
  if (!confirm('Nonaktifkan template ini?')) return;
  const r = await fetch('/ERP/harpy/hq/checklist.php?action=delete', {method:'POST', body:JSON.stringify({id})});
  const d = await r.json();
  if (d.error){ alert('⚠️ '+d.error); return; }
  loadTemplates();
}

// ── Compliance ──
async function loadCompliance(){
  const tgl = document.getElementById('cmpDate').value;
  const wrap = document.getElementById('cmpContent');
  wrap.innerHTML = '<div class="empty">⏳ Memuat…</div>';
  try {
    const r = await fetch(`/ERP/harpy/hq/checklist.php?action=compliance&tanggal=${tgl}`);
    const d = await r.json();
    if (d.error){ wrap.innerHTML = `<div class="empty">⚠️ ${esc(d.error)}</div>`; return; }
    const {templates, outlets, matrix} = d.data;
    if (!templates.length){ wrap.innerHTML = '<div class="empty">Belum ada template aktif.</div>'; return; }
    if (!outlets.length){ wrap.innerHTML = '<div class="empty">Belum ada outlet.</div>'; return; }

    let html = '<div style="overflow-x:auto"><table class="cmp-table"><thead><tr><th>Outlet</th>';
    templates.forEach(t => html += `<th style="text-align:center">${esc(t.judul)}</th>`);
    html += '</tr></thead><tbody>';
    outlets.forEach(o => {
      html += `<tr><td><strong>${esc(o.nama_outlet)}</strong></td>`;
      templates.forEach(t => {
        const cell = matrix[o.id] && matrix[o.id][t.id] ? matrix[o.id][t.id] : {submitted:false};
        if (!cell.submitted){
          html += `<td><div class="cmp-cell cmp-no">✗ Belum</div></td>`;
        } else {
          const full = cell.checked >= cell.total;
          const cls = full ? 'cmp-yes' : 'cmp-partial';
          const icon = full ? '✓' : '◐';
          html += `<td><div class="cmp-cell ${cls}" title="oleh ${esc(cell.by||'-')} · ${esc(cell.at||'')}">${icon} ${cell.checked}/${cell.total}</div></td>`;
        }
      });
      html += '</tr>';
    });
    html += '</tbody></table></div>';
    wrap.innerHTML = html;
  } catch(e){ wrap.innerHTML = `<div class="empty">⚠️ ${esc(e.message)}</div>`; }
}

loadTemplates();
</script>

<?php require __DIR__ . '/_layout_close.php'; ?>
