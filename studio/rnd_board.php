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
    'rnd'
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
  <!-- 대시보드 '사내 전체 현황' 과 같은 카드 (2026-10-16). 다만 범위가 다르다 —
       RndRepo::stats() 는 **볼 수 있는 과제만** 센다(비공개 제외). 그래서 '전사'
       가 아니라 그 범위를 제목 줄에 적는다. 숫자는 assign.js 가 #ba-rnd-stats .n 에 채운다. -->
  <section class="ba-org" aria-labelledby="ba-rnd-stats-h">
    <div class="ba-org__head">
      <h2 id="ba-rnd-stats-h">R&amp;D 현황</h2>
      <span class="ba-org__hint">내가 볼 수 있는 과제 기준입니다. 비공개 과제는 세지 않습니다.</span>
    </div>
    <div class="ba-org__cards" id="ba-rnd-stats">
      <div class="ba-orgc ba-orgc--rnd ba-orgc--static">
        <span class="ba-orgc__ic" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M9 3h6M10 3v6L4.5 18.5A2 2 0 0 0 6.2 21h11.6a2 2 0 0 0 1.7-2.5L14 9V3"/></svg>
        </span>
        <span class="ba-orgc__body">
          <span class="ba-orgc__t">진행 중 과제</span>
          <span class="n" data-k="running_count">–</span>
          <span class="ba-orgc__s">승인 · 진행</span>
        </span>
      </div>
      <div class="ba-orgc ba-orgc--done ba-orgc--static">
        <span class="ba-orgc__ic" aria-hidden="true">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.6 2.6L16 9.7"/></svg>
        </span>
        <span class="ba-orgc__body">
          <span class="ba-orgc__t">최근 분기 종료</span>
          <span class="n" data-k="done_quarter">–</span>
          <span class="ba-orgc__s">최근 90일 안에 종료한 과제</span>
        </span>
      </div>
      <div class="ba-orgc ba-orgc--out ba-orgc--static">
        <span class="ba-orgc__ic" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8zM14 3v5h5M9 13h6M9 17h4"/></svg>
        </span>
        <span class="ba-orgc__body">
          <span class="ba-orgc__t">누적 산출물</span>
          <span class="n" data-k="output_total">–</span>
          <span class="ba-orgc__s">지금까지 등록된 산출물</span>
        </span>
      </div>
    </div>
  </section>

  <!-- ============ 실행 줄 ============ -->
  <div class="ba-filters ba-actionbar">
    <a class="ba-btn ba-btn--primary" href="rnd_form.php">과제 발의</a>
    <span class="ba-head__sub">
      하고 싶은 과제를 올리면 승인 뒤 보드에 공개됩니다. 공개 범위는 발의할 때 정합니다.
    </span>
  </div>

  <!-- ============ 조회 조건 ============ -->
  <div class="ba-filters ba-filterbox">
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

    <label class="ba-check" for="ba-r-interest">
      <input type="checkbox" id="ba-r-interest">
      <span>관심 과제만</span>
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
