<?php
// ══════════════════════════════════════════════════════
// superadmin/broadcast.php — WA Broadcast
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';

date_default_timezone_set('Asia/Jakarta');

$action = $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');
    $db = Database::get();

    // Helper to get tenants by target filter
    function getTargetTenants(PDO $db, string $target, array $customIds = []): array {
        if ($target === 'semua') {
            return $db->query("SELECT id, nama_outlet, owner_name, owner_wa, coin_balance FROM tenants WHERE status IN ('active','trial') ORDER BY nama_outlet")->fetchAll();
        }
        if ($target === 'active') {
            return $db->query("SELECT id, nama_outlet, owner_name, owner_wa, coin_balance FROM tenants WHERE status='active' ORDER BY nama_outlet")->fetchAll();
        }
        if ($target === 'trial') {
            return $db->query("SELECT id, nama_outlet, owner_name, owner_wa, coin_balance FROM tenants WHERE status='trial' ORDER BY nama_outlet")->fetchAll();
        }
        if ($target === 'coin_rendah') {
            return $db->query("SELECT id, nama_outlet, owner_name, owner_wa, coin_balance FROM tenants WHERE coin_balance < 10000 AND status='active' ORDER BY coin_balance ASC")->fetchAll();
        }
        if ($target === 'custom' && !empty($customIds)) {
            $ids = implode(',', array_map('intval', $customIds));
            return $db->query("SELECT id, nama_outlet, owner_name, owner_wa, coin_balance FROM tenants WHERE id IN ($ids) ORDER BY nama_outlet")->fetchAll();
        }
        return [];
    }

    function fillTemplate(string $tpl, array $tenant): string {
        return str_replace(
            ['{nama_outlet}', '{owner_name}', '{coin_balance}'],
            [$tenant['nama_outlet'], $tenant['owner_name'], number_format($tenant['coin_balance'])],
            $tpl
        );
    }

    if ($action === 'all_tenants') {
        $rows = $db->query("SELECT id, nama_outlet, owner_name FROM tenants WHERE status IN ('active','trial') ORDER BY nama_outlet")->fetchAll();
        echo json_encode($rows); exit;
    }

    if ($action === 'preview') {
        saVerifyCsrf();
        $target    = $_POST['target'] ?? 'semua';
        $customIds = json_decode($_POST['custom_ids'] ?? '[]', true) ?: [];
        $message   = $_POST['message'] ?? '';

        $tenants = getTargetTenants($db, $target, $customIds);
        $sample  = array_slice($tenants, 0, 3);

        echo json_encode([
            'count'  => count($tenants),
            'sample' => array_map(fn($t) => [
                'nama_outlet' => $t['nama_outlet'],
                'owner_name'  => $t['owner_name'],
                'message'     => fillTemplate($message, $t),
                'wa'          => $t['owner_wa'],
            ], $sample),
        ]);
        exit;
    }

    if ($action === 'send') {
        saVerifyCsrf();
        $target    = $_POST['target'] ?? 'semua';
        $customIds = json_decode($_POST['custom_ids'] ?? '[]', true) ?: [];
        $message   = trim($_POST['message'] ?? '');
        $subject   = trim($_POST['subject'] ?? 'Broadcast WA');

        if (!$message) { echo json_encode(['error' => 'Pesan kosong.']); exit; }

        $tenants = getTargetTenants($db, $target, $customIds);
        $logged  = 0;

        foreach ($tenants as $t) {
            $filledMsg = fillTemplate($message, $t);
            try {
                $db->prepare(
                    "INSERT INTO support_tickets (tenant_id, superadmin_id, channel, subject, message, type)
                     VALUES (?, ?, 'wa', ?, ?, 'info')"
                )->execute([$t['id'], $_SESSION['superadmin_id'], $subject, $filledMsg]);
                $logged++;
            } catch (Throwable) {}
        }

        logSuperAdminAction('broadcast', null, "Broadcast ke $logged tenant: $subject");

        echo json_encode([
            'success' => true,
            'count'   => $logged,
            'links'   => array_map(fn($t) => [
                'nama' => $t['nama_outlet'],
                'wa'   => $t['owner_wa'],
                'url'  => 'https://wa.me/' . preg_replace('/[^0-9]/', '', $t['owner_wa']) . '?text=' . rawurlencode(fillTemplate($message, $t)),
            ], $tenants),
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
<?php saRenderHead('Broadcast'); ?>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('broadcast', 'WA Broadcast'); ?>

<div class="sa-page-header">
  <h1>Broadcast WA</h1>
  <p>Kirim pesan massal ke tenant via WhatsApp</p>
</div>

<div class="sa-grid-2" style="align-items:flex-start;">
  <!-- Form -->
  <div>
    <div class="sa-card" style="margin-bottom:16px;">
      <div class="sa-card-header"><h3>⚙️ Konfigurasi Broadcast</h3></div>
      <div class="sa-card-body">
        <div class="form-group" style="margin-bottom:14px;">
          <label style="font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:rgba(255,255,255,.4);">Target Penerima</label>
          <select id="bcTarget" style="width:100%;margin-top:6px;padding:10px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-family:var(--font);" onchange="toggleCustomList()">
            <option value="semua">Semua Tenant (Aktif + Trial)</option>
            <option value="active">Hanya Aktif</option>
            <option value="trial">Hanya Trial</option>
            <option value="coin_rendah">Coin Rendah (&lt;10.000)</option>
            <option value="custom">Pilih Manual</option>
          </select>
        </div>

        <!-- Custom checkboxes -->
        <div id="customList" style="display:none;margin-bottom:14px;">
          <label style="font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:rgba(255,255,255,.4);">Pilih Tenant</label>
          <div style="margin-top:6px;max-height:200px;overflow-y:auto;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:8px;" id="tenantCheckboxes">
            <div style="color:rgba(255,255,255,.35);font-size:13px;">Memuat...</div>
          </div>
          <div style="display:flex;gap:8px;margin-top:8px;">
            <button class="sa-btn sa-btn-sm sa-btn-outline" onclick="selectAll(true)">Pilih Semua</button>
            <button class="sa-btn sa-btn-sm sa-btn-outline" onclick="selectAll(false)">Batal Semua</button>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:14px;">
          <label style="font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:rgba(255,255,255,.4);">Subjek (untuk log)</label>
          <input type="text" id="bcSubject" placeholder="Contoh: Promo Coin September" style="width:100%;margin-top:6px;padding:10px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-family:var(--font);font-size:13.5px;"/>
        </div>

        <div class="form-group" style="margin-bottom:6px;">
          <label style="font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:rgba(255,255,255,.4);">Pesan WA</label>
          <textarea id="bcMessage" placeholder="Halo {nama_outlet}, kami ingin menginfokan..." style="width:100%;margin-top:6px;padding:10px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-family:var(--font);font-size:13.5px;resize:vertical;min-height:140px;" oninput="updatePreview()"></textarea>
        </div>
        <div style="font-size:11.5px;color:rgba(255,255,255,.35);margin-bottom:16px;">
          Variabel: <code style="background:rgba(255,255,255,.08);padding:2px 6px;border-radius:4px;">{nama_outlet}</code>
          <code style="background:rgba(255,255,255,.08);padding:2px 6px;border-radius:4px;">{owner_name}</code>
          <code style="background:rgba(255,255,255,.08);padding:2px 6px;border-radius:4px;">{coin_balance}</code>
        </div>
        <div style="display:flex;gap:10px;">
          <button class="sa-btn sa-btn-outline" onclick="previewBroadcast()">👁️ Preview</button>
          <button class="sa-btn sa-btn-primary" id="btnSend" onclick="sendBroadcast()" style="display:none;">📣 Kirim Broadcast</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Preview & Result -->
  <div>
    <div class="sa-card" id="previewCard" style="margin-bottom:16px;display:none;">
      <div class="sa-card-header">
        <h3>👁️ Preview</h3>
        <span id="previewCount" style="font-size:13px;color:rgba(255,255,255,.5);"></span>
      </div>
      <div class="sa-card-body" id="previewBody"></div>
    </div>

    <div class="sa-card" id="resultCard" style="display:none;">
      <div class="sa-card-header"><h3>✅ Hasil Broadcast</h3></div>
      <div class="sa-card-body" id="resultBody"></div>
    </div>
  </div>
</div>

<?php saRenderNavClose(); ?>

<script>
let allTenants = [];
let lastPreviewLinks = [];

// Load all tenants for custom selector
fetch('broadcast.php?action=all_tenants', { headers: {'X-Requested-With':'XMLHttpRequest'} })
  .then(r => r.json()).then(rows => {
    allTenants = rows;
    const el = document.getElementById('tenantCheckboxes');
    el.innerHTML = rows.map(t => `
      <label style="display:flex;align-items:center;gap:8px;padding:6px 4px;cursor:pointer;font-size:13px;color:rgba(255,255,255,.8);">
        <input type="checkbox" class="tenant-cb" value="${t.id}" style="accent-color:#6366F1;"/>
        ${esc(t.nama_outlet)} <small style="color:rgba(255,255,255,.4);">${esc(t.owner_name)}</small>
      </label>`).join('');
  });

function toggleCustomList() {
  const v = document.getElementById('bcTarget').value;
  document.getElementById('customList').style.display = v === 'custom' ? '' : 'none';
}

function selectAll(check) {
  document.querySelectorAll('.tenant-cb').forEach(c => c.checked = check);
}

function getCustomIds() {
  return Array.from(document.querySelectorAll('.tenant-cb:checked')).map(c => c.value);
}

function updatePreview() {
  // Live preview of first sample
}

function previewBroadcast() {
  const target = document.getElementById('bcTarget').value;
  const msg    = document.getElementById('bcMessage').value;
  const customIds = JSON.stringify(getCustomIds());

  const fd = new FormData();
  fd.append('_csrf', saCsrf());
  fd.append('target', target);
  fd.append('message', msg);
  fd.append('custom_ids', customIds);

  fetch('broadcast.php?action=preview', { method:'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'} })
    .then(r => r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }

      document.getElementById('previewCard').style.display = '';
      document.getElementById('previewCount').textContent = `Akan dikirim ke ${d.count} tenant`;
      document.getElementById('btnSend').style.display = '';

      if (!d.count) {
        document.getElementById('previewBody').innerHTML = '<p style="color:rgba(255,255,255,.35);font-size:13px;">Tidak ada tenant sesuai filter.</p>';
        return;
      }

      document.getElementById('previewBody').innerHTML = `
        <div style="background:rgba(99,102,241,.1);border:1px solid rgba(99,102,241,.2);border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:14px;color:rgba(255,255,255,.8);">
          <strong style="color:var(--sa);">Akan dikirim ke ${d.count} tenant</strong>
        </div>
        <div style="font-size:12px;color:rgba(255,255,255,.4);margin-bottom:10px;">Sampel preview (3 pertama):</div>
        ${d.sample.map(s => `
          <div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:8px;padding:12px;margin-bottom:10px;">
            <div style="font-size:12px;font-weight:700;color:var(--sa);margin-bottom:6px;">${esc(s.nama_outlet)} — ${esc(s.owner_name)}</div>
            <div style="font-size:13px;color:rgba(255,255,255,.75);white-space:pre-wrap;">${esc(s.message)}</div>
          </div>`).join('')}`;
    });
}

