<?php
// ══════════════════════════════════════════════════════
// hq/sdm.php — SDM Analytics Lintas Outlet
//   #3 Rekap absensi lintas outlet
//   #5 Perbandingan produktivitas (omset per karyawan)
// ══════════════════════════════════════════════════════

$activePage = 'hq-sdm';
$pageTitle  = 'SDM Analytics';

define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];
$action = $_GET['action'] ?? '';

function sdmRange(): array {
    $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start'] ?? '') ? $_GET['start'] : date('Y-m-01');
    $end   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end'] ?? '')   ? $_GET['end']   : date('Y-m-d');
    return [$start, $end];
}

// ── API: rekap absensi lintas outlet ─────────────────
if ($action === 'absensi') {
    header('Content-Type: application/json');
    [$start, $end] = sdmRange();
    $outletId = (int)($_GET['outlet_id'] ?? 0);
    try {
        $oFilter = $outletId > 0 ? " AND a.outlet_id=?" : "";
        // Rekap per karyawan: hadir/izin/sakit/alpha + telat
        // Telat = jam_masuk melewati jam_buka outlet (default 08:00 kalau kolom belum ada)
        $sql = "SELECT u.id user_id, u.nama, a.outlet_id,
                       o.nama_outlet,
                       COUNT(*) total_hari,
                       SUM(a.status='hadir') hadir,
                       SUM(a.status='izin')  izin,
                       SUM(a.status='sakit') sakit,
                       SUM(a.status='alpha') alpha,
                       SUM(CASE WHEN a.status='hadir' AND a.jam_masuk IS NOT NULL
                                 AND a.jam_masuk > COALESCE(o.jam_buka,'08:00:00')
                                THEN 1 ELSE 0 END) telat
                  FROM hl_absensi a
                  JOIN hl_users u ON u.id=a.user_id AND u.tenant_id=a.tenant_id
                  LEFT JOIN outlets o ON o.id=a.outlet_id
                 WHERE a.tenant_id=? AND a.tanggal BETWEEN ? AND ? $oFilter
                 GROUP BY u.id, u.nama, a.outlet_id, o.nama_outlet
                 ORDER BY (telat + alpha + izin) DESC, u.nama";
        $params = [$tid, $start, $end];
        if ($outletId > 0) $params[] = $outletId;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['ok'=>true, 'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: produktivitas per outlet (omset / karyawan) ──
if ($action === 'produktivitas') {
    header('Content-Type: application/json');
    [$start, $end] = sdmRange();
    try {
        $oStmt = $db->prepare("SELECT id, nama_outlet FROM outlets
                                WHERE tenant_id=? AND status IN ('trial','grace','active')
                                ORDER BY is_main DESC, nama_outlet");
        $oStmt->execute([$tid]);
        $rows = [];
        foreach ($oStmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $oid = (int)$o['id'];
            // Omset
            $om = $db->prepare("SELECT COALESCE(SUM(total),0) FROM hl_transaksi
                                 WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal) BETWEEN ? AND ?");
            $om->execute([$tid,$oid,$start,$end]);
            $omset = (int)$om->fetchColumn();
            // Order count
            $oc = $db->prepare("SELECT COUNT(*) FROM hl_transaksi
                                 WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal) BETWEEN ? AND ?");
            $oc->execute([$tid,$oid,$start,$end]);
            $orderCount = (int)$oc->fetchColumn();
            // Karyawan aktif
            $kc = $db->prepare("SELECT COUNT(DISTINCT karyawan_id) FROM hl_karyawan_outlet
                                 WHERE tenant_id=? AND outlet_id=? AND is_active=1");
            $kc->execute([$tid,$oid]);
            $kary = (int)$kc->fetchColumn();
            $rows[] = [
                'outlet_id'   => $oid,
                'nama_outlet' => $o['nama_outlet'],
                'omset'       => $omset,
                'order_count' => $orderCount,
                'karyawan'    => $kary,
                'omset_per_kar' => $kary > 0 ? (int)round($omset/$kary) : 0,
                'order_per_kar' => $kary > 0 ? round($orderCount/$kary,1) : 0,
            ];
        }
        usort($rows, fn($a,$b)=>$b['omset_per_kar'] <=> $a['omset_per_kar']);
        echo json_encode(['ok'=>true, 'rows'=>$rows]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

$outlets = $db->prepare("SELECT id, nama_outlet FROM outlets WHERE tenant_id=? AND status IN ('trial','grace','active') ORDER BY is_main DESC, nama_outlet");
$outlets->execute([$tid]);
$outletList = $outlets->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/_layout_open.php';
?>
<style>
.sdm-tabs{display:flex;gap:6px;margin-bottom:16px}
.sdm-tab{padding:9px 18px;border-radius:9px;font-size:13px;font-weight:700;border:1px solid #E5E9F2;background:#fff;color:#6B7280;cursor:pointer;font-family:inherit}
.sdm-tab.active{background:#0F1C3A;color:#fff;border-color:#0F1C3A}
.panel{background:#fff;border:1px solid #EEF1F8;border-radius:14px;padding:20px;box-shadow:0 1px 6px rgba(0,0,0,.05);margin-bottom:16px}
.panel-title{font-size:14px;font-weight:700;color:#0F1C3A;margin-bottom:14px}
.filter{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
.filter input,.filter select{padding:7px 11px;border:1px solid #E5E9F2;border-radius:8px;font-family:inherit;font-size:13px}
.btn{padding:7px 13px;border-radius:8px;font-weight:700;font-size:13px;border:none;cursor:pointer;font-family:inherit;background:#0F1C3A;color:#fff}
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{background:#F7F8FC;padding:9px 11px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;color:#6B7280}
.tbl td{padding:10px 11px;border-top:1px solid #F0F1F4}
.tbl .num{font-family:monospace;font-weight:700;text-align:right}
.pill{font-size:11px;font-weight:700;padding:2px 8px;border-radius:100px}
.pill-ok{background:#D1FAE5;color:#065F46}
.pill-warn{background:#FEF3C7;color:#92400E}
.pill-bad{background:#FEE2E2;color:#991B1B}
.medal{font-weight:800}
.bar{background:#EEF1F8;border-radius:100px;height:7px;overflow:hidden;margin-top:3px}
.bar-fill{height:100%;background:#35E8D5}
.empty{text-align:center;padding:30px;color:#9CA3AF;font-size:13px}
</style>

<h1 style="font-size:1.3rem;font-weight:800;color:#0F1C3A;margin-bottom:4px">👥 SDM Analytics Lintas Outlet</h1>
<p style="font-size:13px;color:#6B7280;margin-bottom:16px">Rekap absensi & perbandingan produktivitas SDM antar outlet.</p>

<div class="sdm-tabs">
  <button class="sdm-tab active" id="tabAbs" onclick="switchTab('abs')">📅 Rekap Absensi</button>
  <button class="sdm-tab" id="tabProd" onclick="switchTab('prod')">⚡ Produktivitas</button>
</div>

<div class="filter">
  <label style="font-size:12px;color:#6B7280;font-weight:600">Periode:</label>
  <input type="date" id="fStart" value="<?= date('Y-m-01') ?>">
  <input type="date" id="fEnd" value="<?= date('Y-m-d') ?>">
  <select id="fOutlet">
    <option value="0">📍 Semua Outlet</option>
    <?php foreach ($outletList as $o): ?><option value="<?= (int)$o['id'] ?>"><?= htmlspecialchars($o['nama_outlet']) ?></option><?php endforeach; ?>
  </select>
  <button class="btn" onclick="reload()">↻ Terapkan</button>
</div>

<!-- ABSENSI -->
<div id="paneAbs">
  <div class="panel">
    <div class="panel-title">📅 Rekap Absensi per Karyawan</div>
    <div id="absBox"><div class="empty">⏳ Memuat…</div></div>
  </div>
</div>

<!-- PRODUKTIVITAS -->
<div id="paneProd" style="display:none">
  <div class="panel">
    <div class="panel-title">⚡ Produktivitas per Outlet <span style="font-size:11px;font-weight:400;color:#9CA3AF">omset & order per karyawan</span></div>
    <div id="prodBox"><div class="empty">⏳ Memuat…</div></div>
  </div>
</div>

<script>
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const fmtRp = n => 'Rp ' + Number(n||0).toLocaleString('id-ID');
const fmt = n => Number(n||0).toLocaleString('id-ID');
let curTab = 'abs';

function switchTab(t){
  curTab = t;
  document.getElementById('tabAbs').classList.toggle('active', t==='abs');
  document.getElementById('tabProd').classList.toggle('active', t==='prod');
  document.getElementById('paneAbs').style.display = t==='abs'?'block':'none';
  document.getElementById('paneProd').style.display = t==='prod'?'block':'none';
  reload();
}
function params(){
  return `start=${document.getElementById('fStart').value}&end=${document.getElementById('fEnd').value}&outlet_id=${document.getElementById('fOutlet').value}`;
}
function reload(){ curTab==='abs' ? loadAbsensi() : loadProd(); }

async function loadAbsensi(){
  const box = document.getElementById('absBox');
  box.innerHTML = '<div class="empty">⏳ Memuat…</div>';
  try {
    const r = await fetch(`/ERP/harpy/hq/sdm.php?action=absensi&${params()}`);
    const d = await r.json();
    if (d.error){ box.innerHTML = `<div class="empty">⚠️ ${esc(d.error)}</div>`; return; }
    if (!d.rows.length){ box.innerHTML = '<div class="empty">Belum ada data absensi.</div>'; return; }
    let html = '<table class="tbl"><thead><tr><th>Karyawan</th><th>Outlet</th><th style="text-align:center">Hadir</th><th style="text-align:center">Telat</th><th style="text-align:center">Izin</th><th style="text-align:center">Sakit</th><th style="text-align:center">Alpha</th><th style="text-align:center">Disiplin</th></tr></thead><tbody>';
    d.rows.forEach(r => {
      const total = Number(r.total_hari)||1;
      const skor = Math.round((Number(r.hadir) - Number(r.telat)*0.5 - Number(r.alpha)) / total * 100);
      const pill = skor>=90?'pill-ok':skor>=70?'pill-warn':'pill-bad';
      html += `<tr>
        <td><strong>${esc(r.nama)}</strong></td>
        <td>${esc(r.nama_outlet||'-')}</td>
        <td style="text-align:center">${r.hadir}</td>
        <td style="text-align:center">${Number(r.telat)>0?`<span class="pill pill-warn">${r.telat}</span>`:'0'}</td>
        <td style="text-align:center">${r.izin}</td>
        <td style="text-align:center">${r.sakit}</td>
        <td style="text-align:center">${Number(r.alpha)>0?`<span class="pill pill-bad">${r.alpha}</span>`:'0'}</td>
        <td style="text-align:center"><span class="pill ${pill}">${skor}%</span></td>
      </tr>`;
    });
    html += '</tbody></table>';
    box.innerHTML = html;
  } catch(e){ box.innerHTML = `<div class="empty">⚠️ ${esc(e.message)}</div>`; }
}

async function loadProd(){
  const box = document.getElementById('prodBox');
  box.innerHTML = '<div class="empty">⏳ Memuat…</div>';
  try {
    const r = await fetch(`/ERP/harpy/hq/sdm.php?action=produktivitas&${params()}`);
    const d = await r.json();
    if (d.error){ box.innerHTML = `<div class="empty">⚠️ ${esc(d.error)}</div>`; return; }
    if (!d.rows.length){ box.innerHTML = '<div class="empty">Belum ada data.</div>'; return; }
    const maxOpk = Math.max(...d.rows.map(r=>Number(r.omset_per_kar)), 1);
    let html = '<table class="tbl"><thead><tr><th>#</th><th>Outlet</th><th style="text-align:right">Omset</th><th style="text-align:center">Karyawan</th><th style="text-align:right">Omset/Karyawan</th><th style="text-align:right">Order/Karyawan</th></tr></thead><tbody>';
    d.rows.forEach((r,i) => {
      const medal = i===0?'🥇':i===1?'🥈':i===2?'🥉':(i+1);
      const pct = Math.round(Number(r.omset_per_kar)/maxOpk*100);
      html += `<tr>
        <td class="medal">${medal}</td>
        <td><strong>${esc(r.nama_outlet)}</strong></td>
        <td class="num">${fmtRp(r.omset)}</td>
        <td style="text-align:center">${r.karyawan}</td>
        <td class="num">${fmtRp(r.omset_per_kar)}<div class="bar"><div class="bar-fill" style="width:${pct}%"></div></div></td>
        <td class="num">${r.order_per_kar}</td>
      </tr>`;
    });
    html += '</tbody></table>';
    box.innerHTML = html;
  } catch(e){ box.innerHTML = `<div class="empty">⚠️ ${esc(e.message)}</div>`; }
}

loadAbsensi();
</script>

<?php require __DIR__ . '/_layout_close.php'; ?>
