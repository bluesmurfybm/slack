<?php
/**
 * 관리자용 R&D 점유 현황 (P10-2).
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ **점유율 감사 전용 화면이다.**                                    │
 * │                                                                  │
 * │ 역량 점수를 여기에 올리지 마라. 구성원을 한 줄로 세운 표에 점수를 │
 * │ 얹으면 정렬 한 번으로 CLAUDE.md 가 금지한 전사 랭킹이 된다.       │
 * │ 이 화면이 답하는 질문은 하나다 — "누가 R&D 로 얼마나 차 있는가".  │
 * └──────────────────────────────────────────────────────────────────┘
 */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user = bs_require_login();
if (!bs_is_admin()) {
    header('Location: rnd_board.php?err=denied');
    exit;
}

bs_layout_head(
    $user,
    'R&D 점유 현황',
    '업무 배정',
    '구성원별 R&D 점유율과 상한 대비 여유를 봅니다. 점유 감사용입니다.',
    'project'
);
?>

<div class="ba-rnd-load" id="ba-rnd-load">

  <div class="ba-typetabs" role="tablist" aria-label="과업 유형">
    <a role="tab" aria-selected="false" href="project_list.php">프로젝트</a>
    <a role="tab" aria-selected="false" href="rnd_board.php">R&amp;D 과제</a>
    <a role="tab" aria-selected="true"  class="on" href="rnd_load.php">점유 현황</a>
  </div>

  <div class="ba-alert ba-alert--quiet">
    이 화면은 <strong>점유율 감사 전용</strong>입니다. 역량 점수는 들어 있지 않으며,
    여기서 사람을 비교하지 마십시오. 상한값은
    <code>bs_setting</code> 에서 읽습니다.
  </div>

  <div class="ba-rnd-stats" id="ba-rl-limits">
    <div class="ba-rnd-stat"><span class="n" data-k="total_cap">–</span><span class="t">1인 합계 상한</span></div>
    <div class="ba-rnd-stat"><span class="n" data-k="per_project_cap">–</span><span class="t">과제당 상한</span></div>
    <div class="ba-rnd-stat"><span class="n" data-k="concurrent_max">–</span><span class="t">동시 참여 상한</span></div>
    <div class="ba-rnd-stat"><span class="n" data-k="stale_weeks">–</span><span class="t">정체 판정(주)</span></div>
  </div>

  <div class="ba-table-wrap">
    <table class="ba-table" id="ba-rl-table">
      <thead>
        <tr>
          <th>구성원</th>
          <th style="width:90px">역할</th>
          <th style="width:110px">R&amp;D 점유</th>
          <th style="width:70px">과제 수</th>
          <th style="width:100px">상한 여유</th>
          <th>참여 중인 과제</th>
        </tr>
      </thead>
      <tbody>
        <tr><td colspan="6" class="ba-loading">불러오는 중…</td></tr>
      </tbody>
    </table>
  </div>
</div>

<?php bs_layout_foot(); ?>
