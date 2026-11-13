<?php
/**
 * BlueLedger 출장여비 — 화면은 이 한 장이고, 데이터는 api.php 가 준다.
 * 포털 로그인 없이 들어오면 포털 로그인 화면으로 보낸다.
 */
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../core/worksystems.php';
$u = bl_require_page_login();
if (isset($_GET['trip'])) {
    require_once __DIR__ . '/lib/notify.php';
    bl_notice_seen((int)$u['id'], (string)$_GET['trip']);
}
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
<link rel="stylesheet" href="styles/common.css?v=<?= (int)@filemtime(__DIR__ . '/styles/common.css') ?>">
<link rel="stylesheet" href="styles/travel.css?v=<?= (int)@filemtime(__DIR__ . '/styles/travel.css') ?>">
</head>
<body>
<div class="topbar">
  <div class="topbar-in">
    <a class="logo" href="../index.php" style="text-decoration:none"><b>blue</b><span class="dash">-</span>iWorks</a>
    <div class="top-right">
      <?= work_systems_bell('../', 'blueledger') ?>
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
  <!-- 관리자 화면에서만 보인다 -->
  <span class="rolebadge" aria-label="관리자 화면">관리자</span>
  <!-- 관리자 보기 전환(2026-11-13 칸반 시안): 보드(청구 칸반) · 일정(달력, 준비 중) · 통계(예전 관리자 대시보드) -->
  <div class="viewseg" id="viewSeg" role="tablist" aria-label="관리자 보기">
    <button type="button" role="tab" data-v="board" aria-selected="true">보드</button>
    <button type="button" role="tab" data-v="cal" aria-selected="false">일정</button>
    <button type="button" role="tab" data-v="stats" aria-selected="false">통계</button>
  </div>
  <span class="hspace"></span>
  <!-- 오늘 유가 알약 — 예전 '오늘의 유가' 큰 카드 3개를 대신한다(값은 같은 유가 API) -->
  <span class="fuelpill num" id="fuelPill" hidden></span>
  <!-- 관리자 화면: 마지막 갱신 시각 + 새로고침(예전 '지금 갱신' — 정산 관리자만) -->
  <span class="fuelupd" id="fuelUpd" hidden><span class="num" id="fuelTime"></span>
    <button type="button" class="rbtn admin-only" id="btnFuelRefresh" aria-label="유가 지금 갱신" title="유가 지금 갱신"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M19.5 12a7.5 7.5 0 1 1-2.2-5.3"/><path d="M19.5 4.5v3.8h-3.8"/></svg></button></span>
  <!-- 관리자 화면: 연도(통계 보기에서만) · CSV 내보내기 · 설정(정산 관리자만) -->
  <div class="hact" id="hAct">
    <select class="btn selbtn" id="yearSel" aria-label="조회 연도"></select>
    <button type="button" class="btn" id="btnCsv"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7.5 10.5L12 15l4.5-4.5M5 19.5h14"/></svg>CSV</button>
    <button type="button" class="btn icbtn admin-only" id="btnSettings" aria-label="설정" title="설정"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg></button>
  </div>
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
    <!-- 구성원 화면에서는 오른쪽에서 열리는 드로어(폭 480px, 휴대폰은 전체 화면). 관리자 팝업 창에서는 창을 꽉 채운다 -->
    <div class="slip-head">
      <div class="sh-t"><h2 id="slipTitle">출장여비 청구서</h2>
        <div class="sh-sub"><span class="mode" id="formMode">New</span><span class="qtag" id="queueTag" hidden></span><span class="draftnote" id="draftNote"></span></div></div>
      <button type="button" class="btn fresh" id="btnNew" hidden>새로 쓰기</button>
      <button type="button" class="btn mybtn" id="btnMy" aria-haspopup="dialog">My Claims<span class="cnt" id="myCount"></span></button>
      <button type="button" class="slipx" id="btnSlipClose" aria-label="닫기" title="닫기"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
    <ol class="steps" id="steps" aria-label="진행 단계"><li data-s="1"><i>1</i>청구</li><li data-s="2"><i>2</i>대표 승인</li><li data-s="3"><i>3</i>정산</li></ol>
    <form class="slip-body" id="form" autocomplete="off" onsubmit="return false">
      <fieldset id="fsInfo">
        <legend>출장 정보</legend>
        <div class="grid">
          <!-- 출장 기간: 칸을 누르면 달력이 팝업으로 열린다. 값은 숨은 칸(#date, YYYY-MM-DD)에 들어가고 저장은 그 값을 쓴다.
               기간(당일·1박2일 …)은 달력 아래에서 고르고, 칸 오른쪽에 배지로 보인다 -->
          <div class="full datewrap">
            <div class="flab"><span class="req">출장 기간</span></div>
            <button type="button" class="datefield" id="dateField" aria-haspopup="true" aria-expanded="false"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15" rx="2.5"/><path d="M3.5 9.5h17M8 3v4M16 3v4"/></svg><span class="calpick num" id="calPick"></span><span class="pbadge" id="periodBadge">당일</span></button>
            <div class="calpop" id="calPop" hidden>
              <div class="cal" id="cal"></div>
              <div class="calfoot">
                <label class="f"><span>기간</span>
                  <select id="period"><option>당일</option><option>1박2일</option><option>2박3일</option><option>3박4일</option><option>4박5일</option><option>1주 이상</option></select></label>
                <button type="button" class="btn primary" id="calDone">확인</button>
              </div>
            </div>
            <input type="hidden" id="date">
          </div>
          <label class="f"><span class="req">출장지</span><input id="place" placeholder="예: 대전" autocomplete="off"></label>
          <label class="f"><span class="req">출장자</span><select id="traveler"></select></label>
          <label class="f full"><span>출장 목적</span><input id="purpose" placeholder="예: 한밭대 노마드칼리지 계약 미팅"></label>
          <!-- 동행자: 이름으로 찾아 넣으면 이름표(이니셜·이름·x)로 붙는다. 외부 동행자는 쉼표로 -->
          <div class="full">
            <div class="flab">동행자 <span id="compCount"></span></div>
            <div class="compbox">
              <div class="searchf"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4 4"/></svg><input id="compSearch" placeholder="이름으로 검색해 추가" autocomplete="off" aria-controls="compSugg"></div>
              <div class="sugg" id="compSugg" role="listbox" hidden></div>
            </div>
            <div class="tags" id="compChips"></div>
            <input id="compExtra" class="inp" placeholder="외부 동행자 (쉼표로 구분)">
          </div>
        </div>
      </fieldset>

      <fieldset id="fsMoney">
        <legend>경비 항목</legend>
        <button type="button" class="linkbtn rulebtn" id="ruleShow" aria-expanded="false" aria-controls="ruleBox">기준 보기</button>
        <div class="rulebox" id="ruleBox" hidden>
          <div><b>숙박비</b> 1박 1실 80,000원 (동행 1명마다 10,000원 추가)</div>
          <div><b>식대</b> 1인 <span class="rbMeal">15,000</span>원</div>
          <div><b>주유비</b> 편도 거리 ÷ 연비 × 유가 × 2(왕복), 올림</div>
          <div><b>기타</b> 영수증 실비 (통행료·주차비 등)</div>
        </div>
        <!-- 경비는 항목별 카드: 아이콘 | 항목명·세부 | 금액. 영수증이 필요한데 없으면 카드 테두리가 주황색이 된다 -->
        <div class="xlist">
          <div class="xcard" id="xc-transport" data-k="transport">
            <div class="xrow"><span class="xic"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 15l1.4-4.6A2 2 0 0 1 7.8 9h8.4a2 2 0 0 1 1.9 1.4L19.5 15v3H17M4.5 15v3H7M4.5 15h15"/><circle cx="7.5" cy="17.5" r="1.5"/><circle cx="16.5" cy="17.5" r="1.5"/></svg></span><div class="xmain"><b>교통비</b><span class="xsub" id="xs-transport"></span></div>
              <span class="xamtw"><input id="transport" class="num xamt" inputmode="numeric" placeholder="0" aria-label="교통비"><em>원</em></span></div>
            <div class="xbody">
              <select id="mode" aria-label="교통 수단">
                <option value="" hidden>선택</option>
                <option>자가용(휘발유)</option><option>자가용(경유)</option><option>자가용(LPG)</option>
                <option>KTX</option><option>고속버스</option><option>시외버스</option><option>지하철</option><option>택시</option><option>법인차량</option><option>기타</option>
              </select>
              <!-- 자가용이 아니면 갈아탄 수단을 더 고른다(KTX+지하철+택시). 저장 값은 '+' 로 잇는다 -->
              <div class="extra" id="extraBox"><span class="hint">함께 이용</span><div class="chips" id="extraChips"></div></div>
              <div class="hint legacy" id="modeLegacy" hidden></div>
              <!-- 자가용 주유비: 계산식을 보여 주고, 오늘 유가가 자동으로 들어간다(영수증 불필요) -->
              <div class="fuel" id="fuelBox">
                <div class="frow">편도 <input id="km" inputmode="decimal" placeholder="0" aria-label="편도 거리 km"> km<button type="button" class="btn sm" id="btnFuel">금액에 적용</button></div>
                <div class="formula num" id="fuelInfo"></div>
                <div class="okline"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>오늘 유가 자동 적용 · 영수증 불필요</div>
              </div>
            </div>
          <div class="xrc"><div class="thumbs" id="th-transport"></div><span class="xrcn" id="rcn-transport"></span><span class="xneed"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.8v5.2M12 16.2h.01"/></svg>영수증을 첨부해 주세요</span><label class="att" data-k="transport">사진 첨부<input type="file" accept="image/*,application/pdf" multiple aria-label="교통비 영수증 첨부"></label></div>
          </div>
          <div class="xcard" id="xc-toll" data-k="toll">
            <div class="xrow"><span class="xic"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4L5 20M16 4l3 16M12 5v2.5M12 11v2.5M12 17v2.5"/></svg></span><div class="xmain"><b>통행료</b><span class="xsub">고속도로 통행료·주차비</span></div>
              <span class="xamtw"><input id="toll" class="num xamt" inputmode="numeric" placeholder="0" aria-label="통행료"><em>원</em></span></div>
          <div class="xrc"><div class="thumbs" id="th-toll"></div><span class="xrcn" id="rcn-toll"></span><span class="xneed"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.8v5.2M12 16.2h.01"/></svg>영수증을 첨부해 주세요</span><label class="att" data-k="toll">사진 첨부<input type="file" accept="image/*,application/pdf" multiple aria-label="통행료 영수증 첨부"></label></div>
          </div>
          <!-- 숙박비(2026-11-13 추가) — 박수·인원(출장자+동행)으로 기준(1박 80,000원 + 동행 1명당 10,000원)을 계산해 넘으면 주황 -->
          <div class="xcard" id="xc-lodging" data-k="lodging">
            <div class="xrow"><span class="xic"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 18.5V6.5M3 14h18v4.5M21 14v-2.5A2.5 2.5 0 0 0 18.5 9H11v5"/><circle cx="7" cy="11" r="2"/></svg></span><div class="xmain"><b>숙박비</b><span class="xsub" id="lodgingHint"></span></div>
              <span class="xamtw"><input id="lodging" class="num xamt" inputmode="numeric" placeholder="0" aria-label="숙박비"><em>원</em></span></div>
            <div class="xbody"><div class="lrow">
              <label class="lnum"><input id="lodgingNights" class="num" type="number" min="0" max="99" inputmode="numeric" aria-label="숙박 박수"><span>박</span></label>
              <label class="lnum"><input id="lodgingPeople" class="num" type="number" min="1" max="99" inputmode="numeric" aria-label="숙박 인원(동행 포함)"><span>명</span></label>
              <span class="hint">인원은 출장자 포함 · 동행자를 넣으면 저절로 바뀝니다</span></div></div>
          <div class="xrc"><div class="thumbs" id="th-lodging"></div><span class="xrcn" id="rcn-lodging"></span><span class="xneed"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.8v5.2M12 16.2h.01"/></svg>영수증을 첨부해 주세요</span><label class="att" data-k="lodging">사진 첨부<input type="file" accept="image/*,application/pdf" multiple aria-label="숙박비 영수증 첨부"></label></div>
          </div>
          <div class="xcard" id="xc-meal" data-k="meal">
            <div class="xrow"><span class="xic"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3v7a2 2 0 0 0 2 2v9M5 3v4.5M9 3v4.5M17 21V3c-2.2 1.4-3.5 4-3.5 7.5H17"/></svg></span><div class="xmain"><b>식대</b><span class="xsub"><span id="mealHint"></span></span></div>
              <span class="xamtw"><input id="meal" class="num xamt" inputmode="numeric" placeholder="0" aria-label="식대"><em>원</em></span></div>
          <div class="xrc"><div class="thumbs" id="th-meal"></div><span class="xrcn" id="rcn-meal"></span><span class="xneed"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.8v5.2M12 16.2h.01"/></svg>영수증을 첨부해 주세요</span><label class="att" data-k="meal">사진 첨부<input type="file" accept="image/*,application/pdf" multiple aria-label="식대 영수증 첨부"></label></div>
          </div>
          <div class="xcard" id="xc-etc" data-k="etc">
            <div class="xrow"><span class="xic"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h3"/></svg></span><div class="xmain"><b>기타</b><span class="xsub">주차비 등 영수증 실비</span></div>
              <span class="xamtw"><input id="etc" class="num xamt" inputmode="numeric" placeholder="0" aria-label="기타"><em>원</em></span></div>
            <div class="xbody"><input id="etcNote" class="inp" placeholder="기타 내역 (주차비 등)"></div>
          <div class="xrc"><div class="thumbs" id="th-etc"></div><span class="xrcn" id="rcn-etc"></span><span class="xneed"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.8v5.2M12 16.2h.01"/></svg>영수증을 첨부해 주세요</span><label class="att" data-k="etc">사진 첨부<input type="file" accept="image/*,application/pdf" multiple aria-label="기타 영수증 첨부"></label></div>
          </div>
        </div>
        <div class="hint" id="attHint"></div>
        <!-- 청구하는 금액 — 교통비+통행료+식대+기타. 금액을 고치면 바로 바뀐다 -->
        <div class="xsum"><span class="lab sumlab">총 합계</span><div class="moneysum num" id="moneyTotal">0원</div></div>
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
      <div class="sf-sum"><div class="total-lab">합계</div><div class="total num" id="total">0원</div></div>
      <!-- 항목 비율 막대(구성원 청구 작성 창) -->
      <div class="sfbar" id="sumBar" aria-hidden="true"></div>
      <div class="btns">
        <button class="btn danger" id="btnDel" hidden>삭제</button>
        <button class="btn" id="btnDraft" hidden>임시 저장</button>
        <button class="btn primary" id="btnSave">청구서 저장</button>
        <!-- 관리자(승인·정산) 화면: 누르면 바로 승인(반려)하고 저장한다. '청구서 저장' 단추와 위쪽 승인 칸을 대신한다 -->
        <button class="btn danger" id="btnReject" hidden>반려</button>
        <button class="btn primary" id="btnApprove" hidden>승인</button>
      </div>
    </div>
  </section>

  <div class="col right">
    <!-- 구성원 첫 화면 '출장경비 대시보드' (2026-11-13 시안). 관리자 화면에서는 숨는다 -->
    <section class="mdash" id="memberDash" aria-labelledby="mbTitle">
      <!-- 파란 배너 — 이번 달 청구액과 바로 가기 -->
      <div class="mbanner">
        <div class="mb-l">
          <h2 class="mb-k" id="mbTitle">출장경비</h2>
          <div class="mb-v num" id="mbAmt">0<small>원</small></div>
          <div class="mb-s num" id="mbSub"></div>
        </div>
        <div class="mb-act">
          <button type="button" class="btn mb-new" id="btnNewClaim"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>새 출장비 청구</button>
          <button type="button" class="btn mb-rc" id="btnRcFirst"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l2-2.5h6L17 8h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>영수증 먼저 올리기</button>
        </div>
      </div>
      <!-- 할 일(넓게) + 이번 달 경비 구성(좁게) -->
      <div class="mgrid">
        <div class="card"><div class="chead"><h3>할 일</h3><span class="meta num" id="todoMeta"></span></div>
          <div class="todos" id="todoList"></div></div>
        <div class="card"><div class="chead"><h3>이번 달 경비 구성</h3></div>
          <div class="donutw"><div class="donut" id="mixDonut"></div><div class="mixlist" id="mixList"></div></div></div>
      </div>
      <!-- 출장비 기준 — 알약 한 줄 -->
      <div class="rpills" aria-label="출장비 기준">
        <span class="rp"><b>숙박</b>1박 80,000원</span>
        <span class="rp"><b>식대</b><span class="num">1인 1식 <span class="rbMeal">15,000</span>원</span></span>
        <span class="rp"><b>주유비</b>오늘 유가로 왕복 자동 계산</span>
        <span class="rp"><b>기타</b>영수증 실비</span>
        <button type="button" class="linkbtn" id="btnRules">전체 기준 보기</button>
      </div>
      <!-- 진행 보드 — 작성 중 → 대표 승인 대기 → 정산 대기 → 정산 완료 -->
      <div class="mbhead"><h3>진행 현황</h3><button type="button" class="linkbtn" id="btnAllHist">전체 내역</button></div>
      <div class="mboard" id="mBoard"></div>
    </section>
    <!-- 관리자 '청구 보드' (2026-11-13 칸반 시안) — 승인 대기 → 정산 대기 → 정산 완료 / 반려.
         카드를 다음 열로 끌거나 카드의 단추를 누르면 승인·정산이 처리된다(청구서 창과 같은 저장) -->
    <section class="kview" id="boardView" aria-labelledby="boardTitle">
      <div class="dhead">
        <div><h2 class="dtitle" id="boardTitle">청구 보드</h2><div class="dsub" id="boardSub">카드를 끌어 다음 단계로 옮기거나 버튼으로 처리하세요</div></div>
        <div class="ksum">
          <div class="ksumi"><span>승인할 금액</span><b class="num" id="sumApprove">0원</b></div>
          <div class="ksumi"><span>지급 예정</span><b class="num" id="sumPay">0원</b></div>
        </div>
      </div>
      <!-- 휴대폰: 열 탭으로 넘긴다(가로로 밀어도 된다) -->
      <div class="ctabs ktabs" id="kTabs" role="tablist" aria-label="열"></div>
      <div class="kboard" id="kBoard"></div>
    </section>
    <!-- 일정 — 월간 달력에 사람별 색으로 출장 표시(다음에 만든다) -->
    <section class="kview" id="calView" aria-labelledby="calTitle">
      <div class="dhead"><div><h2 class="dtitle" id="calTitle">출장 일정</h2><div class="dsub">월간 달력에 사람별 색깔로 출장을 표시합니다</div></div></div>
      <div class="card kcal-empty"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15" rx="2.5"/><path d="M3.5 9.5h17M8 3v4M16 3v4"/></svg><b>일정 보기는 준비 중입니다</b><span>지금은 보드와 통계를 이용해 주세요.</span></div>
    </section>
    <!-- 관리자 '통계' — 예전 '출장경비 관리'(요약 카드·월별·출장자별·청구 목록·출장비 기준). 구성원 화면에서는 숨는다 -->
    <section class="adash" id="adminDash" aria-labelledby="adminTitle">
      <div class="dhead">
        <div><div class="dmon">요약 · 월별 · 출장자별 · 청구 목록</div><h2 class="dtitle" id="adminTitle">출장경비 통계</h2></div>
        <div class="dact"><span class="meta num" id="statsYear"></span></div>
      </div>
      <div class="scards" id="kpis"></div>
      <div class="achart">
        <div class="card"><div class="chead"><h3>월별 출장여비</h3><span class="meta num" id="monthMeta"></span></div>
          <div class="chart" id="monthChart"></div></div>
        <div class="card"><div class="chead"><h3>출장자별 합계</h3><span class="meta num" id="personMeta"></span></div>
          <div class="plist" id="byPerson"></div></div>
      </div>
      <div class="agrid">
        <div class="card alist" id="listCard">
          <div class="chead"><h3>청구 목록</h3><span class="meta num" id="listMeta"></span></div>
          <div class="ltools">
            <div class="ctabs" role="tablist" id="fTabs" aria-label="상태"></div>
            <div class="searchf lsearch"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4 4"/></svg><input id="q" placeholder="출장자·출장지 검색" aria-label="출장자·출장지 검색" autocomplete="off"></div>
          </div>
          <div class="tablewrap">
            <table class="atable">
              <thead><tr><th>출장일</th><th>출장자</th><th>출장지 · 목적</th><th class="r">금액</th><th>상태</th></tr></thead>
              <tbody id="tbody"></tbody>
              <tfoot id="tfoot"></tfoot>
            </table>
          </div>
        </div>
        <div class="card" id="aRuleCard"><div class="chead"><h3>출장비 기준</h3><button type="button" class="btn sm rhbtn" id="btnRuleEdit">기준 수정</button></div>
          <ul class="rsum">
            <li><span>숙박비</span><b class="num">1박 80,000원</b></li>
            <li><span>식대</span><b class="num">1인 1식 <span class="rbMeal">15,000</span>원</b></li>
            <li><span>주유비</span><b>거리÷연비×유가×2</b></li>
            <li><span>장기 출장</span><b>협의 · 미타결 시 70%</b></li>
          </ul>
          <div class="rsrc" id="ruleSrc"></div>
          <button type="button" class="linkbtn" id="btnRuleAll">전체 기준 보기</button>
        </div>
      </div>
    </section>
  </div>
</main>

<div class="scrim" id="scrim"></div>
<!-- 관리자 보드: 카드를 누르면 오른쪽에서 열리는 상세 창(읽기 전용 + 처리 단추) -->
<aside class="kdrawer" id="kDrawer" aria-labelledby="kdName" aria-hidden="true">
  <div class="kd-head">
    <span class="av" id="kdAv"></span>
    <div class="kd-t"><h2 id="kdName"></h2><div class="kd-sub" id="kdSub"></div></div>
    <button type="button" class="slipx kd-x" id="kdClose" aria-label="닫기" title="닫기 (Esc)"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
  </div>
  <div class="kd-body" id="kdBody"></div>
  <!-- 아래 고정 처리 영역 — 상태·권한에 따라 반려 사유 + [반려][승인] / [정산 완료 처리] / 사유 + [승인 대기로 되돌리기] -->
  <div class="kd-foot" id="kdFoot"></div>
</aside>
<!-- 반려 사유 — 청구서 창(팝업)의 [반려]가 쓴다. 보드는 상세 창 아래 칸에서 받는다. 꼭 적어야 하고, 청구자 화면에 그대로 보인다 -->
<dialog id="rejDlg" aria-labelledby="rejDlgTitle">
  <div class="dh" id="rejDlgTitle">반려 사유</div>
  <div class="db">
    <div class="rej-who" id="rejWho"></div>
    <label class="f"><span class="req">사유</span><textarea id="rejReason" rows="4" maxlength="500" placeholder="예: 식대가 기준(1인 15,000원)을 넘습니다. 영수증을 다시 확인해 주세요."></textarea></label>
    <div class="hint">적은 사유는 청구자 화면에 그대로 보입니다.</div>
  </div>
  <div class="df"><button type="button" class="btn" id="rejCancel">취소</button><button type="button" class="btn danger rejgo" id="rejOk">반려</button></div>
</dialog>
<!-- [출장비 기준] 전체 — 작성기준과 유가별 주유비 표. 구성원 [출장비 기준], 관리자 [전체 기준 보기]가 연다 -->
<dialog id="ruleDlg" aria-label="출장비 기준">
  <div class="dh">출장비 기준<button type="button" class="rcx" id="ruleDlgClose" aria-label="닫기"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
  <div class="db" id="ruleDlgBody">
      <details class="panel" id="rulePanel" open>
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
</dialog>
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
