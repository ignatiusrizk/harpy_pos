<?php
// ══════════════════════════════════════════════════════
// cron/trial_lifecycle.php — Outlet lifecycle management
//
// Jalankan setiap hari via Hostinger Cron Jobs:
//   0 2 * * * php /path/to/harpy/cron/trial_lifecycle.php
//
// Yang dilakukan:
//   1. trial   → grace  : jika trial_ends_at < NOW()
//   2. grace   → suspended: jika grace_ends_at < NOW()
//   3. suspended → purge: jika purge_at < NOW() (hapus data)
//   4. Cleanup email_verifications expired
//   5. Cleanup registration_attempts lama
// ══════════════════════════════════════════════════════

// Proteksi: hanya boleh dijalankan dari CLI
if (php_sapi_name() !== 'cli' && !defined('ALLOW_CRON_WEB')) {
    http_response_code(403);
    die('CLI only.');
}

define('ROOT', dirname(__DIR__));
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/EmailVerification.php';
require_once ROOT . '/core/RateLimiter.php';
require_once ROOT . '/core/Mailer.php';

// ── Helper: log transisi outlet ke superadmin_logs (best-effort) ──
function logOutletTransition(PDO $db, int $outletId, int $tenantId, string $from, string $to): void {
    try {
        $db->prepare(
            "INSERT INTO superadmin_logs (action, target_type, target_id, details, created_at)
             VALUES ('outlet_status_transition','outlet',?,?,NOW())"
        )->execute([
            $outletId,
            json_encode(['tenant_id'=>$tenantId,'from'=>$from,'to'=>$to,'source'=>'cron']),
        ]);
    } catch (Throwable $e) {
        // Table mungkin belum ada / kolom beda — skip diam
        error_log('[cron logOutletTransition] ' . $e->getMessage());
    }
}

$db  = Database::get();
$now = date('Y-m-d H:i:s');
$log = [];

function clog(string $msg): void {
    global $log;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
    $log[] = $line;
}

clog('=== trial_lifecycle.php START ===');

