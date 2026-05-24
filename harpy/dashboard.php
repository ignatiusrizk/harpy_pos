<?php
// ── Mode routing (HQ vs Outlet) — single URL /dashboard.php ──
if (session_status() === PHP_SESSION_NONE) session_start();

// Switch mode via ?to=hq atau ?to=outlet
if (isset($_GET['to'])) {
    $_SESSION['hq_mode'] = ($_GET['to'] === 'hq');
    header('Location: dashboard.php');
    exit;
}

// Kalau hq_mode aktif & role boleh akses HQ → render HQ view
$_dashRole = $_SESSION['hl_user']['role'] ?? '';
if (!empty($_SESSION['hq_mode'])
    && in_array($_dashRole, ['owner','manager','superadmin'], true)) {
    require __DIR__ . '/hq/dashboard.php';
    exit;
}
// Kalau bukan owner/manager tapi hq_mode tertinggal di session → reset
$_SESSION['hq_mode'] = false;

$activePage = 'dashboard';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once __DIR__ . '/components.php';

$user  = currentUser();
$today = date('Y-m-d');
$tid   = TenantResolver::id();

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php?msg=logout');
    exit;
}

// ── Early: tangani AJAX saat belum ada outlet ─────────
$hasOutlet = TenantResolver::hasOutlet();

// ── POST handlers (no-outlet state) — HARUS sebelum output HTML ──
$pwError = '';

if (!$hasOutlet && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $ownerWa  = preg_replace('/\D/', '', $_POST['owner_wa'] ?? '');
    if (substr($ownerWa, 0, 2) === '08') $ownerWa = '628' . substr($ownerWa, 2);
    if (substr($ownerWa, 0, 1) === '8')  $ownerWa = '62' . $ownerWa;
    $namaOutlet = trim(strip_tags($_POST['nama_outlet'] ?? ''));
    $kota       = trim(strip_tags($_POST['kota'] ?? ''));

    try {
        $db = Database::get();
        $db->prepare("UPDATE tenants SET nama_outlet=?, owner_wa=?, kota=? WHERE id=?")
           ->execute([$namaOutlet ?: null, $ownerWa ?: null, $kota ?: null, $tid]);
        TenantResolver::reset();
        header('Location: dashboard.php?profile_saved=1');
        exit;
    } catch (Throwable $e) {
        error_log('[dashboard save_profile] ' . $e->getMessage());
        header('Location: dashboard.php?profile_error=' . urlencode($e->getMessage()));
        exit;
    }
}

if (!$hasOutlet && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $db        = Database::get();
    $currentPw = $_POST['current_password'] ?? '';
    $newPw     = $_POST['new_password']     ?? '';
    $confPw    = $_POST['confirm_password'] ?? '';

    $row = $db->prepare("SELECT password FROM hl_users WHERE id=?");
    $row->execute([$user['id']]);
    $stored = $row->fetchColumn();

    if (!password_verify($currentPw, $stored)) {
        $pwError = 'Password lama tidak sesuai.';
    } elseif (strlen($newPw) < 8) {
        $pwError = 'Password baru minimal 8 karakter.';
    } elseif ($newPw !== $confPw) {
        $pwError = 'Konfirmasi password tidak cocok.';
    } else {
        $hash = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 11]);
        $db->prepare("UPDATE hl_users SET password=? WHERE id=?")->execute([$hash, $user['id']]);
        $db->prepare("UPDATE tenants SET password_hash=? WHERE id=?")->execute([$hash, $tid]);
        header('Location: dashboard.php?pw_changed=1');
        exit;
    }
}

