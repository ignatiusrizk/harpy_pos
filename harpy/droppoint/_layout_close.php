<?php
// droppoint/_layout_close.php
$_aPage = $activePage ?? '';
?>
</main>
<nav class="mt-nav">
  <a href="dashboard.php"     class="<?= $_aPage==='dashboard'? 'active':'' ?>"><span class="ico">🏠</span>Beranda</a>
  <a href="input_order.php"   class="<?= $_aPage==='input'    ? 'active':'' ?>"><span class="ico">➕</span>Input</a>
  <a href="orders.php"        class="<?= $_aPage==='orders'   ? 'active':'' ?>"><span class="ico">📋</span>Order</a>
  <a href="komisi.php"        class="<?= $_aPage==='komisi'   ? 'active':'' ?>"><span class="ico">💰</span>Komisi</a>
</nav>
</body>
</html>
