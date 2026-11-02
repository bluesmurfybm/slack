<?php
/** 아이디어 상세 (032) — 본문·평가·피드백·검토·과제로 올리기. 내용은 assign.js 가 채운다. */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/presenter.php';
require_once __DIR__ . '/inc/repo/IdeaRepo.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user = bs_require_login();
$id   = bs_param_int('id', 0);
$idea = $id ? (new IdeaRepo(bs_db()))->find($id) : null;
if (!$idea || (bs_idea_is_deleted($idea) && !bs_is_admin() && !bs_idea_is_author($idea))) {
    header('Location: idea_board.php?err=notfound');
    exit;
}

bs_layout_head($user, $idea['title'], '과업 편성/현황', '', 'idea');
?>

<?= bs_back_link('idea_board.php', '아이디어 목록') ?>

<div class="ba-rnd-view ba-idea-view" id="ba-idea-view" data-id="<?= (int)$idea['id'] ?>"
     data-can-create-project="<?= bs_can(BS_CAP_PROJECT_CREATE) ? '1' : '0' ?>">
  <div class="ba-rnd-head" id="ba-iv-head"><div class="ba-loading">불러오는 중…</div></div>

  <div class="ba-idea-cols">
    <div class="ba-idea-main">
      <section class="ba-panel" id="ba-iv-body"></section>
      <section class="ba-panel" id="ba-iv-comments">
        <h2>피드백</h2>
        <div id="ba-iv-clist"></div>
        <div class="ba-idea-cform">
          <textarea id="ba-iv-ctext" rows="3" maxlength="2000"
                    placeholder="질문, 보완 아이디어, 비슷한 사례 — 무엇이든 좋습니다"></textarea>
          <button type="button" class="ba-btn ba-btn--primary" id="ba-iv-csend">남기기</button>
        </div>
      </section>
    </div>
    <aside class="ba-idea-side">
      <section class="ba-panel" id="ba-iv-rating"></section>
      <section class="ba-panel" id="ba-iv-lineage" hidden></section>
    </aside>
  </div>
</div>

<?php bs_layout_foot(); ?>
