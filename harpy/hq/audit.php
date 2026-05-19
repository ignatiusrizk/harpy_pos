<?php
// ══════════════════════════════════════════════════════
// hq/audit.php — HQ Audit Log Viewer (gabungan)
//
// Spec data ownership: "Audit log → Outlet (aktivitas) + Account (lintas outlet)"
// Halaman ini gabungkan 2 source:
//   - hl_audit_log         → aktivitas operasional outlet
//   - superadmin_logs      → action HQ-level (mutasi karyawan, save role,
//                            outlet status transition, account settings dll)
// ══════════════════════════════════════════════════════

$activePage = 'hq-audit';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];
$action = $_GET['action'] ?? '';

if ($action === 'data') {
    header('Content-Type: application/json');
    $start = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
    $end   = $_GET['end']   ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-m-d', strtotime('-7 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = date('Y-m-d');

    $oidArg    = (int)($_GET['outlet_id'] ?? 0);
    $userArg   = (int)($_GET['user_id']   ?? 0);
    $sourceArg = $_GET['source'] ?? 'all'; // all | outlet | hq
    $modulArg  = trim($_GET['modul'] ?? '');
    $q         = trim($_GET['q'] ?? '');

    $entries = [];

    // ── Source 1: hl_audit_log (outlet aktivitas) ─────────
    if ($sourceArg === 'all' || $sourceArg === 'outlet') {
        try {
            $where  = ['tenant_id=?', 'DATE(created_at) BETWEEN ? AND ?'];
            $params = [$tid, $start, $end];
            if ($oidArg > 0)  { $where[] = 'outlet_id=?';  $params[] = $oidArg; }
            if ($userArg > 0) { $where[] = 'user_id=?';    $params[] = $userArg; }
            if ($modulArg)    { $where[] = 'modul=?';       $params[] = $modulArg; }
            if ($q)            { $where[] = '(aksi LIKE ? OR keterangan LIKE ? OR user_nama LIKE ?)';
                                 $like = "%$q%"; $params[] = $like; $params[] = $like; $params[] = $like; }
            $sql = "SELECT id, outlet_id, user_id, user_nama, aksi, modul, keterangan,
                           ip_address, created_at,
                           (SELECT nama_outlet FROM outlets WHERE id=hl_audit_log.outlet_id) AS nama_outlet
                      FROM hl_audit_log WHERE " . implode(' AND ', $where) .
                   " ORDER BY created_at DESC LIMIT 300";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $entries[] = [
                    'id'         => 'a' . $row['id'],
                    'source'     => 'outlet',
                    'created_at' => $row['created_at'],
                    'outlet_id'  => (int)$row['outlet_id'],
                    'outlet'     => $row['nama_outlet'] ?? null,
                    'user_id'    => $row['user_id'],
                    'user'       => $row['user_nama'] ?? '-',
                    'action'     => $row['aksi'],
                    'modul'      => $row['modul'],
                    'detail'     => $row['keterangan'],
                    'ip'         => $row['ip_address'],
                ];
            }
        } catch (Throwable) { /* table tidak ada */ }
    }

    // ── Source 2: superadmin_logs (HQ-level actions) ──────
    if ($sourceArg === 'all' || $sourceArg === 'hq') {
        try {
            $where = ['DATE(created_at) BETWEEN ? AND ?',
                      "(JSON_EXTRACT(details, '$.tenant_id') = ? OR target_id = ? OR target_id IN (SELECT id FROM outlets WHERE tenant_id=?))"];
            $params = [$start, $end, $tid, $tid, $tid];
            if ($q) {
                $where[] = '(action LIKE ? OR details LIKE ?)';
                $like = "%$q%"; $params[] = $like; $params[] = $like;
            }
            $sql = "SELECT id, action, target_type, target_id, details, created_at
                      FROM superadmin_logs
                     WHERE " . implode(' AND ', $where) . "
                     ORDER BY created_at DESC LIMIT 300";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $det = @json_decode($row['details'], true);
                $byUid = (int)($det['by'] ?? 0);
                $byNama = '-';
                if ($byUid > 0) {
                    try {
                        $u = $db->prepare("SELECT nama FROM hl_users WHERE id=? LIMIT 1");
                        $u->execute([$byUid]);
                        $byNama = (string)($u->fetchColumn() ?: '-');
                    } catch (Throwable) {}
                }
                $outletId = 0;
                $outletNama = null;
                if (($row['target_type'] ?? '') === 'outlet') {
                    $outletId = (int)$row['target_id'];
                    try {
                        $on = $db->prepare("SELECT nama_outlet FROM outlets WHERE id=? AND tenant_id=? LIMIT 1");
                        $on->execute([$outletId, $tid]);
                        $outletNama = $on->fetchColumn() ?: null;
                    } catch (Throwable) {}
                }
                $entries[] = [
                    'id'         => 'h' . $row['id'],
                    'source'     => 'hq',
                    'created_at' => $row['created_at'],
                    'outlet_id'  => $outletId,
                    'outlet'     => $outletNama,
                    'user_id'    => $byUid ?: null,
                    'user'       => $byNama,
                    'action'     => $row['action'],
                    'modul'      => $row['target_type'],
                    'detail'     => is_array($det)
                        ? ($det['detail'] ?? ($det['change'] ?? json_encode($det, JSON_UNESCAPED_SLASHES)))
                        : $row['details'],
                    'ip'         => null,
                ];
            }
        } catch (Throwable) { /* table tidak ada */ }
    }

    // Sort gabungan by created_at DESC
    usort($entries, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    // Limit total 300
    $entries = array_slice($entries, 0, 300);

    echo json_encode([
        'periode'    => ['start'=>$start, 'end'=>$end],
        'total'      => count($entries),
        'entries'    => $entries,
    ]);
    exit;
}

