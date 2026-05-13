<?php
// ══════════════════════════════════════════════════════
// superadmin/registration_wizard.php — 5-step provisioning wizard
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';
require_once SA_ROOT . '/../core/Database.php';

date_default_timezone_set('Asia/Jakarta');

$db = Database::get();

// ── Load existing registration request jika ada ───────
$regId  = (int)($_GET['id'] ?? 0);
$regRow = null;
if ($regId) {
    $s = $db->prepare("SELECT * FROM registration_requests WHERE id=? LIMIT 1");
    $s->execute([$regId]);
    $regRow = $s->fetch();
}

// ── Init wizard session ───────────────────────────────
if (isset($_GET['new']) || empty($_SESSION['sa_wizard'])) {
    $_SESSION['sa_wizard'] = ['step' => 1, 'reg_id' => $regId];
    if ($regRow) {
        $_SESSION['sa_wizard']['nama_outlet']      = $regRow['nama_outlet'] ?? '';
        $_SESSION['sa_wizard']['nama_perusahaan']  = $regRow['nama_perusahaan'] ?? '';
        $_SESSION['sa_wizard']['owner_name']       = $regRow['owner_name'] ?? '';
        $_SESSION['sa_wizard']['owner_wa']         = $regRow['owner_wa'] ?? '';
        $_SESSION['sa_wizard']['kota']             = $regRow['kota'] ?? '';
        $_SESSION['sa_wizard']['source']           = $regRow['source'] ?? 'assisted';
        $_SESSION['sa_wizard']['notes']            = $regRow['notes'] ?? '';
        $_SESSION['sa_wizard']['setup_fee']        = (int)($regRow['setup_fee'] ?? 300000);
        $_SESSION['sa_wizard']['coin_awal']        = (int)($regRow['coin_awal'] ?? 50000);
        $_SESSION['sa_wizard']['trial_days']       = (int)($regRow['trial_days'] ?? 30);
        $_SESSION['sa_wizard']['coin_mode']        = $regRow['coin_mode'] ?? 'shared';
    }
}

$wiz   = &$_SESSION['sa_wizard'];
$step  = (int)($wiz['step'] ?? 1);
$error = '';
$result = null; // provisioning result

// ── Handle POST ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saVerifyCsrf();
    $pStep = (int)($_POST['step'] ?? 1);

    if ($pStep === 1) {
        $wiz['nama_outlet']     = substr(trim(strip_tags($_POST['nama_outlet'] ?? '')), 0, 100);
        $wiz['nama_perusahaan'] = substr(trim(strip_tags($_POST['nama_perusahaan'] ?? '')), 0, 100);
        $wiz['owner_name']      = substr(trim(strip_tags($_POST['owner_name'] ?? '')), 0, 100);
        $wiz['owner_wa']        = substr(trim(preg_replace('/[^0-9+\-\s]/', '', $_POST['owner_wa'] ?? '')), 0, 20);
        $wiz['kota']            = substr(trim(strip_tags($_POST['kota'] ?? '')), 0, 100);
        $wiz['source']          = in_array($_POST['source'] ?? '', ['self_service','assisted']) ? $_POST['source'] : 'assisted';
        $wiz['notes']           = substr(trim(strip_tags($_POST['notes'] ?? '')), 0, 500);

        if (!$wiz['nama_outlet'] || !$wiz['owner_name'] || !$wiz['owner_wa']) {
            $error = 'Nama outlet, nama owner, dan nomor WA wajib diisi.';
        } else {
            $wiz['step'] = 2; $step = 2;
        }
    }

    elseif ($pStep === 2) {
        $feeRaw = $_POST['setup_fee_type'] ?? 'standard';
        if ($feeRaw === 'standard') $wiz['setup_fee'] = 300000;
        elseif ($feeRaw === 'premium') $wiz['setup_fee'] = 500000;
        elseif ($feeRaw === 'free') $wiz['setup_fee'] = 0;
        else $wiz['setup_fee'] = max(0, (int)($_POST['setup_fee_custom'] ?? 0));

        $wiz['coin_awal']  = max(0, (int)($_POST['coin_awal'] ?? 50000));
        $wiz['trial_days'] = max(0, (int)($_POST['trial_days'] ?? 30));
        $wiz['coin_mode']  = in_array($_POST['coin_mode'] ?? '', ['shared','per_outlet']) ? $_POST['coin_mode'] : 'shared';
        $wiz['step'] = 3; $step = 3;
    }

    elseif ($pStep === 3) {
        $ps = $_POST['payment_status'] ?? 'belum_bayar';
        $wiz['payment_status'] = in_array($ps, ['belum_bayar','sudah_bayar','gratis']) ? $ps : 'belum_bayar';
        $wiz['gateway_ref']    = substr(trim($_POST['gateway_ref'] ?? ''), 0, 100);
        $wiz['paid_at']        = $_POST['paid_at'] ?: null;
        $wiz['step'] = 4; $step = 4;
    }

    elseif ($pStep === 4) {
        // Run provisioning
        $provResult = provisionTenant($wiz);
        if ($provResult['success']) {
            $wiz['step'] = 5;
            $step = 5;
            $result = $provResult;
            $wiz['result'] = $provResult;
        } else {
            $error = 'Provisioning gagal: ' . htmlspecialchars($provResult['error']);
            $step  = 4;
        }
    }

    elseif ($pStep === 'back') {
        $backTo = max(1, (int)($_POST['back_to'] ?? ($step - 1)));
        $wiz['step'] = $backTo; $step = $backTo;
    }
}

