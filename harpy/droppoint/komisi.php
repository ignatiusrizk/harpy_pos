<?php
// droppoint/komisi.php — Rekap komisi mitra
define('ROOT', dirname(__DIR__));
require_once __DIR__ . '/middleware/mitra_guard.php';

$activePage = 'komisi';
$pageTitle  = 'Komisi Saya';
$tid = $mitra['tenant_id']; $dp = $mitra['drop_point_id'];
$db  = mitraDb();

// Periode bulan ini (live calculation, walau belum di-generate ke rekap)
$mStart = date('Y-m-01');
$mEnd   = date('Y-m-d');

// Live count order + omset bulan ini
$s = $db->prepare("SELECT COUNT(*) c, COALESCE(SUM(total),0) omset
                     FROM hl_transaksi
                    WHERE tenant_id=? AND drop_point_id=? AND DATE(tanggal) BETWEEN ? AND ?");
$s->execute([$tid,$dp,$mStart,$mEnd]);
$cur = $s->fetch(PDO::FETCH_ASSOC);

// Total kg bulan ini (sum jumlah dari items utama)
$s = $db->prepare("SELECT COALESCE(SUM(ti.jumlah),0) FROM hl_transaksi t
                     JOIN hl_transaksi_item ti ON ti.transaksi_id=t.id
                          AND ti.id=(SELECT MIN(id) FROM hl_transaksi_item WHERE transaksi_id=t.id)
                    WHERE t.tenant_id=? AND t.drop_point_id=? AND DATE(t.tanggal) BETWEEN ? AND ?");
$s->execute([$tid,$dp,$mStart,$mEnd]);
$totalKg = (float)$s->fetchColumn();

// Hitung komisi sesuai model
$dpRow = $mitra['dp'];
$model = $dpRow['komisi_model'];
$komisi = 0;
$breakdown = [];
if ($model === 'per_kg' || $model === 'kombinasi') {
    $k = (int)$dpRow['komisi_per_kg'] * (int)round($totalKg);
    $komisi += $k;
    if ($k > 0) $breakdown[] = "Rp ".number_format($dpRow['komisi_per_kg'],0,',','.')."/kg × ".number_format($totalKg,1)." kg = Rp ".number_format($k,0,',','.');
}
if ($model === 'persen' || $model === 'kombinasi') {
    $k = (int)round((float)$dpRow['komisi_persen'] / 100 * (int)$cur['omset']);
    $komisi += $k;
    if ($k > 0) $breakdown[] = "{$dpRow['komisi_persen']}% × Rp ".number_format($cur['omset'],0,',','.')." = Rp ".number_format($k,0,',','.');
}
if ($model === 'flat' || $model === 'kombinasi') {
    $k = (int)$dpRow['komisi_flat'] * (int)$cur['c'];
    $komisi += $k;
    if ($k > 0) $breakdown[] = "Rp ".number_format($dpRow['komisi_flat'],0,',','.')." × ".$cur['c']." order = Rp ".number_format($k,0,',','.');
}

// Riwayat rekap sebelumnya
$s = $db->prepare("SELECT * FROM hl_komisi_rekap
                    WHERE tenant_id=? AND drop_point_id=?
                    ORDER BY periode_start DESC LIMIT 12");
$s->execute([$tid,$dp]);
$history = $s->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/_layout_open.php';
?>

<div class="card" style="background:linear-gradient(135deg,#0F1C3A,#1a2d52);color:#fff">
  <div style="font-size:11px;font-weight:700;letter-spacing:.08em;color:#35E8D5;margin-bottom:4px">💰 PERIODE BERJALAN</div>
  <div style="font-size:12px;color:rgba(255,255,255,.6);margin-bottom:10px"><?= date('d M', strtotime($mStart)) ?> – <?= date('d M Y', strtotime($mEnd)) ?></div>
  <div style="font-size:1.9rem;font-weight:800;font-family:monospace">Rp <?= number_format($komisi,0,',','.') ?></div>
  <div style="font-size:11px;color:rgba(255,255,255,.6);margin-top:6px;line-height:1.6">
    <strong style="color:rgba(255,255,255,.85)">Rincian:</strong><br>
    <?php if (!$breakdown): ?><em>Belum ada order bulan ini</em><?php else: foreach ($breakdown as $b) echo mitraEsc($b).'<br>'; endif; ?>
  </div>
  <div style="border-top:1px solid rgba(255,255,255,.1);margin-top:10px;padding-top:8px;display:flex;justify-content:space-between;font-size:12px">
    <span><?= $cur['c'] ?> order</span>
    <span><?= number_format($totalKg,1) ?> kg</span>
    <span>Omset Rp <?= number_format($cur['omset'],0,',','.') ?></span>
  </div>
  <div style="font-size:10px;color:rgba(255,255,255,.4);margin-top:8px;font-style:italic">
    * Estimasi live. Rekap final di-generate outlet sesuai periode.
  </div>
</div>

<div class="card">
  <h2>🧾 Riwayat Rekap</h2>
  <?php if (!$history): ?>
    <div class="empty"><span class="ico">📊</span>Belum ada rekap dari outlet</div>
  <?php else: foreach ($history as $h): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #F3F4F6">
      <div>
        <div style="font-weight:700;color:#0F1C3A;font-size:13px">
          <?= date('d M', strtotime($h['periode_start'])) ?> – <?= date('d M Y', strtotime($h['periode_end'])) ?>
        </div>
        <div style="font-size:11px;color:#9CA3AF;margin-top:1px">
          <?= (int)$h['total_order'] ?> order · <?= number_format((float)$h['total_kg'],1) ?> kg
        </div>
      </div>
      <div style="text-align:right">
        <div style="font-family:monospace;font-weight:800;color:#0F1C3A;font-size:14px">Rp <?= number_format((int)$h['total_komisi'],0,',','.') ?></div>
        <?php if ($h['status'] === 'dibayar'): ?>
          <div style="font-size:10px;color:#065F46;font-weight:700">✓ Dibayar <?= date('d M', strtotime($h['dibayar_at'])) ?></div>
        <?php else: ?>
          <div style="font-size:10px;color:#92400E;font-weight:700">⏳ Pending</div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>

<?php require __DIR__ . '/_layout_close.php'; ?>
