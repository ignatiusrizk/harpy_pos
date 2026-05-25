<?php
// ══════════════════════════════════════════════════════
// loyalty.php — Settings Sistem Poin
//   - Config tenant: rupiah_per_poin, poin_value, expiry_months, enabled
//   - CRUD katalog reward (hl_poin_reward) per outlet
// ══════════════════════════════════════════════════════

$activePage = 'loyalty';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/Loyalty.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
$tid  = (int)TenantResolver::id();
$oid  = (int)TenantResolver::outletId();

// ── AJAX ──────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    if (!hasPermission('settings.manage_loyalty') && !hasPermission('owner') && $user['role'] !== 'owner' && $user['role'] !== 'admin') {
        // fallback: allow owner/superadmin/admin/manager
        if (!in_array($user['role'] ?? '', ['owner','superadmin','admin','manager'], true)) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
    }

    if ($action === 'get_config') {
        try {
            $db = Database::get();
            $st = $db->prepare("SELECT loyalty_enabled, loyalty_rupiah_per_poin, loyalty_poin_value, loyalty_expiry_months
                                  FROM tenants WHERE id=?");
            $st->execute([$tid]);
            $cfg = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['ok'=>true, 'config'=>[
                'enabled'         => (int)($cfg['loyalty_enabled'] ?? 0) === 1,
                'rupiah_per_poin' => (int)($cfg['loyalty_rupiah_per_poin'] ?? 1000),
                'poin_value'      => (int)($cfg['loyalty_poin_value'] ?? 100),
                'expiry_months'   => (int)($cfg['loyalty_expiry_months'] ?? 12),
            ]]);
        } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($action === 'save_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        try {
            $enabled = !empty($d['enabled']) ? 1 : 0;
            $rpp     = max(100,  (int)($d['rupiah_per_poin'] ?? 1000));
            $pv      = max(1,    (int)($d['poin_value']      ?? 100));
            $exp     = max(1, min(60, (int)($d['expiry_months'] ?? 12)));
            Database::get()
                ->prepare("UPDATE tenants
                              SET loyalty_enabled=?, loyalty_rupiah_per_poin=?,
                                  loyalty_poin_value=?, loyalty_expiry_months=?
                            WHERE id=?")
                ->execute([$enabled, $rpp, $pv, $exp, $tid]);
            logAudit('save_loyalty_config', 'loyalty', "enabled=$enabled rpp=$rpp pv=$pv exp=$exp");
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($action === 'list_rewards') {
        try {
            $db = Database::get();
            $st = $db->prepare("SELECT * FROM hl_poin_reward
                                 WHERE tenant_id=? AND outlet_id=?
                                 ORDER BY poin_dibutuhkan ASC");
            $st->execute([$tid, $oid]);
            echo json_encode(['ok'=>true, 'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($action === 'save_reward' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id   = (int)($d['id'] ?? 0);
        $nama = substr(trim($d['nama_reward'] ?? ''), 0, 100);
        $desk = substr(trim($d['deskripsi'] ?? ''), 0, 1000);
        $poin = max(1, (int)($d['poin_dibutuhkan'] ?? 0));
        $tipe = in_array($d['tipe'] ?? '', ['diskon_nominal','diskon_persen','gratis_layanan'], true) ? $d['tipe'] : 'diskon_nominal';
        $nilai = max(0, (int)($d['nilai'] ?? 0));
        $minTrx = max(0, (int)($d['min_transaksi'] ?? 0));
        $maxBulan = max(0, (int)($d['max_redeem_per_bulan'] ?? 0));
        $active = !empty($d['is_active']) ? 1 : 0;

        if (!$nama) { echo json_encode(['error'=>'Nama reward wajib']); exit; }

        try {
            $db = Database::get();
            if ($id > 0) {
                // Update
                $db->prepare("UPDATE hl_poin_reward
                                 SET nama_reward=?, deskripsi=?, poin_dibutuhkan=?, tipe=?, nilai=?,
                                     min_transaksi=?, max_redeem_per_bulan=?, is_active=?
                               WHERE id=? AND tenant_id=? AND outlet_id=?")
                   ->execute([$nama, $desk, $poin, $tipe, $nilai, $minTrx, $maxBulan, $active, $id, $tid, $oid]);
                logAudit('update_reward', 'loyalty', "Reward #$id: $nama");
                echo json_encode(['ok'=>true, 'id'=>$id]);
            } else {
                // Create
                $db->prepare("INSERT INTO hl_poin_reward
                                (tenant_id, outlet_id, nama_reward, deskripsi, poin_dibutuhkan,
                                 tipe, nilai, min_transaksi, max_redeem_per_bulan, is_active)
                              VALUES (?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$tid, $oid, $nama, $desk, $poin, $tipe, $nilai, $minTrx, $maxBulan, $active]);
                $newId = (int)$db->lastInsertId();
                logAudit('create_reward', 'loyalty', "Reward baru: $nama");
                echo json_encode(['ok'=>true, 'id'=>$newId]);
            }
        } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($action === 'toggle_reward' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        if (!$id) { echo json_encode(['error'=>'id wajib']); exit; }
        try {
            Database::get()
                ->prepare("UPDATE hl_poin_reward SET is_active=1-is_active
                            WHERE id=? AND tenant_id=? AND outlet_id=?")
                ->execute([$id, $tid, $oid]);
            logAudit('toggle_reward', 'loyalty', "Reward #$id toggled");
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($action === 'delete_reward' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $d  = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        if (!$id) { echo json_encode(['error'=>'id wajib']); exit; }
        try {
            // Cek apakah pernah dipakai
            $db = Database::get();
            $st = $db->prepare("SELECT COUNT(*) FROM hl_loyalty_log WHERE reward_id=?");
            $st->execute([$id]);
            if ((int)$st->fetchColumn() > 0) {
                echo json_encode(['error'=>'Reward ini sudah pernah dipakai — non-aktifkan saja agar history tetap valid.']);
                exit;
            }
            $db->prepare("DELETE FROM hl_poin_reward WHERE id=? AND tenant_id=? AND outlet_id=?")
               ->execute([$id, $tid, $oid]);
            logAudit('delete_reward', 'loyalty', "Reward #$id deleted");
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    echo json_encode(['error'=>'Unknown action']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Sistem Poin & Reward'); ?>
<style>
.cfg-card{background:#fff;border:1px solid rgba(27,45,90,.08);border-radius:12px;padding:18px 20px;margin-bottom:16px}
.cfg-card h3{font-size:14px;margin:0 0 12px;color:var(--navy);font-weight:800}
.reward-row{padding:12px 14px;border:1px solid rgba(27,45,90,.08);border-radius:10px;margin-bottom:8px;display:grid;grid-template-columns:1fr auto auto auto;gap:10px;align-items:center}
.reward-row.inactive{opacity:.55;background:#F8FAFC}
.pill{display:inline-block;font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px}
@media(max-width:680px){
  .reward-row{grid-template-columns:1fr auto;gap:8px}
  .reward-row > div:nth-child(2){grid-column:1/-1;text-align:left;font-size:13px;color:#0891B2}
  .reward-row > div:nth-child(3){grid-column:1}
  .reward-row > div:nth-child(4){grid-column:2;justify-self:end}
}
</style>
</head>
<body>
<?php renderTopbar('loyalty'); ?>

<div class="hl-main">
  <div style="margin-bottom:18px">
    <h1 style="font-size:1.3rem;font-weight:800;color:var(--navy);margin:0">⭐ Sistem Poin & Reward</h1>
    <p style="font-size:13px;color:var(--gray);margin:4px 0 0">
      Atur tarif poin & katalog reward yang bisa ditukar pelanggan.
    </p>
  </div>

  <!-- KONFIGURASI -->
  <div class="cfg-card">
    <h3>⚙️ Konfigurasi Loyalty</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:14px">
      <div class="hl-form-group" style="margin:0">
        <label class="hl-label">Status</label>
        <select id="cfgEnabled" class="hl-input">
          <option value="1">✅ Aktif</option>
          <option value="0">❌ Nonaktif</option>
        </select>
      </div>
      <div class="hl-form-group" style="margin:0">
        <label class="hl-label">Rp/poin (earn)</label>
        <input type="number" id="cfgRpp" class="hl-input" min="100" step="500" placeholder="8000"/>
      </div>
      <div class="hl-form-group" style="margin:0">
        <label class="hl-label">Nilai poin (redeem Rp)</label>
        <input type="number" id="cfgPv" class="hl-input" min="1" step="100" placeholder="100"/>
      </div>
      <div class="hl-form-group" style="margin:0">
        <label class="hl-label">Expiry (bulan)</label>
        <input type="number" id="cfgExp" class="hl-input" min="1" max="60" placeholder="12"/>
      </div>
    </div>
    <button class="hl-btn hl-btn-primary" onclick="saveConfig()">💾 Simpan Konfigurasi</button>
    <small style="display:block;margin-top:8px;color:var(--gray);font-size:11px">
      <strong>Contoh:</strong> Rp/poin = 8.000, Nilai poin = 100 → setiap belanja Rp 8.000 dapat 1 poin,
      1 poin saat redeem = diskon Rp 100.
    </small>
  </div>

  <!-- KATALOG REWARD -->
  <div class="cfg-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <h3 style="margin:0">🎁 Katalog Reward</h3>
      <button class="hl-btn hl-btn-teal hl-btn-sm" onclick="openRewardModal()">+ Tambah Reward</button>
    </div>
    <div id="rewardList"><div class="hl-loading">⏳ Memuat...</div></div>
  </div>
</div>

<!-- MODAL REWARD -->
<div class="hl-modal-overlay" id="modalReward">
  <div class="hl-modal" style="max-width:540px">
    <div class="hl-modal-header">
      <span class="hl-modal-title" id="rewardModalTitle">➕ Tambah Reward</span>
      <button class="hl-modal-close" onclick="closeRewardModal()">✕</button>
    </div>
    <div class="hl-modal-body">
      <input type="hidden" id="r_id"/>
      <div class="hl-form-group">
        <label class="hl-label">Nama Reward <span class="req">*</span></label>
        <input type="text" id="r_nama" class="hl-input" placeholder="Diskon Rp 10.000"/>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Poin Dibutuhkan <span class="req">*</span></label>
          <input type="number" id="r_poin" class="hl-input" min="1" placeholder="50"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Tipe</label>
          <select id="r_tipe" class="hl-input">
            <option value="diskon_nominal">💰 Diskon Nominal (Rp)</option>
            <option value="diskon_persen">📉 Diskon Persen (%)</option>
            <option value="gratis_layanan">🎁 Gratis Layanan (qty)</option>
          </select>
        </div>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Nilai <span class="req">*</span></label>
        <input type="number" id="r_nilai" class="hl-input" min="0" placeholder="10000"/>
        <small id="r_nilai_hint" style="font-size:11px;color:var(--gray)">Nominal diskon dalam Rupiah</small>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Min. Transaksi (Rp)</label>
          <input type="number" id="r_mintrx" class="hl-input" min="0" placeholder="0 = tanpa min"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Max Redeem / Bulan</label>
          <input type="number" id="r_maxbulan" class="hl-input" min="0" placeholder="0 = unlimited"/>
        </div>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Deskripsi (opsional)</label>
        <textarea id="r_desk" class="hl-input hl-textarea" placeholder="Detail/syarat reward..."></textarea>
      </div>
      <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
        <input type="checkbox" id="r_active" checked/> Aktif (terlihat di POS)
      </label>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeRewardModal()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveReward()">💾 Simpan</button>
    </div>
  </div>
</div>

<?php renderToast(); ?>

<script>
async function loadConfig(){
  try{
    const r = await fetch('loyalty.php?action=get_config');
    const d = await r.json();
    if (d.error) return;
    document.getElementById('cfgEnabled').value = d.config.enabled ? '1' : '0';
    document.getElementById('cfgRpp').value     = d.config.rupiah_per_poin;
    document.getElementById('cfgPv').value      = d.config.poin_value;
    document.getElementById('cfgExp').value     = d.config.expiry_months;
  } catch(e){}
}

async function saveConfig(){
  const body = {
    enabled:         document.getElementById('cfgEnabled').value === '1',
    rupiah_per_poin: parseInt(document.getElementById('cfgRpp').value) || 1000,
    poin_value:      parseInt(document.getElementById('cfgPv').value)  || 100,
    expiry_months:   parseInt(document.getElementById('cfgExp').value) || 12,
  };
  try {
    const r = await fetch('loyalty.php?action=save_config', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify(body)
    });
    const d = await r.json();
    if (d.error) { showToast(d.error,'error'); return; }
    showToast('✓ Konfigurasi tersimpan','success');
  } catch(e){ showToast('Network error','error'); }
}

const TIPE_LABEL = {
  diskon_nominal: 'Diskon Nominal',
  diskon_persen:  'Diskon Persen',
  gratis_layanan: 'Gratis Layanan'
};
const TIPE_COLOR = {
  diskon_nominal: ['#DBEAFE','#1E40AF'],
  diskon_persen:  ['#FEF3C7','#92400E'],
  gratis_layanan: ['#D1FAE5','#065F46'],
};

async function loadRewards(){
  const box = document.getElementById('rewardList');
  try {
    const r = await fetch('loyalty.php?action=list_rewards');
    const d = await r.json();
    if (d.error) { box.innerHTML = '<div class="hl-empty">'+d.error+'</div>'; return; }
    const rows = d.rows || [];
    if (!rows.length) { box.innerHTML = `<div class="hl-empty-v2">
      <div class="e-icon">🎁</div>
      <div class="e-title">Belum ada reward</div>
      <div class="e-sub">Tambahkan reward pertama supaya pelanggan bisa tukar poin</div>
      <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="openRewardModal()">+ Tambah Reward</button>
    </div>`; return; }
    box.innerHTML = rows.map(r => {
      const [bg,fg] = TIPE_COLOR[r.tipe] || ['#F1F5F9','#475569'];
      const nilaiTxt = r.tipe === 'diskon_nominal' ? 'Rp ' + parseInt(r.nilai).toLocaleString('id-ID')
                    : r.tipe === 'diskon_persen'  ? r.nilai + '%'
                    : r.nilai + ' qty';
      return `<div class="reward-row ${r.is_active==1?'':'inactive'}">
        <div>
          <div style="font-size:14px;font-weight:700;color:var(--navy)">${esc(r.nama_reward)}</div>
          <div style="font-size:11px;color:var(--gray);margin-top:2px">
            <span class="pill" style="background:${bg};color:${fg}">${TIPE_LABEL[r.tipe]||r.tipe}</span>
            · Nilai: <strong>${nilaiTxt}</strong>
            ${r.min_transaksi > 0 ? '· Min Rp ' + parseInt(r.min_transaksi).toLocaleString('id-ID') : ''}
            ${r.max_redeem_per_bulan > 0 ? '· Max ' + r.max_redeem_per_bulan + '×/bulan' : ''}
          </div>
        </div>
        <div style="font-size:14px;font-weight:800;color:#0891B2;text-align:right;white-space:nowrap">${r.poin_dibutuhkan} poin</div>
        <div>
          <span class="pill" style="background:${r.is_active==1?'#D1FAE5':'#FEE2E2'};color:${r.is_active==1?'#065F46':'#991B1B'}">
            ${r.is_active==1?'Aktif':'Nonaktif'}
          </span>
        </div>
        <div style="display:flex;gap:4px">
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick='editReward(${JSON.stringify(r)})'>✏️</button>
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="toggleReward(${r.id})" title="${r.is_active==1?'Nonaktifkan':'Aktifkan'}">${r.is_active==1?'⏸':'▶'}</button>
          <button class="hl-btn hl-btn-outline hl-btn-sm" style="color:#dc2626" onclick="deleteReward(${r.id})" title="Hapus">🗑</button>
        </div>
      </div>`;
    }).join('');
  } catch(e){ box.innerHTML = '<div class="hl-empty">❌ '+e.message+'</div>'; }
}

function openRewardModal(){
  document.getElementById('rewardModalTitle').textContent = '➕ Tambah Reward';
  document.getElementById('r_id').value = '';
  document.getElementById('r_nama').value = '';
  document.getElementById('r_poin').value = '50';
  document.getElementById('r_tipe').value = 'diskon_nominal';
  document.getElementById('r_nilai').value = '10000';
  document.getElementById('r_mintrx').value = '0';
  document.getElementById('r_maxbulan').value = '0';
  document.getElementById('r_desk').value = '';
  document.getElementById('r_active').checked = true;
  updateNilaiHint();
  document.getElementById('modalReward').classList.add('open');
}

function editReward(r){
  document.getElementById('rewardModalTitle').textContent = '✏️ Edit Reward';
  document.getElementById('r_id').value = r.id;
  document.getElementById('r_nama').value = r.nama_reward;
  document.getElementById('r_poin').value = r.poin_dibutuhkan;
  document.getElementById('r_tipe').value = r.tipe;
  document.getElementById('r_nilai').value = r.nilai;
  document.getElementById('r_mintrx').value = r.min_transaksi || 0;
  document.getElementById('r_maxbulan').value = r.max_redeem_per_bulan || 0;
  document.getElementById('r_desk').value = r.deskripsi || '';
  document.getElementById('r_active').checked = r.is_active == 1;
  updateNilaiHint();
  document.getElementById('modalReward').classList.add('open');
}

function closeRewardModal(){ document.getElementById('modalReward').classList.remove('open'); }

document.getElementById('r_tipe').addEventListener('change', updateNilaiHint);
function updateNilaiHint(){
  const t = document.getElementById('r_tipe').value;
  const h = document.getElementById('r_nilai_hint');
  if (t === 'diskon_nominal') h.textContent = 'Nominal diskon dalam Rupiah';
  else if (t === 'diskon_persen') h.textContent = 'Persentase 1-100';
  else h.textContent = 'Quantity (jumlah free items)';
}

async function saveReward(){
  const body = {
    id:                   parseInt(document.getElementById('r_id').value) || 0,
    nama_reward:          document.getElementById('r_nama').value,
    poin_dibutuhkan:      parseInt(document.getElementById('r_poin').value),
    tipe:                 document.getElementById('r_tipe').value,
    nilai:                parseInt(document.getElementById('r_nilai').value),
    min_transaksi:        parseInt(document.getElementById('r_mintrx').value) || 0,
    max_redeem_per_bulan: parseInt(document.getElementById('r_maxbulan').value) || 0,
    deskripsi:            document.getElementById('r_desk').value,
    is_active:            document.getElementById('r_active').checked,
  };
  if (!body.nama_reward) { showToast('Nama wajib diisi','error'); return; }
  if (!body.poin_dibutuhkan) { showToast('Poin dibutuhkan wajib','error'); return; }
  try {
    const r = await fetch('loyalty.php?action=save_reward', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify(body)
    });
    const d = await r.json();
    if (d.error) { showToast(d.error,'error'); return; }
    showToast('✓ Reward tersimpan','success');
    closeRewardModal(); loadRewards();
  } catch(e){ showToast('Network error','error'); }
}

async function toggleReward(id){
  try {
    const r = await fetch('loyalty.php?action=toggle_reward', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({id})
    });
    const d = await r.json();
    if (d.error) { showToast(d.error,'error'); return; }
    loadRewards();
  } catch(e){ showToast('Network error','error'); }
}

async function deleteReward(id){
  if (!confirm('Hapus reward ini?')) return;
  try {
    const r = await fetch('loyalty.php?action=delete_reward', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({id})
    });
    const d = await r.json();
    if (d.error) { showToast(d.error,'error'); return; }
    showToast('✓ Reward dihapus','success');
    loadRewards();
  } catch(e){ showToast('Network error','error'); }
}

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}

loadConfig();
loadRewards();
</script>
</body>
</html>