// If step 5, load result from session if not fresh
if ($step === 5 && !$result && !empty($wiz['result'])) {
    $result = $wiz['result'];
}

// ── Provisioning function ─────────────────────────────
function provisionTenant(array $wizard): array
{
    $db = Database::get();
    $db->beginTransaction();
    try {
        // 1. Generate unique slug
        $slugBase = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $wizard['nama_outlet']));
        $slugBase = trim($slugBase, '_') ?: 'outlet';
        $slug = $slugBase;
        $i = 2;
        while (true) {
            $chk = $db->prepare("SELECT COUNT(*) FROM tenants WHERE slug=?");
            $chk->execute([$slug]);
            if ((int)$chk->fetchColumn() === 0) break;
            $slug = $slugBase . '_' . $i++;
        }

        // 2. Insert tenant
        $trialEnds = date('Y-m-d H:i:s', strtotime('+' . ((int)$wizard['trial_days']) . ' days'));
        $db->prepare(
            "INSERT INTO tenants (slug, db_name, nama_outlet, owner_name, owner_wa, status, coin_balance, coin_mode, total_outlets, trial_ends_at, provisioned_at)
             VALUES (?,?,?,?,?,?,?,?,0,?,NOW())"
        )->execute([
            $slug,
            'u269895997_harpy_master',
            $wizard['nama_perusahaan'] ?: $wizard['nama_outlet'],
            $wizard['owner_name'],
            $wizard['owner_wa'],
            'trial',
            $wizard['coin_mode'] === 'shared' ? (int)$wizard['coin_awal'] : 0,
            $wizard['coin_mode'],
            $trialEnds,
        ]);
        $tenantId = (int)$db->lastInsertId();

        // 3. Insert outlet
        $outletSlug = $slug . '_outlet1';
        $db->prepare(
            "INSERT INTO outlets (tenant_id, nama_outlet, slug, kota, status, coin_balance, is_main, setup_done)
             VALUES (?,?,?,?,?,?,1,0)"
        )->execute([
            $tenantId,
            $wizard['nama_outlet'],
            $outletSlug,
            $wizard['kota'] ?? '',
            'active',
            $wizard['coin_mode'] === 'per_outlet' ? (int)$wizard['coin_awal'] : 0,
        ]);
        $outletId = (int)$db->lastInsertId();

        // Update total_outlets
        $db->prepare("UPDATE tenants SET total_outlets=1 WHERE id=?")->execute([$tenantId]);

        // 4. Generate credentials
        $password  = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 8);
        $hashedPw  = password_hash($password, PASSWORD_BCRYPT);
        $username  = strtolower(preg_replace('/[^a-z0-9]/i', '', $wizard['owner_name']));
        if (!$username) $username = 'owner' . $tenantId;

        // Ensure unique username
        $uChk = $db->prepare("SELECT COUNT(*) FROM hl_users WHERE username=?");
        $uChk->execute([$username]);
        if ((int)$uChk->fetchColumn() > 0) $username .= $tenantId;

        // 5. Create admin user
        $db->prepare(
            "INSERT INTO hl_users (tenant_id, username, password, nama, role, is_active, created_at)
             VALUES (?,?,?,?,'admin',1,NOW())"
        )->execute([$tenantId, $username, $hashedPw, $wizard['owner_name']]);

        // 6. Seed default services
        $defaultLayanan = [
            ['nama' => 'Cuci Kering',   'harga' => 6000,  'satuan' => 'kg', 'kategori' => 'reguler'],
            ['nama' => 'Cuci Setrika',  'harga' => 8000,  'satuan' => 'kg', 'kategori' => 'reguler'],
            ['nama' => 'Express',       'harga' => 15000, 'satuan' => 'kg', 'kategori' => 'express'],
        ];
        $lStmt = $db->prepare(
            "INSERT INTO hl_layanan (tenant_id, outlet_id, nama, harga, satuan, kategori, is_active, urutan, created_at)
             VALUES (?,?,?,?,?,?,1,0,NOW())"
        );
        foreach ($defaultLayanan as $l) {
            $lStmt->execute([$tenantId, $outletId, $l['nama'], $l['harga'], $l['satuan'], $l['kategori']]);
        }

        // 7. Payment record
        $payStatus = $wizard['payment_status'] ?? 'belum_bayar';
        if ($payStatus !== 'belum_bayar' && (int)$wizard['setup_fee'] > 0) {
            $paidAt = $payStatus === 'gratis'
                ? date('Y-m-d H:i:s')
                : (!empty($wizard['paid_at']) ? $wizard['paid_at'] . ' 00:00:00' : date('Y-m-d H:i:s'));
            try {
                $db->prepare(
                    "INSERT INTO payments (tenant_id, outlet_id, type, amount, gateway_ref, status, paid_at, created_at)
                     VALUES (?,?,?,?,?,?,?,NOW())"
                )->execute([
                    $tenantId, $outletId, 'setup_fee',
                    (int)$wizard['setup_fee'],
                    $wizard['gateway_ref'] ?: null,
                    'success', $paidAt,
                ]);
            } catch (Throwable) {
                // payments table might not exist — non-fatal
            }
        }

        // 8. Update or create registration_request record
        if (!empty($wizard['reg_id'])) {
            $db->prepare(
                "UPDATE registration_requests SET status='completed', tenant_id=?, outlet_id=?, updated_at=NOW() WHERE id=?"
            )->execute([$tenantId, $outletId, (int)$wizard['reg_id']]);
        } else {
            $db->prepare(
                "INSERT INTO registration_requests
                 (source, nama_outlet, owner_name, owner_wa, kota, status, payment_status, setup_fee, coin_awal, trial_days, coin_mode, tenant_id, outlet_id, handled_by, created_at)
                 VALUES (?,?,?,?,?,'completed',?,?,?,?,?,?,?,?,NOW())"
            )->execute([
                $wizard['source'] ?? 'assisted',
                $wizard['nama_outlet'],
                $wizard['owner_name'],
                $wizard['owner_wa'],
                $wizard['kota'] ?? '',
                $payStatus === 'belum_bayar' ? 'pending' : 'paid',
                (int)$wizard['setup_fee'],
                (int)$wizard['coin_awal'],
                (int)$wizard['trial_days'],
                $wizard['coin_mode'],
                $tenantId, $outletId,
                $_SESSION['superadmin_id'] ?? null,
            ]);
        }

        // 9. Log action
        logSuperAdminAction('provision_tenant', $tenantId,
            "Provisioned: {$wizard['nama_outlet']} | Owner: {$wizard['owner_name']} | WA: {$wizard['owner_wa']}"
        );

        $db->commit();

        $waMsg = "Halo *{$wizard['owner_name']}*\n\nSelamat datang di *Harpy Laundry ERP*!\n\nAkun Anda sudah aktif:\n\n"
            . "Link Login: https://harpy.id/ERP/harpy/login.php\n"
            . "Username: *{$username}*\n"
            . "Password: *{$password}*\n\n"
            . "Silakan login dan mulai setup outlet Anda.\n"
            . "Jika butuh bantuan, hubungi kami.\n\n_Tim Harpy_";

        return [
            'success'    => true,
            'tenant_id'  => $tenantId,
            'outlet_id'  => $outletId,
            'username'   => $username,
            'password'   => $password,
            'wa_message' => $waMsg,
            'wa_number'  => preg_replace('/[^0-9]/', '', $wizard['owner_wa']),
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
<?php saRenderHead('Wizard Registrasi'); ?>
<style>
/* ── Wizard header steps ────────────────────────── */
.wizard-steps {
  display: flex; align-items: center; gap: 0;
  margin-bottom: 32px; overflow-x: auto; padding-bottom: 4px;
}
.wstep {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 16px; border-radius: 10px;
  font-size: 13px; font-weight: 600; color: rgba(255,255,255,.3);
  flex-shrink: 0; position: relative;
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
.wstep-connector {
  width: 24px; height: 2px;
  background: rgba(255,255,255,.08); flex-shrink: 0; margin: 0 -2px;
}
.wstep-connector.done { background: rgba(16,185,129,.3); }

/* ── Form card ──────────────────────────────────── */
.wiz-card {
  background: rgba(255,255,255,.03);
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 16px; padding: 28px;
  max-width: 640px;
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
.wiz-textarea { resize: vertical; min-height: 80px; }
.wiz-select option { background: var(--navy); }
.field-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media(max-width:540px) { .field-grid-2 { grid-template-columns: 1fr; } }

/* Radio options */
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

/* Review table */
.review-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 20px; }
.review-table td { padding: 10px 14px; border-bottom: 1px solid rgba(255,255,255,.05); }
.review-table tr:last-child td { border-bottom: none; }
.review-table td:first-child { color: rgba(255,255,255,.4); font-weight: 600; width: 45%; }
.review-table td:last-child { color: var(--white); font-weight: 500; }

/* Done screen */
.done-screen { text-align: center; padding: 20px 0; }
.done-icon { font-size: 56px; margin-bottom: 16px; }
.done-creds {
  background: rgba(99,102,241,.08); border: 1.5px solid rgba(99,102,241,.2);
  border-radius: 12px; padding: 18px 20px; text-align: left;
  margin: 20px 0; font-family: var(--mono);
}
.done-creds .cred-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; font-size: 13px; }
.done-creds .cred-key   { color: rgba(255,255,255,.45); }
.done-creds .cred-value { color: var(--white); font-weight: 700; }
.wa-preview {
  background: rgba(37,211,102,.06); border: 1.5px solid rgba(37,211,102,.15);
  border-radius: 12px; padding: 16px 18px; text-align: left; margin: 16px 0;
  font-size: 13px; color: rgba(255,255,255,.8); white-space: pre-wrap; line-height: 1.6;
}
.copy-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600;
  background: rgba(37,211,102,.12); border: 1px solid rgba(37,211,102,.25);
  color: #86efac; cursor: pointer; transition: all .15s;
}
.copy-btn:hover { background: rgba(37,211,102,.25); color: #fff; }

.error-box {
  background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3);
  color: #FCA5A5; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 13px;
}

