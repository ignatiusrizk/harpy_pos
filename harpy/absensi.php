<?php
$activePage = 'absensi';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once __DIR__ . '/components.php';
$user = currentUser();

// ── API ───────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $oid = TenantResolver::outletId();

    // CLOCK IN
    if ($action === 'clock_in' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $d   = json_decode(file_get_contents('php://input'), true);
        $tgl = date('Y-m-d');
        $jam = date('H:i:s');

        $row = TenantQuery::rawOne(
            "SELECT * FROM hl_absensi WHERE tenant_id=? AND outlet_id=? AND user_id=? AND tanggal=?",
            [$tid, $oid, $user['id'], $tgl]
        );

        if ($row) {
            if ($row['jam_masuk']) {
                echo json_encode(['error' => 'Anda sudah clock in hari ini jam ' . substr($row['jam_masuk'],0,5)]);
            } else {
                echo json_encode(['error' => 'Data absensi hari ini sudah ada']);
            }
            exit;
        }

        TenantQuery::insert('hl_absensi', [
            'user_id'     => $user['id'],
            'tanggal'     => $tgl,
            'jam_masuk'   => $jam,
            'lokasi_masuk'=> substr(trim(strip_tags($d['lokasi'] ?? '')), 0, 255) ?: null,
            'status'      => 'hadir',
        ]);

        logAudit('clock_in', 'absensi', 'Tanggal: ' . $tgl);
        echo json_encode(['success' => true, 'jam' => substr($jam,0,5), 'tanggal' => $tgl]);
        exit;
    }

    // CLOCK OUT
    if ($action === 'clock_out' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $d   = json_decode(file_get_contents('php://input'), true);
        $tgl = date('Y-m-d');
        $jam = date('H:i:s');

        $row = TenantQuery::rawOne(
            "SELECT * FROM hl_absensi WHERE tenant_id=? AND outlet_id=? AND user_id=? AND tanggal=?",
            [$tid, $oid, $user['id'], $tgl]
        );

        if (!$row) {
            echo json_encode(['error' => 'Anda belum clock in hari ini']); exit;
        }
        if ($row['jam_keluar']) {
            echo json_encode(['error' => 'Anda sudah clock out jam ' . substr($row['jam_keluar'],0,5)]); exit;
        }

        $masuk  = strtotime($tgl . ' ' . $row['jam_masuk']);
        $keluar = strtotime($tgl . ' ' . $jam);
        $durasi = round(($keluar - $masuk) / 60);

        TenantQuery::update('hl_absensi',
            ['jam_keluar' => $jam, 'durasi_menit' => $durasi,
             'lokasi_keluar' => substr(trim(strip_tags($d['lokasi'] ?? '')), 0, 255) ?: null],
            'id = ?', [$row['id']]
        );

        logAudit('clock_out', 'absensi', 'Durasi: ' . $durasi . ' menit');
        $jam_str = substr($jam,0,5);
        $dur_str = floor($durasi/60) . ' jam ' . ($durasi%60) . ' menit';
        echo json_encode(['success'=>true, 'jam'=>$jam_str, 'durasi'=>$dur_str]);
        exit;
    }

    // STATUS HARI INI
    if ($action === 'status_hari_ini') {
        $tgl = date('Y-m-d');
        $row = TenantQuery::rawOne(
            "SELECT * FROM hl_absensi WHERE tenant_id=? AND outlet_id=? AND user_id=? AND tanggal=?",
            [$tid, $oid, $user['id'], $tgl]
        );
        echo json_encode($row ?: ['status'=>'belum']);
        exit;
    }

    // REKAP PERSONAL
    if ($action === 'rekap_personal') {
        $bulan = $_GET['bulan'] ?? date('Y-m');
        [$y,$m] = explode('-', $bulan);
        $dari   = "$y-$m-01";
        $sampai = date('Y-m-t', strtotime($dari));

        $uid = hasPermission('absensi.view_all') && !empty($_GET['user_id'])
               ? intval($_GET['user_id']) : $user['id'];

        $data = TenantQuery::raw(
            "SELECT a.*, u.nama FROM hl_absensi a
             JOIN hl_users u ON u.id=a.user_id AND u.tenant_id=a.tenant_id
             WHERE a.tenant_id=? AND a.outlet_id=? AND a.user_id=? AND a.tanggal BETWEEN ? AND ?
             ORDER BY a.tanggal",
            [$tid, $oid, $uid, $dari, $sampai]
        );

        $hadir  = count(array_filter($data, fn($r) => $r['status']==='hadir'));
        $izin   = count(array_filter($data, fn($r) => $r['status']==='izin'));
        $sakit  = count(array_filter($data, fn($r) => $r['status']==='sakit'));
        $alpha  = count(array_filter($data, fn($r) => $r['status']==='alpha'));
        $total_menit = array_sum(array_column($data, 'durasi_menit'));

        echo json_encode([
            'data'    => $data,
            'summary' => compact('hadir','izin','sakit','alpha','total_menit'),
            'periode' => ['dari'=>$dari,'sampai'=>$sampai,'bulan'=>$bulan],
        ]); exit;
    }

    // REKAP SEMUA KARYAWAN (admin only)
    if ($action === 'rekap_all') {
        if (!hasPermission('absensi.view_all') && !hasPermission('absensi.approve_izin')) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
        $bulan  = $_GET['bulan'] ?? date('Y-m');
        [$y,$m] = explode('-', $bulan);
        $dari   = "$y-$m-01";
        $sampai = date('Y-m-t', strtotime($dari));

        $rows = TenantQuery::raw(
            "SELECT u.id, u.nama, u.role,
             COUNT(CASE WHEN a.status='hadir'  THEN 1 END) as hadir,
             COUNT(CASE WHEN a.status='izin'   THEN 1 END) as izin,
             COUNT(CASE WHEN a.status='sakit'  THEN 1 END) as sakit,
             COUNT(CASE WHEN a.status='alpha'  THEN 1 END) as alpha,
             COALESCE(SUM(a.durasi_menit),0) as total_menit,
             MAX(a.tanggal) as last_absen
             FROM hl_users u
             JOIN hl_karyawan_outlet ko
               ON ko.karyawan_id=u.id AND ko.tenant_id=u.tenant_id
              AND ko.outlet_id=? AND ko.is_active=1
             LEFT JOIN hl_absensi a ON a.user_id=u.id AND a.tenant_id=u.tenant_id AND a.outlet_id=?
                AND a.tanggal BETWEEN ? AND ?
             WHERE u.tenant_id=? AND u.is_active=1
             GROUP BY u.id ORDER BY u.nama",
            [$oid, $oid, $dari, $sampai, $tid]
        );
        echo json_encode(['data'=>$rows, 'periode'=>['bulan'=>$bulan,'dari'=>$dari,'sampai'=>$sampai]]);
        exit;
    }

    // INPUT IZIN/SAKIT
    if ($action === 'input_izin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $d    = json_decode(file_get_contents('php://input'), true);
        $dari = substr(trim($d['dari'] ?? ''), 0, 10);
        $samp = substr(trim($d['sampai'] ?? ''), 0, 10);
        $tipe = in_array($d['tipe']??'', ['izin','sakit','cuti']) ? $d['tipe'] : 'izin';
        $alas = substr(trim(strip_tags($d['alasan'] ?? '')), 0, 500);

        TenantQuery::insert('hl_izin', [
            'user_id'        => $user['id'],
            'dari_tanggal'   => $dari,
            'sampai_tanggal' => $samp,
            'tipe'           => $tipe,
            'alasan'         => $alas,
        ]);

        // INSERT IGNORE untuk range tanggal ke hl_absensi
        $db   = Database::get();
        $stmt = $db->prepare(
            "INSERT IGNORE INTO hl_absensi (tenant_id,outlet_id,user_id,tanggal,status,catatan)
             VALUES (?,?,?,?,?,?)"
        );
        $cur = strtotime($dari);
        $end = strtotime($samp);
        while ($cur <= $end) {
            $stmt->execute([$tid, $oid, $user['id'], date('Y-m-d',$cur), $tipe, $alas]);
            $cur = strtotime('+1 day', $cur);
        }

        logAudit('input_izin', 'absensi', $tipe . ': ' . $dari . ' – ' . $samp);
        echo json_encode(['success'=>true]); exit;
    }

    // APPROVE IZIN (admin only)
    if ($action === 'approve_izin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        if (!hasPermission('absensi.view_all') && !hasPermission('absensi.approve_izin')) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
        $d      = json_decode(file_get_contents('php://input'), true);
        $status = in_array($d['status']??'', ['approved','rejected']) ? $d['status'] : 'rejected';

        TenantQuery::update('hl_izin',
            ['status' => $status, 'approved_by' => $user['id']],
            'id = ?', [intval($d['id'])]
        );
        logAudit('approve_izin', 'absensi', 'ID: ' . intval($d['id']) . ' → ' . $status);
        echo json_encode(['success'=>true]); exit;
    }

    // LIST IZIN
    if ($action === 'list_izin') {
        if (hasPermission('absensi.view_all')) {
            $rows = TenantQuery::raw(
                "SELECT i.*,u.nama FROM hl_izin i
                 JOIN hl_users u ON u.id=i.user_id AND u.tenant_id=i.tenant_id
                 WHERE i.tenant_id=? AND i.outlet_id=? ORDER BY i.created_at DESC LIMIT 50",
                [$tid, $oid]
            );
        } else {
            $rows = TenantQuery::raw(
                "SELECT i.*,u.nama FROM hl_izin i
                 JOIN hl_users u ON u.id=i.user_id AND u.tenant_id=i.tenant_id
                 WHERE i.tenant_id=? AND i.outlet_id=? AND i.user_id=? ORDER BY i.created_at DESC LIMIT 20",
                [$tid, $oid, $user['id']]
            );
        }
        echo json_encode($rows); exit;
    }

    // LIST USERS (admin)
    if ($action === 'list_users') {
        if (!hasPermission('absensi.view_all') && !hasPermission('absensi.approve_izin')) {
            echo json_encode([]); exit;
        }
        // Hanya karyawan yang ditugaskan ke outlet ini (per brief HQ-Outlet)
        $rows = TenantQuery::raw(
            "SELECT u.id, u.nama, u.role
             FROM hl_users u
             JOIN hl_karyawan_outlet ko
               ON ko.karyawan_id=u.id AND ko.tenant_id=u.tenant_id
              AND ko.outlet_id=? AND ko.is_active=1
             WHERE u.tenant_id=? AND u.is_active=1
             ORDER BY u.nama",
            [$oid, $tid]
        );
        echo json_encode($rows); exit;
    }

    // HANDOVER: precompute (saldo kas, order_pending, order_siap_ambil)
    if ($action === 'handover_compute') {
        $tgl = $_GET['tanggal'] ?? date('Y-m-d');
        try {
            $db = Database::get();
            // Saldo kas akhir hari = pemasukan - pengeluaran hari ini (sederhana)
            $kas = 0;
            try {
                $st = $db->prepare("SELECT COALESCE(SUM(CASE WHEN tipe='masuk' THEN nominal ELSE -nominal END),0)
                                      FROM hl_kas WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal)=?");
                $st->execute([$tid, $oid, $tgl]);
                $kas = (int)$st->fetchColumn();
            } catch (Throwable $e) { $kas = 0; }

            $pending = TenantQuery::count('hl_transaksi',
                "status_proses IN ('masuk','cuci','kering','setrika')", []);
            $siap    = TenantQuery::count('hl_transaksi',
                "status_proses='siap'", []);

            // Cek existing handover hari ini
            $existing = null;
            try {
                $st = $db->prepare("SELECT * FROM hl_shift_handover WHERE tenant_id=? AND outlet_id=? AND tanggal=? AND user_id_keluar=? ORDER BY id DESC LIMIT 1");
                $st->execute([$tid, $oid, $tgl, $_SESSION['user_id'] ?? 0]);
                $existing = $st->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}

            echo json_encode([
                'ok' => true,
                'saldo_kas_akhir'   => $kas,
                'order_pending'     => $pending,
                'order_siap_ambil'  => $siap,
                'existing'          => $existing,
            ]);
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'handover_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $tgl   = $d['tanggal'] ?? date('Y-m-d');
        $shift = in_array(($d['shift'] ?? 'pagi'), ['pagi','sore','malam'], true) ? $d['shift'] : 'pagi';
        try {
            $db  = Database::get();
            $stmt = $db->prepare("INSERT INTO hl_shift_handover
                (tenant_id, outlet_id, user_id_keluar, user_id_masuk, tanggal, shift,
                 saldo_kas_akhir, order_pending, order_siap_ambil,
                 kondisi_mesin, catatan_khusus, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?, 'submitted')");
            $stmt->execute([
                $tid, $oid,
                $_SESSION['user_id'] ?? 0,
                !empty($d['user_id_masuk']) ? intval($d['user_id_masuk']) : null,
                $tgl, $shift,
                intval($d['saldo_kas_akhir'] ?? 0),
                intval($d['order_pending'] ?? 0),
                intval($d['order_siap_ambil'] ?? 0),
                trim($d['kondisi_mesin'] ?? ''),
                trim($d['catatan_khusus'] ?? ''),
            ]);
            logAudit('handover_submit', 'shift', "$tgl/$shift");
            echo json_encode(['ok'=>true, 'id'=>(int)$db->lastInsertId()]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>'Gagal simpan handover: '.$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'handover_ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $d  = json_decode(file_get_contents('php://input'), true);
        $id = intval($d['id'] ?? 0);
        if (!$id) { echo json_encode(['error'=>'id wajib']); exit; }
        try {
            $db = Database::get();
            $stmt = $db->prepare("UPDATE hl_shift_handover
                                     SET status='acknowledged', acknowledged_at=NOW(), acknowledged_by=?
                                   WHERE tenant_id=? AND outlet_id=? AND id=?");
            $stmt->execute([$_SESSION['user_id'] ?? 0, $tid, $oid, $id]);
            logAudit('handover_ack', 'shift#'.$id, '');
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'handover_pending') {
        try {
            $db = Database::get();
            $stmt = $db->prepare("SELECT h.*, u.nama AS nama_keluar
                                    FROM hl_shift_handover h
                                    LEFT JOIN hl_users u ON u.id=h.user_id_keluar
                                   WHERE h.tenant_id=? AND h.outlet_id=?
                                     AND h.status='submitted'
                                   ORDER BY h.id DESC LIMIT 5");
            $stmt->execute([$tid, $oid]);
            echo json_encode(['ok'=>true, 'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            echo json_encode(['ok'=>true, 'rows'=>[]]);
        }
        exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Absensi'); ?>
<style>
/* ── CLOCK WIDGET ── */
.clock-widget{background:linear-gradient(135deg,var(--navy-d),var(--navy));border-radius:20px;padding:32px;text-align:center;color:var(--white);margin-bottom:20px;position:relative;overflow:hidden}
.clock-widget::before{content:'';position:absolute;top:-60px;right:-60px;width:200px;height:200px;background:rgba(53,232,213,.06);border-radius:50%}
.clock-time{font-family:var(--mono);font-size:3rem;font-weight:800;color:var(--teal);letter-spacing:.06em;line-height:1;margin-bottom:6px}
.clock-date{font-size:14px;color:rgba(255,255,255,.5);margin-bottom:24px}
.clock-status{font-size:13px;font-weight:600;padding:8px 20px;border-radius:100px;display:inline-block;margin-bottom:20px}
.clock-status.belum{background:rgba(255,255,255,.08);color:rgba(255,255,255,.5)}
.clock-status.masuk{background:rgba(16,185,129,.2);color:#6EE7B7}
.clock-status.keluar{background:rgba(107,114,128,.2);color:rgba(255,255,255,.5)}
.clock-btns{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
.btn-clock-in{padding:14px 32px;background:var(--teal);color:var(--navy-d);border:none;border-radius:12px;font-family:var(--font);font-size:15px;font-weight:700;cursor:pointer;transition:all .2s}
.btn-clock-in:hover{background:var(--teal-d);transform:translateY(-2px);box-shadow:0 8px 24px rgba(53,232,213,.3)}
.btn-clock-out{padding:14px 32px;background:rgba(239,68,68,.15);color:#FCA5A5;border:1.5px solid rgba(239,68,68,.3);border-radius:12px;font-family:var(--font);font-size:15px;font-weight:700;cursor:pointer;transition:all .2s}
.btn-clock-out:hover{background:var(--red);color:white;transform:translateY(-2px)}
.btn-clock:disabled{opacity:.4;pointer-events:none}
.jam-info{display:flex;gap:16px;justify-content:center;margin-top:12px}
.jam-chip{background:rgba(255,255,255,.06);border-radius:10px;padding:8px 16px;font-size:13px}
.jam-chip span{font-family:var(--mono);font-weight:700;color:var(--teal)}

/* ── CALENDAR ── */
.absensi-cal{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-top:12px}
.cal-header{text-align:center;font-size:11px;font-weight:700;color:var(--gray);padding:6px 0;text-transform:uppercase;letter-spacing:.06em}
.cal-day{aspect-ratio:1;border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:12px;font-weight:600;cursor:default;transition:all .2s;border:1.5px solid transparent}
.cal-day.today{border-color:var(--teal);color:var(--teal)}
.cal-day.hadir{background:#D1FAE5;color:#065F46}
.cal-day.izin{background:#FEF3C7;color:#92400E}
.cal-day.sakit{background:#DBEAFE;color:#1D4ED8}
.cal-day.alpha{background:#FEE2E2;color:#991B1B}
.cal-day.libur{background:var(--off);color:var(--gray);opacity:.5}
.cal-day.empty{visibility:hidden}
.cal-dot{width:5px;height:5px;border-radius:50%;background:currentColor;margin-top:2px}

/* ── TABS ── */
.hl-tabs{display:flex;gap:4px;background:var(--white);border-radius:var(--r-lg);padding:6px;box-shadow:var(--shadow);margin-bottom:20px;border:1px solid rgba(27,45,90,.07)}
.hl-tab{flex:1;padding:10px 16px;border-radius:var(--r);font-size:14px;font-weight:600;color:var(--gray);cursor:pointer;text-align:center;transition:all .2s;border:none;background:transparent;font-family:var(--font)}
.hl-tab:hover{color:var(--navy)}
.hl-tab.active{background:var(--navy);color:var(--white)}

/* ── IZIN FORM ── */
.tipe-izin-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px}
.tipe-izin-btn{padding:12px 8px;border-radius:var(--r);border:2px solid rgba(27,45,90,.12);background:var(--off);cursor:pointer;text-align:center;font-family:var(--font);font-size:13px;font-weight:600;transition:all .2s;color:var(--navy)}
.tipe-izin-btn:hover{border-color:var(--teal)}
.tipe-izin-btn.active{border-color:var(--teal);background:var(--teal-bg);color:var(--navy)}

/* ── REKAP TABLE ── */
.durasi-bar{background:var(--light);border-radius:100px;height:6px;margin-top:4px;overflow:hidden}
.durasi-fill{height:100%;background:var(--teal);border-radius:100px;transition:width .5s}
@media(max-width:680px){
  .clock-widget{padding:22px 18px}
  .clock-time{font-size:2.2rem}
  .clock-date{font-size:12px;margin-bottom:16px}
  .btn-clock-in,.btn-clock-out{padding:12px 24px;font-size:14px}
  .jam-info{gap:10px}
  .tipe-izin-grid{grid-template-columns:1fr 1fr 1fr}
  #calStats{grid-template-columns:repeat(2,1fr) !important;gap:6px !important}
  .hl-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .hl-table thead th{font-size:11px;padding:8px 8px}
  .hl-table tbody td{font-size:12px;padding:8px 8px}
}
@media(max-width:400px){
  .clock-time{font-size:1.9rem}
  .clock-btns{gap:8px}
  .btn-clock-in,.btn-clock-out{padding:10px 18px;font-size:13px}
  #calStats{grid-template-columns:repeat(2,1fr) !important}
}
</style>
</head>
<body>
<?php renderTopbar('absensi'); ?>

<div class="hl-main">

  <!-- CLOCK WIDGET + REKAP PRIBADI (2 COL) -->
  <div class="hl-grid-2" style="margin-bottom:20px">

    <!-- CLOCK IN/OUT -->
    <div>
      <div class="clock-widget">
        <div class="clock-time" id="clockTime">--:--:--</div>
        <div class="clock-date" id="clockDate">--</div>
        <div class="clock-status belum" id="clockStatus">⏳ Memuat status...</div>
        <div class="clock-btns">
          <button class="btn-clock-in btn-clock" id="btnClockIn" onclick="clockIn()" disabled>
            ▶ Clock In
          </button>
          <button class="btn-clock-out btn-clock" id="btnClockOut" onclick="clockOut()" disabled>
            ■ Clock Out
          </button>
        </div>
        <div class="jam-info" id="jamInfo" style="display:none">
          <div class="jam-chip">Masuk: <span id="jamMasuk">-</span></div>
          <div class="jam-chip">Keluar: <span id="jamKeluar">-</span></div>
          <div class="jam-chip">Durasi: <span id="durasi">-</span></div>
        </div>
      </div>

      <!-- SERAH TERIMA SHIFT (handover) -->
      <div class="hl-card" style="margin-bottom:16px">
        <div class="hl-card-header">
          <div class="hl-card-title">🤝 Serah Terima Shift</div>
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="toggleHandover()">
            <span id="hoToggleLabel">Buka Form</span>
          </button>
        </div>
        <div class="hl-card-body" id="handoverBody" style="display:none">
          <div id="handoverPending" style="margin-bottom:10px"></div>
          <div class="hl-form-row" style="margin-bottom:10px">
            <div class="hl-form-group">
              <label class="hl-label">Tanggal</label>
              <input type="date" id="ho_tanggal" class="hl-input"/>
            </div>
            <div class="hl-form-group">
              <label class="hl-label">Shift</label>
              <select id="ho_shift" class="hl-input" onchange="">
                <option value="pagi">Pagi</option>
                <option value="sore">Sore</option>
                <option value="malam">Malam</option>
              </select>
            </div>
          </div>
          <div class="hl-form-row" style="margin-bottom:10px">
            <div class="hl-form-group">
              <label class="hl-label">Saldo Kas Akhir (Rp)</label>
              <input type="number" id="ho_kas" class="hl-input" step="500" min="0"/>
            </div>
            <div class="hl-form-group">
              <label class="hl-label">Diserahkan ke (opsional)</label>
              <select id="ho_user_masuk" class="hl-input">
                <option value="">— Pilih kasir penerus —</option>
              </select>
            </div>
          </div>
          <div class="hl-form-row" style="margin-bottom:10px">
            <div class="hl-form-group">
              <label class="hl-label">Order Pending</label>
              <input type="number" id="ho_pending" class="hl-input" min="0" readonly style="background:#F1F5F9"/>
            </div>
            <div class="hl-form-group">
              <label class="hl-label">Order Siap Diambil</label>
              <input type="number" id="ho_siap" class="hl-input" min="0" readonly style="background:#F1F5F9"/>
            </div>
          </div>
          <div class="hl-form-group" style="margin-bottom:10px">
            <label class="hl-label">Kondisi Mesin</label>
            <textarea id="ho_mesin" class="hl-input hl-textarea" placeholder="Mesin A normal, mesin B sedikit bunyi..." style="min-height:60px"></textarea>
          </div>
          <div class="hl-form-group" style="margin-bottom:12px">
            <label class="hl-label">Catatan Khusus</label>
            <textarea id="ho_catatan" class="hl-input hl-textarea" placeholder="Pelanggan A janji ambil sore, dll." style="min-height:60px"></textarea>
          </div>
          <div style="display:flex;gap:8px">
            <button class="hl-btn hl-btn-outline" onclick="refreshHandover()">↻ Refresh Data</button>
            <button class="hl-btn hl-btn-primary" style="flex:1" onclick="submitHandover()">📤 Submit Handover</button>
          </div>
          <small style="display:block;margin-top:8px;color:var(--gray)">
            ℹ️ Optional — tidak menghalangi Clock Out. Berguna untuk audit & shift swap.
          </small>
        </div>
      </div>

      <!-- FORM IZIN/SAKIT -->
      <div class="hl-card">
        <div class="hl-card-header">
          <div class="hl-card-title">📝 Ajukan Izin / Sakit</div>
        </div>
        <div class="hl-card-body">
          <div class="tipe-izin-grid">
            <button class="tipe-izin-btn active" id="tipeIzin" onclick="setTipeIzin('izin',this)">📋 Izin</button>
            <button class="tipe-izin-btn" onclick="setTipeIzin('sakit',this)">🤒 Sakit</button>
            <button class="tipe-izin-btn" onclick="setTipeIzin('cuti',this)">🏖️ Cuti</button>
          </div>
          <input type="hidden" id="f_tipe_izin" value="izin"/>
          <div class="hl-form-row" style="margin-bottom:12px">
            <div class="hl-form-group">
              <label class="hl-label">Dari Tanggal</label>
              <input type="date" id="f_izin_dari" class="hl-input"/>
            </div>
            <div class="hl-form-group">
              <label class="hl-label">Sampai Tanggal</label>
              <input type="date" id="f_izin_sampai" class="hl-input"/>
            </div>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Alasan</label>
            <textarea id="f_alasan" class="hl-input hl-textarea" placeholder="Keterangan izin..."></textarea>
          </div>
          <button class="hl-btn hl-btn-primary hl-btn-full" onclick="submitIzin()">
            📤 Ajukan
          </button>
        </div>
      </div>
    </div>

    <!-- KALENDER ABSENSI BULAN INI -->
    <div>
      <div class="hl-card" style="margin-bottom:16px">
        <div class="hl-card-header">
          <div class="hl-card-title">📅 Kalender Absensi</div>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="month" id="calBulan" class="hl-input" style="width:auto;font-size:13px;padding:5px 10px"/>
            <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadKalender()">↻</button>
          </div>
        </div>
        <div class="hl-card-body">
          <!-- Stat mini -->
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:16px" id="calStats">
            <div style="text-align:center;padding:10px;background:#D1FAE5;border-radius:10px">
              <div style="font-size:1.3rem;font-weight:800;color:#065F46" id="cHadir">-</div>
              <div style="font-size:11px;color:#065F46">Hadir</div>
            </div>
            <div style="text-align:center;padding:10px;background:#FEF3C7;border-radius:10px">
              <div style="font-size:1.3rem;font-weight:800;color:#92400E" id="cIzin">-</div>
              <div style="font-size:11px;color:#92400E">Izin</div>
            </div>
            <div style="text-align:center;padding:10px;background:#DBEAFE;border-radius:10px">
              <div style="font-size:1.3rem;font-weight:800;color:#1D4ED8" id="cSakit">-</div>
              <div style="font-size:11px;color:#1D4ED8">Sakit</div>
            </div>
            <div style="text-align:center;padding:10px;background:#FEE2E2;border-radius:10px">
              <div style="font-size:1.3rem;font-weight:800;color:#991B1B" id="cAlpha">-</div>
              <div style="font-size:11px;color:#991B1B">Alpha</div>
            </div>
          </div>
          <!-- Calendar grid -->
          <div class="absensi-cal" id="calGrid">
            <div class="cal-header">Min</div>
            <div class="cal-header">Sen</div>
            <div class="cal-header">Sel</div>
            <div class="cal-header">Rab</div>
            <div class="cal-header">Kam</div>
            <div class="cal-header">Jum</div>
            <div class="cal-header">Sab</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- TABS ADMIN -->
  <?php if (hasPermission('absensi.view_all') || hasPermission('absensi.approve_izin')): ?>
  <div class="hl-tabs">
    <button class="hl-tab active" onclick="switchTab('rekap',this)">📊 Rekap Semua Karyawan</button>
    <button class="hl-tab" onclick="switchTab('izin',this)">📋 Pengajuan Izin</button>
  </div>

  <!-- REKAP ALL -->
  <div id="tabRekap">
    <div class="hl-filter-collapsible">
      <button class="hl-filter-toggle-btn" id="rekapFilterBtn" onclick="toggleFilter('rekapFilter')">
        📅 Periode Rekap <span class="hl-toggle-arrow">▼</span>
      </button>
      <div class="hl-filter-bar" id="rekapFilter">
        <span class="hl-filter-label">Bulan</span>
        <input type="month" id="rekapBulan" class="hl-input" style="width:auto"/>
        <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="loadRekapAll()">🔍 Tampilkan</button>
      </div>
    </div>
    <div class="hl-card">
      <div class="hl-card-header">
        <div class="hl-card-title">👥 Rekap Kehadiran Karyawan</div>
        <span id="rekapInfo" style="font-size:12px;color:var(--gray)"></span>
      </div>
      <div class="hl-table-wrap">
        <table class="hl-table hl-stack-mobile">
          <thead>
            <tr>
              <th>Nama</th>
              <th>Role</th>
              <th style="text-align:center">Hadir</th>
              <th style="text-align:center">Izin</th>
              <th style="text-align:center">Sakit</th>
              <th style="text-align:center">Alpha</th>
              <th>Total Jam</th>
              <th>Rata-rata/hari</th>
              <th>Terakhir</th>
            </tr>
          </thead>
          <tbody id="rekapBody">
            <tr><td colspan="9" class="hl-loading">⏳ Pilih bulan dan klik Tampilkan</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- IZIN LIST -->
  <div id="tabIzin" style="display:none">
    <div class="hl-card">
      <div class="hl-card-header">
        <div class="hl-card-title">📋 Pengajuan Izin & Sakit</div>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadIzinList()">↻ Refresh</button>
      </div>
      <div class="hl-table-wrap">
        <table class="hl-table hl-stack-mobile">
          <thead>
            <tr>
              <th>Nama</th>
              <th>Tipe</th>
              <th>Dari</th>
              <th>Sampai</th>
              <th>Alasan</th>
              <th>Status</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody id="izinBody">
            <tr><td colspan="7" class="hl-loading">⏳ Memuat...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>

<?php renderToast(); ?>

<script>
const IS_ADMIN = <?= (hasPermission('absensi.view_all') || hasPermission('absensi.approve_izin')) ? 'true' : 'false' ?>;

// ── LIVE CLOCK ────────────────────────────────────────
function updateClock() {
  const now  = new Date();
  const time = now.toLocaleTimeString('id-ID', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
  const date = now.toLocaleDateString('id-ID', {weekday:'long',day:'numeric',month:'long',year:'numeric'});
  document.getElementById('clockTime').textContent = time;
  document.getElementById('clockDate').textContent = date;
}
setInterval(updateClock, 1000);
updateClock();

// ── HELPERS ───────────────────────────────────────────
function localDateStr(d) {
  const dt = d || new Date();
  return dt.getFullYear() + '-' +
    String(dt.getMonth()+1).padStart(2,'0') + '-' +
    String(dt.getDate()).padStart(2,'0');
}
function localMonthStr(d) {
  const dt = d || new Date();
  return dt.getFullYear() + '-' + String(dt.getMonth()+1).padStart(2,'0');
}

document.addEventListener('DOMContentLoaded', () => {
  initFilter('rekapFilter');
  const today = localDateStr();
  const bulan = today.substring(0,7);
  document.getElementById('calBulan').value    = bulan;
  document.getElementById('f_izin_dari').value  = today;
  document.getElementById('f_izin_sampai').value = today;
  if (IS_ADMIN) document.getElementById('rekapBulan').value = bulan;

  loadStatusHariIni();
  loadKalender();
  loadIzinList();

  // Handover defaults
  document.getElementById('ho_tanggal').value = today;
  const h = new Date().getHours();
  document.getElementById('ho_shift').value = (h < 12 ? 'pagi' : (h < 18 ? 'sore' : 'malam'));
});

// ── HANDOVER SHIFT ────────────────────────────────────
let handoverOpen = false;
async function toggleHandover() {
  handoverOpen = !handoverOpen;
  document.getElementById('handoverBody').style.display = handoverOpen ? 'block' : 'none';
  document.getElementById('hoToggleLabel').textContent  = handoverOpen ? 'Tutup' : 'Buka Form';
  if (handoverOpen) {
    await refreshHandover();
    await loadHandoverUsers();
    await loadHandoverPending();
  }
}

async function refreshHandover() {
  const tgl = document.getElementById('ho_tanggal').value || localDateStr();
  try {
    const r = await fetch('absensi.php?action=handover_compute&tanggal=' + tgl);
    const d = await r.json();
    if (d.error) return;
    document.getElementById('ho_kas').value    = d.saldo_kas_akhir || 0;
    document.getElementById('ho_pending').value = d.order_pending || 0;
    document.getElementById('ho_siap').value    = d.order_siap_ambil || 0;
  } catch (e) {}
}

async function loadHandoverUsers() {
  try {
    const r = await fetch('absensi.php?action=list_users');
    const d = await r.json();
    if (Array.isArray(d)) {
      const sel = document.getElementById('ho_user_masuk');
      sel.innerHTML = '<option value="">— Pilih kasir penerus —</option>' +
        d.map(u => `<option value="${u.id}">${u.nama} (${u.role})</option>`).join('');
    }
  } catch (e) {}
}

async function loadHandoverPending() {
  try {
    const r = await fetch('absensi.php?action=handover_pending');
    const d = await r.json();
    const box = document.getElementById('handoverPending');
    if (!d.rows || !d.rows.length) { box.innerHTML = ''; return; }
    box.innerHTML = d.rows.map(h => `
      <div style="background:#FEF3C7;border-left:4px solid #F59E0B;padding:8px 12px;border-radius:8px;margin-bottom:6px;font-size:13px">
        ⚠️ Handover dari <strong>${h.nama_keluar || '-'}</strong> (${h.tanggal} ${h.shift})
        — Kas Rp ${parseInt(h.saldo_kas_akhir).toLocaleString('id-ID')},
        ${h.order_pending} pending, ${h.order_siap_ambil} siap ambil.
        <button class="hl-btn hl-btn-sm" style="margin-left:6px" onclick="ackHandover(${h.id})">✓ Acknowledge</button>
        ${h.catatan_khusus ? `<div style="margin-top:4px;color:#92400E"><em>Catatan: ${h.catatan_khusus}</em></div>` : ''}
      </div>`).join('');
  } catch (e) {}
}

async function ackHandover(id) {
  try {
    const r = await fetch('absensi.php?action=handover_ack', {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({id})
    });
    const d = await r.json();
    if (d.error) { showToast(d.error, 'error'); return; }
    showToast('✓ Handover di-acknowledge');
    loadHandoverPending();
  } catch (e) { showToast('Network error','error'); }
}

async function submitHandover() {
  const body = {
    tanggal: document.getElementById('ho_tanggal').value,
    shift:   document.getElementById('ho_shift').value,
    user_id_masuk:    document.getElementById('ho_user_masuk').value || null,
    saldo_kas_akhir:  parseInt(document.getElementById('ho_kas').value)||0,
    order_pending:    parseInt(document.getElementById('ho_pending').value)||0,
    order_siap_ambil: parseInt(document.getElementById('ho_siap').value)||0,
    kondisi_mesin:    document.getElementById('ho_mesin').value,
    catatan_khusus:   document.getElementById('ho_catatan').value,
  };
  try {
    const r = await fetch('absensi.php?action=handover_save', {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify(body)
    });
    const d = await r.json();
    if (d.error) { showToast(d.error, 'error'); return; }
    showToast('✓ Handover tersimpan');
    document.getElementById('ho_mesin').value = '';
    document.getElementById('ho_catatan').value = '';
    loadHandoverPending();
  } catch (e) { showToast('Network error','error'); }
}

// ── STATUS HARI INI ───────────────────────────────────
async function loadStatusHariIni() {
  const r = await fetch('absensi.php?action=status_hari_ini');
  const d = await r.json();
  updateClockUI(d);
}

function updateClockUI(d) {
  const statusEl = document.getElementById('clockStatus');
  const inBtn    = document.getElementById('btnClockIn');
  const outBtn   = document.getElementById('btnClockOut');
  const jamInfo  = document.getElementById('jamInfo');

  if (!d || d.status === 'belum') {
    statusEl.className = 'clock-status belum';
    statusEl.textContent = '⏳ Belum Clock In';
    inBtn.disabled  = false;
    outBtn.disabled = true;
    jamInfo.style.display = 'none';
  } else if (d.jam_masuk && !d.jam_keluar) {
    statusEl.className = 'clock-status masuk';
    statusEl.textContent = '✅ Sedang Bekerja';
    inBtn.disabled  = true;
    outBtn.disabled = false;
    jamInfo.style.display = 'flex';
    document.getElementById('jamMasuk').textContent  = d.jam_masuk.substring(0,5);
    document.getElementById('jamKeluar').textContent = '-';
    document.getElementById('durasi').textContent    = '-';
  } else if (d.jam_keluar) {
    statusEl.className = 'clock-status keluar';
    statusEl.textContent = '🏁 Selesai Bekerja';
    inBtn.disabled  = true;
    outBtn.disabled = true;
    jamInfo.style.display = 'flex';
    document.getElementById('jamMasuk').textContent  = d.jam_masuk.substring(0,5);
    document.getElementById('jamKeluar').textContent = d.jam_keluar.substring(0,5);
    const dur = parseInt(d.durasi_menit||0);
    document.getElementById('durasi').textContent = Math.floor(dur/60) + 'j ' + (dur%60) + 'm';
  } else if (['izin','sakit','alpha'].includes(d.status)) {
    statusEl.className = 'clock-status belum';
    statusEl.textContent = {izin:'📋 Izin',sakit:'🤒 Sakit',alpha:'❌ Alpha'}[d.status];
    inBtn.disabled  = true;
    outBtn.disabled = true;
  }
}

// ── CLOCK IN/OUT ──────────────────────────────────────
async function clockIn() {
  const btn = document.getElementById('btnClockIn');
  btn.disabled = true; btn.textContent = '⏳...';

  const r = await fetch('absensi.php?action=clock_in', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({})
  });
  const d = await r.json();
  if (d.success) {
    showToast('✅ Clock In berhasil! Jam ' + d.jam, 'success');
    loadStatusHariIni();
    loadKalender();
  } else {
    showToast('❌ ' + (d.error||'Gagal'), 'error');
    btn.disabled = false;
  }
  btn.textContent = '▶ Clock In';
}

async function clockOut() {
  if (!confirm('Yakin clock out sekarang?')) return;
  const btn = document.getElementById('btnClockOut');
  btn.disabled = true; btn.textContent = '⏳...';

  const r = await fetch('absensi.php?action=clock_out', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({})
  });
  const d = await r.json();
  if (d.success) {
    showToast('✅ Clock Out! Durasi kerja: ' + d.durasi, 'success');
    loadStatusHariIni();
    loadKalender();
  } else {
    showToast('❌ ' + (d.error||'Gagal'), 'error');
    btn.disabled = false;
  }
  btn.textContent = '■ Clock Out';
}

// ── KALENDER ──────────────────────────────────────────
async function loadKalender() {
  const bulan = document.getElementById('calBulan').value;
  if (!bulan) return;

  const r = await fetch('absensi.php?action=rekap_personal&bulan=' + bulan);
  const d = await r.json();

  document.getElementById('cHadir').textContent = d.summary.hadir;
  document.getElementById('cIzin').textContent  = d.summary.izin;
  document.getElementById('cSakit').textContent = d.summary.sakit;
  document.getElementById('cAlpha').textContent = d.summary.alpha;

  const [y,m] = bulan.split('-').map(Number);
  const firstDay = new Date(y, m-1, 1).getDay();
  const daysInMonth = new Date(y, m, 0).getDate();
  const today = localDateStr();

  const statusMap = {};
  d.data.forEach(row => { statusMap[row.tanggal] = row.status; });

  const cal = document.getElementById('calGrid');
  while (cal.children.length > 7) cal.removeChild(cal.lastChild);

  for (let i = 0; i < firstDay; i++) {
    const empty = document.createElement('div');
    empty.className = 'cal-day empty';
    cal.appendChild(empty);
  }

  for (let day = 1; day <= daysInMonth; day++) {
    const dateStr = `${y}-${String(m).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
    const status  = statusMap[dateStr];
    const isToday = dateStr === today;
    const isSun   = new Date(dateStr).getDay() === 0;

    const el = document.createElement('div');
    el.className = 'cal-day ' + (status || (isSun ? 'libur' : '')) + (isToday ? ' today' : '');
    el.innerHTML = `<span>${day}</span>${status ? '<div class="cal-dot"></div>' : ''}`;
    el.title     = status ? statusLabel(status) : dateStr;
    cal.appendChild(el);
  }
}

// ── IZIN ──────────────────────────────────────────────
function setTipeIzin(tipe, el) {
  document.getElementById('f_tipe_izin').value = tipe;
  document.querySelectorAll('.tipe-izin-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
}

async function submitIzin() {
  const payload = {
    tipe:   document.getElementById('f_tipe_izin').value,
    dari:   document.getElementById('f_izin_dari').value,
    sampai: document.getElementById('f_izin_sampai').value,
    alasan: document.getElementById('f_alasan').value,
  };
  if (!payload.dari || !payload.sampai) { showToast('⚠️ Tanggal wajib diisi','error'); return; }
  if (!payload.alasan.trim()) { showToast('⚠️ Alasan wajib diisi','error'); return; }

  const r = await fetch('absensi.php?action=input_izin', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify(payload)
  });
  const d = await r.json();
  if (d.success) {
    showToast('✅ Pengajuan berhasil dikirim!', 'success');
    document.getElementById('f_alasan').value = '';
    loadKalender();
    loadIzinList();
  } else {
    showToast('❌ ' + (d.error||'Gagal'), 'error');
  }
}

// ── REKAP ALL (ADMIN) ─────────────────────────────────
async function loadRekapAll() {
  if (!IS_ADMIN) return;
  const bulan = document.getElementById('rekapBulan').value;
  document.getElementById('rekapBody').innerHTML = '<tr><td colspan="9" class="hl-loading">⏳ Memuat...</td></tr>';

  const r = await fetch('absensi.php?action=rekap_all&bulan=' + bulan);
  const d = await r.json();

  if (!d.data?.length) {
    document.getElementById('rekapBody').innerHTML = '<tr><td colspan="9" class="hl-empty">Belum ada data</td></tr>';
    return;
  }

  const maxMenit = Math.max(...d.data.map(x => parseInt(x.total_menit)||0), 1);

  document.getElementById('rekapBody').innerHTML = d.data.map(row => {
    const menit   = parseInt(row.total_menit)||0;
    const jam     = Math.floor(menit/60);
    const hadir   = parseInt(row.hadir)||0;
    const rataMin = hadir > 0 ? Math.round(menit/hadir) : 0;
    const rataStr = hadir > 0 ? Math.floor(rataMin/60) + 'j ' + (rataMin%60) + 'm' : '-';
    const pct     = Math.round((menit/maxMenit)*100);
    return `<tr>
      <td data-lbl="Nama" style="font-weight:600;color:var(--navy)">${esc(row.nama)}</td>
      <td data-lbl="Role"><span class="hl-badge hl-badge-gray" style="font-size:10px">${row.role}</span></td>
      <td data-lbl="Hadir" style="text-align:center"><span style="font-weight:700;color:var(--green)">${row.hadir}</span></td>
      <td data-lbl="Izin" style="text-align:center"><span style="font-weight:700;color:var(--yellow)">${row.izin}</span></td>
      <td data-lbl="Sakit" style="text-align:center"><span style="font-weight:700;color:var(--blue)">${row.sakit}</span></td>
      <td data-lbl="Alpha" style="text-align:center"><span style="font-weight:700;color:var(--red)">${row.alpha}</span></td>
      <td data-lbl="Total Jam">
        <div style="font-family:var(--mono);font-size:13px;font-weight:600">${jam}j ${menit%60}m</div>
        <div class="durasi-bar"><div class="durasi-fill" style="width:${pct}%"></div></div>
      </td>
      <td data-lbl="Rata/hari" style="font-size:13px;color:var(--gray)">${rataStr}</td>
      <td data-lbl="Terakhir" style="font-size:12px;color:var(--gray)">${row.last_absen ? fmtDate(row.last_absen) : '-'}</td>
    </tr>`;
  }).join('');
  document.getElementById('rekapInfo').textContent = d.data.length + ' karyawan · ' + d.periode.bulan;
}

// ── IZIN LIST ─────────────────────────────────────────
async function loadIzinList() {
  const r = await fetch('absensi.php?action=list_izin');
  const d = await r.json();
  const el = document.getElementById('izinBody');
  if (!el) return;

  if (!d.length) {
    el.innerHTML = '<tr><td colspan="7" class="hl-empty">Belum ada pengajuan izin</td></tr>';
    return;
  }

  const tipeBadge = {izin:'📋 Izin',sakit:'🤒 Sakit',cuti:'🏖️ Cuti'};
  const statusBadge = {
    pending:'<span class="hl-badge" style="background:#FEF3C7;color:#92400E">⏳ Pending</span>',
    approved:'<span class="hl-badge hl-badge-green">✅ Approved</span>',
    rejected:'<span class="hl-badge hl-badge-red">❌ Ditolak</span>',
  };

  el.innerHTML = d.map(row => `<tr>
    <td data-lbl="Nama" style="font-weight:600">${esc(row.nama)}</td>
    <td data-lbl="Tipe"><span class="hl-badge hl-badge-gray">${tipeBadge[row.tipe]||row.tipe}</span></td>
    <td data-lbl="Dari" style="font-size:13px">${fmtDate(row.dari_tanggal)}</td>
    <td data-lbl="Sampai" style="font-size:13px">${fmtDate(row.sampai_tanggal)}</td>
    <td data-lbl="Alasan" style="font-size:13px;max-width:180px;color:var(--gray)">${esc(row.alasan||'-')}</td>
    <td data-lbl="Status">${statusBadge[row.status]||row.status}</td>
    <td>
      ${IS_ADMIN && row.status==='pending' ? `
        <div style="display:flex;gap:4px">
          <button class="hl-btn hl-btn-green hl-btn-sm" onclick="approveIzin(${row.id},'approved')">✅ Approve</button>
          <button class="hl-btn hl-btn-danger hl-btn-sm" onclick="approveIzin(${row.id},'rejected')">❌ Tolak</button>
        </div>` : '-'}
    </td>
  </tr>`).join('');
}

async function approveIzin(id, status) {
  const r = await fetch('absensi.php?action=approve_izin', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id, status})
  });
  const d = await r.json();
  if (d.success) {
    showToast(status==='approved' ? '✅ Izin disetujui' : '❌ Izin ditolak', 'success');
    loadIzinList();
  }
}

// ── TABS ──────────────────────────────────────────────
function switchTab(name, el) {
  document.getElementById('tabRekap').style.display = name==='rekap' ? 'block' : 'none';
  document.getElementById('tabIzin').style.display  = name==='izin'  ? 'block' : 'none';
  document.querySelectorAll('.hl-tab').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  if (name==='rekap') loadRekapAll();
  if (name==='izin')  loadIzinList();
}

function statusLabel(s){return{hadir:'✅ Hadir',izin:'📋 Izin',sakit:'🤒 Sakit',alpha:'❌ Alpha'}[s]||s}
function fmtDate(d){if(!d)return'-';return new Date(d+'T00:00:00').toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function showToast(msg,type='success'){const t=document.getElementById('toast');t.textContent=msg;t.className='hl-toast '+type+' show';setTimeout(()=>t.className='hl-toast',3500)}
</script>
</body>
</html>
