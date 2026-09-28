<?php
require_once __DIR__ . '/../bootstrap.php';

$page = dti_page_begin('score');
include __DIR__ . '/../views/head.php';
?>
  <div class="wrap">
    <?php include __DIR__ . '/../views/tabs.php'; ?>
    <section class="statspage" id="scorePage"></section>
  </div>
<?php include __DIR__ . '/../views/foot.php'; ?>
