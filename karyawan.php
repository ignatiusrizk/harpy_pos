<?php
$activePage = 'karyawan';
require_once 'auth.php';
require_once 'components.php';
requireLogin();
$user = currentUser();
if (isset($_GET['logout'])) doLogout();

requirePermission('karyawan.view');

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $pdo = getDB();

    // Auto create gaji table
    $pdo->exec("CREATE TABLE IF NOT EXISTS hl_gaji (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        bulan      VARCHAR(7) NOT NULL,
        gaji_pokok DECIMAL(12,2) DEFAULT 0,
        bonus      DECIMAL(12,2) DEFAULT 0,
        potongan   DECIMAL(12,2) DEFAULT 0,
        total      DECIMAL(12,2) DEFAULT 0,
        status     ENUM('pending','dibayar') DEFAULT 'pending',
        catatan    TEXT,
        dibayar_at TIMESTAMP NULL,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_bulan (user_id, bulan),
        FOREIGN KEY (user_id) REFERENCES hl_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Tambah kolom gaji_pokok ke hl_users jika belum ada
    try {
        $pdo->exec("ALTER TABLE hl_users ADD COLUMN gaji_pokok DECIMAL(12,2) DEFAULT 0");
        $pdo->exec("ALTER TABLE hl_users ADD COLUMN jabatan VARCHAR(100) DEFAULT NULL");
        $pdo->exec("ALTER TABLE hl_users ADD COLUMN telepon VARCHAR(20) DEFAULT NULL");
        $pdo->exec("ALTER TABLE hl_users ADD COLUMN alamat TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE hl_users ADD COLUMN tgl_masuk DATE DEFAULT NULL");
    } catch(Exception $e) {} // Ignore jika kolom sudah ada

    // GET ROLES dari hl_roles
    if ($action === 'get_roles') {
        try {
            $rows = $pdo->query("SELECT id, nama, deskripsi FROM hl_roles WHERE is_active=1 ORDER BY nama")->fetchAll();
            echo json_encode($rows);
        } catch(Exception $e) {
            // Fallback jika tabel belum ada
            echo json_encode([
                ['id'=>'superadmin','nama'=>'Owner','deskripsi'=>'Akses penuh'],
                ['id'=>'admin','nama'=>'Manager','deskripsi'=>'Manager operasional'],
                ['id'=>'staff','nama'=>'Kasir','deskripsi'=>'Kasir/Staff'],
            ]);
        }
        exit;
    }

    if ($action === 'list') {
        $rows = $pdo->query("SELECT u.*,
            COALESCE(r.nama, u.role) as role_nama,
            (SELECT COUNT(*) FROM hl_absensi WHERE user_id=u.id AND MONTH(tanggal)=MONTH(CURDATE()) AND status='hadir') as hadir_bulan_ini,
            (SELECT jam_masuk FROM hl_absensi WHERE user_id=u.id AND tanggal=CURDATE()) as jam_masuk_hari_ini
            FROM hl_users u
            LEFT JOIN hl_roles r ON r.id = u.role_id
            ORDER BY u.nama")->fetchAll();
        echo json_encode($rows); exit;
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD']==='POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        if (!empty($d['id']) && !hasPermission('karyawan.edit')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        if (empty($d['id']) && !hasPermission('karyawan.create')) { echo json_encode(['error'=>'Akses ditolak']); exit; }

        if (!empty($d['id'])) {
            // Edit — jangan update password jika kosong
            if (!empty($d['password'])) {
                $hash = password_hash($d['password'], PASSWORD_DEFAULT);
                // Resolve role_id dan role lama dari pilihan
            $roleId   = is_numeric($d['role']) ? intval($d['role']) : null;
            $roleLama = 'staff';
            if ($roleId) {
                $rStmt = $pdo->prepare("SELECT nama FROM hl_roles WHERE id=?");
                $rStmt->execute([$roleId]);
                $rNama = $rStmt->fetchColumn();
                if (in_array(strtolower($rNama), ['owner'])) $roleLama = 'superadmin';
                elseif (in_array(strtolower($rNama), ['manager','admin'])) $roleLama = 'admin';
                else $roleLama = 'staff';
            } else {
                $roleLama = $d['role'];
            }
            $pdo->prepare("UPDATE hl_users SET nama=?,username=?,role=?,role_id=?,password=?,gaji_pokok=?,jabatan=?,telepon=?,alamat=?,tgl_masuk=?,is_active=? WHERE id=?")
                    ->execute([$d['nama'],$d['username'],$roleLama,$roleId,$hash,$d['gaji_pokok'],$d['jabatan'],$d['telepon'],$d['alamat'],$d['tgl_masuk']?:null,$d['is_active'],$d['id']]);
            } else {
                $pdo->prepare("UPDATE hl_users SET nama=?,username=?,role=?,role_id=?,gaji_pokok=?,jabatan=?,telepon=?,alamat=?,tgl_masuk=?,is_active=? WHERE id=?")
                    ->execute([$d['nama'],$d['username'],$roleLama,$roleId,$d['gaji_pokok'],$d['jabatan'],$d['telepon'],$d['alamat'],$d['tgl_masuk']?:null,$d['is_active'],$d['id']]);
            }
        } else {
            if (empty($d['password'])) { echo json_encode(['error'=>'Password wajib diisi untuk karyawan baru']); exit; }
            // Cek username duplikat
            $dup = $pdo->prepare("SELECT id FROM hl_users WHERE username=?");
            $dup->execute([$d['username']]);
            if ($dup->fetch()) { echo json_encode(['error'=>'Username sudah digunakan']); exit; }
            $hash     = password_hash($d['password'], PASSWORD_DEFAULT);
            $roleId2  = is_numeric($d['role']) ? intval($d['role']) : null;
            $roleLama2 = 'staff';
            if ($roleId2) {
                $rStmt2 = $pdo->prepare("SELECT nama FROM hl_roles WHERE id=?");
                $rStmt2->execute([$roleId2]);
                $rNama2 = $rStmt2->fetchColumn();
                if (in_array(strtolower($rNama2), ['owner'])) $roleLama2 = 'superadmin';
                elseif (in_array(strtolower($rNama2), ['manager','admin'])) $roleLama2 = 'admin';
                else $roleLama2 = 'staff';
            } else {
                $roleLama2 = $d['role'];
            }
            $pdo->prepare("INSERT INTO hl_users (nama,username,password,role,role_id,gaji_pokok,jabatan,telepon,alamat,tgl_masuk,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,1)")
                ->execute([$d['nama'],$d['username'],$hash,$roleLama2,$roleId2,$d['gaji_pokok'],$d['jabatan'],$d['telepon'],$d['alamat'],$d['tgl_masuk']?:null]);
        }
        echo json_encode(['success'=>true]); exit;
    }

    if ($action === 'list_gaji') {
        $bulan = $_GET['bulan'] ?? date('Y-m');
        $rows  = $pdo->prepare("SELECT g.*,u.nama,u.jabatan,u.gaji_pokok as gaji_default
            FROM hl_gaji g JOIN hl_users u ON u.id=g.user_id
            WHERE g.bulan=? ORDER BY u.nama");
        $rows->execute([$bulan]);
        echo json_encode($rows->fetchAll()); exit;
    }

    if ($action === 'generate_gaji' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('karyawan.manage_gaji')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        $d     = json_decode(file_get_contents('php://input'), true);
        $bulan = $d['bulan'] ?? date('Y-m');
        $users = $pdo->query("SELECT * FROM hl_users WHERE is_active=1")->fetchAll();
        $stmt  = $pdo->prepare("INSERT IGNORE INTO hl_gaji (user_id,bulan,gaji_pokok,total,created_by) VALUES (?,?,?,?,?)");
        foreach ($users as $u) {
            $gp = floatval($u['gaji_pokok']??0);
            $stmt->execute([$u['id'], $bulan, $gp, $gp, $user['id']]);
        }
        logAudit('generate_gaji','karyawan','Generate gaji bulan: '.($d['bulan']??''));
        echo json_encode(['success'=>true]); exit;
    }

    if ($action === 'save_gaji' && $_SERVER['REQUEST_METHOD']==='POST') {
        $d     = json_decode(file_get_contents('php://input'), true);
        $total = floatval($d['gaji_pokok']) + floatval($d['bonus']) - floatval($d['potongan']);
        $pdo->prepare("UPDATE hl_gaji SET gaji_pokok=?,bonus=?,potongan=?,total=?,catatan=? WHERE id=?")
            ->execute([$d['gaji_pokok'],$d['bonus'],$d['potongan'],$total,$d['catatan'],$d['id']]);
        logAudit('edit_gaji','karyawan','Edit slip gaji ID: '.($d['id']??''));
        echo json_encode(['success'=>true]); exit;
    }

    if ($action === 'bayar_gaji' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('karyawan.manage_gaji')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        $d = json_decode(file_get_contents('php://input'), true);
        $pdo->prepare("UPDATE hl_gaji SET status='dibayar',dibayar_at=NOW() WHERE id=?")
            ->execute([$d['id']]);
        // Insert ke kas
        $gaji = $pdo->prepare("SELECT g.*,u.nama FROM hl_gaji g JOIN hl_users u ON u.id=g.user_id WHERE g.id=?");
        $gaji->execute([$d['id']]);
        $g = $gaji->fetch();
        if ($g) {
            try {
                $pdo->prepare("INSERT INTO hl_kas (tanggal,tipe,kategori,keterangan,jumlah,created_by) VALUES (?,?,?,?,?,?)")
                    ->execute([date('Y-m-d'),'keluar','Gaji Karyawan','Gaji '.$g['nama'].' bulan '.$g['bulan'],floatval($g['total']),$user['id']]);
            } catch(Exception $e) {}
        }
        logAudit('bayar_gaji','karyawan','Bayar gaji ID: '.($d['id']??''), $d['id']??null);
        echo json_encode(['success'=>true]); exit;
    }

    if ($action === 'stats') {
        $total  = $pdo->query("SELECT COUNT(*) FROM hl_users WHERE is_active=1")->fetchColumn();
        $hadir  = $pdo->query("SELECT COUNT(*) FROM hl_absensi WHERE tanggal=CURDATE() AND status='hadir'")->fetchColumn();
        $total_gaji = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM hl_gaji WHERE bulan=? AND status='pending'");
        $total_gaji->execute([date('Y-m')]);
        echo json_encode(['total'=>$total,'hadir'=>$hadir,'total_gaji'=>$total_gaji->fetchColumn()]); exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
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
</style>
</head>
<body>
<?php renderTopbar('karyawan'); ?>
<div class="hl-main">

  <div class="hl-stat-grid-4" style="margin-bottom:20px">
    <div class="hl-stat-card teal"><div class="hl-stat-num" id="sTotal">-</div><div class="hl-stat-label">👥 Total Karyawan</div></div>
    <div class="hl-stat-card green"><div class="hl-stat-num" id="sHadir">-</div><div class="hl-stat-label">✅ Hadir Hari Ini</div></div>
    <div class="hl-stat-card red"><div class="hl-stat-num" id="sGaji" style="font-size:1rem">-</div><div class="hl-stat-label">💰 Gaji Belum Dibayar</div></div>
    <div class="hl-stat-card purple">
      <button class="hl-btn hl-btn-primary hl-btn-full" onclick="openModal()" style="margin-top:4px">+ Tambah Karyawan</button>
    </div>
  </div>

  <!-- TABS -->
  <div class="hl-tabs">
    <button class="hl-tab active" onclick="switchTab('daftar',this)">👥 Daftar Karyawan</button>
    <button class="hl-tab" onclick="switchTab('gaji',this)">💰 Penggajian</button>
  </div>

  <!-- TAB DAFTAR -->
  <div id="tabDaftar">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px" id="karyawanGrid">
      <div class="hl-loading">⏳ Memuat...</div>
    </div>
  </div>

  <!-- TAB GAJI -->
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
        <table class="hl-table">
          <thead>
            <tr>
              <th>Nama</th>
              <th>Jabatan</th>
              <th style="text-align:right">Gaji Pokok</th>
              <th style="text-align:right">Bonus</th>
              <th style="text-align:right">Potongan</th>
              <th style="text-align:right">Total</th>
              <th>Status</th>
              <th>Aksi</th>
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
          <label class="hl-label">Password <span id="pwHint" style="font-weight:400;color:var(--gray)">(wajib untuk baru)</span></label>
          <input type="password" id="f_password" class="hl-input" placeholder="Kosongkan jika tidak diubah"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Role <span class="req">*</span></label>
          <select id="f_role" class="hl-input">
            <option value="">⏳ Memuat...</option>
          </select>
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
        <textarea id="f_alamat" class="hl-input hl-textarea" placeholder="Alamat lengkap..."></textarea>
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
      <div style="background:linear-gradient(135deg,var(--navy-d),var(--navy));border-radius:var(--r);padding:14px;text-align:center;margin-bottom:12px">
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
  document.getElementById('sGaji').textContent  = 'Rp ' + parseFloat(d.total_gaji).toLocaleString('id-ID');
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
  grid.innerHTML = allKaryawan.map(k => {
    const roleDisplay = k.role_nama || k.role;
    return `
    <div class="kartu ${k.is_active==1?'':'kartu-inactive'}">
      <div class="kartu-avatar">${(k.nama||'?').charAt(0).toUpperCase()}</div>
      <div class="kartu-nama">
        ${k.jam_masuk_hari_ini ? '<span class="online-dot"></span>' : ''}
        ${esc(k.nama)}
      </div>
      <div class="kartu-jabatan">${esc(k.jabatan||'-')} · @${esc(k.username)}</div>
      <div class="kartu-meta">
        <span class="hl-badge ${roleBadge[k.role]||'hl-badge-gray'}" style="font-size:10px">${esc(roleDisplay)}</span>
        ${k.telepon?`<span class="hl-badge hl-badge-gray" style="font-size:10px">${k.telepon}</span>`:''}
        ${k.hadir_bulan_ini>0?`<span class="hl-badge hl-badge-green" style="font-size:10px">${k.hadir_bulan_ini} hari hadir</span>`:''}
      </div>
      ${k.gaji_pokok>0?`<div style="font-size:12px;color:var(--gray);margin-bottom:10px">💰 Gaji: <strong style="color:var(--navy);font-family:var(--mono)">Rp ${parseFloat(k.gaji_pokok).toLocaleString('id-ID')}</strong></div>`:''}
      <div style="display:flex;gap:6px">
        <button class="hl-btn hl-btn-outline hl-btn-sm" style="flex:1" onclick="editKaryawan(${k.id})">✏️ Edit</button>
        ${k.tgl_masuk?`<span style="font-size:10px;color:var(--gray);align-self:center">Bergabung ${fmtDate(k.tgl_masuk)}</span>`:''}
      </div>
    </div>`;
  }).join('');
}

async function loadRoleOptions() {
  const resp  = await fetch('karyawan.php?action=get_roles');
  const roles = await resp.json();
  const sel   = document.getElementById('f_role');
  if (!sel) return;
  sel.innerHTML = roles.map(role =>
    `<option value="${role.id}">${esc(String(role.nama))}${role.deskripsi ? ' — ' + esc(String(role.deskripsi)) : ''}</option>`
  ).join('');
}

async function openModal(data=null) {
  // Load role options dulu
  await loadRoleOptions();

  document.getElementById('f_id').value        = data?.id||'';
  document.getElementById('f_nama').value      = data?.nama||'';
  document.getElementById('f_username').value  = data?.username||'';
  document.getElementById('f_password').value  = '';
  document.getElementById('f_role').value      = data?.role_id || data?.role || '';
  document.getElementById('f_jabatan').value   = data?.jabatan||'';
  document.getElementById('f_telepon').value   = data?.telepon||'';
  document.getElementById('f_gaji').value      = data?.gaji_pokok||'';
  document.getElementById('f_tgl_masuk').value = data?.tgl_masuk||'';
  document.getElementById('f_alamat').value    = data?.alamat||'';
  document.getElementById('f_active').value    = data?.is_active??1;
  document.getElementById('karyModalTitle').textContent = data ? '✏️ Edit Karyawan' : '➕ Tambah Karyawan';
  document.getElementById('modalKary').classList.add('open');
}
function editKaryawan(id) { openModal(allKaryawan.find(k=>k.id==id)); }
function closeModal() { document.getElementById('modalKary').classList.remove('open'); }

async function saveKaryawan() {
  const nama     = document.getElementById('f_nama').value.trim();
  const username = document.getElementById('f_username').value.trim();
  if (!nama)     { showToast('⚠️ Nama wajib diisi','error'); return; }
  if (!username) { showToast('⚠️ Username wajib diisi','error'); return; }

  const r = await fetch('karyawan.php?action=save', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      id:         document.getElementById('f_id').value,
      nama, username,
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

// ── GAJI ──────────────────────────────────────────────
function switchTab(name, el) {
  document.getElementById('tabDaftar').style.display = name==='daftar' ? 'block' : 'none';
  document.getElementById('tabGaji').style.display   = name==='gaji'   ? 'block' : 'none';
  document.querySelectorAll('.hl-tab').forEach(b=>b.classList.remove('active'));
  el.classList.add('active');
  if (name==='gaji') loadGaji();
}

async function generateGaji() {
  const bulan = document.getElementById('gajiBulan').value;
  if (!bulan) return;
  if (!confirm('Generate slip gaji untuk semua karyawan bulan ' + bulan + '?')) return;
  const r = await fetch('karyawan.php?action=generate_gaji', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({bulan})
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
    document.getElementById('gajiFoot').style.display = 'none';
    return;
  }

  let totalGaji = 0;
  document.getElementById('gajiBody').innerHTML = d.map(g => {
    totalGaji += parseFloat(g.total||0);
    return `<tr>
      <td style="font-weight:600;color:var(--navy)">${esc(g.nama)}</td>
      <td style="font-size:12px;color:var(--gray)">${esc(g.jabatan||'-')}</td>
      <td class="hl-td-mono hl-td-right">Rp ${parseFloat(g.gaji_pokok).toLocaleString('id-ID')}</td>
      <td class="hl-td-mono hl-td-right" style="color:var(--green)">+Rp ${parseFloat(g.bonus||0).toLocaleString('id-ID')}</td>
      <td class="hl-td-mono hl-td-right" style="color:var(--red)">-Rp ${parseFloat(g.potongan||0).toLocaleString('id-ID')}</td>
      <td class="hl-td-mono hl-td-right" style="font-weight:800;color:var(--navy)">Rp ${parseFloat(g.total||0).toLocaleString('id-ID')}</td>
      <td><span class="hl-badge ${g.status==='dibayar'?'hl-badge-green':'hl-badge-dp'}">${g.status==='dibayar'?'✅ Dibayar':'⏳ Pending'}</span></td>
      <td>
        <div style="display:flex;gap:4px">
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="editGaji(${JSON.stringify(g).replace(/"/g,'&quot;')})">✏️</button>
          ${g.status==='pending'?`<button class="hl-btn hl-btn-green hl-btn-sm" onclick="bayarGaji(${g.id})">💰 Bayar</button>`:''}
        </div>
      </td>
    </tr>`;
  }).join('');

  document.getElementById('gajiFoot').style.display = '';
  document.getElementById('gajiTotal').textContent = 'Rp ' + totalGaji.toLocaleString('id-ID');
  document.getElementById('gajiInfo').textContent  = d.length + ' karyawan · ' + bulan;
}

function editGaji(g) {
  document.getElementById('gf_id').value       = g.id;
  document.getElementById('gf_pokok').value    = g.gaji_pokok;
  document.getElementById('gf_bonus').value    = g.bonus||0;
  document.getElementById('gf_potongan').value = g.potongan||0;
  document.getElementById('gf_catatan').value  = g.catatan||'';
  document.getElementById('gajiModalTitle').textContent = '✏️ Edit Gaji — ' + g.nama;
  recalcGaji();
  document.getElementById('modalGaji').classList.add('open');
}
function closeGajiModal() { document.getElementById('modalGaji').classList.remove('open'); }
function recalcGaji() {
  const total = (parseFloat(document.getElementById('gf_pokok').value)||0)
              + (parseFloat(document.getElementById('gf_bonus').value)||0)
              - (parseFloat(document.getElementById('gf_potongan').value)||0);
  document.getElementById('gfTotal').textContent = 'Rp ' + Math.max(total,0).toLocaleString('id-ID');
}
async function saveGaji() {
  const r = await fetch('karyawan.php?action=save_gaji', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      id:        document.getElementById('gf_id').value,
      gaji_pokok:document.getElementById('gf_pokok').value,
      bonus:     document.getElementById('gf_bonus').value,
      potongan:  document.getElementById('gf_potongan').value,
      catatan:   document.getElementById('gf_catatan').value,
    })
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Gaji disimpan!','success'); closeGajiModal(); loadGaji(); loadStats(); }
}
async function bayarGaji(id) {
  if (!confirm('Tandai gaji ini sudah dibayar? Akan otomatis tercatat di kas keluar.')) return;
  const r = await fetch('karyawan.php?action=bayar_gaji', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({id})
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Gaji dibayar & tercatat di kas!','success'); loadGaji(); loadStats(); }
}

function fmtDate(d){if(!d)return'-';return new Date(d+'T00:00:00').toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function showToast(msg,type='success'){const t=document.getElementById('toast');t.textContent=msg;t.className='hl-toast '+type+' show';setTimeout(()=>t.className='hl-toast',3500)}
</script>
</body>
</html>
