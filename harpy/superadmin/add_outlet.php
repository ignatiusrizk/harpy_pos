<?php
// ══════════════════════════════════════════════════════
// superadmin/add_outlet.php — Tambah Outlet ke Tenant Existing
// URL: add_outlet.php?tenant_id={id}
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';
require_once SA_ROOT . '/../core/Database.php';

date_default_timezone_set('Asia/Jakarta');

$db = Database::get();

$tenantId = (int)($_GET['tenant_id'] ?? 0);
if (!$tenantId) { header('Location: clients.php'); exit; }

// Load tenant
$tenantStmt = $db->prepare("SELECT * FROM tenants WHERE id=? LIMIT 1");
$tenantStmt->execute([$tenantId]);
$tenant = $tenantStmt->fetch();
if (!$tenant) { header('Location: clients.php'); exit; }

// Load existing outlets
$existingOutlets = $db->prepare("SELECT * FROM outlets WHERE tenant_id=? ORDER BY is_main DESC, nama_outlet")->execute([$tenantId]) ?: [];
$existingStmt = $db->prepare("SELECT * FROM outlets WHERE tenant_id=? ORDER BY is_main DESC, nama_outlet");
$existingStmt->execute([$tenantId]);
$existingOutlets = $existingStmt->fetchAll();

// ── Init wizard session ───────────────────────────────
if (isset($_GET['reset']) || empty($_SESSION['sa_add_outlet']) || ((int)($_SESSION['sa_add_outlet']['tenant_id'] ?? 0) !== $tenantId)) {
    $_SESSION['sa_add_outlet'] = ['step' => 1, 'tenant_id' => $tenantId];
}

$wiz   = &$_SESSION['sa_add_outlet'];
$step  = (int)($wiz['step'] ?? 1);
$error = '';
$result = null;

// ── Handle POST ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saVerifyCsrf();
    $pStep = (int)($_POST['step'] ?? 1);

    if ($pStep === 1) {
        $wiz['nama_outlet'] = substr(trim(strip_tags($_POST['nama_outlet'] ?? '')), 0, 100);
        $wiz['alamat']      = substr(trim(strip_tags($_POST['alamat'] ?? '')), 0, 300);
        $wiz['kota']        = substr(trim(strip_tags($_POST['kota'] ?? '')), 0, 100);
        $wiz['telepon']     = substr(trim(preg_replace('/[^0-9+\-\s]/', '', $_POST['telepon'] ?? '')), 0, 20);

        if (!$wiz['nama_outlet']) {
            $error = 'Nama outlet wajib diisi.';
        } else {
            $wiz['step'] = 2; $step = 2;
        }
    }

    elseif ($pStep === 2) {
        $coinConf = $_POST['coin_config'] ?? 'shared_inherit';
        $wiz['coin_config']         = $coinConf;
        $wiz['coin_initial_balance'] = max(0, (int)($_POST['coin_initial_balance'] ?? 0));
        $wiz['step'] = 3; $step = 3;
    }

    elseif ($pStep === 3) {
        // Provision outlet
        $provResult = provisionOutlet($wiz, $tenant);
        if ($provResult['success']) {
            $wiz['step'] = 4; $step = 4;
            $result = $provResult;
            $wiz['result'] = $provResult;
        } else {
            $error = 'Gagal: ' . htmlspecialchars($provResult['error']);
            $step  = 3;
        }
    }

    elseif ($pStep === 'back') {
        $backTo = max(1, (int)($_POST['back_to'] ?? ($step - 1)));
        $wiz['step'] = $backTo; $step = $backTo;
    }
}

if ($step === 4 && !$result && !empty($wiz['result'])) {
    $result = $wiz['result'];
}

