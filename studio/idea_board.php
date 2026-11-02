<?php
/** 아이디어 보드 (032). 데이터는 assign.js 가 api/idea.php 에서 채운다. */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/repo/IdeaRepo.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user = bs_require_login();
if (!IdeaRepo::schemaReady(bs_db())) {   // 032 없이 코드만 올라간 서버 — 쓰다가 실패하지 않게 미리 알린다
    bs_layout_head($user, '아이디어', '과업 편성/현황', '', 'idea');
    echo '<div class="ba-alert">이 서버의 DB 에 아이디어 표가 아직 없습니다. 관리자가 '
       . '<code>studio/sql/032_migration_idea.sql</code> 을 적용하면 쓸 수 있습니다.</div>';
    bs_layout_foot();
    exit;
}
$err  = bs_param_str('err');

bs_layout_head(
    $user,
    '아이디어',
    '과업 편성/현황',
    '과제가 되기 전의 생각을 나눕니다. 누구나 올리고 평가하고 의견을 붙입니다.',
    'idea'
);
?>

<?php if ($err !== ''): ?>
<div class="ba-alert">
  <?= h(match ($err) {
      'denied'   => '권한이 없습니다.',
      'notfound' => '아이디어를 찾을 수 없습니다. 지워졌을 수 있습니다.',
      default    => '요청을 처리하지 못했습니다.',
  }) ?>
</div>
<?php endif; ?>

<div class="ba-rnd ba-idea" id="ba-idea-board">

  <!-- ============ 흐름 안내 ============
       아이디어가 어디로 가는지 보여야 올릴 마음이 생긴다. 채택되면 과제·프로젝트로
       넘어가고, 그 진행이 아이디어 화면에 이어 보인다. -->
  <section class="ba-idea-flow" aria-label="아이디어가 가는 길">
    <ol>
      <li><b>올리기</b><span>떠오른 생각과 계기를 적습니다. 익명도 됩니다</span></li>
      <li><b>평가 · 피드백</b><span>누구나 가치와 실현 가능성을 매기고 의견을 붙입니다</span></li>
      <li><b>검토</b><span>채택 · 보류 · 반려 — 사유가 남습니다</span></li>
      <li><b>과제 · 프로젝트로</b><span>R&amp;D 과제로 발의하거나, 바로 프로젝트로 넘깁니다</span></li>
    </ol>
  </section>

  <!-- ============ 실행 줄 ============ -->
  <div class="ba-filters ba-actionbar">
    <a class="ba-btn ba-btn--primary" href="idea_form.php">아이디어 올리기</a>
    <span class="ba-head__sub">
      다듬어지지 않아도 됩니다. 평가는 <b><?= BS_IDEA_MIN_RATERS ?>명</b>이 모여야 평균이 보이고,
      누가 몇 점을 줬는지는 본인만 봅니다.
    </span>
  </div>

  <!-- ============ 조회 조건 ============ -->
  <div class="ba-filters ba-filterbox">
    <label class="ba-field">
      <span>갈래</span>
      <select id="ba-i-category">
        <option value="">전체</option>
        <?php foreach (BS_RND_CATEGORY as $code => $label): ?>
          <option value="<?= h($code) ?>"><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="ba-field" style="flex:1 1 220px;max-width:420px">
      <span>검색</span>
      <input type="search" id="ba-i-q" placeholder="번호, 제목, 내용">
    </label>

    <label class="ba-field">
      <span>정렬</span>
      <select id="ba-i-sort">
        <?php foreach (IdeaRepo::SORTS as $code => $label): ?>
          <option value="<?= h($code) ?>"><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="ba-check" for="ba-i-mine">
      <input type="checkbox" id="ba-i-mine">
      <span>내 아이디어만</span>
    </label>

    <?php if (bs_is_admin()): ?>
    <label class="ba-check" for="ba-i-deleted" title="관리자만 보입니다">
      <input type="checkbox" id="ba-i-deleted">
      <span>삭제 포함</span>
    </label>
    <?php endif; ?>

    <span class="ba-spacer"></span>
    <button type="button" class="ba-btn" id="ba-i-reset">조건 초기화</button>
  </div>

  <!-- ============ 상태 탭 ============ -->
  <div class="ba-listbar">
    <div class="ba-statabs" id="ba-i-tabs" role="tablist" aria-label="아이디어 상태"></div>
  </div>

  <!-- ============ 카드 그리드 ============ -->
  <div class="ba-rnd-grid" id="ba-i-grid">
    <div class="ba-loading">불러오는 중…</div>
  </div>

  <div class="ba-pager" id="ba-i-pager"></div>
</div>

<?php bs_layout_foot(); ?>
