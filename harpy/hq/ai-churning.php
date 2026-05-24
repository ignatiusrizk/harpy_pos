<?php
// ══════════════════════════════════════════════════════
// hq/ai-churning.php — Smart Notif WA: Pelanggan Churning
//
// Owner lihat list pelanggan yang lama tidak laundry.
// Per pelanggan: klik "Generate Pesan" → AI tulis pesan personal →
// owner review → klik "Send WA" buka wa.me link.
// ══════════════════════════════════════════════════════

$activePage = 'hq-ai-churning';
$pageTitle  = 'AI Smart Notif — Pelanggan Churning';

define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';
require_once ROOT . '/core/AIChurnDetector.php';
require_once ROOT . '/core/CoinLedger.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];
$action = $_GET['action'] ?? '';

// ── API: list churning ───────────────────────────────
if ($action === 'list') {
    header('Content-Type: application/json');
    $outletId = (int)($_GET['outlet_id'] ?? 0);
    $limit    = min(200, max(10, (int)($_GET['limit'] ?? 50)));

    try {
        $list = AIChurnDetector::detect($tid, $outletId ?: null, $limit);

        // Enrich dengan log status (kalau sudah pernah di-outreach)
        if ($list) {
            $ids = array_column($list, 'pelanggan_id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sqlLog = "SELECT pelanggan_id, status, generated_at, sent_at
                         FROM hl_ai_outreach_log
                        WHERE tenant_id=? AND campaign_type='churn_winback'
                          AND pelanggan_id IN ($placeholders)
                          AND generated_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                        ORDER BY id DESC";
            $stmt = $db->prepare($sqlLog);
            $stmt->execute(array_merge([$tid], $ids));
            $logRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $logMap = [];
            foreach ($logRows as $r) {
                if (!isset($logMap[$r['pelanggan_id']])) {
                    $logMap[$r['pelanggan_id']] = $r;
                }
            }
            foreach ($list as &$c) {
                $c['last_outreach'] = $logMap[$c['pelanggan_id']] ?? null;
            }
            unset($c);
        }

        echo json_encode(['ok' => true, 'list' => $list, 'count' => count($list)]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: generate message ────────────────────────────
if ($action === 'generate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!CoinLedger::canAfford('ai_churn_message')) {
        echo json_encode(['error' => 'Coin tidak cukup. Butuh 30 coin per pesan.']);
        exit;
    }

    $pelangganId = (int)($_POST['pelanggan_id'] ?? 0);
    $outletId    = (int)($_POST['outlet_id'] ?? 0) ?: null;

    if (!$pelangganId) {
        echo json_encode(['error' => 'pelanggan_id required']);
        exit;
    }

    // Re-fetch customer data
    $stmt = $db->prepare("SELECT id, nama, telepon FROM hl_pelanggan WHERE id=? AND tenant_id=?");
    $stmt->execute([$pelangganId, $tid]);
    $cust = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cust) {
        echo json_encode(['error' => 'Pelanggan tidak ditemukan']);
        exit;
    }

    // Build customer object
    $customer = [
        'nama'            => $cust['nama'],
        'days_since_last' => (int)($_POST['days_since_last'] ?? 30),
        'top_layanan'     => $_POST['top_layanan'] ?? 'laundry',
        'total_order'     => (int)($_POST['total_order'] ?? 0),
    ];

    $outletNama = $hqTenant['nama_outlet'] ?? 'Outlet kami';

    try {
        $gen = AIChurnDetector::generateMessage($customer, $outletNama);
        $message = $gen['message'];

        // Deduct + log
        try { CoinLedger::deduct('ai_churn_message'); } catch (Throwable) {}

        $logId = AIChurnDetector::logOutreach($tid, $outletId, $pelangganId, $message, [
            'days_since_last' => $customer['days_since_last'],
            'top_layanan'     => $customer['top_layanan'],
            'total_order'     => $customer['total_order'],
            'tokens'          => $gen['tokens_in'] + $gen['tokens_out'],
        ]);

        try { logAudit('ai_churn_message', 'crm', "Generate pesan churning untuk {$cust['nama']}", (string)$pelangganId); } catch (Throwable) {}

        echo json_encode([
            'ok'      => true,
            'log_id'  => $logId,
            'message' => $message,
            'telepon' => preg_replace('/[^0-9]/', '', $cust['telepon']),
        ]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: mark status ─────────────────────────────────
if ($action === 'mark' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $logId = (int)($_POST['log_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $user = currentUser();
    $userId = $user ? (int)$user['id'] : null;

    $ok = AIChurnDetector::updateStatus($logId, $tid, $status, $userId);
    echo json_encode(['ok' => $ok]);
    exit;
}

// ── Get outlets untuk filter ─────────────────────────
$outlets = $db->prepare("SELECT id, nama_outlet FROM outlets WHERE tenant_id=? AND status IN ('trial','grace','active') ORDER BY nama_outlet");
$outlets->execute([$tid]);
$outletList = $outlets->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/_layout_open.php';
?>
<style>
.churn-hero { background:linear-gradient(135deg,#0F1C3A,#1a2d52); color:#fff; padding:24px 28px;
  border-radius:14px; margin-bottom:18px; position:relative; overflow:hidden }
.churn-hero h1 { font-size:1.4rem; font-weight:800; margin-bottom:6px }
.churn-hero p { color:rgba(255,255,255,.65); font-size:13px }
.churn-hero .ico-bg { position:absolute; right:-30px; top:-30px; font-size:140px; opacity:.06 }

.churn-filter { background:#fff; border:1px solid #EEF1F8; border-radius:14px;
  padding:14px 18px; margin-bottom:18px; display:flex; gap:10px; align-items:center; flex-wrap:wrap }
.churn-filter select, .churn-filter button { font-family:inherit; font-size:13px; border-radius:8px; padding:7px 12px }
.churn-filter select { background:#fff; border:1px solid #E5E9F2 }
.btn-load { background:#0F1C3A; color:#fff; border:none; font-weight:700; cursor:pointer }
.btn-load:hover { background:#1a2d52 }

.churn-list { display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:14px }
.churn-card { background:#fff; border:1px solid #EEF1F8; border-radius:12px; padding:16px 18px;
  position:relative; transition:box-shadow .15s }
.churn-card:hover { box-shadow:0 4px 12px rgba(27,45,90,.08) }
.churn-card.critical { border-left:4px solid #EF4444 }
.churn-card.warning  { border-left:4px solid #F59E0B }
.churn-card.mild     { border-left:4px solid #6B7280 }

.cc-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px }
.cc-nama { font-size:15px; font-weight:800; color:#0F1C3A; line-height:1.2 }
.cc-tel { font-size:11px; color:#6B7280; margin-top:3px; font-family:monospace }
.cc-ratio { background:#FEE2E2; color:#991B1B; font-size:10px; font-weight:800; padding:3px 8px;
  border-radius:100px; white-space:nowrap }
.cc-ratio.warning { background:#FEF3C7; color:#92400E }
.cc-ratio.mild { background:#F3F4F6; color:#374151 }

.cc-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin:10px 0;
  background:#F7F8FC; border-radius:8px; padding:10px }
.cc-stat-num { font-size:14px; font-weight:800; color:#0F1C3A; font-family:monospace }
.cc-stat-label { font-size:10px; color:#6B7280; text-transform:uppercase; letter-spacing:.04em; margin-top:2px }

.cc-layanan { font-size:11px; color:#6B7280; margin-bottom:10px }
.cc-layanan strong { color:#0F1C3A }

.cc-msg-box { background:#F0FDFB; border:1px solid #B6F0E6; border-radius:8px; padding:11px 13px;
  font-size:13px; line-height:1.55; color:#0F1C3A; margin-bottom:10px; white-space:pre-wrap;
  display:none }
.cc-msg-box.active { display:block }

.cc-actions { display:flex; gap:6px; flex-wrap:wrap }
.cc-btn { padding:7px 12px; border-radius:7px; font-size:11px; font-weight:700; border:none;
  cursor:pointer; font-family:inherit; text-decoration:none; display:inline-flex; align-items:center; gap:4px }
.cc-btn-gen { background:linear-gradient(135deg,#667eea,#764ba2); color:#fff; flex:1; justify-content:center }
.cc-btn-gen:disabled { opacity:.5; cursor:wait }
.cc-btn-send { background:#25D366; color:#fff; flex:1; justify-content:center }
.cc-btn-send:hover { background:#1DA851 }
.cc-btn-skip { background:#F3F4F6; color:#6B7280; padding:7px 10px }
.cc-btn-skip:hover { background:#E5E7EB }

.cc-status { font-size:10px; color:#9CA3AF; margin-top:8px; padding-top:8px; border-top:1px dashed #EEF1F8 }
.cc-status.sent { color:#10B981 }
.cc-status.skipped { color:#F59E0B }
.cc-status.dismissed { color:#6B7280 }

.empty { text-align:center; padding:48px 20px; color:#6B7280; background:#fff; border-radius:14px; grid-column:1/-1 }
.empty .ico { font-size:48px; margin-bottom:12px }

.loading { text-align:center; padding:40px; color:#6B7280; grid-column:1/-1 }
</style>

<div class="churn-hero">
  <div class="ico-bg">📞</div>
  <h1>🎯 Smart Notif — Pelanggan Churning</h1>
  <p>Pelanggan yang biasanya rutin laundry tapi sudah lama tidak datang. AI bantu generate pesan personal untuk ajak mereka kembali.</p>
</div>

<div class="churn-filter">
  <label style="font-size:12px;color:#6B7280;font-weight:600">Outlet:</label>
  <select id="fOutlet">
    <option value="0">📍 Semua Outlet</option>
    <?php foreach ($outletList as $o): ?>
      <option value="<?= (int)$o['id'] ?>"><?= htmlspecialchars($o['nama_outlet']) ?></option>
    <?php endforeach; ?>
  </select>
  <label style="font-size:12px;color:#6B7280;font-weight:600;margin-left:10px">Limit:</label>
  <select id="fLimit">
    <option value="30">30 teratas</option>
    <option value="50" selected>50 teratas</option>
    <option value="100">100 teratas</option>
  </select>
  <button class="btn-load" onclick="loadList()">🔍 Cek Pelanggan Churning</button>
  <div style="margin-left:auto;font-size:11px;color:#6B7280">
    💡 Generate pesan = 30 coin/pelanggan
  </div>
</div>

<div id="churnList" class="churn-list">
  <div class="empty">
    <div class="ico">🎯</div>
    <div>Klik tombol "Cek Pelanggan Churning" untuk mulai</div>
    <div style="font-size:12px;margin-top:6px;color:#9CA3AF">AI akan analisa pola order historis dan flag pelanggan yang overdue</div>
  </div>
</div>

<script>
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const fmtDate = s => s ? new Date(s).toLocaleDateString('id-ID', {day:'2-digit', month:'short', year:'numeric'}) : '-';

async function loadList(){
  const outletId = document.getElementById('fOutlet').value;
  const limit = document.getElementById('fLimit').value;
  const listEl = document.getElementById('churnList');
  listEl.innerHTML = '<div class="loading">⏳ Menganalisa pola order pelanggan…</div>';

  try {
    const r = await fetch(`/ERP/harpy/hq/ai-churning.php?action=list&outlet_id=${outletId}&limit=${limit}`);
    const d = await r.json();
    if (d.error) {
      listEl.innerHTML = `<div class="empty"><div class="ico">⚠️</div><div>${esc(d.error)}</div></div>`;
      return;
    }
    if (!d.list || d.list.length === 0) {
      listEl.innerHTML = `<div class="empty"><div class="ico">✅</div><div>Tidak ada pelanggan churning saat ini</div><div style="font-size:12px;color:#9CA3AF;margin-top:6px">Semua pelanggan aktif!</div></div>`;
      return;
    }

    listEl.innerHTML = d.list.map(renderCard).join('');
  } catch (e) {
    listEl.innerHTML = `<div class="empty"><div class="ico">⚠️</div><div>Gagal load: ${esc(e.message)}</div></div>`;
  }
}

function ratioClass(ratio) {
  if (ratio >= 4) return 'critical';
  if (ratio >= 3) return 'warning';
  return 'mild';
}

function renderCard(c) {
  const cls = ratioClass(c.overdue_ratio);
  const last = c.last_outreach;
  let statusHtml = '';
  if (last) {
    const date = fmtDate(last.generated_at);
    statusHtml = `<div class="cc-status ${last.status}">
      ${last.status === 'sent' ? '✅ Pernah dikirim WA — ' + fmtDate(last.sent_at)
        : last.status === 'skipped' ? '⏭️ Sudah di-skip ' + date
        : last.status === 'dismissed' ? '🗑️ Dismissed ' + date
        : '📝 Pesan tersimpan ' + date}
    </div>`;
  }

  return `
    <div class="churn-card ${cls}" data-pid="${c.pelanggan_id}">
      <div class="cc-header">
        <div>
          <div class="cc-nama">${esc(c.nama)}</div>
          <div class="cc-tel">${esc(c.telepon)}</div>
        </div>
        <div class="cc-ratio ${cls}">${c.days_since_last}H · ${c.overdue_ratio}x overdue</div>
      </div>
      <div class="cc-stats">
        <div><div class="cc-stat-num">${c.total_order}</div><div class="cc-stat-label">Order Historis</div></div>
        <div><div class="cc-stat-num">${c.avg_interval_days}H</div><div class="cc-stat-label">Avg Interval</div></div>
        <div><div class="cc-stat-num">${c.days_since_last}H</div><div class="cc-stat-label">Sejak Terakhir</div></div>
      </div>
      <div class="cc-layanan">
        Layanan favorit: <strong>${esc(c.top_layanan || 'belum ada data')}</strong>
        · Order terakhir: <strong>${fmtDate(c.last_order_date)}</strong>
      </div>
      <div class="cc-msg-box" id="msg-${c.pelanggan_id}"></div>
      <div class="cc-actions" id="actions-${c.pelanggan_id}">
        <button class="cc-btn cc-btn-gen" onclick='generateMsg(${JSON.stringify(c).replace(/'/g,"&#39;")})'>
          ✨ Generate Pesan
        </button>
        <button class="cc-btn cc-btn-skip" onclick="skipCustomer(${c.pelanggan_id})">⏭️</button>
      </div>
      ${statusHtml}
    </div>
  `;
}

async function generateMsg(c) {
  const card = document.querySelector(`.churn-card[data-pid="${c.pelanggan_id}"]`);
  const btn = card.querySelector('.cc-btn-gen');
  const msgBox = document.getElementById(`msg-${c.pelanggan_id}`);
  const actions = document.getElementById(`actions-${c.pelanggan_id}`);

  btn.disabled = true;
  btn.textContent = '⏳ Generating…';

  const fd = new FormData();
  fd.append('pelanggan_id', c.pelanggan_id);
  fd.append('days_since_last', c.days_since_last);
  fd.append('top_layanan', c.top_layanan || 'laundry');
  fd.append('total_order', c.total_order);

  try {
    const r = await fetch('/ERP/harpy/hq/ai-churning.php?action=generate', { method:'POST', body: fd });
    const d = await r.json();
    if (d.error) {
      btn.disabled = false;
      btn.textContent = '✨ Generate Pesan';
      alert('⚠️ ' + d.error);
      return;
    }

    msgBox.textContent = d.message;
    msgBox.classList.add('active');

    const waUrl = `https://wa.me/${d.telepon}?text=${encodeURIComponent(d.message)}`;
    actions.innerHTML = `
      <a class="cc-btn cc-btn-send" href="${waUrl}" target="_blank"
         onclick="markStatus(${d.log_id}, 'sent', this.closest('.churn-card'))">
        💬 Kirim WA
      </a>
      <button class="cc-btn cc-btn-gen" onclick='generateMsg(${JSON.stringify(c).replace(/'/g,"&#39;")})'>
        🔄 Re-generate
      </button>
      <button class="cc-btn cc-btn-skip" onclick="markStatus(${d.log_id}, 'dismissed', this.closest('.churn-card'))">🗑️</button>
    `;
  } catch (e) {
    btn.disabled = false;
    btn.textContent = '✨ Generate Pesan';
    alert('Gagal: ' + e.message);
  }
}

async function skipCustomer(pid) {
  const card = document.querySelector(`.churn-card[data-pid="${pid}"]`);
  card.style.opacity = '0.4';
  card.style.pointerEvents = 'none';
}

async function markStatus(logId, status, card) {
  const fd = new FormData();
  fd.append('log_id', logId);
  fd.append('status', status);
  try {
    await fetch('/ERP/harpy/hq/ai-churning.php?action=mark', { method:'POST', body: fd });
  } catch (e) {}
  if (status === 'dismissed' && card) {
    card.style.opacity = '0.4';
    card.style.pointerEvents = 'none';
  }
}
</script>

<?php require __DIR__ . '/_layout_close.php'; ?>
