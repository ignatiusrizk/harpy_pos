<?php
// ══════════════════════════════════════════════════════
// superadmin/registrations.php — Registration Inbox
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

    // LIST
    if ($action === 'list') {
        $status = $_GET['status'] ?? '';
        $q      = trim($_GET['q'] ?? '');
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $where = ['1=1']; $params = [];
        if ($status) { $where[] = 'r.status=?'; $params[] = $status; }
        if ($q) {
            $where[] = '(r.nama_outlet LIKE ? OR r.owner_name LIKE ? OR r.owner_wa LIKE ?)';
            $like = "%$q%"; array_push($params, $like, $like, $like);
        }
        $whereStr = implode(' AND ', $where);

        $rows = $db->prepare(
            "SELECT r.*, t.slug as tenant_slug
             FROM registration_requests r
             LEFT JOIN tenants t ON t.id=r.tenant_id
             WHERE $whereStr
             ORDER BY r.created_at DESC
             LIMIT $limit OFFSET $offset"
        );
        $rows->execute($params);
        $rows = $rows->fetchAll();

        $cnt = $db->prepare("SELECT COUNT(*) FROM registration_requests r WHERE $whereStr");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        echo json_encode([
            'data'        => $rows,
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => ceil($total / $limit),
        ]);
        exit;
    }

    // CREATE new registration request
    if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        saVerifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $nama_outlet = substr(trim(strip_tags($d['nama_outlet'] ?? '')), 0, 100);
        $owner_name  = substr(trim(strip_tags($d['owner_name'] ?? '')), 0, 100);
        $owner_wa    = substr(trim(preg_replace('/[^0-9+\-\s]/', '', $d['owner_wa'] ?? '')), 0, 20);
        $kota        = substr(trim(strip_tags($d['kota'] ?? '')), 0, 100);
        $source      = in_array($d['source'] ?? '', ['self_service','assisted']) ? $d['source'] : 'assisted';
        $notes       = substr(trim(strip_tags($d['notes'] ?? '')), 0, 500);

        if (!$nama_outlet || !$owner_name || !$owner_wa) {
            echo json_encode(['error' => 'Nama outlet, owner, dan WA wajib diisi']); exit;
        }

        $db->prepare(
            "INSERT INTO registration_requests (source, nama_outlet, owner_name, owner_wa, kota, status, notes, handled_by)
             VALUES (?,?,?,?,?,'pending',?,?)"
        )->execute([$source, $nama_outlet, $owner_name, $owner_wa, $kota, $notes, $_SESSION['superadmin_id']]);
        $id = (int)$db->lastInsertId();

        logSuperAdminAction('create_registration', null, "Buat registrasi baru: $nama_outlet | $owner_name | $owner_wa");
        echo json_encode(['success' => true, 'id' => $id]);
        exit;
    }

    // CANCEL
    if ($action === 'cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        saVerifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE registration_requests SET status='cancelled', updated_at=NOW() WHERE id=?")
           ->execute([$id]);
        logSuperAdminAction('cancel_registration', null, "Cancel registrasi ID: $id");
        echo json_encode(['success' => true]);
        exit;
    }

    // STATUS COUNTS
    if ($action === 'counts') {
        $counts = [];
        $statuses = ['pending', 'payment_pending', 'provisioning', 'completed', 'failed', 'cancelled'];
        foreach ($statuses as $s) {
            $r = $db->prepare("SELECT COUNT(*) FROM registration_requests WHERE status=?");
            $r->execute([$s]);
            $counts[$s] = (int)$r->fetchColumn();
        }
        $counts['all'] = array_sum(array_values($counts));
        echo json_encode($counts);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Registrasi'); ?>
<style>
.reg-filter-tabs {
  display: flex; gap: 0; border-bottom: 1px solid rgba(255,255,255,.07);
  margin-bottom: 0; overflow-x: auto;
}
.reg-tab {
  padding: 12px 18px; font-size: 13px; font-weight: 600;
  color: rgba(255,255,255,.4); background: none; border: none;
  cursor: pointer; white-space: nowrap;
  border-bottom: 2px solid transparent; margin-bottom: -1px;
  transition: all .15s; display: flex; align-items: center; gap: 6px;
}
.reg-tab:hover { color: rgba(255,255,255,.8); }
.reg-tab.active { color: var(--sa); border-bottom-color: var(--sa); }
.reg-tab .badge {
  background: rgba(255,255,255,.1); border-radius: 20px;
  padding: 1px 7px; font-size: 10.5px; font-family: var(--mono);
}
.reg-tab.active .badge { background: var(--sa-l); color: var(--sa); }

.status-badge {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600;
}
.s-pending         { background: rgba(245,158,11,.15); color: #FCD34D; border: 1px solid rgba(245,158,11,.25); }
.s-payment_pending { background: rgba(99,102,241,.15); color: #A5B4FC; border: 1px solid rgba(99,102,241,.25); }
.s-provisioning    { background: rgba(59,130,246,.15); color: #93C5FD; border: 1px solid rgba(59,130,246,.25); }
.s-completed       { background: rgba(16,185,129,.15); color: #6EE7B7; border: 1px solid rgba(16,185,129,.25); }
.s-failed          { background: rgba(239,68,68,.15);  color: #FCA5A5; border: 1px solid rgba(239,68,68,.25); }
.s-cancelled       { background: rgba(107,114,128,.15); color: #D1D5DB; border: 1px solid rgba(107,114,128,.25); }

.src-badge {
  display: inline-block; padding: 2px 8px; border-radius: 20px;
  font-size: 10px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase;
}
.src-assisted    { background: rgba(99,102,241,.15); color: #A5B4FC; }
.src-self_service { background: rgba(16,185,129,.15); color: #6EE7B7; }

.wa-link {
  color: #86efac; font-size: 12px; text-decoration: none;
  display: inline-flex; align-items: center; gap: 4px;
}
.wa-link:hover { color: #fff; }

/* CREATE MODAL form rows */
.form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
.form-row-1 { margin-bottom: 14px; }
@media (max-width:600px) { .form-row-2 { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<?php saRenderNav('registrations', 'Registrasi Klien'); ?>

<div class="sa-page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div>
    <h1>Registrasi Klien</h1>
    <p>Kelola permintaan pendaftaran tenant baru</p>
  </div>
  <button class="sa-btn sa-btn-primary" onclick="openCreate()">
    <span>+</span> Daftarkan Manual
  </button>
</div>

<!-- Filter bar -->
<div class="sa-card" style="margin-bottom:0;">
  <div style="padding:14px 20px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <input type="text" id="searchInput" placeholder="Cari nama outlet, owner, WA..."
           style="flex:1;min-width:200px;padding:8px 12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:var(--r);color:var(--white);font-family:var(--font);font-size:13px;outline:none;"
           onkeyup="debounceLoad()" />
    <span id="totalLabel" style="font-size:12px;color:rgba(255,255,255,.35);white-space:nowrap;"></span>
  </div>
  <div class="reg-filter-tabs">
    <button class="reg-tab active" data-status="" onclick="setTab(this,'')">
      Semua <span class="badge" id="cnt-all">0</span>
    </button>
    <button class="reg-tab" data-status="pending" onclick="setTab(this,'pending')">
      Pending <span class="badge" id="cnt-pending">0</span>
    </button>
    <button class="reg-tab" data-status="payment_pending" onclick="setTab(this,'payment_pending')">
      Menunggu Bayar <span class="badge" id="cnt-payment_pending">0</span>
    </button>
    <button class="reg-tab" data-status="provisioning" onclick="setTab(this,'provisioning')">
      Provisioning <span class="badge" id="cnt-provisioning">0</span>
    </button>
    <button class="reg-tab" data-status="completed" onclick="setTab(this,'completed')">
      Selesai <span class="badge" id="cnt-completed">0</span>
    </button>
    <button class="reg-tab" data-status="cancelled" onclick="setTab(this,'cancelled')">
      Dibatalkan <span class="badge" id="cnt-cancelled">0</span>
    </button>
  </div>

  <div class="sa-table-wrap">
    <table class="sa-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Nama Outlet</th>
          <th>Owner</th>
          <th>WA</th>
          <th>Source</th>
          <th>Status</th>
          <th>Tanggal</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody id="tableBody">
        <tr><td colspan="8" style="text-align:center;padding:40px;color:rgba(255,255,255,.3)">Memuat...</td></tr>
      </tbody>
    </table>
  </div>
  <div id="paginationWrap" class="sa-pagination"></div>
</div>

<!-- CREATE MODAL -->
<div class="sa-modal-overlay" id="createModal">
  <div class="sa-modal" style="max-width:560px;">
    <h3>Daftarkan Klien Manual</h3>

    <div class="form-row-2">
      <div class="form-group">
        <label>Nama Outlet <span style="color:var(--red)">*</span></label>
        <input type="text" id="c_nama_outlet" placeholder="Harpy Laundry Baru" />
      </div>
      <div class="form-group">
        <label>Nama Perusahaan / Brand</label>
        <input type="text" id="c_nama_perusahaan" placeholder="Optional" />
      </div>
    </div>
    <div class="form-row-2">
      <div class="form-group">
        <label>Nama Owner <span style="color:var(--red)">*</span></label>
        <input type="text" id="c_owner_name" placeholder="Budi Santoso" />
      </div>
      <div class="form-group">
        <label>No WA Owner <span style="color:var(--red)">*</span></label>
        <input type="text" id="c_owner_wa" placeholder="081234567890" />
      </div>
    </div>
    <div class="form-row-2">
      <div class="form-group">
        <label>Kota</label>
        <input type="text" id="c_kota" placeholder="Semarang" />
      </div>
      <div class="form-group">
        <label>Sumber Registrasi</label>
        <select id="c_source">
          <option value="assisted">Assisted (oleh CS)</option>
          <option value="self_service">Self Service</option>
        </select>
      </div>
    </div>
    <div class="form-row-1">
      <div class="form-group">
        <label>Catatan Internal</label>
        <textarea id="c_notes" rows="2" placeholder="Catatan opsional..."></textarea>
      </div>
    </div>

    <p style="font-size:12px;color:rgba(255,255,255,.35);margin-bottom:16px;">
      Setelah disimpan, klik "Proses" untuk melanjutkan ke wizard provisioning.
    </p>

    <div class="sa-modal-footer">
      <button class="sa-btn sa-btn-outline" onclick="closeCreate()">Batal</button>
      <button class="sa-btn sa-btn-primary" onclick="submitCreate()" id="createBtn">Simpan Registrasi</button>
    </div>
  </div>
</div>

<div class="sa-toast" id="toast"></div>

</div></div><!-- close sa-main + sa-content -->

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
let currentStatus = '', currentPage = 1, debTimer;

function debounceLoad() {
  clearTimeout(debTimer);
  debTimer = setTimeout(() => { currentPage=1; loadList(); }, 350);
}

function setTab(el, status) {
  document.querySelectorAll('.reg-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  currentStatus = status;
  currentPage = 1;
  loadList();
}

async function loadList() {
  const q   = document.getElementById('searchInput').value;
  const res = await fetch(`?action=list&status=${encodeURIComponent(currentStatus)}&q=${encodeURIComponent(q)}&page=${currentPage}`);
  const j   = await res.json();
  renderTable(j);
  document.getElementById('totalLabel').textContent = j.total + ' registrasi';
}

async function loadCounts() {
  const res = await fetch('?action=counts');
  const j   = await res.json();
  Object.keys(j).forEach(k => {
    const el = document.getElementById('cnt-' + k);
    if (el) el.textContent = j[k];
  });
}

function renderTable(j) {
  const tb = document.getElementById('tableBody');
  if (!j.data || !j.data.length) {
    tb.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:40px;color:rgba(255,255,255,.3)">Tidak ada data</td></tr>';
    document.getElementById('paginationWrap').innerHTML = '';
    return;
  }

  const statusLabels = {
    pending: 'Pending', payment_pending: 'Menunggu Bayar',
    provisioning: 'Provisioning', completed: 'Selesai',
    failed: 'Gagal', cancelled: 'Dibatalkan'
  };

  tb.innerHTML = j.data.map(r => `
    <tr>
      <td style="font-family:var(--mono);font-size:12px;color:rgba(255,255,255,.35)">#${r.id}</td>
      <td>
        <div style="font-weight:700;color:var(--white)">${esc(r.nama_outlet)}</div>
        ${r.nama_perusahaan ? `<div style="font-size:11px;color:rgba(255,255,255,.35)">${esc(r.nama_perusahaan)}</div>` : ''}
        ${r.kota ? `<div style="font-size:11px;color:rgba(255,255,255,.3)">${esc(r.kota)}</div>` : ''}
      </td>
      <td>${esc(r.owner_name)}</td>
      <td>
        <a href="https://wa.me/${r.owner_wa.replace(/[^0-9]/g,'')}" target="_blank" class="wa-link">
          <span>&#x1F4AC;</span> ${esc(r.owner_wa)}
        </a>
      </td>
      <td><span class="src-badge src-${r.source}">${r.source === 'assisted' ? 'Assisted' : 'Self'}</span></td>
      <td><span class="status-badge s-${r.status}">${statusLabels[r.status] || r.status}</span></td>
      <td style="font-size:12px;color:rgba(255,255,255,.4)">${fmtDate(r.created_at)}</td>
      <td>
        ${r.status !== 'completed' && r.status !== 'cancelled' ? `
          <a href="registration_wizard.php?id=${r.id}" class="sa-btn sa-btn-primary sa-btn-sm">Proses</a>
        ` : ''}
        ${r.status === 'completed' && r.tenant_id ? `
          <a href="client_detail.php?id=${r.tenant_id}" class="sa-btn sa-btn-outline sa-btn-sm">Detail</a>
        ` : ''}
        ${r.status === 'pending' ? `
          <button class="sa-btn sa-btn-danger sa-btn-sm" onclick="cancelReg(${r.id})">Batal</button>
        ` : ''}
      </td>
    </tr>
  `).join('');

  // Pagination
  renderPagination(j);
}

function renderPagination(j) {
  const wrap = document.getElementById('paginationWrap');
  if (j.total_pages <= 1) { wrap.innerHTML = ''; return; }
  let html = `<span style="font-size:12px;color:rgba(255,255,255,.3);margin-right:8px;">
    ${j.page} / ${j.total_pages}
  </span>`;
  html += `<button class="sa-btn sa-btn-outline sa-btn-sm ${j.page<=1?'disabled':''}" onclick="goPage(${j.page-1})">&#x2190;</button>`;
  html += `<button class="sa-btn sa-btn-outline sa-btn-sm ${j.page>=j.total_pages?'disabled':''}" onclick="goPage(${j.page+1})">&#x2192;</button>`;
  wrap.innerHTML = html;
}

function goPage(p) { currentPage = p; loadList(); }

function openCreate() {
  document.getElementById('createModal').classList.add('open');
}
function closeCreate() {
  document.getElementById('createModal').classList.remove('open');
}

async function submitCreate() {
  const btn = document.getElementById('createBtn');
  const data = {
    nama_outlet: document.getElementById('c_nama_outlet').value.trim(),
    owner_name:  document.getElementById('c_owner_name').value.trim(),
    owner_wa:    document.getElementById('c_owner_wa').value.trim(),
    kota:        document.getElementById('c_kota').value.trim(),
    source:      document.getElementById('c_source').value,
    notes:       document.getElementById('c_notes').value.trim(),
  };
  if (!data.nama_outlet || !data.owner_name || !data.owner_wa) {
    showToast('Nama outlet, owner, dan WA wajib diisi', 'error'); return;
  }
  btn.disabled = true; btn.textContent = 'Menyimpan...';
  try {
    const res = await fetch('?action=create', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(data)
    });
    const j = await res.json();
    if (j.success) {
      closeCreate();
      showToast('Registrasi disimpan!', 'success');
      loadList(); loadCounts();
      // Optionally redirect to wizard
      if (confirm('Lanjut ke wizard provisioning sekarang?')) {
        window.location.href = `registration_wizard.php?id=${j.id}`;
      }
    } else {
      showToast(j.error || 'Gagal menyimpan', 'error');
    }
  } finally {
    btn.disabled = false; btn.textContent = 'Simpan Registrasi';
  }
}

async function cancelReg(id) {
  if (!confirm('Yakin batalkan registrasi ini?')) return;
  const fd = new FormData();
  fd.append('id', id); fd.append('_csrf', CSRF);
  const res = await fetch('?action=cancel', { method: 'POST', body: fd });
  const j   = await res.json();
  if (j.success) { showToast('Registrasi dibatalkan', 'info'); loadList(); loadCounts(); }
  else showToast(j.error || 'Gagal', 'error');
}

function showToast(msg, type='success') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = `sa-toast ${type} show`;
  setTimeout(() => t.classList.remove('show'), 3000);
}

function esc(s) {
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fmtDate(s) {
  if (!s) return '-';
  const d = new Date(s);
  return d.toLocaleDateString('id-ID', {day:'2-digit',month:'short',year:'numeric'});
}

// Close modal on backdrop click
document.getElementById('createModal').addEventListener('click', function(e) {
  if (e.target === this) closeCreate();
});

// Close sidebar on mobile
function saOpenNav()  { document.getElementById('saSidebar').classList.add('open'); document.getElementById('saOverlay').classList.add('open'); }
function saCloseNav() { document.getElementById('saSidebar').classList.remove('open'); document.getElementById('saOverlay').classList.remove('open'); }

// Init
loadList();
loadCounts();
</script>
</body>
</html>
