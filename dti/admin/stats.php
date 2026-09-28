<?php
require_once __DIR__ . '/../bootstrap.php';

$page = dti_page_begin('stats');
include __DIR__ . '/../views/head.php';
?>
  <div class="wrap">
    <?php include __DIR__ . '/../views/tabs.php'; ?>
    <section class="statspage" id="statsPage"></section>
  </div>
<?php include __DIR__ . '/../views/foot.php'; ?>
