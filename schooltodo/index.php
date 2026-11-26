<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/worksystems.php';

$page = schooltodo_page_begin();
$me = $page['identity'];
$bootJson = json_encode([
    'me' => ['email' => $me['email'], 'name' => $me['name']],
    'statuses' => SCHOOLTODO_STATUSES,
    'status_todo' => SCHOOLTODO_STATUS_TODO,
    'stages_fixed' => SCHOOLTODO_STAGES_DEFAULT,
    'status_excluded' => SCHOOLTODO_STATUS_EXCLUDED,
    'history_max' => SCHOOLTODO_HISTORY_MAX,
    'sorts' => SCHOOLTODO_SORTS,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="ko">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Coursemos TaskSheet</title>
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
        <span class="eyebrow">대학 일괄 할일 · Bulk Tasks</span>
        <h1>Coursemos TaskSheet</h1>
      </div>
      <div class="site-act">
        <button class="btn-ghost" id="csvBtn" onclick="downloadCsv()" title="지금 탭 전체를 CSV 로 내려받습니다">엑셀(CSV) 내려받기</button>
        <button class="btn-ghost archive" id="archiveBtn" onclick="openArchive()" title="보관한 할일 보기">📦 보관함 <span class="n">0</span></button>
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
      <div class="archived-note" id="archivedNote" style="display:none">
        <span class="an-icon">📦</span>
        <span class="an-sub"></span>
        <button type="button" class="btn-mini" onclick="openArchive()">보관함</button>
        <button type="button" class="btn-mini" onclick="setArchived(APP.sheetId, false)">꺼내기</button>
      </div>
      <div class="sheet-info">
        <div class="sheet-desc" id="sheetDesc"></div>
      </div>

      <div class="progress">
        <div class="bar"><i id="progressBar"></i></div>
        <span class="progress-text" id="progressText"></span>
        <span class="sync-box">
          <button type="button" class="sync-ind" id="syncInd" onclick="syncNow(true)" title="누르면 다른 사람이 바꾼 것을 바로 받습니다">
            <span class="ico">↻</span><span class="txt"></span></button>
          <label class="sync-auto" title="켜 두면 30초마다 받아 옵니다 (브라우저에 기억)">
            <input type="checkbox" id="syncAuto" onchange="setSyncAuto(this.checked)"> 자동</label>
        </span>
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
        <div class="toolbar-right">
          <label class="hide-ex" title="해당없음 대학을 표에서 감춥니다. 상태에서 해당없음을 고르면 그때는 보입니다">
            <input type="checkbox" id="hideExcluded" onchange="setHideExcluded(this.checked)"> 해당없음 숨기기</label>
          <button type="button" class="btn-mini bulk" onclick="openVerBulk()" title="무들 버전을 골라 그 버전 대학 전부의 상태를 바꿉니다">🧩 버전별 일괄 변경</button>
        </div>
      </div>

      <div class="grid-wrap">
        <table class="grid">
          <thead>
            <tr>
              <th class="c-no"></th>
              <?php foreach (['name' => '대학', 'ver' => '버전', 'vpn' => 'VPN', 'status' => '상태', 'note' => '메모', 'updated_at' => '갱신'] as $col => $label): ?>
              <th class="c-<?= $col === 'updated_at' ? 'by' : $col ?>" data-col="<?= $col ?>" onclick="sortBy('<?= $col ?>')">
                <span class="th-in"><span><?= $label ?> <span class="arrow"></span></span>
                  <button type="button" class="colbtn" onclick="openColMenu(event, '<?= $col ?>')" aria-label="<?= $label ?> 열 메뉴">▾</button></span>
              </th>
              <?php endforeach; ?>
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
        <div class="field">
          <label>단계 <span class="hint">미조치 → … → 해당없음</span></label>
          <div class="stage-list" id="f-stages"></div>
          <button type="button" class="btn-mini" onclick="addStage()">+ 단계 추가</button>
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

  <div class="overlay" id="archiveOverlay" onclick="if(event.target===this)closeArchive()">
    <div class="sheet">
      <div class="sheet-head">
        <h2>보관함</h2>
        <button class="x" onclick="closeArchive()" aria-label="닫기">×</button>
      </div>
      <div class="arc-list" id="archiveList"></div>
    </div>
  </div>

  <div class="overlay" id="verBulkOverlay" onclick="if(event.target===this)closeVerBulk()">
    <div class="sheet">
      <div class="sheet-head">
        <h2>버전별 일괄 변경</h2>
        <button class="x" onclick="closeVerBulk()" aria-label="닫기">×</button>
      </div>
      <div class="sheet-body">
        <div class="field">
          <label>무들 버전 (고른 버전의 대학 전부 — 거르기·숨김과 무관)</label>
          <div class="vb-list" id="vbList"></div>
        </div>
        <div class="field">
          <label for="vbStatus">바꿀 상태</label>
          <select id="vbStatus"></select>
        </div>
      </div>
      <div class="sheet-foot">
        <span class="vb-count" id="vbCount"></span>
        <button class="btn-ghost" onclick="closeVerBulk()">취소</button>
        <button class="btn-submit" id="vbApply" onclick="applyVerBulk()">바꾸기</button>
      </div>
    </div>
  </div>

  <div class="colmenu" id="colMenu"></div>
  <div class="colmenu" id="tabMenu"></div>
  <div class="colmenu histmenu" id="histMenu"></div>

  <div class="toast" id="toast"></div>

  <div class="celebrate" id="celebrate" onclick="closeCelebrate()">
    <div class="celebrate-card">
      <div class="celebrate-emoji">🎉</div>
      <h3>와! 시트를 전부 채웠어요!</h3>
      <p id="celebrateSheet"></p>
      <span class="celebrate-hint">아무 데나 누르면 닫혀요</span>
    </div>
  </div>

  <script>const SCHOOLTODO_BOOT = <?= $bootJson ?>;</script>
  <?php foreach (['core', 'sheets', 'grid', 'celebrate', 'main', 'sync'] as $script): ?>
  <script src="<?= schooltodo_asset("static/{$script}.js") ?>"></script>
  <?php endforeach; ?>
</body>

</html>
