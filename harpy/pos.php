<?php
$activePage = 'pos';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/Loyalty.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
$loyaltyCfg = Loyalty::config((int)TenantResolver::id());

// ── API HANDLER ───────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();

    // GET layanan list
    $oid = TenantResolver::outletId();

    if ($action === 'get_layanan') {
        $rows = TenantQuery::raw(
            "SELECT * FROM hl_layanan WHERE tenant_id=? AND outlet_id=? AND is_active=1 ORDER BY kategori,urutan",
            [$tid, $oid]
        );
        echo json_encode($rows); exit;
    }

    // SEARCH pelanggan — TENANT-SCOPED (lintas outlet)
    // Pelanggan adalah aset account, bisa transaksi di outlet manapun
    // ── action=estimasi_suggest: hitung estimasi jam berdasarkan antrian saat ini ──
    if ($action === 'estimasi_suggest') {
        try {
            $stmt = Database::get()->prepare(
                "SELECT COUNT(*) FROM hl_transaksi
                  WHERE tenant_id=? AND outlet_id=?
                    AND status_proses NOT IN ('siap','diambil','selesai','batal','dibatalkan')"
            );
            $stmt->execute([$tid, $oid]);
            $antrian = (int)$stmt->fetchColumn();
            $jam = 24;
            if ($antrian > 20) $jam = 36;
            if ($antrian > 40) $jam = 48;
            $datetime = date('Y-m-d H:i:s', strtotime("+{$jam} hours"));
            $tanggalOnly = date('Y-m-d', strtotime("+{$jam} hours"));
            $hari = ['Min','Sen','Sel','Rab','Kam','Jum','Sab'][(int)date('w', strtotime($datetime))];
            $isToday = $tanggalOnly === date('Y-m-d');
            $isTomorrow = $tanggalOnly === date('Y-m-d', strtotime('+1 day'));
            $label = ($isToday ? 'Hari ini' : ($isTomorrow ? 'Besok' : "$hari ".date('d M', strtotime($datetime))))
                   . ' jam ' . date('H:i', strtotime($datetime));
            echo json_encode([
                'ok'=>true, 'antrian'=>$antrian, 'jam'=>$jam,
                'datetime'=>$datetime, 'date_only'=>$tanggalOnly,
                'label'=>$label,
            ]);
        } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($action === 'search_pelanggan') {
        $q = '%' . trim($_GET['q'] ?? '') . '%';
        $rows = TenantQuery::raw(
            "SELECT p.*,
                    (SELECT nama_outlet FROM outlets WHERE id=p.registered_outlet_id) AS registered_at_outlet
             FROM hl_pelanggan p
             WHERE p.tenant_id=? AND (p.nama LIKE ? OR p.telepon LIKE ?) AND p.is_active=1
             ORDER BY (p.registered_outlet_id = ?) DESC, p.total_visit_count DESC
             LIMIT 8",
            [$tid, $q, $q, $oid]
        );
        echo json_encode($rows); exit;
    }

    // INFO POIN + REWARD untuk pelanggan terpilih
    if ($action === 'pelanggan_poin') {
        $pid = intval($_GET['id'] ?? 0);
        if (!$pid) { echo json_encode(['error'=>'id wajib']); exit; }
        try {
            $db = Database::get();
            $st = $db->prepare("SELECT id, nama, tier, segmen, poin_balance, catatan_tetap,
                                       preferensi_parfum, preferensi_suhu
                                  FROM hl_pelanggan WHERE id=? AND tenant_id=?");
            $st->execute([$pid, $tid]);
            $pel = $st->fetch(PDO::FETCH_ASSOC);
            if (!$pel) { echo json_encode(['error'=>'Pelanggan tidak ditemukan']); exit; }

            $poin = (int)$pel['poin_balance'];
            $rewards = Loyalty::availableRewards($tid, $oid, $poin);
            echo json_encode([
                'ok' => true,
                'pelanggan' => [
                    'id'       => (int)$pel['id'],
                    'nama'     => $pel['nama'],
                    'tier'     => $pel['tier'] ?? 'regular',
                    'segmen'   => $pel['segmen'] ?? 'baru',
                    'poin'     => $poin,
                    'catatan_tetap'      => $pel['catatan_tetap'] ?? '',
                    'preferensi_parfum'  => $pel['preferensi_parfum'] ?? '',
                    'preferensi_suhu'    => $pel['preferensi_suhu'] ?? '',
                ],
                'rewards' => $rewards,
                'config'  => [
                    'enabled'    => Loyalty::isEnabled($tid),
                    'poin_value' => $loyaltyCfg['poin_value'],
                ],
            ]);
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // SAVE transaksi
    // UPLOAD FOTO KONDISI CUCIAN (multipart) — returns relative path
    if ($action === 'upload_foto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hasPermission('pos.create')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        require_once ROOT . '/core/FileUpload.php';
        $f = $_FILES['foto'] ?? null;
        if (!$f) { echo json_encode(['error'=>'File foto tidak ditemukan']); exit; }
        $res = FileUpload::uploadImage($f, 'uploads/foto_masuk', 't' . $tid . '_o' . $oid);
        if ($res['error']) { echo json_encode(['error'=>$res['error']]); exit; }
        echo json_encode(['ok'=>true, 'path'=>$res['path']]);
        exit;
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hasPermission('pos.create')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $data  = json_decode(file_get_contents('php://input'), true);
        $items = $data['items'] ?? [];
        if (empty($items))      { echo json_encode(['error'=>'Minimal 1 item']); exit; }
        if (count($items) > 30) { echo json_encode(['error'=>'Maksimal 30 item per order']); exit; }

        // Sanitize
        $nama_pel  = substr(trim(strip_tags($data['nama_pelanggan'] ?? '')), 0, 100);
        $telepon   = substr(preg_replace('/[^0-9+\-\s]/', '', $data['telepon'] ?? ''), 0, 20);
        $catatan   = substr(trim(strip_tags($data['catatan'] ?? '')), 0, 500);
        $tanggal   = substr(trim($data['tanggal'] ?? date('Y-m-d')), 0, 10);
        // Estimasi selesai — terima DATE (yyyy-mm-dd) atau DATETIME, normalisasi ke DATETIME.
        // Kalau kosong, auto-compute dari antrian saat ini.
        $estRaw = trim($data['estimasi'] ?? '');
        if ($estRaw === '') {
            // Auto: hitung dari antrian
            try {
                $q = Database::get()->prepare("SELECT COUNT(*) FROM hl_transaksi
                      WHERE tenant_id=? AND outlet_id=?
                        AND status_proses NOT IN ('siap','diambil','selesai','batal','dibatalkan')");
                $q->execute([$tid, $oid]);
                $antrian = (int)$q->fetchColumn();
            } catch (Throwable) { $antrian = 0; }
            $estimasiJam = $antrian > 40 ? 48 : ($antrian > 20 ? 36 : 24);
            $estimasi    = date('Y-m-d H:i:s', strtotime("+{$estimasiJam} hours"));
        } else {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $estRaw)) {
                // Tanggal saja → default jam 14:00
                $estimasi    = $estRaw . ' 14:00:00';
            } else {
                $estimasi    = substr($estRaw, 0, 19);
            }
            $estimasiJam = max(1, (int)round((strtotime($estimasi) - time()) / 3600));
        }

        if (!$nama_pel) { echo json_encode(['error'=>'Nama pelanggan wajib diisi']); exit; }
        if (!$telepon)  { echo json_encode(['error'=>'Nomor telepon wajib diisi']); exit; }

        // Validasi items
        foreach ($items as $item) {
            if (floatval($item['jumlah'] ?? 0) <= 0)      { echo json_encode(['error'=>'Jumlah item harus lebih dari 0']); exit; }
            if (floatval($item['harga_satuan'] ?? 0) < 0)  { echo json_encode(['error'=>'Harga tidak boleh negatif']); exit; }
            if (empty($item['nama_layanan']))               { echo json_encode(['error'=>'Nama layanan tidak boleh kosong']); exit; }
        }

        $db = Database::get();
        $db->beginTransaction();
        try {
            // Generate no order per outlet
            $prefix = 'HL-' . date('Ymd') . '-';
            $cnt    = $db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND no_order LIKE ?");
            $cnt->execute([$tid, $oid, $prefix . '%']);
            $no = $prefix . str_pad((int)$cnt->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);

            // Hitung total
            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += floatval($item['jumlah']) * floatval($item['harga_satuan']);
            }
            $diskon     = floatval($data['diskon'] ?? 0);
            $redeemPoin = max(0, (int)($data['redeem_poin'] ?? 0));
            // total/dp/status dihitung SETELAH pel_id + redeem diketahui

            // Upsert pelanggan — TENANT-SCOPED (lintas outlet)
            // Lookup by tenant_id + telepon (HP unique per tenant)
            $pel_id = null;
            if ($nama_pel) {
                $pelRow = TenantQuery::rawOne(
                    "SELECT id FROM hl_pelanggan WHERE tenant_id=? AND telepon=? LIMIT 1",
                    [$tid, $telepon]
                );
                if ($pelRow) {
                    // Sudah pernah daftar (di outlet manapun) — increment visit count
                    $pel_id = $pelRow['id'];
                    $db->prepare(
                        "UPDATE hl_pelanggan
                            SET total_order = total_order + 1,
                                total_visit_count = total_visit_count + 1
                          WHERE id = ? AND tenant_id = ?"
                    )->execute([$pel_id, $tid]);
                } else {
                    // Pelanggan baru — catat outlet pertama daftar
                    TenantQuery::insert('hl_pelanggan', [
                        'nama'                  => $nama_pel,
                        'telepon'               => $telepon,
                        'tipe'                  => 'retail',
                        'total_order'           => 1,
                        'total_visit_count'     => 1,
                        'registered_outlet_id'  => $oid,
                        'outlet_id'             => $oid, // legacy compat
                    ]);
                    $pel_id = $db->lastInsertId();
                }
            }

            // ── Loyalty redeem (poin → diskon) — hitung nilai dulu, deduct setelah insert ──
            $redeemValue = 0;
            if ($redeemPoin > 0 && $pel_id && Loyalty::isEnabled($tid)) {
                $cfg = Loyalty::config($tid);
                // Clamp by saldo poin + by rupiah (jangan melebihi subtotal - diskon manual)
                $balPoin   = Loyalty::balance($tid, (int)$pel_id);
                $maxRupiah = max(0, $subtotal - $diskon);
                $maxPoin   = min($balPoin, (int)floor($maxRupiah / $cfg['poin_value']));
                $redeemPoin = min($redeemPoin, $maxPoin);
                if ($redeemPoin > 0) {
                    $redeemValue = $redeemPoin * $cfg['poin_value'];
                    if ($catatan === '') $catatan = "Redeem $redeemPoin poin (-Rp " . number_format($redeemValue,0,',','.') . ")";
                    else $catatan .= " · Redeem $redeemPoin poin (-Rp " . number_format($redeemValue,0,',','.') . ")";
                } else {
                    $redeemPoin = 0;
                }
            } else {
                $redeemPoin = 0;
            }

            // Total final (diskon manual + nilai redeem)
            $diskonTotal = $diskon + $redeemValue;
            $total    = max(0, $subtotal - $diskonTotal);
            $dp       = floatval($data['dp'] ?? 0);
            $sisa     = $total - $dp;
            $status_b = $dp >= $total ? 'lunas' : ($dp > 0 ? 'dp' : 'belum_bayar');

            // Foto masuk (optional, dari upload_foto endpoint)
            $fotoMasuk = trim($data['foto_masuk'] ?? '');
            $hasFotoMasuk = true;
            try { $db->query("SELECT foto_masuk FROM hl_transaksi LIMIT 1"); } catch (Throwable) { $hasFotoMasuk = false; }

            // Insert transaksi header (with estimasi_jam kalau kolom ada)
            $hasEstJam = true;
            try { $db->query("SELECT estimasi_jam FROM hl_transaksi LIMIT 1"); } catch (Throwable) { $hasEstJam = false; }
            if ($hasEstJam) {
                $stmt = $db->prepare(
                    "INSERT INTO hl_transaksi
                     (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,
                      subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,
                      status_proses,estimasi_selesai,estimasi_jam,catatan,created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                );
                $stmt->execute([
                    $tid, $oid, $no, $tanggal, $pel_id, $nama_pel, $telepon,
                    $subtotal, $diskonTotal, $total, $dp, $sisa,
                    $data['metode_bayar'] ?? 'cash', $status_b,
                    'masuk', $estimasi, $estimasiJam, $catatan, $user['id']
                ]);
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO hl_transaksi
                     (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,
                      subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,
                      status_proses,estimasi_selesai,catatan,created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                );
                $stmt->execute([
                    $tid, $oid, $no, $tanggal, $pel_id, $nama_pel, $telepon,
                    $subtotal, $diskonTotal, $total, $dp, $sisa,
                    $data['metode_bayar'] ?? 'cash', $status_b,
                    'masuk', $estimasi, $catatan, $user['id']
                ]);
            }
            $trx_id = $db->lastInsertId();

            // Simpan foto_masuk kalau kolom & data ada
            if ($hasFotoMasuk && $fotoMasuk !== '') {
                try {
                    $db->prepare("UPDATE hl_transaksi SET foto_masuk=? WHERE id=? AND tenant_id=? AND outlet_id=?")
                       ->execute([substr($fotoMasuk,0,255), $trx_id, $tid, $oid]);
                } catch (Throwable) {}
            }

            // Deduct poin redeem (dalam transaksi yang sama) — transaksi_id terisi
            if ($redeemPoin > 0 && $pel_id) {
                Loyalty::redeemInTx($db, $tid, $oid, (int)$pel_id, $redeemPoin, (int)$trx_id, $user['id']);
            }

            // Insert items
            $istmt = $db->prepare(
                "INSERT INTO hl_transaksi_item
                 (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal,catatan_item)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            );
            foreach ($items as $item) {
                $sub = floatval($item['jumlah']) * floatval($item['harga_satuan']);
                $istmt->execute([
                    $tid, $oid, $trx_id,
                    $item['layanan_id'] ?: null,
                    substr(trim(strip_tags($item['nama_layanan'])), 0, 100),
                    $item['satuan'] ?? 'kg',
                    $item['jumlah'],
                    $item['harga_satuan'],
                    $sub,
                    substr(trim(strip_tags($item['catatan_item'] ?? '')), 0, 255)
                ]);
            }

            // Log status masuk (hl_proses_log tidak punya outlet_id)
            $db->prepare(
                "INSERT INTO hl_proses_log (tenant_id,transaksi_id,status_baru,oleh) VALUES (?,?,?,?)"
            )->execute([$tid, $trx_id, 'masuk', $user['nama']]);

            // AUTO INSERT KAS jika ada DP/Lunas
            if ($dp > 0) {
                $metode      = $data['metode_bayar'] ?? 'cash';
                $metodeLabel = ['cash'=>'Cash','transfer'=>'Transfer','qris'=>'QRIS'][$metode] ?? 'Cash';
                $isPaid      = $dp >= $total;
                $kasKet      = ($isPaid ? 'Pembayaran LUNAS' : 'DP/Uang Muka') .
                               ' order ' . $no . ' - ' . $nama_pel . ' via ' . $metodeLabel;

                TenantQuery::insert('hl_kas', [
                    'tanggal'    => $tanggal,
                    'tipe'       => 'masuk',
                    'kategori'   => 'Penjualan Laundry',
                    'keterangan' => $kasKet,
                    'jumlah'     => $dp,
                    'ref_order'  => $no,
                    'created_by' => $user['id'],
                ]);
            }

            $db->commit();
            logAudit('create', 'orders', 'Buat order baru: ' . $no . ' - ' . $nama_pel, $no);

            // Loyalty: earn poin TIDAK lagi di sini — sekarang triggered saat
            // status_proses berubah ke 'siap' (di orders.php / kanban.php).
            // Touch last_transaksi supaya segmentasi akurat saat order dibuat.
            $poinEarned = 0;
            if ($pel_id) {
                try { Loyalty::touchLastTransaksi($tid, (int)$pel_id); } catch (Throwable) {}
            }

            echo json_encode(['success'=>true, 'no_order'=>$no, 'id'=>$trx_id,
                'total'=>$total, 'sisa'=>$sisa, 'poin_earned'=>$poinEarned,
                'poin_redeemed'=>$redeemPoin, 'redeem_value'=>$redeemValue]);

            // Run anomaly check (silent, async-ish — setelah response dikirim)
            try {
                require_once ROOT . '/core/AnomalyDetector.php';
                AnomalyDetector::check($tid, $oid);
            } catch (Throwable) {}
        } catch (Throwable $e) {
            $db->rollBack();
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // GET detail transaksi (untuk print)
    if ($action === 'get_detail') {
        $id  = intval($_GET['id']);
        $t   = TenantQuery::rawOne(
            "SELECT * FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?", [$tid, $oid, $id]
        );
        if (!$t) { echo json_encode(['error'=>'Not found']); exit; }
        $t['items'] = TenantQuery::raw(
            "SELECT * FROM hl_transaksi_item WHERE tenant_id=? AND outlet_id=? AND transaksi_id=? ORDER BY id",
            [$tid, $oid, $id]
        );
        echo json_encode($t); exit;
    }

    echo json_encode(['error'=>'Unknown action']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('POS'); ?>
<style>
/* LAYOUT */
.main{max-width:1100px;width:100%;margin:0 auto;padding:24px 20px}
.grid-2{display:grid;grid-template-columns:1.1fr .9fr;gap:20px;align-items:start}

/* CARD */
.card{background:var(--white);border-radius:var(--r-lg);border:1px solid rgba(27,45,90,.07);box-shadow:var(--shadow);overflow:hidden;margin-bottom:20px}
.card-header{padding:16px 20px;border-bottom:1px solid var(--light);display:flex;align-items:center;justify-content:space-between}
.card-title{font-size:14px;font-weight:700;color:var(--navy);display:flex;align-items:center;gap:8px}
.card-body{padding:20px}

/* FORM */
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.form-row.cols3{grid-template-columns:1fr 1fr 1fr}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.full{grid-column:1/-1}
label{font-size:11px;font-weight:700;color:var(--navy);letter-spacing:.05em;text-transform:uppercase}
label .req{color:var(--red)}
input,select,textarea{padding:9px 12px;border:1.5px solid rgba(27,45,90,.14);border-radius:var(--r);font-family:var(--font);font-size:14px;color:var(--dark);background:var(--off);outline:none;transition:all .2s;width:100%}
input:focus,select:focus,textarea:focus{border-color:var(--teal);background:var(--white);box-shadow:0 0 0 3px rgba(53,232,213,.1)}
textarea{resize:vertical;min-height:64px}

/* AUTOCOMPLETE */
.autocomplete-wrap{position:relative}
.autocomplete-list{position:absolute;top:100%;left:0;right:0;background:var(--white);border:1.5px solid rgba(53,232,213,.3);border-radius:var(--r);z-index:50;max-height:200px;overflow-y:auto;box-shadow:var(--shadow-lg);display:none}
.autocomplete-list.open{display:block}
.ac-item{padding:10px 14px;cursor:pointer;font-size:14px;border-bottom:1px solid var(--light);transition:background .15s}
.ac-item:hover{background:var(--teal-bg)}
.ac-item .ac-sub{font-size:11px;color:var(--gray);margin-top:2px}

/* ITEMS GRID */
.items-table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:12px}
.items-table thead tr{background:var(--navy-d)}
.items-table thead th{padding:9px 10px;text-align:left;font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.6);white-space:nowrap}
.items-table tbody tr{border-bottom:1px solid var(--light)}
.items-table tbody td{padding:6px 6px;vertical-align:middle}
.items-table tbody tr:last-child{border-bottom:none}
.item-input{padding:7px 9px;font-size:13px}
.item-subtotal{font-family:var(--mono);font-weight:600;color:var(--navy);text-align:right;white-space:nowrap;font-size:13px;min-width:90px}
.btn-remove{background:#FEE2E2;color:var(--red);border:none;border-radius:6px;padding:5px 9px;cursor:pointer;font-size:13px;transition:all .2s}
.btn-remove:hover{background:var(--red);color:white}

/* SUMMARY BOX */
.summary-box{background:linear-gradient(135deg,var(--navy-d),var(--navy));border-radius:var(--r-lg);padding:20px;color:var(--white);margin-top:4px}
.sum-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;font-size:14px}
.sum-row.total{border-top:1px solid rgba(255,255,255,.15);margin-top:8px;padding-top:12px}
.sum-label{color:rgba(255,255,255,.6)}
.sum-value{font-family:var(--mono);font-weight:700}
.sum-value.big{font-size:1.4rem;color:var(--teal)}
.sum-value.sisa{color:#FCA5A5}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:10px 18px;border-radius:var(--r);font-family:var(--font);font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;border:none}
.btn-primary{background:var(--teal);color:var(--navy-d);padding:13px 28px;font-size:15px;width:100%}
.btn-primary:hover{background:var(--teal-d);box-shadow:0 4px 16px rgba(53,232,213,.3)}
.btn-outline{background:transparent;color:var(--navy);border:1.5px solid rgba(27,45,90,.2)}
.btn-outline:hover{background:var(--light)}
.btn-teal-sm{background:var(--teal-bg);color:var(--teal-d);border:1px solid rgba(53,232,213,.3);font-size:13px;padding:7px 14px}
.btn-teal-sm:hover{background:var(--teal);color:var(--navy-d)}
.btn-green{background:#D1FAE5;color:#065F46}
.btn-green:hover{background:var(--green);color:white}
.btn-actions{display:flex;gap:10px;margin-top:16px}
.btn:disabled{opacity:.5;pointer-events:none}

/* LAYANAN GRID (quick pick) */
.layanan-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:12px;max-height:220px;overflow-y:auto}
.layanan-btn{padding:8px 6px;background:var(--off);border:1.5px solid rgba(27,45,90,.1);border-radius:8px;cursor:pointer;text-align:left;transition:all .2s;font-family:var(--font)}
.layanan-btn:hover{border-color:var(--teal);background:var(--teal-bg)}
.layanan-btn .l-nama{font-size:12px;font-weight:600;color:var(--navy);line-height:1.3}
.layanan-btn .l-harga{font-size:11px;color:var(--teal-d);font-family:var(--mono);margin-top:2px}
.layanan-btn .l-kat{font-size:10px;color:var(--gray);margin-bottom:2px}
.layanan-search{margin-bottom:8px}

/* TOAST */
.toast{position:fixed;bottom:20px;right:20px;z-index:999;padding:13px 18px;border-radius:var(--r);font-size:14px;font-weight:600;color:white;transform:translateY(60px);opacity:0;transition:all .3s cubic-bezier(.4,0,.2,1);pointer-events:none;max-width:320px}
.toast.show{transform:translateY(0);opacity:1}
.toast.success{background:var(--green)}
.toast.error{background:var(--red)}

/* VOUCHER */
.voucher-applied{background:linear-gradient(135deg,#D1FAE5,#A7F3D0);border:1.5px solid #6EE7B7;border-radius:var(--r);padding:10px 14px;font-size:13px;color:#065F46}
.voucher-applied strong{font-family:var(--mono);letter-spacing:.08em}

/* PRINT MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,28,58,.6);backdrop-filter:blur(4px);z-index:200;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:var(--white);border-radius:var(--r-lg);padding:0;width:380px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-lg)}
.modal-header{padding:16px 20px;border-bottom:1px solid var(--light);display:flex;justify-content:space-between;align-items:center}
.modal-title{font-size:15px;font-weight:700;color:var(--navy)}
.modal-close{background:none;border:none;font-size:18px;cursor:pointer;color:var(--gray);padding:4px}
.modal-body{padding:20px}
.modal-footer{padding:16px 20px;border-top:1px solid var(--light);display:flex;gap:10px;justify-content:flex-end}

/* STRUK THERMAL */
.struk{font-family:'Courier New',monospace;font-size:12px;line-height:1.6;color:#000;width:72mm;margin:0 auto}
.struk-header{text-align:center;border-bottom:1px dashed #000;padding-bottom:8px;margin-bottom:8px}
.struk-header h2{font-size:14px;font-weight:bold;letter-spacing:.04em}
.struk-header p{font-size:10px}
.struk-row{display:flex;justify-content:space-between;font-size:11px}
.struk-row.bold{font-weight:bold;font-size:12px}
.struk-item{margin:4px 0;font-size:11px}
.struk-divider{border:none;border-top:1px dashed #000;margin:6px 0}
.struk-total{border-top:2px solid #000;margin-top:6px;padding-top:6px}
.struk-footer{text-align:center;margin-top:8px;font-size:10px;border-top:1px dashed #000;padding-top:8px}

@media print{
  body *{visibility:hidden}
  #strukPrint,#strukPrint *{visibility:visible}
  #strukPrint{position:fixed;left:0;top:0;width:80mm;padding:4mm;background:white}
  @page{size:80mm auto;margin:0}
}

@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}

@media(max-width:800px){
  .main{padding:16px 14px}
  .grid-2{grid-template-columns:1fr;gap:14px}
  .grid-2 > div[style*="sticky"]{position:static!important}  /* lepas sticky di HP */
  .layanan-grid{grid-template-columns:repeat(2,1fr)}
  .form-row{grid-template-columns:1fr}
  .form-row.cols3{grid-template-columns:1fr 1fr}
  /* Konfirmasi modal fit HP */
  #confirmSaveModal > div{padding:16px 18px}
}
@media(max-width:680px){
  .main{padding:12px 10px 80px;max-width:100%;overflow-x:hidden}
  .card{margin-bottom:14px;overflow:visible} /* lepas overflow:hidden supaya table wrap bisa scroll */
  /* card-header wrap + tombol stack */
  .card-header{padding:12px 14px;flex-wrap:wrap;gap:8px}
  .card-header .btn,.card-header button{flex-shrink:0}
  .card-body{padding:14px}
  .layanan-grid{grid-template-columns:repeat(2,1fr);gap:5px;max-height:180px}

  /* TABEL ITEMS — convert ke layout stacked card per row di HP (UX lebih baik dari scroll horizontal) */
  .items-table, .items-table thead, .items-table tbody,
  .items-table tr, .items-table th, .items-table td { display:block; width:100% }
  .items-table thead { display:none }  /* sembunyikan header — pakai label inline */
  .items-table tbody tr {
    border:1px solid rgba(27,45,90,.1); border-radius:10px;
    margin-bottom:10px; padding:10px 12px; background:#fff;
  }
  .items-table tbody td {
    display:flex; justify-content:space-between; align-items:center;
    padding:5px 0; border:none; font-size:13px; gap:8px;
  }
  /* Label kolom via pseudo-element */
  .items-table tbody td::before {
    content: attr(data-lbl); font-size:11px; color:var(--gray); font-weight:600;
    text-transform:uppercase; letter-spacing:.05em; flex-shrink:0;
  }
  /* Hide labels jika tidak ada data-lbl */
  .items-table tbody td:empty::before { content:'' }
  /* Inputs di stacked layout */
  .items-table tbody td input,
  .items-table tbody td select {
    text-align:right; flex:1; min-width:0; max-width:160px;
  }
  .items-table tbody td .item-sub { font-weight:700; color:var(--navy); }
  /* Tombol remove di pojok kanan atas card */
  .items-table tbody td:last-child {
    justify-content:flex-end; padding-top:8px;
    border-top:1px dashed rgba(27,45,90,.08); margin-top:6px;
  }

  .table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .btn-actions{flex-direction:column;gap:8px}
  .btn-actions .btn{width:100%}
  .btn{padding:12px 14px;font-size:14px}
  /* AI floating menutupi tombol — geser ke kiri-bawah */
  #aiBubbleBtn{bottom:80px!important;right:14px!important}
  #aiChatPanel{right:14px!important;left:14px;width:auto!important;max-width:none}
  /* Loyalty reward list maks 140px di HP */
  #rewardsList{max-height:140px!important}
  /* Summary box — pastikan tidak overflow */
  .summary-box{padding:14px}
  .summary-box input{max-width:90px!important}
  /* Voucher row stack di HP */
  .form-row.cols3{grid-template-columns:1fr 1fr}
}
@media(max-width:400px){
  .main{padding:8px 8px 80px}
  .layanan-grid{grid-template-columns:1fr 1fr;gap:4px}
}
</style>
</head>
<body>
<?php renderTopbar('pos'); ?>

<div class="main">
  <div class="grid-2">

    <!-- KOLOM KIRI: Form + Items -->
    <div>

      <!-- INFO PELANGGAN -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">👤 Informasi Pelanggan</div>
          <span id="noOrderBadge" style="font-family:var(--mono);font-size:12px;color:var(--teal)"></span>
        </div>
        <div class="card-body">
          <div class="form-row">
            <div class="form-group">
              <label>Tanggal <span class="req">*</span></label>
              <input type="date" id="f_tanggal"/>
            </div>
            <div class="form-group">
              <label>Estimasi Selesai</label>
              <input type="date" id="f_estimasi"/>
              <small id="estHint" style="display:block;margin-top:4px;font-size:11px;color:#0891B2;font-weight:600">⏱ Memuat saran…</small>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group full">
              <label>Nama Pelanggan <span class="req">*</span></label>
              <div class="autocomplete-wrap">
                <input type="text" id="f_nama" placeholder="Ketik nama atau cari pelanggan..."
                  autocomplete="off" oninput="searchPelanggan(this.value)"/>
                <div class="autocomplete-list" id="acList"></div>
              </div>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>No. Telepon <span class="req">*</span></label>
              <input type="tel" id="f_telepon" placeholder="08xxxxxxxxxx"/>
            </div>
            <div class="form-group full">
              <label>Catatan Order</label>
              <textarea id="f_catatan" placeholder="Warna, permintaan khusus, kondisi pakaian, dll..." style="min-height:80px"></textarea>
            </div>
          </div>
        </div>
      </div>

      <!-- ITEM LAYANAN -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">🧺 Layanan yang Digunakan</div>
          <button class="btn btn-teal-sm" onclick="addEmptyRow()">+ Tambah Baris</button>
        </div>
        <div class="card-body" style="padding-bottom:12px">

          <!-- Quick pick layanan -->
          <div style="margin-bottom:12px">
            <input type="text" class="layanan-search" id="layananSearch"
              placeholder="🔍 Cari layanan..." oninput="filterLayanan(this.value)"
              style="margin-bottom:8px"/>
            <div class="layanan-grid" id="layananGrid">
              <div style="color:var(--gray);font-size:13px;padding:8px">Memuat layanan...</div>
            </div>
          </div>

          <!-- Table items -->
          <div class="items-table-wrap" style="overflow-x:auto">
            <table class="items-table">
              <thead>
                <tr>
                  <th style="min-width:130px">Layanan</th>
                  <th style="width:60px">Satuan</th>
                  <th style="width:70px">Jumlah</th>
                  <th style="width:100px">Harga/Sat</th>
                  <th style="width:90px">Subtotal</th>
                  <th style="width:80px">Catatan</th>
                  <th style="width:36px"></th>
                </tr>
              </thead>
              <tbody id="itemsBody"></tbody>
            </table>
          </div>
          <div id="emptyItems" style="text-align:center;padding:20px;color:var(--gray);font-size:14px">
            Pilih layanan di atas atau klik "+ Tambah Baris"
          </div>

          <!-- FOTO KONDISI CUCIAN -->
          <div style="margin-top:16px;padding-top:14px;border-top:1px dashed rgba(27,45,90,.1)">
            <label style="font-size:12px;font-weight:600;color:var(--gray);display:block;margin-bottom:6px">
              📸 Foto Kondisi Cucian (opsional)
            </label>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <input type="file" id="f_foto" accept="image/*" capture="environment"
                     style="font-size:13px" onchange="uploadFotoMasuk(this)"/>
              <span id="fotoStatus" style="font-size:12px;color:var(--gray)"></span>
              <button type="button" class="btn btn-outline btn-sm" id="btnFotoClear" onclick="clearFoto()" style="display:none">✕ Hapus</button>
            </div>
            <img id="fotoPreview" style="display:none;max-height:80px;border-radius:8px;margin-top:8px;border:1px solid rgba(27,45,90,.1)"/>
            <input type="hidden" id="f_foto_path" value=""/>
          </div>
        </div>
      </div>

    </div>

    <!-- KOLOM KANAN: Summary + Bayar -->
    <div style="position:sticky;top:72px">

      <!-- SUMMARY -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">💰 Ringkasan Pembayaran</div>
        </div>
        <div class="card-body">
          <div class="summary-box">
            <div class="sum-row">
              <span class="sum-label">Subtotal</span>
              <span class="sum-value" id="sumSubtotal">Rp 0</span>
            </div>
            <div class="sum-row">
              <span class="sum-label">Diskon</span>
              <span class="sum-value" style="color:#FCA5A5">- Rp <span id="sumDiskon">0</span></span>
            </div>
            <div class="sum-row total">
              <span style="font-weight:700;color:white">TOTAL</span>
              <span class="sum-value big" id="sumTotal">Rp 0</span>
            </div>
            <div class="sum-row" style="margin-top:8px">
              <span class="sum-label">DP / Bayar</span>
              <span class="sum-value" id="sumDP">Rp 0</span>
            </div>
            <div class="sum-row">
              <span class="sum-label">Sisa Bayar</span>
              <span class="sum-value sisa" id="sumSisa">Rp 0</span>
            </div>
          </div>

          <div style="margin-top:16px;display:flex;flex-direction:column;gap:10px">

            <!-- VOUCHER -->
            <div style="display:flex;gap:8px;align-items:flex-end">
              <div class="form-group" style="flex:1;margin-bottom:0">
                <label>🎟️ Kode Voucher / Promo</label>
                <input type="text" id="f_voucher" placeholder="Masukkan kode..."
                  style="text-transform:uppercase;letter-spacing:.08em;font-family:var(--mono)"
                  oninput="this.value=this.value.toUpperCase()"/>
              </div>
              <button type="button" class="btn btn-teal-sm" onclick="applyVoucher()" style="margin-bottom:1px;white-space:nowrap">
                ✓ Pakai
              </button>
            </div>
            <div id="voucherInfo" style="display:none">
              <div id="voucherInfoText"></div>
              <button type="button" onclick="removeVoucher()" style="background:none;border:none;color:var(--red);font-size:12px;cursor:pointer;margin-top:4px;padding:0">✕ Hapus kode</button>
            </div>

            <!-- LOYALTY REDEEM -->
            <div id="loyaltyBox" style="display:none;background:#F0FDFB;border:1px solid #B6F0E6;border-radius:8px;padding:11px 13px">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <span style="font-size:13px;font-weight:700;color:#0F1C3A">
                  ⭐ Poin Loyalty
                  <span id="loyaltyTierBadge" style="margin-left:6px;font-size:10px;font-weight:700;padding:2px 7px;border-radius:100px;background:#fff;color:#0891B2;display:none"></span>
                  <span id="loyaltySegmenBadge" style="margin-left:4px;font-size:10px;font-weight:700;padding:2px 7px;border-radius:100px;display:none"></span>
                </span>
                <span style="font-size:12px;color:#0891B2;font-weight:700"><span id="loyaltyBal">0</span> poin</span>
              </div>

              <!-- DAFTAR REWARD (dynamic) -->
              <div id="rewardsList" style="display:none;margin-bottom:8px;max-height:180px;overflow-y:auto"></div>

              <!-- INPUT MANUAL POIN -->
              <div style="display:flex;gap:8px;align-items:flex-end;padding-top:8px;border-top:1px dashed rgba(8,145,178,.25)">
                <div class="form-group" style="flex:1;margin-bottom:0">
                  <label style="font-size:11px">Tukar Poin (manual)</label>
                  <input type="number" id="f_redeem_poin" value="0" min="0" oninput="recalc()"/>
                </div>
                <button type="button" class="btn btn-teal-sm" onclick="redeemMax()" style="margin-bottom:1px;white-space:nowrap">Max</button>
              </div>
              <div id="redeemInfo" style="font-size:11px;color:#0891B2;margin-top:5px;display:none"></div>
            </div>

            <div class="form-row cols3">
              <div class="form-group">
                <label>Diskon (Rp)</label>
                <input type="number" id="f_diskon" value="0" min="0" oninput="recalc()"/>
              </div>
              <div class="form-group">
                <label>DP / Bayar</label>
                <input type="number" id="f_dp" value="0" min="0" oninput="recalc()"/>
              </div>
              <div class="form-group">
                <label>Metode</label>
                <select id="f_metode">
                  <option value="cash">💵 Cash</option>
                  <option value="transfer">🏦 Transfer</option>
                  <option value="qris">📱 QRIS</option>
                </select>
              </div>
            </div>

            <div id="statusBayarInfo" style="text-align:center;font-size:13px;font-weight:600;padding:8px;border-radius:8px;background:var(--light);color:var(--gray)">
              Belum ada item
            </div>

            <button class="btn btn-primary" id="btnSave" onclick="saveTransaksi()" disabled>
              💾 Simpan & Print Struk
            </button>
            <button class="btn btn-outline" onclick="resetForm()">
              ↺ Reset Form
            </button>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- FLOATING AI CHATBOT -->
<div id="aiFloating" style="display:none">
  <button id="aiBubbleBtn" onclick="toggleAIChat()"
    style="position:fixed;bottom:24px;right:24px;z-index:1000;
           width:52px;height:52px;border-radius:50%;border:none;cursor:pointer;
           background:linear-gradient(135deg,#667eea,#764ba2);
           color:white;font-size:20px;box-shadow:0 4px 20px rgba(102,126,234,.5);
           transition:all .3s;display:flex;align-items:center;justify-content:center">
    ✨
  </button>
  <div id="aiNotifDot" style="display:none;position:fixed;bottom:66px;right:24px;z-index:1001;
    width:12px;height:12px;background:var(--red);border-radius:50%;border:2px solid white"></div>
  <div id="aiChatPanel"
    style="display:none;position:fixed;bottom:88px;right:24px;z-index:999;
           width:340px;max-height:520px;
           background:white;border-radius:16px;
           box-shadow:0 8px 40px rgba(27,45,90,.2);
           border:1px solid rgba(139,92,246,.2);
           flex-direction:column;overflow:hidden">
    <div style="background:linear-gradient(135deg,#667eea,#764ba2);padding:14px 16px;display:flex;align-items:center;justify-content:space-between">
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:32px;height:32px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px">✨</div>
        <div>
          <div style="color:white;font-weight:700;font-size:14px">AI Assistant</div>
          <div style="color:rgba(255,255,255,.7);font-size:11px" id="aiStatusText">Pilih customer dulu</div>
        </div>
      </div>
      <div style="display:flex;gap:6px">
        <button onclick="loadAIRekomendasi()" id="btnRefreshAI"
          style="background:rgba(255,255,255,.2);border:none;color:white;border-radius:8px;padding:5px 10px;cursor:pointer;font-size:12px;font-weight:600">↻</button>
        <button onclick="toggleAIChat()"
          style="background:rgba(255,255,255,.2);border:none;color:white;border-radius:8px;padding:5px 10px;cursor:pointer;font-size:14px">✕</button>
      </div>
    </div>
    <div id="aiContent" style="flex:1;overflow-y:auto;padding:14px;font-size:13px;max-height:420px;background:var(--off)">
      <div style="text-align:center;padding:32px 16px;color:var(--gray)">
        <div style="font-size:2rem;margin-bottom:8px;opacity:.4">✨</div>
        <div style="font-size:13px;font-weight:600;margin-bottom:4px">AI Upselling Assistant</div>
        <div style="font-size:12px">Pilih customer di form untuk mendapatkan rekomendasi layanan</div>
      </div>
    </div>
  </div>
</div>

<!-- MODAL STRUK -->
<div class="modal-overlay" id="modalStruk">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">🧾 Struk Pembayaran</span>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
      <div id="strukPrint"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal()">Tutup</button>
      <button class="btn btn-green" onclick="printStruk()">🖨️ Print Struk</button>
      <button class="btn btn-teal-sm" onclick="window.location.href='orders.php'">📋 Lihat Orders</button>
    </div>
  </div>
</div>

<script>
let items = [];
let layananAll = [];
let lastSaved  = null;
let acTimeout  = null;

// ── Estimasi auto-suggest dari antrian ──
async function loadEstimasiHint(){
  const el = document.getElementById('estHint');
  if (!el) return;
  try {
    const r = await fetch('pos.php?action=estimasi_suggest');
    const d = await r.json();
    if (d.error || !d.ok) { el.textContent = ''; return; }
    el.innerHTML = `⏱ Saran: <strong>${d.label}</strong> (${d.jam}j, antrian ${d.antrian} order)`;
    // Auto-isi date kalau kosong
    const fe = document.getElementById('f_estimasi');
    if (fe && !fe.value) fe.value = d.date_only;
  } catch(e){ /* silent */ }
}
const LOYALTY = <?= json_encode(['enabled'=>$loyaltyCfg['enabled'],'poin_value'=>$loyaltyCfg['poin_value'],'rupiah_per_poin'=>$loyaltyCfg['rupiah_per_poin']]) ?>;
let currentPelangganPoin = 0;

function localDateStr(d) {
  const dt = d || new Date();
  return dt.getFullYear() + '-' +
    String(dt.getMonth()+1).padStart(2,'0') + '-' +
    String(dt.getDate()).padStart(2,'0');
}

document.addEventListener('DOMContentLoaded', () => {
  const today = localDateStr();
  document.getElementById('f_tanggal').value = today;
  // Kosongkan estimasi dulu — loadEstimasiHint akan isi otomatis sesuai antrian
  loadLayanan();
  loadEstimasiHint();

  // ── KEYBOARD SHORTCUTS ──
  document.addEventListener('keydown', (e) => {
    // Skip kalau lagi ketik di input/textarea (kecuali F2/F3/Esc yang harus tetap aktif)
    const tag = (e.target.tagName||'').toLowerCase();
    const isField = tag === 'input' || tag === 'textarea' || tag === 'select';

    if (e.key === 'F2') {
      e.preventDefault();
      const el = document.getElementById('f_nama'); if (el) el.focus();
    } else if (e.key === 'F3') {
      e.preventDefault();
      saveTransaksi();
    } else if (e.key === 'Escape') {
      const cfm = document.getElementById('confirmSaveModal');
      if (cfm && cfm.style.display === 'flex') { closeCfm(); return; }
      if (!isField) resetForm();
    } else if (e.key === 'Enter') {
      const cfm = document.getElementById('confirmSaveModal');
      if (cfm && cfm.style.display === 'flex') { e.preventDefault(); doSaveTransaksi(); }
    }
  });
});

// ── FOTO MASUK UPLOAD ────────────────────────────────
async function uploadFotoMasuk(input) {
  const f = input.files && input.files[0];
  if (!f) return;
  const status = document.getElementById('fotoStatus');
  status.textContent = '⏳ Mengunggah...';
  const fd = new FormData();
  fd.append('foto', f);
  try {
    const r = await fetch('pos.php?action=upload_foto', {
      method:'POST',
      headers:{'X-CSRF-Token':csrfToken()},
      body: fd
    });
    const d = await r.json();
    if (d.error) { status.textContent = '❌ ' + d.error; status.style.color = 'var(--red)'; return; }
    document.getElementById('f_foto_path').value = d.path;
    const prev = document.getElementById('fotoPreview');
    prev.src = '/ERP/harpy/' + d.path;
    prev.style.display = 'block';
    document.getElementById('btnFotoClear').style.display = '';
    status.textContent = '✓ Terunggah';
    status.style.color = 'var(--green)';
  } catch(e){ status.textContent = '❌ Network error'; status.style.color = 'var(--red)'; }
}

function clearFoto() {
  document.getElementById('f_foto').value = '';
  document.getElementById('f_foto_path').value = '';
  document.getElementById('fotoPreview').style.display = 'none';
  document.getElementById('fotoPreview').src = '';
  document.getElementById('fotoStatus').textContent = '';
  document.getElementById('btnFotoClear').style.display = 'none';
}

// ── KONFIRMASI MODAL ────────────────────────────────
function closeCfm(){ document.getElementById('confirmSaveModal').style.display = 'none'; }

async function loadLayanan() {
  const res = await fetch('pos.php?action=get_layanan');
  layananAll = await res.json();
  renderLayananGrid(layananAll);
}

function renderLayananGrid(list) {
  const grid = document.getElementById('layananGrid');
  if (!list.length) {
    grid.innerHTML = '<div style="color:var(--gray);font-size:13px;padding:8px;grid-column:1/-1">Tidak ada layanan</div>';
    return;
  }
  grid.innerHTML = list.map(l => `
    <button class="layanan-btn" onclick="addLayananItem(${l.id},'${esc(l.nama)}','${l.satuan}',${l.harga})">
      <div class="l-kat">${esc(l.kategori||'')}</div>
      <div class="l-nama">${esc(l.nama)}</div>
      <div class="l-harga">Rp ${parseFloat(l.harga).toLocaleString('id-ID')}/${l.satuan}</div>
    </button>`).join('');
}

function filterLayanan(q) {
  const filtered = q
    ? layananAll.filter(l => l.nama.toLowerCase().includes(q.toLowerCase()) || (l.kategori||'').toLowerCase().includes(q.toLowerCase()))
    : layananAll;
  renderLayananGrid(filtered);
}

function addLayananItem(id, nama, satuan, harga) {
  const existIdx = items.findIndex(i => i.layanan_id == id && !i.catatan_item);
  if (existIdx >= 0) {
    items[existIdx].jumlah += 1;
    renderItems(); recalc();
    showToast('Quantity ' + nama + ' +1', 'success');
    return;
  }
  const existWithNote = items.findIndex(i => i.layanan_id == id && i.catatan_item);
  if (existWithNote >= 0) {
    if (confirm(nama + ' sudah ada di daftar.\n\nOK = Tambah baris baru\nBatal = Tidak jadi')) {
      items.push({layanan_id:id,nama_layanan:nama,satuan,jumlah:1,harga_satuan:harga,catatan_item:''});
      renderItems(); recalc();
    }
    return;
  }
  items.push({layanan_id:id,nama_layanan:nama,satuan,jumlah:1,harga_satuan:harga,catatan_item:''});
  renderItems(); recalc();
}

function addEmptyRow() {
  items.push({layanan_id:null,nama_layanan:'',satuan:'kg',jumlah:1,harga_satuan:0,catatan_item:''});
  renderItems();
}

function removeItem(idx) { items.splice(idx,1); renderItems(); recalc(); }

function renderItems() {
  const tbody = document.getElementById('itemsBody');
  const empty = document.getElementById('emptyItems');
  if (!items.length) {
    tbody.innerHTML = '';
    empty.style.display = 'block';
    document.getElementById('btnSave').disabled = true;
    return;
  }
  empty.style.display = 'none';
  document.getElementById('btnSave').disabled = false;
  tbody.innerHTML = items.map((item, i) => `
    <tr>
      <td data-lbl="Layanan"><input class="item-input" style="width:100%;min-width:120px" value="${esc(item.nama_layanan)}"
        placeholder="Nama layanan" oninput="items[${i}].nama_layanan=this.value;recalc()"/></td>
      <td data-lbl="Satuan"><select class="item-input" style="width:64px" onchange="items[${i}].satuan=this.value">
        ${['kg','pcs','set','pasang'].map(s=>`<option value="${s}" ${item.satuan===s?'selected':''}>${s}</option>`).join('')}
      </select></td>
      <td data-lbl="Jumlah"><input class="item-input" type="number" value="${item.jumlah}" min="0.1" step="0.1" style="width:64px"
        oninput="items[${i}].jumlah=parseFloat(this.value)||0;recalc()"/></td>
      <td data-lbl="Harga"><input class="item-input" type="number" value="${item.harga_satuan}" min="0" step="500" style="width:96px"
        oninput="items[${i}].harga_satuan=parseFloat(this.value)||0;recalc()"/></td>
      <td data-lbl="Subtotal" class="item-subtotal">Rp ${(item.jumlah*item.harga_satuan).toLocaleString('id-ID')}</td>
      <td data-lbl="Catatan"><input class="item-input" value="${esc(item.catatan_item)}" placeholder="..."
        style="width:72px" oninput="items[${i}].catatan_item=this.value"/></td>
      <td><button class="btn-remove" onclick="removeItem(${i})">✕ Hapus</button></td>
    </tr>`).join('');
}

function recalc() {
  const subtotal = items.reduce((s,i) => s + i.jumlah*i.harga_satuan, 0);
  const diskon   = parseFloat(document.getElementById('f_diskon').value)||0;

  // Loyalty redeem → diskon
  let redeemValue = 0, redeemPoin = 0;
  if (LOYALTY.enabled && currentPelangganId) {
    redeemPoin = parseInt(document.getElementById('f_redeem_poin')?.value || 0) || 0;
    const maxByRp = Math.floor(Math.max(0, subtotal-diskon)/LOYALTY.poin_value);
    redeemPoin = Math.max(0, Math.min(redeemPoin, currentPelangganPoin, maxByRp));
    redeemValue = redeemPoin * LOYALTY.poin_value;
    const ri = document.getElementById('redeemInfo');
    if (ri) {
      if (redeemPoin > 0) { ri.style.display='block'; ri.textContent = `−Rp ${redeemValue.toLocaleString('id-ID')} dari ${redeemPoin} poin`; }
      else ri.style.display='none';
    }
  }

  const total    = Math.max(subtotal - diskon - redeemValue, 0);
  const dp       = parseFloat(document.getElementById('f_dp').value)||0;
  const sisa     = total - dp;

  document.getElementById('sumSubtotal').textContent = 'Rp ' + subtotal.toLocaleString('id-ID');
  document.getElementById('sumDiskon').textContent   = (diskon + redeemValue).toLocaleString('id-ID');
  document.getElementById('sumTotal').textContent    = 'Rp ' + total.toLocaleString('id-ID');
  document.getElementById('sumDP').textContent       = 'Rp ' + dp.toLocaleString('id-ID');
  document.getElementById('sumSisa').textContent     = 'Rp ' + sisa.toLocaleString('id-ID');

  const cells = document.querySelectorAll('.item-subtotal');
  items.forEach((item, i) => { if(cells[i]) cells[i].textContent='Rp '+(item.jumlah*item.harga_satuan).toLocaleString('id-ID'); });

  const info = document.getElementById('statusBayarInfo');
  if (!items.length) {
    info.textContent='Belum ada item';info.style.background='var(--light)';info.style.color='var(--gray)';
  } else if (dp >= total && total > 0) {
    info.textContent='✅ LUNAS';info.style.background='#D1FAE5';info.style.color='#065F46';
  } else if (dp > 0) {
    info.textContent='⚡ DP — Sisa Rp '+sisa.toLocaleString('id-ID');info.style.background='#FEF3C7';info.style.color='#92400E';
  } else {
    info.textContent='⏳ Belum Bayar';info.style.background='#FEE2E2';info.style.color='#991B1B';
  }
}

function searchPelanggan(q) {
  clearTimeout(acTimeout);
  const list = document.getElementById('acList');
  if (q.length < 2) { list.classList.remove('open'); return; }
  acTimeout = setTimeout(async () => {
    const res  = await fetch('pos.php?action=search_pelanggan&q=' + encodeURIComponent(q));
    const data = await res.json();
    if (!data.length) { list.classList.remove('open'); return; }
    list.innerHTML = data.map(p => `
      <div class="ac-item" onclick="selectPelanggan(${p.id},'${esc(p.nama)}','${esc(p.telepon||'')}',${parseInt(p.poin_balance||0)})">
        <div>${esc(p.nama)}${LOYALTY.enabled && (p.poin_balance>0)?` <span style="font-size:11px;color:#0891B2">⭐${p.poin_balance}</span>`:''}</div>
        <div class="ac-sub">${p.telepon||'No telepon'} · ${p.tipe} · ${p.total_order} order</div>
      </div>`).join('');
    list.classList.add('open');
  }, 300);
}

let currentPelangganId = null;
let aiChatOpen = false;

const TIER_BADGE  = {regular:'',silver:'🥈 Silver',gold:'🥇 Gold',platinum:'💎 Platinum'};
const TIER_COLOR  = {regular:'#94A3B8',silver:'#94A3B8',gold:'#D97706',platinum:'#7C3AED'};
const SEGMEN_BADGE= {baru:'🆕 Baru',regular:'',vip:'⭐ VIP',dormant:'😴 Dormant'};
const SEGMEN_COLOR= {baru:'#0891B2',regular:'#94A3B8',vip:'#D97706',dormant:'#9CA3AF'};

function selectPelanggan(id, nama, telp, poin) {
  currentPelangganId = id;
  currentPelangganPoin = parseInt(poin||0);
  document.getElementById('f_nama').value    = nama;
  document.getElementById('f_telepon').value = telp;
  document.getElementById('acList').classList.remove('open');
  document.getElementById('aiFloating').style.display = 'block';
  document.getElementById('aiStatusText').textContent = nama;
  document.getElementById('aiNotifDot').style.display = 'block';
  // Fetch info detail (poin, tier, segmen, rewards, preferensi)
  loadPelangganInfo(id);
}

async function loadPelangganInfo(id){
  try {
    const r = await fetch('pos.php?action=pelanggan_poin&id=' + id);
    const d = await r.json();
    if (d.error) { updateLoyaltyBox(); return; }
    currentPelangganPoin = parseInt(d.pelanggan.poin || 0);
    // Auto-load catatan_tetap (preferensi) ke field catatan
    const cat = document.getElementById('f_catatan');
    const cur = (cat?.value || '').trim();
    if (cat && d.pelanggan.catatan_tetap && cur === '') {
      cat.value = d.pelanggan.catatan_tetap;
      showToast('💡 Catatan tetap pelanggan otomatis dimuat','success');
    }
    renderTierSegmenBadges(d.pelanggan);
    renderRewards(d.rewards || [], d.pelanggan.poin);
    updateLoyaltyBox();
  } catch(e) { updateLoyaltyBox(); }
}

function renderTierSegmenBadges(p){
  const t = document.getElementById('loyaltyTierBadge');
  const s = document.getElementById('loyaltySegmenBadge');
  if (t) {
    if (TIER_BADGE[p.tier]) { t.textContent = TIER_BADGE[p.tier]; t.style.color = TIER_COLOR[p.tier]; t.style.display=''; }
    else { t.style.display='none'; }
  }
  if (s) {
    if (SEGMEN_BADGE[p.segmen]) { s.textContent = SEGMEN_BADGE[p.segmen]; s.style.background = SEGMEN_COLOR[p.segmen]+'20'; s.style.color = SEGMEN_COLOR[p.segmen]; s.style.display=''; }
    else { s.style.display='none'; }
  }
}

function renderRewards(rewards, poin){
  const list = document.getElementById('rewardsList');
  if (!list) return;
  if (!rewards.length) { list.style.display='none'; return; }
  list.style.display='block';
  list.innerHTML = rewards.map(r => {
    const ok = !!r.bisa_redeem;
    const tipeLabel = {
      diskon_nominal: 'Diskon Rp ' + parseInt(r.nilai).toLocaleString('id-ID'),
      diskon_persen:  'Diskon ' + r.nilai + '%',
      gratis_layanan: 'Gratis Layanan'
    }[r.tipe] || '';
    return `<div style="display:flex;align-items:center;gap:8px;padding:7px 9px;margin-bottom:5px;border-radius:7px;border:1px solid ${ok?'rgba(8,145,178,.25)':'rgba(148,163,184,.2)'};background:${ok?'#fff':'#F8FAFC'};opacity:${ok?1:.65}">
      <div style="flex:1;min-width:0">
        <div style="font-size:12px;font-weight:700;color:#0F1C3A;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(r.nama_reward)}</div>
        <div style="font-size:10px;color:#64748B">${tipeLabel} · <strong>${r.poin_dibutuhkan} poin</strong>${!ok?' · butuh '+r.kurang+' lagi':''}</div>
      </div>
      ${ok
        ? `<button type="button" class="btn btn-teal-sm" style="padding:5px 11px;font-size:11px;white-space:nowrap" onclick="useReward(${r.poin_dibutuhkan},${parseInt(r.nilai)},'${r.tipe}','${esc(r.nama_reward)}')">✓ Pakai</button>`
        : `<span style="font-size:10px;color:#94A3B8">🔒</span>`}
    </div>`;
  }).join('');
}

function useReward(poin, nilai, tipe, nama){
  // Set redeem ke jumlah poin reward — recalc otomatis applied
  document.getElementById('f_redeem_poin').value = poin;
  showToast('🎁 Reward dipakai: ' + nama, 'success');
  recalc();
}

function updateLoyaltyBox(){
  const box = document.getElementById('loyaltyBox');
  if (!box) return;
  if (LOYALTY.enabled && currentPelangganId && currentPelangganPoin > 0) {
    document.getElementById('loyaltyBal').textContent = currentPelangganPoin.toLocaleString('id-ID');
    box.style.display = 'block';
  } else {
    box.style.display = 'none';
    const rp = document.getElementById('f_redeem_poin'); if (rp) rp.value = 0;
    const rl = document.getElementById('rewardsList'); if (rl) rl.style.display = 'none';
    const tb = document.getElementById('loyaltyTierBadge'); if (tb) tb.style.display = 'none';
    const sb = document.getElementById('loyaltySegmenBadge'); if (sb) sb.style.display = 'none';
  }
}

function redeemMax(){
  const subtotal = items.reduce((s,i)=>s+i.jumlah*i.harga_satuan,0);
  const diskon   = parseFloat(document.getElementById('f_diskon').value)||0;
  const maxByRp  = Math.floor(Math.max(0, subtotal-diskon) / LOYALTY.poin_value);
  const maxPoin  = Math.min(currentPelangganPoin, maxByRp);
  document.getElementById('f_redeem_poin').value = maxPoin;
  recalc();
}

function toggleAIChat() {
  aiChatOpen = !aiChatOpen;
  const panel = document.getElementById('aiChatPanel');
  const btn   = document.getElementById('aiBubbleBtn');
  panel.style.display = aiChatOpen ? 'flex' : 'none';
  btn.style.transform = aiChatOpen ? 'scale(0.9)' : 'scale(1)';
  btn.textContent     = aiChatOpen ? '✕' : '✨';
  if (aiChatOpen) { document.getElementById('aiNotifDot').style.display='none'; loadAIRekomendasi(); }
}

async function loadAIRekomendasi() {
  if (!currentPelangganId) return;
  const btn = document.getElementById('btnRefreshAI');
  btn.disabled=true; btn.textContent='⏳';
  document.getElementById('aiStatusText').textContent='Sedang menganalisis...';
  document.getElementById('aiContent').innerHTML=`<div style="text-align:center;padding:24px;color:var(--gray)"><div style="font-size:1.5rem;margin-bottom:8px;animation:spin 1s linear infinite;display:inline-block">⚙️</div><div style="font-size:13px">Menganalisis histori customer...</div></div>`;
  try {
    const r = await fetch('ai.php?action=upselling', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({pelanggan_id:currentPelangganId,current_items:items})
    });
    // Defensive: kalau endpoint return HTML (404/500), jangan crash JSON.parse
    const txt = await r.text();
    let d;
    try { d = JSON.parse(txt); }
    catch (parseErr) {
      const isMissing = r.status === 404 || /not found|<!doctype|<html/i.test(txt.substring(0,200));
      document.getElementById('aiContent').innerHTML =
        `<div style="background:#FEF3C7;border-left:4px solid #F59E0B;padding:12px 14px;border-radius:8px;font-size:13px;color:#92400E">
          <div style="font-weight:700;margin-bottom:6px">⚠️ Fitur AI Rekomendasi belum tersedia</div>
          <div>Endpoint <code>ai.php?action=upselling</code> ${isMissing?'belum dibuat di server':'mengembalikan respons tidak valid'}. Hubungi admin untuk aktivasi modul AI Upselling.</div>
          <div style="margin-top:8px;font-size:11px;color:var(--gray)">Status HTTP: ${r.status}</div>
        </div>`;
      document.getElementById('aiStatusText').textContent = 'AI belum aktif';
      return;
    }
    if (d.error) {
      document.getElementById('aiContent').innerHTML=`<div style="color:var(--red);font-size:13px;padding:12px">❌ ${d.error}</div>`;
      return;
    }
    const data=d.data;
    const segmen={'new':'Baru','regular':'Regular','vip':'VIP'}[data.segmen]||data.segmen;
    const segmenColor={'new':'var(--blue)','regular':'var(--teal-d)','vip':'#F59E0B'}[data.segmen]||'var(--gray)';
    document.getElementById('aiStatusText').textContent=segmen+' · '+(data.rekomendasi?.length||0)+' rekomendasi';
    document.getElementById('aiContent').innerHTML=`
      <div style="margin-bottom:12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span style="background:${segmenColor};color:white;font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px">${segmen}</span>
        <span style="font-size:12px;color:var(--gray);font-style:italic">"${esc(data.insight)}"</span>
      </div>
      ${(data.rekomendasi||[]).map((r,i)=>`
      <div style="background:${i===0?'#F5F3FF':'white'};border-radius:10px;padding:12px;margin-bottom:8px;border:1.5px solid ${i===0?'rgba(139,92,246,.25)':'rgba(27,45,90,.08)'}">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px">
          <div style="font-size:13px;font-weight:700;color:var(--navy)">${i===0?'⭐ ':''}${esc(r.layanan)}</div>
          <span style="font-size:10px;font-weight:700;background:var(--teal-bg);color:var(--teal-d);padding:2px 6px;border-radius:100px;white-space:nowrap;flex-shrink:0;margin-left:6px">+${esc(r.potensi_revenue)}</span>
        </div>
        <div style="font-size:11px;color:var(--gray);margin-bottom:7px">${esc(r.alasan)}</div>
        <div style="background:var(--off);border-radius:7px;padding:7px 10px;font-size:11px;color:var(--navy);border-left:3px solid var(--teal);font-style:italic;line-height:1.5">"${esc(r.script)}"</div>
      </div>`).join('')}
      <div style="font-size:10px;color:var(--gray);text-align:right;margin-top:4px">AI · ${new Date().toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'})}</div>`;
  } catch(e) {
    document.getElementById('aiContent').innerHTML=`<div style="color:var(--red);font-size:13px;padding:12px">❌ Error: ${e.message}</div>`;
    document.getElementById('aiStatusText').textContent='Error';
  } finally {
    btn.disabled=false; btn.textContent='↻';
  }
}

document.addEventListener('click', e => {
  if (!e.target.closest('.autocomplete-wrap'))
    document.getElementById('acList').classList.remove('open');
});

function saveTransaksi() {
  const nama = document.getElementById('f_nama').value.trim();
  const telp = document.getElementById('f_telepon').value.trim();
  if (!nama) { showToast('⚠️ Nama pelanggan wajib diisi', 'error'); return; }
  if (!telp) { showToast('⚠️ Nomor HP wajib diisi', 'error'); return; }
  if (!items.length) { showToast('⚠️ Minimal 1 item layanan', 'error'); return; }

  // Tampilkan konfirmasi modal dulu
  const total = document.getElementById('sumTotal')?.textContent || 'Rp 0';
  const dp    = document.getElementById('sumDP')?.textContent || 'Rp 0';
  const sisa  = document.getElementById('sumSisa')?.textContent || 'Rp 0';
  const metode = document.getElementById('f_metode')?.options[document.getElementById('f_metode').selectedIndex]?.text || '-';
  const fotoOK = !!document.getElementById('f_foto_path').value;

  document.getElementById('cfmBody').innerHTML =
    '<div><strong>' + escapeHtml(nama) + '</strong> · ' + escapeHtml(telp) + '</div>' +
    '<div>Item: <strong>' + items.length + '</strong> baris</div>' +
    '<div>Total: <strong>' + total + '</strong></div>' +
    '<div>DP/Bayar: <strong>' + dp + '</strong> (' + metode + ')</div>' +
    '<div>Sisa: <strong>' + sisa + '</strong></div>' +
    '<div style="margin-top:4px;color:' + (fotoOK?'var(--green)':'var(--gray)') + '">' +
      (fotoOK ? '📸 Foto kondisi terlampir' : '📸 Tanpa foto kondisi') + '</div>';
  const modal = document.getElementById('confirmSaveModal');
  modal.style.display = 'flex';
  setTimeout(()=>document.getElementById('cfmYes')?.focus(), 50);
}

function escapeHtml(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

async function doSaveTransaksi() {
  closeCfm();
  const nama = document.getElementById('f_nama').value.trim();
  const telp = document.getElementById('f_telepon').value.trim();
  if (!nama || !telp || !items.length) return;

  const btn = document.getElementById('btnSave');
  btn.disabled=true; btn.textContent='⏳ Menyimpan...';

  const payload = {
    tanggal:        document.getElementById('f_tanggal').value,
    estimasi:       document.getElementById('f_estimasi').value,
    nama_pelanggan: nama,
    telepon:        document.getElementById('f_telepon').value,
    catatan:        document.getElementById('f_catatan').value,
    diskon:         document.getElementById('f_diskon').value,
    redeem_poin:    (LOYALTY.enabled && currentPelangganId) ? (parseInt(document.getElementById('f_redeem_poin')?.value||0)||0) : 0,
    dp:             document.getElementById('f_dp').value,
    metode_bayar:   document.getElementById('f_metode').value,
    foto_masuk:     document.getElementById('f_foto_path').value || '',
    items
  };

  try {
    const res  = await fetch('pos.php?action=save', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.success) {
      if (appliedVoucher) {
        await fetch('promo.php?action=apply_voucher', {
          method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
          body: JSON.stringify({voucher_id:appliedVoucher.voucher_id||null,promo_id:appliedVoucher.promo_id||null,no_order:data.no_order})
        });
      }
      showToast('✅ Order ' + data.no_order + ' tersimpan!', 'success');
      lastSaved = data;
      await showStruk(data.id);
      resetForm();
    } else {
      showToast('❌ ' + (data.error||'Gagal menyimpan'), 'error');
    }
  } catch(e) {
    showToast('❌ Error: ' + e.message, 'error');
  }
  btn.disabled=false; btn.textContent='💾 Simpan & Print Struk';
}

async function showStruk(id) {
  const res  = await fetch('pos.php?action=get_detail&id=' + id);
  const data = await res.json();
  if (data.error) return;

  const isFull    = parseFloat(data.dp) >= parseFloat(data.total);
  const metodeTxt = {'cash':'Cash','transfer':'Transfer Bank','qris':'QRIS'}[data.metode_bayar]||data.metode_bayar;
  const trackUrl  = 'https://harpy.id/ERP/track.php?order=' + encodeURIComponent(data.no_order);

  const itemRows = (data.items||[]).map(item => `
    <div class="struk-item">
      ${item.nama_layanan}${item.catatan_item?' ('+item.catatan_item+')':''}
      <br>&nbsp;&nbsp;${parseFloat(item.jumlah).toLocaleString('id-ID')} ${item.satuan} x Rp ${parseFloat(item.harga_satuan).toLocaleString('id-ID')}
    </div>
    <div class="struk-row">
      <span></span>
      <span>Rp ${parseFloat(item.subtotal).toLocaleString('id-ID')}</span>
    </div>`).join('');

  document.getElementById('strukPrint').innerHTML = `
    <div class="struk">
      <div class="struk-header">
        <h2>HARPY LAUNDRY</h2>
        <p>Jl. Rawa Selatan IV No.1, Johar Baru</p>
        <p>Jakarta Pusat | +62 896-1525-9302</p>
        <p>harpy.id</p>
      </div>
      <div class="struk-row"><span>No. Order</span><span>${data.no_order}</span></div>
      <div class="struk-row"><span>Tanggal</span><span>${formatDate(data.tanggal)}</span></div>
      <div class="struk-row"><span>Pelanggan</span><span>${data.nama_pelanggan}</span></div>
      ${data.telepon?`<div class="struk-row"><span>Telp</span><span>${data.telepon}</span></div>`:''}
      ${data.estimasi_selesai?`<div class="struk-row"><span>Est. Selesai</span><span>${formatDate(data.estimasi_selesai)}</span></div>`:''}
      <hr class="struk-divider"/>
      ${itemRows}
      <hr class="struk-divider"/>
      <div class="struk-row"><span>Subtotal</span><span>Rp ${parseFloat(data.subtotal).toLocaleString('id-ID')}</span></div>
      ${parseFloat(data.diskon)>0?`<div class="struk-row"><span>Diskon${appliedVoucher?' ('+esc(appliedVoucher.kode)+')':''}</span><span>- Rp ${parseFloat(data.diskon).toLocaleString('id-ID')}</span></div>`:''}
      <div class="struk-total">
        <div class="struk-row bold"><span>TOTAL</span><span>Rp ${parseFloat(data.total).toLocaleString('id-ID')}</span></div>
        <div class="struk-row"><span>Bayar (${metodeTxt})</span><span>Rp ${parseFloat(data.dp).toLocaleString('id-ID')}</span></div>
        ${!isFull?`<div class="struk-row bold"><span>SISA BAYAR</span><span>Rp ${parseFloat(data.sisa_bayar).toLocaleString('id-ID')}</span></div>`:''}
      </div>
      ${data.catatan?`<hr class="struk-divider"/><div style="font-size:11px">Catatan: ${data.catatan}</div>`:''}
      <div class="struk-footer">
        <p>${isFull?'** LUNAS **':'** BELUM LUNAS **'}</p>
        <div style="margin:8px auto;width:80px;height:80px" id="qrcode"></div>
        <p style="font-size:9px">Scan untuk cek status</p>
        <p>Terima kasih telah mempercayakan</p>
        <p>cucian Anda kepada Harpy Laundry!</p>
      </div>
    </div>`;

  const qrEl = document.getElementById('qrcode');
  if (qrEl) {
    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' + encodeURIComponent(trackUrl);
    qrEl.innerHTML = `<img src="${qrUrl}" width="80" height="80" style="display:block"/>`;
  }
  document.getElementById('modalStruk').classList.add('open');
}

function printStruk() { window.print(); }
function closeModal()  { document.getElementById('modalStruk').classList.remove('open'); }

let appliedVoucher = null;

async function applyVoucher() {
  const kode = document.getElementById('f_voucher').value.trim().toUpperCase();
  if (!kode) { showToast('⚠️ Masukkan kode voucher/promo', 'error'); return; }
  const subtotal = items.reduce((s,i)=>s+i.jumlah*i.harga_satuan, 0);
  if (subtotal <= 0) { showToast('⚠️ Tambahkan item terlebih dahulu', 'error'); return; }

  try {
    const r = await fetch('promo.php?action=validate&kode=' + encodeURIComponent(kode) + '&total=' + subtotal);
    const d = await r.json();
    if (d.valid) {
      appliedVoucher = d;
      document.getElementById('f_diskon').value = Math.round(d.diskon);
      recalc();
      const infoEl = document.getElementById('voucherInfo');
      infoEl.style.display = 'block';
      infoEl.className = 'voucher-applied';
      document.getElementById('voucherInfoText').innerHTML =
        '✅ <strong>' + esc(d.kode) + '</strong> — ' + esc(d.nama) +
        ' <span style="background:#065F46;color:white;padding:2px 8px;border-radius:100px;font-size:11px;font-weight:700">' + esc(d.info) + '</span>' +
        '<br><span style="font-size:12px;opacity:.8">Diskon: Rp ' + Math.round(d.diskon).toLocaleString('id-ID') + '</span>';
      document.getElementById('f_voucher').disabled = true;
      showToast('✅ Voucher berhasil dipakai! Diskon Rp ' + Math.round(d.diskon).toLocaleString('id-ID'), 'success');
    } else {
      showToast('❌ ' + (d.error||'Kode tidak valid'), 'error');
    }
  } catch(e) { showToast('❌ Error: ' + e.message, 'error'); }
}

function removeVoucher() {
  appliedVoucher = null;
  document.getElementById('f_voucher').value    = '';
  document.getElementById('f_voucher').disabled = false;
  document.getElementById('f_diskon').value     = '0';
  document.getElementById('voucherInfo').style.display = 'none';
  recalc();
  showToast('🎟️ Kode voucher dihapus', 'success');
}

function resetForm() {
  items = []; appliedVoucher = null;
  renderItems(); recalc();
  ['f_nama','f_telepon','f_catatan'].forEach(id => document.getElementById(id).value='');
  document.getElementById('f_diskon').value='0';
  document.getElementById('f_dp').value='0';
  document.getElementById('f_metode').value='cash';
  document.getElementById('f_voucher').value='';
  document.getElementById('f_voucher').disabled=false;
  document.getElementById('voucherInfo').style.display='none';
  const today=localDateStr();
  document.getElementById('f_tanggal').value=today;
  const est=new Date(); est.setDate(est.getDate()+2);
  document.getElementById('f_estimasi').value=localDateStr(est);
  currentPelangganId=null; currentPelangganPoin=0;
  const rp=document.getElementById('f_redeem_poin'); if(rp) rp.value='0';
  updateLoyaltyBox();
  if (typeof clearFoto === 'function') clearFoto();
}

function formatDate(d) {
  if (!d) return '-';
  return new Date(d).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'});
}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
function showToast(msg,type='success'){const t=document.getElementById('toast');t.textContent=msg;t.className='toast '+type+' show';setTimeout(()=>t.className='toast',3500)}
</script>
<!-- KONFIRMASI MODAL -->
<div id="confirmSaveModal" style="display:none;position:fixed;inset:0;background:rgba(15,28,58,.55);z-index:2000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:14px;padding:20px 22px;max-width:380px;width:90%;box-shadow:0 12px 40px rgba(15,28,58,.25)">
    <div style="font-size:16px;font-weight:800;color:var(--navy);margin-bottom:12px">📋 Konfirmasi Order Baru</div>
    <div id="cfmBody" style="font-size:13px;color:#334155;line-height:1.7;background:#F8FAFC;border-radius:9px;padding:12px 14px;margin-bottom:14px"></div>
    <div style="font-size:11px;color:var(--gray);margin-bottom:14px">Pastikan data benar — order yang sudah tersimpan tidak bisa dibatalkan dari POS.</div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-outline" style="flex:1" onclick="closeCfm()">✕ Batal</button>
      <button class="btn btn-primary" style="flex:1.4" id="cfmYes" onclick="doSaveTransaksi()">✓ Ya, Simpan (Enter)</button>
    </div>
  </div>
</div>

<?php renderToast(); ?>
</body>
</html>
