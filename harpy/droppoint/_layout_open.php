<?php
// droppoint/_layout_open.php — Shell portal mitra (mobile-first)
$_aPage = $activePage ?? '';
$_pageTitle = $pageTitle ?? 'Portal Mitra';
$_namaMitra = $mitra['dp']['nama_mitra'] ?? 'Mitra';
$_userNama  = $mitra['user_nama'] ?? 'Mitra';
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
  <meta name="csrf-token" content="<?= mitraEsc(mitraCsrf()) ?>">
  <title><?= mitraEsc($_pageTitle) ?> · <?= mitraEsc($_namaMitra) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Plus Jakarta Sans',system-ui,sans-serif;background:#F4F7FB;color:#0F1C3A;min-height:100vh;font-size:14px}
.mt-top{background:#0F1C3A;color:#fff;padding:12px 16px;position:sticky;top:0;z-index:90;box-shadow:0 1px 8px rgba(0,0,0,.12);max-width:480px;margin:0 auto}
.mt-top-row{display:flex;justify-content:space-between;align-items:center;gap:8px}
.mt-brand{font-size:13px;font-weight:800;color:#35E8D5;letter-spacing:.03em;min-width:0;flex:1}
.mt-brand small{display:block;font-size:10px;font-weight:500;color:rgba(255,255,255,.55);margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mt-logout{font-size:11px;color:rgba(255,255,255,.6);text-decoration:none;border:1px solid rgba(255,255,255,.15);padding:5px 10px;border-radius:6px;flex-shrink:0}
.mt-main{max-width:480px;margin:0 auto;padding:14px 14px 80px}
.mt-nav{position:fixed;bottom:0;left:50%;transform:translateX(-50%);width:100%;max-width:480px;background:#fff;border-top:1px solid #E5E9F2;display:flex;z-index:100;box-shadow:0 -2px 10px rgba(0,0,0,.08)}
.mt-nav a{flex:1;text-align:center;padding:10px 4px;text-decoration:none;color:#9CA3AF;font-size:10px;font-weight:600}
.mt-nav a .ico{display:block;font-size:18px;margin-bottom:2px}
.mt-nav a.active{color:#0891B2}
.mt-nav a.active .ico{color:#0891B2}
.card{background:#fff;border-radius:12px;padding:16px;box-shadow:0 1px 4px rgba(0,0,0,.04);margin-bottom:12px}
.card h2{font-size:14px;font-weight:800;color:#0F1C3A;margin-bottom:10px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:11px 16px;border-radius:9px;font-weight:700;font-size:14px;border:none;cursor:pointer;font-family:inherit;text-decoration:none}
.btn-primary{background:#0F1C3A;color:#fff;width:100%}
.btn-primary:hover{background:#1a2d52}
.btn-teal{background:#35E8D5;color:#0F1C3A;width:100%}
.btn-wa{background:#25D366;color:#fff;width:100%}
.fld{margin-bottom:12px}
.fld label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}
.fld input,.fld select,.fld textarea{width:100%;padding:11px 13px;border:1px solid #E5E9F2;border-radius:8px;font-family:inherit;font-size:14px;background:#fff}
.fld textarea{resize:vertical;min-height:64px}
.alert{padding:11px 14px;border-radius:9px;font-size:13px;margin-bottom:12px}
.alert.success{background:#D1FAE5;color:#065F46;border:1px solid #6EE7B7}
.alert.error{background:#FEE2E2;color:#991B1B;border:1px solid #FCA5A5}
.empty{text-align:center;padding:40px 20px;color:#9CA3AF;font-size:13px}
.empty .ico{font-size:42px;margin-bottom:8px;display:block}
.pill{font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px;text-transform:uppercase}
.pl-masuk{background:#DBEAFE;color:#1E40AF}
.pl-cuci,.pl-kering,.pl-setrika{background:#FEF3C7;color:#92400E}
.pl-siap{background:#FED7AA;color:#9A3412}
.pl-diambil,.pl-selesai{background:#D1FAE5;color:#065F46}
.stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px}
.stat-card{background:#fff;border-radius:10px;padding:12px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.stat-num{font-size:1.5rem;font-weight:800;color:#0F1C3A;font-family:monospace;line-height:1}
.stat-label{font-size:11px;color:#6B7280;font-weight:600;margin-top:3px}
</style>
</head>
<body>
<div class="mt-top">
  <div class="mt-top-row">
    <div class="mt-brand">📦 <?= mitraEsc($_namaMitra) ?> <small>Drop Point · <?= mitraEsc($_userNama) ?></small></div>
    <a href="/ERP/harpy/logout.php" class="mt-logout" onclick="return confirm('Logout?')">Keluar</a>
  </div>
</div>
<main class="mt-main">
