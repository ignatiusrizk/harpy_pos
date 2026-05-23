<?php
// droppoint/input_order.php — Form input order dari mitra
define('ROOT', dirname(__DIR__));
require_once __DIR__ . '/middleware/mitra_guard.php';

$activePage = 'input';
$pageTitle  = 'Input Order';
$tid = $mitra['tenant_id']; $oid = $mitra['outlet_id']; $dp = $mitra['drop_point_id'];
$db  = mitraDb();
$action = $_GET['action'] ?? '';

// ── API: get layanan outlet ──
if ($action === 'layanan') {
    header('Content-Type: application/json');
    try {
        $s = $db->prepare("SELECT id, nama, kategori, satuan, harga FROM hl_layanan
                            WHERE tenant_id=? AND outlet_id=? AND is_active=1
                            ORDER BY kategori, urutan, nama");
        $s->execute([$tid, $oid]);
        echo json_encode(['ok'=>true, 'layanan'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: submit order ──
if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    mitraVerifyCsrf();
    $d = json_decode(file_get_contents('php://input'), true) ?: [];

    $nama    = substr(trim(strip_tags($d['nama'] ?? '')), 0, 100);
    $telepon = substr(preg_replace('/[^0-9+\-\s]/', '', $d['telepon'] ?? ''), 0, 20);
    $estKg   = max(0.1, (float)($d['est_kg'] ?? 0));
    $layId   = (int)($d['layanan_id'] ?? 0);
    $catatan = substr(trim(strip_tags($d['catatan'] ?? '')), 0, 500);

    if (!$nama)    { echo json_encode(['error'=>'Nama pelanggan wajib diisi']); exit; }
    if (!$telepon) { echo json_encode(['error'=>'No. WA pelanggan wajib diisi']); exit; }
    if (!$layId)   { echo json_encode(['error'=>'Pilih jenis layanan']); exit; }
    if ($estKg <= 0) { echo json_encode(['error'=>'Estimasi berat tidak valid']); exit; }

    try {
        // Ambil layanan
        $ls = $db->prepare("SELECT id, nama, satuan, harga FROM hl_layanan
                             WHERE id=? AND tenant_id=? AND outlet_id=? AND is_active=1");
        $ls->execute([$layId, $tid, $oid]);
        $lay = $ls->fetch(PDO::FETCH_ASSOC);
        if (!$lay) { echo json_encode(['error'=>'Layanan tidak valid']); exit; }

        $hargaSatuan = (float)$lay['harga'];
        $subtotal    = $estKg * $hargaSatuan;
        $total       = $subtotal;

        $db->beginTransaction();

        // Upsert pelanggan (account-scoped lintas outlet)
        $pelRow = $db->prepare("SELECT id FROM hl_pelanggan WHERE tenant_id=? AND telepon=? LIMIT 1");
        $pelRow->execute([$tid, $telepon]);
        $pelId = (int)$pelRow->fetchColumn();
        if ($pelId) {
            $db->prepare("UPDATE hl_pelanggan
                             SET total_order=total_order+1, total_visit_count=total_visit_count+1
                           WHERE id=? AND tenant_id=?")
               ->execute([$pelId, $tid]);
        } else {
            $db->prepare("INSERT INTO hl_pelanggan
                            (tenant_id, outlet_id, registered_outlet_id, nama, telepon, tipe,
                             total_order, total_visit_count, is_active, created_at)
                          VALUES (?,?,?,?,?,'retail',1,1,1,NOW())")
               ->execute([$tid, $oid, $oid, $nama, $telepon]);
            $pelId = (int)$db->lastInsertId();
        }

        // Generate no order
        $prefix = 'DP-' . date('Ymd') . '-';
        $cnt = $db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND no_order LIKE ?");
        $cnt->execute([$tid, $oid, $prefix.'%']);
        $no = $prefix . str_pad((int)$cnt->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);

        $catNote = "Order dari mitra " . ($mitra['dp']['nama_mitra'] ?? '');
        $catInternal = "Estimasi mitra: {$estKg} kg × Rp ".number_format($hargaSatuan,0,',','.')
                     ." = Rp ".number_format($total,0,',','.')." — final ditimbang ulang di outlet";

        // Insert transaksi
        $ins = $db->prepare("INSERT INTO hl_transaksi
            (tenant_id, outlet_id, drop_point_id, no_order, tanggal, pelanggan_id,
             nama_pelanggan, telepon, subtotal, diskon, total, dp, sisa_bayar,
             metode_bayar, status_bayar, status_proses, catatan, catatan_internal, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,0,?,0,?,'cash','belum_bayar','masuk',?,?,?)");
        $ins->execute([
            $tid, $oid, $dp, $no, date('Y-m-d'), $pelId, $nama, $telepon,
            $subtotal, $total, $total, $catatan ?: $catNote, $catInternal, $mitra['user_id']
        ]);
        $trxId = (int)$db->lastInsertId();

        // Insert item
        $db->prepare("INSERT INTO hl_transaksi_item
            (tenant_id, outlet_id, transaksi_id, layanan_id, nama_layanan, satuan, jumlah, harga_satuan, subtotal, catatan_item)
            VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$tid, $oid, $trxId, $layId, $lay['nama'], $lay['satuan'] ?: 'kg',
                      $estKg, $hargaSatuan, $subtotal, 'Estimasi awal']);

        $db->commit();

        // Generate WA link untuk pelanggan
        $pelPhone = preg_replace('/[^0-9]/','',$telepon);
        if (strpos($pelPhone,'0') === 0) $pelPhone = '62'.substr($pelPhone,1);
        elseif (strpos($pelPhone,'62') !== 0) $pelPhone = '62'.$pelPhone;
        $waPelText = "Halo $nama, cucian kamu sudah kami terima di *".($mitra['dp']['nama_mitra'] ?? 'Mitra').
                     "*.\nOrder: $no\nEstimasi: $estKg kg ".$lay['nama'].
                     "\nTim outlet akan segera pickup. Terima kasih! 🙏";
        $waPelUrl = "https://wa.me/$pelPhone?text=" . urlencode($waPelText);

        // Cari kurir/staff outlet (role kurir/staff dengan telepon)
        $kurirText = "*Order Pickup Baru* 📦\nDari: ".($mitra['dp']['nama_mitra'] ?? '').
                     "\nAlamat: ".($mitra['dp']['alamat'] ?? '-').
                     "\nPelanggan: $nama ($telepon)\nEst: $estKg kg ".$lay['nama']."\nOrder: $no";
        $waKurirUrl = '';
        try {
            $k = $db->prepare("SELECT u.telepon FROM hl_users u
                                JOIN hl_karyawan_outlet ko ON ko.karyawan_id=u.id AND ko.tenant_id=u.tenant_id
                                     AND ko.outlet_id=? AND ko.is_active=1
                               WHERE u.tenant_id=? AND u.role IN ('kurir','staff','manager') AND u.is_active=1
                                 AND u.telepon IS NOT NULL AND u.telepon<>''
                               ORDER BY FIELD(u.role,'kurir','staff','manager') LIMIT 1");
            $k->execute([$oid,$tid]);
            $kTel = $k->fetchColumn();
            if ($kTel) {
                $kp = preg_replace('/[^0-9]/','',$kTel);
                if (strpos($kp,'0') === 0) $kp = '62'.substr($kp,1);
                elseif (strpos($kp,'62') !== 0) $kp = '62'.$kp;
                $waKurirUrl = "https://wa.me/$kp?text=".urlencode($kurirText);
            }
        } catch (Throwable) {}

        echo json_encode([
            'ok'=>true, 'no_order'=>$no, 'total'=>$total,
            'wa_pelanggan'=>$waPelUrl, 'wa_kurir'=>$waKurirUrl,
        ]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

require __DIR__ . '/_layout_open.php';
?>
<div class="card">
  <h2>➕ Input Order Baru</h2>
  <div id="ipAlert"></div>
  <form id="ipForm" onsubmit="event.preventDefault();submitOrder()">
    <div class="fld">
      <label>Nama Pelanggan <span style="color:#EF4444">*</span></label>
      <input type="text" id="ipNama" required maxlength="100" placeholder="Bu Sari">
    </div>
    <div class="fld">
      <label>No. WhatsApp <span style="color:#EF4444">*</span></label>
      <input type="tel" id="ipTelepon" required maxlength="20" placeholder="08123456789">
    </div>
    <div class="fld">
      <label>Jenis Layanan <span style="color:#EF4444">*</span></label>
      <select id="ipLayanan" required><option value="">— Memuat —</option></select>
    </div>
    <div class="fld">
      <label>Estimasi Berat (kg) <span style="color:#EF4444">*</span></label>
      <input type="number" id="ipKg" required min="0.5" step="0.5" value="3" placeholder="3">
    </div>
    <div class="fld">
      <label>Catatan Khusus (opsional)</label>
      <textarea id="ipCatatan" maxlength="500" placeholder="Warna terpisah, noda di kerah, dll"></textarea>
    </div>
    <button type="submit" class="btn btn-primary" id="ipSubmit">📦 Simpan Order</button>
  </form>
</div>

<div class="card" id="successBox" style="display:none">
  <h2 style="color:#065F46">✅ Order Tersimpan!</h2>
  <div id="successDetail" style="font-size:13px;color:#374151;line-height:1.6;margin-bottom:12px"></div>
  <a id="waPelBtn" class="btn btn-wa" target="_blank" style="margin-bottom:8px">💬 Kirim WA ke Pelanggan</a>
  <a id="waKurirBtn" class="btn" target="_blank" style="background:#0891B2;color:#fff;width:100%;margin-bottom:8px">🛵 Kirim WA ke Kurir</a>
  <a href="input_order.php" class="btn" style="background:#F3F4F6;color:#374151;width:100%">+ Input Order Lain</a>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

async function loadLayanan(){
  try {
    const r = await fetch('input_order.php?action=layanan');
    const d = await r.json();
    if (d.error){ document.getElementById('ipLayanan').innerHTML = `<option value="">⚠️ ${esc(d.error)}</option>`; return; }
    const sel = document.getElementById('ipLayanan');
    if (!d.layanan.length){ sel.innerHTML = '<option value="">Belum ada layanan</option>'; return; }
    sel.innerHTML = '<option value="">— Pilih —</option>' + d.layanan.map(l =>
      `<option value="${l.id}">${esc(l.nama)} — Rp ${Number(l.harga).toLocaleString('id-ID')}/${esc(l.satuan)}</option>`
    ).join('');
  } catch(e){ alert('Gagal memuat layanan: '+e.message); }
}

async function submitOrder(){
  const alertEl = document.getElementById('ipAlert');
  alertEl.innerHTML = '';
  const btn = document.getElementById('ipSubmit');
  btn.disabled = true; btn.textContent = '⏳ Menyimpan…';

  const body = {
    nama:        document.getElementById('ipNama').value.trim(),
    telepon:     document.getElementById('ipTelepon').value.trim(),
    layanan_id:  document.getElementById('ipLayanan').value,
    est_kg:      document.getElementById('ipKg').value,
    catatan:     document.getElementById('ipCatatan').value.trim(),
  };

  try {
    const r = await fetch('input_order.php?action=submit', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body: JSON.stringify(body)
    });
    const d = await r.json();
    btn.disabled = false; btn.textContent = '📦 Simpan Order';
    if (d.error){ alertEl.innerHTML = `<div class="alert error">⚠️ ${esc(d.error)}</div>`; return; }

    document.getElementById('ipForm').style.display = 'none';
    document.querySelector('.card h2').parentElement.style.display = 'none';
    const sb = document.getElementById('successBox');
    document.getElementById('successDetail').innerHTML = `
      <strong>No. Order:</strong> ${esc(d.no_order)}<br>
      <strong>Estimasi total:</strong> Rp ${Number(d.total).toLocaleString('id-ID')}<br>
      <em style="font-size:11px;color:#9CA3AF">Harga final ditimbang ulang di outlet</em>
    `;
    document.getElementById('waPelBtn').href = d.wa_pelanggan;
    if (d.wa_kurir) document.getElementById('waKurirBtn').href = d.wa_kurir;
    else document.getElementById('waKurirBtn').style.display = 'none';
    sb.style.display = 'block';
    window.scrollTo({top:0, behavior:'smooth'});
  } catch (e) {
    btn.disabled = false; btn.textContent = '📦 Simpan Order';
    alertEl.innerHTML = `<div class="alert error">⚠️ Gagal koneksi: ${esc(e.message)}</div>`;
  }
}

loadLayanan();
</script>

<?php require __DIR__ . '/_layout_close.php'; ?>
