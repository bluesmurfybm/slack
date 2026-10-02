<?php
/** R&D 과제 상세 — 개요 / 참여자 / 진행 기록 / 산출물 (명세서 §8.3). */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/presenter.php';
require_once __DIR__ . '/inc/repo/RndRepo.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user = bs_require_login();
$repo = new RndRepo(bs_db());

$id = bs_param_int('id', 0);
// find() 가 가시성을 이미 본다. 못 보는 과제는 null 로 온다 —
// 보드로 돌려보내되 '없다' 와 '못 본다' 를 가르지 않는다.
$rnd = $id ? $repo->find($id) : null;
if (!$rnd) {
    header('Location: rnd_board.php?err=notfound');
    exit;
}

bs_layout_head(
    $user,
    $rnd['name'],
    '과업 편성/현황',
    '',
    'project'
);
?>

<div class="ba-rnd-view" id="ba-rnd-view" data-id="<?= (int)$rnd['id'] ?>">

  <div class="ba-typetabs" role="tablist" aria-label="과업 유형">
    <a role="tab" aria-selected="false" href="project_list.php">프로젝트</a>
    <a role="tab" aria-selected="true"  class="on" href="rnd_board.php">R&amp;D 과제</a>
  </div>

  <!-- 머리 — 코드·상태·뱃지와 과제 단추. 내용은 JS 가 채운다. -->
  <div class="ba-rnd-head" id="ba-rv-head">
    <div class="ba-loading">불러오는 중…</div>
  </div>

  <!-- 탭 -->
  <div class="ba-listbar">
    <div class="ba-statabs" id="ba-rv-tabs" role="tablist" aria-label="과제 상세">
      <button type="button" role="tab" data-tab="overview" aria-selected="true">개요</button>
      <button type="button" role="tab" data-tab="members"  aria-selected="false">참여자<i id="ba-rv-n-members">0</i></button>
      <button type="button" role="tab" data-tab="logs"     aria-selected="false">진행 기록<i id="ba-rv-n-logs">0</i></button>
      <button type="button" role="tab" data-tab="outputs"  aria-selected="false">산출물<i id="ba-rv-n-outputs">0</i></button>
    </div>
  </div>

  <section class="ba-rv-pane" data-pane="overview" id="ba-rv-overview"></section>
  <section class="ba-rv-pane" data-pane="members"  id="ba-rv-members" hidden></section>
  <section class="ba-rv-pane" data-pane="logs"     id="ba-rv-logs"    hidden></section>
  <section class="ba-rv-pane" data-pane="outputs"  id="ba-rv-outputs" hidden></section>
</div>

<?php bs_layout_foot(); ?>
