<?php
// track.php — Customer order tracking (public, no login needed)

require_once 'auth.php'; // hanya untuk getDB()

$order_no = strtoupper(trim($_GET['order'] ?? ''));
$telepon  = preg_replace('/[^0-9]/', '', $_GET['telp'] ?? '');
$data     = null;
$error    = '';
$searched = false;

if ($order_no || $telepon) {
    $searched = true;
    $pdo = getDB();

    if ($order_no) {
        $stmt = $pdo->prepare("SELECT t.*, GROUP_CONCAT(i.nama_layanan ORDER BY i.id SEPARATOR '||') as layanan_names,
            GROUP_CONCAT(i.jumlah ORDER BY i.id SEPARATOR '||') as layanan_jml,
            GROUP_CONCAT(i.satuan ORDER BY i.id SEPARATOR '||') as layanan_sat,
            GROUP_CONCAT(i.subtotal ORDER BY i.id SEPARATOR '||') as layanan_sub
            FROM hl_transaksi t
            LEFT JOIN hl_transaksi_item i ON i.transaksi_id = t.id
            WHERE t.no_order = ?
            GROUP BY t.id");
        $stmt->execute([$order_no]);
        $data = $stmt->fetch();
        if (!$data) $error = 'No. order tidak ditemukan. Pastikan penulisan sudah benar.';
    } elseif ($telepon) {
        $stmt = $pdo->prepare("SELECT t.*, GROUP_CONCAT(i.nama_layanan ORDER BY i.id SEPARATOR '||') as layanan_names,
            GROUP_CONCAT(i.jumlah ORDER BY i.id SEPARATOR '||') as layanan_jml,
            GROUP_CONCAT(i.satuan ORDER BY i.id SEPARATOR '||') as layanan_sat,
            GROUP_CONCAT(i.subtotal ORDER BY i.id SEPARATOR '||') as layanan_sub
            FROM hl_transaksi t
            LEFT JOIN hl_transaksi_item i ON i.transaksi_id = t.id
            WHERE REPLACE(REPLACE(REPLACE(t.telepon,' ',''),'-',''),'+','') LIKE ?
            AND t.status_proses != 'diambil'
            GROUP BY t.id ORDER BY t.created_at DESC LIMIT 5");
        $like = '%' . substr($telepon, -8) . '%';
        $stmt->execute([$like]);
        $rows = $stmt->fetchAll();
        if (empty($rows)) $error = 'Tidak ada order aktif untuk nomor tersebut.';
        elseif (count($rows) === 1) $data = $rows[0];
        else $data = $rows; // multiple results
    }
}

function statusInfo($s) {
    $map = [
        'masuk'   => ['label'=>'Diterima',           'icon'=>'📥','color'=>'#3B82F6','step'=>1],
        'cuci'    => ['label'=>'Sedang Dicuci',       'icon'=>'🫧','color'=>'#F59E0B','step'=>2],
        'kering'  => ['label'=>'Sedang Dikeringkan',  'icon'=>'💨','color'=>'#F59E0B','step'=>3],
        'setrika' => ['label'=>'Sedang Disetrika',    'icon'=>'👔','color'=>'#8B5CF6','step'=>4],
        'siap'    => ['label'=>'Siap Diambil',        'icon'=>'✅','color'=>'#10B981','step'=>5],
        'diambil' => ['label'=>'Sudah Diambil',       'icon'=>'📦','color'=>'#6B7280','step'=>6],
    ];
    return $map[$s] ?? ['label'=>$s,'icon'=>'❓','color'=>'#6B7280','step'=>0];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Cek Status Laundry — Harpy Laundry</title>
<meta name="description" content="Cek status laundry Anda di Harpy Laundry"/>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{
  --teal:#35E8D5;--teal-d:#1CC4B2;--teal-bg:#E8FBF9;
  --navy:#1B2D5A;--navy-d:#0F1C3A;
  --white:#fff;--off:#F7F8FC;--light:#EEF1F8;
  --gray:#6C7A8D;--dark:#1C1C2E;
  --red:#EF4444;--green:#10B981;--yellow:#F59E0B;
  --font:'Plus Jakarta Sans',sans-serif;--mono:'DM Mono',monospace;
  --r:12px;--r-lg:20px;
  --shadow:0 4px 20px rgba(27,45,90,.1);
  --shadow-lg:0 12px 40px rgba(27,45,90,.16);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:15px}
body{font-family:var(--font);background:var(--navy-d);min-height:100vh;color:var(--dark)}
body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(255,255,255,.02) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.02) 1px,transparent 1px);background-size:48px 48px;pointer-events:none}