function sendBroadcast() {
  if (!confirm('Kirim broadcast ke semua tenant yang dipilih?')) return;

  const btn = document.getElementById('btnSend');
  btn.textContent = '⏳ Mengirim...';
  btn.disabled = true;

  const target = document.getElementById('bcTarget').value;
  const msg    = document.getElementById('bcMessage').value;
  const subj   = document.getElementById('bcSubject').value;
  const customIds = JSON.stringify(getCustomIds());

  const fd = new FormData();
  fd.append('_csrf', saCsrf());
  fd.append('target', target);
  fd.append('message', msg);
  fd.append('subject', subj);
  fd.append('custom_ids', customIds);

  fetch('broadcast.php?action=send', { method:'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'} })
    .then(r => r.json()).then(d => {
      btn.textContent = '📣 Kirim Broadcast';
      btn.disabled = false;

      if (d.error) { saShowToast(d.error, 'error'); return; }

      saShowToast(`Broadcast berhasil! ${d.count} tenant dicatat.`, 'success');
      lastPreviewLinks = d.links || [];

      document.getElementById('resultCard').style.display = '';
      document.getElementById('resultBody').innerHTML = `
        <p style="font-size:13.5px;color:rgba(255,255,255,.7);margin-bottom:16px;">
          ✅ Broadcast dicatat untuk <strong>${d.count} tenant</strong>. Klik link WA di bawah untuk kirim manual.
        </p>
        <div style="max-height:400px;overflow-y:auto;">
          ${d.links.map(l => `
            <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:8px;margin-bottom:6px;">
              <span style="font-size:13px;color:rgba(255,255,255,.75);">${esc(l.nama)}</span>
              <a href="${esc(l.url)}" target="_blank" class="sa-btn sa-btn-sm sa-btn-wa">💬 Kirim WA</a>
            </div>`).join('')}
        </div>`;
    });
}

function esc(s) { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
</script>
</body>
</html>
