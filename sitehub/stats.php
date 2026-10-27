<?php
/**
 * SiteHub 통계 — 지정 계정만(guard.php sitehub_can_stats, 기본 pink@bluesoft.co.kr · config.php 'sitehub_stats_emails').
 *  - 계약 · 사이트: 오늘 기준, 보관함 제외. 유지보수 이력: Slack 리스트 동기화본, 기간 선택.
 *  - 데이터는 api.php?stats 가 집계해 주고(같은 권한 검사), 그림은 static/stats.js 가 CSS 막대로 그린다(외부 차트 라이브러리 없음).
 */
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/db.php';
$me = sitehub_require_login();
sitehub_session_release();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$canStats    = sitehub_can_stats($me);
$canSync     = sitehub_can_sync($me);   // 화면 자동 동기화 (core.js autoSync)
$pageTitle   = 'SiteHub 통계';
$pageLede    = '계약 현황과 유지보수 처리 이력';
$headActions = '<a class="btn-ghost sh-statlink" href="index.php">← 사이트 목록</a>';

if (!$canStats) {
    http_response_code(403);
    $pageLede = '';
    include __DIR__ . '/views/head.php';
    echo '<main class="wrap"><ul class="list" style="margin-top:24px"><li class="empty"><div class="big">통계는 지정된 계정만 볼 수 있습니다</div>'
       . '사이트 목록은 <a href="index.php">BlueLab SiteHub</a> 에서 볼 수 있습니다.</li></ul></main>';
    $scripts = ['core'];
    include __DIR__ . '/views/foot.php';
    exit;
}
include __DIR__ . '/views/head.php';
?>
  <main class="wrap sh-statspage" id="statsRoot">

    <section class="sh-ssec">
      <div class="sh-ssec-head">
        <h2>계약 · 사이트</h2>
        <span class="muted" id="contractAsOf">오늘 기준 · 보관함 제외</span>
        <label class="muted">계약액 연도 <select id="contractYear"></select></label>
      </div>
      <div class="stats sh-kpis" id="kpiContract"></div>
      <div class="sh-sgrid">
        <div class="sh-panel">
          <div class="sh-panel-head"><h3>연간 계약액 상위 10</h3><p>유상 · 고른 연도에 걸친 계약 · 계약 금액(1년 기준)</p></div>
          <div id="chTop"></div>
        </div>
        <div class="sh-panel">
          <div class="sh-panel-head"><h3>호스팅</h3><p>사용 중 사이트 수</p></div>
          <div id="chHosting"></div>
        </div>
      </div>
      <div class="sh-panel">
        <div class="sh-panel-head"><h3>만료 예정 · 최근 만료</h3><p id="expWindow"></p><p>계약 · 호스팅 · 도메인 · SSL</p></div>
        <div id="tblExpiring"></div>
      </div>
    </section>

    <section class="sh-ssec">
      <div class="sh-ssec-head">
        <h2>유지보수 이력</h2>
        <span class="muted" id="histSource">Slack BlueLab-유지보수처리목록</span>
      </div>
      <div class="sh-filterrow">
        <div class="viewtoggle sh-viewseg" id="periodSeg" role="tablist" aria-label="기간">
          <button type="button" data-period="12m">최근 12개월</button>
          <button type="button" data-period="ytd"><?= date('Y') ?>년</button>
          <button type="button" data-period="lastyear"><?= (int)date('Y') - 1 ?>년</button>
          <button type="button" data-period="all">전체</button>
        </div>
      </div>
      <div class="stats sh-kpis" id="kpiHistory"></div>
      <div class="sh-panel">
        <div class="sh-panel-head"><h3>월별 요청 건수</h3><p>요청일 기준 · 무/유상 열로 나눔</p></div>
        <div id="chMonths"></div>
      </div>
      <div class="sh-sgrid sh-sgrid3">
        <div class="sh-panel">
          <div class="sh-panel-head"><h3>처리상태</h3><p>기간 안 요청</p></div>
          <div id="chStatus"></div>
        </div>
        <div class="sh-panel">
          <div class="sh-panel-head"><h3>요청구분</h3><p>기간 안 요청</p></div>
          <div id="chChannel"></div>
        </div>
        <div class="sh-panel">
          <div class="sh-panel-head"><h3>사이트별 상위 10</h3><p>누르면 그 사이트의 이력 탭</p></div>
          <div id="chSites"></div>
        </div>
      </div>
    </section>

    <section class="sh-ssec">
      <div class="sh-ssec-head">
        <h2>지출 비용</h2>
        <span class="muted" id="costSource">사이트 상세 ‘비용’ 탭 · 결제일 기준 · 고른 기간 (연별 표는 전체)</span>
      </div>
      <div class="sh-filterrow">
        <div class="viewtoggle sh-viewseg" id="costPeriodSeg" role="tablist" aria-label="지출 기간">
          <button type="button" data-period="12m">최근 12개월</button>
          <button type="button" data-period="ytd"><?= date('Y') ?>년</button>
          <button type="button" data-period="lastyear"><?= (int)date('Y') - 1 ?>년</button>
          <button type="button" data-period="all">전체</button>
        </div>
      </div>
      <div class="stats sh-kpis" id="kpiCost"></div>
      <div class="sh-panel">
        <div class="sh-panel-head"><h3>월별 지출</h3><p>결제일 기준 · 금액 합계</p></div>
        <div id="chCostMonths"></div>
      </div>
      <div class="sh-sgrid">
        <div class="sh-panel">
          <div class="sh-panel-head"><h3>연별 지출</h3><p>기간과 무관한 전체</p></div>
          <div id="tblCostYears"></div>
        </div>
        <div class="sh-panel">
          <div class="sh-panel-head"><h3>업체(결제처)별</h3><p>고른 기간 금액 합계</p></div>
          <div id="chCostVendors"></div>
        </div>
      </div>
      <div class="sh-panel">
        <div class="sh-panel-head"><h3>업체별 · 연도별</h3><p>기간과 무관한 전체 · 금액 합계</p></div>
        <div id="tblCostVendorYears"></div>
      </div>
      <div class="sh-sgrid">
        <div class="sh-panel">
          <div class="sh-panel-head"><h3>구분별</h3><p>고른 기간</p></div>
          <div id="chCostKinds"></div>
        </div>
        <div class="sh-panel">
          <div class="sh-panel-head"><h3>사이트별 상위 10</h3><p>누르면 그 사이트의 비용 탭</p></div>
          <div id="chCostSites"></div>
        </div>
      </div>
    </section>
  </main>
  <div class="sh-tip" id="shTip" role="tooltip" hidden></div>
<?php $scripts = ['core', 'stats']; include __DIR__ . '/views/foot.php'; ?>
