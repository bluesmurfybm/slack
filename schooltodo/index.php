<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/worksystems.php';

$page = schooltodo_page_begin();
$me = $page['identity'];
$bootJson = json_encode([
    'me' => ['email' => $me['email'], 'name' => $me['name']],
    'statuses' => SCHOOLTODO_STATUSES,
    'status_todo' => SCHOOLTODO_STATUS_TODO,
    'status_done' => SCHOOLTODO_STATUS_DONE,
    'status_excluded' => SCHOOLTODO_STATUS_EXCLUDED,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="ko">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>대학 일괄 할일 엑셀</title>
  <link rel="icon" type="image/svg+xml" href="../styles/favicons/schooltodo.svg">
  <?php require_once __DIR__ . '/../core/pwa.php'; echo pwa_head_tags('../'); ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet"
    href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
  <link rel="stylesheet" href="../styles/topbar.css">
  <link rel="stylesheet" href="<?= schooltodo_asset('styles/style.css') ?>">
</head>

<body>
  <div class="topbar">
    <div class="topbar-in">
      <a class="logo" href="<?= schooltodo_h($page['portal']) ?>/" style="text-decoration:none"><b>blue</b><span
          class="dash">-</span>iWorks</a>
      <div class="top-right">
        <div class="user-menu" id="hdrUserMenu">
          <div class="user-chip" onclick="toggleUserMenu(event)" title="메뉴">
            <span class="avatar" style="background:<?= schooltodo_h($me['color'] ?: '#606D79') ?>"><?= schooltodo_h(mb_substr($me['name'], 0, 1)) ?></span>
            <span class="nm"><?= schooltodo_h($me['name']) ?></span>
            <span class="user-caret">▾</span>
          </div>
          <div class="dd-menu" id="hdrUserDd">
            <a href="<?= schooltodo_h($page['portal']) ?>/?view=profile">👤 마이페이지</a>
            <div class="dd-sep"></div>
            <?= work_systems_menu('../', 'schooltodo') ?>
            <div class="dd-sep"></div>
            <a href="<?= schooltodo_h($page['portal']) ?>/api/logout.php">🚪 로그아웃</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <header class="site">
    <div class="site-inner">
      <div class="brand">
        <span class="eyebrow">대학마다 하나씩 해야 하는 일 · Bulk To-do</span>
        <h1>대학 일괄 할일 엑셀</h1>
      </div>
      <div class="site-act">
        <button class="btn-ghost" id="csvBtn" onclick="downloadCsv()">엑셀(CSV) 내려받기</button>
        <button class="btn-new" onclick="openSheetForm()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 5v14M5 12h14" />
          </svg>
          새 할일
        </button>
      </div>
    </div>
  </header>

  <div class="wrap">
    <div class="tabs" id="tabs"></div>

    <div class="empty-all" id="emptyAll">
      <div class="big">아직 할일이 없어요</div>
      <div>오른쪽 위 <b>새 할일</b> 단추로 탭을 만들면 대학 목록이 채워집니다.</div>
    </div>

    <div id="sheetArea">
      <div class="sheet-info">
        <div class="sheet-desc" id="sheetDesc"></div>
        <div class="sheet-act">
          <button class="btn-mini" onclick="openSheetForm(APP.sheetId)">이름·설명 수정</button>
          <button class="btn-mini danger" onclick="askDeleteSheet()">삭제</button>
        </div>
      </div>

      <div class="progress">
        <div class="bar"><i id="progressBar"></i></div>
        <span class="progress-text" id="progressText"></span>
      </div>

      <div class="toolbar">
        <div class="search">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="7" />
            <path d="M21 21l-4.3-4.3" />
          </svg>
          <input id="q" type="text" placeholder="대학 이름·메모 검색" oninput="renderGrid()" autocomplete="off">
        </div>
        <div class="chips" id="statusFilter"></div>
      </div>

      <div class="grid-wrap">
        <table class="grid">
          <thead>
            <tr>
              <th class="c-no"></th>
              <th class="c-name">대학</th>
              <th class="c-ver">버전</th>
              <th class="c-status">상태</th>
              <th class="c-note">메모</th>
              <th class="c-by">갱신</th>
            </tr>
          </thead>
          <tbody id="gridBody"></tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="overlay" id="sheetOverlay" onclick="if(event.target===this)closeSheetForm()">
    <div class="sheet">
      <div class="sheet-head">
        <h2 id="sheetFormTitle">새 할일</h2>
        <button class="x" onclick="closeSheetForm()" aria-label="닫기">×</button>
      </div>
      <div class="sheet-body">
        <div class="field">
          <label for="f-title">이름</label>
          <input id="f-title" type="text" maxlength="200" placeholder="예: jQuery 3.5.1 업그레이드" autocomplete="off">
        </div>
        <div class="field">
          <label for="f-desc">설명</label>
          <textarea id="f-desc" maxlength="2000" placeholder="무엇을 해야 끝인지, 참고 링크 등"></textarea>
        </div>
      </div>
      <div class="sheet-foot">
        <button class="btn-ghost" onclick="closeSheetForm()">취소</button>
        <button class="btn-submit" onclick="submitSheetForm()">저장</button>
      </div>
    </div>
  </div>

  <div class="overlay" id="confirmOverlay" onclick="if(event.target===this)closeConfirm()">
    <div class="cdialog">
      <h3>할일 삭제</h3>
      <p id="confirmText"></p>
      <div class="row-btn">
        <button class="btn-ghost" onclick="closeConfirm()">취소</button>
        <button class="btn-danger" onclick="deleteSheet()">삭제</button>
      </div>
    </div>
  </div>

  <div class="toast" id="toast"></div>

  <script>const SCHOOLTODO_BOOT = <?= $bootJson ?>;</script>
  <?php foreach (['core', 'sheets', 'grid', 'main'] as $script): ?>
  <script src="<?= schooltodo_asset("static/{$script}.js") ?>"></script>
  <?php endforeach; ?>
</body>

</html>
