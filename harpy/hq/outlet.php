<?php
// ══════════════════════════════════════════════════════
// hq/outlet.php — Manajemen Outlet (HQ View)
// Brief HQ-Outlet Section 4.6
//
// Bedanya dengan select-outlet.php:
//   - select-outlet.php hanya untuk PILIH outlet aktif saat login
//   - halaman ini untuk KELOLA outlet (edit data, topup, masuk)
//
// Fitur:
//   - List card semua outlet milik tenant (termasuk yang closed)
//   - Edit data: nama, alamat, kota, telepon, jam operasional
//   - Tambah outlet baru → link ke add-outlet.php
//   - Masuk outlet → switch-outlet.php
//   - Coin topup (per outlet, mode per_outlet) → WA placeholder
//   - Set main outlet (is_main flag)
//   - View lifecycle info (trial/grace dates)
// ══════════════════════════════════════════════════════

$activePage = 'hq-outlet';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];
$uid  = (int)($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? '';

function logOutletAction(PDO $db, int $tid, int $uid, string $act, int $oid, string $detail): void {
    try {
        $db->prepare("INSERT INTO superadmin_logs (action, target_type, target_id, details, created_at)
                      VALUES (?,'outlet',?,?,NOW())")
           ->execute([$act, $oid, json_encode(['tenant_id'=>$tid,'by'=>$uid,'detail'=>$detail])]);
    } catch (Throwable) {}
}

// Cek apakah kolom operating_hours sudah ada
$hasOpHours = true;
try { $db->query("SELECT operating_hours FROM outlets LIMIT 1"); }
catch (Throwable) { $hasOpHours = false; }

// Cek kolom jam_buka (patokan telat absensi)
$hasJamBuka = true;
try { $db->query("SELECT jam_buka FROM outlets LIMIT 1"); }
catch (Throwable) { $hasJamBuka = false; }

// Cek kolom target omset
$hasTarget = true;
try { $db->query("SELECT target_omset_harian FROM outlets LIMIT 1"); }
catch (Throwable) { $hasTarget = false; }

// ── AJAX actions ──────────────────────────────────────
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'list') {
        $stmt = $db->prepare(
            "SELECT * FROM outlets WHERE tenant_id=?
              ORDER BY status='closed' ASC, is_main DESC, nama_outlet ASC"
        );
        $stmt->execute([$tid]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$o) {
            $oid = (int)$o['id'];
            try {
                $s = $db->prepare("SELECT COALESCE(SUM(total),0) AS s, COUNT(*) AS c
                                     FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND DATE_FORMAT(tanggal,'%Y-%m')=?");
                $s->execute([$tid, $oid, date('Y-m')]);
                $r = $s->fetch();
                $o['omset_month'] = (int)$r['s'];
                $o['order_month'] = (int)$r['c'];
            } catch (Throwable) { $o['omset_month']=0; $o['order_month']=0; }

            try {
                $s = $db->prepare("SELECT COUNT(DISTINCT karyawan_id) FROM hl_karyawan_outlet
                                     WHERE tenant_id=? AND outlet_id=? AND is_active=1");
                $s->execute([$tid, $oid]);
                $o['karyawan_count'] = (int)$s->fetchColumn();
            } catch (Throwable) { $o['karyawan_count'] = 0; }
        }
        unset($o);
        echo json_encode($rows); exit;
    }

    if ($action === 'detail') {
        $oid = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM outlets WHERE id=? AND tenant_id=? LIMIT 1");
        $stmt->execute([$oid, $tid]);
        $o = $stmt->fetch();
        if (!$o) { echo json_encode(['error'=>'Outlet tidak ditemukan']); exit; }
        echo json_encode(['outlet'=>$o, 'has_op_hours'=>$hasOpHours]); exit;
    }

    if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        verifyCsrf();

        $oid     = (int)($d['id'] ?? 0);
        $nama    = substr(trim(strip_tags($d['nama_outlet'] ?? '')), 0, 100);
        $alamat  = substr(trim(strip_tags($d['alamat'] ?? '')), 0, 500);
        $kota    = substr(trim(strip_tags($d['kota'] ?? '')), 0, 100);
        $telepon = substr(preg_replace('/[^0-9+\-\s]/', '', $d['telepon'] ?? ''), 0, 20);
        $opHours = substr(trim(strip_tags($d['operating_hours'] ?? '')), 0, 100);
        $jamBuka = preg_match('/^\d{2}:\d{2}$/', $d['jam_buka'] ?? '') ? $d['jam_buka'].':00' : null;
        $targetHarian  = max(0, (int)($d['target_omset_harian']  ?? 0));
        $targetBulanan = max(0, (int)($d['target_omset_bulanan'] ?? 0));
        $setMain = !empty($d['is_main']);

        if (!$oid)  { echo json_encode(['error'=>'ID outlet invalid']); exit; }
        if (!$nama) { echo json_encode(['error'=>'Nama outlet wajib diisi']); exit; }

        // Validasi outlet milik tenant
        $v = $db->prepare("SELECT status FROM outlets WHERE id=? AND tenant_id=? LIMIT 1");
        $v->execute([$oid, $tid]);
        $stat = $v->fetchColumn();
        if (!$stat) { echo json_encode(['error'=>'Outlet bukan milik akun ini']); exit; }
        if ($stat === 'closed') { echo json_encode(['error'=>'Outlet sudah closed, tidak bisa di-edit']); exit; }

        $db->beginTransaction();
        try {
            // Update outlet
            $cols = ['nama_outlet=?', 'alamat=?', 'kota=?', 'telepon=?'];
            $args = [$nama, $alamat ?: null, $kota ?: null, $telepon ?: null];
            if ($hasOpHours) {
                $cols[] = 'operating_hours=?';
                $args[] = $opHours ?: null;
            }
            if ($hasJamBuka && $jamBuka) {
                $cols[] = 'jam_buka=?';
                $args[] = $jamBuka;
            }
            if ($hasTarget) {
                $cols[] = 'target_omset_harian=?';  $args[] = $targetHarian;
                $cols[] = 'target_omset_bulanan=?'; $args[] = $targetBulanan;
            }
            $args[] = $oid;
            $args[] = $tid;
            $db->prepare("UPDATE outlets SET " . implode(',', $cols) . " WHERE id=? AND tenant_id=?")
               ->execute($args);

            // Toggle is_main: kalau set main, unset main lain
            if ($setMain) {
                $db->prepare("UPDATE outlets SET is_main=0 WHERE tenant_id=? AND id!=?")->execute([$tid, $oid]);
                $db->prepare("UPDATE outlets SET is_main=1 WHERE id=? AND tenant_id=?")->execute([$oid, $tid]);
            }
            $db->commit();

            logOutletAction($db, $tid, $uid, 'update_outlet', $oid, "nama=$nama setMain=" . ($setMain?'1':'0'));
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[hq outlet update] '.$e->getMessage());
            echo json_encode(['error'=>'Gagal update: '.$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error'=>'Unknown action']); exit;
}

$tenantNm   = $hqTenant['nama_outlet'] ?? '-';
$ownerNama  = $hqUser['nama'] ?? 'Owner';
$coinMode   = $hqTenant['coin_mode'] ?? 'shared';
$tenantCoin = (int)($hqTenant['coin_balance'] ?? 0);
$csrf       = getCsrfToken();
$supportWa  = '6281234567890';
?>
<?php
$pageTitle  = 'Kelola Outlet';
$activePage = 'hq-outlet';
require __DIR__ . '/_layout_open.php';
?>
<style>
  .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:12px}
  h1{font-size:1.4rem;font-weight:800;color:#0F1C3A}
  h1 small{display:block;font-size:13px;font-weight:400;color:#6B7280;margin-top:2px}

  .btn{padding:10px 18px;border-radius:9px;font-weight:700;font-size:13px;
       text-decoration:none;display:inline-flex;align-items:center;gap:6px;border:none;cursor:pointer;font-family:inherit}
  .btn-primary{background:#35E8D5;color:#0F1C3A}
  .btn-light{background:#F3F4F6;color:#0F1C3A;border:1px solid #E5E7EB}
  .btn-dark{background:#0F1C3A;color:#fff}
  .btn-wa{background:#25D366;color:#fff}
  .btn-sm{padding:6px 12px;font-size:11px}

  .summary-bar{background:#fff;border-radius:12px;padding:14px 18px;margin-bottom:16px;
               display:flex;gap:18px;align-items:center;flex-wrap:wrap;box-shadow:0 1px 6px rgba(0,0,0,.05)}
  .summary-item{font-size:13px;color:#6B7280}
  .summary-item strong{color:#0F1C3A;font-weight:800}
  .mode-pill{background:rgba(53,232,213,.15);border:1px solid rgba(53,232,213,.3);
             color:#0891B2;font-size:11px;font-weight:700;padding:3px 10px;border-radius:100px}

  .alert{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:14px}
  .alert.warn{background:#FEF3C7;color:#92400E;border:1px solid #FDE68A}

  .outlet-list{display:grid;grid-template-columns:1fr;gap:12px}
  .ocard{background:#fff;border-radius:14px;padding:20px 24px;box-shadow:0 1px 6px rgba(0,0,0,.05);
         display:grid;grid-template-columns:1.5fr 1fr 1fr auto;gap:18px;align-items:center;
         transition:box-shadow .2s;border-left:4px solid #E5E7EB}
  .ocard:hover{box-shadow:0 4px 16px rgba(0,0,0,.08)}
  .ocard.s-trial{border-left-color:#3B82F6}
  .ocard.s-grace{border-left-color:#F59E0B}
  .ocard.s-active{border-left-color:#10B981}
  .ocard.s-suspended{border-left-color:#EF4444;opacity:.85}
  .ocard.s-closed{border-left-color:#6B7280;opacity:.5}

  .ocard-name{font-size:1.05rem;font-weight:800;color:#0F1C3A;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .ocard-name small{font-weight:500;color:#6B7280;font-size:12px;width:100%;display:block;margin-top:3px}
  .ocard-status{font-size:9px;font-weight:800;padding:3px 9px;border-radius:100px;text-transform:uppercase;white-space:nowrap}
  .st-trial{background:#DBEAFE;color:#1E40AF}
  .st-grace{background:#FEF3C7;color:#92400E}
  .st-active{background:#D1FAE5;color:#065F46}
  .st-suspended{background:#FEE2E2;color:#991B1B}
  .st-closed{background:#F3F4F6;color:#6B7280}
  .main-pill{background:#F0FDFB;color:#0891B2;font-size:9px;font-weight:800;padding:2px 7px;border-radius:4px}

  .ocard-metric{font-size:12px;color:#6B7280}
  .ocard-metric strong{color:#0F1C3A;font-weight:800;font-family:monospace;display:block;font-size:14px;margin-bottom:1px}

  .ocard-coin{font-size:12px;color:#6B7280}
  .ocard-coin .num{color:#0F1C3A;font-weight:800;font-family:monospace;font-size:14px;display:block;margin-bottom:1px}
  .ocard-coin small{font-size:10px;color:#9CA3AF;text-transform:uppercase}

  .ocard-actions{display:flex;flex-direction:column;gap:5px;align-items:flex-end}

  .lifecycle-info{font-size:11px;color:#6B7280;padding:8px 12px;background:#FFFBEB;border-radius:6px;
                  margin-top:6px;display:inline-block}
  .lifecycle-info.danger{background:#FEF2F2;color:#991B1B}

  /* Modal */
  .modal-backdrop{position:fixed;inset:0;background:rgba(15,28,58,.75);z-index:999;display:none;
                  align-items:center;justify-content:center;padding:20px}
  .modal-backdrop.open{display:flex}
  .modal{background:#fff;border-radius:14px;max-width:560px;width:100%;max-height:90vh;overflow:auto;
         padding:24px 26px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
  .modal-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px}
  .modal-title{font-size:1.1rem;font-weight:800;color:#0F1C3A}
  .modal-close{background:none;border:none;font-size:24px;cursor:pointer;color:#9CA3AF;line-height:1}
  .modal-close:hover{color:#0F1C3A}

  .form-grid{display:grid;gap:12px}
  .form-grid label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:4px}
  .form-grid input,.form-grid textarea{
    width:100%;padding:9px 12px;border:1.5px solid #E5E7EB;border-radius:8px;
    font-size:14px;outline:none;font-family:inherit;box-sizing:border-box
  }
  .form-grid input:focus,.form-grid textarea:focus{border-color:#35E8D5}

  .alert.error{background:#FEE2E2;color:#991B1B;border:1px solid #FECACA}
  .alert.success{background:#D1FAE5;color:#065F46;border:1px solid #6EE7B7}

  @media(max-width:900px){
    .ocard{grid-template-columns:1fr;gap:10px}
    .ocard-actions{flex-direction:row;justify-content:flex-start}
  }
</style>

  <?php if (!$hasOpHours): ?>
  <div class="alert warn">
    ⚠️ <strong>Migration belum dijalankan.</strong> Field "Jam Operasional" belum tersedia.
    Jalankan: <code>ALTER TABLE outlets ADD COLUMN operating_hours VARCHAR(100) NULL AFTER telepon;</code>
  </div>
  <?php endif; ?>

  <div class="header">
    <h1>🏪 Manajemen Outlet
      <small>Kelola semua cabang · <?= htmlspecialchars($tenantNm) ?></small>
    </h1>
    <?php if ($hqCanManageOutlet): ?><a href="/ERP/harpy/add-outlet.php" class="btn btn-primary">+ Tambah Outlet Baru</a><?php endif; ?>
  </div>

  <div class="summary-bar">
    <div class="summary-item">
      Mode Coin: <span class="mode-pill"><?= strtoupper(htmlspecialchars($coinMode)) ?></span>
    </div>
    <?php if ($coinMode === 'shared'): ?>
    <div class="summary-item">
      Saldo Tenant: <strong>🪙 <?= number_format($tenantCoin) ?></strong>
      <span style="font-size:11px;color:#9CA3AF">(dibagi semua outlet)</span>
    </div>
    <?php endif; ?>
    <div class="summary-item" id="outletCountInfo">Memuat…</div>
  </div>

  <div class="outlet-list" id="outletList">
    <div style="text-align:center;padding:40px;color:#9CA3AF">⏳ Memuat outlet…</div>
  </div>

<!-- Edit Modal -->
<div class="modal-backdrop" id="editModal" onclick="if(event.target===this)closeModal('editModal')">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">✏️ Edit Outlet</div>
      <button class="modal-close" onclick="closeModal('editModal')">×</button>
    </div>
    <div id="editAlert"></div>
    <div class="form-grid">
      <input type="hidden" id="edId">
      <div>
        <label>Nama Outlet <span style="color:#EF4444">*</span></label>
        <input type="text" id="edNama" maxlength="100">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div>
          <label>Kota</label>
          <input type="text" id="edKota" maxlength="100" placeholder="cth: Jakarta">
        </div>
        <div>
          <label>Telepon</label>
          <input type="tel" id="edTelepon" maxlength="20" placeholder="0812xxx">
        </div>
      </div>
      <div>
        <label>Alamat</label>
        <textarea id="edAlamat" rows="2" maxlength="500" placeholder="Jl. Sudirman No. 12, Tanah Abang"></textarea>
      </div>
      <?php if ($hasOpHours): ?>
      <div>
        <label>Jam Operasional</label>
        <input type="text" id="edOpHours" maxlength="100" placeholder="cth: Senin–Sabtu 08:00–21:00, Minggu 09:00–18:00">
      </div>
      <?php endif; ?>
      <?php if ($hasJamBuka): ?>
      <div>
        <label>Jam Buka <small style="color:#9CA3AF;font-weight:400">(patokan keterlambatan absensi)</small></label>
        <input type="time" id="edJamBuka" value="08:00">
      </div>
      <?php endif; ?>
      <?php if ($hasTarget): ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div>
          <label>🎯 Target Omset Harian (Rp)</label>
          <input type="number" id="edTargetHarian" min="0" step="50000" value="0" placeholder="1500000">
        </div>
        <div>
          <label>🎯 Target Omset Bulanan (Rp)</label>
          <input type="number" id="edTargetBulanan" min="0" step="500000" value="0" placeholder="40000000">
        </div>
      </div>
      <?php endif; ?>
      <div>
        <label style="display:flex;align-items:center;gap:8px;font-weight:600;cursor:pointer">
          <input type="checkbox" id="edMain" style="width:auto;margin:0">
          Jadikan outlet utama (UTAMA)
          <small style="color:#9CA3AF;font-weight:400">Akan ditampilkan paling depan</small>
        </label>
      </div>
      <button class="btn btn-primary" style="padding:12px;font-size:14px" onclick="submitEdit()">
        💾 Simpan Perubahan
      </button>
    </div>
  </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const hasOpHours = <?= $hasOpHours ? 'true' : 'false' ?>;
const hasJamBuka = <?= $hasJamBuka ? 'true' : 'false' ?>;
const hasTarget  = <?= $hasTarget  ? 'true' : 'false' ?>;
const coinMode = <?= json_encode($coinMode) ?>;
const supportWa = <?= json_encode($supportWa) ?>;

function escapeHtml(s){return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function fmtRp(n){return 'Rp ' + Number(n||0).toLocaleString('id-ID')}
function fmtDate(s){if(!s)return null;const d=new Date(s);return d.toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}
function daysUntil(s){if(!s)return null;const diff=new Date(s).getTime()-Date.now();return Math.ceil(diff/86400000)}

async function loadList(){
  const r = await fetch('/ERP/harpy/hq/outlet.php?action=list');
  const rows = await r.json();
  document.getElementById('outletCountInfo').innerHTML =
    `<strong>${rows.length}</strong> total outlet (` +
    rows.filter(o => ['trial','grace','active'].includes(o.status)).length + ' aktif)';

  if (rows.length === 0) {
    document.getElementById('outletList').innerHTML =
      '<div style="text-align:center;padding:48px;background:#fff;border-radius:14px;color:#6B7280">' +
      '<div style="font-size:48px">🏪</div><p>Belum ada outlet. <a href="/ERP/harpy/add-outlet.php" style="color:#0891B2;font-weight:700">Tambah outlet pertama →</a></p>' +
      '</div>';
    return;
  }

  document.getElementById('outletList').innerHTML = rows.map(o => {
    const isClosed   = o.status === 'closed';
    const canEnter   = ['trial','grace','active'].includes(o.status);
    const trialDays  = o.status === 'trial' ? daysUntil(o.trial_ends_at) : null;
    const graceDays  = o.status === 'grace' ? daysUntil(o.grace_ends_at) : null;
    const purgeDays  = o.status === 'suspended' ? daysUntil(o.purge_at) : null;

    let lifecycle = '';
    if (trialDays !== null) {
      lifecycle = `<div class="lifecycle-info ${trialDays<=3?'danger':''}">⏰ Trial sisa <strong>${trialDays} hari</strong> · berakhir ${fmtDate(o.trial_ends_at)}</div>`;
    } else if (graceDays !== null) {
      lifecycle = `<div class="lifecycle-info danger">⚠️ Grace sisa <strong>${graceDays} hari</strong> · aktivasi sebelum ${fmtDate(o.grace_ends_at)}</div>`;
    } else if (purgeDays !== null) {
      lifecycle = `<div class="lifecycle-info danger">🚨 Data akan dihapus dalam <strong>${purgeDays} hari</strong></div>`;
    } else if (o.activated_at) {
      lifecycle = `<div class="lifecycle-info" style="background:#F0FDF4;color:#065F46">✓ Aktif sejak ${fmtDate(o.activated_at)}</div>`;
    }

    const coinShow = o.status === 'trial' && parseInt(o.trial_coin_balance||0) > 0
      ? `<span class="num">${Number(o.trial_coin_balance).toLocaleString('id-ID')}</span><small>Trial Coin</small>`
      : (coinMode === 'shared'
          ? `<span class="num" style="color:#9CA3AF;font-style:italic">shared</span><small>Pakai saldo tenant</small>`
          : `<span class="num">${Number(o.coin_balance).toLocaleString('id-ID')}</span><small>Coin Outlet</small>`);

    const topupBtn = (!isClosed && (coinMode === 'per_outlet' || (o.status === 'trial' && o.trial_coin_balance == 0)))
      ? `<a href="https://wa.me/${supportWa}?text=${encodeURIComponent('Halo Tim LAMASY, mau topup coin outlet "'+o.nama_outlet+'"')}"
            target="_blank" rel="noopener" class="btn btn-wa btn-sm">💳 Topup</a>`
      : '';

    return `
      <div class="ocard s-${o.status}">
        <div>
          <div class="ocard-name">
            📍 ${escapeHtml(o.nama_outlet)}
            <span class="ocard-status st-${o.status}">${o.status}</span>
            ${parseInt(o.is_main)===1 ? '<span class="main-pill">⭐ UTAMA</span>' : ''}
            <small>${escapeHtml(o.kota || 'Tanpa kota')}${o.telepon ? ' · 📞 '+escapeHtml(o.telepon) : ''}</small>
            ${o.alamat ? `<small>${escapeHtml(o.alamat)}</small>` : ''}
            ${o.operating_hours ? `<small>🕐 ${escapeHtml(o.operating_hours)}</small>` : ''}
          </div>
          ${lifecycle}
        </div>
        <div class="ocard-metric">
          <strong>Rp ${Number(o.omset_month||0).toLocaleString('id-ID')}</strong>
          Omset Bulan Ini · ${o.order_month||0} order · ${o.karyawan_count||0} karyawan
        </div>
        <div class="ocard-coin">${coinShow}</div>
        <div class="ocard-actions">
          ${canEnter ? `<a href="/ERP/harpy/switch-outlet.php?id=${o.id}" class="btn btn-primary btn-sm">Masuk →</a>` : ''}
          ${!isClosed ? `<button class="btn btn-light btn-sm" onclick="openEdit(${o.id})">✏️ Edit</button>` : ''}
          ${topupBtn}
        </div>
      </div>
    `;
  }).join('');
}

async function openEdit(id){
  const r = await fetch('/ERP/harpy/hq/outlet.php?action=detail&id=' + id);
  const d = await r.json();
  if (d.error) { alert(d.error); return; }
  const o = d.outlet;
  document.getElementById('edId').value = o.id;
  document.getElementById('edNama').value = o.nama_outlet || '';
  document.getElementById('edKota').value = o.kota || '';
  document.getElementById('edTelepon').value = o.telepon || '';
  document.getElementById('edAlamat').value = o.alamat || '';
  if (hasOpHours) document.getElementById('edOpHours').value = o.operating_hours || '';
  if (hasJamBuka) document.getElementById('edJamBuka').value = (o.jam_buka || '08:00:00').slice(0,5);
  if (hasTarget) {
    document.getElementById('edTargetHarian').value  = parseInt(o.target_omset_harian)  || 0;
    document.getElementById('edTargetBulanan').value = parseInt(o.target_omset_bulanan) || 0;
  }
  document.getElementById('edMain').checked = parseInt(o.is_main) === 1;
  document.getElementById('editAlert').innerHTML = '';
  openModal('editModal');
}

async function submitEdit(){
  const alertEl = document.getElementById('editAlert');
  alertEl.innerHTML = '';
  const data = {
    id: document.getElementById('edId').value,
    nama_outlet: document.getElementById('edNama').value.trim(),
    kota: document.getElementById('edKota').value.trim(),
    telepon: document.getElementById('edTelepon').value.trim(),
    alamat: document.getElementById('edAlamat').value.trim(),
    is_main: document.getElementById('edMain').checked ? 1 : 0,
  };
  if (hasOpHours) data.operating_hours = document.getElementById('edOpHours').value.trim();
  if (hasJamBuka) data.jam_buka = document.getElementById('edJamBuka').value;
  if (hasTarget) {
    data.target_omset_harian  = document.getElementById('edTargetHarian').value;
    data.target_omset_bulanan = document.getElementById('edTargetBulanan').value;
  }
  if (!data.nama_outlet) { alertEl.innerHTML = '<div class="alert error">Nama outlet wajib diisi</div>'; return; }

  const r = await fetch('/ERP/harpy/hq/outlet.php?action=update', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
    body: JSON.stringify(data),
  });
  const j = await r.json();
  if (j.error) { alertEl.innerHTML = '<div class="alert error">'+escapeHtml(j.error)+'</div>'; return; }
  alertEl.innerHTML = '<div class="alert success">✓ Tersimpan</div>';
  setTimeout(() => { closeModal('editModal'); loadList(); }, 700);
}

function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}

loadList();
</script>
<?php require __DIR__ . '/_layout_close.php'; ?>
