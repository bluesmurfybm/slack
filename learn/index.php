<?php
/**
 * BlueLearn — 사내 강의 수강료 지원. 화면은 이 한 장(SPA)이고, 데이터는 api.php 가 준다.
 * 포털 로그인 없이 들어오면 포털 로그인 화면으로 보낸다.
 */
require_once __DIR__ . '/guard.php';
learn_require_page_login();
?>
<!DOCTYPE html>
<html lang="ko">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BlueLearn</title>
  <link rel="icon" href="styles/favicon.ico">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet"
    href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
  <link rel="stylesheet" href="../styles/topbar.css">
  <link rel="stylesheet" href="styles/style.css">
</head>

<body>
  <div class="topbar">
    <div class="topbar-in">
      <a class="logo" id="hdrBrand" href="#" style="text-decoration:none"><b>blue</b><span
          class="dash">-</span>iWorks</a>
      <div class="seg-mode admin-only" role="group" aria-label="화면 전환">
        <button type="button" id="modeUser" class="on" onclick="setMode('user')">구성원 화면</button>
        <button type="button" id="modeAdmin" onclick="setMode('admin')">관리자 화면</button>
      </div>
      <div class="top-right">
        <div class="user-menu" id="hdrUserMenu">
          <div class="user-chip" onclick="toggleUserMenu(event)" title="메뉴">
            <span class="avatar" id="hdrAvatar"></span>
            <span class="nm" id="hdrName"></span>
            <span class="user-caret">▾</span>
          </div>
          <div class="dd-menu" id="hdrUserDd">
            <a href="#" id="dd-mypage">👤 마이페이지</a>
            <div class="dd-sep"></div>
            <div class="dd-label">업무 시스템</div>
            <a href="#" id="dd-slack">📥 업무현황판</a>
            <div class="dd-sep"></div>
            <a href="#" id="dd-logout">🚪 로그아웃</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <header class="site">
    <div class="site-inner">
      <div class="brand">
        <span class="eyebrow">BlueUP-Learning</span>
        <h1 id="pageTitle">BlueLearn</h1>
      </div>
    </div>
  </header>

  <div class="wrap">
    <!-- ===================== 구성원 화면 ===================== -->
    <section id="userView">
      <!-- 왼쪽은 내 현황, 오른쪽은 회사가 권하는 강의. 추천을 탭 안에 두면 거기까지
           들어가야만 보여서 아무도 안 본다 — 첫 화면에 나란히 둔다 -->
      <div class="home">
        <section class="mystrip" id="myStrip">
          <div class="ms-head">
            <span class="ms-ava" id="msAva"></span>
            <div class="ms-id">
              <span class="ms-name">
                <b id="msName"></b>
                <span class="ms-chip" id="msSub"></span>
              </span>
            </div>
            <span class="ms-hours" id="msHours"></span>
          </div>
          <div class="ms-body" id="msBody">
            <div class="ms-nums" id="msNums"></div>
            <div class="ms-running" id="msRunning"></div>
          </div>
        </section>

        <aside id="catPreview"></aside>
      </div>

      <!-- 관리자 처리 대기 줄. 관리자가 아니거나 처리할 게 없으면 비어 있다 -->
      <div id="todoRow"></div>

      <p class="note" id="policyNote" style="display:none"></p>

      <!-- 신청 목록과 추천·필수 강의는 성격이 달라 한 목록에 섞지 않는다.
           동작 버튼도 같은 밑선 위에 올려 "여기가 목록의 머리"임을 한 줄로 만든다 -->
      <div class="pane-bar">
        <div class="pane-tabs" id="paneTabs"></div>
        <div class="site-act" id="siteAct">
          <button class="btn-ghost" type="button" id="msMine"
            onclick="toggleMineOnly()">내 신청 내역</button>
          <button class="btn-new" id="btnNew" onclick="openForm()">＋ 강의 신청</button>
        </div>
      </div>

      <div class="toolbar" id="listTools">
        <div class="search">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            stroke-linecap="round">
            <circle cx="11" cy="11" r="7" />
            <path d="M20 20l-3.5-3.5" />
          </svg>
          <input id="f-q" type="text" placeholder="강의명·신청인으로 찾기" oninput="applyFilters()">
        </div>
        <div class="filters">
          <select id="f-site" onchange="applyFilters()"></select>
          <select id="f-level" onchange="applyFilters()"></select>
          <select id="f-status" onchange="applyFilters()"></select>
          <label class="check"><input type="checkbox" id="f-mine" onchange="applyFilters()"> 내 신청만</label>
          <div class="viewtoggle">
            <button type="button" id="vList" title="리스트로 보기" onclick="setLayout('list')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" />
              </svg>
            </button>
            <button type="button" id="vCard" title="카드로 보기" onclick="setLayout('card')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7" rx="1.5" />
                <rect x="14" y="3" width="7" height="7" rx="1.5" />
                <rect x="3" y="14" width="7" height="7" rx="1.5" />
                <rect x="14" y="14" width="7" height="7" rx="1.5" />
              </svg>
            </button>
          </div>
        </div>
      </div>

      <!-- 미이수 필수 알림. 추천·필수 강의 탭에서만 채워진다 -->
      <div id="catAlert"></div>

      <div class="ledger-head" id="ledgerHead">
        <span class="title"><span id="ledgerTitle">신청 목록</span>
          <span class="count" id="listCount"></span></span>
      </div>
      <ul class="list" id="board"></ul>
      <div id="listPager"></div>
    </section>

    <!-- ===================== 관리자 화면 ===================== -->
    <section id="adminView" style="display:none">
      <div class="adminlay">
        <nav class="sidenav" id="adminNav" aria-label="관리자 메뉴"></nav>
        <div id="adminPanel"></div>
      </div>
    </section>
  </div>

  <!-- ===================== 상세 드로어 ===================== -->
  <div class="scrim" id="scrim" onclick="closeDrawer()"></div>
  <aside class="drawer" id="drawer" aria-hidden="true">
    <header>
      <div class="d-head">
        <div class="d-tags" id="dTags"></div>
        <h3 id="dTitle"></h3>
      </div>
      <button class="x" onclick="closeDrawer()" aria-label="닫기">×</button>
    </header>
    <div class="d-body" id="dBody"></div>
    <footer id="dFoot"></footer>
  </aside>

  <!-- ===================== 등록/수정 폼 ===================== -->
  <div class="overlay" id="overlay">
    <div class="sheet">
      <div class="sheet-head">
        <div>
          <span class="k">BlueLearn</span>
          <h2 id="formTitle">강의 신청</h2>
        </div>
        <button class="x" onclick="closeSheet()" aria-label="닫기"
          style="background:none;border:none;font-size:22px;color:var(--tx-quinary);cursor:pointer">×</button>
      </div>
      <div class="sheet-body">
        <p class="note catalog" id="catalogNote" style="display:none"></p>
        <div class="two">
          <div class="field">
            <label for="i-site">교육 플랫폼</label>
            <select id="i-site" onchange="onSiteChange()"></select>
          </div>
          <div class="field">
            <label for="i-level">학습수준</label>
            <select id="i-level"></select>
          </div>
        </div>
        <div class="two">
          <div class="field">
            <label for="i-large">강의 대분류</label>
            <select id="i-large" onchange="onLargeChange()"></select>
          </div>
          <div class="field">
            <label for="i-medium">강의 중분류</label>
            <select id="i-medium"></select>
          </div>
        </div>
        <div class="addbar">
          <button type="button" class="btn-mini"
            onclick="openCategoryBrowser(true)">📚 분류 전체 보기</button>
          <span class="muted" id="catHint" style="display:none">★ 는 추천 분류입니다</span>
        </div>
        <div class="field">
          <label for="i-title">강의/교육명</label>
          <input id="i-title" type="text" list="titleSuggest" placeholder="강의명을 입력하세요">
          <datalist id="titleSuggest"></datalist>
        </div>
        <div class="field">
          <label for="i-url">수강주소</label>
          <input id="i-url" type="text" placeholder="https://">
        </div>
        <div class="two">
          <div class="field">
            <label for="i-applicant">신청자</label>
            <input id="i-applicant" type="text" readonly>
          </div>
          <div class="field">
            <label>계정 구분</label>
            <div class="seg" id="i-account">
              <button type="button" data-v="회사계정" onclick="pickAccount('회사계정')">회사계정</button>
              <button type="button" class="on" data-v="개인계정"
                onclick="pickAccount('개인계정')">개인계정</button>
            </div>
          </div>
        </div>
        <p class="note" id="accountNote" style="display:none">
          회사계정 결제 건은 승인까지만 진행되고 환급 절차가 없습니다.
        </p>
        <div class="two">
          <div class="field">
            <label>강의 기간</label>
            <div class="triple" style="grid-template-columns:1fr 1fr">
              <select id="i-hours"></select>
              <select id="i-minutes"></select>
            </div>
          </div>
          <div class="field">
            <label for="i-price">수강료</label>
            <input id="i-price" type="number" min="0" step="1000" placeholder="0">
            <label class="check" style="margin-top:2px">
              <input type="checkbox" id="i-free" onchange="onFreeChange()"> 무료 강의입니다
            </label>
          </div>
        </div>
        <p class="note" id="capNote" style="display:none"></p>
        <div class="two">
          <div class="field">
            <label for="i-start">강의 시작일</label>
            <input id="i-start" type="date">
          </div>
          <div class="field">
            <label for="i-end">강의 종료일</label>
            <input id="i-end" type="date">
          </div>
        </div>
      </div>
      <div class="sheet-foot">
        <button class="btn-ghost" onclick="closeSheet()">취소</button>
        <button class="btn-submit" id="formSave" onclick="saveForm()">저장</button>
      </div>
    </div>
  </div>

  <!-- ===================== 분류 한눈에 보기 ===================== -->
  <div class="overlay" id="catsOverlay">
    <div class="sheet wide">
      <div class="sheet-head">
        <div>
          <span class="k">강의 분류</span>
          <h2 id="catsTitle">플랫폼별 카테고리</h2>
        </div>
        <button class="x" onclick="closeCats()" aria-label="닫기"
          style="background:none;border:none;font-size:22px;color:var(--tx-quinary);cursor:pointer">×</button>
      </div>
      <div class="sheet-body" id="catsBody"></div>
      <div class="sheet-foot">
        <button class="btn-ghost" onclick="closeCats()">닫기</button>
      </div>
    </div>
  </div>

  <!-- ============ 추천 · 필수 강의 등록 (관리자) ============ -->
  <div class="overlay" id="cfOverlay">
    <div class="sheet">
      <div class="sheet-head">
        <div>
          <span class="k">BlueLearn · 관리자</span>
          <h2 id="cfTitle">추천 · 필수 강의 등록</h2>
        </div>
        <button class="x" onclick="closeCatalogForm()" aria-label="닫기"
          style="background:none;border:none;font-size:22px;color:var(--tx-quinary);cursor:pointer">×</button>
      </div>
      <div class="sheet-body">
        <div class="cf-sec"><i>1</i>강의 정보<span class="tag">강의 신청 폼과 동일</span></div>
        <div class="two">
          <div class="field">
            <label for="cf-site">교육 플랫폼</label>
            <select id="cf-site" onchange="cfSiteChange()"></select>
          </div>
          <div class="field">
            <label for="cf-level">학습수준</label>
            <select id="cf-level"></select>
          </div>
        </div>
        <div class="two">
          <div class="field">
            <label for="cf-large">강의 대분류</label>
            <select id="cf-large" onchange="cfLargeChange()"></select>
          </div>
          <div class="field">
            <label for="cf-medium">강의 중분류</label>
            <select id="cf-medium"></select>
          </div>
        </div>
        <div class="field">
          <label for="cf-title">강의/교육명</label>
          <input id="cf-title" type="text" placeholder="강의명을 입력하세요">
        </div>
        <div class="field">
          <label for="cf-url">수강주소</label>
          <input id="cf-url" type="text" placeholder="https://">
        </div>
        <div class="two">
          <div class="field">
            <label>강의 기간</label>
            <div class="triple" style="grid-template-columns:1fr 1fr">
              <select id="cf-hours"></select>
              <select id="cf-minutes"></select>
            </div>
          </div>
          <div class="field">
            <label for="cf-price">수강료</label>
            <input id="cf-price" type="number" min="0" step="1000" placeholder="0">
            <label class="check" style="margin-top:2px">
              <input type="checkbox" id="cf-free" onchange="cfFreeChange()"> 무료 강의입니다
            </label>
          </div>
        </div>

        <div class="cf-sec"><i>2</i>노출 설정</div>
        <div class="field">
          <label>등급</label>
          <div class="seg" id="cf-grade">
            <button type="button" data-v="추천" onclick="pickGrade('추천')">추천</button>
            <button type="button" data-v="필수" onclick="pickGrade('필수')">필수</button>
          </div>
          <p class="note" id="cfGradeNote" style="display:none">
            필수로 지정하면 이수 기한이 반드시 필요하고, 직원이 신청할 때 수강 승인 없이
            바로 수강 상태로 등록됩니다. 개인 연간 한도도 소모하지 않습니다.
          </p>
        </div>
        <div class="field">
          <label>대상 범위</label>
          <div class="seg" id="cf-scope">
            <button type="button" data-v="전사" onclick="pickScope('전사')">전사</button>
            <button type="button" data-v="지정" onclick="pickScope('지정')">구성원 지정</button>
          </div>
          <div class="cf-targets" id="cfTargets" style="display:none"></div>
        </div>
        <div class="two">
          <div class="field" id="cfDueWrap">
            <label for="cf-due">이수 기한</label>
            <input id="cf-due" type="date">
          </div>
          <div class="field">
            <label for="cf-sort">정렬 순서</label>
            <input id="cf-sort" type="number" min="0" step="1">
          </div>
        </div>
        <div class="two">
          <div class="field">
            <label for="cf-from">노출 시작일</label>
            <input id="cf-from" type="date">
          </div>
          <div class="field">
            <label for="cf-to">노출 종료일</label>
            <input id="cf-to" type="date">
          </div>
        </div>
        <div class="field">
          <label for="cf-reason">사유</label>
          <textarea id="cf-reason" class="date-field" rows="2"
            placeholder="왜 들어야 하는지 한 줄로 적어 주세요"></textarea>
          <span class="muted">직원 화면의 강의 카드에 그대로 노출됩니다.</span>
        </div>
        <label class="check">
          <input type="checkbox" id="cf-active"> 지금 바로 노출 (끄면 임시 저장 상태로만 보관됩니다)
        </label>
      </div>
      <div class="sheet-foot">
        <button class="btn-mini danger" id="cfDelete" onclick="deleteCatalog()"
          style="margin-right:auto">삭제</button>
        <button class="btn-ghost" onclick="closeCatalogForm()">취소</button>
        <button class="btn-submit" id="cfSave" onclick="saveCatalogForm()">등록</button>
      </div>
    </div>
  </div>

  <!-- ===================== 사유 입력 ===================== -->
  <div class="overlay" id="reasonOverlay">
    <div class="cdialog">
      <h3 id="reasonTitle">반려</h3>
      <p id="reasonHint">사유를 입력하면 신청자에게 그대로 보입니다.</p>
      <textarea id="reasonInput" class="date-field" rows="3" placeholder="사유"></textarea>
      <div class="row-btn">
        <button class="btn-ghost" onclick="closeReason(null)">취소</button>
        <button class="btn-submit" onclick="submitReason()">확인</button>
      </div>
    </div>
  </div>

  <!-- ===================== 확인 ===================== -->
  <div class="overlay" id="confirmOverlay">
    <div class="cdialog">
      <h3 id="confirmTitle">확인</h3>
      <p id="confirmHint"></p>
      <div class="row-btn">
        <button class="btn-ghost" onclick="closeConfirm(false)">취소</button>
        <button class="btn-submit" id="confirmOk" onclick="closeConfirm(true)">확인</button>
      </div>
    </div>
  </div>

  <div class="toast" id="toast"></div>

  <script src="static/core.js"></script>
  <script src="static/list.js"></script>
  <script src="static/form.js"></script>
  <script src="static/cats.js"></script>
  <script src="static/catalog.js"></script>
  <script src="static/drawer.js"></script>
  <script src="static/manage.js"></script>
  <script src="static/stats.js"></script>
  <script src="static/admin.js"></script>
  <script src="static/main.js"></script>
</body>

</html>
