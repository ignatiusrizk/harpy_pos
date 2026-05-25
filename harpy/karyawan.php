<?php
$activePage = 'karyawan';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
requirePermission('karyawan.view');

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $oid = TenantResolver::outletId();

    if ($action === 'get_roles') {
        $rows = TenantQuery::raw(
            "SELECT id, nama, deskripsi FROM hl_roles WHERE tenant_id=? AND is_active=1 ORDER BY nama",
            [$tid]
        );
        echo json_encode($rows); exit;
    }

    if ($action === 'list') {
        // OUTLET VIEW: tampilkan karyawan yang DITUGASKAN ke outlet ini
        // Sesuai brief HQ-Outlet Fase 3: karyawan = aset account, penugasan via hl_karyawan_outlet
        $rows = TenantQuery::raw(
            "SELECT u.*,
                COALESCE(r.nama, u.role) as role_nama,
                ko.assigned_at,
                (SELECT COUNT(*) FROM hl_absensi
                  WHERE user_id=u.id AND tenant_id=u.tenant_id AND outlet_id=?
                    AND MONTH(tanggal)=MONTH(CURDATE()) AND status='hadir') as hadir_bulan_ini,
                (SELECT jam_masuk FROM hl_absensi
                  WHERE user_id=u.id AND tenant_id=u.tenant_id AND outlet_id=?
                    AND tanggal=CURDATE() LIMIT 1) as jam_masuk_hari_ini
             FROM hl_users u
             JOIN hl_karyawan_outlet ko
               ON ko.karyawan_id=u.id AND ko.tenant_id=u.tenant_id
              AND ko.outlet_id=? AND ko.is_active=1
             LEFT JOIN hl_roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id
             WHERE u.tenant_id=?
             ORDER BY u.nama",
            [$oid, $oid, $oid, $tid]
        );
        echo json_encode($rows); exit;
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD']==='POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        if (!empty($d['id']) && !hasPermission('karyawan.edit')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        if (empty($d['id']) && !hasPermission('karyawan.create')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();

        $nama     = substr(trim(strip_tags($d['nama'] ?? '')), 0, 100);
        $username = substr(trim(strip_tags($d['username'] ?? '')), 0, 50);
        if (!$nama)     { echo json_encode(['error'=>'Nama wajib diisi']); exit; }
        if (!$username) { echo json_encode(['error'=>'Username wajib diisi']); exit; }

        // Resolve role
        $roleId  = is_numeric($d['role'] ?? '') ? intval($d['role']) : null;
        $roleStr = 'staff';
        if ($roleId) {
            $rRow = TenantQuery::rawOne("SELECT nama FROM hl_roles WHERE id=? AND tenant_id=?", [$roleId, $tid]);
            if ($rRow) {
                $rNama = strtolower($rRow['nama']);
                if (str_contains($rNama, 'owner') || str_contains($rNama, 'super')) $roleStr = 'superadmin';
                elseif (str_contains($rNama, 'manager') || str_contains($rNama, 'admin')) $roleStr = 'admin';
                else $roleStr = 'staff';
            }
        } elseif (!empty($d['role'])) {
            $roleStr = $d['role'];
        }

        $data = [
            'nama'       => $nama,
            'username'   => $username,
            'role'       => $roleStr,
            'role_id'    => $roleId,
            'gaji_pokok' => floatval($d['gaji_pokok'] ?? 0),
            'jabatan'    => substr(trim($d['jabatan'] ?? ''), 0, 100),
            'telepon'    => substr(trim(preg_replace('/[^0-9+\-\s]/', '', $d['telepon'] ?? '')), 0, 20),
            'alamat'     => substr(trim($d['alamat'] ?? ''), 0, 300),
            'tgl_masuk'  => $d['tgl_masuk'] ?: null,
            'is_active'  => intval($d['is_active'] ?? 1),
        ];

        if (!empty($d['id'])) {
            if (!empty($d['password'])) {
                $data['password'] = password_hash($d['password'], PASSWORD_DEFAULT);
            }
            TenantQuery::update('hl_users', $data, 'id = ?', [intval($d['id'])]);
        } else {
            if (empty($d['password'])) { echo json_encode(['error'=>'Password wajib diisi untuk karyawan baru']); exit; }
            // Cek duplikat username per tenant
            if (TenantQuery::exists('hl_users', 'username = ?', [$username])) {
                echo json_encode(['error'=>'Username sudah digunakan']); exit;
            }
            $data['password']  = password_hash($d['password'], PASSWORD_DEFAULT);
            $data['outlet_id'] = $oid; // outlet default saat login
            TenantQuery::insert('hl_users', $data);
            $newUserId = (int)Database::get()->lastInsertId();

            // Auto-assign ke outlet ini (sesuai brief HQ-Outlet Fase 3)
            try {
                Database::get()->prepare(
                    "INSERT INTO hl_karyawan_outlet
                       (tenant_id, karyawan_id, outlet_id, assigned_by, is_active)
                     VALUES (?,?,?,?,1)"
                )->execute([$tid, $newUserId, $oid, currentUser()['id'] ?? null]);
            } catch (Throwable $e) {
                error_log('[karyawan create assign] ' . $e->getMessage());
            }
        }
        logAudit(!empty($d['id'])?'update':'create','karyawan',(!empty($d['id'])?'Edit':'Tambah').' karyawan: '.$nama);
        echo json_encode(['success'=>true]); exit;
    }

    if ($action === 'list_gaji') {
        $bulan = $_GET['bulan'] ?? date('Y-m');
        $rows  = TenantQuery::raw(
            "SELECT g.*, u.nama, u.jabatan, u.gaji_pokok as gaji_default
             FROM hl_gaji g
             JOIN hl_users u ON u.id=g.user_id AND u.tenant_id=g.tenant_id
             WHERE g.tenant_id=? AND g.outlet_id=? AND g.bulan=? ORDER BY u.nama",
            [$tid, $oid, $bulan]
        );
        echo json_encode($rows); exit;
    }

    if ($action === 'generate_gaji' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('karyawan.manage_gaji')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d     = json_decode(file_get_contents('php://input'), true);
        $bulan = $d['bulan'] ?? date('Y-m');
        // Hanya generate gaji untuk karyawan yang ditugaskan di outlet ini
        $users = TenantQuery::raw(
            "SELECT u.* FROM hl_users u
             JOIN hl_karyawan_outlet ko
               ON ko.karyawan_id=u.id AND ko.tenant_id=u.tenant_id
              AND ko.outlet_id=? AND ko.is_active=1
             WHERE u.tenant_id=? AND u.is_active=1",
            [$oid, $tid]
        );
        $db    = Database::get();
        // Jumlah outlet aktif per karyawan → split proporsional kalau multi-outlet
        $cntRows = TenantQuery::raw("SELECT karyawan_id, COUNT(DISTINCT outlet_id) c
                                       FROM hl_karyawan_outlet WHERE tenant_id=? AND is_active=1 GROUP BY karyawan_id", [$tid]);
        $outletCount = [];
        foreach ($cntRows as $row) $outletCount[(int)$row['karyawan_id']] = max(1,(int)$row['c']);

        $stmt  = $db->prepare(
            "INSERT IGNORE INTO hl_gaji (tenant_id,outlet_id,user_id,bulan,gaji_pokok,total,catatan,created_by,created_at)
             VALUES (?,?,?,?,?,?,?,?,NOW())"
        );
        foreach ($users as $u) {
            $uid2 = (int)$u['id'];
            $nOutlet = $outletCount[$uid2] ?? 1;
            $gpFull = floatval($u['gaji_pokok'] ?? 0);
            $gp = $nOutlet > 1 ? round($gpFull / $nOutlet) : $gpFull;
            $note = $nOutlet > 1 ? "Gaji di-split $nOutlet outlet (porsi 1/$nOutlet)" : null;
            $stmt->execute([$tid, $oid, $uid2, $bulan, $gp, $gp, $note, $user['id']]);
        }
        logAudit('generate_gaji','karyawan','Generate gaji bulan: '.$bulan);
        echo json_encode(['success'=>true]); exit;
    }

    if ($action === 'save_gaji' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('karyawan.manage_gaji')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d     = json_decode(file_get_contents('php://input'), true);
        $total = floatval($d['gaji_pokok']) + floatval($d['bonus'] ?? 0) - floatval($d['potongan'] ?? 0);
        TenantQuery::update('hl_gaji', [
            'gaji_pokok' => floatval($d['gaji_pokok']),
            'bonus'      => floatval($d['bonus'] ?? 0),
            'potongan'   => floatval($d['potongan'] ?? 0),
            'total'      => $total,
            'catatan'    => substr(trim($d['catatan'] ?? ''), 0, 300),
        ], 'id = ?', [intval($d['id'])]);
        logAudit('edit_gaji','karyawan','Edit slip gaji ID: '.($d['id']??''));
        echo json_encode(['success'=>true]); exit;
    }

    if ($action === 'bayar_gaji' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('karyawan.manage_gaji')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d    = json_decode(file_get_contents('php://input'), true);
        $gId  = intval($d['id']);
        TenantQuery::update('hl_gaji', ['status'=>'dibayar','dibayar_at'=>date('Y-m-d H:i:s')], 'id = ?', [$gId]);
        // Auto-insert ke kas keluar
        $g = TenantQuery::rawOne(
            "SELECT g.*, u.nama FROM hl_gaji g JOIN hl_users u ON u.id=g.user_id AND u.tenant_id=g.tenant_id
             WHERE g.id=? AND g.tenant_id=? AND g.outlet_id=?",
            [$gId, $tid, $oid]
        );
        if ($g) {
            TenantQuery::insert('hl_kas', [
                'tanggal'    => date('Y-m-d'),
                'tipe'       => 'keluar',
                'kategori'   => 'Gaji Karyawan',
                'keterangan' => 'Gaji '.$g['nama'].' bulan '.$g['bulan'],
                'jumlah'     => floatval($g['total']),
                'created_by' => $user['id'],
            ]);
        }
        logAudit('bayar_gaji','karyawan','Bayar gaji ID: '.$gId);
        echo json_encode(['success'=>true]); exit;
    }

    if ($action === 'stats') {
        $total      = TenantQuery::count('hl_users', 'is_active=1');
        $hadir      = TenantQuery::count('hl_absensi', "tanggal=CURDATE() AND status='hadir'");
        $totalGaji  = TenantQuery::raw(
            "SELECT COALESCE(SUM(total),0) as c FROM hl_gaji WHERE tenant_id=? AND outlet_id=? AND bulan=? AND status='pending'",
            [$tid, $oid, date('Y-m')]
        );
        echo json_encode(['total'=>$total,'hadir'=>$hadir,'total_gaji'=>$totalGaji[0]['c']??0]); exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
}

function localMonthStr() {
    return date('Y-m');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Karyawan'); ?>
<style>
.kartu{background:var(--white);border-radius:var(--r-lg);border:1px solid rgba(27,45,90,.07);padding:20px;transition:all .2s;position:relative}
.kartu:hover{box-shadow:var(--shadow-lg)}
.kartu-avatar{width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--navy),var(--teal-d));display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:white;font-weight:800;margin-bottom:12px}
.kartu-nama{font-size:15px;font-weight:700;color:var(--navy)}
.kartu-jabatan{font-size:12px;color:var(--gray);margin-bottom:10px}
.kartu-meta{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
.kartu-inactive{opacity:.5}
.online-dot{width:8px;height:8px;border-radius:50%;background:var(--green);display:inline-block;margin-right:4px}
.hl-tab{padding:10px 18px;font-size:14px;font-weight:600;border:none;background:transparent;color:var(--gray);cursor:pointer;border-bottom:2px solid transparent;transition:all .2s}
.hl-tab.active{color:var(--teal);border-bottom-color:var(--teal)}
.hl-tabs{display:flex;gap:4px;border-bottom:2px solid var(--light);margin-bottom:20px}
.hl-td-mono{font-family:var(--mono);font-size:13px}
.hl-td-right{text-align:right}
.hl-btn-green{background:#D1FAE5;color:#065F46}
.hl-btn-green:hover{background:var(--green);color:white}
@media(max-width:680px){.kartu{padding:16px}}
</style>
</head>
<body>
<?php renderTopbar('karyawan'); ?>
<div class="hl-main">

  <div class="hl-stat-grid-4" style="margin-bottom:20px">
    <div class="hl-stat-card teal"><div class="hl-stat-num" id="sTotal">-</div><div class="hl-stat-label">👥 Total Karyawan</div></div>
    <div class="hl-stat-card green"><div class="hl-stat-num" id="sHadir">-</div><div class="hl-stat-label">✅ Hadir Hari Ini</div></div>
    <div class="hl-stat-card navy"><div class="hl-stat-num" id="sGaji" style="font-size:1rem">-</div><div class="hl-stat-label">💰 Gaji Belum Dibayar</div></div>
    <div class="hl-stat-card purple">
      <button class="hl-btn hl-btn-primary hl-btn-full" onclick="openModal()" style="margin-top:4px">+ Tambah Karyawan</button>
    </div>
  </div>

  <div class="hl-tabs">
    <button class="hl-tab active" onclick="switchTab('daftar',this)">👥 Daftar Karyawan</button>
    <button class="hl-tab" onclick="switchTab('gaji',this)">💰 Penggajian</button>
  </div>

  <div id="tabDaftar">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px" id="karyawanGrid">
      <div class="hl-loading">⏳ Memuat...</div>
    </div>
  </div>

  <div id="tabGaji" style="display:none">
    <div class="hl-filter-collapsible">
      <button class="hl-filter-toggle-btn" id="gajiFilterBtn" onclick="toggleFilter('gajiFilter')">
        📅 Periode Gaji <span class="hl-toggle-arrow">▼</span>
      </button>
      <div class="hl-filter-bar" id="gajiFilter">
        <span class="hl-filter-label">Bulan</span>
        <input type="month" id="gajiBulan" class="hl-input" style="width:auto"/>
        <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="loadGaji()">🔍 Tampilkan</button>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="generateGaji()">⚡ Generate Slip Gaji</button>
      </div>
    </div>
    <div class="hl-card">
      <div class="hl-card-header">
        <div class="hl-card-title">💰 Slip Gaji Karyawan</div>
        <span id="gajiInfo" style="font-size:12px;color:var(--gray)"></span>
      </div>
      <div class="hl-table-wrap">
        <table class="hl-table hl-stack-mobile">
          <thead>
            <tr>
              <th>Nama</th><th>Jabatan</th>
              <th style="text-align:right">Gaji Pokok</th><th style="text-align:right">Bonus</th>
              <th style="text-align:right">Potongan</th><th style="text-align:right">Total</th>
              <th>Status</th><th>Aksi</th>
            </tr>
          </thead>
          <tbody id="gajiBody">
            <tr><td colspan="8" class="hl-loading">⏳ Pilih bulan dan klik Tampilkan</td></tr>
          </tbody>
          <tfoot id="gajiFoot" style="display:none">
            <tr>
              <td colspan="5" style="color:rgba(255,255,255,.6)">Total Penggajian</td>
              <td class="hl-td-mono hl-td-right" id="gajiTotal"></td>
              <td colspan="2"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- MODAL KARYAWAN -->
<div class="hl-modal-overlay" id="modalKary">
  <div class="hl-modal">
    <div class="hl-modal-header">
      <span class="hl-modal-title" id="karyModalTitle">➕ Tambah Karyawan</span>
      <button class="hl-modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="hl-modal-body">
      <input type="hidden" id="f_id"/>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Nama Lengkap <span class="req">*</span></label>
          <input type="text" id="f_nama" class="hl-input" placeholder="Nama karyawan"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Username <span class="req">*</span></label>
          <input type="text" id="f_username" class="hl-input" placeholder="Untuk login"/>
        </div>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Password <span style="font-weight:400;color:var(--gray)">(wajib untuk baru)</span></label>
          <input type="password" id="f_password" class="hl-input" placeholder="Kosongkan jika tidak diubah"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Role <span class="req">*</span></label>
          <select id="f_role" class="hl-input"><option value="">⏳ Memuat...</option></select>
        </div>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Jabatan</label>
          <input type="text" id="f_jabatan" class="hl-input" placeholder="Operator, Kasir, dll"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Telepon</label>
          <input type="tel" id="f_telepon" class="hl-input" placeholder="08xxxxxxxxxx"/>
        </div>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Gaji Pokok (Rp)</label>
          <input type="number" id="f_gaji" class="hl-input" placeholder="0" min="0" step="50000"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Tanggal Masuk</label>
          <input type="date" id="f_tgl_masuk" class="hl-input"/>
        </div>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Alamat</label>
        <textarea id="f_alamat" class="hl-input hl-textarea"></textarea>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Status</label>
        <select id="f_active" class="hl-input">
          <option value="1">✅ Aktif</option>
          <option value="0">⏸️ Nonaktif</option>
        </select>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeModal()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveKaryawan()">💾 Simpan</button>
    </div>
  </div>
</div>

<!-- MODAL EDIT GAJI -->
<div class="hl-modal-overlay" id="modalGaji">
  <div class="hl-modal hl-modal-sm">
    <div class="hl-modal-header">
      <span class="hl-modal-title" id="gajiModalTitle">✏️ Edit Slip Gaji</span>
      <button class="hl-modal-close" onclick="closeGajiModal()">✕</button>
    </div>
    <div class="hl-modal-body">
      <input type="hidden" id="gf_id"/>
      <div class="hl-form-group">
        <label class="hl-label">Gaji Pokok (Rp)</label>
        <input type="number" id="gf_pokok" class="hl-input" min="0" step="50000" oninput="recalcGaji()"/>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Bonus (Rp)</label>
          <input type="number" id="gf_bonus" class="hl-input" value="0" min="0" step="10000" oninput="recalcGaji()"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Potongan (Rp)</label>
          <input type="number" id="gf_potongan" class="hl-input" value="0" min="0" step="10000" oninput="recalcGaji()"/>
        </div>
      </div>
      <div style="background:linear-gradient(135deg,#0F1C3A,var(--navy));border-radius:var(--r);padding:14px;text-align:center;margin-bottom:12px">
        <div style="color:rgba(255,255,255,.5);font-size:12px;margin-bottom:4px">Total Gaji</div>
        <div style="font-family:var(--mono);font-size:1.4rem;font-weight:800;color:var(--teal)" id="gfTotal">Rp 0</div>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Catatan</label>
        <input type="text" id="gf_catatan" class="hl-input" placeholder="Keterangan tambahan..."/>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeGajiModal()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveGaji()">💾 Simpan</button>
    </div>
  </div>
</div>

<?php renderToast(); ?>
<script>
let allKaryawan = [];

function localMonthStr() {
  const d = new Date();
  return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0');
}

document.addEventListener('DOMContentLoaded', () => {
  initFilter('gajiFilter');
  loadKaryawan(); loadStats();
  document.getElementById('gajiBulan').value = localMonthStr();
});

async function loadStats() {
  const r = await fetch('karyawan.php?action=stats');
  const d = await r.json();
  document.getElementById('sTotal').textContent = d.total;
  document.getElementById('sHadir').textContent = d.hadir;
  document.getElementById('sGaji').textContent  = 'Rp '+parseFloat(d.total_gaji||0).toLocaleString('id-ID');
}

async function loadKaryawan() {
  const r = await fetch('karyawan.php?action=list');
  allKaryawan = await r.json();
  renderKaryawan();
}

function renderKaryawan() {
  const grid = document.getElementById('karyawanGrid');
  if (!allKaryawan.length) { grid.innerHTML = '<div class="hl-empty">Belum ada karyawan.</div>'; return; }
  const roleBadge = {superadmin:'hl-badge-red',admin:'hl-badge-navy',staff:'hl-badge-teal'};
  grid.innerHTML = allKaryawan.map(k => `
    <div class="kartu ${k.is_active==1?'':'kartu-inactive'}">
      <div class="kartu-avatar">${(k.nama||'?').charAt(0).toUpperCase()}</div>
      <div class="kartu-nama">
        ${k.jam_masuk_hari_ini?'<span class="online-dot"></span>':''}
        ${esc(k.nama)}
      </div>
      <div class="kartu-jabatan">${esc(k.jabatan||'-')} · @${esc(k.username)}</div>
      <div class="kartu-meta">
        <span class="hl-badge ${roleBadge[k.role]||'hl-badge-gray'}" style="font-size:10px">${esc(k.role_nama||k.role)}</span>
        ${k.telepon?`<span class="hl-badge hl-badge-gray" style="font-size:10px">${k.telepon}</span>`:''}
        ${k.hadir_bulan_ini>0?`<span class="hl-badge hl-badge-green" style="font-size:10px">${k.hadir_bulan_ini} hari hadir</span>`:''}
      </div>
      ${k.gaji_pokok>0?`<div style="font-size:12px;color:var(--gray);margin-bottom:10px">💰 Gaji: <strong style="color:var(--navy);font-family:var(--mono)">Rp ${parseFloat(k.gaji_pokok).toLocaleString('id-ID')}</strong></div>`:''}
      <div style="display:flex;gap:6px;align-items:center">
        <button class="hl-btn hl-btn-outline hl-btn-sm" style="flex:1" onclick="editKaryawan(${k.id})">✏️ Edit</button>
        ${k.tgl_masuk?`<span style="font-size:10px;color:var(--gray)">Bergabung ${fmtDate(k.tgl_masuk)}</span>`:''}
      </div>
    </div>`).join('');
}

async function loadRoleOptions() {
  const resp  = await fetch('karyawan.php?action=get_roles');
  const roles = await resp.json();
  document.getElementById('f_role').innerHTML =
    roles.map(r=>`<option value="${r.id}">${esc(String(r.nama))}${r.deskripsi?' — '+esc(String(r.deskripsi)):''}</option>`).join('');
}

async function openModal(data=null) {
  await loadRoleOptions();
  document.getElementById('f_id').value        = data?.id||'';
  document.getElementById('f_nama').value      = data?.nama||'';
  document.getElementById('f_username').value  = data?.username||'';
  document.getElementById('f_password').value  = '';
  document.getElementById('f_role').value      = data?.role_id||data?.role||'';
  document.getElementById('f_jabatan').value   = data?.jabatan||'';
  document.getElementById('f_telepon').value   = data?.telepon||'';
  document.getElementById('f_gaji').value      = data?.gaji_pokok||'';
  document.getElementById('f_tgl_masuk').value = data?.tgl_masuk||'';
  document.getElementById('f_alamat').value    = data?.alamat||'';
  document.getElementById('f_active').value    = data?.is_active??1;
  document.getElementById('karyModalTitle').textContent = data?'✏️ Edit Karyawan':'➕ Tambah Karyawan';
  document.getElementById('modalKary').classList.add('open');
}
function editKaryawan(id) { openModal(allKaryawan.find(k=>k.id==id)); }
function closeModal() { document.getElementById('modalKary').classList.remove('open'); }

async function saveKaryawan() {
  const nama = document.getElementById('f_nama').value.trim();
  const username = document.getElementById('f_username').value.trim();
  if (!nama)     { showToast('⚠️ Nama wajib diisi','error'); return; }
  if (!username) { showToast('⚠️ Username wajib diisi','error'); return; }
  const r = await fetch('karyawan.php?action=save', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({
      id:document.getElementById('f_id').value, nama, username,
      password:   document.getElementById('f_password').value,
      role:       document.getElementById('f_role').value,
      jabatan:    document.getElementById('f_jabatan').value,
      telepon:    document.getElementById('f_telepon').value,
      gaji_pokok: document.getElementById('f_gaji').value||0,
      tgl_masuk:  document.getElementById('f_tgl_masuk').value,
      alamat:     document.getElementById('f_alamat').value,
      is_active:  document.getElementById('f_active').value,
    })
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Karyawan disimpan!','success'); closeModal(); loadKaryawan(); loadStats(); }
  else showToast('❌ '+(d.error||'Gagal'),'error');
}

function switchTab(name, el) {
  document.getElementById('tabDaftar').style.display = name==='daftar'?'block':'none';
  document.getElementById('tabGaji').style.display   = name==='gaji'?'block':'none';
  document.querySelectorAll('.hl-tab').forEach(b=>b.classList.remove('active'));
  el.classList.add('active');
  if (name==='gaji') loadGaji();
}

async function generateGaji() {
  const bulan = document.getElementById('gajiBulan').value;
  if (!bulan) return;
  if (!confirm('Generate slip gaji untuk semua karyawan bulan '+bulan+'?')) return;
  const r = await fetch('karyawan.php?action=generate_gaji',{
    method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body:JSON.stringify({bulan})
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Slip gaji berhasil digenerate!','success'); loadGaji(); loadStats(); }
}

async function loadGaji() {
  const bulan = document.getElementById('gajiBulan').value;
  document.getElementById('gajiBody').innerHTML = '<tr><td colspan="8" class="hl-loading">⏳ Memuat...</td></tr>';
  const r = await fetch('karyawan.php?action=list_gaji&bulan='+bulan);
  const d = await r.json();
  if (!d.length) {
    document.getElementById('gajiBody').innerHTML = '<tr><td colspan="8" class="hl-empty">Belum ada slip gaji. Klik "Generate Slip Gaji" dulu.</td></tr>';
    document.getElementById('gajiFoot').style.display='none'; return;
  }
  let totalGaji = 0;
  document.getElementById('gajiBody').innerHTML = d.map(g => {
    totalGaji += parseFloat(g.total||0);
    return `<tr>
      <td data-lbl="Nama" style="font-weight:600;color:var(--navy)">${esc(g.nama)}</td>
      <td data-lbl="Jabatan" style="font-size:12px;color:var(--gray)">${esc(g.jabatan||'-')}</td>
      <td data-lbl="Pokok" class="hl-td-mono hl-td-right">Rp ${parseFloat(g.gaji_pokok||0).toLocaleString('id-ID')}</td>
      <td data-lbl="Bonus" class="hl-td-mono hl-td-right" style="color:var(--green)">+Rp ${parseFloat(g.bonus||0).toLocaleString('id-ID')}</td>
      <td data-lbl="Potongan" class="hl-td-mono hl-td-right" style="color:#EF4444">-Rp ${parseFloat(g.potongan||0).toLocaleString('id-ID')}</td>
      <td data-lbl="Total" class="hl-td-mono hl-td-right" style="font-weight:800;color:var(--navy)">Rp ${parseFloat(g.total||0).toLocaleString('id-ID')}</td>
      <td data-lbl="Status"><span class="hl-badge ${g.status==='dibayar'?'hl-badge-lunas':'hl-badge-dp'}">${g.status==='dibayar'?'✅ Dibayar':'⏳ Pending'}</span></td>
      <td>
        <div style="display:flex;gap:4px">
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick='editGaji(${JSON.stringify(g)})'>✏️ Edit</button>
          ${g.status==='pending'?`<button class="hl-btn hl-btn-sm hl-btn-green" onclick="bayarGaji(${g.id})">💰 Bayar</button>`:''}
        </div>
      </td>
    </tr>`;
  }).join('');
  document.getElementById('gajiFoot').style.display='';
  document.getElementById('gajiTotal').textContent = 'Rp '+totalGaji.toLocaleString('id-ID');
  document.getElementById('gajiInfo').textContent  = d.length+' karyawan · '+bulan;
}

function editGaji(g) {
  document.getElementById('gf_id').value       = g.id;
  document.getElementById('gf_pokok').value    = g.gaji_pokok;
  document.getElementById('gf_bonus').value    = g.bonus||0;
  document.getElementById('gf_potongan').value = g.potongan||0;
  document.getElementById('gf_catatan').value  = g.catatan||'';
  document.getElementById('gajiModalTitle').textContent = '✏️ Edit Gaji — '+g.nama;
  recalcGaji();
  document.getElementById('modalGaji').classList.add('open');
}
function closeGajiModal() { document.getElementById('modalGaji').classList.remove('open'); }
function recalcGaji() {
  const total = (parseFloat(document.getElementById('gf_pokok').value)||0)
              + (parseFloat(document.getElementById('gf_bonus').value)||0)
              - (parseFloat(document.getElementById('gf_potongan').value)||0);
  document.getElementById('gfTotal').textContent = 'Rp '+Math.max(total,0).toLocaleString('id-ID');
}
async function saveGaji() {
  const r = await fetch('karyawan.php?action=save_gaji',{
    method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body:JSON.stringify({id:document.getElementById('gf_id').value,gaji_pokok:document.getElementById('gf_pokok').value,bonus:document.getElementById('gf_bonus').value,potongan:document.getElementById('gf_potongan').value,catatan:document.getElementById('gf_catatan').value})
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Gaji disimpan!','success'); closeGajiModal(); loadGaji(); loadStats(); }
}
async function bayarGaji(id) {
  if (!confirm('Tandai gaji ini sudah dibayar? Akan otomatis tercatat di kas keluar.')) return;
  const r = await fetch('karyawan.php?action=bayar_gaji',{
    method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body:JSON.stringify({id})
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Gaji dibayar & tercatat di kas!','success'); loadGaji(); loadStats(); }
}

function fmtDate(d){if(!d)return'-';return new Date(d+'T00:00:00').toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
</script>
</body>
</html>