// ── AJAX ACTIONS ──────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    // Jika belum ada outlet, kembalikan data kosong agar JS tidak error
    if (!$hasOutlet) {
        if ($action === 'stats') {
            echo json_encode(['order'=>['total_order'=>0,'omset'=>0,'terkumpul'=>0,'belum_lunas'=>0,'siap_diambil'=>0],'kas'=>['masuk'=>0,'keluar'=>0],'aktif'=>0,'hadir'=>0,'saldo'=>0,'is_staff'=>false,'role'=>$user['role']]);
        } elseif ($action === 'alerts') {
            echo json_encode(['siap'=>[],'mepet'=>[],'piutang'=>[]]);
        } elseif ($action === 'pipeline') {
            echo json_encode([]);
        } elseif ($action === 'chart7') {
            echo json_encode([]);
        } else {
            echo json_encode(['error'=>'Belum ada outlet.']);
        }
        exit;
    }

    $oid = TenantResolver::outletId();

    // ── STATS HARIAN ─────────────────────────────────
    if ($action === 'stats') {
        $isStaff = ($user['role'] === 'staff');

        if ($isStaff) {
            $orderData = TenantQuery::rawOne(
                "SELECT COUNT(*) as total_order,
                        COALESCE(SUM(total),0) as omset,
                        COALESCE(SUM(dp),0) as terkumpul,
                        SUM(CASE WHEN status_bayar != 'lunas' THEN 1 ELSE 0 END) as belum_lunas,
                        SUM(CASE WHEN status_proses = 'siap' THEN 1 ELSE 0 END) as siap_diambil
                 FROM hl_transaksi
                 WHERE tenant_id = ? AND outlet_id = ? AND DATE(tanggal) = ? AND created_by = ?",
                [$tid, $oid, $today, $user['id']]
            );
        } else {
            $orderData = TenantQuery::rawOne(
                "SELECT COUNT(*) as total_order,
                        COALESCE(SUM(total),0) as omset,
                        COALESCE(SUM(dp),0) as terkumpul,
                        SUM(CASE WHEN status_bayar != 'lunas' THEN 1 ELSE 0 END) as belum_lunas,
                        SUM(CASE WHEN status_proses = 'siap' THEN 1 ELSE 0 END) as siap_diambil
                 FROM hl_transaksi
                 WHERE tenant_id = ? AND outlet_id = ? AND DATE(tanggal) = ?",
                [$tid, $oid, $today]
            );
        }

        $kasData = ['masuk' => 0, 'keluar' => 0];
        if (!$isStaff) {
            $kasData = TenantQuery::rawOne(
                "SELECT COALESCE(SUM(CASE WHEN tipe='masuk' THEN jumlah END),0) as masuk,
                        COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah END),0) as keluar
                 FROM hl_kas WHERE tenant_id = ? AND outlet_id = ? AND tanggal = ?",
                [$tid, $oid, $today]
            ) ?: ['masuk' => 0, 'keluar' => 0];
        }

        $aktif = TenantQuery::count('hl_transaksi', "status_proses != 'diambil'");

        if ($isStaff) {
            $absensi = TenantQuery::rawOne(
                "SELECT jam_masuk FROM hl_absensi WHERE tenant_id = ? AND outlet_id = ? AND user_id = ? AND tanggal = ?",
                [$tid, $oid, $user['id'], $today]
            );
            $hadir = ($absensi && $absensi['jam_masuk']) ? 1 : 0;
        } else {
            $hadir = TenantQuery::count('hl_absensi', "tanggal = ? AND status = 'hadir'", [$today]);
        }

        // Target omset (defensif kalau kolom belum ada)
        $targetHarian = 0; $targetBulanan = 0;
        try {
            $tg = TenantQuery::rawOne(
                "SELECT target_omset_harian, target_omset_bulanan FROM outlets WHERE id=? AND tenant_id=?",
                [$oid, $tid]
            );
            if ($tg) { $targetHarian = (int)$tg['target_omset_harian']; $targetBulanan = (int)$tg['target_omset_bulanan']; }
        } catch (Throwable) {}

        // Omset bulan ini (untuk progress bulanan)
        $omsetBulan = 0;
        try {
            $om = TenantQuery::rawOne(
                "SELECT COALESCE(SUM(total),0) s FROM hl_transaksi
                  WHERE tenant_id=? AND outlet_id=? AND DATE_FORMAT(tanggal,'%Y-%m')=DATE_FORMAT(?, '%Y-%m')",
                [$tid, $oid, $today]
            );
            $omsetBulan = (int)($om['s'] ?? 0);
        } catch (Throwable) {}

        echo json_encode([
            'order'    => $orderData,
            'kas'      => $kasData,
            'aktif'    => $aktif,
            'hadir'    => $hadir,
            'saldo'    => floatval($kasData['masuk']) - floatval($kasData['keluar']),
            'is_staff' => $isStaff,
            'role'     => $user['role'],
            'target'   => [
                'harian'      => $targetHarian,
                'bulanan'     => $targetBulanan,
                'omset_bulan' => $omsetBulan,
                'hari_sisa'   => max(0, (int)date('t') - (int)date('j')),
            ],
        ]);
        exit;
    }

    // ── QUICK SEARCH (HP/nama/no order) ──
    if ($action === 'quick_search') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 3) { echo json_encode(['ok'=>true,'rows'=>[]]); exit; }
        try {
            $like = '%' . $q . '%';
            $db = Database::get();
            $stmt = $db->prepare("
                SELECT t.id, t.no_order, t.nama_pelanggan, t.telepon,
                       t.total, t.status_proses, t.status_bayar,
                       t.tanggal, t.estimasi_selesai, t.created_at
                  FROM hl_transaksi t
                 WHERE t.tenant_id=? AND t.outlet_id=?
                   AND (t.no_order LIKE ? OR t.nama_pelanggan LIKE ? OR t.telepon LIKE ?)
                 ORDER BY t.status_proses='diambil' ASC, t.id DESC
                 LIMIT 10
            ");
            $stmt->execute([$tid, $oid, $like, $like, $like]);
            echo json_encode(['ok'=>true, 'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    // ── EXTRAS (segmen breakdown, top 5 pelanggan, week vs week) ──
    if ($action === 'extras') {
        $thisWeekStart = date('Y-m-d', strtotime('monday this week'));
        $todayStr      = date('Y-m-d');
        $lastWeekStart = date('Y-m-d', strtotime('monday this week -7 days'));
        $lastWeekEnd   = date('Y-m-d', strtotime('sunday this week -7 days'));

        // 1. Breakdown segmen hari ini
        $segmen = [];
        try {
            $s = $db->prepare("
                SELECT COALESCE(l.segmen, CASE WHEN t.drop_point_id IS NOT NULL THEN 'drop_point' ELSE 'lainnya' END) seg,
                       COALESCE(SUM(ti.subtotal),0) total
                  FROM hl_transaksi t
                  LEFT JOIN hl_transaksi_item ti ON ti.transaksi_id=t.id
                  LEFT JOIN hl_layanan l ON l.id=ti.layanan_id
                 WHERE t.tenant_id=? AND t.outlet_id=? AND DATE(t.tanggal)=?
                 GROUP BY seg ORDER BY total DESC
            ");
            $s->execute([$tid, $oid, $todayStr]);
            $segmen = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {}

        // 2. Top 5 pelanggan bulan ini
        $topCust = [];
        try {
            $monthStart = date('Y-m-01');
            $s = $db->prepare("
                SELECT p.nama, p.telepon, COUNT(t.id) ord, COALESCE(SUM(t.total),0) spend
                  FROM hl_transaksi t
                  JOIN hl_pelanggan p ON p.id=t.pelanggan_id AND p.tenant_id=t.tenant_id
                 WHERE t.tenant_id=? AND t.outlet_id=? AND DATE(t.tanggal) BETWEEN ? AND ?
                 GROUP BY p.id, p.nama, p.telepon
                 ORDER BY spend DESC LIMIT 5
            ");
            $s->execute([$tid, $oid, $monthStart, $todayStr]);
            $topCust = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {}

        // 3. Week vs week (omset & order)
        $wow = ['this_omset'=>0,'this_order'=>0,'last_omset'=>0,'last_order'=>0];
        try {
            $s = $db->prepare("SELECT COUNT(*) c, COALESCE(SUM(total),0) o FROM hl_transaksi
                                WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal) BETWEEN ? AND ?");
            $s->execute([$tid,$oid,$thisWeekStart,$todayStr]); $a = $s->fetch(PDO::FETCH_ASSOC);
            $wow['this_omset'] = (int)$a['o']; $wow['this_order'] = (int)$a['c'];
            $s->execute([$tid,$oid,$lastWeekStart,$lastWeekEnd]); $b = $s->fetch(PDO::FETCH_ASSOC);
            $wow['last_omset'] = (int)$b['o']; $wow['last_order'] = (int)$b['c'];
        } catch (Throwable) {}

        echo json_encode(['ok'=>true, 'segmen'=>$segmen, 'top_pelanggan'=>$topCust, 'wow'=>$wow]);
        exit;
    }

    // ── ALERTS ───────────────────────────────────────
    if ($action === 'alerts') {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        $siap = TenantQuery::raw(
            "SELECT no_order, nama_pelanggan, telepon, estimasi_selesai,
                    total, sisa_bayar, status_bayar, updated_at
             FROM hl_transaksi
             WHERE tenant_id = ? AND outlet_id = ? AND status_proses = 'siap'
             ORDER BY updated_at DESC LIMIT 20",
            [$tid, $oid]
        );

        $mepet = TenantQuery::raw(
            "SELECT no_order, nama_pelanggan, telepon, estimasi_selesai,
                    total, sisa_bayar, status_bayar, status_proses
             FROM hl_transaksi
             WHERE tenant_id = ? AND outlet_id = ? AND estimasi_selesai <= ?
               AND status_proses NOT IN ('siap','diambil')
             ORDER BY estimasi_selesai ASC LIMIT 20",
            [$tid, $oid, $tomorrow]
        );

        $piutang = TenantQuery::raw(
            "SELECT t.no_order, t.nama_pelanggan, t.telepon, t.tanggal,
                    t.total, t.sisa_bayar, t.status_proses,
                    DATEDIFF(CURDATE(), t.tanggal) as hari_lalu
             FROM hl_transaksi t
             LEFT JOIN hl_pelanggan p ON p.id = t.pelanggan_id
                                      AND p.tenant_id = t.tenant_id
                                      AND p.outlet_id = t.outlet_id
             WHERE t.tenant_id = ? AND t.outlet_id = ?
               AND t.status_bayar != 'lunas'
               AND t.tanggal <= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
               AND t.status_proses != 'diambil'
               AND (p.metode_bayar IS NULL OR p.metode_bayar = 'langsung')
             ORDER BY t.tanggal ASC LIMIT 20",
            [$tid, $oid]
        );

        // Mitra drop point yang inaktif >7 hari (tidak ada order)
        $mitraInaktif = [];
        try {
            $mitraInaktif = TenantQuery::raw(
                "SELECT dp.id, dp.nama_mitra, dp.wa,
                        (SELECT MAX(created_at) FROM hl_transaksi
                          WHERE tenant_id=dp.tenant_id AND drop_point_id=dp.id) AS last_order
                   FROM hl_drop_points dp
                  WHERE dp.tenant_id=? AND dp.outlet_id=? AND dp.status='aktif'
                    AND NOT EXISTS (
                       SELECT 1 FROM hl_transaksi t
                        WHERE t.tenant_id=dp.tenant_id AND t.drop_point_id=dp.id
                          AND t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    )
                  ORDER BY dp.nama_mitra",
                [$tid, $oid]
            );
        } catch (Throwable) {}

        echo json_encode(['siap' => $siap, 'mepet' => $mepet, 'piutang' => $piutang, 'mitra_inaktif' => $mitraInaktif]);
        exit;
    }

    // ── PIPELINE ─────────────────────────────────────
    if ($action === 'pipeline') {
        $rows = TenantQuery::raw(
            "SELECT status_proses, COUNT(*) as count
             FROM hl_transaksi
             WHERE tenant_id = ? AND outlet_id = ? AND status_proses != 'diambil'
             GROUP BY status_proses",
            [$tid, $oid]
        );
        $map = [];
        foreach ($rows as $r) $map[$r['status_proses']] = $r['count'];
        echo json_encode($map);
        exit;
    }

    // ── CHART 7 HARI ─────────────────────────────────
    if ($action === 'chart7') {
        $rows = TenantQuery::raw(
            "SELECT DATE(tanggal) as tgl,
                    COALESCE(SUM(total),0) as omset,
                    COUNT(*) as order_count
             FROM hl_transaksi
             WHERE tenant_id = ? AND outlet_id = ? AND tanggal >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
             GROUP BY DATE(tanggal) ORDER BY tgl",
            [$tid, $oid]
        );
        echo json_encode($rows);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Dashboard'); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
.dash-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 20px; }
.dash-card {
  background: var(--white); border-radius: var(--r-lg);
  border: 1px solid rgba(27,45,90,.07); box-shadow: var(--shadow);
  padding: 20px; position: relative; overflow: hidden;
}
.dash-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
.dash-card.teal::before   { background: linear-gradient(90deg,var(--teal),var(--teal-d)); }
.dash-card.green::before  { background: linear-gradient(90deg,var(--green),#34D399); }
.dash-card.red::before    { background: linear-gradient(90deg,var(--red),#F87171); }
.dash-card.navy::before   { background: linear-gradient(90deg,var(--navy),#2D4A8A); }
.dash-card.purple::before { background: linear-gradient(90deg,var(--purple),#A78BFA); }
.dash-num   { font-size: 1.6rem; font-weight: 900; color: var(--navy); font-family: var(--mono); line-height: 1; margin-bottom: 4px; }
.dash-label { font-size: 12px; color: var(--gray); font-weight: 500; }
.dash-sub   { font-size: 11px; color: var(--gray); margin-top: 6px; }
.pipeline   { display: flex; gap: 8px; }
.pipe-item  {
  flex: 1; background: var(--white); border-radius: var(--r);
  padding: 12px 14px; border: 1px solid rgba(27,45,90,.07);
  box-shadow: var(--shadow); text-align: center;
}
.pipe-num   { font-size: 1.4rem; font-weight: 800; color: var(--navy); font-family: var(--mono); }
.pipe-label { font-size: 11px; color: var(--gray); margin-top: 3px; }
.pipe-item.active { border-color: var(--teal); background: var(--teal-bg); }
.pipe-item.active .pipe-num { color: var(--teal-d); }
.alert-title  { font-size: 13px; font-weight: 700; color: var(--navy); display: flex; align-items: center; gap: 8px; }
.alert-badge  { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 100px; }
.alert-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 14px; background: var(--white);
  border-radius: var(--r); border: 1px solid rgba(27,45,90,.07);
  margin-bottom: 6px; gap: 12px;
}
.alert-no   { font-family: var(--mono); font-size: 12px; font-weight: 700; color: var(--teal-d); white-space: nowrap; }
.alert-nama { font-size: 14px; font-weight: 600; color: var(--navy); }
.alert-meta { font-size: 12px; color: var(--gray); }
.alert-wa   { padding: 5px 10px; background: #25D366; color: white; border: none; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; text-decoration: none; }
.chart-wrap { position: relative; height: 200px; }

@media(max-width:900px) {
  .dash-grid { grid-template-columns: repeat(2,1fr); }
  .pipeline  { flex-wrap: wrap; }
  .pipe-item { flex: 1 1 calc(33% - 8px); min-width: 80px; }
}
@media(max-width:680px) {
  .dash-grid { grid-template-columns: repeat(2,1fr); gap: 10px; }
  .dash-card { padding: 12px; }
  .dash-num  { font-size: 1.1rem; }
  .dash-sub  { font-size: 10px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .pipeline  { overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; }
  .pipe-item { flex: 0 0 auto; min-width: 62px; padding: 9px 7px; }
  .alert-row { flex-wrap: wrap; gap: 6px; padding: 9px 10px; }
  .alert-row > div:first-child { flex: 1 1 100%; }
  .alert-row > div:last-child  { width: 100%; display: flex; justify-content: flex-end; gap: 6px; }
  .chart-wrap { height: 150px; }
}
</style>
</head>
<body>
<?php renderTopbar('dashboard', !$hasOutlet); ?>

<?php if (!$hasOutlet):
// ════════════════════════════════════════════════════════
// NO-OUTLET STATE — onboarding dashboard
// ════════════════════════════════════════════════════════
$tenant = currentTenant();
$ownerNama  = $user['nama'] ?? 'Owner';
$tenantNama = $tenant['nama_outlet'] ?? '';
$tenantWa   = $tenant['owner_wa']   ?? '';
$tenantKota = $tenant['kota']       ?? '';
$tenantEmail= $tenant['email']      ?? $user['email'] ?? '';

// Cek onboarding progress
$profileDone  = !empty($tenantWa);
$profileSaved = isset($_GET['profile_saved']);
$pwChanged    = isset($_GET['pw_changed']);
$pwError      = $pwError ?? '';
?>
<div style="background:#F4F7FB;min-height:calc(100vh - 60px);padding:28px 16px 80px">
<div style="max-width:860px;margin:0 auto;display:flex;flex-direction:column;gap:20px">

<?php if ($profileSaved): ?>
<div style="background:#D1FAE5;border:1px solid #6EE7B7;color:#065F46;padding:10px 16px;border-radius:8px;font-size:14px">
  ✅ Profil berhasil diperbarui.
</div>
<?php endif; ?>
<?php if (!empty($_GET['profile_error'])): ?>
<div style="background:#FEE2E2;border:1px solid #FCA5A5;color:#991B1B;padding:10px 16px;border-radius:8px;font-size:14px">
  ❌ Gagal menyimpan profil: <?= htmlspecialchars($_GET['profile_error']) ?>
</div>
<?php endif; ?>
<?php if ($pwChanged): ?>
<div style="background:#D1FAE5;border:1px solid #6EE7B7;color:#065F46;padding:10px 16px;border-radius:8px;font-size:14px">
  ✅ Password berhasil diubah.
</div>
<?php endif; ?>

<!-- ① HERO CTA ──────────────────────────────────────── -->
<div style="background:linear-gradient(135deg,#0F1C3A 0%,#1a2d52 100%);
            border-radius:16px;padding:36px 32px;color:#fff;text-align:center;
            position:relative;overflow:hidden">
  <div style="position:absolute;inset:0;background:radial-gradient(ellipse at 70% 50%,rgba(53,232,213,.15),transparent 70%)"></div>
  <div style="position:relative">
    <div style="display:inline-block;background:rgba(53,232,213,.15);border:1px solid rgba(53,232,213,.3);
                color:#35E8D5;font-size:12px;font-weight:700;padding:4px 14px;border-radius:100px;
                margin-bottom:16px;letter-spacing:.05em">
      🎁 TRIAL 7 HARI GRATIS · 1.000 COIN
    </div>
    <h1 style="font-size:clamp(1.4rem,4vw,2rem);font-weight:800;margin:0 0 12px;line-height:1.25">
      Selamat datang, <?= htmlspecialchars($ownerNama) ?>! 👋
    </h1>
    <p style="font-size:15px;color:rgba(255,255,255,.75);max-width:500px;margin:0 auto 28px;line-height:1.65">
      Akun LAMASY kamu sudah aktif. Daftarkan outlet pertama untuk mulai
      mengelola laundry dengan AI — gratis 7 hari, tanpa kartu kredit.
    </p>
    <a href="/ERP/harpy/add-outlet.php"
       style="display:inline-block;background:#35E8D5;color:#0F1C3A;font-weight:800;
              font-size:16px;padding:15px 40px;border-radius:12px;text-decoration:none;
              transition:opacity .2s">
      🏪 Daftarkan Outlet — Gratis 7 Hari
    </a>
    <div style="margin-top:12px;font-size:12px;color:rgba(255,255,255,.4)">
      ⏱ Cuma butuh 3 menit. Tidak perlu kartu kredit.
    </div>
  </div>
</div>

<!-- ② PROFIL + CHECKLIST ────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px" class="no-outlet-grid">

  <!-- PROFIL AKUN -->
  <div style="background:#fff;border-radius:14px;padding:24px;box-shadow:0 1px 8px rgba(0,0,0,.06)">
    <h3 style="font-size:15px;font-weight:700;color:#0F1C3A;margin:0 0 18px;
               display:flex;align-items:center;gap:8px">
      <span style="background:#F0FDFB;border-radius:8px;padding:4px 8px">👤</span> Profil Akun
    </h3>
    <form method="POST">
      <input type="hidden" name="save_profile" value="1">
      <div style="margin-bottom:13px">
        <label style="font-size:12px;font-weight:600;color:#6B7280;display:block;margin-bottom:4px">Nama Outlet / Brand</label>
        <input type="text" name="nama_outlet" value="<?= htmlspecialchars($tenantNama) ?>"
               style="width:100%;padding:9px 12px;border:1.5px solid #E5E7EB;border-radius:8px;
                      font-size:14px;box-sizing:border-box;outline:none"
               onfocus="this.style.borderColor='#35E8D5'" onblur="this.style.borderColor='#E5E7EB'">
      </div>
      <div style="margin-bottom:13px">
        <label style="font-size:12px;font-weight:600;color:#6B7280;display:block;margin-bottom:4px">
          Email <span style="font-weight:400;color:#9CA3AF">(tidak bisa diubah)</span>
        </label>
        <input type="email" value="<?= htmlspecialchars($tenantEmail) ?>" disabled
               style="width:100%;padding:9px 12px;border:1.5px solid #E5E7EB;border-radius:8px;
                      font-size:14px;box-sizing:border-box;background:#F9FAFB;color:#9CA3AF">
      </div>
      <div style="margin-bottom:13px">
        <label style="font-size:12px;font-weight:600;color:#6B7280;display:block;margin-bottom:4px">Nomor WhatsApp</label>
        <input type="tel" name="owner_wa"
               value="<?= htmlspecialchars(preg_replace('/^628/', '08', $tenantWa)) ?>"
               placeholder="08xxxxxxxxxx"
               style="width:100%;padding:9px 12px;border:1.5px solid #E5E7EB;border-radius:8px;
                      font-size:14px;box-sizing:border-box;outline:none"
               onfocus="this.style.borderColor='#35E8D5'" onblur="this.style.borderColor='#E5E7EB'">
      </div>
      <div style="margin-bottom:16px">
        <label style="font-size:12px;font-weight:600;color:#6B7280;display:block;margin-bottom:4px">Kota</label>
        <input type="text" name="kota" value="<?= htmlspecialchars($tenantKota) ?>"
               placeholder="cth: Surabaya"
               style="width:100%;padding:9px 12px;border:1.5px solid #E5E7EB;border-radius:8px;
                      font-size:14px;box-sizing:border-box;outline:none"
               onfocus="this.style.borderColor='#35E8D5'" onblur="this.style.borderColor='#E5E7EB'">
      </div>
      <button type="submit"
              style="width:100%;background:#35E8D5;color:#0F1C3A;border:none;padding:10px;
                     border-radius:8px;font-weight:700;font-size:14px;cursor:pointer">
        💾 Simpan Profil
      </button>
    </form>

    <!-- Password change toggle -->
    <div style="margin-top:16px;border-top:1px solid #F3F4F6;padding-top:16px">
      <button onclick="document.getElementById('pwForm').style.display=
                document.getElementById('pwForm').style.display==='none'?'block':'none'"
              style="background:none;border:1.5px solid #E5E7EB;color:#374151;padding:8px 16px;
                     border-radius:8px;font-size:13px;cursor:pointer;width:100%">
        🔑 Ubah Password
      </button>
      <div id="pwForm" style="display:<?= $pwError ? 'block' : 'none' ?>;margin-top:12px">
        <?php if ($pwError): ?>
        <div style="background:#FEE2E2;color:#991B1B;padding:8px 12px;border-radius:6px;
                    font-size:13px;margin-bottom:10px"><?= htmlspecialchars($pwError) ?></div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="change_password" value="1">
          <input type="password" name="current_password" placeholder="Password lama"
                 style="width:100%;padding:8px 12px;border:1.5px solid #E5E7EB;border-radius:8px;
                        font-size:13px;box-sizing:border-box;margin-bottom:8px;outline:none">
          <input type="password" name="new_password" placeholder="Password baru (min 8 karakter)"
                 style="width:100%;padding:8px 12px;border:1.5px solid #E5E7EB;border-radius:8px;
                        font-size:13px;box-sizing:border-box;margin-bottom:8px;outline:none">
          <input type="password" name="confirm_password" placeholder="Ulangi password baru"
                 style="width:100%;padding:8px 12px;border:1.5px solid #E5E7EB;border-radius:8px;
                        font-size:13px;box-sizing:border-box;margin-bottom:10px;outline:none">
          <button type="submit"
                  style="width:100%;background:#0F1C3A;color:#fff;border:none;padding:9px;
                         border-radius:8px;font-weight:600;font-size:13px;cursor:pointer">
            Simpan Password Baru
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ONBOARDING CHECKLIST -->
  <div style="background:#fff;border-radius:14px;padding:24px;box-shadow:0 1px 8px rgba(0,0,0,.06)">
    <h3 style="font-size:15px;font-weight:700;color:#0F1C3A;margin:0 0 6px;
               display:flex;align-items:center;gap:8px">
      <span style="background:#F0FDFB;border-radius:8px;padding:4px 8px">✅</span> Setup Checklist
    </h3>
    <?php
    $steps = [
      ['done'=>true,        'locked'=>false, 'label'=>'Verifikasi email',           'link'=>null,                        'icon'=>'📧'],
      ['done'=>$profileDone,'locked'=>false, 'label'=>'Lengkapi profil perusahaan', 'link'=>null,                        'icon'=>'👤'],
      ['done'=>false,       'locked'=>false, 'label'=>'Daftarkan outlet pertama',   'link'=>'/ERP/harpy/add-outlet.php', 'icon'=>'🏪'],
      ['done'=>false,       'locked'=>true,  'label'=>'Setup layanan & harga',      'link'=>null,                        'icon'=>'🧺'],
      ['done'=>false,       'locked'=>true,  'label'=>'Tambah karyawan pertama',    'link'=>null,                        'icon'=>'👥'],
      ['done'=>false,       'locked'=>true,  'label'=>'Buat order pertama',         'link'=>null,                        'icon'=>'🛒'],
    ];
    $doneCnt = count(array_filter($steps, fn($s) => $s['done']));
    $total   = count($steps);
    $pct     = round($doneCnt / $total * 100);
    ?>
    <!-- Progress bar -->
    <div style="margin-bottom:16px">
      <div style="display:flex;justify-content:space-between;font-size:12px;color:#6B7280;margin-bottom:5px">
        <span><?= $doneCnt ?>/<?= $total ?> selesai</span>
        <span><?= $pct ?>%</span>
      </div>
      <div style="background:#F3F4F6;border-radius:100px;height:8px;overflow:hidden">
        <div style="background:linear-gradient(90deg,#35E8D5,#0891B2);height:100%;
                    width:<?= $pct ?>%;border-radius:100px;transition:width .4s"></div>
      </div>
    </div>
    <!-- Steps -->
    <?php foreach ($steps as $i => $s):
      $statusColor = $s['done'] ? '#065F46' : ($s['locked'] ? '#9CA3AF' : '#0F1C3A');
      $bg = $s['done'] ? '#F0FDF4' : ($s['locked'] ? '#F9FAFB' : '#fff');
      $border = $s['done'] ? '#6EE7B7' : '#E5E7EB';
      $check = $s['done'] ? '✅' : ($s['locked'] ? '🔒' : '⭕');
    ?>
    <div style="display:flex;align-items:center;gap:10px;padding:9px 10px;
                background:<?= $bg ?>;border:1px solid <?= $border ?>;
                border-radius:8px;margin-bottom:6px;font-size:13px">
      <span style="font-size:14px;flex-shrink:0"><?= $check ?></span>
      <span style="flex:1;color:<?= $statusColor ?>;<?= $s['locked'] ? 'opacity:.5' : '' ?>">
        <?= $s['icon'] ?> <?= $s['label'] ?>
        <?php if ($s['locked']): ?>
          <span style="font-size:11px;color:#9CA3AF;display:block">Tersedia setelah outlet didaftarkan</span>
        <?php endif; ?>
      </span>
      <?php if (!$s['done'] && !$s['locked'] && $s['link']): ?>
      <a href="<?= $s['link'] ?>"
         style="background:#35E8D5;color:#0F1C3A;font-size:11px;font-weight:700;
                padding:4px 10px;border-radius:6px;text-decoration:none;white-space:nowrap">
        <?= $i === 2 ? 'Mulai →' : 'Buka →' ?>
      </a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div><!-- /profil+checklist grid -->

<!-- ③ SCREENSHOT TOUR ──────────────────────────────── -->
<div style="background:#fff;border-radius:14px;padding:24px;box-shadow:0 1px 8px rgba(0,0,0,.06)">
  <h3 style="font-size:15px;font-weight:700;color:#0F1C3A;margin:0 0 4px">
    🖥️ Ini yang bisa kamu lakukan dengan LAMASY
  </h3>
  <p style="font-size:13px;color:#6B7280;margin:0 0 18px">Daftar outlet untuk akses penuh ke semua fitur</p>
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px" class="tour-grid">
    <?php
    $features = [
      ['icon'=>'🛒','title'=>'POS & Order',       'desc'=>'Input order, cetak nota, kelola status cucian'],
      ['icon'=>'📊','title'=>'Dashboard & Laporan','desc'=>'Pantau omset, saldo kas, dan kinerja harian'],
      ['icon'=>'🤖','title'=>'AI Briefing',        'desc'=>'Laporan performa outlet otomatis setiap hari'],
      ['icon'=>'💬','title'=>'WhatsApp Otomatis',  'desc'=>'Notif pelanggan saat cucian siap, otomatis'],
    ];
    foreach ($features as $f):
    ?>
    <div style="background:#F8FAFC;border:1.5px solid #E5E7EB;border-radius:10px;
                padding:16px 14px;text-align:center">
      <div style="font-size:28px;margin-bottom:8px"><?= $f['icon'] ?></div>
      <div style="font-size:13px;font-weight:700;color:#0F1C3A;margin-bottom:4px"><?= $f['title'] ?></div>
      <div style="font-size:11px;color:#6B7280;line-height:1.5"><?= $f['desc'] ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="text-align:center;margin-top:16px">
    <a href="/ERP/harpy/add-outlet.php"
       style="font-size:13px;color:#0891B2;text-decoration:none;font-weight:600">
      Daftar outlet untuk akses penuh →
    </a>
  </div>
</div>

<!-- ④ FAQ ──────────────────────────────────────────── -->
<div style="background:#fff;border-radius:14px;padding:24px;box-shadow:0 1px 8px rgba(0,0,0,.06)">
  <h3 style="font-size:15px;font-weight:700;color:#0F1C3A;margin:0 0 16px">❓ Pertanyaan Umum</h3>
  <?php
  $faqs = [
    ['q'=>'Apa bedanya "akun" dan "outlet" di LAMASY?',
     'a'=>'<strong>Akun</strong> = identitas perusahaan kamu (gratis selamanya, tidak kadaluarsa). <strong>Outlet</strong> = toko/cabang operasional yang punya trial 7 hari. 1 akun bisa punya banyak outlet.'],
    ['q'=>'Berapa biaya setelah trial 7 hari habis?',
     'a'=>'Setup fee Rp 300rb–500rb untuk aktivasi outlet. Setelah itu pakai sistem coin: topup mulai Rp 50rb, bayar per fitur yang dipakai saja.'],
    ['q'=>'Saya punya 3 cabang, harus bayar 3x?',
     'a'=>'Ya, setiap outlet bayar setup fee terpisah. Tapi 1 akun bisa kelola semua cabang dari 1 dashboard — hemat waktu dan lebih mudah dipantau.'],
    ['q'=>'Apakah data saya aman?',
     'a'=>'Data tersimpan di server aman. Setelah trial habis, data tetap ada 7 hari (grace period) + 30 hari recovery. Cukup waktu untuk aktivasi tanpa kehilangan data.'],
    ['q'=>'Butuh install aplikasi?',
     'a'=>'Tidak perlu. LAMASY berjalan di browser — buka di HP atau laptop. Tidak perlu install apapun, langsung pakai.'],
  ];
  foreach ($faqs as $i => $faq):
  ?>
  <div style="border-bottom:1px solid #F3F4F6;<?= $i===count($faqs)-1 ? 'border-bottom:none' : '' ?>">
    <button onclick="toggleFaqNo(<?= $i ?>)"
            style="width:100%;text-align:left;background:none;border:none;padding:13px 0;
                   font-size:14px;font-weight:600;color:#0F1C3A;cursor:pointer;
                   display:flex;justify-content:space-between;align-items:center;gap:8px">
      <span><?= htmlspecialchars($faq['q']) ?></span>
      <span id="faqArrowNo<?= $i ?>" style="font-size:12px;color:#9CA3AF;flex-shrink:0">▼</span>
    </button>
    <div id="faqAnsNo<?= $i ?>"
         style="max-height:0;overflow:hidden;transition:max-height .3s ease">
      <p style="font-size:13px;color:#4B5563;line-height:1.7;padding:0 0 14px;margin:0">
        <?= $faq['a'] ?>
      </p>
    </div>
  </div>
  <?php endforeach; ?>
  <div style="margin-top:16px;text-align:center;font-size:13px;color:#6B7280">
    Masih ada pertanyaan?
    <a href="https://wa.me/6281234567890?text=Halo%2C+saya+baru+daftar+LAMASY+dan+butuh+bantuan"
       style="color:#35E8D5;font-weight:700;text-decoration:none">Chat WhatsApp Kami →</a>
  </div>
</div>

</div><!-- /max-width wrapper -->
</div><!-- /no-outlet bg -->

<!-- FLOATING WA BUTTON -->
<a href="https://wa.me/6281234567890?text=Halo%2C+saya+baru+daftar+LAMASY+dan+butuh+bantuan"
   target="_blank" rel="noopener"
   style="position:fixed;bottom:24px;right:24px;background:#25D366;color:#fff;
          border-radius:100px;padding:12px 18px 12px 14px;font-size:14px;font-weight:700;
          text-decoration:none;box-shadow:0 4px 16px rgba(37,211,102,.4);
          display:flex;align-items:center;gap:8px;z-index:999;transition:transform .2s"
   onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
  <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
  </svg>
  Butuh bantuan?
</a>

<style>
@media(max-width:640px){
  .no-outlet-grid{grid-template-columns:1fr!important}
  .tour-grid{grid-template-columns:repeat(2,1fr)!important}
}
</style>
<script>
function toggleFaqNo(i){
  var a=document.getElementById('faqAnsNo'+i);
  var arr=document.getElementById('faqArrowNo'+i);
  var isOpen=a.style.maxHeight&&a.style.maxHeight!=='0px';
  a.style.maxHeight=isOpen?'0px':a.scrollHeight+'px';
  arr.textContent=isOpen?'▼':'▲';
}
// Mark tutorial step done via localStorage
if(localStorage.getItem('lamasy_tutorial_done')){
  // Could update UI here if needed
}
</script>

<?php else: ?>
<!-- ════════════════════════════════════════════════════
     NORMAL DASHBOARD (outlet exists)
════════════════════════════════════════════════════ -->
<div class="hl-main" style="max-width:1400px;width:100%">

<?php
// ── Status banners (trial / grace) ────────────────────
$banners = TenantResolver::getBannerInfo();
foreach ($banners as $b):
    $bg = $b['type'] === 'warning'
        ? 'linear-gradient(90deg,#FEF3C7,#FDE68A)'
        : 'linear-gradient(90deg,#DBEAFE,#BFDBFE)';
    $border = $b['type'] === 'warning' ? '#F59E0B' : '#3B82F6';
    $color  = $b['type'] === 'warning' ? '#92400E' : '#1E40AF';
?>
<div style="background:<?= $bg ?>;border-left:4px solid <?= $border ?>;
            color:<?= $color ?>;padding:10px 16px;border-radius:8px;
            font-size:13px;margin-bottom:14px;line-height:1.5">
    <?= $b['message'] ?>
</div>
<?php endforeach; ?>

<?php
// ══════════════════════════════════════════════════════
// Dashboard variant per role (brief Akses Karyawan Section 6.4)
// owner/manager/admin/superadmin → full dashboard (existing)
// kasir/staff/kurir → dashboard ringkas (task-focused)
// ══════════════════════════════════════════════════════
$_dashRole = $user['role'] ?? '';
$_isRingkas = in_array($_dashRole, ['kasir','staff','kurir'], true);

if ($_isRingkas):
    $oid = TenantResolver::outletId();
    $uname = htmlspecialchars($user['nama'] ?? 'Karyawan');
    $greetTime = (date('H') < 11 ? 'pagi' : (date('H') < 15 ? 'siang' : (date('H') < 19 ? 'sore' : 'malam')));
    $outletNm = htmlspecialchars(TenantResolver::namaOutlet());

    // Cek absensi hari ini
    $absStmt = TenantQuery::rawOne(
        "SELECT id, jam_masuk, jam_keluar, status FROM hl_absensi
          WHERE tenant_id=? AND outlet_id=? AND user_id=? AND tanggal=? LIMIT 1",
        [$tid, $oid, $user['id'], $today]
    );
    $clockedIn  = $absStmt && !empty($absStmt['jam_masuk']) && empty($absStmt['jam_keluar']);
    $clockedOut = $absStmt && !empty($absStmt['jam_keluar']);
?>

<!-- HERO RINGKAS -->
<div style="background:linear-gradient(135deg,#0F1C3A,#1a2d52);color:#fff;border-radius:14px;
            padding:22px 26px;margin-bottom:20px;display:flex;justify-content:space-between;
            align-items:center;flex-wrap:wrap;gap:14px">
  <div>
    <h2 style="font-size:1.2rem;font-weight:800;margin-bottom:3px">Selamat <?= $greetTime ?>, <?= $uname ?>!</h2>
    <div style="font-size:13px;color:rgba(255,255,255,.55)">
      📍 <?= $outletNm ?> · <?= date('d M Y') ?>
    </div>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <?php if ($clockedOut): ?>
      <div style="background:rgba(52,211,153,.15);border:1px solid rgba(52,211,153,.3);
                  color:#34D399;font-size:12px;font-weight:700;padding:6px 14px;border-radius:100px">
        ✓ Sudah Clock Out
      </div>
    <?php elseif ($clockedIn): ?>
      <div style="background:rgba(52,211,153,.15);border:1px solid rgba(52,211,153,.3);
                  color:#34D399;font-size:12px;font-weight:700;padding:6px 14px;border-radius:100px">
        🟢 Clocked In · <?= substr($absStmt['jam_masuk'], 0, 5) ?>
      </div>
      <a href="absensi.php" class="hl-btn hl-btn-outline hl-btn-sm" style="color:#fff;border-color:rgba(255,255,255,.3)">Clock Out</a>
    <?php else: ?>
      <a href="absensi.php" class="hl-btn"
         style="background:#35E8D5;color:#0F1C3A;font-weight:700;padding:8px 18px;
                border-radius:8px;text-decoration:none;font-size:13px">
        🕐 Clock In Sekarang
      </a>
    <?php endif; ?>
  </div>
</div>

<?php // ── DASHBOARD KASIR ──────────────────────────────
if ($_dashRole === 'kasir'):
    // Stats kasir: transaksi yang dia proses hari ini
    $kasirStats = TenantQuery::rawOne(
        "SELECT COUNT(*) AS total, COALESCE(SUM(total),0) AS omset
           FROM hl_transaksi
          WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal)=? AND created_by=?",
        [$tid, $oid, $today, $user['id']]
    ) ?: ['total'=>0,'omset'=>0];

    // Order masuk hari ini (semua kasir di outlet ini)
    $orderMasuk = (int)(TenantQuery::rawOne(
        "SELECT COUNT(*) AS c FROM hl_transaksi
          WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal)=?",
        [$tid, $oid, $today]
    )['c'] ?? 0);

    // Order siap diambil
    $orderSiap = (int)(TenantQuery::rawOne(
        "SELECT COUNT(*) AS c FROM hl_transaksi
          WHERE tenant_id=? AND outlet_id=? AND status_proses='siap'",
        [$tid, $oid]
    )['c'] ?? 0);
?>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px" class="rk-grid3">
  <div style="background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 6px rgba(0,0,0,.05);border-top:3px solid #35E8D5">
    <div style="font-size:1.4rem;font-weight:800;color:#0F1C3A;font-family:monospace"><?= (int)$kasirStats['total'] ?></div>
    <div style="font-size:12px;color:#6B7280;font-weight:600">Transaksi Saya Hari Ini</div>
    <div style="font-size:11px;color:#9CA3AF;margin-top:3px">Rp <?= number_format((int)$kasirStats['omset'], 0, ',', '.') ?></div>
  </div>
  <div style="background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 6px rgba(0,0,0,.05);border-top:3px solid #3B82F6">
    <div style="font-size:1.4rem;font-weight:800;color:#0F1C3A;font-family:monospace"><?= $orderMasuk ?></div>
    <div style="font-size:12px;color:#6B7280;font-weight:600">Order Masuk Hari Ini</div>
    <div style="font-size:11px;color:#9CA3AF;margin-top:3px">Semua kasir outlet</div>
  </div>
  <div style="background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 6px rgba(0,0,0,.05);border-top:3px solid #F59E0B">
    <div style="font-size:1.4rem;font-weight:800;color:#0F1C3A;font-family:monospace"><?= $orderSiap ?></div>
    <div style="font-size:12px;color:#6B7280;font-weight:600">Siap Diambil</div>
    <div style="font-size:11px;color:#9CA3AF;margin-top:3px">Perlu notif pelanggan</div>
  </div>
</div>

<!-- Quick actions kasir -->
<div style="background:#fff;border-radius:12px;padding:22px;box-shadow:0 1px 6px rgba(0,0,0,.05);margin-bottom:20px">
  <h3 style="font-size:14px;font-weight:700;color:#0F1C3A;margin-bottom:14px">⚡ Aksi Cepat</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">
    <a href="pos.php" style="background:#35E8D5;color:#0F1C3A;font-weight:800;font-size:15px;
       padding:18px;border-radius:10px;text-decoration:none;text-align:center">
      🛒 Buat Order Baru
    </a>
    <a href="orders.php?status=aktif" style="background:#0F1C3A;color:#fff;font-weight:700;font-size:14px;
       padding:18px;border-radius:10px;text-decoration:none;text-align:center">
      📋 Lihat Antrian Order
    </a>
    <a href="orders.php?status=siap" style="background:#F59E0B;color:#fff;font-weight:700;font-size:14px;
       padding:18px;border-radius:10px;text-decoration:none;text-align:center">
      ✅ Order Siap Diambil
    </a>
    <a href="customer.php" style="background:rgba(53,232,213,.1);color:#0F1C3A;font-weight:700;font-size:14px;
       padding:18px;border-radius:10px;text-decoration:none;text-align:center;border:1.5px solid rgba(53,232,213,.3)">
      👥 Cari Pelanggan
    </a>
  </div>
</div>

<?php elseif ($_dashRole === 'staff'):
    // ── DASHBOARD STAFF (produksi) ────────────────────
    $perluKerja = (int)(TenantQuery::rawOne(
        "SELECT COUNT(*) AS c FROM hl_transaksi
          WHERE tenant_id=? AND outlet_id=? AND status_proses IN ('cuci','kering','setrika')",
        [$tid, $oid]
    )['c'] ?? 0);
    $selesaiHariIni = (int)(TenantQuery::rawOne(
        "SELECT COUNT(*) AS c FROM hl_transaksi
          WHERE tenant_id=? AND outlet_id=? AND status_proses='siap' AND DATE(tanggal)=?",
        [$tid, $oid, $today]
    )['c'] ?? 0);
?>
<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:20px" class="rk-grid2">
  <div style="background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 6px rgba(0,0,0,.05);border-top:3px solid #F59E0B">
    <div style="font-size:1.5rem;font-weight:800;color:#0F1C3A;font-family:monospace"><?= $perluKerja ?></div>
    <div style="font-size:12px;color:#6B7280;font-weight:600">Perlu Dikerjakan</div>
    <div style="font-size:11px;color:#9CA3AF;margin-top:3px">Status: cuci / kering / setrika</div>
  </div>
  <div style="background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 6px rgba(0,0,0,.05);border-top:3px solid #34D399">
    <div style="font-size:1.5rem;font-weight:800;color:#0F1C3A;font-family:monospace"><?= $selesaiHariIni ?></div>
    <div style="font-size:12px;color:#6B7280;font-weight:600">Selesai Hari Ini</div>
    <div style="font-size:11px;color:#9CA3AF;margin-top:3px">Status: siap</div>
  </div>
</div>

<div style="background:#fff;border-radius:12px;padding:22px;box-shadow:0 1px 6px rgba(0,0,0,.05);margin-bottom:20px">
  <h3 style="font-size:14px;font-weight:700;color:#0F1C3A;margin-bottom:14px">📋 Order Yang Perlu Dikerjakan</h3>
  <?php
  $orderList = TenantQuery::raw(
    "SELECT id, no_order, nama_pelanggan, status_proses, tanggal, estimasi_selesai
       FROM hl_transaksi
      WHERE tenant_id=? AND outlet_id=? AND status_proses IN ('cuci','kering','setrika')
      ORDER BY estimasi_selesai ASC, tanggal ASC LIMIT 10",
    [$tid, $oid]
  );
  ?>
  <?php if (empty($orderList)): ?>
  <div style="text-align:center;padding:30px;color:#9CA3AF;font-size:13px">Tidak ada order yang perlu dikerjakan saat ini ✓</div>
  <?php else: foreach ($orderList as $o): ?>
  <div style="display:flex;justify-content:space-between;align-items:center;padding:11px 0;
              border-bottom:1px solid #F3F4F6;font-size:13px">
    <div>
      <div style="font-weight:700;color:#0F1C3A"><?= htmlspecialchars($o['no_order']) ?>
        — <?= htmlspecialchars($o['nama_pelanggan'] ?? '-') ?></div>
      <div style="font-size:11px;color:#9CA3AF">Estimasi: <?= $o['estimasi_selesai'] ? date('d M H:i', strtotime($o['estimasi_selesai'])) : '-' ?></div>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <span style="background:#FEF3C7;color:#92400E;font-size:10px;font-weight:700;padding:3px 10px;border-radius:100px;text-transform:uppercase">
        <?= $o['status_proses'] ?>
      </span>
      <a href="orders.php?id=<?= $o['id'] ?>" style="color:#0891B2;text-decoration:none;font-size:12px;font-weight:700">Update →</a>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<?php elseif ($_dashRole === 'kurir'):
    // ── DASHBOARD KURIR (delivery) ────────────────────
    $siapAntar = (int)(TenantQuery::rawOne(
        "SELECT COUNT(*) AS c FROM hl_transaksi
          WHERE tenant_id=? AND outlet_id=? AND status_proses='siap'",
        [$tid, $oid]
    )['c'] ?? 0);
    $sudahAntar = (int)(TenantQuery::rawOne(
        "SELECT COUNT(*) AS c FROM hl_transaksi
          WHERE tenant_id=? AND outlet_id=? AND status_proses='selesai' AND DATE(tanggal)=?",
        [$tid, $oid, $today]
    )['c'] ?? 0);
?>
<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:20px" class="rk-grid2">
  <div style="background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 6px rgba(0,0,0,.05);border-top:3px solid #F59E0B">
    <div style="font-size:1.5rem;font-weight:800;color:#0F1C3A;font-family:monospace"><?= $siapAntar ?></div>
    <div style="font-size:12px;color:#6B7280;font-weight:600">Siap Antar Hari Ini</div>
  </div>
  <div style="background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 6px rgba(0,0,0,.05);border-top:3px solid #34D399">
    <div style="font-size:1.5rem;font-weight:800;color:#0F1C3A;font-family:monospace"><?= $sudahAntar ?></div>
    <div style="font-size:12px;color:#6B7280;font-weight:600">Sudah Diantar Hari Ini</div>
  </div>
</div>

<div style="background:#fff;border-radius:12px;padding:22px;box-shadow:0 1px 6px rgba(0,0,0,.05);margin-bottom:20px">
  <h3 style="font-size:14px;font-weight:700;color:#0F1C3A;margin-bottom:14px">🚚 Daftar Antar Hari Ini</h3>
  <?php
  $antarList = TenantQuery::raw(
    "SELECT t.id, t.no_order, t.nama_pelanggan, t.telepon, t.status_proses,
            p.alamat AS alamat_pelanggan
       FROM hl_transaksi t
       LEFT JOIN hl_pelanggan p ON p.id = t.pelanggan_id
      WHERE t.tenant_id=? AND t.outlet_id=? AND t.status_proses='siap'
      ORDER BY t.tanggal ASC LIMIT 10",
    [$tid, $oid]
  );
  ?>
  <?php if (empty($antarList)): ?>
  <div style="text-align:center;padding:30px;color:#9CA3AF;font-size:13px">Belum ada order yang perlu diantar ✓</div>
  <?php else: foreach ($antarList as $o): ?>
  <div style="padding:13px 0;border-bottom:1px solid #F3F4F6;font-size:13px">
    <div style="display:flex;justify-content:space-between;align-items:start;gap:10px;margin-bottom:5px">
      <div style="font-weight:700;color:#0F1C3A">
        <?= htmlspecialchars($o['no_order']) ?> — <?= htmlspecialchars($o['nama_pelanggan'] ?? '-') ?>
      </div>
      <a href="orders.php?id=<?= $o['id'] ?>" style="color:#0891B2;text-decoration:none;font-size:12px;font-weight:700">Update Status →</a>
    </div>
    <?php if (!empty($o['alamat_pelanggan'])): ?>
    <div style="font-size:12px;color:#6B7280">📍 <?= htmlspecialchars($o['alamat_pelanggan']) ?></div>
    <?php endif; ?>
    <?php if (!empty($o['telepon'])): ?>
    <div style="font-size:12px;color:#6B7280">📞
      <a href="tel:<?= htmlspecialchars($o['telepon']) ?>" style="color:#0891B2;text-decoration:none"><?= htmlspecialchars($o['telepon']) ?></a>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; endif; ?>
</div>

<?php endif; // role-specific ringkas ?>

<style>
@media(max-width:640px){
  .rk-grid3{grid-template-columns:1fr!important}
  .rk-grid2{grid-template-columns:1fr!important}
}
</style>

</div><!-- /hl-main untuk ringkas -->
<?php renderToast(); ?>
</body>
</html>
<?php exit; endif; // _isRingkas — full dashboard di bawah hanya untuk owner/manager ?>

  <!-- ══ FULL DASHBOARD (owner/manager/admin/superadmin) ══ -->

  <!-- GREETING -->
  <div style="margin-bottom:20px;display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap">
    <div>
      <h1 style="font-size:1.3rem;font-weight:800;color:var(--navy)" id="greeting">Selamat pagi!</h1>
      <p style="font-size:13px;color:var(--gray)" id="dashDate">--</p>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <?php if ($user['role'] !== 'staff'): ?>
      <div id="aiBriefingBadge"
           style="background:linear-gradient(135deg,#667eea,#764ba2);color:white;font-size:12px;font-weight:600;padding:6px 14px;border-radius:100px;cursor:pointer"
           onclick="toggleBriefing()">✨ AI Briefing</div>
      <?php endif; ?>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadAll()">↻ Refresh</button>
    </div>
  </div>

  <!-- HANDOVER PENDING BANNER -->
  <div id="hoBanner" style="display:none;margin-bottom:14px"></div>

  <!-- QUICK SEARCH BAR -->
  <div style="position:relative;margin-bottom:20px">
    <input type="text" id="qSearch" placeholder="🔍 Cari cepat: nomor HP, nama pelanggan, atau no. order…"
           autocomplete="off"
           style="width:100%;padding:13px 16px;font-size:14px;border:1.5px solid #E5E9F2;border-radius:12px;
                  background:#fff;font-family:inherit;outline:none;transition:border .15s"
           onfocus="this.style.borderColor='#35E8D5'"
           onblur="this.style.borderColor='#E5E9F2'">
    <div id="qSearchRes" style="position:absolute;top:calc(100% + 4px);left:0;right:0;background:#fff;border:1px solid #E5E9F2;
                                border-radius:10px;box-shadow:0 8px 24px rgba(15,28,58,.15);max-height:420px;overflow-y:auto;
                                z-index:50;display:none"></div>
  </div>

  <!-- AI BRIEFING PANEL -->
  <div id="aiBriefingPanel" style="display:none;margin-bottom:20px">
    <div class="hl-card" style="border:2px solid rgba(139,92,246,.2);background:linear-gradient(135deg,#FAFAFA,#F5F3FF)">
      <div class="hl-card-header" style="border-bottom:1px solid rgba(139,92,246,.1)">
        <div class="hl-card-title" style="display:flex;align-items:center;gap:8px">
          <span style="background:linear-gradient(135deg,#667eea,#764ba2);color:white;font-size:10px;font-weight:800;padding:3px 8px;border-radius:100px;letter-spacing:.06em">AI</span>
          Briefing Harian
        </div>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadBriefing()" id="btnBriefingRefresh">↻</button>
      </div>
      <div class="hl-card-body" id="aiBriefingContent">
        <div class="hl-loading">⏳ AI sedang menganalisis data...</div>
      </div>
    </div>
  </div>

  <!-- TARGET OMSET -->
  <div id="targetWrap" style="display:none;margin-bottom:14px">
    <div style="background:#fff;border:1px solid rgba(27,45,90,.07);border-radius:var(--r-lg);padding:14px 16px;box-shadow:var(--shadow)">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px" id="targetGrid">
        <div id="targetHarianBox">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
            <span style="font-size:11px;font-weight:800;color:#6B7280;letter-spacing:.06em">🎯 TARGET HARI INI</span>
            <span id="targetHarianPct" style="font-size:13px;font-weight:800;color:#0F1C3A">0%</span>
          </div>
          <div style="font-size:13px;font-weight:700;color:#0F1C3A;margin-bottom:5px" id="targetHarianText">-</div>
          <div style="background:#EEF1F8;border-radius:100px;height:8px;overflow:hidden">
            <div id="targetHarianBar" style="height:100%;background:linear-gradient(90deg,#35E8D5,#10B981);width:0%;transition:width .4s"></div>
          </div>
          <div id="targetHarianSub" style="font-size:11px;color:#6B7280;margin-top:4px">-</div>
        </div>
        <div id="targetBulananBox">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
            <span style="font-size:11px;font-weight:800;color:#6B7280;letter-spacing:.06em">🎯 TARGET BULAN INI</span>
            <span id="targetBulananPct" style="font-size:13px;font-weight:800;color:#0F1C3A">0%</span>
          </div>
          <div style="font-size:13px;font-weight:700;color:#0F1C3A;margin-bottom:5px" id="targetBulananText">-</div>
          <div style="background:#EEF1F8;border-radius:100px;height:8px;overflow:hidden">
            <div id="targetBulananBar" style="height:100%;background:linear-gradient(90deg,#3B82F6,#8B5CF6);width:0%;transition:width .4s"></div>
          </div>
          <div id="targetBulananSub" style="font-size:11px;color:#6B7280;margin-top:4px">-</div>
        </div>
      </div>
    </div>
  </div>

  <!-- STAT CARDS -->
  <div class="dash-grid">
    <div class="dash-card teal">
      <div class="dash-num" id="dOmset">-</div>
      <div class="dash-label">Omset Hari Ini</div>
      <div class="dash-sub" id="dTerkumpul">Terkumpul: -</div>
    </div>
    <div class="dash-card green">
      <div class="dash-num" id="dOrder">-</div>
      <div class="dash-label">Order Masuk Hari Ini</div>
      <div class="dash-sub" id="dAktif">Aktif: - order</div>
    </div>
    <div class="dash-card navy" id="dashKasCard">
      <div class="dash-num" id="dSaldo">-</div>
      <div class="dash-label">Saldo Kas Hari Ini</div>
      <div class="dash-sub" id="dKasSub">Masuk: - / Keluar: -</div>
    </div>
    <div class="dash-card purple">
      <div class="dash-num" id="dHadir">-</div>
      <div class="dash-label">Karyawan Hadir</div>
      <div class="dash-sub" id="dSiap">Siap diambil: - order</div>
    </div>
  </div>

  <!-- EXTRAS: SEGMEN + TOP PELANGGAN + WOW -->
  <div id="extrasWrap" style="display:none;margin-bottom:20px">
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px" id="extrasGrid">
      <div class="hl-card" style="padding:14px 16px">
        <div style="font-size:12px;font-weight:700;color:#6B7280;letter-spacing:.05em;margin-bottom:8px">🧷 OMSET PER SEGMEN HARI INI</div>
        <div id="segmenBox" style="font-size:13px"></div>
      </div>
      <div class="hl-card" style="padding:14px 16px">
        <div style="font-size:12px;font-weight:700;color:#6B7280;letter-spacing:.05em;margin-bottom:8px">🏆 TOP 5 PELANGGAN BULAN INI</div>
        <div id="topCustBox" style="font-size:13px"></div>
      </div>
      <div class="hl-card" style="padding:14px 16px">
        <div style="font-size:12px;font-weight:700;color:#6B7280;letter-spacing:.05em;margin-bottom:8px">📊 MINGGU INI vs MINGGU LALU</div>
        <div id="wowBox" style="font-size:13px"></div>
      </div>
    </div>
  </div>

  <!-- PIPELINE -->
  <div class="hl-card" style="margin-bottom:20px">
    <div class="hl-card-header">
      <div class="hl-card-title">Pipeline Order Aktif</div>
      <span id="pipeTotal" style="font-size:12px;color:var(--gray)"></span>
    </div>
    <div class="hl-card-body" style="padding:14px">
      <div class="pipeline" id="pipeline"><div class="hl-loading">⏳</div></div>
    </div>
  </div>

  <div class="hl-grid-2" style="gap:20px">
    <div>
      <!-- SIAP DIAMBIL -->
      <div class="hl-card" style="margin-bottom:18px">
        <div class="hl-card-header">
          <div class="alert-title">Siap Diambil
            <span class="alert-badge" id="badgeSiap" style="background:#D1FAE5;color:#065F46">0</span>
          </div>
          <a href="orders.php?status=siap" style="font-size:12px;color:var(--teal);text-decoration:none">Lihat semua</a>
        </div>
        <div class="hl-card-body" style="padding:12px" id="listSiap"><div class="hl-loading">⏳</div></div>
      </div>

      <!-- MEPET ESTIMASI -->
      <div class="hl-card">
        <div class="hl-card-header">
          <div class="alert-title">Harus Selesai Hari Ini / Besok
            <span class="alert-badge" id="badgeMepet" style="background:#FEF3C7;color:#92400E">0</span>
          </div>
        </div>
        <div class="hl-card-body" style="padding:12px" id="listMepet"><div class="hl-loading">⏳</div></div>
      </div>
    </div>

    <div>
      <!-- CHART 7 HARI -->
      <div class="hl-card" style="margin-bottom:18px">
        <div class="hl-card-header">
          <div class="hl-card-title">Omset 7 Hari Terakhir</div>
        </div>
        <div class="hl-card-body">
          <div class="chart-wrap"><canvas id="chartOmset"></canvas></div>
        </div>
      </div>

      <!-- PIUTANG -->
      <div class="hl-card">
        <div class="hl-card-header">
          <div class="alert-title">Belum Bayar (&gt; 3 Hari)
            <span class="alert-badge" id="badgePiutang" style="background:#FEE2E2;color:#991B1B">0</span>
          </div>
          <a href="orders.php?bayar=belum" style="font-size:12px;color:var(--teal);text-decoration:none">Lihat semua</a>
        </div>
        <div class="hl-card-body" style="padding:12px" id="listPiutang"><div class="hl-loading">⏳</div></div>
      </div>
    </div>

    <!-- MITRA DROP POINT INAKTIF -->
    <div id="mitraInaktifWrap" style="display:none;margin-top:16px">
      <div class="hl-card" style="border-left:4px solid #F59E0B">
        <div class="hl-card-header">
          <div class="alert-title">📦 Mitra Drop Point Tidak Aktif &gt;7 Hari</div>
          <a href="droppoint_manager.php" style="font-size:12px;color:var(--teal);text-decoration:none">Kelola mitra</a>
        </div>
        <div class="hl-card-body" style="padding:12px" id="mitraInaktifList"></div>
      </div>
    </div>

</div><!-- /hl-main -->

<?php endif; // hasOutlet ?>

<?php renderToast(); ?>
<script>
let chartInstance = null;

function localDateStr(d){const dt=d||new Date();return dt.getFullYear()+'-'+String(dt.getMonth()+1).padStart(2,'0')+'-'+String(dt.getDate()).padStart(2,'0');}

document.addEventListener('DOMContentLoaded',()=>{
  const now=new Date(), h=now.getHours();
  const greet=h<11?'Selamat pagi':h<15?'Selamat siang':h<18?'Selamat sore':'Selamat malam';
  document.getElementById('greeting').textContent=greet+', <?= htmlspecialchars($user['nama']) ?>!';
  document.getElementById('dashDate').textContent=now.toLocaleDateString('id-ID',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
  loadAll();
});

async function loadAll(){loadStats();loadAlerts();loadPipeline();loadChart();loadExtras();loadHandoverBanner();}

// ── HANDOVER BANNER (unacknowledged shifts) ──
async function loadHandoverBanner(){
  try {
    const r = await fetch('absensi.php?action=handover_pending');
    const d = await r.json();
    const box = document.getElementById('hoBanner');
    if (!box) return;
    if (!d.rows || !d.rows.length) { box.style.display='none'; return; }
    box.style.display = 'block';
    box.innerHTML = d.rows.map(h => `
      <div style="background:linear-gradient(90deg,#FEF3C7,#FFE4B5);border-left:4px solid #F59E0B;padding:10px 14px;border-radius:10px;display:flex;align-items:center;gap:10px;font-size:13px;margin-bottom:6px">
        <span style="font-size:18px">🤝</span>
        <div style="flex:1">
          <strong>Handover dari ${(h.nama_keluar||'-')}</strong>
          (${h.tanggal} · ${h.shift}) — Kas Rp ${parseInt(h.saldo_kas_akhir).toLocaleString('id-ID')},
          ${h.order_pending} pending, ${h.order_siap_ambil} siap.
          ${h.catatan_khusus ? `<div style="color:#92400E;font-size:12px;margin-top:2px"><em>“${h.catatan_khusus}”</em></div>` : ''}
        </div>
        <a href="absensi.php" style="background:#F59E0B;color:#fff;padding:6px 12px;border-radius:8px;font-weight:600;text-decoration:none;font-size:12px">Buka Absensi →</a>
      </div>`).join('');
  } catch (e) {}
}

// ── QUICK SEARCH ──
const STATUS_PILL_BG = {
  masuk:'#DBEAFE/#1E40AF', cuci:'#FEF3C7/#92400E', kering:'#CFFAFE/#155E75',
  setrika:'#EDE9FE/#5B21B6', siap:'#D1FAE5/#065F46', diambil:'#F3F4F6/#6B7280'
};
function qsEsc(s){return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function qsTimerLabel(estimasiSelesai, statusProses){
  if (statusProses === 'diambil' || statusProses === 'selesai') return '✓ Sudah diambil';
  if (!estimasiSelesai) return '-';
  const t = new Date(estimasiSelesai.replace(' ','T')).getTime();
  const diffMs = t - Date.now();
  if (diffMs < 0) {
    const lateH = Math.abs(diffMs)/3600000;
    return '⚠️ TERLAMBAT ' + (lateH<1 ? Math.round(lateH*60)+'m' : lateH.toFixed(1).replace('.0','')+'j');
  }
  const h = diffMs/3600000;
  return (h<1 ? Math.round(h*60)+'m' : h.toFixed(1).replace('.0','')+'j') + ' lagi';
}
let qsTimer = null;
const qsInput = document.getElementById('qSearch');
const qsRes   = document.getElementById('qSearchRes');
if (qsInput) {
  qsInput.addEventListener('input', () => {
    clearTimeout(qsTimer);
    const q = qsInput.value.trim();
    if (q.length < 3) { qsRes.style.display='none'; qsRes.innerHTML=''; return; }
    qsTimer = setTimeout(async () => {
      try {
        const r = await fetch('dashboard.php?action=quick_search&q=' + encodeURIComponent(q));
        const d = await r.json();
        if (d.error || !d.rows){ qsRes.style.display='none'; return; }
        if (!d.rows.length){
          qsRes.innerHTML = '<div style="padding:14px;color:#9CA3AF;font-size:13px;text-align:center">Tidak ada order yang cocok.</div>';
        } else {
          qsRes.innerHTML = d.rows.map(r => {
            const [bg,fg] = (STATUS_PILL_BG[r.status_proses] || '#F3F4F6/#6B7280').split('/');
            const timer = qsTimerLabel(r.estimasi_selesai, r.status_proses);
            const timerColor = timer.includes('TERLAMBAT') ? '#EF4444' : (r.status_proses==='diambil' ? '#10B981' : '#374151');
            return `<a href="orders.php?q=${encodeURIComponent(r.no_order)}" style="display:block;padding:11px 14px;border-bottom:1px solid #F3F4F6;text-decoration:none;color:inherit">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
                <div style="min-width:0;flex:1">
                  <div style="font-weight:700;color:#0F1C3A;font-size:13px">${qsEsc(r.nama_pelanggan)} <small style="color:#9CA3AF;font-weight:400">· ${qsEsc(r.no_order)}</small></div>
                  <div style="font-size:11px;color:#6B7280;margin-top:2px">${qsEsc(r.telepon||'-')} · Rp ${Number(r.total).toLocaleString('id-ID')}</div>
                </div>
                <div style="text-align:right;white-space:nowrap">
                  <span style="background:${bg};color:${fg};font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px;text-transform:uppercase">${qsEsc(r.status_proses)}</span>
                  <div style="font-size:11px;font-weight:600;color:${timerColor};margin-top:3px">⏱ ${timer}</div>
                </div>
              </div>
            </a>`;
          }).join('');
        }
        qsRes.style.display='block';
      } catch(e){ qsRes.style.display='none'; }
    }, 300);
  });
  document.addEventListener('click', e => {
    if (!e.target.closest('#qSearch') && !e.target.closest('#qSearchRes')) qsRes.style.display='none';
  });
}

const SEG_LBL = {kiloan:'🧺 Kiloan',self_service:'🪙 Self-Service',b2b:'🏢 B2B',satuan:'👕 Satuan',drop_point:'📦 Drop Point',lainnya:'📦 Lainnya'};
async function loadExtras(){
  try {
    const r = await fetch('dashboard.php?action=extras');
    const d = await r.json();
    if (d.error) return;
    document.getElementById('extrasWrap').style.display = 'block';
    const fmt = n => 'Rp '+Number(n||0).toLocaleString('id-ID');

    // Segmen
    const totSeg = (d.segmen||[]).reduce((s,r)=>s+Number(r.total),0) || 1;
    document.getElementById('segmenBox').innerHTML = (d.segmen||[]).length
      ? d.segmen.map(s => {
          const pct = Math.round(Number(s.total)/totSeg*100);
          return `<div style="margin-bottom:7px">
            <div style="display:flex;justify-content:space-between;font-size:12px">
              <span>${SEG_LBL[s.seg]||s.seg}</span>
              <span style="font-family:monospace;font-weight:700">${fmt(s.total)} <small style="color:#9CA3AF">${pct}%</small></span>
            </div>
            <div style="background:#EEF1F8;border-radius:100px;height:5px;margin-top:2px"><div style="background:#35E8D5;height:100%;width:${pct}%;border-radius:100px"></div></div>
          </div>`;
        }).join('')
      : '<div style="color:#9CA3AF">Belum ada transaksi hari ini</div>';

    // Top Pelanggan
    document.getElementById('topCustBox').innerHTML = (d.top_pelanggan||[]).length
      ? d.top_pelanggan.map((p,i) => {
          const medal = i===0?'🥇':i===1?'🥈':i===2?'🥉':`#${i+1}`;
          return `<div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;border-bottom:1px solid #F3F4F6">
            <div style="min-width:0;flex:1">
              <div style="font-weight:700;color:#0F1C3A;font-size:12px">${medal} ${p.nama||'-'}</div>
              <div style="font-size:10px;color:#9CA3AF">${p.ord||0} order</div>
            </div>
            <div style="font-family:monospace;font-weight:700;font-size:12px">${fmt(p.spend)}</div>
          </div>`;
        }).join('')
      : '<div style="color:#9CA3AF">Belum ada pelanggan terdaftar</div>';

    // WoW
    const w = d.wow || {};
    const oPct = w.last_omset > 0 ? Math.round((w.this_omset - w.last_omset)/w.last_omset*100) : (w.this_omset>0?100:0);
    const orPct= w.last_order > 0 ? Math.round((w.this_order - w.last_order)/w.last_order*100) : (w.this_order>0?100:0);
    const arrow = v => v>0?`<span style="color:#10B981">↑ +${v}%</span>` : (v<0?`<span style="color:#EF4444">↓ ${v}%</span>`:`<span style="color:#9CA3AF">→ 0%</span>`);
    document.getElementById('wowBox').innerHTML = `
      <div style="margin-bottom:8px">
        <div style="font-size:11px;color:#6B7280">Omset minggu ini</div>
        <div style="font-family:monospace;font-weight:800;color:#0F1C3A">${fmt(w.this_omset)}</div>
        <div style="font-size:11px">${arrow(oPct)} vs ${fmt(w.last_omset)} mgg lalu</div>
      </div>
      <div>
        <div style="font-size:11px;color:#6B7280">Order minggu ini</div>
        <div style="font-family:monospace;font-weight:800;color:#0F1C3A">${w.this_order} order</div>
        <div style="font-size:11px">${arrow(orPct)} vs ${w.last_order} mgg lalu</div>
      </div>`;
  } catch(e){}
}

// ── AI BRIEFING ───────────────────────────────────────
let briefingLoaded=false, briefingVisible=false;
function toggleBriefing(){
  briefingVisible=!briefingVisible;
  document.getElementById('aiBriefingPanel').style.display=briefingVisible?'block':'none';
  if(briefingVisible&&!briefingLoaded)loadBriefing();
}
async function loadBriefing(){
  const btn=document.getElementById('btnBriefingRefresh');
  if(btn){btn.disabled=true;btn.textContent='⏳';}
  document.getElementById('aiBriefingContent').innerHTML='<div class="hl-loading">⏳ AI sedang menganalisis data hari ini...</div>';
  try{
    const r=await fetch('ai.php?action=briefing');
    const txt=await r.text();
    let d;
    try{d=JSON.parse(txt);}
    catch(parseErr){
      document.getElementById('aiBriefingContent').innerHTML=
        `<div style="background:#FEF3C7;border-left:4px solid #F59E0B;padding:12px 14px;border-radius:8px;font-size:13px;color:#92400E">
          <div style="font-weight:700;margin-bottom:6px">⚠️ AI Briefing gagal merespons</div>
          <div>Server mengembalikan format tidak valid (HTTP ${r.status}). Cek error_log atau coba lagi nanti.</div>
        </div>`;
      return;
    }
    if(d.error){document.getElementById('aiBriefingContent').innerHTML=`<div style="color:var(--red);font-size:13px">❌ ${d.error}</div>`;return;}
    const data=d.data;
    const cmap={baik:'var(--green)',waspada:'var(--yellow)',kritis:'var(--red)'};
    const imap={baik:'✅',waspada:'⚠️',kritis:'🚨'};
    document.getElementById('aiBriefingContent').innerHTML=`
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
        <span style="font-size:1.4rem">${imap[data.kondisi]||'📊'}</span>
        <div>
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:${cmap[data.kondisi]||'var(--gray)'}">${data.kondisi?.toUpperCase()}</div>
          <div style="font-size:14px;color:var(--dark);font-weight:500">${esc(data.ringkasan)}</div>
        </div>
      </div>
      ${data.poin_penting?.length?`<div style="margin-bottom:14px">${data.poin_penting.map(p=>`<div style="display:flex;gap:8px;align-items:flex-start;padding:7px 0;border-bottom:1px solid rgba(27,45,90,.06)"><span style="color:var(--teal);font-weight:700;flex-shrink:0">→</span><span style="font-size:13px;color:var(--dark)">${esc(p)}</span></div>`).join('')}</div>`:''}
      ${data.peluang?`<div style="background:linear-gradient(135deg,#D1FAE5,#A7F3D0);border-radius:var(--r);padding:10px 14px;font-size:13px;color:#065F46">💡 <strong>Peluang:</strong> ${esc(data.peluang)}</div>`:''}
      <div style="font-size:11px;color:var(--gray);text-align:right;margin-top:10px">AI · ${new Date().toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'})}</div>`;
    briefingLoaded=true;
  }catch(e){document.getElementById('aiBriefingContent').innerHTML=`<div style="color:var(--red);font-size:13px">❌ ${e.message}</div>`;}
  finally{if(btn){btn.disabled=false;btn.textContent='↻';}}
}

// ── STATS ─────────────────────────────────────────────
async function loadStats(){
  const r=await fetch('dashboard.php?action=stats');
  const d=await r.json();
  const isStaff=d.is_staff;
  document.getElementById('dOmset').textContent='Rp '+parseFloat(d.order?.omset||0).toLocaleString('id-ID');
  document.getElementById('dTerkumpul').textContent=isStaff?'Order saya: '+(d.order?.total_order||0):'Terkumpul: Rp '+parseFloat(d.order?.terkumpul||0).toLocaleString('id-ID');
  document.getElementById('dOrder').textContent=(d.order?.total_order||0)+' order';
  document.getElementById('dAktif').textContent='Aktif: '+(d.aktif||0)+' order';
  if(isStaff){
    const kc=document.getElementById('dashKasCard');
    if(kc)kc.style.display='none';
  }else{
    const saldo=parseFloat(d.saldo||0);
    document.getElementById('dSaldo').textContent='Rp '+saldo.toLocaleString('id-ID');
    document.getElementById('dSaldo').style.color=saldo>=0?'var(--navy)':'var(--red)';
    document.getElementById('dKasSub').textContent='Masuk: Rp '+parseFloat(d.kas?.masuk||0).toLocaleString('id-ID')+' / Keluar: Rp '+parseFloat(d.kas?.keluar||0).toLocaleString('id-ID');
  }
  document.getElementById('dHadir').textContent=isStaff?(d.hadir?'Sudah Clock In':'Belum Clock In'):(d.hadir||0)+' orang';
  if(isStaff)document.getElementById('dHadir').style.color=d.hadir?'var(--green)':'var(--red)';
  document.getElementById('dSiap').textContent='Siap diambil: '+(d.order?.siap_diambil||0)+' order';

  // Target omset progress
  renderTargetProgress(d);
}

function renderTargetProgress(d){
  const wrap = document.getElementById('targetWrap');
  if (!wrap) return;
  const t = d.target || {};
  const tH = parseInt(t.harian)||0, tB = parseInt(t.bulanan)||0;
  if (!tH && !tB) { wrap.style.display='none'; return; }
  wrap.style.display='block';
  const fmt = n => 'Rp '+Number(n||0).toLocaleString('id-ID');

  // Harian
  const omsetH = parseInt(d.order?.omset)||0;
  const boxH = document.getElementById('targetHarianBox');
  if (tH > 0) {
    boxH.style.display='block';
    const pct = Math.min(100, Math.round(omsetH/tH*100));
    document.getElementById('targetHarianPct').textContent  = pct + '%';
    document.getElementById('targetHarianText').textContent = fmt(omsetH) + ' / ' + fmt(tH);
    document.getElementById('targetHarianBar').style.width  = pct + '%';
    const kurang = Math.max(0, tH - omsetH);
    document.getElementById('targetHarianSub').textContent = kurang > 0
      ? `Kurang ${fmt(kurang)} lagi`
      : '✓ Target tercapai!';
  } else { boxH.style.display='none'; }

  // Bulanan
  const omsetB = parseInt(t.omset_bulan)||0;
  const sisaHari = parseInt(t.hari_sisa)||0;
  const boxB = document.getElementById('targetBulananBox');
  if (tB > 0) {
    boxB.style.display='block';
    const pct = Math.min(100, Math.round(omsetB/tB*100));
    document.getElementById('targetBulananPct').textContent = pct + '%';
    document.getElementById('targetBulananText').textContent = fmt(omsetB) + ' / ' + fmt(tB);
    document.getElementById('targetBulananBar').style.width = pct + '%';
    const kurang = Math.max(0, tB - omsetB);
    let sub;
    if (kurang === 0) sub = '✓ Target tercapai!';
    else if (sisaHari <= 0) sub = `Kurang ${fmt(kurang)} (bulan berakhir hari ini)`;
    else sub = `Sisa ${sisaHari} hari — butuh ${fmt(Math.round(kurang/sisaHari))}/hari`;
    document.getElementById('targetBulananSub').textContent = sub;
  } else { boxB.style.display='none'; }
}

// ── PIPELINE ──────────────────────────────────────────
async function loadPipeline(){
  const r=await fetch('dashboard.php?action=pipeline');
  const d=await r.json();
  const steps=[{key:'masuk',label:'Diterima'},{key:'cuci',label:'Cuci'},{key:'kering',label:'Kering'},{key:'setrika',label:'Setrika'},{key:'siap',label:'Siap Ambil'}];
  const total=Object.values(d).reduce((s,v)=>s+parseInt(v||0),0);
  document.getElementById('pipeTotal').textContent=total+' order aktif';
  document.getElementById('pipeline').innerHTML=steps.map(s=>`<div class="pipe-item ${s.key==='siap'?'active':''}"><div class="pipe-num">${d[s.key]||0}</div><div class="pipe-label">${s.label}</div></div>`).join('');
}

// ── ALERTS ────────────────────────────────────────────
async function loadAlerts(){
  const r=await fetch('dashboard.php?action=alerts');
  const d=await r.json();
  document.getElementById('badgeSiap').textContent=d.siap.length;
  document.getElementById('listSiap').innerHTML=d.siap.length?d.siap.map(o=>alertRow(o,'siap')).join(''):'<div class="hl-empty" style="padding:16px">Tidak ada order siap diambil</div>';
  document.getElementById('badgeMepet').textContent=d.mepet.length;
  document.getElementById('listMepet').innerHTML=d.mepet.length?d.mepet.map(o=>alertRow(o,'mepet')).join(''):'<div class="hl-empty" style="padding:16px">Semua order on-track</div>';
  document.getElementById('badgePiutang').textContent=d.piutang.length;
  document.getElementById('listPiutang').innerHTML=d.piutang.length?d.piutang.map(o=>alertRow(o,'piutang')).join(''):'<div class="hl-empty" style="padding:16px">Tidak ada piutang tertunggak</div>';

  // Mitra drop point inaktif >7 hari
  const wrapMI = document.getElementById('mitraInaktifWrap');
  if (wrapMI && Array.isArray(d.mitra_inaktif) && d.mitra_inaktif.length) {
    wrapMI.style.display = 'block';
    document.getElementById('mitraInaktifList').innerHTML = d.mitra_inaktif.map(m => {
      const last = m.last_order ? new Date(m.last_order).toLocaleDateString('id-ID',{day:'2-digit',month:'short'}) : 'belum pernah';
      const wa = m.wa ? (''+m.wa).replace(/[^0-9]/g,'').replace(/^0/,'62') : '';
      const waLink = wa ? `<a class="alert-wa" target="_blank" href="https://wa.me/${wa.startsWith('62')?wa:'62'+wa}?text=${encodeURIComponent('Halo '+m.nama_mitra+', sudah lama tidak ada order dari titik kamu. Semua baik2 saja? 🙏')}">💬 WA</a>` : '';
      return `<div class="alert-row">
        <div style="flex:1;min-width:0">
          <div class="alert-nama">📦 ${m.nama_mitra}</div>
          <div class="alert-meta">Order terakhir: ${last}</div>
        </div>${waLink}
      </div>`;
    }).join('');
  } else if (wrapMI) {
    wrapMI.style.display = 'none';
  }
}

function alertRow(o,tipe){
  const phone=(o.telepon||'').replace(/[^0-9]/g,'').replace(/^0/,'62');
  const waMsg=tipe==='siap'?`Halo *${o.nama_pelanggan}*, laundry Anda order *${o.no_order}* sudah siap diambil. Total: Rp ${parseFloat(o.total).toLocaleString('id-ID')}. Terima kasih!`:tipe==='piutang'?`Halo *${o.nama_pelanggan}*, mengingatkan pembayaran order *${o.no_order}* sebesar Rp ${parseFloat(o.sisa_bayar).toLocaleString('id-ID')} belum lunas.`:`Halo *${o.nama_pelanggan}*, order *${o.no_order}* dijadwalkan selesai ${fmtDate(o.estimasi_selesai)}.`;
  const waUrl=phone?'https://wa.me/'+phone+'?text='+encodeURIComponent(waMsg):null;
  let badge='';
  if(tipe==='mepet'){const est=new Date(o.estimasi_selesai+'T00:00:00'),today=new Date();today.setHours(0,0,0,0);const diff=Math.round((est-today)/86400000);badge=diff<=0?'<span class="hl-badge hl-badge-red" style="font-size:10px">Terlambat</span>':'<span class="hl-badge hl-badge-dp" style="font-size:10px">Besok</span>';}
  if(tipe==='piutang')badge=`<span class="hl-badge hl-badge-red" style="font-size:10px">${o.hari_lalu} hari lalu</span>`;
  return `<div class="alert-row">
    <div style="min-width:0;flex:1">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px"><span class="alert-no">${o.no_order}</span>${badge}</div>
      <div class="alert-nama">${esc(o.nama_pelanggan)}</div>
      <div class="alert-meta">${tipe==='siap'?'Sisa bayar: <strong>Rp '+parseFloat(o.sisa_bayar||0).toLocaleString('id-ID')+'</strong>':tipe==='mepet'?'Est: '+fmtDate(o.estimasi_selesai)+' · '+statusLabel(o.status_proses):'Sisa: <strong style="color:var(--red)">Rp '+parseFloat(o.sisa_bayar).toLocaleString('id-ID')+'</strong> · '+fmtDate(o.tanggal)}</div>
    </div>
    <div style="display:flex;gap:6px;align-items:center;flex-shrink:0">
      ${waUrl?`<a href="${waUrl}" target="_blank" class="alert-wa">WA</a>`:''}
      <a href="orders.php" class="hl-btn hl-btn-outline hl-btn-sm" style="font-size:11px">Detail</a>
    </div>
  </div>`;
}

// ── CHART ─────────────────────────────────────────────
async function loadChart(){
  const r=await fetch('dashboard.php?action=chart7');
  const d=await r.json();
  if(chartInstance)chartInstance.destroy();
  const days=[];
  for(let i=6;i>=0;i--){const dt=new Date();dt.setDate(dt.getDate()-i);days.push(localDateStr(dt));}
  const dataMap={};
  d.forEach(x=>{dataMap[x.tgl]={omset:parseFloat(x.omset),count:parseInt(x.order_count)};});
  chartInstance=new Chart(document.getElementById('chartOmset'),{
    type:'bar',
    data:{
      labels:days.map(d=>new Date(d+'T00:00:00').toLocaleDateString('id-ID',{day:'numeric',month:'short'})),
      datasets:[{label:'Omset',data:days.map(d=>dataMap[d]?.omset||0),backgroundColor:days.map((_,i)=>i===6?'rgba(53,232,213,.8)':'rgba(27,45,90,.5)'),borderRadius:6}]
    },
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{callback:v=>'Rp '+(v/1000).toFixed(0)+'k'}},x:{grid:{display:false}}}}
  });
}

function statusLabel(s){return{masuk:'Diterima',cuci:'Cuci',kering:'Kering',setrika:'Setrika',siap:'Siap',diambil:'Diambil'}[s]||s;}
function fmtDate(d){if(!d)return'-';return new Date(d+'T00:00:00').toLocaleDateString('id-ID',{day:'2-digit',month:'short'});}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
</script>
</body>
</html>
