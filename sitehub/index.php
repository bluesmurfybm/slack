<?php
/**
 * BlueLab SiteHub — 유지보수 사이트 정보. 화면 한 장: 요약 · 툴바 · 목록(리스트/카드) · 상세 드로어 · 시트.
 *  - 사이트 기준: 목록 1행 = 사이트 1개, 접속정보·담당자·계약·유지보수 방법·Slack 이력은 그 아래.
 *  - 동작은 access 모듈(가드·API·암호화·칸 하나 저장), 스타일은 dti 모듈(style.css·드로어·시트)을 따른다.
 */
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/db.php';
$me = sitehub_require_login();
sitehub_session_release();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$canSync = sitehub_can_sync($me);
include __DIR__ . '/views/head.php';
?>
  <main class="wrap">
    <div class="stats sh-stats" id="stats"></div>
    <div class="sh-expiry-note" id="expiryNote" hidden></div>
    <div class="sh-archive-note" id="archiveNote" hidden>
      <b>보관함</b> — 미사용·종료된 사이트입니다. 접속정보·담당자·계약·이력은 그대로 남아 있고, 행의 <b>복원</b>을 누르면 다시 사용 중 목록으로 돌아갑니다.
    </div>

    <div class="toolbar">
      <div class="search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
        <input id="q" type="text" autocomplete="off"
          placeholder="사이트명 · 고객사 · 주소 · 호스팅 · 담당자를 입력하세요">
        <button type="button" class="sh-qclear" id="qClear" title="검색어 지우기" aria-label="검색어 지우기" hidden>×</button>
      </div>
      <div class="filters">
        <select id="fHosting" title="호스팅으로 거르기"></select>
        <select id="fStack" title="스택으로 거르기"></select>
      </div>
      <div class="viewtoggle sh-viewseg" id="viewseg" role="tablist">
        <button type="button" data-view="active" onclick="setView('active')" title="사용 중인 사이트">사용 중 <b id="cntActive"></b></button>
        <button type="button" data-view="archive" onclick="setView('archive')" title="보관함 — 미사용·종료된 사이트">보관함 <b id="cntArchive"></b></button>
      </div>
      <button class="btn-ghost" type="button" id="btnImport" onclick="openImport()" title="호스팅 정보 엑셀(시트별 사이트)을 읽어 넣습니다">엑셀 가져오기</button>
      <button class="btn-ghost" type="button" id="btnSync" onclick="runSync()" hidden>이력 동기화</button>
      <button class="btn-mini" type="button" id="btnUnmatched" onclick="openUnmatched()" hidden></button>
      <div class="viewtoggle" id="viewtoggle">
        <button type="button" data-layout="list" title="리스트 보기" aria-label="리스트 보기" onclick="setLayout('list')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M4 6h16M4 12h16M4 18h16" /></svg></button>
        <button type="button" data-layout="card" title="카드 보기" aria-label="카드 보기" onclick="setLayout('card')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3.5" y="3.5" width="7" height="7" rx="1.5" /><rect x="13.5" y="3.5" width="7" height="7" rx="1.5" />
            <rect x="3.5" y="13.5" width="7" height="7" rx="1.5" /><rect x="13.5" y="13.5" width="7" height="7" rx="1.5" /></svg></button>
      </div>
    </div>

    <div class="ledger-head">
      <span class="title">사이트</span>
      <span class="count" id="count"></span>
    </div>
    <div id="list"><ul class="list"><li class="empty">불러오는 중…</li></ul></div>
  </main>
<?php $scripts = ['core', 'list', 'drawer', 'form', 'procs', 'sync', 'monitor']; include __DIR__ . '/views/foot.php'; ?>
