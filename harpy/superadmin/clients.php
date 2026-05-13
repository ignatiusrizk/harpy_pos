<?php
// ══════════════════════════════════════════════════════
// superadmin/clients.php — Client List & Management
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

    if ($action === 'list') {
        $q            = trim($_GET['q'] ?? '');
        $status       = $_GET['status'] ?? '';
        $coinFilter   = $_GET['coin_filter'] ?? '';
        $actFilter    = $_GET['activity_filter'] ?? '';
        $joinFilter   = $_GET['join_filter'] ?? '';
        $page         = max(1, (int)($_GET['page'] ?? 1));
        $sort         = in_array($_GET['sort'] ?? '', ['nama_outlet','coin_balance','created_at','last_login']) ? $_GET['sort'] : 't.created_at';
        $dir          = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $limit        = 20;
        $offset       = ($page - 1) * $limit;

        // Sort column mapping
        $sortMap = [
            'nama_outlet'   => 't.nama_outlet',
            'coin_balance'  => 't.coin_balance',
            'created_at'    => 't.created_at',
            'last_login'    => 'last_login',
        ];
        $sortCol = $sortMap[$sort] ?? 't.created_at';

        $where = ['1=1'];
        $params = [];

        if ($q) {
            $where[] = '(t.nama_outlet LIKE ? OR t.owner_name LIKE ? OR t.owner_wa LIKE ? OR t.slug LIKE ?)';
            $like = "%$q%";
            array_push($params, $like, $like, $like, $like);
        }

        if ($status) {
            $where[] = 't.status = ?';
            $params[] = $status;
        }

        if ($coinFilter === 'kritis') {
            $where[] = 't.coin_balance < 5000';
        } elseif ($coinFilter === 'rendah') {
            $where[] = 't.coin_balance < 10000';
        }

        if ($actFilter === 'aktif_7') {
            $where[] = 'MAX(u.last_login) > NOW() - INTERVAL 7 DAY';
        } elseif ($actFilter === 'tidak_aktif_14') {
            $where[] = '(MAX(u.last_login) < NOW() - INTERVAL 14 DAY OR MAX(u.last_login) IS NULL)';
        } elseif ($actFilter === 'belum_login') {
            $where[] = 'MAX(u.last_login) IS NULL';
        }

        if ($joinFilter === 'bulan_ini') {
            $where[] = 'MONTH(t.provisioned_at) = MONTH(NOW()) AND YEAR(t.provisioned_at) = YEAR(NOW())';
        } elseif ($joinFilter === 'bulan_lalu') {
            $where[] = 'MONTH(t.provisioned_at) = MONTH(NOW() - INTERVAL 1 MONTH) AND YEAR(t.provisioned_at) = YEAR(NOW() - INTERVAL 1 MONTH)';
        }

        $whereStr = implode(' AND ', $where);
        $havingFilters = ['aktif_7','tidak_aktif_14','belum_login'];
        $needHaving = in_array($actFilter, $havingFilters);
        $havingStr = '';
        if ($needHaving) {
            if ($actFilter === 'aktif_7') $havingStr = 'HAVING MAX(u.last_login) > NOW() - INTERVAL 7 DAY';
            elseif ($actFilter === 'tidak_aktif_14') $havingStr = 'HAVING (MAX(u.last_login) < NOW() - INTERVAL 14 DAY OR MAX(u.last_login) IS NULL)';
            elseif ($actFilter === 'belum_login') $havingStr = 'HAVING MAX(u.last_login) IS NULL';
        }

        // Remove activity from WHERE if it's a HAVING filter
        if ($needHaving) {
            $whereStr = implode(' AND ', array_filter($where, fn($w) => !str_contains($w, 'last_login')));
        }

        // Count
        $cntStmt = $db->prepare(
            "SELECT COUNT(*) FROM (
               SELECT t.id FROM tenants t
               LEFT JOIN hl_users u ON u.tenant_id = t.id
               WHERE $whereStr
               GROUP BY t.id
               $havingStr
             ) x"
        );
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        // Data
        $stmt = $db->prepare(
            "SELECT t.*, MAX(u.last_login) as last_login
             FROM tenants t
             LEFT JOIN hl_users u ON u.tenant_id = t.id
             WHERE $whereStr
             GROUP BY t.id
             $havingStr
             ORDER BY $sortCol $dir
             LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        echo json_encode([
            'rows'  => $rows,
            'total' => $total,
            'pages' => max(1, (int)ceil($total / $limit)),
            'page'  => $page,
        ]);
        exit;
    }

    if ($action === 'topup') {
        saVerifyCsrf();
        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        $amount   = (int)($_POST['amount'] ?? 0);
        $note     = trim($_POST['note'] ?? '');

        if ($tenantId <= 0 || $amount <= 0) {
            echo json_encode(['error' => 'Data tidak valid.']); exit;
        }

        try {
            $db->beginTransaction();

            // Get current balance
            $bal = (int)$db->prepare("SELECT coin_balance FROM tenants WHERE id=? FOR UPDATE")->execute([$tenantId]) ? 0 : 0;
            $row = $db->prepare("SELECT coin_balance FROM tenants WHERE id=?")->execute([$tenantId]) ? null : null;
            $stm = $db->prepare("SELECT coin_balance FROM tenants WHERE id=?");
            $stm->execute([$tenantId]);
            $bal = (int)$stm->fetchColumn();

            $newBal = $bal + $amount;

            $db->prepare("UPDATE tenants SET coin_balance = ? WHERE id = ?")->execute([$newBal, $tenantId]);

            $db->prepare(
                "INSERT INTO coin_ledger (tenant_id, type, amount, feature_used, description, balance_after)
                 VALUES (?, 'topup', ?, 'manual_topup', ?, ?)"
            )->execute([$tenantId, $amount, $note ?: 'Manual topup by super admin', $newBal]);

            $db->commit();

            logSuperAdminAction('topup_coin', $tenantId, "Topup $amount coin. Note: $note");
            echo json_encode(['success' => true, 'new_balance' => $newBal]);
        } catch (Throwable $e) {
            $db->rollBack();
            echo json_encode(['error' => 'Gagal topup: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'toggle_status') {
        saVerifyCsrf();
        $tenantId  = (int)($_POST['tenant_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';

        if (!in_array($newStatus, ['active','suspended','trial'])) {
            echo json_encode(['error' => 'Status tidak valid.']); exit;
        }

        $db->prepare("UPDATE tenants SET status = ? WHERE id = ?")->execute([$newStatus, $tenantId]);
        logSuperAdminAction('toggle_status', $tenantId, "Status diubah ke $newStatus");
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
<?php saRenderHead('Clients'); ?>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('clients', 'Client Management'); ?>

<div class="sa-page-header">
  <h1>Clients</h1>
  <p>Kelola seluruh tenant platform Harpy</p>
</div>

<!-- Filter Bar -->
<div class="sa-card" style="margin-bottom:20px;">
  <div class="sa-filter-bar">
    <input type="text" id="fSearch" placeholder="Cari nama outlet, owner, WA..." style="flex:1;min-width:200px;" oninput="debounceLoad()"/>
    <select id="fStatus" onchange="loadClients()">
      <option value="">Semua Status</option>
      <option value="active">Aktif</option>
      <option value="trial">Trial</option>
      <option value="suspended">Suspended</option>
    </select>
    <select id="fCoin" onchange="loadClients()">
      <option value="">Semua Coin</option>
      <option value="rendah">Coin Rendah (&lt;10K)</option>
      <option value="kritis">Coin Kritis (&lt;5K)</option>
    </select>
    <select id="fActivity" onchange="loadClients()">
      <option value="">Semua Aktivitas</option>
      <option value="aktif_7">Aktif 7 hari</option>
      <option value="tidak_aktif_14">Tidak aktif 14 hari</option>
      <option value="belum_login">Belum pernah login</option>
    </select>
    <select id="fJoin" onchange="loadClients()">
      <option value="">Semua Waktu Daftar</option>
      <option value="bulan_ini">Bulan ini</option>
      <option value="bulan_lalu">Bulan lalu</option>
    </select>
  </div>

  <div class="sa-table-wrap">
    <table class="sa-table">
      <thead>
        <tr>
          <th><a href="#" onclick="sortBy('nama_outlet');return false;" style="color:inherit;text-decoration:none;">Nama Outlet ↕</a></th>
          <th>Owner</th>
          <th>WA</th>
          <th><a href="#" onclick="sortBy('status');return false;" style="color:inherit;text-decoration:none;">Status ↕</a></th>
          <th><a href="#" onclick="sortBy('coin_balance');return false;" style="color:inherit;text-decoration:none;">Coin ↕</a></th>
          <th><a href="#" onclick="sortBy('last_login');return false;" style="color:inherit;text-decoration:none;">Last Login ↕</a></th>
          <th><a href="#" onclick="sortBy('created_at');return false;" style="color:inherit;text-decoration:none;">Sejak ↕</a></th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody id="clientsBody">
        <tr><td colspan="8" style="text-align:center;color:rgba(255,255,255,.35);padding:32px;">Memuat data...</td></tr>
      </tbody>
    </table>
  </div>
  <div class="sa-pagination" id="paginationWrap"></div>
</div>

<!-- Topup Modal -->
<div class="sa-modal-overlay" id="topupModal">
  <div class="sa-modal">
    <h3>🪙 Topup Coin</h3>
    <input type="hidden" id="topupTenantId"/>
    <div class="form-group">
      <label>Tenant</label>
      <input type="text" id="topupTenantName" readonly style="opacity:.6;"/>
    </div>
    <div class="form-group">
      <label>Jumlah Coin</label>
      <input type="number" id="topupAmount" placeholder="Contoh: 50000" min="1" required/>
    </div>
    <div class="form-group">
      <label>Keterangan</label>
      <input type="text" id="topupNote" placeholder="Alasan topup..."/>
    </div>
    <div class="sa-modal-footer">
      <button class="sa-btn sa-btn-outline" onclick="closeModal('topupModal')">Batal</button>
      <button class="sa-btn sa-btn-primary" onclick="submitTopup()">Topup Sekarang</button>
    </div>
  </div>
</div>

<?php saRenderNavClose(); ?>

<script>
let currentPage = 1;
let currentSort = 'created_at';
let currentDir  = 'DESC';
let debTimer    = null;

function debounceLoad() {
  clearTimeout(debTimer);
  debTimer = setTimeout(() => { currentPage = 1; loadClients(); }, 380);
}

function sortBy(col) {
  if (currentSort === col) currentDir = currentDir === 'ASC' ? 'DESC' : 'ASC';
  else { currentSort = col; currentDir = 'DESC'; }
  loadClients();
}

function loadClients() {
  const params = new URLSearchParams({
    action:          'list',
    q:               document.getElementById('fSearch').value,
    status:          document.getElementById('fStatus').value,
    coin_filter:     document.getElementById('fCoin').value,
    activity_filter: document.getElementById('fActivity').value,
    join_filter:     document.getElementById('fJoin').value,
    page:            currentPage,
    sort:            currentSort,
    dir:             currentDir,
  });

  fetch('clients.php?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json()).then(data => {
      renderRows(data.rows);
      renderPagination(data.page, data.pages, data.total);
    }).catch(() => {
      document.getElementById('clientsBody').innerHTML =
        '<tr><td colspan="8" style="text-align:center;color:#FCA5A5;padding:24px;">Gagal memuat data.</td></tr>';
    });
}

function relTime(ts) {
  if (!ts) return '<span style="color:rgba(255,255,255,.3);">Belum pernah</span>';
  const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
  if (diff < 3600)  return Math.floor(diff/60) + ' mnt lalu';
  if (diff < 86400) return Math.floor(diff/3600) + ' jam lalu';
  return Math.floor(diff/86400) + ' hari lalu';
}

function coinHtml(bal) {
  const n = parseInt(bal);
  const fmt = n.toLocaleString('id-ID');
  if (n < 5000)  return `<span class="coin-kritis">${fmt}</span>`;
  if (n < 10000) return `<span class="coin-rendah">${fmt}</span>`;
  return `<span class="coin-ok">${fmt}</span>`;
}

function statusBadge(s) {
  const map = { active: 'active', trial: 'trial', suspended: 'suspended' };
  const lbl = { active: 'Aktif', trial: 'Trial', suspended: 'Suspended' };
  return `<span class="sa-badge sa-badge-${map[s]||'indigo'}">${lbl[s]||s}</span>`;
}

function esc(s) { const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

function renderRows(rows) {
  const tbody = document.getElementById('clientsBody');
  if (!rows || !rows.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:rgba(255,255,255,.35);padding:32px;">Tidak ada data.</td></tr>';
    return;
  }
  tbody.innerHTML = rows.map(t => {
    const statusOpp = t.status === 'suspended' ? 'active' : 'suspended';
    const statusBtnLabel = t.status === 'suspended' ? 'Aktifkan' : 'Suspend';
    const statusBtnClass = t.status === 'suspended' ? 'sa-btn-green' : 'sa-btn-danger';
    return `<tr>
      <td><strong>${esc(t.nama_outlet)}</strong><br><small style="color:rgba(255,255,255,.35);font-family:var(--mono);font-size:10px;">${esc(t.slug)}</small></td>
      <td>${esc(t.owner_name)}</td>
      <td><a href="https://wa.me/${esc(t.owner_wa)}" target="_blank" style="color:#86efac;text-decoration:none;font-family:var(--mono);font-size:12px;">${esc(t.owner_wa)}</a></td>
      <td>${statusBadge(t.status)}</td>
      <td>${coinHtml(t.coin_balance)}</td>
      <td style="font-size:12px;">${relTime(t.last_login)}</td>
      <td style="font-size:12px;color:rgba(255,255,255,.4);">${t.provisioned_at ? new Date(t.provisioned_at).toLocaleDateString('id-ID') : '-'}</td>
      <td>
        <a href="client_detail.php?id=${t.id}" class="sa-btn sa-btn-sm sa-btn-outline">Detail</a>
        <a href="https://wa.me/${esc(t.owner_wa)}" target="_blank" class="sa-btn sa-btn-sm sa-btn-wa" style="margin-left:3px;">WA</a>
        <button class="sa-btn sa-btn-sm sa-btn-primary" style="margin-left:3px;" onclick="openTopup(${t.id}, '${esc(t.nama_outlet)}')">Topup</button>
        <button class="sa-btn sa-btn-sm ${statusBtnClass}" style="margin-left:3px;" onclick="toggleStatus(${t.id}, '${statusOpp}', '${esc(t.nama_outlet)}')">${statusBtnLabel}</button>
      </td>
    </tr>`;
  }).join('');
}

function renderPagination(page, pages, total) {
  const el = document.getElementById('paginationWrap');
  let html = `<span style="font-size:12px;color:rgba(255,255,255,.35);margin-right:10px;">Total: ${total}</span>`;
  html += `<button class="sa-btn sa-btn-sm sa-btn-outline ${page<=1?'disabled':''}" onclick="gotoPage(${page-1})">‹ Prev</button>`;
  for (let i = Math.max(1, page-2); i <= Math.min(pages, page+2); i++) {
    html += `<button class="sa-btn sa-btn-sm ${i===page?'sa-btn-primary':'sa-btn-outline'}" onclick="gotoPage(${i})">${i}</button>`;
  }
  html += `<button class="sa-btn sa-btn-sm sa-btn-outline ${page>=pages?'disabled':''}" onclick="gotoPage(${page+1})">Next ›</button>`;
  el.innerHTML = html;
}

function gotoPage(p) { currentPage = p; loadClients(); }

function openTopup(id, nama) {
  document.getElementById('topupTenantId').value = id;
  document.getElementById('topupTenantName').value = nama;
  document.getElementById('topupAmount').value = '';
  document.getElementById('topupNote').value = '';
  document.getElementById('topupModal').classList.add('open');
}

function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

function submitTopup() {
  const id     = document.getElementById('topupTenantId').value;
  const amount = document.getElementById('topupAmount').value;
  const note   = document.getElementById('topupNote').value;

  if (!amount || amount < 1) { saShowToast('Jumlah coin harus > 0', 'error'); return; }

  saPost('clients.php?action=topup', { tenant_id: id, amount, note })
    .then(r => r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      saShowToast('Topup berhasil! Saldo baru: ' + parseInt(d.new_balance).toLocaleString('id-ID'), 'success');
      closeModal('topupModal');
      loadClients();
    });
}

function toggleStatus(id, newStatus, nama) {
  const label = newStatus === 'suspended' ? 'suspend' : 'aktifkan';
  if (!confirm(`Yakin ${label} ${nama}?`)) return;

  saPost('clients.php?action=toggle_status', { tenant_id: id, new_status: newStatus })
    .then(r => r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      saShowToast('Status berhasil diubah.', 'success');
      loadClients();
    });
}

// Initial load
loadClients();
</script>
</body>
</html>
