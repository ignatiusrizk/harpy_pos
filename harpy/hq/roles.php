<?php
// ══════════════════════════════════════════════════════
// hq/roles.php — Manajemen Akses & Role (HQ View)
// Brief HQ-Outlet Section 4.7 (continued — review #8)
//
// Note: hl_roles + hl_permissions sudah tenant-scoped di codebase
// (bukan per outlet). Halaman ini expose UI dari HQ utk:
//   - Lihat semua role lintas outlet
//   - Buat role baru (mis: Manager Regional dengan access_hq)
//   - Edit permission per role
//   - Lihat user yang assigned ke role (lintas outlet)
//   - Konsistensi setup karena tenant-level
//
// TIDAK menggantikan outlet/settings.php — outlet view tetap bisa
// manage. Ini melengkapi untuk konteks multi-outlet (HQ).
// ══════════════════════════════════════════════════════

$activePage = 'hq-roles';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];
$uid  = (int)($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? '';

function logRoleAction(PDO $db, int $tid, int $uid, string $act, int $rid, string $detail = ''): void {
    try {
        $db->prepare("INSERT INTO superadmin_logs (action, target_type, target_id, details, created_at)
                      VALUES (?,'role',?,?,NOW())")
           ->execute([$act, $rid, json_encode(['tenant_id'=>$tid,'by'=>$uid,'detail'=>$detail])]);
    } catch (Throwable) {}
}

// ── AJAX actions ──────────────────────────────────────
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'list') {
        try {
            $stmt = $db->prepare(
                "SELECT r.id, r.nama, r.deskripsi, r.is_system, r.is_active,
                        (SELECT COUNT(*) FROM hl_users
                          WHERE tenant_id=r.tenant_id AND role_id=r.id AND is_active=1) AS user_count,
                        (SELECT COUNT(*) FROM hl_role_permissions
                          WHERE tenant_id=r.tenant_id AND role_id=r.id) AS perm_count
                   FROM hl_roles r
                  WHERE r.tenant_id=?
                  ORDER BY r.is_system DESC, r.nama ASC"
            );
            $stmt->execute([$tid]);
            echo json_encode($stmt->fetchAll());
        } catch (Throwable $e) {
            echo json_encode(['error'=>$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'permissions_grouped') {
        // Semua permission yang tersedia, di-group per modul
        try {
            $stmt = $db->prepare(
                "SELECT id, kode, modul, aksi, deskripsi FROM hl_permissions
                  WHERE tenant_id=? OR tenant_id IS NULL OR tenant_id=0
                  ORDER BY modul ASC, aksi ASC"
            );
            $stmt->execute([$tid]);
            $rows = $stmt->fetchAll();
            $grouped = [];
            foreach ($rows as $r) {
                $modul = $r['modul'] ?: 'lain';
                $grouped[$modul][] = $r;
            }
            echo json_encode($grouped);
        } catch (Throwable $e) {
            echo json_encode(['error'=>$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'detail') {
        $rid = (int)($_GET['id'] ?? 0);
        try {
            $r = $db->prepare("SELECT * FROM hl_roles WHERE id=? AND tenant_id=? LIMIT 1");
            $r->execute([$rid, $tid]);
            $role = $r->fetch();
            if (!$role) { echo json_encode(['error'=>'Role tidak ditemukan']); exit; }

            // Assigned permissions
            $p = $db->prepare("SELECT permission_id FROM hl_role_permissions
                                WHERE tenant_id=? AND role_id=?");
            $p->execute([$tid, $rid]);
            $permIds = array_map('intval', $p->fetchAll(PDO::FETCH_COLUMN));

            // Users assigned to this role (lintas outlet)
            $u = $db->prepare(
                "SELECT u.id, u.nama, u.username, u.role, u.outlet_id, u.is_active,
                        (SELECT nama_outlet FROM outlets WHERE id=u.outlet_id) AS nama_outlet
                   FROM hl_users u
                  WHERE u.tenant_id=? AND u.role_id=?
                  ORDER BY u.is_active DESC, u.nama ASC"
            );
            $u->execute([$tid, $rid]);
            $users = $u->fetchAll();

            // Karyawan outlet assignments untuk tiap user
            foreach ($users as &$usr) {
                $usr['assignments'] = [];
                try {
                    $a = $db->prepare("SELECT o.nama_outlet
                                         FROM hl_karyawan_outlet ko
                                         JOIN outlets o ON o.id=ko.outlet_id
                                        WHERE ko.tenant_id=? AND ko.karyawan_id=? AND ko.is_active=1");
                    $a->execute([$tid, $usr['id']]);
                    $usr['assignments'] = $a->fetchAll(PDO::FETCH_COLUMN);
                } catch (Throwable) {}
            }
            unset($usr);

            echo json_encode([
                'role'        => $role,
                'permissions' => $permIds,
                'users'       => $users,
            ]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        verifyCsrf();
        $rid  = (int)($d['id'] ?? 0);
        $nama = substr(trim(strip_tags($d['nama'] ?? '')), 0, 100);
        $desk = substr(trim(strip_tags($d['deskripsi'] ?? '')), 0, 300);
        $active = (int)(!empty($d['is_active']) ? 1 : 0);
        $permIds = array_map('intval', $d['permissions'] ?? []);

        if (!$nama) { echo json_encode(['error'=>'Nama role wajib diisi']); exit; }

        $db->beginTransaction();
        try {
            if ($rid) {
                $v = $db->prepare("SELECT is_system FROM hl_roles WHERE id=? AND tenant_id=?");
                $v->execute([$rid, $tid]);
                $sys = $v->fetchColumn();
                if ($sys === false) { throw new Exception('Role tidak ditemukan'); }
                // System role tidak boleh ganti nama, hanya permission
                if ((int)$sys === 1) {
                    // Update permissions only
                } else {
                    $db->prepare("UPDATE hl_roles SET nama=?, deskripsi=?, is_active=? WHERE id=? AND tenant_id=?")
                       ->execute([$nama, $desk ?: null, $active, $rid, $tid]);
                }
                $db->prepare("DELETE FROM hl_role_permissions WHERE tenant_id=? AND role_id=?")
                   ->execute([$tid, $rid]);
            } else {
                $db->prepare("INSERT INTO hl_roles (tenant_id, nama, deskripsi, is_system, is_active)
                              VALUES (?,?,?,0,?)")
                   ->execute([$tid, $nama, $desk ?: null, $active]);
                $rid = (int)$db->lastInsertId();
            }

            // Insert permissions
            if (!empty($permIds)) {
                $ins = $db->prepare("INSERT INTO hl_role_permissions (tenant_id, role_id, permission_id) VALUES (?,?,?)");
                foreach ($permIds as $pid) {
                    if ($pid > 0) $ins->execute([$tid, $rid, $pid]);
                }
            }

            $db->commit();
            logRoleAction($db, $tid, $uid, 'save_role', $rid, "nama=$nama perms=" . count($permIds));
            echo json_encode(['success'=>true, 'id'=>$rid]);
        } catch (Throwable $e) {
            $db->rollBack();
            echo json_encode(['error'=>'Gagal: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        verifyCsrf();
        $rid = (int)($d['id'] ?? 0);
        if (!$rid) { echo json_encode(['error'=>'ID invalid']); exit; }

        try {
            $v = $db->prepare("SELECT is_system FROM hl_roles WHERE id=? AND tenant_id=?");
            $v->execute([$rid, $tid]);
            $sys = $v->fetchColumn();
            if ($sys === false) { echo json_encode(['error'=>'Role tidak ditemukan']); exit; }
            if ((int)$sys === 1) { echo json_encode(['error'=>'Role sistem tidak bisa dihapus']); exit; }

            // Cek apakah ada user yang pakai role ini
            $u = $db->prepare("SELECT COUNT(*) FROM hl_users WHERE tenant_id=? AND role_id=? AND is_active=1");
            $u->execute([$tid, $rid]);
            if ((int)$u->fetchColumn() > 0) {
                echo json_encode(['error'=>'Role ini masih dipakai karyawan aktif. Pindahkan dulu lalu coba lagi.']);
                exit;
            }

            $db->prepare("DELETE FROM hl_role_permissions WHERE tenant_id=? AND role_id=?")->execute([$tid, $rid]);
            $db->prepare("DELETE FROM hl_roles WHERE id=? AND tenant_id=?")->execute([$rid, $tid]);
            logRoleAction($db, $tid, $uid, 'delete_role', $rid);
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>'Gagal: '.$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error'=>'Unknown action']); exit;
}

$tenantNm  = $hqTenant['nama_outlet'] ?? 'HQ';
$ownerNama = $hqUser['nama'] ?? 'Owner';
$csrf      = getCsrfToken();
?>
<?php
$pageTitle  = 'Role & Akses';
$activePage = 'hq-roles';
require __DIR__ . '/_layout_open.php';
?>
<style>
  .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px}
  h1{font-size:1.4rem;font-weight:800;color:#0F1C3A}
  h1 small{display:block;font-size:13px;font-weight:400;color:#6B7280;margin-top:2px}

  .btn{padding:10px 18px;border-radius:9px;font-weight:700;font-size:13px;border:none;cursor:pointer;
       font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
  .btn-primary{background:#35E8D5;color:#0F1C3A}
  .btn-light{background:#F3F4F6;color:#0F1C3A;border:1px solid #E5E7EB}
  .btn-danger{background:#FEE2E2;color:#991B1B}
  .btn-sm{padding:6px 12px;font-size:11px}

  .info-banner{background:#EFF6FF;border:1px solid #BFDBFE;color:#1E40AF;padding:12px 16px;
               border-radius:10px;font-size:13px;margin-bottom:16px;line-height:1.6}

  .role-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
  .rcard{background:#fff;border-radius:12px;padding:18px 20px;box-shadow:0 1px 6px rgba(0,0,0,.05);
         border-top:3px solid #35E8D5;transition:box-shadow .2s;position:relative}
  .rcard:hover{box-shadow:0 4px 16px rgba(0,0,0,.08)}
  .rcard.inactive{opacity:.55}
  .rcard-system{border-top-color:#8B5CF6}
  .rcard-name{font-size:1.1rem;font-weight:800;color:#0F1C3A;display:flex;align-items:center;gap:6px;margin-bottom:3px}
  .pill-system{background:#EDE9FE;color:#5B21B6;font-size:9px;font-weight:800;padding:2px 7px;border-radius:4px}
  .pill-off{background:#F3F4F6;color:#6B7280;font-size:9px;font-weight:800;padding:2px 7px;border-radius:4px}
  .rcard-desc{font-size:12px;color:#6B7280;margin-bottom:14px;min-height:32px;line-height:1.5}
  .rcard-stats{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;font-size:12px}
  .rcard-stat{background:#F9FAFB;border-radius:8px;padding:10px;text-align:center}
  .rcard-stat strong{display:block;font-size:1.1rem;color:#0F1C3A;font-family:monospace;font-weight:800}
  .rcard-stat small{color:#9CA3AF;font-size:10px;text-transform:uppercase;letter-spacing:.04em;font-weight:600}
  .rcard-actions{display:flex;gap:6px}
  .rcard-actions .btn{flex:1;justify-content:center}

  /* Modal */
  .modal-backdrop{position:fixed;inset:0;background:rgba(15,28,58,.75);z-index:999;display:none;
                  align-items:center;justify-content:center;padding:20px}
  .modal-backdrop.open{display:flex}
  .modal{background:#fff;border-radius:14px;max-width:720px;width:100%;max-height:90vh;overflow:auto;
         padding:24px 26px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
  .modal-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px}
  .modal-title{font-size:1.1rem;font-weight:800;color:#0F1C3A}
  .modal-close{background:none;border:none;font-size:24px;cursor:pointer;color:#9CA3AF;line-height:1}
  .modal-close:hover{color:#0F1C3A}

  .form-grid{display:grid;gap:12px}
  .form-grid label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}
  .form-grid input[type=text],.form-grid textarea{
    width:100%;padding:9px 12px;border:1.5px solid #E5E7EB;border-radius:8px;
    font-size:14px;outline:none;font-family:inherit;box-sizing:border-box
  }
  .form-grid input:focus,.form-grid textarea:focus{border-color:#35E8D5}

  .perm-group{background:#F9FAFB;border-radius:8px;padding:10px 12px;margin-bottom:6px}
  .perm-group-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;
                    color:#6B7280;margin-bottom:6px;display:flex;justify-content:space-between;align-items:center}
  .perm-group-bulk{font-size:10px;color:#0891B2;cursor:pointer;text-decoration:underline}
  .perm-row{display:flex;align-items:center;gap:8px;padding:4px 0;font-size:12px}
  .perm-row input{width:16px;height:16px;accent-color:#35E8D5;cursor:pointer}
  .perm-row label{cursor:pointer;color:#374151;font-weight:500;margin:0!important;flex:1}
  .perm-row small{color:#9CA3AF;font-size:10px;display:block;margin-top:1px}

  .user-row{display:flex;justify-content:space-between;align-items:center;padding:9px 0;
            border-bottom:1px solid #F3F4F6;font-size:13px}
  .user-row:last-child{border-bottom:none}
  .user-name strong{color:#0F1C3A;font-weight:700}
  .user-name small{display:block;color:#9CA3AF;font-size:11px;margin-top:1px}

  .alert{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:12px}
  .alert.error{background:#FEE2E2;color:#991B1B;border:1px solid #FECACA}
  .alert.success{background:#D1FAE5;color:#065F46;border:1px solid #6EE7B7}
</style>

  <div class="header">
    <h1>🔐 Manajemen Akses & Role
      <small>Setup permission lintas outlet · <?= htmlspecialchars($tenantNm) ?></small>
    </h1>
    <button class="btn btn-primary" onclick="openCreate()">+ Buat Role Baru</button>
  </div>

  <div class="info-banner">
    💡 <strong>Beda dengan settings outlet view:</strong> Role di sini berlaku
    untuk seluruh akun (tenant-level) jadi otomatis konsisten di semua outlet.
    Tetap bisa diatur juga lewat <code>settings.php</code> outlet view.
  </div>

  <div class="role-grid" id="roleGrid">
    <div style="grid-column:1/-1;text-align:center;padding:40px;color:#9CA3AF">⏳ Memuat…</div>
  </div>

<!-- Form Modal (Create / Edit) -->
<div class="modal-backdrop" id="formModal" onclick="if(event.target===this)closeModal('formModal')">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="formTitle">+ Buat Role Baru</div>
      <button class="modal-close" onclick="closeModal('formModal')">×</button>
    </div>
    <div id="formAlert"></div>
    <div class="form-grid">
      <input type="hidden" id="fId">
      <div>
        <label>Nama Role <span style="color:#EF4444">*</span></label>
        <input type="text" id="fNama" maxlength="100" placeholder="cth: Manager Regional">
      </div>
      <div>
        <label>Deskripsi</label>
        <textarea id="fDesk" rows="2" maxlength="300" placeholder="cth: Bisa akses HQ tapi tidak bisa kelola billing"></textarea>
      </div>
      <div>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" id="fActive" checked style="width:auto;margin:0">
          Aktif (bisa di-assign ke karyawan)
        </label>
      </div>
      <div>
        <label>Permission yang Diizinkan</label>
        <div id="permList" style="background:#F9FAFB;border:1.5px solid #E5E7EB;border-radius:8px;
             padding:10px;max-height:380px;overflow-y:auto">
          <div style="color:#9CA3AF;font-size:12px">Memuat permission…</div>
        </div>
      </div>
      <button class="btn btn-primary" style="padding:12px;font-size:14px" onclick="submitForm()">
        💾 Simpan Role
      </button>
    </div>
  </div>
</div>

<!-- Detail Modal -->
<div class="modal-backdrop" id="detailModal" onclick="if(event.target===this)closeModal('detailModal')">
  <div class="modal" id="detailContent"></div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
let permsCache = null;

function escapeHtml(s){return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}

async function loadList(){
  const r = await fetch('/ERP/harpy/hq/roles.php?action=list');
  const rows = await r.json();
  const grid = document.getElementById('roleGrid');
  if (rows.error) { grid.innerHTML = `<div style="grid-column:1/-1" class="alert error">${escapeHtml(rows.error)}</div>`; return; }
  if (rows.length === 0) {
    grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:#9CA3AF">Belum ada role</div>';
    return;
  }
  grid.innerHTML = rows.map(r => `
    <div class="rcard ${r.is_system==1?'rcard-system':''} ${r.is_active==0?'inactive':''}">
      <div class="rcard-name">
        ${escapeHtml(r.nama)}
        ${r.is_system==1 ? '<span class="pill-system">SYSTEM</span>' : ''}
        ${r.is_active==0 ? '<span class="pill-off">NON-AKTIF</span>' : ''}
      </div>
      <div class="rcard-desc">${escapeHtml(r.deskripsi || '(tanpa deskripsi)')}</div>
      <div class="rcard-stats">
        <div class="rcard-stat"><strong>${r.user_count}</strong><small>Karyawan</small></div>
        <div class="rcard-stat"><strong>${r.perm_count}</strong><small>Permission</small></div>
      </div>
      <div class="rcard-actions">
        <button class="btn btn-light btn-sm" onclick="showDetail(${r.id})">Detail</button>
        <button class="btn btn-primary btn-sm" onclick="openEdit(${r.id})">Edit Permission</button>
        ${r.is_system==1 ? '' : `<button class="btn btn-danger btn-sm" onclick="deleteRole(${r.id},'${escapeHtml(r.nama)}',${r.user_count})">🗑️</button>`}
      </div>
    </div>
  `).join('');
}

async function loadPermsCache(){
  if (permsCache) return permsCache;
  const r = await fetch('/ERP/harpy/hq/roles.php?action=permissions_grouped');
  permsCache = await r.json();
  return permsCache;
}

function renderPermList(grouped, checkedIds){
  const checkedSet = new Set(checkedIds.map(Number));
  const list = document.getElementById('permList');
  if (grouped.error || Object.keys(grouped).length === 0) {
    list.innerHTML = '<div style="color:#9CA3AF;font-size:12px">Belum ada permission terdefinisi di akun ini.</div>';
    return;
  }
  list.innerHTML = Object.entries(grouped).map(([modul, items]) => `
    <div class="perm-group">
      <div class="perm-group-title">
        📦 ${escapeHtml(modul.toUpperCase())}
        <span class="perm-group-bulk" onclick="bulkToggle('${escapeHtml(modul)}', true)">centang semua</span>
      </div>
      ${items.map(p => `
        <div class="perm-row">
          <input type="checkbox" class="perm-cb perm-mod-${escapeHtml(modul)}" value="${p.id}" id="perm-${p.id}"
                 ${checkedSet.has(parseInt(p.id))?'checked':''}>
          <label for="perm-${p.id}">
            <strong>${escapeHtml(p.kode)}</strong>
            ${p.deskripsi ? `<small>${escapeHtml(p.deskripsi)}</small>` : ''}
          </label>
        </div>
      `).join('')}
    </div>
  `).join('');
}

function bulkToggle(modul, value){
  document.querySelectorAll('.perm-mod-' + modul.replace(/[^a-z0-9_-]/gi, '')).forEach(c => c.checked = value);
}

async function openCreate(){
  document.getElementById('formTitle').textContent = '+ Buat Role Baru';
  document.getElementById('fId').value = '';
  document.getElementById('fNama').value = '';
  document.getElementById('fDesk').value = '';
  document.getElementById('fActive').checked = true;
  document.getElementById('fNama').disabled = false;
  document.getElementById('formAlert').innerHTML = '';
  const grouped = await loadPermsCache();
  renderPermList(grouped, []);
  openModal('formModal');
}

async function openEdit(id){
  const r = await fetch('/ERP/harpy/hq/roles.php?action=detail&id=' + id);
  const d = await r.json();
  if (d.error) { alert(d.error); return; }
  document.getElementById('formTitle').textContent = '✏️ Edit: ' + d.role.nama;
  document.getElementById('fId').value = d.role.id;
  document.getElementById('fNama').value = d.role.nama;
  document.getElementById('fDesk').value = d.role.deskripsi || '';
  document.getElementById('fActive').checked = d.role.is_active == 1;
  // System role: nama tidak boleh diganti
  document.getElementById('fNama').disabled = (d.role.is_system == 1);
  document.getElementById('formAlert').innerHTML = d.role.is_system == 1
    ? '<div class="alert" style="background:#EDE9FE;color:#5B21B6;border:1px solid #C4B5FD">ℹ️ Role sistem — nama tidak bisa diganti, hanya permission</div>'
    : '';
  const grouped = await loadPermsCache();
  renderPermList(grouped, d.permissions);
  openModal('formModal');
}

async function showDetail(id){
  const r = await fetch('/ERP/harpy/hq/roles.php?action=detail&id=' + id);
  const d = await r.json();
  if (d.error) { alert(d.error); return; }
  const usersHtml = d.users.length === 0
    ? '<div style="color:#9CA3AF;font-size:13px;font-style:italic;padding:14px 0">Belum ada karyawan yang punya role ini.</div>'
    : d.users.map(u => `
        <div class="user-row">
          <div class="user-name">
            <strong>${escapeHtml(u.nama)}</strong>
            <small>@${escapeHtml(u.username)} · ${u.is_active==1?'✓ Aktif':'⛔ Non-aktif'}</small>
          </div>
          <div style="font-size:11px;color:#6B7280;text-align:right">
            ${u.assignments.length > 0
              ? u.assignments.map(o => `📍 ${escapeHtml(o)}`).join('<br>')
              : '<span style="color:#9CA3AF">⚠️ Tidak ditugaskan</span>'}
          </div>
        </div>
      `).join('');

  document.getElementById('detailContent').innerHTML = `
    <div class="modal-header">
      <div>
        <div class="modal-title">${escapeHtml(d.role.nama)} ${d.role.is_system==1?'<span class="pill-system">SYSTEM</span>':''}</div>
        <div style="font-size:12px;color:#6B7280;margin-top:3px">${escapeHtml(d.role.deskripsi || '(tanpa deskripsi)')}</div>
      </div>
      <button class="modal-close" onclick="closeModal('detailModal')">×</button>
    </div>

    <div style="margin-bottom:14px">
      <div style="font-size:11px;color:#6B7280;font-weight:700;text-transform:uppercase;margin-bottom:8px">
        🔐 Permission Aktif (${d.permissions.length})
      </div>
      ${d.permissions.length === 0
        ? '<div style="color:#9CA3AF;font-size:13px">Belum ada permission yang di-assign</div>'
        : '<div style="background:#F9FAFB;padding:10px 12px;border-radius:8px;font-size:11px;color:#374151">' +
          d.permissions.length + ' permission di-assign. Klik <strong>Edit Permission</strong> untuk lihat detail.</div>'}
    </div>

    <div>
      <div style="font-size:11px;color:#6B7280;font-weight:700;text-transform:uppercase;margin-bottom:8px">
        👥 Karyawan dengan Role Ini (${d.users.length})
      </div>
      ${usersHtml}
    </div>
  `;
  openModal('detailModal');
}

async function submitForm(){
  const alertEl = document.getElementById('formAlert');
  const data = {
    id: document.getElementById('fId').value,
    nama: document.getElementById('fNama').value.trim(),
    deskripsi: document.getElementById('fDesk').value.trim(),
    is_active: document.getElementById('fActive').checked ? 1 : 0,
    permissions: Array.from(document.querySelectorAll('.perm-cb:checked')).map(c => parseInt(c.value)),
  };
  if (!data.nama && !data.id) { alertEl.innerHTML = '<div class="alert error">Nama wajib diisi</div>'; return; }

  const r = await fetch('/ERP/harpy/hq/roles.php?action=save', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
    body: JSON.stringify(data),
  });
  const j = await r.json();
  if (j.error) { alertEl.innerHTML = '<div class="alert error">'+escapeHtml(j.error)+'</div>'; return; }
  alertEl.innerHTML = '<div class="alert success">✓ Tersimpan</div>';
  setTimeout(() => { closeModal('formModal'); loadList(); }, 700);
}

async function deleteRole(id, nama, userCount){
  if (userCount > 0) {
    alert(`Role "${nama}" masih dipakai ${userCount} karyawan aktif. Pindahkan dulu sebelum hapus.`);
    return;
  }
  if (!confirm(`Hapus role "${nama}"?\nSemua permission yang ter-assign akan ikut terhapus.`)) return;
  const r = await fetch('/ERP/harpy/hq/roles.php?action=delete', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
    body: JSON.stringify({id}),
  });
  const j = await r.json();
  if (j.error) { alert(j.error); return; }
  loadList();
}

function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}

loadList();
</script>
<?php require __DIR__ . '/_layout_close.php'; ?>
