<?php
// ══════════════════════════════════════════════════════
// superadmin/client_detail.php — Full Tenant Detail
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';

date_default_timezone_set('Asia/Jakarta');

$tenantId = intval($_GET['id'] ?? 0);
$action   = $_GET['action'] ?? '';

$db = Database::get();

// ── API ACTIONS ───────────────────────────────────────
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'get_coin_history') {
        $id = (int)($_GET['id'] ?? 0);
        $rows = $db->prepare(
            "SELECT * FROM coin_ledger WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 50"
        );
        $rows->execute([$id]);
        echo json_encode($rows->fetchAll()); exit;
    }

    if ($action === 'get_payments') {
        $id = (int)($_GET['id'] ?? 0);
        $rows = $db->prepare(
            "SELECT * FROM payments WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 50"
        );
        $rows->execute([$id]);
        echo json_encode($rows->fetchAll()); exit;
    }

    if ($action === 'get_notes') {
        $id = (int)($_GET['id'] ?? 0);
        $rows = $db->prepare(
            "SELECT n.*, s.nama as sa_nama FROM tenant_notes n
             LEFT JOIN super_admins s ON s.id = n.superadmin_id
             WHERE n.tenant_id = ? ORDER BY n.is_pinned DESC, n.created_at DESC"
        );
        $rows->execute([$id]);
        echo json_encode($rows->fetchAll()); exit;
    }

    if ($action === 'add_note') {
        saVerifyCsrf();
        $id   = (int)($_POST['tenant_id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        if (!$note) { echo json_encode(['error' => 'Note kosong.']); exit; }
        $db->prepare(
            "INSERT INTO tenant_notes (tenant_id, superadmin_id, note) VALUES (?,?,?)"
        )->execute([$id, $_SESSION['superadmin_id'], $note]);
        logSuperAdminAction('add_note', $id, 'Tambah catatan: ' . substr($note, 0, 80));
        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]); exit;
    }

    if ($action === 'delete_note') {
        saVerifyCsrf();
        $nid = (int)($_POST['note_id'] ?? 0);
        $db->prepare("DELETE FROM tenant_notes WHERE id = ? AND superadmin_id = ?")->execute([$nid, $_SESSION['superadmin_id']]);
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'pin_note') {
        saVerifyCsrf();
        $nid = (int)($_POST['note_id'] ?? 0);
        $db->prepare("UPDATE tenant_notes SET is_pinned = 1 - is_pinned WHERE id = ?")->execute([$nid]);
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'get_comms') {
        $id = (int)($_GET['id'] ?? 0);
        $rows = $db->prepare(
            "SELECT s.*, sa.nama as sa_nama FROM support_tickets s
             LEFT JOIN super_admins sa ON sa.id = s.superadmin_id
             WHERE s.tenant_id = ? ORDER BY s.created_at DESC LIMIT 50"
        );
        $rows->execute([$id]);
        echo json_encode($rows->fetchAll()); exit;
    }

    if ($action === 'add_comm') {
        saVerifyCsrf();
        $id      = (int)($_POST['tenant_id'] ?? 0);
        $channel = $_POST['channel'] ?? 'wa';
        $subject = trim($_POST['subject'] ?? '');
        $msg     = trim($_POST['message'] ?? '');
        $type    = $_POST['type'] ?? 'support';
        $db->prepare(
            "INSERT INTO support_tickets (tenant_id, superadmin_id, channel, subject, message, type)
             VALUES (?,?,?,?,?,?)"
        )->execute([$id, $_SESSION['superadmin_id'], $channel, $subject, $msg, $type]);
        logSuperAdminAction('add_comm', $id, "$channel: $subject");
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'topup') {
        saVerifyCsrf();
        $id     = (int)($_POST['tenant_id'] ?? 0);
        $amount = (int)($_POST['amount'] ?? 0);
        $note   = trim($_POST['note'] ?? '');
        if ($amount <= 0) { echo json_encode(['error' => 'Jumlah tidak valid.']); exit; }
        try {
            $db->beginTransaction();
            $stm = $db->prepare("SELECT coin_balance FROM tenants WHERE id=?");
            $stm->execute([$id]);
            $bal    = (int)$stm->fetchColumn();
            $newBal = $bal + $amount;
            $db->prepare("UPDATE tenants SET coin_balance=? WHERE id=?")->execute([$newBal, $id]);
            $db->prepare("INSERT INTO coin_ledger (tenant_id,type,amount,feature_used,description,balance_after) VALUES (?,'topup',?,'manual_topup',?,?)")
               ->execute([$id, $amount, $note ?: 'Manual topup by super admin', $newBal]);
            $db->commit();
            logSuperAdminAction('topup_coin', $id, "Topup $amount coin");
            echo json_encode(['success' => true, 'new_balance' => $newBal]);
        } catch (Throwable $e) { $db->rollBack(); echo json_encode(['error' => $e->getMessage()]); }
        exit;
    }

    if ($action === 'suspend') {
        saVerifyCsrf();
        $id = (int)($_POST['tenant_id'] ?? 0);
        $db->prepare("UPDATE tenants SET status='suspended' WHERE id=?")->execute([$id]);
        logSuperAdminAction('suspend', $id, 'Tenant disuspend');
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'activate') {
        saVerifyCsrf();
        $id = (int)($_POST['tenant_id'] ?? 0);
        $db->prepare("UPDATE tenants SET status='active' WHERE id=?")->execute([$id]);
        logSuperAdminAction('activate', $id, 'Tenant diaktifkan');
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'extend_trial') {
        saVerifyCsrf();
        $id   = (int)($_POST['tenant_id'] ?? 0);
        $days = max(1, (int)($_POST['days'] ?? 7));
        $db->prepare("UPDATE tenants SET trial_ends_at = DATE_ADD(GREATEST(IFNULL(trial_ends_at, NOW()), NOW()), INTERVAL ? DAY) WHERE id=?")
           ->execute([$days, $id]);
        logSuperAdminAction('extend_trial', $id, "Trial diperpanjang $days hari");
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'reset_password') {
        saVerifyCsrf();
        $id     = (int)($_POST['tenant_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        $pw     = $_POST['new_password'] ?? '';
        if (strlen($pw) < 6) { echo json_encode(['error' => 'Password minimal 6 karakter.']); exit; }
        $hash = password_hash($pw, PASSWORD_BCRYPT);
        $db->prepare("UPDATE hl_users SET password=? WHERE id=? AND tenant_id=?")->execute([$hash, $userId, $id]);
        logSuperAdminAction('reset_password', $id, "Reset password user #$userId");
        echo json_encode(['success' => true]); exit;
    }

    echo json_encode(['error' => 'Action tidak dikenal.']); exit;
}

// ── Load tenant data ──────────────────────────────────
if (!$tenantId) { header('Location: clients.php'); exit; }

$stm = $db->prepare("SELECT * FROM tenants WHERE id=?");
$stm->execute([$tenantId]);
$tenant = $stm->fetch();
if (!$tenant) { header('Location: clients.php'); exit; }

$stm2 = $db->prepare("SELECT MAX(last_login) FROM hl_users WHERE tenant_id=?");
$stm2->execute([$tenantId]);
$lastLogin = $stm2->fetchColumn();

// Stats
$statsStm = $db->prepare(
    "SELECT
       COUNT(*) as total_orders,
       COUNT(CASE WHEN tanggal >= NOW() - INTERVAL 30 DAY THEN 1 END) as orders_30d
     FROM hl_transaksi WHERE tenant_id=?"
);
$statsStm->execute([$tenantId]);
$orderStats = $statsStm->fetch();

$coinStats = $db->prepare(
    "SELECT
       COALESCE(SUM(CASE WHEN type='deduct' THEN amount END),0) as total_used,
       COALESCE(SUM(CASE WHEN type='topup'  THEN amount END),0) as total_topup
     FROM coin_ledger WHERE tenant_id=?"
);
$coinStats->execute([$tenantId]);
$coinStat = $coinStats->fetch();

$coinByFeature = $db->prepare(
    "SELECT feature_used, SUM(amount) as total
     FROM coin_ledger WHERE tenant_id=? AND type='deduct'
     GROUP BY feature_used ORDER BY total DESC"
);
$coinByFeature->execute([$tenantId]);
$featureStat = $coinByFeature->fetchAll();

// Health
$loginOk   = $lastLogin && strtotime($lastLogin) > strtotime('-7 days');
$txWeek    = (int)$db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=? AND tanggal >= NOW()-INTERVAL 7 DAY")->execute([$tenantId]) ? 0 : 0;
$txStm     = $db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=? AND tanggal >= NOW()-INTERVAL 7 DAY");
$txStm->execute([$tenantId]);
$txWeek    = (int)$txStm->fetchColumn();
$coinOk    = (int)$tenant['coin_balance'] > 20000;
$layCount  = (int)$db->prepare("SELECT COUNT(*) FROM hl_layanan WHERE tenant_id=?")->execute([$tenantId]) ? 0 : 0;
$laySt     = $db->prepare("SELECT COUNT(*) FROM hl_layanan WHERE tenant_id=?");
$laySt->execute([$tenantId]);
$layCount  = (int)$laySt->fetchColumn();
$onboardOk = $layCount > 0 && (int)$orderStats['total_orders'] > 0;

// Users
$users = $db->prepare("SELECT id, username, nama, role, last_login FROM hl_users WHERE tenant_id=? ORDER BY role, nama")->execute([$tenantId]) ? [] : [];
$usrSt = $db->prepare("SELECT id, username, nama, role, last_login FROM hl_users WHERE tenant_id=? ORDER BY role, nama");
$usrSt->execute([$tenantId]);
$users = $usrSt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Detail: ' . htmlspecialchars($tenant['nama_outlet'])); ?>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('clients', 'Detail Client'); ?>

<div class="sa-page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div>
    <a href="clients.php" style="font-size:12.5px;color:rgba(255,255,255,.4);text-decoration:none;margin-bottom:6px;display:block;">← Kembali ke Clients</a>
    <h1><?= htmlspecialchars($tenant['nama_outlet']) ?></h1>
    <p>
      <span class="sa-badge sa-badge-<?= $tenant['status'] === 'active' ? 'active' : ($tenant['status'] === 'pending_verification' ? 'trial' : 'suspended') ?>">
        <?= ucfirst(str_replace('_',' ',$tenant['status'])) ?>
      </span>
      <span style="color:rgba(255,255,255,.35);margin-left:10px;font-family:var(--mono);font-size:12px;"><?= htmlspecialchars($tenant['slug']) ?></span>
    </p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="https://wa.me/<?= htmlspecialchars($tenant['owner_wa']) ?>" target="_blank" class="sa-btn sa-btn-wa">💬 WA Owner</a>
    <?php if ($tenant['status'] !== 'suspended'): ?>
    <button class="sa-btn sa-btn-danger" onclick="doAction('suspend')">🔒 Suspend</button>
    <?php else: ?>
    <button class="sa-btn sa-btn-green" onclick="doAction('activate')">✅ Aktifkan</button>
    <?php endif; ?>
    <button class="sa-btn sa-btn-primary" onclick="openSection('topup')">🪙 Topup Coin</button>
  </div>
</div>

<!-- Tabs -->
<div class="sa-tabs">
  <button class="sa-tab active" onclick="showTab('profil')">👤 Profil</button>
  <button class="sa-tab" onclick="showTab('health')">💊 Health</button>
  <button class="sa-tab" onclick="showTab('stats')">📊 Stats</button>
  <button class="sa-tab" onclick="showTab('coins')">🪙 Coin History</button>
  <button class="sa-tab" onclick="showTab('payments')">💳 Payments</button>
  <button class="sa-tab" onclick="showTab('notes')">📝 Notes</button>
  <button class="sa-tab" onclick="showTab('comms')">💬 Komunikasi</button>
  <button class="sa-tab" onclick="showTab('aksi')">⚙️ Aksi Manual</button>
</div>

<!-- Tab: Profil -->
<div class="sa-tab-panel active" id="tab-profil">
  <div class="sa-grid-2">
    <div class="sa-card">
      <div class="sa-card-header"><h3>Informasi Tenant</h3></div>
      <div class="sa-card-body">
        <table style="width:100%;font-size:13.5px;border-collapse:collapse;">
          <?php $rows = [
            ['Nama Outlet', $tenant['nama_outlet']],
            ['Slug / ID', $tenant['slug'] . ' (#' . $tenant['id'] . ')'],
            ['Owner', $tenant['owner_name']],
            ['WA Owner', $tenant['owner_wa']],
            ['Status', ucfirst($tenant['status'])],
            ['Coin Balance', number_format($tenant['coin_balance']) . ' coin'],
            ['Trial Ends', $tenant['trial_ends_at'] ?: '-'],
            ['Provisioned', $tenant['provisioned_at'] ?: '-'],
            ['Bergabung', $tenant['created_at']],
            ['Last Login', $lastLogin ?: 'Belum pernah'],
          ];
          foreach ($rows as [$k, $v]): ?>
          <tr>
            <td style="padding:8px 0;color:rgba(255,255,255,.4);width:140px;vertical-align:top;"><?= $k ?></td>
            <td style="padding:8px 0;color:var(--white);font-weight:500;"><?= htmlspecialchars((string)$v) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>

    <div class="sa-card">
      <div class="sa-card-header"><h3>Users</h3></div>
      <div class="sa-card-body">
        <table class="sa-table">
          <thead><tr><th>Nama</th><th>Username</th><th>Role</th><th>Last Login</th></tr></thead>
          <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td><?= htmlspecialchars($u['name']) ?></td>
            <td style="font-family:var(--mono);font-size:11px;"><?= htmlspecialchars($u['username']) ?></td>
            <td><span class="sa-badge sa-badge-indigo" style="font-size:10px;"><?= htmlspecialchars($u['role']) ?></span></td>
            <td style="font-size:12px;color:rgba(255,255,255,.4);"><?= $u['last_login'] ? date('d M Y', strtotime($u['last_login'])) : 'Belum pernah' ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Tab: Health -->
<div class="sa-tab-panel" id="tab-health">
  <div class="sa-grid-4">
    <?php
    $healthCards = [
      ['Login 7 Hari', $loginOk, $loginOk ? 'Login: ' . ($lastLogin ? date('d M', strtotime($lastLogin)) : '-') : 'Belum login 7 hari', '🔐'],
      ['Transaksi Aktif', $txWeek > 0, "$txWeek transaksi minggu ini", '📋'],
      ['Coin Cukup', $coinOk, number_format($tenant['coin_balance']) . ' coin', '🪙'],
      ['Onboarding Done', $onboardOk, $layCount . ' layanan, ' . $orderStats['total_orders'] . ' order', '🚀'],
    ];
    foreach ($healthCards as [$label, $ok, $sub, $icon]):
    ?>
    <div class="sa-stat-card <?= $ok ? 'green' : 'red' ?>">
      <div class="label"><?= $label ?></div>
      <div class="value" style="font-size:32px;"><?= $ok ? '✅' : '❌' ?></div>
      <div class="sub"><?= htmlspecialchars($sub) ?></div>
      <span class="icon-bg"><?= $icon ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Tab: Stats -->
<div class="sa-tab-panel" id="tab-stats">
  <div class="sa-grid-4" style="margin-bottom:20px;">
    <div class="sa-stat-card indigo">
      <div class="label">Total Order</div>
      <div class="value"><?= number_format($orderStats['total_orders']) ?></div>
      <span class="icon-bg">📋</span>
    </div>
    <div class="sa-stat-card blue">
      <div class="label">Avg Order/hari (30d)</div>
      <div class="value" style="font-size:20px;"><?= round($orderStats['orders_30d'] / 30, 1) ?></div>
      <span class="icon-bg">📈</span>
    </div>
    <div class="sa-stat-card red">
      <div class="label">Coin Digunakan</div>
      <div class="value" style="font-size:18px;"><?= number_format($coinStat['total_used']) ?></div>
      <span class="icon-bg">🪙</span>
    </div>
    <div class="sa-stat-card green">
      <div class="label">Total Topup</div>
      <div class="value" style="font-size:18px;"><?= number_format($coinStat['total_topup']) ?></div>
      <span class="icon-bg">💳</span>
    </div>
  </div>
  <?php if ($featureStat): ?>
  <div class="sa-card">
    <div class="sa-card-header"><h3>Coin per Fitur</h3></div>
    <div class="sa-card-body">
      <table class="sa-table">
        <thead><tr><th>Fitur</th><th>Total Coin Digunakan</th></tr></thead>
        <tbody>
        <?php foreach ($featureStat as $f): ?>
        <tr>
          <td style="font-family:var(--mono);font-size:12px;"><?= htmlspecialchars($f['feature_used'] ?: '-') ?></td>
          <td><?= number_format($f['total']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Tab: Coin History -->
<div class="sa-tab-panel" id="tab-coins">
  <div class="sa-card">
    <div class="sa-card-header"><h3>Coin Ledger (50 terakhir)</h3></div>
    <div class="sa-table-wrap">
      <table class="sa-table">
        <thead><tr><th>Tanggal</th><th>Tipe</th><th>Jumlah</th><th>Fitur</th><th>Keterangan</th><th>Balance Setelah</th></tr></thead>
        <tbody id="coinHistoryBody"><tr><td colspan="6" style="text-align:center;padding:20px;color:rgba(255,255,255,.35);">Memuat...</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<!-- Tab: Payments -->
<div class="sa-tab-panel" id="tab-payments">
  <div class="sa-card">
    <div class="sa-card-header"><h3>Riwayat Pembayaran</h3></div>
    <div class="sa-table-wrap">
      <table class="sa-table">
        <thead><tr><th>Tanggal</th><th>Tipe</th><th>Nominal</th><th>Coin</th><th>Gateway Ref</th><th>Status</th></tr></thead>
        <tbody id="paymentsBody"><tr><td colspan="6" style="text-align:center;padding:20px;color:rgba(255,255,255,.35);">Memuat...</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<!-- Tab: Notes -->
<div class="sa-tab-panel" id="tab-notes">
  <div class="sa-card" style="margin-bottom:16px;">
    <div class="sa-card-body">
      <div style="display:flex;gap:10px;">
        <textarea id="newNoteText" placeholder="Tulis catatan..." style="flex:1;padding:10px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:8px;color:#fff;font-family:var(--font);font-size:13.5px;resize:vertical;min-height:80px;"></textarea>
        <button class="sa-btn sa-btn-primary" onclick="addNote()" style="align-self:flex-end;">Simpan</button>
      </div>
    </div>
  </div>
  <div id="notesContainer"></div>
</div>

<!-- Tab: Komunikasi -->
<div class="sa-tab-panel" id="tab-comms">
  <div class="sa-card" style="margin-bottom:16px;">
    <div class="sa-card-header"><h3>Catat Komunikasi</h3></div>
    <div class="sa-card-body">
      <div class="sa-grid-2" style="gap:10px;margin-bottom:10px;">
        <div>
          <label style="font-size:11px;color:rgba(255,255,255,.4);font-weight:600;letter-spacing:.06em;text-transform:uppercase;">Channel</label>
          <select id="commChannel" style="width:100%;margin-top:6px;padding:8px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-family:var(--font);">
            <option value="wa">WhatsApp</option>
            <option value="email">Email</option>
            <option value="call">Telepon</option>
            <option value="system">System</option>
          </select>
        </div>
        <div>
          <label style="font-size:11px;color:rgba(255,255,255,.4);font-weight:600;letter-spacing:.06em;text-transform:uppercase;">Tipe</label>
          <select id="commType" style="width:100%;margin-top:6px;padding:8px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-family:var(--font);">
            <option value="support">Support</option>
            <option value="onboarding">Onboarding</option>
            <option value="billing">Billing</option>
            <option value="churn_risk">Churn Risk</option>
            <option value="info">Info</option>
          </select>
        </div>
      </div>
      <input type="text" id="commSubject" placeholder="Subjek..." style="width:100%;padding:9px 12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-family:var(--font);font-size:13.5px;margin-bottom:10px;"/>
      <textarea id="commMessage" placeholder="Pesan / catatan komunikasi..." style="width:100%;padding:10px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-family:var(--font);font-size:13.5px;resize:vertical;min-height:80px;"></textarea>
      <div style="margin-top:10px;text-align:right;">
        <button class="sa-btn sa-btn-primary" onclick="addComm()">Simpan Komunikasi</button>
      </div>
    </div>
  </div>
  <div id="commsTimeline"></div>
</div>

<!-- Tab: Aksi Manual -->
<div class="sa-tab-panel" id="tab-aksi">
  <div class="sa-grid-2">
    <!-- Topup Coin -->
    <div class="sa-card" id="topupSection">
      <div class="sa-card-header"><h3>🪙 Topup Coin</h3></div>
      <div class="sa-card-body">
        <p style="font-size:13px;color:rgba(255,255,255,.5);margin-bottom:14px;">Saldo saat ini: <strong style="color:#FCD34D;"><?= number_format($tenant['coin_balance']) ?> coin</strong></p>
        <div class="form-group" style="margin-bottom:12px;">
          <label style="font-size:11px;color:rgba(255,255,255,.4);font-weight:600;letter-spacing:.06em;text-transform:uppercase;">Jumlah Coin</label>
          <input type="number" id="topupAmt" placeholder="Contoh: 50000" style="width:100%;margin-top:6px;padding:10px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-family:var(--font);font-size:14px;"/>
        </div>
        <div class="form-group" style="margin-bottom:14px;">
          <label style="font-size:11px;color:rgba(255,255,255,.4);font-weight:600;letter-spacing:.06em;text-transform:uppercase;">Keterangan</label>
          <input type="text" id="topupNoteAksi" placeholder="Alasan topup..." style="width:100%;margin-top:6px;padding:10px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-family:var(--font);font-size:14px;"/>
        </div>
        <button class="sa-btn sa-btn-primary" onclick="doTopup()">Topup Sekarang</button>
      </div>
    </div>

    <!-- Suspend / Activate -->
    <div class="sa-card">
      <div class="sa-card-header"><h3>🔒 Status Tenant</h3></div>
      <div class="sa-card-body">
        <p style="font-size:13px;color:rgba(255,255,255,.5);margin-bottom:14px;">Status saat ini: <span class="sa-badge sa-badge-<?= $tenant['status'] === 'active' ? 'active' : 'suspended' ?>"><?= ucfirst($tenant['status']) ?></span></p>
        <?php if ($tenant['status'] !== 'suspended'): ?>
        <button class="sa-btn sa-btn-danger" onclick="doAction('suspend')" style="margin-right:8px;">🔒 Suspend Tenant</button>
        <?php else: ?>
        <button class="sa-btn sa-btn-green" onclick="doAction('activate')" style="margin-right:8px;">✅ Aktifkan Tenant</button>
        <?php endif; ?>
      </div>
    </div>

    <!-- Extend Trial -->
    <div class="sa-card">
      <div class="sa-card-header"><h3>⏰ Perpanjang Trial</h3></div>
      <div class="sa-card-body">
        <p style="font-size:13px;color:rgba(255,255,255,.5);margin-bottom:14px;">Trial berakhir: <strong><?= $tenant['trial_ends_at'] ? date('d M Y', strtotime($tenant['trial_ends_at'])) : '-' ?></strong></p>
        <div style="display:flex;gap:10px;align-items:center;">
          <input type="number" id="extendDays" value="7" min="1" max="30" style="width:80px;padding:9px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-family:var(--font);"/>
          <span style="color:rgba(255,255,255,.5);font-size:13px;">hari</span>
          <button class="sa-btn sa-btn-primary" onclick="doExtendTrial()">Perpanjang</button>
        </div>
      </div>
    </div>

    <!-- Reset Password -->
    <div class="sa-card">
      <div class="sa-card-header"><h3>🔑 Reset Password User</h3></div>
      <div class="sa-card-body">
        <div style="display:flex;flex-direction:column;gap:10px;">
          <select id="resetUserId" style="padding:9px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-family:var(--font);">
            <?php foreach ($users as $u): ?>
            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name'] . ' (' . $u['username'] . ')') ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" id="newPassword" placeholder="Password baru (min 6 karakter)" style="padding:9px 12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-family:var(--font);"/>
          <button class="sa-btn sa-btn-outline" onclick="doResetPassword()">Reset Password</button>
        </div>
      </div>
    </div>
  </div>
</div>

<?php saRenderNavClose(); ?>

<script>
const TENANT_ID = <?= $tenantId ?>;

function showTab(name) {
  document.querySelectorAll('.sa-tab').forEach((t, i) => {
    const tabs = ['profil','health','stats','coins','payments','notes','comms','aksi'];
    t.classList.toggle('active', tabs[i] === name);
  });
  document.querySelectorAll('.sa-tab-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');

  if (name === 'coins') loadCoinHistory();
  if (name === 'payments') loadPayments();
  if (name === 'notes') loadNotes();
  if (name === 'comms') loadComms();
}

function openSection(s) { showTab(s === 'topup' ? 'aksi' : s); }

function esc(s) { const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
function rupiah(n) { return 'Rp '+parseInt(n).toLocaleString('id-ID'); }
function fmtDate(s) { return s ? new Date(s).toLocaleString('id-ID',{dateStyle:'short',timeStyle:'short'}) : '-'; }

// Coin History
function loadCoinHistory() {
  fetch(`client_detail.php?action=get_coin_history&id=${TENANT_ID}`, {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(rows => {
      const tbody = document.getElementById('coinHistoryBody');
      if (!rows.length) { tbody.innerHTML='<tr><td colspan="6" style="text-align:center;padding:20px;color:rgba(255,255,255,.35);">Belum ada riwayat.</td></tr>'; return; }
      tbody.innerHTML = rows.map(r => `<tr>
        <td style="font-size:12px;">${fmtDate(r.created_at)}</td>
        <td><span class="sa-badge ${r.type==='topup'?'sa-badge-active':'sa-badge-red'}">${r.type}</span></td>
        <td style="font-family:var(--mono);">${parseInt(r.amount).toLocaleString('id-ID')}</td>
        <td style="font-size:12px;color:rgba(255,255,255,.5);">${esc(r.feature_used||'-')}</td>
        <td style="font-size:12px;color:rgba(255,255,255,.5);">${esc(r.description||'-')}</td>
        <td style="font-family:var(--mono);color:#FCD34D;">${parseInt(r.balance_after).toLocaleString('id-ID')}</td>
      </tr>`).join('');
    });
}

// Payments
function loadPayments() {
  fetch(`client_detail.php?action=get_payments&id=${TENANT_ID}`, {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(rows => {
      const tbody = document.getElementById('paymentsBody');
      if (!rows.length) { tbody.innerHTML='<tr><td colspan="6" style="text-align:center;padding:20px;color:rgba(255,255,255,.35);">Belum ada pembayaran.</td></tr>'; return; }
      tbody.innerHTML = rows.map(r => `<tr>
        <td style="font-size:12px;">${fmtDate(r.created_at)}</td>
        <td>${esc(r.type||'-')}</td>
        <td style="font-family:var(--mono);">${rupiah(r.amount||0)}</td>
        <td>${r.coin_amount ? parseInt(r.coin_amount).toLocaleString('id-ID') : '-'}</td>
        <td style="font-family:var(--mono);font-size:11px;">${esc(r.gateway_ref||'-')}</td>
        <td><span class="sa-badge ${r.status==='success'?'sa-badge-active':'sa-badge-yellow'}">${esc(r.status||'-')}</span></td>
      </tr>`).join('');
    });
}

// Notes
function loadNotes() {
  fetch(`client_detail.php?action=get_notes&id=${TENANT_ID}`, {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(notes => {
      const el = document.getElementById('notesContainer');
      if (!notes.length) { el.innerHTML='<p style="color:rgba(255,255,255,.35);font-size:13px;">Belum ada catatan.</p>'; return; }
      el.innerHTML = notes.map(n => `
        <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,${n.is_pinned?'.2':'.07'});border-radius:10px;padding:14px 16px;margin-bottom:10px;position:relative;">
          ${n.is_pinned ? '<span style="color:#FCD34D;font-size:12px;">📌 Pinned</span><br>' : ''}
          <div style="font-size:14px;color:rgba(255,255,255,.85);white-space:pre-wrap;margin-bottom:8px;">${esc(n.note)}</div>
          <div style="font-size:11px;color:rgba(255,255,255,.3);">${esc(n.sa_nama||'Admin')} · ${fmtDate(n.created_at)}</div>
          <div style="position:absolute;top:12px;right:12px;display:flex;gap:6px;">
            <button class="sa-btn sa-btn-sm sa-btn-outline" onclick="pinNote(${n.id})">${n.is_pinned?'Unpin':'Pin'}</button>
            <button class="sa-btn sa-btn-sm sa-btn-danger" onclick="deleteNote(${n.id})">Hapus</button>
          </div>
        </div>`).join('');
    });
}

function addNote() {
  const text = document.getElementById('newNoteText').value.trim();
  if (!text) return;
  saPost(`client_detail.php?action=add_note`, { tenant_id: TENANT_ID, note: text })
    .then(r=>r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      document.getElementById('newNoteText').value = '';
      saShowToast('Catatan ditambahkan.', 'success');
      loadNotes();
    });
}

function pinNote(id) {
  saPost(`client_detail.php?action=pin_note`, { note_id: id })
    .then(r=>r.json()).then(() => loadNotes());
}

function deleteNote(id) {
  if (!confirm('Hapus catatan ini?')) return;
  saPost(`client_detail.php?action=delete_note`, { note_id: id })
    .then(r=>r.json()).then(() => { saShowToast('Catatan dihapus.'); loadNotes(); });
}

// Communications
function loadComms() {
  fetch(`client_detail.php?action=get_comms&id=${TENANT_ID}`, {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(items => {
      const el = document.getElementById('commsTimeline');
      if (!items.length) { el.innerHTML='<p style="color:rgba(255,255,255,.35);font-size:13px;">Belum ada riwayat komunikasi.</p>'; return; }
      const chIcons = { wa:'💬', email:'📧', call:'📞', system:'⚙️' };
      el.innerHTML = items.map(c => `
        <div style="display:flex;gap:12px;margin-bottom:14px;">
          <div style="width:36px;height:36px;border-radius:50%;background:rgba(99,102,241,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:16px;">${chIcons[c.channel]||'💬'}</div>
          <div style="flex:1;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
              <strong style="font-size:13.5px;">${esc(c.subject||'(tanpa subjek)')}</strong>
              <span class="sa-badge sa-badge-indigo" style="font-size:10px;">${esc(c.type)}</span>
              <span class="sa-badge sa-badge-indigo" style="font-size:10px;">${esc(c.channel)}</span>
            </div>
            <div style="font-size:13px;color:rgba(255,255,255,.6);margin-top:4px;white-space:pre-wrap;">${esc(c.message||'')}</div>
            <div style="font-size:11px;color:rgba(255,255,255,.3);margin-top:4px;">${esc(c.sa_nama||'Admin')} · ${fmtDate(c.created_at)}</div>
          </div>
        </div>`).join('');
    });
}

function addComm() {
  const data = {
    tenant_id: TENANT_ID,
    channel:  document.getElementById('commChannel').value,
    type:     document.getElementById('commType').value,
    subject:  document.getElementById('commSubject').value,
    message:  document.getElementById('commMessage').value,
  };
  saPost(`client_detail.php?action=add_comm`, data)
    .then(r=>r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      document.getElementById('commSubject').value = '';
      document.getElementById('commMessage').value = '';
      saShowToast('Komunikasi dicatat.', 'success');
      loadComms();
    });
}

// Actions
function doAction(act) {
  const labels = { suspend: 'suspend', activate: 'aktifkan' };
  if (!confirm(`Yakin ${labels[act]} tenant ini?`)) return;
  saPost(`client_detail.php?action=${act}`, { tenant_id: TENANT_ID })
    .then(r=>r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      saShowToast('Status berhasil diubah.', 'success');
      setTimeout(() => location.reload(), 1200);
    });
}

function doTopup() {
  const amt  = document.getElementById('topupAmt').value;
  const note = document.getElementById('topupNoteAksi').value;
  if (!amt || amt < 1) { saShowToast('Jumlah harus > 0', 'error'); return; }
  saPost(`client_detail.php?action=topup`, { tenant_id: TENANT_ID, amount: amt, note })
    .then(r=>r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      saShowToast('Topup berhasil! Saldo baru: ' + parseInt(d.new_balance).toLocaleString('id-ID'), 'success');
      setTimeout(() => location.reload(), 1500);
    });
}

function doExtendTrial() {
  const days = document.getElementById('extendDays').value;
  if (!days || days < 1) { saShowToast('Hari harus > 0', 'error'); return; }
  saPost(`client_detail.php?action=extend_trial`, { tenant_id: TENANT_ID, days })
    .then(r=>r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      saShowToast('Trial diperpanjang.', 'success');
      setTimeout(() => location.reload(), 1200);
    });
}

function doResetPassword() {
  const userId = document.getElementById('resetUserId').value;
  const pw     = document.getElementById('newPassword').value;
  if (!pw || pw.length < 6) { saShowToast('Password minimal 6 karakter.', 'error'); return; }
  if (!confirm('Reset password user ini?')) return;
  saPost(`client_detail.php?action=reset_password`, { tenant_id: TENANT_ID, user_id: userId, new_password: pw })
    .then(r=>r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      saShowToast('Password berhasil direset.', 'success');
      document.getElementById('newPassword').value = '';
    });
}
</script>
</body>
</html>
