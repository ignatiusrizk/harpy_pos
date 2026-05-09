<?php
$activePage = 'dashboard';
require_once 'auth.php';
require_once 'components.php';
requireLogin();
$user = currentUser();
if (isset($_GET['logout'])) doLogout();

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $pdo = getDB();
    $today = date('Y-m-d');

    // STATS HARIAN
    if ($action === 'stats') {
        $isStaff = ($user['role'] === 'staff');

        // Order hari ini — staff hanya lihat order milik sendiri
        if ($isStaff) {
            $order = $pdo->prepare("SELECT
                COUNT(*) as total_order,
                COALESCE(SUM(total),0) as omset,
                COALESCE(SUM(dp),0) as terkumpul,
                SUM(CASE WHEN status_bayar!='lunas' THEN 1 ELSE 0 END) as belum_lunas,
                SUM(CASE WHEN status_proses='siap' THEN 1 ELSE 0 END) as siap_diambil
                FROM hl_transaksi WHERE DATE(tanggal)=? AND created_by=?");
            $order->execute([$today, $user['id']]);
        } else {
            $order = $pdo->prepare("SELECT
                COUNT(*) as total_order,
                COALESCE(SUM(total),0) as omset,
                COALESCE(SUM(dp),0) as terkumpul,
                SUM(CASE WHEN status_bayar!='lunas' THEN 1 ELSE 0 END) as belum_lunas,
                SUM(CASE WHEN status_proses='siap' THEN 1 ELSE 0 END) as siap_diambil
                FROM hl_transaksi WHERE DATE(tanggal)=?");
            $order->execute([$today]);
        }
        $orderData = $order->fetch();

        // Kas hari ini — hanya admin/owner
        $kasData = ['masuk'=>0,'keluar'=>0];
        if (!$isStaff) {
            $kas = $pdo->prepare("SELECT
                COALESCE(SUM(CASE WHEN tipe='masuk' THEN jumlah END),0) as masuk,
                COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah END),0) as keluar
                FROM hl_kas WHERE tanggal=?");
            $kas->execute([$today]);
            $kasData = $kas->fetch();
        }

        // Total order aktif
        $aktif = $pdo->query("SELECT COUNT(*) FROM hl_transaksi WHERE status_proses != 'diambil'")->fetchColumn();

        // Absensi hari ini — staff hanya lihat diri sendiri
        if ($isStaff) {
            $hadirStmt = $pdo->prepare("SELECT jam_masuk, jam_keluar, status FROM hl_absensi WHERE user_id=? AND tanggal=?");
            $hadirStmt->execute([$user['id'], $today]);
            $absensiSelf = $hadirStmt->fetch();
            $hadir = $absensiSelf ? ($absensiSelf['jam_masuk'] ? 1 : 0) : 0;
        } else {
            $hadir = $pdo->query("SELECT COUNT(*) FROM hl_absensi WHERE tanggal='$today' AND status='hadir'")->fetchColumn();
        }

        echo json_encode([
            'order'    => $orderData,
            'kas'      => $kasData,
            'aktif'    => $aktif,
            'hadir'    => $hadir,
            'saldo'    => floatval($kasData['masuk']) - floatval($kasData['keluar']),
            'is_staff' => $isStaff,
            'role'     => $user['role'],
        ]); exit;
    }

    // ORDER ALERTS — siap diambil & mendekati estimasi & belum bayar
    if ($action === 'alerts') {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        // Pastikan kolom metode_bayar ada
    try { $pdo->exec("ALTER TABLE hl_pelanggan ADD COLUMN metode_bayar ENUM('langsung','bulanan') DEFAULT 'langsung'"); } catch(Exception $e) {}

    // Siap diambil
        $siap = $pdo->query("SELECT no_order, nama_pelanggan, telepon, estimasi_selesai,
            total, sisa_bayar, status_bayar, updated_at
            FROM hl_transaksi
            WHERE status_proses='siap'
            ORDER BY updated_at DESC LIMIT 20")->fetchAll();

        // Estimasi selesai hari ini atau besok (belum siap)
        $mepet = $pdo->query("SELECT no_order, nama_pelanggan, telepon, estimasi_selesai,
            total, sisa_bayar, status_bayar, status_proses
            FROM hl_transaksi
            WHERE estimasi_selesai <= '$tomorrow'
            AND status_proses NOT IN ('siap','diambil')
            ORDER BY estimasi_selesai ASC LIMIT 20")->fetchAll();

        // Belum bayar > 3 hari — exclude customer dengan metode bulanan
        $piutang = $pdo->query("SELECT t.no_order, t.nama_pelanggan, t.telepon, t.tanggal,
            t.total, t.sisa_bayar, t.status_proses,
            DATEDIFF(CURDATE(), t.tanggal) as hari_lalu
            FROM hl_transaksi t
            LEFT JOIN hl_pelanggan p ON p.id = t.pelanggan_id
            WHERE t.status_bayar != 'lunas'
            AND t.tanggal <= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
            AND t.status_proses != 'diambil'
            AND (p.metode_bayar IS NULL OR p.metode_bayar = 'langsung')
            ORDER BY t.tanggal ASC LIMIT 20")->fetchAll();

        echo json_encode([
            'siap'    => $siap,
            'mepet'   => $mepet,
            'piutang' => $piutang,
        ]); exit;
    }

    // Pipeline — pastikan ada index
    if ($action === 'pipeline') {
        try { $pdo->exec("ALTER TABLE hl_transaksi ADD INDEX idx_status_proses (status_proses)"); } catch(Exception $e) {}
        $rows = $pdo->query("SELECT status_proses, COUNT(*) as count
            FROM hl_transaksi WHERE status_proses != 'diambil'
            GROUP BY status_proses")->fetchAll();
        $map = [];
        foreach ($rows as $r) $map[$r['status_proses']] = $r['count'];
        echo json_encode($map); exit;
    }

    // OMSET 7 HARI
    if ($action === 'chart7') {
        $rows = $pdo->query("SELECT DATE(tanggal) as tgl,
            COALESCE(SUM(total),0) as omset,
            COUNT(*) as order_count
            FROM hl_transaksi
            WHERE tanggal >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            GROUP BY DATE(tanggal) ORDER BY tgl")->fetchAll();
        echo json_encode($rows); exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Dashboard'); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
/* STAT CARDS */
.dash-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 20px; }
.dash-card {
  background: var(--white); border-radius: var(--r-lg);
  border: 1px solid rgba(27,45,90,.07); box-shadow: var(--shadow);
  padding: 20px; position: relative; overflow: hidden;
}
.dash-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
.dash-card.teal::before   { background: linear-gradient(90deg,var(--teal),var(--teal-d)); }
.dash-card.green::before  { background: linear-gradient(90deg,var(--green),#34D399); }
.dash-card.red::before    { background: linear-gradient(90deg,var(--red),#F87171); }
.dash-card.navy::before   { background: linear-gradient(90deg,var(--navy),#2D4A8A); }
.dash-card.purple::before { background: linear-gradient(90deg,var(--purple),#A78BFA); }
.dash-card.yellow::before { background: linear-gradient(90deg,var(--yellow),#FCD34D); }
.dash-num   { font-size: 1.6rem; font-weight: 900; color: var(--navy); font-family: var(--mono); line-height: 1; margin-bottom: 4px; }
.dash-label { font-size: 12px; color: var(--gray); font-weight: 500; }
.dash-sub   { font-size: 11px; color: var(--gray); margin-top: 6px; }

/* PIPELINE */
.pipeline { display: flex; gap: 8px; margin-bottom: 20px; }
.pipe-item {
  flex: 1; background: var(--white); border-radius: var(--r);
  padding: 12px 14px; border: 1px solid rgba(27,45,90,.07);
  box-shadow: var(--shadow); text-align: center;
}
.pipe-num   { font-size: 1.4rem; font-weight: 800; color: var(--navy); font-family: var(--mono); }
.pipe-label { font-size: 11px; color: var(--gray); margin-top: 3px; }
.pipe-item.active { border-color: var(--teal); background: var(--teal-bg); }
.pipe-item.active .pipe-num { color: var(--teal-d); }

/* ALERT CARDS */
.alert-section { margin-bottom: 18px; }
.alert-header  {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 10px;
}
.alert-title   { font-size: 13px; font-weight: 700; color: var(--navy); display: flex; align-items: center; gap: 8px; }
.alert-badge   { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 100px; }
.alert-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 14px; background: var(--white);
  border-radius: var(--r); border: 1px solid rgba(27,45,90,.07);
  margin-bottom: 6px; gap: 12px; transition: all .15s;
}
.alert-row:hover { box-shadow: var(--shadow); border-color: rgba(27,45,90,.14); }
.alert-no    { font-family: var(--mono); font-size: 12px; font-weight: 700; color: var(--teal-d); white-space: nowrap; }
.alert-nama  { font-size: 14px; font-weight: 600; color: var(--navy); }
.alert-meta  { font-size: 12px; color: var(--gray); }
.alert-wa    {
  padding: 5px 10px; background: #25D366; color: white;
  border: none; border-radius: 8px; font-size: 12px; font-weight: 600;
  cursor: pointer; white-space: nowrap; text-decoration: none;
}

/* CHART */
.chart-wrap { position: relative; height: 200px; }
@keyframes spin { from{transform:rotate(0deg)} to{transform:rotate(360deg)} }

/* ── RESPONSIVE — TABLET (≤ 900px) ── */
@media(max-width:900px) {
  .dash-grid { grid-template-columns: repeat(2,1fr); }
  .pipeline  { flex-wrap: wrap; }
  .pipe-item { flex: 1 1 calc(33% - 8px); min-width: 80px; }
}

/* ── RESPONSIVE — MOBILE (≤ 680px) ── */
@media(max-width:680px) {
  /* Stat cards */
  .dash-grid { grid-template-columns: repeat(2,1fr); gap: 10px; }
  .dash-card { padding: 12px; }
  .dash-num  { font-size: 1.1rem; }
  .dash-label{ font-size: 11px; }
  .dash-sub  { font-size: 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }

  /* Pipeline */
  .pipeline  { gap: 5px; overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; -webkit-overflow-scrolling: touch; }
  .pipe-item { flex: 0 0 auto; min-width: 62px; padding: 9px 7px; }
  .pipe-num  { font-size: 1rem; }
  .pipe-label{ font-size: 10px; }

  /* Alert rows — tombol wrap ke bawah seperti pos/orders */
  .alert-row        { padding: 9px 10px; gap: 6px; flex-wrap: wrap; }
  .alert-row > div:first-child  { flex: 1 1 100% !important; min-width: 0; }
  .alert-row > div:last-child   { width: 100%; display: flex !important; justify-content: flex-end; gap: 6px; }
  .alert-nama{ font-size: 13px; }
  .alert-meta{ font-size: 11px; word-break: break-word; }
  .alert-no  { font-size: 11px; }
  .alert-wa  { padding: 6px 12px; font-size: 12px; }

  /* Chart */
  .chart-wrap{ height: 150px; }
}

/* ── RESPONSIVE — SMALL MOBILE (≤ 420px) ── */
@media(max-width:420px) {
  .dash-grid { gap: 8px; }
  .dash-card { padding: 10px; }
  .dash-num  { font-size: 1rem; }
  .pipeline  { gap: 4px; }
  .pipe-item { min-width: 56px; padding: 8px 5px; }
}
</style>
</head>
<body>
<?php renderTopbar('dashboard'); ?>
<div class="hl-main" style="max-width:1400px;width:100%">

  <!-- GREETING + AI BRIEFING -->
  <div style="margin-bottom:20px;display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap;min-width:0">
    <div>
      <h1 style="font-size:1.3rem;font-weight:800;color:var(--navy)" id="greeting">Selamat pagi!</h1>
      <p style="font-size:13px;color:var(--gray)" id="dashDate">--</p>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <div id="aiBriefingBadge" style="display:none;background:linear-gradient(135deg,#667eea,#764ba2);color:white;font-size:12px;font-weight:600;padding:6px 14px;border-radius:100px;cursor:pointer<?= $user['role']==='staff' ? ';display:none!important' : '' ?>" onclick="toggleBriefing()">
        ✨ AI Briefing
      </div>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadAll()">↻ Refresh</button>
    </div>
  </div>

  <!-- AI BRIEFING PANEL -->
  <div id="aiBriefingPanel" style="display:none;margin-bottom:20px">
    <div class="hl-card" style="border:2px solid rgba(139,92,246,.2);background:linear-gradient(135deg,#FAFAFA,#F5F3FF)">
      <div class="hl-card-header" style="border-bottom:1px solid rgba(139,92,246,.1)">
        <div class="hl-card-title" style="display:flex;align-items:center;gap:8px">
          <span style="background:linear-gradient(135deg,#667eea,#764ba2);color:white;font-size:10px;font-weight:800;padding:3px 8px;border-radius:100px;letter-spacing:.06em">AI</span>
          Briefing Harian
        </div>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadBriefing()" id="btnBriefingRefresh">↻</button>
      </div>
      <div class="hl-card-body" id="aiBriefingContent">
        <div class="hl-loading">⏳ AI sedang menganalisis data hari ini...</div>
      </div>
    </div>
  </div>

  <!-- STAT CARDS -->
  <div class="dash-grid">
    <div class="dash-card teal">
      <div class="dash-num" id="dOmset">-</div>
      <div class="dash-label">Omset Hari Ini</div>
      <div class="dash-sub" id="dTerkumpul">Terkumpul: -</div>
    </div>
    <div class="dash-card green">
      <div class="dash-num" id="dOrder">-</div>
      <div class="dash-label">Order Masuk Hari Ini</div>
      <div class="dash-sub" id="dAktif">Aktif: - order</div>
    </div>
    <div class="dash-card navy" id="dashKasCard">
      <div class="dash-num" id="dSaldo">-</div>
      <div class="dash-label">Saldo Kas Hari Ini</div>
      <div class="dash-sub" id="dKasSub">Masuk: - / Keluar: -</div>
    </div>
    <div class="dash-card purple">
      <div class="dash-num" id="dHadir">-</div>
      <div class="dash-label">Karyawan Hadir</div>
      <div class="dash-sub" id="dSiap">Siap diambil: - order</div>
    </div>
  </div>

  <!-- PIPELINE ORDER -->
  <div class="hl-card" style="margin-bottom:20px">
    <div class="hl-card-header">
      <div class="hl-card-title">Pipeline Order Aktif</div>
      <span id="pipeTotal" style="font-size:12px;color:var(--gray)"></span>
    </div>
    <div class="hl-card-body" style="padding:14px">
      <div class="pipeline" id="pipeline">
        <div class="hl-loading">⏳</div>
      </div>
    </div>
  </div>

  <div class="hl-grid-2" style="gap:20px">

    <!-- KIRI: ALERTS -->
    <div>

      <!-- SIAP DIAMBIL -->
      <div class="hl-card alert-section">
        <div class="hl-card-header">
          <div class="alert-title">
            Siap Diambil
            <span class="alert-badge" id="badgeSiap" style="background:#D1FAE5;color:#065F46">0</span>
          </div>
          <a href="orders.php?status=siap" style="font-size:12px;color:var(--teal);text-decoration:none">Lihat semua</a>
        </div>
        <div class="hl-card-body" style="padding:12px" id="listSiap">
          <div class="hl-loading">⏳</div>
        </div>
      </div>

      <!-- MENDEKATI ESTIMASI -->
      <div class="hl-card alert-section">
        <div class="hl-card-header">
          <div class="alert-title">
            Harus Selesai Hari Ini / Besok
            <span class="alert-badge" id="badgeMepet" style="background:#FEF3C7;color:#92400E">0</span>
          </div>
        </div>
        <div class="hl-card-body" style="padding:12px" id="listMepet">
          <div class="hl-loading">⏳</div>
        </div>
      </div>

    </div>

    <!-- KANAN: CHART + PIUTANG -->
    <div>

      <!-- CHART 7 HARI -->
      <div class="hl-card" style="margin-bottom:18px">
        <div class="hl-card-header">
          <div class="hl-card-title">Omset 7 Hari Terakhir</div>
        </div>
        <div class="hl-card-body">
          <div class="chart-wrap"><canvas id="chartOmset"></canvas></div>
        </div>
      </div>

      <!-- PIUTANG -->
      <div class="hl-card">
        <div class="hl-card-header">
          <div class="alert-title">
            Belum Bayar (&gt; 3 Hari)
            <span class="alert-badge" id="badgePiutang" style="background:#FEE2E2;color:#991B1B">0</span>
          </div>
          <a href="orders.php?bayar=belum" style="font-size:12px;color:var(--teal);text-decoration:none">Lihat semua</a>
        </div>
        <div class="hl-card-body" style="padding:12px" id="listPiutang">
          <div class="hl-loading">⏳</div>
        </div>
      </div>

    </div>
  </div>
</div>

<?php renderToast(); ?>
<script>
let chartInstance = null;


// Helper: ambil tanggal lokal (bukan UTC)
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
  // Greeting & tanggal
  const now  = new Date();
  const hour = now.getHours();
  const greet = hour < 11 ? 'Selamat pagi' : hour < 15 ? 'Selamat siang' : hour < 18 ? 'Selamat sore' : 'Selamat malam';
  document.getElementById('greeting').textContent = greet + ', <?= htmlspecialchars($user['nama']) ?>!';
  document.getElementById('dashDate').textContent = now.toLocaleDateString('id-ID',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
  // Badge AI langsung muncul — briefing load saat diklik
  document.getElementById('aiBriefingBadge').style.display = 'block';
  loadAll();
});

async function loadAll() {
  loadStats();
  loadAlerts();
  loadPipeline();
  loadChart();
  // AI briefing TIDAK auto-load — user klik sendiri
}

// ── AI BRIEFING ───────────────────────────────────────
let briefingLoaded = false;
let briefingVisible = false;

function toggleBriefing() {
  briefingVisible = !briefingVisible;
  document.getElementById('aiBriefingPanel').style.display = briefingVisible ? 'block' : 'none';
  if (briefingVisible && !briefingLoaded) loadBriefing();
}

async function loadBriefing() {
  const btn = document.getElementById('btnBriefingRefresh');
  if (btn) { btn.disabled = true; btn.textContent = '⏳'; }
  document.getElementById('aiBriefingContent').innerHTML =
    '<div class="hl-loading">⏳ AI sedang menganalisis data hari ini...</div>';

  try {
    const r = await fetch('ai.php?action=briefing');
    const d = await r.json();

    if (d.error) {
      document.getElementById('aiBriefingContent').innerHTML =
        `<div style="color:var(--red);font-size:13px">❌ ${d.error}</div>`;
      return;
    }

    const data = d.data;
    const kondisiColor = { baik:'var(--green)', waspada:'var(--yellow)', kritis:'var(--red)' }[data.kondisi] || 'var(--gray)';
    const kondisiIcon  = { baik:'✅', waspada:'⚠️', kritis:'🚨' }[data.kondisi] || '📊';

    document.getElementById('aiBriefingContent').innerHTML = `
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
        <span style="font-size:1.4rem">${kondisiIcon}</span>
        <div>
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:${kondisiColor}">${data.kondisi?.toUpperCase()}</div>
          <div style="font-size:14px;color:var(--dark);font-weight:500">${esc(data.ringkasan)}</div>
        </div>
      </div>
      ${data.poin_penting?.length ? `
      <div style="margin-bottom:14px">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--gray);margin-bottom:8px">Yang perlu dilakukan hari ini:</div>
        ${data.poin_penting.map(p => `
          <div style="display:flex;gap:8px;align-items:flex-start;padding:7px 0;border-bottom:1px solid rgba(27,45,90,.06)">
            <span style="color:var(--teal);font-weight:700;flex-shrink:0">→</span>
            <span style="font-size:13px;color:var(--dark)">${esc(p)}</span>
          </div>`).join('')}
      </div>` : ''}
      ${data.peluang ? `
      <div style="background:linear-gradient(135deg,#D1FAE5,#A7F3D0);border-radius:var(--r);padding:10px 14px;font-size:13px;color:#065F46">
        💡 <strong>Peluang:</strong> ${esc(data.peluang)}
      </div>` : ''}
      <div style="font-size:11px;color:var(--gray);text-align:right;margin-top:10px">
        AI · ${new Date().toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'})}
      </div>`;

    briefingLoaded = true;
    document.getElementById('aiBriefingBadge').style.display = 'block';

  } catch(e) {
    document.getElementById('aiBriefingContent').innerHTML =
      `<div style="color:var(--red);font-size:13px">❌ ${e.message}</div>`;
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = '↻'; }
  }
}

// ── STATS ─────────────────────────────────────────────
async function loadStats() {
  const r = await fetch('dashboard.php?action=stats');
  const d = await r.json();
  const isStaff = d.is_staff;

  // Label sesuai role
  document.getElementById('dOmset').textContent     = 'Rp ' + parseFloat(d.order.omset||0).toLocaleString('id-ID');
  document.getElementById('dTerkumpul').textContent = isStaff
    ? 'Order saya hari ini: ' + (d.order.total_order||0)
    : 'Terkumpul: Rp ' + parseFloat(d.order.terkumpul||0).toLocaleString('id-ID');

  document.getElementById('dOrder').textContent  = (d.order.total_order||0) + ' order';
  document.getElementById('dAktif').textContent  = 'Aktif: ' + (d.aktif||0) + ' order';

  // Kas — sembunyikan untuk staff
  const kasCard = document.getElementById('dashKasCard');
  if (isStaff) {
    if (kasCard) kasCard.style.display = 'none';
    document.getElementById('dOmset').closest('.dash-card').querySelector('.dash-label').textContent =
      isStaff ? 'Order Saya Hari Ini' : 'Omset Hari Ini';
  } else {
    const saldo = parseFloat(d.saldo||0);
    document.getElementById('dSaldo').textContent  = 'Rp ' + saldo.toLocaleString('id-ID');
    document.getElementById('dSaldo').style.color  = saldo >= 0 ? 'var(--navy)' : 'var(--red)';
    document.getElementById('dKasSub').textContent =
      'Masuk: Rp ' + parseFloat(d.kas.masuk||0).toLocaleString('id-ID') +
      ' / Keluar: Rp ' + parseFloat(d.kas.keluar||0).toLocaleString('id-ID');
  }

  // Absensi — staff tampilkan status absensi sendiri
  if (isStaff) {
    document.getElementById('dHadir').textContent = d.hadir ? 'Sudah Clock In' : 'Belum Clock In';
    document.getElementById('dHadir').style.color = d.hadir ? 'var(--green)' : 'var(--red)';
  } else {
    document.getElementById('dHadir').textContent = (d.hadir||0) + ' orang';
  }

  document.getElementById('dSiap').textContent = 'Siap diambil: ' + (d.order.siap_diambil||0) + ' order';
}

// ── PIPELINE ──────────────────────────────────────────
async function loadPipeline() {
  const r = await fetch('dashboard.php?action=pipeline');
  const d = await r.json();

  const steps = [
    {key:'masuk',   label:'Diterima'},
    {key:'cuci',    label:'Cuci'},
    {key:'kering',  label:'Kering'},
    {key:'setrika', label:'Setrika'},
    {key:'siap',    label:'Siap Ambil'},
  ];

  const total = Object.values(d).reduce((s,v) => s + parseInt(v||0), 0);
  document.getElementById('pipeTotal').textContent = total + ' order aktif';

  document.getElementById('pipeline').innerHTML = steps.map(s => `
    <div class="pipe-item ${s.key==='siap'?'active':''}">
      <div class="pipe-num">${d[s.key]||0}</div>
      <div class="pipe-label">${s.label}</div>
    </div>`).join('') + '<div style="display:flex;align-items:center;padding:0 4px;color:var(--gray);font-size:18px">→</div>'.repeat(0);
}

// ── ALERTS ────────────────────────────────────────────
async function loadAlerts() {
  const r = await fetch('dashboard.php?action=alerts');
  const d = await r.json();

  // Siap diambil
  document.getElementById('badgeSiap').textContent = d.siap.length;
  document.getElementById('listSiap').innerHTML = d.siap.length
    ? d.siap.map(o => alertRow(o, 'siap')).join('')
    : '<div class="hl-empty" style="padding:16px">Tidak ada order yang siap diambil</div>';

  // Mepet estimasi
  document.getElementById('badgeMepet').textContent = d.mepet.length;
  document.getElementById('listMepet').innerHTML = d.mepet.length
    ? d.mepet.map(o => alertRow(o, 'mepet')).join('')
    : '<div class="hl-empty" style="padding:16px">Semua order on-track</div>';

  // Piutang
  document.getElementById('badgePiutang').textContent = d.piutang.length;
  document.getElementById('listPiutang').innerHTML = d.piutang.length
    ? d.piutang.map(o => alertRow(o, 'piutang')).join('')
    : '<div class="hl-empty" style="padding:16px">Tidak ada piutang tertunggak</div>';
}

function alertRow(o, tipe) {
  const phone = (o.telepon||'').replace(/[^0-9]/g,'').replace(/^0/,'62');
  const waMsg = tipe === 'siap'
    ? `Halo *${o.nama_pelanggan}*, laundry Anda order *${o.no_order}* sudah siap diambil di Harpy Laundry. Total: Rp ${parseFloat(o.total).toLocaleString('id-ID')}. Terima kasih!`
    : tipe === 'piutang'
    ? `Halo *${o.nama_pelanggan}*, mengingatkan pembayaran laundry order *${o.no_order}* sebesar Rp ${parseFloat(o.sisa_bayar).toLocaleString('id-ID')} belum lunas. Mohon segera dilunasi. Terima kasih!`
    : `Halo *${o.nama_pelanggan}*, laundry Anda order *${o.no_order}* sedang dalam proses dan dijadwalkan selesai ${fmtDate(o.estimasi_selesai)}. Terima kasih sudah mempercayai Harpy Laundry!`;

  const waUrl = phone ? 'https://wa.me/' + phone + '?text=' + encodeURIComponent(waMsg) : null;

  let badge = '';
  if (tipe === 'mepet') {
    const est  = new Date(o.estimasi_selesai + 'T00:00:00');
    const today = new Date(); today.setHours(0,0,0,0);
    const diff  = Math.round((est - today) / 86400000);
    badge = diff <= 0
      ? '<span class="hl-badge hl-badge-red" style="font-size:10px">Terlambat</span>'
      : '<span class="hl-badge hl-badge-dp" style="font-size:10px">Besok</span>';
  }
  if (tipe === 'piutang') {
    badge = `<span class="hl-badge hl-badge-red" style="font-size:10px">${o.hari_lalu} hari lalu</span>`;
  }

  return `<div class="alert-row">
    <div style="min-width:0;flex:1">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px">
        <span class="alert-no">${o.no_order}</span>
        ${badge}
      </div>
      <div class="alert-nama">${esc(o.nama_pelanggan)}</div>
      <div class="alert-meta">
        ${tipe==='siap' ? 'Sisa bayar: <strong>Rp ' + parseFloat(o.sisa_bayar||0).toLocaleString('id-ID') + '</strong>' : ''}
        ${tipe==='mepet' ? 'Est: ' + fmtDate(o.estimasi_selesai) + ' · Status: ' + statusLabel(o.status_proses) : ''}
        ${tipe==='piutang' ? 'Sisa: <strong style="color:var(--red)">Rp ' + parseFloat(o.sisa_bayar).toLocaleString('id-ID') + '</strong> · ' + fmtDate(o.tanggal) : ''}
      </div>
    </div>
    <div style="display:flex;gap:6px;align-items:center;flex-shrink:0">
      ${waUrl ? `<a href="${waUrl}" target="_blank" class="alert-wa">WA</a>` : ''}
      <a href="orders.php" class="hl-btn hl-btn-outline hl-btn-sm" style="font-size:11px">Detail</a>
    </div>
  </div>`;
}

// ── CHART 7 HARI ──────────────────────────────────────
async function loadChart() {
  const r = await fetch('dashboard.php?action=chart7');
  const d = await r.json();

  if (chartInstance) { chartInstance.destroy(); }

  // Fill missing days
  const days = [];
  for (let i = 6; i >= 0; i--) {
    const dt = new Date();
    dt.setDate(dt.getDate() - i);
    days.push(localDateStr(dt));
  }
  const dataMap = {};
  d.forEach(x => { dataMap[x.tgl] = { omset: parseFloat(x.omset), count: parseInt(x.order_count) }; });

  chartInstance = new Chart(document.getElementById('chartOmset'), {
    type: 'bar',
    data: {
      labels: days.map(d => new Date(d+'T00:00:00').toLocaleDateString('id-ID',{day:'numeric',month:'short'})),
      datasets: [{
        label: 'Omset',
        data: days.map(d => dataMap[d]?.omset || 0),
        backgroundColor: days.map((d,i) => i===6 ? 'rgba(53,232,213,.8)' : 'rgba(27,45,90,.5)'),
        borderRadius: 6,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, ticks: { callback: v => 'Rp ' + (v/1000).toFixed(0) + 'k' } },
        x: { grid: { display: false } }
      }
    }
  });
}

function statusLabel(s){return{masuk:'Diterima',cuci:'Cuci',kering:'Kering',setrika:'Setrika',siap:'Siap',diambil:'Diambil'}[s]||s}
function fmtDate(d){if(!d)return'-';return new Date(d+'T00:00:00').toLocaleDateString('id-ID',{day:'2-digit',month:'short'})}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function showToast(msg,type='success'){const t=document.getElementById('toast');t.textContent=msg;t.className='hl-toast '+type+' show';setTimeout(()=>t.className='hl-toast',3500)}
</script>
</body>
</html>