// ── Provision outlet function ─────────────────────────
function provisionOutlet(array $wiz, array $tenant): array
{
    $db = Database::get();
    $db->beginTransaction();
    try {
        $tenantId = (int)$tenant['id'];

        // Generate unique slug
        $slugBase = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $wiz['nama_outlet']));
        $slugBase = trim($slugBase, '_') ?: 'outlet';
        $slug = $tenant['slug'] . '_' . $slugBase;
        $i = 2;
        while (true) {
            $chk = $db->prepare("SELECT COUNT(*) FROM outlets WHERE slug=?");
            $chk->execute([$slug]);
            if ((int)$chk->fetchColumn() === 0) break;
            $slug = $tenant['slug'] . '_' . $slugBase . '_' . $i++;
        }

        // Determine coin balance for new outlet
        $coinBalance = 0;
        if ($tenant['coin_mode'] === 'per_outlet') {
            $coinBalance = (int)$wiz['coin_initial_balance'];
        }

        // Insert outlet
        $db->prepare(
            "INSERT INTO outlets (tenant_id, nama_outlet, slug, alamat, kota, telepon, status, coin_balance, is_main, setup_done, created_at)
             VALUES (?,?,?,?,?,?,?,?,0,0,NOW())"
        )->execute([
            $tenantId, $wiz['nama_outlet'], $slug,
            $wiz['alamat'] ?? '', $wiz['kota'] ?? '',
            $wiz['telepon'] ?? '', 'active', $coinBalance,
        ]);
        $outletId = (int)$db->lastInsertId();

        // Update total_outlets on tenant
        $db->prepare("UPDATE tenants SET total_outlets = (SELECT COUNT(*) FROM outlets WHERE tenant_id=? AND status='active') WHERE id=?")
           ->execute([$tenantId, $tenantId]);

        // Seed default services for new outlet
        $defaultLayanan = [
            ['nama' => 'Cuci Kering',  'harga' => 6000,  'satuan' => 'kg', 'kategori' => 'reguler'],
            ['nama' => 'Cuci Setrika', 'harga' => 8000,  'satuan' => 'kg', 'kategori' => 'reguler'],
            ['nama' => 'Express',      'harga' => 15000, 'satuan' => 'kg', 'kategori' => 'express'],
        ];
        $lStmt = $db->prepare(
            "INSERT INTO hl_layanan (tenant_id, outlet_id, nama, harga, satuan, kategori, is_active, urutan, created_at)
             VALUES (?,?,?,?,?,?,1,0,NOW())"
        );
        foreach ($defaultLayanan as $l) {
            $lStmt->execute([$tenantId, $outletId, $l['nama'], $l['harga'], $l['satuan'], $l['kategori']]);
        }

        // If per_outlet mode and coin_initial_balance > 0, optionally note transfer from shared
        $note = '';
        if ($tenant['coin_mode'] === 'per_outlet' && $coinBalance > 0) {
            $note = "Saldo awal per_outlet: {$coinBalance} coin (perlu dikurangi manual dari shared balance jika diinginkan)";
        }

        logSuperAdminAction('add_outlet', $tenantId,
            "Tambah outlet: {$wiz['nama_outlet']} ke tenant {$tenant['nama_outlet']} (slug: {$slug})"
        );

        $db->commit();

        return [
            'success'    => true,
            'outlet_id'  => $outletId,
            'slug'       => $slug,
            'note'       => $note,
        ];

    } catch (Throwable $e) {
        $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

$csrf = saGetCsrf();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Tambah Outlet'); ?>
<style>
.wiz-card {
  background: rgba(255,255,255,.03);
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 16px; padding: 28px;
  max-width: 580px;
}
.wiz-card h2 { font-size: 18px; font-weight: 800; margin-bottom: 6px; color: var(--white); }
.wiz-card .sub { font-size: 13px; color: rgba(255,255,255,.35); margin-bottom: 24px; }

.wiz-field { margin-bottom: 16px; }
.wiz-label { font-size: 11px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: rgba(255,255,255,.4); display: block; margin-bottom: 6px; }
.wiz-label .req { color: var(--red); }
.wiz-input, .wiz-select, .wiz-textarea {
  width: 100%; padding: 10px 14px;
  background: rgba(255,255,255,.06); border: 1.5px solid rgba(255,255,255,.1);
  border-radius: var(--r); color: var(--white); font-family: var(--font); font-size: 14px; outline: none;
  transition: border-color .15s;
}
.wiz-input:focus, .wiz-select:focus, .wiz-textarea:focus {
  border-color: var(--sa); box-shadow: 0 0 0 3px rgba(99,102,241,.12);
}
.wiz-textarea { resize: vertical; min-height: 72px; }
.wiz-select option { background: var(--navy); }

.field-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media(max-width:540px) { .field-grid-2 { grid-template-columns: 1fr; } }

.radio-group { display: flex; flex-direction: column; gap: 8px; }
.radio-opt {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 12px 14px; border-radius: 10px; cursor: pointer;
  border: 1.5px solid rgba(255,255,255,.08); background: rgba(255,255,255,.03);
  transition: all .15s;
}
.radio-opt:hover { border-color: rgba(99,102,241,.3); background: rgba(99,102,241,.05); }
.radio-opt input[type=radio] { margin-top: 2px; accent-color: var(--sa); flex-shrink: 0; }
.radio-opt .opt-label { font-size: 13px; font-weight: 600; color: var(--white); }
.radio-opt .opt-sub   { font-size: 11px; color: rgba(255,255,255,.35); margin-top: 2px; }
.radio-opt.selected   { border-color: var(--sa); background: var(--sa-l); }

.review-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 20px; }
.review-table td { padding: 10px 14px; border-bottom: 1px solid rgba(255,255,255,.05); }
.review-table tr:last-child td { border-bottom: none; }
.review-table td:first-child { color: rgba(255,255,255,.4); font-weight: 600; width: 45%; }
.review-table td:last-child { color: var(--white); }

