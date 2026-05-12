<?php
// ══════════════════════════════════════════════════════
// migrate_harpy_johar.php — One-time migration script
// Pindahkan data existing ke harpy_tenant_harpy_johar
//
// CARA PAKAI:
//   Via CLI (lebih aman):
//     php migrate_harpy_johar.php
//   Via browser (tambahkan token di URL):
//     https://yoursite.com/harpy/migrate_harpy_johar.php?token=GANTI_INI
//
// PENTING:
//   1. Backup database lama sebelum menjalankan ini
//   2. Jalankan SEKALI saja
//   3. Hapus file ini setelah selesai
// ══════════════════════════════════════════════════════

define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';

// ── Security: token check jika dijalankan via browser ─
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    $token = $_GET['token'] ?? '';
    if ($token !== 'GANTI_TOKEN_INI_SEBELUM_DEPLOY') {
        http_response_code(403);
        die('Akses ditolak. Jalankan via CLI atau gunakan token yang benar.');
    }
}

// ── Konfigurasi ───────────────────────────────────────
$SOURCE_DB  = 'u269895997_Laundry_Masuk';       // DB lama
$TARGET_DB  = 'harpy_tenant_harpy_johar';         // DB tenant baru
$TENANT_SLUG = 'harpy_johar';

set_time_limit(300);
$errors = [];
$results = [];

out('═══════════════════════════════════════');
out('   Harpy Johar Migration Script');
out('   Source : ' . $SOURCE_DB);
out('   Target : ' . $TARGET_DB);
out('═══════════════════════════════════════');

// ── Koneksi ───────────────────────────────────────────
try {
    $src = Database::connectTo($SOURCE_DB);
    $tgt = Database::connectTo($TARGET_DB);
    out('✅ Koneksi berhasil');
} catch (Exception $e) {
    die('❌ Koneksi gagal: ' . $e->getMessage());
}

// ── Daftar tabel yang akan dimigrasikan ───────────────
// Urutan penting karena ada foreign key constraint
$tables = [
    // Auth & users (tanpa dependency)
    'hl_roles'            => ['truncate' => true,  'skip_cols' => []],
    'hl_permissions'      => ['truncate' => true,  'skip_cols' => []],
    'hl_role_permissions' => ['truncate' => true,  'skip_cols' => []],
    'hl_users'            => ['truncate' => true,  'skip_cols' => []],

    // Core business
    'hl_pelanggan'        => ['truncate' => true,  'skip_cols' => []],
    'hl_layanan'          => ['truncate' => true,  'skip_cols' => []],
    'hl_transaksi'        => ['truncate' => true,  'skip_cols' => []],
    'hl_transaksi_item'   => ['truncate' => true,  'skip_cols' => []],

    // Finance & HR
    'hl_kas'              => ['truncate' => true,  'skip_cols' => []],
    'hl_gaji'             => ['truncate' => true,  'skip_cols' => []],
    'hl_absensi'          => ['truncate' => true,  'skip_cols' => []],
    'hl_izin'             => ['truncate' => true,  'skip_cols' => []],

    // Promo
    'hl_promo'            => ['truncate' => true,  'skip_cols' => []],
    'hl_voucher'          => ['truncate' => true,  'skip_cols' => []],

    // Audit (opsional)
    'hl_audit_log'        => ['truncate' => true,  'skip_cols' => []],
];

// ── Disable FK checks selama migrasi ─────────────────
$tgt->exec('SET FOREIGN_KEY_CHECKS = 0');
$src->exec('SET FOREIGN_KEY_CHECKS = 0');

// ── Migrasi per tabel ─────────────────────────────────
foreach ($tables as $table => $opts) {
    out("\n--- Migrasi: {$table} ---");

    // Cek apakah tabel ada di source
    $check = $src->query("SHOW TABLES LIKE '{$table}'")->fetch();
    if (!$check) {
        out("⚠️  Tabel tidak ada di source, skip.");
        continue;
    }

    // Ambil semua data dari source
    $rows = $src->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        out("   (kosong, skip)");
        $results[$table] = 0;
        continue;
    }

    // Truncate target jika diminta
    if ($opts['truncate']) {
        $tgt->exec("TRUNCATE TABLE `{$table}`");
    }

    // Cek kolom yang ada di target
    $targetCols = [];
    foreach ($tgt->query("SHOW COLUMNS FROM `{$table}`")->fetchAll() as $col) {
        $targetCols[] = $col['Field'];
    }

    // Filter kolom
    $firstRow = $rows[0];
    $availCols = array_intersect(array_keys($firstRow), $targetCols);
    $availCols = array_diff($availCols, $opts['skip_cols']);
    $availCols = array_values($availCols);

    if (empty($availCols)) {
        out("⚠️  Tidak ada kolom yang cocok, skip.");
        continue;
    }

    $colList     = implode(',', array_map(fn($c) => "`{$c}`", $availCols));
    $placeholder = implode(',', array_fill(0, count($availCols), '?'));
    $stmt        = $tgt->prepare("INSERT INTO `{$table}` ({$colList}) VALUES ({$placeholder})");

    $count  = 0;
    $failed = 0;
    foreach ($rows as $row) {
        $values = array_map(fn($c) => $row[$c] ?? null, $availCols);
        try {
            $stmt->execute($values);
            $count++;
        } catch (Exception $e) {
            $failed++;
            if ($failed <= 3) { // batasi log error agar tidak flood
                out("   ⚠️  Row gagal: " . $e->getMessage());
            }
        }
    }

    $results[$table] = $count;
    out("   ✅ {$count} rows migrated" . ($failed > 0 ? ", {$failed} gagal" : ''));
}

// ── Re-enable FK checks ───────────────────────────────
$tgt->exec('SET FOREIGN_KEY_CHECKS = 1');
$src->exec('SET FOREIGN_KEY_CHECKS = 1');

// ── Daftarkan harpy_johar ke harpy_master (jika belum) ─
out("\n--- Daftar ke harpy_master ---");
try {
    $existing = Database::master()->prepare(
        "SELECT id FROM tenants WHERE slug = ? LIMIT 1"
    );
    $existing->execute([$TENANT_SLUG]);
    if ($existing->fetch()) {
        out("   ℹ️  Tenant sudah terdaftar di master, skip.");
    } else {
        Database::master()->prepare("
            INSERT INTO tenants
              (slug, db_name, nama_outlet, status, coin_balance, provisioned_at)
            VALUES (?, ?, 'Harpy Johar', 'active', 50000, NOW())
        ")->execute([$TENANT_SLUG, $TARGET_DB]);
        out("   ✅ Tenant harpy_johar terdaftar di harpy_master");
    }
} catch (Exception $e) {
    out("   ❌ Gagal daftar ke master: " . $e->getMessage());
}

// ── Summary ───────────────────────────────────────────
out("\n═══════════════════════════════════════");
out("   MIGRATION SUMMARY");
out("═══════════════════════════════════════");
foreach ($results as $table => $count) {
    out(str_pad($table, 30) . ": {$count} rows");
}
if (!empty($errors)) {
    out("\nErrors:");
    foreach ($errors as $e) out("  - {$e}");
}
out("\n✅ Selesai. Hapus file ini setelah verifikasi.");
out("⚠️  Jangan lupa verifikasi data di target DB sebelum go-live.");

// ── Helper ────────────────────────────────────────────
function out(string $msg): void
{
    if (php_sapi_name() === 'cli') {
        echo $msg . "\n";
    } else {
        echo nl2br(htmlspecialchars($msg)) . "<br>\n";
        flush();
    }
}
