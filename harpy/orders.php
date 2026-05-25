<?php
$activePage = 'orders';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/Loyalty.php';
require_once __DIR__ . '/components.php';
$user = currentUser();

if (!hasPermission('orders.view_all') && !hasPermission('orders.view_own')) requirePermission('orders.view_all');

// Helper: data filter scope berdasarkan permission
if (!function_exists('getDataFilter')) {
    function getDataFilter(string $kode) {
        // 'view_all' → tanpa filter (return null), 'view_own' → 'own',
        // fallback false kalau tidak punya keduanya
        if (hasPermission($kode)) return null;
        $owns = str_replace('view_all','view_own', $kode);
        if (hasPermission($owns)) return 'own';
        return false;
    }
}

// ── API ───────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    // Tangkap PHP fatal supaya tetap return JSON (bukan empty 500)
    register_shutdown_function(function() {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            if (!headers_sent()) header('Content-Type: application/json');
            echo json_encode(['error'=>'PHP fatal: '.$e['message'].' @ '.$e['file'].':'.$e['line']]);
        }
    });
    @ini_set('display_errors', '0');
    error_reporting(0);
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $oid = TenantResolver::outletId();

    // LIST orders
    // Defensive: cek dukungan kolom drop_point_id (kalau migration belum jalan)
    $hasDropPoint = false;
    try {
        Database::get()->query("SELECT drop_point_id FROM hl_transaksi LIMIT 1");
        Database::get()->query("SELECT 1 FROM hl_drop_points LIMIT 1");
        $hasDropPoint = true;
    } catch (Throwable) { /* migration belum jalan */ }

    if ($action === 'list') {
        $filter = getDataFilter('orders.view_all');
        if ($filter === false) $filter = getDataFilter('orders.view_own');
        $q       = $_GET['q'] ?? '';
        $status  = $_GET['status'] ?? '';
        $bayar   = $_GET['bayar'] ?? '';
        $dari    = $_GET['dari'] ?? '';
        $sampai  = $_GET['sampai'] ?? '';
        $sumber  = $_GET['sumber'] ?? '';      // '' / 'walkin' / 'drop' / 'drop:<id>'
        $page    = max(1, intval($_GET['page'] ?? 1));
        $limit   = 25;
        $offset  = ($page - 1) * $limit;

        $where = ['t.tenant_id = ?', 't.outlet_id = ?']; $params = [$tid, $oid];
        if ($q) {
            $where[] = "(t.no_order LIKE ? OR t.nama_pelanggan LIKE ? OR t.telepon LIKE ?)";
            $like = "%$q%"; $params = array_merge($params, [$like, $like, $like]);
        }
        if ($status) { $where[] = "t.status_proses=?"; $params[] = $status; }
        if ($bayar)  { $where[] = "t.status_bayar=?";  $params[] = $bayar; }
        if ($dari)   { $where[] = "DATE(t.tanggal) >= ?"; $params[] = $dari; }
        if ($sampai) { $where[] = "DATE(t.tanggal) <= ?"; $params[] = $sampai; }
        if ($hasDropPoint) {
            if ($sumber === 'walkin')      { $where[] = "t.drop_point_id IS NULL"; }
            elseif ($sumber === 'drop')    { $where[] = "t.drop_point_id IS NOT NULL"; }
            elseif (strpos($sumber, 'drop:') === 0) {
                $dpId = (int)substr($sumber, 5);
                if ($dpId > 0) { $where[] = "t.drop_point_id = ?"; $params[] = $dpId; }
            }
        }

        // Filter berdasarkan permission
        if ($filter === 'own') { $where[] = "t.created_by=?"; $params[] = $user['id']; }
        elseif ($filter === 'today') { $where[] = "DATE(t.tanggal)=CURDATE()"; }

        $sort    = $_GET['sort'] ?? 'tanggal';
        $dir     = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $sortMap = ['no_order'=>'t.no_order','tanggal'=>'t.tanggal','nama_pelanggan'=>'t.nama_pelanggan',
                    'total'=>'t.total','estimasi_selesai'=>'t.estimasi_selesai'];
        $sortCol = $sortMap[$sort] ?? 't.tanggal';

        $whereStr = implode(' AND ', $where);

        $namaMitraCol = $hasDropPoint
            ? "(SELECT nama_mitra FROM hl_drop_points WHERE id=t.drop_point_id) as nama_mitra"
            : "NULL as nama_mitra";
        $sql = "SELECT t.*,
            (SELECT GROUP_CONCAT(nama_layanan SEPARATOR ', ') FROM hl_transaksi_item WHERE transaksi_id=t.id AND tenant_id=t.tenant_id AND outlet_id=t.outlet_id) as layanan_list,
            $namaMitraCol
            FROM hl_transaksi t
            WHERE $whereStr
            ORDER BY $sortCol $dir
            LIMIT $limit OFFSET $offset";

        try {
            $rows = TenantQuery::raw($sql, $params);
        } catch (Throwable $e) {
            echo json_encode(['error'=>'Query gagal: '.$e->getMessage()]); exit;
        }

        try {
            $cnt   = TenantQuery::raw("SELECT COUNT(*) as c FROM hl_transaksi t WHERE $whereStr", $params);
            $total = intval($cnt[0]['c'] ?? 0);
        } catch (Throwable $e) { $total = count($rows); }

        echo json_encode([
            'data'        => $rows,
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => ceil($total / $limit),
        ]);
        exit;
    }

    // GET detail 1 order
    if ($action === 'get') {
        $id = intval($_GET['id']);
        $t  = TenantQuery::rawOne("SELECT * FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?", [$tid, $oid, $id]);
        if (!$t) { echo json_encode(['error'=>'Not found']); exit; }
        $t['items'] = TenantQuery::raw("SELECT * FROM hl_transaksi_item WHERE tenant_id=? AND outlet_id=? AND transaksi_id=? ORDER BY id", [$tid, $oid, $id]);
        $t['logs']  = TenantQuery::raw("SELECT * FROM hl_proses_log WHERE transaksi_id=? ORDER BY created_at DESC LIMIT 10", [$id]);
        echo json_encode($t); exit;
    }

    // UPDATE order
    if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $data = json_decode(file_get_contents('php://input'), true);
        $id   = intval($data['id']);

        $db = Database::get();
        $db->beginTransaction();
        try {
            // Verify ownership
            $oldRow = $db->prepare("SELECT status_proses,status_bayar,catatan,dp FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?");
            $oldRow->execute([$tid, $oid, $id]);
            $oldRow = $oldRow->fetch();
            if (!$oldRow) { $db->rollBack(); echo json_encode(['error'=>'Order tidak ditemukan']); exit; }

            // Recalc total jika ada items baru
            $subtotal = 0;
            if (!empty($data['items'])) {
                foreach ($data['items'] as $item) {
                    $subtotal += floatval($item['jumlah']) * floatval($item['harga_satuan']);
                }
            }

            $diskon = floatval($data['diskon'] ?? 0);
            $total  = $subtotal > 0 ? ($subtotal - $diskon) : floatval($data['total'] ?? 0);
            $dp     = floatval($data['dp'] ?? 0);
            $sisa   = $total - $dp;
            $sbayar = $dp >= $total && $total > 0 ? 'lunas' : ($dp > 0 ? 'dp' : 'belum_bayar');

            // Update header.
            // tgl_selesai distempel saat status pertama kali jadi siap/diambil/selesai.
            // handled_by distempel saat status pertama kali keluar dari 'masuk' (mulai dikerjakan).
            $stmt = $db->prepare("UPDATE hl_transaksi SET
                status_proses=?, status_bayar=?,
                catatan=?, catatan_internal=?,
                metode_bayar=?, dp=?, sisa_bayar=?,
                diskon=?, total=?, subtotal=?,
                estimasi_selesai=?,
                tgl_selesai = CASE
                    WHEN ? IN ('siap','diambil','selesai') AND tgl_selesai IS NULL THEN CURDATE()
                    ELSE tgl_selesai END,
                handled_by = CASE
                    WHEN ? NOT IN ('masuk') AND handled_by IS NULL THEN ?
                    ELSE handled_by END
                WHERE tenant_id=? AND outlet_id=? AND id=?");
            $stmt->execute([
                $data['status_proses'],
                $sbayar,
                $data['catatan'] ?? '',
                $data['catatan_internal'] ?? '',
                $data['metode_bayar'] ?? 'cash',
                $dp, $sisa, $diskon, $total, $subtotal > 0 ? $subtotal : null,
                $data['estimasi'] ?: null,
                $data['status_proses'],
                $data['status_proses'], (int)$user['id'],
                $tid, $oid, $id
            ]);

            // Update items jika ada
            if (!empty($data['items'])) {
                $db->prepare("DELETE FROM hl_transaksi_item WHERE tenant_id=? AND outlet_id=? AND transaksi_id=?")->execute([$tid, $oid, $id]);
                $istmt = $db->prepare("INSERT INTO hl_transaksi_item
                    (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal,catatan_item)
                    VALUES (?,?,?,?,?,?,?,?,?,?)");
                foreach ($data['items'] as $item) {
                    $sub = floatval($item['jumlah']) * floatval($item['harga_satuan']);
                    $istmt->execute([
                        $tid, $oid, $id,
                        $item['layanan_id'] ?: null,
                        $item['nama_layanan'],
                        $item['satuan'],
                        $item['jumlah'],
                        $item['harga_satuan'],
                        $sub,
                        $item['catatan_item'] ?? ''
                    ]);
                }
                // Update subtotal di header
                $db->prepare("UPDATE hl_transaksi SET subtotal=? WHERE tenant_id=? AND outlet_id=? AND id=?")->execute([$subtotal, $tid, $oid, $id]);
            }

            // ── LOG semua perubahan ──────────────────────────────
            $logs_to_insert = [];

            if ($oldRow['status_proses'] !== $data['status_proses']) {
                $logs_to_insert[] = [
                    'transaksi_id' => $id,
                    'status_lama'  => $oldRow['status_proses'],
                    'status_baru'  => $data['status_proses'],
                    'tipe'         => 'proses',
                    'catatan'      => 'Status diubah: ' . $oldRow['status_proses'] . ' → ' . $data['status_proses'],
                    'oleh'         => $user['nama']
                ];
            }

            if ($oldRow['status_bayar'] !== $sbayar) {
                $logs_to_insert[] = [
                    'transaksi_id' => $id,
                    'status_lama'  => $oldRow['status_bayar'],
                    'status_baru'  => $sbayar,
                    'tipe'         => 'bayar',
                    'catatan'      => 'Pembayaran diupdate: DP Rp ' . number_format($dp, 0, ',', '.') . ' · Status: ' . $sbayar,
                    'oleh'         => $user['nama']
                ];
            }

            if (!empty($data['items'])) {
                $newItemNames = implode(', ', array_column($data['items'], 'nama_layanan'));
                $logs_to_insert[] = [
                    'transaksi_id' => $id,
                    'status_lama'  => null,
                    'status_baru'  => 'items_updated',
                    'tipe'         => 'items',
                    'catatan'      => 'Layanan diupdate: ' . $newItemNames,
                    'oleh'         => $user['nama']
                ];
            }

            if (trim($oldRow['catatan'] ?? '') !== trim($data['catatan'] ?? '')) {
                $logs_to_insert[] = [
                    'transaksi_id' => $id,
                    'status_lama'  => null,
                    'status_baru'  => 'catatan_updated',
                    'tipe'         => 'catatan',
                    'catatan'      => 'Catatan diupdate',
                    'oleh'         => $user['nama']
                ];
            }

            $lstmt = $db->prepare("INSERT INTO hl_proses_log (transaksi_id,status_lama,status_baru,tipe,catatan,oleh) VALUES (?,?,?,?,?,?)");
            foreach ($logs_to_insert as $log) {
                $lstmt->execute([
                    $log['transaksi_id'],
                    $log['status_lama'],
                    $log['status_baru'],
                    $log['tipe'],
                    $log['catatan'],
                    $log['oleh']
                ]);
            }

            logAudit('update', 'orders', 'Update order ID: ' . $id);
            $db->commit();

            // Loyalty: earn poin saat status_proses berubah ke 'siap' (idempotent)
            $poinEarned = 0;
            if ($data['status_proses'] === 'siap' && $oldRow['status_proses'] !== 'siap') {
                try {
                    $prow = TenantQuery::rawOne("SELECT pelanggan_id,total FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?", [$tid,$oid,$id]);
                    if ($prow && $prow['pelanggan_id'])
                        $poinEarned = Loyalty::earnForTransaction($tid, $oid, (int)$id, (int)$prow['pelanggan_id'], (float)$prow['total']);
                } catch (Throwable) {}
            }

            echo json_encode(['success' => true, 'poin_earned' => $poinEarned]);
        } catch (Throwable $e) {
            $db->rollBack();
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // UPDATE PEMBAYARAN — pilihan sebagian/lunas + bukti
    if ($action === 'bayar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        if (!hasPermission('orders.update_payment')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        $id     = intval($_POST['id'] ?? 0);
        $tipe   = $_POST['tipe_bayar'] ?? 'sebagian';
        $jumlah = floatval($_POST['jumlah'] ?? 0);

        // Verify ownership & get current data
        $row = TenantQuery::rawOne("SELECT total, dp, sisa_bayar FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?", [$tid, $oid, $id]);
        if (!$row) { echo json_encode(['error'=>'Order tidak ditemukan']); exit; }

        $new_dp   = floatval($row['dp']) + $jumlah;
        $new_sisa = floatval($row['total']) - $new_dp;
        if ($tipe === 'lunas') {
            $new_dp   = floatval($row['total']);
            $new_sisa = 0;
        }
        $new_status = $new_sisa <= 0 ? 'lunas' : ($new_dp > 0 ? 'dp' : 'belum_bayar');

        // Upload bukti bayar
        $bukti_path = null;
        if (!empty($_FILES['bukti']['tmp_name'])) {
            $ext     = strtolower(pathinfo($_FILES['bukti']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];
            if (!in_array($ext, $allowed)) {
                echo json_encode(['error'=>'Format file tidak didukung. Gunakan JPG/PNG.']); exit;
            }
            $dir = __DIR__ . '/uploads/bukti_bayar/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $filename = 'bukti_' . $id . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['bukti']['tmp_name'], $dir . $filename)) {
                $bukti_path = 'uploads/bukti_bayar/' . $filename;
            }
        }

        $db = Database::get();
        $db->beginTransaction();
        try {
            $upd    = "UPDATE hl_transaksi SET dp=?, sisa_bayar=?, status_bayar=?, metode_bayar=?";
            $params = [$new_dp, $new_sisa, $new_status, $_POST['metode'] ?? 'cash'];
            if ($bukti_path) { $upd .= ", bukti_bayar=?"; $params[] = $bukti_path; }
            $upd .= " WHERE tenant_id=? AND outlet_id=? AND id=?"; $params[] = $tid; $params[] = $oid; $params[] = $id;
            $db->prepare($upd)->execute($params);

            // Log
            $ket = $tipe === 'lunas'
                ? "Pembayaran LUNAS Rp " . number_format($new_dp, 0, ',', '.')
                : "Pembayaran sebagian Rp " . number_format($jumlah, 0, ',', '.') . " · Sisa Rp " . number_format(max($new_sisa, 0), 0, ',', '.');
            if ($bukti_path) $ket .= " · Bukti bayar terlampir";

            $db->prepare("INSERT INTO hl_proses_log (transaksi_id,status_lama,status_baru,tipe,catatan,oleh) VALUES (?,?,?,?,?,?)")
               ->execute([$id, $row['dp'] > 0 ? 'dp' : 'belum_bayar', $new_status, 'bayar', $ket, $user['nama']]);

            // Ambil no_order & nama_pelanggan
            $trxData = $db->prepare("SELECT no_order, nama_pelanggan FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?");
            $trxData->execute([$tid, $oid, $id]);
            $trx = $trxData->fetch();

            $metodeLabel = ['cash'=>'Cash','transfer'=>'Transfer','qris'=>'QRIS'][$_POST['metode'] ?? 'cash'] ?? 'Cash';
            $kasKet = ($tipe === 'lunas' ? 'Pelunasan' : 'Pembayaran sebagian') .
                      ' order ' . ($trx['no_order'] ?? '') .
                      ' - ' . ($trx['nama_pelanggan'] ?? '') .
                      ' via ' . $metodeLabel;

            // AUTO INSERT KAS MASUK
            TenantQuery::insert('hl_kas', [
                'tanggal'    => date('Y-m-d'),
                'tipe'       => 'masuk',
                'kategori'   => 'Pelunasan Order',
                'keterangan' => $kasKet,
                'jumlah'     => $jumlah,
                'ref_order'  => $trx['no_order'] ?? null,
                'created_by' => $user['id'],
            ]);

            logAudit('payment', 'orders', 'Pembayaran order: ' . ($trx['no_order'] ?? '') . ', Rp ' . number_format($jumlah, 0, ',', '.'));
            $db->commit();

            // Loyalty: earn TIDAK di-trigger oleh pembayaran lagi (sekarang
            // by status_proses='siap'). Cuma touch last_transaksi.
            $poinEarned = 0;
            try {
                $prow = TenantQuery::rawOne("SELECT pelanggan_id FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?", [$tid,$oid,$id]);
                if ($prow && $prow['pelanggan_id']) {
                    Loyalty::touchLastTransaksi($tid, (int)$prow['pelanggan_id']);
                }
            } catch (Throwable) {}

            echo json_encode([
                'success'      => true,
                'poin_earned'  => $poinEarned,
                'status_bayar' => $new_status,
                'dp'           => $new_dp,
                'sisa'         => max($new_sisa, 0),
                'bukti'        => $bukti_path
            ]);
        } catch (Throwable $e) {
            $db->rollBack();
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // BULK UPDATE STATUS_PROSES
    if ($action === 'bulk_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hasPermission('orders.update') && !hasPermission('orders.update_own')) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
        verifyCsrf();
        $data = json_decode(file_get_contents('php://input'), true);
        $ids    = $data['ids'] ?? [];
        $status = $data['status'] ?? '';
        $allowed = ['masuk','cuci','kering','setrika','siap','diambil'];
        if (!in_array($status, $allowed, true)) { echo json_encode(['error'=>'Status tidak valid']); exit; }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_filter($ids, fn($x) => $x > 0);
        if (!$ids) { echo json_encode(['error'=>'Tidak ada order dipilih']); exit; }
        if (count($ids) > 100) { echo json_encode(['error'=>'Maksimal 100 order per bulk']); exit; }

        $db = Database::get();
        $db->beginTransaction();
        try {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            // Update + handled_by stamp + tgl_selesai stamp
            $sql = "UPDATE hl_transaksi
                       SET status_proses=?,
                           handled_by = CASE WHEN ? IN ('cuci','kering','setrika','siap','diambil','selesai')
                                              AND handled_by IS NULL THEN ? ELSE handled_by END,
                           tgl_selesai = CASE WHEN ? IN ('siap','diambil','selesai') AND tgl_selesai IS NULL
                                              THEN NOW() ELSE tgl_selesai END
                     WHERE tenant_id=? AND outlet_id=? AND id IN ($ph)";
            $params = [$status, $status, $user['id'], $status, $tid, $oid, ...$ids];
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $affected = $stmt->rowCount();

            // Log per id
            $lg = $db->prepare("INSERT INTO hl_proses_log (tenant_id,transaksi_id,status_baru,oleh,catatan) VALUES (?,?,?,?,?)");
            foreach ($ids as $oidord) {
                $lg->execute([$tid, $oidord, $status, $user['nama'], 'Bulk update']);
            }
            $db->commit();
            logAudit('bulk_status', 'orders', count($ids).' order → '.$status);
            echo json_encode(['ok'=>true, 'affected'=>$affected]);
        } catch (Throwable $e) {
            $db->rollBack();
            echo json_encode(['error'=>$e->getMessage()]);
        }
        exit;
    }

    // LIST CATATAN INTERNAL (multi-row, hl_order_notes)
    if ($action === 'notes_list') {
        $oidv = intval($_GET['order_id'] ?? 0);
        if (!$oidv) { echo json_encode(['ok'=>true,'rows'=>[]]); exit; }
        try {
            $db = Database::get();
            $stmt = $db->prepare("SELECT id, user_id, user_nama, catatan, created_at
                                    FROM hl_order_notes
                                   WHERE tenant_id=? AND outlet_id=? AND transaksi_id=?
                                   ORDER BY id DESC");
            $stmt->execute([$tid, $oid, $oidv]);
            echo json_encode(['ok'=>true, 'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            echo json_encode(['ok'=>true, 'rows'=>[]]);
        }
        exit;
    }

    // ADD CATATAN INTERNAL
    if ($action === 'note_add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $data    = json_decode(file_get_contents('php://input'), true);
        $oidv    = intval($data['order_id'] ?? 0);
        $catatan = trim($data['catatan'] ?? '');
        if (!$oidv || $catatan === '') { echo json_encode(['error'=>'order_id & catatan wajib']); exit; }

        // Verify ownership transaksi
        $ck = TenantQuery::rawOne("SELECT id FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?", [$tid, $oid, $oidv]);
        if (!$ck) { echo json_encode(['error'=>'Order tidak ditemukan']); exit; }

        $userId   = $_SESSION['user_id']   ?? null;
        $userNama = $_SESSION['user_nama'] ?? ($_SESSION['nama'] ?? null);
        try {
            $db = Database::get();
            $stmt = $db->prepare("INSERT INTO hl_order_notes
                (tenant_id, outlet_id, transaksi_id, user_id, user_nama, catatan)
                VALUES (?,?,?,?,?,?)");
            $stmt->execute([$tid, $oid, $oidv, $userId, $userNama, $catatan]);
            logAudit('note_add', 'order#'.$oidv, mb_substr($catatan,0,80));
            echo json_encode(['ok'=>true, 'id'=>(int)$db->lastInsertId()]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>'Gagal simpan catatan: '.$e->getMessage()]);
        }
        exit;
    }

    // SUMMARY stats
    if ($action === 'summary') {
        $statuses = ['masuk','cuci','kering','setrika','siap','diambil'];
        $sc = [];
        foreach ($statuses as $s) {
            $sc[$s] = TenantQuery::count('hl_transaksi', 'status_proses=?', [$s]);
        }
        echo json_encode(['statuses' => $sc]);
        exit;
    }

    // GET layanan
    if ($action === 'get_layanan') {
        $rows = TenantQuery::raw("SELECT * FROM hl_layanan WHERE tenant_id=? AND outlet_id=? AND is_active=1 ORDER BY kategori,urutan", [$tid, $oid]);
        echo json_encode($rows); exit;
    }

    // GET STRUK DATA — untuk cetak ulang nota
    if ($action === 'get_struk') {
        $id = intval($_GET['id']);
        $t  = TenantQuery::rawOne("SELECT * FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?", [$tid, $oid, $id]);
        if (!$t) { echo json_encode(['error'=>'Not found']); exit; }
        $t['items'] = TenantQuery::raw("SELECT * FROM hl_transaksi_item WHERE tenant_id=? AND outlet_id=? AND transaksi_id=? ORDER BY id", [$tid, $oid, $id]);
        echo json_encode($t); exit;
    }

    // GENERATE WA REMINDER MESSAGE
    if ($action === 'wa_message') {
        $id   = intval($_GET['id']);
        $tipe = $_GET['tipe'] ?? 'reminder';
        $t    = TenantQuery::rawOne("SELECT * FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?", [$tid, $oid, $id]);
        if (!$t) { echo json_encode(['error'=>'Not found']); exit; }

        $t['items'] = TenantQuery::raw("SELECT * FROM hl_transaksi_item WHERE tenant_id=? AND outlet_id=? AND transaksi_id=? ORDER BY id", [$tid, $oid, $id]);

        $itemList = '';
        foreach ($t['items'] as $item) {
            $itemList .= "\n   • " . $item['nama_layanan'] . " — " . floatval($item['jumlah']) . " " . $item['satuan'];
        }

        $totalFmt = "Rp " . number_format(floatval($t['total']), 0, ',', '.');
        $sisaFmt  = "Rp " . number_format(floatval($t['sisa_bayar']), 0, ',', '.');
        $est      = $t['estimasi_selesai'] ? date('d M Y', strtotime($t['estimasi_selesai'])) : '-';

        if ($tipe === 'siap') {
            $msg = "Halo *{$t['nama_pelanggan']}* 👋\n\n"
                 . "Laundry Anda di *Harpy Laundry* sudah *✅ SIAP DIAMBIL!*\n\n"
                 . "📋 *No. Order:* {$t['no_order']}\n"
                 . "🧺 *Layanan:*{$itemList}\n"
                 . "💰 *Total:* {$totalFmt}\n"
                 . ($t['sisa_bayar'] > 0 ? "⚠️ *Sisa Bayar:* {$sisaFmt}\n" : "✅ *Status Bayar:* Lunas\n")
                 . "\n📍 Silakan diambil di:\nJl. Rawa Selatan IV No.1, Johar Baru, Jakarta Pusat\n"
                 . "\nTerima kasih sudah mempercayakan cucian Anda kepada kami 🙏\n_Harpy Laundry | harpy.id_";
        } elseif ($tipe === 'lunas_reminder') {
            $msg = "Halo *{$t['nama_pelanggan']}* 👋\n\n"
                 . "Ini pengingat untuk pelunasan laundry Anda di *Harpy Laundry*.\n\n"
                 . "📋 *No. Order:* {$t['no_order']}\n"
                 . "💰 *Total:* {$totalFmt}\n"
                 . "⚠️ *Sisa yang harus dibayar:* {$sisaFmt}\n\n"
                 . "Mohon segera dilunasi saat pengambilan ya 🙏\n"
                 . "\nTerima kasih!\n_Harpy Laundry | harpy.id_";
        } else {
            $statusLabel = ['masuk'=>'Diterima','cuci'=>'Sedang Dicuci','kering'=>'Sedang Dikeringkan',
                'setrika'=>'Sedang Disetrika','siap'=>'Siap Diambil','diambil'=>'Sudah Diambil'];
            $stLabel = $statusLabel[$t['status_proses']] ?? $t['status_proses'];
            $msg = "Halo *{$t['nama_pelanggan']}* 👋\n\n"
                 . "Update status laundry Anda di *Harpy Laundry*:\n\n"
                 . "📋 *No. Order:* {$t['no_order']}\n"
                 . "🔄 *Status:* {$stLabel}\n"
                 . "📅 *Est. Selesai:* {$est}\n"
                 . "🧺 *Layanan:*{$itemList}\n\n"
                 . "Cek status real-time: https://harpy.id/track.php?order={$t['no_order']}\n\n"
                 . "Terima kasih 🙏\n_Harpy Laundry | harpy.id_";
        }

        $phone = preg_replace('/[^0-9]/', '', $t['telepon'] ?? '');
        if (substr($phone, 0, 1) === '0') $phone = '62' . substr($phone, 1);

        echo json_encode(['success'=>true, 'message'=>$msg, 'phone'=>$phone, 'no_order'=>$t['no_order']]); exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Daftar Order'); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--teal:#35E8D5;--teal-d:#1CC4B2;--teal-bg:#E8FBF9;--navy:#1B2D5A;--navy-d:#0F1C3A;--white:#fff;--off:#F7F8FC;--light:#EEF1F8;--gray:#6C7A8D;--dark:#1C1C2E;--red:#EF4444;--green:#10B981;--yellow:#F59E0B;--blue:#3B82F6;--font:'Plus Jakarta Sans',sans-serif;--mono:'DM Mono',monospace;--r:10px;--r-lg:16px;--shadow:0 2px 12px rgba(27,45,90,.08);--shadow-lg:0 8px 32px rgba(27,45,90,.14)}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--off);color:var(--dark);min-height:100vh}
.topbar{background:var(--navy-d);padding:0 24px;height:56px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;border-bottom:1px solid rgba(53,232,213,.15)}
.topbar-brand span{color:var(--teal)}
.main{max-width:1300px;width:100%;margin:0 auto;padding:24px 20px}

/* STATS */
.stats{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:24px}
.stat-card{background:var(--white);border-radius:var(--r-lg);padding:14px 16px;border:1px solid rgba(27,45,90,.07);box-shadow:var(--shadow);cursor:pointer;transition:all .2s}
.stat-card:hover,.stat-card.active{border-color:var(--teal);box-shadow:0 4px 16px rgba(53,232,213,.15)}
.stat-card.active{background:var(--teal-bg)}
.stat-num{font-size:1.5rem;font-weight:800;color:var(--navy);line-height:1;font-family:var(--mono)}
.stat-label{font-size:11px;color:var(--gray);margin-top:4px;font-weight:500}

/* FILTER BAR */
.filter-bar{display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap}
.filter-bar input{flex:1;min-width:200px;padding:9px 14px;border:1.5px solid rgba(27,45,90,.14);border-radius:var(--r);font-family:var(--font);font-size:14px;background:var(--white);outline:none;transition:all .2s}
.filter-bar input:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(53,232,213,.1)}
.filter-bar select{padding:9px 12px;border:1.5px solid rgba(27,45,90,.14);border-radius:var(--r);font-family:var(--font);font-size:13px;background:var(--white);outline:none;cursor:pointer}

/* CARD */
.card{background:var(--white);border-radius:var(--r-lg);border:1px solid rgba(27,45,90,.07);box-shadow:var(--shadow);overflow:hidden}
.card-header{padding:16px 20px;border-bottom:1px solid var(--light);display:flex;align-items:center;justify-content:space-between}
.card-title{font-size:14px;font-weight:700;color:var(--navy)}

/* TABLE */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13.5px}
thead tr{background:var(--navy-d)}
thead th{padding:11px 12px;text-align:left;font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.6);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--light);transition:background .15s;cursor:pointer}
tbody tr:hover{background:#F0FDF9}
tbody td{padding:11px 12px;vertical-align:middle}
.td-no{font-family:var(--mono);font-size:12px;color:var(--teal-d);font-weight:600}
.td-nama{font-weight:600;color:var(--navy)}
.td-total{font-family:var(--mono);font-weight:700;color:var(--navy);text-align:right}
.td-layanan{font-size:12px;color:var(--gray);max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* BADGES */
.badge{display:inline-block;font-size:11px;font-weight:600;letter-spacing:.04em;padding:3px 9px;border-radius:100px;white-space:nowrap}
.b-masuk{background:#DBEAFE;color:#1D4ED8}
.b-cuci{background:#FEF9C3;color:#854D0E}
.b-kering{background:#FEF3C7;color:#92400E}
.b-setrika{background:#EDE9FE;color:#5B21B6}
.b-siap{background:#D1FAE5;color:#065F46}
.b-diambil{background:#F3F4F6;color:#374151}
.b-lunas{background:#D1FAE5;color:#065F46}
.b-dp{background:#FEF3C7;color:#92400E}
.b-belum_bayar{background:#FEE2E2;color:#991B1B}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,28,58,.6);backdrop-filter:blur(4px);z-index:200;align-items:flex-start;justify-content:flex-end;padding:16px}
.modal-overlay.open{display:flex}
.modal{background:var(--white);border-radius:var(--r-lg);width:520px;max-width:95vw;height:calc(100vh - 32px);overflow-y:auto;box-shadow:var(--shadow-lg);display:flex;flex-direction:column}
.modal-header{padding:18px 20px;border-bottom:1px solid var(--light);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:var(--white);z-index:10}
.modal-title{font-size:15px;font-weight:700;color:var(--navy)}
.modal-close{background:none;border:none;font-size:18px;cursor:pointer;color:var(--gray);padding:4px}
.modal-body{padding:20px;flex:1;overflow-y:auto}
.modal-footer{padding:16px 20px;border-top:1px solid var(--light);display:flex;gap:10px;justify-content:flex-end;position:sticky;bottom:0;background:var(--white)}

.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:12px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.form-row.cols3{grid-template-columns:1fr 1fr 1fr}
label{font-size:11px;font-weight:700;color:var(--navy);letter-spacing:.05em;text-transform:uppercase}
input,select,textarea{padding:9px 12px;border:1.5px solid rgba(27,45,90,.14);border-radius:var(--r);font-family:var(--font);font-size:14px;color:var(--dark);background:var(--off);outline:none;transition:all .2s;width:100%}
input:focus,select:focus,textarea:focus{border-color:var(--teal);background:var(--white);box-shadow:0 0 0 3px rgba(53,232,213,.1)}
textarea{resize:vertical;min-height:64px}

/* ITEMS IN MODAL */
.items-table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:8px}
.items-table thead tr{background:var(--navy-d)}
.items-table thead th{padding:8px 8px;text-align:left;font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.6)}
.items-table tbody tr{border-bottom:1px solid var(--light)}
.items-table tbody td{padding:5px 5px;vertical-align:middle}
.item-input{padding:6px 8px;font-size:12px}
.btn-remove{background:#FEE2E2;color:var(--red);border:none;border-radius:6px;padding:4px 7px;cursor:pointer;font-size:12px}
.btn-remove:hover{background:var(--red);color:white}
.item-sub{font-family:var(--mono);font-size:12px;text-align:right;white-space:nowrap}

/* PROSES STEPS */
.proses-steps{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:4px}
.step-btn{padding:7px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;border:1.5px solid rgba(27,45,90,.12);background:var(--off);transition:all .2s;font-family:var(--font)}
.step-btn:hover{border-color:var(--teal);background:var(--teal-bg)}
.step-btn.active{background:var(--navy);color:var(--white);border-color:var(--navy)}

/* LOG */
.log-item{padding:8px 0;border-bottom:1px solid var(--light);font-size:12px;display:flex;gap:8px;align-items:flex-start}
.log-time{font-family:var(--mono);font-size:11px;color:var(--gray);white-space:nowrap;min-width:100px}
.log-text{color:var(--dark)}

/* TOTAL SUMMARY */
.total-box{background:linear-gradient(135deg,var(--navy-d),var(--navy));border-radius:var(--r);padding:14px 16px;color:var(--white);margin-bottom:12px}
.tb-row{display:flex;justify-content:space-between;font-size:13px;padding:3px 0}
.tb-label{color:rgba(255,255,255,.6)}
.tb-value{font-family:var(--mono);font-weight:600}
.tb-total{border-top:1px solid rgba(255,255,255,.2);margin-top:6px;padding-top:8px}
.tb-big{font-size:1.2rem;color:var(--teal)}
.tb-sisa{color:#FCA5A5}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 16px;border-radius:var(--r);font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;border:none}
.btn-primary{background:var(--teal);color:var(--navy-d)}
.btn-primary:hover{background:var(--teal-d)}
.btn-outline{background:transparent;color:var(--navy);border:1.5px solid rgba(27,45,90,.2)}
.btn-outline:hover{background:var(--light)}
.btn-teal-sm{background:var(--teal-bg);color:var(--teal-d);border:1px solid rgba(53,232,213,.3);font-size:12px;padding:6px 12px}
.btn-teal-sm:hover{background:var(--teal);color:var(--navy-d)}
.btn-sm{padding:6px 12px;font-size:12px}

.section-title{font-size:12px;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;margin-top:16px;display:flex;align-items:center;gap:6px}
.section-title::after{content:'';flex:1;height:1px;background:var(--light)}

.empty{text-align:center;padding:40px;color:var(--gray);font-size:14px}
.loading{text-align:center;padding:32px;color:var(--gray);font-size:14px}

.toast{position:fixed;bottom:20px;right:20px;z-index:999;padding:13px 18px;border-radius:var(--r);font-size:14px;font-weight:600;color:white;transform:translateY(60px);opacity:0;transition:all .3s cubic-bezier(.4,0,.2,1);pointer-events:none;max-width:320px}
.toast.show{transform:translateY(0);opacity:1}
.toast.success{background:var(--green)}
.wa-type-btn{transition:all .2s}
.toast.error{background:var(--red)}

/* ACTION BUTTONS */
.action-btns{display:flex;gap:5px;flex-wrap:wrap}
.th-sort{cursor:pointer;user-select:none;white-space:nowrap}
.th-sort:hover{background:rgba(255,255,255,.1)}
.sort-icon{font-size:10px;opacity:.5;margin-left:3px}
.th-sort.asc .sort-icon::after{content:'↑';opacity:1}
.th-sort.desc .sort-icon::after{content:'↓';opacity:1}
.th-sort.asc .sort-icon,.th-sort.desc .sort-icon{opacity:0}
.th-sort.asc::after,.th-sort.desc::after{content:'';margin-left:4px}
.action-btns .btn{padding:5px 9px;font-size:11px;white-space:nowrap}

/* STRUK PRINT */
.struk{font-family:'Courier New',monospace;font-size:12px;line-height:1.6;color:#000;max-width:300px;margin:0 auto}
.struk-header{text-align:center;border-bottom:1px dashed #000;padding-bottom:8px;margin-bottom:8px}
.struk-header h2{font-size:15px;font-weight:bold}
.struk-header p{font-size:11px}
.struk-row{display:flex;justify-content:space-between;font-size:12px}
.struk-row.bold{font-weight:bold}
.struk-item{margin:4px 0;font-size:11px}
.struk-divider{border:none;border-top:1px dashed #000;margin:6px 0}
.struk-total{border-top:2px solid #000;margin-top:6px;padding-top:6px}
.struk-footer{text-align:center;margin-top:10px;font-size:10px;border-top:1px dashed #000;padding-top:8px}

/* WA PREVIEW */
.wa-bubble{background:#DCF8C6;border-radius:12px 12px 4px 12px;padding:14px 16px;font-size:13px;line-height:1.7;white-space:pre-wrap;max-width:100%;word-break:break-word;margin-bottom:12px;box-shadow:0 1px 4px rgba(0,0,0,.12)}
.wa-bubble strong{font-weight:700}

@media print{
  body *{visibility:hidden}
  #strukCetakUlang,#strukCetakUlang *{visibility:visible}
  #strukCetakUlang{position:fixed;left:0;top:0;width:80mm}
}

/* PAYMENT MODAL */
.pay-opt{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
.pay-btn{padding:16px;border-radius:var(--r);border:2px solid rgba(27,45,90,.12);background:var(--off);cursor:pointer;text-align:center;transition:all .2s;font-family:var(--font)}
.pay-btn:hover{border-color:var(--teal)}
.pay-btn.selected{border-color:var(--teal);background:var(--teal-bg)}
.pay-btn .pay-icon{font-size:1.6rem;display:block;margin-bottom:6px}
.pay-btn .pay-label{font-size:13px;font-weight:700;color:var(--navy)}
.pay-btn .pay-sub{font-size:11px;color:var(--gray);margin-top:2px}
.bukti-preview{width:100%;max-height:160px;object-fit:cover;border-radius:var(--r);margin-top:8px;display:none}
.bukti-drop{border:2px dashed rgba(27,45,90,.18);border-radius:var(--r);padding:20px;text-align:center;cursor:pointer;transition:all .2s;background:var(--off)}
.bukti-drop:hover{border-color:var(--teal);background:var(--teal-bg)}
.bukti-drop p{font-size:13px;color:var(--gray);margin-top:6px}

@media(max-width:900px){
  .stats{grid-template-columns:repeat(3,1fr)}
}
@media(max-width:680px){
  .main{padding:12px 10px 80px}
  .stats{grid-template-columns:repeat(2,1fr);gap:8px}
  .stat-num{font-size:1.2rem}
  .stat-label{font-size:10px}
  .filter-bar input{min-width:0 !important;width:100%}
  .filter-bar{gap:8px}
  .card-header{padding:12px 14px;flex-wrap:wrap;gap:6px}
  .modal{width:100%;max-width:100%;border-radius:var(--r-lg) var(--r-lg) 0 0;height:92vh}
  .modal-overlay{align-items:flex-end;padding:0}
  /* Table utama orders scroll horizontal di HP */
  .table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .table-wrap table{min-width:760px}
  /* Bulk toolbar wrap di HP */
  #bulkToolbar{flex-direction:column;align-items:stretch!important;gap:8px}
  #bulkToolbar select,#bulkToolbar button{width:100%}
  .items-table{table-layout:fixed !important;width:100% !important}
  .items-table th{min-width:0 !important}
  .items-table th:nth-child(1){width:auto !important}
  .items-table th:nth-child(2){width:52px !important}
  .items-table th:nth-child(3){width:52px !important}
  .items-table th:nth-child(4){width:80px !important}
  .items-table th:nth-child(5),.items-table td:nth-child(5){display:none !important}
  .items-table th:nth-child(6){width:32px !important}
  .items-table td input,.items-table td select{width:100% !important;min-width:0 !important}
  .action-btns{flex-wrap:wrap}
  .pay-opt{grid-template-columns:1fr 1fr}
  /* Tombol di action col harus stack vertikal supaya readable */
  .action-btns .btn{padding:8px 10px;font-size:11px;flex:1;min-width:0}
}
@media(max-width:400px){
  .main{padding:8px 8px 80px}
  .stats{grid-template-columns:repeat(2,1fr);gap:6px}
  .stat-num{font-size:1.05rem}
}
</style>
</head>
<body>
<?php renderTopbar('orders'); ?>

<div class="main">

  <!-- STATS -->
  <div class="stats" id="statsRow">
    <div class="stat-card" onclick="filterByStatus('')" id="statAll">
      <div class="stat-num" id="sAll">-</div>
      <div class="stat-label">Semua Order</div>
    </div>
    <div class="stat-card" onclick="filterByStatus('masuk')" id="statMasuk">
      <div class="stat-num" id="sMasuk">-</div>
      <div class="stat-label">📥 Masuk</div>
    </div>
    <div class="stat-card" onclick="filterByStatus('cuci')" id="statCuci">
      <div class="stat-num" id="sCuci">-</div>
      <div class="stat-label">🫧 Cuci</div>
    </div>
    <div class="stat-card" onclick="filterByStatus('kering')" id="statKering">
      <div class="stat-num" id="sKering">-</div>
      <div class="stat-label">💨 Kering</div>
    </div>
    <div class="stat-card" onclick="filterByStatus('siap')" id="statSiap">
      <div class="stat-num" id="sSiap">-</div>
      <div class="stat-label">✅ Siap</div>
    </div>
    <div class="stat-card" onclick="filterByStatus('diambil')" id="statDiambil">
      <div class="stat-num" id="sDiambil">-</div>
      <div class="stat-label">📦 Diambil</div>
    </div>
  </div>

  <!-- FILTER -->
  <div class="hl-filter-collapsible">
    <button class="hl-filter-toggle-btn" id="orderFilterBtn" onclick="toggleFilter('orderFilter')">
      🔍 Filter &amp; Pencarian <span class="hl-filter-active-dot" id="orderFilterDot"></span>
      <span class="hl-toggle-arrow">▼</span>
    </button>
    <div class="hl-filter-bar" id="orderFilter">
      <input type="text" id="searchInput" placeholder="Cari nama, no. order, telepon..."
        oninput="debounce()" style="flex:1;min-width:180px"/>
      <select id="filterStatus" onchange="loadOrders(1)">
        <option value="">Semua Status</option>
        <option value="masuk">Masuk</option>
        <option value="cuci">Proses Cuci</option>
        <option value="kering">Proses Kering</option>
        <option value="setrika">Setrika</option>
        <option value="siap">Siap Diambil</option>
        <option value="diambil">Sudah Diambil</option>
      </select>
      <select id="filterBayar" onchange="loadOrders(1)">
        <option value="">Semua Pembayaran</option>
        <option value="belum_bayar">Belum Bayar</option>
        <option value="dp">DP</option>
        <option value="lunas">Lunas</option>
      </select>
      <select id="filterSumber" onchange="loadOrders(1)" title="Sumber order">
        <option value="">Semua Sumber</option>
        <option value="walkin">🏪 Walk-in</option>
        <option value="drop">📦 Drop Point</option>
      </select>
      <input type="date" id="filterDari" onchange="loadOrders(1)" title="Dari tanggal"
        style="width:auto;padding:9px 10px;border:1.5px solid rgba(27,45,90,.14);border-radius:var(--r);font-family:var(--font);font-size:13px;background:var(--white);outline:none"/>
      <input type="date" id="filterSampai" onchange="loadOrders(1)" title="Sampai tanggal"
        style="width:auto;padding:9px 10px;border:1.5px solid rgba(27,45,90,.14);border-radius:var(--r);font-family:var(--font);font-size:13px;background:var(--white);outline:none"/>
      <button class="btn btn-outline btn-sm" onclick="resetFilter()" title="Reset filter">✕ Reset</button>
      <button class="btn btn-teal-sm" onclick="loadOrders(1)">↻</button>
      <a href="pos.php" class="btn btn-teal-sm">+ Order Baru</a>
    </div>
  </div>

  <!-- TABLE -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">📋 Daftar Order Laundry</div>
      <span id="tableInfo" style="font-size:12px;color:var(--gray)"></span>
    </div>
    <!-- BULK TOOLBAR -->
    <div id="bulkToolbar" style="display:none;background:#0F1C3A;color:#fff;padding:10px 16px;align-items:center;gap:12px;flex-wrap:wrap">
      <span style="font-size:13px;font-weight:600"><span id="bulkCount">0</span> order dipilih</span>
      <select id="bulkStatus" style="padding:6px 10px;border-radius:7px;border:none;font-size:13px">
        <option value="">— Pilih status baru —</option>
        <option value="masuk">📥 Masuk</option>
        <option value="cuci">🫧 Cuci</option>
        <option value="kering">💨 Kering</option>
        <option value="setrika">👔 Setrika</option>
        <option value="siap">✅ Siap</option>
        <option value="diambil">📦 Diambil</option>
      </select>
      <button class="btn btn-teal-sm btn-sm" onclick="applyBulkStatus()">✓ Terapkan</button>
      <button class="btn btn-outline btn-sm" style="background:rgba(255,255,255,.1);color:#fff;border-color:rgba(255,255,255,.2)" onclick="clearBulkSelection()">✕ Batal</button>
    </div>
    <div class="table-wrap">
      <table class="hl-stack-mobile">
        <thead>
          <tr>
            <th style="width:34px;text-align:center"><input type="checkbox" id="bulkAll" onclick="toggleAllBulk(this)" title="Pilih semua di halaman ini"/></th>
            <th class="th-sort" onclick="setSort('no_order')" id="th_no_order">No. Order <span class="sort-icon">↕</span></th>
            <th class="th-sort" onclick="setSort('tanggal')" id="th_tanggal">Tanggal <span class="sort-icon">↕</span></th>
            <th class="th-sort" onclick="setSort('nama_pelanggan')" id="th_nama_pelanggan">Pelanggan <span class="sort-icon">↕</span></th>
            <th>Layanan</th>
            <th>Status Proses</th>
            <th>Status Bayar</th>
            <th class="th-sort" onclick="setSort('total')" id="th_total" style="text-align:right">Total <span class="sort-icon">↕</span></th>
            <th style="text-align:right">Sisa</th>
            <th class="th-sort" onclick="setSort('estimasi_selesai')" id="th_estimasi_selesai">Est. Selesai <span class="sort-icon">↕</span></th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody id="tableBody">
          <tr><td colspan="11"><div class="loading">⏳ Memuat data...</div></td></tr>
        </tbody>
      </table>
    </div>
    <div id="ordersPaging" style="padding:12px 16px;border-top:1px solid var(--light)"></div>
  </div>

</div>

<!-- MODAL PEMBAYARAN -->
<div class="modal-overlay" id="modalBayar" style="align-items:center;justify-content:center;padding:20px">
  <div class="modal" style="height:auto;max-height:90vh;width:480px">
    <div class="modal-header">
      <span class="modal-title">💰 Update Pembayaran</span>
      <button class="modal-close" onclick="closeBayarModal()">✕</button>
    </div>
    <div class="modal-body">
      <div id="bayarInfo" style="background:var(--off);border-radius:var(--r);padding:12px 14px;margin-bottom:16px;font-size:13px"></div>

      <div class="pay-opt">
        <button class="pay-btn selected" id="btnSebagian" onclick="selectTipe('sebagian')">
          <span class="pay-icon">⚡</span>
          <div class="pay-label">Bayar Sebagian</div>
          <div class="pay-sub">Input nominal yang dibayar</div>
        </button>
        <button class="pay-btn" id="btnLunas" onclick="selectTipe('lunas')">
          <span class="pay-icon">✅</span>
          <div class="pay-label">Lunas Sekarang</div>
          <div class="pay-sub">Bayar semua sisa tagihan</div>
        </button>
      </div>

      <div id="nominalWrap" class="form-group">
        <label>Jumlah Dibayar (Rp) <span class="req">*</span></label>
        <input type="number" id="bayarJumlah" placeholder="0" min="0" step="500"
          oninput="updateBayarPreview()"/>
        <div id="quickNominal" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px"></div>
        <div id="bayarPreview" style="margin-top:8px;border-radius:var(--r);padding:10px 12px;display:none;font-size:13px"></div>
      </div>

      <div id="pembulatanWrap" style="display:none;margin-bottom:14px">
        <label style="font-size:11px;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.05em">Pembulatan (Cash)</label>
        <div style="display:flex;gap:6px;margin-top:6px">
          <button class="btn btn-outline btn-sm" onclick="setPembulatan(500)">ke 500</button>
          <button class="btn btn-outline btn-sm" onclick="setPembulatan(1000)">ke 1.000</button>
          <button class="btn btn-outline btn-sm" onclick="setPembulatan(2000)">ke 2.000</button>
          <button class="btn btn-outline btn-sm" onclick="setPembulatan(5000)">ke 5.000</button>
          <button class="btn btn-outline btn-sm" onclick="setPembulatan(10000)">ke 10.000</button>
        </div>
        <div id="pembulatanInfo" style="font-size:12px;color:var(--gray);margin-top:6px"></div>
      </div>

      <div class="form-group">
        <label>Metode Pembayaran</label>
        <select id="bayarMetode" onchange="onMetodeChange()">
          <option value="cash">💵 Cash</option>
          <option value="transfer">🏦 Transfer Bank</option>
          <option value="qris">📱 QRIS</option>
        </select>
      </div>

      <div class="form-group">
        <label>Bukti Pembayaran (opsional)</label>
        <div class="bukti-drop" onclick="document.getElementById('buktiFile').click()">
          <div style="font-size:1.5rem">📎</div>
          <p>Klik untuk upload foto bukti transfer/QRIS</p>
          <p style="font-size:11px">JPG, PNG, maks 5MB</p>
        </div>
        <input type="file" id="buktiFile" accept="image/*" style="display:none"
          onchange="previewBukti(this)"/>
        <img id="buktiPreview" class="bukti-preview"/>
        <div id="buktiName" style="font-size:12px;color:var(--teal);margin-top:4px"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline btn-sm" onclick="closeBayarModal()">Batal</button>
      <button class="btn btn-primary btn-sm" onclick="submitBayar()">💾 Simpan Pembayaran</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modalDetail">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title" id="modalTitle">Detail Order</span>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body" id="modalBody">
      <div class="loading">⏳ Memuat...</div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline btn-sm" onclick="closeModal()">Tutup</button>
      <button class="btn btn-teal-sm btn-sm" id="btnBayarDariDetail" onclick="openBayarFromDetail()">💰 Update Bayar</button>
      <button class="btn btn-primary btn-sm" id="btnSaveEdit" onclick="saveEdit()">💾 Simpan Perubahan</button>
    </div>
  </div>
</div>

<!-- MODAL CETAK ULANG NOTA -->
<div class="modal-overlay" id="modalCetak" style="align-items:center;justify-content:center;padding:20px">
  <div class="modal" style="height:auto;max-height:90vh;width:420px">
    <div class="modal-header">
      <span class="modal-title">🖨️ Cetak Ulang Nota</span>
      <button class="modal-close" onclick="closeCetakModal()">✕</button>
    </div>
    <div class="modal-body">
      <div id="strukCetakUlang"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline btn-sm" onclick="closeCetakModal()">Tutup</button>
      <button class="btn btn-primary btn-sm" onclick="doPrint()">🖨️ Print</button>
    </div>
  </div>
</div>

<!-- MODAL WA REMINDER -->
<div class="modal-overlay" id="modalWA" style="align-items:center;justify-content:center;padding:20px">
  <div class="modal" style="height:auto;max-height:90vh;width:480px">
    <div class="modal-header">
      <span class="modal-title">📱 Kirim WhatsApp</span>
      <button class="modal-close" onclick="closeWAModal()">✕</button>
    </div>
    <div class="modal-body">
      <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
        <button class="btn btn-teal-sm btn-sm wa-type-btn active" onclick="selectWAType('reminder',this)">🔄 Update Status</button>
        <button class="btn btn-outline btn-sm wa-type-btn" onclick="selectWAType('siap',this)">✅ Siap Diambil</button>
        <button class="btn btn-outline btn-sm wa-type-btn" onclick="selectWAType('lunas_reminder',this)">💰 Tagihan</button>
      </div>
      <p style="font-size:12px;color:var(--gray);margin-bottom:10px">👁️ Preview pesan:</p>
      <div class="wa-bubble" id="waBubble">Memuat...</div>
      <div style="font-size:12px;color:var(--gray)">
        📱 Nomor: <strong id="waPhone">-</strong>
        &nbsp;·&nbsp;
        <a id="waTrackLink" href="#" target="_blank" style="color:var(--teal);font-size:12px">🔗 Link Tracking</a>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline btn-sm" onclick="closeWAModal()">Tutup</button>
      <button class="btn btn-primary btn-sm" onclick="kirimWA()">📲 Buka WhatsApp</button>
    </div>
  </div>
</div>

<script>
let searchTimer = null;
let currentEditId = null;
let editItems = [];
let layananAll = [];

document.addEventListener('DOMContentLoaded', () => {
  initFilter('orderFilter');
  loadSummary();
  loadOrders();
  loadLayanan();
});

// ── LOAD ──────────────────────────────────────────────
async function loadSummary() {
  const r = await fetch('orders.php?action=summary');
  const d = await r.json();
  const total = Object.values(d.statuses).reduce((a,b) => a + +b, 0);
  document.getElementById('sAll').textContent     = total;
  document.getElementById('sMasuk').textContent   = d.statuses.masuk   || 0;
  document.getElementById('sCuci').textContent    = d.statuses.cuci    || 0;
  document.getElementById('sKering').textContent  = d.statuses.kering  || 0;
  document.getElementById('sSiap').textContent    = d.statuses.siap    || 0;
  document.getElementById('sDiambil').textContent = d.statuses.diambil || 0;
}

async function loadLayanan() {
  const r = await fetch('orders.php?action=get_layanan');
  layananAll = await r.json();
}

// ── BULK SELECTION ────────────────────────────────────
function onBulkCbChange() {
  const sel = document.querySelectorAll('.bulkCb:checked').length;
  document.getElementById('bulkCount').textContent = sel;
  document.getElementById('bulkToolbar').style.display = sel > 0 ? 'flex' : 'none';
  // Sync header checkbox tri-state
  const total = document.querySelectorAll('.bulkCb').length;
  const all = document.getElementById('bulkAll');
  if (all) {
    all.checked = (sel > 0 && sel === total);
    all.indeterminate = (sel > 0 && sel < total);
  }
}
function toggleAllBulk(cb) {
  document.querySelectorAll('.bulkCb').forEach(x => x.checked = cb.checked);
  onBulkCbChange();
}
function clearBulkSelection() {
  document.querySelectorAll('.bulkCb').forEach(x => x.checked = false);
  const all = document.getElementById('bulkAll'); if (all) { all.checked=false; all.indeterminate=false; }
  onBulkCbChange();
}
async function applyBulkStatus() {
  const status = document.getElementById('bulkStatus').value;
  if (!status) { showToast('Pilih status dulu','error'); return; }
  const ids = Array.from(document.querySelectorAll('.bulkCb:checked')).map(x => parseInt(x.value));
  if (!ids.length) { showToast('Tidak ada order dipilih','error'); return; }
  if (!confirm('Update status ' + ids.length + ' order menjadi "' + status + '"?')) return;
  try {
    const r = await fetch('orders.php?action=bulk_status', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({ids, status})
    });
    const d = await r.json();
    if (d.error) { showToast(d.error,'error'); return; }
    showToast('✓ ' + (d.affected||ids.length) + ' order diupdate');
    clearBulkSelection();
    loadOrders(ordersCurrentPage);
  } catch (e) { showToast('Network error','error'); }
}

let ordersCurrentPage = 1;
let ordersTotalPages  = 1;
let ordersSort        = 'tanggal';
let ordersSortDir     = 'desc';

function setSort(col) {
  if (ordersSort === col) {
    ordersSortDir = ordersSortDir === 'asc' ? 'desc' : 'asc';
  } else {
    ordersSort    = col;
    ordersSortDir = col === 'tanggal' ? 'desc' : 'asc';
  }
  document.querySelectorAll('.th-sort').forEach(th => th.classList.remove('asc','desc'));
  const th = document.getElementById('th_' + col);
  if (th) th.classList.add(ordersSortDir);
  loadOrders(1);
}

async function loadOrders(page=1) {
  ordersCurrentPage = page;
  const q      = document.getElementById('searchInput').value;
  const st     = document.getElementById('filterStatus').value;
  const by     = document.getElementById('filterBayar').value;
  const dari   = document.getElementById('filterDari').value;
  const sampai = document.getElementById('filterSampai').value;
  const sumber = document.getElementById('filterSumber')?.value || '';

  // Skeleton: 6 row table skeleton
  document.getElementById('tableBody').innerHTML = Array.from({length:6}).map(()=>`
    <tr><td colspan="11" style="padding:0;border-bottom:1px solid var(--light)">
      <div class="hl-skel-row" style="padding:14px 12px">
        <span class="hl-skel" style="width:90px"></span>
        <span class="hl-skel" style="width:70px"></span>
        <span class="hl-skel" style="width:140px"></span>
        <span class="hl-skel" style="width:120px;display:none" class="hide-sm"></span>
        <span class="hl-skel" style="width:70px;margin-left:auto"></span>
      </div>
    </td></tr>`).join('');

  const r = await fetch(`orders.php?action=list&q=${encodeURIComponent(q)}&status=${st}&bayar=${by}&dari=${dari}&sampai=${sampai}&sumber=${sumber}&page=${page}&sort=${ordersSort}&dir=${ordersSortDir}`);
  const d = await r.json();

  if (!d.data?.length) {
    document.getElementById('tableBody').innerHTML = `<tr><td colspan="11" style="padding:0">
      <div class="hl-empty-v2" style="margin:14px;background:transparent;border:0">
        <div class="e-icon">📭</div>
        <div class="e-title">Tidak ada order</div>
        <div class="e-sub">Coba ubah filter atau tanggal pencarian</div>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="resetFilter()">↻ Reset Filter</button>
      </div></td></tr>`;
    document.getElementById('tableInfo').textContent = '';
    document.getElementById('ordersPaging').innerHTML = '';
    return;
  }

  document.getElementById('tableBody').innerHTML = d.data.map(row => {
    const sisaColor = parseFloat(row.sisa_bayar) > 0 ? 'var(--red)' : 'var(--green)';
    const sisaText  = parseFloat(row.sisa_bayar) > 0 ? 'Rp ' + parseFloat(row.sisa_bayar).toLocaleString('id-ID') : '&#10003;';
    const telp      = row.telepon ? '<div style="font-size:11px;color:var(--gray)">' + row.telepon + '</div>' : '';
    const est       = row.estimasi_selesai ? fmtDate(row.estimasi_selesai) : '-';
    const bayarBtn  = parseFloat(row.sisa_bayar) > 0
      ? `<button class="btn btn-teal-sm" onclick="openBayarById(${row.id})">&#128176; Bayar</button>`
      : '<span style="font-size:11px;color:var(--green);padding:4px">&#10003; Lunas</span>';

    return '<tr onclick="openDetail(' + row.id + ')">'
      + '<td onclick="event.stopPropagation()" style="text-align:center">'
      +   '<input type="checkbox" class="bulkCb" value="' + row.id + '" onclick="onBulkCbChange()"/>'
      + '</td>'
      + '<td data-lbl="No Order"><span class="td-no">' + row.no_order + '</span></td>'
      + '<td data-lbl="Tanggal">' + fmtDate(row.tanggal) + '</td>'
      + '<td data-lbl="Pelanggan"><div class="td-nama">' + esc(row.nama_pelanggan)
      +   (row.nama_mitra ? ' <span style="font-size:9px;font-weight:700;background:#FEF3C7;color:#92400E;padding:2px 7px;border-radius:100px;margin-left:4px">📦 ' + esc(row.nama_mitra) + '</span>' : '')
      +   '</div>' + telp + '</td>'
      + '<td data-lbl="Layanan"><div class="td-layanan">' + esc(row.layanan_list||'-') + '</div></td>'
      + '<td data-lbl="Status"><span class="badge b-' + row.status_proses + '">' + statusLabel(row.status_proses) + '</span></td>'
      + '<td data-lbl="Bayar"><span class="badge b-' + row.status_bayar + '">' + bayarLabel(row.status_bayar) + '</span></td>'
      + '<td data-lbl="Total" class="td-total">Rp ' + parseFloat(row.total).toLocaleString('id-ID') + '</td>'
      + '<td data-lbl="Sisa" style="font-family:var(--mono);font-size:12px;text-align:right;color:' + sisaColor + '">' + sisaText + '</td>'
      + '<td data-lbl="Estimasi" style="font-size:12px;color:var(--gray)">' + est + '</td>'
      + '<td onclick="event.stopPropagation()">'
      + '<div class="action-btns">'
      + bayarBtn
      + '<button class="btn btn-outline" onclick="cetakUlang(' + row.id + ')" title="Cetak Ulang">&#128424;&#65039;</button>'
      + '<button class="btn btn-outline" onclick="openWAModal(' + row.id + ')" title="Kirim WA">&#128241;</button>'
      + '</div></td>'
      + '</tr>';
  }).join('');

  ordersTotalPages = d.total_pages;
  document.getElementById('tableInfo').textContent = `${d.total} order · halaman ${page} dari ${d.total_pages}`;
  // Reset bulk selection on reload
  const btb = document.getElementById('bulkToolbar'); if (btb) btb.style.display = 'none';
  const ball = document.getElementById('bulkAll'); if (ball) { ball.checked=false; ball.indeterminate=false; }
  renderOrdersPaging(d.page, d.total_pages);
}

function renderOrdersPaging(page, total) {
  const el = document.getElementById('ordersPaging');
  if (!el || total <= 1) { if(el) el.innerHTML=''; return; }

  let html = '<div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:16px;flex-wrap:wrap">';
  html += `<button class="btn btn-outline btn-sm" onclick="loadOrders(${page-1})" ${page===1?'disabled':''}>← Prev</button>`;

  const start = Math.max(1, page-2);
  const end   = Math.min(total, page+2);
  if (start > 1) html += `<button class="btn btn-outline btn-sm" onclick="loadOrders(1)">1</button>`;
  if (start > 2) html += `<span style="color:var(--gray);padding:0 4px">...</span>`;
  for (let i=start; i<=end; i++) {
    html += `<button class="btn ${i===page?'btn-teal-sm':'btn-outline btn-sm'}" onclick="loadOrders(${i})">${i}</button>`;
  }
  if (end < total-1) html += `<span style="color:var(--gray);padding:0 4px">...</span>`;
  if (end < total)   html += `<button class="btn btn-outline btn-sm" onclick="loadOrders(${total})">${total}</button>`;
  html += `<button class="btn btn-outline btn-sm" onclick="loadOrders(${page+1})" ${page===total?'disabled':''}>Next →</button>`;
  html += '</div>';
  el.innerHTML = html;
}

function resetFilter() {
  document.getElementById('filterDari').value   = '';
  document.getElementById('filterSampai').value = '';
  document.getElementById('filterStatus').value = '';
  document.getElementById('filterBayar').value  = '';
  const fs = document.getElementById('filterSumber'); if (fs) fs.value = '';
  document.getElementById('searchInput').value  = '';
  loadOrders(1);
}

// ── DETAIL / EDIT MODAL ───────────────────────────────
async function openDetail(id) {
  currentEditId = id;
  document.getElementById('modalBody').innerHTML = '<div class="loading">⏳ Memuat...</div>';
  document.getElementById('modalDetail').classList.add('open');

  const r = await fetch('orders.php?action=get&id=' + id);
  const d = await r.json();
  if (d.error) { document.getElementById('modalBody').innerHTML = '<div class="empty">❌ ' + d.error + '</div>'; return; }

  editItems = d.items || [];
  document.getElementById('modalTitle').textContent = '📋 ' + d.no_order;

  const statuses = [
    ['masuk','📥 Masuk'],['cuci','🫧 Cuci'],['kering','💨 Kering'],
    ['setrika','👔 Setrika'],['siap','✅ Siap'],['diambil','📦 Diambil']
  ];

  document.getElementById('modalBody').innerHTML = `
    <div style="background:var(--off);border-radius:var(--r);padding:12px 14px;margin-bottom:16px;font-size:13px">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
        <div><span style="color:var(--gray)">Pelanggan: </span><strong>${esc(d.nama_pelanggan)}</strong></div>
        <div><span style="color:var(--gray)">Telepon: </span>${d.telepon||'-'}</div>
        <div><span style="color:var(--gray)">Tanggal: </span>${fmtDate(d.tanggal)}</div>
        <div><span style="color:var(--gray)">Dibuat oleh: </span>${d.created_by||'-'}</div>
      </div>
    </div>

    <div class="section-title">🔄 Status Proses</div>
    <div class="proses-steps" id="prosesSteps">
      ${statuses.map(([v,l]) => `<button class="step-btn ${d.status_proses===v?'active':''}" onclick="setProses('${v}',this)">${l}</button>`).join('')}
    </div>
    <input type="hidden" id="edit_status_proses" value="${d.status_proses}"/>

    <div class="section-title">🧺 Layanan</div>
    <div style="overflow-x:auto;margin-bottom:8px">
      <table class="items-table">
        <thead><tr>
          <th>Layanan</th><th>Sat</th><th>Jml</th><th>Harga</th><th>Subtotal</th><th>Ket</th><th></th>
        </tr></thead>
        <tbody id="editItemsBody"></tbody>
      </table>
    </div>
    <button class="btn btn-teal-sm btn-sm" onclick="addEditRow()" style="margin-bottom:12px">+ Tambah Item</button>

    <div style="margin-bottom:12px">
      <input type="text" placeholder="🔍 Cari & tambah layanan..." oninput="filterEditLayanan(this.value)" id="editLayananSearch"
        style="margin-bottom:6px;font-size:13px;padding:7px 10px"/>
      <div id="editLayananGrid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:5px;max-height:150px;overflow-y:auto"></div>
    </div>

    <div class="total-box" id="editTotalBox">
      <div class="tb-row"><span class="tb-label">Subtotal</span><span class="tb-value" id="etSubtotal">-</span></div>
      <div class="tb-row"><span class="tb-label">Diskon</span><span class="tb-value">- Rp <input type="number" id="edit_diskon" value="${d.diskon||0}" min="0" step="500" oninput="recalcEdit()" style="width:80px;background:transparent;border:none;border-bottom:1px solid rgba(255,255,255,.3);color:white;font-family:var(--mono);font-size:13px;padding:0;outline:none"/></span></div>
      <div class="tb-row tb-total"><span style="color:white;font-weight:700">TOTAL</span><span class="tb-value tb-big" id="etTotal">-</span></div>
      <div class="tb-row"><span class="tb-label">DP/Bayar</span><span class="tb-value">Rp <input type="number" id="edit_dp" value="${d.dp||0}" min="0" step="1000" oninput="recalcEdit()" style="width:90px;background:transparent;border:none;border-bottom:1px solid rgba(255,255,255,.3);color:white;font-family:var(--mono);font-size:13px;padding:0;outline:none"/></span></div>
      <div class="tb-row"><span class="tb-label">Sisa Bayar</span><span class="tb-value tb-sisa" id="etSisa">-</span></div>
    </div>

    <div class="form-row" style="margin-bottom:12px">
      <div class="form-group">
        <label>Metode Bayar</label>
        <select id="edit_metode">
          ${['cash','transfer','qris'].map(m=>`<option value="${m}" ${d.metode_bayar===m?'selected':''}>${m.toUpperCase()}</option>`).join('')}
        </select>
      </div>
      <div class="form-group">
        <label>Estimasi Selesai</label>
        <input type="date" id="edit_estimasi" value="${d.estimasi_selesai||''}"/>
      </div>
    </div>

    <div class="section-title">📝 Catatan</div>
    <div class="form-group">
      <label>Catatan untuk Pelanggan</label>
      <textarea id="edit_catatan">${esc(d.catatan||'')}</textarea>
    </div>
    <div class="form-group">
      <label>Catatan Internal</label>
      <textarea id="edit_catatan_internal" placeholder="Catatan hanya untuk tim...">${esc(d.catatan_internal||'')}</textarea>
    </div>

    <div class="section-title">🗒️ Catatan Internal (Tim) <span style="font-size:11px;font-weight:500;color:var(--gray)">— riwayat per-user</span></div>
    <div id="notesList" style="margin-bottom:8px;max-height:200px;overflow-y:auto;border:1px solid rgba(27,45,90,.1);border-radius:8px;padding:8px;background:var(--off)">
      <div style="color:var(--gray);font-size:13px">⏳ Memuat catatan...</div>
    </div>
    <div style="display:flex;gap:6px;margin-bottom:16px">
      <input type="text" id="noteInput" placeholder="Tulis catatan tim (akan tercatat siapa & kapan)..." style="flex:1;font-size:13px;padding:8px 10px;border:1px solid rgba(27,45,90,.15);border-radius:7px"/>
      <button class="btn btn-teal-sm btn-sm" onclick="addNote()" style="padding:8px 14px">+ Tambah</button>
    </div>

    <div class="section-title">📜 Riwayat Status</div>
    <div id="logList">
      ${(d.logs||[]).length ? (d.logs||[]).map(l => {
        const icons = {proses:'🔄',bayar:'💰',items:'🧺',catatan:'📝',bukti:'📎'};
        const icon = icons[l.tipe] || '📌';
        return `<div class="log-item">
          <span class="log-time">${fmtDateTime(l.created_at)}</span>
          <span class="log-text">${icon} ${esc(l.catatan||'')} <span style="color:var(--gray);font-size:11px">· ${esc(l.oleh||'-')}</span></span>
        </div>`;
      }).join('') : '<div style="color:var(--gray);font-size:13px;padding:8px 0">Belum ada riwayat perubahan</div>'}
    </div>`;

  renderEditItems();
  renderEditLayananGrid(layananAll);
  recalcEdit();
  loadNotes(id);
}

// ── CATATAN INTERNAL MULTI-ROW ────────────────────────
async function loadNotes(orderId) {
  const box = document.getElementById('notesList');
  if (!box) return;
  try {
    const r = await fetch('orders.php?action=notes_list&order_id=' + orderId);
    const d = await r.json();
    const rows = d.rows || [];
    if (!rows.length) {
      box.innerHTML = '<div style="color:var(--gray);font-size:13px;text-align:center;padding:6px">Belum ada catatan tim</div>';
      return;
    }
    box.innerHTML = rows.map(n => `
      <div style="padding:7px 0;border-bottom:1px dashed rgba(27,45,90,.08)">
        <div style="font-size:13px;color:var(--navy);white-space:pre-wrap">${esc(n.catatan)}</div>
        <div style="font-size:11px;color:var(--gray);margin-top:3px">
          ✍️ ${esc(n.user_nama || '-')} · ${fmtDateTime(n.created_at)}
        </div>
      </div>`).join('');
  } catch (e) {
    box.innerHTML = '<div style="color:#dc2626;font-size:12px">❌ Gagal memuat catatan</div>';
  }
}

async function addNote() {
  if (!currentEditId) return;
  const inp = document.getElementById('noteInput');
  const v = (inp.value || '').trim();
  if (!v) { showToast('Tulis catatan dulu', 'error'); return; }
  try {
    const r = await fetch('orders.php?action=note_add', {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({order_id: currentEditId, catatan: v})
    });
    const d = await r.json();
    if (d.error) { showToast(d.error, 'error'); return; }
    inp.value = '';
    loadNotes(currentEditId);
    showToast('✓ Catatan ditambahkan');
  } catch (e) {
    showToast('Network error', 'error');
  }
}

function closeModal() {
  document.getElementById('modalDetail').classList.remove('open');
  currentEditId = null;
  editItems = [];
}

function openBayarFromDetail() {
  if (!currentEditId) return;
  openBayarById(currentEditId);
}

async function openBayarById(id) {
  const r = await fetch('orders.php?action=get&id=' + id);
  const d = await r.json();
  if (d.id) openBayarModal(d.id, d.nama_pelanggan, d.total, d.dp||0, d.sisa_bayar||0);
}

// ── PROSES STEPS ──────────────────────────────────────
function setProses(val, el) {
  document.getElementById('edit_status_proses').value = val;
  document.querySelectorAll('.step-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
}

// ── EDIT ITEMS ────────────────────────────────────────
function renderEditItems() {
  const tbody = document.getElementById('editItemsBody');
  if (!tbody) return;
  tbody.innerHTML = editItems.map((item, i) => `
    <tr>
      <td><input class="item-input" value="${esc(item.nama_layanan)}" style="width:110px" oninput="editItems[${i}].nama_layanan=this.value;recalcEdit()"/></td>
      <td><select class="item-input" style="width:52px" onchange="editItems[${i}].satuan=this.value">
        ${['kg','pcs','set','pasang'].map(s=>`<option value="${s}" ${item.satuan===s?'selected':''}>${s}</option>`).join('')}
      </select></td>
      <td><input class="item-input" type="number" value="${item.jumlah}" step="0.1" min="0" style="width:52px" oninput="editItems[${i}].jumlah=parseFloat(this.value)||0;recalcEdit()"/></td>
      <td><input class="item-input" type="number" value="${item.harga_satuan}" step="500" min="0" style="width:80px" oninput="editItems[${i}].harga_satuan=parseFloat(this.value)||0;recalcEdit()"/></td>
      <td class="item-sub">Rp ${(item.jumlah*item.harga_satuan).toLocaleString('id-ID')}</td>
      <td><input class="item-input" value="${esc(item.catatan_item||'')}" placeholder="..." style="width:60px" oninput="editItems[${i}].catatan_item=this.value"/></td>
      <td><button class="btn-remove" onclick="removeEditItem(${i})">✕</button></td>
    </tr>`).join('');
}

function addEditRow() {
  editItems.push({layanan_id:null,nama_layanan:'',satuan:'kg',jumlah:1,harga_satuan:0,catatan_item:''});
  renderEditItems(); recalcEdit();
}
function removeEditItem(i) { editItems.splice(i,1); renderEditItems(); recalcEdit(); }

function renderEditLayananGrid(list) {
  const grid = document.getElementById('editLayananGrid');
  if (!grid) return;
  grid.innerHTML = (list||[]).map(l => `
    <button style="padding:6px 8px;background:var(--off);border:1px solid rgba(27,45,90,.1);border-radius:7px;cursor:pointer;text-align:left;font-family:var(--font);transition:all .2s"
      onmouseover="this.style.borderColor='var(--teal)'" onmouseout="this.style.borderColor='rgba(27,45,90,.1)'"
      onclick="addEditLayanan(${l.id},'${esc(l.nama)}','${l.satuan}',${l.harga})">
      <div style="font-size:11px;font-weight:600;color:var(--navy)">${esc(l.nama)}</div>
      <div style="font-size:10px;color:var(--teal-d);font-family:var(--mono)">Rp ${parseFloat(l.harga).toLocaleString('id-ID')}</div>
    </button>`).join('');
}

function filterEditLayanan(q) {
  const filtered = q ? layananAll.filter(l=>l.nama.toLowerCase().includes(q.toLowerCase())) : layananAll;
  renderEditLayananGrid(filtered);
}

function addEditLayanan(id, nama, satuan, harga) {
  editItems.push({layanan_id:id,nama_layanan:nama,satuan,jumlah:1,harga_satuan:harga,catatan_item:''});
  renderEditItems(); recalcEdit();
}

function recalcEdit() {
  const sub  = editItems.reduce((s,i) => s + i.jumlah * i.harga_satuan, 0);
  const dis  = parseFloat(document.getElementById('edit_diskon')?.value) || 0;
  const tot  = Math.max(sub - dis, 0);
  const dp   = parseFloat(document.getElementById('edit_dp')?.value) || 0;
  const sisa = tot - dp;
  const subEl = document.getElementById('etSubtotal');
  const totEl = document.getElementById('etTotal');
  const sisEl = document.getElementById('etSisa');
  if (subEl) subEl.textContent = 'Rp ' + sub.toLocaleString('id-ID');
  if (totEl) totEl.textContent = 'Rp ' + tot.toLocaleString('id-ID');
  if (sisEl) sisEl.textContent = 'Rp ' + sisa.toLocaleString('id-ID');
  const cells = document.querySelectorAll('.item-sub');
  editItems.forEach((item,i) => { if(cells[i]) cells[i].textContent = 'Rp ' + (item.jumlah*item.harga_satuan).toLocaleString('id-ID'); });
}

// ── SAVE EDIT ─────────────────────────────────────────
async function saveEdit() {
  if (!currentEditId) return;
  const btn = document.getElementById('btnSaveEdit');
  btn.disabled = true; btn.textContent = '⏳ Menyimpan...';

  const payload = {
    id:               currentEditId,
    status_proses:    document.getElementById('edit_status_proses').value,
    catatan:          document.getElementById('edit_catatan').value,
    catatan_internal: document.getElementById('edit_catatan_internal').value,
    metode_bayar:     document.getElementById('edit_metode').value,
    diskon:           document.getElementById('edit_diskon').value,
    dp:               document.getElementById('edit_dp').value,
    estimasi:         document.getElementById('edit_estimasi').value,
    items:            editItems
  };

  const r = await fetch('orders.php?action=update', {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify(payload)
  });
  const d = await r.json();

  if (d.success) {
    showToast('✅ Order berhasil diupdate!', 'success');
    closeModal();
    loadOrders();
    loadSummary();
  } else {
    showToast('❌ ' + (d.error||'Gagal'), 'error');
  }
  btn.disabled = false; btn.textContent = '💾 Simpan Perubahan';
}

// ── FILTER ────────────────────────────────────────────
function filterByStatus(s) {
  document.getElementById('filterStatus').value = s;
  loadOrders();
}
function debounce() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => loadOrders(1), 400);
}

// ── HELPERS ───────────────────────────────────────────
function statusLabel(s){return{'masuk':'📥 Masuk','cuci':'🫧 Cuci','kering':'💨 Kering','setrika':'👔 Setrika','siap':'✅ Siap','diambil':'📦 Diambil'}[s]||s}
function bayarLabel(s){return{'lunas':'✅ Lunas','dp':'⚡ DP','belum_bayar':'⏳ Belum Bayar'}[s]||s}
function fmtDate(d){if(!d)return'-';return new Date(d).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}
function fmtDateTime(d){if(!d)return'-';return new Date(d).toLocaleString('id-ID',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
function showToast(msg,type='success'){const t=document.getElementById('toast');t.textContent=msg;t.className='toast '+type+' show';setTimeout(()=>t.className='toast',3500)}

// ── CETAK ULANG ───────────────────────────────────────
async function cetakUlang(id) {
  document.getElementById('strukCetakUlang').innerHTML = '<div style="text-align:center;padding:20px;color:var(--gray)">⏳ Memuat...</div>';
  document.getElementById('modalCetak').classList.add('open');

  const r = await fetch('orders.php?action=get_struk&id=' + id);
  const d = await r.json();
  if (d.error) { document.getElementById('strukCetakUlang').innerHTML = '<div style="color:red">❌ ' + d.error + '</div>'; return; }

  const isFull = parseFloat(d.dp) >= parseFloat(d.total);
  const metodeTxt = {'cash':'Cash','transfer':'Transfer Bank','qris':'QRIS'}[d.metode_bayar] || d.metode_bayar;
  const statusProsesLabel = {'masuk':'Diterima','cuci':'Sedang Dicuci','kering':'Sedang Dikeringkan','setrika':'Sedang Disetrika','siap':'Siap Diambil','diambil':'Sudah Diambil'}[d.status_proses] || d.status_proses;

  const itemRows = (d.items||[]).map(item => `
    <div class="struk-item">
      ${item.nama_layanan}
      <br>&nbsp;&nbsp;${parseFloat(item.jumlah).toLocaleString('id-ID')} ${item.satuan} × Rp ${parseFloat(item.harga_satuan).toLocaleString('id-ID')}
      ${item.catatan_item ? '<br>&nbsp;&nbsp;<em>Ket: ' + item.catatan_item + '</em>' : ''}
    </div>
    <div class="struk-row">
      <span></span>
      <span>Rp ${parseFloat(item.subtotal).toLocaleString('id-ID')}</span>
    </div>`).join('');

  document.getElementById('strukCetakUlang').innerHTML = `
    <div class="struk">
      <div class="struk-header">
        <h2>🫧 HARPY LAUNDRY</h2>
        <p>Jl. Rawa Selatan IV No.1, Johar Baru</p>
        <p>Jakarta Pusat | +62 896-1525-9302</p>
        <p>harpy.id</p>
        <p style="margin-top:4px;font-size:10px">— SALINAN NOTA —</p>
      </div>
      <div class="struk-row"><span>No. Order</span><span>${d.no_order}</span></div>
      <div class="struk-row"><span>Tanggal</span><span>${fmtDate(d.tanggal)}</span></div>
      <div class="struk-row"><span>Pelanggan</span><span>${esc(d.nama_pelanggan)}</span></div>
      ${d.telepon ? `<div class="struk-row"><span>Telp</span><span>${d.telepon}</span></div>` : ''}
      ${d.estimasi_selesai ? `<div class="struk-row"><span>Est. Selesai</span><span>${fmtDate(d.estimasi_selesai)}</span></div>` : ''}
      <div class="struk-row"><span>Status</span><span>${statusProsesLabel}</span></div>
      <hr class="struk-divider"/>
      ${itemRows}
      <hr class="struk-divider"/>
      <div class="struk-row"><span>Subtotal</span><span>Rp ${parseFloat(d.subtotal||d.total).toLocaleString('id-ID')}</span></div>
      ${parseFloat(d.diskon||0)>0 ? `<div class="struk-row"><span>Diskon</span><span>- Rp ${parseFloat(d.diskon).toLocaleString('id-ID')}</span></div>` : ''}
      <div class="struk-total">
        <div class="struk-row bold"><span>TOTAL</span><span>Rp ${parseFloat(d.total).toLocaleString('id-ID')}</span></div>
        <div class="struk-row"><span>Dibayar (${metodeTxt})</span><span>Rp ${parseFloat(d.dp||0).toLocaleString('id-ID')}</span></div>
        ${!isFull ? `<div class="struk-row bold"><span>SISA BAYAR</span><span>Rp ${parseFloat(d.sisa_bayar||0).toLocaleString('id-ID')}</span></div>` : ''}
      </div>
      ${d.catatan ? `<hr class="struk-divider"/><div style="font-size:11px">📝 ${esc(d.catatan)}</div>` : ''}
      <div class="struk-footer">
        <p>Status: ${isFull ? '✅ LUNAS' : '⚡ Belum Lunas'}</p>
        <p>Cek status: harpy.id/track.php</p>
        <p>Terima kasih telah mempercayakan</p>
        <p>cucian Anda kepada Harpy Laundry!</p>
      </div>
    </div>`;
}

function closeCetakModal() { document.getElementById('modalCetak').classList.remove('open'); }
function doPrint() { window.print(); }

// ── WA REMINDER ───────────────────────────────────────
let currentWAId = null;
let currentWAType = 'reminder';
let currentWAData = null;

async function openWAModal(id) {
  currentWAId = id;
  currentWAType = 'reminder';
  document.getElementById('waBubble').textContent = '⏳ Memuat...';
  document.getElementById('waPhone').textContent = '-';
  document.getElementById('modalWA').classList.add('open');
  await loadWAMessage();
}

function closeWAModal() { document.getElementById('modalWA').classList.remove('open'); currentWAId = null; }

async function selectWAType(type, el) {
  currentWAType = type;
  document.querySelectorAll('.wa-type-btn').forEach(b => {
    b.className = b.classList.contains('wa-type-btn') ? 'btn btn-outline btn-sm wa-type-btn' : b.className;
  });
  el.className = 'btn btn-teal-sm btn-sm wa-type-btn active';
  await loadWAMessage();
}

async function loadWAMessage() {
  if (!currentWAId) return;
  const r = await fetch(`orders.php?action=wa_message&id=${currentWAId}&tipe=${currentWAType}`);
  const d = await r.json();
  if (d.error) { document.getElementById('waBubble').textContent = '❌ ' + d.error; return; }
  currentWAData = d;

  const star = '*';
  const boldRegex = new RegExp('\\' + star + '([^' + star + ']+)\\' + star, 'g');
  const formatted = d.message
    .replace(boldRegex, '<strong>$1</strong>')
    .replace(/\n/g, '<br>');
  document.getElementById('waBubble').innerHTML = formatted;
  document.getElementById('waPhone').textContent = d.phone ? '+' + d.phone : 'Tidak ada nomor';
  document.getElementById('waTrackLink').href = 'track.php?order=' + encodeURIComponent(d.no_order);
}

function kirimWA() {
  if (!currentWAData) return;
  if (!currentWAData.phone) { showToast('⚠️ Nomor HP tidak tersedia', 'error'); return; }
  const url = 'https://wa.me/' + currentWAData.phone + '?text=' + encodeURIComponent(currentWAData.message);
  window.open(url, '_blank');
  closeWAModal();
  showToast('📲 WhatsApp dibuka!', 'success');
}

// ── PAYMENT MODAL ────────────────────────────────────
let currentBayarId = null;
let currentBayarData = null;
let currentTipeBayar = 'sebagian';

function openBayarModal(id, namaP, total, dp, sisa) {
  currentBayarId   = id;
  currentBayarData = {total: parseFloat(total), dp: parseFloat(dp), sisa: parseFloat(sisa)};
  currentTipeBayar = 'sebagian';

  document.getElementById('bayarInfo').innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;text-align:center">
      <div><div style="font-size:11px;color:var(--gray)">Total</div><div style="font-weight:700;font-family:var(--mono)">Rp ${parseFloat(total).toLocaleString('id-ID')}</div></div>
      <div><div style="font-size:11px;color:var(--gray)">Sudah Bayar</div><div style="font-weight:700;font-family:var(--mono);color:var(--green)">Rp ${parseFloat(dp).toLocaleString('id-ID')}</div></div>
      <div><div style="font-size:11px;color:var(--gray)">Sisa Tagihan</div><div style="font-weight:700;font-family:var(--mono);color:var(--red)">Rp ${parseFloat(sisa).toLocaleString('id-ID')}</div></div>
    </div>
    <div style="margin-top:8px;font-size:13px;font-weight:600;color:var(--navy)">Pelanggan: ${esc(namaP)}</div>`;

  document.getElementById('bayarJumlah').value = '';
  document.getElementById('bayarPreview').style.display = 'none';
  document.getElementById('buktiPreview').style.display = 'none';
  document.getElementById('buktiName').textContent = '';
  document.getElementById('buktiFile').value = '';
  document.getElementById('pembulatanInfo').textContent = '';
  selectTipe('sebagian');
  buildQuickNominal(parseFloat(sisa));
  document.getElementById('modalBayar').classList.add('open');
}

function closeBayarModal() {
  document.getElementById('modalBayar').classList.remove('open');
  currentBayarId = null;
}

function buildQuickNominal(sisa) {
  const roundUp = (n, to) => Math.ceil(n / to) * to;
  const opts = new Set([
    sisa,
    roundUp(sisa, 500),
    roundUp(sisa, 1000),
    roundUp(sisa, 5000),
    roundUp(sisa, 10000),
  ]);
  const el = document.getElementById('quickNominal');
  el.innerHTML = [...opts].filter(v => v > 0).map(v =>
    `<button class="btn btn-outline btn-sm" style="font-family:var(--mono);font-size:11px"
      onclick="setNominal(${v})">Rp ${v.toLocaleString('id-ID')}</button>`
  ).join('');
}

function setNominal(val) {
  document.getElementById('bayarJumlah').value = val;
  updateBayarPreview();
}

function setPembulatan(kelipatan) {
  const sisa    = currentBayarData?.sisa || 0;
  const rounded = Math.ceil(sisa / kelipatan) * kelipatan;
  document.getElementById('bayarJumlah').value = rounded;
  updateBayarPreview();
}

function onMetodeChange() {
  const metode = document.getElementById('bayarMetode').value;
  const wrap   = document.getElementById('pembulatanWrap');
  wrap.style.display = metode === 'cash' ? 'block' : 'none';
  if (metode !== 'cash') {
    document.getElementById('pembulatanInfo').textContent = '';
  }
  updateBayarPreview();
}

function selectTipe(tipe) {
  currentTipeBayar = tipe;
  document.getElementById('btnSebagian').classList.toggle('selected', tipe==='sebagian');
  document.getElementById('btnLunas').classList.toggle('selected', tipe==='lunas');
  document.getElementById('nominalWrap').style.display = 'flex';
  if (tipe === 'lunas' && currentBayarData) {
    document.getElementById('bayarJumlah').value = currentBayarData.sisa;
    buildQuickNominal(currentBayarData.sisa);
  }
  updateBayarPreview();
  onMetodeChange();
}

function updateBayarPreview() {
  const val    = parseFloat(document.getElementById('bayarJumlah').value) || 0;
  const sisa   = currentBayarData?.sisa || 0;
  const metode = document.getElementById('bayarMetode').value;
  const el     = document.getElementById('bayarPreview');
  const pInfo  = document.getElementById('pembulatanInfo');

  if (val <= 0) { el.style.display='none'; return; }

  el.style.display = 'block';
  const kembalian = val - sisa;

  if (val > sisa) {
    el.style.background = '#D1FAE5';
    el.style.color      = '#065F46';
    el.innerHTML = `
      <div style="display:flex;justify-content:space-between">
        <span>Dibayar:</span><strong>Rp ${val.toLocaleString('id-ID')}</strong>
      </div>
      <div style="display:flex;justify-content:space-between;border-top:1px dashed rgba(0,0,0,.1);margin-top:6px;padding-top:6px">
        <span>Kembalian:</span><strong>Rp ${kembalian.toLocaleString('id-ID')}</strong>
      </div>`;
    if (metode === 'cash' && kembalian > 0) {
      pInfo.innerHTML = `<span style="color:var(--green)">Kembalian: Rp ${kembalian.toLocaleString('id-ID')}</span>`;
    }
  } else if (val === sisa) {
    el.style.background = '#D1FAE5';
    el.style.color      = '#065F46';
    el.innerHTML = '<strong>✅ Pas — order akan lunas</strong>';
  } else {
    const sisaSetelah = sisa - val;
    el.style.background = '#FEF3C7';
    el.style.color      = '#92400E';
    el.innerHTML = `
      <div style="display:flex;justify-content:space-between">
        <span>Dibayar:</span><strong>Rp ${val.toLocaleString('id-ID')}</strong>
      </div>
      <div style="display:flex;justify-content:space-between;border-top:1px dashed rgba(0,0,0,.1);margin-top:6px;padding-top:6px">
        <span>Sisa setelah ini:</span><strong>Rp ${sisaSetelah.toLocaleString('id-ID')}</strong>
      </div>`;
  }

  if (metode === 'cash' && val > 0 && val < sisa) {
    pInfo.innerHTML = '<span style="color:var(--yellow)">⚠️ Bayar sebagian — tidak perlu pembulatan</span>';
  } else if (metode === 'cash' && val === sisa) {
    pInfo.innerHTML = '<span style="color:var(--green)">✅ Nominal pas</span>';
  }
}

function previewBukti(input) {
  const file = input.files[0];
  if (!file) return;
  if (file.size > 5*1024*1024) { showToast('❌ File terlalu besar (maks 5MB)', 'error'); input.value=''; return; }
  const reader = new FileReader();
  reader.onload = e => {
    const img = document.getElementById('buktiPreview');
    img.src = e.target.result;
    img.style.display = 'block';
  };
  reader.readAsDataURL(file);
  document.getElementById('buktiName').textContent = '📎 ' + file.name;
}

async function submitBayar() {
  if (!currentBayarId) return;
  const tipe   = currentTipeBayar;
  const jumlah = parseFloat(document.getElementById('bayarJumlah').value) || 0;
  const metode = document.getElementById('bayarMetode').value;
  const file   = document.getElementById('buktiFile').files[0];

  if (tipe === 'sebagian' && jumlah <= 0) {
    showToast('⚠️ Masukkan jumlah yang dibayar', 'error'); return;
  }

  const fd = new FormData();
  fd.append('id', currentBayarId);
  fd.append('tipe_bayar', tipe);
  fd.append('jumlah', tipe === 'lunas' ? (currentBayarData?.sisa || 0) : jumlah);
  fd.append('metode', metode);
  if (file) fd.append('bukti', file);

  try {
    const r = await fetch('orders.php?action=bayar', {
      method: 'POST',
      headers: {'X-CSRF-Token': csrfToken()},
      body: fd
    });
    const d = await r.json();
    if (d.success) {
      showToast('✅ Pembayaran berhasil disimpan! Status: ' + bayarLabel(d.status_bayar), 'success');
      closeBayarModal();
      loadOrders();
      loadSummary();
      if (currentEditId) openDetail(currentEditId);
    } else {
      showToast('❌ ' + (d.error||'Gagal'), 'error');
    }
  } catch(e) {
    showToast('❌ Error: ' + e.message, 'error');
  }
}
</script>
<?php renderToast(); ?>
</body>
</html>
