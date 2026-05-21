<?php
// ══════════════════════════════════════════════════════
// checklist.php (outlet) — Staff isi checklist harian
// ══════════════════════════════════════════════════════

$activePage = 'checklist';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/Checklist.php';
require_once __DIR__ . '/components.php';
$user = currentUser();

$tid = TenantResolver::id();
$oid = TenantResolver::outletId();
$action = $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');

    // List template aktif + submission hari ini
    if ($action === 'today') {
        $tgl = date('Y-m-d');
        try {
            $templates = Checklist::listTemplates($tid, true);
            foreach ($templates as &$t) {
                $sub = Checklist::getSubmission($tid, $oid, (int)$t['id'], $tgl);
                $t['submission'] = $sub ? [
                    'answers'       => $sub['answers'],
                    'checked_items' => (int)$sub['checked_items'],
                    'total_items'   => (int)$sub['total_items'],
                    'by'            => $sub['submitted_by_nama'],
                    'at'            => $sub['submitted_at'],
                ] : null;
            }
            unset($t);
            echo json_encode(['ok'=>true, 'tanggal'=>$tgl, 'templates'=>$templates]);
        } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    // Submit isian
    if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $templateId = (int)($d['template_id'] ?? 0);
        $answers    = $d['answers'] ?? [];
        $tgl        = date('Y-m-d');
        try {
            Checklist::submit($tid, $oid, $templateId, $tgl, $answers,
                $user ? (int)$user['id'] : null, $user['nama'] ?? null);
            logAudit('submit', 'checklist', "Isi checklist #$templateId", (string)$templateId);
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Checklist Harian'); ?>
<style>
.ck-wrap{max-width:760px;margin:0 auto}
.ck-card{background:var(--white);border:1px solid #E5E9F2;border-radius:var(--r-lg);padding:20px;margin-bottom:16px;box-shadow:var(--shadow)}
.ck-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px}
.ck-judul{font-size:16px;font-weight:800;color:var(--navy)}
.ck-desc{font-size:12px;color:var(--gray);margin-bottom:14px}
.ck-status{font-size:11px;font-weight:700;padding:3px 10px;border-radius:100px;white-space:nowrap}
.ck-status.done{background:#D1FAE5;color:#065F46}
.ck-status.partial{background:#FEF3C7;color:#92400E}
.ck-status.empty{background:#FEE2E2;color:#991B1B}
.ck-item{display:flex;gap:10px;align-items:flex-start;padding:11px;border:1px solid #EEF1F8;border-radius:9px;margin-bottom:7px}
.ck-item input[type=checkbox]{width:20px;height:20px;margin-top:1px;cursor:pointer;flex-shrink:0;accent-color:var(--teal-d)}
.ck-item-body{flex:1}
.ck-item-text{font-size:14px;color:var(--dark);font-weight:600}
.ck-item-text .req{color:#EF4444;font-size:11px;margin-left:4px}
.ck-item-note{width:100%;margin-top:6px;padding:6px 9px;border:1px solid #E5E9F2;border-radius:6px;font-family:inherit;font-size:12px}
.ck-submit{margin-top:12px}
.ck-meta{font-size:11px;color:var(--gray);margin-top:8px}
.empty{text-align:center;padding:50px 20px;color:var(--gray)}
.empty .ico{font-size:48px;margin-bottom:10px}
</style>
</head>
<body>
<?php renderTopbar('checklist'); ?>

<div class="hl-main">
  <div class="ck-wrap">
    <h2 style="font-size:1.3rem;font-weight:800;color:var(--navy);margin-bottom:4px">✅ Checklist Harian</h2>
    <p style="font-size:13px;color:var(--gray);margin-bottom:18px" id="ckDate"></p>
    <div id="ckList"><div class="empty"><div class="ico">⏳</div>Memuat checklist…</div></div>
  </div>
</div>

<?php renderToast(); ?>
<script>
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

async function loadToday(){
  const wrap = document.getElementById('ckList');
  try {
    const r = await fetch('checklist.php?action=today');
    const d = await r.json();
    if (d.error){ wrap.innerHTML = `<div class="empty">⚠️ ${esc(d.error)}</div>`; return; }
    document.getElementById('ckDate').textContent = new Date(d.tanggal).toLocaleDateString('id-ID',{weekday:'long',day:'2-digit',month:'long',year:'numeric'});
    if (!d.templates.length){ wrap.innerHTML = `<div class="empty"><div class="ico">📭</div>Belum ada checklist dari HQ untuk hari ini.</div>`; return; }
    wrap.innerHTML = d.templates.map(renderCard).join('');
  } catch(e){ wrap.innerHTML = `<div class="empty">⚠️ ${esc(e.message)}</div>`; }
}

function renderCard(t){
  const sub = t.submission;
  let statusHtml = '<span class="ck-status empty">Belum diisi</span>';
  if (sub){
    const full = sub.checked_items >= sub.total_items;
    statusHtml = `<span class="ck-status ${full?'done':'partial'}">${full?'✓ Lengkap':'◐ Sebagian'} ${sub.checked_items}/${sub.total_items}</span>`;
  }
  const items = t.items.map((it,i) => {
    const ans = sub && sub.answers ? (sub.answers[i] || sub.answers[String(i)] || {}) : {};
    const checked = ans.checked ? 'checked' : '';
    const note = ans.note ? esc(ans.note) : '';
    return `
      <div class="ck-item">
        <input type="checkbox" id="ck_${t.id}_${i}" ${checked}>
        <div class="ck-item-body">
          <div class="ck-item-text">${esc(it.text)}${it.required?'<span class="req">*wajib</span>':''}</div>
          <input type="text" class="ck-item-note" id="note_${t.id}_${i}" placeholder="Catatan (opsional)…" value="${note}">
        </div>
      </div>`;
  }).join('');
  return `
    <div class="ck-card" data-tid="${t.id}">
      <div class="ck-head">
        <div class="ck-judul">${esc(t.judul)}</div>
        ${statusHtml}
      </div>
      ${t.deskripsi?`<div class="ck-desc">${esc(t.deskripsi)}</div>`:''}
      ${items}
      <button class="hl-btn hl-btn-primary ck-submit" onclick="submitCk(${t.id}, ${t.items.length})">💾 Simpan Checklist</button>
      ${sub?`<div class="ck-meta">Terakhir diisi oleh ${esc(sub.by||'-')} · ${esc(sub.at||'')}</div>`:''}
    </div>`;
}

async function submitCk(tid, itemCount){
  const answers = {};
  for (let i=0;i<itemCount;i++){
    answers[i] = {
      checked: document.getElementById(`ck_${tid}_${i}`).checked ? 1 : 0,
      note: document.getElementById(`note_${tid}_${i}`).value.trim(),
    };
  }
  try {
    const r = await fetch('checklist.php?action=submit', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({template_id: tid, answers})
    });
    const d = await r.json();
    if (d.error){ showToast('❌ '+d.error,'error'); return; }
    showToast('✅ Checklist tersimpan!','success');
    loadToday();
  } catch(e){ showToast('❌ '+e.message,'error'); }
}

loadToday();
</script>
</body>
</html>
