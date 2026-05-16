<?php
// ══════════════════════════════════════════════════════
// hq/laporan.php — Laporan Konsolidasi Lintas Outlet (HQ View)
// Brief HQ-Outlet Section 4.5
//
// Fitur:
//   - Filter periode (custom date range, preset bulan/minggu)
//   - Omset total + breakdown per outlet (chart bar + tabel)
//   - Top customer (top spender lintas outlet)
//   - Pelanggan baru per periode
//   - Drill-down ke laporan.php (outlet view) per outlet
// ══════════════════════════════════════════════════════

$activePage = 'hq-laporan';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];
$action = $_GET['action'] ?? '';

// Default periode: bulan berjalan
$defaultStart = date('Y-m-01');
$defaultEnd   = date('Y-m-d');

if ($action === 'data') {
    header('Content-Type: application/json');
    $start = $_GET['start'] ?? $defaultStart;
    $end   = $_GET['end']   ?? $defaultEnd;

    // Sanitize date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = $defaultStart;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = $defaultEnd;

    // ── Ringkasan total ─────────────────────────────────
    $sum = $db->prepare(
        "SELECT COUNT(*) AS total_order,
                COALESCE(SUM(total),0) AS omset,
                COALESCE(SUM(CASE WHEN status_bayar='lunas' THEN total ELSE 0 END),0) AS lunas,
                COALESCE(SUM(CASE WHEN status_bayar!='lunas' THEN total - COALESCE(dp,0) ELSE 0 END),0) AS piutang
           FROM hl_transaksi
          WHERE tenant_id=? AND DATE(tanggal) BETWEEN ? AND ?"
    );
    $sum->execute([$tid, $start, $end]);
    $summary = $sum->fetch() ?: ['total_order'=>0,'omset'=>0,'lunas'=>0,'piutang'=>0];

    // ── Breakdown per outlet ────────────────────────────
    $outletsStmt = $db->prepare(
        "SELECT id, nama_outlet, status FROM outlets
          WHERE tenant_id=? AND status IN ('trial','grace','active')
          ORDER BY is_main DESC, nama_outlet ASC"
    );
    $outletsStmt->execute([$tid]);
    $outlets = $outletsStmt->fetchAll();

    foreach ($outlets as &$o) {
        $oid = (int)$o['id'];
        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*) AS c, COALESCE(SUM(total),0) AS s
                   FROM hl_transaksi
                  WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal) BETWEEN ? AND ?"
            );
            $stmt->execute([$tid, $oid, $start, $end]);
            $r = $stmt->fetch();
            $o['order_count'] = (int)$r['c'];
            $o['omset']       = (int)$r['s'];
        } catch (Throwable) {
            $o['order_count'] = 0; $o['omset'] = 0;
        }
    }
    unset($o);

    // ── Chart data: omset per hari (timeline) ───────────
    $timeline = [];
    try {
        $stmt = $db->prepare(
            "SELECT DATE(tanggal) AS d, COALESCE(SUM(total),0) AS s
               FROM hl_transaksi
              WHERE tenant_id=? AND DATE(tanggal) BETWEEN ? AND ?
              GROUP BY DATE(tanggal)
              ORDER BY d ASC"
        );
        $stmt->execute([$tid, $start, $end]);
        $timeline = $stmt->fetchAll();
    } catch (Throwable) {}

    // ── Top customers (lintas outlet) ───────────────────
    $topCust = [];
    try {
        $stmt = $db->prepare(
            "SELECT t.pelanggan_id,
                    p.nama, p.telepon,
                    COUNT(*) AS order_count,
                    COALESCE(SUM(t.total),0) AS total_spend
               FROM hl_transaksi t
               LEFT JOIN hl_pelanggan p ON p.id = t.pelanggan_id
              WHERE t.tenant_id=? AND DATE(t.tanggal) BETWEEN ? AND ? AND t.pelanggan_id IS NOT NULL
              GROUP BY t.pelanggan_id, p.nama, p.telepon
              ORDER BY total_spend DESC LIMIT 10"
        );
        $stmt->execute([$tid, $start, $end]);
        $topCust = $stmt->fetchAll();
    } catch (Throwable $e) { error_log('[hq laporan topCust] '.$e->getMessage()); }

    // ── Pelanggan baru di periode ───────────────────────
    $newCustomers = 0;
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM hl_pelanggan
              WHERE tenant_id=? AND DATE(created_at) BETWEEN ? AND ?"
        );
        $stmt->execute([$tid, $start, $end]);
        $newCustomers = (int)$stmt->fetchColumn();
    } catch (Throwable) {}

    echo json_encode([
        'summary'      => $summary,
        'per_outlet'   => $outlets,
        'timeline'     => $timeline,
        'top_customers'=> $topCust,
        'new_customers'=> $newCustomers,
        'periode'      => ['start'=>$start, 'end'=>$end],
    ]);
    exit;
}

