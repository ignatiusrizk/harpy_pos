<?php
// ══════════════════════════════════════════════════════
// owner_report.php — Feed laporan & alert in-app untuk owner
// Hanya owner/manager yang punya akses
// ══════════════════════════════════════════════════════

$activePage = 'owner_report';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/Notifier.php';
require_once ROOT . '/core/DailyReport.php';
require_once __DIR__ . '/components.php';

$user = currentUser();
$role = $user['role'] ?? 'staff';
if (!in_array($role, ['owner','superadmin','admin','manager'], true)) {
    http_response_code(403);
    die('Akses ditolak — hanya owner/manager.');
}

$tid = TenantResolver::id();
$oid = TenantResolver::outletId();
$action = $_GET['action'] ?? '';

// ── API: list feed (paginated, sort by sent_at desc) ──
if ($action === 'list') {
    header('Content-Type: application/json');
    $limit = max(10, min(50, (int)($_GET['limit'] ?? 30)));
    try {
        $db = Database::get();
        $stmt = $db->prepare("SELECT id, type, channel, subject, body_summary, status,
                                     read_at, sent_at
                                FROM hl_notif_log
                               WHERE tenant_id=? AND outlet_id=?
                                 AND channel IN ('inapp','email')
                               ORDER BY id DESC LIMIT ?");
        $stmt->bindValue(1, $tid, PDO::PARAM_INT);
        $stmt->bindValue(2, $oid, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $unread = Notifier::unreadCount($tid, $oid);
        echo json_encode(['ok'=>true, 'rows'=>$rows, 'unread'=>$unread]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: mark read 1 / semua ──
if ($action === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($d['id'] ?? 0);
    $all = !empty($d['all']);
    try {
        $db = Database::get();
        if ($all) {
            $db->prepare("UPDATE hl_notif_log SET read_at=NOW()
                          WHERE tenant_id=? AND outlet_id=? AND read_at IS NULL AND channel='inapp'")
               ->execute([$tid, $oid]);
        } elseif ($id) {
            $db->prepare("UPDATE hl_notif_log SET read_at=NOW()
                          WHERE id=? AND tenant_id=? AND outlet_id=?")
               ->execute([$id, $tid, $oid]);
        }
        echo json_encode(['ok'=>true]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: force kirim daily report sekarang (tombol "kirim sekarang") ──
if ($action === 'send_now' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!in_array($role, ['owner','superadmin','manager'], true)) { echo json_encode(['error'=>'Akses ditolak']); exit; }
    try {
        // Bypass jam check dengan trick: clear sent_today filter via deleting today's daily_report log
        // Lebih aman: build laporan & kirim langsung tanpa Notifier dedup
        $report = DailyReport::build($tid, $oid, ['omset','order','kas','absensi','alert']);
        $res = Notifier::notifyOwner($tid, $oid, [
            'type'         => 'daily_report_manual',  // type beda biar tidak terkunci sentToday
            'subject'      => $report['subject'] . ' (manual)',
            'body_html'    => $report['html'],
            'body_summary' => $report['summary'],
            'channels'     => ['email','inapp'],
            'coin_feature' => 'daily_report',
        ]);
        echo json_encode($res);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: preview HTML laporan (untuk modal) ──
if ($action === 'preview') {
    $id = (int)($_GET['id'] ?? 0);
    $db = Database::get();
    $s = $db->prepare("SELECT subject, body_summary, type, sent_at FROM hl_notif_log
                        WHERE id=? AND tenant_id=? AND outlet_id=?");
    $s->execute([$id, $tid, $oid]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo 'Not found'; exit; }
    header('Content-Type: text/html; charset=UTF-8');
    echo "<h3>".htmlspecialchars($row['subject'])."</h3>";
    echo "<p style='color:#6B7280;font-size:12px'>".htmlspecialchars($row['sent_at'])."</p>";
    echo "<p>".nl2br(htmlspecialchars($row['body_summary']))."</p>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Laporan Owner'); ?>
<style>
.feed-item{background:#fff;border:1px solid #E5E9F2;border-radius:10px;padding:14px 16px;margin-bottom:10px;cursor:pointer;transition:box-shadow .15s,transform .1s}
.feed-item:hover{box-shadow:0 2px 8px rgba(0,0,0,.06)}
.feed-item.unread{background:#FEF9E7;border-left:4px solid #F59E0B}
.feed-head{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:4px}
.feed-subj{font-size:14px;font-weight:700;color:#0F1C3A;flex:1;min-width:0}
.feed-meta{font-size:11px;color:#9CA3AF;white-space:nowrap}
.feed-sum{font-size:13px;color:#374151;line-height:1.5}
.feed-tag{display:inline-block;font-size:10px;font-weight:700;padding:2px 7px;border-radius:100px;margin-right:5px}
.tag-daily{background:#D1FAE5;color:#065F46}
.tag-alert{background:#FEE2E2;color:#991B1B}
.tag-invoice{background:#DBEAFE;color:#1E40AF}
.tag-reminder{background:#FEF3C7;color:#92400E}
</style>
</head>
<body>
<?php renderTopbar('owner_report'); ?>

<div class="hl-main" style="max-width:760px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
    <div>
      <h1 style="font-size:1.3rem;font-weight:800;color:var(--navy)">📨 Notifikasi & Laporan</h1>
      <p style="font-size:13px;color:var(--gray)">Feed laporan harian + alert anomali outlet <span id="unreadBadge"></span></p>
    </div>
    <div style="display:flex;gap:8px">
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="markAllRead()">✓ Tandai semua dibaca</button>
      <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="sendNow()">📊 Kirim Laporan Sekarang</button>
    </div>
  </div>

  <div id="feedBox">
    <?php for($i=0;$i<4;$i++): ?>
    <div class="hl-skel-card" style="padding:14px">
      <span class="hl-skel" style="width:120px;display:block"></span>
      <span class="hl-skel lg" style="width:75%;display:block;margin-top:8px"></span>
      <span class="hl-skel" style="width:50%;display:block;margin-top:6px"></span>
    </div>
    <?php endfor; ?>
  </div>
</div>

<?php renderToast(); ?>
<script>
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const tagMap = {
  daily_report:'tag-daily', daily_report_manual:'tag-daily',
  alert_omset_drop:'tag-alert', alert_kas_tidak_diinput:'tag-alert',
  alert_order_menumpuk:'tag-alert', alert_absensi_rendah:'tag-alert',
  alert_coin_rendah:'tag-alert',
  invoice_b2b:'tag-invoice', reminder_piutang:'tag-reminder',
};

async function loadFeed(){
  const box = document.getElementById('feedBox');
  try {
    const r = await fetch('owner_report.php?action=list');
    const d = await r.json();
    if (d.error){ box.innerHTML = `<div style="text-align:center;padding:40px;color:#EF4444">⚠️ ${esc(d.error)}</div>`; return; }
    document.getElementById('unreadBadge').innerHTML = d.unread > 0
      ? `<span style="background:#EF4444;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:100px;margin-left:6px">${d.unread} belum dibaca</span>` : '';
    if (!d.rows.length){
      box.innerHTML = `<div class="hl-empty-v2">
        <div class="e-icon">📭</div>
        <div class="e-title">Belum ada notifikasi</div>
        <div class="e-sub">Notifikasi alert anomali & daily report akan muncul di sini</div>
      </div>`;
      return;
    }
    box.innerHTML = d.rows.map(r => {
      const unread = r.channel==='inapp' && !r.read_at;
      const tag = tagMap[r.type] || 'tag-daily';
      const dt = new Date(r.sent_at).toLocaleString('id-ID',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
      return `<div class="feed-item ${unread?'unread':''}" onclick="openItem(${r.id}, ${unread?1:0})">
        <div class="feed-head">
          <div class="feed-subj">
            <span class="feed-tag ${tag}">${esc(r.type)}</span>
            ${esc(r.subject || '-')}
          </div>
          <div class="feed-meta">
            ${r.channel==='email'?'📧 ':'🔔 '}${dt}
          </div>
        </div>
        ${r.body_summary ? `<div class="feed-sum">${esc(r.body_summary)}</div>` : ''}
      </div>`;
    }).join('');
  } catch(e){ box.innerHTML = `<div style="color:#EF4444;text-align:center;padding:30px">⚠️ ${esc(e.message)}</div>`; }
}

async function openItem(id, isUnread){
  if (isUnread) {
    try {
      await fetch('owner_report.php?action=mark_read', {method:'POST', body:JSON.stringify({id})});
    } catch(e){}
    loadFeed();
  }
  // Optional: open preview modal (untuk MVP, log saja)
  window.open('owner_report.php?action=preview&id='+id, '_blank', 'width=600,height=600');
}

async function markAllRead(){
  if (!confirm('Tandai semua notifikasi sebagai sudah dibaca?')) return;
  try {
    await fetch('owner_report.php?action=mark_read', {method:'POST', body:JSON.stringify({all:true})});
    showToast('✅ Semua ditandai dibaca','success');
    loadFeed();
  } catch(e){ showToast('Gagal: '+e.message,'error'); }
}

async function sendNow(){
  if (!confirm('Kirim laporan harian sekarang? (deduct 100 coin)')) return;
  try {
    const r = await fetch('owner_report.php?action=send_now', {method:'POST'});
    const d = await r.json();
    if (d.error){ showToast('⚠️ '+d.error,'error'); return; }
    showToast('✅ Laporan terkirim ke ' + (d.channels_sent||[]).join(', '), 'success');
    loadFeed();
  } catch(e){ showToast('Gagal: '+e.message,'error'); }
}

loadFeed();
</script>
</body>
</html>