.wiz-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 28px; gap: 12px; }
</style>
</head>
<body>
<?php saRenderNav('registrations', 'Wizard Registrasi Klien'); ?>

<div style="max-width: 700px;">

  <div class="sa-page-header" style="display:flex;align-items:center;gap:12px;">
    <a href="registrations.php" class="sa-btn sa-btn-outline sa-btn-sm">&#x2190; Kembali</a>
    <div>
      <h1>Wizard Registrasi</h1>
      <p>Provisioning tenant baru <?= $regRow ? '— Ref #' . $regId : '' ?></p>
    </div>
  </div>

  <!-- Step indicators -->
  <div class="wizard-steps">
    <?php
    $steps = ['Data Klien', 'Paket', 'Pembayaran', 'Review', 'Selesai'];
    foreach ($steps as $i => $label):
        $n = $i + 1;
        $cls = $n < $step ? 'done' : ($n === $step ? 'active' : '');
    ?>
      <?php if ($i > 0): ?>
        <div class="wstep-connector <?= $n <= $step ? 'done' : '' ?>"></div>
      <?php endif; ?>
      <div class="wstep <?= $cls ?>">
        <div class="wstep-num"><?= $n < $step ? '&#x2713;' : $n ?></div>
        <?= htmlspecialchars($label) ?>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($error): ?>
    <div class="error-box"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- ── STEP 1: DATA KLIEN ──────────────────────── -->
  <?php if ($step === 1): ?>
  <div class="wiz-card">
    <h2>Data Klien</h2>
    <div class="sub">Informasi dasar tentang outlet dan pemilik</div>
    <form method="POST">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>"/>
      <input type="hidden" name="step" value="1"/>

      <div class="field-grid-2">
        <div class="wiz-field">
          <label class="wiz-label">Nama Outlet <span class="req">*</span></label>
          <input type="text" name="nama_outlet" class="wiz-input" required
                 placeholder="Harpy Laundry Semarang" value="<?= htmlspecialchars($wiz['nama_outlet'] ?? '') ?>"/>
        </div>
        <div class="wiz-field">
          <label class="wiz-label">Nama Perusahaan / Brand</label>
          <input type="text" name="nama_perusahaan" class="wiz-input"
                 placeholder="Opsional" value="<?= htmlspecialchars($wiz['nama_perusahaan'] ?? '') ?>"/>
        </div>
      </div>

      <div class="field-grid-2">
        <div class="wiz-field">
          <label class="wiz-label">Nama Owner <span class="req">*</span></label>
          <input type="text" name="owner_name" class="wiz-input" required
                 placeholder="Budi Santoso" value="<?= htmlspecialchars($wiz['owner_name'] ?? '') ?>"/>
        </div>
        <div class="wiz-field">
          <label class="wiz-label">No WA Owner <span class="req">*</span></label>
          <input type="text" name="owner_wa" class="wiz-input" required
                 placeholder="081234567890" value="<?= htmlspecialchars($wiz['owner_wa'] ?? '') ?>"/>
        </div>
      </div>

      <div class="field-grid-2">
        <div class="wiz-field">
          <label class="wiz-label">Kota</label>
          <input type="text" name="kota" class="wiz-input"
                 placeholder="Semarang" value="<?= htmlspecialchars($wiz['kota'] ?? '') ?>"/>
        </div>
        <div class="wiz-field">
          <label class="wiz-label">Sumber</label>
          <select name="source" class="wiz-select">
            <option value="assisted" <?= ($wiz['source'] ?? 'assisted') === 'assisted' ? 'selected' : '' ?>>Assisted (oleh CS)</option>
            <option value="self_service" <?= ($wiz['source'] ?? '') === 'self_service' ? 'selected' : '' ?>>Self Service</option>
          </select>
        </div>
      </div>

      <div class="wiz-field">
        <label class="wiz-label">Catatan Internal</label>
        <textarea name="notes" class="wiz-textarea" placeholder="Opsional..."><?= htmlspecialchars($wiz['notes'] ?? '') ?></textarea>
      </div>

      <div class="wiz-footer">
        <span></span>
        <button type="submit" class="sa-btn sa-btn-primary">Lanjut &rarr;</button>
      </div>
    </form>
  </div>

  <!-- ── STEP 2: PAKET ──────────────────────────── -->
  <?php elseif ($step === 2): ?>
  <div class="wiz-card">
    <h2>Paket & Konfigurasi</h2>
    <div class="sub">Setup fee, coin awal, dan trial</div>
    <form method="POST" id="step2Form">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>"/>
      <input type="hidden" name="step" value="2"/>

      <div class="wiz-field">
        <label class="wiz-label">Setup Fee</label>
        <div class="radio-group">
          <?php $curFee = (int)($wiz['setup_fee'] ?? 300000); ?>
          <?php $feeType = $curFee === 300000 ? 'standard' : ($curFee === 500000 ? 'premium' : ($curFee === 0 ? 'free' : 'custom')); ?>
          <label class="radio-opt <?= $feeType === 'standard' ? 'selected' : '' ?>" onclick="selectRadio(this,'setup_fee_type','standard')">
            <input type="radio" name="setup_fee_type" value="standard" <?= $feeType === 'standard' ? 'checked' : '' ?> onchange="updateFeeOpts(this)"/>
            <div><div class="opt-label">Standar — Rp 300.000</div><div class="opt-sub">Paket default untuk UMKM laundry</div></div>
          </label>
          <label class="radio-opt <?= $feeType === 'premium' ? 'selected' : '' ?>" onclick="selectRadio(this,'setup_fee_type','premium')">
            <input type="radio" name="setup_fee_type" value="premium" <?= $feeType === 'premium' ? 'checked' : '' ?> onchange="updateFeeOpts(this)"/>
            <div><div class="opt-label">Premium — Rp 500.000</div><div class="opt-sub">Termasuk setup & onboarding intensif</div></div>
          </label>
          <label class="radio-opt <?= $feeType === 'free' ? 'selected' : '' ?>" onclick="selectRadio(this,'setup_fee_type','free')">
            <input type="radio" name="setup_fee_type" value="free" <?= $feeType === 'free' ? 'checked' : '' ?> onchange="updateFeeOpts(this)"/>
            <div><div class="opt-label">Gratis</div><div class="opt-sub">Untuk demo / partnership</div></div>
          </label>
          <label class="radio-opt <?= $feeType === 'custom' ? 'selected' : '' ?>" onclick="selectRadio(this,'setup_fee_type','custom')">
            <input type="radio" name="setup_fee_type" value="custom" <?= $feeType === 'custom' ? 'checked' : '' ?> onchange="updateFeeOpts(this)"/>
            <div style="width:100%">
              <div class="opt-label">Custom</div>
              <input type="number" name="setup_fee_custom" id="customFeeInput"
                     class="wiz-input" style="margin-top:8px;<?= $feeType !== 'custom' ? 'display:none' : '' ?>"
                     placeholder="Nominal custom" min="0" value="<?= $feeType === 'custom' ? $curFee : '' ?>"/>
            </div>
          </label>
        </div>
      </div>

      <div class="field-grid-2">
        <div class="wiz-field">
          <label class="wiz-label">Coin Awal</label>
          <input type="number" name="coin_awal" class="wiz-input" min="0"
                 value="<?= (int)($wiz['coin_awal'] ?? 50000) ?>" placeholder="50000"/>
          <div style="font-size:11px;color:rgba(255,255,255,.3);margin-top:5px">Standar: 50.000 coin</div>
        </div>
        <div class="wiz-field">
          <label class="wiz-label">Trial Days</label>
          <input type="number" name="trial_days" class="wiz-input" min="0"
                 value="<?= (int)($wiz['trial_days'] ?? 30) ?>" placeholder="30"/>
          <div style="font-size:11px;color:rgba(255,255,255,.3);margin-top:5px">0 = tidak ada trial</div>
        </div>
      </div>

      <div class="wiz-field">
        <label class="wiz-label">Mode Coin</label>
        <div class="radio-group">
          <?php $cm = $wiz['coin_mode'] ?? 'shared'; ?>
          <label class="radio-opt <?= $cm === 'shared' ? 'selected' : '' ?>" onclick="selectRadio(this,'coin_mode','shared')">
            <input type="radio" name="coin_mode" value="shared" <?= $cm === 'shared' ? 'checked' : '' ?>/>
            <div><div class="opt-label">Shared</div><div class="opt-sub">Semua outlet berbagi 1 saldo coin dari tenant</div></div>
          </label>
          <label class="radio-opt <?= $cm === 'per_outlet' ? 'selected' : '' ?>" onclick="selectRadio(this,'coin_mode','per_outlet')">
            <input type="radio" name="coin_mode" value="per_outlet" <?= $cm === 'per_outlet' ? 'checked' : '' ?>/>
            <div><div class="opt-label">Per Outlet</div><div class="opt-sub">Setiap outlet punya saldo coin sendiri</div></div>
          </label>
        </div>
      </div>

      <div class="wiz-footer">
        <button type="submit" name="step" value="back" formaction="?<?= $regId ? 'id='.$regId : '' ?>" class="sa-btn sa-btn-outline"
                onclick="document.querySelector('[name=step]').value='back';document.querySelector('[name=back_to]')?.remove();
                         let i=document.createElement('input');i.name='back_to';i.value='1';i.type='hidden';this.form.append(i);">
          &larr; Kembali
        </button>
        <button type="submit" class="sa-btn sa-btn-primary">Lanjut &rarr;</button>
      </div>
    </form>
  </div>

  <!-- ── STEP 3: PEMBAYARAN ─────────────────────── -->
  <?php elseif ($step === 3): ?>
  <div class="wiz-card">
    <h2>Status Pembayaran</h2>
    <div class="sub">Konfirmasi setup fee dari klien</div>
    <form method="POST">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>"/>
      <input type="hidden" name="step" value="3"/>

      <?php $ps = $wiz['payment_status'] ?? 'belum_bayar'; ?>
      <div class="wiz-field">
        <label class="wiz-label">Status Pembayaran</label>
        <div class="radio-group">
          <label class="radio-opt <?= $ps === 'belum_bayar' ? 'selected' : '' ?>" onclick="selectRadio(this,'payment_status','belum_bayar');togglePayFields('belum_bayar')">
            <input type="radio" name="payment_status" value="belum_bayar" <?= $ps === 'belum_bayar' ? 'checked' : '' ?> onchange="togglePayFields('belum_bayar')"/>
            <div><div class="opt-label">Belum Bayar</div><div class="opt-sub">Akun tetap dibuat, payment menyusul</div></div>
          </label>
          <label class="radio-opt <?= $ps === 'sudah_bayar' ? 'selected' : '' ?>" onclick="selectRadio(this,'payment_status','sudah_bayar');togglePayFields('sudah_bayar')">
            <input type="radio" name="payment_status" value="sudah_bayar" <?= $ps === 'sudah_bayar' ? 'checked' : '' ?> onchange="togglePayFields('sudah_bayar')"/>
            <div><div class="opt-label">Sudah Bayar</div><div class="opt-sub">Masukkan referensi dan tanggal bayar</div></div>
          </label>
          <label class="radio-opt <?= $ps === 'gratis' ? 'selected' : '' ?>" onclick="selectRadio(this,'payment_status','gratis');togglePayFields('gratis')">
            <input type="radio" name="payment_status" value="gratis" <?= $ps === 'gratis' ? 'checked' : '' ?> onchange="togglePayFields('gratis')"/>
            <div><div class="opt-label">Gratis / Voucher</div><div class="opt-sub">Setup fee di-waive</div></div>
          </label>
        </div>
      </div>

      <div id="payFields" style="<?= $ps !== 'sudah_bayar' ? 'display:none' : '' ?>">
        <div class="field-grid-2">
          <div class="wiz-field">
            <label class="wiz-label">Referensi / No. Transfer</label>
            <input type="text" name="gateway_ref" class="wiz-input"
                   placeholder="TRF-20250501-001" value="<?= htmlspecialchars($wiz['gateway_ref'] ?? '') ?>"/>
          </div>
          <div class="wiz-field">
            <label class="wiz-label">Tanggal Bayar</label>
            <input type="date" name="paid_at" class="wiz-input"
                   value="<?= htmlspecialchars($wiz['paid_at'] ?? date('Y-m-d')) ?>"/>
          </div>
        </div>
      </div>

      <div class="wiz-footer">
        <button type="button" class="sa-btn sa-btn-outline" onclick="goBack(2)">&larr; Kembali</button>
        <button type="submit" class="sa-btn sa-btn-primary">Lanjut &rarr;</button>
      </div>
    </form>
  </div>

  <!-- ── STEP 4: REVIEW ─────────────────────────── -->
  <?php elseif ($step === 4): ?>
  <div class="wiz-card">
    <h2>Review & Konfirmasi</h2>
    <div class="sub">Periksa semua data sebelum provisioning dijalankan</div>

    <table class="review-table">
      <tr><td>Nama Outlet</td><td><?= htmlspecialchars($wiz['nama_outlet'] ?? '-') ?></td></tr>
      <tr><td>Nama Perusahaan</td><td><?= htmlspecialchars($wiz['nama_perusahaan'] ?: '-') ?></td></tr>
      <tr><td>Owner</td><td><?= htmlspecialchars($wiz['owner_name'] ?? '-') ?></td></tr>
      <tr><td>WhatsApp</td><td><?= htmlspecialchars($wiz['owner_wa'] ?? '-') ?></td></tr>
      <tr><td>Kota</td><td><?= htmlspecialchars($wiz['kota'] ?: '-') ?></td></tr>
      <tr><td>Sumber</td><td><?= $wiz['source'] === 'self_service' ? 'Self Service' : 'Assisted' ?></td></tr>
      <tr><td colspan="2" style="padding-top:14px;font-weight:700;color:rgba(255,255,255,.6);font-size:11px;letter-spacing:.08em;text-transform:uppercase;">Paket</td></tr>
      <tr><td>Setup Fee</td><td>Rp <?= number_format((int)($wiz['setup_fee'] ?? 0), 0, ',', '.') ?></td></tr>
      <tr><td>Coin Awal</td><td><?= number_format((int)($wiz['coin_awal'] ?? 0), 0, ',', '.') ?> coin</td></tr>
      <tr><td>Trial</td><td><?= (int)($wiz['trial_days'] ?? 0) ?> hari</td></tr>
      <tr><td>Mode Coin</td><td><?= $wiz['coin_mode'] === 'per_outlet' ? 'Per Outlet' : 'Shared' ?></td></tr>
      <tr><td colspan="2" style="padding-top:14px;font-weight:700;color:rgba(255,255,255,.6);font-size:11px;letter-spacing:.08em;text-transform:uppercase;">Pembayaran</td></tr>
      <tr><td>Status Bayar</td><td>
        <?php $psMap = ['belum_bayar'=>'Belum Bayar','sudah_bayar'=>'Sudah Bayar','gratis'=>'Gratis']; ?>
        <?= $psMap[$wiz['payment_status'] ?? 'belum_bayar'] ?? '-' ?>
      </td></tr>
      <?php if (($wiz['payment_status'] ?? '') === 'sudah_bayar' && $wiz['gateway_ref']): ?>
        <tr><td>Ref</td><td><?= htmlspecialchars($wiz['gateway_ref']) ?></td></tr>
      <?php endif; ?>
    </table>

    <div style="background:rgba(99,102,241,.06);border:1px solid rgba(99,102,241,.15);border-radius:10px;padding:14px 16px;font-size:13px;color:rgba(255,255,255,.6);margin-bottom:20px;">
      Sistem akan membuat: <strong style="color:var(--white)">1 tenant &bull; 1 outlet &bull; 1 user admin &bull; 3 layanan default</strong>
    </div>

    <form method="POST" id="provisionForm">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>"/>
      <input type="hidden" name="step" value="4"/>

      <div class="wiz-footer">
        <button type="button" class="sa-btn sa-btn-outline" onclick="goBack(3)">&larr; Kembali</button>
        <button type="submit" class="sa-btn sa-btn-primary" id="provBtn" onclick="startProvision()">
          Proses Sekarang
        </button>
      </div>
    </form>
  </div>

  <!-- ── STEP 5: DONE ──────────────────────────── -->
  <?php elseif ($step === 5 && $result): ?>
  <div class="wiz-card" style="max-width:700px;">
    <div class="done-screen">
      <div class="done-icon">&#x1F389;</div>
      <h2 style="font-size:22px;margin-bottom:8px;">Provisioning Berhasil!</h2>
      <p style="color:rgba(255,255,255,.45);margin-bottom:24px;">
        Tenant <strong><?= htmlspecialchars($wiz['nama_outlet'] ?? '') ?></strong> sudah aktif.
      </p>

      <div class="done-creds">
        <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.3);margin-bottom:10px;">Kredensial Login (tampil sekali)</div>
        <div class="cred-row">
          <span class="cred-key">URL Login</span>
          <span class="cred-value">harpy.id/ERP/harpy/login.php</span>
        </div>
        <div class="cred-row">
          <span class="cred-key">Username</span>
          <span class="cred-value"><?= htmlspecialchars($result['username']) ?></span>
        </div>
        <div class="cred-row">
          <span class="cred-key">Password</span>
          <span class="cred-value" style="color:#6EE7B7"><?= htmlspecialchars($result['password']) ?></span>
        </div>
        <div class="cred-row">
          <span class="cred-key">Tenant ID</span>
          <span class="cred-value">#<?= $result['tenant_id'] ?></span>
        </div>
      </div>

      <div style="text-align:left;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;">
        <span style="font-size:13px;font-weight:700;color:rgba(255,255,255,.5)">Pesan WA untuk klien</span>
        <button class="copy-btn" onclick="copyWa()">&#x1F4CB; Copy Pesan</button>
      </div>
      <div class="wa-preview" id="waPreview"><?= htmlspecialchars($result['wa_message']) ?></div>

      <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center;margin-top:20px;">
        <a href="https://wa.me/<?= htmlspecialchars($result['wa_number']) ?>?text=<?= urlencode($result['wa_message']) ?>"
           target="_blank" class="sa-btn sa-btn-wa">
          &#x1F4AC; Kirim via WA
        </a>
        <a href="client_detail.php?id=<?= $result['tenant_id'] ?>" class="sa-btn sa-btn-outline">
          Lihat Detail Client
        </a>
        <a href="registration_wizard.php?new=1" class="sa-btn sa-btn-primary">
          Daftarkan Lagi
        </a>
        <a href="registrations.php" class="sa-btn sa-btn-outline">
          Kembali ke Daftar
        </a>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /.max-width -->

