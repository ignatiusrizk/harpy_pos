<?php
// ══════════════════════════════════════════════════════
// hq/penggajian.php — Penggajian Konsolidasi (#4)
//   Total beban gaji semua outlet + generate slip massal
// ══════════════════════════════════════════════════════

$activePage = 'hq-penggajian';
$pageTitle  = 'Penggajian Konsolidasi';

define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];
$action = $_GET['action'] ?? '';
$canManage = !empty($hqIsOwner) || !empty($hqIsManager);

function gajiBulan(): string {
    return preg_match('/^\d{4}-\d{2}$/', $_GET['bulan'] ?? ($_POST['bulan'] ?? '')) ? ($_GET['bulan'] ?? $_POST['bulan']) : date('Y-m');
}

// ── API: konsolidasi per outlet ──────────────────────
if ($action === 'data') {
    header('Content-Type: application/json');
    $bulan = gajiBulan();
    try {
        $oStmt = $db->prepare("SELECT id, nama_outlet FROM outlets
                                WHERE tenant_id=? AND status IN ('trial','grace','active')
                                ORDER BY is_main DESC, nama_outlet");
        $oStmt->execute([$tid]);
        $rows = [];
        $totBeban=0; $totPending=0; $totDibayar=0; $totSlip=0; $totKar=0;
        foreach ($oStmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $oid = (int)$o['id'];
            // Slip gaji bulan ini
            $g = $db->prepare("SELECT COUNT(*) slip, COALESCE(SUM(total),0) beban,
                                      COALESCE(SUM(CASE WHEN status='dibayar' THEN total ELSE 0 END),0) dibayar,
                                      COALESCE(SUM(CASE WHEN status='pending'  THEN total ELSE 0 END),0) pending,
                                      SUM(status='dibayar') cnt_dibayar, SUM(status='pending') cnt_pending
                                 FROM hl_gaji WHERE tenant_id=? AND outlet_id=? AND bulan=?");
            $g->execute([$tid,$oid,$bulan]);
            $gr = $g->fetch(PDO::FETCH_ASSOC);
            // Karyawan aktif di outlet
            $k = $db->prepare("SELECT COUNT(DISTINCT karyawan_id) FROM hl_karyawan_outlet
                                WHERE tenant_id=? AND outlet_id=? AND is_active=1");
            $k->execute([$tid,$oid]);
            $kary = (int)$k->fetchColumn();

            $beban=(int)$gr['beban'];
            $rows[] = [
                'outlet_id'=>$oid, 'nama_outlet'=>$o['nama_outlet'],
                'karyawan'=>$kary, 'slip'=>(int)$gr['slip'],
                'beban'=>$beban, 'pending'=>(int)$gr['pending'], 'dibayar'=>(int)$gr['dibayar'],
                'cnt_pending'=>(int)$gr['cnt_pending'], 'cnt_dibayar'=>(int)$gr['cnt_dibayar'],
                'belum_generate'=>max(0, $kary - (int)$gr['slip']),
            ];
            $totBeban+=$beban; $totPending+=(int)$gr['pending']; $totDibayar+=(int)$gr['dibayar'];
            $totSlip+=(int)$gr['slip']; $totKar+=$kary;
        }
        echo json_encode(['ok'=>true, 'bulan'=>$bulan, 'rows'=>$rows,
            'total'=>['beban'=>$totBeban,'pending'=>$totPending,'dibayar'=>$totDibayar,'slip'=>$totSlip,'karyawan'=>$totKar]]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: generate slip massal (semua / 1 outlet) ─────
if ($action === 'generate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $bulan = preg_match('/^\d{4}-\d{2}$/', $d['bulan'] ?? '') ? $d['bulan'] : date('Y-m');
    $targetOutlet = (int)($d['outlet_id'] ?? 0); // 0 = semua
    $u = currentUser();
    try {
        $oSql = "SELECT id FROM outlets WHERE tenant_id=? AND status IN ('trial','grace','active')" . ($targetOutlet>0?" AND id=?":"");
        $oStmt = $db->prepare($oSql);
        $oStmt->execute($targetOutlet>0 ? [$tid,$targetOutlet] : [$tid]);
        $outletIds = array_column($oStmt->fetchAll(PDO::FETCH_ASSOC), 'id');

        // Jumlah outlet aktif per karyawan → untuk split proporsional gaji
        $cntStmt = $db->prepare("SELECT karyawan_id, COUNT(DISTINCT outlet_id) c
                                   FROM hl_karyawan_outlet
                                  WHERE tenant_id=? AND is_active=1 GROUP BY karyawan_id");
        $cntStmt->execute([$tid]);
        $outletCount = [];
        foreach ($cntStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $outletCount[(int)$row['karyawan_id']] = max(1,(int)$row['c']);

        $ins = $db->prepare("INSERT IGNORE INTO hl_gaji (tenant_id,outlet_id,user_id,bulan,gaji_pokok,total,status,catatan,created_by,created_at)
                             VALUES (?,?,?,?,?,?,'pending',?,NOW())");
        $created = 0; $splitCount = 0;
        foreach ($outletIds as $oid) {
            $oid = (int)$oid;
            $users = $db->prepare("SELECT u.id, u.gaji_pokok FROM hl_users u
                                    JOIN hl_karyawan_outlet ko ON ko.karyawan_id=u.id AND ko.tenant_id=u.tenant_id
                                         AND ko.outlet_id=? AND ko.is_active=1
                                   WHERE u.tenant_id=? AND u.is_active=1");
            $users->execute([$oid,$tid]);
            foreach ($users->fetchAll(PDO::FETCH_ASSOC) as $usr) {
                $uid2 = (int)$usr['id'];
                $nOutlet = $outletCount[$uid2] ?? 1;
                $gpFull = (float)($usr['gaji_pokok'] ?? 0);
                $gp = $nOutlet > 1 ? round($gpFull / $nOutlet) : $gpFull;
                $note = $nOutlet > 1 ? "Gaji di-split $nOutlet outlet (porsi 1/$nOutlet dari Rp ".number_format($gpFull,0,',','.').")" : null;
                $ins->execute([$tid,$oid,$uid2,$bulan,$gp,$gp,$note, $u?(int)$u['id']:null]);
                if ($ins->rowCount() > 0) { $created++; if ($nOutlet>1) $splitCount++; }
            }
        }
        try { logAudit('generate_gaji','penggajian',"Generate slip massal $bulan ($created baru, $splitCount split multi-outlet)"); } catch (Throwable) {}
        echo json_encode(['ok'=>true, 'created'=>$created, 'split'=>$splitCount]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: tandai semua dibayar (1 outlet / semua) ─────
if ($action === 'mark_paid' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $bulan = preg_match('/^\d{4}-\d{2}$/', $d['bulan'] ?? '') ? $d['bulan'] : date('Y-m');
    $oid = (int)($d['outlet_id'] ?? 0);
    try {
        $sql = "UPDATE hl_gaji SET status='dibayar', dibayar_at=NOW()
                 WHERE tenant_id=? AND bulan=? AND status='pending'" . ($oid>0?" AND outlet_id=?":"");
        $stmt = $db->prepare($sql);
        $stmt->execute($oid>0 ? [$tid,$bulan,$oid] : [$tid,$bulan]);
        try { logAudit('mark_paid','penggajian',"Tandai gaji dibayar $bulan".($oid?" outlet#$oid":" semua")); } catch (Throwable) {}
        echo json_encode(['ok'=>true, 'affected'=>$stmt->rowCount()]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

require __DIR__ . '/_layout_open.php';
?>
<style>
.pg-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:10px}
.pg-head h1{font-size:1.3rem;font-weight:800;color:#0F1C3A}
.metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
.metric{background:#fff;border:1px solid #EEF1F8;border-radius:12px;padding:16px;box-shadow:0 1px 6px rgba(0,0,0,.05);border-top:3px solid #35E8D5}
.metric.amber{border-top-color:#F59E0B}.metric.green{border-top-color:#10B981}.metric.blue{border-top-color:#3B82F6}
.metric-num{font-size:1.4rem;font-weight:800;color:#0F1C3A;font-family:monospace}
.metric-label{font-size:12px;color:#6B7280;font-weight:600;margin-top:2px}
.panel{background:#fff;border:1px solid #EEF1F8;border-radius:14px;padding:20px;box-shadow:0 1px 6px rgba(0,0,0,.05)}
.panel-title{font-size:14px;font-weight:700;color:#0F1C3A;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}
.filter{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
.filter input{padding:7px 11px;border:1px solid #E5E9F2;border-radius:8px;font-family:inherit;font-size:13px}
.btn{padding:8px 14px;border-radius:8px;font-weight:700;font-size:13px;border:none;cursor:pointer;font-family:inherit;background:#0F1C3A;color:#fff}
.btn:hover{background:#1a2d52}.btn-sm{padding:6px 11px;font-size:12px}
.btn-light{background:#fff;color:#0F1C3A;border:1px solid #E5E9F2}
.btn-green{background:#10B981}.btn-green:hover{background:#059669}
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{background:#F7F8FC;padding:9px 11px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;color:#6B7280}
.tbl td{padding:10px 11px;border-top:1px solid #F0F1F4}
.tbl .num{font-family:monospace;font-weight:700;text-align:right}
.pill{font-size:11px;font-weight:700;padding:2px 8px;border-radius:100px}
.pill-warn{background:#FEF3C7;color:#92400E}.pill-ok{background:#D1FAE5;color:#065F46}.pill-gray{background:#F3F4F6;color:#6B7280}
.empty{text-align:center;padding:30px;color:#9CA3AF;font-size:13px}
</style>

<div class="pg-head">
  <h1>💵 Penggajian Konsolidasi</h1>
  <?php if ($canManage): ?>
  <div style="display:flex;gap:8px">
    <button class="btn" onclick="generateAll()">⚙️ Generate Slip Semua Outlet</button>
  </div>
  <?php endif; ?>
</div>
<p style="font-size:13px;color:#6B7280;margin-bottom:16px">Total beban gaji semua outlet + generate slip massal per bulan.</p>

<div class="filter">
  <label style="font-size:12px;color:#6B7280;font-weight:600">Bulan:</label>
  <input type="month" id="fBulan" value="<?= date('Y-m') ?>">
  <button class="btn btn-light btn-sm" onclick="loadData()">↻ Terapkan</button>
</div>

<div class="metrics">
  <div class="metric"><div class="metric-num" id="mBeban">-</div><div class="metric-label">Total Beban Gaji</div></div>
  <div class="metric amber"><div class="metric-num" id="mPending">-</div><div class="metric-label">Belum Dibayar</div></div>
  <div class="metric green"><div class="metric-num" id="mDibayar">-</div><div class="metric-label">Sudah Dibayar</div></div>
  <div class="metric blue"><div class="metric-num" id="mSlip">-</div><div class="metric-label">Total Slip / Karyawan</div></div>
</div>

<div class="panel">
  <div class="panel-title">📍 Beban Gaji per Outlet</div>
  <div id="tblBox"><div class="empty">⏳ Memuat…</div></div>
</div>

<script>
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const fmtRp = n => 'Rp ' + Number(n||0).toLocaleString('id-ID');
const CAN_MANAGE = <?= $canManage ? 'true':'false' ?>;

async function loadData(){
  const bulan = document.getElementById('fBulan').value;
  const box = document.getElementById('tblBox');
  box.innerHTML = '<div class="empty">⏳ Memuat…</div>';
  try {
    const r = await fetch(`/ERP/harpy/hq/penggajian.php?action=data&bulan=${bulan}`);
    const d = await r.json();
    if (d.error){ box.innerHTML = `<div class="empty">⚠️ ${esc(d.error)}</div>`; return; }
    document.getElementById('mBeban').textContent = fmtRp(d.total.beban);
    document.getElementById('mPending').textContent = fmtRp(d.total.pending);
    document.getElementById('mDibayar').textContent = fmtRp(d.total.dibayar);
    document.getElementById('mSlip').textContent = `${d.total.slip} / ${d.total.karyawan}`;
    if (!d.rows.length){ box.innerHTML = '<div class="empty">Belum ada outlet.</div>'; return; }
    let html = '<table class="tbl"><thead><tr><th>Outlet</th><th style="text-align:center">Karyawan</th><th style="text-align:center">Slip</th><th style="text-align:right">Beban</th><th style="text-align:center">Status</th>'+(CAN_MANAGE?'<th></th>':'')+'</tr></thead><tbody>';
    d.rows.forEach(o => {
      let status;
      if (o.slip === 0) status = '<span class="pill pill-gray">belum generate</span>';
      else if (o.cnt_pending === 0) status = '<span class="pill pill-ok">lunas</span>';
      else status = `<span class="pill pill-warn">${o.cnt_pending} pending</span>`;
      const belumGen = o.belum_generate > 0 ? ` <span style="font-size:10px;color:#EF4444">(${o.belum_generate} blm)</span>` : '';
      html += `<tr>
        <td><strong>${esc(o.nama_outlet)}</strong></td>
        <td style="text-align:center">${o.karyawan}</td>
        <td style="text-align:center">${o.slip}${belumGen}</td>
        <td class="num">${fmtRp(o.beban)}</td>
        <td style="text-align:center">${status}</td>
        ${CAN_MANAGE?`<td style="white-space:nowrap">
          ${o.belum_generate>0?`<button class="btn btn-light btn-sm" onclick="genOutlet(${o.outlet_id})">⚙️ Generate</button>`:''}
          ${o.cnt_pending>0?`<button class="btn btn-green btn-sm" onclick="markPaid(${o.outlet_id}, ${JSON.stringify(o.nama_outlet)})">✓ Bayar</button>`:''}
        </td>`:''}
      </tr>`;
    });
    html += '</tbody></table>';
    box.innerHTML = html;
  } catch(e){ box.innerHTML = `<div class="empty">⚠️ ${esc(e.message)}</div>`; }
}

async function generateAll(){
  const bulan = document.getElementById('fBulan').value;
  if (!confirm(`Generate slip gaji untuk SEMUA outlet bulan ${bulan}?\nKaryawan yang sudah punya slip tidak akan dobel.`)) return;
  await doGenerate(bulan, 0);
}
async function genOutlet(oid){
  const bulan = document.getElementById('fBulan').value;
  await doGenerate(bulan, oid);
}
async function doGenerate(bulan, oid){
  try {
    const r = await fetch('/ERP/harpy/hq/penggajian.php?action=generate', {method:'POST', body:JSON.stringify({bulan, outlet_id:oid})});
    const d = await r.json();
    if (d.error){ alert('⚠️ '+d.error); return; }
    let msg = `✅ ${d.created} slip gaji baru dibuat.`;
    if (d.split > 0) msg += `\n${d.split} karyawan multi-outlet → gaji di-split proporsional.`;
    alert(msg);
    loadData();
  } catch(e){ alert('Gagal: '+e.message); }
}
async function markPaid(oid, nama){
  const bulan = document.getElementById('fBulan').value;
  if (!confirm(`Tandai semua slip pending di "${nama}" bulan ${bulan} sebagai DIBAYAR?`)) return;
  try {
    const r = await fetch('/ERP/harpy/hq/penggajian.php?action=mark_paid', {method:'POST', body:JSON.stringify({bulan, outlet_id:oid})});
    const d = await r.json();
    if (d.error){ alert('⚠️ '+d.error); return; }
    alert(`✅ ${d.affected} slip ditandai dibayar.`);
    loadData();
  } catch(e){ alert('Gagal: '+e.message); }
}

loadData();
</script>

<?php require __DIR__ . '/_layout_close.php'; ?>
