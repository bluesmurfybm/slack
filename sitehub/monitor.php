<?php
/**
 * BLUMO 감시 — 예전 blumo.pink 관리화면을 SiteHub 안으로 옮긴 것. 화면 세 개(주소 ?tab=):
 *  - board     감시 주소 전체의 상태 · 원인 · 응답 · 24h 가용률 · SSL — 지금 점검, 자동 점검 멈춤, 알림 무음, 점검 기록
 *  - incidents 확정된 장애 이력
 *  - settings  웹훅 · 판정 기준 · 일시정지 · 자동 점검(작업 스케줄러) · 환경 진단 · 예전 BLUMO 가져오기 (지정 계정만)
 * 감시 대상 추가·수정은 사이트 상세의 '감시 설정' 탭. 점검은 monitor_engine.php, 자동 실행은 monitor_run.php.
 */
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/db.php';
$me = sitehub_require_login();
sitehub_session_release();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
sitehub_db();   // 테이블이 없으면 만든다

$canStats    = sitehub_can_stats($me);
$canSync     = sitehub_can_sync($me);
$pageTitle   = 'BLUMO 감시';
$pageLede    = '유지보수 사이트 접속 감시 — 증상이 아니라 원인까지';
$headActions = '<a class="btn-ghost sh-statlink" href="index.php">← 사이트 목록</a>'
             . ($canStats ? '<a class="btn-ghost sh-statlink" href="stats.php">통계</a>' : '')
             . '<button class="btn-new" type="button" id="runAll" onclick="monRunAll()">전체 지금 점검</button>';
include __DIR__ . '/views/head.php';
?>
  <main class="wrap sh-monpage">
    <div id="monBanner"></div>
    <div class="stats sh-mon-kpi" id="monKpi"></div>

    <div class="toolbar">
      <div class="search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
        <input id="monQ" type="text" autocomplete="off" placeholder="사이트명 · 주소 · 원인으로 찾기">
      </div>
      <div class="viewtoggle sh-viewseg" id="monTabs" role="tablist">
        <button type="button" data-tab="board" onclick="monTab('board')">감시 현황</button>
        <button type="button" data-tab="incidents" onclick="monTab('incidents')">장애 이력</button>
<?php if ($canStats): ?>
        <button type="button" data-tab="settings" onclick="monTab('settings')">설정</button>
<?php endif; ?>
      </div>
    </div>

    <div id="monBody"><ul class="list"><li class="empty">불러오는 중…</li></ul></div>
  </main>
<?php $scripts = ['core', 'form', 'monitor']; include __DIR__ . '/views/foot.php'; ?>
