<?php
// ══════════════════════════════════════════════════════
// hq/promo.php — Promo Lintas Outlet (HQ View)
// Brief HQ-Outlet Section 4.4
//
// Konsep scope:
//   - scope='outlet': promo lama, hanya berlaku 1 outlet (dibuat di outlet view)
//   - scope='account': dibuat di HQ, di-assign ke outlet via hl_promo_outlets
//     * Tidak ada row di hl_promo_outlets → fallback: berlaku semua outlet
//     * Ada row dengan outlet_id=0 → berlaku semua outlet
//     * Ada row outlet_id spesifik → berlaku hanya outlet itu
// ══════════════════════════════════════════════════════

$activePage = 'hq-promo';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];
$uid  = (int)($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? '';

// ── AJAX actions ──────────────────────────────────────
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'list') {
        $q = trim($_GET['q'] ?? '');
        $params = [$tid];
        $whereExtra = '';
        if ($q !== '') {
            $whereExtra = " AND (p.nama LIKE ? OR p.deskripsi LIKE ?)";
            $like = "%$q%";
            $params[] = $like; $params[] = $like;
        }

        try {
            // Defensive: cek target_mode kolom
            $hasTM = true;
            try { $db->query("SELECT target_mode FROM hl_promo LIMIT 1"); } catch (Throwable) { $hasTM = false; }
            $tmCol = $hasTM ? "p.target_mode," : "'all' AS target_mode,";
            $stmt = $db->prepare(
                "SELECT p.id, p.nama, p.deskripsi, p.tipe, p.nilai,
                        p.min_transaksi, p.maks_diskon, p.kuota, p.terpakai,
                        p.berlaku_dari, p.berlaku_sampai, p.is_active,
                        p.scope, $tmCol p.outlet_id AS source_outlet_id,
                        p.created_at,
                        (SELECT nama_outlet FROM outlets WHERE id=p.outlet_id) AS source_outlet_name
                   FROM hl_promo p
                  WHERE p.tenant_id=? $whereExtra
                  ORDER BY p.is_active DESC, p.created_at DESC"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('[hq promo list] '.$e->getMessage());
            echo json_encode(['error'=>$e->getMessage()]); exit;
        }

        // Untuk account scope, ambil outlet assignment
        foreach ($rows as &$r) {
            $r['target_outlets'] = [];
            $r['target_all']     = false;
            $r['exclude_outlets'] = [];
            if ($r['scope'] === 'account') {
                try {
                    $a = $db->prepare(
                        "SELECT po.outlet_id, o.nama_outlet
                           FROM hl_promo_outlets po
                           LEFT JOIN outlets o ON o.id = po.outlet_id
                          WHERE po.tenant_id=? AND po.promo_id=?"
                    );
                    $a->execute([$tid, $r['id']]);
                    $assigns = $a->fetchAll();
                    $mode = $r['target_mode'] ?? 'all';

                    if ($mode === 'all' || empty($assigns) || ($assigns[0]['outlet_id'] ?? null) == 0) {
                        $r['target_all'] = true;
                        $r['target_mode'] = 'all';
                    } elseif ($mode === 'exclude') {
                        $r['exclude_outlets'] = array_filter($assigns, fn($x) => (int)$x['outlet_id'] !== 0);
                    } else {
                        $r['target_outlets'] = array_filter($assigns, fn($x) => (int)$x['outlet_id'] !== 0);
                        $r['target_mode'] = 'include';
                    }
                } catch (Throwable) {}
            }
        }
        unset($r);
        echo json_encode($rows); exit;
    }

    if ($action === 'detail') {
        $pid = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM hl_promo WHERE id=? AND tenant_id=? LIMIT 1");
        $stmt->execute([$pid, $tid]);
        $p = $stmt->fetch();
        if (!$p) { echo json_encode(['error'=>'Promo tidak ditemukan']); exit; }

        $assigned = [];
        try {
            $a = $db->prepare("SELECT outlet_id FROM hl_promo_outlets WHERE tenant_id=? AND promo_id=?");
            $a->execute([$tid, $pid]);
            $assigned = array_map('intval', $a->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {}

        $allOutlets = $db->prepare(
            "SELECT id, nama_outlet, status FROM outlets
              WHERE tenant_id=? AND status IN ('trial','grace','active')
              ORDER BY is_main DESC, nama_outlet ASC"
        );
        $allOutlets->execute([$tid]);

        // Pastikan field target_mode ada di response (default 'all')
        if (!isset($p['target_mode'])) $p['target_mode'] = 'all';
        echo json_encode([
            'promo'       => $p,
            'assigned'    => $assigned,
            'all_outlets' => $allOutlets->fetchAll(),
        ]);
        exit;
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        verifyCsrf();

        $id    = (int)($d['id'] ?? 0);
        $nama  = substr(trim(strip_tags($d['nama'] ?? '')), 0, 100);
        $desk  = substr(trim($d['deskripsi'] ?? ''), 0, 500);
        $tipe  = in_array($d['tipe'] ?? '', ['persen','nominal','free_item'], true) ? $d['tipe'] : 'persen';
        $nilai = floatval($d['nilai'] ?? 0);
        $minTx = floatval($d['min_transaksi'] ?? 0);
        $maks  = floatval($d['maks_diskon'] ?? 0);
        $dari  = $d['berlaku_dari']   ?: null;
        $sampai= $d['berlaku_sampai'] ?: null;
        $kuota = intval($d['kuota'] ?? 0);
        $active= (int)(!empty($d['is_active']) ? 1 : 0);

        // Target mode: 'all' | 'include' | 'exclude'
        $targetMode    = in_array($d['target_mode'] ?? '', ['all','include','exclude'], true) ? $d['target_mode'] : 'all';
        $targetOutlets = array_map('intval', $d['target_outlets'] ?? []);

        if (!$nama)  { echo json_encode(['error'=>'Nama promo wajib diisi']); exit; }
        if ($nilai <= 0) { echo json_encode(['error'=>'Nilai promo harus > 0']); exit; }
        if ($targetMode === 'include' && empty($targetOutlets)) {
            echo json_encode(['error'=>'Pilih minimal 1 outlet target']); exit;
        }
        if ($targetMode === 'exclude' && empty($targetOutlets)) {
            echo json_encode(['error'=>'Pilih minimal 1 outlet yang dikecualikan']); exit;
        }

        // Validasi outlet ownership
        if (!empty($targetOutlets)) {
            $ph = implode(',', array_fill(0, count($targetOutlets), '?'));
            $vO = $db->prepare("SELECT COUNT(*) FROM outlets
                                 WHERE tenant_id=? AND id IN ($ph)");
            $vO->execute(array_merge([$tid], $targetOutlets));
            if ((int)$vO->fetchColumn() !== count($targetOutlets)) {
                echo json_encode(['error'=>'Outlet target invalid']); exit;
            }
        }

        $db->beginTransaction();
        // Cek kolom target_mode exist (graceful kalau migration belum)
        $hasTargetModeCol = true;
        try { $db->query("SELECT target_mode FROM hl_promo LIMIT 1"); }
        catch (Throwable) { $hasTargetModeCol = false; }

        try {
            if ($id) {
                $vP = $db->prepare("SELECT scope FROM hl_promo WHERE id=? AND tenant_id=?");
                $vP->execute([$id, $tid]);
                $exScope = $vP->fetchColumn();
                if (!$exScope) { throw new Exception('Promo tidak ditemukan'); }
                if ($exScope !== 'account') { throw new Exception('Hanya promo HQ yang bisa diedit dari sini'); }

                $cols = ['nama=?', 'deskripsi=?', 'tipe=?', 'nilai=?', 'min_transaksi=?', 'maks_diskon=?',
                         'berlaku_dari=?', 'berlaku_sampai=?', 'kuota=?', 'is_active=?'];
                $args = [$nama, $desk, $tipe, $nilai, $minTx, $maks, $dari, $sampai, $kuota, $active];
                if ($hasTargetModeCol) { $cols[] = 'target_mode=?'; $args[] = $targetMode; }
                $args[] = $id; $args[] = $tid;
                $db->prepare("UPDATE hl_promo SET " . implode(',', $cols) .
                             " WHERE id=? AND tenant_id=? AND scope='account'")->execute($args);

                $db->prepare("DELETE FROM hl_promo_outlets WHERE tenant_id=? AND promo_id=?")
                   ->execute([$tid, $id]);

                $promoId = $id;
            } else {
                if ($hasTargetModeCol) {
                    $db->prepare(
                        "INSERT INTO hl_promo
                           (tenant_id, outlet_id, scope, target_mode, nama, deskripsi, tipe, nilai,
                            min_transaksi, maks_diskon, berlaku_dari, berlaku_sampai,
                            kuota, terpakai, is_active, created_at)
                         VALUES (?, 0, 'account', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW())"
                    )->execute([$tid, $targetMode, $nama, $desk, $tipe, $nilai, $minTx, $maks, $dari, $sampai, $kuota, $active]);
                } else {
                    $db->prepare(
                        "INSERT INTO hl_promo
                           (tenant_id, outlet_id, scope, nama, deskripsi, tipe, nilai,
                            min_transaksi, maks_diskon, berlaku_dari, berlaku_sampai,
                            kuota, terpakai, is_active, created_at)
                         VALUES (?, 0, 'account', ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW())"
                    )->execute([$tid, $nama, $desk, $tipe, $nilai, $minTx, $maks, $dari, $sampai, $kuota, $active]);
                }
                $promoId = (int)$db->lastInsertId();
            }

            // Insert outlet pivot
            if ($targetMode === 'all') {
                // Sentinel outlet_id=0 untuk semua (kompat dengan logic POS lama)
                $db->prepare("INSERT INTO hl_promo_outlets (tenant_id, promo_id, outlet_id) VALUES (?,?,0)")
                   ->execute([$tid, $promoId]);
            } else {
                // include: outlets diizinkan; exclude: outlets dikecualikan
                $ins = $db->prepare("INSERT INTO hl_promo_outlets (tenant_id, promo_id, outlet_id) VALUES (?,?,?)");
                foreach ($targetOutlets as $oid) {
                    $ins->execute([$tid, $promoId, $oid]);
                }
            }

            $db->commit();
            echo json_encode(['success'=>true, 'id'=>$promoId]);
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[hq promo save] '.$e->getMessage());
            echo json_encode(['error'=>'Gagal simpan: '.$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        verifyCsrf();
        $id = (int)($d['id'] ?? 0);
        if (!$id) { echo json_encode(['error'=>'ID invalid']); exit; }

        try {
            // Soft delete: set is_active=0 (jaga history)
            $r = $db->prepare("UPDATE hl_promo SET is_active=0
                                 WHERE id=? AND tenant_id=? AND scope='account'");
            $r->execute([$id, $tid]);
            if ($r->rowCount() === 0) {
                echo json_encode(['error'=>'Promo tidak ditemukan atau bukan promo HQ']);
                exit;
            }
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            error_log('[hq promo delete] '.$e->getMessage());
            echo json_encode(['error'=>'Gagal hapus: '.$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'voucher_list') {
        $promoId = (int)($_GET['promo_id'] ?? 0);
        try {
            $sql = "SELECT v.id, v.kode, v.is_used, v.used_at, v.nama_penerima, v.telepon,
                           v.expired_at, v.created_at, v.promo_id,
                           (SELECT nama FROM hl_promo WHERE id=v.promo_id) AS promo_nama
                      FROM hl_voucher v
                     WHERE v.tenant_id=?";
            $params = [$tid];
            if ($promoId > 0) { $sql .= " AND v.promo_id=?"; $params[] = $promoId; }
            $sql .= " ORDER BY v.created_at DESC LIMIT 200";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            echo json_encode($stmt->fetchAll());
        } catch (Throwable $e) {
            echo json_encode(['error'=>$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'voucher_generate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        verifyCsrf();
        $promoId = (int)($d['promo_id'] ?? 0);
        $jumlah  = max(1, min(100, (int)($d['jumlah'] ?? 1)));
        $prefix  = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($d['prefix'] ?? 'HQ')), 0, 6));
        $expired = !empty($d['expired_at']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['expired_at']) ? $d['expired_at'] : null;
        $nama    = substr(trim(strip_tags($d['nama_penerima'] ?? '')), 0, 100) ?: null;
        $telp    = substr(preg_replace('/[^0-9+\-\s]/', '', $d['telepon'] ?? ''), 0, 20) ?: null;

        $v = $db->prepare("SELECT id FROM hl_promo WHERE id=? AND tenant_id=? LIMIT 1");
        $v->execute([$promoId, $tid]);
        if (!$v->fetchColumn()) { echo json_encode(['error'=>'Promo tidak ditemukan']); exit; }

        $generated = [];
        try {
            $chk = $db->prepare("SELECT id FROM hl_voucher WHERE kode=? LIMIT 1");
            $ins = $db->prepare("INSERT INTO hl_voucher
                                   (tenant_id, outlet_id, promo_id, kode, is_used, nama_penerima, telepon, expired_at, created_at)
                                 VALUES (?,0,?,?,0,?,?,?,NOW())");
            for ($i = 0; $i < $jumlah; $i++) {
                $kode = null;
                for ($try = 0; $try < 5; $try++) {
                    $cand = $prefix . '-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 6));
                    $chk->execute([$cand]);
                    if (!$chk->fetchColumn()) { $kode = $cand; break; }
                }
                if (!$kode) continue;
                $ins->execute([$tid, $promoId, $kode, $nama, $telp, $expired]);
                $generated[] = $kode;
            }
            echo json_encode(['success'=>true, 'generated'=>$generated]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>'Gagal: '.$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error'=>'Unknown action']); exit;
}

$ownerNama  = $hqUser['nama'] ?? 'Owner';
$tenantNama = $hqTenant['nama_outlet'] ?? 'HQ';
$csrf       = getCsrfToken();

// Cek apakah tabel hl_promo_outlets sudah ada
$migrationOk = true;
try { $db->query("SELECT 1 FROM hl_promo_outlets LIMIT 1"); }
catch (Throwable) { $migrationOk = false; }
?>
<?php
$pageTitle  = 'Promo & Voucher';
$activePage = 'hq-promo';
require __DIR__ . '/_layout_open.php';
?>
<style>
  .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:12px}
  h1{font-size:1.4rem;font-weight:800;color:#0F1C3A}
  h1 small{display:block;font-size:13px;font-weight:400;color:#6B7280;margin-top:2px}

  .toolbar{background:#fff;border-radius:12px;padding:14px 18px;display:flex;gap:10px;align-items:center;
           flex-wrap:wrap;margin-bottom:16px;box-shadow:0 1px 6px rgba(0,0,0,.05)}
  .toolbar input{flex:1;min-width:200px;padding:9px 14px;border:1.5px solid #E5E7EB;border-radius:8px;font-size:14px;outline:none}
  .toolbar input:focus{border-color:#35E8D5}

  .pm-grid{display:grid;grid-template-columns:1fr;gap:10px}
  .pm-card{background:#fff;border-radius:12px;padding:16px 18px;display:grid;
           grid-template-columns:1fr 2fr 1fr auto;gap:16px;align-items:center;
           box-shadow:0 1px 6px rgba(0,0,0,.05);transition:box-shadow .2s}
  .pm-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08)}
  .pm-card.inactive{opacity:.6}
  .pm-name{font-weight:700;color:#0F1C3A;font-size:14px}
  .pm-name small{display:block;color:#6B7280;font-weight:400;font-size:12px;margin-top:2px}
  .pm-scope{font-size:9px;font-weight:800;padding:2px 8px;border-radius:100px;text-transform:uppercase;
            display:inline-block;margin-top:5px;letter-spacing:.05em}
  .scope-account{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff}
  .scope-outlet{background:#DBEAFE;color:#1E40AF}
  .pm-nilai{font-family:monospace;font-weight:700;color:#0F1C3A;font-size:15px}
  .pm-nilai small{display:block;color:#9CA3AF;font-weight:400;font-size:10px;text-transform:uppercase}
  .pm-target{font-size:11px;color:#6B7280}
  .pm-target strong{color:#0F1C3A;display:block;font-size:12px;margin-bottom:3px}
  .target-tag{background:#F0FDFB;color:#0891B2;font-size:10px;font-weight:600;
              padding:2px 7px;border-radius:4px;margin-right:3px;display:inline-block;margin-bottom:2px}
  .target-all{background:#FEF3C7;color:#92400E;font-weight:700}
  .pm-actions{display:flex;gap:5px}

  .btn{padding:6px 11px;border-radius:7px;font-weight:700;font-size:12px;border:none;cursor:pointer;
       font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:4px}
  .btn-primary{background:#35E8D5;color:#0F1C3A}
  .btn-light{background:#F3F4F6;color:#0F1C3A}
  .btn-light:hover{background:#E5E7EB}
  .btn-danger{background:#FEE2E2;color:#991B1B}
  .btn-danger:hover{background:#FECACA}
  .btn-big{padding:11px 20px;font-size:14px}

  .empty{text-align:center;padding:48px 20px;color:#9CA3AF;background:#fff;border-radius:12px}
  .empty .ico{font-size:48px;margin-bottom:10px}

  .modal-backdrop{position:fixed;inset:0;background:rgba(15,28,58,.75);z-index:999;display:none;
                  align-items:center;justify-content:center;padding:20px}
  .modal-backdrop.open{display:flex}
  .modal{background:#fff;border-radius:14px;max-width:640px;width:100%;max-height:90vh;overflow:auto;
         padding:24px 26px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
  .modal-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px}
  .modal-title{font-size:1.1rem;font-weight:800;color:#0F1C3A}
  .modal-close{background:none;border:none;font-size:24px;cursor:pointer;color:#9CA3AF;line-height:1}
  .modal-close:hover{color:#0F1C3A}

  .form-grid{display:grid;gap:12px}
  .form-grid label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:4px}
  .form-grid input,.form-grid select,.form-grid textarea{
    width:100%;padding:9px 12px;border:1.5px solid #E5E7EB;border-radius:8px;
    font-size:14px;outline:none;font-family:inherit;box-sizing:border-box
  }
  .form-grid input:focus,.form-grid select:focus,.form-grid textarea:focus{border-color:#35E8D5}

  .target-radio{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px}
  .target-radio label{display:flex;align-items:center;gap:8px;padding:11px 14px;border:1.5px solid #E5E7EB;
                      border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;color:#374151;
                      transition:all .2s}
  .target-radio label:has(input:checked){border-color:#35E8D5;background:#F0FDFB;color:#0F1C3A}
  .target-radio input{width:auto;margin:0}

  .alert{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:12px}
  .alert.error{background:#FEE2E2;color:#991B1B;border:1px solid #FECACA}
  .alert.success{background:#D1FAE5;color:#065F46;border:1px solid #6EE7B7}
  .alert.warn{background:#FEF3C7;color:#92400E;border:1px solid #FDE68A}

  @media(max-width:780px){
    .pm-card{grid-template-columns:1fr;gap:6px}
  }
</style>

  <?php if (!$migrationOk): ?>
  <div class="alert warn" style="margin-bottom:14px">
    ⚠️ <strong>Migration SQL belum dijalankan.</strong>
    Jalankan SQL <code>CREATE TABLE hl_promo_outlets</code> di phpMyAdmin sebelum pakai fitur promo HQ.
  </div>
  <?php endif; ?>

  <div class="header">
    <h1>🎟️ Promo Lintas Outlet
      <small>Buat & kelola promo dari HQ · <?= htmlspecialchars($tenantNama) ?></small>
    </h1>
    <button class="btn btn-primary btn-big" onclick="openCreate()">+ Buat Promo HQ</button>
  </div>

  <div class="toolbar">
    <input type="search" id="searchInput" placeholder="🔍 Cari nama atau deskripsi promo…" oninput="loadList()">
    <span id="totalCount" style="font-size:12px;color:#6B7280;font-weight:600"></span>
  </div>

  <div class="pm-grid" id="promoGrid">
    <div class="empty"><div class="ico">⏳</div><p>Memuat…</p></div>
  </div>

<!-- Create/Edit Modal -->
<!-- Voucher Modal -->
<div class="modal-backdrop" id="voucherModal" onclick="if(event.target===this)closeModal('voucherModal')">
  <div class="modal" style="max-width:680px">
    <div class="modal-header">
      <div>
        <div class="modal-title">🎫 Voucher untuk: <span id="vcPromoNama"></span></div>
        <div style="font-size:12px;color:#6B7280;margin-top:3px">Generate kode voucher untuk dibagi ke pelanggan</div>
      </div>
      <button class="modal-close" onclick="closeModal('voucherModal')">×</button>
    </div>
    <input type="hidden" id="vcPromoId">
    <div id="vcAlert"></div>

    <div style="background:#F9FAFB;border:1.5px solid #E5E7EB;border-radius:8px;padding:14px;margin-bottom:14px">
      <div style="font-size:11px;font-weight:800;color:#6B7280;text-transform:uppercase;margin-bottom:8px">Generate Voucher Baru</div>
      <div style="display:grid;grid-template-columns:1fr 1fr 2fr;gap:10px;margin-bottom:8px">
        <div>
          <label style="font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;display:block">Jumlah</label>
          <input type="number" id="vcJumlah" value="1" min="1" max="100"
                 style="width:100%;padding:8px 10px;border:1.5px solid #E5E7EB;border-radius:6px;font-size:13px;font-family:inherit;outline:none">
        </div>
        <div>
          <label style="font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;display:block">Prefix</label>
          <input type="text" id="vcPrefix" value="HQ" maxlength="6" placeholder="cth: VIP"
                 style="width:100%;padding:8px 10px;border:1.5px solid #E5E7EB;border-radius:6px;font-size:13px;font-family:inherit;outline:none;text-transform:uppercase">
        </div>
        <div>
          <label style="font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;display:block">Expired</label>
          <input type="date" id="vcExpired"
                 style="width:100%;padding:8px 10px;border:1.5px solid #E5E7EB;border-radius:6px;font-size:13px;font-family:inherit;outline:none">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
        <div>
          <label style="font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;display:block">Nama Penerima <small style="font-weight:400;color:#9CA3AF">(opsional)</small></label>
          <input type="text" id="vcNama" maxlength="100" placeholder="kosongkan kalau bebas"
                 style="width:100%;padding:8px 10px;border:1.5px solid #E5E7EB;border-radius:6px;font-size:13px;font-family:inherit;outline:none">
        </div>
        <div>
          <label style="font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;display:block">Telepon <small style="font-weight:400;color:#9CA3AF">(opsional)</small></label>
          <input type="tel" id="vcTelp" maxlength="20" placeholder="08xxx"
                 style="width:100%;padding:8px 10px;border:1.5px solid #E5E7EB;border-radius:6px;font-size:13px;font-family:inherit;outline:none">
        </div>
      </div>
      <button class="btn btn-primary" style="padding:9px 16px;font-size:13px" onclick="generateVoucher()">
        ✨ Generate Voucher
      </button>
    </div>

    <div style="font-size:11px;font-weight:800;color:#6B7280;text-transform:uppercase;margin-bottom:8px">
      📜 Voucher Terdaftar (200 terakhir)
    </div>
    <div id="vcList" style="max-height:300px;overflow-y:auto;font-size:12px">
      <div style="text-align:center;color:#9CA3AF;padding:14px">Memuat…</div>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="formModal" onclick="if(event.target===this)closeModal('formModal')">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="formTitle">+ Buat Promo HQ</div>
      <button class="modal-close" onclick="closeModal('formModal')">×</button>
    </div>
    <div id="formAlert"></div>
    <div class="form-grid">
      <input type="hidden" id="fId">
      <div>
        <label>Nama Promo <span style="color:#EF4444">*</span></label>
        <input type="text" id="fNama" maxlength="100" placeholder="cth: Promo Lebaran 20%">
      </div>
      <div>
        <label>Deskripsi</label>
        <textarea id="fDesk" rows="2" maxlength="500" placeholder="Detail promo, syarat, dll"></textarea>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div>
          <label>Tipe Diskon</label>
          <select id="fTipe">
            <option value="persen">Persen (%)</option>
            <option value="nominal">Nominal (Rp)</option>
            <option value="free_item">Item Gratis</option>
          </select>
        </div>
        <div>
          <label>Nilai <span style="color:#EF4444">*</span></label>
          <input type="number" id="fNilai" min="0" step="0.01" placeholder="20">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div>
          <label>Min Transaksi (Rp)</label>
          <input type="number" id="fMin" min="0" placeholder="0">
        </div>
        <div>
          <label>Maks Diskon (Rp)</label>
          <input type="number" id="fMaks" min="0" placeholder="0 = no limit">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div><label>Berlaku Dari</label><input type="date" id="fDari"></div>
        <div><label>Berlaku Sampai</label><input type="date" id="fSampai"></div>
      </div>
      <div>
        <label>Kuota Pemakaian <span style="font-weight:400;color:#9CA3AF">(0 = unlimited)</span></label>
        <input type="number" id="fKuota" min="0" placeholder="0">
      </div>

      <div>
        <label style="margin-bottom:8px">🎯 Target Outlet</label>
        <div class="target-radio" style="grid-template-columns:1fr 1fr 1fr">
          <label>
            <input type="radio" name="targetMode" value="all" checked onchange="toggleTarget()">
            <span>🌐 Semua Outlet</span>
          </label>
          <label>
            <input type="radio" name="targetMode" value="include" onchange="toggleTarget()">
            <span>✓ Pilih Tertentu</span>
          </label>
          <label>
            <input type="radio" name="targetMode" value="exclude" onchange="toggleTarget()">
            <span>❌ Semua Kecuali</span>
          </label>
        </div>
        <div id="pickerLabel" style="display:none;font-size:11px;color:#6B7280;margin-bottom:6px">
          <span class="lbl-include">Centang outlet yang BOLEH pakai promo:</span>
          <span class="lbl-exclude" style="display:none">Centang outlet yang DIKECUALIKAN (tidak boleh pakai):</span>
        </div>
        <div id="outletPicker" style="display:none;background:#F9FAFB;border:1.5px solid #E5E7EB;
             border-radius:8px;padding:10px;max-height:160px;overflow-y:auto"></div>
      </div>

      <div>
        <label style="display:flex;align-items:center;gap:8px;font-weight:600;cursor:pointer">
          <input type="checkbox" id="fActive" checked style="width:auto;margin:0">
          Aktifkan promo
        </label>
      </div>
      <button class="btn btn-primary" style="padding:12px;font-size:14px" onclick="submitForm()">
        💾 Simpan Promo
      </button>
    </div>
  </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
let allOutletsCache = [];

function escapeHtml(s){return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function fmtRp(n){return 'Rp ' + Number(n||0).toLocaleString('id-ID')}
function fmtDate(s){if(!s)return '-';const d=new Date(s);return d.toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}

function nilaiText(p){
  if (p.tipe === 'persen') return p.nilai + '%';
  if (p.tipe === 'nominal') return fmtRp(p.nilai);
  return 'Item Gratis';
}

function periodeText(p){
  if (!p.berlaku_dari && !p.berlaku_sampai) return 'Tanpa batas waktu';
  return (p.berlaku_dari?fmtDate(p.berlaku_dari):'-') + ' → ' + (p.berlaku_sampai?fmtDate(p.berlaku_sampai):'-');
}

async function loadList(){
  const q = document.getElementById('searchInput').value;
  const r = await fetch('/ERP/harpy/hq/promo.php?action=list&q=' + encodeURIComponent(q));
  const rows = await r.json();
  if (rows.error) { document.getElementById('promoGrid').innerHTML =
    `<div class="alert error">${escapeHtml(rows.error)}</div>`; return; }

  document.getElementById('totalCount').textContent = rows.length + ' promo';
  const grid = document.getElementById('promoGrid');

  if (rows.length === 0) {
    grid.innerHTML = '<div class="empty"><div class="ico">🎟️</div><p>Belum ada promo. Klik <strong>+ Buat Promo HQ</strong> untuk mulai.</p></div>';
    return;
  }

  grid.innerHTML = rows.map(r => {
    const scopeBadge = r.scope === 'account'
      ? '<span class="pm-scope scope-account">🏢 HQ</span>'
      : '<span class="pm-scope scope-outlet">📍 OUTLET</span>';

    let target;
    if (r.scope === 'outlet') {
      target = `<strong>📍 ${escapeHtml(r.source_outlet_name || '?')}</strong><span style="font-size:11px;color:#9CA3AF">Dibuat di outlet view</span>`;
    } else if (r.target_all) {
      target = `<strong>🌐 Semua Outlet</strong><span class="target-tag target-all">SEMUA</span>`;
    } else if (r.target_mode === 'exclude' && r.exclude_outlets && r.exclude_outlets.length > 0) {
      target = `<strong>🌐 Semua kecuali ${r.exclude_outlets.length} outlet</strong>` +
        r.exclude_outlets.map(o => `<span class="target-tag" style="background:#FEE2E2;color:#991B1B">❌ ${escapeHtml(o.nama_outlet || '?')}</span>`).join('');
    } else if (r.target_outlets.length > 0) {
      target = `<strong>${r.target_outlets.length} outlet</strong>` +
        r.target_outlets.map(o => `<span class="target-tag">📍 ${escapeHtml(o.nama_outlet || '?')}</span>`).join('');
    } else {
      target = `<strong style="color:#9CA3AF">Belum ada target</strong>`;
    }

    const voucherBtn = `<button class="btn btn-light" onclick="openVoucherModal(${r.id},'${escapeHtml(r.nama)}')">🎫 Voucher</button>`;
    const editBtn = r.scope === 'account'
      ? `<button class="btn btn-light" onclick="openEdit(${r.id})">✏️ Edit</button>
         ${voucherBtn}
         <button class="btn btn-danger" onclick="deletePromo(${r.id},'${escapeHtml(r.nama)}')">🗑️</button>`
      : `${voucherBtn}<span style="font-size:11px;color:#9CA3AF;padding:6px 4px">Edit di outlet view</span>`;

    return `
      <div class="pm-card ${r.is_active==0?'inactive':''}">
        <div>
          <div class="pm-name">${escapeHtml(r.nama)}
            <small>${escapeHtml(r.deskripsi || periodeText(r))}</small>
          </div>
          ${scopeBadge}
          ${r.is_active==0 ? '<span class="pm-scope" style="background:#F3F4F6;color:#6B7280;margin-left:5px">NON-AKTIF</span>' : ''}
        </div>
        <div class="pm-target">${target}</div>
        <div class="pm-nilai">${nilaiText(r)}<small>${r.tipe}</small></div>
        <div class="pm-actions">${editBtn}</div>
      </div>
    `;
  }).join('');
}

function openCreate(){
  document.getElementById('formTitle').textContent = '+ Buat Promo HQ';
  document.getElementById('fId').value = '';
  document.getElementById('fNama').value = '';
  document.getElementById('fDesk').value = '';
  document.getElementById('fTipe').value = 'persen';
  document.getElementById('fNilai').value = '';
  document.getElementById('fMin').value = '';
  document.getElementById('fMaks').value = '';
  document.getElementById('fDari').value = '';
  document.getElementById('fSampai').value = '';
  document.getElementById('fKuota').value = '';
  document.getElementById('fActive').checked = true;
  document.querySelector('input[name="targetMode"][value="all"]').checked = true;
  document.getElementById('formAlert').innerHTML = '';
  loadOutletPicker([]);
  toggleTarget();
  openModal('formModal');
}

async function openEdit(id){
  const r = await fetch('/ERP/harpy/hq/promo.php?action=detail&id=' + id);
  const d = await r.json();
  if (d.error) { alert(d.error); return; }
  const p = d.promo;

  document.getElementById('formTitle').textContent = '✏️ Edit Promo: ' + p.nama;
  document.getElementById('fId').value = p.id;
  document.getElementById('fNama').value = p.nama;
  document.getElementById('fDesk').value = p.deskripsi || '';
  document.getElementById('fTipe').value = p.tipe;
  document.getElementById('fNilai').value = p.nilai;
  document.getElementById('fMin').value = p.min_transaksi || 0;
  document.getElementById('fMaks').value = p.maks_diskon || 0;
  document.getElementById('fDari').value = p.berlaku_dari || '';
  document.getElementById('fSampai').value = p.berlaku_sampai || '';
  document.getElementById('fKuota').value = p.kuota || 0;
  document.getElementById('fActive').checked = p.is_active == 1;

  // Set target mode dari kolom DB (kalau ada), atau infer dari assigned
  const tm = p.target_mode || 'all';
  if (d.assigned.includes(0) || d.assigned.length === 0 || tm === 'all') {
    document.querySelector('input[name="targetMode"][value="all"]').checked = true;
  } else if (tm === 'exclude') {
    document.querySelector('input[name="targetMode"][value="exclude"]').checked = true;
  } else {
    document.querySelector('input[name="targetMode"][value="include"]').checked = true;
  }

  allOutletsCache = d.all_outlets;
  loadOutletPicker(d.assigned.filter(o => o > 0));
  toggleTarget();
  document.getElementById('formAlert').innerHTML = '';
  openModal('formModal');
}

async function loadOutletPicker(checkedIds){
  if (allOutletsCache.length === 0) {
    // First load
    try {
      const r = await fetch('/ERP/harpy/hq/promo.php?action=detail&id=0');
      // ignore - this won't work
    } catch {}
  }
  if (allOutletsCache.length === 0) {
    // Fetch via list of outlets from any detail call ... simpler: do separate call
    // We'll embed in PHP next reload — for now, fallback to listing from detail of first promo
  }
  const picker = document.getElementById('outletPicker');
  if (allOutletsCache.length === 0) {
    picker.innerHTML = '<div style="color:#9CA3AF;font-size:12px">Memuat daftar outlet…</div>';
    // Force load by calling list (we'll re-trigger)
    await loadAllOutlets();
  }
  picker.innerHTML = allOutletsCache.length === 0
    ? '<div style="color:#9CA3AF;font-size:12px">Belum ada outlet aktif.</div>'
    : allOutletsCache.map(o => `
        <label style="display:flex;align-items:center;gap:8px;padding:5px 0;cursor:pointer;font-size:13px;color:#374151">
          <input type="checkbox" class="picker-cb" value="${o.id}" ${checkedIds.includes(o.id)?'checked':''} style="width:auto;margin:0">
          📍 ${escapeHtml(o.nama_outlet)}
        </label>
      `).join('');
}

async function loadAllOutlets(){
  if (allOutletsCache.length) return;
  // Hack: pakai endpoint detail untuk dapat all_outlets list
  // Atau: kita tambahkan endpoint khusus. Untuk sekarang, panggil list dan ambil dari mana saja.
  // Solusi cepat: query langsung via PHP — kita embed di bawah
}

function toggleTarget(){
  const mode = document.querySelector('input[name="targetMode"]:checked').value;
  const showPicker = mode !== 'all';
  document.getElementById('outletPicker').style.display = showPicker ? 'block' : 'none';
  document.getElementById('pickerLabel').style.display = showPicker ? 'block' : 'none';
  document.querySelector('.lbl-include').style.display = mode === 'include' ? 'inline' : 'none';
  document.querySelector('.lbl-exclude').style.display = mode === 'exclude' ? 'inline' : 'none';
}

async function submitForm(){
  const alertEl = document.getElementById('formAlert');
  alertEl.innerHTML = '';
  const targetMode = document.querySelector('input[name="targetMode"]:checked').value;
  const data = {
    id: document.getElementById('fId').value,
    nama: document.getElementById('fNama').value.trim(),
    deskripsi: document.getElementById('fDesk').value.trim(),
    tipe: document.getElementById('fTipe').value,
    nilai: parseFloat(document.getElementById('fNilai').value),
    min_transaksi: parseFloat(document.getElementById('fMin').value || 0),
    maks_diskon: parseFloat(document.getElementById('fMaks').value || 0),
    berlaku_dari: document.getElementById('fDari').value,
    berlaku_sampai: document.getElementById('fSampai').value,
    kuota: parseInt(document.getElementById('fKuota').value || 0),
    is_active: document.getElementById('fActive').checked ? 1 : 0,
    target_mode: targetMode,
    target_outlets: Array.from(document.querySelectorAll('.picker-cb:checked')).map(c => parseInt(c.value)),
  };

  if (!data.nama)     { alertEl.innerHTML = '<div class="alert error">Nama wajib diisi</div>'; return; }
  if (!(data.nilai > 0)) { alertEl.innerHTML = '<div class="alert error">Nilai harus lebih dari 0</div>'; return; }
  if (targetMode === 'include' && data.target_outlets.length === 0) {
    alertEl.innerHTML = '<div class="alert error">Pilih minimal 1 outlet target</div>'; return;
  }
  if (targetMode === 'exclude' && data.target_outlets.length === 0) {
    alertEl.innerHTML = '<div class="alert error">Pilih minimal 1 outlet yang dikecualikan</div>'; return;
  }

  const r = await fetch('/ERP/harpy/hq/promo.php?action=save', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
    body: JSON.stringify(data),
  });
  const j = await r.json();
  if (j.error) { alertEl.innerHTML = '<div class="alert error">'+escapeHtml(j.error)+'</div>'; return; }
  alertEl.innerHTML = '<div class="alert success">✓ Tersimpan</div>';
  setTimeout(() => { closeModal('formModal'); loadList(); }, 700);
}

async function deletePromo(id, nama){
  if (!confirm(`Non-aktifkan promo "${nama}"?\n(Promo akan tetap di history, tidak terhapus permanen)`)) return;
  const r = await fetch('/ERP/harpy/hq/promo.php?action=delete', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
    body: JSON.stringify({id}),
  });
  const j = await r.json();
  if (j.error) { alert(j.error); return; }
  loadList();
}

function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}

// ── Voucher modal ──────────────────────────────────
async function openVoucherModal(promoId, promoNama){
  document.getElementById('vcPromoId').value = promoId;
  document.getElementById('vcPromoNama').textContent = promoNama;
  document.getElementById('vcAlert').innerHTML = '';
  document.getElementById('vcJumlah').value = 1;
  document.getElementById('vcPrefix').value = 'HQ';
  document.getElementById('vcExpired').value = '';
  document.getElementById('vcNama').value = '';
  document.getElementById('vcTelp').value = '';
  openModal('voucherModal');
  await loadVoucherList(promoId);
}

async function loadVoucherList(promoId){
  const r = await fetch('/ERP/harpy/hq/promo.php?action=voucher_list&promo_id=' + promoId);
  const rows = await r.json();
  const box = document.getElementById('vcList');
  if (rows.error || !rows.length) {
    box.innerHTML = '<div style="text-align:center;color:#9CA3AF;padding:14px">Belum ada voucher untuk promo ini.</div>';
    return;
  }
  box.innerHTML = rows.map(v => `
    <div style="display:grid;grid-template-columns:auto 1fr auto;gap:10px;align-items:center;
                padding:9px 12px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:7px;margin-bottom:5px">
      <code style="background:#0F1C3A;color:#35E8D5;padding:5px 9px;border-radius:5px;font-size:12px;font-weight:700;cursor:copy"
            onclick="navigator.clipboard.writeText('${escapeHtml(v.kode)}');this.textContent='✓ copied'"
            title="Klik untuk copy">${escapeHtml(v.kode)}</code>
      <div style="font-size:11px;color:#6B7280">
        ${v.nama_penerima ? '👤 '+escapeHtml(v.nama_penerima) : '<span style="color:#9CA3AF">bebas</span>'}
        ${v.telepon ? ' · 📞 '+escapeHtml(v.telepon) : ''}
        ${v.expired_at ? '<br>⏰ Exp: '+new Date(v.expired_at).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}) : ''}
      </div>
      <span style="font-size:10px;font-weight:700;padding:3px 8px;border-radius:100px;
            background:${v.is_used==1?'#FEE2E2':'#D1FAE5'};color:${v.is_used==1?'#991B1B':'#065F46'}">
        ${v.is_used==1 ? '✓ TERPAKAI' : '○ AKTIF'}
      </span>
    </div>
  `).join('');
}

async function generateVoucher(){
  const alertEl = document.getElementById('vcAlert');
  alertEl.innerHTML = '';
  const promoId = document.getElementById('vcPromoId').value;
  const data = {
    promo_id: parseInt(promoId),
    jumlah:   parseInt(document.getElementById('vcJumlah').value || 1),
    prefix:   document.getElementById('vcPrefix').value.trim().toUpperCase(),
    expired_at: document.getElementById('vcExpired').value,
    nama_penerima: document.getElementById('vcNama').value.trim(),
    telepon: document.getElementById('vcTelp').value.trim(),
  };
  if (data.jumlah < 1 || data.jumlah > 100) { alertEl.innerHTML = '<div class="alert error">Jumlah 1-100</div>'; return; }

  const r = await fetch('/ERP/harpy/hq/promo.php?action=voucher_generate', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
    body: JSON.stringify(data),
  });
  const j = await r.json();
  if (j.error) { alertEl.innerHTML = '<div class="alert error">'+escapeHtml(j.error)+'</div>'; return; }
  alertEl.innerHTML = `<div class="alert success">✓ ${j.generated.length} voucher digenerate: <code>${j.generated.join(', ')}</code></div>`;
  await loadVoucherList(promoId);
}

// Pre-load semua outlet untuk picker
(async () => {
  // Trigger via detail action di promo dummy — atau lebih simple, embed di PHP
  // Embed langsung dari PHP:
  allOutletsCache = <?php
    try {
        $oStmt = $db->prepare("SELECT id, nama_outlet, status FROM outlets
                                WHERE tenant_id=? AND status IN ('trial','grace','active')
                                ORDER BY is_main DESC, nama_outlet ASC");
        $oStmt->execute([$tid]);
        echo json_encode($oStmt->fetchAll());
    } catch (Throwable) { echo '[]'; }
  ?>;
})();

loadList();
</script>
<?php require __DIR__ . '/_layout_close.php'; ?>
