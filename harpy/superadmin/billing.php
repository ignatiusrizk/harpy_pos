<?php
// ══════════════════════════════════════════════════════
// superadmin/billing.php — Revenue Overview
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';

date_default_timezone_set('Asia/Jakarta');

$action = $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');
    $db = Database::get();

    if ($action === 'stats') {
        $m  = date('n'); $y = date('Y');
        $lm = date('n', strtotime('-1 month')); $ly = date('Y', strtotime('-1 month'));

        function getBillingStats(PDO $db, int $month, int $year): array {
            $stm = $db->prepare(
                "SELECT
                   COALESCE(SUM(CASE WHEN type='setup_fee' THEN amount END),0) as setup_fee,
                   COALESCE(SUM(CASE WHEN type='coin_topup' THEN amount END),0) as coin_topup,
                   COALESCE(SUM(amount),0) as grand_total,
                   COUNT(*) as count
                 FROM payments
                 WHERE status='success' AND MONTH(paid_at)=? AND YEAR(paid_at)=?"
            );
            $stm->execute([$month, $year]);
            return $stm->fetch();
        }

        $ytd = $db->prepare(
            "SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE status='success' AND YEAR(paid_at)=?"
        );
        $ytd->execute([$y]);

        echo json_encode([
            'bulan_ini'  => getBillingStats($db, $m, $y),
            'bulan_lalu' => getBillingStats($db, $lm, $ly),
            'ytd'        => (float)$ytd->fetchColumn(),
        ]);
        exit;
    }

    if ($action === 'list') {
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $month  = (int)($_GET['month'] ?? 0);
        $year   = (int)($_GET['year'] ?? date('Y'));
        $type   = $_GET['type'] ?? '';
        $status = $_GET['status'] ?? '';
        $limit  = 25;
        $offset = ($page - 1) * $limit;

        $where  = ['1=1'];
        $params = [];

        if ($month > 0) {
            $where[] = 'MONTH(p.paid_at) = ? AND YEAR(p.paid_at) = ?';
            array_push($params, $month, $year);
        }
        if ($type) {
            $where[] = 'p.type = ?';
            $params[] = $type;
        }
        if ($status) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }

        $whereStr = implode(' AND ', $where);

        $cnt = $db->prepare("SELECT COUNT(*) FROM payments p WHERE $whereStr");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $stm = $db->prepare(
            "SELECT p.*, t.nama_outlet, t.owner_name
             FROM payments p
             LEFT JOIN tenants t ON t.id = p.tenant_id
             WHERE $whereStr
             ORDER BY p.created_at DESC
             LIMIT $limit OFFSET $offset"
        );
        $stm->execute($params);
        $rows = $stm->fetchAll();

        echo json_encode([
            'rows'  => $rows,
            'total' => $total,
            'pages' => max(1, (int)ceil($total / $limit)),
            'page'  => $page,
        ]);
        exit;
    }

    echo json_encode(['error' => 'Action tidak dikenal.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Billing'); ?>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('billing', 'Billing & Revenue'); ?>

<div class="sa-page-header">
  <h1>Billing</h1>
  <p>Revenue dan pembayaran seluruh platform</p>
</div>

<!-- Revenue Cards -->
<div id="billingStats" style="margin-bottom:24px;">
  <div class="sa-stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr));">
    <!-- Bulan ini -->
    <div class="sa-stat-card green">
      <div class="label">Setup Fee Bulan Ini</div>
      <div class="value" id="b-sf-cur" style="font-size:18px;">—</div>
      <span class="icon-bg">🏪</span>
    </div>
    <div class="sa-stat-card green">
      <div class="label">Coin Topup Bulan Ini</div>
      <div class="value" id="b-ct-cur" style="font-size:18px;">—</div>
      <span class="icon-bg">🪙</span>
    </div>
    <div class="sa-stat-card indigo">
      <div class="label">Total Bulan Ini</div>
      <div class="value" id="b-tot-cur" style="font-size:18px;">—</div>
      <div class="sub" id="b-cnt-cur"></div>
      <span class="icon-bg">💰</span>
    </div>
    <!-- Bulan lalu -->
    <div class="sa-stat-card blue">
      <div class="label">Total Bulan Lalu</div>
      <div class="value" id="b-tot-last" style="font-size:18px;">—</div>
      <span class="icon-bg">📅</span>
    </div>
    <!-- YTD -->
    <div class="sa-stat-card yellow">
      <div class="label">YTD <?= date('Y') ?></div>
      <div class="value" id="b-ytd" style="font-size:18px;">—</div>
      <span class="icon-bg">📊</span>
    </div>
  </div>
</div>

<!-- Payment List -->
<div class="sa-card">
  <div class="sa-card-header">
    <h3>Riwayat Pembayaran</h3>
  </div>

  <div class="sa-filter-bar">
    <select id="bMonth" onchange="loadBilling()">
      <option value="">Semua Bulan</option>
      <?php for ($i = 1; $i <= 12; $i++): ?>
      <option value="<?= $i ?>" <?= $i == date('n') ? 'selected' : '' ?>><?= date('M', mktime(0,0,0,$i,1)) ?> <?= date('Y') ?></option>
      <?php endfor; ?>
    </select>
    <select id="bType" onchange="loadBilling()">
      <option value="">Semua Tipe</option>
      <option value="setup_fee">Setup Fee</option>
      <option value="coin_topup">Coin Topup</option>
      <option value="subscription">Subscription</option>
    </select>
    <select id="bStatus" onchange="loadBilling()">
      <option value="">Semua Status</option>
      <option value="success">Success</option>
      <option value="pending">Pending</option>
      <option value="failed">Failed</option>
    </select>
  </div>

  <div class="sa-table-wrap">
    <table class="sa-table">
      <thead>
        <tr>
          <th>Tanggal</th>
          <th>Tenant</th>
          <th>Tipe</th>
          <th>Nominal</th>
          <th>Coin</th>
          <th>Gateway Ref</th>
          <th>Notes</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody id="billingBody">
        <tr><td colspan="8" style="text-align:center;padding:32px;color:rgba(255,255,255,.35);">Memuat...</td></tr>
      </tbody>
    </table>
  </div>
  <div class="sa-pagination" id="billingPagination"></div>
</div>

<?php saRenderNavClose(); ?>

<script>
const rupiah = n => 'Rp ' + parseFloat(n||0).toLocaleString('id-ID', {minimumFractionDigits:0});
let bPage = 1;

// Load stats
fetch('billing.php?action=stats', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
  .then(r => r.json()).then(d => {
    document.getElementById('b-sf-cur').textContent  = rupiah(d.bulan_ini.setup_fee);
    document.getElementById('b-ct-cur').textContent  = rupiah(d.bulan_ini.coin_topup);
    document.getElementById('b-tot-cur').textContent = rupiah(d.bulan_ini.grand_total);
    document.getElementById('b-cnt-cur').textContent = d.bulan_ini.count + ' transaksi';
    document.getElementById('b-tot-last').textContent = rupiah(d.bulan_lalu.grand_total);
    document.getElementById('b-ytd').textContent     = rupiah(d.ytd);
  });

function loadBilling() {
  const params = new URLSearchParams({
    action: 'list',
    month:  document.getElementById('bMonth').value,
    year:   new Date().getFullYear(),
    type:   document.getElementById('bType').value,
    status: document.getElementById('bStatus').value,
    page:   bPage,
  });

  fetch('billing.php?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json()).then(data => {
      renderBilling(data.rows);
      renderBillingPagination(data.page, data.pages, data.total);
    });
}

function esc(s) { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
function fmtDate(s) { return s ? new Date(s).toLocaleString('id-ID',{dateStyle:'short',timeStyle:'short'}) : '-'; }

function renderBilling(rows) {
  const tbody = document.getElementById('billingBody');
  if (!rows || !rows.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px;color:rgba(255,255,255,.35);">Tidak ada data.</td></tr>';
    return;
  }
  const typeColors = { setup_fee: 'sa-badge-blue', coin_topup: 'sa-badge-indigo', subscription: 'sa-badge-active' };
  tbody.innerHTML = rows.map(r => `<tr>
    <td style="font-size:12px;">${fmtDate(r.paid_at || r.created_at)}</td>
    <td>
      <a href="client_detail.php?id=${r.tenant_id}" style="color:var(--white);text-decoration:none;font-weight:600;">${esc(r.nama_outlet||'-')}</a>
      <br><small style="color:rgba(255,255,255,.35);font-size:11px;">${esc(r.owner_name||'')}</small>
    </td>
    <td><span class="sa-badge ${typeColors[r.type]||'sa-badge-indigo'}" style="font-size:10.5px;">${esc(r.type||'-')}</span></td>
    <td style="font-family:var(--mono);color:#6EE7B7;">${rupiah(r.amount)}</td>
    <td style="font-family:var(--mono);">${r.coin_amount ? parseInt(r.coin_amount).toLocaleString('id-ID') : '-'}</td>
    <td style="font-family:var(--mono);font-size:11px;color:rgba(255,255,255,.4);">${esc(r.gateway_ref||'-')}</td>
    <td style="font-size:12px;color:rgba(255,255,255,.45);">${esc(r.notes||'-')}</td>
    <td><span class="sa-badge ${r.status==='success'?'sa-badge-active':(r.status==='pending'?'sa-badge-yellow':'sa-badge-red')}">${esc(r.status)}</span></td>
  </tr>`).join('');
}

function renderBillingPagination(page, pages, total) {
  const el = document.getElementById('billingPagination');
  let html = `<span style="font-size:12px;color:rgba(255,255,255,.35);margin-right:10px;">Total: ${total}</span>`;
  html += `<button class="sa-btn sa-btn-sm sa-btn-outline ${page<=1?'disabled':''}" onclick="bGoto(${page-1})">‹ Prev</button>`;
  for (let i = Math.max(1,page-2); i <= Math.min(pages,page+2); i++) {
    html += `<button class="sa-btn sa-btn-sm ${i===page?'sa-btn-primary':'sa-btn-outline'}" onclick="bGoto(${i})">${i}</button>`;
  }
  html += `<button class="sa-btn sa-btn-sm sa-btn-outline ${page>=pages?'disabled':''}" onclick="bGoto(${page+1})">Next ›</button>`;
  el.innerHTML = html;
}

function bGoto(p) { bPage = p; loadBilling(); }

loadBilling();
</script>
</body>
</html>
