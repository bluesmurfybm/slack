<?php
/**
 * 전 모듈 공통 상단바 — blue-iWorks 포털 대시보드와 완전히 동일한 클래스/스타일.
 * slack 쪽 페이지들의 <body> 바로 다음에 include 해서 쓴다.
 * (require_once auth.php; require_login(); 이후에 include할 것 — current_portal_user() 필요)
 *
 * slack/ 바로 아래 페이지(lists.php 등)는 $__bwBase 안 정해도 됨(기본 '').
 * slack/xxx/ 하위 폴더 페이지(schools/schools_admin.php 등)는 include 전에
 * $__bwBase = '../'; 로 한 단계 더 위임을 알려줘야 링크가 안 깨진다.
 *
 * $__bwCurrent 는 드롭다운 "업무 시스템"에서 현재 위치로 표시할 key(worksystems.php 참고).
 * 안 주면 아무 줄도 강조하지 않는다.
 */
require_once __DIR__ . '/../core/worksystems.php';
$__bwBase = isset($__bwBase) ? $__bwBase : '';
$__bwCurrent = isset($__bwCurrent) ? $__bwCurrent : '';
$__bwUser = function_exists('current_portal_user') ? current_portal_user() : null;
?>
<?php // header.css 가 @import 하는 topbar.css 는 브라우저에 옛것이 남는다 — 바뀌면 새로 받게 여기서 한 번 더 건다 ?>
<link rel="stylesheet" href="<?= $__bwBase ?>../styles/topbar.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/topbar.css') ?>">
<div class="topbar">
  <div class="topbar-in">
    <a class="logo" href="<?= $__bwBase ?>../index.php" style="text-decoration:none"><b>blue</b><span class="dash">-</span>iWorks</a>
    <div class="top-right">
      <?php if ($__bwUser): ?>
      <?= work_systems_bell($__bwBase . '../', $__bwCurrent) ?>
      <?= portal_user_menu($__bwBase . '../', $__bwCurrent) ?>
      <?php endif; ?>
    </div>
  </div>
</div>
