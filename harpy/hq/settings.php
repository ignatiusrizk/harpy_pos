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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    if (!hash_equals(getCsrfToken(), $_POST['_csrf'] ?? '')) {
        $profileError = 'CSRF mismatch';
    } else {
        $namaOutlet = substr(trim(strip_tags($_POST['nama_outlet'] ?? '')), 0, 100);
        $ownerName  = substr(trim(strip_tags($_POST['owner_name'] ?? '')), 0, 100);
        $ownerWa    = preg_replace('/\D/', '', $_POST['owner_wa'] ?? '');
        if (substr($ownerWa, 0, 2) === '08') $ownerWa = '628' . substr($ownerWa, 2);
        if (substr($ownerWa, 0, 1) === '8')  $ownerWa = '62' . $ownerWa;
        $kota = substr(trim(strip_tags($_POST['kota'] ?? '')), 0, 100);

        if (!$namaOutlet) {
            $profileError = 'Nama brand wajib diisi';
        } else {
            try {
                // Cek duplicate WA (kecuali tenant ini sendiri)
                if ($ownerWa) {
                    $chk = $db->prepare("SELECT id FROM tenants WHERE owner_wa=? AND id!=? LIMIT 1");
                    $chk->execute([$ownerWa, $tid]);
                    if ($chk->fetchColumn()) {
                        $profileError = 'Nomor WhatsApp sudah dipakai akun lain.';
                    }
                }
                if (!$profileError) {
                    $db->prepare("UPDATE tenants SET nama_outlet=?, owner_name=?, owner_wa=?, kota=? WHERE id=?")
                       ->execute([$namaOutlet, $ownerName ?: null, $ownerWa ?: null, $kota ?: null, $tid]);
                    logAcc($db, $tid, $uid, "profile updated");
                    $profileSuccess = true;
                    // Refresh tenant data
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

$csrf = getCsrfToken();
$supportWa = '6281234567890';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HQ Settings — LAMASY</title>
<meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',system-ui,sans-serif;background:#F4F7FB;color:#0F1C3A;min-height:100vh}
  .hq-topbar{background:#0F1C3A;color:#fff;padding:14px 24px;display:flex;justify-content:space-between;
             align-items:center;flex-wrap:wrap;gap:12px;box-shadow:0 1px 8px rgba(0,0,0,.15)}
  .hq-brand{display:flex;align-items:center;gap:10px;font-size:16px;font-weight:700;color:#35E8D5}
  .hq-brand-sub{color:rgba(255,255,255,.5);font-size:11px;font-weight:400;margin-left:4px}
  .hq-badge{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;font-size:10px;font-weight:800;
            padding:3px 10px;border-radius:100px;letter-spacing:.06em}
  .hq-topbar-right{display:flex;align-items:center;gap:14px;font-size:13px;color:rgba(255,255,255,.85)}
  .hq-topbar a{color:rgba(255,255,255,.55);text-decoration:none;font-size:13px;padding:6px 10px;border-radius:6px}
  .hq-topbar a:hover{background:rgba(255,255,255,.08);color:#fff}
  .hq-topbar a.active{background:rgba(53,232,213,.15);color:#35E8D5}
  .hq-logout{border:1px solid rgba(255,255,255,.15);padding:6px 14px!important}

  .container{max-width:980px;margin:24px auto;padding:0 20px 60px}
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
</head>
<body>

<div class="hq-topbar">
  <div class="hq-brand">
    <img src="/ERP/harpy/assets/logo.png" alt="LAMASY" style="height:28px">
    LAMASY <span class="hq-brand-sub">by Harpy</span>
    <span class="hq-badge">🏢 HQ</span>
  </div>
  <div class="hq-topbar-right">
    <a href="/ERP/harpy/dashboard.php?to=hq">📊 Dashboard</a>
    <a href="/ERP/harpy/hq/outlet.php">🏪 Outlet</a>
    <a href="/ERP/harpy/hq/karyawan.php">👥 Karyawan</a>
    <a href="/ERP/harpy/hq/pelanggan.php">🧑‍🤝‍🧑 Pelanggan</a>
    <a href="/ERP/harpy/hq/promo.php">🎟️ Promo</a>
    <a href="/ERP/harpy/hq/laporan.php">📈 Laporan</a>
    <a href="/ERP/harpy/hq/settings.php" class="active">⚙️ Settings</a>
    <span><?= htmlspecialchars($ownerNama) ?></span>
    <a href="/ERP/harpy/dashboard.php?to=outlet">← Outlet View</a>
    <a href="/ERP/harpy/logout.php" class="hq-logout" onclick="return confirm('Yakin logout?')">Logout</a>
  </div>
</div>

<div class="container">
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

      <div>
        <button type="submit" class="btn btn-primary">💾 Simpan Profil</button>
      </div>

      <div class="danger-zone" style="margin-top:8px">
        ⚠️ <strong>Ingin ganti email login?</strong>
        Hubungi tim LAMASY via WhatsApp untuk proses verifikasi keamanan.
      </div>
    </form>
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

  <!-- ③ COIN & BILLING -->
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
  </div>

  <!-- ④ INFO AKUN -->
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

loadCoin();
</script>
</body>
</html>