/* TOPBAR */
.topbar{background:rgba(255,255,255,.04);backdrop-filter:blur(20px);border-bottom:1px solid rgba(255,255,255,.08);padding:0 24px;height:60px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:16px;color:var(--white);text-decoration:none}
.brand span{color:var(--teal)}
.topbar-link{font-size:13px;color:rgba(255,255,255,.5);text-decoration:none;transition:color .2s}
.topbar-link:hover{color:var(--white)}

/* HERO */
.hero{padding:48px 20px 0;text-align:center;position:relative;z-index:1}
.hero h1{font-size:clamp(1.6rem,4vw,2.4rem);font-weight:900;color:var(--white);margin-bottom:10px;line-height:1.2}
.hero h1 span{color:var(--teal)}
.hero p{font-size:15px;color:rgba(255,255,255,.5);margin-bottom:32px}

/* SEARCH CARD */
.search-wrap{max-width:520px;margin:0 auto 32px;background:rgba(255,255,255,.05);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.1);border-radius:var(--r-lg);padding:28px;box-shadow:var(--shadow-lg)}
.tabs{display:flex;gap:4px;background:rgba(255,255,255,.06);border-radius:10px;padding:4px;margin-bottom:20px}
.stab{flex:1;padding:9px;border-radius:8px;font-size:13px;font-weight:600;color:rgba(255,255,255,.5);cursor:pointer;text-align:center;transition:all .2s;border:none;background:transparent;font-family:var(--font)}
.stab.active{background:var(--teal);color:var(--navy-d)}
.input-group{display:flex;gap:8px}
.input-group input{flex:1;padding:12px 16px;background:rgba(255,255,255,.08);border:1.5px solid rgba(255,255,255,.12);border-radius:var(--r);font-family:var(--font);font-size:15px;color:var(--white);outline:none;transition:all .2s}
.input-group input::placeholder{color:rgba(255,255,255,.3)}
.input-group input:focus{border-color:var(--teal);background:rgba(53,232,213,.08);box-shadow:0 0 0 3px rgba(53,232,213,.1)}
.btn-cek{padding:12px 24px;background:var(--teal);color:var(--navy-d);border:none;border-radius:var(--r);font-family:var(--font);font-size:14px;font-weight:700;cursor:pointer;transition:all .2s;white-space:nowrap}
.btn-cek:hover{background:var(--teal-d);transform:translateY(-1px)}

/* RESULT AREA */
.result-wrap{max-width:640px;margin:0 auto;padding:0 20px 48px;position:relative;z-index:1}

/* ERROR */
.error-box{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);border-radius:var(--r-lg);padding:24px;text-align:center;color:#FCA5A5;margin-bottom:20px}
.error-box .e-icon{font-size:2rem;margin-bottom:8px}
.error-box p{font-size:14px;line-height:1.6}

/* ORDER CARD */
.order-card{background:var(--white);border-radius:var(--r-lg);overflow:hidden;box-shadow:var(--shadow-lg);margin-bottom:16px}
.order-top{padding:20px 24px;display:flex;justify-content:space-between;align-items:flex-start;border-bottom:1px solid var(--light)}
.order-no{font-family:var(--mono);font-size:14px;font-weight:700;color:var(--navy);letter-spacing:.06em}
.order-date{font-size:12px;color:var(--gray);margin-top:3px}
.order-nama{font-size:16px;font-weight:700;color:var(--navy)}
.order-telp{font-size:13px;color:var(--gray)}

/* STATUS BADGE BIG */
.status-big{display:flex;align-items:center;gap:10px;padding:16px 24px;border-bottom:1px solid var(--light)}
.status-icon{font-size:2rem;line-height:1}
.status-text{font-size:18px;font-weight:800;color:var(--navy)}
.status-sub{font-size:13px;color:var(--gray);margin-top:2px}

/* PROGRESS BAR */
.progress-wrap{padding:16px 24px;border-bottom:1px solid var(--light)}
.progress-steps{display:flex;align-items:center;gap:0;position:relative}
.step{flex:1;display:flex;flex-direction:column;align-items:center;position:relative}
.step-dot{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;z-index:1;transition:all .3s}
.step-dot.done{background:var(--green);color:white}
.step-dot.active{background:var(--teal);color:var(--navy-d);box-shadow:0 0 0 4px rgba(53,232,213,.2)}
.step-dot.pending{background:var(--light);color:var(--gray)}
.step-label{font-size:10px;color:var(--gray);margin-top:5px;text-align:center;line-height:1.3;max-width:52px}
.step-label.active{color:var(--navy);font-weight:600}
.step-line{position:absolute;top:14px;left:50%;right:-50%;height:3px;z-index:0}
.step-line.done{background:var(--green)}
.step-line.pending{background:var(--light)}

