<?php
// ══════════════════════════════════════════════════════
// retention.php — Halaman dormant reminder
// List pelanggan dormant + tombol WA per orang + tracking 14-hari.
// ══════════════════════════════════════════════════════

$activePage = 'retention';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/RetentionManager.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
$tid  = (int)TenantResolver::id();
$oid  = (int)TenantResolver::outletId();

// ── AJAX ──────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    if (!hasPermission('customer.view') && !hasPermission('customer.edit')) {
        echo json_encode(['error'=>'Akses ditolak']); exit;
    }

    if ($action === 'list') {
        $res = RetentionManager::dueReminders($tid, $oid);
        echo json_encode(['ok'=>true] + $res);
        exit;
    }

    if ($action === 'mark_sent' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $pid = (int)($d['pelanggan_id'] ?? 0);
        if (!$pid) { echo json_encode(['error'=>'pelanggan_id wajib']); exit; }
        $ok = RetentionManager::markSent($tid, $oid, $pid);
        echo json_encode(['ok'=>$ok]); exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Retensi Dormant'); ?>
</head>
<body>
<?php renderTopbar('retention'); ?>

<div class="hl-main">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px">
    <div>
      <h1 style="font-size:1.3rem;font-weight:800;color:var(--navy);margin:0">😴 Retensi Pelanggan Dormant</h1>
      <p style="font-size:13px;color:var(--gray);margin:4px 0 0">
        Pelanggan yang tidak transaksi &gt; 30 hari. Klik tombol WA untuk reminder personal.
      </p>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <span style="font-size:12px;color:var(--gray)">Quota harian: <strong id="quotaInfo">- / 20</strong></span>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadList()">↻ Refresh</button>
    </div>
  </div>

  <!-- INFO BOX -->
  <div style="background:#FFFBEB;border-left:4px solid #F59E0B;padding:12px 16px;border-radius:10px;font-size:13px;color:#92400E;margin-bottom:16px">
    <strong>💡 Aturan:</strong> Max 20 pelanggan/hari/outlet. Pelanggan yang sudah di-reminder
    dalam 14 hari terakhir tidak muncul di list (anti-spam).
  </div>

  <div id="dormantList"><div class="hl-loading">⏳ Memuat...</div></div>
</div>

<?php renderToast(); ?>

<script>
async function loadList(){
  const box = document.getElementById('dormantList');
  box.innerHTML = Array.from({length:4}).map(()=>`
    <div class="hl-skel-card" style="padding:14px 16px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
        <div style="flex:1">
          <span class="hl-skel lg" style="width:140px;display:block"></span>
          <span class="hl-skel" style="width:60%;display:block;margin-top:8px"></span>
        </div>
        <span class="hl-skel" style="width:90px;height:32px"></span>
      </div>
    </div>`).join('');
  try {
    const r = await fetch('retention.php?action=list');
    const d = await r.json();
    if (d.error) { box.innerHTML = '<div class="hl-empty">❌ ' + d.error + '</div>'; return; }

    document.getElementById('quotaInfo').textContent = (d.sent_today || 0) + ' / 20';

    const rows = d.data || [];
    if (!rows.length) {
      const overQuota = d.sent_today >= 20;
      box.innerHTML = `<div class="hl-empty-v2">
        <div class="e-icon">${overQuota ? '⏰' : '🎉'}</div>
        <div class="e-title">${overQuota ? 'Quota harian habis' : 'Tidak ada dormant'}</div>
        <div class="e-sub">${overQuota
          ? 'Quota 20 reminder hari ini sudah terpakai. Lanjut besok ya.'
          : 'Tidak ada pelanggan dormant yang perlu di-reminder sekarang. Bagus!'}</div>
      </div>`;
      return;
    }

    box.innerHTML = rows.map(p => {
      const hari   = p.hari_absen;
      const poin   = p.poin_balance > 0
        ? `<span class="hl-badge" style="background:#F0FDFB;color:#0F766E;font-size:11px;margin-left:6px">⭐ ${p.poin_balance} poin</span>`
        : '';
      const previewMsg = String(p.pesan||'').substring(0,140) + (p.pesan && p.pesan.length>140 ? '...' : '');
      return `
        <div style="background:#fff;border:1px solid rgba(27,45,90,.08);border-radius:12px;padding:14px 16px;margin-bottom:10px">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:8px">
            <div style="flex:1;min-width:200px">
              <div style="font-size:15px;font-weight:700;color:var(--navy)">${esc(p.nama)} ${poin}</div>
              <div style="font-size:12px;color:var(--gray);margin-top:2px">
                📞 ${esc(p.telepon)} · Terakhir order: <strong>${formatDate(p.last_transaksi)}</strong>
                · <span style="color:#dc2626;font-weight:600">${hari} hari absen</span>
              </div>
            </div>
            <div style="display:flex;gap:6px;align-items:center">
              <a href="${p.wa_url}" target="_blank" class="hl-btn hl-btn-primary hl-btn-sm" onclick="onWAClick(${p.id})">
                💬 Kirim WA
              </a>
              <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="markSent(${p.id}, this)" title="Tandai sudah dikirim (anti-spam 14 hari)">✓ Tandai</button>
            </div>
          </div>
          <div style="background:#F8FAFC;border-radius:8px;padding:8px 12px;font-size:12px;color:#475569;white-space:pre-wrap;font-style:italic">${esc(previewMsg)}</div>
        </div>`;
    }).join('');
  } catch(e) {
    box.innerHTML = '<div class="hl-empty">❌ ' + e.message + '</div>';
  }
}

async function markSent(pid, btn) {
  if (btn) { btn.disabled = true; btn.textContent = '⏳'; }
  try {
    const r = await fetch('retention.php?action=mark_sent', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({pelanggan_id: pid})
    });
    const d = await r.json();
    if (d.error) { showToast(d.error,'error'); return; }
    showToast('✓ Ditandai sebagai sudah dikirim', 'success');
    loadList();
  } catch(e) { showToast('Network error', 'error'); }
  finally { if (btn) { btn.disabled = false; btn.textContent = '✓ Tandai'; } }
}

function onWAClick(pid) {
  // Auto-mark when kasir clicks WA button
  setTimeout(() => markSent(pid, null), 1500);
}

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function formatDate(d){if(!d)return'-';return new Date(d).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'});}

loadList();
</script>
</body>
</html>
