<?php
// ══════════════════════════════════════════════════════
// droppoint_manager.php — Manajemen Mitra Drop Point (ERP outlet)
//   - CRUD mitra
//   - Generate / reset akun login mitra (role='mitra')
//   - Generate rekap komisi periode
//   - Bayar komisi → insert hl_kas
//   - Order hari ini dari drop point
// ══════════════════════════════════════════════════════

$activePage = 'droppoint';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once __DIR__ . '/components.php';

$user = currentUser();
$tid  = TenantResolver::id();
$oid  = TenantResolver::outletId();

// Hanya owner/admin/manager outlet
$role = $user['role'] ?? 'staff';
if (!in_array($role, ['owner','superadmin','admin','manager'], true)) {
    http_response_code(403);
    die('Akses ditolak. Hanya owner/manager outlet.');
}

$action = $_GET['action'] ?? '';

// ══════════════════════════════════════════════════════
// API Handlers
// ══════════════════════════════════════════════════════
if ($action) {
    header('Content-Type: application/json');
    $db = Database::get();

    // ── LIST MITRA + stats ──
    if ($action === 'list_mitra') {
        try {
            $s = $db->prepare("
                SELECT dp.*,
                       (SELECT id FROM hl_users WHERE drop_point_id=dp.id AND tenant_id=dp.tenant_id LIMIT 1) AS user_id,
                       (SELECT username FROM hl_users WHERE drop_point_id=dp.id AND tenant_id=dp.tenant_id LIMIT 1) AS username,
                       (SELECT is_active FROM hl_users WHERE drop_point_id=dp.id AND tenant_id=dp.tenant_id LIMIT 1) AS user_active,
                       (SELECT COUNT(*) FROM hl_transaksi
                         WHERE tenant_id=dp.tenant_id AND drop_point_id=dp.id
                           AND DATE_FORMAT(tanggal,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')) AS order_bulan,
                       (SELECT MAX(created_at) FROM hl_transaksi
                         WHERE tenant_id=dp.tenant_id AND drop_point_id=dp.id) AS last_order
                  FROM hl_drop_points dp
                 WHERE dp.tenant_id=? AND dp.outlet_id=?
                 ORDER BY dp.status='nonaktif' ASC, dp.nama_mitra ASC
            ");
            $s->execute([$tid, $oid]);
            echo json_encode(['ok'=>true, 'mitra'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    // ── SAVE MITRA (create / update) ──
    if ($action === 'save_mitra' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id    = (int)($d['id'] ?? 0);
        $nama  = substr(trim(strip_tags($d['nama_mitra'] ?? '')), 0, 100);
        $alamat= substr(trim(strip_tags($d['alamat'] ?? '')), 0, 500);
        $wa    = substr(preg_replace('/[^0-9+\-\s]/','', $d['wa'] ?? ''), 0, 20);
        $model = in_array($d['komisi_model'] ?? 'per_kg', ['per_kg','persen','flat','kombinasi'], true) ? $d['komisi_model'] : 'per_kg';
        $perKg = max(0, (int)($d['komisi_per_kg'] ?? 0));
        $persen= max(0, (float)($d['komisi_persen'] ?? 0));
        $flat  = max(0, (int)($d['komisi_flat'] ?? 0));
        $periode = in_array($d['periode_rekap'] ?? 'bulanan', ['mingguan','bulanan'], true) ? $d['periode_rekap'] : 'bulanan';
        $status  = ($d['status'] ?? 'aktif') === 'nonaktif' ? 'nonaktif' : 'aktif';

        if (!$nama) { echo json_encode(['error'=>'Nama mitra wajib diisi']); exit; }

        try {
            if ($id) {
                $db->prepare("UPDATE hl_drop_points SET
                                nama_mitra=?, alamat=?, wa=?, komisi_model=?, komisi_per_kg=?, komisi_persen=?, komisi_flat=?,
                                periode_rekap=?, status=?
                              WHERE id=? AND tenant_id=? AND outlet_id=?")
                   ->execute([$nama, $alamat ?: null, $wa ?: null, $model, $perKg, $persen, $flat,
                              $periode, $status, $id, $tid, $oid]);
                logAudit('update','droppoint',"Edit mitra: $nama", (string)$id);
                echo json_encode(['ok'=>true, 'id'=>$id]);
            } else {
                $db->prepare("INSERT INTO hl_drop_points
                                (tenant_id, outlet_id, nama_mitra, alamat, wa, komisi_model, komisi_per_kg, komisi_persen, komisi_flat,
                                 periode_rekap, status)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$tid, $oid, $nama, $alamat ?: null, $wa ?: null, $model, $perKg, $persen, $flat, $periode, $status]);
                $newId = (int)$db->lastInsertId();
                logAudit('create','droppoint',"Tambah mitra: $nama", (string)$newId);
                echo json_encode(['ok'=>true, 'id'=>$newId]);
            }
        } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    // ── GENERATE / RESET AKUN MITRA ──
    if ($action === 'gen_account' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $dpId = (int)($d['drop_point_id'] ?? 0);
        if (!$dpId) { echo json_encode(['error'=>'drop_point_id wajib']); exit; }

        try {
            // Verifikasi mitra milik outlet
            $vs = $db->prepare("SELECT id, nama_mitra FROM hl_drop_points WHERE id=? AND tenant_id=? AND outlet_id=?");
            $vs->execute([$dpId, $tid, $oid]);
            $dp = $vs->fetch(PDO::FETCH_ASSOC);
            if (!$dp) { echo json_encode(['error'=>'Mitra tidak ditemukan']); exit; }

            // Generate credentials
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $dp['nama_mitra']));
            $slug = trim($slug, '-');
            $rand = strtolower(bin2hex(random_bytes(2)));
            $username = "mitra_{$slug}_{$rand}";

            // Random password 8 char alphanumeric
            $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789abcdefghjkmnpqrstuvwxyz';
            $password = '';
            for ($i=0;$i<8;$i++) $password .= $alphabet[random_int(0, strlen($alphabet)-1)];
            $hash = password_hash($password, PASSWORD_DEFAULT);

            // Cek akun existing
            $ex = $db->prepare("SELECT id FROM hl_users WHERE drop_point_id=? AND tenant_id=? LIMIT 1");
            $ex->execute([$dpId, $tid]);
            $existId = (int)$ex->fetchColumn();

            if ($existId) {
                // Reset password (jangan ganti username)
                $db->prepare("UPDATE hl_users SET password=?, is_active=1 WHERE id=?")
                   ->execute([$hash, $existId]);
                $cur = $db->prepare("SELECT username FROM hl_users WHERE id=?");
                $cur->execute([$existId]);
                $username = $cur->fetchColumn();
                logAudit('reset_password','droppoint',"Reset akun mitra: ".$dp['nama_mitra'], (string)$dpId);
                echo json_encode(['ok'=>true, 'mode'=>'reset', 'username'=>$username, 'password'=>$password]);
            } else {
                // Pastikan username unik (retry kalau bentrok)
                for ($try=0;$try<5;$try++) {
                    $chk = $db->prepare("SELECT 1 FROM hl_users WHERE username=? LIMIT 1");
                    $chk->execute([$username]);
                    if (!$chk->fetchColumn()) break;
                    $rand = strtolower(bin2hex(random_bytes(2)));
                    $username = "mitra_{$slug}_{$rand}";
                }
                $db->prepare("INSERT INTO hl_users
                                (tenant_id, outlet_id, drop_point_id, username, password, nama, role, is_active, created_at)
                              VALUES (?,?,?,?,?,?,'mitra',1,NOW())")
                   ->execute([$tid, $oid, $dpId, $username, $hash, 'Mitra '.$dp['nama_mitra']]);
                logAudit('create_account','droppoint',"Buat akun mitra: ".$dp['nama_mitra'], (string)$dpId);
                echo json_encode(['ok'=>true, 'mode'=>'create', 'username'=>$username, 'password'=>$password]);
            }
        } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    // ── TOGGLE active akun mitra ──
    if ($action === 'toggle_account' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $dpId = (int)($d['drop_point_id'] ?? 0);
        $aktif = !empty($d['aktif']) ? 1 : 0;
        try {
            $db->prepare("UPDATE hl_users SET is_active=? WHERE drop_point_id=? AND tenant_id=?")
               ->execute([$aktif, $dpId, $tid]);
            logAudit('toggle_account','droppoint',($aktif?'Aktifkan':'Nonaktifkan')." akun mitra #$dpId", (string)$dpId);
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    // ── LIST REKAP + komisi periode ──
    if ($action === 'list_rekap') {
        // Periode opsional (default: bulan ini)
        $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start'] ?? '') ? $_GET['start'] : date('Y-m-01');
        $end   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end'] ?? '')   ? $_GET['end']   : date('Y-m-d');

        try {
            $s = $db->prepare("SELECT * FROM hl_drop_points WHERE tenant_id=? AND outlet_id=? AND status='aktif' ORDER BY nama_mitra");
            $s->execute([$tid,$oid]);
            $mitras = $s->fetchAll(PDO::FETCH_ASSOC);
            $rows = [];
            foreach ($mitras as $m) {
                $row = ['drop_point_id'=>(int)$m['id'], 'nama'=>$m['nama_mitra'],
                        'model'=>$m['komisi_model'], 'order'=>0,'kg'=>0.0,'omset'=>0,'komisi'=>0];

                $o = $db->prepare("SELECT COUNT(*) c, COALESCE(SUM(total),0) omset
                                     FROM hl_transaksi WHERE tenant_id=? AND drop_point_id=? AND DATE(tanggal) BETWEEN ? AND ?");
                $o->execute([$tid,$m['id'],$start,$end]);
                $or = $o->fetch(PDO::FETCH_ASSOC);
                $row['order'] = (int)$or['c']; $row['omset'] = (int)$or['omset'];

                $k = $db->prepare("SELECT COALESCE(SUM(ti.jumlah),0) FROM hl_transaksi t
                                    JOIN hl_transaksi_item ti ON ti.transaksi_id=t.id
                                         AND ti.id=(SELECT MIN(id) FROM hl_transaksi_item WHERE transaksi_id=t.id)
                                   WHERE t.tenant_id=? AND t.drop_point_id=? AND DATE(t.tanggal) BETWEEN ? AND ?");
                $k->execute([$tid,$m['id'],$start,$end]);
                $row['kg'] = (float)$k->fetchColumn();

                // Hitung komisi
                $komisi = 0;
                if ($m['komisi_model']==='per_kg' || $m['komisi_model']==='kombinasi')
                    $komisi += (int)$m['komisi_per_kg'] * (int)round($row['kg']);
                if ($m['komisi_model']==='persen' || $m['komisi_model']==='kombinasi')
                    $komisi += (int)round((float)$m['komisi_persen']/100 * $row['omset']);
                if ($m['komisi_model']==='flat' || $m['komisi_model']==='kombinasi')
                    $komisi += (int)$m['komisi_flat'] * $row['order'];
                $row['komisi'] = $komisi;

                // Status rekap (apakah sudah di-generate untuk periode ini)
                $r = $db->prepare("SELECT id, status FROM hl_komisi_rekap
                                    WHERE tenant_id=? AND drop_point_id=? AND periode_start=? AND periode_end=? LIMIT 1");
                $r->execute([$tid,$m['id'],$start,$end]);
                $rr = $r->fetch(PDO::FETCH_ASSOC);
                $row['rekap_id'] = $rr ? (int)$rr['id'] : null;
                $row['rekap_status'] = $rr['status'] ?? null;

                $rows[] = $row;
            }
            echo json_encode(['ok'=>true, 'periode'=>['start'=>$start,'end'=>$end], 'rows'=>$rows]);
        } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    // ── GENERATE REKAP (upsert ke hl_komisi_rekap untuk semua mitra periode) ──
    if ($action === 'generate_rekap' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['start'] ?? '') ? $d['start'] : date('Y-m-01');
        $end   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['end'] ?? '')   ? $d['end']   : date('Y-m-d');

        try {
            $s = $db->prepare("SELECT * FROM hl_drop_points WHERE tenant_id=? AND outlet_id=? AND status='aktif'");
            $s->execute([$tid,$oid]);
            $created = 0; $updated = 0;
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $o = $db->prepare("SELECT COUNT(*) c, COALESCE(SUM(total),0) omset
                                     FROM hl_transaksi WHERE tenant_id=? AND drop_point_id=? AND DATE(tanggal) BETWEEN ? AND ?");
                $o->execute([$tid,$m['id'],$start,$end]);
                $or = $o->fetch(PDO::FETCH_ASSOC);
                $k = $db->prepare("SELECT COALESCE(SUM(ti.jumlah),0) FROM hl_transaksi t
                                    JOIN hl_transaksi_item ti ON ti.transaksi_id=t.id
                                         AND ti.id=(SELECT MIN(id) FROM hl_transaksi_item WHERE transaksi_id=t.id)
                                   WHERE t.tenant_id=? AND t.drop_point_id=? AND DATE(t.tanggal) BETWEEN ? AND ?");
                $k->execute([$tid,$m['id'],$start,$end]);
                $kg = (float)$k->fetchColumn();
                $omset = (int)$or['omset']; $orderC = (int)$or['c'];

                $komisi = 0;
                if ($m['komisi_model']==='per_kg' || $m['komisi_model']==='kombinasi')
                    $komisi += (int)$m['komisi_per_kg'] * (int)round($kg);
                if ($m['komisi_model']==='persen' || $m['komisi_model']==='kombinasi')
                    $komisi += (int)round((float)$m['komisi_persen']/100 * $omset);
                if ($m['komisi_model']==='flat' || $m['komisi_model']==='kombinasi')
                    $komisi += (int)$m['komisi_flat'] * $orderC;

                // Upsert (UNIQUE on tenant+drop_point+start+end)
                $stmt = $db->prepare("INSERT INTO hl_komisi_rekap
                    (tenant_id, outlet_id, drop_point_id, periode_start, periode_end,
                     total_order, total_kg, total_omset, total_komisi, status)
                    VALUES (?,?,?,?,?,?,?,?,?,'pending')
                    ON DUPLICATE KEY UPDATE
                      total_order=VALUES(total_order), total_kg=VALUES(total_kg),
                      total_omset=VALUES(total_omset), total_komisi=VALUES(total_komisi)
                      /* tidak override status='dibayar' karena WHERE-able? — pakai logic CASE: */
                ");
                // Tidak update kalau status sudah dibayar
                $chk = $db->prepare("SELECT id, status FROM hl_komisi_rekap WHERE tenant_id=? AND drop_point_id=? AND periode_start=? AND periode_end=?");
                $chk->execute([$tid, $m['id'], $start, $end]);
                $exist = $chk->fetch(PDO::FETCH_ASSOC);
                if ($exist && $exist['status'] === 'dibayar') { continue; }

                $stmt->execute([$tid, $oid, $m['id'], $start, $end, $orderC, $kg, $omset, $komisi]);
                if ($exist) $updated++; else $created++;
            }
            logAudit('generate_rekap','droppoint',"Generate rekap $start s/d $end ($created baru, $updated update)");
            echo json_encode(['ok'=>true, 'created'=>$created, 'updated'=>$updated]);
        } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    // ── BAYAR KOMISI (1 rekap atau semua pending periode) ──
    if ($action === 'bayar' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $rekapId = (int)($d['rekap_id'] ?? 0);
        $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['start'] ?? '') ? $d['start'] : null;
        $end   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['end'] ?? '')   ? $d['end']   : null;

        try {
            $db->beginTransaction();
            $sql = "SELECT r.*, dp.nama_mitra, dp.wa FROM hl_komisi_rekap r
                      JOIN hl_drop_points dp ON dp.id=r.drop_point_id
                     WHERE r.tenant_id=? AND r.outlet_id=? AND r.status='pending'";
            $params = [$tid, $oid];
            if ($rekapId) { $sql .= " AND r.id=?"; $params[] = $rekapId; }
            elseif ($start && $end) { $sql .= " AND r.periode_start=? AND r.periode_end=?"; $params[] = $start; $params[] = $end; }
            else { $db->rollBack(); echo json_encode(['error'=>'Periode atau rekap_id wajib']); exit; }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rekaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$rekaps) { $db->rollBack(); echo json_encode(['error'=>'Tidak ada rekap pending']); exit; }

            $paid = []; $totalBayar = 0;
            foreach ($rekaps as $r) {
                $komisi = (int)$r['total_komisi'];
                if ($komisi <= 0) continue;
                $ket = "Komisi mitra {$r['nama_mitra']} periode ".date('d M',strtotime($r['periode_start'])).
                       "–".date('d M Y',strtotime($r['periode_end']));

                // Insert kas keluar
                $kasIns = $db->prepare("INSERT INTO hl_kas
                    (tenant_id, outlet_id, tanggal, tipe, kategori, keterangan, jumlah, created_by, created_at)
                    VALUES (?,?,?, 'keluar', 'Komisi Mitra', ?, ?, ?, NOW())");
                $kasIns->execute([$tid,$oid,date('Y-m-d'),$ket,$komisi,$user['id']]);
                $kasId = (int)$db->lastInsertId();

                // Update rekap status
                $db->prepare("UPDATE hl_komisi_rekap
                                 SET status='dibayar', dibayar_at=NOW(), kas_id=?
                               WHERE id=? AND tenant_id=?")
                   ->execute([$kasId, $r['id'], $tid]);

                $totalBayar += $komisi;

                // Generate WA link
                $waUrl = '';
                if ($r['wa']) {
                    $p = preg_replace('/[^0-9]/','',$r['wa']);
                    if (strpos($p,'0')===0) $p='62'.substr($p,1);
                    elseif (strpos($p,'62')!==0) $p='62'.$p;
                    $txt = "Halo {$r['nama_mitra']}, komisi periode ".date('d M',strtotime($r['periode_start'])).
                           "–".date('d M Y',strtotime($r['periode_end'])).
                           " sebesar *Rp ".number_format($komisi,0,',','.')."* sudah kami transfer ya! Terima kasih 🙏";
                    $waUrl = "https://wa.me/$p?text=".urlencode($txt);
                }
                $paid[] = ['rekap_id'=>(int)$r['id'], 'nama'=>$r['nama_mitra'], 'komisi'=>$komisi, 'wa'=>$waUrl];
            }
            $db->commit();
            logAudit('bayar_komisi','droppoint',"Bayar komisi ".count($paid)." mitra, total Rp ".number_format($totalBayar,0,',','.'));
            echo json_encode(['ok'=>true, 'paid'=>$paid, 'total'=>$totalBayar]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['error'=>$e->getMessage()]);
        }
        exit;
    }

    // ── ORDERS HARI INI dari drop point (semua mitra outlet ini) ──
    if ($action === 'orders_today') {
        try {
            $s = $db->prepare("
                SELECT t.id, t.no_order, t.nama_pelanggan, t.telepon, t.total, t.status_proses,
                       t.created_at, dp.nama_mitra
                  FROM hl_transaksi t
                  JOIN hl_drop_points dp ON dp.id=t.drop_point_id
                 WHERE t.tenant_id=? AND t.outlet_id=? AND t.drop_point_id IS NOT NULL
                   AND DATE(t.tanggal)=?
                 ORDER BY t.id DESC
            ");
            $s->execute([$tid, $oid, date('Y-m-d')]);
            echo json_encode(['ok'=>true, 'orders'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    // ── KONFIRMASI PICKUP (set order ke 'cuci') ──
    if ($action === 'confirm_pickup' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $orderId = (int)($d['order_id'] ?? 0);
        try {
            $db->prepare("UPDATE hl_transaksi SET status_proses='cuci'
                          WHERE id=? AND tenant_id=? AND outlet_id=? AND drop_point_id IS NOT NULL AND status_proses='masuk'")
               ->execute([$orderId, $tid, $oid]);
            logAudit('confirm_pickup','droppoint',"Konfirmasi pickup order #$orderId", (string)$orderId);
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    echo json_encode(['error'=>'Unknown action']); exit;
}

// ══════════════════════════════════════════════════════
// VIEW
// ══════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Drop Point Manager'); ?>
<style>
.dp-tabs{display:flex;gap:6px;margin-bottom:16px;background:#fff;border-radius:10px;padding:5px;box-shadow:0 1px 4px rgba(0,0,0,.04);width:fit-content;max-width:100%;overflow-x:auto}
.dp-tab{padding:8px 18px;border-radius:7px;font-size:13px;font-weight:700;color:#6B7280;cursor:pointer;border:none;background:transparent;font-family:inherit;white-space:nowrap}
.dp-tab.active{background:#0F1C3A;color:#fff}
.panel{background:#fff;border-radius:12px;padding:18px 20px;box-shadow:0 1px 4px rgba(0,0,0,.05);margin-bottom:16px}
.panel-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px}
.panel-head h2{font-size:15px;font-weight:800;color:#0F1C3A}
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{background:#F7F8FC;padding:9px 11px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;color:#6B7280}
.tbl td{padding:10px 11px;border-top:1px solid #F0F1F4}
.tbl .num{font-family:monospace;font-weight:700;text-align:right}
.btn{padding:7px 14px;border-radius:7px;font-weight:700;font-size:12px;border:none;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:5px}
.btn-primary{background:#0F1C3A;color:#fff}.btn-primary:hover{background:#1a2d52}
.btn-light{background:#fff;color:#0F1C3A;border:1px solid #E5E9F2}
.btn-green{background:#10B981;color:#fff}.btn-green:hover{background:#059669}
.btn-wa{background:#25D366;color:#fff}
.btn-sm{padding:5px 10px;font-size:11px}
.pill{font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px}
.pill-aktif{background:#D1FAE5;color:#065F46}
.pill-nonaktif{background:#F3F4F6;color:#6B7280}
.modal-bg{display:none;position:fixed;inset:0;background:rgba(15,28,58,.5);z-index:1000;align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto}
.modal-bg.open{display:flex}
.modal{background:#fff;border-radius:14px;width:100%;max-width:540px;padding:24px}
.modal h3{font-size:16px;font-weight:800;color:#0F1C3A;margin-bottom:14px}
.fld{margin-bottom:12px}
.fld label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}
.fld input,.fld select,.fld textarea{width:100%;padding:9px 12px;border:1px solid #E5E9F2;border-radius:8px;font-family:inherit;font-size:14px}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.creds{background:#FEF3C7;border:1px solid #FDE68A;border-radius:10px;padding:14px;margin:10px 0;font-family:monospace;font-size:13px}
.creds strong{color:#0F1C3A;font-family:'Plus Jakarta Sans',sans-serif}
.empty{text-align:center;padding:30px;color:#9CA3AF;font-size:13px}
</style>
</head>
<body>
<?php renderTopbar('droppoint'); ?>

<h1 style="font-size:1.3rem;font-weight:800;color:#0F1C3A;margin-bottom:14px">📦 Manajemen Mitra Drop Point</h1>

<div class="dp-tabs">
  <button class="dp-tab active" id="tabMitra"  onclick="switchTab('mitra')">👥 Mitra</button>
  <button class="dp-tab" id="tabRekap"  onclick="switchTab('rekap')">💰 Rekap Komisi</button>
  <button class="dp-tab" id="tabOrders" onclick="switchTab('orders')">📋 Order Hari Ini</button>
</div>

<!-- TAB MITRA -->
<div id="paneMitra">
  <div class="panel">
    <div class="panel-head">
      <h2>Daftar Mitra</h2>
      <button class="btn btn-primary" onclick="openMitra()">+ Tambah Mitra</button>
    </div>
    <div id="mitraBox"><div class="empty">⏳ Memuat…</div></div>
  </div>
</div>

<!-- TAB REKAP -->
<div id="paneRekap" style="display:none">
  <div class="panel">
    <div class="panel-head">
      <h2>Rekap Komisi Mitra</h2>
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <input type="date" id="rStart" value="<?= date('Y-m-01') ?>">
        <input type="date" id="rEnd"   value="<?= date('Y-m-d') ?>">
        <button class="btn btn-light btn-sm" onclick="loadRekap()">↻ Refresh</button>
        <button class="btn btn-primary btn-sm" onclick="generateRekap()">⚙️ Generate Rekap</button>
        <button class="btn btn-green btn-sm" onclick="bayarSemua()">💸 Bayar Semua</button>
      </div>
    </div>
    <div id="rekapBox"><div class="empty">⏳ Memuat…</div></div>
  </div>
</div>

<!-- TAB ORDERS -->
<div id="paneOrders" style="display:none">
  <div class="panel">
    <div class="panel-head"><h2>Order Drop Point Hari Ini</h2>
      <button class="btn btn-light btn-sm" onclick="loadOrders()">↻ Refresh</button>
    </div>
    <div id="ordersBox"><div class="empty">⏳ Memuat…</div></div>
  </div>
</div>

<!-- MITRA MODAL -->
<div class="modal-bg" id="mitraModal">
  <div class="modal">
    <h3 id="mTitle">Tambah Mitra</h3>
    <input type="hidden" id="mId">
    <div class="fld"><label>Nama Mitra *</label><input type="text" id="mNama" maxlength="100"></div>
    <div class="fld-row">
      <div class="fld"><label>WA</label><input type="tel" id="mWa" maxlength="20" placeholder="08xxx"></div>
      <div class="fld"><label>Status</label><select id="mStatus"><option value="aktif">Aktif</option><option value="nonaktif">Nonaktif</option></select></div>
    </div>
    <div class="fld"><label>Alamat</label><textarea id="mAlamat" rows="2" maxlength="500"></textarea></div>
    <div class="fld">
      <label>Model Komisi</label>
      <select id="mModel" onchange="updateKomisiFields()">
        <option value="per_kg">Per kg</option>
        <option value="persen">% dari omset</option>
        <option value="flat">Flat per order</option>
        <option value="kombinasi">Kombinasi (sum semua)</option>
      </select>
    </div>
    <div class="fld-row">
      <div class="fld" data-fld="per_kg"><label>Komisi per kg (Rp)</label><input type="number" id="mPerKg" min="0" value="0"></div>
      <div class="fld" data-fld="persen"><label>Persen (%)</label><input type="number" id="mPersen" min="0" max="100" step="0.5" value="0"></div>
    </div>
    <div class="fld-row">
      <div class="fld" data-fld="flat"><label>Flat per order (Rp)</label><input type="number" id="mFlat" min="0" value="0"></div>
      <div class="fld"><label>Periode Rekap</label><select id="mPeriode"><option value="bulanan">Bulanan</option><option value="mingguan">Mingguan</option></select></div>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
      <button class="btn btn-light" onclick="closeModal('mitraModal')">Batal</button>
      <button class="btn btn-primary" onclick="saveMitra()">Simpan</button>
    </div>
  </div>
</div>

<!-- CREDENTIALS MODAL -->
<div class="modal-bg" id="credsModal">
  <div class="modal">
    <h3>🔑 Akun Login Mitra</h3>
    <p style="font-size:13px;color:#6B7280;margin-bottom:8px" id="credsSub"></p>
    <div class="creds" id="credsBox"></div>
    <p style="font-size:11px;color:#92400E;background:#FEF3C7;padding:8px 11px;border-radius:7px">
      ⚠️ <strong>Simpan sekarang!</strong> Password tidak bisa dilihat lagi setelah modal ini ditutup.
      Reset password kapan saja dari tombol "Akun".
    </p>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
      <a id="credsWa" class="btn btn-wa" target="_blank">💬 Kirim via WA</a>
      <button class="btn btn-primary" onclick="closeModal('credsModal')">Selesai</button>
    </div>
  </div>
</div>

<?php renderToast(); ?>
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const fmtRp = n => 'Rp ' + Number(n||0).toLocaleString('id-ID');
let curMitra = null;

function switchTab(t){
  ['mitra','rekap','orders'].forEach(x => {
    document.getElementById('tab'+x.charAt(0).toUpperCase()+x.slice(1)).classList.toggle('active', x===t);
    document.getElementById('pane'+x.charAt(0).toUpperCase()+x.slice(1)).style.display = x===t?'block':'none';
  });
  if (t==='rekap')  loadRekap();
  if (t==='orders') loadOrders();
}
function closeModal(id){ document.getElementById(id).classList.remove('open'); }

// ── Mitra ──
async function loadMitra(){
  const box = document.getElementById('mitraBox');
  try {
    const r = await fetch('droppoint_manager.php?action=list_mitra');
    const d = await r.json();
    if (d.error){ box.innerHTML = `<div class="empty">⚠️ ${esc(d.error)}</div>`; return; }
    if (!d.mitra.length){ box.innerHTML = '<div class="empty">Belum ada mitra. Klik <strong>+ Tambah Mitra</strong></div>'; return; }
    let html = '<div style="overflow-x:auto"><table class="tbl hl-stack-mobile"><thead><tr><th>Mitra</th><th>WA</th><th>Komisi</th><th>Order bln ini</th><th>Akun</th><th>Status</th><th></th></tr></thead><tbody>';
    d.mitra.forEach(m => {
      const komisi = m.komisi_model==='per_kg'   ? `Rp ${Number(m.komisi_per_kg).toLocaleString('id-ID')}/kg`
                   : m.komisi_model==='persen'   ? `${m.komisi_persen}% omset`
                   : m.komisi_model==='flat'     ? `Rp ${Number(m.komisi_flat).toLocaleString('id-ID')}/order`
                   : `Kombinasi`;
      const akunHtml = m.username
        ? `<span style="font-family:monospace;font-size:11px">${esc(m.username)}</span><br>
           <small style="color:${m.user_active==1?'#10B981':'#9CA3AF'}">${m.user_active==1?'aktif':'nonaktif'}</small>`
        : '<small style="color:#9CA3AF">belum dibuat</small>';
      html += `<tr>
        <td data-lbl="Mitra"><strong>${esc(m.nama_mitra)}</strong>${m.alamat?`<br><small style="color:#9CA3AF">${esc(m.alamat).substring(0,40)}</small>`:''}</td>
        <td data-lbl="WA" style="font-family:monospace;font-size:11px">${esc(m.wa||'-')}</td>
        <td data-lbl="Komisi">${komisi}</td>
        <td data-lbl="Order bln ini" style="text-align:center">${m.order_bulan}</td>
        <td data-lbl="Akun">${akunHtml}</td>
        <td data-lbl="Status"><span class="pill pill-${m.status}">${m.status}</span></td>
        <td style="white-space:nowrap">
          <button class="btn btn-light btn-sm" onclick='openMitra(${JSON.stringify(m)})'>✏️ Edit</button>
          <button class="btn btn-light btn-sm" onclick="genAccount(${m.id}, ${JSON.stringify(m.nama_mitra)}, ${JSON.stringify(m.wa||'')})">🔑 Akun</button>
          ${m.username?`<button class="btn btn-light btn-sm" onclick="toggleAccount(${m.id}, ${m.user_active==1?0:1})">${m.user_active==1?'🔒 Nonaktif':'✓ Aktifkan'}</button>`:''}
        </td>
      </tr>`;
    });
    html += '</tbody></table></div>';
    box.innerHTML = html;
  } catch(e){ box.innerHTML = `<div class="empty">⚠️ ${esc(e.message)}</div>`; }
}

function openMitra(m){
  curMitra = m || null;
  document.getElementById('mTitle').textContent = m ? 'Edit Mitra' : 'Tambah Mitra';
  document.getElementById('mId').value      = m ? m.id : '';
  document.getElementById('mNama').value    = m ? m.nama_mitra : '';
  document.getElementById('mWa').value      = m ? (m.wa||'') : '';
  document.getElementById('mAlamat').value  = m ? (m.alamat||'') : '';
  document.getElementById('mModel').value   = m ? m.komisi_model : 'per_kg';
  document.getElementById('mPerKg').value   = m ? m.komisi_per_kg : 0;
  document.getElementById('mPersen').value  = m ? m.komisi_persen : 0;
  document.getElementById('mFlat').value    = m ? m.komisi_flat : 0;
  document.getElementById('mPeriode').value = m ? m.periode_rekap : 'bulanan';
  document.getElementById('mStatus').value  = m ? m.status : 'aktif';
  updateKomisiFields();
  document.getElementById('mitraModal').classList.add('open');
}
function updateKomisiFields(){
  const model = document.getElementById('mModel').value;
  document.querySelectorAll('[data-fld]').forEach(el => {
    const fld = el.getAttribute('data-fld');
    el.style.display = (model === fld || model === 'kombinasi') ? '' : 'none';
  });
}

async function saveMitra(){
  const body = {
    id: document.getElementById('mId').value,
    nama_mitra: document.getElementById('mNama').value,
    alamat:     document.getElementById('mAlamat').value,
    wa:         document.getElementById('mWa').value,
    komisi_model: document.getElementById('mModel').value,
    komisi_per_kg: document.getElementById('mPerKg').value,
    komisi_persen: document.getElementById('mPersen').value,
    komisi_flat:   document.getElementById('mFlat').value,
    periode_rekap: document.getElementById('mPeriode').value,
    status:        document.getElementById('mStatus').value,
  };
  try {
    const r = await fetch('droppoint_manager.php?action=save_mitra', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body: JSON.stringify(body)
    });
    const d = await r.json();
    if (d.error){ showToast('⚠️ '+d.error,'error'); return; }
    closeModal('mitraModal'); showToast('✅ Tersimpan','success'); loadMitra();
  } catch(e){ showToast('Gagal: '+e.message,'error'); }
}

async function genAccount(dpId, nama, wa){
  if (!confirm(`Buat/reset akun login untuk "${nama}"?\nPassword baru akan di-generate dan password lama tidak berlaku.`)) return;
  try {
    const r = await fetch('droppoint_manager.php?action=gen_account', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body: JSON.stringify({drop_point_id: dpId})
    });
    const d = await r.json();
    if (d.error){ showToast('⚠️ '+d.error,'error'); return; }
    document.getElementById('credsSub').textContent = `${d.mode==='create'?'Akun baru dibuat':'Password di-reset'} untuk ${nama}`;
    document.getElementById('credsBox').innerHTML = `
      <strong>Username:</strong> ${esc(d.username)}<br>
      <strong>Password:</strong> ${esc(d.password)}<br>
      <strong>URL:</strong> /ERP/harpy/login.php
    `;
    if (wa){
      const p = (''+wa).replace(/[^0-9]/g,'').replace(/^0/,'62');
      const txt = `Halo ${nama}, ini akun login portal mitra:\n\nUsername: ${d.username}\nPassword: ${d.password}\nLogin di: https://harpylaundry.id/ERP/harpy/login.php\n\nSilakan login & ganti password kalau perlu. Terima kasih!`;
      document.getElementById('credsWa').href = `https://wa.me/${p.startsWith('62')?p:'62'+p}?text=${encodeURIComponent(txt)}`;
      document.getElementById('credsWa').style.display = '';
    } else {
      document.getElementById('credsWa').style.display = 'none';
    }
    document.getElementById('credsModal').classList.add('open');
    loadMitra();
  } catch(e){ showToast('Gagal: '+e.message,'error'); }
}

async function toggleAccount(dpId, aktif){
  if (!confirm(aktif==1?'Aktifkan akun mitra?':'Nonaktifkan akun mitra? Mitra tidak bisa login lagi.')) return;
  try {
    await fetch('droppoint_manager.php?action=toggle_account', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body: JSON.stringify({drop_point_id: dpId, aktif})
    });
    loadMitra();
  } catch(e){}
}

// ── Rekap ──
async function loadRekap(){
  const start = document.getElementById('rStart').value;
  const end   = document.getElementById('rEnd').value;
  const box = document.getElementById('rekapBox');
  box.innerHTML = '<div class="empty">⏳ Menghitung…</div>';
  try {
    const r = await fetch(`droppoint_manager.php?action=list_rekap&start=${start}&end=${end}`);
    const d = await r.json();
    if (d.error){ box.innerHTML = `<div class="empty">⚠️ ${esc(d.error)}</div>`; return; }
    if (!d.rows.length){ box.innerHTML = '<div class="empty">Tidak ada mitra aktif.</div>'; return; }
    let totOrder=0,totKg=0,totOmset=0,totKomisi=0;
    let html = '<div style="overflow-x:auto"><table class="tbl hl-stack-mobile"><thead><tr><th>Mitra</th><th style="text-align:right">Order</th><th style="text-align:right">Kg</th><th style="text-align:right">Omset</th><th style="text-align:right">Komisi</th><th>Status</th><th></th></tr></thead><tbody>';
    d.rows.forEach(r => {
      totOrder+=r.order; totKg+=Number(r.kg); totOmset+=r.omset; totKomisi+=r.komisi;
      const stat = r.rekap_status==='dibayar' ? '<span class="pill pill-aktif">dibayar</span>'
                 : r.rekap_status==='pending' ? '<span class="pill" style="background:#FEF3C7;color:#92400E">pending</span>'
                 : '<span class="pill pill-nonaktif">live</span>';
      html += `<tr>
        <td data-lbl="Mitra"><strong>${esc(r.nama)}</strong></td>
        <td data-lbl="Order" class="num">${r.order}</td>
        <td data-lbl="Kg" class="num">${Number(r.kg).toFixed(1)}</td>
        <td data-lbl="Omset" class="num">${fmtRp(r.omset)}</td>
        <td data-lbl="Komisi" class="num" style="color:#0F1C3A">${fmtRp(r.komisi)}</td>
        <td data-lbl="Status">${stat}</td>
        <td>${r.rekap_id && r.rekap_status==='pending' && r.komisi>0
          ? `<button class="btn btn-green btn-sm" onclick="bayarSatu(${r.rekap_id})">💸 Bayar</button>`
          : ''}</td>
      </tr>`;
    });
    html += `<tr style="background:#F7F8FC;font-weight:800">
      <td>TOTAL</td><td class="num">${totOrder}</td><td class="num">${totKg.toFixed(1)}</td>
      <td class="num">${fmtRp(totOmset)}</td><td class="num">${fmtRp(totKomisi)}</td><td></td><td></td></tr>`;
    html += '</tbody></table></div>';
    box.innerHTML = html;
  } catch(e){ box.innerHTML = `<div class="empty">⚠️ ${esc(e.message)}</div>`; }
}

async function generateRekap(){
  const start = document.getElementById('rStart').value;
  const end   = document.getElementById('rEnd').value;
  if (!confirm(`Generate rekap komisi periode ${start} s/d ${end}?\nRekap yang sudah 'dibayar' tidak akan ditimpa.`)) return;
  try {
    const r = await fetch('droppoint_manager.php?action=generate_rekap', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body: JSON.stringify({start, end})
    });
    const d = await r.json();
    if (d.error){ showToast('⚠️ '+d.error,'error'); return; }
    showToast(`✅ Rekap: ${d.created} baru, ${d.updated} update`,'success');
    loadRekap();
  } catch(e){ showToast('Gagal: '+e.message,'error'); }
}

async function bayarSatu(rekapId){
  if (!confirm('Bayar komisi mitra ini sekarang? Akan otomatis masuk Kas Keluar.')) return;
  doBayar({rekap_id: rekapId});
}
async function bayarSemua(){
  const start = document.getElementById('rStart').value;
  const end   = document.getElementById('rEnd').value;
  if (!confirm(`Bayar SEMUA komisi pending periode ${start} s/d ${end}?\nAkan masuk Kas Keluar kategori "Komisi Mitra".`)) return;
  doBayar({start, end});
}
async function doBayar(body){
  try {
    const r = await fetch('droppoint_manager.php?action=bayar', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body: JSON.stringify(body)
    });
    const d = await r.json();
    if (d.error){ showToast('⚠️ '+d.error,'error'); return; }
    let msg = `✅ ${d.paid.length} komisi dibayar (total Rp ${Number(d.total).toLocaleString('id-ID')}).`;
    const waLinks = d.paid.filter(p => p.wa);
    if (waLinks.length) msg += `\n\nKlik OK untuk buka WA notifikasi ke mitra (${waLinks.length} link).`;
    alert(msg);
    waLinks.forEach((p,i) => setTimeout(()=>window.open(p.wa,'_blank'), i*400));
    loadRekap();
  } catch(e){ showToast('Gagal: '+e.message,'error'); }
}

// ── Orders ──
async function loadOrders(){
  const box = document.getElementById('ordersBox');
  box.innerHTML = '<div class="empty">⏳ Memuat…</div>';
  try {
    const r = await fetch('droppoint_manager.php?action=orders_today');
    const d = await r.json();
    if (d.error){ box.innerHTML = `<div class="empty">⚠️ ${esc(d.error)}</div>`; return; }
    if (!d.orders.length){ box.innerHTML = '<div class="empty">Belum ada order drop point hari ini.</div>'; return; }
    let html = '<div style="overflow-x:auto"><table class="tbl hl-stack-mobile"><thead><tr><th>No. Order</th><th>Mitra</th><th>Pelanggan</th><th>Total</th><th>Status</th><th>Waktu</th><th></th></tr></thead><tbody>';
    d.orders.forEach(o => {
      html += `<tr>
        <td data-lbl="No Order"><strong>${esc(o.no_order)}</strong></td>
        <td data-lbl="Mitra">${esc(o.nama_mitra)}</td>
        <td data-lbl="Pelanggan">${esc(o.nama_pelanggan)}<br><small style="color:#9CA3AF">${esc(o.telepon||'-')}</small></td>
        <td data-lbl="Total" class="num">${fmtRp(o.total)}</td>
        <td data-lbl="Status"><span class="pill" style="background:#DBEAFE;color:#1E40AF">${esc(o.status_proses)}</span></td>
        <td data-lbl="Waktu"><small>${new Date(o.created_at).toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'})}</small></td>
        <td>${o.status_proses==='masuk'?`<button class="btn btn-primary btn-sm" onclick="confirmPickup(${o.id})">✓ Pickup</button>`:''}</td>
      </tr>`;
    });
    html += '</tbody></table></div>';
    box.innerHTML = html;
  } catch(e){ box.innerHTML = `<div class="empty">⚠️ ${esc(e.message)}</div>`; }
}

async function confirmPickup(orderId){
  if (!confirm('Konfirmasi pickup? Order akan masuk ke status "cuci".')) return;
  try {
    await fetch('droppoint_manager.php?action=confirm_pickup', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body: JSON.stringify({order_id: orderId})
    });
    loadOrders();
  } catch(e){}
}

loadMitra();
</script>
</body>
</html>