/* ITEMS */
.items-wrap{padding:16px 24px;border-bottom:1px solid var(--light)}
.items-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--gray);margin-bottom:10px}
.item-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid var(--light);font-size:14px}
.item-row:last-child{border-bottom:none}
.item-nama{font-weight:500;color:var(--dark)}
.item-det{font-size:12px;color:var(--gray)}
.item-price{font-family:var(--mono);font-size:13px;font-weight:600;color:var(--navy)}

/* BAYAR */
.bayar-wrap{padding:16px 24px;background:var(--off)}
.bayar-row{display:flex;justify-content:space-between;font-size:14px;padding:4px 0}
.bayar-label{color:var(--gray)}
.bayar-value{font-family:var(--mono);font-weight:600}
.bayar-total{border-top:2px solid var(--light);margin-top:8px;padding-top:10px}
.bayar-total .bayar-label{font-weight:700;color:var(--navy)}
.bayar-total .bayar-value{font-size:1.2rem;color:var(--navy)}
.sisa-badge{display:inline-block;background:#FEE2E2;color:var(--red);font-size:12px;font-weight:700;padding:4px 12px;border-radius:100px;margin-top:8px}
.lunas-badge{display:inline-block;background:#D1FAE5;color:var(--green);font-size:12px;font-weight:700;padding:4px 12px;border-radius:100px;margin-top:8px}

/* CATATAN */
.catatan-box{padding:14px 24px;background:#FFFBEB;border-top:1px solid #FDE68A;font-size:13px;color:#92400E}

/* ESTIMASI */
.est-box{padding:12px 24px;border-bottom:1px solid var(--light);display:flex;align-items:center;gap:10px;font-size:14px}
.est-icon{font-size:1.2rem}
.est-label{color:var(--gray)}
.est-date{font-weight:700;color:var(--navy)}

/* MULTIPLE RESULTS */
.multi-card{background:var(--white);border-radius:var(--r-lg);padding:18px 20px;margin-bottom:10px;cursor:pointer;transition:all .2s;box-shadow:var(--shadow);border:2px solid transparent;text-decoration:none;display:block}
.multi-card:hover{border-color:var(--teal);transform:translateY(-2px)}
.multi-no{font-family:var(--mono);font-size:13px;font-weight:700;color:var(--teal-d)}
.multi-nama{font-size:15px;font-weight:700;color:var(--navy);margin:4px 0}
.multi-meta{font-size:12px;color:var(--gray)}

/* FOOTER */
.page-footer{text-align:center;padding:24px;color:rgba(255,255,255,.3);font-size:13px}
.page-footer a{color:rgba(255,255,255,.5);text-decoration:none}
.page-footer a:hover{color:var(--teal)}
</style>
</head>
<body>

<div class="topbar">
  <a href="https://harpy.id" class="brand">🫧 Harpy <span>Laundry</span></a>
  <a href="https://harpy.id" class="topbar-link">← Kembali ke harpy.id</a>
</div>

<div class="hero">
  <h1>Cek Status <span>Laundry</span> Anda</h1>
  <p>Masukkan no. order atau nomor HP untuk melihat status terkini</p>
</div>

<!-- SEARCH FORM -->
<div style="max-width:520px;margin:0 auto 32px;padding:0 20px;position:relative;z-index:1">
  <div class="search-wrap">
    <div class="tabs">
      <button class="stab active" id="tabOrder" onclick="switchSearchTab('order')">📋 No. Order</button>
      <button class="stab" id="tabHP" onclick="switchSearchTab('hp')">📱 No. HP</button>
    </div>

    <form method="GET" action="track.php">
      <div id="searchOrder">
        <div class="input-group">
          <input type="text" name="order" value="<?= htmlspecialchars($order_no) ?>"
            placeholder="Contoh: HL-20260501-001"
            style="text-transform:uppercase;letter-spacing:.06em;font-family:var(--mono)"
            oninput="this.value=this.value.toUpperCase()" autofocus/>
          <button type="submit" class="btn-cek">🔍 Cek</button>
        </div>
      </div>
      <div id="searchHP" style="display:none">
        <div class="input-group">
          <input type="tel" name="telp" value="<?= htmlspecialchars($_GET['telp'] ?? '') ?>"
            placeholder="Contoh: 08123456789"/>
          <button type="submit" class="btn-cek">🔍 Cek</button>
        </div>
        <p style="font-size:12px;color:rgba(255,255,255,.3);margin-top:8px">Akan menampilkan order aktif yang belum diambil</p>
      </div>
    </form>
  </div>
</div>

<!-- RESULT -->
<div class="result-wrap">

<?php if ($searched && $error): ?>
  <div class="error-box">
    <div class="e-icon">😔</div>
    <p><?= htmlspecialchars($error) ?></p>
    <p style="margin-top:8px;font-size:12px">Hubungi kami: <strong>+62 896-1525-9302</strong></p>
  </div>

<?php elseif ($searched && is_array($data) && isset($data[0]['no_order'])): ?>
  <!-- Multiple results -->
  <p style="color:rgba(255,255,255,.6);font-size:14px;margin-bottom:12px">Ditemukan <?= count($data) ?> order aktif:</p>
  <?php foreach ($data as $row):
    $si = statusInfo($row['status_proses']);
  ?>
  <a href="track.php?order=<?= urlencode($row['no_order']) ?>" class="multi-card">
    <div class="multi-no"><?= htmlspecialchars($row['no_order']) ?></div>
    <div class="multi-nama"><?= htmlspecialchars($row['nama_pelanggan']) ?></div>
    <div class="multi-meta">
      <?= $si['icon'] ?> <?= $si['label'] ?> · <?= date('d M Y', strtotime($row['tanggal'])) ?>
      · Total Rp <?= number_format($row['total'],0,',','.') ?>
    </div>
  </a>
  <?php endforeach; ?>

<?php elseif ($searched && $data && isset($data['no_order'])): ?>
  <?php
    $si    = statusInfo($data['status_proses']);
    $steps = ['masuk','cuci','kering','setrika','siap','diambil'];
    $curStep = $si['step'];
    $layananNames = explode('||', $data['layanan_names'] ?? '');
    $layananJml   = explode('||', $data['layanan_jml']   ?? '');
    $layananSat   = explode('||', $data['layanan_sat']   ?? '');
    $layananSub   = explode('||', $data['layanan_sub']   ?? '');
    $isFull = floatval($data['sisa_bayar']) <= 0;
  ?>
  <div class="order-card">

    <!-- TOP INFO -->
    <div class="order-top">
      <div>
        <div class="order-no"><?= htmlspecialchars($data['no_order']) ?></div>
        <div class="order-date">Masuk: <?= date('d M Y', strtotime($data['tanggal'])) ?></div>
      </div>
      <div style="text-align:right">
        <div class="order-nama"><?= htmlspecialchars($data['nama_pelanggan']) ?></div>
        <?php if ($data['telepon']): ?>
        <div class="order-telp"><?= htmlspecialchars($data['telepon']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- STATUS BESAR -->
    <div class="status-big" style="background:<?= $si['color'] ?>18;border-left:4px solid <?= $si['color'] ?>">
      <div class="status-icon"><?= $si['icon'] ?></div>
      <div>
        <div class="status-text"><?= $si['label'] ?></div>
        <?php if ($data['estimasi_selesai'] && $data['status_proses'] !== 'diambil'): ?>
        <div class="status-sub">Estimasi selesai: <?= date('d M Y', strtotime($data['estimasi_selesai'])) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- PROGRESS STEPS -->
    <div class="progress-wrap">
      <div class="progress-steps">
        <?php
        $stepLabels = ['masuk'=>'Diterima','cuci'=>'Cuci','kering'=>'Kering','setrika'=>'Setrika','siap'=>'Siap','diambil'=>'Diambil'];
        $stepIcons  = ['masuk'=>'📥','cuci'=>'🫧','kering'=>'💨','setrika'=>'👔','siap'=>'✅','diambil'=>'📦'];
        foreach ($steps as $idx => $s):
          $sInfo = statusInfo($s);
          $isDone   = $sInfo['step'] < $curStep;
          $isActive = $sInfo['step'] === $curStep;
          $dotClass = $isDone ? 'done' : ($isActive ? 'active' : 'pending');
          $isLast   = $idx === count($steps)-1;
        ?>
        <div class="step">
          <?php if (!$isLast): ?>
          <div class="step-line <?= $isDone ? 'done' : 'pending' ?>"></div>
          <?php endif; ?>
          <div class="step-dot <?= $dotClass ?>">
            <?= $isDone ? '✓' : ($isActive ? $stepIcons[$s] : ($sInfo['step']+1)) ?>
          </div>
          <div class="step-label <?= $isActive?'active':'' ?>"><?= $stepLabels[$s] ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ITEMS -->
    <?php if ($layananNames[0]): ?>
    <div class="items-wrap">
      <div class="items-title">Layanan</div>
      <?php foreach ($layananNames as $idx => $nama): if (!$nama) continue; ?>
      <div class="item-row">
        <div>
          <div class="item-nama"><?= htmlspecialchars($nama) ?></div>
          <div class="item-det"><?= htmlspecialchars($layananJml[$idx] ?? '') ?> <?= htmlspecialchars($layananSat[$idx] ?? '') ?></div>
        </div>
        <div class="item-price">Rp <?= number_format(floatval($layananSub[$idx] ?? 0),0,',','.') ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- TOTAL & BAYAR -->
    <div class="bayar-wrap">
      <?php if (floatval($data['diskon'] ?? 0) > 0): ?>
      <div class="bayar-row">
        <span class="bayar-label">Subtotal</span>
        <span class="bayar-value">Rp <?= number_format(floatval($data['subtotal']??$data['total']),0,',','.') ?></span>
      </div>
      <div class="bayar-row">
        <span class="bayar-label">Diskon</span>
        <span class="bayar-value" style="color:var(--green)">- Rp <?= number_format(floatval($data['diskon']),0,',','.') ?></span>
      </div>
      <?php endif; ?>
      <div class="bayar-row bayar-total">
        <span class="bayar-label">Total</span>
        <span class="bayar-value">Rp <?= number_format(floatval($data['total']),0,',','.') ?></span>
      </div>
      <?php if (floatval($data['dp'] ?? 0) > 0): ?>
      <div class="bayar-row">
        <span class="bayar-label">Sudah dibayar</span>
        <span class="bayar-value" style="color:var(--green)">Rp <?= number_format(floatval($data['dp']),0,',','.') ?></span>
      </div>
      <?php endif; ?>
      <div>
        <?php if ($isFull): ?>
        <span class="lunas-badge">✅ LUNAS</span>
        <?php else: ?>
        <span class="sisa-badge">⚠️ Sisa: Rp <?= number_format(floatval($data['sisa_bayar']),0,',','.') ?></span>
        <?php endif; ?>
      </div>
    </div>

    <!-- CATATAN -->
    <?php if (!empty($data['catatan'])): ?>
    <div class="catatan-box">
      📝 <strong>Catatan:</strong> <?= htmlspecialchars($data['catatan']) ?>
    </div>
    <?php endif; ?>

  </div>

  <!-- CEK ORDER LAIN -->
  <div style="text-align:center;margin-top:16px">
    <a href="track.php" style="color:rgba(255,255,255,.5);font-size:13px;text-decoration:none">← Cek order lain</a>
  </div>

<?php elseif (!$searched): ?>
  <!-- EMPTY STATE / PANDUAN -->
  <div style="text-align:center;padding:16px 0;color:rgba(255,255,255,.4);font-size:14px">
    <div style="font-size:2.5rem;margin-bottom:12px;opacity:.5">🫧</div>
    <p>Masukkan no. order dari struk Anda</p>
    <p style="font-size:12px;margin-top:6px">Format: <span style="font-family:var(--mono);color:var(--teal)">HL-YYYYMMDD-001</span></p>
  </div>
<?php endif; ?>

</div>

<div class="page-footer">
  <p>🫧 <strong>Harpy Laundry</strong> · Jl. Rawa Selatan IV No.1, Johar Baru, Jakarta Pusat</p>
  <p style="margin-top:4px">+62 896-1525-9302 · <a href="https://harpy.id">harpy.id</a></p>
</div>

<script>
function switchSearchTab(tab) {
  document.getElementById('searchOrder').style.display = tab==='order' ? 'block' : 'none';
  document.getElementById('searchHP').style.display    = tab==='hp'    ? 'block' : 'none';
  document.getElementById('tabOrder').classList.toggle('active', tab==='order');
  document.getElementById('tabHP').classList.toggle('active', tab==='hp');
}

// Auto switch tab if searching by HP
<?php if (!empty($_GET['telp'])): ?>
switchSearchTab('hp');
<?php endif; ?>
</script>
</body>
</html>