if ($action === 'filter_options') {
    header('Content-Type: application/json');
    $outlets = $db->prepare("SELECT id, nama_outlet FROM outlets WHERE tenant_id=? AND status!='closed' ORDER BY is_main DESC, nama_outlet ASC");
    $outlets->execute([$tid]);
    $users   = $db->prepare("SELECT id, nama FROM hl_users WHERE tenant_id=? ORDER BY nama ASC");
    $users->execute([$tid]);
    $moduls  = [];
    try {
        $m = $db->prepare("SELECT DISTINCT modul FROM hl_audit_log WHERE tenant_id=? AND modul IS NOT NULL AND modul!='' ORDER BY modul");
        $m->execute([$tid]);
        $moduls = $m->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable) {}
    echo json_encode([
        'outlets' => $outlets->fetchAll(),
        'users'   => $users->fetchAll(),
        'moduls'  => $moduls,
    ]);
    exit;
}

$ownerNama = $hqUser['nama'] ?? 'Owner';
$tenantNm  = $hqTenant['nama_outlet'] ?? 'HQ';
?>
<?php
$pageTitle  = 'Audit Log';
$activePage = 'hq-audit';
require __DIR__ . '/_layout_open.php';
?>
<style>
  h1{font-size:1.4rem;font-weight:800;color:#0F1C3A;margin-bottom:6px}
  h1 small{display:block;font-size:13px;font-weight:400;color:#6B7280;margin-top:2px}

  .filter-bar{background:#fff;border-radius:12px;padding:14px 18px;display:flex;gap:10px;
              flex-wrap:wrap;margin-bottom:16px;box-shadow:0 1px 6px rgba(0,0,0,.05);align-items:center}
  .filter-bar label{font-size:12px;color:#6B7280;font-weight:600;display:flex;align-items:center;gap:6px}
  .filter-bar input[type=date],.filter-bar input[type=search],.filter-bar select{
    padding:8px 12px;border:1.5px solid #E5E7EB;border-radius:8px;font-size:13px;
    font-family:inherit;background:#fff;outline:none
  }
  .filter-bar input[type=search]{flex:1;min-width:180px}
  .filter-bar select{cursor:pointer;min-width:130px}
  .filter-bar input:focus,.filter-bar select:focus{border-color:#35E8D5}

  .stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px}
  .stat-pill{background:#fff;border-radius:10px;padding:10px 14px;box-shadow:0 1px 6px rgba(0,0,0,.05);display:flex;align-items:center;gap:10px}
  .stat-pill .num{font-size:1.4rem;font-weight:800;color:#0F1C3A;font-family:monospace}
  .stat-pill .lbl{font-size:11px;color:#6B7280;text-transform:uppercase;letter-spacing:.05em;font-weight:700}

  .timeline{background:#fff;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);padding:6px 0}
  .log-row{display:grid;grid-template-columns:auto 1fr auto;gap:12px;padding:13px 22px;
           border-bottom:1px solid #F3F4F6;font-size:13px;align-items:start}
  .log-row:last-child{border-bottom:none}
  .log-time{font-family:monospace;color:#9CA3AF;font-size:11px;white-space:nowrap;line-height:1.4;padding-top:1px}
  .log-time strong{color:#0F1C3A;display:block;font-size:12px;font-weight:600}
  .log-main strong{color:#0F1C3A;font-weight:700}
  .log-main small{display:block;color:#6B7280;font-size:11px;margin-top:3px;line-height:1.5;word-break:break-word}
  .log-tags{font-size:10px;display:flex;flex-wrap:wrap;gap:4px;margin-top:5px}
  .log-tag{padding:2px 7px;border-radius:100px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
  .tag-source-outlet{background:#DBEAFE;color:#1E40AF}
  .tag-source-hq{background:linear-gradient(135deg,rgba(102,126,234,.18),rgba(118,75,162,.18));color:#5B21B6}
  .tag-outlet{background:#F0FDFB;color:#0891B2}
  .tag-modul{background:#F3F4F6;color:#374151}
  .log-user{text-align:right;font-size:11px;color:#6B7280;white-space:nowrap}
  .log-user strong{display:block;color:#0F1C3A;font-weight:700;font-size:12px}

  .empty{text-align:center;padding:60px 20px;color:#9CA3AF;font-size:13px}
  .empty .ico{font-size:48px;margin-bottom:12px;opacity:.5}

  @media(max-width:780px){
    .stats-row{grid-template-columns:1fr}
    .log-row{grid-template-columns:1fr;gap:6px}
    .log-time,.log-user{font-size:10px}
  }
</style>

  <h1>📋 Audit Log
    <small>Aktivitas semua outlet + HQ · <?= htmlspecialchars($tenantNm) ?></small>
  </h1>

  <div class="filter-bar">
    <label>📅 <input type="date" id="dStart" value="<?= date('Y-m-d', strtotime('-7 days')) ?>"></label>
    <label>– <input type="date" id="dEnd" value="<?= date('Y-m-d') ?>"></label>
    <select id="filterSource" onchange="loadData()">
      <option value="all">🔀 Semua Source</option>
      <option value="outlet">📍 Aktivitas Outlet</option>
      <option value="hq">🏢 Action HQ</option>
    </select>
    <select id="filterOutlet" onchange="loadData()">
      <option value="0">📍 Semua Outlet</option>
    </select>
    <select id="filterUser" onchange="loadData()">
      <option value="0">👤 Semua User</option>
    </select>
    <select id="filterModul" onchange="loadData()">
      <option value="">📦 Semua Modul</option>
    </select>
    <input type="search" id="searchQ" placeholder="🔍 Cari action / user / detail…" oninput="debouncedLoad()">
  </div>

  <div class="stats-row">
    <div class="stat-pill"><div class="num" id="statTotal">-</div><div class="lbl">Total Entry</div></div>
    <div class="stat-pill"><div class="num" id="statOutlet">-</div><div class="lbl">Aktivitas Outlet</div></div>
    <div class="stat-pill"><div class="num" id="statHq">-</div><div class="lbl">Action HQ</div></div>
  </div>

  <div class="timeline" id="timeline">
    <div class="empty"><div class="ico">⏳</div><p>Memuat…</p></div>
  </div>

<script>
function esc(s){return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function fmtDate(s){if(!s)return '-';const d=new Date(s);return d.toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}
function fmtTime(s){if(!s)return '-';const d=new Date(s);return d.toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'})}

let debounceT = null;
function debouncedLoad(){
  clearTimeout(debounceT);
  debounceT = setTimeout(loadData, 350);
}

async function loadFilterOptions(){
  const r = await fetch('/ERP/harpy/hq/audit.php?action=filter_options');
  const d = await r.json();
  document.getElementById('filterOutlet').innerHTML = '<option value="0">📍 Semua Outlet</option>' +
    (d.outlets||[]).map(o => `<option value="${o.id}">📍 ${esc(o.nama_outlet)}</option>`).join('');
  document.getElementById('filterUser').innerHTML = '<option value="0">👤 Semua User</option>' +
    (d.users||[]).map(u => `<option value="${u.id}">👤 ${esc(u.nama)}</option>`).join('');
  document.getElementById('filterModul').innerHTML = '<option value="">📦 Semua Modul</option>' +
    (d.moduls||[]).map(m => `<option value="${esc(m)}">📦 ${esc(m)}</option>`).join('');
}

async function loadData(){
  const params = new URLSearchParams({
    start: document.getElementById('dStart').value,
    end:   document.getElementById('dEnd').value,
    source: document.getElementById('filterSource').value,
    outlet_id: document.getElementById('filterOutlet').value,
    user_id: document.getElementById('filterUser').value,
    modul: document.getElementById('filterModul').value,
    q: document.getElementById('searchQ').value,
  });
  const r = await fetch('/ERP/harpy/hq/audit.php?action=data&' + params.toString());
  const d = await r.json();

  document.getElementById('statTotal').textContent = d.total || 0;
  document.getElementById('statOutlet').textContent = (d.entries||[]).filter(e => e.source==='outlet').length;
  document.getElementById('statHq').textContent     = (d.entries||[]).filter(e => e.source==='hq').length;

  const tl = document.getElementById('timeline');
  if (!d.entries || d.entries.length === 0) {
    tl.innerHTML = '<div class="empty"><div class="ico">📋</div><p>Tidak ada entry audit log untuk filter ini.</p></div>';
    return;
  }
  tl.innerHTML = d.entries.map(e => {
    const sourceTag = e.source === 'hq'
      ? '<span class="log-tag tag-source-hq">🏢 HQ</span>'
      : '<span class="log-tag tag-source-outlet">📍 Outlet</span>';
    const tags = [
      sourceTag,
      e.outlet ? `<span class="log-tag tag-outlet">📍 ${esc(e.outlet)}</span>` : '',
      e.modul  ? `<span class="log-tag tag-modul">${esc(e.modul)}</span>` : '',
    ].filter(Boolean).join('');
    return `
      <div class="log-row">
        <div class="log-time">
          <strong>${fmtTime(e.created_at)}</strong>
          ${fmtDate(e.created_at)}
        </div>
        <div class="log-main">
          <strong>${esc(e.action || '(action)')}</strong>
          ${e.detail ? `<small>${esc(e.detail)}</small>` : ''}
          <div class="log-tags">${tags}</div>
        </div>
        <div class="log-user">
          <strong>${esc(e.user || '-')}</strong>
          ${e.ip ? `IP: ${esc(e.ip)}` : ''}
        </div>
      </div>
    `;
  }).join('');
}

loadFilterOptions();
loadData();
</script>
<?php require __DIR__ . '/_layout_close.php'; ?>
