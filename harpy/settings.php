<?php
$activePage = 'settings';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
requirePermission('settings.roles');

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();

    // LIST ROLES
    if ($action === 'list_roles') {
        $rows = TenantQuery::raw(
            "SELECT r.*,
             (SELECT COUNT(*) FROM hl_users WHERE role_id=r.id AND tenant_id=r.tenant_id AND is_active=1) as jumlah_user,
             (SELECT COUNT(*) FROM hl_role_permissions WHERE role_id=r.id) as jumlah_perm
             FROM hl_roles r
             WHERE r.tenant_id=?
             ORDER BY r.is_system DESC, r.nama",
            [$tid]
        );
        echo json_encode($rows); exit;
    }

    // GET ROLE PERMISSIONS
    if ($action === 'get_role_perms') {
        $rid = intval($_GET['role_id']);
        // Verify role belongs to tenant
        if (!TenantQuery::exists('hl_roles', 'id = ?', [$rid])) {
            echo json_encode([]); exit;
        }
        $rows = TenantQuery::raw(
            "SELECT permission_id, filter_data FROM hl_role_permissions WHERE role_id=?",
            [$rid]
        );
        $map = [];
        foreach ($rows as $r) $map[$r['permission_id']] = $r['filter_data'];
        echo json_encode($map); exit;
    }

    // GET ALL PERMISSIONS (grouped by modul) — global table, no tenant_id
    if ($action === 'get_all_perms') {
        $db   = Database::get();
        $rows = $db->query("SELECT * FROM hl_permissions ORDER BY modul, aksi")->fetchAll();
        $grouped = [];
        foreach ($rows as $r) $grouped[$r['modul']][] = $r;
        echo json_encode($grouped); exit;
    }

    // SAVE ROLE
    if ($action === 'save_role' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $nama    = substr(trim(strip_tags($d['nama'] ?? '')), 0, 80);
        $deskrp  = substr(trim(strip_tags($d['deskripsi'] ?? '')), 0, 255);
        $isActv  = intval($d['is_active'] ?? 1) ? 1 : 0;

        if (!empty($d['id'])) {
            $rid = intval($d['id']);
            // Verify ownership
            $role = TenantQuery::rawOne("SELECT is_system FROM hl_roles WHERE id=? AND tenant_id=?", [$rid, $tid]);
            if (!$role) { echo json_encode(['error'=>'Role tidak ditemukan']); exit; }

            if ($role['is_system']) {
                // System role: boleh edit deskripsi & status saja
                TenantQuery::update('hl_roles',
                    ['deskripsi' => $deskrp, 'is_active' => $isActv],
                    'id = ?', [$rid]
                );
            } else {
                if (!$nama) { echo json_encode(['error'=>'Nama role wajib diisi']); exit; }
                TenantQuery::update('hl_roles',
                    ['nama' => $nama, 'deskripsi' => $deskrp, 'is_active' => $isActv],
                    'id = ?', [$rid]
                );
            }
            logAudit('update', 'settings', 'Role: ' . $nama);
            echo json_encode(['success'=>true, 'id'=>$rid]); exit;
        } else {
            if (!$nama) { echo json_encode(['error'=>'Nama role wajib diisi']); exit; }
            $newId = TenantQuery::insert('hl_roles', [
                'nama'      => $nama,
                'deskripsi' => $deskrp,
                'is_system' => 0,
                'is_active' => 1,
            ]);
            logAudit('create', 'settings', 'Role: ' . $nama);
            echo json_encode(['success'=>true, 'id'=>$newId]); exit;
        }
    }

    // SAVE PERMISSIONS untuk role
    if ($action === 'save_perms' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $d      = json_decode(file_get_contents('php://input'), true);
        $roleId = intval($d['role_id']);
        $perms  = $d['permissions'] ?? []; // {permission_id: filter_data}

        // Verify role belongs to tenant
        if (!TenantQuery::exists('hl_roles', 'id = ?', [$roleId])) {
            echo json_encode(['error'=>'Role tidak ditemukan']); exit;
        }

        $db = Database::get();
        $db->prepare("DELETE FROM hl_role_permissions WHERE role_id=? AND tenant_id=?")->execute([$roleId, $tid]);
        $stmt = $db->prepare("INSERT INTO hl_role_permissions (tenant_id,role_id,permission_id,filter_data) VALUES (?,?,?,?)");
        foreach ($perms as $permId => $filter) {
            if ($filter) $stmt->execute([$tid, $roleId, intval($permId), $filter]);
        }

        logAudit('update_permission', 'settings', 'Update permission role ID: ' . $roleId);
        echo json_encode(['success'=>true]); exit;
    }

    // DELETE ROLE
    if ($action === 'delete_role' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $d   = json_decode(file_get_contents('php://input'), true);
        $rid = intval($d['id']);

        $role = TenantQuery::rawOne("SELECT is_system FROM hl_roles WHERE id=? AND tenant_id=?", [$rid, $tid]);
        if (!$role) { echo json_encode(['error'=>'Role tidak ditemukan']); exit; }
        if ($role['is_system']) {
            echo json_encode(['error'=>'Role sistem tidak bisa dihapus']); exit;
        }

        $jumlahUser = TenantQuery::count('hl_users', 'role_id = ?', [$rid]);
        if ($jumlahUser > 0) {
            echo json_encode(['error'=>'Role masih digunakan oleh karyawan — pindahkan role karyawan dahulu']); exit;
        }

        $db = Database::get();
        $db->prepare("DELETE FROM hl_roles WHERE id=? AND tenant_id=? AND is_system=0")->execute([$rid, $tid]);
        logAudit('delete', 'settings', 'Role ID: ' . $rid);
        echo json_encode(['success'=>true]); exit;
    }

    // DUPLICATE ROLE
    if ($action === 'duplicate_role' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $d   = json_decode(file_get_contents('php://input'), true);
        $rid = intval($d['id']);

        $src = TenantQuery::rawOne("SELECT * FROM hl_roles WHERE id=? AND tenant_id=?", [$rid, $tid]);
        if (!$src) { echo json_encode(['error'=>'Role tidak ditemukan']); exit; }

        $newId = TenantQuery::insert('hl_roles', [
            'nama'      => 'Salinan ' . $src['nama'],
            'deskripsi' => 'Duplikat dari ' . $src['nama'],
            'is_system' => 0,
            'is_active' => 1,
        ]);

        // Copy permissions
        $db    = Database::get();
        $perms = $db->prepare("SELECT permission_id,filter_data FROM hl_role_permissions WHERE role_id=? AND tenant_id=?");
        $perms->execute([$rid, $tid]);
        $stmt  = $db->prepare("INSERT INTO hl_role_permissions (tenant_id,role_id,permission_id,filter_data) VALUES (?,?,?,?)");
        foreach ($perms->fetchAll() as $p) $stmt->execute([$tid, $newId, $p['permission_id'], $p['filter_data']]);

        logAudit('create', 'settings', 'Duplikat role: ' . $src['nama']);
        echo json_encode(['success'=>true, 'id'=>$newId]); exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Settings — Role & Permission'); ?>
<style>
.role-card{background:var(--white);border-radius:var(--r-lg);border:2px solid rgba(27,45,90,.07);padding:20px;transition:all .2s;cursor:pointer;position:relative}
.role-card:hover{border-color:var(--teal);box-shadow:var(--shadow-lg)}
.role-card.selected{border-color:var(--teal);background:var(--teal-bg)}
.role-card.system::before{content:'SYSTEM';position:absolute;top:10px;right:10px;font-size:9px;font-weight:800;letter-spacing:.1em;background:var(--light);color:var(--gray);padding:2px 7px;border-radius:100px}
.role-nama{font-size:16px;font-weight:800;color:var(--navy);margin-bottom:4px}
.role-desc{font-size:13px;color:var(--gray);margin-bottom:12px;line-height:1.4}
.role-meta{display:flex;gap:10px;font-size:12px;color:var(--gray)}

/* PERMISSION MATRIX */
.perm-section{margin-bottom:20px}
.perm-section-title{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:var(--navy);padding:10px 0 8px;border-bottom:2px solid var(--light);margin-bottom:10px;display:flex;align-items:center;gap:8px}
.perm-row{display:flex;align-items:center;padding:9px 12px;border-radius:var(--r);transition:background .15s;gap:12px}
.perm-row:hover{background:var(--off)}
.perm-label{flex:1;font-size:14px;color:var(--dark)}
.perm-desc{font-size:11px;color:var(--gray);margin-top:1px}
.perm-toggle{display:flex;align-items:center;gap:6px}

/* FILTER SELECTOR */
.filter-sel{padding:5px 8px;border:1.5px solid rgba(27,45,90,.14);border-radius:8px;font-size:12px;font-family:var(--font);background:var(--off);cursor:pointer;color:var(--dark);outline:none;transition:all .2s}
.filter-sel:focus{border-color:var(--teal)}
.filter-sel.active{background:#D1FAE5;border-color:var(--green);color:#065F46;font-weight:600}

/* CHECKBOX custom */
.hl-check{width:18px;height:18px;border-radius:5px;border:2px solid rgba(27,45,90,.2);background:var(--off);cursor:pointer;appearance:none;-webkit-appearance:none;transition:all .2s;flex-shrink:0}
.hl-check:checked{background:var(--teal);border-color:var(--teal);background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 16 16' fill='%230F1C3A' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M12.207 4.793a1 1 0 010 1.414l-5 5a1 1 0 01-1.414 0l-2-2a1 1 0 011.414-1.414L6.5 9.086l4.293-4.293a1 1 0 011.414 0z'/%3E%3C/svg%3E")}

/* STICKY SAVE */
.sticky-save{position:sticky;bottom:0;background:var(--white);border-top:1px solid var(--light);padding:14px 20px;display:flex;gap:10px;justify-content:flex-end;z-index:10}
</style>
</head>
<body>
<?php renderTopbar('settings'); ?>

<div class="hl-main">
  <div style="margin-bottom:20px">
    <h1 style="font-size:1.4rem;font-weight:800;color:var(--navy)">⚙️ Settings — Role & Permission</h1>
    <p style="font-size:14px;color:var(--gray);margin-top:4px">Kelola role karyawan dan hak akses per fitur</p>
  </div>

  <div class="hl-grid-2" style="gap:24px;align-items:start">

    <!-- KOLOM KIRI: Daftar Role -->
    <div>
      <div class="hl-card">
        <div class="hl-card-header">
          <div class="hl-card-title">🎭 Daftar Role</div>
          <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="openRoleModal()">+ Buat Role</button>
        </div>
        <div class="hl-card-body">
          <div id="roleList" style="display:flex;flex-direction:column;gap:10px">
            <div class="hl-loading">⏳ Memuat...</div>
          </div>
        </div>
      </div>
    </div>

    <!-- KOLOM KANAN: Permission Matrix -->
    <div>
      <div class="hl-card" id="permCard" style="display:none">
        <div class="hl-card-header">
          <div class="hl-card-title" id="permTitle">🔐 Permission</div>
          <div style="display:flex;gap:8px">
            <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="checkAll(true)">✓ Semua</button>
            <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="checkAll(false)">✗ Hapus Semua</button>
          </div>
        </div>
        <div class="hl-card-body" id="permMatrix">
          <div class="hl-loading">⏳ Memuat permission...</div>
        </div>
        <div class="sticky-save">
          <button class="hl-btn hl-btn-outline" onclick="cancelEdit()">Batal</button>
          <button class="hl-btn hl-btn-primary" onclick="savePerms()">💾 Simpan Permission</button>
        </div>
      </div>

      <div id="permEmpty" style="text-align:center;padding:60px 20px;color:var(--gray)">
        <div style="font-size:2.5rem;margin-bottom:12px;opacity:.4">🎭</div>
        <p style="font-size:14px">Pilih role di sebelah kiri untuk mengatur permission</p>
      </div>
    </div>
  </div>
</div>

<!-- MODAL ROLE -->
<div class="hl-modal-overlay" id="modalRole">
  <div class="hl-modal hl-modal-sm">
    <div class="hl-modal-header">
      <span class="hl-modal-title" id="roleModalTitle">➕ Buat Role Baru</span>
      <button class="hl-modal-close" onclick="closeRoleModal()">✕</button>
    </div>
    <div class="hl-modal-body">
      <input type="hidden" id="r_id"/>
      <input type="hidden" id="r_is_system" value="0"/>
      <div class="hl-form-group">
        <label class="hl-label">Nama Role <span class="req">*</span></label>
        <input type="text" id="r_nama" class="hl-input" placeholder="Contoh: Supervisor, Kurir..."/>
        <div id="r_nama_hint" style="font-size:11px;color:var(--gray);margin-top:3px"></div>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Deskripsi</label>
        <textarea id="r_desc" class="hl-input hl-textarea" placeholder="Keterangan role ini..." style="min-height:72px"></textarea>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Status</label>
        <select id="r_active" class="hl-input">
          <option value="1">✅ Aktif</option>
          <option value="0">⏸️ Nonaktif</option>
        </select>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeRoleModal()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveRole()">💾 Simpan</button>
    </div>
  </div>
</div>

<?php renderToast(); ?>

<script>
let allRoles = [];
let allPerms = {};
let currentRoleId = null;
let currentRolePerms = {}; // {perm_id: filter_data}

const MODUL_ICON = {
  pos:'🧾',orders:'📋',kas:'💰',laporan:'📊',
  customer:'👥',karyawan:'👤',promo:'🎟️',
  layanan:'🧺',absensi:'🕐',settings:'⚙️'
};
const MODUL_LABEL = {
  pos:'POS',orders:'Order',kas:'Kas',laporan:'Laporan',
  customer:'Customer',karyawan:'Karyawan',promo:'Promo',
  layanan:'Layanan',absensi:'Absensi',settings:'Settings'
};

document.addEventListener('DOMContentLoaded', () => {
  loadRoles();
  loadAllPerms();
});

// ── LOAD ROLES ────────────────────────────────────────
async function loadRoles() {
  const r = await fetch('settings.php?action=list_roles');
  allRoles = await r.json();
  renderRoles();
}

function renderRoles() {
  const el = document.getElementById('roleList');
  if (!allRoles.length) { el.innerHTML = '<div class="hl-empty">Belum ada role</div>'; return; }

  el.innerHTML = allRoles.map(role => `
    <div class="role-card ${role.is_system==1?'system':''} ${currentRoleId==role.id?'selected':''}"
         onclick="selectRole(${role.id})">
      <div class="role-nama">${esc(role.nama)}</div>
      <div class="role-desc">${esc(role.deskripsi||'Tidak ada deskripsi')}</div>
      <div class="role-meta">
        <span>👤 ${role.jumlah_user} user</span>
        <span>🔐 ${role.jumlah_perm} permission</span>
        <span class="hl-badge ${role.is_active==1?'hl-badge-green':'hl-badge-red'}" style="font-size:10px">${role.is_active==1?'Aktif':'Nonaktif'}</span>
      </div>
      <div style="display:flex;gap:6px;margin-top:12px" onclick="event.stopPropagation()">
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="editRole(${role.id})">✏️ Edit</button>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="duplicateRole(${role.id})">📋 Duplikat</button>
        ${role.is_system==0?`<button class="hl-btn hl-btn-danger hl-btn-sm" onclick="deleteRole(${role.id})">🗑️</button>`:''}
      </div>
    </div>`).join('');
}

// ── LOAD ALL PERMISSIONS ──────────────────────────────
async function loadAllPerms() {
  const r = await fetch('settings.php?action=get_all_perms');
  allPerms = await r.json();
}

// ── SELECT ROLE → tampilkan permission matrix ─────────
async function selectRole(id) {
  currentRoleId = id;
  renderRoles();

  const role = allRoles.find(r=>r.id==id);
  document.getElementById('permTitle').textContent = '🔐 Permission — ' + role.nama;
  document.getElementById('permCard').style.display = 'block';
  document.getElementById('permEmpty').style.display = 'none';
  document.getElementById('permMatrix').innerHTML = '<div class="hl-loading">⏳ Memuat...</div>';

  const r = await fetch('settings.php?action=get_role_perms&role_id='+id);
  currentRolePerms = await r.json();

  renderPermMatrix();
}

function cancelEdit() {
  currentRoleId = null;
  renderRoles();
  document.getElementById('permCard').style.display = 'none';
  document.getElementById('permEmpty').style.display = 'block';
}

// ── RENDER PERMISSION MATRIX ──────────────────────────
function renderPermMatrix() {
  const el = document.getElementById('permMatrix');
  let html  = '';

  for (const [modul, perms] of Object.entries(allPerms)) {
    const icon  = MODUL_ICON[modul] || '📌';
    const label = MODUL_LABEL[modul] || modul;

    html += `<div class="perm-section">
      <div class="perm-section-title">
        <span style="font-size:1.1rem">${icon}</span> ${label}
        <button class="hl-btn hl-btn-outline hl-btn-sm" style="margin-left:auto;font-size:11px"
          onclick="toggleModul('${modul}',true)">✓ Semua</button>
        <button class="hl-btn hl-btn-outline hl-btn-sm" style="font-size:11px"
          onclick="toggleModul('${modul}',false)">✗ Hapus</button>
      </div>`;

    perms.forEach(p => {
      const checked    = !!currentRolePerms[p.id];
      const filterVal  = currentRolePerms[p.id] || 'all';
      const hasFilter  = ['orders.view_all','orders.view_own','absensi.view_own','absensi.view_all'].includes(p.kode);

      html += `<div class="perm-row">
        <input type="checkbox" class="hl-check perm-cb" id="perm_${p.id}"
          data-id="${p.id}" data-has-filter="${hasFilter}"
          ${checked?'checked':''} onchange="togglePerm(${p.id},this.checked)"/>
        <label for="perm_${p.id}" style="flex:1;cursor:pointer">
          <div class="perm-label">${esc(p.label)}</div>
          <div class="perm-desc">${esc(p.deskripsi||'')}</div>
        </label>
        ${hasFilter ? `
        <select class="filter-sel ${checked?'active':''}" id="filter_${p.id}"
          onchange="updateFilter(${p.id},this.value)" ${checked?'':'disabled'}>
          <option value="all" ${filterVal==='all'?'selected':''}>Semua Data</option>
          <option value="own" ${filterVal==='own'?'selected':''}>Data Sendiri</option>
          <option value="today" ${filterVal==='today'?'selected':''}>Hari Ini Saja</option>
        </select>` : ''}
      </div>`;
    });
    html += '</div>';
  }

  el.innerHTML = html;
}

function togglePerm(id, checked) {
  if (checked) {
    currentRolePerms[id] = 'all';
  } else {
    delete currentRolePerms[id];
  }
  const sel = document.getElementById('filter_'+id);
  if (sel) {
    sel.disabled = !checked;
    sel.className = 'filter-sel ' + (checked?'active':'');
  }
}

function updateFilter(id, val) {
  if (currentRolePerms[id] !== undefined) {
    currentRolePerms[id] = val;
  }
}

function checkAll(checked) {
  document.querySelectorAll('.perm-cb').forEach(cb => {
    cb.checked = checked;
    togglePerm(parseInt(cb.dataset.id), checked);
  });
}

function toggleModul(modul, checked) {
  const perms = allPerms[modul] || [];
  perms.forEach(p => {
    const cb = document.getElementById('perm_'+p.id);
    if (cb) { cb.checked = checked; togglePerm(p.id, checked); }
  });
}

// ── SAVE PERMISSIONS ──────────────────────────────────
async function savePerms() {
  if (!currentRoleId) return;
  const r = await fetch('settings.php?action=save_perms', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({ role_id: currentRoleId, permissions: currentRolePerms })
  });
  const d = await r.json();
  if (d.success) {
    showToast('✅ Permission berhasil disimpan!', 'success');
    loadRoles();
  } else {
    showToast('❌ ' + (d.error||'Gagal'), 'error');
  }
}

// ── ROLE CRUD ─────────────────────────────────────────
function openRoleModal(data=null) {
  document.getElementById('r_id').value        = data?.id||'';
  document.getElementById('r_nama').value      = data?.nama||'';
  document.getElementById('r_desc').value      = data?.deskripsi||'';
  document.getElementById('r_active').value    = data?.is_active??1;
  document.getElementById('r_is_system').value = data?.is_system||0;

  const namaInput = document.getElementById('r_nama');
  const namaHint  = document.getElementById('r_nama_hint');
  if (data?.is_system==1) {
    namaInput.disabled = true;
    namaHint.textContent = '⚠️ Nama role sistem tidak bisa diubah';
  } else {
    namaInput.disabled = false;
    namaHint.textContent = '';
  }
  document.getElementById('roleModalTitle').textContent = data ? '✏️ Edit Role' : '➕ Buat Role Baru';
  document.getElementById('modalRole').classList.add('open');
}

function editRole(id) { openRoleModal(allRoles.find(r=>r.id==id)); }
function closeRoleModal() { document.getElementById('modalRole').classList.remove('open'); }

async function saveRole() {
  const nama = document.getElementById('r_nama').value.trim();
  const sys  = document.getElementById('r_is_system').value;
  if (!sys || sys=='0') {
    if (!nama) { showToast('⚠️ Nama role wajib diisi','error'); return; }
  }
  const r = await fetch('settings.php?action=save_role', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({
      id:        document.getElementById('r_id').value,
      nama,
      deskripsi: document.getElementById('r_desc').value,
      is_active: document.getElementById('r_active').value,
    })
  });
  const d = await r.json();
  if (d.success) {
    showToast('✅ Role disimpan!','success');
    closeRoleModal(); loadRoles();
    if (d.id) selectRole(d.id);
  } else showToast('❌ '+(d.error||'Gagal'),'error');
}

async function duplicateRole(id) {
  const role = allRoles.find(r=>r.id==id);
  if (!confirm('Duplikat role "'+role.nama+'" beserta semua permission-nya?')) return;
  const r = await fetch('settings.php?action=duplicate_role', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id})
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Role diduplikat!','success'); loadRoles(); }
  else showToast('❌ '+(d.error||'Gagal'),'error');
}

async function deleteRole(id) {
  const role = allRoles.find(r=>r.id==id);
  if (!confirm('Hapus role "'+role.nama+'"? Aksi ini tidak bisa dibatalkan.')) return;
  const r = await fetch('settings.php?action=delete_role', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id})
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Role dihapus','success'); if(currentRoleId==id) cancelEdit(); loadRoles(); }
  else showToast('❌ '+(d.error||'Gagal'),'error');
}

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function showToast(msg,type='success'){const t=document.getElementById('toast');t.textContent=msg;t.className='hl-toast '+type+' show';setTimeout(()=>t.className='hl-toast',3500)}
</script>
</body>
</html>
