<?php
// ══════════════════════════════════════════════════════
// hq/karyawan.php — Manajemen Karyawan Lintas Outlet (HQ View)
// Brief HQ-Outlet Section 4.2
//
// Fitur:
//   - List semua karyawan tenant + outlet assignment-nya
//   - Detail: info karyawan + outlet aktif + riwayat penugasan
//   - Mutasi: pindah karyawan dari outlet A ke outlet B
//   - Add Assignment: assign karyawan ke outlet tambahan
//   - Remove Assignment: cabut penugasan outlet tertentu
// ══════════════════════════════════════════════════════

$activePage = 'hq-karyawan';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];
$uid  = (int)($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? '';

// ── Helper: log audit (best-effort) ───────────────────
function hqAudit(PDO $db, int $tid, int $uid, string $action, string $detail): void {
    try {
        $db->prepare(
            "INSERT INTO superadmin_logs (action, target_type, target_id, details, created_at)
             VALUES (?,'karyawan',?,?,NOW())"
        )->execute([$action, $tid, json_encode(['by'=>$uid,'detail'=>$detail])]);
    } catch (Throwable $e) { error_log('[hq audit] '.$e->getMessage()); }
}

// ── AJAX actions ──────────────────────────────────────
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'list') {
        $q          = trim($_GET['q'] ?? '');
        $filterOid  = (int)($_GET['outlet_id'] ?? 0);
        $filterStat = $_GET['status']  ?? '';     // active | inactive
        $filterJab  = trim($_GET['jabatan'] ?? '');

        $params = [$tid];
        $whereExtra = '';
        if ($q !== '') {
            $whereExtra .= " AND (u.nama LIKE ? OR u.username LIKE ? OR u.telepon LIKE ?)";
            $like = "%$q%";
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($filterStat === 'active')   { $whereExtra .= " AND u.is_active=1"; }
        if ($filterStat === 'inactive') { $whereExtra .= " AND u.is_active=0"; }
        if ($filterJab !== '')          { $whereExtra .= " AND u.jabatan = ?"; $params[] = $filterJab; }

        // Filter outlet → JOIN hl_karyawan_outlet
        $joinOutlet = '';
        if ($filterOid > 0) {
            try {
                // pakai EXISTS subquery agar tidak duplicate
                $whereExtra .= " AND EXISTS (
                    SELECT 1 FROM hl_karyawan_outlet ko
                    WHERE ko.tenant_id=u.tenant_id AND ko.karyawan_id=u.id
                      AND ko.outlet_id=? AND ko.is_active=1
                )";
                $params[] = $filterOid;
            } catch (Throwable) {}
        }

        $stmt = $db->prepare(
            "SELECT u.id, u.nama, u.username, u.role, u.telepon, u.is_active, u.email,
                    u.jabatan, u.outlet_id AS primary_outlet_id,
                    u.created_at, u.last_login
               FROM hl_users u
              WHERE u.tenant_id = ? $whereExtra
              ORDER BY u.is_active DESC, u.nama ASC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Untuk tiap karyawan, ambil daftar outlet aktif
        foreach ($rows as &$r) {
            $r['assignments'] = [];
            try {
                $a = $db->prepare(
                    "SELECT ko.outlet_id, ko.assigned_at, o.nama_outlet, o.status
                       FROM hl_karyawan_outlet ko
                       JOIN outlets o ON o.id = ko.outlet_id
                      WHERE ko.tenant_id=? AND ko.karyawan_id=? AND ko.is_active=1
                      ORDER BY o.is_main DESC, o.nama_outlet ASC"
                );
                $a->execute([$tid, $r['id']]);
                $r['assignments'] = $a->fetchAll();
            } catch (Throwable) { /* tabel belum ada */ }
        }
        unset($r);
        echo json_encode($rows); exit;
    }

    if ($action === 'filter_options') {
        // Outlet list + daftar jabatan unique untuk dropdown filter
        $outlets = $db->prepare("SELECT id, nama_outlet FROM outlets
                                   WHERE tenant_id=? AND status!='closed'
                                   ORDER BY is_main DESC, nama_outlet ASC");
        $outlets->execute([$tid]);

        $jabs = $db->prepare("SELECT DISTINCT jabatan FROM hl_users
                                WHERE tenant_id=? AND jabatan IS NOT NULL AND jabatan!=''
                                ORDER BY jabatan ASC");
        $jabs->execute([$tid]);

        echo json_encode([
            'outlets'  => $outlets->fetchAll(),
            'jabatan'  => $jabs->fetchAll(PDO::FETCH_COLUMN),
        ]);
        exit;
    }

    if ($action === 'toggle_active' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        verifyCsrf();
        $kid = (int)($d['karyawan_id'] ?? 0);
        $newState = !empty($d['activate']) ? 1 : 0;
        if (!$kid) { echo json_encode(['error'=>'ID invalid']); exit; }

        // Validate ownership + jangan deactivate diri sendiri
        $u = $db->prepare("SELECT id, role FROM hl_users WHERE id=? AND tenant_id=?");
        $u->execute([$kid, $tid]);
        $usr = $u->fetch();
        if (!$usr) { echo json_encode(['error'=>'Karyawan tidak ditemukan']); exit; }
        if ($kid === $uid && $newState === 0) {
            echo json_encode(['error'=>'Tidak bisa menonaktifkan akun sendiri']); exit;
        }
        if ($usr['role'] === 'owner' && $newState === 0) {
            echo json_encode(['error'=>'Akun owner tidak bisa dinonaktifkan dari sini']); exit;
        }

        try {
            $db->prepare("UPDATE hl_users SET is_active=? WHERE id=? AND tenant_id=?")
               ->execute([$newState, $kid, $tid]);
            // Saat di-deactivate: cabut semua active assignment juga
            if ($newState === 0) {
                try {
                    $db->prepare("UPDATE hl_karyawan_outlet
                                     SET is_active=0, unassigned_at=NOW()
                                   WHERE tenant_id=? AND karyawan_id=? AND is_active=1")
                       ->execute([$tid, $kid]);
                } catch (Throwable) {}
            }
            hqAudit($db, $tid, $uid, $newState ? 'activate_karyawan' : 'deactivate_karyawan', "karyawan=$kid");
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>'Gagal: '.$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'performance') {
        // Rekap absensi & order yg ditangani karyawan lintas outlet (30 hari terakhir)
        $kid = (int)($_GET['id'] ?? 0);
        if (!$kid) { echo json_encode(['error'=>'ID invalid']); exit; }

        $since = date('Y-m-d', strtotime('-30 days'));
        $today = date('Y-m-d');

        $absensi = [];
        try {
            $a = $db->prepare(
                "SELECT a.outlet_id, COUNT(*) AS total,
                        SUM(a.status='hadir') AS hadir,
                        SUM(a.status='izin')  AS izin,
                        SUM(a.status='sakit') AS sakit,
                        SUM(a.status='alpha') AS alpha,
                        (SELECT nama_outlet FROM outlets WHERE id=a.outlet_id) AS nama_outlet
                   FROM hl_absensi a
                  WHERE a.tenant_id=? AND a.user_id=? AND a.tanggal BETWEEN ? AND ?
                  GROUP BY a.outlet_id"
            );
            $a->execute([$tid, $kid, $since, $today]);
            $absensi = $a->fetchAll();
        } catch (Throwable) {}

        $orders = [];
        try {
            $o = $db->prepare(
                "SELECT t.outlet_id, COUNT(*) AS total_order,
                        COALESCE(SUM(t.total),0) AS total_omset,
                        (SELECT nama_outlet FROM outlets WHERE id=t.outlet_id) AS nama_outlet
                   FROM hl_transaksi t
                  WHERE t.tenant_id=? AND t.created_by=? AND DATE(t.tanggal) BETWEEN ? AND ?
                  GROUP BY t.outlet_id"
            );
            $o->execute([$tid, $kid, $since, $today]);
            $orders = $o->fetchAll();
        } catch (Throwable) {}

        echo json_encode([
            'periode'  => ['since'=>$since, 'until'=>$today, 'days'=>30],
            'absensi'  => $absensi,
            'orders'   => $orders,
        ]);
        exit;
    }

    if ($action === 'detail') {
        $kid = (int)($_GET['id'] ?? 0);
        $karyawan = $db->prepare("SELECT * FROM hl_users WHERE id=? AND tenant_id=? LIMIT 1");
        $karyawan->execute([$kid, $tid]);
        $k = $karyawan->fetch();
        if (!$k) { echo json_encode(['error'=>'Karyawan tidak ditemukan']); exit; }

        // Active assignments
        $assignments = [];
        $history = [];
        try {
            $a = $db->prepare(
                "SELECT ko.*, o.nama_outlet, o.status AS outlet_status
                   FROM hl_karyawan_outlet ko
                   JOIN outlets o ON o.id = ko.outlet_id
                  WHERE ko.tenant_id=? AND ko.karyawan_id=?
                  ORDER BY ko.is_active DESC, ko.assigned_at DESC"
            );
            $a->execute([$tid, $kid]);
            foreach ($a->fetchAll() as $row) {
                if ($row['is_active']) $assignments[] = $row;
                else                   $history[] = $row;
            }
        } catch (Throwable) { /* tabel belum ada */ }

        // List outlet aktif (untuk pilihan mutasi/add)
        $outlets = $db->prepare(
            "SELECT id, nama_outlet, status FROM outlets
              WHERE tenant_id=? AND status IN ('trial','grace','active')
              ORDER BY is_main DESC, nama_outlet ASC"
        );
        $outlets->execute([$tid]);

        echo json_encode([
            'karyawan'    => $k,
            'assignments' => $assignments,
            'history'     => $history,
            'all_outlets' => $outlets->fetchAll(),
        ]);
        exit;
    }

    if ($action === 'mutasi' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        verifyCsrf();
        $kid  = (int)($d['karyawan_id'] ?? 0);
        $from = (int)($d['from_outlet_id'] ?? 0);
        $to   = (int)($d['to_outlet_id']   ?? 0);
        $note = trim(strip_tags($d['notes'] ?? ''));
        $efektif = $d['tanggal_efektif'] ?? '';
        // Validate date format YYYY-MM-DD, default = today
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $efektif)) {
            $efektif = date('Y-m-d');
        }
        $efektifTs = $efektif . ' ' . date('H:i:s');

        if (!$kid || !$from || !$to || $from === $to) {
            echo json_encode(['error'=>'Data mutasi tidak valid']); exit;
        }

        // Validasi: karyawan harus milik tenant, outlet to harus aktif
        $vK = $db->prepare("SELECT id FROM hl_users WHERE id=? AND tenant_id=?");
        $vK->execute([$kid, $tid]); if (!$vK->fetchColumn()) { echo json_encode(['error'=>'Karyawan invalid']); exit; }
        $vO = $db->prepare("SELECT id FROM outlets WHERE id=? AND tenant_id=? AND status IN ('trial','grace','active')");
        $vO->execute([$to, $tid]);  if (!$vO->fetchColumn()) { echo json_encode(['error'=>'Outlet tujuan invalid']); exit; }

        $db->beginTransaction();
        try {
            // Tutup assignment lama (FROM) — unassigned_at = tanggal efektif
            $db->prepare(
                "UPDATE hl_karyawan_outlet
                    SET is_active=0, unassigned_at=?,
                        notes = CONCAT(COALESCE(notes,''), ' | Mutasi ke outlet #$to efektif $efektif: ', ?)
                  WHERE tenant_id=? AND karyawan_id=? AND outlet_id=? AND is_active=1"
            )->execute([$efektifTs, $note, $tid, $kid, $from]);

            // Buat assignment baru (TO) — atau aktifkan kalau sudah ada inactive
            $existing = $db->prepare(
                "SELECT id FROM hl_karyawan_outlet
                  WHERE tenant_id=? AND karyawan_id=? AND outlet_id=? LIMIT 1"
            );
            $existing->execute([$tid, $kid, $to]);
            if ($exId = $existing->fetchColumn()) {
                $db->prepare(
                    "UPDATE hl_karyawan_outlet
                        SET is_active=1, assigned_at=?, unassigned_at=NULL,
                            assigned_by=?, notes=CONCAT(COALESCE(notes,''),' | Mutasi dari outlet #$from efektif $efektif: ',?)
                      WHERE id=?"
                )->execute([$efektifTs, $uid, $note, $exId]);
            } else {
                $db->prepare(
                    "INSERT INTO hl_karyawan_outlet
                       (tenant_id, karyawan_id, outlet_id, is_active, assigned_at, assigned_by, notes)
                     VALUES (?,?,?,1,?,?,?)"
                )->execute([$tid, $kid, $to, $efektifTs, $uid, "Mutasi dari outlet #$from efektif $efektif: $note"]);
            }

            // Update primary outlet (default login) ke outlet baru
            $db->prepare("UPDATE hl_users SET outlet_id=? WHERE id=? AND tenant_id=?")
               ->execute([$to, $kid, $tid]);

            $db->commit();
            hqAudit($db, $tid, $uid, 'mutasi_karyawan', "karyawan=$kid from=$from to=$to efektif=$efektif note=$note");
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[hq mutasi] '.$e->getMessage());
            echo json_encode(['error'=>'Gagal mutasi: '.$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'add_assignment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        verifyCsrf();
        $kid = (int)($d['karyawan_id'] ?? 0);
        $oid = (int)($d['outlet_id']   ?? 0);
        if (!$kid || !$oid) { echo json_encode(['error'=>'Data invalid']); exit; }

        $vK = $db->prepare("SELECT id FROM hl_users WHERE id=? AND tenant_id=?");
        $vK->execute([$kid, $tid]); if (!$vK->fetchColumn()) { echo json_encode(['error'=>'Karyawan invalid']); exit; }
        $vO = $db->prepare("SELECT id FROM outlets WHERE id=? AND tenant_id=? AND status IN ('trial','grace','active')");
        $vO->execute([$oid, $tid]);  if (!$vO->fetchColumn()) { echo json_encode(['error'=>'Outlet invalid']); exit; }

        // Cek sudah ada assignment aktif belum
        $chk = $db->prepare("SELECT id FROM hl_karyawan_outlet
                              WHERE tenant_id=? AND karyawan_id=? AND outlet_id=? AND is_active=1");
        $chk->execute([$tid, $kid, $oid]);
        if ($chk->fetchColumn()) { echo json_encode(['error'=>'Sudah ditugaskan ke outlet ini']); exit; }

        try {
            // Cek apakah ada record inactive (re-activate)
            $ex = $db->prepare("SELECT id FROM hl_karyawan_outlet
                                 WHERE tenant_id=? AND karyawan_id=? AND outlet_id=? LIMIT 1");
            $ex->execute([$tid, $kid, $oid]);
            if ($exId = $ex->fetchColumn()) {
                $db->prepare("UPDATE hl_karyawan_outlet
                                 SET is_active=1, assigned_at=NOW(), unassigned_at=NULL, assigned_by=?
                               WHERE id=?")
                   ->execute([$uid, $exId]);
            } else {
                $db->prepare("INSERT INTO hl_karyawan_outlet
                                (tenant_id, karyawan_id, outlet_id, is_active, assigned_at, assigned_by)
                              VALUES (?,?,?,1,NOW(),?)")
                   ->execute([$tid, $kid, $oid, $uid]);
            }
            hqAudit($db, $tid, $uid, 'add_assignment', "karyawan=$kid outlet=$oid");
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            error_log('[hq add_assignment] '.$e->getMessage());
            echo json_encode(['error'=>'Gagal: '.$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        verifyCsrf();

        $nama     = substr(trim(strip_tags($d['nama'] ?? '')), 0, 100);
        $username = substr(trim(strip_tags($d['username'] ?? '')), 0, 50);
        $email    = substr(trim($d['email'] ?? ''), 0, 150);
        $password = $d['password'] ?? '';
        $role     = in_array($d['role'] ?? '', ['owner','manager','admin','kasir','staff','kurir'], true) ? $d['role'] : 'staff';
        $jabatan  = substr(trim(strip_tags($d['jabatan'] ?? '')), 0, 100);
        $telepon  = substr(preg_replace('/[^0-9+\-\s]/', '', $d['telepon'] ?? ''), 0, 20);
        $nik      = substr(preg_replace('/\D/', '', $d['nik'] ?? ''), 0, 50);
        $kontrakTipe = in_array($d['kontrak_tipe'] ?? '', ['tetap','kontrak','harian','partime'], true) ? $d['kontrak_tipe'] : null;
        $kontrakMulai   = !empty($d['kontrak_mulai'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['kontrak_mulai'])   ? $d['kontrak_mulai']   : null;
        $kontrakSelesai = !empty($d['kontrak_selesai']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['kontrak_selesai']) ? $d['kontrak_selesai'] : null;
        $bankNama     = substr(trim(strip_tags($d['bank_nama'] ?? '')), 0, 100);
        $noRekening   = substr(preg_replace('/[^0-9\-]/', '', $d['no_rekening'] ?? ''), 0, 50);
        $bankAtasnama = substr(trim(strip_tags($d['bank_atasnama'] ?? '')), 0, 100);
        $assignOutlets = array_map('intval', $d['outlet_ids'] ?? []);

        if (!$nama)                  { echo json_encode(['error'=>'Nama wajib diisi']); exit; }
        if (!$username)              { echo json_encode(['error'=>'Username wajib diisi']); exit; }
        if (strlen($password) < 6)   { echo json_encode(['error'=>'Password minimal 6 karakter']); exit; }
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['error'=>'Format email tidak valid']); exit;
        }

        // Cek duplikat username per tenant
        $chk = $db->prepare("SELECT id FROM hl_users WHERE tenant_id=? AND username=? LIMIT 1");
        $chk->execute([$tid, $username]);
        if ($chk->fetchColumn()) { echo json_encode(['error'=>'Username sudah digunakan']); exit; }

        // Validasi outlet (semua harus milik tenant + aktif)
        if (!empty($assignOutlets)) {
            $ph = implode(',', array_fill(0, count($assignOutlets), '?'));
            $vO = $db->prepare("SELECT COUNT(*) FROM outlets
                                 WHERE tenant_id=? AND id IN ($ph) AND status IN ('trial','grace','active')");
            $vO->execute(array_merge([$tid], $assignOutlets));
            if ((int)$vO->fetchColumn() !== count($assignOutlets)) {
                echo json_encode(['error'=>'Salah satu outlet tujuan tidak valid']); exit;
            }
        }

        // Detect kolom extra (NIK, kontrak, bank) — graceful kalau migration belum
        $hasExtra = true;
        try { $db->query("SELECT nik, kontrak_tipe, bank_nama FROM hl_users LIMIT 1"); }
        catch (Throwable) { $hasExtra = false; }

        $db->beginTransaction();
        try {
            $primaryOid = $assignOutlets[0] ?? 0;
            if ($hasExtra) {
                $db->prepare(
                    "INSERT INTO hl_users
                       (tenant_id, outlet_id, username, email, password, nama, role, jabatan, telepon,
                        nik, kontrak_tipe, kontrak_mulai, kontrak_selesai,
                        bank_nama, no_rekening, bank_atasnama,
                        is_active, email_verified)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,1)"
                )->execute([
                    $tid, $primaryOid, $username, $email ?: null,
                    password_hash($password, PASSWORD_DEFAULT),
                    $nama, $role, $jabatan ?: null, $telepon ?: null,
                    $nik ?: null, $kontrakTipe, $kontrakMulai, $kontrakSelesai,
                    $bankNama ?: null, $noRekening ?: null, $bankAtasnama ?: null,
                ]);
            } else {
                $db->prepare(
                    "INSERT INTO hl_users
                       (tenant_id, outlet_id, username, email, password, nama, role, jabatan, telepon,
                        is_active, email_verified)
                     VALUES (?,?,?,?,?,?,?,?,?,1,1)"
                )->execute([
                    $tid, $primaryOid, $username, $email ?: null,
                    password_hash($password, PASSWORD_DEFAULT),
                    $nama, $role, $jabatan ?: null, $telepon ?: null,
                ]);
            }
            $newId = (int)$db->lastInsertId();

            // Assign ke outlet yang dipilih
            foreach ($assignOutlets as $oid) {
                $db->prepare(
                    "INSERT INTO hl_karyawan_outlet
                       (tenant_id, karyawan_id, outlet_id, is_active, assigned_at, assigned_by)
                     VALUES (?,?,?,1,NOW(),?)"
                )->execute([$tid, $newId, $oid, $uid]);
            }

            $db->commit();
            hqAudit($db, $tid, $uid, 'create_karyawan',
                "karyawan=$newId nama=$nama role=$role outlets=".implode(',', $assignOutlets));
            echo json_encode(['success'=>true, 'id'=>$newId]);
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[hq create karyawan] '.$e->getMessage());
            echo json_encode(['error'=>'Gagal simpan: '.$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'remove_assignment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        verifyCsrf();
        $kid = (int)($d['karyawan_id'] ?? 0);
        $oid = (int)($d['outlet_id']   ?? 0);
        if (!$kid || !$oid) { echo json_encode(['error'=>'Data invalid']); exit; }

        try {
            $db->prepare(
                "UPDATE hl_karyawan_outlet
                    SET is_active=0, unassigned_at=NOW()
                  WHERE tenant_id=? AND karyawan_id=? AND outlet_id=? AND is_active=1"
            )->execute([$tid, $kid, $oid]);
            hqAudit($db, $tid, $uid, 'remove_assignment', "karyawan=$kid outlet=$oid");
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            error_log('[hq remove_assignment] '.$e->getMessage());
            echo json_encode(['error'=>'Gagal: '.$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error'=>'Unknown action']); exit;
}

// ── List outlet untuk dropdown filter ─────────────────
$outletAll = $db->prepare("SELECT id, nama_outlet FROM outlets WHERE tenant_id=? AND status!='closed' ORDER BY is_main DESC, nama_outlet ASC");
$outletAll->execute([$tid]);
$outletList = $outletAll->fetchAll();

$ownerNama  = $hqUser['nama'] ?? 'Owner';
$tenantNama = $hqTenant['nama_outlet'] ?? 'HQ';
$csrf       = getCsrfToken();
?>
<?php
$pageTitle  = 'Karyawan Lintas Outlet';
$activePage = 'hq-karyawan';
require __DIR__ . '/_layout_open.php';
?>
<style>
  .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px}
  h1{font-size:1.4rem;font-weight:800;color:#0F1C3A}
  h1 small{display:block;font-size:13px;font-weight:400;color:#6B7280;margin-top:2px}

  .toolbar{background:#fff;border-radius:12px;padding:14px 18px;display:flex;gap:10px;align-items:center;
           flex-wrap:wrap;margin-bottom:16px;box-shadow:0 1px 6px rgba(0,0,0,.05)}
  .toolbar input,.toolbar select{padding:9px 14px;border:1.5px solid #E5E7EB;border-radius:8px;font-size:14px;outline:none;font-family:inherit}
  .toolbar input{flex:1;min-width:200px}
  .toolbar select{min-width:140px;background:#fff;cursor:pointer}
  .toolbar input:focus,.toolbar select:focus{border-color:#35E8D5}
  .kr-card.inactive{opacity:.6;background:#F9FAFB}
  .perf-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:8px}
  .perf-card{background:#F9FAFB;border-radius:8px;padding:10px 14px;font-size:12px}
  .perf-card strong{display:block;font-size:1.05rem;color:#0F1C3A;font-family:monospace;font-weight:800}
  .perf-card small{color:#6B7280}

  .karyawan-grid{display:grid;grid-template-columns:1fr;gap:10px}
  .kr-card{background:#fff;border-radius:12px;padding:16px 18px;display:grid;
           grid-template-columns:1fr 2fr auto;gap:16px;align-items:center;
           box-shadow:0 1px 6px rgba(0,0,0,.05);transition:box-shadow .2s}
  .kr-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08)}
  .kr-name{font-weight:700;color:#0F1C3A;font-size:14px}
  .kr-name small{display:block;color:#6B7280;font-weight:400;font-size:12px;margin-top:2px}
  .kr-role{font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px;text-transform:uppercase;
           display:inline-block;margin-top:4px}
  .role-owner{background:#FEF3C7;color:#92400E}
  .role-manager{background:#DDD6FE;color:#5B21B6}
  .role-admin{background:#DBEAFE;color:#1E40AF}
  .role-kasir{background:#D1FAE5;color:#065F46}
  .role-staff{background:#F3F4F6;color:#374151}
  .role-kurir{background:#FED7AA;color:#9A3412}
  .role-superadmin{background:#FBCFE8;color:#9D174D}
  .kr-outlets{display:flex;flex-wrap:wrap;gap:5px}
  .kr-outlet-badge{background:#F0FDFB;border:1px solid rgba(53,232,213,.3);color:#0F1C3A;
                   font-size:11px;font-weight:600;padding:3px 9px;border-radius:6px}
  .kr-outlet-empty{color:#9CA3AF;font-size:12px;font-style:italic}
  .kr-actions{display:flex;gap:6px}
  .btn{padding:7px 13px;border-radius:8px;font-weight:700;font-size:12px;border:none;cursor:pointer;
       font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:5px}
  .btn-primary{background:#35E8D5;color:#0F1C3A}
  .btn-light{background:#F3F4F6;color:#0F1C3A}
  .btn-light:hover{background:#E5E7EB}
  .btn-danger{background:#FEE2E2;color:#991B1B}
  .btn-danger:hover{background:#FECACA}

  .empty{text-align:center;padding:48px 20px;color:#9CA3AF;background:#fff;border-radius:12px}
  .empty .ico{font-size:48px;margin-bottom:10px}

  /* Modal */
  .modal-backdrop{position:fixed;inset:0;background:rgba(15,28,58,.75);z-index:999;display:none;
                  align-items:center;justify-content:center;padding:20px}
  .modal-backdrop.open{display:flex}
  .modal{background:#fff;border-radius:14px;max-width:640px;width:100%;max-height:90vh;overflow:auto;
         padding:24px 26px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
  .modal-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px}
  .modal-title{font-size:1.1rem;font-weight:800;color:#0F1C3A}
  .modal-close{background:none;border:none;font-size:24px;cursor:pointer;color:#9CA3AF;line-height:1}
  .modal-close:hover{color:#0F1C3A}
  .section{margin-bottom:18px}
  .section-label{font-size:11px;color:#6B7280;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px}
  .info-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #F3F4F6;font-size:13px}
  .info-row:last-child{border-bottom:none}
  .info-row .lbl{color:#6B7280}
  .info-row .val{font-weight:600;color:#0F1C3A}

  .assignment-item{background:#F0FDFB;border:1px solid rgba(53,232,213,.3);border-radius:8px;
                   padding:10px 14px;margin-bottom:6px;display:flex;justify-content:space-between;align-items:center}
  .assignment-item .info{font-size:13px}
  .assignment-item .info strong{color:#0F1C3A}
  .assignment-item .info small{color:#6B7280;display:block;margin-top:2px;font-size:11px}

  .history-item{background:#F9FAFB;border-radius:8px;padding:9px 14px;margin-bottom:5px;font-size:12px;
                color:#6B7280;display:flex;justify-content:space-between;align-items:center}
  .history-item strong{color:#374151}

  .form-grid{display:grid;gap:12px}
  .form-grid label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:4px}
  .form-grid select,.form-grid input,.form-grid textarea{
    width:100%;padding:9px 12px;border:1.5px solid #E5E7EB;border-radius:8px;
    font-size:14px;outline:none;font-family:inherit;box-sizing:border-box
  }
  .form-grid select:focus,.form-grid input:focus,.form-grid textarea:focus{border-color:#35E8D5}

  .alert{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:12px}
  .alert.error{background:#FEE2E2;color:#991B1B;border:1px solid #FECACA}
  .alert.success{background:#D1FAE5;color:#065F46;border:1px solid #6EE7B7}

  @media(max-width:640px){
    .kr-card{grid-template-columns:1fr;gap:8px}
    .kr-actions{justify-content:flex-start;flex-wrap:wrap}
  }
</style>

  <div class="header">
    <h1>👥 Manajemen Karyawan
      <small>Karyawan lintas outlet · <?= htmlspecialchars($tenantNama) ?></small>
    </h1>
    <button class="btn btn-primary" style="padding:11px 20px;font-size:14px" onclick="openCreate()">
      + Tambah Karyawan
    </button>
  </div>

  <div class="toolbar">
    <input type="search" id="searchInput" placeholder="🔍 Cari nama, username, HP…" oninput="loadList()">
    <select id="filterOutlet" onchange="loadList()">
      <option value="">📍 Semua Outlet</option>
    </select>
    <select id="filterStatus" onchange="loadList()">
      <option value="">⚪ Semua Status</option>
      <option value="active">✓ Aktif</option>
      <option value="inactive">✕ Non-aktif</option>
    </select>
    <select id="filterJabatan" onchange="loadList()">
      <option value="">💼 Semua Jabatan</option>
    </select>
    <span id="totalCount" style="font-size:12px;color:#6B7280;font-weight:600"></span>
  </div>

  <div class="karyawan-grid" id="karyawanGrid">
    <div class="empty"><div class="ico">⏳</div><p>Memuat...</p></div>
  </div>

<!-- Detail Modal -->
<div class="modal-backdrop" id="detailModal" onclick="if(event.target===this)closeModal('detailModal')">
  <div class="modal" id="detailContent"></div>
</div>

<!-- Mutasi Modal -->
<div class="modal-backdrop" id="mutasiModal" onclick="if(event.target===this)closeModal('mutasiModal')">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">🔄 Mutasi Karyawan</div>
      <button class="modal-close" onclick="closeModal('mutasiModal')">×</button>
    </div>
    <div id="mutasiAlert"></div>
    <div class="form-grid">
      <div>
        <label>Karyawan</label>
        <input type="text" id="mutKaryawanName" readonly style="background:#F9FAFB">
        <input type="hidden" id="mutKaryawanId">
      </div>
      <div>
        <label>Dari Outlet</label>
        <select id="mutFrom"></select>
      </div>
      <div>
        <label>Ke Outlet</label>
        <select id="mutTo"></select>
      </div>
      <div>
        <label>Tanggal Efektif</label>
        <input type="date" id="mutTanggal">
        <small style="color:#9CA3AF;font-size:11px;display:block;margin-top:3px">
          Default: hari ini. Histori akan dicatat dengan tanggal ini.
        </small>
      </div>
      <div>
        <label>Catatan / Alasan</label>
        <textarea id="mutNotes" rows="2" placeholder="cth: rotasi tim, kebutuhan operasional, request karyawan…"></textarea>
      </div>
      <button class="btn btn-primary" style="padding:12px;font-size:14px" onclick="submitMutasi()">
        ✓ Konfirmasi Mutasi
      </button>
    </div>
  </div>
</div>

<!-- Create Karyawan Modal -->
<div class="modal-backdrop" id="createModal" onclick="if(event.target===this)closeModal('createModal')">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">➕ Tambah Karyawan Baru</div>
      <button class="modal-close" onclick="closeModal('createModal')">×</button>
    </div>
    <div id="createAlert"></div>
    <div class="form-grid">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div>
          <label>Nama Lengkap <span style="color:#EF4444">*</span></label>
          <input type="text" id="crNama" maxlength="100" placeholder="cth: Budi Santoso">
        </div>
        <div>
          <label>Username <span style="color:#EF4444">*</span></label>
          <input type="text" id="crUsername" maxlength="50" placeholder="cth: budi.s">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div>
          <label>Password <span style="color:#EF4444">*</span></label>
          <input type="password" id="crPassword" minlength="6" placeholder="Min 6 karakter">
        </div>
        <div>
          <label>Role <span style="color:#EF4444">*</span></label>
          <select id="crRole">
            <option value="kasir">Kasir (POS, Order, Customer)</option>
            <option value="staff">Staff (produksi laundry)</option>
            <option value="kurir">Kurir (delivery)</option>
            <option value="manager">Manager (HQ terbatas)</option>
            <option value="admin">Admin (ops penuh)</option>
          </select>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div>
          <label>Telepon</label>
          <input type="tel" id="crTelepon" maxlength="20" placeholder="08xxxxxxxxxx">
        </div>
        <div>
          <label>Email</label>
          <input type="email" id="crEmail" maxlength="150" placeholder="opsional">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div>
          <label>Jabatan</label>
          <input type="text" id="crJabatan" maxlength="100" placeholder="cth: Kasir Senior">
        </div>
        <div>
          <label>NIK <small style="font-weight:400;color:#9CA3AF">(opsional)</small></label>
          <input type="text" id="crNik" maxlength="16" placeholder="16 digit KTP" inputmode="numeric">
        </div>
      </div>

      <!-- Kontrak section -->
      <div style="background:#F9FAFB;border:1.5px solid #E5E7EB;border-radius:8px;padding:12px;margin-top:4px">
        <div style="font-size:11px;color:#6B7280;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px">
          📋 Kontrak
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
          <div>
            <label>Tipe Kontrak</label>
            <select id="crKontrakTipe">
              <option value="">— pilih —</option>
              <option value="tetap">Tetap</option>
              <option value="kontrak">Kontrak</option>
              <option value="harian">Harian</option>
              <option value="partime">Part-time</option>
            </select>
          </div>
          <div>
            <label>Mulai</label>
            <input type="date" id="crKontrakMulai">
          </div>
          <div>
            <label>Selesai</label>
            <input type="date" id="crKontrakSelesai">
          </div>
        </div>
      </div>

      <!-- Bank section -->
      <div style="background:#F9FAFB;border:1.5px solid #E5E7EB;border-radius:8px;padding:12px">
        <div style="font-size:11px;color:#6B7280;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px">
          💳 Rekening Bank <small style="font-weight:400;text-transform:none">(untuk penggajian)</small>
        </div>
        <div style="display:grid;grid-template-columns:1fr 2fr;gap:10px;margin-bottom:8px">
          <div>
            <label>Bank</label>
            <input type="text" id="crBankNama" maxlength="100" placeholder="cth: BCA, Mandiri">
          </div>
          <div>
            <label>No. Rekening</label>
            <input type="text" id="crNoRek" maxlength="50" placeholder="cth: 1234567890" inputmode="numeric">
          </div>
        </div>
        <div>
          <label>Atas Nama</label>
          <input type="text" id="crBankAtasnama" maxlength="100" placeholder="Nama sesuai buku tabungan">
        </div>
      </div>

      <div>
        <label>Tugaskan ke Outlet <span style="font-weight:400;color:#9CA3AF">(bisa pilih banyak)</span></label>
        <div id="crOutletList" style="background:#F9FAFB;border:1.5px solid #E5E7EB;border-radius:8px;
                                       padding:10px;max-height:140px;overflow-y:auto"></div>
      </div>
      <button class="btn btn-primary" style="padding:12px;font-size:14px" onclick="submitCreate()">
        ✓ Simpan Karyawan
      </button>
    </div>
  </div>
</div>

<!-- Add Assignment Modal -->
<div class="modal-backdrop" id="addModal" onclick="if(event.target===this)closeModal('addModal')">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <div class="modal-title">➕ Tambah Penugasan Outlet</div>
      <button class="modal-close" onclick="closeModal('addModal')">×</button>
    </div>
    <div id="addAlert"></div>
    <div class="form-grid">
      <div>
        <label>Karyawan</label>
        <input type="text" id="addKaryawanName" readonly style="background:#F9FAFB">
        <input type="hidden" id="addKaryawanId">
      </div>
      <div>
        <label>Tugaskan ke Outlet</label>
        <select id="addOutletId"></select>
      </div>
      <button class="btn btn-primary" style="padding:12px;font-size:14px" onclick="submitAdd()">
        ✓ Tambah Penugasan
      </button>
    </div>
  </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;

function escapeHtml(s){
  return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
}

function roleClass(role){return 'role-'+(role||'staff').replace(/\s/g,'');}

async function loadFilterOptions(){
  const r = await fetch('/ERP/harpy/hq/karyawan.php?action=filter_options');
  const d = await r.json();
  const outletSel = document.getElementById('filterOutlet');
  const jabSel = document.getElementById('filterJabatan');
  if (d.outlets) {
    outletSel.innerHTML = '<option value="">📍 Semua Outlet</option>' +
      d.outlets.map(o => `<option value="${o.id}">📍 ${escapeHtml(o.nama_outlet)}</option>`).join('');
  }
  if (d.jabatan) {
    jabSel.innerHTML = '<option value="">💼 Semua Jabatan</option>' +
      d.jabatan.map(j => `<option value="${escapeHtml(j)}">${escapeHtml(j)}</option>`).join('');
  }
}

async function loadList(){
  const q = document.getElementById('searchInput').value;
  const fOid  = document.getElementById('filterOutlet').value;
  const fStat = document.getElementById('filterStatus').value;
  const fJab  = document.getElementById('filterJabatan').value;
  const url = '/ERP/harpy/hq/karyawan.php?action=list&q=' + encodeURIComponent(q)
    + '&outlet_id=' + encodeURIComponent(fOid)
    + '&status=' + encodeURIComponent(fStat)
    + '&jabatan=' + encodeURIComponent(fJab);
  const r = await fetch(url);
  const rows = await r.json();
  const grid = document.getElementById('karyawanGrid');
  document.getElementById('totalCount').textContent = rows.length + ' karyawan';

  if (rows.length === 0) {
    grid.innerHTML = '<div class="empty"><div class="ico">👥</div><p>Belum ada karyawan' +
      (q?' yang cocok dengan pencarian':'') + '</p></div>';
    return;
  }

  grid.innerHTML = rows.map(r => {
    const outlets = r.assignments && r.assignments.length
      ? r.assignments.map(a => `<span class="kr-outlet-badge">📍 ${escapeHtml(a.nama_outlet)}</span>`).join('')
      : '<span class="kr-outlet-empty">⚠️ Belum ditugaskan ke outlet manapun</span>';
    const inactiveBadge = r.is_active == 0
      ? '<span class="kr-role" style="background:#FEE2E2;color:#991B1B;margin-left:4px">NON-AKTIF</span>'
      : '';
    return `
      <div class="kr-card ${r.is_active==0?'inactive':''}">
        <div>
          <div class="kr-name">${escapeHtml(r.nama)}
            <small>@${escapeHtml(r.username)}${r.telepon?' · '+escapeHtml(r.telepon):''}${r.jabatan?' · 💼 '+escapeHtml(r.jabatan):''}</small>
          </div>
          <span class="kr-role ${roleClass(r.role)}">${escapeHtml(r.role||'staff')}</span>${inactiveBadge}
        </div>
        <div class="kr-outlets">${outlets}</div>
        <div class="kr-actions">
          <button class="btn btn-light" onclick="showDetail(${r.id})">Detail</button>
          ${r.is_active==1 ? `<button class="btn btn-primary" onclick="openMutasi(${r.id}, '${escapeHtml(r.nama)}', ${JSON.stringify(r.assignments||[]).replace(/'/g, '&apos;')})">🔄 Mutasi</button>` : ''}
        </div>
      </div>
    `;
  }).join('');
}

async function showDetail(id){
  const r = await fetch('/ERP/harpy/hq/karyawan.php?action=detail&id=' + id);
  const d = await r.json();
  if (d.error) { alert(d.error); return; }

  const k = d.karyawan;
  const ass = d.assignments || [];
  const hist = d.history || [];

  const assHtml = ass.length === 0
    ? '<div style="color:#9CA3AF;font-size:13px;font-style:italic">⚠️ Belum ditugaskan ke outlet manapun</div>'
    : ass.map(a => `
        <div class="assignment-item">
          <div class="info">
            <strong>📍 ${escapeHtml(a.nama_outlet)}</strong>
            <small>Status: ${a.outlet_status} · Sejak: ${a.assigned_at ? new Date(a.assigned_at).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}) : '-'}</small>
          </div>
          <button class="btn btn-danger" onclick="removeAssignment(${k.id}, ${a.outlet_id}, '${escapeHtml(a.nama_outlet)}')">Cabut</button>
        </div>
      `).join('');

  const histHtml = hist.length === 0
    ? '<div style="color:#9CA3AF;font-size:12px">Belum ada riwayat mutasi.</div>'
    : hist.map(h => `
        <div class="history-item">
          <span>📍 <strong>${escapeHtml(h.nama_outlet)}</strong> ${h.notes ? '· '+escapeHtml(h.notes) : ''}</span>
          <span>${h.assigned_at ? new Date(h.assigned_at).toLocaleDateString('id-ID',{day:'2-digit',month:'short'}) : '-'}${h.unassigned_at ? ' → '+new Date(h.unassigned_at).toLocaleDateString('id-ID',{day:'2-digit',month:'short'}) : ''}</span>
        </div>
      `).join('');

  const toggleBtn = k.is_active == 1
    ? `<button class="btn btn-danger" onclick="toggleActive(${k.id}, '${escapeHtml(k.nama)}', false)">⛔ Nonaktifkan Karyawan</button>`
    : `<button class="btn btn-primary" onclick="toggleActive(${k.id}, '${escapeHtml(k.nama)}', true)">✓ Aktifkan Kembali</button>`;

  document.getElementById('detailContent').innerHTML = `
    <div class="modal-header">
      <div>
        <div class="modal-title">${escapeHtml(k.nama)}</div>
        <div style="font-size:12px;color:#6B7280;margin-top:3px">@${escapeHtml(k.username)} · <span class="kr-role ${roleClass(k.role)}">${escapeHtml(k.role)}</span>${k.is_active==0?' · <span style="color:#991B1B;font-weight:700">NON-AKTIF</span>':''}</div>
      </div>
      <button class="modal-close" onclick="closeModal('detailModal')">×</button>
    </div>

    <div class="section">
      <div class="section-label">Info Karyawan</div>
      <div class="info-row"><span class="lbl">Email</span><span class="val">${escapeHtml(k.email || '-')}</span></div>
      <div class="info-row"><span class="lbl">Telepon</span><span class="val">${escapeHtml(k.telepon || '-')}</span></div>
      <div class="info-row"><span class="lbl">Jabatan</span><span class="val">${escapeHtml(k.jabatan || '-')}</span></div>
      ${k.nik ? `<div class="info-row"><span class="lbl">NIK</span><span class="val">${escapeHtml(k.nik)}</span></div>` : ''}
      <div class="info-row"><span class="lbl">Status</span><span class="val">${k.is_active==1?'✓ Aktif':'⛔ Non-aktif'}</span></div>
      <div class="info-row"><span class="lbl">Bergabung</span><span class="val">${k.created_at ? new Date(k.created_at).toLocaleDateString('id-ID') : '-'}</span></div>
    </div>

    ${k.kontrak_tipe ? `
    <div class="section">
      <div class="section-label">📋 Kontrak Kerja</div>
      <div class="info-row"><span class="lbl">Tipe</span><span class="val" style="text-transform:capitalize">${escapeHtml(k.kontrak_tipe)}</span></div>
      <div class="info-row"><span class="lbl">Mulai</span><span class="val">${k.kontrak_mulai ? new Date(k.kontrak_mulai).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}) : '-'}</span></div>
      <div class="info-row"><span class="lbl">Selesai</span><span class="val">${k.kontrak_selesai ? new Date(k.kontrak_selesai).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}) : '<span style="color:#9CA3AF">tanpa batas</span>'}</span></div>
    </div>` : ''}

    ${k.bank_nama || k.no_rekening ? `
    <div class="section">
      <div class="section-label">💳 Rekening Bank <small style="color:#9CA3AF;font-weight:400;text-transform:none">(untuk penggajian)</small></div>
      <div class="info-row"><span class="lbl">Bank</span><span class="val">${escapeHtml(k.bank_nama || '-')}</span></div>
      <div class="info-row"><span class="lbl">No. Rekening</span><span class="val" style="font-family:monospace">${escapeHtml(k.no_rekening || '-')}</span></div>
      <div class="info-row"><span class="lbl">Atas Nama</span><span class="val">${escapeHtml(k.bank_atasnama || '-')}</span></div>
    </div>` : ''}

    <div class="section">
      <div class="section-label">📍 Outlet Aktif Saat Ini (${ass.length})</div>
      ${assHtml}
      ${k.is_active==1 ? `<button class="btn btn-light" style="margin-top:8px;width:100%" onclick="openAdd(${k.id}, '${escapeHtml(k.nama)}')">+ Tambah Penugasan Outlet</button>` : ''}
    </div>

    <div class="section">
      <div class="section-label">📜 Riwayat Mutasi</div>
      ${histHtml}
    </div>

    <div class="section">
      <div class="section-label">📊 Performa & Absensi — 30 Hari Terakhir</div>
      <div id="perfBox" style="color:#9CA3AF;font-size:12px;text-align:center;padding:14px">Memuat…</div>
    </div>

    <div class="section" style="border-top:1px dashed #E5E7EB;padding-top:14px">
      ${toggleBtn}
    </div>
  `;
  openModal('detailModal');
  loadPerformance(k.id);
}

async function loadPerformance(kid){
  const box = document.getElementById('perfBox');
  if (!box) return;
  try {
    const r = await fetch('/ERP/harpy/hq/karyawan.php?action=performance&id=' + kid);
    const d = await r.json();
    if (d.error) { box.innerHTML = '<span style="color:#9CA3AF">Tidak ada data performa</span>'; return; }
    const abs = d.absensi || [];
    const ord = d.orders  || [];
    if (abs.length === 0 && ord.length === 0) {
      box.innerHTML = '<span style="color:#9CA3AF">Belum ada aktivitas dalam 30 hari terakhir.</span>';
      return;
    }
    // Gabung per outlet
    const byOutlet = {};
    abs.forEach(a => { byOutlet[a.outlet_id] = { nama: a.nama_outlet, hadir:+a.hadir, izin:+a.izin, sakit:+a.sakit, alpha:+a.alpha, total:+a.total, order:0, omset:0 }; });
    ord.forEach(o => {
      if (!byOutlet[o.outlet_id]) byOutlet[o.outlet_id] = { nama: o.nama_outlet, hadir:0, izin:0, sakit:0, alpha:0, total:0, order:0, omset:0 };
      byOutlet[o.outlet_id].order = +o.total_order;
      byOutlet[o.outlet_id].omset = +o.total_omset;
    });
    box.innerHTML = Object.values(byOutlet).map(b => `
      <div style="margin-bottom:10px;padding:10px 12px;background:#F9FAFB;border-radius:8px">
        <div style="font-weight:700;color:#0F1C3A;margin-bottom:6px;font-size:13px">📍 ${escapeHtml(b.nama || '-')}</div>
        <div class="perf-grid">
          <div class="perf-card"><strong>${b.hadir}/${b.total}</strong><small>Hadir (izin: ${b.izin}, sakit: ${b.sakit}, alpha: ${b.alpha})</small></div>
          <div class="perf-card"><strong>${b.order}</strong><small>Order Ditangani · Rp ${Number(b.omset).toLocaleString('id-ID')}</small></div>
        </div>
      </div>
    `).join('');
  } catch (e) {
    box.innerHTML = '<span style="color:#9CA3AF">Gagal memuat performa</span>';
  }
}

async function toggleActive(id, nama, activate){
  const msg = activate
    ? `Aktifkan kembali "${nama}"?`
    : `Nonaktifkan "${nama}"?\n\nKaryawan tidak bisa login. Semua penugasan outlet akan dicabut.\nHistori data tidak dihapus.`;
  if (!confirm(msg)) return;
  const r = await fetch('/ERP/harpy/hq/karyawan.php?action=toggle_active', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
    body: JSON.stringify({karyawan_id: id, activate: activate ? 1 : 0}),
  });
  const j = await r.json();
  if (j.error) { alert(j.error); return; }
  closeModal('detailModal');
  loadList();
}

function openMutasi(id, nama, currentAssignments){
  document.getElementById('mutKaryawanId').value = id;
  document.getElementById('mutKaryawanName').value = nama;
  document.getElementById('mutNotes').value = '';
  document.getElementById('mutTanggal').value = new Date().toISOString().slice(0,10);
  document.getElementById('mutasiAlert').innerHTML = '';

  // Populate FROM dropdown (from current assignments)
  const fromSel = document.getElementById('mutFrom');
  fromSel.innerHTML = currentAssignments.length === 0
    ? '<option value="">(belum ditugaskan ke outlet apapun)</option>'
    : currentAssignments.map(a => `<option value="${a.outlet_id}">${escapeHtml(a.nama_outlet)}</option>`).join('');

  // Populate TO dropdown (all outlets)
  const allOutlets = <?= json_encode($outletList) ?>;
  document.getElementById('mutTo').innerHTML = allOutlets
    .map(o => `<option value="${o.id}">${escapeHtml(o.nama_outlet)}</option>`).join('');

  openModal('mutasiModal');
}

async function submitMutasi(){
  const alertEl = document.getElementById('mutasiAlert');
  alertEl.innerHTML = '';
  const data = {
    karyawan_id: document.getElementById('mutKaryawanId').value,
    from_outlet_id: document.getElementById('mutFrom').value,
    to_outlet_id: document.getElementById('mutTo').value,
    tanggal_efektif: document.getElementById('mutTanggal').value,
    notes: document.getElementById('mutNotes').value,
  };
  if (!data.from_outlet_id) { alertEl.innerHTML = '<div class="alert error">Pilih outlet asal</div>'; return; }
  if (data.from_outlet_id === data.to_outlet_id) { alertEl.innerHTML = '<div class="alert error">Outlet asal dan tujuan tidak boleh sama</div>'; return; }

  const r = await fetch('/ERP/harpy/hq/karyawan.php?action=mutasi', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
    body: JSON.stringify(data),
  });
  const j = await r.json();
  if (j.error) { alertEl.innerHTML = '<div class="alert error">'+escapeHtml(j.error)+'</div>'; return; }
  alertEl.innerHTML = '<div class="alert success">✓ Mutasi berhasil</div>';
  setTimeout(() => { closeModal('mutasiModal'); loadList(); }, 800);
}

function openAdd(id, nama){
  closeModal('detailModal');
  document.getElementById('addKaryawanId').value = id;
  document.getElementById('addKaryawanName').value = nama;
  document.getElementById('addAlert').innerHTML = '';
  const allOutlets = <?= json_encode($outletList) ?>;
  document.getElementById('addOutletId').innerHTML = allOutlets
    .map(o => `<option value="${o.id}">${escapeHtml(o.nama_outlet)}</option>`).join('');
  openModal('addModal');
}

async function submitAdd(){
  const alertEl = document.getElementById('addAlert');
  alertEl.innerHTML = '';
  const data = {
    karyawan_id: document.getElementById('addKaryawanId').value,
    outlet_id: document.getElementById('addOutletId').value,
  };
  const r = await fetch('/ERP/harpy/hq/karyawan.php?action=add_assignment', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
    body: JSON.stringify(data),
  });
  const j = await r.json();
  if (j.error) { alertEl.innerHTML = '<div class="alert error">'+escapeHtml(j.error)+'</div>'; return; }
  alertEl.innerHTML = '<div class="alert success">✓ Penugasan ditambahkan</div>';
  setTimeout(() => { closeModal('addModal'); loadList(); }, 700);
}

async function removeAssignment(kid, oid, outletName){
  if (!confirm(`Cabut penugasan karyawan dari outlet "${outletName}"?`)) return;
  const r = await fetch('/ERP/harpy/hq/karyawan.php?action=remove_assignment', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
    body: JSON.stringify({karyawan_id: kid, outlet_id: oid}),
  });
  const j = await r.json();
  if (j.error) { alert(j.error); return; }
  closeModal('detailModal');
  loadList();
}

function openCreate(){
  // Reset form
  ['crNama','crUsername','crPassword','crTelepon','crEmail','crJabatan',
   'crNik','crKontrakMulai','crKontrakSelesai','crBankNama','crNoRek','crBankAtasnama']
   .forEach(id=>{ const el = document.getElementById(id); if (el) el.value=''; });
  document.getElementById('crRole').value = 'kasir';
  document.getElementById('crKontrakTipe').value = '';
  document.getElementById('createAlert').innerHTML = '';

  // Populate outlet checkbox list
  const allOutlets = <?= json_encode($outletList) ?>;
  document.getElementById('crOutletList').innerHTML = allOutlets.length === 0
    ? '<div style="color:#9CA3AF;font-size:12px">Belum ada outlet aktif. Tambahkan outlet dulu sebelum assign karyawan.</div>'
    : allOutlets.map(o => `
        <label style="display:flex;align-items:center;gap:8px;padding:5px 0;cursor:pointer;font-size:13px;color:#374151">
          <input type="checkbox" class="cr-outlet-cb" value="${o.id}" style="width:auto;margin:0">
          📍 ${escapeHtml(o.nama_outlet)}
        </label>
      `).join('');

  openModal('createModal');
}

async function submitCreate(){
  const alertEl = document.getElementById('createAlert');
  alertEl.innerHTML = '';

  const data = {
    nama: document.getElementById('crNama').value.trim(),
    username: document.getElementById('crUsername').value.trim(),
    password: document.getElementById('crPassword').value,
    role: document.getElementById('crRole').value,
    telepon: document.getElementById('crTelepon').value.trim(),
    email: document.getElementById('crEmail').value.trim(),
    jabatan: document.getElementById('crJabatan').value.trim(),
    nik: document.getElementById('crNik').value.trim(),
    kontrak_tipe: document.getElementById('crKontrakTipe').value,
    kontrak_mulai: document.getElementById('crKontrakMulai').value,
    kontrak_selesai: document.getElementById('crKontrakSelesai').value,
    bank_nama: document.getElementById('crBankNama').value.trim(),
    no_rekening: document.getElementById('crNoRek').value.trim(),
    bank_atasnama: document.getElementById('crBankAtasnama').value.trim(),
    outlet_ids: Array.from(document.querySelectorAll('.cr-outlet-cb:checked')).map(c => parseInt(c.value)),
  };

  if (!data.nama)     { alertEl.innerHTML = '<div class="alert error">Nama wajib diisi</div>'; return; }
  if (!data.username) { alertEl.innerHTML = '<div class="alert error">Username wajib diisi</div>'; return; }
  if (data.password.length < 6) { alertEl.innerHTML = '<div class="alert error">Password minimal 6 karakter</div>'; return; }

  const r = await fetch('/ERP/harpy/hq/karyawan.php?action=create', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
    body: JSON.stringify(data),
  });
  const j = await r.json();
  if (j.error) { alertEl.innerHTML = '<div class="alert error">'+escapeHtml(j.error)+'</div>'; return; }
  alertEl.innerHTML = '<div class="alert success">✓ Karyawan berhasil ditambahkan</div>';
  setTimeout(() => { closeModal('createModal'); loadList(); }, 800);
}

function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}

loadFilterOptions();
loadList();
</script>
<?php require __DIR__ . '/_layout_close.php'; ?>