</div></div><!-- close sa-main + sa-content -->

<script>
// Back nav via hidden form
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

function selectRadio(label, name, val) {
  document.querySelectorAll('.radio-opt').forEach(l => {
    if (l.querySelector(`[name="${name}"]`)) l.classList.remove('selected');
  });
  label.classList.add('selected');
  const inp = label.querySelector('input[type=radio]');
  if (inp) inp.checked = true;
}

function updateFeeOpts(radio) {
  const customInput = document.getElementById('customFeeInput');
  if (!customInput) return;
  customInput.style.display = radio.value === 'custom' ? 'block' : 'none';
}

function togglePayFields(status) {
  const f = document.getElementById('payFields');
  if (f) f.style.display = status === 'sudah_bayar' ? 'block' : 'none';
}

function startProvision() {
  const btn = document.getElementById('provBtn');
  if (btn) { btn.disabled = true; btn.textContent = 'Memproses...'; }
}

async function copyWa() {
  const txt = document.getElementById('waPreview')?.textContent || '';
  try {
    await navigator.clipboard.writeText(txt);
    showToast('Pesan WA berhasil di-copy!', 'success');
  } catch {
    showToast('Gagal copy — select manual', 'error');
  }
}

function showToast(msg, type='success') {
  let t = document.getElementById('toast');
  if (!t) { t = document.createElement('div'); t.id='toast'; t.className='sa-toast'; document.body.appendChild(t); }
  t.textContent = msg;
  t.className = `sa-toast ${type} show`;
  setTimeout(() => t.classList.remove('show'), 3000);
}

function saOpenNav()  { document.getElementById('saSidebar').classList.add('open'); document.getElementById('saOverlay').classList.add('open'); }
function saCloseNav() { document.getElementById('saSidebar').classList.remove('open'); document.getElementById('saOverlay').classList.remove('open'); }
</script>
</body>
</html>
