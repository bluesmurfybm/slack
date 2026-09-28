<?php
if (!isset($page)) {
    http_response_code(404);
    exit;
}
?>
<nav class="tabs">
  <?php foreach (DTI_PAGES as $key => $tab): if (!$tab['admin']) continue; ?>
  <a href="<?= dti_page_href($page, $key) ?>"<?= $key === $page['key'] ? ' class="on" aria-current="page"' : '' ?>><?= dti_h($tab['label']) ?></a>
  <?php endforeach; ?>
</nav>
