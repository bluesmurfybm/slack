<?php
/** 프로젝트 목록 — 상태·기간·검색으로 거르고 페이지 단위로 본다. 데이터는 assign.js 가 API 에서 채운다. */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user = bs_require_login();

// 등록 버튼 노출. 프로젝트가 특정되지 않은 화면이라 관리자만 통과한다.
// PM 은 자기 프로젝트를 고친다(bs_is_pm 은 프로젝트별 판정이라 여기서는 못 쓴다).
$canCreate = bs_can(BS_CAP_PROJECT_MANAGE);
$isAdmin   = bs_is_admin();

// 다른 화면에서 권한/존재 문제로 튕겨 왔을 때 이유를 알려 준다.
$err = bs_param_str('err');

bs_layout_head(
    $user,
    '프로젝트',
    '업무 배정',
    '프로젝트를 등록하고 배정 진행 상태를 확인합니다.',
    'project'
);
?>

<?php if ($err !== ''): ?>
<div class="ba-alert">
  <?= h(match ($err) {
      'denied'   => '권한이 없습니다. PM 또는 관리자만 프로젝트를 등록·수정할 수 있습니다.',
      'notfound' => '프로젝트를 찾을 수 없습니다. 지워졌을 수 있습니다.',
      default    => '요청을 처리하지 못했습니다.',
  }) ?>
</div>
<?php endif; ?>

<div class="ba-list" id="ba-list-app" data-is-admin="<?= $isAdmin ? '1' : '0' ?>">

  <!-- ============ 프로젝트 / R&D 탭 ============
       같은 bs_project 표를 쓰지만 성격이 다르다. 목록 쿼리는
       project_type='project' 로 걸려 있어 R&D 가 섞이지 않는다
       (ProjectRepo::buildWhere). -->
  <div class="ba-typetabs" role="tablist" aria-label="과업 유형">
    <a role="tab" aria-selected="true"  class="on" href="project_list.php">프로젝트</a>
    <a role="tab" aria-selected="false" href="rnd_board.php">R&amp;D 과제</a>
  </div>

  <!-- ============ 실행 줄 ============ -->
  <div class="ba-filters" style="justify-content:flex-start">
    <?php if ($canCreate): ?>
      <a class="ba-btn ba-btn--primary" href="project_form.php">새 프로젝트</a>
    <?php endif; ?>
    <span class="ba-head__sub">
      프로젝트를 등록하면 개발 범위를 올리고 배정안을 만들 수 있습니다.
    </span>
  </div>

  <!-- ============ 조회 조건 ============ -->
  <div class="ba-filters">
    <label class="ba-field">
      <span>트랙</span>
      <select id="ba-f-track">
        <option value="">전체</option>
        <?php foreach (BS_PROJECT_TRACK as $code => $label): ?>
          <option value="<?= h($code) ?>"><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <!-- 기간 필터는 "프로젝트 기간이 이 구간과 겹치는 것" 이다.
         시작일만 보면 진행 중인 장기 프로젝트가 빠진다. -->
    <label class="ba-field">
      <span>기간 겹침</span>
      <input type="date" id="ba-f-from" aria-label="기간 시작">
    </label>
    <label class="ba-field">
      <span>~</span>
      <input type="date" id="ba-f-to" aria-label="기간 끝">
    </label>

    <label class="ba-field" style="flex:1 1 220px;max-width:420px">
      <span>검색</span>
      <input type="search" id="ba-f-keyword" placeholder="코드, 프로젝트명, 고객, 개요">
    </label>

    <label class="ba-field">
      <span>정렬</span>
      <select id="ba-f-sort">
        <option value="recent" selected>최근 등록 순</option>
        <option value="status">상태 순</option>
        <option value="dev_start">개발 시작일 순</option>
        <option value="name">이름 순</option>
        <option value="oldest">오래된 순</option>
      </select>
    </label>

    <label class="ba-check" for="ba-f-mine">
      <input type="checkbox" id="ba-f-mine">
      <span>내 프로젝트만</span>
    </label>

    <?php if ($isAdmin): ?>
    <label class="ba-check" for="ba-f-deleted" title="관리자만 보입니다">
      <input type="checkbox" id="ba-f-deleted">
      <span>삭제 포함</span>
    </label>
    <?php endif; ?>

    <span class="ba-spacer"></span>
    <button type="button" class="ba-btn" id="ba-f-reset">조건 초기화</button>
  </div>

  <!-- ============ 상태 탭 ============ -->
  <div class="ba-listbar">
    <div class="ba-statabs" id="ba-tabs" role="tablist" aria-label="프로젝트 상태"></div>
  </div>

  <!-- ============ 목록 ============ -->
  <div class="ba-table-wrap">
    <table class="ba-table" id="ba-list">
      <thead>
        <tr>
          <th style="width:118px">코드</th>
          <th>프로젝트명</th>
          <th style="width:130px">고객</th>
          <th style="width:110px">트랙</th>
          <th style="width:170px">개발 기간</th>
          <th style="width:100px">배포</th>
          <th style="width:88px">담당</th>
          <th style="width:64px">출처</th>
          <th style="width:92px">상태</th>
        </tr>
      </thead>
      <tbody>
        <tr><td colspan="9" class="ba-loading">불러오는 중…</td></tr>
      </tbody>
    </table>
  </div>

  <div class="ba-pager" id="ba-pager"></div>
</div>

<?php bs_layout_foot(); ?>
