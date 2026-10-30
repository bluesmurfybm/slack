<?php
/**
 * Coursemos EnvLog (envlog 모듈) — 대학 운영 웹서버 아파치 로그를 여러 대 나란히 보는 화면.
 *  - 대학 목록·테스트서버 접속 정보는 EnvHub(access)의 schools + school_access(test_srv) 에서 읽는다.
 *  - 실제 SSH·로그 읽기는 상주 데몬(daemon/envlogd.py)이 하고, api.php 가 포털 로그인을 확인한 뒤 넘긴다.
 *  - 화면 동작은 envlog/README.md 참고.
 */
require_once __DIR__ . '/guard.php';
envlog_require_login();
envlog_session_release();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// 공통 상단바(slack/header.php)는 "포털 루트까지 되짚는 접두사"를 $__bwBase 로 받는다.
$__bwBase = '../slack/';
$__bwCurrent = 'envlog';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Coursemos EnvLog</title>
<link rel="icon" type="image/svg+xml" href="../styles/favicons/envlog.svg">
<?php require_once __DIR__ . '/../core/pwa.php'; echo pwa_head_tags('../'); ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
<link rel="stylesheet" href="../slack/styles/header.css?v=<?= filemtime(__DIR__ . '/../slack/styles/header.css') ?>">
<link rel="stylesheet" href="../slack/styles/common.css?v=<?= filemtime(__DIR__ . '/../slack/styles/common.css') ?>">
<link rel="stylesheet" href="styles/envlog.css?v=<?= filemtime(__DIR__ . '/styles/envlog.css') ?>">
</head>
<body>
<?php include __DIR__ . '/../slack/header.php'; ?>

<div class="wrap">
  <div class="head">
    <h1>📜 Coursemos EnvLog</h1>
    <span class="hint" style="color:var(--hint);font-size:12.5px">EnvHub 의 테스트서버를 거쳐 운영 웹서버 여러 대의 아파치 로그를 같은 시각으로 나란히 봅니다.</span>
  </div>

<div id="lv">
  <div class="bar">
    <div class="ac">
      <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <!-- name 을 검색용으로 두고 자동완성을 끈다. 아래 "직접 입력" 의 비밀번호 칸 때문에 브라우저가 이 칸을
           로그인 아이디로 오해해 저장된 계정을 채우는 일이 있어, 비밀번호 칸은 new-password 로 표시한다 -->
      <input id="q" name="univ-search" placeholder="대학 검색 — 이름 · 사이트 · IP" autocomplete="off" spellcheck="false">
      <span id="qVer" class="bdg ver" hidden></span>
      <div class="dd" id="dd"></div>
    </div>
    <button id="btnConnect" class="primary">접속</button>
    <button id="btnDisconnect" disabled>연결 끊기</button>
    <label class="chk"><input type="checkbox" id="chkManual"> 직접 입력</label>
    <div id="manual">
      <input id="mHost" name="ssh-host" placeholder="IP:포트" size="18" autocomplete="off"><input id="mUser" name="ssh-user" placeholder="아이디" value="ubion" size="8" autocomplete="off"><input id="mPw" name="ssh-pass" type="password" placeholder="비밀번호" size="12" autocomplete="new-password">
    </div>
    <span id="connInfo" class="hint"></span>
  </div>

  <div id="main">
    <aside>
      <section>
        <h2>서버</h2>
        <div id="servers"><div class="hint">접속하면 테스트서버 checkWeb.sh 에서 운영서버 목록을 읽습니다.</div></div>
        <div style="margin-top:6px; display:flex; gap:10px; flex-wrap:wrap">
          <label class="chk"><input type="checkbox" id="chkSync" checked> 동일 시간 스크롤</label>
          <label class="chk"><input type="checkbox" id="chkMerge"> 병합 모드</label>
        </div>
      </section>
      <section>
        <h2>로그 파일</h2>
        <div style="display:flex; gap:4px"><input id="logDir" value="/usr/local/apache2/logs" style="flex:1"><button id="btnFiles" title="목록 새로고침">↻</button></div>
        <div id="logdirs" class="hint" style="margin-top:4px"></div>
        <!-- 기본값 php_errors.log: 가장 자주 보는 파일. 지우면 전체 목록 -->
        <div style="display:flex; gap:4px; margin-top:6px"><input id="fileFilter" value="php_errors.log" placeholder="파일명 검색 (예: 2026-10, error)" autocomplete="off" style="flex:1"><button id="fileFilterClear" title="검색어 지우고 전체 보기">✕</button></div>
        <div class="list" id="files" style="margin-top:6px"><div class="hint" style="padding:6px">서버에 접속하면 목록이 표시됩니다.</div></div>
        <div class="hint" style="margin-top:4px">체크된 모든 서버의 목록을 합쳐 보여줍니다. 오른쪽 S번호 = 그 파일이 있는 서버. 선택한 파일은 같은 경로로 엽니다. 로그 폴더 밖은 볼 수 없습니다.</div>
      </section>
    </aside>

    <div id="content">
      <!-- 로그 안 검색 — 로그 바로 위에 둔다(브라우저의 찾기 막대처럼). 결과 줄은 패널에, 결과 목록은 왼쪽 "검색 결과" 에 -->
      <div id="sbar">
        <select id="sType" title="검색 종류"><option value="ip">IP</option><option value="time">시간</option><option value="text">텍스트</option><option value="regex">정규식</option></select>
        <input id="sQ" placeholder="예) 10.1.2.3">
        <button id="btnSearch" class="primary">검색</button>
        <button id="btnNext" title="현재 보고 있는 위치 이후에서 검색">현재 위치 이후</button>
        <label class="chk"><input type="checkbox" id="chkOnlyHits" checked> 검색된 줄만 보기</label>
        <button id="sInfo" class="cnt" hidden title="검색 결과 목록 펼치기/접기"></button>
        <span id="sHint" class="hint">IP: 단어 단위 정확 일치 (10.1.2.3 은 10.1.2.33 과 구분) · 결과 줄 더블클릭 → 원문 위치</span>
      </div>
      <!-- 검색 결과 목록(전 서버 시간순). 건수 단추로 펼치고 접는다. 클릭 → 원문 위치 -->
      <div id="resultsBox" hidden><div class="list results" id="results"></div></div>
      <div id="panes"><div class="empty">대학을 검색해 접속한 뒤, 왼쪽에서 로그 파일을 선택하세요.</div></div>
      <div id="detail"></div>
      <div id="status">준비</div>
    </div>
  </div>
</div>
</div>

<script src="static/envlog.js?v=<?= filemtime(__DIR__ . '/static/envlog.js') ?>"></script>
</body>
</html>
