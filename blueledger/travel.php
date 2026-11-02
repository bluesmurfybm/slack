<?php
/**
 * BlueLedger 출장여비 — 화면은 이 한 장이고, 데이터는 api.php 가 준다.
 * 포털 로그인 없이 들어오면 포털 로그인 화면으로 보낸다.
 */
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../core/worksystems.php';
$u = bl_require_page_login();
$csrf = bl_csrf_token();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
<title>BlueExpense</title>
<link rel="icon" type="image/svg+xml" href="../styles/favicons/blueledger.svg">
<?php require_once __DIR__ . '/../core/pwa.php'; echo pwa_head_tags('../'); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+KR:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
<link rel="stylesheet" href="../styles/topbar.css">
<link rel="stylesheet" href="styles/travel.css?v=<?= (int)@filemtime(__DIR__ . '/styles/travel.css') ?>">
</head>
<body>
<div class="topbar">
  <div class="topbar-in">
    <a class="logo" href="../index.php" style="text-decoration:none"><b>blue</b><span class="dash">-</span>iWorks</a>
    <div class="top-right">
      <div class="user-menu" id="hdrUserMenu">
        <div class="user-chip" id="hdrChip" title="메뉴">
          <span class="avatar" style="background:<?= htmlspecialchars(user_color($u)) ?>"><?= htmlspecialchars(mb_substr($u['name'], 0, 1)) ?></span>
          <span class="nm"><?= htmlspecialchars($u['name']) ?></span>
          <span class="user-caret">▾</span>
        </div>
        <div class="dd-menu">
          <a href="../index.php?view=profile">👤 마이페이지</a>
          <div class="dd-sep"></div>
          <?= work_systems_menu('../', 'blueledger') ?>
          <div class="dd-sep"></div>
          <a href="../api/logout.php">🚪 로그아웃</a>
        </div>
      </div>
    </div>
  </div>
</div>
<header class="bl">
  <h1>BlueExpense</h1>
  <span class="hspace"></span>
  <select class="btn" id="yearSel" aria-label="조회 연도"></select>
  <button class="btn wide" id="btnCsv">CSV 내보내기</button>
  <button class="btn admin-only" id="btnSettings" aria-label="설정">설정</button>
  <!-- 관리자(승인·정산)만 보인다. 처음엔 구성원 화면으로 열고, 여기서 관리자 화면으로 바꾼다.
       줄의 맨 오른쪽 — 상단바 이름 단추 바로 아래에 선다 -->
  <div class="modeseg" id="modeSeg" role="tablist" aria-label="화면 전환" hidden>
    <button type="button" role="tab" data-mode="member" aria-selected="true">구성원</button>
    <button type="button" role="tab" data-mode="admin" aria-selected="false">관리자</button>
  </div>
</header>

<nav class="tabs" aria-label="화면 전환">
  <div class="seg" role="tablist">
    <button role="tab" data-tab="dash" aria-selected="true">현황</button>
    <button role="tab" data-tab="form" aria-selected="false">작성</button>
    <button role="tab" data-tab="list" aria-selected="false">목록</button>
  </div>
</nav>

