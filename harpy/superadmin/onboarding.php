<?php
// ══════════════════════════════════════════════════════
// superadmin/onboarding.php — Onboarding Tracker
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
        // Get tenants: new (last 30 days) + any tenant not fully onboarded
        $tenants = $db->query(
            "SELECT t.id, t.nama_outlet, t.owner_name, t.owner_wa, t.status, t.created_at, t.provisioned_at
             FROM tenants t
             WHERE t.provisioned_at >= NOW() - INTERVAL 90 DAY
                OR t.id IN (
                  SELECT tt.id FROM tenants tt
                  LEFT JOIN hl_layanan l ON l.tenant_id = tt.id
                  LEFT JOIN hl_transaksi tx ON tx.tenant_id = tt.id
                  WHERE l.id IS NULL OR tx.id IS NULL
                )
             ORDER BY t.provisioned_at DESC
             LIMIT 100"
        )->fetchAll();

        $result = [];
        foreach ($tenants as $t) {
            $id = $t['id'];

            // Step 1: Login pertama
            $s1 = (bool)$db->prepare("SELECT MAX(last_login) FROM hl_users WHERE tenant_id=?")
                ->execute([$id]) ? null : null;
            $stm = $db->prepare("SELECT MAX(last_login) FROM hl_users WHERE tenant_id=?");
            $stm->execute([$id]);
            $firstLogin = $stm->fetchColumn();
            $t['step1'] = !empty($firstLogin);

            // Step 2: Input layanan
            $stm = $db->prepare("SELECT COUNT(*) FROM hl_layanan WHERE tenant_id=?");
            $stm->execute([$id]);
            $t['step2'] = (int)$stm->fetchColumn() > 0;

            // Step 3: Input karyawan (user selain yang pertama register — count user > 1)
            $stm = $db->prepare("SELECT COUNT(*) FROM hl_users WHERE tenant_id=?");
            $stm->execute([$id]);
            $t['step3'] = (int)$stm->fetchColumn() > 1;

            // Step 4: Order pertama
            $stm = $db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=?");
            $stm->execute([$id]);
            $t['step4'] = (int)$stm->fetchColumn() > 0;

            // Step 5: Kirim WA (pakai coin)
            $stm = $db->prepare("SELECT COUNT(*) FROM coin_ledger WHERE tenant_id=? AND feature_used='send_wa'");
            $stm->execute([$id]);
            $t['step5'] = (int)$stm->fetchColumn() > 0;

            $t['steps_done'] = (int)$t['step1'] + (int)$t['step2'] + (int)$t['step3'] + (int)$t['step4'] + (int)$t['step5'];
            $t['complete'] = $t['steps_done'] >= 5;

            // Last activity
            $stm = $db->prepare("SELECT MAX(last_login) FROM hl_users WHERE tenant_id=?");
            $stm->execute([$id]);
            $t['last_login'] = $stm->fetchColumn();

            $result[] = $t;
        }

        // Sort: incomplete first, then by provisioned_at
        usort($result, fn($a, $b) => $a['complete'] !== $b['complete']
            ? (int)$a['complete'] - (int)$b['complete']
            : strtotime($b['provisioned_at'] ?? '0') - strtotime($a['provisioned_at'] ?? '0')
        );

        echo json_encode($result);
        exit;
    }

    if ($action === 'send_reminder') {
        saVerifyCsrf();
        $id = (int)($_POST['tenant_id'] ?? 0);
        // Log as support ticket
        $db->prepare(
            "INSERT INTO support_tickets (tenant_id, superadmin_id, channel, subject, message, type)
             VALUES (?, ?, 'system', 'WA Reminder Onboarding', 'Reminder otomatis terkirim via super admin', 'onboarding')"
        )->execute([$id, $_SESSION['superadmin_id']]);
        logSuperAdminAction('send_reminder', $id, 'WA onboarding reminder sent');
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
<?php saRenderHead('Onboarding'); ?>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('onboarding', 'Onboarding Tracker'); ?>

<div class="sa-page-header">
  <h1>Onboarding Tracker</h1>
  <p>Pantau progress onboarding setiap tenant baru</p>
</div>

<!-- Summary -->
<div class="sa-stats-grid" id="onboardStats" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));margin-bottom:24px;">
  <div class="sa-stat-card indigo"><div class="label">Total Dipantau</div><div class="value" id="ob-total">—</div></div>
  <div class="sa-stat-card green"><div class="label">Onboarding Selesai</div><div class="value" id="ob-done">—</div></div>
  <div class="sa-stat-card yellow"><div class="label">Dalam Progress</div><div class="value" id="ob-inprog">—</div></div>
  <div class="sa-stat-card red"><div class="label">Stuck (belum login)</div><div class="value" id="ob-stuck">—</div></div>
</div>

