<?php
// droppoint/dashboard.php — Beranda mitra
define('ROOT', dirname(__DIR__));
require_once __DIR__ . '/middleware/mitra_guard.php';

$activePage = 'dashboard';
$pageTitle  = 'Beranda';
$tid = $mitra['tenant_id']; $oid = $mitra['outlet_id']; $dp = $mitra['drop_point_id'];
$db  = mitraDb();

// Stats
$today = date('Y-m-d');
$startMonth = date('Y-m-01');

$s = $db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=? AND drop_point_id=? AND DATE(tanggal)=?");
$s->execute([$tid,$dp,$today]); $orderToday = (int)$s->fetchColumn();

$s = $db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=? AND drop_point_id=? AND DATE(tanggal) BETWEEN ? AND ?");
$s->execute([$tid,$dp,$startMonth,$today]); $orderMonth = (int)$s->fetchColumn();

// Komisi pending bulan ini (rekap belum dibayar)
$s = $db->prepare("SELECT COALESCE(SUM(total_komisi),0) FROM hl_komisi_rekap
                    WHERE tenant_id=? AND drop_point_id=? AND status='pending'");
$s->execute([$tid,$dp]); $komisiPending = (int)$s->fetchColumn();

// 5 order terbaru
$s = $db->prepare("SELECT id, nama_pelanggan, total, status_proses, created_at
                     FROM hl_transaksi
                    WHERE tenant_id=? AND drop_point_id=?
                    ORDER BY id DESC LIMIT 5");
$s->execute([$tid,$dp]); $recent = $s->fetchAll(PDO::FETCH_ASSOC);

// Kontak outlet (untuk tombol Hubungi Outlet)
$s = $db->prepare("SELECT nama_outlet, telepon, alamat FROM outlets WHERE id=? AND tenant_id=?");
$s->execute([$oid, $tid]);
$outletInfo = $s->fetch(PDO::FETCH_ASSOC) ?: [];
$outletWa = '';
if (!empty($outletInfo['telepon'])) {
    $w = preg_replace('/[^0-9]/','',$outletInfo['telepon']);
    if (strpos($w,'0') === 0) $w = '62'.substr($w,1);
    elseif (strpos($w,'62') !== 0) $w = '62'.$w;
    $outletWa = $w;
}

require __DIR__ . '/_layout_open.php';
?>

<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-num"><?= $orderToday ?></div>
    <div class="stat-label">Order Hari Ini</div>
  </div>
  <div class="stat-card">
    <div class="stat-num"><?= $orderMonth ?></div>
    <div class="stat-label">Bulan Ini</div>
  </div>
</div>

<div class="card" style="background:linear-gradient(135deg,#0F1C3A,#1a2d52);color:#fff">
  <div style="font-size:11px;font-weight:700;letter-spacing:.08em;color:#35E8D5;margin-bottom:4px">💰 KOMISI PENDING</div>
  <div style="font-size:1.6rem;font-weight:800;font-family:monospace">Rp <?= number_format($komisiPending,0,',','.') ?></div>
  <a href="komisi.php" style="font-size:11px;color:rgba(255,255,255,.7);text-decoration:underline;display:inline-block;margin-top:6px">Lihat rincian →</a>
</div>

<a href="input_order.php" class="btn btn-teal" style="margin-bottom:16px">➕ Input Order Baru</a>

<div class="card">
  <h2>📋 Order Terbaru</h2>
  <?php if (!$recent): ?>
    <div class="empty"><span class="ico">📦</span>Belum ada order. Mulai input order pertama!</div>
  <?php else: foreach ($recent as $r):
    $st = $r['status_proses'];
  ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid #F3F4F6">
      <div style="min-width:0;flex:1">
        <div style="font-weight:700;color:#0F1C3A;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= mitraEsc($r['nama_pelanggan']) ?></div>
        <div style="font-size:11px;color:#9CA3AF">Rp <?= number_format((int)$r['total'],0,',','.') ?> · <?= date('d M H:i', strtotime($r['created_at'])) ?></div>
      </div>
      <span class="pill pl-<?= mitraEsc($st) ?>"><?= mitraEsc($st) ?></span>
    </div>
  <?php endforeach; endif; ?>
  <a href="orders.php" style="display:block;text-align:center;font-size:12px;color:#0891B2;font-weight:700;margin-top:10px">Lihat semua order →</a>
</div>

<!-- Kontak outlet -->
<?php if ($outletInfo): ?>
<div class="card">
  <h2>📞 Butuh Bantuan?</h2>
  <div style="font-size:13px;color:#374151;margin-bottom:4px">
    <strong><?= mitraEsc($outletInfo['nama_outlet'] ?? '') ?></strong>
  </div>
  <?php if (!empty($outletInfo['alamat'])): ?>
    <div style="font-size:11px;color:#9CA3AF;margin-bottom:10px"><?= mitraEsc($outletInfo['alamat']) ?></div>
  <?php endif; ?>
  <?php if ($outletWa): ?>
    <a href="https://wa.me/<?= mitraEsc($outletWa) ?>?text=<?= urlencode('Halo, saya '.($mitra['dp']['nama_mitra'] ?? '').' (drop point). Saya mau tanya...') ?>"
       target="_blank" class="btn btn-wa">
      💬 Hubungi Outlet via WhatsApp
    </a>
  <?php else: ?>
    <div style="font-size:11px;color:#9CA3AF">Nomor WhatsApp outlet belum diatur.</div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_layout_close.php'; ?>
