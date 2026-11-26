<?php
/**
 * 블루 클러스터 에이전트 목록 화면.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

$me = bcl_require_login();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

bcl_page_head($me, '블루 클러스터 에이전트');
?>
<main class="bcl-main">
  <h1>블루 클러스터 에이전트</h1>
  <div class="bcl-empty">아직 작업이 없습니다.</div>
</main>
<?php
bcl_page_foot();
