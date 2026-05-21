<?php
// ══════════════════════════════════════════════════════
// hq/broadcast.php — Broadcast SOP/Instruksi ke staff outlet via WA
// ══════════════════════════════════════════════════════

$activePage = 'hq-broadcast';
$pageTitle  = 'Broadcast SOP';

define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';
require_once ROOT . '/core/Broadcast.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];
$action = $_GET['action'] ?? '';
$canSend = !empty($hqIsOwner) || !empty($hqIsManager);

// ── API: preview recipients ──────────────────────────
if ($action === 'preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $outletIds = array_map('intval', $d['outlet_ids'] ?? []);
    try {
        echo json_encode(['ok'=>true, 'recipients'=>Broadcast::recipientsForOutlets($tid, $outletIds)]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: create broadcast ────────────────────────────
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$canSend) { echo json_encode(['error'=>'Akses ditolak']); exit; }
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $u = currentUser();
    try {
        $bid = Broadcast::create($tid, $d['judul'] ?? '', $d['pesan'] ?? '',
            array_map('intval', $d['outlet_ids'] ?? []),
            $u ? (int)$u['id'] : null, $u['nama'] ?? null);
        try { logAudit('create', 'broadcast', 'Broadcast: '.($d['judul']??''), (string)$bid); } catch (Throwable) {}
        echo json_encode(['ok'=>true, 'id'=>$bid]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: detail broadcast (recipients + wa links) ────
if ($action === 'detail') {
    header('Content-Type: application/json');
    $bid = (int)($_GET['id'] ?? 0);
    try {
        $b = Broadcast::get($tid, $bid);
        if (!$b) { echo json_encode(['error'=>'Tidak ditemukan']); exit; }
        echo json_encode(['ok'=>true, 'broadcast'=>$b]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: history ─────────────────────────────────────
if ($action === 'history') {
    header('Content-Type: application/json');
    try { echo json_encode(['ok'=>true, 'history'=>Broadcast::history($tid)]); }
    catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: mark sent ───────────────────────────────────
if ($action === 'mark_sent' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    echo json_encode(['ok'=>Broadcast::markSent($tid, (int)($d['recipient_id'] ?? 0))]);
    exit;
}

$outlets = $db->prepare("SELECT id, nama_outlet FROM outlets WHERE tenant_id=? AND status IN ('trial','grace','active') ORDER BY is_main DESC, nama_outlet");
$outlets->execute([$tid]);
$outletList = $outlets->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/_layout_open.php';
?>
<style>
.bc-grid{display:grid;grid-template-columns:1fr 360px;gap:18px}
.panel{background:#fff;border:1px solid #EEF1F8;border-radius:14px;padding:20px;box-shadow:0 1px 6px rgba(0,0,0,.05)}
.panel h3{font-size:14px;font-weight:800;color:#0F1C3A;margin-bottom:14px}
.btn{padding:9px 16px;border-radius:9px;font-weight:700;font-size:13px;border:none;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.btn-primary{background:#0F1C3A;color:#fff}.btn-primary:hover{background:#1a2d52}
.btn-light{background:#fff;color:#0F1C3A;border:1px solid #E5E9F2}
.btn-sm{padding:6px 11px;font-size:12px}
.btn-wa{background:#25D366;color:#fff}.btn-wa:hover{background:#1DA851}
.fld{margin-bottom:14px}
.fld label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}
.fld input[type=text],.fld textarea{width:100%;padding:10px 12px;border:1px solid #E5E9F2;border-radius:8px;font-family:inherit;font-size:14px}
.fld textarea{min-height:120px;resize:vertical;line-height:1.5}
.outlet-chk{display:flex;flex-wrap:wrap;gap:8px}
.outlet-chk label{display:flex;align-items:center;gap:6px;padding:7px 12px;border:1px solid #E5E9F2;border-radius:8px;font-size:13px;cursor:pointer}
.outlet-chk label:hover{background:#F7F8FC}
.recip-preview{font-size:12px;color:#6B7280;margin-top:8px}
.hist-item{padding:12px;border:1px solid #EEF1F8;border-radius:9px;margin-bottom:8px;cursor:pointer;transition:background .15s}
.hist-item:hover{background:#F7F8FC}
.hist-judul{font-size:13px;font-weight:700;color:#0F1C3A}
.hist-meta{font-size:11px;color:#9CA3AF;margin-top:3px}
.hist-prog{font-size:11px;font-weight:700;margin-top:4px}
.recip-row{display:flex;align-items:center;gap:8px;padding:9px 11px;border:1px solid #EEF1F8;border-radius:8px;margin-bottom:6px}
.recip-info{flex:1;min-width:0}
.recip-nama{font-size:13px;font-weight:700;color:#0F1C3A}
.recip-sub{font-size:11px;color:#9CA3AF}
.recip-sent{color:#10B981;font-size:11px;font-weight:700}
.modal-bg{display:none;position:fixed;inset:0;background:rgba(15,28,58,.5);z-index:1000;align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto}
.modal-bg.open{display:flex}
.modal{background:#fff;border-radius:14px;width:100%;max-width:520px;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.modal h3{font-size:16px;font-weight:800;color:#0F1C3A;margin-bottom:6px}
.empty{text-align:center;padding:30px;color:#9CA3AF;font-size:13px}
@media(max-width:900px){.bc-grid{grid-template-columns:1fr}}
</style>

<h1 style="font-size:1.3rem;font-weight:800;color:#0F1C3A;margin-bottom:4px">📢 Broadcast SOP & Instruksi</h1>
<p style="font-size:13px;color:#6B7280;margin-bottom:18px">Kirim pesan/SOP ke staff outlet via WhatsApp. Pilih outlet, tulis pesan, kirim per penerima.</p>

<div class="bc-grid">
  <!-- COMPOSE -->
  <div class="panel">
    <h3>✍️ Tulis Broadcast</h3>
    <?php if (!$canSend): ?>
      <div class="empty">Hanya owner/manager yang bisa broadcast.</div>
    <?php else: ?>
    <div class="fld">
      <label>Judul</label>
      <input type="text" id="bcJudul" placeholder="Update SOP Penanganan Komplain">
    </div>
    <div class="fld">
      <label>Isi Pesan</label>
      <textarea id="bcPesan" placeholder="Halo tim, mulai hari ini..."></textarea>
    </div>
    <div class="fld">
      <label>Outlet Tujuan</label>
      <div class="outlet-chk" id="bcOutlets">
        <?php foreach ($outletList as $o): ?>
          <label><input type="checkbox" class="bc-ocb" value="<?= (int)$o['id'] ?>" checked onchange="previewRecip()"> <?= htmlspecialchars($o['nama_outlet']) ?></label>
        <?php endforeach; ?>
      </div>
      <div class="recip-preview" id="recipPreview">Menghitung penerima…</div>
    </div>
    <button class="btn btn-primary" onclick="createBroadcast()">📢 Buat Broadcast</button>
    <?php endif; ?>
  </div>

  <!-- HISTORY -->
  <div class="panel">
    <h3>🕘 Riwayat</h3>
    <div id="histList"><div class="empty">⏳ Memuat…</div></div>
  </div>
</div>

<!-- DETAIL MODAL -->
<div class="modal-bg" id="detailModal">
  <div class="modal">
    <h3 id="dmJudul">-</h3>
    <div id="dmMeta" style="font-size:12px;color:#9CA3AF;margin-bottom:12px"></div>
    <div id="dmPesan" style="background:#F7F8FC;border-radius:8px;padding:12px;font-size:13px;line-height:1.55;white-space:pre-wrap;margin-bottom:16px"></div>
    <div style="font-size:12px;font-weight:700;color:#374151;margin-bottom:8px">Penerima:</div>
    <div id="dmRecipients"></div>
    <div class="modal-actions" style="display:flex;justify-content:flex-end;margin-top:16px">
      <button class="btn btn-light" onclick="closeDetail()">Tutup</button>
    </div>
  </div>
</div>

<script>
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
let curPesan = '';

function selectedOutlets(){
  return [...document.querySelectorAll('.bc-ocb:checked')].map(cb => parseInt(cb.value));
}

async function previewRecip(){
  const ids = selectedOutlets();
  const el = document.getElementById('recipPreview');
  if (!ids.length){ el.textContent = 'Pilih minimal 1 outlet.'; return; }
  try {
    const r = await fetch('/ERP/harpy/hq/broadcast.php?action=preview', {method:'POST', body:JSON.stringify({outlet_ids:ids})});
    const d = await r.json();
    if (d.error){ el.textContent = '⚠️ '+d.error; return; }
    el.innerHTML = `📱 <strong>${d.recipients.length} penerima</strong> dengan nomor WA di outlet terpilih`;
  } catch(e){ el.textContent = '⚠️ '+e.message; }
}

async function createBroadcast(){
  const judul = document.getElementById('bcJudul').value.trim();
  const pesan = document.getElementById('bcPesan').value.trim();
  const ids = selectedOutlets();
  if (!judul || !pesan){ alert('Judul & pesan wajib diisi'); return; }
  if (!ids.length){ alert('Pilih minimal 1 outlet'); return; }
  try {
    const r = await fetch('/ERP/harpy/hq/broadcast.php?action=create', {method:'POST',
      body:JSON.stringify({judul, pesan, outlet_ids:ids})});
    const d = await r.json();
    if (d.error){ alert('⚠️ '+d.error); return; }
    document.getElementById('bcJudul').value='';
    document.getElementById('bcPesan').value='';
    loadHistory();
    openDetail(d.id);
  } catch(e){ alert('Gagal: '+e.message); }
}

async function loadHistory(){
  const wrap = document.getElementById('histList');
  try {
    const r = await fetch('/ERP/harpy/hq/broadcast.php?action=history');
    const d = await r.json();
    if (d.error){ wrap.innerHTML = `<div class="empty">⚠️ ${esc(d.error)}</div>`; return; }
    if (!d.history.length){ wrap.innerHTML = '<div class="empty">Belum ada broadcast.</div>'; return; }
    wrap.innerHTML = d.history.map(b => {
      const pct = b.total>0 ? Math.round(b.sent/b.total*100) : 0;
      return `<div class="hist-item" onclick="openDetail(${b.id})">
        <div class="hist-judul">${esc(b.judul)}</div>
        <div class="hist-meta">${esc(b.created_by_nama||'-')} · ${new Date(b.created_at).toLocaleString('id-ID')}</div>
        <div class="hist-prog" style="color:${pct>=100?'#10B981':'#F59E0B'}">📤 ${b.sent}/${b.total} terkirim (${pct}%)</div>
      </div>`;
    }).join('');
  } catch(e){ wrap.innerHTML = `<div class="empty">⚠️ ${esc(e.message)}</div>`; }
}

async function openDetail(id){
  try {
    const r = await fetch(`/ERP/harpy/hq/broadcast.php?action=detail&id=${id}`);
    const d = await r.json();
    if (d.error){ alert('⚠️ '+d.error); return; }
    const b = d.broadcast;
    curPesan = b.pesan;
    document.getElementById('dmJudul').textContent = b.judul;
    document.getElementById('dmMeta').textContent = `${b.created_by_nama||'-'} · ${new Date(b.created_at).toLocaleString('id-ID')}`;
    document.getElementById('dmPesan').textContent = b.pesan;
    document.getElementById('dmRecipients').innerHTML = b.recipients.map(rc => {
      const waUrl = `https://wa.me/${rc.telepon}?text=${encodeURIComponent('*'+b.judul+'*\n\n'+b.pesan)}`;
      const sent = rc.status === 'sent';
      return `<div class="recip-row">
        <div class="recip-info">
          <div class="recip-nama">${esc(rc.nama||'-')}</div>
          <div class="recip-sub">${esc(rc.nama_outlet||'')} · ${esc(rc.telepon)}</div>
        </div>
        ${sent
          ? `<span class="recip-sent">✓ terkirim</span>`
          : `<a class="btn btn-wa btn-sm" href="${waUrl}" target="_blank" onclick="markSent(${rc.id}, this)">💬 Kirim</a>`}
      </div>`;
    }).join('');
    document.getElementById('detailModal').classList.add('open');
  } catch(e){ alert('Gagal: '+e.message); }
}
function closeDetail(){ document.getElementById('detailModal').classList.remove('open'); loadHistory(); }

async function markSent(rid, el){
  try {
    await fetch('/ERP/harpy/hq/broadcast.php?action=mark_sent', {method:'POST', body:JSON.stringify({recipient_id:rid})});
    el.outerHTML = '<span class="recip-sent">✓ terkirim</span>';
  } catch(e){}
}

<?php if ($canSend): ?>previewRecip();<?php endif; ?>
loadHistory();
</script>

<?php require __DIR__ . '/_layout_close.php'; ?>
