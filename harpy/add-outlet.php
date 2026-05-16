<?php
// ══════════════════════════════════════════════════════
// add-outlet.php — Wizard tambah outlet
// Outlet 1: trial 7 hari gratis, 1000 coin
// Outlet 2+: (fase berikutnya — butuh payment)
// ══════════════════════════════════════════════════════

$activePage = 'add-outlet';
define('ROOT', __DIR__);

// ── Auth check sebelum tenant_guard ───────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['tenant_id'])) {
    header('Location: /ERP/harpy/login.php?msg=not_logged_in');
    exit;
}

// tenant_guard memberikan: currentUser(), getCsrfToken(), Database, TenantResolver, dll
// TenantResolver sudah tolerate no-outlet untuk add-outlet.php
require_once ROOT . '/middleware/tenant_guard.php';

$tid  = TenantResolver::id();
$user = currentUser() ?? [];

// Hanya owner yang boleh tambah outlet
if (($user['role'] ?? '') !== 'owner') {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:60px;text-align:center;background:#0F1C3A;color:#fff;min-height:100vh">
        <h2 style="color:#35E8D5">🔒 Akses Ditolak</h2>
        <p style="color:rgba(255,255,255,.6)">Hanya owner yang bisa menambah outlet.</p>
        <a href="/ERP/harpy/dashboard.php" style="color:#35E8D5">← Kembali</a>
    </div>');
}

