<?php
// ══════════════════════════════════════════════════════
// piutang.php — Rekap Piutang B2B (pelanggan bulanan/korporat)
// Akses: owner/manager/admin
// ══════════════════════════════════════════════════════

$activePage = 'piutang';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/Notifier.php';
require_once __DIR__ . '/components.php';

$user = currentUser();
$role = $user['role'] ?? 'staff';
if (!in_array($role, ['owner','superadmin','admin','manager'], true)) {
    http_response_code(403);
    die('Akses ditolak — hanya owner/manager.');
}

$tid = TenantResolver::id();
$oid = TenantResolver::outletId();
$action = $_GET['action'] ?? '';
$db = Database::get();

// ── API: list piutang + summary ──
if ($action === 'list') {
    header('Content-Type: application/json');
    $statusF = $_GET['status'] ?? '';
    try {
        $where = "p.tenant_id=? AND p.outlet_id=?"; $params = [$tid, $oid];
        if ($statusF) { $where .= " AND p.status=?"; $params[] = $statusF; }

        $stmt = $db->prepare("
            SELECT p.*,
                   pl.nama AS pelanggan_nama, pl.telepon AS pelanggan_wa,
                   DATEDIFF(p.jatuh_tempo, CURDATE()) AS hari_tempo
              FROM hl_piutang p
              JOIN hl_pelanggan pl ON pl.id=p.pelanggan_id AND pl.tenant_id=p.tenant_id
             WHERE $where
             ORDER BY p.status!='lunas' DESC, p.jatuh_tempo ASC LIMIT 200
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Summary
        $sumStmt = $db->prepare("SELECT
              COALESCE(SUM(CASE WHEN status!='lunas' THEN sisa_tagihan ELSE 0 END),0) outstanding,
              COALESCE(SUM(CASE WHEN status!='lunas' AND jatuh_tempo BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN sisa_tagihan ELSE 0 END),0) due_week,
              COALESCE(SUM(CASE WHEN status!='lunas' AND jatuh_tempo < CURDATE() THEN sisa_tagihan ELSE 0 END),0) overdue
            FROM hl_piutang WHERE tenant_id=? AND outlet_id=?");
        $sumStmt->execute([$tid, $oid]);
        $summary = $sumStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode(['ok'=>true, 'rows'=>$rows, 'summary'=>$summary]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: list pelanggan B2B (tipe_bayar='bulanan') untuk dropdown ──
if ($action === 'list_b2b_customer') {
    header('Content-Type: application/json');
    // Defensive: cek kolom tipe_bayar
    $hasTipeBayar = true;
    try { $db->query("SELECT tipe_bayar FROM hl_pelanggan LIMIT 1"); } catch (Throwable) { $hasTipeBayar = false; }
    try {
        $where = $hasTipeBayar
            ? "tenant_id=? AND is_active=1 AND (tipe_bayar='bulanan' OR tipe IN ('vip'))"
            : "tenant_id=? AND is_active=1 AND tipe IN ('vip')";
        $s = $db->prepare("SELECT id, nama, telepon FROM hl_pelanggan
                            WHERE $where ORDER BY nama LIMIT 200");
        $s->execute([$tid]);
        echo json_encode(['ok'=>true, 'rows'=>$s->fetchAll(PDO::FETCH_ASSOC),
                          'has_tipe_bayar'=>$hasTipeBayar]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: generate tagihan dari order pelanggan B2B periode ──
if ($action === 'generate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    verifyCsrf();
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $pelId   = (int)($d['pelanggan_id'] ?? 0);
    $start   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['start'] ?? '') ? $d['start'] : null;
    $end     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['end'] ?? '')   ? $d['end']   : null;
    $tempo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['jatuh_tempo'] ?? '') ? $d['jatuh_tempo'] : null;
    if (!$pelId || !$start || !$end || !$tempo) { echo json_encode(['error'=>'Field tidak lengkap']); exit; }

    try {
        $s = $db->prepare("SELECT COUNT(*) cnt, COALESCE(SUM(total),0) total
                             FROM hl_transaksi
                            WHERE tenant_id=? AND outlet_id=? AND pelanggan_id=?
                              AND DATE(tanggal) BETWEEN ? AND ?");
        $s->execute([$tid, $oid, $pelId, $start, $end]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        $totalTagihan = (int)$r['total'];
        $totalOrder   = (int)$r['cnt'];
        if ($totalOrder === 0) { echo json_encode(['error'=>'Tidak ada order pelanggan ini di periode tsb']); exit; }

        // Sudah dibayar dari hl_transaksi (kalau ada dp/lunas)
        $sb = $db->prepare("SELECT COALESCE(SUM(dp),0) FROM hl_transaksi
                             WHERE tenant_id=? AND outlet_id=? AND pelanggan_id=?
                               AND DATE(tanggal) BETWEEN ? AND ?");
        $sb->execute([$tid,$oid,$pelId,$start,$end]);
        $totalDibayar = (int)$sb->fetchColumn();

        $stmt = $db->prepare("INSERT INTO hl_piutang
            (tenant_id, outlet_id, pelanggan_id, periode_start, periode_end, jatuh_tempo,
             total_order, total_tagihan, total_dibayar, status)
            VALUES (?,?,?,?,?,?,?,?,?, 'belum_tagih')
            ON DUPLICATE KEY UPDATE
              total_order=VALUES(total_order), total_tagihan=VALUES(total_tagihan),
              total_dibayar=VALUES(total_dibayar)");
        $stmt->execute([$tid,$oid,$pelId,$start,$end,$tempo,$totalOrder,$totalTagihan,$totalDibayar]);
        logAudit('generate','piutang',"Generate tagihan pelanggan #$pelId, $start s/d $end, Rp ".number_format($totalTagihan,0,',','.'));
        echo json_encode(['ok'=>true, 'total_tagihan'=>$totalTagihan, 'total_order'=>$totalOrder]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: tandai invoice terkirim + buka WA link invoice ──
if ($action === 'mark_invoiced' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    verifyCsrf();
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($d['id'] ?? 0);
    try {
        // Cek coin
        if (!CoinLedger::canAfford('invoice_b2b')) { echo json_encode(['error'=>'Coin tidak cukup (butuh 200)']); exit; }

        $s = $db->prepare("SELECT p.*, pl.nama, pl.telepon FROM hl_piutang p
                            JOIN hl_pelanggan pl ON pl.id=p.pelanggan_id
                           WHERE p.id=? AND p.tenant_id=? AND p.outlet_id=?");
        $s->execute([$id,$tid,$oid]); $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['error'=>'Piutang tidak ditemukan']); exit; }

        $db->prepare("UPDATE hl_piutang SET status='sudah_tagih', invoice_sent_at=NOW()
                       WHERE id=? AND tenant_id=?")->execute([$id,$tid]);

        try { CoinLedger::deduct('invoice_b2b', (string)$id); } catch (Throwable) {}

        // Log + WA link
        $waUrl = '';
        if ($row['telepon']) {
            $p = preg_replace('/[^0-9]/','',$row['telepon']);
            if (strpos($p,'0')===0) $p = '62'.substr($p,1);
            elseif (strpos($p,'62')!==0) $p = '62'.$p;
            $txt = "Halo *{$row['nama']}*,\n\nInvoice tagihan laundry periode "
                 . date('d M', strtotime($row['periode_start'])) . " – "
                 . date('d M Y', strtotime($row['periode_end'])) . ":\n"
                 . "Jumlah order: {$row['total_order']}\n"
                 . "Total tagihan: *Rp " . number_format((int)$row['total_tagihan'],0,',','.') . "*\n"
                 . "Sudah dibayar: Rp " . number_format((int)$row['total_dibayar'],0,',','.') . "\n"
                 . "Sisa tagihan: *Rp " . number_format((int)$row['sisa_tagihan'],0,',','.') . "*\n"
                 . "Jatuh tempo: " . date('d M Y', strtotime($row['jatuh_tempo'])) . "\n\n"
                 . "Terima kasih 🙏";
            $waUrl = "https://wa.me/$p?text=" . urlencode($txt);
        }

        Notifier::log($tid, $oid, 'invoice_b2b', 'inapp', $row['telepon'] ?? null,
            "Invoice piutang #$id: ".$row['nama']." Rp ".number_format((int)$row['sisa_tagihan']),
            "Invoice ".$row['nama']." periode ".$row['periode_start']." s/d ".$row['periode_end']);
        logAudit('invoice','piutang',"Kirim invoice #$id pelanggan {$row['nama']}", (string)$id);
        echo json_encode(['ok'=>true, 'wa'=>$waUrl]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: catat pembayaran ──
if ($action === 'bayar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    verifyCsrf();
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($d['id'] ?? 0);
    $jumlah = max(0, (int)($d['jumlah'] ?? 0));
    $tipe = $d['tipe'] ?? 'sebagian'; // sebagian | lunas
    if (!$id || $jumlah <= 0) { echo json_encode(['error'=>'Jumlah tidak valid']); exit; }

    try {
        $db->beginTransaction();
        $s = $db->prepare("SELECT p.*, pl.nama FROM hl_piutang p
                            JOIN hl_pelanggan pl ON pl.id=p.pelanggan_id
                           WHERE p.id=? AND p.tenant_id=? AND p.outlet_id=? FOR UPDATE");
        $s->execute([$id,$tid,$oid]); $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $db->rollBack(); echo json_encode(['error'=>'Piutang tidak ditemukan']); exit; }

        $newDibayar = (int)$row['total_dibayar'] + $jumlah;
        if ($tipe === 'lunas') $newDibayar = (int)$row['total_tagihan'];
        $status = $newDibayar >= (int)$row['total_tagihan'] ? 'lunas' : 'sebagian';
        $lunasAt = $status === 'lunas' ? date('Y-m-d H:i:s') : null;

        // Insert kas masuk
        $ket = "Pelunasan piutang ".$row['nama']." periode "
             . date('d M', strtotime($row['periode_start'])) . "–"
             . date('d M Y', strtotime($row['periode_end']));
        TenantQuery::insert('hl_kas', [
            'tanggal'    => date('Y-m-d'),
            'tipe'       => 'masuk',
            'kategori'   => 'Pembayaran Piutang',
            'keterangan' => $ket,
            'jumlah'     => $jumlah,
            'created_by' => $user['id'],
        ]);
        $kasId = (int)$db->lastInsertId();

        $db->prepare("UPDATE hl_piutang SET total_dibayar=?, status=?, lunas_at=?, kas_id=?
                       WHERE id=? AND tenant_id=?")
           ->execute([$newDibayar, $status, $lunasAt, $kasId, $id, $tid]);
        $db->commit();
        logAudit('bayar','piutang',"Bayar piutang #$id ".$row['nama']." Rp ".number_format($jumlah,0,',','.'));
        echo json_encode(['ok'=>true, 'status'=>$status]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

// ── API: kirim reminder + log + return WA link ──
if ($action === 'reminder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    verifyCsrf();
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($d['id'] ?? 0);
    try {
        if (!CoinLedger::canAfford('reminder_piutang')) { echo json_encode(['error'=>'Coin tidak cukup (butuh 100)']); exit; }
        $s = $db->prepare("SELECT p.*, pl.nama, pl.telepon FROM hl_piutang p
                            JOIN hl_pelanggan pl ON pl.id=p.pelanggan_id
                           WHERE p.id=? AND p.tenant_id=? AND p.outlet_id=? AND p.status!='lunas'");
        $s->execute([$id,$tid,$oid]); $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['error'=>'Piutang tidak ditemukan / sudah lunas']); exit; }

        $waUrl = '';
        if ($row['telepon']) {
            $p = preg_replace('/[^0-9]/','',$row['telepon']);
            if (strpos($p,'0')===0) $p = '62'.substr($p,1);
            elseif (strpos($p,'62')!==0) $p = '62'.$p;
            $hari = (int)((strtotime($row['jatuh_tempo']) - strtotime(date('Y-m-d')))/86400);
            $status = $hari < 0 ? "telah lewat ".abs($hari)." hari" : ($hari === 0 ? "jatuh tempo HARI INI" : "akan jatuh tempo dalam $hari hari");
            $txt = "Halo *{$row['nama']}*,\n\nReminder tagihan laundry periode "
                 . date('d M', strtotime($row['periode_start']))." – ".date('d M Y', strtotime($row['periode_end'])).":\n"
                 . "Sisa tagihan: *Rp " . number_format((int)$row['sisa_tagihan'],0,',','.') . "*\n"
                 . "Jatuh tempo: " . date('d M Y', strtotime($row['jatuh_tempo'])) . " ($status)\n\n"
                 . "Mohon segera diselesaikan ya 🙏";
            $waUrl = "https://wa.me/$p?text=".urlencode($txt);
        }
        try { CoinLedger::deduct('reminder_piutang', (string)$id); } catch (Throwable) {}
        Notifier::log($tid,$oid,'reminder_piutang','inapp',$row['telepon'] ?? null,
            "Reminder piutang #$id: {$row['nama']}",
            "Reminder ke {$row['nama']} sisa Rp ".number_format((int)$row['sisa_tagihan']));
        logAudit('reminder','piutang',"Reminder piutang #$id {$row['nama']}", (string)$id);
        echo json_encode(['ok'=>true, 'wa'=>$waUrl]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

require_once ROOT . '/core/CoinLedger.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Piutang B2B'); ?>
<style>
.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px}
.sum-card{background:#fff;border:1px solid #E5E9F2;border-radius:12px;padding:16px;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.sum-card .v{font-size:1.4rem;font-weight:800;color:#0F1C3A;font-family:monospace}
.sum-card .l{font-size:12px;color:#6B7280;font-weight:600;margin-top:4px}
.sum-card.warn .v{color:#F59E0B}
.sum-card.danger .v{color:#EF4444}
.tbl{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.tbl th{background:#F7F8FC;padding:9px 11px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;color:#6B7280}
.tbl td{padding:10px 11px;border-top:1px solid #F0F1F4}
.tbl .num{font-family:monospace;font-weight:700;text-align:right}
.pill{font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px;text-transform:uppercase}
.pl-belum_tagih{background:#F3F4F6;color:#6B7280}
.pl-sudah_tagih{background:#DBEAFE;color:#1E40AF}
.pl-sebagian{background:#FEF3C7;color:#92400E}
.pl-lunas{background:#D1FAE5;color:#065F46}
.tempo-warn{color:#F59E0B;font-weight:700}
.tempo-overdue{color:#EF4444;font-weight:700}
.btn-sm{padding:5px 9px;font-size:11px}
.empty{text-align:center;padding:40px;color:#9CA3AF}
.modal-bg{display:none;position:fixed;inset:0;background:rgba(15,28,58,.5);z-index:1000;align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto}
.modal-bg.open{display:flex}
.modal{background:#fff;border-radius:14px;width:100%;max-width:480px;padding:24px}
.modal h3{font-size:16px;font-weight:800;color:#0F1C3A;margin-bottom:14px}
.fld{margin-bottom:12px}
.fld label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}
.fld input,.fld select{width:100%;padding:9px 12px;border:1px solid #E5E9F2;border-radius:8px;font-family:inherit;font-size:14px}
</style>
</head>
<body>
<?php renderTopbar('piutang'); ?>

<div class="hl-main" style="max-width:1200px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px">
    <div>
      <h1 style="font-size:1.3rem;font-weight:800;color:var(--navy)">💼 Rekap Piutang B2B</h1>
      <p style="font-size:13px;color:var(--gray)">Tagihan pelanggan korporat/bulanan & status pembayarannya</p>
    </div>
    <button class="hl-btn hl-btn-primary" onclick="openGen()">+ Buat Tagihan</button>
  </div>

  <div class="summary">
    <div class="sum-card"><div class="v" id="sumOut">-</div><div class="l">💰 Outstanding Total</div></div>
    <div class="sum-card warn"><div class="v" id="sumDue">-</div><div class="l">⏰ Jatuh Tempo 7 Hari</div></div>
    <div class="sum-card danger"><div class="v" id="sumOver">-</div><div class="l">🚨 Sudah Lewat Tempo</div></div>
  </div>

  <div style="margin-bottom:10px;display:flex;gap:6px;flex-wrap:wrap">
    <button class="hl-btn hl-btn-outline hl-btn-sm" data-filter="" onclick="setFilter('')">Semua</button>
    <button class="hl-btn hl-btn-outline hl-btn-sm" data-filter="belum_tagih" onclick="setFilter('belum_tagih')">Belum Tagih</button>
    <button class="hl-btn hl-btn-outline hl-btn-sm" data-filter="sudah_tagih" onclick="setFilter('sudah_tagih')">Sudah Tagih</button>
    <button class="hl-btn hl-btn-outline hl-btn-sm" data-filter="sebagian" onclick="setFilter('sebagian')">Sebagian</button>
    <button class="hl-btn hl-btn-outline hl-btn-sm" data-filter="lunas" onclick="setFilter('lunas')">Lunas</button>
  </div>

  <div id="listBox"><div class="empty">⏳ Memuat…</div></div>
</div>

<!-- GENERATE MODAL -->
<div class="modal-bg" id="genModal"><div class="modal">
  <h3>+ Buat Tagihan Piutang B2B</h3>
  <div class="fld">
    <label>Pelanggan B2B (tipe bayar=bulanan / VIP)</label>
    <select id="genPel"><option value="">— Memuat —</option></select>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
    <div class="fld"><label>Periode Mulai</label><input type="date" id="genStart" value="<?= date('Y-m-01') ?>"></div>
    <div class="fld"><label>Periode Akhir</label><input type="date" id="genEnd" value="<?= date('Y-m-d') ?>"></div>
  </div>
  <div class="fld"><label>Jatuh Tempo</label><input type="date" id="genTempo" value="<?= date('Y-m-d', strtotime('+14 days')) ?>"></div>
  <div style="display:flex;gap:8px;justify-content:flex-end">
    <button class="hl-btn hl-btn-outline" onclick="closeModal('genModal')">Batal</button>
    <button class="hl-btn hl-btn-primary" onclick="doGen()">Buat Tagihan</button>
  </div>
</div></div>

<!-- BAYAR MODAL -->
<div class="modal-bg" id="bayarModal"><div class="modal">
  <h3>💵 Catat Pembayaran</h3>
  <p id="bayarSub" style="font-size:13px;color:#6B7280;margin-bottom:12px"></p>
  <input type="hidden" id="bayarId">
  <div class="fld">
    <label>Tipe</label>
    <select id="bayarTipe" onchange="bayarTipeChange()">
      <option value="sebagian">Sebagian</option>
      <option value="lunas">Lunas (sisa)</option>
    </select>
  </div>
  <div class="fld">
    <label>Jumlah Rp</label>
    <input type="number" id="bayarJml" min="0" step="1000">
  </div>
  <div style="display:flex;gap:8px;justify-content:flex-end">
    <button class="hl-btn hl-btn-outline" onclick="closeModal('bayarModal')">Batal</button>
    <button class="hl-btn hl-btn-primary" onclick="doBayar()">Catat → Kas Masuk</button>
  </div>
</div></div>

<?php renderToast(); ?>
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const fmtRp = n => 'Rp ' + Number(n||0).toLocaleString('id-ID');
let curFilter = '';
let bayarSisa = 0;
let pelangganList = [];

function setFilter(f){
  curFilter = f;
  document.querySelectorAll('[data-filter]').forEach(b => b.classList.toggle('hl-btn-primary', b.dataset.filter===f));
  loadList();
}
function closeModal(id){ document.getElementById(id).classList.remove('open'); }

async function loadList(){
  const box = document.getElementById('listBox');
  try {
    const r = await fetch('piutang.php?action=list' + (curFilter?'&status='+curFilter:''));
    const d = await r.json();
    if (d.error){ box.innerHTML = `<div class="empty">⚠️ ${esc(d.error)}</div>`; return; }
    document.getElementById('sumOut').textContent  = fmtRp(d.summary.outstanding);
    document.getElementById('sumDue').textContent  = fmtRp(d.summary.due_week);
    document.getElementById('sumOver').textContent = fmtRp(d.summary.overdue);
    if (!d.rows.length){ box.innerHTML = `<div class="hl-empty-v2">
      <div class="e-icon">💼</div>
      <div class="e-title">Belum ada piutang</div>
      <div class="e-sub">Tagihan B2B yang belum lunas akan muncul di sini</div>
    </div>`; return; }
    let html = '<div style="overflow-x:auto"><table class="tbl hl-stack-mobile"><thead><tr><th>Pelanggan</th><th>Periode</th><th>Jatuh Tempo</th><th style="text-align:right">Tagihan</th><th style="text-align:right">Sisa</th><th>Status</th><th></th></tr></thead><tbody>';
    d.rows.forEach(r => {
      const ht = parseInt(r.hari_tempo);
      let tempoStr = new Date(r.jatuh_tempo).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'});
      if (r.status !== 'lunas') {
        if (ht < 0)       tempoStr += ` <span class="tempo-overdue">(lewat ${Math.abs(ht)} hari)</span>`;
        else if (ht <= 3) tempoStr += ` <span class="tempo-warn">(${ht} hari)</span>`;
        else              tempoStr += ` <small style="color:#9CA3AF">(${ht} hari)</small>`;
      }
      const actions = r.status === 'lunas' ? '<span style="color:#9CA3AF;font-size:11px">✓ lunas</span>' : `
        ${r.status==='belum_tagih' ? `<button class="hl-btn hl-btn-outline btn-sm" onclick="markInvoiced(${r.id})">📤 Tagih</button>` : ''}
        ${r.status!=='belum_tagih' ? `<button class="hl-btn hl-btn-outline btn-sm" onclick="reminder(${r.id})">🔔 Reminder</button>` : ''}
        <button class="hl-btn hl-btn-primary btn-sm" onclick="openBayar(${r.id}, '${esc(r.pelanggan_nama)}', ${r.sisa_tagihan})">💵 Bayar</button>
      `;
      html += `<tr>
        <td data-lbl="Pelanggan"><strong>${esc(r.pelanggan_nama)}</strong><br><small style="color:#9CA3AF">${esc(r.pelanggan_wa||'-')}</small></td>
        <td data-lbl="Periode">${new Date(r.periode_start).toLocaleDateString('id-ID',{day:'2-digit',month:'short'})} – ${new Date(r.periode_end).toLocaleDateString('id-ID',{day:'2-digit',month:'short'})}<br><small style="color:#9CA3AF">${r.total_order} order</small></td>
        <td data-lbl="Jatuh Tempo">${tempoStr}</td>
        <td data-lbl="Tagihan" class="num">${fmtRp(r.total_tagihan)}</td>
        <td data-lbl="Sisa" class="num"><strong>${fmtRp(r.sisa_tagihan)}</strong></td>
        <td data-lbl="Status"><span class="pill pl-${r.status}">${r.status.replace('_',' ')}</span></td>
        <td style="white-space:nowrap">${actions}</td>
      </tr>`;
    });
    html += '</tbody></table></div>';
    box.innerHTML = html;
  } catch(e){ box.innerHTML = `<div class="empty">⚠️ ${esc(e.message)}</div>`; }
}

// Generate
async function openGen(){
  document.getElementById('genModal').classList.add('open');
  if (!pelangganList.length){
    try {
      const r = await fetch('piutang.php?action=list_b2b_customer');
      const d = await r.json();
      pelangganList = d.rows || [];
      const sel = document.getElementById('genPel');
      sel.innerHTML = pelangganList.length
        ? '<option value="">— Pilih —</option>' + pelangganList.map(p => `<option value="${p.id}">${esc(p.nama)} (${esc(p.telepon||'-')})</option>`).join('')
        : '<option value="">⚠️ Belum ada pelanggan B2B (tipe_bayar=bulanan)</option>';
    } catch(e){ document.getElementById('genPel').innerHTML = '<option>⚠️ Gagal load</option>'; }
  }
}
async function doGen(){
  const body = {
    pelanggan_id: document.getElementById('genPel').value,
    start:        document.getElementById('genStart').value,
    end:          document.getElementById('genEnd').value,
    jatuh_tempo:  document.getElementById('genTempo').value,
  };
  if (!body.pelanggan_id) { alert('Pilih pelanggan'); return; }
  try {
    const r = await fetch('piutang.php?action=generate', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body:JSON.stringify(body)});
    const d = await r.json();
    if (d.error){ alert('⚠️ '+d.error); return; }
    showToast(`✅ Tagihan ${d.total_order} order = ${fmtRp(d.total_tagihan)}`,'success');
    closeModal('genModal'); loadList();
  } catch(e){ alert('Gagal: '+e.message); }
}

async function markInvoiced(id){
  if (!confirm('Tandai sebagai sudah tagih? (deduct 200 coin + buka WA invoice)')) return;
  try {
    const r = await fetch('piutang.php?action=mark_invoiced', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body:JSON.stringify({id})});
    const d = await r.json();
    if (d.error){ alert('⚠️ '+d.error); return; }
    if (d.wa) window.open(d.wa, '_blank');
    showToast('✅ Invoice ditandai terkirim','success'); loadList();
  } catch(e){ alert('Gagal: '+e.message); }
}

async function reminder(id){
  if (!confirm('Kirim reminder? (deduct 100 coin + buka WA)')) return;
  try {
    const r = await fetch('piutang.php?action=reminder', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body:JSON.stringify({id})});
    const d = await r.json();
    if (d.error){ alert('⚠️ '+d.error); return; }
    if (d.wa) window.open(d.wa, '_blank');
    showToast('✅ Reminder dikirim','success');
  } catch(e){ alert('Gagal: '+e.message); }
}

function openBayar(id, nama, sisa){
  document.getElementById('bayarId').value = id;
  document.getElementById('bayarSub').textContent = `${nama} — sisa ${fmtRp(sisa)}`;
  bayarSisa = sisa;
  document.getElementById('bayarTipe').value = 'sebagian';
  document.getElementById('bayarJml').value = sisa;
  document.getElementById('bayarModal').classList.add('open');
}
function bayarTipeChange(){
  if (document.getElementById('bayarTipe').value === 'lunas') {
    document.getElementById('bayarJml').value = bayarSisa;
  }
}
async function doBayar(){
  const body = {
    id:     document.getElementById('bayarId').value,
    tipe:   document.getElementById('bayarTipe').value,
    jumlah: document.getElementById('bayarJml').value,
  };
  try {
    const r = await fetch('piutang.php?action=bayar', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body:JSON.stringify(body)});
    const d = await r.json();
    if (d.error){ alert('⚠️ '+d.error); return; }
    showToast(`✅ Tercatat (status: ${d.status})`,'success');
    closeModal('bayarModal'); loadList();
  } catch(e){ alert('Gagal: '+e.message); }
}

loadList();
</script>
</body>
</html>
