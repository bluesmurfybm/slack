<?php
require_once __DIR__ . '/../bootstrap.php';

$page = dti_page_begin('archive');
include __DIR__ . '/../views/head.php';
?>
<?php include __DIR__ . '/../views/list.php'; ?>
<?php include __DIR__ . '/../views/topic_modals.php'; ?>
<?php include __DIR__ . '/../views/foot.php'; ?>
