<?php
/**
 * BlueBooks — 사내 도서 구매 신청. 화면은 이 한 장(SPA)이고, 데이터는 api.php 가 준다.
 * 포털 로그인 없이 들어오면 포털 로그인 화면으로 보낸다.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/worksystems.php';

$page = book_page_begin();
$me = $page['identity'];
$meJson = json_encode([
    'email' => $me['email'],
    'name' => $me['name'],
    'color' => $me['color'],
    'is_admin' => $page['is_admin'],
    'portal_url' => $page['portal'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="ko">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BlueBooks</title>
  <link rel="icon" type="image/svg+xml" href="../styles/favicons/book.svg">
  <?php require_once __DIR__ . '/../core/pwa.php'; echo pwa_head_tags('../'); ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet"
    href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
  <link rel="stylesheet" href="../styles/topbar.css">
  <link rel="stylesheet" href="<?= book_asset('styles/style.css') ?>">
</head>

<body data-mode="<?= $page['is_admin'] ? 'admin' : 'user' ?>">
  <div class="topbar">
    <div class="topbar-in">
      <a class="logo" href="<?= book_h($page['portal']) ?>/" style="text-decoration:none"><b>blue</b><span
          class="dash">-</span>iWorks</a>
      <div class="top-right">
        <div class="user-menu" id="hdrUserMenu">
          <div class="user-chip" onclick="toggleUserMenu(event)" title="메뉴">
            <span class="avatar" style="background:<?= book_h($me['color'] ?: '#606D79') ?>"><?= book_h(mb_substr($me['name'], 0, 1)) ?></span>
            <span class="nm"><?= book_h($me['name']) ?></span>
            <span class="user-caret">▾</span>
          </div>
          <div class="dd-menu" id="hdrUserDd">
            <a href="<?= book_h($page['portal']) ?>/?view=profile">👤 마이페이지</a>
            <div class="dd-sep"></div>
            <?= work_systems_menu('../', 'book') ?>
            <div class="dd-sep"></div>
            <a href="<?= book_h($page['portal']) ?>/api/logout.php">🚪 로그아웃</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <header class="site">
    <div class="site-inner">
      <div class="brand">
        <span class="eyebrow">사내 도서 구매 · Book Requests</span>
        <h1>BlueBooks</h1>
      </div>
      <div class="site-act">
        <button class="btn-new" onclick="openForm()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 5v14M5 12h14" />
          </svg>
          신청
        </button>
      </div>
    </div>
  </header>

  <div class="wrap">
    <div class="toolbar">
      <div class="search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="11" cy="11" r="7" />
          <path d="M21 21l-4.3-4.3" />
        </svg>
        <input id="q" type="text" placeholder="도서명 검색" oninput="render()" autocomplete="off">
      </div>
      <div class="filters">
        <select id="yearSel" onchange="onYear(this.value)"></select>
      </div>
    </div>

    <div class="chips applicant-filter" id="applicantFilter"></div>

    <div class="ledger-head">
      <span class="title" id="ledgerTitle">신청 목록</span>
      <span class="count" id="ledgerCount"></span>
    </div>
    <ul class="list" id="list"></ul>
    <div class="applicant-total" id="applicantTotal">
      <span class="t-label" id="applicantTotalLabel"></span>
      <span class="t-sum" id="applicantTotalSum"></span>
    </div>
  </div>

  <div class="overlay" id="overlay" onclick="if(event.target===this)closeForm()">
    <div class="sheet">
      <div class="sheet-head">
        <div><span class="k">신규 신청</span>
          <h2>도서 구매 신청서</h2>
        </div>
        <button class="x" onclick="closeForm()" aria-label="닫기">×</button>
      </div>
      <div class="sheet-body">
        <div class="field">
          <label>신청자</label>
          <div class="chips" id="picker"></div>
        </div>
        <div class="two">
          <div class="field">
            <label>신청일</label>
            <input id="f-date" type="date">
          </div>
          <div class="field">
            <label>구분</label>
            <div class="seg" id="seg">
              <button type="button" data-v="직무관련" onclick="setGubun(this)">직무관련</button>
              <button type="button" data-v="직무무관" class="on" onclick="setGubun(this)">직무무관</button>
            </div>
          </div>
        </div>
        <div class="field">
          <label>도서명</label>
          <input id="f-title" type="text" placeholder="도서명을 입력하면 후보를 찾아줍니다" oninput="onTitleInput()" autocomplete="off">
          <div class="booksearch" id="booksearch"></div>
        </div>
        <div class="dup" id="dup">
          <div class="h">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
            </svg>
            이미 신청된 책이에요
          </div>
          <ul id="dup-list"></ul>
        </div>
        <div class="two">
          <div class="field"><label>저자</label><input id="f-author" type="text" placeholder="저자명"></div>
          <div class="field"><label>출판사</label><input id="f-pub" type="text" placeholder="출판사"></div>
        </div>
        <div class="field">
          <label>도서단가 (원)</label>
          <input id="f-price" type="text" inputmode="numeric" placeholder="" oninput="formatPriceInput(this)">
          <div class="notice" id="price-notice">직무관련 도서는 단가의 50% 가 지원됩니다</div>
        </div>
        <div class="field">
          <label>비고</label>
          <textarea id="f-note" placeholder=""></textarea>
        </div>
      </div>
      <div class="sheet-foot">
        <button class="btn-ghost" onclick="closeForm()">취소</button>
        <button class="btn-submit" onclick="submitForm()">신청 등록</button>
      </div>
    </div>
  </div>

  <div class="overlay" id="confirmOverlay" onclick="if(event.target===this)closeConfirm()">
    <div class="cdialog">
      <h3>신청 삭제</h3>
      <p id="confirmText"></p>
      <div class="row-btn">
        <button class="btn-ghost" onclick="closeConfirm()">취소</button>
        <button class="btn-danger" onclick="doDelete()">삭제</button>
      </div>
    </div>
  </div>

  <div class="toast" id="toast"></div>

  <!-- 인라인 onclick 이 함수를 전역에서 찾으므로 ES 모듈이 아닌 클래식 스크립트로 둔다 -->
  <script>const BOOK_ME = <?= $meJson ?>;</script>
  <?php foreach (['core', 'list', 'form', 'main'] as $script): ?>
  <script src="<?= book_asset("static/{$script}.js") ?>"></script>
  <?php endforeach; ?>
</body>

</html>
