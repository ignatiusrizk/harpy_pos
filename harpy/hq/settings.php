<?php
// ══════════════════════════════════════════════════════
// hq/settings.php — Account Settings (HQ View)
// Brief HQ-Outlet Section 4.7
//
// Fitur:
//   - Profil perusahaan: nama brand, owner_name, owner_wa, kota
//   - Email login (read-only)
//   - Ganti password
//   - Coin mode (read-only)
//   - Saldo coin tenant + per outlet
//   - Request topup coin per outlet (WA placeholder — payment gateway TODO)
// ══════════════════════════════════════════════════════

$activePage = 'hq-settings';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];
$uid  = (int)($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? '';

// ── Helper audit log ──────────────────────────────────
function logAcc(PDO $db, int $tid, int $uid, string $what): void {
    try {
        $db->prepare("INSERT INTO superadmin_logs (action, target_type, target_id, details, created_at)
                      VALUES ('account_settings','tenant',?,?,NOW())")
           ->execute([$tid, json_encode(['by'=>$uid,'change'=>$what])]);
    } catch (Throwable) {}
}

// ── POST handlers ─────────────────────────────────────
$profileSuccess = false; $profileError = '';
$pwSuccess = false; $pwError = '';

require_once ROOT . '/core/FileUpload.php';

// Cek kolom deskripsi & logo & notif exist
$hasDeskripsi = true;
try { $db->query("SELECT deskripsi FROM tenants LIMIT 1"); } catch (Throwable) { $hasDeskripsi = false; }
$hasLogo = true;
try { $db->query("SELECT logo_path FROM tenants LIMIT 1"); } catch (Throwable) { $hasLogo = false; }
$hasNotifSettings = true;
try { $db->query("SELECT notif_settings FROM tenants LIMIT 1"); } catch (Throwable) { $hasNotifSettings = false; }

$logoSuccess = false; $logoError = '';

// ── Logo upload handler ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_logo'])) {
    if (!hash_equals(getCsrfToken(), $_POST['_csrf'] ?? '')) {
        $logoError = 'CSRF mismatch';
    } elseif (!$hasLogo) {
        $logoError = 'Kolom logo_path belum ada. Jalankan SQL migration dulu.';
    } elseif (empty($_FILES['logo']['tmp_name'])) {
        $logoError = 'Tidak ada file yang diupload.';
    } else {
        $up = FileUpload::uploadImage($_FILES['logo'], 'uploads/logos', 'tenant_' . $tid);
        if ($up['error']) {
            $logoError = $up['error'];
        } else {
            try {
                $old = $hqTenant['logo_path'] ?? null;
                $db->prepare("UPDATE tenants SET logo_path=? WHERE id=?")->execute([$up['path'], $tid]);
                if ($old) FileUpload::deleteIfExists($old);
                logAcc($db, $tid, $uid, "logo updated: {$up['path']}");
                $logoSuccess = true;
                $r = $db->prepare("SELECT * FROM tenants WHERE id=?");
                $r->execute([$tid]); $hqTenant = $r->fetch();
            } catch (Throwable $e) { $logoError = 'Gagal simpan: ' . $e->getMessage(); }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_logo']) && $hasLogo) {
    if (hash_equals(getCsrfToken(), $_POST['_csrf'] ?? '')) {
        try {
            $old = $hqTenant['logo_path'] ?? null;
            $db->prepare("UPDATE tenants SET logo_path=NULL WHERE id=?")->execute([$tid]);
            if ($old) FileUpload::deleteIfExists($old);
            logAcc($db, $tid, $uid, "logo removed");
            $logoSuccess = true;
            $r = $db->prepare("SELECT * FROM tenants WHERE id=?");
            $r->execute([$tid]); $hqTenant = $r->fetch();
        } catch (Throwable $e) { $logoError = 'Gagal hapus: ' . $e->getMessage(); }
    }
}

$notifSuccess = false; $notifError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    if (!hash_equals(getCsrfToken(), $_POST['_csrf'] ?? '')) {
        $profileError = 'CSRF mismatch';
    } else {
        $namaOutlet = substr(trim(strip_tags($_POST['nama_outlet'] ?? '')), 0, 100);
        $ownerName  = substr(trim(strip_tags($_POST['owner_name'] ?? '')), 0, 100);
        $ownerWa    = preg_replace('/\D/', '', $_POST['owner_wa'] ?? '');
        if (substr($ownerWa, 0, 2) === '08') $ownerWa = '628' . substr($ownerWa, 2);
        if (substr($ownerWa, 0, 1) === '8')  $ownerWa = '62' . $ownerWa;
        $kota      = substr(trim(strip_tags($_POST['kota'] ?? '')), 0, 100);
        $deskripsi = substr(trim(strip_tags($_POST['deskripsi'] ?? '')), 0, 500);

        if (!$namaOutlet) {
            $profileError = 'Nama brand wajib diisi';
        } else {
            try {
                if ($ownerWa) {
                    $chk = $db->prepare("SELECT id FROM tenants WHERE owner_wa=? AND id!=? LIMIT 1");
                    $chk->execute([$ownerWa, $tid]);
                    if ($chk->fetchColumn()) {
                        $profileError = 'Nomor WhatsApp sudah dipakai akun lain.';
                    }
                }
                if (!$profileError) {
                    if ($hasDeskripsi) {
                        $db->prepare("UPDATE tenants SET nama_outlet=?, owner_name=?, owner_wa=?, kota=?, deskripsi=? WHERE id=?")
                           ->execute([$namaOutlet, $ownerName ?: null, $ownerWa ?: null, $kota ?: null, $deskripsi ?: null, $tid]);
                    } else {
                        $db->prepare("UPDATE tenants SET nama_outlet=?, owner_name=?, owner_wa=?, kota=? WHERE id=?")
                           ->execute([$namaOutlet, $ownerName ?: null, $ownerWa ?: null, $kota ?: null, $tid]);
                    }
                    logAcc($db, $tid, $uid, "profile updated");
                    $profileSuccess = true;
                    $r = $db->prepare("SELECT * FROM tenants WHERE id=?");
                    $r->execute([$tid]);
                    $hqTenant = $r->fetch();
                }
            } catch (Throwable $e) {
                $profileError = 'Gagal: ' . $e->getMessage();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_notif'])) {
    if (!hash_equals(getCsrfToken(), $_POST['_csrf'] ?? '')) {
        $notifError = 'CSRF mismatch';
    } elseif (!$hasNotifSettings) {
        $notifError = 'Kolom notif_settings belum ada. Jalankan SQL migration dulu.';
    } else {
        $alerts = ['coin_low', 'trial_ending', 'daily_report'];
        $channels = ['email', 'wa'];
        $prefs = [];
        foreach ($alerts as $a) {
            foreach ($channels as $c) {
                $prefs[$a][$c] = !empty($_POST['notif'][$a][$c]) ? 1 : 0;
            }
        }
        // Daily report config
        $jam = $_POST['daily_report_jam'] ?? '21:00';
        if (!preg_match('/^\d{2}:\d{2}$/', $jam)) $jam = '21:00';
        $prefs['daily_report_jam'] = $jam;
        $validKonten = ['omset','order','kas','absensi','alert'];
        $konten = [];
        foreach ((array)($_POST['daily_konten'] ?? []) as $k) {
            if (in_array($k, $validKonten, true)) $konten[] = $k;
        }
        if (!$konten) $konten = $validKonten;
        $prefs['daily_report_konten'] = $konten;
        try {
            $db->prepare("UPDATE tenants SET notif_settings=? WHERE id=?")
               ->execute([json_encode($prefs), $tid]);
            logAcc($db, $tid, $uid, "notif preferences updated");
            $notifSuccess = true;
            $r = $db->prepare("SELECT * FROM tenants WHERE id=?");
            $r->execute([$tid]);
            $hqTenant = $r->fetch();
        } catch (Throwable $e) {
            $notifError = 'Gagal: ' . $e->getMessage();
        }
    }
}

// ── Simpan setting Loyalty ────────────────────────────
$loyaltySuccess = false; $loyaltyError = null;
$hasLoyalty = true;
try { $db->query("SELECT loyalty_enabled FROM tenants LIMIT 1"); }
catch (Throwable) { $hasLoyalty = false; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_loyalty'])) {
    if (!hash_equals(getCsrfToken(), $_POST['_csrf'] ?? '')) {
        $loyaltyError = 'CSRF mismatch';
    } elseif (!$hasLoyalty) {
        $loyaltyError = 'Kolom loyalty belum ada. Jalankan loyalty_migration.sql dulu.';
    } else {
        $enabled = !empty($_POST['loyalty_enabled']) ? 1 : 0;
        $rpPerPoin = max(1, (int)($_POST['loyalty_rupiah_per_poin'] ?? 1000));
        $poinValue = max(1, (int)($_POST['loyalty_poin_value'] ?? 100));
        try {
            $db->prepare("UPDATE tenants SET loyalty_enabled=?, loyalty_rupiah_per_poin=?, loyalty_poin_value=? WHERE id=?")
               ->execute([$enabled, $rpPerPoin, $poinValue, $tid]);
            try { logAcc($db, $tid, $uid, "loyalty settings updated"); } catch (Throwable) {}
            $loyaltySuccess = true;
            $r = $db->prepare("SELECT * FROM tenants WHERE id=?"); $r->execute([$tid]); $hqTenant = $r->fetch();
        } catch (Throwable $e) { $loyaltyError = 'Gagal: '.$e->getMessage(); }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!hash_equals(getCsrfToken(), $_POST['_csrf'] ?? '')) {
        $pwError = 'CSRF mismatch';
    } else {
        $currentPw = $_POST['current_password'] ?? '';
        $newPw     = $_POST['new_password']     ?? '';
        $confPw    = $_POST['confirm_password'] ?? '';

        $u = $db->prepare("SELECT password FROM hl_users WHERE id=?");
        $u->execute([$uid]);
        $stored = $u->fetchColumn();

        if (!password_verify($currentPw, $stored)) {
            $pwError = 'Password lama tidak sesuai';
        } elseif (strlen($newPw) < 8) {
            $pwError = 'Password baru minimal 8 karakter';
        } elseif ($newPw !== $confPw) {
            $pwError = 'Konfirmasi password tidak cocok';
        } else {
            try {
                $hash = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 11]);
                $db->prepare("UPDATE hl_users SET password=? WHERE id=?")->execute([$hash, $uid]);
                $db->prepare("UPDATE tenants SET password_hash=? WHERE id=?")->execute([$hash, $tid]);
                logAcc($db, $tid, $uid, "password changed");
                $pwSuccess = true;
            } catch (Throwable $e) {
                $pwError = 'Gagal: ' . $e->getMessage();
            }
        }
    }
}

// ── AJAX action: histori topup (payments) ─────────────
if ($action === 'topup_history') {
    header('Content-Type: application/json');
    try {
        $stmt = $db->prepare(
            "SELECT id, amount, coin_amount, type, status, payment_method, paid_at, created_at, notes
               FROM payments
              WHERE tenant_id=? AND (type='coin_topup' OR type IS NULL)
              ORDER BY COALESCE(paid_at, created_at) DESC LIMIT 50"
        );
        $stmt->execute([$tid]);
        echo json_encode($stmt->fetchAll());
    } catch (Throwable $e) {
        // Table payments mungkin belum ada
        echo json_encode([]);
    }
    exit;
}

// ── AJAX action: histori pemakaian coin per outlet ────
if ($action === 'coin_usage') {
    header('Content-Type: application/json');
    try {
        $stmt = $db->prepare(
            "SELECT cl.id, cl.outlet_id, cl.amount, cl.feature_used, cl.description,
                    cl.balance_after, cl.created_at,
                    (SELECT nama_outlet FROM outlets WHERE id=cl.outlet_id) AS nama_outlet
               FROM coin_ledger cl
              WHERE cl.tenant_id=? AND cl.type='debit'
              ORDER BY cl.created_at DESC LIMIT 50"
        );
        $stmt->execute([$tid]);
        echo json_encode($stmt->fetchAll());
    } catch (Throwable $e) {
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

// ── AJAX action: ambil saldo coin ─────────────────────
if ($action === 'coin_balance') {
    header('Content-Type: application/json');
    try {
        $row = $db->prepare("SELECT coin_balance, coin_mode FROM tenants WHERE id=?");
        $row->execute([$tid]);
        $t = $row->fetch();

        $outlets = $db->prepare(
            "SELECT id, nama_outlet, status, coin_balance, trial_coin_balance
               FROM outlets
              WHERE tenant_id=? AND status IN ('trial','grace','active')
              ORDER BY is_main DESC, nama_outlet ASC"
        );
        $outlets->execute([$tid]);

        echo json_encode([
            'tenant_coin' => (int)$t['coin_balance'],
            'coin_mode'   => $t['coin_mode'],
            'outlets'     => $outlets->fetchAll(),
        ]);
    } catch (Throwable $e) {
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

// ── Load fresh tenant info ────────────────────────────
$r = $db->prepare("SELECT * FROM tenants WHERE id=?");
$r->execute([$tid]);
$hqTenant = $r->fetch();

$ownerNama  = $hqUser['nama'] ?? 'Owner';
$tenantNm   = $hqTenant['nama_outlet'] ?? '-';
$ownerWaFmt = preg_replace('/^628/', '08', $hqTenant['owner_wa'] ?? '');

// Hitung jumlah outlet aktif
$outletCnt = (int)$db->query("SELECT COUNT(*) FROM outlets WHERE tenant_id=$tid AND status IN ('trial','grace','active')")->fetchColumn();

// Saldo tenant
$tenantCoin = (int)($hqTenant['coin_balance'] ?? 0);
$coinMode   = $hqTenant['coin_mode'] ?? 'shared';

// Parse notif preferences
$notifDefaults = [
    'coin_low'      => ['email'=>1, 'wa'=>0],
    'trial_ending'  => ['email'=>1, 'wa'=>1],
    'daily_report'  => ['email'=>0, 'wa'=>0],
];
$notifPrefs = $notifDefaults;
if (!empty($hqTenant['notif_settings'])) {
    $parsed = json_decode($hqTenant['notif_settings'], true);
    if (is_array($parsed)) {
        foreach ($notifDefaults as $k => $v) {
            $notifPrefs[$k] = [
                'email' => (int)!empty($parsed[$k]['email']),
                'wa'    => (int)!empty($parsed[$k]['wa']),
            ];
        }
    }
}

// Aktivasi info (paket aktif)
$firstActivated = null;
$outletStatusCount = ['trial'=>0,'grace'=>0,'active'=>0,'suspended'=>0,'closed'=>0];
try {
    $r = $db->prepare("SELECT MIN(activated_at) FROM outlets WHERE tenant_id=? AND activated_at IS NOT NULL");
    $r->execute([$tid]);
    $firstActivated = $r->fetchColumn();
    $rs = $db->prepare("SELECT status, COUNT(*) c FROM outlets WHERE tenant_id=? GROUP BY status");
    $rs->execute([$tid]);
    foreach ($rs->fetchAll() as $row) { $outletStatusCount[$row['status']] = (int)$row['c']; }
} catch (Throwable) {}

$csrf = getCsrfToken();
$supportWa = '6281234567890';
?>
<?php
$pageTitle  = 'Pengaturan';
$activePage = 'hq-settings';
require __DIR__ . '/_layout_open.php';
?>
<style>
  h1{font-size:1.4rem;font-weight:800;color:#0F1C3A;margin-bottom:6px}
  .subtitle{font-size:13px;color:#6B7280;margin-bottom:20px}

  .panel{background:#fff;border-radius:12px;padding:24px 26px;box-shadow:0 1px 6px rgba(0,0,0,.05);margin-bottom:18px}
  .panel-title{font-size:15px;font-weight:700;color:#0F1C3A;margin-bottom:4px;
               display:flex;align-items:center;gap:8px}
  .panel-sub{font-size:12px;color:#6B7280;margin-bottom:18px}

  .form-grid{display:grid;gap:14px}
  .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}
  label small{font-weight:400;color:#9CA3AF}
  input[type=text],input[type=email],input[type=tel],input[type=password]{
    width:100%;padding:10px 13px;border:1.5px solid #E5E7EB;border-radius:8px;
    font-size:14px;outline:none;font-family:inherit;box-sizing:border-box
  }
  input:focus{border-color:#35E8D5}
  input[readonly]{background:#F9FAFB;color:#6B7280;cursor:not-allowed}

  .btn{padding:10px 20px;border-radius:9px;font-weight:700;font-size:14px;border:none;cursor:pointer;
       font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
  .btn-primary{background:#35E8D5;color:#0F1C3A}
  .btn-light{background:#F3F4F6;color:#0F1C3A;border:1.5px solid #E5E7EB}
  .btn-wa{background:#25D366;color:#fff}
  .btn-dark{background:#0F1C3A;color:#fff}

  .alert{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:14px}
  .alert.error{background:#FEE2E2;color:#991B1B;border:1px solid #FECACA}
  .alert.success{background:#D1FAE5;color:#065F46;border:1px solid #6EE7B7}

  .info-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:14px}
  .info-card{background:linear-gradient(135deg,#F0FDFB,#fff);border:1px solid rgba(53,232,213,.2);
             border-radius:10px;padding:14px}
  .info-num{font-size:1.4rem;font-weight:800;color:#0F1C3A;font-family:monospace;margin-bottom:2px}
  .info-label{font-size:11px;color:#6B7280;font-weight:700;text-transform:uppercase;letter-spacing:.05em}

  .outlet-coin-row{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:12px;
                   align-items:center;padding:13px 0;border-bottom:1px solid #F3F4F6;font-size:13px}
  .outlet-coin-row:last-child{border-bottom:none}
  .outlet-name{font-weight:700;color:#0F1C3A}
  .outlet-status{font-size:9px;font-weight:700;padding:2px 7px;border-radius:100px;
                 text-transform:uppercase;display:inline-block;margin-left:5px}
  .st-trial{background:#DBEAFE;color:#1E40AF}
  .st-grace{background:#FEF3C7;color:#92400E}
  .st-active{background:#D1FAE5;color:#065F46}
  .coin-num{font-family:monospace;font-weight:700;text-align:right;color:#0F1C3A}
  .coin-num small{display:block;color:#9CA3AF;font-weight:400;font-size:10px;text-transform:uppercase}
  .coin-trial{color:#F59E0B}

  .mode-badge{background:rgba(53,232,213,.15);border:1px solid rgba(53,232,213,.3);color:#0891B2;
              padding:3px 10px;border-radius:100px;font-size:11px;font-weight:700;display:inline-block}
  .danger-zone{border-left:3px solid #EF4444;padding:12px 16px;background:#FEF2F2;border-radius:0 8px 8px 0;font-size:12px;color:#991B1B}

  @media(max-width:640px){
    .grid-2{grid-template-columns:1fr}
    .info-grid{grid-template-columns:1fr}
    .outlet-coin-row{grid-template-columns:1fr;gap:6px}
  }
</style>

  <h1>⚙️ Pengaturan Akun</h1>
  <p class="subtitle">Profil perusahaan, password, dan billing — hanya bisa diakses dari HQ view</p>

  <!-- ① PROFIL PERUSAHAAN -->
  <div class="panel">
    <div class="panel-title">🏢 Profil Perusahaan</div>
    <div class="panel-sub">Identitas perusahaan/brand kamu. Email tidak bisa diganti mandiri.</div>

    <?php if ($profileSuccess): ?>
    <div class="alert success">✓ Profil berhasil diperbarui.</div>
    <?php elseif ($profileError): ?>
    <div class="alert error">❌ <?= htmlspecialchars($profileError) ?></div>
    <?php endif; ?>

    <form method="POST" class="form-grid">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="save_profile" value="1">

      <div>
        <label>Nama Brand / Perusahaan <span style="color:#EF4444">*</span></label>
        <input type="text" name="nama_outlet" maxlength="100"
               value="<?= htmlspecialchars($hqTenant['nama_outlet'] ?? '') ?>" required>
      </div>

      <div class="grid-2">
        <div>
          <label>Email Login <small>(read-only)</small></label>
          <input type="email" value="<?= htmlspecialchars($hqTenant['email'] ?? '') ?>" readonly>
        </div>
        <div>
          <label>Kota</label>
          <input type="text" name="kota" maxlength="100"
                 value="<?= htmlspecialchars($hqTenant['kota'] ?? '') ?>" placeholder="cth: Jakarta">
        </div>
      </div>

      <div class="grid-2">
        <div>
          <label>Nama Owner / PIC</label>
          <input type="text" name="owner_name" maxlength="100"
                 value="<?= htmlspecialchars($hqTenant['owner_name'] ?? '') ?>">
        </div>
        <div>
          <label>Nomor WhatsApp Owner</label>
          <input type="tel" name="owner_wa" maxlength="15"
                 value="<?= htmlspecialchars($ownerWaFmt) ?>" placeholder="08xxxxxxxxxx">
        </div>
      </div>

      <?php if ($hasDeskripsi): ?>
      <div>
        <label>Deskripsi Singkat <small>(opsional, max 500 karakter)</small></label>
        <textarea name="deskripsi" rows="2" maxlength="500"
                  style="width:100%;padding:10px 13px;border:1.5px solid #E5E7EB;border-radius:8px;
                         font-size:14px;outline:none;font-family:inherit;box-sizing:border-box"
                  placeholder="cth: Laundry kiloan & satuan, 5 cabang di Jakarta"><?= htmlspecialchars($hqTenant['deskripsi'] ?? '') ?></textarea>
      </div>
      <?php endif; ?>

      <div>
        <button type="submit" class="btn btn-primary">💾 Simpan Profil</button>
      </div>

      <div class="danger-zone" style="margin-top:8px">
        ⚠️ <strong>Ingin ganti email login?</strong>
        Hubungi tim LAMASY via WhatsApp untuk proses verifikasi keamanan.
      </div>
    </form>

    <?php if ($hasLogo): ?>
    <!-- Logo upload (terpisah karena multipart) -->
    <div style="margin-top:18px;padding-top:18px;border-top:1px dashed #E5E7EB">
      <label style="font-size:12px;font-weight:700;color:#374151;margin-bottom:8px;display:block">
        🖼️ Logo Brand <small style="font-weight:400;color:#9CA3AF">(max 2MB, JPG/PNG/WebP)</small>
      </label>

      <?php if ($logoSuccess): ?>
      <div class="alert success">✓ Logo berhasil diperbarui.</div>
      <?php elseif ($logoError): ?>
      <div class="alert error">❌ <?= htmlspecialchars($logoError) ?></div>
      <?php endif; ?>

      <div style="display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap">
        <?php if (!empty($hqTenant['logo_path'])): ?>
        <div style="position:relative">
          <img src="<?= htmlspecialchars(FileUpload::publicUrl($hqTenant['logo_path'])) ?>?v=<?= time() ?>"
               alt="Logo" style="width:96px;height:96px;object-fit:cover;border-radius:10px;
                                  border:1.5px solid #E5E7EB;background:#fff">
          <form method="POST" style="margin-top:6px">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="remove_logo" value="1">
            <button type="submit"
                    onclick="return confirm('Hapus logo brand?')"
                    style="background:transparent;color:#EF4444;border:1px solid #FCA5A5;
                           padding:4px 10px;font-size:11px;font-weight:600;border-radius:6px;
                           cursor:pointer;font-family:inherit">
              🗑️ Hapus Logo
            </button>
          </form>
        </div>
        <?php else: ?>
        <div style="width:96px;height:96px;border-radius:10px;border:1.5px dashed #D1D5DB;
                    background:#F9FAFB;display:flex;align-items:center;justify-content:center;
                    color:#9CA3AF;font-size:32px;flex-shrink:0">🏢</div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" style="flex:1;min-width:240px">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="upload_logo" value="1">
          <input type="file" name="logo" accept="image/jpeg,image/png,image/webp,image/gif" required
                 style="width:100%;padding:10px;border:1.5px solid #E5E7EB;border-radius:8px;
                        font-size:13px;background:#fff;font-family:inherit;margin-bottom:8px">
          <button type="submit" class="btn btn-primary" style="font-size:13px;padding:8px 18px">
            ⬆️ Upload Logo
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ② GANTI PASSWORD -->
  <div class="panel">
    <div class="panel-title">🔑 Ganti Password</div>
    <div class="panel-sub">Password lama akan diverifikasi sebelum perubahan disimpan.</div>

    <?php if ($pwSuccess): ?>
    <div class="alert success">✓ Password berhasil diubah. Login berikutnya pakai password baru.</div>
    <?php elseif ($pwError): ?>
    <div class="alert error">❌ <?= htmlspecialchars($pwError) ?></div>
    <?php endif; ?>

    <form method="POST" class="form-grid">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="change_password" value="1">

      <div>
        <label>Password Lama</label>
        <input type="password" name="current_password" required>
      </div>

      <div class="grid-2">
        <div>
          <label>Password Baru <small>(min 8 karakter)</small></label>
          <input type="password" name="new_password" minlength="8" required>
        </div>
        <div>
          <label>Konfirmasi Password Baru</label>
          <input type="password" name="confirm_password" minlength="8" required>
        </div>
      </div>

      <div>
        <button type="submit" class="btn btn-dark">🔐 Simpan Password Baru</button>
      </div>
    </form>
  </div>

  <!-- ③ COIN & BILLING — owner only (brief 3.2: manager tidak akses billing) -->
  <?php if ($hqCanBilling): ?>
  <div class="panel">
    <div class="panel-title">🪙 Coin & Billing</div>
    <div class="panel-sub">
      Mode coin: <span class="mode-badge"><?= strtoupper(htmlspecialchars($coinMode)) ?></span>
      <?= $coinMode === 'shared' ? '— saldo dibagi semua outlet' : '— tiap outlet punya saldo terpisah' ?>
    </div>

    <div class="info-grid">
      <div class="info-card">
        <div class="info-num"><?= number_format($tenantCoin) ?></div>
        <div class="info-label">Saldo Tenant (Shared)</div>
      </div>
      <div class="info-card" style="border-color:rgba(245,158,11,.3);background:linear-gradient(135deg,#FFFBEB,#fff)">
        <div class="info-num"><?= $outletCnt ?></div>
        <div class="info-label">Outlet Aktif</div>
      </div>
      <div class="info-card" style="border-color:rgba(139,92,246,.3);background:linear-gradient(135deg,#F5F3FF,#fff)">
        <div class="info-num">-</div>
        <div class="info-label">Paket Aktif (TODO)</div>
      </div>
    </div>

    <!-- Per-outlet coin breakdown -->
    <div style="font-size:13px;color:#6B7280;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin:18px 0 10px">
      Saldo per Outlet
    </div>
    <div id="outletCoinList">
      <div style="color:#9CA3AF;font-size:12px">Memuat…</div>
    </div>

    <!-- Topup section -->
    <div style="margin-top:22px;padding-top:18px;border-top:1px dashed #E5E7EB">
      <div class="panel-title" style="font-size:14px;margin-bottom:8px">💳 Topup Coin</div>
      <p style="font-size:13px;color:#6B7280;line-height:1.6;margin-bottom:14px">
        🚧 <strong>Payment gateway sedang dikembangkan.</strong>
        Untuk topup coin sementara, hubungi tim LAMASY via WhatsApp dengan menyebutkan jumlah dan
        outlet tujuan (jika mode <code>per_outlet</code>).
      </p>
      <a href="https://wa.me/<?= $supportWa ?>?text=<?= urlencode('Halo Tim LAMASY, saya mau topup coin untuk akun ' . ($hqTenant['email'] ?? '-') . '. Mohon info paket topup yang tersedia.') ?>"
         target="_blank" rel="noopener" class="btn btn-wa">
        💬 Request Topup via WhatsApp
      </a>
    </div>

    <!-- Histori Topup -->
    <div style="margin-top:22px;padding-top:18px;border-top:1px dashed #E5E7EB">
      <div class="panel-title" style="font-size:14px;margin-bottom:10px">📜 Histori Topup Coin</div>
      <div id="topupHistory">
        <div style="color:#9CA3AF;font-size:12px;text-align:center;padding:14px">Memuat…</div>
      </div>
    </div>

    <!-- Histori Pemakaian -->
    <div style="margin-top:22px;padding-top:18px;border-top:1px dashed #E5E7EB">
      <div class="panel-title" style="font-size:14px;margin-bottom:10px">🔥 Histori Pemakaian Coin per Outlet</div>
      <div id="coinUsage">
        <div style="color:#9CA3AF;font-size:12px;text-align:center;padding:14px">Memuat…</div>
      </div>
    </div>
  </div>

  <?php endif; // hqCanBilling — coin & billing ?>

  <!-- LOYALTY POIN -->
  <div class="panel">
    <div class="panel-title">⭐ Program Loyalty Poin</div>
    <div class="panel-sub">Pelanggan kumpulkan poin tiap transaksi lunas, bisa ditukar di outlet mana saja.</div>
    <?php if ($loyaltySuccess): ?>
      <div class="alert success">✓ Setting loyalty tersimpan.</div>
    <?php elseif ($loyaltyError): ?>
      <div class="alert error"><?= htmlspecialchars($loyaltyError) ?></div>
    <?php endif; ?>
    <?php if (!$hasLoyalty): ?>
      <div class="alert error">⚠️ Jalankan <code>loyalty_migration.sql</code> dulu.</div>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= getCsrfToken() ?>">
      <input type="hidden" name="save_loyalty" value="1">
      <label style="display:flex;align-items:center;gap:8px;font-weight:600;margin-bottom:14px;cursor:pointer">
        <input type="checkbox" name="loyalty_enabled" value="1" style="width:auto" <?= !empty($hqTenant['loyalty_enabled'])?'checked':'' ?>>
        Aktifkan program loyalty
      </label>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px">Rp belanja per 1 poin (earn)</label>
          <input type="number" name="loyalty_rupiah_per_poin" min="1" value="<?= (int)($hqTenant['loyalty_rupiah_per_poin'] ?? 1000) ?>"
                 style="width:100%;padding:9px 12px;border:1px solid #E5E9F2;border-radius:8px">
          <small style="color:#9CA3AF">Contoh 1000 = tiap Rp1.000 dapat 1 poin</small>
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px">Nilai 1 poin saat redeem (Rp)</label>
          <input type="number" name="loyalty_poin_value" min="1" value="<?= (int)($hqTenant['loyalty_poin_value'] ?? 100) ?>"
                 style="width:100%;padding:9px 12px;border:1px solid #E5E9F2;border-radius:8px">
          <small style="color:#9CA3AF">Contoh 100 = 1 poin = Rp100 diskon</small>
        </div>
      </div>
      <button type="submit" class="btn btn-primary">💾 Simpan Loyalty</button>
    </form>
    <?php endif; ?>
  </div>

  <!-- ④ NOTIFIKASI PREFERENCE -->
  <div class="panel">
    <div class="panel-title">🔔 Preferensi Notifikasi</div>
    <div class="panel-sub">Pilih channel mana yang menerima alert untuk tiap jenis kejadian.</div>

    <?php if ($notifSuccess): ?>
    <div class="alert success">✓ Preferensi notifikasi tersimpan.</div>
    <?php elseif ($notifError): ?>
    <div class="alert error">❌ <?= htmlspecialchars($notifError) ?></div>
    <?php endif; ?>

    <?php if (!$hasNotifSettings): ?>
    <div class="alert" style="background:#FEF3C7;color:#92400E;border:1px solid #FDE68A">
      ⚠️ Field <code>notif_settings</code> belum tersedia. Jalankan SQL:
      <code style="display:block;margin-top:6px;background:rgba(0,0,0,.06);padding:6px 10px;border-radius:4px">
        ALTER TABLE tenants ADD COLUMN notif_settings TEXT NULL;
      </code>
    </div>
    <?php else: ?>

    <form method="POST">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="save_notif" value="1">

      <div style="overflow-x:auto">
        <table style="width:100%;font-size:13px;border-collapse:collapse">
          <thead>
            <tr style="background:#F9FAFB">
              <th style="text-align:left;padding:10px 12px;font-size:11px;color:#6B7280;font-weight:800;text-transform:uppercase;letter-spacing:.04em">Jenis Alert</th>
              <th style="padding:10px;font-size:11px;color:#6B7280;font-weight:800;text-transform:uppercase">📧 Email</th>
              <th style="padding:10px;font-size:11px;color:#6B7280;font-weight:800;text-transform:uppercase">💬 WhatsApp</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $alertRows = [
                'coin_low'     => ['🪙 Coin Hampir Habis', 'Kirim alert kalau saldo coin <1000'],
                'trial_ending' => ['⏰ Trial Mau Berakhir', 'Reminder H-3 dan H-1 sebelum trial habis'],
                'daily_report' => ['📊 Laporan Harian', 'Ringkasan omset & order tiap pagi'],
            ];
            foreach ($alertRows as $key => [$lbl, $desc]):
            ?>
            <tr style="border-top:1px solid #F3F4F6">
              <td style="padding:11px 12px">
                <div style="font-weight:700;color:#0F1C3A"><?= $lbl ?></div>
                <div style="font-size:11px;color:#9CA3AF;margin-top:2px"><?= $desc ?></div>
              </td>
              <td style="padding:11px 10px;text-align:center">
                <input type="checkbox" name="notif[<?= $key ?>][email]" value="1"
                       <?= $notifPrefs[$key]['email'] ? 'checked' : '' ?>
                       style="width:18px;height:18px;accent-color:#35E8D5;cursor:pointer">
              </td>
              <td style="padding:11px 10px;text-align:center">
                <input type="checkbox" name="notif[<?= $key ?>][wa]" value="1"
                       <?= $notifPrefs[$key]['wa'] ? 'checked' : '' ?>
                       style="width:18px;height:18px;accent-color:#25D366;cursor:pointer">
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Daily report config -->
      <?php
        $dailyJam = $notifPrefs['daily_report_jam'] ?? '21:00';
        if (!preg_match('/^\d{2}:\d{2}$/', $dailyJam)) $dailyJam = '21:00';
        $dailyKonten = $notifPrefs['daily_report_konten'] ?? ['omset','order','kas','absensi','alert'];
        if (!is_array($dailyKonten)) $dailyKonten = ['omset','order','kas','absensi','alert'];
      ?>
      <div style="background:#F9FAFB;border:1px solid #EEF1F8;border-radius:10px;padding:14px 16px;margin-top:16px">
        <div style="font-weight:700;color:#0F1C3A;font-size:13px;margin-bottom:10px">📊 Konfigurasi Laporan Harian</div>
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
          <label style="font-size:12px;font-weight:600;color:#374151">Jam kirim:</label>
          <input type="time" name="daily_report_jam" value="<?= htmlspecialchars($dailyJam) ?>"
                 style="padding:6px 10px;border:1px solid #E5E9F2;border-radius:7px;font-family:inherit">
          <span style="font-size:11px;color:#9CA3AF">(akan dikirim jika request masuk setelah jam ini & belum dikirim hari ini)</span>
        </div>
        <div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:6px">Konten:</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <?php foreach (['omset'=>'💰 Omset','order'=>'📦 Order','kas'=>'💵 Kas','absensi'=>'👥 Absensi','alert'=>'⚠️ Alert'] as $k=>$lbl): ?>
            <label style="display:flex;align-items:center;gap:5px;font-size:12px;color:#374151;cursor:pointer">
              <input type="checkbox" name="daily_konten[]" value="<?= $k ?>"
                     <?= in_array($k, $dailyKonten, true) ? 'checked' : '' ?>
                     style="accent-color:#35E8D5">
              <?= $lbl ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div style="margin-top:14px">
        <button type="submit" class="btn btn-primary">💾 Simpan Preferensi</button>
        <span style="font-size:11px;color:#9CA3AF;margin-left:10px">
          ℹ️ Notifikasi WhatsApp memerlukan integrasi yang sedang dalam development
        </span>
      </div>
    </form>
    <?php endif; ?>
  </div>

  <!-- ⑤ PAKET AKTIF — owner only (manager tidak akses) -->
  <?php if ($hqCanBilling): ?>
  <div class="panel">
    <div class="panel-title">📦 Paket Aktif</div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
      <div class="info-card" style="background:linear-gradient(135deg,#F0FDF4,#fff);border-color:rgba(52,211,153,.3)">
        <div class="info-num"><?= ucfirst(htmlspecialchars($hqTenant['status'] ?? '-')) ?></div>
        <div class="info-label">Status Akun</div>
      </div>
      <div class="info-card">
        <div class="info-num"><?= $outletStatusCount['active'] + $outletStatusCount['trial'] + $outletStatusCount['grace'] ?></div>
        <div class="info-label">Outlet Aktif</div>
      </div>
      <div class="info-card" style="background:linear-gradient(135deg,#EFF6FF,#fff);border-color:rgba(59,130,246,.3)">
        <div class="info-num"><?= $outletStatusCount['trial'] ?></div>
        <div class="info-label">Outlet Trial</div>
      </div>
      <div class="info-card" style="background:linear-gradient(135deg,#FEF2F2,#fff);border-color:rgba(239,68,68,.3)">
        <div class="info-num"><?= $outletStatusCount['suspended'] ?></div>
        <div class="info-label">Outlet Suspended</div>
      </div>
    </div>

    <div style="margin-top:18px;background:#F9FAFB;border-radius:10px;padding:14px 18px;font-size:13px;color:#374151">
      <div style="display:flex;justify-content:space-between;padding:5px 0">
        <span style="color:#6B7280">Sumber Pendaftaran</span>
        <strong><?= htmlspecialchars($hqTenant['registration_source'] ?? '-') ?></strong>
      </div>
      <div style="display:flex;justify-content:space-between;padding:5px 0">
        <span style="color:#6B7280">Tanggal Aktivasi Outlet Pertama</span>
        <strong><?= $firstActivated ? date('d M Y', strtotime($firstActivated)) : '<span style="color:#9CA3AF;font-weight:400">(belum ada)</span>' ?></strong>
      </div>
      <div style="display:flex;justify-content:space-between;padding:5px 0">
        <span style="color:#6B7280">Email Terverifikasi</span>
        <strong style="color:<?= $hqTenant['verified_at'] ? '#34D399' : '#F59E0B' ?>">
          <?= $hqTenant['verified_at'] ? '✓ ' . date('d M Y', strtotime($hqTenant['verified_at'])) : '⚠️ Belum' ?>
        </strong>
      </div>
    </div>

    <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap">
      <a href="/ERP/harpy/add-outlet.php" class="btn btn-primary">🏪 Tambah Outlet Baru</a>
      <a href="https://wa.me/<?= $supportWa ?>?text=<?= urlencode('Halo Tim LAMASY, saya mau upgrade paket / tambah outlet untuk akun '.($hqTenant['email'] ?? '-').'.') ?>"
         target="_blank" rel="noopener" class="btn btn-wa">💬 Upgrade via WhatsApp</a>
    </div>
  </div>

  <?php endif; // paket aktif (owner only) ?>

  <!-- ⑥ INFO AKUN -->
  <div class="panel">
    <div class="panel-title">ℹ️ Info Akun</div>
    <div class="form-grid">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;font-size:13px">
        <div>
          <div style="color:#9CA3AF;font-size:11px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px">Status</div>
          <div style="font-weight:700;color:#34D399"><?= ucfirst(htmlspecialchars($hqTenant['status'])) ?></div>
        </div>
        <div>
          <div style="color:#9CA3AF;font-size:11px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px">Bergabung</div>
          <div style="font-weight:700"><?= $hqTenant['registered_at'] ? date('d M Y', strtotime($hqTenant['registered_at'])) : '-' ?></div>
        </div>
        <div>
          <div style="color:#9CA3AF;font-size:11px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px">Email Terverifikasi</div>
          <div style="font-weight:700;color:<?= $hqTenant['verified_at'] ? '#34D399' : '#F59E0B' ?>">
            <?= $hqTenant['verified_at'] ? '✓ ' . date('d M Y', strtotime($hqTenant['verified_at'])) : '⚠️ Belum' ?>
          </div>
        </div>
        <div>
          <div style="color:#9CA3AF;font-size:11px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px">Sumber Pendaftaran</div>
          <div style="font-weight:700"><?= htmlspecialchars($hqTenant['registration_source'] ?? '-') ?></div>
        </div>
      </div>
    </div>
  </div>

<script>
function escapeHtml(s){return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function fmt(n){return Number(n||0).toLocaleString('id-ID')}

async function loadCoin(){
  const r = await fetch('/ERP/harpy/hq/settings.php?action=coin_balance');
  const d = await r.json();
  if (d.error) {
    document.getElementById('outletCoinList').innerHTML = `<div style="color:#EF4444;font-size:12px">Error: ${escapeHtml(d.error)}</div>`;
    return;
  }
  if (!d.outlets || d.outlets.length === 0) {
    document.getElementById('outletCoinList').innerHTML =
      '<div style="color:#9CA3AF;font-size:13px;text-align:center;padding:20px">Belum ada outlet aktif</div>';
    return;
  }
  const isShared = d.coin_mode === 'shared';
  document.getElementById('outletCoinList').innerHTML = d.outlets.map(o => `
    <div class="outlet-coin-row">
      <div>
        <span class="outlet-name">📍 ${escapeHtml(o.nama_outlet)}</span>
        <span class="outlet-status st-${o.status}">${o.status}</span>
      </div>
      <div class="coin-num ${o.trial_coin_balance > 0 ? 'coin-trial' : ''}">
        ${fmt(o.trial_coin_balance || 0)}<small>Coin Trial</small>
      </div>
      <div class="coin-num">
        ${isShared ? '<span style="color:#9CA3AF;font-style:italic;font-size:11px">(shared)</span>' : fmt(o.coin_balance || 0)}
        <small>Coin Real</small>
      </div>
      <div>
        <a href="https://wa.me/<?= $supportWa ?>?text=${encodeURIComponent('Halo Tim LAMASY, saya mau topup coin untuk outlet "'+o.nama_outlet+'". Mohon info paket topup.')}"
           target="_blank" rel="noopener"
           style="padding:6px 12px;background:#25D366;color:#fff;border-radius:7px;text-decoration:none;font-size:11px;font-weight:700">
          💳 Topup
        </a>
      </div>
    </div>
  `).join('');
}

async function loadTopupHistory(){
  const box = document.getElementById('topupHistory');
  try {
    const r = await fetch('/ERP/harpy/hq/settings.php?action=topup_history');
    const rows = await r.json();
    if (!rows || rows.length === 0) {
      box.innerHTML = '<div style="color:#9CA3AF;font-size:12px;text-align:center;padding:14px">Belum ada histori topup</div>';
      return;
    }
    box.innerHTML = `
      <div style="overflow-x:auto">
        <table style="width:100%;font-size:12px;border-collapse:collapse">
          <thead>
            <tr style="background:#F9FAFB">
              <th style="text-align:left;padding:8px 10px;font-size:10px;color:#6B7280;font-weight:800;text-transform:uppercase">Tanggal</th>
              <th style="text-align:left;padding:8px 10px;font-size:10px;color:#6B7280;font-weight:800;text-transform:uppercase">Tipe</th>
              <th style="text-align:right;padding:8px 10px;font-size:10px;color:#6B7280;font-weight:800;text-transform:uppercase">Jumlah</th>
              <th style="text-align:right;padding:8px 10px;font-size:10px;color:#6B7280;font-weight:800;text-transform:uppercase">Coin</th>
              <th style="text-align:left;padding:8px 10px;font-size:10px;color:#6B7280;font-weight:800;text-transform:uppercase">Status</th>
            </tr>
          </thead>
          <tbody>
            ${rows.map(r => `
              <tr style="border-top:1px solid #F3F4F6">
                <td style="padding:9px 10px">${new Date(r.paid_at || r.created_at).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}</td>
                <td style="padding:9px 10px">${escapeHtml(r.type || 'topup')}</td>
                <td style="padding:9px 10px;text-align:right;font-family:monospace;font-weight:700">Rp ${Number(r.amount||0).toLocaleString('id-ID')}</td>
                <td style="padding:9px 10px;text-align:right;font-family:monospace">${Number(r.coin_amount||0).toLocaleString('id-ID')}</td>
                <td style="padding:9px 10px">
                  <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px;
                              background:${r.status==='success'?'#D1FAE5':'#FEF3C7'};
                              color:${r.status==='success'?'#065F46':'#92400E'};text-transform:uppercase">
                    ${escapeHtml(r.status || 'pending')}
                  </span>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>`;
  } catch (e) {
    box.innerHTML = '<div style="color:#9CA3AF;font-size:12px;text-align:center;padding:14px">Tabel histori topup belum tersedia</div>';
  }
}

async function loadCoinUsage(){
  const box = document.getElementById('coinUsage');
  try {
    const r = await fetch('/ERP/harpy/hq/settings.php?action=coin_usage');
    const rows = await r.json();
    if (rows.error || !rows.length) {
      box.innerHTML = '<div style="color:#9CA3AF;font-size:12px;text-align:center;padding:14px">Belum ada riwayat pemakaian coin</div>';
      return;
    }
    box.innerHTML = `
      <div style="overflow-x:auto">
        <table style="width:100%;font-size:12px;border-collapse:collapse">
          <thead>
            <tr style="background:#F9FAFB">
              <th style="text-align:left;padding:8px 10px;font-size:10px;color:#6B7280;font-weight:800;text-transform:uppercase">Waktu</th>
              <th style="text-align:left;padding:8px 10px;font-size:10px;color:#6B7280;font-weight:800;text-transform:uppercase">Outlet</th>
              <th style="text-align:left;padding:8px 10px;font-size:10px;color:#6B7280;font-weight:800;text-transform:uppercase">Fitur</th>
              <th style="text-align:right;padding:8px 10px;font-size:10px;color:#6B7280;font-weight:800;text-transform:uppercase">Coin</th>
              <th style="text-align:right;padding:8px 10px;font-size:10px;color:#6B7280;font-weight:800;text-transform:uppercase">Saldo Setelah</th>
            </tr>
          </thead>
          <tbody>
            ${rows.map(r => `
              <tr style="border-top:1px solid #F3F4F6">
                <td style="padding:9px 10px">${new Date(r.created_at).toLocaleDateString('id-ID',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})}</td>
                <td style="padding:9px 10px">📍 ${escapeHtml(r.nama_outlet || '?')}</td>
                <td style="padding:9px 10px">
                  <div style="font-weight:600">${escapeHtml(r.feature_used || '-')}</div>
                  ${r.description ? `<div style="font-size:10px;color:#9CA3AF;margin-top:2px">${escapeHtml(r.description)}</div>` : ''}
                </td>
                <td style="padding:9px 10px;text-align:right;font-family:monospace;font-weight:700;color:#EF4444">
                  -${Number(Math.abs(r.amount||0)).toLocaleString('id-ID')}
                </td>
                <td style="padding:9px 10px;text-align:right;font-family:monospace;color:#6B7280">${Number(r.balance_after||0).toLocaleString('id-ID')}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>`;
  } catch (e) {
    box.innerHTML = '<div style="color:#9CA3AF;font-size:12px;text-align:center;padding:14px">Gagal memuat</div>';
  }
}
function escapeHtml(s){return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}

loadCoin();
loadTopupHistory();
loadCoinUsage();
</script>
<?php require __DIR__ . '/_layout_close.php'; ?>