// CSRF
function aoCsrf(): string {
    if (empty($_SESSION['ao_csrf'])) $_SESSION['ao_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['ao_csrf'];
}
function aoVerifyCsrf(): void {
    if (!hash_equals(aoCsrf(), $_POST['_csrf'] ?? '')) {
        http_response_code(403); die('CSRF mismatch.');
    }
}

// Helper
function aoSlugify(string $str): string {
    $str = strtolower(trim($str));
    $str = preg_replace('/[^a-z0-9\s-]/', '', $str);
    $str = preg_replace('/[\s-]+/', '_', $str);
    return preg_replace('/^_+|_+$/', '', $str) ?: 'outlet';
}
function aoUniqueSlug(string $base, int $tenantId): string {
    $db = Database::get();
    $slug = aoSlugify($base);
    $i = 1;
    $candidate = $tenantId . '_' . $slug;
    while (true) {
        $s = $db->prepare("SELECT id FROM outlets WHERE slug=? LIMIT 1");
        $s->execute([$candidate]);
        if (!$s->fetch()) return $candidate;
        $candidate = $tenantId . '_' . $slug . '_' . $i++;
    }
}

// Hitung outlet yang sudah ada
$cntQ = Database::get()->prepare("SELECT COUNT(*) FROM outlets WHERE tenant_id=? AND status!='closed'");
$cntQ->execute([$tid]);
$outletCount = (int)$cntQ->fetchColumn();

$isFirstOutlet = $outletCount === 0;

// Per brief: outlet ke-2 dst wajib bayar setup fee (tidak ada trial gratis)
// Payment gateway belum di-integrasikan → tampilkan halaman placeholder
if (!$isFirstOutlet) {
    require_once __DIR__ . '/components.php';
    $hasOutlet = TenantResolver::hasOutlet();
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
    <?php renderHead('Tambah Outlet Baru'); ?>
    </head>
    <body>
    <?php renderTopbar('add-outlet', !$hasOutlet); ?>
    <div style="max-width:560px;margin:48px auto;padding:0 16px">
      <div style="background:#fff;border-radius:16px;padding:36px 28px;box-shadow:0 4px 24px rgba(0,0,0,.06);text-align:center">
        <div style="font-size:64px;margin-bottom:16px">💳</div>
        <h1 style="font-size:1.4rem;font-weight:800;color:#0F1C3A;margin-bottom:10px">
          Outlet Berbayar
        </h1>
        <p style="color:#6B7280;font-size:14px;line-height:1.7;margin-bottom:20px">
          Outlet pertama mendapat <strong>trial 7 hari gratis</strong>.
          Outlet berikutnya memerlukan pembayaran <strong>setup fee</strong>
          (Rp 300rb – 500rb) langsung tanpa periode trial.
        </p>
        <div style="background:#FEF3C7;border:1px solid #FCD34D;color:#92400E;padding:12px 16px;
                    border-radius:10px;font-size:13px;margin-bottom:24px;text-align:left">
          🚧 <strong>Coming soon</strong> — payment gateway sedang dalam pengembangan.
          Hubungi tim LAMASY untuk aktivasi outlet tambahan manual.
        </div>
        <a href="https://wa.me/6281234567890?text=<?= urlencode('Halo Tim LAMASY, saya mau buka outlet kedua untuk akun saya. Mohon info untuk proses pembayaran setup fee.') ?>"
           target="_blank" rel="noopener"
           style="display:inline-block;background:#25D366;color:#fff;font-weight:700;
                  font-size:14px;padding:13px 28px;border-radius:10px;text-decoration:none;margin-bottom:10px">
          💬 Chat Tim LAMASY untuk Aktivasi
        </a>
        <div>
          <a href="dashboard.php"
             style="display:inline-block;font-size:13px;color:#6B7280;text-decoration:none;padding:10px">
            ← Kembali ke Dashboard
          </a>
        </div>
      </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// Wizard state
if (isset($_GET['reset'])) unset($_SESSION['ao']);
if (empty($_SESSION['ao'])) {
    $_SESSION['ao'] = ['step' => 1, 'data' => []];
}
$w    = &$_SESSION['ao'];
$step = (int)($w['step'] ?? 1);
$d    = &$w['data'];
$error = '';
$success = false;

// ── Step 1 submit ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step1_submit'])) {
    aoVerifyCsrf();
    $namaOutlet = trim($_POST['nama_outlet'] ?? '');
    $kota       = trim($_POST['kota'] ?? '');
    $alamat     = trim($_POST['alamat'] ?? '');
    $telepon    = trim($_POST['telepon'] ?? '');

    if (strlen($namaOutlet) < 3) {
        $error = 'Nama outlet minimal 3 karakter.';
    } elseif (strlen($namaOutlet) > 80) {
        $error = 'Nama outlet maksimal 80 karakter.';
    } else {
        $d['nama_outlet'] = $namaOutlet;
        $d['kota']        = $kota;
        $d['alamat']      = $alamat;
        $d['telepon']     = $telepon;
        $w['step'] = 2; $step = 2;
        $_SESSION['ao_csrf'] = bin2hex(random_bytes(32));
    }
}

// ── Step 2: confirm & create ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step2_submit'])) {
    aoVerifyCsrf();

    $db = Database::get();
    $db->beginTransaction();
    try {
        $outletSlug  = aoUniqueSlug($d['nama_outlet'], $tid);
        $trialEnds   = date('Y-m-d H:i:s', time() + 7 * 86400);
        $trialCoins  = $isFirstOutlet ? 1000 : 0;   // hanya outlet 1 dapat trial coins
        $trialStatus = 'trial'; // semua outlet baru mulai di trial

        $db->prepare("
            INSERT INTO outlets
              (tenant_id, nama_outlet, slug, kota, alamat, telepon,
               status, trial_starts_at, trial_ends_at,
               trial_coin_balance, coin_balance, is_main, setup_done)
            VALUES (?,?,?,?,?,?,?,NOW(),?,?,0,?,0)
        ")->execute([
            $tid,
            $d['nama_outlet'],
            $outletSlug,
            $d['kota']    ?: null,
            $d['alamat']  ?: null,
            $d['telepon'] ?: null,
            $trialStatus,
            $trialEnds,
            $trialCoins,
            $isFirstOutlet ? 1 : 0,  // is_main hanya untuk outlet pertama
        ]);
        $outletId = (int)$db->lastInsertId();

        // Update total_outlets di tenant
        $db->prepare(
            "UPDATE tenants SET total_outlets = total_outlets + 1 WHERE id=?"
        )->execute([$tid]);

        // Set is_main jika outlet pertama
        if ($isFirstOutlet) {
            $db->prepare("UPDATE outlets SET is_main=1 WHERE id=?")->execute([$outletId]);
        }

        $db->commit();

        // Set outlet ke session
        $_SESSION['outlet_id'] = $outletId;
        $_SESSION['has_outlet'] = true;

        // Refresh TenantResolver
        TenantResolver::reset();

        unset($_SESSION['ao']);

        $success   = true;
        $outletName = $d['nama_outlet'];

    } catch (Throwable $e) {
        $db->rollBack();
        error_log('[add-outlet.php] Error: ' . $e->getMessage());
        $error = 'Terjadi kesalahan teknis. Coba lagi atau hubungi support.';
    }
}

