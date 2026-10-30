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
// AI 분석 기능 사용 여부 — config.php 'envlog_ai_enabled' (기본 true). false 면 AI 상자·사용 금액 표시를 아예 그리지 않고 api.php 도 거절한다
$__envlogAi = (bool)((require __DIR__ . '/../config.php')['envlog_ai_enabled'] ?? true);
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
    <?php if ($__envlogAi): ?><span id="aiBudget" class="budget" title="이번 달 AI 분석(Claude) 사용 금액 / 월 상한"></span><?php endif; ?>
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
    <button id="btnDisconnect" disabled data-why="접속된 대학 없음" title="접속된 대학 없음">연결 끊기</button>
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
          <label class="chk" title="2초마다 파일 끝을 확인해 새로 붙은 줄을 아래에 덧붙입니다 (tail -f). 화면이 맨 아래일 때만 따라 내려갑니다"><input type="checkbox" id="chkTail"> 실시간(tail -f)</label>
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
      <?php if ($__envlogAi): ?>
      <!-- AI 분석: 로그를 서버에서 추려(IP·시간 범위) 사용자가 확인한 뒤 Claude 에 묻는다. 자세한 흐름은 ai.php 머리말 -->
      <div id="aibox">
        <div class="aihead">
          <button id="aiToggle" type="button">🤖 AI 분석 (Claude) ▾</button>
          <span class="spacer"></span><span id="aiKeyState" class="bdg no">키 확인 중</span><span id="aiKeyHint" class="hint"></span><button id="aiKeyBtn" type="button">API 키</button>
          <div class="aidesc hint">시간 범위(최대 2시간)로 php_errors·access_log 를 서버에서 추려 Claude 에게 묻습니다. IP 를 넣으면 그 사용자의 요청만 봅니다. 보내기 전에 자료와 예상 비용을 확인할 수 있습니다.</div>
        </div>
        <div class="aibody">
          <div id="aiKeyRow" class="airow keyrow" hidden>
            <div id="aiKeyNote" class="hint" style="flex-basis:100%"></div>
            <label>개인 키 (선택)</label><input id="aiKeyIn" type="password" placeholder="sk-ant-… (비워 두면 공용 키 사용)" autocomplete="new-password" style="flex:1"><button id="aiKeySave" type="button">저장</button><button id="aiKeyClose" type="button">닫기</button>
          </div>
          <div class="airow">
            <label class="lab">시간 <b style="color:#b91c1c">*</b></label><input id="aiFrom" type="datetime-local" step="60" required><span>~</span><input id="aiTo" type="datetime-local" step="60" required>
            <select id="aiWin" title="수동 지정: 시작·끝을 직접 입력. 그 외: 끝 시각 기준으로 시작 시각을 맞춤"><option value="">수동 지정</option><option value="30">30분</option><option value="60">1시간</option><option value="120">2시간</option><option value="1440">1일 (IP 필요)</option><option value="2880">2일 (IP 필요)</option><option value="4320">3일 (IP 필요)</option></select>
            <span id="aiWinHint" class="hint">IP 없이 보면 최대 2시간, IP 를 넣으면 최대 72시간</span>
          </div>
          <div class="airow" id="aiIpRow">
            <label class="lab">IP (선택)</label>
            <span id="aiIps" class="chips"><span class="hint" id="aiIpsEmpty">없음 — 시간 범위 전체를 봅니다</span></span>
            <button id="aiIpAdd" type="button" title="IP 를 넣습니다 (최대 10개)">＋ IP 추가</button>
            <span id="aiIpEdit" class="ipedit" hidden><input id="aiIpNew" placeholder="예) 203.0.113.10 (여러 개는 쉼표)" size="26" autocomplete="off"><button id="aiIpOk" class="primary" type="button">확인</button><button id="aiIpCancel" type="button">취소</button></span>
            <span class="hint" style="flex-basis:100%">IP 를 넣으면 access_log 에서 그 IP 들의 요청만 보고, php_errors 는 그 IP 의 첫~마지막 요청 사이만 봅니다. 비우면 시간 범위 전체(앞부분 400줄까지)</span>
          </div>
          <div class="airow">
            <label>access_log</label><select id="aiAccess" title="대학마다 이름이 달라 로그가 가장 많이 쌓이는 것을 기본으로 고릅니다"></select>
            <label>php_errors</label><select id="aiPhp"></select>
            <label class="chk" title="정적 파일 요청 제외, UA 요약, 같은 요청 접기 등으로 토큰을 50~70% 줄입니다. 원문이 필요하면 끄세요"><input type="checkbox" id="aiCondense" checked> 간추려 보내기</label>
            <button id="aiCollect" type="button" title="보낼 자료와 예상 비용을 미리 봅니다 (선택)">조회 대상 파일 확인</button>
            <span id="aiRotHint" class="hint" hidden></span>
            <span class="hint" style="flex-basis:100%">서버마다 남기는 access_log·php_errors 파일 이름이 다릅니다. 기본으로 고른 파일이 맞지 않으면 바꿔 주세요. 시간 범위에 맞는 날짜 파일은 자동으로 찾습니다.</span>
          </div>
          <div id="aiParts" class="aiparts"></div>
          <div class="askgrid">
            <textarea id="aiPrompt" rows="2" placeholder="질문 — 예) 이 IP 사용자가 퀴즈 응시 중 겪은 문제와 원인을 찾아줘  (Ctrl+Enter 로 보내기)"></textarea>
            <button id="aiAsk" class="primary" type="button" title="자료를 아직 안 모았으면 자동으로 모은 뒤 Claude 에 보냅니다 (Ctrl+Enter)">질문</button>
            <div class="modelrow"><label>모델</label><select id="aiModel" title="모델"></select></div>
          </div>
          <div id="aiProgress" class="aiprogress" hidden><span class="dot"></span><span id="aiProgressText">처리 중…</span><span class="track"><i></i></span></div>
          <div id="aiMsg" class="hint">시간 범위(와 필요하면 IP)를 넣고 질문을 쓴 뒤 "질문" 을 누르면 자료를 모아 바로 보냅니다. 보내기 전에 어떤 줄이 가는지·비용이 얼마인지 보고 싶으면 "조회 대상 파일 확인" 을 먼저 누르세요.</div>
          <div id="aiAnsTools" class="airow" hidden><button id="aiMore" type="button" class="primary" hidden>이어서 받기</button><button id="aiExpand" type="button">펼치기</button><button id="aiCopy" type="button">복사</button><span class="hint">답은 로그에 근거한 추정입니다. 중요한 판단은 원문 로그로 확인하세요.</span></div>
          <pre id="aiAnswer" hidden></pre>
        </div>
      </div>
      <?php endif; ?>
      <div id="status">준비</div>
    </div>
  </div>
</div>
</div>

<script>window.ENVLOG_AI = <?= $__envlogAi ? 'true' : 'false' ?>;</script>
<script src="static/envlog.js?v=<?= filemtime(__DIR__ . '/static/envlog.js') ?>"></script>
</body>
</html>
