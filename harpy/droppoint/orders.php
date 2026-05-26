<?php
// droppoint/orders.php — Daftar order mitra
define('ROOT', dirname(__DIR__));
require_once __DIR__ . '/middleware/mitra_guard.php';

$activePage = 'orders';
$pageTitle  = 'Order Saya';
$tid = $mitra['tenant_id']; $dp = $mitra['drop_point_id'];
$db  = mitraDb();

// AJAX detail
if (($_GET['action'] ?? '') === 'detail') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    try {
        $s = $db->prepare("SELECT t.*, dp.nama_mitra
                             FROM hl_transaksi t
                        LEFT JOIN hl_drop_points dp ON dp.id=t.drop_point_id
                            WHERE t.id=? AND t.tenant_id=? AND t.drop_point_id=? LIMIT 1");
        $s->execute([$id, $tid, $dp]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) { echo json_encode(['error'=>'Order tidak ditemukan']); exit; }
        $i = $db->prepare("SELECT nama_layanan, satuan, jumlah, harga_satuan, subtotal, catatan_item
                             FROM hl_transaksi_item WHERE transaksi_id=? ORDER BY id");
        $i->execute([$id]);
        $r['items'] = $i->fetchAll(PDO::FETCH_ASSOC);
        // Generate WA URL ke pelanggan
        $waUrl = '';
        if (!empty($r['telepon'])) {
            $p = preg_replace('/[^0-9]/','',$r['telepon']);
            if (strpos($p,'0') === 0) $p = '62'.substr($p,1);
            elseif (strpos($p,'62') !== 0) $p = '62'.$p;
            $txt = "Halo {$r['nama_pelanggan']}, mau cek update cucian kamu order {$r['no_order']}?";
            $waUrl = "https://wa.me/$p?text=" . rawurlencode($txt);
        }
        $r['wa_url'] = $waUrl;
        echo json_encode(['ok'=>true, 'order'=>$r]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

$filter = $_GET['filter'] ?? 'all';
$today  = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));

$where = "t.tenant_id=? AND t.drop_point_id=?";
$params = [$tid,$dp];
if ($filter === 'today')   { $where .= " AND DATE(t.tanggal)=?"; $params[] = $today; }
elseif ($filter === 'week'){ $where .= " AND DATE(t.tanggal)>=?"; $params[] = $weekStart; }

$s = $db->prepare("SELECT t.id,t.no_order,t.nama_pelanggan,t.telepon,t.total,
                          t.status_proses,t.status_bayar,t.tanggal,t.created_at,
                          ti.nama_layanan, ti.jumlah, ti.satuan
                     FROM hl_transaksi t
                     LEFT JOIN hl_transaksi_item ti ON ti.transaksi_id=t.id
                          AND ti.tenant_id=t.tenant_id AND ti.id=(SELECT MIN(id) FROM hl_transaksi_item WHERE transaksi_id=t.id)
                    WHERE $where
                    ORDER BY t.id DESC LIMIT 100");
$s->execute($params);
$rows = $s->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/_layout_open.php';
?>

<div style="display:flex;gap:6px;margin-bottom:12px;overflow-x:auto;-webkit-overflow-scrolling:touch">
  <?php foreach (['all'=>'Semua','today'=>'Hari Ini','week'=>'Minggu Ini'] as $k=>$lbl): ?>
    <a href="?filter=<?= $k ?>" style="padding:7px 14px;border-radius:100px;font-size:12px;font-weight:700;text-decoration:none;white-space:nowrap;
        background:<?= $filter===$k?'#0F1C3A':'#fff' ?>;color:<?= $filter===$k?'#fff':'#6B7280' ?>;border:1px solid <?= $filter===$k?'#0F1C3A':'#E5E9F2' ?>">
      <?= $lbl ?>
    </a>
  <?php endforeach; ?>
</div>

<?php if (!$rows): ?>
  <div class="card empty"><span class="ico">📦</span>Belum ada order untuk filter ini.</div>
<?php else: foreach ($rows as $r):
  $st = $r['status_proses'];
?>
  <div class="card" style="padding:13px 14px;cursor:pointer" onclick="openDetail(<?= (int)$r['id'] ?>)">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:5px">
      <div style="min-width:0;flex:1">
        <div style="font-weight:800;color:#0F1C3A;font-size:14px"><?= mitraEsc($r['nama_pelanggan']) ?></div>
        <div style="font-size:11px;color:#9CA3AF;margin-top:1px"><?= mitraEsc($r['no_order']) ?> · <?= date('d M H:i', strtotime($r['created_at'])) ?></div>
      </div>
      <span class="pill pl-<?= mitraEsc($st) ?>"><?= mitraEsc($st) ?></span>
    </div>
    <div style="font-size:12px;color:#374151;margin-top:6px">
      <?= mitraEsc($r['nama_layanan'] ?? '-') ?> · <?= number_format((float)$r['jumlah'],1) ?> <?= mitraEsc($r['satuan'] ?? 'kg') ?>
      · <strong>Rp <?= number_format((int)$r['total'],0,',','.') ?></strong>
    </div>
    <?php if ($r['status_bayar'] === 'lunas'): ?>
      <div style="font-size:10px;color:#065F46;margin-top:3px;font-weight:700">✓ Sudah dibayar</div>
    <?php endif; ?>
    <div style="font-size:10px;color:#9CA3AF;margin-top:6px">Tap untuk detail →</div>
  </div>
<?php endforeach; endif; ?>

<!-- Detail modal -->
<div id="dpModal" style="display:none;position:fixed;inset:0;background:rgba(15,28,58,.55);z-index:200;align-items:flex-end;justify-content:center;padding:0">
  <div style="background:#fff;border-radius:16px 16px 0 0;width:100%;max-width:480px;max-height:90vh;overflow-y:auto">
    <div style="padding:14px 16px;border-bottom:1px solid #E5E9F2;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:5">
      <div style="font-size:15px;font-weight:800;color:#0F1C3A">Detail Order</div>
      <button onclick="closeDetail()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#9CA3AF;padding:0 4px">✕</button>
    </div>
    <div id="dpModalBody" style="padding:16px">
      <div class="empty"><span class="ico">⏳</span>Memuat...</div>
    </div>
  </div>
</div>

<script>
async function openDetail(id){
  const m = document.getElementById('dpModal');
  m.style.display = 'flex';
  const body = document.getElementById('dpModalBody');
  body.innerHTML = '<div class="empty"><span class="ico">⏳</span>Memuat...</div>';
  try {
    const r = await fetch('orders.php?action=detail&id=' + id);
    const d = await r.json();
    if (d.error) { body.innerHTML = '<div class="alert error">'+escDp(d.error)+'</div>'; return; }
    const o = d.order;
    const itemsHtml = (o.items||[]).map(i =>
      `<div style="display:flex;justify-content:space-between;padding:5px 0;font-size:13px;border-bottom:1px dashed #F1F5F9">
        <div>${escDp(i.nama_layanan)} <span style="color:#9CA3AF">· ${Number(i.jumlah).toLocaleString('id-ID')} ${escDp(i.satuan)}</span></div>
        <div style="font-family:monospace">Rp ${Number(i.subtotal).toLocaleString('id-ID')}</div>
      </div>`).join('');
    const bayarBadge = o.status_bayar === 'lunas'
      ? '<span class="pill" style="background:#D1FAE5;color:#065F46">✓ Lunas</span>'
      : o.status_bayar === 'dp'
        ? '<span class="pill" style="background:#FEF3C7;color:#92400E">⚡ DP</span>'
        : '<span class="pill" style="background:#FEE2E2;color:#991B1B">⏳ Belum bayar</span>';
    body.innerHTML = `
      <div style="font-size:12px;color:#9CA3AF;margin-bottom:2px">${escDp(o.no_order)}</div>
      <div style="font-size:1.1rem;font-weight:800;color:#0F1C3A">${escDp(o.nama_pelanggan)}</div>
      <div style="font-size:12px;color:#374151;margin-top:1px">📞 ${escDp(o.telepon||'-')}</div>
      <div style="margin:12px 0;display:flex;gap:6px;flex-wrap:wrap">
        <span class="pill pl-${escDp(o.status_proses)}">${escDp(o.status_proses)}</span>
        ${bayarBadge}
      </div>
      <div style="background:#F8FAFC;border-radius:9px;padding:10px 12px;margin-bottom:10px">${itemsHtml || '<em style="color:#9CA3AF;font-size:12px">Tidak ada item</em>'}</div>
      <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:700;color:#0F1C3A;padding:4px 0;border-top:1px solid #E5E9F2">
        <span>Total</span><span style="font-family:monospace">Rp ${Number(o.total).toLocaleString('id-ID')}</span>
      </div>
      ${o.sisa_bayar > 0 ? `<div style="display:flex;justify-content:space-between;font-size:12px;color:#dc2626;padding:4px 0">
        <span>Sisa bayar</span><span style="font-family:monospace">Rp ${Number(o.sisa_bayar).toLocaleString('id-ID')}</span></div>` : ''}
      ${o.catatan ? `<div style="background:#FEF3C7;border-left:3px solid #F59E0B;padding:8px 10px;border-radius:6px;font-size:12px;color:#92400E;margin-top:10px">📝 ${escDp(o.catatan)}</div>` : ''}
      <div style="display:grid;gap:8px;margin-top:14px">
        ${o.wa_url ? `<a href="${o.wa_url}" target="_blank" class="btn btn-wa">💬 Hubungi Pelanggan via WA</a>` : ''}
        <a href="/ERP/harpy/track.php?order=${encodeURIComponent(o.no_order)}" target="_blank" class="btn" style="background:#F3F4F6;color:#374151;width:100%">🔍 Lihat Tracking</a>
      </div>`;
  } catch(e) {
    body.innerHTML = '<div class="alert error">Gagal memuat: '+escDp(e.message)+'</div>';
  }
}
function closeDetail(){ document.getElementById('dpModal').style.display = 'none'; }
function escDp(s){return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
// Tutup modal kalau klik backdrop
document.getElementById('dpModal').addEventListener('click', function(e){
  if (e.target === this) closeDetail();
});
</script>

<?php require __DIR__ . '/_layout_close.php'; ?>