<!-- Filter -->
<div class="sa-card">
  <div class="sa-filter-bar">
    <input type="text" id="obSearch" placeholder="Cari nama outlet..." oninput="filterTable()"/>
    <select id="obFilter" onchange="filterTable()">
      <option value="">Semua</option>
      <option value="incomplete">Belum Selesai</option>
      <option value="complete">Sudah Selesai</option>
      <option value="stuck">Stuck (step 1)</option>
    </select>
  </div>

  <div class="sa-table-wrap">
    <table class="sa-table">
      <thead>
        <tr>
          <th>Tenant</th>
          <th>Owner</th>
          <th style="text-align:center;">1. Login</th>
          <th style="text-align:center;">2. Layanan</th>
          <th style="text-align:center;">3. Karyawan</th>
          <th style="text-align:center;">4. Order</th>
          <th style="text-align:center;">5. Kirim WA</th>
          <th style="text-align:center;">Progress</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody id="obBody">
        <tr><td colspan="9" style="text-align:center;padding:32px;color:rgba(255,255,255,.35);">Memuat...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<?php saRenderNavClose(); ?>

<script>
let allRows = [];

fetch('onboarding.php?action=list', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
  .then(r => r.json()).then(data => {
    allRows = data;
    updateStats(data);
    renderTable(data);
  });

function updateStats(data) {
  const done    = data.filter(r => r.complete).length;
  const inprog  = data.filter(r => !r.complete && r.step1).length;
  const stuck   = data.filter(r => !r.step1).length;
  document.getElementById('ob-total').textContent  = data.length;
  document.getElementById('ob-done').textContent   = done;
  document.getElementById('ob-inprog').textContent = inprog;
  document.getElementById('ob-stuck').textContent  = stuck;
}

function filterTable() {
  const q  = document.getElementById('obSearch').value.toLowerCase();
  const f  = document.getElementById('obFilter').value;
  let rows = allRows.filter(r => {
    const match = !q || r.nama_outlet.toLowerCase().includes(q) || (r.owner_name||'').toLowerCase().includes(q);
    const modeOk = !f
      || (f === 'incomplete' && !r.complete)
      || (f === 'complete' && r.complete)
      || (f === 'stuck' && !r.step1);
    return match && modeOk;
  });
  renderTable(rows);
}

function renderTable(rows) {
  const tbody = document.getElementById('obBody');
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:24px;color:rgba(255,255,255,.35);">Tidak ada data.</td></tr>';
    return;
  }

  function stepIcon(ok) {
    return ok ? '<span class="step-done">✓</span>' : '<span class="step-fail">✗</span>';
  }

  function progressBar(n) {
    const pct = Math.round(n / 5 * 100);
    const color = pct === 100 ? '#10B981' : pct >= 60 ? '#F59E0B' : '#EF4444';
    return `<div style="display:flex;align-items:center;gap:6px;">
      <div style="flex:1;height:6px;background:rgba(255,255,255,.1);border-radius:3px;overflow:hidden;">
        <div style="width:${pct}%;height:100%;background:${color};border-radius:3px;transition:width .3s;"></div>
      </div>
      <span style="font-size:11px;color:rgba(255,255,255,.5);font-family:var(--mono);">${n}/5</span>
    </div>`;
  }

  function isStuck(r) {
    if (r.complete) return false;
    // Stuck if no login for > 24h since provisioned
    if (!r.last_login) return true;
    const diff = Date.now() - new Date(r.last_login).getTime();
    return diff > 24 * 3600 * 1000 && !r.complete;
  }

  function esc(s) { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

  tbody.innerHTML = rows.map(r => {
    const stuck = isStuck(r);
    const rowBg = stuck ? 'background:rgba(239,68,68,.04);' : '';
    return `<tr style="${rowBg}">
      <td>
        <a href="client_detail.php?id=${r.id}" style="color:var(--white);text-decoration:none;font-weight:600;">${esc(r.nama_outlet)}</a>
        ${stuck ? '<br><span style="font-size:10px;color:#FCA5A5;">⚠ Stuck</span>' : ''}
      </td>
      <td style="font-size:12.5px;">${esc(r.owner_name)}</td>
      <td style="text-align:center;">${stepIcon(r.step1)}</td>
      <td style="text-align:center;">${stepIcon(r.step2)}</td>
      <td style="text-align:center;">${stepIcon(r.step3)}</td>
      <td style="text-align:center;">${stepIcon(r.step4)}</td>
      <td style="text-align:center;">${stepIcon(r.step5)}</td>
      <td>${progressBar(r.steps_done)}</td>
      <td>
        <a href="client_detail.php?id=${r.id}" class="sa-btn sa-btn-sm sa-btn-outline" style="margin-right:4px;">Detail</a>
        ${!r.complete ? `<button class="sa-btn sa-btn-sm sa-btn-wa" onclick="sendReminder(${r.id}, '${esc(r.nama_outlet)}')">WA Reminder</button>` : ''}
      </td>
    </tr>`;
  }).join('');
}

function sendReminder(id, nama) {
  if (!confirm(`Kirim WA reminder ke ${nama}?`)) return;
  saPost('onboarding.php?action=send_reminder', { tenant_id: id })
    .then(r => r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      saShowToast('Reminder dicatat. Kirim manual via WA.', 'info');
    });
}
</script>
</body>
</html>