// Back
if (isset($_POST['go_back'])) {
    $w['step'] = max(1, $step - 1);
    $step = $w['step'];
    $_SESSION['ao_csrf'] = bin2hex(random_bytes(32));
}

$csrf = aoCsrf();

require_once __DIR__ . '/components.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Tambah Outlet'); ?>
<style>
.ao-wrap {
  max-width: 560px;
  margin: 0 auto;
  padding: 32px 16px 60px;
}
.ao-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 32px;
}
.ao-brand a { text-decoration:none; color: var(--gray); font-size:13px; }
.ao-brand a:hover { color: var(--navy); }
.stepper {
  display: flex; align-items: center;
  margin-bottom: 28px;
}
.step-item {
  display: flex; flex-direction: column; align-items: center;
  flex: 1; position: relative;
}
.step-item:not(:last-child)::after {
  content: ''; position: absolute; top: 16px; left: 50%;
  width: 100%; height: 2px; background: rgba(27,45,90,.12); z-index: 0;
}
.step-item.done:not(:last-child)::after { background: var(--teal); }
.step-dot {
  width: 32px; height: 32px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 700; position: relative; z-index: 1;
  background: #f1f5f9; border: 2px solid rgba(27,45,90,.12); color: var(--gray);
  transition: all .3s;
}
.step-item.active .step-dot { background: var(--teal); color: var(--navy); border-color: var(--teal); }
.step-item.done   .step-dot { background: var(--teal-bg); color: var(--teal-d); border-color: var(--teal); }
.step-label { font-size: 11px; margin-top: 6px; color: var(--gray); text-align: center; }
.step-item.active .step-label { color: var(--teal-d); font-weight: 600; }
.ao-card {
  background: var(--white); border-radius: var(--r-lg);
  border: 1px solid rgba(27,45,90,.08);
  box-shadow: var(--shadow); padding: 32px;
}
.ao-title { font-size: 1.2rem; font-weight: 800; color: var(--navy); margin-bottom: 6px; }
.ao-sub { font-size: 14px; color: var(--gray); margin-bottom: 24px; line-height: 1.5; }
.field { margin-bottom: 18px; }
.field label { display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:var(--dark); }
.req { color: var(--teal); } .opt { color: var(--gray); font-weight:400; font-size:12px; }
.field input, .field textarea {
  width: 100%; padding: 10px 14px;
  border: 1.5px solid rgba(27,45,90,.12); border-radius: 8px;
  font-size: 14px; font-family: inherit; color: var(--dark);
  transition: border-color .2s; outline: none; background: #fff;
}
.field input:focus, .field textarea:focus { border-color: var(--teal); }
.field textarea { resize: vertical; min-height: 72px; }
.hint { font-size: 12px; color: var(--gray); margin-top: 5px; }
.alert-error {
  background: #FEF2F2; border: 1px solid #FECACA;
  color: #991B1B; padding: 12px 16px; border-radius: 8px;
  font-size: 14px; margin-bottom: 20px;
}
.btn-row { display: flex; gap: 10px; margin-top: 24px; }
.review-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 10px 0; border-bottom: 1px solid rgba(27,45,90,.07);
  font-size: 14px;
}
.review-row:last-child { border-bottom: none; }
.rv-label { color: var(--gray); } .rv-val { font-weight: 600; text-align: right; }
.trial-box {
  background: var(--teal-bg); border: 1.5px solid rgba(53,232,213,.25);
  border-radius: 10px; padding: 16px; margin-top: 20px;
}
.trial-box .trial-title { font-size: 13px; font-weight: 700; color: var(--teal-d); margin-bottom: 8px; }
.trial-box ul { font-size: 13px; color: var(--dark); padding-left: 18px; line-height: 1.9; }