// ── 1. trial → grace ─────────────────────────────────
$trialExpired = $db->prepare("
    SELECT id, tenant_id, nama_outlet FROM outlets
    WHERE status = 'trial'
      AND trial_ends_at IS NOT NULL
      AND trial_ends_at < ?
");
$trialExpired->execute([$now]);
$trialRows = $trialExpired->fetchAll();

foreach ($trialRows as $outlet) {
    $graceEndsAt = date('Y-m-d H:i:s', time() + 7 * 86400);
    $purgeAt     = date('Y-m-d H:i:s', time() + 37 * 86400);

    $db->prepare("
        UPDATE outlets
        SET status = 'grace', grace_ends_at = ?, purge_at = ?
        WHERE id = ?
    ")->execute([$graceEndsAt, $purgeAt, $outlet['id']]);

    logOutletTransition($db, (int)$outlet['id'], (int)$outlet['tenant_id'], 'trial', 'grace');
    clog("trial→grace: outlet_id={$outlet['id']} ({$outlet['nama_outlet']}) tenant_id={$outlet['tenant_id']}");
}
clog(count($trialRows) . ' outlets moved trial→grace');

// ── 1b. Trial reminder (H-3 dan H-1 sebelum trial_ends_at) ───
$reminderRows = $db->prepare("
    SELECT o.id, o.nama_outlet, o.tenant_id, o.trial_ends_at,
           DATEDIFF(o.trial_ends_at, NOW()) AS days_left,
           t.email, t.owner_name
      FROM outlets o
      JOIN tenants t ON t.id = o.tenant_id
     WHERE o.status = 'trial'
       AND o.trial_ends_at IS NOT NULL
       AND DATEDIFF(o.trial_ends_at, NOW()) IN (1, 3)
       AND t.email IS NOT NULL
");
$reminderRows->execute();
$reminders = $reminderRows->fetchAll();
$sent = 0;
foreach ($reminders as $r) {
    $ok = Mailer::sendTrialReminder($r['email'], $r['owner_name'] ?? 'Owner', (int)$r['days_left']);
    if ($ok) $sent++;
    clog("reminder ($r[days_left]h): outlet_id=$r[id] email=$r[email] " . ($ok ? 'sent' : 'FAILED'));
}
clog("$sent trial reminder emails sent");

// ── 2. grace → suspended ─────────────────────────────
$graceExpired = $db->prepare("
    SELECT id, tenant_id, nama_outlet FROM outlets
    WHERE status = 'grace'
      AND grace_ends_at IS NOT NULL
      AND grace_ends_at < ?
");
$graceExpired->execute([$now]);
$graceRows = $graceExpired->fetchAll();

foreach ($graceRows as $outlet) {
    $db->prepare("
        UPDATE outlets SET status = 'suspended' WHERE id = ?
    ")->execute([$outlet['id']]);

    logOutletTransition($db, (int)$outlet['id'], (int)$outlet['tenant_id'], 'grace', 'suspended');
    clog("grace→suspended: outlet_id={$outlet['id']} ({$outlet['nama_outlet']})");
}
clog(count($graceRows) . ' outlets moved grace→suspended');

// ── 3. suspended → purge ─────────────────────────────
$purgeReady = $db->prepare("
    SELECT id, tenant_id, nama_outlet FROM outlets
    WHERE status = 'suspended'
      AND purge_at IS NOT NULL
      AND purge_at < ?
");
$purgeReady->execute([$now]);
$purgeRows = $purgeReady->fetchAll();

foreach ($purgeRows as $outlet) {
    $oid = $outlet['id'];
    $tid = $outlet['tenant_id'];

    // Hapus data operasional outlet
    // CATATAN: hl_pelanggan TIDAK DIHAPUS — pelanggan adalah aset account
    // (lintas outlet), bukan milik 1 cabang. Sesuai brief HQ-Outlet Fase 2.
    $tables = [
        'hl_transaksi_item', 'hl_transaksi',
        'hl_layanan', 'hl_kas', 'hl_absensi', 'hl_izin',
        'hl_gaji', 'hl_promo', 'hl_voucher', 'hl_audit_log',
    ];
    foreach ($tables as $table) {
        try {
            $db->prepare("DELETE FROM $table WHERE tenant_id=? AND outlet_id=?")->execute([$tid, $oid]);
        } catch (Throwable $e) {
            clog("  WARN: gagal hapus $table untuk outlet $oid: " . $e->getMessage());
        }
    }

    // hl_pelanggan: registered_outlet_id pointer ke outlet yang di-purge
    // → set null, biarkan record tetap (account-level)
    try {
        $db->prepare("UPDATE hl_pelanggan SET registered_outlet_id=NULL WHERE tenant_id=? AND registered_outlet_id=?")
           ->execute([$tid, $oid]);
    } catch (Throwable $e) {
        clog("  WARN: gagal reset registered_outlet_id pelanggan: " . $e->getMessage());
    }

    // Hapus coin ledger outlet
    $db->prepare("DELETE FROM coin_ledger WHERE tenant_id=? AND outlet_id=?")->execute([$tid, $oid]);

    // Hapus users outlet
    $db->prepare("DELETE FROM hl_users WHERE tenant_id=? AND outlet_id=?")->execute([$tid, $oid]);

    // Tandai outlet sebagai closed
    $db->prepare("UPDATE outlets SET status='closed' WHERE id=?")->execute([$oid]);

    // Update total_outlets di tenant
    $db->prepare("
        UPDATE tenants
        SET total_outlets = (SELECT COUNT(*) FROM outlets WHERE tenant_id=? AND status!='closed')
        WHERE id=?
    ")->execute([$tid, $tid]);

    logOutletTransition($db, (int)$oid, (int)$tid, 'suspended', 'closed');
    clog("PURGED: outlet_id=$oid ({$outlet['nama_outlet']}) tenant_id=$tid");
}
clog(count($purgeRows) . ' outlets purged');

// ── 4. Cleanup email verifications ───────────────────
EmailVerification::cleanup();
clog('email_verifications cleanup done');

// ── 5. Cleanup registration_attempts ─────────────────
RateLimiter::cleanup();
clog('registration_attempts cleanup done');

// ── 6. Reset trial_coin_balance ke 0 jika outlet sudah tidak trial ──
$db->prepare("
    UPDATE outlets
    SET trial_coin_balance = 0
    WHERE status != 'trial' AND trial_coin_balance > 0
")->execute();

clog('trial_coin_balance reset for non-trial outlets');
clog('=== trial_lifecycle.php DONE ===');
