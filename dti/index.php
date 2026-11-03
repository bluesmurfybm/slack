<?php
require_once __DIR__ . '/bootstrap.php';

$page = dti_page_begin('list');
dti_push_seen(null, $page['identity']['email'], 'dti/index.php');
include __DIR__ . '/views/head.php';
?>
<?php include __DIR__ . '/views/list.php'; ?>
<?php include __DIR__ . '/views/foot.php'; ?>
