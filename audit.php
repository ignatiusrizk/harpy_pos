<?php
$activePage = 'audit';
require_once 'auth.php';
require_once 'components.php';
requireLogin();
$user = currentUser();
if (isset($_GET['logout'])) doLogout();
requirePermission('audit.view');

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $pdo = getDB();

    // Auto-create tabel jika belum ada
    $pdo->exec("CREATE TABLE IF NOT EXISTS hl_audit_log (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT DEFAULT NULL,
        user_nama   VARCHAR(100) DEFAULT NULL,
        user_role   VARCHAR(50) DEFAULT NULL,
        modul       VARCHAR(50) NOT NULL,
        aksi        VARCHAR(100) NOT NULL,
        keterangan  TEXT,
        ref_id      VARCHAR(100) DEFAULT NULL,
        ip_address  VARCHAR(45) DEFAULT NULL,
        user_agent  VARCHAR(255) DEFAULT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_modul (modul),
        INDEX idx_user (user_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($action === 'list') {
        $q      = $_GET['q']      ?? '';
        $modul  = $_GET['modul']  ?? '';
        $userId = $_GET['user_id']?? '';
        $dari   = $_GET['dari']   ?? '';
        $sampai = $_GET['sampai'] ?? '';
        $page   = max(1, intval($_GET['page'] ?? 1));
        $limit  = 50;
        $offset = ($page - 1) * $limit;

        $where = ['1=1']; $params = [];
        if ($q)      { $where[] = '(aksi LIKE ? OR keterangan LIKE ? OR user_nama LIKE ?)'; $like="%$q%"; $params=array_merge($params,[$like,$like,$like]); }
        if ($modul)  { $where[] = 'modul=?';   $params[] = $modul; }
        if ($userId) { $where[] = 'user_id=?'; $params[] = $userId; }
        if ($dari)   { $where[] = 'DATE(created_at)>=?'; $params[] = $dari; }
        if ($sampai) { $where[] = 'DATE(created_at)<=?'; $params[] = $sampai; }

        $whereStr = implode(' AND ', $where);
        $stmt = $pdo->prepare("SELECT * FROM hl_audit_log WHERE $whereStr ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM hl_audit_log WHERE $whereStr");
        $cnt->execute($params);
        $total = intval($cnt->fetchColumn());

        echo json_encode(['data'=>$rows,'total'=>$total,'page'=>$page,'total_pages'=>ceil($total/$limit)]);
        exit;
    }

    if ($action === 'stats') {
        $today = date('Y-m-d');
        $total  = $pdo->query("SELECT COUNT(*) FROM hl_audit_log")->fetchColumn();
        $hari   = $pdo->query("SELECT COUNT(*) FROM hl_audit_log WHERE DATE(created_at)='$today'")->fetchColumn();
        $users  = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM hl_audit_log WHERE DATE(created_at)='$today'")->fetchColumn();
        $moduls = $pdo->query("SELECT modul, COUNT(*) as c FROM hl_audit_log GROUP BY modul ORDER BY c DESC")->fetchAll();
        echo json_encode(['total'=>$total,'hari'=>$hari,'users'=>$users,'moduls'=>$moduls]); exit;
    }

    if ($action === 'users') {
        $rows = $pdo->query("SELECT DISTINCT user_id, user_nama FROM hl_audit_log WHERE user_id IS NOT NULL ORDER BY user_nama")->fetchAll();
        echo json_encode($rows); exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Audit Log'); ?>
<style>
.aksi-badge{display:inline-block;font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px;white-space:nowrap;letter-spacing:.04em}
.aksi-create{background:#D1FAE5;color:#065F46}
.aksi-update{background:#DBEAFE;color:#1D4ED8}
.aksi-update_status{background:#EDE9FE;color:#5B21B6}
.aksi-delete{background:#FEE2E2;color:#991B1B}
.aksi-payment{background:#FEF3C7;color:#92400E}
.aksi-login{background:#D1FAE5;color:#065F46}
.aksi-logout{background:#F3F4F6;color:#374151}
.aksi-generate_gaji{background:#FEF3C7;color:#92400E}
.aksi-bayar_gaji{background:#FEE2E2;color:#991B1B}
.aksi-update_permission{background:#EDE9FE;color:#5B21B6}
.aksi-default{background:var(--light);color:var(--gray)}
.modul-badge{display:inline-block;font-size:10px;font-weight:600;padding:2px 7px;border-radius:6px;background:var(--light);color:var(--gray)}
.log-time{font-family:var(--mono);font-size:11px;color:var(--gray);white-space:nowrap}
.log-ket{font-size:12px;color:var(--gray);max-width:300px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
</style>
</head>
<body>
<?php renderTopbar('audit'); ?>
<div class="hl-main">

  <!-- STATS -->
  <div class="hl-stat-grid-4" style="margin-bottom:20px">
    <div class="hl-stat-card teal"><div class="hl-stat-num" id="sTotal">-</div><div class="hl-stat-label">Total Log</div></div>
    <div class="hl-stat-card green"><div class="hl-stat-num" id="sHari">-</div><div class="hl-stat-label">Aktivitas Hari Ini</div></div>
    <div class="hl-stat-card navy"><div class="hl-stat-num" id="sUsers">-</div><div class="hl-stat-label">User Aktif Hari Ini</div></div>
    <div class="hl-stat-card purple">
      <div class="hl-stat-num" id="sTopModul" style="font-size:1rem">-</div>
      <div class="hl-stat-label">Modul Tersibuk</div>
    </div>
  </div>

  <!-- FILTER -->
  <div class="hl-filter-bar">
    <input type="text" id="fSearch" class="hl-input" placeholder="🔍 Cari aksi, keterangan, user..."
      oninput="debounce()" style="flex:1;max-width:280px"/>
    <select id="fModul" class="hl-input" style="width:auto" onchange="loadLog(1)">
      <option value="">Semua Modul</option>
      <option value="orders">Orders</option>
      <option value="kas">Kas</option>
      <option value="customer">Customer</option>
      <option value="karyawan">Karyawan</option>
      <option value="layanan">Layanan</option>
      <option value="settings">Settings</option>
      <option value="auth">Auth (Login)</option>
    </select>
    <select id="fUser" class="hl-input" style="width:auto" onchange="loadLog(1)">
      <option value="">Semua User</option>
    </select>
    <input type="date" id="fDari" class="hl-input" style="width:auto" onchange="loadLog(1)"/>
    <input type="date" id="fSampai" class="hl-input" style="width:auto" onchange="loadLog(1)"/>
    <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="resetFilter()">✕</button>
    <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadLog(1)">↻</button>
  </div>

  <!-- TABLE -->
  <div class="hl-card">
    <div class="hl-card-header">
      <div class="hl-card-title">📋 Riwayat Aktivitas</div>
      <span id="logInfo" style="font-size:12px;color:var(--gray)"></span>
    </div>
    <div class="hl-table-wrap">
      <table class="hl-table">
        <thead>
          <tr>
            <th>Waktu</th>
            <th>User</th>
            <th>Role</th>
            <th>Modul</th>
            <th>Aksi</th>
            <th>Keterangan</th>
            <th>Ref</th>
            <th>IP</th>
          </tr>
        </thead>
        <tbody id="logBody">
          <tr><td colspan="8" class="hl-loading">⏳ Memuat...</td></tr>
        </tbody>
      </table>
    </div>
    <div id="logPaging" style="padding:12px 16px;border-top:1px solid var(--light)"></div>
  </div>

</div>
<?php renderToast(); ?>
<script>
let searchTimer = null;
let currentPage = 1;

document.addEventListener('DOMContentLoaded', () => {
  const today = localDateStr();
  document.getElementById('fDari').value   = today;
  document.getElementById('fSampai').value = today;
  loadStats();
  loadUsers();
  loadLog(1);
});

function localDateStr(d) {
  const dt = d || new Date();
  return dt.getFullYear() + '-' + String(dt.getMonth()+1).padStart(2,'0') + '-' + String(dt.getDate()).padStart(2,'0');
}

async function loadStats() {
  const r = await fetch('audit.php?action=stats');
  const d = await r.json();
  document.getElementById('sTotal').textContent    = parseInt(d.total).toLocaleString('id-ID');
  document.getElementById('sHari').textContent     = d.hari;
  document.getElementById('sUsers').textContent    = d.users;
  document.getElementById('sTopModul').textContent = d.moduls[0]?.modul || '-';
}

async function loadUsers() {
  const r = await fetch('audit.php?action=users');
  const d = await r.json();
  const sel = document.getElementById('fUser');
  sel.innerHTML = '<option value="">Semua User</option>' +
    d.map(u => `<option value="${u.user_id}">${esc(u.user_nama)}</option>`).join('');
}

async function loadLog(page=1) {
  currentPage = page;
  const q      = document.getElementById('fSearch').value;
  const modul  = document.getElementById('fModul').value;
  const userId = document.getElementById('fUser').value;
  const dari   = document.getElementById('fDari').value;
  const sampai = document.getElementById('fSampai').value;

  document.getElementById('logBody').innerHTML = '<tr><td colspan="8" class="hl-loading">⏳ Memuat...</td></tr>';

  const r = await fetch(`audit.php?action=list&q=${encodeURIComponent(q)}&modul=${modul}&user_id=${userId}&dari=${dari}&sampai=${sampai}&page=${page}`);
  const d = await r.json();

  if (!d.data?.length) {
    document.getElementById('logBody').innerHTML = '<tr><td colspan="8" class="hl-empty">📭 Tidak ada log.</td></tr>';
    document.getElementById('logPaging').innerHTML = '';
    document.getElementById('logInfo').textContent = '';
    return;
  }

  const aksiColor = {
    create:'aksi-create', update:'aksi-update', update_status:'aksi-update_status',
    delete:'aksi-delete', payment:'aksi-payment', login:'aksi-login',
    logout:'aksi-logout', generate_gaji:'aksi-generate_gaji', bayar_gaji:'aksi-bayar_gaji',
    update_permission:'aksi-update_permission', edit_gaji:'aksi-update',
  };

  document.getElementById('logBody').innerHTML = d.data.map(row => `
    <tr>
      <td class="log-time">${fmtDateTime(row.created_at)}</td>
      <td style="font-weight:600;font-size:13px;color:var(--navy)">${esc(row.user_nama||'-')}</td>
      <td><span class="modul-badge">${esc(row.user_role||'-')}</span></td>
      <td><span class="modul-badge" style="background:var(--teal-bg);color:var(--teal-d)">${esc(row.modul)}</span></td>
      <td><span class="aksi-badge ${aksiColor[row.aksi]||'aksi-default'}">${esc(row.aksi)}</span></td>
      <td class="log-ket" title="${esc(row.keterangan||'')}">${esc(row.keterangan||'-')}</td>
      <td style="font-family:var(--mono);font-size:11px;color:var(--teal-d)">${esc(row.ref_id||'-')}</td>
      <td style="font-size:11px;color:var(--gray)">${esc(row.ip_address||'-')}</td>
    </tr>`).join('');

  document.getElementById('logInfo').textContent = `${d.total.toLocaleString('id-ID')} aktivitas · hal ${page}/${d.total_pages}`;
  renderPaging(page, d.total_pages);
}

function renderPaging(page, total) {
  const el = document.getElementById('logPaging');
  if (total <= 1) { el.innerHTML=''; return; }
  let html = '<div style="display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap">';
  html += `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadLog(${page-1})" ${page===1?'disabled':''}>← Prev</button>`;
  const start=Math.max(1,page-2), end=Math.min(total,page+2);
  if(start>1) html+=`<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadLog(1)">1</button>`;
  if(start>2) html+=`<span style="color:var(--gray)">...</span>`;
  for(let i=start;i<=end;i++) html+=`<button class="hl-btn ${i===page?'hl-btn-primary':'hl-btn-outline'} hl-btn-sm" onclick="loadLog(${i})">${i}</button>`;
  if(end<total-1) html+=`<span style="color:var(--gray)">...</span>`;
  if(end<total) html+=`<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadLog(${total})">${total}</button>`;
  html += `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadLog(${page+1})" ${page===total?'disabled':''}>Next →</button>`;
  html += '</div>';
  el.innerHTML = html;
}

function resetFilter() {
  document.getElementById('fSearch').value  = '';
  document.getElementById('fModul').value   = '';
  document.getElementById('fUser').value    = '';
  document.getElementById('fDari').value    = localDateStr();
  document.getElementById('fSampai').value  = localDateStr();
  loadLog(1);
}

function debounce(){ clearTimeout(searchTimer); searchTimer=setTimeout(()=>loadLog(1),400); }
function fmtDateTime(d){if(!d)return'-';const dt=new Date(d);return dt.toLocaleDateString('id-ID',{day:'2-digit',month:'short'})+' '+dt.toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit',second:'2-digit'});}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function showToast(msg,type='success'){const t=document.getElementById('toast');t.textContent=msg;t.className='hl-toast '+type+' show';setTimeout(()=>t.className='hl-toast',3500)}
</script>
</body>
</html>