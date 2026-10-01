<?php
/** BlueStudio 대시보드 — 확정 배정안과 진행상황을 한눈에 본다. 모듈 기본 진입 화면. */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

// 로그인 상태에 따라 다르게 그려지는 페이지라 캐시에 남으면 안 된다(BlueCart 와 같은 처리).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$user = bs_require_login();

bs_layout_head(
    $user,
    '대시보드',
    '업무 배정',
    '확정된 배정안과 진행상황을 확인합니다.',
    'dashboard'
);
?>

<div class="ba-dash" id="ba-dash" data-project="<?= (int)(bs_param_int('project_id', 0) ?? 0) ?>">

  <div class="ba-alert" id="ba-dash-error" hidden></div>

  <!-- ============ 뷰 전환 (P11) ============
       한 화면에 다 쌓지 않고 보는 축을 고르게 한다. 지시형 프로젝트와
       자발형 R&D 는 성격이 달라서, 섞어 놓으면 둘 다 잘 안 보인다. -->
  <div class="ba-viewtabs" id="ba-dash-views" role="tablist" aria-label="대시보드 보기">
    <button type="button" role="tab" data-view="all"     aria-selected="true">통합</button>
    <button type="button" role="tab" data-view="project" aria-selected="false">프로젝트</button>
    <button type="button" role="tab" data-view="rnd"     aria-selected="false">R&amp;D</button>
  </div>

  <!-- ============ 조직 지표 ============ -->
  <div class="ba-rnd-stats" id="ba-dash-org">
    <div class="ba-rnd-stat"><span class="n" data-k="project_running">–</span><span class="t">진행 중 프로젝트</span></div>
    <div class="ba-rnd-stat"><span class="n" data-k="rnd_running">–</span><span class="t">진행 중 R&amp;D 과제</span></div>
    <div class="ba-rnd-stat"><span class="n" data-k="output_quarter">–</span><span class="t" id="ba-dash-q">이번 분기 산출물</span></div>
  </div>

  <!-- ============ 내 점유 구성 ============ -->
  <section class="ba-panel" id="ba-me-load-wrap">
    <h2>내 점유 구성 <span class="ba-dim" id="ba-me-load-sum"></span></h2>
    <p class="ba-panel__hint">
      앞으로 60일 기준입니다. <b>합산 숫자 하나로 보여 주지 않습니다</b> —
      무엇이 얼마나 차지하는지 나눠 적습니다.
    </p>
    <div id="ba-me-load"><div class="ba-loading">불러오는 중…</div></div>
  </section>

  <!-- ============ 내가 맡은 것 ============ -->
  <section class="ba-panel" data-view-of="project all">
    <h2>내가 맡은 일 <span class="ba-dim" id="ba-mine-sum"></span></h2>
    <p class="ba-panel__hint">
      <b>확정된 배정안</b>에서 내가 맡은 태스크입니다. 확정 전 배정안은 나오지 않습니다.
    </p>
    <div id="ba-mine"><div class="ba-loading">불러오는 중…</div></div>
  </section>

  <!-- ============ 내 R&D 과제 ============ -->
  <section class="ba-panel" data-view-of="rnd all">
    <h2>내 R&amp;D 과제 <span class="ba-dim" id="ba-myrnd-sum"></span></h2>
    <p class="ba-panel__hint">
      승인되어 참여 중인 과제입니다. 진행 기록이 오래 없으면 <b>조용함</b>으로 표시합니다.
    </p>
    <div id="ba-myrnd"><div class="ba-loading">불러오는 중…</div></div>
  </section>

  <!-- ============ 진행 중 프로젝트 카드 ============ -->
  <section class="ba-panel" data-view-of="project all">
    <h2>진행 중 프로젝트 <span class="ba-dim" id="ba-cards-sum"></span></h2>
    <div id="ba-cards"><div class="ba-loading">불러오는 중…</div></div>
  </section>

  <!-- ============ 선택한 프로젝트의 보드 ============ -->
  <section id="ba-board" hidden>
    <div class="ba-panel ba-bd__head">
      <div>
        <h2 id="ba-bd-name"></h2>
        <p class="ba-panel__hint" id="ba-bd-sub"></p>
      </div>
      <span class="ba-spacer"></span>
      <div class="ba-bd__toggle" role="group" aria-label="보기 방식">
        <button type="button" class="ba-btn ba-btn--sm is-on" data-view="kanban">칸반</button>
        <button type="button" class="ba-btn ba-btn--sm" data-view="gantt">간트</button>
      </div>
      <button type="button" class="ba-btn ba-btn--sm" id="ba-bd-close">닫기</button>
    </div>

    <div class="ba-note" id="ba-bd-note" hidden></div>

    <!-- 담당자별 카드 -->
    <div class="ba-panel" id="ba-bd-people-wrap">
      <h2>담당자별 <span class="ba-dim" id="ba-bd-people-sum"></span></h2>
      <div id="ba-bd-people"></div>
    </div>

    <!-- 지연 -->
    <div class="ba-panel" id="ba-bd-late-wrap" hidden>
      <h2>지연 <span class="ba-dim" id="ba-bd-late-sum"></span></h2>
      <p class="ba-panel__hint">
        계획 종료일이 지났는데 아직 끝나지 않은 태스크입니다.
        운영 배포·완료·보류는 지연으로 세지 않습니다.
      </p>
      <div id="ba-bd-late"></div>
    </div>

    <!-- 칸반 / 간트 -->
    <div class="ba-panel">
      <h2 id="ba-bd-viewname">칸반</h2>
      <div id="ba-bd-kanban"></div>
      <div id="ba-bd-gantt" hidden></div>
    </div>

    <!-- 최근 진행 피드 -->
    <div class="ba-panel">
      <h2>최근 진행상황 <span class="ba-dim" id="ba-bd-feed-sum"></span></h2>
      <div id="ba-bd-feed"></div>
    </div>
  </section>
</div>

<!-- 진행상황 드로어 (명세서 §7.2) -->
<div class="ba-drawer" id="ba-pg-drawer" hidden>
  <div class="ba-drawer__box" role="dialog" aria-modal="true" aria-labelledby="ba-pg-title">
    <div class="ba-drawer__head">
      <h2 id="ba-pg-title">진행상황</h2>
      <button type="button" class="ba-close" data-close aria-label="닫기">&times;</button>
    </div>
    <div class="ba-drawer__body" id="ba-pg-body"></div>
    <div class="ba-drawer__foot">
      <span class="ba-spacer"></span>
      <button type="button" class="ba-btn" data-close>닫기</button>
    </div>
  </div>
</div>

<?php bs_layout_foot(); ?>
