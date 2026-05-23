<?php
// droppoint/orders.php — Daftar order mitra
define('ROOT', dirname(__DIR__));
require_once __DIR__ . '/middleware/mitra_guard.php';

$activePage = 'orders';
$pageTitle  = 'Order Saya';
$tid = $mitra['tenant_id']; $dp = $mitra['drop_point_id'];
$db  = mitraDb();

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
  <div class="card" style="padding:13px 14px">
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
  </div>
<?php endforeach; endif; ?>

<?php require __DIR__ . '/_layout_close.php'; ?>