<main id="main" data-tab="dash">
  <!-- 청구서 -->
  <section class="slip" aria-label="출장여비 청구서">
    <div class="slip-head"><h2>출장여비 청구서</h2><span class="mode" id="formMode">New</span><span class="qtag" id="queueTag" hidden></span>
      <button type="button" class="btn fresh" id="btnNew" hidden>새로 쓰기</button>
      <button type="button" class="btn mybtn" id="btnMy" aria-haspopup="dialog">My Claims<span class="cnt" id="myCount"></span></button>
      <button type="button" class="slipx" id="btnSlipClose" aria-label="이 창 닫기" title="이 창 닫기">×</button></div>
    <form class="slip-body" id="form" autocomplete="off" onsubmit="return false">
      <fieldset id="fsInfo">
        <legend>출장 정보</legend>
        <div class="grid">
          <!-- 출장일은 달력에서 누른다. 값은 아래 숨은 칸(#date, YYYY-MM-DD)에 들어가고 저장은 그 값을 쓴다.
               기간(1박2일 …)을 고르면 그 날들이 옅게 이어져 보인다 -->
          <div class="full">
            <div class="flab"><span class="req">출장일</span> <span class="calpick" id="calPick"></span></div>
            <div class="cal" id="cal"></div>
            <input type="hidden" id="date">
          </div>
          <label class="f full"><span class="req">출장자</span><select id="traveler"></select></label>
          <div class="full">
            <div class="f" style="font-size:12px;color:var(--sub);font-weight:500;margin-bottom:4px">동행자 <span id="compCount"></span></div>
            <div class="chips" id="compChips"></div>
            <input id="compExtra" placeholder="외부 동행자 (쉼표로 구분)" style="margin-top:6px;border:1px solid var(--line);border-radius:7px;padding:7px 9px;background:var(--panel);width:100%">
          </div>
          <label class="f"><span>기간</span>
            <select id="period"><option>당일</option><option>1박2일</option><option>2박3일</option><option>3박4일</option><option>4박5일</option><option>1주 이상</option></select></label>
          <label class="f"><span class="req">출장지</span><input id="place" placeholder="예: 대전" autocomplete="off"></label>
          <label class="f full"><span>출장목적</span><input id="purpose" placeholder="예: 한밭대 노마드칼리지 계약 미팅"></label>
        </div>
      </fieldset>

      <fieldset id="fsMoney">
        <legend>출장여비 명세</legend>
        <div class="money">
          <span class="lab">교통 수단</span>
          <select id="mode">
            <option value="" hidden>선택</option>
            <option>자가용(휘발유)</option><option>자가용(경유)</option><option>자가용(LPG)</option>
            <option>KTX</option><option>고속버스</option><option>시외버스</option><option>지하철</option><option>택시</option><option>법인차량</option><option>기타</option>
          </select>
          <!-- 자가용이 아니면 갈아탄 수단을 더 고른다(KTX+지하철+택시). 저장 값은 '+' 로 잇는다 -->
          <div class="extra" id="extraBox">
            <span class="hint">함께 이용</span>
            <div class="chips" id="extraChips"></div>
          </div>
          <div class="hint legacy" id="modeLegacy" hidden></div>
          <div class="fuel" id="fuelBox">
            편도 <input id="km" inputmode="decimal" placeholder="0"> km
            <button type="button" class="btn" id="btnFuel" style="padding:5px 10px">주유비 기준 적용</button>
            <span id="fuelInfo"></span>
          </div>
          <span class="lab">교통비</span><input id="transport" class="num" inputmode="numeric" placeholder="0"><label class="att" data-k="transport">영수증<input type="file" accept="image/*,application/pdf" multiple aria-label="교통비 영수증 첨부"></label>
          <div class="thumbs" id="th-transport"></div>
          <span class="lab">통행료</span><input id="toll" class="num" inputmode="numeric" placeholder="0"><label class="att" data-k="toll">영수증<input type="file" accept="image/*,application/pdf" multiple aria-label="통행료 영수증 첨부"></label>
          <div class="thumbs" id="th-toll"></div>
          <span class="lab">식대</span><input id="meal" class="num" inputmode="numeric" placeholder="0"><label class="att" data-k="meal">영수증<input type="file" accept="image/*,application/pdf" multiple aria-label="식대 영수증 첨부"></label>
          <div class="thumbs" id="th-meal"></div>
          <span></span><div class="hint" id="mealHint" style="grid-column:2/-1"></div>
          <span class="lab">기타</span><input id="etc" class="num" inputmode="numeric" placeholder="0"><label class="att" data-k="etc">영수증<input type="file" accept="image/*,application/pdf" multiple aria-label="기타 영수증 첨부"></label>
          <div class="thumbs" id="th-etc"></div>
          <span></span><input id="etcNote" placeholder="기타 내역 (주차비, 숙박비 등)" style="text-align:left;grid-column:2/-1">
          <span></span><div class="hint" id="attHint" style="grid-column:2/-1">영수증은 사진(JPG·PNG) 또는 PDF로 올릴 수 있습니다.</div>
          <!-- 청구하는 금액 — 교통비+통행료+식대+기타. 금액을 고치면 바로 바뀐다 -->
          <span class="lab sumlab">총 합계</span><div class="moneysum num" id="moneyTotal">0원</div>
        </div>
      </fieldset>

      <fieldset id="fsApproval">
        <legend>승인</legend>
        <div class="stline" id="stApproval"></div>
        <div class="seg2" id="approvalSeg">
          <button type="button" data-v="승인대기" class="s-wait">승인대기</button>
          <button type="button" data-v="승인" class="s-ok">승인</button>
          <button type="button" data-v="반려" class="s-no">반려</button>
        </div>
        <div class="grid" style="margin-top:8px">
          <label class="f"><span>승인자</span><input id="approver" readonly title="승인·반려를 누르면 승인 관리자 이름이 자동으로 들어갑니다"></label>
          <label class="f"><span>승인일</span><input type="date" id="approvedAt"></label>
        </div>
      </fieldset>

      <fieldset id="fsSettle">
        <legend>정산</legend>
        <div class="stline" id="stSettle"></div>
        <div class="seg2" id="statusSeg">
          <button type="button" data-v="미처리" class="s-wait">미처리</button>
          <button type="button" data-v="처리완료" class="s-ok">처리완료</button>
        </div>
        <div class="grid" style="margin-top:8px">
          <label class="f"><span>총 합계</span><input id="settleTotal" class="num" readonly tabindex="-1" title="출장여비 명세의 합계와 같습니다"></label>
          <label class="f"><span>처리자</span><input id="handler" readonly title="정산 처리자는 김지안으로 고정입니다"></label>
          <label class="f"><span>처리일시</span><input type="date" id="processedAt"></label>
          <label class="f"><span>지급방법</span>
            <select id="payMethod"><option value="">선택</option><option>계좌이체</option><option>법인카드</option><option>현금</option><option>법인카드+계좌이체</option></select></label>
          <label class="f full"><span>비고</span><input id="note"></label>
        </div>
      </fieldset>
      <p class="lockmsg" id="lockMsg" hidden></p>
    </form>
    <div class="slip-foot">
      <div><div class="total-lab">합계</div><div class="total num" id="total">0원</div></div>
      <div class="btns">
        <button class="btn danger" id="btnDel" hidden>삭제</button>
        <button class="btn primary" id="btnSave">청구서 저장</button>
        <!-- 관리자(승인·정산) 화면: 누르면 바로 승인(반려)하고 저장한다. '청구서 저장' 단추와 위쪽 승인 칸을 대신한다 -->
        <button class="btn danger" id="btnReject" hidden>반려</button>
        <button class="btn primary" id="btnApprove" hidden>승인</button>
      </div>
    </div>
  </section>

  <div class="col right">
    <div id="infoArea" class="col">
      <div class="panel" id="pricePanel">
        <h2>오늘의 유가<span class="meta" id="priceLabel"></span></h2>
        <div class="prices" id="priceCards"></div>
        <div class="pfoot" id="priceFoot"></div>
      </div>
      <details class="panel" id="rulePanel">
        <summary>출장비 산정기준 (작성기준)</summary>
        <div class="rules">
          <h3>1. 출장보고서 작성기준</h3>
          <ol type="가" style="list-style-type:none;padding-left:0">
            <li>가. 신청일은 출장업무를 수행한 다음 날을 기준으로 청구하며, 그날이 휴일이면 그다음 날을 기준으로 합니다.</li>
            <li>나. 주유비 산정기준을 확인하여, 주유산정금액 안에서 비용을 산정·작성합니다.</li>
          </ol>
          <h3>2. 출장비 기준</h3>
          <div class="tablewrap"><table>
            <thead><tr><th style="width:84px">구분</th><th>내용</th><th>기준</th></tr></thead>
            <tbody>
              <tr><td>숙박비</td><td>업무로 인한 출장지에서의 숙박비용</td><td>1박 1실 80,000원 기준<br>예외사항이 없는 한 1명 이상 동행 시 10,000원씩 추가 산정</td></tr>
              <tr><td>주유비</td><td>출장지로의 자가용 운행에 따른 주유비용</td><td>주유비 산정기준에 따름<br>주유산정금액 안에서 비용을 산정</td></tr>
              <tr><td>식대</td><td>업무로 인한 출장지에서의 식대비용</td><td>1인 식대 <span id="ruleMeal">15,000</span>원 기준</td></tr>
              <tr><td>기타</td><td>영수증을 첨부할 수 있는 기타비용 (고속도로 통행료, 주차비 등)</td><td>첨부된 영수증 기준</td></tr>
              <tr><td>장기 출장비</td><td>1주 이상의 업무출장에 따른 비용</td><td>프로젝트별로 회사와 협의하여 책정<br>별도 협의안이 타결되지 않으면 단기출장비로 산정된 비용 합계의 70% 기준</td></tr>
            </tbody>
          </table></div>
          <h3>3. 주유비 산정기준</h3>
          <div class="formula">주유산정금액 = 운행거리(km) ÷ 연비(km/L) × 유가(원) × 2(왕복)</div>
          <ol style="list-style-type:none;padding-left:0">
            <li>산출 금액은 백원 단위에서 올림하여 최종 금액을 정하며, 출장지까지의 왕복 주유비입니다.</li>
            <li>운행거리: 출장지까지의 편도 거리</li>
            <li>연비: 차량의 등록연비 기준</li>
            <li>유가기준: 한국석유공사 석유정보망 페트로넷(<a href="https://www.opinet.co.kr/" target="_blank" rel="noopener">opinet.co.kr</a>) / 대한LPG협회(<a href="https://www.klpg.or.kr/html/dh/board04" target="_blank" rel="noopener">klpg.or.kr</a>)의 공시유가</li>
            <li>기준유가는 매주 최신 유가(한국석유공사 오피넷 전국 주간 평균)로 산정하는 것으로 합니다.</li>
          </ol>
          <h3>출장 거리에 따른 주유산정비용 <span class="hint" id="ruleBase" style="font-weight:500"></span></h3>
          <div class="tablewrap"><table>
            <thead><tr><th>편도 거리</th><th class="r">휘발유</th><th class="r">경유</th><th class="r">LPG</th><th>적용 출장지</th></tr></thead>
            <tbody id="fuelTable"></tbody>
          </table></div>
        </div>
      </details>
    </div>
    <!-- 관리자 화면 순서: 요약 숫자 → 청구 목록 → 월별 출장여비·처리할 청구. 휴대폰에서는 현황/목록 탭이 나눠 보여 준다 -->
    <div id="dashArea" class="col">
      <div class="kpis" id="kpis"></div>
    </div>

    <div class="panel" id="listPanel">
      <!-- 제목 줄 전체가 단추다. 처음엔 접혀 있고, 한 번 누르면 펼치고 다시 누르면 접는다(작성기준 패널과 같다) -->
      <h2><button type="button" class="fold" id="listFold" aria-expanded="false" aria-controls="listBody">청구 목록<span class="meta" id="listMeta"></span><span class="foldtxt"></span></button></h2>
      <div id="listBody">
      <div class="tools">
        <input id="q" placeholder="출장자, 출장지, 목적 검색" aria-label="검색">
        <select class="btn" id="fStatus" aria-label="상태 필터">
          <option value="all">전체 상태</option><option value="승인대기">승인대기</option><option value="미처리">정산 미처리</option><option value="처리완료">정산 완료</option><option value="반려">반려</option>
        </select>
      </div>
      <div class="tablewrap">
        <table>
          <thead>
            <tr class="grp"><th colspan="7"></th><th colspan="7">출장여비 명세</th><th colspan="2">승인</th><th colspan="5">정산</th></tr>
            <tr><th>No.</th><th>출장자</th><th>동행자</th><th>출장일</th><th>기간</th><th>출장지</th><th>출장목적</th>
              <th>수단</th><th class="r">교통비</th><th class="r">통행료</th><th class="r">식대</th><th class="r">기타</th><th class="r">합계</th><th>영수증</th>
              <th>승인</th><th>승인자</th>
              <th>처리여부</th><th>처리자</th><th>처리일시</th><th>지급방법</th><th>비고</th></tr>
          </thead>
          <tbody id="tbody"></tbody>
          <tfoot id="tfoot"></tfoot>
        </table>
      </div>
      </div>
    </div>

    <div id="dashCharts" class="col">
      <div class="charts">
        <div class="panel">
          <h2>월별 출장여비<span class="meta" id="monthMeta"></span></h2>
          <div class="legend"><span><i style="background:var(--c1)"></i>교통비</span><span><i style="background:var(--c2)"></i>통행료</span><span><i style="background:var(--c3)"></i>식대</span><span><i style="background:var(--c4)"></i>기타</span></div>
          <div class="chart" id="monthChart"></div>
        </div>
        <div class="col" style="gap:16px">
          <div class="panel"><h2 id="queueTitle">처리할 청구<span class="meta">승인대기·미정산</span></h2><div class="queue" id="queue"></div></div>
          <div class="panel admin-only"><h2>출장자별 합계</h2><div class="bars-h" id="byPerson"></div></div>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- 구성원 화면 전용: 본인 청구와 진행 상태. 청구서 머리의 '내 청구' 단추로 연다 -->
<!-- 영수증 크게 보기 — 미리보기·영수증 칸을 누르면 새 탭 대신 이 창이 화면 위에 뜬다 -->
<dialog id="rcDlg" aria-labelledby="rcDlgTitle">
  <div class="rcdlg-head">
    <b id="rcDlgTitle"></b><span class="hint" id="rcDlgPos"></span>
    <a id="rcDlgOpen" target="_blank" rel="noopener">새 창으로 ↗</a>
    <button type="button" class="rcx" id="rcDlgClose" aria-label="닫기" title="닫기 (Esc)">×</button>
  </div>
  <div class="rcdlg-body">
    <button type="button" class="rcnav" id="rcPrev" aria-label="이전 영수증">‹</button>
    <div class="rcdlg-view" id="rcDlgView"></div>
    <button type="button" class="rcnav" id="rcNext" aria-label="다음 영수증">›</button>
  </div>
</dialog>
<!-- 관리자 화면으로 들어올 때 승인 대기·정산 대기가 있으면 뜬다 -->
<dialog id="pendDlg" aria-labelledby="pendDlgTitle">
  <div class="dh" id="pendDlgTitle">처리할 청구가 있습니다</div>
  <div class="db">
    <div class="pend-kpis" id="pendKpis"></div>
    <div class="queue pend-list" id="pendList"></div>
  </div>
  <div class="df"><button class="btn primary" id="pendDlgClose">확인</button></div>
</dialog>
<dialog id="myDlg" aria-labelledby="myDlgTitle">
  <div class="dh" id="myDlgTitle">My Claims <span class="hint" id="myMeta" style="font-weight:500"></span></div>
  <div class="db mylist" id="myList"></div>
  <div class="df"><button class="btn" id="myDlgClose">닫기</button></div>
</dialog>
<dialog id="dlg">
  <div class="dh">설정</div>
  <div class="db">
    <div>
      <div style="font-weight:600;margin-bottom:6px">권한</div>
      <div class="hint" style="margin-bottom:8px">관리자(정산)는 모든 청구를 관리하고 정산하며, 관리자(승인)는 승인·반려를 합니다. 두 관리자도 구성원 화면에서 본인 청구를 씁니다. 명단과 비밀번호는 blue-iWorks 포털 계정을 그대로 씁니다.</div>
      <div class="ulist" id="userList"></div>
    </div>
    <!-- 승인자·처리자는 설정하지 않는다 — 승인자는 김호영, 처리자는 김지안으로 고정된다(2026-10-26) -->
    <div>
      <div style="font-weight:600;margin-bottom:6px">주유비 산정 기준 <span class="hint" style="font-weight:400">(편도km ÷ 연비 × 유가 × 2, 천원 단위 올림)</span></div>
      <div class="grid">
        <label class="f"><span>휘발유 (원/L)</span><input id="sGas" inputmode="decimal"></label>
        <label class="f"><span>경유 (원/L)</span><input id="sDiesel" inputmode="decimal"></label>
        <label class="f"><span>LPG (원/L)</span><input id="sLpg" inputmode="decimal"></label>
        <label class="f"><span>연비 (km/L)</span><input id="sEff" inputmode="decimal"></label>
        <label class="f"><span>유가 기준일</span><input id="sBase"></label>
        <label class="f"><span>1인 식대 기준 (원)</span><input id="sMeal" inputmode="numeric"></label>
      </div>
    </div>
    <div>
      <div style="font-weight:600;margin-bottom:6px">유가 자동 갱신 (오피넷 무료 API)</div>
      <div class="hint" style="margin-bottom:6px" id="keyState"></div>
      <label class="f"><span>오피넷 API 키</span><input id="sKey" placeholder="발급받은 키를 붙여넣기" autocomplete="off"></label>
      <label style="display:flex;gap:8px;align-items:flex-start;margin-top:10px;font-size:13px;cursor:pointer">
        <input type="checkbox" id="sAutoFuel" style="margin-top:3px">
        <span>새로 받은 유가를 주유비 산정 기준에 <b>바로 적용</b><br><span class="hint">끄면 정산 관리자가 '이 가격을 산정 기준으로 적용'을 눌러야 바뀝니다(작성기준은 매주 최신 유가로 산정). 켜 두면 위 휘발유·경유·LPG 칸은 다음 갱신 때 새 유가로 덮입니다.</span></span>
      </label>
    </div>
  </div>
  <div class="df"><button class="btn" id="dlgCancel">닫기</button><button class="btn primary" id="dlgSave">설정 저장</button></div>
</dialog>

<div class="toast" id="toast" role="status"></div>

<!-- 주소에 파일 수정 시각을 붙여, 고치고 배포하면 브라우저가 옛 사본 대신 새 파일을 받게 한다(포털 pwa.js 와 같은 방식) -->
<script src="static/travel.js?v=<?= (int)@filemtime(__DIR__ . '/static/travel.js') ?>"></script>
</body>
</html>
