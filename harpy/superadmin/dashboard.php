<?php
// ══════════════════════════════════════════════════════
// superadmin/dashboard.php — Platform Overview
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';

date_default_timezone_set('Asia/Jakarta');

$action = $_GET['action'] ?? '';

// ── API ACTIONS ───────────────────────────────────────
if ($action) {
    header('Content-Type: application/json');
    $db = Database::get();

    if ($action === 'stats') {
        $month = date('n');
        $year  = date('Y');

        $totals = $db->query(
            "SELECT
               COUNT(*) as total,
               SUM(status='active') as aktif,
               SUM(status='trial') as trial,
               SUM(status='suspended') as suspended
             FROM tenants"
        )->fetch();

        $revenue = $db->prepare(
            "SELECT COALESCE(SUM(amount),0) as total
             FROM payments
             WHERE status='success' AND MONTH(paid_at)=? AND YEAR(paid_at)=?"
        );
        $revenue->execute([$month, $year]);
        $revenueTotal = $revenue->fetchColumn();

        $coinSold = $db->prepare(
            "SELECT COALESCE(SUM(coin_amount),0) as total
             FROM payments
             WHERE type='coin_topup' AND MONTH(paid_at)=? AND YEAR(paid_at)=? AND status='success'"
        );
        $coinSold->execute([$month, $year]);
        $coinSoldTotal = $coinSold->fetchColumn();

        $newTenants = $db->query(
            "SELECT COUNT(*) FROM tenants WHERE provisioned_at >= NOW() - INTERVAL 30 DAY"
        )->fetchColumn();

        $churnRisk = $db->query(
            "SELECT COUNT(DISTINCT t.id) FROM tenants t
             LEFT JOIN hl_users u ON u.tenant_id = t.id
             WHERE t.status IN ('active','trial')
             AND (
               t.coin_balance < 5000
               OR (t.status='trial' AND t.trial_ends_at < DATE_ADD(NOW(), INTERVAL 3 DAY))
               OR (t.status='active' AND (
                 SELECT MAX(u2.last_login) FROM hl_users u2 WHERE u2.tenant_id = t.id
               ) < NOW() - INTERVAL 14 DAY
               OR (SELECT MAX(u2.last_login) FROM hl_users u2 WHERE u2.tenant_id = t.id) IS NULL)
             )"
        )->fetchColumn();

        $coinKritis = $db->query(
            "SELECT COUNT(*) FROM tenants WHERE coin_balance < 5000 AND status='active'"
        )->fetchColumn();

        echo json_encode([
            'total'       => (int)$totals['total'],
            'aktif'       => (int)$totals['aktif'],
            'trial'       => (int)$totals['trial'],
            'suspended'   => (int)$totals['suspended'],
            'revenue'     => (float)$revenueTotal,
            'coin_sold'   => (int)$coinSoldTotal,
            'new_tenants' => (int)$newTenants,
            'churn_risk'  => (int)$churnRisk,
            'coin_kritis' => (int)$coinKritis,
        ]);
        exit;
    }

    if ($action === 'alerts') {
        // Coin kritis (< 10000)
        $coinAlert = $db->query(
            "SELECT id, nama_outlet, owner_name, owner_wa, coin_balance
             FROM tenants WHERE coin_balance < 10000 AND status='active'
             ORDER BY coin_balance ASC LIMIT 10"
        )->fetchAll();

        // Trial < 3 hari
        $trialAlert = $db->query(
            "SELECT id, nama_outlet, owner_name, owner_wa, trial_ends_at,
                    DATEDIFF(trial_ends_at, NOW()) as days_left
             FROM tenants
             WHERE status='trial' AND trial_ends_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)
             ORDER BY trial_ends_at ASC LIMIT 10"
        )->fetchAll();

        // Tidak login > 14 hari
        $inactiveAlert = $db->query(
            "SELECT t.id, t.nama_outlet, t.owner_name, t.owner_wa,
                    MAX(u.last_login) as last_login,
                    DATEDIFF(NOW(), MAX(u.last_login)) as days_inactive
             FROM tenants t
             LEFT JOIN hl_users u ON u.tenant_id = t.id
             WHERE t.status = 'active'
             GROUP BY t.id
             HAVING last_login < NOW() - INTERVAL 14 DAY OR last_login IS NULL
             ORDER BY last_login ASC LIMIT 10"
        )->fetchAll();

        echo json_encode([
            'coin_kritis' => $coinAlert,
            'trial_habis' => $trialAlert,
            'tidak_login' => $inactiveAlert,
        ]);
        exit;
    }

    if ($action === 'chart_tenants') {
        $rows = $db->query(
            "SELECT DATE_FORMAT(provisioned_at,'%b %Y') as label,
                    YEAR(provisioned_at) as yr, MONTH(provisioned_at) as mo,
                    COUNT(*) as total
             FROM tenants
             WHERE provisioned_at >= NOW() - INTERVAL 6 MONTH
             GROUP BY yr, mo, label
             ORDER BY yr ASC, mo ASC"
        )->fetchAll();

        echo json_encode($rows);
        exit;
    }

    if ($action === 'chart_coins') {
        $rows = $db->query(
            "SELECT DATE_FORMAT(paid_at,'%b %Y') as label,
                    YEAR(paid_at) as yr, MONTH(paid_at) as mo,
                    COALESCE(SUM(coin_amount),0) as total
             FROM payments
             WHERE type='coin_topup' AND status='success'
               AND paid_at >= NOW() - INTERVAL 6 MONTH
             GROUP BY yr, mo, label
             ORDER BY yr ASC, mo ASC"
        )->fetchAll();

        echo json_encode($rows);
        exit;
    }

    echo json_encode(['error' => 'Action tidak dikenal.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Dashboard'); ?>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('dashboard', 'Dashboard'); ?>

<div class="sa-page-header">
  <h1>Platform Dashboard</h1>
  <p>Overview seluruh tenant Harpy Laundry ERP</p>
</div>

<!-- Stats Grid -->
<div class="sa-stats-grid" id="statsGrid">
  <div class="sa-stat-card indigo"><div class="label">Total Tenant</div><div class="value" id="s-total">—</div><span class="icon-bg">🏪</span></div>
  <div class="sa-stat-card green"><div class="label">Aktif</div><div class="value" id="s-aktif">—</div><span class="icon-bg">✅</span></div>
  <div class="sa-stat-card blue"><div class="label">Trial</div><div class="value" id="s-trial">—</div><span class="icon-bg">🔬</span></div>
  <div class="sa-stat-card red"><div class="label">Suspended</div><div class="value" id="s-suspended">—</div><span class="icon-bg">🔒</span></div>
  <div class="sa-stat-card green"><div class="label">Revenue Bulan Ini</div><div class="value" id="s-revenue" style="font-size:18px">—</div><span class="icon-bg">💰</span></div>
  <div class="sa-stat-card indigo"><div class="label">Coin Terjual Bulan Ini</div><div class="value" id="s-coin" style="font-size:18px">—</div><span class="icon-bg">🪙</span></div>
  <div class="sa-stat-card blue"><div class="label">Tenant Baru 30 Hari</div><div class="value" id="s-new">—</div><span class="icon-bg">🆕</span></div>
  <div class="sa-stat-card yellow"><div class="label">Churn Risk</div><div class="value" id="s-churn">—</div><div class="sub">perlu follow up</div><span class="icon-bg">⚠️</span></div>
  <div class="sa-stat-card red"><div class="label">Coin Kritis</div><div class="value" id="s-kritis">—</div><div class="sub">&lt; 5.000 coin</div><span class="icon-bg">🔴</span></div>
</div>

<!-- Charts Row -->
<div class="sa-grid-2" style="margin-bottom:24px;">
  <div class="sa-card">
    <div class="sa-card-header"><h3>📈 Tenant Baru per Bulan</h3></div>
    <div class="sa-card-body"><canvas id="chartTenants" height="180"></canvas></div>
  </div>
  <div class="sa-card">
    <div class="sa-card-header"><h3>🪙 Coin Terjual per Bulan</h3></div>
    <div class="sa-card-body"><canvas id="chartCoins" height="180"></canvas></div>
  </div>
</div>

<!-- Alerts -->
<div class="sa-card">
  <div class="sa-card-header">
    <h3>🚨 Alert Aktif</h3>
    <div class="sa-tabs" style="margin-bottom:0;border:none;">
      <button class="sa-tab active" onclick="switchAlertTab('coin')">Coin Kritis</button>
      <button class="sa-tab" onclick="switchAlertTab('trial')">Trial Habis</button>
      <button class="sa-tab" onclick="switchAlertTab('inactive')">Tidak Aktif</button>
    </div>
  </div>

  <div class="sa-card-body">
    <div id="alertCoin">
      <div style="color:rgba(255,255,255,.4);font-size:13px;">Memuat...</div>
    </div>
    <div id="alertTrial" style="display:none">
      <div style="color:rgba(255,255,255,.4);font-size:13px;">Memuat...</div>
    </div>
    <div id="alertInactive" style="display:none">
      <div style="color:rgba(255,255,255,.4);font-size:13px;">Memuat...</div>
    </div>
  </div>
</div>

<?php saRenderNavClose(); ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const rupiah = n => 'Rp ' + parseInt(n).toLocaleString('id-ID');
const saFmtCoin = n => parseInt(n).toLocaleString('id-ID');

// Load stats
fetch('dashboard.php?action=stats', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
  .then(r => r.json()).then(d => {
    document.getElementById('s-total').textContent = d.total;
    document.getElementById('s-aktif').textContent = d.aktif;
    document.getElementById('s-trial').textContent = d.trial;
    document.getElementById('s-suspended').textContent = d.suspended;
    document.getElementById('s-revenue').textContent = rupiah(d.revenue);
    document.getElementById('s-coin').textContent = saFmtCoin(d.coin_sold);
    document.getElementById('s-new').textContent = d.new_tenants;
    document.getElementById('s-churn').textContent = d.churn_risk;
    document.getElementById('s-kritis').textContent = d.coin_kritis;
  });

// Charts
const chartDefaults = {
  plugins: { legend: { display: false } },
  scales: {
    x: { grid: { color: 'rgba(255,255,255,.06)' }, ticks: { color: 'rgba(255,255,255,.5)', font: { size: 11 } } },
    y: { grid: { color: 'rgba(255,255,255,.06)' }, ticks: { color: 'rgba(255,255,255,.5)', font: { size: 11 } }, beginAtZero: true }
  }
};

fetch('dashboard.php?action=chart_tenants', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
  .then(r => r.json()).then(rows => {
    new Chart(document.getElementById('chartTenants'), {
      type: 'bar',
      data: {
        labels: rows.map(r => r.label),
        datasets: [{ data: rows.map(r => r.total),
          backgroundColor: 'rgba(99,102,241,.6)', borderColor: '#6366F1',
          borderWidth: 2, borderRadius: 6 }]
      },
      options: { ...chartDefaults, responsive: true }
    });
  });

fetch('dashboard.php?action=chart_coins', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
  .then(r => r.json()).then(rows => {
    new Chart(document.getElementById('chartCoins'), {
      type: 'line',
      data: {
        labels: rows.map(r => r.label),
        datasets: [{ data: rows.map(r => r.total),
          borderColor: '#6366F1', backgroundColor: 'rgba(99,102,241,.15)',
          borderWidth: 2, fill: true, tension: .4,
          pointBackgroundColor: '#6366F1', pointRadius: 4 }]
      },
      options: { ...chartDefaults, responsive: true }
    });
  });

// Alerts
let alertsData = null;
fetch('dashboard.php?action=alerts', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
  .then(r => r.json()).then(d => {
    alertsData = d;
    renderAlertCoin(d.coin_kritis);
    renderAlertTrial(d.trial_habis);
    renderAlertInactive(d.tidak_login);
  });

function renderAlertCoin(list) {
  const el = document.getElementById('alertCoin');
  if (!list || !list.length) { el.innerHTML = '<p style="color:rgba(255,255,255,.35);font-size:13px;">Tidak ada alert coin kritis.</p>'; return; }
  el.innerHTML = list.map(t => `
    <div class="sa-alert-item">
      <span class="alert-icon">🔴</span>
      <div class="alert-text">
        <strong>${esc(t.nama_outlet)}</strong> — ${esc(t.owner_name)}
        <span class="coin-kritis" style="margin-left:8px;">${parseInt(t.coin_balance).toLocaleString('id-ID')} coin</span>
      </div>
      <div class="alert-action">
        <a href="client_detail.php?id=${t.id}" class="sa-btn sa-btn-sm sa-btn-outline">Detail</a>
        <a href="https://wa.me/${t.owner_wa}" target="_blank" class="sa-btn sa-btn-sm sa-btn-wa" style="margin-left:4px;">WA</a>
      </div>
    </div>`).join('');
}

function renderAlertTrial(list) {
  const el = document.getElementById('alertTrial');
  if (!list || !list.length) { el.innerHTML = '<p style="color:rgba(255,255,255,.35);font-size:13px;">Tidak ada trial akan habis.</p>'; return; }
  el.innerHTML = list.map(t => `
    <div class="sa-alert-item">
      <span class="alert-icon">⏰</span>
      <div class="alert-text">
        <strong>${esc(t.nama_outlet)}</strong> — ${esc(t.owner_name)}
        <span class="sa-badge sa-badge-yellow" style="margin-left:8px;">${t.days_left} hari lagi</span>
      </div>
      <div class="alert-action">
        <a href="client_detail.php?id=${t.id}" class="sa-btn sa-btn-sm sa-btn-outline">Detail</a>
        <a href="https://wa.me/${t.owner_wa}" target="_blank" class="sa-btn sa-btn-sm sa-btn-wa" style="margin-left:4px;">WA</a>
      </div>
    </div>`).join('');
}

function renderAlertInactive(list) {
  const el = document.getElementById('alertInactive');
  if (!list || !list.length) { el.innerHTML = '<p style="color:rgba(255,255,255,.35);font-size:13px;">Tidak ada tenant tidak aktif.</p>'; return; }
  el.innerHTML = list.map(t => `
    <div class="sa-alert-item">
      <span class="alert-icon">😴</span>
      <div class="alert-text">
        <strong>${esc(t.nama_outlet)}</strong> — ${esc(t.owner_name)}
        <span style="color:rgba(255,255,255,.4);margin-left:8px;font-size:12px;">${t.days_inactive ? t.days_inactive+' hari tidak login' : 'Belum pernah login'}</span>
      </div>
      <div class="alert-action">
        <a href="client_detail.php?id=${t.id}" class="sa-btn sa-btn-sm sa-btn-outline">Detail</a>
        <a href="https://wa.me/${t.owner_wa}" target="_blank" class="sa-btn sa-btn-sm sa-btn-wa" style="margin-left:4px;">WA</a>
      </div>
    </div>`).join('');
}

function switchAlertTab(tab) {
  document.querySelectorAll('.sa-tabs .sa-tab').forEach((t,i) => {
    const tabs = ['coin','trial','inactive'];
    t.classList.toggle('active', tabs[i] === tab);
  });
  document.getElementById('alertCoin').style.display    = tab === 'coin'     ? '' : 'none';
  document.getElementById('alertTrial').style.display   = tab === 'trial'    ? '' : 'none';
  document.getElementById('alertInactive').style.display = tab === 'inactive' ? '' : 'none';
}

function esc(s) {
  const d = document.createElement('div'); d.textContent = s; return d.innerHTML;
}
</script>
</body>
</html>
