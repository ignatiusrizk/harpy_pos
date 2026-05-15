<?php
// ══════════════════════════════════════════════════════
// superadmin/churn_risk.php — Churn Risk Monitor
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';

date_default_timezone_set('Asia/Jakarta');

$action = $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');
    $db = Database::get();

    if ($action === 'list') {
        // Trial diambil dari outlet (MIN trial_ends_at = outlet trial pertama yang habis)
        $tenants = $db->query(
            "SELECT t.id, t.nama_outlet, t.owner_name, t.owner_wa, t.status,
                    t.coin_balance, t.provisioned_at,
                    (SELECT MIN(o.trial_ends_at) FROM outlets o
                       WHERE o.tenant_id = t.id AND o.status = 'trial') as trial_ends_at,
                    (SELECT COUNT(*) FROM outlets o
                       WHERE o.tenant_id = t.id AND o.status = 'trial') as trial_outlets,
                    (SELECT MAX(u.last_login) FROM hl_users u WHERE u.tenant_id = t.id) as last_login,
                    (SELECT COUNT(*) FROM payments p WHERE p.tenant_id = t.id) as payment_count,
                    (SELECT COUNT(*) FROM hl_transaksi tx WHERE tx.tenant_id = t.id AND tx.tanggal >= NOW() - INTERVAL 7 DAY) as orders_this_week,
                    (SELECT COUNT(*) FROM hl_transaksi tx WHERE tx.tenant_id = t.id AND tx.tanggal >= NOW() - INTERVAL 30 DAY AND tx.tanggal < NOW() - INTERVAL 7 DAY) as orders_last_month_approx
             FROM tenants t
             WHERE t.status = 'active'
             ORDER BY t.nama_outlet"
        )->fetchAll();

        $result = [];
        foreach ($tenants as $t) {
            $risks = [];

            // Risk 1: Tidak login > 14 hari
            if (!$t['last_login'] || strtotime($t['last_login']) < strtotime('-14 days')) {
                $risks[] = 'tidak_login_14';
            }

            // Risk 2: Coin kritis
            if ((int)$t['coin_balance'] < 5000) {
                $risks[] = 'coin_kritis';
            }

            // Risk 3: Ada outlet dengan trial habis dalam 3 hari
            if ((int)$t['trial_outlets'] > 0 && $t['trial_ends_at'] && strtotime($t['trial_ends_at']) < strtotime('+3 days')) {
                $risks[] = 'trial_habis';
            }

            // Risk 4: Tidak pernah topup
            if ((int)$t['payment_count'] === 0) {
                $risks[] = 'tidak_pernah_topup';
            }

            // Risk 5: Order turun drastis
            $weekAvg  = (int)$t['orders_this_week'];
            $monthAvg = round((int)$t['orders_last_month_approx'] / 3); // 3 weeks
            if ($monthAvg > 0 && $weekAvg < ($monthAvg * 0.3)) {
                $risks[] = 'order_turun';
            }

            if (!empty($risks)) {
                $t['risks'] = $risks;
                $t['risk_count'] = count($risks);
                $result[] = $t;
            }
        }

        // Sort by risk count desc
        usort($result, fn($a, $b) => $b['risk_count'] - $a['risk_count']);

        echo json_encode($result);
        exit;
    }

    if ($action === 'mark_followup') {
        saVerifyCsrf();
        $id    = (int)($_POST['tenant_id'] ?? 0);
        $admin = saCurrentAdmin();
        $note  = '✓ Follow up on ' . date('d M Y H:i') . ' by ' . ($admin['name'] ?? 'Admin');
        $db->prepare(
            "INSERT INTO tenant_notes (tenant_id, superadmin_id, note, is_pinned)
             VALUES (?, ?, ?, 0)"
        )->execute([$id, $_SESSION['superadmin_id'], $note]);
        logSuperAdminAction('mark_followup', $id, 'Marked as followed up');
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'add_comm') {
        saVerifyCsrf();
        $id      = (int)($_POST['tenant_id'] ?? 0);
        $channel = $_POST['channel'] ?? 'wa';
        $subject = trim($_POST['subject'] ?? '');
        $msg     = trim($_POST['message'] ?? '');
        $type    = 'churn_risk';
        $db->prepare(
            "INSERT INTO support_tickets (tenant_id, superadmin_id, channel, subject, message, type)
             VALUES (?,?,?,?,?,?)"
        )->execute([$id, $_SESSION['superadmin_id'], $channel, $subject, $msg, $type]);
        logSuperAdminAction('churn_comm', $id, "Churn comm: $channel - $subject");
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Action tidak dikenal.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Churn Risk'); ?>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('churn_risk', 'Churn Risk Monitor'); ?>

<div class="sa-page-header">
  <h1>Churn Risk</h1>
  <p>Tenant berisiko churn yang perlu follow up segera</p>
</div>

<!-- Risk Legend -->
<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;">
  <span class="sa-risk-badge risk-tidak-login">⏰ Tidak login 14+ hari</span>
  <span class="sa-risk-badge risk-coin">🪙 Coin kritis &lt;5K</span>
  <span class="sa-risk-badge risk-trial">⚡ Trial hampir habis</span>
  <span class="sa-risk-badge risk-no-topup">💳 Belum pernah topup</span>
  <span class="sa-risk-badge risk-order-turun">📉 Order turun &gt;70%</span>
</div>

<!-- Stats -->
<div class="sa-stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));margin-bottom:20px;">
  <div class="sa-stat-card red"><div class="label">Total At Risk</div><div class="value" id="cr-total">—</div></div>
  <div class="sa-stat-card red"><div class="label">Risiko Tinggi (3+)</div><div class="value" id="cr-high">—</div></div>
  <div class="sa-stat-card yellow"><div class="label">Belum Follow Up</div><div class="value" id="cr-nofup">—</div></div>
</div>

<!-- Table -->
<div class="sa-card">
  <div class="sa-filter-bar">
    <input type="text" id="crSearch" placeholder="Cari tenant..." oninput="filterChurn()"/>
    <select id="crRiskFilter" onchange="filterChurn()">
      <option value="">Semua Risiko</option>
      <option value="tidak_login_14">Tidak Login</option>
      <option value="coin_kritis">Coin Kritis</option>
      <option value="trial_habis">Trial Habis</option>
      <option value="tidak_pernah_topup">Belum Topup</option>
      <option value="order_turun">Order Turun</option>
    </select>
  </div>

  <div class="sa-table-wrap">
    <table class="sa-table">
      <thead>
        <tr>
          <th>Tenant</th>
          <th>Owner</th>
          <th>Risiko</th>
          <th>Coin</th>
          <th>Last Login</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody id="crBody">
        <tr><td colspan="6" style="text-align:center;padding:32px;color:rgba(255,255,255,.35);">Memuat...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Comm Modal -->
<div class="sa-modal-overlay" id="commModal">
  <div class="sa-modal">
    <h3>💬 Catat Komunikasi</h3>
    <input type="hidden" id="commTenantId"/>
    <div class="form-group">
      <label>Channel</label>
      <select id="commCh">
        <option value="wa">WhatsApp</option>
        <option value="call">Telepon</option>
        <option value="email">Email</option>
      </select>
    </div>
    <div class="form-group">
      <label>Subjek</label>
      <input type="text" id="commSubj" placeholder="Contoh: Follow up churn risk"/>
    </div>
    <div class="form-group">
      <label>Catatan</label>
      <textarea id="commMsg" placeholder="Hasil komunikasi..."></textarea>
    </div>
    <div class="sa-modal-footer">
      <button class="sa-btn sa-btn-outline" onclick="closeModal('commModal')">Batal</button>
      <button class="sa-btn sa-btn-primary" onclick="submitComm()">Simpan</button>
    </div>
  </div>
</div>

<?php saRenderNavClose(); ?>

<script>
let crAllRows = [];

fetch('churn_risk.php?action=list', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
  .then(r => r.json()).then(data => {
    crAllRows = data;
    document.getElementById('cr-total').textContent = data.length;
    document.getElementById('cr-high').textContent  = data.filter(r => r.risk_count >= 3).length;
    document.getElementById('cr-nofup').textContent = data.length; // simplified
    renderChurn(data);
  });

function filterChurn() {
  const q   = document.getElementById('crSearch').value.toLowerCase();
  const rf  = document.getElementById('crRiskFilter').value;
  let rows = crAllRows.filter(r => {
    const match = !q || r.nama_outlet.toLowerCase().includes(q) || (r.owner_name||'').toLowerCase().includes(q);
    const riskOk = !rf || r.risks.includes(rf);
    return match && riskOk;
  });
  renderChurn(rows);
}

const riskBadges = {
  tidak_login_14:    '<span class="sa-risk-badge risk-tidak-login">⏰ Tidak Login</span>',
  coin_kritis:       '<span class="sa-risk-badge risk-coin">🪙 Coin Kritis</span>',
  trial_habis:       '<span class="sa-risk-badge risk-trial">⚡ Trial Habis</span>',
  tidak_pernah_topup:'<span class="sa-risk-badge risk-no-topup">💳 Belum Topup</span>',
  order_turun:       '<span class="sa-risk-badge risk-order-turun">📉 Order Turun</span>',
};

function relTime(ts) {
  if (!ts) return '<span style="color:rgba(255,255,255,.3);">Belum pernah</span>';
  const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
  if (diff < 3600)  return Math.floor(diff/60) + ' mnt lalu';
  if (diff < 86400) return Math.floor(diff/3600) + ' jam lalu';
  return Math.floor(diff/86400) + ' hari lalu';
}

function esc(s) { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

function renderChurn(rows) {
  const tbody = document.getElementById('crBody');
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:rgba(255,255,255,.35);">Tidak ada churn risk saat ini.</td></tr>';
    return;
  }
  tbody.innerHTML = rows.map(r => {
    const riskBadgeHtml = r.risks.map(k => riskBadges[k] || k).join('');
    const bgRed = r.risk_count >= 3 ? 'background:rgba(239,68,68,.04);' : '';
    const coinFmt = parseInt(r.coin_balance).toLocaleString('id-ID');
    const coinCls = r.coin_balance < 5000 ? 'coin-kritis' : (r.coin_balance < 10000 ? 'coin-rendah' : 'coin-ok');
    return `<tr style="${bgRed}">
      <td>
        <a href="client_detail.php?id=${r.id}" style="color:var(--white);text-decoration:none;font-weight:600;">${esc(r.nama_outlet)}</a>
        <br><span class="sa-badge ${r.risk_count>=3?'sa-badge-red':'sa-badge-yellow'}" style="font-size:10px;margin-top:2px;">${r.risk_count} risiko</span>
      </td>
      <td style="font-size:12.5px;">${esc(r.owner_name)}</td>
      <td>${riskBadgeHtml}</td>
      <td><span class="${coinCls}">${coinFmt}</span></td>
      <td style="font-size:12px;">${relTime(r.last_login)}</td>
      <td>
        <a href="https://wa.me/${esc(r.owner_wa)}" target="_blank" class="sa-btn sa-btn-sm sa-btn-wa" style="margin-bottom:3px;">💬 WA</a>
        <button class="sa-btn sa-btn-sm sa-btn-outline" onclick="markFollowup(${r.id}, '${esc(r.nama_outlet)}')" style="margin-left:3px;margin-bottom:3px;">✓ Follow Up</button>
        <button class="sa-btn sa-btn-sm sa-btn-primary" onclick="openComm(${r.id})" style="margin-left:3px;">+ Catat</button>
        <a href="client_detail.php?id=${r.id}" class="sa-btn sa-btn-sm sa-btn-outline" style="margin-left:3px;">Detail</a>
      </td>
    </tr>`;
  }).join('');
}

function markFollowup(id, nama) {
  saPost('churn_risk.php?action=mark_followup', { tenant_id: id })
    .then(r => r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      saShowToast('Follow up dicatat untuk ' + nama, 'success');
    });
}

let commTargetId = null;
function openComm(id) {
  commTargetId = id;
  document.getElementById('commCh').value    = 'wa';
  document.getElementById('commSubj').value  = '';
  document.getElementById('commMsg').value   = '';
  document.getElementById('commModal').classList.add('open');
}

function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

function submitComm() {
  if (!commTargetId) return;
  saPost('churn_risk.php?action=add_comm', {
    tenant_id: commTargetId,
    channel:   document.getElementById('commCh').value,
    subject:   document.getElementById('commSubj').value,
    message:   document.getElementById('commMsg').value,
  }).then(r => r.json()).then(d => {
    if (d.error) { saShowToast(d.error, 'error'); return; }
    saShowToast('Komunikasi dicatat.', 'success');
    closeModal('commModal');
  });
}
</script>
</body>
</html>
