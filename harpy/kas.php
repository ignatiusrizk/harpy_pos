<?php
$activePage = 'kas';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
requirePermission('kas.view');

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $oid = TenantResolver::outletId();

    // LIST KAS
    if ($action === 'list') {
        $dari   = $_GET['dari']   ?? date('Y-m-01');
        $sampai = $_GET['sampai'] ?? date('Y-m-d');
        $tipe   = $_GET['tipe']   ?? '';
        $kat    = $_GET['kat']    ?? '';

        $where  = ['tenant_id = ?', 'outlet_id = ?', 'tanggal BETWEEN ? AND ?'];
        $params = [$tid, $oid, $dari, $sampai];
        if ($tipe) { $where[] = 'tipe=?';     $params[] = $tipe; }
        if ($kat)  { $where[] = 'kategori=?'; $params[] = $kat; }
        $whereStr = implode(' AND ', $where);

        $rows    = TenantQuery::raw("SELECT * FROM hl_kas WHERE $whereStr ORDER BY tanggal DESC, id DESC", $params);
        $summary = TenantQuery::raw(
            "SELECT COALESCE(SUM(CASE WHEN tipe='masuk' THEN jumlah END),0) as total_masuk,
                    COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah END),0) as total_keluar,
                    COUNT(*) as total_transaksi
             FROM hl_kas WHERE $whereStr",
            $params
        );
        echo json_encode(['data'=>$rows, 'summary'=>$summary[0] ?? []]);
        exit;
    }

    // SAVE KAS
    if ($action === 'save' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('kas.create')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $keterangan = substr(trim(strip_tags($d['keterangan'] ?? '')), 0, 500);
        if (!$keterangan) { echo json_encode(['error'=>'Keterangan wajib diisi']); exit; }
        if (floatval($d['jumlah'] ?? 0) <= 0) { echo json_encode(['error'=>'Jumlah harus lebih dari 0']); exit; }

        $data = [
            'tanggal'    => $d['tanggal']   ?? date('Y-m-d'),
            'tipe'       => in_array($d['tipe']??'', ['masuk','keluar']) ? $d['tipe'] : 'masuk',
            'kategori'   => substr(trim($d['kategori'] ?? ''), 0, 50),
            'keterangan' => $keterangan,
            'jumlah'     => floatval($d['jumlah']),
            'ref_order'  => $d['ref_order'] ? strtoupper(substr(trim($d['ref_order']), 0, 30)) : null,
        ];

        if (!empty($d['id'])) {
            TenantQuery::update('hl_kas', $data, 'id = ?', [intval($d['id'])]);
        } else {
            $data['created_by'] = $user['id'];
            TenantQuery::insert('hl_kas', $data);
        }
        logAudit(!empty($d['id'])?'update':'create','kas',($data['tipe']).' Rp '.number_format($data['jumlah'],0,',','.').': '.$keterangan);
        echo json_encode(['success'=>true]);
        // Anomaly check (silent)
        try {
            require_once __DIR__ . '/core/AnomalyDetector.php';
            AnomalyDetector::check($tid, $oid);
        } catch (Throwable) {}
        exit;
    }

    // DELETE KAS
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('kas.delete')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        TenantQuery::delete('hl_kas', 'id = ?', [intval($d['id'])]);
        echo json_encode(['success'=>true]); exit;
    }

    // SUMMARY HARIAN
    if ($action === 'summary_harian') {
        $tgl = $_GET['tgl'] ?? date('Y-m-d');
        $kasData  = TenantQuery::raw(
            "SELECT COALESCE(SUM(CASE WHEN tipe='masuk' THEN jumlah END),0) as kas_masuk,
                    COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah END),0) as kas_keluar
             FROM hl_kas WHERE tenant_id=? AND outlet_id=? AND tanggal=?",
            [$tid, $oid, $tgl]
        );
        $orderData = TenantQuery::raw(
            "SELECT COUNT(*) as total_order,
                    COALESCE(SUM(total),0) as omset,
                    COALESCE(SUM(dp),0) as terkumpul
             FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal)=?",
            [$tid, $oid, $tgl]
        );
        echo json_encode(array_merge($kasData[0] ?? [], $orderData[0] ?? [])); exit;
    }

    // KATEGORI LIST
    if ($action === 'kategori') {
        $rows = TenantQuery::raw("SELECT DISTINCT kategori FROM hl_kas WHERE tenant_id=? AND outlet_id=? ORDER BY kategori", [$tid, $oid]);
        echo json_encode(array_column($rows, 'kategori')); exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Kas'); ?>
<style>
/* SUMMARY CARDS */
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.sum-card{background:var(--white);border-radius:var(--r-lg);padding:18px 20px;border:1px solid rgba(27,45,90,.07);box-shadow:var(--shadow);position:relative;overflow:hidden}
.sum-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.sum-card.masuk::before{background:linear-gradient(90deg,var(--green),#34D399)}
.sum-card.keluar::before{background:linear-gradient(90deg,#EF4444,#F87171)}
.sum-card.saldo::before{background:linear-gradient(90deg,var(--teal),var(--teal-d))}
.sum-card.order::before{background:linear-gradient(90deg,#8B5CF6,#A78BFA)}
.sum-num{font-size:1.4rem;font-weight:800;color:var(--navy);line-height:1;font-family:var(--mono);margin-bottom:4px}
.sum-num.green{color:var(--green)}
.sum-num.red{color:#EF4444}
.sum-num.teal{color:var(--teal-d)}
.sum-label{font-size:12px;color:var(--gray);font-weight:500}

/* LAYOUT 2 COL */
.layout-2{display:grid;grid-template-columns:360px 1fr;gap:20px;align-items:start}

/* FORM */
.tipe-toggle{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px}
.tipe-btn{padding:12px;border-radius:var(--r);border:2px solid rgba(27,45,90,.12);background:var(--off);cursor:pointer;text-align:center;font-family:var(--font);font-weight:600;font-size:14px;transition:all .2s}
.tipe-btn.masuk.active{background:#D1FAE5;border-color:var(--green);color:#065F46}
.tipe-btn.keluar.active{background:#FEE2E2;border-color:#EF4444;color:#991B1B}
.tipe-btn:not(.active):hover{border-color:var(--teal)}

/* TABLE */
.td-jumlah{font-family:var(--mono);font-weight:700;text-align:right;font-size:14px}
.td-masuk{color:var(--green)}
.td-keluar{color:#EF4444}
tfoot tr{background:var(--navy);color:var(--white)}
tfoot td{padding:12px;font-weight:700;font-size:13px}
tfoot td.td-jumlah{font-family:var(--mono)}

/* BADGE */
.b-masuk{background:#D1FAE5;color:#065F46}
.b-keluar{background:#FEE2E2;color:#991B1B}
.b-kat{background:var(--light);color:var(--gray)}

/* SALDO BOX */
.saldo-box{background:linear-gradient(135deg,var(--navy-d, #0F1C3A),var(--navy));border-radius:var(--r-lg);padding:20px;color:var(--white);margin-top:16px}
.sb-row{display:flex;justify-content:space-between;padding:5px 0;font-size:14px}
.sb-label{color:rgba(255,255,255,.6)}
.sb-value{font-family:var(--mono);font-weight:600}
.sb-value.green{color:#6EE7B7}
.sb-value.red{color:#FCA5A5}
.sb-divider{border:none;border-top:1px solid rgba(255,255,255,.15);margin:8px 0}
.sb-saldo{font-size:1.4rem;font-weight:800;color:var(--teal)}
.shortcut-btns{display:flex;gap:6px}
.sc-btn{padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;border:1.5px solid rgba(27,45,90,.12);background:var(--off);cursor:pointer;font-family:var(--font);transition:all .2s;color:var(--navy)}
.sc-btn:hover,.sc-btn.active{background:var(--teal);color:var(--navy);border-color:var(--teal)}
@media(max-width:860px){.layout-2{grid-template-columns:1fr}.summary-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:680px){.summary-grid{grid-template-columns:repeat(2,1fr);gap:10px}.sum-card{padding:14px}.sum-num{font-size:1.1rem}}
</style>
</head>
<body>
<?php renderTopbar('kas'); ?>
<div class="hl-main">

  <div class="summary-grid">
    <div class="sum-card masuk"><div class="sum-num green" id="sumMasuk">Rp 0</div><div class="sum-label">💚 Total Kas Masuk</div></div>
    <div class="sum-card keluar"><div class="sum-num red" id="sumKeluar">Rp 0</div><div class="sum-label">❤️ Total Kas Keluar</div></div>
    <div class="sum-card saldo"><div class="sum-num teal" id="sumSaldo">Rp 0</div><div class="sum-label">💎 Saldo Bersih</div></div>
    <div class="sum-card order"><div class="sum-num" id="sumOrder" style="color:#8B5CF6">0</div><div class="sum-label">📋 Transaksi Kas</div></div>
  </div>

  <div class="hl-filter-collapsible">
    <button class="hl-filter-toggle-btn" id="kasFilterBtn" onclick="toggleFilter('kasFilter')">
      🔍 Filter Periode <span class="hl-toggle-arrow">▼</span>
    </button>
    <div class="hl-filter-bar" id="kasFilter">
      <label style="font-size:12px;font-weight:700;color:var(--navy)">Dari</label>
      <input type="date" id="fDari" class="hl-input" style="width:auto" onchange="loadKas()"/>
      <label style="font-size:12px;font-weight:700;color:var(--navy)">s/d</label>
      <input type="date" id="fSampai" class="hl-input" style="width:auto" onchange="loadKas()"/>
      <select id="fTipe" class="hl-input" style="width:auto" onchange="loadKas()">
        <option value="">Semua Tipe</option>
        <option value="masuk">Kas Masuk</option>
        <option value="keluar">Kas Keluar</option>
      </select>
      <div class="shortcut-btns">
        <button class="sc-btn" onclick="setRange('hari',this)">Hari Ini</button>
        <button class="sc-btn active" onclick="setRange('bulan',this)">Bulan Ini</button>
        <button class="sc-btn" onclick="setRange('minggu',this)">7 Hari</button>
      </div>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadKas()" style="margin-left:auto">🔄</button>
    </div>
  </div>

  <div class="layout-2">

    <!-- KOLOM KIRI: Form Input -->
    <div>
      <div class="hl-card">
        <div class="hl-card-header">
          <div class="hl-card-title" id="formTitle">➕ Input Kas</div>
        </div>
        <div style="padding:18px">
          <div class="tipe-toggle">
            <button class="tipe-btn masuk active" id="btnMasuk" onclick="setTipe('masuk')">💚 Kas Masuk</button>
            <button class="tipe-btn keluar" id="btnKeluar" onclick="setTipe('keluar')">❤️ Kas Keluar</button>
          </div>
          <input type="hidden" id="f_tipe" value="masuk"/>
          <input type="hidden" id="f_id" value=""/>

          <div class="hl-form-row">
            <div class="hl-form-group">
              <label class="hl-label">Tanggal <span class="req">*</span></label>
              <input type="date" id="f_tanggal" class="hl-input"/>
            </div>
            <div class="hl-form-group">
              <label class="hl-label">Jumlah (Rp) <span class="req">*</span></label>
              <input type="number" id="f_jumlah" class="hl-input" placeholder="0" min="0" step="500" oninput="updateJumlahPreview()"/>
            </div>
          </div>

          <div class="hl-form-group">
            <label class="hl-label">Kategori <span class="req">*</span></label>
            <select id="f_kategori" class="hl-input">
              <option value="">— Pilih Kategori —</option>
              <optgroup label="💚 Kas Masuk" id="optMasuk">
                <option value="Penjualan Laundry">Penjualan Laundry</option>
                <option value="Pelunasan Order">Pelunasan Order</option>
                <option value="Pendapatan Lain">Pendapatan Lain</option>
                <option value="Modal">Modal</option>
              </optgroup>
              <optgroup label="❤️ Kas Keluar" id="optKeluar">
                <option value="Gaji Karyawan">Gaji Karyawan</option>
                <option value="Bahan & Deterjen">Bahan &amp; Deterjen</option>
                <option value="Listrik & Air">Listrik &amp; Air</option>
                <option value="Sewa Tempat">Sewa Tempat</option>
                <option value="Peralatan">Peralatan</option>
                <option value="Transportasi">Transportasi</option>
                <option value="Operasional">Operasional</option>
                <option value="Lain-lain">Lain-lain</option>
              </optgroup>
            </select>
          </div>

          <div class="hl-form-group">
            <label class="hl-label">Keterangan <span class="req">*</span></label>
            <textarea id="f_keterangan" class="hl-input hl-textarea" placeholder="Deskripsi transaksi kas..."></textarea>
          </div>

          <div class="hl-form-group">
            <label class="hl-label">No. Order Terkait (opsional)</label>
            <input type="text" id="f_ref_order" class="hl-input" placeholder="HL-20260501-001"
              style="font-family:var(--mono);text-transform:uppercase;letter-spacing:.06em"
              oninput="this.value=this.value.toUpperCase()"/>
          </div>

          <div id="jumlahPreview" style="display:none;text-align:center;padding:12px;border-radius:var(--r);margin-bottom:12px;font-size:1.2rem;font-weight:800;font-family:var(--mono)"></div>

          <button class="hl-btn hl-btn-primary hl-btn-full" onclick="saveKas()" id="btnSave" style="margin-bottom:8px">💾 Simpan</button>
          <button class="hl-btn hl-btn-outline hl-btn-full" onclick="resetForm()">↺ Reset</button>
        </div>
      </div>

      <!-- SALDO BOX HARI INI -->
      <div class="saldo-box" id="saldoBox">
        <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.4);margin-bottom:12px">📊 Ringkasan Hari Ini</div>
        <div class="sb-row"><span class="sb-label">Order Masuk</span><span class="sb-value" id="sbOrder">-</span></div>
        <div class="sb-row"><span class="sb-label">Omset</span><span class="sb-value green" id="sbOmset">-</span></div>
        <div class="sb-row"><span class="sb-label">Terkumpul</span><span class="sb-value green" id="sbTerkumpul">-</span></div>
        <hr class="sb-divider"/>
        <div class="sb-row"><span class="sb-label">Kas Masuk</span><span class="sb-value green" id="sbKasMasuk">-</span></div>
        <div class="sb-row"><span class="sb-label">Kas Keluar</span><span class="sb-value red" id="sbKasKeluar">-</span></div>
        <hr class="sb-divider"/>
        <div class="sb-row"><span style="color:white;font-weight:700">Saldo Bersih</span><span class="sb-saldo" id="sbSaldo">-</span></div>
      </div>
    </div>

    <!-- KOLOM KANAN: Tabel -->
    <div>
      <div class="hl-card">
        <div class="hl-card-header">
          <div class="hl-card-title">📋 Riwayat Kas</div>
          <span id="tableInfo" style="font-size:12px;color:var(--gray)"></span>
        </div>
        <div class="hl-table-wrap">
          <table class="hl-table hl-stack-mobile">
            <thead>
              <tr>
                <th>Tanggal</th><th>Tipe</th><th>Kategori</th><th>Keterangan</th>
                <th>Ref Order</th><th style="text-align:right">Jumlah</th><th></th>
              </tr>
            </thead>
            <tbody id="tableBody">
              <tr><td colspan="7" class="hl-loading">⏳ Memuat...</td></tr>
            </tbody>
            <tfoot id="tableFoot" style="display:none">
              <tr>
                <td colspan="4" style="color:rgba(255,255,255,.6)">Total Periode</td>
                <td></td>
                <td class="td-jumlah" id="footTotal"></td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>

  </div>
</div>

<?php renderToast(); ?>
<script>
function localDateStr(d) {
  const dt = d || new Date();
  return dt.getFullYear()+'-'+String(dt.getMonth()+1).padStart(2,'0')+'-'+String(dt.getDate()).padStart(2,'0');
}

document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('f_tanggal').value = localDateStr();
  setRange('bulan');
  loadSaldoHarian();
});

function setTipe(tipe) {
  document.getElementById('f_tipe').value = tipe;
  document.getElementById('btnMasuk').classList.toggle('active', tipe==='masuk');
  document.getElementById('btnKeluar').classList.toggle('active', tipe==='keluar');
  updateJumlahPreview();
}

function updateJumlahPreview() {
  const jumlah = parseFloat(document.getElementById('f_jumlah').value) || 0;
  const tipe   = document.getElementById('f_tipe').value;
  const el     = document.getElementById('jumlahPreview');
  if (jumlah <= 0) { el.style.display='none'; return; }
  el.style.display = 'block';
  el.style.background = tipe==='masuk' ? '#D1FAE5' : '#FEE2E2';
  el.style.color = tipe==='masuk' ? '#065F46' : '#991B1B';
  el.textContent = (tipe==='masuk' ? '+ ' : '- ') + 'Rp ' + jumlah.toLocaleString('id-ID');
}

function setRange(type, el) {
  const now = new Date();
  let dari, sampai = localDateStr(now);
  if (type === 'hari') {
    dari = sampai;
  } else if (type === 'minggu') {
    const w = new Date(now); w.setDate(w.getDate()-6); dari = localDateStr(w);
  } else {
    dari = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0')+'-01';
  }
  document.getElementById('fDari').value   = dari;
  document.getElementById('fSampai').value = sampai;
  document.querySelectorAll('.sc-btn').forEach(b=>b.classList.remove('active'));
  if (el) el.classList.add('active');
  loadKas();
}

async function loadKas() {
  const dari   = document.getElementById('fDari').value;
  const sampai = document.getElementById('fSampai').value;
  const tipe   = document.getElementById('fTipe').value;
  document.getElementById('tableBody').innerHTML = Array.from({length:5}).map(()=>
    `<tr><td colspan="7" style="padding:0;border-bottom:1px solid var(--light)">
      <div class="hl-skel-row" style="padding:12px 14px">
        <span class="hl-skel" style="width:80px"></span>
        <span class="hl-skel" style="width:140px"></span>
        <span class="hl-skel" style="width:60px;margin-left:auto"></span>
      </div></td></tr>`).join('');

  const r = await fetch(`kas.php?action=list&dari=${dari}&sampai=${sampai}&tipe=${tipe}`);
  const d = await r.json();
  const sm = d.summary;
  const masuk  = parseFloat(sm.total_masuk||0);
  const keluar = parseFloat(sm.total_keluar||0);
  const saldo  = masuk - keluar;

  document.getElementById('sumMasuk').textContent  = 'Rp '+masuk.toLocaleString('id-ID');
  document.getElementById('sumKeluar').textContent = 'Rp '+keluar.toLocaleString('id-ID');
  document.getElementById('sumSaldo').textContent  = 'Rp '+saldo.toLocaleString('id-ID');
  document.getElementById('sumOrder').textContent  = sm.total_transaksi||0;
  document.getElementById('sumSaldo').style.color  = saldo>=0?'var(--green)':'#EF4444';

  if (!d.data?.length) {
    document.getElementById('tableBody').innerHTML = '<tr><td colspan="7" class="hl-empty">📭 Belum ada data kas untuk periode ini.</td></tr>';
    document.getElementById('tableFoot').style.display = 'none';
    document.getElementById('tableInfo').textContent = '';
    return;
  }

  document.getElementById('tableBody').innerHTML = d.data.map(row => `
    <tr>
      <td data-lbl="Tanggal" style="white-space:nowrap;font-size:13px">${fmtDate(row.tanggal)}</td>
      <td data-lbl="Tipe"><span class="hl-badge b-${row.tipe}">${row.tipe==='masuk'?'💚 Masuk':'❤️ Keluar'}</span></td>
      <td data-lbl="Kategori"><span class="hl-badge b-kat" style="background:var(--light);color:var(--gray)">${esc(row.kategori)}</span></td>
      <td data-lbl="Keterangan" style="font-size:13px;max-width:200px">${esc(row.keterangan)}</td>
      <td data-lbl="Ref Order" style="font-family:var(--mono);font-size:12px;color:var(--teal-d)">${row.ref_order||'-'}</td>
      <td data-lbl="Jumlah" class="td-jumlah ${row.tipe==='masuk'?'td-masuk':'td-keluar'}">
        ${row.tipe==='masuk'?'+':'-'} Rp ${parseFloat(row.jumlah).toLocaleString('id-ID')}
      </td>
      <td>
        <div style="display:flex;gap:4px">
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="editKas(${row.id})">✏️ Edit</button>
          <button class="hl-btn hl-btn-sm" style="background:#FEE2E2;color:#991B1B" onclick="deleteKas(${row.id})">🗑️ Hapus</button>
        </div>
      </td>
    </tr>`).join('');

  document.getElementById('tableFoot').style.display = '';
  document.getElementById('footTotal').innerHTML =
    `<span style="color:#6EE7B7">+ Rp ${masuk.toLocaleString('id-ID')}</span>` +
    ` / <span style="color:#FCA5A5">- Rp ${keluar.toLocaleString('id-ID')}</span>` +
    ` = <span style="color:${saldo>=0?'var(--teal)':'#FCA5A5'}">Rp ${saldo.toLocaleString('id-ID')}</span>`;
  document.getElementById('tableInfo').textContent = `${d.data.length} transaksi`;
}

async function loadSaldoHarian() {
  const r = await fetch('kas.php?action=summary_harian&tgl='+localDateStr());
  const d = await r.json();
  document.getElementById('sbOrder').textContent     = (d.total_order||0)+' order';
  document.getElementById('sbOmset').textContent     = 'Rp '+parseFloat(d.omset||0).toLocaleString('id-ID');
  document.getElementById('sbTerkumpul').textContent = 'Rp '+parseFloat(d.terkumpul||0).toLocaleString('id-ID');
  document.getElementById('sbKasMasuk').textContent  = 'Rp '+parseFloat(d.kas_masuk||0).toLocaleString('id-ID');
  document.getElementById('sbKasKeluar').textContent = 'Rp '+parseFloat(d.kas_keluar||0).toLocaleString('id-ID');
  const saldo = parseFloat(d.kas_masuk||0) - parseFloat(d.kas_keluar||0);
  document.getElementById('sbSaldo').textContent = 'Rp '+saldo.toLocaleString('id-ID');
  document.getElementById('sbSaldo').style.color = saldo>=0?'var(--teal)':'#FCA5A5';
}

async function saveKas() {
  const jumlah   = parseFloat(document.getElementById('f_jumlah').value)||0;
  const ket      = document.getElementById('f_keterangan').value.trim();
  const kategori = document.getElementById('f_kategori').value;
  if (jumlah<=0)  { showToast('⚠️ Jumlah harus lebih dari 0','error'); return; }
  if (!ket)       { showToast('⚠️ Keterangan wajib diisi','error'); return; }
  if (!kategori)  { showToast('⚠️ Pilih kategori','error'); return; }

  const btn = document.getElementById('btnSave');
  btn.disabled = true; btn.textContent = '⏳ Menyimpan...';

  const r = await fetch('kas.php?action=save', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({
      id: document.getElementById('f_id').value||null,
      tanggal: document.getElementById('f_tanggal').value,
      tipe: document.getElementById('f_tipe').value,
      kategori, keterangan:ket, jumlah,
      ref_order: document.getElementById('f_ref_order').value||null,
    })
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Kas berhasil disimpan!','success'); resetForm(); loadKas(); loadSaldoHarian(); }
  else showToast('❌ '+(d.error||'Gagal'),'error');
  btn.disabled=false; btn.textContent='💾 Simpan';
}

async function editKas(id) {
  const r = await fetch(`kas.php?action=list&dari=2020-01-01&sampai=2030-12-31`);
  const d = await r.json();
  const row = d.data.find(x => x.id==id);
  if (!row) return;
  document.getElementById('f_id').value         = row.id;
  document.getElementById('f_tanggal').value    = row.tanggal;
  document.getElementById('f_jumlah').value     = row.jumlah;
  document.getElementById('f_keterangan').value = row.keterangan;
  document.getElementById('f_kategori').value   = row.kategori;
  document.getElementById('f_ref_order').value  = row.ref_order||'';
  setTipe(row.tipe); updateJumlahPreview();
  document.getElementById('formTitle').textContent = '✏️ Edit Kas #'+row.id;
  document.getElementById('btnSave').textContent = '💾 Update';
  document.querySelector('.hl-card').scrollIntoView({behavior:'smooth'});
  showToast('📝 Edit mode — ubah data lalu klik Simpan','success');
}

async function deleteKas(id) {
  if (!confirm('Hapus catatan kas ini?')) return;
  const r = await fetch('kas.php?action=delete', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id})
  });
  const d = await r.json();
  if (d.success) { showToast('🗑️ Dihapus','success'); loadKas(); loadSaldoHarian(); }
  else showToast('❌ Gagal hapus','error');
}

function resetForm() {
  document.getElementById('f_id').value='';
  document.getElementById('f_jumlah').value='';
  document.getElementById('f_keterangan').value='';
  document.getElementById('f_kategori').value='';
  document.getElementById('f_ref_order').value='';
  document.getElementById('f_tanggal').value=localDateStr();
  document.getElementById('jumlahPreview').style.display='none';
  document.getElementById('formTitle').textContent='➕ Input Kas';
  document.getElementById('btnSave').textContent='💾 Simpan';
  setTipe('masuk');
}

function fmtDate(d){if(!d)return'-';return new Date(d).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
</script>
</body>
</html>