.error-box {
  background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3);
  color: #FCA5A5; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 13px;
}
.wiz-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 28px; gap: 12px; }

.wizard-steps {
  display: flex; align-items: center; gap: 0;
  margin-bottom: 32px; overflow-x: auto;
}
.wstep {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 16px; border-radius: 10px;
  font-size: 13px; font-weight: 600; color: rgba(255,255,255,.3);
  flex-shrink: 0;
}
.wstep.done   { color: #6EE7B7; }
.wstep.active { color: var(--white); background: rgba(99,102,241,.12); border: 1px solid rgba(99,102,241,.25); }
.wstep-num {
  width: 28px; height: 28px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 800;
  background: rgba(255,255,255,.06); border: 1.5px solid rgba(255,255,255,.12);
  flex-shrink: 0;
}
.wstep.done .wstep-num   { background: rgba(16,185,129,.2); border-color: rgba(16,185,129,.4); color: #6EE7B7; }
.wstep.active .wstep-num { background: var(--sa-l); border-color: var(--sa); color: var(--sa); }
.wstep-connector { width: 24px; height: 2px; background: rgba(255,255,255,.08); flex-shrink: 0; margin: 0 -2px; }
.wstep-connector.done { background: rgba(16,185,129,.3); }
</style>
</head>
<body>
<?php saRenderNav('clients', 'Tambah Outlet'); ?>

<div style="max-width:640px;">

  <div class="sa-page-header" style="display:flex;align-items:center;gap:12px;">
    <a href="client_detail.php?id=<?= $tenantId ?>" class="sa-btn sa-btn-outline sa-btn-sm">&#x2190; Kembali</a>
    <div>
      <h1>Tambah Outlet</h1>
      <p>Tenant: <strong><?= htmlspecialchars($tenant['nama_outlet']) ?></strong> &mdash; <?= count($existingOutlets) ?> outlet aktif</p>
    </div>
  </div>

  <!-- Steps -->
  <div class="wizard-steps">
    <?php
    $steps = ['Data Outlet', 'Konfigurasi Coin', 'Review & Proses', 'Selesai'];
    foreach ($steps as $i => $label):
        $n = $i + 1;
        $cls = $n < $step ? 'done' : ($n === $step ? 'active' : '');
    ?>
      <?php if ($i > 0): ?><div class="wstep-connector <?= $n <= $step ? 'done' : '' ?>"></div><?php endif; ?>
      <div class="wstep <?= $cls ?>">
        <div class="wstep-num"><?= $n < $step ? '&#x2713;' : $n ?></div>
        <?= htmlspecialchars($label) ?>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($error): ?>
    <div class="error-box"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- ── STEP 1 ─────────────────────────────────── -->
  <?php if ($step === 1): ?>
  <div class="wiz-card">
    <h2>Data Outlet Baru</h2>
    <div class="sub">Informasi dasar outlet yang akan dibuat</div>
    <form method="POST">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>"/>
      <input type="hidden" name="step" value="1"/>

      <div class="wiz-field">
        <label class="wiz-label">Nama Outlet <span class="req">*</span></label>
        <input type="text" name="nama_outlet" class="wiz-input" required
               value="<?= htmlspecialchars($wiz['nama_outlet'] ?? '') ?>"
               placeholder="Harpy Laundry Cabang 2" />
      </div>

      <div class="field-grid-2">
        <div class="wiz-field">
          <label class="wiz-label">Kota</label>
          <input type="text" name="kota" class="wiz-input"
                 value="<?= htmlspecialchars($wiz['kota'] ?? '') ?>"
                 placeholder="Semarang" />
        </div>
        <div class="wiz-field">
          <label class="wiz-label">Telepon</label>
          <input type="text" name="telepon" class="wiz-input"
                 value="<?= htmlspecialchars($wiz['telepon'] ?? '') ?>"
                 placeholder="0812345678" />
        </div>
      </div>

      <div class="wiz-field">
        <label class="wiz-label">Alamat</label>
        <textarea name="alamat" class="wiz-textarea"
                  placeholder="Jl. Contoh No. 1, Semarang"><?= htmlspecialchars($wiz['alamat'] ?? '') ?></textarea>
      </div>

      <div class="wiz-footer">
        <span></span>
        <button type="submit" class="sa-btn sa-btn-primary">Lanjut &rarr;</button>
      </div>
    </form>
  </div>

  <!-- ── STEP 2 ─────────────────────────────────── -->
  <?php elseif ($step === 2): ?>
  <div class="wiz-card">
    <h2>Konfigurasi Coin</h2>
    <div class="sub">Atur saldo coin untuk outlet baru ini</div>
    <form method="POST">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>"/>
      <input type="hidden" name="step" value="2"/>

      <div style="background:rgba(99,102,241,.06);border:1px solid rgba(99,102,241,.15);border-radius:10px;padding:14px 16px;font-size:13px;margin-bottom:20px;">
        <strong>Mode Coin Tenant:</strong>
        <?php if ($tenant['coin_mode'] === 'per_outlet'): ?>
          <span style="color:#A5B4FC">Per Outlet</span> — setiap outlet punya saldo sendiri.
          <br><span style="color:rgba(255,255,255,.4)">Saldo shared saat ini: <?= number_format((int)$tenant['coin_balance'],0,',','.') ?> coin</span>
        <?php else: ?>
          <span style="color:#6EE7B7">Shared</span> — semua outlet berbagi saldo tenant.
          <br><span style="color:rgba(255,255,255,.4)">Saldo shared saat ini: <?= number_format((int)$tenant['coin_balance'],0,',','.') ?> coin</span>
        <?php endif; ?>
      </div>

      <?php if ($tenant['coin_mode'] === 'per_outlet'): ?>
        <div class="wiz-field">
          <label class="wiz-label">Saldo Coin Awal Outlet</label>
          <input type="number" name="coin_initial_balance" class="wiz-input" min="0"
                 value="<?= (int)($wiz['coin_initial_balance'] ?? 0) ?>"
                 placeholder="0" />
          <div style="font-size:11px;color:rgba(255,255,255,.3);margin-top:5px">
            Isi 0 jika tidak perlu saldo awal. Transfer manual dari shared balance jika diperlukan.
          </div>
        </div>
      <?php else: ?>
        <input type="hidden" name="coin_initial_balance" value="0"/>
        <p style="font-size:13px;color:rgba(255,255,255,.45);">
          Outlet ini akan otomatis berbagi saldo coin dengan seluruh tenant (<?= number_format((int)$tenant['coin_balance'],0,',','.') ?> coin).
          Tidak perlu setting saldo terpisah.
        </p>
      <?php endif; ?>

      <input type="hidden" name="coin_config" value="<?= $tenant['coin_mode'] === 'per_outlet' ? 'per_outlet' : 'shared_inherit' ?>"/>

      <div class="wiz-footer">
        <button type="button" class="sa-btn sa-btn-outline" onclick="goBack(1)">&larr; Kembali</button>
        <button type="submit" class="sa-btn sa-btn-primary">Lanjut &rarr;</button>
      </div>
    </form>
  </div>

  <!-- ── STEP 3: REVIEW ─────────────────────────── -->
  <?php elseif ($step === 3): ?>
  <div class="wiz-card">
    <h2>Review & Proses</h2>
    <div class="sub">Konfirmasi sebelum outlet dibuat</div>

    <table class="review-table">
      <tr><td>Nama Outlet</td><td><?= htmlspecialchars($wiz['nama_outlet'] ?? '-') ?></td></tr>
      <tr><td>Kota</td><td><?= htmlspecialchars($wiz['kota'] ?: '-') ?></td></tr>
      <tr><td>Telepon</td><td><?= htmlspecialchars($wiz['telepon'] ?: '-') ?></td></tr>
      <tr><td>Alamat</td><td><?= htmlspecialchars($wiz['alamat'] ?: '-') ?></td></tr>
      <tr><td>Mode Coin</td><td><?= $tenant['coin_mode'] === 'per_outlet' ? 'Per Outlet' : 'Shared' ?></td></tr>
      <?php if ($tenant['coin_mode'] === 'per_outlet'): ?>
        <tr><td>Saldo Awal</td><td><?= number_format((int)($wiz['coin_initial_balance'] ?? 0), 0, ',', '.') ?> coin</td></tr>
      <?php endif; ?>
    </table>

    <div style="background:rgba(99,102,241,.06);border:1px solid rgba(99,102,241,.15);border-radius:10px;padding:14px 16px;font-size:13px;color:rgba(255,255,255,.6);margin-bottom:20px;">
      Sistem akan membuat: <strong style="color:var(--white)">1 outlet &bull; 3 layanan default</strong>
    </div>

    <form method="POST">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>"/>
      <input type="hidden" name="step" value="3"/>
      <div class="wiz-footer">
        <button type="button" class="sa-btn sa-btn-outline" onclick="goBack(2)">&larr; Kembali</button>
        <button type="submit" class="sa-btn sa-btn-primary" id="provBtn" onclick="this.disabled=true;this.textContent='Memproses...'">
          Buat Outlet
        </button>
      </div>
    </form>
  </div>

  <!-- ── STEP 4: DONE ──────────────────────────── -->
  <?php elseif ($step === 4 && $result): ?>
  <div class="wiz-card" style="text-align:center;">
    <div style="font-size:48px;margin-bottom:16px;">&#x1F3EA;</div>
    <h2>Outlet Berhasil Dibuat!</h2>
    <p style="color:rgba(255,255,255,.45);margin:12px 0 24px;">
      Outlet <strong><?= htmlspecialchars($wiz['nama_outlet'] ?? '') ?></strong> sudah aktif
      dan dapat diakses oleh user tenant.
    </p>

    <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:16px;text-align:left;font-size:13px;margin-bottom:20px;">
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.05);">
        <span style="color:rgba(255,255,255,.4)">Outlet ID</span>
        <span style="font-family:var(--mono)">#<?= $result['outlet_id'] ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.05);">
        <span style="color:rgba(255,255,255,.4)">Slug</span>
        <span style="font-family:var(--mono)"><?= htmlspecialchars($result['slug']) ?></span>
      </div>
      <?php if ($result['note']): ?>
      <div style="padding:8px 0;color:#FCD34D;font-size:12px;"><?= htmlspecialchars($result['note']) ?></div>
      <?php endif; ?>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center;">
      <a href="add_outlet.php?tenant_id=<?= $tenantId ?>&reset=1" class="sa-btn sa-btn-primary">
        + Tambah Outlet Lagi
      </a>
      <a href="client_detail.php?id=<?= $tenantId ?>" class="sa-btn sa-btn-outline">
        Lihat Detail Client
      </a>
    </div>
  </div>
  <?php endif; ?>

</div>

</div></div>

<script>
function goBack(toStep) {
  const f = document.createElement('form');
  f.method = 'POST';
  f.innerHTML = `
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>"/>
    <input type="hidden" name="step" value="back"/>
    <input type="hidden" name="back_to" value="${toStep}"/>
  `;
  document.body.appendChild(f);
  f.submit();
}
function saOpenNav()  { document.getElementById('saSidebar').classList.add('open'); document.getElementById('saOverlay').classList.add('open'); }
function saCloseNav() { document.getElementById('saSidebar').classList.remove('open'); document.getElementById('saOverlay').classList.remove('open'); }
</script>
</body>
</html>