/* Success screen */
.ao-success {
  text-align: center; padding: 40px 20px;
}
.ao-success .big-icon { font-size: 72px; margin-bottom: 16px; }
.ao-success h1 { font-size: 1.5rem; font-weight: 800; color: var(--navy); margin-bottom: 10px; }
.ao-success p  { font-size: 15px; color: var(--gray); margin-bottom: 28px; line-height: 1.6; }
</style>
</head>
<body>
<?php
// Halaman ini selalu dalam konteks no-outlet (atau menambah outlet baru).
// Sembunyikan nav jika tenant belum punya outlet aktif.
$hasOutlet = TenantResolver::hasOutlet();
renderTopbar('add-outlet', !$hasOutlet);
?>
<div class="ao-wrap">

  <?php if ($success): ?>
    <!-- ══ SUCCESS ══ -->
    <div class="hl-card">
      <div class="ao-success">
        <div class="big-icon">🎉</div>
        <h1>Outlet Berhasil Ditambahkan!</h1>
        <p>
          <strong><?= htmlspecialchars($outletName ?? '') ?></strong> sudah aktif dan siap digunakan.<br>
          <?php if ($isFirstOutlet): ?>
            Kamu mendapat <strong>1.000 coin trial</strong> gratis untuk 7 hari ke depan.
          <?php endif; ?>
        </p>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
          <a href="/ERP/harpy/dashboard.php" class="hl-btn hl-btn-primary" style="padding:13px 32px">
            🚀 Mulai Kelola Laundry
          </a>
          <a href="/ERP/harpy/layanan.php" class="hl-btn hl-btn-outline" style="padding:13px 24px">
            Atur Layanan & Harga
          </a>
        </div>
      </div>
    </div>

  <?php else: ?>

    <!-- Breadcrumb -->
    <div class="ao-brand">
      <a href="/ERP/harpy/dashboard.php">← Dashboard</a>
      <span style="color:rgba(27,45,90,.2)">/</span>
      <span style="font-size:13px;font-weight:600;color:var(--navy)">Tambah Outlet</span>
    </div>

    <!-- Stepper -->
    <div class="stepper">
      <?php
      $stepsMeta = [1=>'Info Outlet', 2=>'Konfirmasi'];
      foreach ($stepsMeta as $sn => $sl):
        $cls = $sn < $step ? 'done' : ($sn === $step ? 'active' : '');
        $dot = $sn < $step ? '✓' : $sn;
      ?>
        <div class="step-item <?= $cls ?>">
          <div class="step-dot"><?= $dot ?></div>
          <div class="step-label"><?= $sl ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Card -->
    <div class="hl-card ao-card">

      <?php if ($error): ?>
        <div class="alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php // ═══ STEP 1 ═════════════════════════════════
      if ($step === 1): ?>

        <div class="ao-title">
          <?= $isFirstOutlet ? '🏪 Outlet Pertama Kamu' : '➕ Tambah Outlet Baru' ?>
        </div>
        <p class="ao-sub">
          <?= $isFirstOutlet
            ? 'Lengkapi info outlet untuk mulai menggunakan LAMASY.'
            : 'Outlet baru akan mulai dengan periode trial.' ?>
        </p>

        <form method="POST" autocomplete="off">
          <input type="hidden" name="_csrf" value="<?= $csrf ?>">
          <div class="field">
            <label>Nama Outlet <span class="req">*</span></label>
            <input type="text" name="nama_outlet" required maxlength="80"
                   value="<?= htmlspecialchars($d['nama_outlet'] ?? '') ?>"
                   placeholder="cth: Laundry Bersih Jaya – Cabang Utama"
                   autofocus>
          </div>
          <div class="field">
            <label>Kota <span class="opt">(opsional)</span></label>
            <input type="text" name="kota" maxlength="100"
                   value="<?= htmlspecialchars($d['kota'] ?? '') ?>"
                   placeholder="cth: Bandung">
          </div>
          <div class="field">
            <label>Alamat <span class="opt">(opsional)</span></label>
            <textarea name="alamat" rows="2" maxlength="300"
                      placeholder="Jl. Contoh No. 1, Kel. ..."><?= htmlspecialchars($d['alamat'] ?? '') ?></textarea>
          </div>
          <div class="field">
            <label>Nomor Telepon Outlet <span class="opt">(opsional)</span></label>
            <input type="tel" name="telepon" maxlength="20"
                   value="<?= htmlspecialchars($d['telepon'] ?? '') ?>"
                   placeholder="cth: 022-1234567">
            <div class="hint">Untuk keperluan nota & profil outlet</div>
          </div>

          <?php if ($isFirstOutlet): ?>
          <div class="trial-box">
            <div class="trial-title">🎁 Yang kamu dapatkan gratis:</div>
            <ul>
              <li>Trial <strong>7 hari</strong> tanpa biaya</li>
              <li><strong>1.000 coin</strong> untuk coba fitur AI & WA</li>
              <li>Akses semua fitur manajemen laundry</li>
            </ul>
          </div>
          <?php endif; ?>

          <div class="btn-row">
            <a href="/ERP/harpy/dashboard.php" class="hl-btn hl-btn-outline">Batal</a>
            <button type="submit" name="step1_submit" class="hl-btn hl-btn-primary" style="flex:1">
              Lanjut →
            </button>
          </div>
        </form>

      <?php // ═══ STEP 2: Review & Confirm ════════════════
      elseif ($step === 2): ?>

        <div class="ao-title">✅ Konfirmasi Outlet</div>
        <p class="ao-sub">Periksa kembali sebelum outlet dibuat.</p>

        <div style="margin-bottom:20px">
          <div class="review-row">
            <span class="rv-label">Nama Outlet</span>
            <span class="rv-val"><?= htmlspecialchars($d['nama_outlet'] ?? '-') ?></span>
          </div>
          <?php if (!empty($d['kota'])): ?>
          <div class="review-row">
            <span class="rv-label">Kota</span>
            <span class="rv-val"><?= htmlspecialchars($d['kota']) ?></span>
          </div>
          <?php endif; ?>
          <?php if (!empty($d['alamat'])): ?>
          <div class="review-row">
            <span class="rv-label">Alamat</span>
            <span class="rv-val" style="max-width:65%;text-align:right"><?= htmlspecialchars($d['alamat']) ?></span>
          </div>
          <?php endif; ?>
          <?php if (!empty($d['telepon'])): ?>
          <div class="review-row">
            <span class="rv-label">Telepon</span>
            <span class="rv-val"><?= htmlspecialchars($d['telepon']) ?></span>
          </div>
          <?php endif; ?>
          <div class="review-row">
            <span class="rv-label">Paket</span>
            <span class="rv-val" style="color:var(--teal-d)">
              <?= $isFirstOutlet ? '🎁 Trial 7 Hari + 1.000 Coin' : '🧪 Trial 7 Hari' ?>
            </span>
          </div>
          <div class="review-row">
            <span class="rv-label">Biaya</span>
            <span class="rv-val" style="color:var(--green)">
              <?= $isFirstOutlet ? 'Gratis' : 'Gratis (trial)' ?>
            </span>
          </div>
        </div>

        <form method="POST">
          <input type="hidden" name="_csrf" value="<?= $csrf ?>">
          <div class="btn-row">
            <button type="submit" name="go_back" class="hl-btn hl-btn-outline">← Kembali</button>
            <button type="submit" name="step2_submit" class="hl-btn hl-btn-primary" style="flex:1">
              🏪 Buat Outlet
            </button>
          </div>
        </form>

      <?php endif; ?>

    </div><!-- /ao-card -->

  <?php endif; // success ?>

</div><!-- /ao-wrap -->

<?php renderToast(); ?>
</body>
</html>
