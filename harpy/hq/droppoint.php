<?php
// hq/droppoint.php — Performa Drop Point Lintas Outlet
$activePage = 'hq-droppoint';
$pageTitle  = 'Drop Point Performance';

define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

$db  = Database::get();
$tid = (int)$hqTenant['id'];
$action = $_GET['action'] ?? '';

function dpRange(): array {
    $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start'] ?? '') ? $_GET['start'] : date('Y-m-01');
    $end   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end']   ?? '') ? $_GET['end']   : date('Y-m-d');
    return [$start, $end];
}

// ── API: ringkasan per outlet ──
if ($action === 'per_outlet') {
    header('Content-Type: application/json');
    [$start, $end] = dpRange();
    try {
        $sql = "
          SELECT o.id outlet_id, o.nama_outlet,
                 (SELECT COUNT(*) FROM hl_drop_points WHERE tenant_id=o.tenant_id AND outlet_id=o.id AND status='aktif') AS mitra_aktif,
                 (SELECT COUNT(*) FROM hl_transaksi t WHERE t.tenant_id=o.tenant_id AND t.outlet_id=o.id
                       AND t.drop_point_id IS NOT NULL AND DATE(t.tanggal) BETWEEN ? AND ?) AS order_periode,
                 (SELECT COALESCE(SUM(total),0) FROM hl_transaksi t WHERE t.tenant_id=o.tenant_id AND t.outlet_id=o.id
                       AND t.drop_point_id IS NOT NULL AND DATE(t.tanggal) BETWEEN ? AND ?) AS omset_periode,
                 (SELECT COALESCE(SUM(total_komisi),0) FROM hl_komisi_rekap
                       WHERE tenant_id=o.tenant_id AND outlet_id=o.id
                         AND periode_start>=? AND periode_end<=?) AS komisi_periode
            FROM outlets o
           WHERE o.tenant_id=? AND o.status IN ('trial','grace','active')
           ORDER BY o.is_main DESC, o.nama_outlet ASC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$start,$end,$start,$end,$start,$end,$tid]);
        echo json_encode(['ok'=>true, 'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC), 'periode'=>['start'=>$start,'end'=>$end]]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: top mitra lintas outlet ──
if ($action === 'top_mitra') {
    header('Content-Type: application/json');
    [$start, $end] = dpRange();
    try {
        $sql = "
          SELECT dp.id, dp.nama_mitra, dp.komisi_model, o.nama_outlet,
                 COUNT(t.id) total_order,
                 COALESCE(SUM(t.total),0) total_omset
            FROM hl_drop_points dp
            JOIN outlets o ON o.id=dp.outlet_id
            LEFT JOIN hl_transaksi t ON t.drop_point_id=dp.id AND t.tenant_id=dp.tenant_id
                 AND DATE(t.tanggal) BETWEEN ? AND ?
           WHERE dp.tenant_id=? AND dp.status='aktif'
           GROUP BY dp.id, dp.nama_mitra, dp.komisi_model, o.nama_outlet
           ORDER BY total_order DESC LIMIT 10
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$start,$end,$tid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Hitung komisi per mitra (live, sesuai model)
        foreach ($rows as &$r) {
            $dpRow = $db->prepare("SELECT * FROM hl_drop_points WHERE id=? AND tenant_id=?");
            $dpRow->execute([$r['id'], $tid]);
            $m = $dpRow->fetch(PDO::FETCH_ASSOC);
            $kg = 0;
            try {
                $k = $db->prepare("SELECT COALESCE(SUM(ti.jumlah),0) FROM hl_transaksi t
                                    JOIN hl_transaksi_item ti ON ti.transaksi_id=t.id
                                         AND ti.id=(SELECT MIN(id) FROM hl_transaksi_item WHERE transaksi_id=t.id)
                                   WHERE t.tenant_id=? AND t.drop_point_id=? AND DATE(t.tanggal) BETWEEN ? AND ?");
                $k->execute([$tid,$r['id'],$start,$end]); $kg = (float)$k->fetchColumn();
            } catch (Throwable) {}
            $komisi = 0;
            if (in_array($m['komisi_model'], ['per_kg','kombinasi'])) $komisi += (int)$m['komisi_per_kg'] * (int)round($kg);
            if (in_array($m['komisi_model'], ['persen','kombinasi'])) $komisi += (int)round((float)$m['komisi_persen']/100 * (int)$r['total_omset']);
            if (in_array($m['komisi_model'], ['flat','kombinasi']))   $komisi += (int)$m['komisi_flat'] * (int)$r['total_order'];
            $r['total_kg'] = $kg;
            $r['total_komisi'] = $komisi;
        }
        unset($r);
        echo json_encode(['ok'=>true, 'rows'=>$rows]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

require __DIR__ . '/_layout_open.php';
?>
<style>
.panel{background:#fff;border:1px solid #EEF1F8;border-radius:14px;padding:20px;box-shadow:0 1px 6px rgba(0,0,0,.05);margin-bottom:16px}
.panel-title{font-size:14px;font-weight:700;color:#0F1C3A;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}
.filter{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
.filter input{padding:7px 11px;border:1px solid #E5E9F2;border-radius:8px;font-family:inherit;font-size:13px}
.btn{padding:7px 13px;border-radius:8px;font-weight:700;font-size:13px;border:none;cursor:pointer;font-family:inherit;background:#0F1C3A;color:#fff}
.btn-light{background:#fff;color:#0F1C3A;border:1px solid #E5E9F2}
.btn-sm{padding:6px 11px;font-size:12px}
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{background:#F7F8FC;padding:9px 11px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;color:#6B7280}
.tbl td{padding:10px 11px;border-top:1px solid #F0F1F4}
.tbl .num{font-family:monospace;font-weight:700;text-align:right}
.medal{font-size:14px;font-weight:800;text-align:center}
.empty{text-align:center;padding:30px;color:#9CA3AF;font-size:13px}
</style>

<h1 style="font-size:1.3rem;font-weight:800;color:#0F1C3A;margin-bottom:4px">📦 Drop Point Performance</h1>
<p style="font-size:13px;color:#6B7280;margin-bottom:16px">Performa mitra drop point lintas outlet — order, omset, komisi.</p>

<div class="filter">
  <label style="font-size:12px;color:#6B7280;font-weight:600">Periode:</label>
  <input type="date" id="fStart" value="<?= date('Y-m-01') ?>">
  <input type="date" id="fEnd"   value="<?= date('Y-m-d') ?>">
  <button class="btn btn-sm" onclick="loadAll()">↻ Terapkan</button>
</div>

<div class="panel">
  <div class="panel-title">🏢 Performa per Outlet</div>
  <div id="perOutletBox"><div class="empty">⏳ Memuat…</div></div>
</div>

<div class="panel">
  <div class="panel-title">🏆 Top Mitra Lintas Outlet</div>
  <div id="topMitraBox"><div class="empty">⏳ Memuat…</div></div>
</div>

<script>
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const fmtRp = n => 'Rp ' + Number(n||0).toLocaleString('id-ID');

function params(){
  return `start=${document.getElementById('fStart').value}&end=${document.getElementById('fEnd').value}`;
}

async function loadPerOutlet(){
  const box = document.getElementById('perOutletBox');
  try {
    const r = await fetch(`/ERP/harpy/hq/droppoint.php?action=per_outlet&${params()}`);
    const d = await r.json();
    if (d.error){ box.innerHTML = `<div class="empty">⚠️ ${esc(d.error)}</div>`; return; }
    if (!d.rows.length){ box.innerHTML = '<div class="empty">Belum ada outlet aktif.</div>'; return; }
    let totMitra=0,totOrder=0,totOmset=0,totKomisi=0;
    let html = '<div style="overflow-x:auto"><table class="tbl"><thead><tr><th>Outlet</th><th style="text-align:center">Mitra Aktif</th><th style="text-align:right">Order</th><th style="text-align:right">Omset</th><th style="text-align:right">Komisi (rekap dibayar)</th></tr></thead><tbody>';
    d.rows.forEach(r => {
      totMitra += +r.mitra_aktif; totOrder += +r.order_periode; totOmset += +r.omset_periode; totKomisi += +r.komisi_periode;
      html += `<tr>
        <td><strong>${esc(r.nama_outlet)}</strong></td>
        <td style="text-align:center">${r.mitra_aktif} mitra</td>
        <td class="num">${Number(r.order_periode).toLocaleString('id-ID')}</td>
        <td class="num">${fmtRp(r.omset_periode)}</td>
        <td class="num">${fmtRp(r.komisi_periode)}</td>
      </tr>`;
    });
    html += `<tr style="background:#F7F8FC;font-weight:800">
      <td>TOTAL</td><td style="text-align:center">${totMitra}</td>
      <td class="num">${Number(totOrder).toLocaleString('id-ID')}</td>
      <td class="num">${fmtRp(totOmset)}</td><td class="num">${fmtRp(totKomisi)}</td></tr>`;
    html += '</tbody></table></div>';
    box.innerHTML = html;
  } catch(e){ box.innerHTML = `<div class="empty">⚠️ ${esc(e.message)}</div>`; }
}

async function loadTopMitra(){
  const box = document.getElementById('topMitraBox');
  try {
    const r = await fetch(`/ERP/harpy/hq/droppoint.php?action=top_mitra&${params()}`);
    const d = await r.json();
    if (d.error){ box.innerHTML = `<div class="empty">⚠️ ${esc(d.error)}</div>`; return; }
    if (!d.rows.length){ box.innerHTML = '<div class="empty">Belum ada mitra aktif.</div>'; return; }
    let html = '<div style="overflow-x:auto"><table class="tbl"><thead><tr><th>#</th><th>Mitra</th><th>Outlet</th><th style="text-align:right">Order</th><th style="text-align:right">Kg</th><th style="text-align:right">Omset</th><th style="text-align:right">Komisi</th></tr></thead><tbody>';
    d.rows.forEach((r,i) => {
      const medal = i===0?'🥇':i===1?'🥈':i===2?'🥉':(i+1);
      html += `<tr>
        <td class="medal">${medal}</td>
        <td><strong>${esc(r.nama_mitra)}</strong></td>
        <td>${esc(r.nama_outlet)}</td>
        <td class="num">${Number(r.total_order).toLocaleString('id-ID')}</td>
        <td class="num">${Number(r.total_kg).toFixed(1)}</td>
        <td class="num">${fmtRp(r.total_omset)}</td>
        <td class="num">${fmtRp(r.total_komisi)}</td>
      </tr>`;
    });
    html += '</tbody></table></div>';
    box.innerHTML = html;
  } catch(e){ box.innerHTML = `<div class="empty">⚠️ ${esc(e.message)}</div>`; }
}

function loadAll(){ loadPerOutlet(); loadTopMitra(); }
loadAll();
</script>

<?php require __DIR__ . '/_layout_close.php'; ?>