$ownerNama  = $hqUser['nama'] ?? 'Owner';
$tenantNama = $hqTenant['nama_outlet'] ?? 'HQ';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HQ Laporan Konsolidasi — LAMASY</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',system-ui,sans-serif;background:#F4F7FB;color:#0F1C3A;min-height:100vh}
  .hq-topbar{background:#0F1C3A;color:#fff;padding:14px 24px;display:flex;justify-content:space-between;
             align-items:center;flex-wrap:wrap;gap:12px;box-shadow:0 1px 8px rgba(0,0,0,.15)}
  .hq-brand{display:flex;align-items:center;gap:10px;font-size:16px;font-weight:700;color:#35E8D5}
  .hq-brand-sub{color:rgba(255,255,255,.5);font-size:11px;font-weight:400;margin-left:4px}
  .hq-badge{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;font-size:10px;font-weight:800;
            padding:3px 10px;border-radius:100px;letter-spacing:.06em}
  .hq-topbar-right{display:flex;align-items:center;gap:14px;font-size:13px;color:rgba(255,255,255,.85)}
  .hq-topbar a{color:rgba(255,255,255,.55);text-decoration:none;font-size:13px;padding:6px 10px;border-radius:6px}
  .hq-topbar a:hover{background:rgba(255,255,255,.08);color:#fff}
  .hq-topbar a.active{background:rgba(53,232,213,.15);color:#35E8D5}
  .hq-logout{border:1px solid rgba(255,255,255,.15);padding:6px 14px!important}

  .container{max-width:1280px;margin:24px auto;padding:0 20px 60px}
  h1{font-size:1.4rem;font-weight:800;color:#0F1C3A;margin-bottom:14px}
  h1 small{display:block;font-size:13px;font-weight:400;color:#6B7280;margin-top:2px}

  .filter-bar{background:#fff;border-radius:12px;padding:14px 18px;display:flex;gap:10px;
              flex-wrap:wrap;margin-bottom:16px;box-shadow:0 1px 6px rgba(0,0,0,.05);align-items:center}
  .filter-bar label{font-size:12px;color:#6B7280;font-weight:600;display:flex;align-items:center;gap:6px}
  .filter-bar input[type=date]{padding:7px 10px;border:1.5px solid #E5E7EB;border-radius:6px;font-size:13px;font-family:inherit}
  .preset-btn{padding:7px 12px;background:#F3F4F6;border:1.5px solid transparent;
              border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;color:#374151}
  .preset-btn.active{background:#0F1C3A;color:#fff}

  .metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
  .metric{background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 6px rgba(0,0,0,.05);
          border-top:3px solid #35E8D5}
  .metric.green{border-top-color:#34D399}.metric.purple{border-top-color:#8B5CF6}
  .metric.orange{border-top-color:#F59E0B}.metric.red{border-top-color:#EF4444}
  .metric-num{font-size:1.5rem;font-weight:800;color:#0F1C3A;font-family:monospace;margin-bottom:2px}
  .metric-label{font-size:12px;color:#6B7280;font-weight:600}

  .grid-2{display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:20px}
  .panel{background:#fff;border-radius:12px;padding:22px;box-shadow:0 1px 6px rgba(0,0,0,.05)}
  .panel-title{font-size:14px;font-weight:700;color:#0F1C3A;margin-bottom:14px;display:flex;align-items:center;gap:6px}

  .outlet-row{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:12px;align-items:center;
              padding:10px 0;border-bottom:1px solid #F3F4F6;font-size:13px}
  .outlet-row:last-child{border-bottom:none}
  .outlet-name{font-weight:700;color:#0F1C3A}
  .outlet-bar{height:6px;background:#F3F4F6;border-radius:100px;overflow:hidden;margin-top:5px}
  .outlet-bar > div{height:100%;background:linear-gradient(90deg,#35E8D5,#0891B2);border-radius:100px;transition:width .4s}
  .outlet-money{font-family:monospace;font-weight:700;text-align:right;color:#0F1C3A}
  .outlet-money small{display:block;font-weight:400;color:#9CA3AF;font-size:11px}
  .btn-light{padding:6px 11px;background:#F3F4F6;border:none;border-radius:6px;font-size:11px;
             font-weight:700;color:#0F1C3A;text-decoration:none}
  .btn-light:hover{background:#E5E7EB}

  .top-row{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid #F3F4F6;font-size:13px;align-items:center}
  .top-row:last-child{border-bottom:none}
  .top-row .rank{background:#0F1C3A;color:#fff;width:20px;height:20px;border-radius:50%;
                 display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;margin-right:8px}
  .top-row .rank.r1{background:#F59E0B}.top-row .rank.r2{background:#94A3B8}.top-row .rank.r3{background:#D97706}
  .top-row .name{font-weight:600;color:#0F1C3A}
  .top-row .name small{display:block;color:#9CA3AF;font-weight:400;font-size:11px;margin-top:1px}
  .top-row .amt{font-family:monospace;font-weight:700;color:#0F1C3A}

  .chart-box{height:280px;position:relative}

  @media(max-width:900px){.metrics{grid-template-columns:repeat(2,1fr)}.grid-2{grid-template-columns:1fr}}
</style>
</head>
<body>

<div class="hq-topbar">
  <div class="hq-brand">
    <img src="/ERP/harpy/assets/logo.png" alt="LAMASY" style="height:28px">
    LAMASY <span class="hq-brand-sub">by Harpy</span>
    <span class="hq-badge">🏢 HQ</span>
  </div>
  <div class="hq-topbar-right">
    <a href="/ERP/harpy/dashboard.php?to=hq">📊 Dashboard</a>
    <a href="/ERP/harpy/hq/karyawan.php">👥 Karyawan</a>
    <a href="/ERP/harpy/hq/pelanggan.php">🧑‍🤝‍🧑 Pelanggan</a>
    <a href="/ERP/harpy/hq/laporan.php" class="active">📈 Laporan</a>
    <span><?= htmlspecialchars($ownerNama) ?></span>
    <a href="/ERP/harpy/dashboard.php?to=outlet">← Outlet View</a>
    <a href="/ERP/harpy/logout.php" class="hq-logout" onclick="return confirm('Yakin logout?')">Logout</a>
  </div>
</div>

<div class="container">
  <h1>📈 Laporan Konsolidasi
    <small>Lintas outlet · <?= htmlspecialchars($tenantNama) ?></small>
  </h1>

  <div class="filter-bar">
    <label>📅 Dari <input type="date" id="dStart" value="<?= $defaultStart ?>"></label>
    <label>sampai <input type="date" id="dEnd" value="<?= $defaultEnd ?>"></label>
    <button class="preset-btn" onclick="setPreset('today')">Hari Ini</button>
    <button class="preset-btn" onclick="setPreset('week')">7 Hari</button>
    <button class="preset-btn active" onclick="setPreset('month')">Bulan Ini</button>
    <button class="preset-btn" onclick="setPreset('30d')">30 Hari</button>
    <button class="btn-light" onclick="loadData()" style="margin-left:auto;cursor:pointer;border:none;font-family:inherit">↻ Refresh</button>
  </div>

  <div class="metrics">
    <div class="metric"><div class="metric-num" id="mOmset">-</div><div class="metric-label">Omset Total</div></div>
    <div class="metric green"><div class="metric-num" id="mOrder">-</div><div class="metric-label">Total Order</div></div>
    <div class="metric red"><div class="metric-num" id="mPiutang">-</div><div class="metric-label">Piutang</div></div>
    <div class="metric purple"><div class="metric-num" id="mNew">-</div><div class="metric-label">Pelanggan Baru</div></div>
  </div>

  <div class="grid-2">
    <div class="panel">
      <div class="panel-title">📊 Tren Omset Harian</div>
      <div class="chart-box"><canvas id="chartTimeline"></canvas></div>
    </div>
    <div class="panel">
      <div class="panel-title">🏆 Top Pelanggan</div>
      <div id="topCustList"><div style="color:#9CA3AF;font-size:12px">Memuat…</div></div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-title">📍 Breakdown per Outlet</div>
    <div id="outletBreakdown"><div style="color:#9CA3AF;font-size:12px">Memuat…</div></div>
  </div>
</div>

<script>
let chartT = null;
function escapeHtml(s){return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function fmtRp(n){return 'Rp ' + Number(n||0).toLocaleString('id-ID')}
function fmtShort(n){
  n = Number(n||0);
  if (n >= 1e9) return (n/1e9).toFixed(1)+'M';
  if (n >= 1e6) return (n/1e6).toFixed(1)+'jt';
  if (n >= 1e3) return (n/1e3).toFixed(0)+'rb';
  return n;
}

function setPreset(p){
  document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
  event.target.classList.add('active');
  const today = new Date();
  const fmt = d => d.toISOString().slice(0,10);
  let start, end = fmt(today);
  if (p === 'today')      start = fmt(today);
  else if (p === 'week')  { const w = new Date(today); w.setDate(w.getDate()-6); start = fmt(w); }
  else if (p === 'month') start = today.getFullYear()+'-'+String(today.getMonth()+1).padStart(2,'0')+'-01';
  else if (p === '30d')   { const w = new Date(today); w.setDate(w.getDate()-29); start = fmt(w); }
  document.getElementById('dStart').value = start;
  document.getElementById('dEnd').value = end;
  loadData();
}

async function loadData(){
  const start = document.getElementById('dStart').value;
  const end   = document.getElementById('dEnd').value;
  const r = await fetch(`hq/laporan.php?action=data&start=${start}&end=${end}`);
  const d = await r.json();

  // Metrics
  document.getElementById('mOmset').textContent = fmtRp(d.summary.omset);
  document.getElementById('mOrder').textContent = Number(d.summary.total_order).toLocaleString('id-ID');
  document.getElementById('mPiutang').textContent = fmtRp(d.summary.piutang);
  document.getElementById('mNew').textContent = d.new_customers;

  // Outlet breakdown
  const maxOmset = Math.max(1, ...d.per_outlet.map(o => o.omset));
  document.getElementById('outletBreakdown').innerHTML = d.per_outlet.length === 0
    ? '<div style="color:#9CA3AF;font-size:13px;text-align:center;padding:20px">Belum ada outlet</div>'
    : d.per_outlet.map(o => `
        <div class="outlet-row">
          <div>
            <div class="outlet-name">📍 ${escapeHtml(o.nama_outlet)}
              <span style="font-size:9px;font-weight:700;padding:1px 6px;border-radius:100px;
                          background:${o.status==='trial'?'#DBEAFE':o.status==='grace'?'#FEF3C7':'#D1FAE5'};
                          color:${o.status==='trial'?'#1E40AF':o.status==='grace'?'#92400E':'#065F46'};
                          margin-left:5px;text-transform:uppercase">${o.status}</span>
            </div>
            <div class="outlet-bar"><div style="width:${(o.omset/maxOmset*100).toFixed(1)}%"></div></div>
          </div>
          <div style="text-align:right;font-size:12px;color:#6B7280">${o.order_count} order</div>
          <div class="outlet-money">${fmtRp(o.omset)}<small>omset</small></div>
          <div>
            <a href="/ERP/harpy/switch-outlet.php?id=${o.id}" class="btn-light">Masuk →</a>
          </div>
        </div>
      `).join('');

  // Top customers
  document.getElementById('topCustList').innerHTML = d.top_customers.length === 0
    ? '<div style="color:#9CA3AF;font-size:13px;text-align:center;padding:20px">Belum ada data</div>'
    : d.top_customers.map((c, i) => `
        <div class="top-row">
          <div style="display:flex;align-items:center">
            <div class="rank ${i<3?'r'+(i+1):''}">${i+1}</div>
            <div class="name">${escapeHtml(c.nama || '(tanpa nama)')}<small>${c.order_count} order${c.telepon ? ' · '+escapeHtml(c.telepon) : ''}</small></div>
          </div>
          <div class="amt">${fmtRp(c.total_spend)}</div>
        </div>
      `).join('');

  // Chart timeline
  const labels = d.timeline.map(t => new Date(t.d).toLocaleDateString('id-ID',{day:'2-digit',month:'short'}));
  const values = d.timeline.map(t => Number(t.s));
  if (chartT) chartT.destroy();
  const ctx = document.getElementById('chartTimeline').getContext('2d');
  chartT = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: 'Omset',
        data: values,
        borderColor: '#35E8D5',
        backgroundColor: 'rgba(53,232,213,.15)',
        tension: 0.3,
        fill: true,
        pointRadius: 3,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => fmtRp(c.parsed.y) } } },
      scales: {
        y: { ticks: { callback: v => 'Rp ' + fmtShort(v) } },
        x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
      }
    }
  });
}
loadData();
</script>
</body>
</html>
