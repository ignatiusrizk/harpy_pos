<?php
// ══════════════════════════════════════════════════════
// kanban.php — Kanban Board Antrian Order (Staff/Kasir POV)
// 5 kolom status: masuk → cuci → kering → setrika → siap
// Card timer countdown, [Lanjut] update status 1-click, auto-refresh 60s.
// ══════════════════════════════════════════════════════

$activePage = 'kanban';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once __DIR__ . '/components.php';
$user = currentUser();

// Permission: kalau bisa lihat orders, bisa pakai kanban
if (!hasPermission('orders.view_all') && !hasPermission('orders.view_own')) {
    requirePermission('orders.view_all');
}

$tid = TenantResolver::id();
$oid = TenantResolver::outletId();
$action = $_GET['action'] ?? '';

// ══════════════════════════════════════════════════════
// API HANDLERS
// ══════════════════════════════════════════════════════
if ($action) {
    @ini_set('display_errors','0'); error_reporting(0);
    register_shutdown_function(function(){
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR], true)) {
            if (!headers_sent()) header('Content-Type: application/json');
            echo json_encode(['error'=>'PHP fatal: '.$e['message']]);
        }
    });
    header('Content-Type: application/json');

    // ── action=data: return semua order aktif 3 hari terakhir, group by status ──
    if ($action === 'data') {
        $statuses = ['masuk','cuci','kering','setrika','siap'];
        try {
            $db = Database::get();
            $sql = "SELECT t.id, t.no_order, t.nama_pelanggan, t.telepon,
                           t.total, t.status_proses, t.created_at,
                           t.estimasi_selesai, t.estimasi_jam,
                           (SELECT GROUP_CONCAT(CONCAT(jumlah,' ',satuan,' ',nama_layanan) SEPARATOR ' · ')
                              FROM hl_transaksi_item
                             WHERE transaksi_id=t.id AND tenant_id=t.tenant_id) AS items_summary
                      FROM hl_transaksi t
                     WHERE t.tenant_id=? AND t.outlet_id=?
                       AND t.status_proses IN ('masuk','cuci','kering','setrika','siap')
                       AND t.created_at >= DATE_SUB(NOW(), INTERVAL 5 DAY)
                     ORDER BY t.created_at ASC LIMIT 200";
            $stmt = $db->prepare($sql);
            $stmt->execute([$tid, $oid]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Group by status
            $cols = array_fill_keys($statuses, []);
            foreach ($rows as $r) {
                $st = $r['status_proses'];
                if (isset($cols[$st])) $cols[$st][] = $r;
            }
            echo json_encode(['ok'=>true, 'columns'=>$cols, 'ts'=>date('H:i:s')]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>$e->getMessage()]);
        }
        exit;
    }

    // ── action=update_status (POST) ──
    if ($action === 'update_status' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('orders.update_status') && !hasPermission('orders.edit')) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['order_id'] ?? 0);
        $next = $d['status_baru'] ?? '';
        $allowed = ['cuci','kering','setrika','siap','diambil'];
        if (!$id || !in_array($next, $allowed, true)) { echo json_encode(['error'=>'Param invalid']); exit; }

        try {
            $db = Database::get();
            // Verify ownership & ambil row
            $rs = $db->prepare("SELECT status_proses, no_order, nama_pelanggan, telepon, total, pelanggan_id
                                  FROM hl_transaksi WHERE id=? AND tenant_id=? AND outlet_id=?");
            $rs->execute([$id, $tid, $oid]);
            $cur = $rs->fetch(PDO::FETCH_ASSOC);
            if (!$cur) { echo json_encode(['error'=>'Order tidak ditemukan']); exit; }

            // Update: stamp tgl_selesai + handled_by (mirror logic orders.php save)
            $upd = $db->prepare("UPDATE hl_transaksi SET status_proses=?,
                tgl_selesai = CASE WHEN ? IN ('siap','diambil','selesai') AND tgl_selesai IS NULL THEN CURDATE() ELSE tgl_selesai END,
                handled_by  = CASE WHEN ? NOT IN ('masuk') AND handled_by IS NULL THEN ? ELSE handled_by END
                WHERE id=? AND tenant_id=? AND outlet_id=?");
            $upd->execute([$next, $next, $next, (int)$user['id'], $id, $tid, $oid]);

            // Log status change
            try {
                $db->prepare("INSERT INTO hl_proses_log (transaksi_id, status_lama, status_baru, tipe, catatan, oleh)
                              VALUES (?,?,?,'proses', ?, ?)")
                   ->execute([$id, $cur['status_proses'], $next,
                              "Update via Kanban", $user['nama'] ?? '-']);
            } catch (Throwable) {}

            try { logAudit('update_status','orders',"Kanban: {$cur['status_proses']} → {$next}", $cur['no_order']); } catch (Throwable) {}

            // Loyalty: earn poin saat status berubah ke 'siap' (idempotent per transaksi)
            $poinEarned = 0;
            $saldoPoin  = 0;
            $nextReward = null;
            if ($next === 'siap'
                && $cur['status_proses'] !== 'siap'
                && !empty($cur['pelanggan_id'])
            ) {
                try {
                    require_once ROOT . '/core/Loyalty.php';
                    $poinEarned = Loyalty::earnForTransaction(
                        $tid, $oid, $id, (int)$cur['pelanggan_id'], (float)$cur['total']
                    );
                    $saldoPoin  = Loyalty::balance($tid, (int)$cur['pelanggan_id']);
                    $nextReward = Loyalty::nextReward($tid, $oid, $saldoPoin);
                } catch (Throwable) {}
            }

            // Generate WA link kalau jadi 'siap'
            $waUrl = '';
            if ($next === 'siap' && $cur['telepon']) {
                $p = preg_replace('/[^0-9]/','',$cur['telepon']);
                if (strpos($p,'0')===0) $p = '62'.substr($p,1);
                elseif (strpos($p,'62')!==0) $p = '62'.$p;
                $txt = "Halo *{$cur['nama_pelanggan']}*, cucian kamu sudah selesai dan siap diambil! 🎉\n"
                     . "Order: {$cur['no_order']}\n"
                     . "Total: Rp " . number_format((int)$cur['total'],0,',','.');
                if ($poinEarned > 0) {
                    $txt .= "\n\n🌟 Kamu dapat *{$poinEarned} poin* dari transaksi ini!"
                          . "\nSaldo poin: *{$saldoPoin} poin*";
                    if ($nextReward) {
                        $kurang = (int)$nextReward['poin_dibutuhkan'] - $saldoPoin;
                        $txt .= "\nButuh *{$kurang} poin* lagi untuk *{$nextReward['nama_reward']}* 🎁";
                    }
                }
                $waUrl = "https://wa.me/$p?text=" . urlencode($txt);
            }

            echo json_encode([
                'ok'=>true, 'status_baru'=>$next, 'wa'=>$waUrl,
                'poin_earned'=>$poinEarned, 'saldo_poin'=>$saldoPoin,
            ]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Kanban Antrian'); ?>
<style>
.kb-wrap{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;padding:0 4px}
@media(max-width:1100px){.kb-wrap{grid-template-columns:repeat(3,1fr)}}
@media(max-width:720px){
  /* Horizontal scroll-snap di HP — UX seperti Trello mobile */
  .kb-wrap{
    display:flex;grid-template-columns:none;
    overflow-x:auto;scroll-snap-type:x mandatory;
    -webkit-overflow-scrolling:touch;
    gap:8px;padding:0 4px 8px;margin:0 -10px;
  }
  .kb-col{flex:0 0 80vw;max-width:340px;scroll-snap-align:start;min-height:300px}
  .kb-col::-webkit-scrollbar{display:none}
  /* Indicator dots scroll position */
  .kb-mobile-hint{display:flex!important;justify-content:center;gap:6px;margin:8px 0 12px;padding:0 14px;flex-wrap:wrap;font-size:11px;color:var(--gray)}
}
.kb-mobile-hint{display:none}

.kb-col{background:#F4F7FB;border-radius:12px;padding:10px;min-height:240px;display:flex;flex-direction:column}
.kb-col-head{display:flex;justify-content:space-between;align-items:center;padding:5px 8px 10px;border-bottom:1px solid #E5E9F2;margin-bottom:8px}
.kb-col-title{font-size:12px;font-weight:800;color:#0F1C3A;text-transform:uppercase;letter-spacing:.06em}
.kb-col-count{background:#0F1C3A;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:100px;min-width:24px;text-align:center}
.kb-col.col-masuk   .kb-col-count{background:#3B82F6}
.kb-col.col-cuci    .kb-col-count{background:#F59E0B}
.kb-col.col-kering  .kb-col-count{background:#06B6D4}
.kb-col.col-setrika .kb-col-count{background:#8B5CF6}
.kb-col.col-siap    .kb-col-count{background:#10B981}

.kb-card{background:#fff;border:1px solid #E5E9F2;border-left:4px solid #6B7280;border-radius:9px;
  padding:10px 12px;margin-bottom:7px;cursor:default;transition:transform .12s,box-shadow .12s}
.kb-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.08);transform:translateY(-1px)}
.kb-card.timer-green{border-left-color:#10B981}
.kb-card.timer-amber{border-left-color:#F59E0B;background:linear-gradient(90deg,#FFFBEB,#fff 30%)}
.kb-card.timer-red  {border-left-color:#EF4444;background:linear-gradient(90deg,#FEE2E2,#fff 30%);animation:lateBlink 2s infinite}
@keyframes lateBlink{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.3)}50%{box-shadow:0 0 0 4px rgba(239,68,68,0)}}

.kb-no{font-family:var(--mono);font-size:10px;color:#9CA3AF;font-weight:700}
.kb-nama{font-size:13px;font-weight:800;color:#0F1C3A;margin:2px 0 4px;line-height:1.2}
.kb-items{font-size:11px;color:#6B7280;line-height:1.4;margin-bottom:6px;display:-webkit-box;-webkit-line-clamp:2;line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.kb-timer{font-size:10px;font-weight:800;margin-bottom:6px;display:flex;justify-content:space-between;align-items:center}
.timer-green .kb-timer-txt{color:#065F46}
.timer-amber .kb-timer-txt{color:#92400E}
.timer-red   .kb-timer-txt{color:#991B1B}
.kb-total{font-family:monospace;color:#0F1C3A;font-weight:700;font-size:10px}
.kb-btn{display:block;width:100%;padding:6px 8px;border:none;border-radius:6px;font-size:11px;font-weight:700;
  cursor:pointer;font-family:inherit;background:#0F1C3A;color:#fff;text-align:center}
.kb-btn:hover{background:#1a2d52}
.kb-btn.siap{background:#10B981}
.kb-btn.siap:hover{background:#059669}
.kb-btn:disabled{opacity:.5;cursor:wait}

.kb-empty{color:#9CA3AF;font-size:12px;text-align:center;padding:20px 8px;font-style:italic}

.kb-toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px}
.kb-live{font-size:11px;color:#10B981;font-weight:700;display:flex;align-items:center;gap:6px}
.kb-live .dot{display:inline-block;width:7px;height:7px;background:#10B981;border-radius:50%;animation:livePulse 2s infinite}
@keyframes livePulse{0%,100%{opacity:1}50%{opacity:.3}}
</style>
</head>
<body>
<?php renderTopbar('kanban'); ?>

<div class="hl-main" style="max-width:1500px;width:100%">
  <div class="kb-toolbar">
    <div>
      <h1 style="font-size:1.3rem;font-weight:800;color:var(--navy)">🗂️ Kanban Antrian Order</h1>
      <p style="font-size:12px;color:var(--gray)">Auto-refresh 60 detik · Klik <strong>Lanjut</strong> untuk pindah status</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center">
      <span class="kb-live"><span class="dot"></span>live · <span id="kbTs">—</span></span>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadKanban()">↻ Refresh</button>
      <a href="pos.php" class="hl-btn hl-btn-primary hl-btn-sm">+ Order Baru</a>
    </div>
  </div>

  <div class="kb-mobile-hint">👈 Geser samping untuk lihat kolom lain</div>
  <div class="kb-wrap" id="kbWrap">
    <div class="kb-col col-masuk"  ><div class="kb-col-head"><span class="kb-col-title">📥 Masuk</span><span class="kb-col-count" id="cnt-masuk">0</span></div><div id="col-masuk"  ><div class="kb-empty">Memuat…</div></div></div>
    <div class="kb-col col-cuci"   ><div class="kb-col-head"><span class="kb-col-title">🫧 Cuci</span><span class="kb-col-count" id="cnt-cuci">0</span></div><div id="col-cuci"   ><div class="kb-empty">Memuat…</div></div></div>
    <div class="kb-col col-kering" ><div class="kb-col-head"><span class="kb-col-title">💨 Kering</span><span class="kb-col-count" id="cnt-kering">0</span></div><div id="col-kering" ><div class="kb-empty">Memuat…</div></div></div>
    <div class="kb-col col-setrika"><div class="kb-col-head"><span class="kb-col-title">👔 Setrika</span><span class="kb-col-count" id="cnt-setrika">0</span></div><div id="col-setrika"><div class="kb-empty">Memuat…</div></div></div>
    <div class="kb-col col-siap"   ><div class="kb-col-head"><span class="kb-col-title">✅ Siap Ambil</span><span class="kb-col-count" id="cnt-siap">0</span></div><div id="col-siap"   ><div class="kb-empty">Memuat…</div></div></div>
  </div>
</div>

<?php renderToast(); ?>
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const NEXT = {masuk:'cuci', cuci:'kering', kering:'setrika', setrika:'siap', siap:'diambil'};
const LABEL_NEXT = {masuk:'→ Cuci', cuci:'→ Kering', kering:'→ Setrika', setrika:'→ Siap', siap:'✓ Ambil'};
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

function timeAgo(ts){
  const diffH = (Date.now() - new Date(ts.replace(' ','T')).getTime()) / 3600000;
  if (diffH < 1)  return Math.round(diffH*60) + 'm';
  if (diffH < 24) return diffH.toFixed(1).replace('.0','') + 'j';
  return Math.round(diffH/24) + 'h';
}

function timerClass(createdAt, estimasiSelesai, estimasiJam){
  // Hitung % waktu terpakai dari created_at ke estimasi_selesai
  const start = new Date(createdAt.replace(' ','T')).getTime();
  let target;
  if (estimasiSelesai) target = new Date(estimasiSelesai.replace(' ','T')).getTime();
  else target = start + (parseInt(estimasiJam)||24) * 3600000;
  const now = Date.now();
  const total = target - start;
  const used = now - start;
  if (total <= 0) return {cls:'timer-amber', txt:timeAgo(createdAt)};
  const pct = used / total * 100;
  if (pct >= 100) {
    const lateMs = now - target;
    const lateH = lateMs / 3600000;
    const lbl = lateH < 1 ? Math.round(lateH*60)+'m' : lateH.toFixed(1).replace('.0','')+'j';
    return {cls:'timer-red', txt:'TERLAMBAT ' + lbl};
  }
  if (pct >= 75) {
    const remH = (target-now) / 3600000;
    return {cls:'timer-amber', txt: (remH<1 ? Math.round(remH*60)+'m' : remH.toFixed(1).replace('.0','')+'j') + ' lagi'};
  }
  const remH = (target-now) / 3600000;
  return {cls:'timer-green', txt: (remH<1 ? Math.round(remH*60)+'m' : remH.toFixed(1).replace('.0','')+'j') + ' lagi'};
}

function renderCard(r){
  const t = timerClass(r.created_at, r.estimasi_selesai, r.estimasi_jam);
  const next = NEXT[r.status_proses];
  const isLast = r.status_proses === 'siap';
  return `<div class="kb-card ${t.cls}" data-id="${r.id}">
    <div class="kb-no">${esc(r.no_order)}</div>
    <div class="kb-nama">${esc(r.nama_pelanggan)}</div>
    <div class="kb-items">${esc(r.items_summary || '-')}</div>
    <div class="kb-timer">
      <span class="kb-timer-txt">⏱ ${t.txt}</span>
      <span class="kb-total">Rp ${Number(r.total).toLocaleString('id-ID')}</span>
    </div>
    <button class="kb-btn ${isLast?'siap':''}" onclick="advanceStatus(${r.id}, '${next}', this)">${LABEL_NEXT[r.status_proses]}</button>
  </div>`;
}

async function loadKanban(){
  try {
    const r = await fetch('kanban.php?action=data');
    const d = await r.json();
    if (d.error){ console.warn('Kanban:', d.error); return; }
    document.getElementById('kbTs').textContent = d.ts;
    ['masuk','cuci','kering','setrika','siap'].forEach(s => {
      const items = d.columns[s] || [];
      document.getElementById('cnt-'+s).textContent = items.length;
      document.getElementById('col-'+s).innerHTML = items.length
        ? items.map(renderCard).join('')
        : '<div class="kb-empty">— kosong —</div>';
    });
  } catch(e){ console.warn(e); }
}

async function advanceStatus(orderId, nextStatus, btn){
  if (!nextStatus) return;
  btn.disabled = true; btn.textContent = '⏳';
  try {
    const r = await fetch('kanban.php?action=update_status', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body: JSON.stringify({order_id: orderId, status_baru: nextStatus})
    });
    const d = await r.json();
    if (d.error){ showToast('⚠️ '+d.error,'error'); btn.disabled=false; loadKanban(); return; }
    showToast('✅ Status → ' + nextStatus, 'success');
    if (d.wa) {
      // Buka WA notif siap ambil di tab baru
      window.open(d.wa, '_blank');
    }
    loadKanban();
  } catch(e){ showToast('Gagal: '+e.message,'error'); btn.disabled=false; }
}

loadKanban();
setInterval(loadKanban, 60000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) loadKanban(); });
</script>
</body>
</html>
