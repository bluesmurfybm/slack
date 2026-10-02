<?php
/** R&D 보드 — 과제 카드 그리드. 데이터는 assign.js 가 api/rnd.php 에서 채운다 (명세서 §8.1). */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user = bs_require_login();

// 발의는 로그인한 사람 누구나 한다. R&D 는 지시가 아니라 발의이므로
// 권한으로 막지 않는다 — 막을 것은 승인 쪽이다(§9.1).
$canApprove = bs_can(BS_CAP_RND_APPROVE);
$err        = bs_param_str('err');

bs_layout_head(
    $user,
    'R&D 과제',
    '과업 편성/현황',
    '구성원이 발의한 R&D 과제를 보고, 모집 중인 과제를 찾습니다.',
    'project'
);
?>

<?php if ($err !== ''): ?>
<div class="ba-alert">
  <?= h(match ($err) {
      'denied'   => '권한이 없습니다.',
      'notfound' => '과제를 찾을 수 없습니다. 비공개 과제이거나 지워졌을 수 있습니다.',
      default    => '요청을 처리하지 못했습니다.',
  }) ?>
</div>
<?php endif; ?>

<div class="ba-rnd" id="ba-rnd-board" data-can-approve="<?= $canApprove ? '1' : '0' ?>">

  <!-- ============ 프로젝트 / R&D 탭 ============ -->
  <div class="ba-typetabs" role="tablist" aria-label="과업 유형">
    <a role="tab" aria-selected="false" href="project_list.php">프로젝트</a>
    <a role="tab" aria-selected="true"  class="on" href="rnd_board.php">R&amp;D 과제</a>
  </div>

  <!-- ============ 머리 통계 ============ -->
  <div class="ba-rnd-stats" id="ba-rnd-stats">
    <div class="ba-rnd-stat"><span class="n" data-k="running_count">–</span><span class="t">진행 중 과제</span></div>
    <div class="ba-rnd-stat"><span class="n" data-k="done_quarter">–</span><span class="t">최근 분기 종료</span></div>
    <div class="ba-rnd-stat"><span class="n" data-k="output_total">–</span><span class="t">누적 산출물</span></div>
  </div>

  <!-- ============ 실행 줄 ============ -->
  <div class="ba-filters" style="justify-content:flex-start">
    <a class="ba-btn ba-btn--primary" href="rnd_form.php">과제 발의</a>
    <span class="ba-head__sub">
      하고 싶은 과제를 올리면 승인 뒤 보드에 공개됩니다. 공개 범위는 발의할 때 정합니다.
    </span>
  </div>

  <!-- ============ 조회 조건 ============ -->
  <div class="ba-filters">
    <label class="ba-field">
      <span>갈래</span>
      <select id="ba-r-category">
        <option value="">전체</option>
        <?php foreach (BS_RND_CATEGORY as $code => $label): ?>
          <option value="<?= h($code) ?>"><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="ba-field" style="flex:1 1 220px;max-width:420px">
      <span>검색</span>
      <input type="search" id="ba-r-keyword" placeholder="코드, 과제명, 개요">
    </label>

    <label class="ba-field">
      <span>정렬</span>
      <select id="ba-r-sort">
        <option value="recent" selected>최신 순</option>
        <option value="active">활동 많은 순</option>
        <option value="recruiting">모집중 우선</option>
      </select>
    </label>

    <label class="ba-check" for="ba-r-recruiting">
      <input type="checkbox" id="ba-r-recruiting">
      <span>모집중만</span>
    </label>

    <label class="ba-check" for="ba-r-mine">
      <input type="checkbox" id="ba-r-mine">
      <span>내 과제만</span>
    </label>

    <span class="ba-spacer"></span>
    <button type="button" class="ba-btn" id="ba-r-reset">조건 초기화</button>
  </div>

  <!-- ============ 상태 탭 ============ -->
  <div class="ba-listbar">
    <div class="ba-statabs" id="ba-r-tabs" role="tablist" aria-label="과제 상태"></div>
  </div>

  <!-- ============ 카드 그리드 ============ -->
  <div class="ba-rnd-grid" id="ba-r-grid">
    <div class="ba-loading">불러오는 중…</div>
  </div>

  <div class="ba-pager" id="ba-r-pager"></div>
</div>

<?php bs_layout_foot(); ?>
