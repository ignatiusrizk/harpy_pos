<?php
// ══════════════════════════════════════════════════════
// hq/mutasi.php — Histori Mutasi Karyawan (HQ View)
// Brief 7.5 — page dedicated untuk audit mutasi lintas karyawan
// ══════════════════════════════════════════════════════

$activePage = 'hq-mutasi';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

$db  = Database::get();
$tid = (int)$hqTenant['id'];
$action = $_GET['action'] ?? '';

if ($action === 'data') {
    header('Content-Type: application/json');
    $start = $_GET['start'] ?? date('Y-m-d', strtotime('-90 days'));
    $end   = $_GET['end']   ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-m-d', strtotime('-90 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = date('Y-m-d');

    $oidArg = (int)($_GET['outlet_id'] ?? 0);
    $kidArg = (int)($_GET['karyawan_id'] ?? 0);

    try {
        // Hanya assignment yang sudah ditutup (mutasi) DAN assignment baru yang asal mutasi
        $where  = ['ko.tenant_id=?'];
        $params = [$tid];
        $where[] = "DATE(COALESCE(ko.unassigned_at, ko.assigned_at)) BETWEEN ? AND ?";
        $params[] = $start; $params[] = $end;

        if ($oidArg > 0) { $where[] = 'ko.outlet_id=?'; $params[] = $oidArg; }
        if ($kidArg > 0) { $where[] = 'ko.karyawan_id=?'; $params[] = $kidArg; }

        // Filter hanya yang catatan mengandung 'Mutasi' ATAU is_active=0 (closed assignment)
        $where[] = "(ko.notes LIKE '%Mutasi%' OR ko.is_active=0)";

        $sql = "SELECT ko.id, ko.karyawan_id, ko.outlet_id, ko.is_active,
                       ko.assigned_at, ko.unassigned_at, ko.assigned_by, ko.notes,
                       (SELECT nama FROM hl_users WHERE id=ko.karyawan_id) AS karyawan_nama,
                       (SELECT username FROM hl_users WHERE id=ko.karyawan_id) AS karyawan_username,
                       (SELECT role FROM hl_users WHERE id=ko.karyawan_id) AS karyawan_role,
                       (SELECT nama_outlet FROM outlets WHERE id=ko.outlet_id) AS outlet_nama,
                       (SELECT nama FROM hl_users WHERE id=ko.assigned_by) AS by_nama
                  FROM hl_karyawan_outlet ko
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY COALESCE(ko.unassigned_at, ko.assigned_at) DESC LIMIT 300";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['entries' => $stmt->fetchAll()]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'filter_options') {
    header('Content-Type: application/json');
    $outlets = $db->prepare("SELECT id, nama_outlet FROM outlets WHERE tenant_id=? AND status!='closed' ORDER BY is_main DESC, nama_outlet ASC");
    $outlets->execute([$tid]);
    $karyawan = $db->prepare("SELECT id, nama FROM hl_users WHERE tenant_id=? ORDER BY nama ASC");
    $karyawan->execute([$tid]);
    echo json_encode([
        'outlets'  => $outlets->fetchAll(),
        'karyawan' => $karyawan->fetchAll(),
    ]);
    exit;
}

$ownerNama = $hqUser['nama'] ?? 'Owner';
$tenantNm  = $hqTenant['nama_outlet'] ?? 'HQ';
?>
<?php
$pageTitle  = 'Riwayat Mutasi Karyawan';
$activePage = 'hq-mutasi';
require __DIR__ . '/_layout_open.php';
?>
<style>
  h1{font-size:1.4rem;font-weight:800;color:#0F1C3A;margin-bottom:6px}
  h1 small{display:block;font-size:13px;font-weight:400;color:#6B7280;margin-top:2px}

  .filter-bar{background:#fff;border-radius:12px;padding:14px 18px;display:flex;gap:10px;
              flex-wrap:wrap;margin-bottom:16px;box-shadow:0 1px 6px rgba(0,0,0,.05);align-items:center}
  .filter-bar label{font-size:12px;color:#6B7280;font-weight:600;display:flex;align-items:center;gap:6px}
  .filter-bar input[type=date],.filter-bar select{padding:8px 12px;border:1.5px solid #E5E7EB;
                                                    border-radius:8px;font-size:13px;font-family:inherit;
                                                    background:#fff;outline:none;cursor:pointer}
  .filter-bar select{min-width:150px}
  .filter-bar input:focus,.filter-bar select:focus{border-color:#35E8D5}

  .timeline{background:#fff;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);padding:6px 0}
  .row{display:grid;grid-template-columns:auto 1fr auto;gap:14px;padding:14px 22px;
       border-bottom:1px solid #F3F4F6;font-size:13px;align-items:start}
  .row:last-child{border-bottom:none}
  .row-date{font-family:monospace;color:#9CA3AF;font-size:11px;white-space:nowrap;line-height:1.4}
  .row-date strong{color:#0F1C3A;display:block;font-size:12px;font-weight:600}
  .row-main strong{color:#0F1C3A;font-weight:700;font-size:14px;display:block;margin-bottom:3px}
  .row-main .info{font-size:12px;color:#6B7280;line-height:1.6}
  .row-main .info code{background:#F3F4F6;padding:1px 6px;border-radius:4px;font-size:11px;color:#0F1C3A}
  .row-tags{font-size:10px;display:flex;flex-wrap:wrap;gap:4px;margin-top:5px}
  .row-tag{padding:2px 7px;border-radius:100px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
  .tag-active{background:#D1FAE5;color:#065F46}
  .tag-closed{background:#FEE2E2;color:#991B1B}
  .tag-mutasi{background:#FEF3C7;color:#92400E}
  .tag-outlet{background:#F0FDFB;color:#0891B2}
  .row-by{text-align:right;font-size:11px;color:#6B7280;white-space:nowrap;line-height:1.4}
  .row-by strong{display:block;color:#0F1C3A;font-weight:700;font-size:12px}

  .stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px}
  .stat{background:#fff;border-radius:10px;padding:12px 14px;box-shadow:0 1px 6px rgba(0,0,0,.05)}
  .stat-num{font-size:1.4rem;font-weight:800;color:#0F1C3A;font-family:monospace}
  .stat-lbl{font-size:11px;color:#6B7280;text-transform:uppercase;letter-spacing:.05em;font-weight:700}

  .empty{text-align:center;padding:60px 20px;color:#9CA3AF;font-size:13px}
  .empty .ico{font-size:48px;margin-bottom:12px;opacity:.5}

  @media(max-width:780px){
    .stats{grid-template-columns:1fr}
    .row{grid-template-columns:1fr;gap:6px}
    .row-date,.row-by{text-align:left}
  }
</style>

  <h1>🔄 Histori Mutasi Karyawan
    <small>Audit trail penugasan & mutasi · <?= htmlspecialchars($tenantNm) ?></small>
  </h1>

  <div class="filter-bar">
    <label>📅 <input type="date" id="dStart" value="<?= date('Y-m-d', strtotime('-90 days')) ?>"></label>
    <label>– <input type="date" id="dEnd" value="<?= date('Y-m-d') ?>"></label>
    <select id="filterOutlet" onchange="loadData()">
      <option value="0">📍 Semua Outlet</option>
    </select>
    <select id="filterKaryawan" onchange="loadData()">
      <option value="0">👤 Semua Karyawan</option>
    </select>
    <button onclick="loadData()"
            style="padding:8px 14px;background:#0F1C3A;color:#fff;border:none;border-radius:8px;
                   font-size:12px;font-weight:700;cursor:pointer;font-family:inherit">↻ Refresh</button>
  </div>

  <div class="stats">
    <div class="stat"><div class="stat-num" id="statTotal">-</div><div class="stat-lbl">Total Event</div></div>
    <div class="stat"><div class="stat-num" id="statMutasi">-</div><div class="stat-lbl">Mutasi Selesai</div></div>
    <div class="stat"><div class="stat-num" id="statKaryawan">-</div><div class="stat-lbl">Karyawan Terlibat</div></div>
  </div>

  <div class="timeline" id="timeline">
    <div class="empty"><div class="ico">⏳</div><p>Memuat…</p></div>
  </div>

<script>
function esc(s){return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function fmtDate(s){if(!s)return '-';const d=new Date(s);return d.toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}
function fmtTime(s){if(!s)return '';const d=new Date(s);return d.toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'})}

async function loadFilters(){
  const r = await fetch('/ERP/harpy/hq/mutasi.php?action=filter_options');
  const d = await r.json();
  document.getElementById('filterOutlet').innerHTML = '<option value="0">📍 Semua Outlet</option>' +
    (d.outlets||[]).map(o => `<option value="${o.id}">📍 ${esc(o.nama_outlet)}</option>`).join('');
  document.getElementById('filterKaryawan').innerHTML = '<option value="0">👤 Semua Karyawan</option>' +
    (d.karyawan||[]).map(k => `<option value="${k.id}">👤 ${esc(k.nama)}</option>`).join('');
}

async function loadData(){
  const params = new URLSearchParams({
    start: document.getElementById('dStart').value,
    end:   document.getElementById('dEnd').value,
    outlet_id: document.getElementById('filterOutlet').value,
    karyawan_id: document.getElementById('filterKaryawan').value,
  });
  const r = await fetch('/ERP/harpy/hq/mutasi.php?action=data&' + params.toString());
  const d = await r.json();
  const entries = d.entries || [];

  document.getElementById('statTotal').textContent = entries.length;
  document.getElementById('statMutasi').textContent = entries.filter(e => (e.notes||'').includes('Mutasi')).length;
  document.getElementById('statKaryawan').textContent = new Set(entries.map(e => e.karyawan_id)).size;

  const tl = document.getElementById('timeline');
  if (entries.length === 0) {
    tl.innerHTML = '<div class="empty"><div class="ico">🔄</div><p>Tidak ada histori mutasi di periode ini.</p></div>';
    return;
  }
  tl.innerHTML = entries.map(e => {
    const eventDate = e.unassigned_at || e.assigned_at;
    const isClosed = e.is_active == 0;
    const isMutasi = (e.notes || '').includes('Mutasi');
    const isNew    = !isClosed && isMutasi;
    return `
      <div class="row">
        <div class="row-date">
          <strong>${fmtDate(eventDate)}</strong>
          ${fmtTime(eventDate)}
        </div>
        <div class="row-main">
          <strong>👤 ${esc(e.karyawan_nama || '-')} <small style="font-weight:400;color:#9CA3AF">@${esc(e.karyawan_username || '-')}</small></strong>
          <div class="info">
            ${isClosed
              ? `🔚 Dicabut dari outlet <code>📍 ${esc(e.outlet_nama || '?')}</code>`
              : `✓ Ditugaskan ke outlet <code>📍 ${esc(e.outlet_nama || '?')}</code>`}
            ${e.notes ? `<br><small style="color:#9CA3AF;font-size:11px">📝 ${esc(e.notes)}</small>` : ''}
          </div>
          <div class="row-tags">
            ${isClosed ? '<span class="row-tag tag-closed">DICABUT</span>' : '<span class="row-tag tag-active">AKTIF</span>'}
            ${isMutasi ? '<span class="row-tag tag-mutasi">🔄 MUTASI</span>' : ''}
            <span class="row-tag tag-outlet">${esc(e.karyawan_role || 'staff').toUpperCase()}</span>
          </div>
        </div>
        <div class="row-by">
          ${e.by_nama ? `<strong>oleh ${esc(e.by_nama)}</strong>` : '<strong>oleh sistem</strong>'}
          ${isClosed && e.assigned_at ? `<br>Aktif sejak: ${fmtDate(e.assigned_at)}` : ''}
        </div>
      </div>
    `;
  }).join('');
}

loadFilters();
loadData();
</script>
<?php require __DIR__ . '/_layout_close.php'; ?>
