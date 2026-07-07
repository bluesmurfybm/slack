<?php
require_once __DIR__ . '/auth.php';
require_login();
$me = current_user();
session_release();   // 세션 잠금 즉시 해제(서브 AJAX 동시요청 경합 방지)
$cfg = require __DIR__ . '/config.php';
$listUrl = $cfg['list_url'] ?? '';

// 캐시 방지: 코드 수정(색상 등)이 새로고침 시 바로 반영되도록
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
// 동기화는 화면 JS가 fetch('sync.php')로 백그라운드 호출 → 콘솔창 안 뜸
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>유지보수 요청</title>
<style>
  :root {
    --bg:#fff; --bg2:#f6f7f8; --line:#e3e5e8; --txt:#1f2328; --muted:#6e7781; --hint:#8b949e; --info:#0c447c; --info-bg:#e6f1fb;
  }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#1a1d21; --bg2:#222529; --line:#383a3f; --txt:#e8e8e8; --muted:#9aa0a6; --hint:#6b7177; --info:#85b7eb; --info-bg:#0c2740; }
  }
  * { box-sizing:border-box; }
  body { font-family:-apple-system,"Malgun Gothic","Apple SD Gothic Neo",sans-serif; background:var(--bg2); color:var(--txt); margin:0; padding:24px; }
  .wrap { width:100%; max-width:1600px; margin:0 auto; }   /* 창 크기에 맞춰 가변(최대 1600px) */
  .head { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; flex-wrap:wrap; gap:8px; }
  .head h1 { font-size:18px; font-weight:600; margin:0; display:flex; align-items:center; gap:10px; }
  .filters { display:flex; gap:8px; align-items:center; }   /* 제목 옆 오른쪽 정렬(부모 space-between) */
  /* 다중선택 필터 드롭다운 */
  .ms { position:relative; display:inline-block; }
  .ms-btn { height:32px; padding:0 10px; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--txt); font-size:13px; cursor:pointer; white-space:nowrap; }
  .ms-btn.active { border-color:var(--info); color:var(--info); background:var(--info-bg); }
  .ms-n { display:inline-block; min-width:16px; text-align:center; background:var(--info); color:#fff; border-radius:8px; font-size:11px; padding:0 5px; margin-left:2px; }
  .ms-ar { color:var(--muted); font-size:10px; }
  .ms-menu { position:absolute; z-index:50; top:36px; right:0; left:auto; min-width:170px; max-height:320px; overflow-y:auto;
             background:var(--bg); border:1px solid var(--line); border-radius:8px; padding:6px; box-shadow:0 6px 22px rgba(0,0,0,.18); }
  .ms-menu[hidden] { display:none; }
  .ms-item { display:flex; align-items:center; gap:8px; padding:5px 7px; border-radius:6px; font-size:13px; cursor:pointer; white-space:nowrap; }
  .ms-item:hover { background:var(--bg2); }
  .ms-item input { width:15px; height:15px; margin:0; cursor:pointer; }
  .ms-empty { font-size:12px; color:var(--muted); padding:6px 7px; }
  .ms-sec { font-size:11px; font-weight:700; color:var(--info); padding:6px 7px 2px; border-top:1px solid var(--line); margin-top:2px; }
  .ms-sec:first-child { border-top:0; margin-top:0; }
  .toolbar { display:flex; gap:8px; align-items:center; justify-content:flex-end; margin-bottom:14px; flex-wrap:wrap; }
  .badge { font-size:12px; color:var(--info); background:var(--info-bg); padding:2px 10px; border-radius:8px; }
  .who { font-size:12px; color:var(--muted); }
  /* 검색창 + 지우기 버튼 */
  .search-wrap { position:relative; display:inline-flex; align-items:center; }
  .search-wrap input { width:140px; padding-right:26px; }
  .search-x { position:absolute; right:5px; top:50%; transform:translateY(-50%); width:16px; height:16px; padding:0;
              border:none; border-radius:50%; background:var(--line); color:var(--bg); font-size:10px; line-height:1;
              display:flex; align-items:center; justify-content:center; cursor:pointer; }
  .search-x:hover { background:var(--muted); }
  .search-x[hidden] { display:none; }
  input,button,select,textarea { font-family:inherit; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--txt); font-size:13px; }
  input,button,select { height:32px; padding:0 10px; }
  button { cursor:pointer; }
  button.primary { background:var(--info); color:#fff; border-color:var(--info); }
  /* 아이콘 헤더 버튼 + 툴팁 + 분석 드롭다운 */
  .iconbar button, .iconbar .ddBtn { height:32px; min-width:34px; padding:0 8px; display:inline-flex; align-items:center; justify-content:center; gap:3px; font-size:15px; line-height:1; }
  .iconbar .txtbtn { min-width:auto; padding:0 12px; font-size:13px; }   /* 글자 버튼(미지정/보관) */
  .iconbar .cnt { font-size:10px; font-weight:700; color:var(--info); }
  .iconbar .primary .cnt { color:#fff; }   /* 활성(파란 배경)일 때 숫자 흰색 */
  .iconbar #sync .ico { display:inline-block; }
  .iconbar #sync.syncing .ico { animation:spin .9s linear infinite; }
  @keyframes spin { to { transform:rotate(360deg); } }
  .tip { position:relative; }
  .tip::after { content:attr(data-tip); position:absolute; top:calc(100% + 7px); left:50%; transform:translateX(-50%);
    background:var(--txt); color:var(--bg); font-size:11px; font-weight:500; white-space:nowrap; padding:4px 8px;
    border-radius:6px; opacity:0; pointer-events:none; transition:opacity .12s; z-index:80; }
  .tip:hover::after { opacity:.95; }
  .dropdown { position:relative; }
  .ddBtn .caret { font-size:10px; color:var(--muted); }
  .ddmenu { position:absolute; top:calc(100% + 6px); right:0; min-width:152px; background:var(--bg); border:1px solid var(--line);
    border-radius:10px; padding:5px; box-shadow:0 6px 22px rgba(0,0,0,.18); display:none; z-index:80; }
  .dropdown.open .ddmenu { display:block; }
  .ddmenu a { display:flex; align-items:center; gap:8px; padding:8px 12px; border-radius:7px; text-decoration:none; color:var(--txt); font-size:13px; white-space:nowrap; }
  .ddmenu a:hover { background:var(--bg2); }
  .listhead { display:flex; align-items:center; gap:10px; padding:9px 16px; border:1px solid var(--line); border-bottom:0; border-radius:12px 12px 0 0; background:var(--bg2); font-size:12px; color:var(--muted); }
  #selInfo { user-select:none; }
  /* 선택 액션(항목 체크 시에만 표시) */
  .sel-actions { display:inline-flex; align-items:center; gap:6px; margin-left:6px; padding-left:10px; border-left:1px solid var(--line); }
  .sel-actions[hidden] { display:none; }
  .sel-actions button { height:24px; padding:0 10px; font-size:12px; }
  .listbox { border:1px solid var(--line); border-radius:0 0 12px 12px; overflow:hidden; background:var(--bg); }
  .cols { display:flex; gap:16px; align-items:flex-start; }
  .col { flex:1; min-width:0; container-type:inline-size; }   /* 상세가 컬럼 폭 기준으로 반응하도록 */
  .row { display:flex; align-items:center; gap:12px; padding:11px 16px; cursor:pointer; border-bottom:1px solid var(--line); }
  .row:hover { background:var(--bg2); }
  /* 리스트(board) 구분 원형 뱃지 (요청자 이름 오른쪽, 전체 탭에서만) */
  .bdot { flex:none; display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px;
          border-radius:50%; font-size:12px; font-weight:700; }
  .bdot.b { background:#6aa8ec; color:#fff; }   /* 블루소프트 = B (연한 파랑) */
  .bdot.w { background:#5cc08a; color:#fff; }   /* 와이오즈 = W (연한 초록) */
  /* 보관 페이지네이션 바 */
  .arch-bar { position:sticky; top:0; z-index:40; display:flex; align-items:center; gap:12px; flex-wrap:wrap; padding:8px 14px; margin-bottom:8px;
              background:var(--bg); border:1px solid var(--line); border-radius:10px; font-size:13px; color:var(--muted);
              box-shadow:0 2px 8px rgba(0,0,0,.12); }
  .arch-bar .arch-total b { color:var(--txt); }
  .arch-bar select { height:28px; }
  .arch-bar .arch-pg { margin-left:auto; display:flex; align-items:center; gap:8px; }
  .arch-bar .arch-pg button { height:28px; padding:0 12px; }
  .arch-bar .arch-cur { min-width:64px; text-align:center; }
  /* 상단 리스트 탭 */
  .btabs { display:flex; gap:8px; margin:0 auto 0 0; flex-wrap:wrap; }   /* 좌측 정렬(우측은 기존 필터) */
  .btab { height:34px; padding:0 16px; border:1px solid var(--line); border-radius:18px; background:var(--bg); color:var(--muted); font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
  .btab.on { border-color:var(--info); color:var(--info); background:var(--info-bg); font-weight:600; }
  .btab-n { font-size:11px; background:var(--info); color:#fff; border-radius:9px; padding:0 6px; }
  /* 커스텀 체크박스 (둥근 초록 체크) */
  .chk { flex:none; appearance:none; -webkit-appearance:none; width:18px; height:18px; margin:0; padding:0;
         border:1.5px solid var(--line); border-radius:5px; background:var(--bg); cursor:pointer; position:relative; transition:all .12s; }
  .chk:hover { border-color:#16a34a; }
  .chk:checked { background:#16a34a; border-color:#16a34a; }
  .chk:checked::after { content:""; position:absolute; left:5px; top:2px; width:4px; height:8px;
         border:solid #fff; border-width:0 2px 2px 0; transform:rotate(45deg); }
  /* 고정(핀) */
  .pinBtn { flex:none; cursor:pointer; font-size:14px; line-height:1; opacity:.22; filter:grayscale(1); user-select:none; }
  .pinBtn:hover { opacity:.7; }
  .pinBtn.on { opacity:1; filter:none; }
  .row.pinned, .row.pinned.unread { box-shadow: inset 3px 0 0 #f5a623; }   /* 좌측 금색 띠로 고정 표시(안읽음보다 우선) */
  /* 안읽음: 제목 굵게 / 읽음: 보통+흐리게 */
  .row.unread .title { font-weight:700; }
  .row.unread .names { font-weight:700; color:var(--txt); }
  .row.unread { box-shadow: inset 3px 0 0 #50b86fc4; }   /* 안읽음: 좌측 파란 띠로 구분 */
  .row.read .title { font-weight:400; color:var(--muted); }
  .row.read { background:var(--bg2); }
  /* 숨김(숨김 보기 모드에서만 노출) */
  .row.hid { opacity:.5; }
  .row.hid .title { text-decoration:line-through; }
  .hideBtn { flex:none; cursor:pointer; font-size:13px; line-height:1; opacity:.3; user-select:none; }
  .hideBtn:hover { opacity:.8; }
  .names { flex:none; width:96px; font-size:11px; font-weight:500; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .title { flex:1; min-width:0; font-size:13px; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .snipline { display:flex; align-items:center; gap:6px; min-width:0; margin-top:2px; }
  .snip { flex:1; min-width:0; font-size:12px; color:var(--hint); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .st { font-size:11px; padding:2px 9px; border-radius:8px; white-space:nowrap; }
  .lock { font-size:10px; color:var(--muted); }
  .cmtcnt { flex:none; font-size:11px; color:var(--muted); white-space:nowrap; }
  .date { font-size:12px; color:var(--hint); flex:none; white-space:nowrap; }
  .detail { padding:16px 20px 20px; border-bottom:1px solid var(--line); background:var(--bg2); }
  .detail.with-cmts { display:flex; gap:20px; align-items:flex-start; }
  .detail-main { flex:1; min-width:0; }
  .detail-cmts { flex:none; width:380px; max-width:90%; min-width:280px; display:flex; flex-direction:column;
                 resize:horizontal; overflow:auto;
                 background:var(--bg); border:1px solid var(--line); border-radius:10px; padding:12px 14px; }
  /* 컬럼(리스트) 폭이 좁으면(예: 미지정+메인 분할 시) 상세를 세로 스택 → 찌그러짐 방지 */
  @container (max-width:900px){ .detail.with-cmts{ flex-direction:column; } .detail-cmts{ width:100%; max-width:100%; } }
  .cmtToggle.on { background:var(--info-bg); color:var(--info); border-color:var(--info); }
  /* 메타 칩 */
  .meta { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 14px; }
  .mi { display:flex; align-items:center; gap:6px; font-size:12px; background:var(--bg); border:1px solid var(--line); border-radius:8px; padding:4px 10px; }
  .ml { color:var(--hint); }
  .mv { color:var(--txt); font-weight:500; }
  .mi select { height:24px; padding:0 22px 0 6px; font-size:12px; border-radius:6px; }
  .mi input[type=date] { height:24px; padding:0 6px; font-size:12px; border-radius:6px; }
  /* 본문 카드 */
  .body-card { font-size:13px; line-height:1.75; white-space:normal; word-break:break-word; color:var(--txt);
               background:var(--bg); border:1px solid var(--line); border-radius:10px; padding:14px 16px; }
  /* 서식 렌더 공통 */
  .body-card a, .cmt-b a { color:#1a73e8; font-weight:600; text-decoration:underline; text-underline-offset:2px; }
  .body-card a:hover, .cmt-b a:hover { color:#0b57d0; }
  .body-card code, .cmt-b code { background:var(--bg2); border:1px solid var(--line); border-radius:4px; padding:0 4px; font-family:Consolas,monospace; font-size:12px; }
  .body-card pre, .cmt-b pre { background:var(--bg2); border:1px solid var(--line); border-radius:6px; padding:8px 10px; margin:6px 0; overflow:auto; font-family:Consolas,monospace; font-size:12px; white-space:pre-wrap; }
  .body-card blockquote, .cmt-b blockquote { margin:4px 0; padding:1px 0 1px 12px; border-left:4px solid var(--hint); color:var(--txt); }
  .links { display:flex; gap:8px; margin-top:14px; flex-wrap:wrap; align-items:center; }
  .links a { font-size:12px; text-decoration:none; color:var(--info); background:var(--bg); border:1px solid var(--line); padding:7px 11px; border-radius:8px; }
  .links .slack-link { font-size:12px; color:#fff; background:#4a154b; border:1px solid #4a154b; padding:5px 11px; border-radius:8px; height:auto; }
  #updated { font-size:12px; color:var(--hint); margin:10px 2px 0; }
  .err { color:#e24b4a; padding:16px; }
  /* 댓글 패널 (우측, 고정 높이 + 스크롤) */
  .cmts-title { font-size:12px; font-weight:600; color:var(--muted); margin-bottom:8px; display:flex; align-items:center; gap:6px; flex:none; }
  .cmts-n { background:var(--info-bg); color:var(--info); border-radius:9px; padding:0 7px; font-size:11px; }
  .cmts { display:flex; flex-direction:column; overflow-y:auto; resize:vertical; height:360px; max-height:85vh; min-height:80px; padding-right:4px; }
  /* 각 댓글 = 컴팩트 한 줄형 */
  .cmt { display:flex; gap:8px; padding:6px 0; border-bottom:1px solid var(--line); }
  .cmt:last-child { border-bottom:0; }
  .cmt-av { flex:none; width:20px; height:20px; margin-top:1px; border-radius:50%;
            display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:700; }
  .cmt-bub { flex:1; min-width:0; }
  .cmt-h b { font-weight:600; font-size:12px; }
  .cmt-t { color:var(--hint); margin-left:6px; font-size:10px; }
  .cmt-b { font-size:12.5px; line-height:1.5; white-space:normal; word-break:break-word; color:var(--txt); margin-top:1px; }
  /* 댓글 첨부 */
  .cmt-files { display:flex; flex-wrap:wrap; gap:6px; margin-top:5px; }
  .cmt-img { max-width:180px; max-height:180px; border-radius:6px; border:1px solid var(--line); display:block; object-fit:cover; background:var(--bg2); }
  .cmt-filedl { font-size:12px; color:var(--info); text-decoration:none; border:1px solid var(--line); border-radius:6px; padding:3px 8px; }
  /* 상세 본문 첨부파일 */
  .atts { margin-top:10px; }
  .atts-title { font-size:12px; font-weight:700; color:var(--muted); margin-bottom:6px; }
  .atts-list { display:flex; flex-wrap:wrap; gap:8px; }
  .att-img { max-width:200px; max-height:200px; border-radius:8px; border:1px solid var(--line); display:block; object-fit:cover; background:var(--bg2); cursor:zoom-in; }
  .att-file { display:inline-flex; align-items:center; gap:6px; font-size:12px; color:var(--txt); text-decoration:none;
              border:1px solid var(--line); border-radius:8px; padding:6px 10px; background:var(--bg2); }
  .att-file:hover { border-color:#1a73e8; color:#1a73e8; }
  .att-file .sz { color:var(--hint); font-size:11px; }
  .cmt-img { cursor:zoom-in; }
  /* 동영상 미리보기(포스터 + 재생 오버레이) */
  .media-video { position:relative; display:inline-block; cursor:pointer; border-radius:8px; overflow:hidden;
                 border:1px solid var(--line); background:#000; line-height:0; }
  .media-video img { display:block; max-width:220px; max-height:200px; object-fit:cover; }
  .media-video .media-noposter { display:flex; align-items:center; justify-content:center; width:220px; height:130px; color:#888; font-size:28px; }
  .media-video .media-play { position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
                             font-size:34px; color:#fff; background:rgba(0,0,0,.28); text-shadow:0 1px 6px rgba(0,0,0,.6); }
  .media-video:hover .media-play { background:rgba(0,0,0,.12); }
  .cmt-files .media-video img { max-width:180px; }
  /* pdf/office 미리보기(첫 페이지 이미지 + 확장자 뱃지) */
  .media-doc { position:relative; display:inline-block; border:1px solid var(--line); border-radius:8px; overflow:hidden;
               background:var(--bg2); text-decoration:none; line-height:0; }
  .media-doc img { display:block; max-width:150px; max-height:200px; object-fit:cover; object-position:top; background:#fff; }
  .cmt-files .media-doc img { max-width:120px; max-height:160px; }
  .media-doc:hover { border-color:#1a73e8; }
  .media-doc-badge { position:absolute; left:6px; bottom:6px; font-size:10px; font-weight:700; color:#fff;
                     background:rgba(0,0,0,.62); border-radius:4px; padding:1px 6px; line-height:1.4; }
  .media-doc.lb { cursor:pointer; }
  /* 썸네일 없는 문서 타일(pdf/엑셀) */
  .media-doc--noimg { display:inline-flex !important; align-items:center; gap:8px; padding:10px 12px; line-height:1.3; max-width:250px; }
  .media-doc--noimg .media-doc-ic { font-size:22px; }
  .media-doc--noimg .media-doc-nm { font-size:12px; color:var(--fg,#333); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .media-doc--noimg .media-doc-badge { position:static; background:#1a73e8; }
  /* 이미지 라이트박스(모달 슬라이드) */
  #lb-video { max-width:92vw; max-height:82vh; border-radius:6px; box-shadow:0 10px 44px rgba(0,0,0,.55); background:#000; }
  #lb-frame { width:92vw; height:84vh; border:none; border-radius:6px; background:#fff; box-shadow:0 10px 44px rgba(0,0,0,.55); }
  #lb-sheet { width:92vw; max-width:1200px; height:84vh; overflow:auto; background:#fff; border-radius:6px; box-shadow:0 10px 44px rgba(0,0,0,.55); }
  #lb-sheet .lb-sheet-load { padding:44px; text-align:center; color:#666; }
  #lb-sheet .lb-sheet-tabs { position:sticky; top:0; display:flex; gap:4px; flex-wrap:wrap; padding:8px; background:#f3f3f3; border-bottom:1px solid #ddd; z-index:1; }
  #lb-sheet .lb-sheet-tabs button { border:1px solid #ccc; background:#fff; border-radius:6px; padding:3px 10px; font-size:12px; cursor:pointer; color:#333; }
  #lb-sheet .lb-sheet-tabs button.on { background:#1a73e8; color:#fff; border-color:#1a73e8; }
  #lb-sheet .lb-sheet-body { padding:8px 10px; }
  #lb-sheet table { border-collapse:collapse; font-size:13px; color:#222; }
  #lb-sheet td, #lb-sheet th { border:1px solid #d7d7d7; padding:3px 8px; white-space:nowrap; }
  #lb-sheet tr:first-child td { background:#fafafa; font-weight:600; }
  #lightbox { position:fixed; inset:0; background:rgba(0,0,0,.86); z-index:9999; display:none; align-items:center; justify-content:center; }
  #lightbox.open { display:flex; }
  #lightbox .lb-stage { display:flex; flex-direction:column; align-items:center; gap:12px; }
  #lb-img { max-width:92vw; max-height:82vh; object-fit:contain; border-radius:6px; box-shadow:0 10px 44px rgba(0,0,0,.55); background:#111;
            transform-origin:center center; transition:transform .12s ease; cursor:zoom-in; will-change:transform; }
  #lb-img.zoomed { cursor:grab; }
  #lb-img.dragging { cursor:grabbing; transition:none; }
  /* 줌 컨트롤 */
  #lightbox .lb-zoom { display:flex; align-items:center; gap:6px; }
  #lightbox .lb-zoom button { background:rgba(255,255,255,.14); color:#fff; border:none; width:30px; height:28px;
                              border-radius:7px; font-size:16px; line-height:1; cursor:pointer; }
  #lightbox .lb-zoom button:hover { background:rgba(255,255,255,.28); }
  #lightbox .lb-zoom #lb-zval { min-width:48px; text-align:center; color:#bbb; font-size:12px; }
  #lightbox .lb-bar { display:flex; align-items:center; gap:16px; color:#e8e8e8; font-size:13px; max-width:92vw; }
  #lightbox .lb-bar #lb-name { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  #lightbox .lb-bar a { color:#7db4ff; text-decoration:none; font-weight:600; white-space:nowrap; }
  .lb-btn {z-index: 1; position:absolute; top:50%; transform:translateY(-50%); background:rgba(0,0,0,.14); color:#fff;
            border:none; font-size:36px; line-height:1; width:54px; height:70px; border-radius:10px; cursor:pointer; }
  .lb-btn:hover { background:rgba(0,0,0,.28); }
  #lb-prev { left:22px; } #lb-next { right:22px; }
  #lb-close { position:absolute; top:16px; right:22px; background:none; border:none; color:#fff; font-size:32px; line-height:1; cursor:pointer; }
  #lb-count { min-width:52px; text-align:center; color:#bbb; }
  .cmt-loading,.cmt-empty { font-size:12px; color:var(--hint); padding:8px 0; }
  .cmt-form { flex:none; display:flex; flex-direction:column; gap:5px; margin-top:10px; padding-top:10px; border-top:1px solid var(--line); }
  .cmt-tb { display:flex; gap:3px; }
  .cmt-tb .tb { width:26px; height:24px; padding:0; font-size:11px; color:var(--muted); display:flex; align-items:center; justify-content:center; }
  .cmt-tb .tb:hover { border-color:var(--info); color:var(--info); }
  /* 리치 에디터: 입력하면서 서식이 바로 보이는 단일 입력창 */
  .cmt-input { width:100%; height:72px; overflow-y:auto; resize:vertical; line-height:1.5; padding:6px 9px;
               border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--txt); font-family:inherit; font-size:12.5px;
               text-align:left; word-break:break-word; }
  .cmt-input:focus { outline:none; border-color:var(--info); }
  .cmt-input:empty:before { content:attr(data-ph); color:var(--hint); pointer-events:none; }
  .cmt-input code { background:var(--bg2); border:1px solid var(--line); border-radius:4px; padding:0 4px; font-family:Consolas,monospace; font-size:12px; }
  .cmt-input a { color:var(--info); }
  .cmt-actions { display:flex; justify-content:flex-end; }
  .cmt-send { height:30px; font-size:12px; background:var(--info); color:#fff; border-color:var(--info); }
  /* @멘션 칩 + 자동완성 */
  .mention { background:var(--info-bg); color:var(--info); border-radius:4px; padding:0 3px; font-weight:600; }
  .mention-menu { position:fixed; z-index:10000; background:var(--bg); border:1px solid var(--line); border-radius:8px;
                  box-shadow:0 6px 22px rgba(0,0,0,.18); max-height:220px; overflow-y:auto; min-width:150px; padding:4px; }
  .mention-menu[hidden] { display:none; }
  .mention-menu .mi2 { padding:5px 10px; font-size:13px; border-radius:6px; cursor:pointer; white-space:nowrap; }
  .mention-menu .mi2.on, .mention-menu .mi2:hover { background:var(--info-bg); color:var(--info); }
  /* 학교 사이트 검색 모달 */
  .sm-overlay { position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9998; display:flex; align-items:flex-start; justify-content:center; padding:6vh 16px; }
  .sm-overlay[hidden] { display:none; }
  .sm-box { width:100%; max-width:640px; max-height:82vh; display:flex; flex-direction:column; background:var(--bg); border:1px solid var(--line); border-radius:14px; box-shadow:0 12px 48px rgba(0,0,0,.35); overflow:hidden; }
  .sm-head { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid var(--line); font-size:15px; }
  .sm-count { font-size:12px; color:var(--muted); font-weight:400; margin-left:8px; }
  .sm-manage { font-size:12px; color:var(--info); text-decoration:none; margin-right:12px; }
  .sm-manage:hover { text-decoration:underline; }
  .sm-x { border:none; background:none; color:var(--muted); font-size:18px; cursor:pointer; height:auto; padding:0 4px; }
  .sm-tools { display:flex; gap:10px; align-items:center; padding:12px 18px; border-bottom:1px solid var(--line); flex-wrap:wrap; }
  .sm-tools input { flex:1; min-width:160px; height:34px; }
  .sm-vers { display:flex; gap:6px; }
  .sm-chip { height:30px; padding:0 12px; border:1px solid var(--line); border-radius:16px; background:var(--bg); color:var(--muted); font-size:13px; cursor:pointer; }
  .sm-chip.on { background:var(--info-bg); border-color:var(--info); color:var(--info); font-weight:600; }
  .sm-list { overflow-y:auto; padding:4px 0; }
  .sm-row { display:flex; align-items:center; gap:12px; padding:10px 18px; border-bottom:1px solid var(--line); }
  .sm-row:last-child { border-bottom:0; }
  .sm-name { flex:1; min-width:0; font-size:14px; font-weight:500; word-break:break-word; }
  .sm-ver { font-size:11px; color:var(--info); background:var(--info-bg); border-radius:8px; padding:1px 7px; margin-left:6px; white-space:nowrap; }
  .sm-btns { display:flex; gap:6px; flex:none; }
  .sm-link { font-size:12px; text-decoration:none; padding:6px 14px; border-radius:8px; border:1px solid var(--line); white-space:nowrap; }
  .sm-link.dev { color:#0c447c; background:#e6f1fb; border-color:#bcd8f5; }
  .sm-link.ops { color:#1b5e20; background:#e4f3e7; border-color:#bfe3c6; }
  .sm-link.dev:hover, .sm-link.ops:hover { filter:brightness(.96); }
  .sm-link.off { color:var(--hint); background:var(--bg2); cursor:not-allowed; opacity:.45; }
  .sm-empty { padding:28px; text-align:center; color:var(--muted); font-size:13px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <h1>📥 유지보수 요청 <span class="badge" id="count"></span></h1>
    <div class="filters iconbar">
      <button id="toggleUn" type="button" class="txtbtn">미지정 숨기기</button>
      <button id="toggleArch" type="button" class="txtbtn">🗄 보관 보기</button>
      <button id="toggleHidden" type="button" class="tip" data-tip="숨김 보기"><span class="ico" id="hidIco">🙈</span><span class="cnt" id="hidCnt"></span></button>
      <button id="schoolBtn" type="button" class="tip" data-tip="학교 검색">🏫</button>
      <div class="dropdown" id="analyzeDd">
        <button type="button" class="ddBtn tip" data-tip="분석 도구" id="analyzeBtn">📈<span class="caret">▾</span></button>
        <div class="ddmenu">
          <a href="difficulty.php">⭐ 난이도 분석</a>
          <a href="category.php">📊 카테고리 분석</a>
          <a href="similar.php">🔍 유사 이력</a>
        </div>
      </div>
      <button id="sync" class="tip" data-tip="동기화"><span class="ico">🔄</span></button>
      <a href="logout.php"><button type="button" class="tip" data-tip="로그아웃">⏻</button></a>
    </div>
  </div>
  <div class="toolbar">
    <div class="btabs" id="btabs"></div>
    <span class="who"><?= htmlspecialchars($me['name'], ENT_QUOTES) ?> 님</span>
    <span class="search-wrap"><input id="search" type="text" placeholder="검색…"><button id="searchClear" type="button" class="search-x" hidden title="검색 지우기">✕</button></span>
    <div class="ms" id="msPriority"></div>
    <div class="ms" id="msTeam"></div>
    <div class="ms" id="msStatus"></div>
    <div class="ms" id="msAsg"></div>
    <button id="reset" type="button">필터 초기화</button>
  </div>
  <div class="cols">
    <div class="col" id="unpanel">
      <div class="listhead"><b>미지정 담당자</b> <span id="uncount"></span></div>
      <div id="unlist" class="listbox"></div>
    </div>
    <div class="col">
      <div class="listhead">
        <input type="checkbox" id="selAll" class="chk">
        <span id="selInfo">전체 선택</span>
        <span id="selActions" class="sel-actions" hidden>
          <button id="readSel" type="button" class="primary">읽음</button>
          <button id="unreadSel" type="button">안읽음</button>
          <button id="clearSel" type="button">선택 해제</button>
        </span>
      </div>
      <div id="list" class="listbox">불러오는 중…</div>
    </div>
  </div>
  <div id="updated"></div>
</div>

<!-- 학교 사이트 검색 모달 -->
<div id="schoolModal" class="sm-overlay" hidden>
  <div class="sm-box">
    <div class="sm-head"><span>🏫 학교 사이트 검색 <span class="sm-count" id="smCount"></span></span>
      <span><a href="schools_admin.php" target="_blank" rel="noopener" class="sm-manage">⚙ 관리</a><button id="smClose" class="sm-x" type="button">✕</button></span></div>
    <div class="sm-tools">
      <input id="smSearch" type="text" placeholder="대학명 검색…">
      <div class="sm-vers" id="smVers"></div>
    </div>
    <div class="sm-list" id="smList"></div>
  </div>
</div>

<script>
const LIST_URL = <?= json_encode($listUrl) ?>;
const ME = <?= json_encode($me['name']) ?>;   // 낙관적 댓글 표시용 본인 이름
let SCHOOLS = null;   // schools.php(DB)에서 최초 조회 후 캐시
const PALETTE = {
  "등록":            {bg:"#eef0f2", fg:"#555c66"},
  "공수산정요청":    {bg:"#faeeda", fg:"#633806"},
  "진행중":          {bg:"#9b7d55", fg:"#ffefdb"},
  "확인요청(검토완료)":     {bg:"#e7d2bb", fg:"#683d00"},
  "확인요청(개발서버반영)": {bg:"#e0f2f1", fg:"#00695c"},
  "확인요청(운영서버반영)": {bg:"#e0f2f1", fg:"#00695c"},
  "운영배포요청":    {bg:"#fff0e0", fg:"#a85b00"},
  "완료":            {bg:"#e4f3e7", fg:"#1b5e20"},
  "보류":            {bg:"#f1efe8", fg:"#6b6b6b"},
  "처리불가":        {bg:"#f3e6e6", fg:"#9b2c2c"},
  "시작 전":         {bg:"#eef0f2", fg:"#555c66"},
  "상세설명필요":     {bg:"#e6f1fb", fg:"#0c447c"},
  "재확인필요":       {bg:"#faeeda", fg:"#633806"},
  "작업불가":         {bg:"#f3e6e6", fg:"#9b2c2c"},
  "기타":            {bg:"#f1efe8", fg:"#444441"}
};
const PRIORITY = {
  "긴급": {bg:"#fbe4e4", fg:"#b91c1c"},
  "중요": {bg:"#fff0e0", fg:"#a85b00"},
  "일반": {bg:"#e6f1fb", fg:"#0c447c"}
};
function priColor(s){ return PRIORITY[s] || PRIORITY["일반"]; }
const TEAM = {
  "블루소프트": {bg:"#e6f1fb", fg:"#0c447c"},
  "와이오즈":   {bg:"#e0f2f1", fg:"#00695c"},
  "시스템개발": {bg:"#eeedfe", fg:"#3c3489"},
  "달빛소프트": {bg:"#ede7f6", fg:"#5e35b1"},
  "프레임":     {bg:"#fff0e0", fg:"#a85b00"},
  "미지정":     {bg:"#eceff1", fg:"#455a64"}
};
function teamColor(s){ return TEAM[s] || {bg:"#eceff1", fg:"#455a64"}; }
let DATA = [], openId = null, filter = "";
let fboardTab = "all";   // 상단 리스트 탭 (all/블루소프트/와이오즈)
let archivedMode = false, archPage = 0, archPageSize = 300, archTotal = 0, archLoading = false;   // 보관 보기(페이지네이션)
const BOARDS_F = ["블루소프트","와이오즈"];
const BOARD_LABEL = { "블루소프트":"유비온", "와이오즈":"와이오즈" };   // 화면 표시용(내부값은 유지)
function boardLabel(b){ return BOARD_LABEL[b] || b; }
// 보드별 독립 필터: 블루소프트/와이오즈 값이 달라 각자 체크·적용
function emptyFilt(){ return {"블루소프트":new Set(), "와이오즈":new Set()}; }
let FILT = { priority:emptyFilt(), team:emptyFilt(), status:emptyFilt(), asg:emptyFilt() };
let selected = new Set();                // 체크박스 선택 항목
let splitOn = true;                       // 미지정 리스트 2분할 표시 여부(기본 표시)
let cmtCache = {};                        // 레코드별 댓글 캐시(렌더 깜빡임 방지)
let showCmts = true;                      // 상세에서 댓글 패널(우측) 표시 여부(기본 표시)
let showHidden = false;                   // 숨김 항목 표시 여부(기본: 숨김)

function pal(s){ return PALETTE[s] || PALETTE["기타"]; }
function pad2(n){ return n<10 ? "0"+n : ""+n; }
function fmtDate(ts){   // yyyy.mm.dd
  if(!ts) return "";
  const d=new Date(ts*1000);
  return d.getFullYear()+"."+pad2(d.getMonth()+1)+"."+pad2(d.getDate());
}
function fmtDT(ts){     // yyyy.mm.dd hh:mm
  if(!ts) return "—";
  const d=new Date(ts*1000);
  return d.getFullYear()+"."+pad2(d.getMonth()+1)+"."+pad2(d.getDate())+" "+pad2(d.getHours())+":"+pad2(d.getMinutes());
}
function fmtYmd(s){ return (s||"").replace(/-/g,"."); }   // "2026-06-17" -> "2026.06.17"
function esc(s){ return (s??"").toString().replace(/[&<>]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;"}[c])); }
function escAttr(s){ return esc(s).replace(/"/g,"&quot;"); }
/* Slack 은 텍스트의 & < > 를 &amp; &lt; &gt; 로 인코딩해 보냄. esc() 후 이중 이스케이프된 걸 1단계 복원 */
function unslack(s){ return s.replace(/&amp;(amp|lt|gt|quot|#39|#x27);/g,"&$1;"); }
/* 자주 쓰는 Slack 이모지 :shortcode: → 유니코드 */
const EMOJI = {
  smile:"😄",smiley:"😃",grinning:"😀",grin:"😁",joy:"😂",rofl:"🤣",sweat_smile:"😅",laughing:"😆",satisfied:"😆",
  wink:"😉",blush:"😊",slightly_smiling_face:"🙂",upside_down_face:"🙃",relaxed:"☺️",yum:"😋",sunglasses:"😎",
  heart_eyes:"😍",kissing_heart:"😘",thinking_face:"🤔",hugging_face:"🤗",neutral_face:"😐",expressionless:"😑",
  no_mouth:"😶",smirk:"😏",unamused:"😒",roll_eyes:"🙄",grimacing:"😬",sweat:"😓",pensive:"😔",confused:"😕",
  worried:"😟",confounded:"😖",persevere:"😣",disappointed:"😞",tired_face:"😫",weary:"😩",cry:"😢",sob:"😭",
  angry:"😠",rage:"😡",sleepy:"😪",mask:"😷",dizzy_face:"😵",astonished:"😲",scream:"😱",flushed:"😳",
  fearful:"😨",cold_sweat:"😰",open_mouth:"😮",hushed:"😯",sleeping:"😴",zzz:"💤",exploding_head:"🤯",
  star_struck:"🤩",partying_face:"🥳",pleading_face:"🥺",smiling_face_with_tear:"🥲",
  "+1":"👍",thumbsup:"👍","-1":"👎",thumbsdown:"👎",ok_hand:"👌",punch:"👊",fist:"✊",fist_raised:"✊",
  v:"✌️",wave:"👋",raised_hands:"🙌",pray:"🙏",clap:"👏",muscle:"💪",raised_hand:"✋",
  point_up:"☝️",point_down:"👇",point_left:"👈",point_right:"👉",ok_woman:"🙆",bow:"🙇",see_no_evil:"🙈",
  heart:"❤️",broken_heart:"💔",two_hearts:"💕",sparkling_heart:"💖",blue_heart:"💙",green_heart:"💚",
  yellow_heart:"💛",purple_heart:"💜",heavy_heart_exclamation:"❣️",
  fire:"🔥",star:"⭐",star2:"🌟",sparkles:"✨",zap:"⚡",boom:"💥",collision:"💥",tada:"🎉",confetti_ball:"🎊",
  gift:"🎁",balloon:"🎈","100":"💯",
  white_check_mark:"✅",heavy_check_mark:"✔️",ballot_box_with_check:"☑️",x:"❌",o:"⭕",heavy_multiplication_x:"✖️",
  warning:"⚠️",exclamation:"❗",heavy_exclamation_mark:"❗",question:"❓",grey_question:"❔",bangbang:"‼️",
  bulb:"💡",rocket:"🚀",eyes:"👀",ok:"🆗","new":"🆕",
  hourglass:"⏳",hourglass_flowing_sand:"⏳",alarm_clock:"⏰",clock:"🕐",calendar:"📅",date:"📆",
  memo:"📝",pencil:"✏️",pencil2:"✏️",pushpin:"📌",round_pushpin:"📍",paperclip:"📎",link:"🔗",
  mag:"🔍",lock:"🔒",unlock:"🔓",key:"🔑",bell:"🔔",email:"✉️",envelope:"✉️",phone:"📞",telephone:"☎️",
  computer:"💻",desktop_computer:"🖥️",hammer:"🔨",wrench:"🔧",gear:"⚙️",package:"📦",
  chart_with_upwards_trend:"📈",chart_with_downwards_trend:"📉",bar_chart:"📊",clipboard:"📋",
  coffee:"☕",beer:"🍺",beers:"🍻",cake:"🍰",pizza:"🍕",rice:"🍚",
  sob_:"😭",sweat_drops:"💦",dash:"💨",poop:"💩",hankey:"💩",check:"✔️",
  smiling_imp:"😈",ghost:"👻",skull:"💀",alien:"👽",robot_face:"🤖",
  raising_hand:"🙋",man_bowing:"🙇‍♂️",woman_bowing:"🙇‍♀️",speech_balloon:"💬",thought_balloon:"💭"
};
/* Slack mrkdwn → HTML (굵게/기울임/취소선/코드/코드블록/링크/줄바꿈) */
function mrkdwn(t){
  if(!t) return '';
  var ph=[]; var stash=function(h){ ph.push(h); return ''+(ph.length-1)+''; };
  t = t.replace(/```([\s\S]*?)```/g,function(m,c){ return stash('<pre>'+unslack(esc(c.replace(/^\n|\n$/g,'')))+'</pre>'); });
  t = t.replace(/`([^`\n]+)`/g,function(m,c){ return stash('<code>'+unslack(esc(c))+'</code>'); });
  t = t.replace(/<(https?:\/\/[^|>]+)\|([^>]+)>/g,function(m,u,l){ return stash('<a href="'+unslack(escAttr(u))+'" target="_blank" rel="noopener">'+unslack(esc(l))+'</a>'); });
  t = t.replace(/<(https?:\/\/[^>]+)>/g,function(m,u){ return stash('<a href="'+unslack(escAttr(u))+'" target="_blank" rel="noopener">'+unslack(esc(u))+'</a>'); });
  // 마크업 없이 그대로 붙여넣은 URL 도 클릭 가능하게 (끝 문장부호는 링크에서 제외)
  t = t.replace(/https?:\/\/[^\s<>]+/g,function(u){
    // 끝의 문장부호 + 마크다운 마커(* _ ~ `)는 링크에서 제외(볼드/기울임 닫기와 충돌 방지)
    var tail=''; var mt=u.match(/[*_~`)\]}.,;:!?]+$/); if(mt){ tail=mt[0]; u=u.slice(0,-tail.length); }
    return stash('<a href="'+unslack(escAttr(u))+'" target="_blank" rel="noopener">'+unslack(esc(u))+'</a>')+tail;
  });
  t = unslack(esc(t));
  t = t.replace(/\*(?!\s)([^*\n]+?)\*/g,'<b>$1</b>');
  t = t.replace(/_(?!\s)([^_\n]+?)_/g,'<i>$1</i>');
  t = t.replace(/~(?!\s)([^~\n]+?)~/g,'<s>$1</s>');
  t = t.replace(/:skin-tone-[2-6]:/g,'');
  t = t.replace(/:([a-z0-9_+-]+):/g,function(m,n){ return EMOJI[n]||m; });
  t = quoteBlocks(t);
  t = t.replace(/\n/g,'<br>');
  t = t.replace(/(\d+)/g,function(m,i){ return ph[+i]; });
  return t;
}
/* 줄 앞의 > (esc 후 &gt;) 연속 라인을 Slack 처럼 인용(blockquote)으로 묶음 */
function quoteBlocks(txt){
  var lines=txt.split('\n'), out=[], buf=[];
  function flush(){ if(buf.length){ out.push('<blockquote>'+buf.join('<br>')+'</blockquote>'); buf=[]; } }
  for(var k=0;k<lines.length;k++){
    var mq=lines[k].match(/^\s*&gt;\s?(.*)$/);
    if(mq){ buf.push(mq[1]); } else { flush(); out.push(lines[k]); }
  }
  flush();
  return out.join('\n');
}
/* 텍스트영역 선택영역을 pre/post 로 감싸기 */
/* 리치 에디터(contenteditable) HTML → Slack mrkdwn 텍스트로 변환 (전송용) */
function htmlToMrkdwn(root){
  function walk(node){
    let out="";
    node.childNodes.forEach(n=>{
      if(n.nodeType===3){ out += n.nodeValue; return; }      // 텍스트
      if(n.nodeType!==1) return;
      if(n.classList && n.classList.contains("mention")){ out += "<@"+(n.dataset.uid||"")+">"; return; }   // @멘션 → Slack ID
      const tag=n.tagName.toLowerCase(), inner=walk(n);
      if(tag==="b"||tag==="strong")           out += "*"+inner+"*";
      else if(tag==="i"||tag==="em")          out += "_"+inner+"_";
      else if(tag==="s"||tag==="strike"||tag==="del") out += "~"+inner+"~";
      else if(tag==="code")                   out += "`"+inner+"`";
      else if(tag==="a"){ const h=n.getAttribute("href")||""; out += h ? (h===inner ? "<"+h+">" : "<"+h+"|"+inner+">") : inner; }
      else if(tag==="br")                     out += "\n";
      else if(tag==="div"||tag==="p")         out += (out && !out.endsWith("\n") ? "\n" : "") + inner;
      else if(tag==="span"){                  // CSS 서식 폴백
        const fw=n.style.fontWeight, fs=n.style.fontStyle, td=(n.style.textDecoration||n.style.textDecorationLine||"");
        let s=inner;
        if(fw==="bold"||parseInt(fw,10)>=600) s="*"+s+"*";
        if(fs==="italic") s="_"+s+"_";
        if(/line-through/.test(td)) s="~"+s+"~";
        out += s;
      }
      else out += inner;
    });
    return out;
  }
  return walk(root);
}
function snip(b){ return (b||"").replace(/\s+/g," ").trim().slice(0,90); }

/* ===== @멘션 자동완성 (요청/담당 이력 사용자 기반) ===== */
let _mMenu=null, _mMatches=[], _mActive=0, _mAnchor=null;
let _mUsers=null;
function mentionUsers(){                     // {id, name} 목록 (요청자+담당자 distinct) — 캐시
  if(_mUsers) return _mUsers;
  const m={};
  DATA.forEach(r=>{
    if(r.req_id && r.req && r.req!=='—') m[r.req_id]=r.req;
    if(r.asg_id && r.asg && r.asg!=='—') m[r.asg_id]=r.asg;
  });
  return _mUsers=Object.entries(m).map(([id,name])=>({id,name}));
}
function _mEnsure(){ if(!_mMenu){ _mMenu=document.createElement('div'); _mMenu.className='mention-menu'; _mMenu.hidden=true; document.body.appendChild(_mMenu); } return _mMenu; }
function hideMention(){ if(_mMenu) _mMenu.hidden=true; _mMatches=[]; _mAnchor=null; }
function mentionActive(){ return _mMenu && !_mMenu.hidden && _mMatches.length; }
function onCmtInput(ed){
  const sel=window.getSelection(); if(!sel.rangeCount){ return hideMention(); }
  const range=sel.getRangeAt(0), node=range.startContainer;
  if(node.nodeType!==3){ return hideMention(); }
  const before=node.nodeValue.slice(0, range.startOffset);
  const at=before.match(/@([^\s@]*)$/);           // @ 뒤 공백 없는 글자열
  if(!at){ return hideMention(); }
  const q=at[1].toLowerCase();
  _mMatches=mentionUsers().filter(u=>u.name.toLowerCase().includes(q)).slice(0,8);
  if(!_mMatches.length){ return hideMention(); }
  _mActive=0; _mAnchor={node, start:range.startOffset-at[0].length, end:range.startOffset};
  const menu=_mEnsure();
  menu.innerHTML=_mMatches.map((u,i)=>`<div class="mi2 ${i===_mActive?'on':''}" data-i="${i}">${esc(u.name)}</div>`).join("");
  let rect=range.getBoundingClientRect();
  if(!rect || (!rect.left && !rect.top)) rect=ed.getBoundingClientRect();
  menu.style.left=Math.round(rect.left)+"px"; menu.style.top=Math.round(rect.bottom+4)+"px";
  menu.hidden=false;
  menu.querySelectorAll(".mi2").forEach(el=>el.addEventListener("mousedown", e=>{ e.preventDefault(); pickMention(+el.dataset.i); }));
}
function mentionNavActive(){ if(_mMenu) _mMenu.querySelectorAll(".mi2").forEach((el,i)=>el.classList.toggle("on", i===_mActive)); }
function pickMention(i){
  const u=_mMatches[i]; if(!u || !_mAnchor){ return; }
  const {node,start,end}=_mAnchor;
  const r=document.createRange(); r.setStart(node,start); r.setEnd(node,end); r.deleteContents();
  const span=document.createElement('span');
  span.className='mention'; span.setAttribute('contenteditable','false'); span.dataset.uid=u.id; span.textContent='@'+u.name;
  r.insertNode(span);
  const sp=document.createTextNode(' '); span.after(sp);
  const sel=window.getSelection(), nr=document.createRange(); nr.setStartAfter(sp); nr.collapse(true); sel.removeAllRanges(); sel.addRange(nr);
  hideMention();
}
document.addEventListener("mousedown", e=>{ if(mentionActive() && !_mMenu.contains(e.target)) hideMention(); });

/* 보드별 독립 필터 통과 여부: 해당 보드의 그 차원 Set 이 비면 미적용, 있으면 포함(OR) */
function selPass(dim, field, r){ const s = FILT[dim][r.board]; return !s || s.size === 0 || s.has(r[field]); }
function matchBase(r, withStatus){   // 검색 + (보드별)우선순위/담당팀 + 선택적으로 진행상태
  return (!filter || (r.title + r.body + r.req + r.asg).toLowerCase().includes(filter)) &&
         (archivedMode || fboardTab === "all" || r.board === fboardTab) &&
         selPass('priority','priority', r) &&
         selPass('team','team', r) &&
         (withStatus === false || selPass('status','status', r));
}
function visible(r){ return showHidden || !r.is_hidden; }   // 숨김 항목은 '숨김 보기'에서만
function filteredItems(){   return DATA.filter(r => visible(r) && matchBase(r, true) && selPass('asg','asg', r)); }
// 미지정 패널: 담당자·진행상태 필터는 적용하지 않음(항상 미지정 + 상태 '등록'만)
const INIT_STATUS = { "블루소프트":"등록", "와이오즈":"시작 전" };   // 보드별 '미지정 대상' 초기상태
function unassignedItems(){ return DATA.filter(r => visible(r) && matchBase(r, false) && (!r.asg || r.asg === '—') && r.status === (INIT_STATUS[r.board] || '등록')); }

function rowHtml(r){
  const p = pal(r.status), open = openId === r.id, unread = !r.is_read;
  const row = `
    <div class="row${unread?' unread':' read'}${r.is_pinned?' pinned':''}${r.is_hidden?' hid':''}" data-id="${esc(r.id)}" ${open?'style="background:var(--bg2)"':''}>
      <input type="checkbox" class="chk" data-id="${esc(r.id)}" ${selected.has(r.id)?'checked':''}>
      <div class="pinBtn doPin${r.is_pinned?' on':''}" data-id="${esc(r.id)}" data-pin="${r.is_pinned?0:1}" title="${r.is_pinned?'고정 해제':'상단 고정'}">📌</div>
      <div class="names">${esc(r.req||'—')}${r.asg && r.asg!=='—' ? `, ${esc(r.asg)}` : ''}</div>
      ${!archivedMode && fboardTab==="all" && r.board ? `<span class="bdot ${r.board==='와이오즈'?'w':'b'}" title="${esc(boardLabel(r.board))}">${r.board==='와이오즈'?'W':'U'}</span>` : ''}
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:8px">
          ${r.priority?`<span class="st" style="flex:none;background:${priColor(r.priority).bg};color:${priColor(r.priority).fg}">${esc(r.priority)}</span>`:''}
          <span class="title">${esc(r.title)}</span>
          ${r.status?`<span class="st" style="background:${p.bg};color:${p.fg}">${esc(r.status)}</span>`:''}
          ${r.team?`<span class="st" style="background:${teamColor(r.team).bg};color:${teamColor(r.team).fg}">${esc(r.team)}</span>`:''}
          ${r.archived?'<span class="st" style="background:#e5e7eb;color:#4b5563">🗄 보관</span>':''}
          ${r.locked?'<span class="lock">✎수정됨</span>':''}
        </div>
        <div class="snipline">
          ${r.cmt_count>0?`<span class="cmtcnt">💬 +${r.cmt_count}</span>`:''}
          <div class="snip">${esc(snip(r.body))}</div>
        </div>
      </div>
      <div class="date">${fmtDate(r.created)}</div>
    </div>`;
  if(!open) return row;
  return row + `
    <div class="detail${showCmts?' with-cmts':''}">
      <div class="detail-main">
        <div class="meta">
          ${r.archived
            ? metaItem('진행상태', r.status||'—')
            : `<div class="mi"><span class="ml">진행상태</span><select class="edit-status" data-id="${esc(r.id)}">${statusEditOptions(r.status, r.board)}</select></div>`}
          ${r.archived
            ? metaItem('담당자', r.asg||'—')
            : `<div class="mi"><span class="ml">담당자</span><select class="edit-asg" data-id="${esc(r.id)}">${asgEditOptions(r.asg_id)}</select></div>`}
          ${metaItem('요청일', r.date?fmtYmd(r.date):fmtDate(r.created))}
          ${(!r.archived && r.board!=="와이오즈")?`<div class="mi"><span class="ml">예상완료일</span><input type="date" class="edit-eta" data-id="${esc(r.id)}" value="${esc(r.eta||'')}"></div>`:''}
          ${r.done?metaItem('완료일', fmtYmd(r.done)):''}
          ${metaItem('갱신', fmtDT(r.updated))}
          ${r.edited_by?metaItem('최종수정', r.edited_by):''}
        </div>
        <div class="body-card">${mrkdwn(r.body)}</div>
        ${attHtml(r.attachments)}
        <div class="links">
          ${LIST_URL?`<button type="button" class="slack-link copyLink" data-url="${esc(LIST_URL)}?record_id=${esc(r.id)}">링크 복사</button>`:''}
          ${r.momo?`<a href="${esc(r.momo)}" target="_blank" rel="noopener">모모 이슈</a>`:''}
          ${r.lms?`<a href="${esc(r.lms)}" target="_blank" rel="noopener">LMS 링크</a>`:''}
          <a href="similar.php?id=${encodeURIComponent(r.id)}" target="_blank" rel="noopener">🔍 유사 이력</a>
          <button type="button" class="rdBtn" data-id="${esc(r.id)}" data-read="${r.is_read?0:1}">${r.is_read?'안읽음으로':'읽음으로'}</button>
          <button type="button" class="doHide" data-id="${esc(r.id)}" data-hide="${r.is_hidden?0:1}">${r.is_hidden?'👁 숨김 해제':'🙈 숨기기'}</button>
          <button type="button" class="cmtToggle ${showCmts?'on':''}">💬 댓글${r.cmt_count>0?` ${r.cmt_count}`:''}${showCmts?' 닫기':''}</button>
        </div>
      </div>
      ${showCmts?`
      <div class="detail-cmts">
        <div class="cmts-title">💬 댓글${r.cmt_count>0?` <span class="cmts-n">${r.cmt_count}</span>`:''}</div>
        <div class="cmts" id="cmts-${esc(r.id)}">${cmtCache[r.id]!==undefined ? cmtHtml(cmtCache[r.id]) : '<div class="cmt-loading">댓글 불러오는 중…</div>'}</div>
        <div class="cmt-form">
          <div class="cmt-tb">
            <button type="button" class="tb" data-id="${esc(r.id)}" data-act="b" title="굵게"><b>B</b></button>
            <button type="button" class="tb" data-id="${esc(r.id)}" data-act="i" title="기울임"><i>I</i></button>
            <button type="button" class="tb" data-id="${esc(r.id)}" data-act="s" title="취소선"><s>S</s></button>
            <button type="button" class="tb" data-id="${esc(r.id)}" data-act="code" title="코드">&lt;/&gt;</button>
            <button type="button" class="tb" data-id="${esc(r.id)}" data-act="link" title="링크">🔗</button>
          </div>
          <div class="cmt-input" id="cin-${esc(r.id)}" contenteditable="true" data-ph="댓글 입력… (Ctrl+Enter 전송)"></div>
          <div class="cmt-actions"><button type="button" class="cmt-send" data-id="${esc(r.id)}">작성</button></div>
        </div>
      </div>`:''}
    </div>`;
}

function metaItem(label, val){
  return `<div class="mi"><span class="ml">${esc(label)}</span><span class="mv">${esc(val)}</span></div>`;
}
/* 진행상태 옵션은 리스트(board)마다 다름 */
const BLUE_STATUS = ["등록","공수산정요청","진행중","확인요청(검토완료)","확인요청(개발서버반영)","확인요청(운영서버반영)","운영배포요청","완료","보류","처리불가"];
const YOZ_STATUS  = ["시작 전","진행중","완료","보류","상세설명필요","작업불가","재확인필요"];
function statusEditOptions(cur, board){
  let list = (board === "와이오즈") ? YOZ_STATUS : BLUE_STATUS;
  if(cur && !list.includes(cur)) list = [cur, ...list];   // 현재값이 목록에 없으면 유지
  return list.map(s=>`<option${s===cur?' selected':''}>${esc(s)}</option>`).join("");
}
function asgUsers(){   // 데이터에서 distinct 담당자 {id:name}
  const m={};
  DATA.forEach(r=>{ if(r.asg_id && r.asg && r.asg!=="—") m[r.asg_id]=r.asg; });
  return Object.entries(m).sort((a,b)=>String(a[1]).localeCompare(String(b[1])));
}
function asgEditOptions(curId){
  let h=`<option value=""${!curId?' selected':''}>미지정</option>`;
  let found=false;
  h+=asgUsers().map(([id,name])=>{ if(id===curId)found=true; return `<option value="${esc(id)}"${id===curId?' selected':''}>${esc(name)}</option>`; }).join("");
  return h;
}
async function updateField(id, field, value){
  try{
    const j = await (await fetch("update.php", {
      method:"POST", headers:{"Content-Type":"application/json"},
      body: JSON.stringify({request_id:id, field, value})
    })).json();
    if(!j.ok) throw new Error(j.error || "실패");
    const r = DATA.find(x=>x.id===id);
    if(r){
      if(field==="status"){ r.status=j.value; r.status_id=j.status_id; }
      else if(field==="eta"){ r.eta=j.eta||null; }
      else { r.asg_id=j.asg_id||null; r.asg=j.asg; }
    }
    render();
  }catch(err){ alert("수정 실패: " + err.message); }
}
function cmtInitial(n){ return (n||"?").trim().slice(0,1) || "?"; }
function authorColor(name){            // 작성자 이름 → 고유 색상(HSL 해시)
  let h=0; const s=(name||"?");
  for(let i=0;i<s.length;i++) h=(h*31 + s.charCodeAt(i))>>>0;
  const hue=h%360;
  return { bg:`hsl(${hue} 68% 92%)`, fg:`hsl(${hue} 45% 35%)` };
}
function fmtSize(n){ if(!n) return ""; if(n<1024) return n+"B"; if(n<1048576) return Math.round(n/1024)+"KB"; return (n/1048576).toFixed(1)+"MB"; }
function fileExt(n){ const m=(n||"").match(/\.([a-z0-9]+)$/i); return m?m[1].toUpperCase():"FILE"; }
const _pf = u => "file.php?u="+encodeURIComponent(u);
/* 앱 내 뷰어로 볼 수 있는 문서 종류: pdf(iframe) / sheet(엑셀·csv, SheetJS) */
function docType(name){
  const e=((name||"").split(".").pop()||"").toLowerCase();
  if(e==="pdf") return "pdf";
  if(e==="xlsx"||e==="xls"||e==="csv") return "sheet";
  return "";
}
/* 동영상 판별: mime 또는 확장자. (전송본 mp4 필드가 없어도 원본으로 재생) */
function isVideo(f){
  const e=((f.name||"").split(".").pop()||"").toLowerCase();
  return (f.mime||"").indexOf("video/")===0 || ["mp4","mov","webm","m4v","ogv","avi","mkv"].includes(e);
}
/* 첨부파일 1개 → HTML. 이미지/동영상/문서(pdf·엑셀 등)/기타 파일 구분 렌더.
   imgCls: 이미지 썸네일에 붙일 클래스(본문=att-img, 댓글=cmt-img) */
function fileHtml(f, imgCls){
  const dl = _pf(f.download||f.url)+"&dl=1&name="+encodeURIComponent(f.name);
  // 동영상: 포스터 + 재생버튼 → 클릭 시 라이트박스에서 재생
  // 전송본(mp4) 있으면 우선, 없으면 원본(download)으로 브라우저 재생
  if(isVideo(f)){
    const vsrc = _pf(f.mp4 || f.download || f.url);
    const poster = f.thumb_video ? _pf(f.thumb_video) : (f.is_image && f.thumb ? _pf(f.thumb) : "");
    return `<div class="media-video lb" data-type="video" data-src="${escAttr(vsrc)}" data-dl="${escAttr(dl)}" data-name="${escAttr(f.name)}" title="${escAttr(f.name)}">`
      + (poster ? `<img src="${poster}" alt="${escAttr(f.name)}" loading="lazy">` : `<div class="media-noposter">🎬</div>`)
      + `<span class="media-play">▶</span></div>`;
  }
  // 이미지: 썸네일 → 라이트박스 원본
  if(f.is_image && f.thumb){
    return `<img class="${imgCls} lb" src="${_pf(f.thumb)}" data-full="${escAttr(_pf(f.url))}" data-dl="${escAttr(dl)}" data-name="${escAttr(f.name)}" alt="${escAttr(f.name)}" loading="lazy" title="${escAttr(f.name)}">`;
  }
  // pdf/엑셀: 앱 내 라이트박스에서 바로 보기(pdf=iframe, 엑셀=표)
  const dt = docType(f.name);
  if(dt){
    const src=escAttr(_pf(f.url)), badge=esc(fileExt(f.name));
    if(f.thumb_pdf){
      return `<div class="media-doc lb" data-type="${dt}" data-src="${src}" data-dl="${escAttr(dl)}" data-name="${escAttr(f.name)}" title="${escAttr(f.name)}">`
        + `<img src="${_pf(f.thumb_pdf)}" alt="${escAttr(f.name)}" loading="lazy">`
        + `<span class="media-doc-badge">${badge}</span></div>`;
    }
    // 썸네일이 없으면 아이콘 타일
    return `<div class="media-doc media-doc--noimg lb" data-type="${dt}" data-src="${src}" data-dl="${escAttr(dl)}" data-name="${escAttr(f.name)}" title="${escAttr(f.name)}">`
      + `<span class="media-doc-ic">${dt==="sheet"?"📊":"📄"}</span>`
      + `<span class="media-doc-nm">${esc(f.name)}</span><span class="media-doc-badge">${badge}</span></div>`;
  }
  // 그 외 문서(doc/ppt 등): 첫 페이지 미리보기 → 클릭 시 원본 새 탭
  if(f.thumb_pdf){
    return `<a class="media-doc" href="${_pf(f.url)}" target="_blank" rel="noopener" title="${escAttr(f.name)}">`
      + `<img src="${_pf(f.thumb_pdf)}" alt="${escAttr(f.name)}" loading="lazy">`
      + `<span class="media-doc-badge">${esc(fileExt(f.name))}</span></a>`;
  }
  // 기타: 다운로드 링크
  return `<a class="att-file" href="${dl}" rel="noopener">📎 <span>${esc(f.name)}</span>${f.size?`<span class="sz">${fmtSize(f.size)}</span>`:""}</a>`;
}
/* 상세 본문 첨부파일: 이미지/동영상/문서 미리보기, 그 외 다운로드 링크 */
function attHtml(list){
  if(!list || !list.length) return "";
  const items = list.map(f=>fileHtml(f, "att-img")).join("");
  return `<div class="atts"><div class="atts-title">📎 첨부파일 ${list.length}</div><div class="atts-list">${items}</div></div>`;
}
function cmtHtml(list){
  if(!list || !list.length) return '<div class="cmt-empty">아직 댓글이 없습니다.</div>';
  return list.map(c=>{
    const col = authorColor(c.author_name);
    return `
    <div class="cmt">
      <div class="cmt-av" style="background:${col.bg};color:${col.fg}">${esc(cmtInitial(c.author_name))}</div>
      <div class="cmt-bub">
        <div class="cmt-h"><b style="color:${col.fg}">${esc(c.author_name)}</b><span class="cmt-t">${esc(c.created_at)}</span></div>
        ${c.body ? `<div class="cmt-b">${mrkdwn(c.body)}</div>` : ''}
        ${(c.files&&c.files.length) ? `<div class="cmt-files">${c.files.map(f=>fileHtml(f, "cmt-img")).join("")}</div>` : ''}
      </div>
    </div>`;
  }).join("");
}
async function loadComments(id){
  try{
    const j = await (await fetch("comments.php?request_id="+encodeURIComponent(id), {cache:"no-store"})).json();
    cmtCache[id] = j.comments || [];
  }catch(e){ cmtCache[id] = []; }
  const box = document.getElementById("cmts-"+id);
  if(box){
    box.innerHTML = cmtHtml(cmtCache[id]); bindLightbox(box);
    box.scrollTop = box.scrollHeight;                       // 최신 댓글(맨 아래) 바로 보이게
    setTimeout(()=>{ box.scrollTop = box.scrollHeight; }, 150);   // 이미지 로드 등 늦은 레이아웃 보정
  }
}
function _nowStr(){ const d=new Date(); return d.getFullYear()+"-"+pad2(d.getMonth()+1)+"-"+pad2(d.getDate())+" "+pad2(d.getHours())+":"+pad2(d.getMinutes()); }
async function postComment(id){
  const ed = document.getElementById("cin-"+id);
  const text = ed ? htmlToMrkdwn(ed).trim() : "";
  if(!text) return;
  const box = document.getElementById("cmts-"+id);
  // 낙관적 표시: Slack 응답 기다리지 않고 즉시 화면에 추가 (렉 체감 제거)
  if(ed) ed.innerHTML = "";
  cmtCache[id] = cmtCache[id] || [];
  cmtCache[id].push({ author_name: ME, body: text, created_at: _nowStr(), files: [] });
  if(box){ box.innerHTML = cmtHtml(cmtCache[id]); bindLightbox(box); box.scrollTop = box.scrollHeight; }
  // 실제 전송은 백그라운드 → 완료되면 Slack 기준으로 재동기화
  try{
    const j = await (await fetch("comments.php", {
      method:"POST", headers:{"Content-Type":"application/json"},
      body: JSON.stringify({request_id:id, text})
    })).json();
    if(!j.ok) throw new Error(j.error || "실패");
    loadComments(id);   // 백그라운드 재로드(대기 안 함)
  }catch(err){
    alert("댓글 작성 실패: " + err.message);
    loadComments(id);   // 실패 시 낙관적 항목 정리
  }
}

function bindRows(box){
  box.querySelectorAll(".row").forEach(el=>{
    el.addEventListener("click",()=>{
      const id = el.dataset.id;
      const opening = (openId !== id);
      openId = opening ? id : null;
      if(opening){
        const r = DATA.find(x=>x.id===id);   // 댓글 패널 상태는 마지막 값 유지(showCmts)
        if(r && !r.is_read) markRead(id, 1, false);
      }
      render();
      if(opening && showCmts) loadComments(id);   // 열 때 최신 댓글 자동 로드
    });
  });
  box.querySelectorAll(".cmtToggle").forEach(el=>{
    el.addEventListener("click", e=>{
      e.stopPropagation();
      showCmts = !showCmts;
      saveView();                                    // 열기/닫기 상태 저장
      render();
      if(showCmts && openId) loadComments(openId);   // 열 때 최신 댓글 로드
    });
  });
  box.querySelectorAll(".rdBtn").forEach(el=>{
    el.addEventListener("click",(e)=>{ e.stopPropagation(); markRead(el.dataset.id, +el.dataset.read, true); });
  });
  box.querySelectorAll(".doPin").forEach(el=>{
    el.addEventListener("click",(e)=>{ e.stopPropagation(); markPin(el.dataset.id, +el.dataset.pin); });
  });
  box.querySelectorAll(".doHide").forEach(el=>{
    el.addEventListener("click",(e)=>{ e.stopPropagation(); markHide(el.dataset.id, +el.dataset.hide); });
  });
  box.querySelectorAll(".copyLink").forEach(el=>{
    el.addEventListener("click", async e=>{
      e.stopPropagation();
      const url = el.dataset.url, old = el.textContent;
      try{ await navigator.clipboard.writeText(url); }
      catch(_){ const ta=document.createElement("textarea"); ta.value=url; document.body.appendChild(ta); ta.select(); document.execCommand("copy"); ta.remove(); }
      el.textContent = "복사됨!";
      setTimeout(()=>{ el.textContent = old; }, 1200);
    });
  });
  box.querySelectorAll(".chk").forEach(el=>{
    el.addEventListener("click", e=>e.stopPropagation());
    el.addEventListener("change", e=>{
      if(e.target.checked) selected.add(e.target.dataset.id);
      else selected.delete(e.target.dataset.id);
      updateSelUI();   // 체크 즉시 액션 버튼 노출/숨김
    });
  });
  box.querySelectorAll(".edit-status").forEach(el=>{
    el.addEventListener("click", e=>e.stopPropagation());
    el.addEventListener("change", e=>{ e.stopPropagation(); updateField(el.dataset.id,"status",e.target.value); });
  });
  box.querySelectorAll(".edit-asg").forEach(el=>{
    el.addEventListener("click", e=>e.stopPropagation());
    el.addEventListener("change", e=>{ e.stopPropagation(); updateField(el.dataset.id,"asg",e.target.value); });
  });
  box.querySelectorAll(".edit-eta").forEach(el=>{
    el.addEventListener("click", e=>e.stopPropagation());
    el.addEventListener("change", e=>{ e.stopPropagation(); updateField(el.dataset.id,"eta",e.target.value); });
  });
  box.querySelectorAll(".cmt-input").forEach(el=>{
    el.addEventListener("click", e=>e.stopPropagation());
    el.addEventListener("input", ()=>onCmtInput(el));
    el.addEventListener("blur", ()=>setTimeout(hideMention, 150));
    el.addEventListener("keydown", e=>{
      if(mentionActive()){   // 자동완성 열림: 방향키/Enter/Tab/Esc 로 선택
        if(e.key==="ArrowDown"){ e.preventDefault(); _mActive=(_mActive+1)%_mMatches.length; mentionNavActive(); return; }
        if(e.key==="ArrowUp"){   e.preventDefault(); _mActive=(_mActive-1+_mMatches.length)%_mMatches.length; mentionNavActive(); return; }
        if(e.key==="Enter"||e.key==="Tab"){ e.preventDefault(); pickMention(_mActive); return; }
        if(e.key==="Escape"){ e.preventDefault(); hideMention(); return; }
      }
      if(e.key==="Enter" && (e.ctrlKey||e.metaKey)){ e.preventDefault(); postComment(el.id.replace("cin-","")); }   // Ctrl/Cmd+Enter 전송
    });
  });
  box.querySelectorAll(".tb").forEach(el=>{
    el.addEventListener("mousedown", e=>e.preventDefault());   // 클릭해도 에디터 선택 유지
    el.addEventListener("click", e=>{
      e.stopPropagation();
      const ed=document.getElementById("cin-"+el.dataset.id), a=el.dataset.act;
      if(!ed) return; ed.focus();
      try{ document.execCommand('styleWithCSS', false, null); }catch(_){}
      if(a==="b") document.execCommand('bold');
      else if(a==="i") document.execCommand('italic');
      else if(a==="s") document.execCommand('strikeThrough');
      else if(a==="code"){ const t=(window.getSelection().toString()||'코드'); document.execCommand('insertHTML', false, '<code>'+t.replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))+'</code>'); }
      else if(a==="link"){ const u=prompt("링크 URL:"); if(u) document.execCommand('createLink', false, u); }
    });
  });
  box.querySelectorAll(".cmt-send").forEach(el=>{
    el.addEventListener("click", e=>{ e.stopPropagation(); postComment(el.dataset.id); });
  });
  bindLightbox(box);
}

/* 이미지 클릭 → 라이트박스 열기 (같은 묶음 내 이미지들로 슬라이드) */
function bindLightbox(scope){
  scope.querySelectorAll(".lb").forEach(el=>{
    el.addEventListener("click", e=>{
      e.stopPropagation();
      const group = el.closest(".atts-list, .cmt-files") || scope;
      const nodes = [...group.querySelectorAll(".lb")];
      const imgs = nodes.map(x=>({ type:x.dataset.type||"image", full:x.dataset.full, src:x.dataset.src,
                                   name:x.dataset.name||"", dl:x.dataset.dl||x.dataset.full }));
      lbOpen(imgs, Math.max(0, nodes.indexOf(el)));
    });
  });
}

function paint(box, items){
  if(items.length === 0){ box.innerHTML = '<div class="err">표시할 항목이 없습니다.</div>'; return; }
  box.innerHTML = items.map(rowHtml).join("");
  bindRows(box);
}

/* ---------- 댓글 영역 크기 조절(드래그) + 크기 기억 ---------- */
const CMT_W_KEY="slackapi_cmt_w", CMT_H_KEY="slackapi_cmt_h", CMT_IN_H_KEY="slackapi_cmt_input_h";
function applyCmtSize(){
  const panel=document.querySelector(".detail-cmts");
  if(!panel) return;                       // 댓글 패널이 열려있을 때만
  const list=panel.querySelector(".cmts");
  const input=panel.querySelector(".cmt-input");
  const w=localStorage.getItem(CMT_W_KEY); if(w) panel.style.width=w+"px";
  const h=localStorage.getItem(CMT_H_KEY); if(list&&h) list.style.height=h+"px";
  const ih=localStorage.getItem(CMT_IN_H_KEY); if(input&&ih) input.style.height=ih+"px";   // 입력창 높이 복원
  // 드래그로 크기 바꾸면(마우스 놓을 때) 기록
  panel.addEventListener("mouseup",()=>localStorage.setItem(CMT_W_KEY, Math.round(panel.getBoundingClientRect().width)));
  if(list) list.addEventListener("mouseup",()=>localStorage.setItem(CMT_H_KEY, Math.round(list.getBoundingClientRect().height)));
  if(input) input.addEventListener("mouseup",()=>localStorage.setItem(CMT_IN_H_KEY, Math.round(input.getBoundingClientRect().height)));   // 입력창 높이 저장
}

/* 선택 UI만 갱신(리스트 재렌더 없이) — 체크 즉시 액션 버튼 노출 */
function updateSelUI(items){
  items = items || filteredItems();
  const selAll = document.getElementById("selAll");
  if(selAll) selAll.checked = items.length>0 && items.every(r=>selected.has(r.id));
  const info = document.getElementById("selInfo");
  if(info) info.textContent = selected.size>0 ? `${selected.size}개 선택됨` : "전체 선택";
  const acts = document.getElementById("selActions");
  if(acts) acts.hidden = selected.size === 0;
}

/* 상단 리스트 탭 (전체/블루소프트/와이오즈) */
function renderBTabs(){
  const box0 = document.getElementById("btabs");
  if(archivedMode){ if(box0) box0.style.display="none"; return; }   // 보관은 전부 유비온 → 탭 숨김
  if(box0) box0.style.display="";
  const counts = { all: DATA.length };
  DATA.forEach(r=>counts[r.board] = (counts[r.board]||0) + 1);
  const tabs = [["all","전체"], ["블루소프트","유비온"], ["와이오즈","와이오즈"]];
  const box = document.getElementById("btabs");
  if(!box) return;
  box.innerHTML = tabs.map(([v,l])=>
    `<button type="button" class="btab${v===fboardTab?' on':''}" data-b="${escAttr(v)}">${esc(l)} <span class="btab-n">${counts[v]||0}</span></button>`).join("");
  box.querySelectorAll(".btab").forEach(t=>t.addEventListener("click", ()=>{
    fboardTab = t.dataset.b; saveFilters(); openId = null; buildAllMS();
    if(archivedMode) loadArchived(0); else render();
  }));
}
function render(){
  const items = filteredItems();
  document.getElementById("count").textContent = items.length + "건";
  renderBTabs();
  const hc = DATA.filter(r=>r.is_hidden).length;   // 숨김 개수 → 토글 버튼에 표시
  document.getElementById("toggleHidden").dataset.tip = (showHidden?"숨김 가리기":"숨김 보기") + (hc?` (${hc})`:"");
  document.getElementById("hidCnt").textContent = hc || "";
  document.getElementById("hidIco").textContent = showHidden ? "🐵" : "🙈";   // 활성 시 손 치운 원숭이
  // 탭 제목 배지: 안읽음 개수(숨김 제외)
  const uc = DATA.filter(r=>!r.is_read && !r.is_hidden).length;
  document.title = (uc>0 ? `(${uc}) ` : "") + "유지보수 요청";
  updateSelUI(items);   // 선택 개수/액션 버튼/전체선택 체크 상태 갱신
  paint(document.getElementById("list"), items);

  if(splitOn && !archivedMode){                 // 미지정 패널(좌측) — 보관 모드에선 숨김
    const un = unassignedItems();
    document.getElementById("uncount").textContent = un.length + "건";
    paint(document.getElementById("unlist"), un);
  }
  applyCmtSize();   // 댓글 영역 사용자 지정 크기 복원
}

/* 선택 항목 일괄 읽음/안읽음 */
async function bulkRead(read){
  const ids = [...selected];
  if(!ids.length){ alert("선택된 항목이 없습니다."); return; }
  try{
    const j = await (await fetch("read.php", {
      method:"POST", headers:{"Content-Type":"application/json"},
      body: JSON.stringify({ids, read})
    })).json();
    if(!j.ok) throw new Error(j.error || "실패");
    ids.forEach(id=>{ const r=DATA.find(x=>x.id===id); if(r) r.is_read = read?1:0; });
    selected.clear();
    render();
  }catch(err){ alert("처리 실패: " + err.message); }
}

/* 읽음/안읽음 처리: 로컬 즉시 반영(재정렬 안 함) + 서버 저장 */
function markRead(id, read, doRender){
  const r = DATA.find(x=>x.id===id);
  if(r) r.is_read = read ? 1 : 0;
  fetch("read.php", {
    method:"POST", headers:{"Content-Type":"application/json"},
    body: JSON.stringify({request_id:id, read})
  }).catch(()=>{});
  if(doRender) render();
}

/* 고정 우선 정렬: 고정 → 안읽음 → 최신순 (data.php ORDER BY 와 동일) */
function sortData(){
  DATA.sort((a,b)=> (b.is_pinned-a.is_pinned) || (a.is_read-b.is_read) || (b.created-a.created));
}
/* 고정/해제: 로컬 즉시 반영 + 재정렬(상단 이동) + 서버 저장 */
function markPin(id, pin){
  const r = DATA.find(x=>x.id===id);
  if(r) r.is_pinned = pin ? 1 : 0;
  fetch("pin.php", {
    method:"POST", headers:{"Content-Type":"application/json"},
    body: JSON.stringify({request_id:id, pin})
  }).catch(()=>{});
  sortData();
  render();
}
/* 숨김/해제: 로컬 즉시 반영 + 서버 저장 */
function markHide(id, hide){
  const r = DATA.find(x=>x.id===id);
  if(r) r.is_hidden = hide ? 1 : 0;
  fetch("hide.php", {
    method:"POST", headers:{"Content-Type":"application/json"},
    body: JSON.stringify({request_id:id, hide})
  }).catch(()=>{});
  if(hide && !showHidden) openId = null;   // 숨기면 목록에서 사라지므로 상세 닫기
  render();
}

/* ---------- 헤더 다중선택 필터 드롭다운 (보드별 섹션) ---------- */
const MS = [
  { id:'msPriority', label:'우선순위', dim:'priority', field:'priority' },
  { id:'msTeam',     label:'담당팀',   dim:'team',     field:'team' },
  { id:'msStatus',   label:'진행상태', dim:'status',   field:'status' },
  { id:'msAsg',      label:'담당자',   dim:'asg',      field:'asg' },
];
// 진행상태·우선순위는 보드별 고정 목록(기존 방식 유지), 담당팀·담당자는 데이터 기반
const CANON = {
  priority: { "블루소프트":["긴급","일반"], "와이오즈":["긴급","중요","일반"] },
  status:   { "블루소프트":BLUE_STATUS,       "와이오즈":YOZ_STATUS },
};
function dimValues(field, board){
  if(CANON[field] && CANON[field][board]) return CANON[field][board];   // 고정 목록
  return [...new Set(DATA.filter(r=>r.board===board).map(r=>r[field]).filter(v=>v && v!=="—"))].sort((a,b)=>a.localeCompare(b,'ko'));
}
function dimSelCount(dim){ return FILT[dim]["블루소프트"].size + FILT[dim]["와이오즈"].size; }
function closeAllMS(){ document.querySelectorAll(".ms-menu").forEach(m=>m.hidden=true); }
function msBtnInner(cfg){ const n=dimSelCount(cfg.dim); return `${esc(cfg.label)}${n?` <span class="ms-n">${n}</span>`:''} <span class="ms-ar">▾</span>`; }
function updateMSBtn(cfg){
  const btn=document.querySelector("#"+cfg.id+" .ms-btn"); if(!btn) return;
  btn.innerHTML=msBtnInner(cfg); btn.classList.toggle("active", dimSelCount(cfg.dim)>0);
}
function buildMS(cfg){
  const cont=document.getElementById(cfg.id); if(!cont) return;
  let menu="";
  const boards = archivedMode ? ["블루소프트"] : ((fboardTab === "all") ? BOARDS_F : [fboardTab]);   // 보관=유비온만, 그 외 활성 탭 기준
  boards.forEach(board=>{
    const set=FILT[cfg.dim][board], vals=dimValues(cfg.field, board);
    menu += `<div class="ms-sec">${esc(boardLabel(board))}</div>`;
    menu += vals.length
      ? vals.map(v=>`<label class="ms-item"><input type="checkbox" data-b="${escAttr(board)}" value="${escAttr(v)}" ${set.has(v)?'checked':''}><span>${esc(v)}</span></label>`).join("")
      : '<div class="ms-empty">항목 없음</div>';
  });
  cont.innerHTML =
    `<button type="button" class="ms-btn${dimSelCount(cfg.dim)?' active':''}">${msBtnInner(cfg)}</button>`+
    `<div class="ms-menu" hidden>${menu}</div>`;
  const btn=cont.querySelector(".ms-btn"), menuEl=cont.querySelector(".ms-menu");
  btn.addEventListener("click", e=>{ e.stopPropagation(); const willOpen=menuEl.hidden; closeAllMS(); menuEl.hidden=!willOpen; });
  menuEl.addEventListener("click", e=>e.stopPropagation());
  menuEl.querySelectorAll("input").forEach(inp=>inp.addEventListener("change", ()=>{
    const set=FILT[cfg.dim][inp.dataset.b];
    if(inp.checked) set.add(inp.value); else set.delete(inp.value);
    updateMSBtn(cfg); saveFilters(); openId=null; render();
  }));
}
function buildAllMS(){ MS.forEach(buildMS); }
document.addEventListener("click", closeAllMS);   // 바깥 클릭 시 메뉴 닫기

/* ---------- 필터 상태 저장/복원 (새로고침에도 유지) ---------- */
const FILTER_KEY = "slackapi_filters";   // 운영 lists.php 와 별도 키
const _DIMS = ["priority","team","status","asg"];
function saveFilters(){
  const dump={}; _DIMS.forEach(d=>dump[d]={"블루소프트":[...FILT[d]["블루소프트"]], "와이오즈":[...FILT[d]["와이오즈"]]});
  localStorage.setItem(FILTER_KEY, JSON.stringify({ filt:dump, fboardTab, search: document.getElementById("search").value }));
}
function restoreFilters(){
  let s = {};
  try { s = JSON.parse(localStorage.getItem(FILTER_KEY) || "{}"); } catch(e){}
  const f = s.filt || {};
  _DIMS.forEach(d=>{ FILT[d] = {"블루소프트":new Set((f[d]&&f[d]["블루소프트"])||[]), "와이오즈":new Set((f[d]&&f[d]["와이오즈"])||[])}; });
  // 구버전(단일 Set: fpri/fteam/fstat/fasg) 저장값 → 블루소프트 필터로 이관(기존 선택 유지)
  if(!s.filt && (s.fpri||s.fteam||s.fstat||s.fasg)){
    FILT.priority["블루소프트"] = new Set(s.fpri||[]);
    FILT.team["블루소프트"]     = new Set(s.fteam||[]);
    FILT.status["블루소프트"]   = new Set(s.fstat||[]);
    FILT.asg["블루소프트"]      = new Set(s.fasg||[]);
  }
  fboardTab = s.fboardTab || "all";
  const sv = s.search || "";
  document.getElementById("search").value = sv;
  filter = sv.toLowerCase().trim();
}

/* ---------- 뷰 토글(미지정 분할/숨김 보기) 저장·복원 ---------- */
const VIEW_KEY = "slackapi_view";
function saveView(){ localStorage.setItem(VIEW_KEY, JSON.stringify({ splitOn, showHidden, showCmts, archivedMode, archPageSize })); }
function restoreView(){
  let v = {};
  try { v = JSON.parse(localStorage.getItem(VIEW_KEY) || "{}"); } catch(e){}
  if(typeof v.splitOn === "boolean")    splitOn = v.splitOn;
  if(typeof v.showHidden === "boolean") showHidden = v.showHidden;
  if(typeof v.showCmts === "boolean")   showCmts = v.showCmts;   // 댓글 패널 열기/닫기 상태 복원
  if(typeof v.archivedMode === "boolean") archivedMode = v.archivedMode;   // 보관 보기 상태 복원
  if(v.archPageSize === 'all' || typeof v.archPageSize === 'number') archPageSize = v.archPageSize;
  // 버튼/패널 UI 에 반영 (toggleHidden 텍스트+개수는 render() 에서 갱신)
  document.getElementById("unpanel").style.display = (splitOn && !archivedMode) ? "" : "none";
  document.getElementById("toggleUn").textContent  = splitOn ? "미지정 숨기기" : "미지정 리스트 표시";
  document.getElementById("toggleHidden").classList.toggle("primary", showHidden);
  const ab=document.getElementById("toggleArch");
  ab.classList.toggle("primary", archivedMode);
  ab.textContent = archivedMode ? "🗄 보관 닫기" : "🗄 보관 보기";
}

/* ---------- 브라우저 알림 ---------- */
let prevSeen = null;   // id -> cmt_count 스냅샷 (새 요청/새 댓글 비교용)
function notify(title, body){
  if(!("Notification" in window) || Notification.permission!=="granted") return;
  try{ const n=new Notification(title, {body, tag:"slackapi", renotify:true}); n.onclick=()=>{ window.focus(); n.close(); }; }catch(e){}
}
function detectAndNotify(){
  const cur = new Map(DATA.map(r=>[r.id, r.cmt_count]));
  if(prevSeen){   // 최초 로드는 기준값만 잡고 알림 안 함
    const fresh = DATA.filter(r=>!prevSeen.has(r.id) && !r.is_hidden);
    const newc  = DATA.filter(r=>prevSeen.has(r.id) && r.cmt_count > prevSeen.get(r.id) && !r.is_hidden);
    if(fresh.length===1) notify("🆕 새 유지보수 요청", fresh[0].title);
    else if(fresh.length>1) notify("🆕 새 유지보수 요청", fresh.length+"건 등록됨");
    if(newc.length===1) notify("💬 새 댓글", newc[0].title);
    else if(newc.length>1) notify("💬 새 댓글", newc.length+"건에 댓글");
  }
  prevSeen = cur;
}
// 첫 클릭(사용자 제스처) 때 알림 권한 요청 (브라우저 정책상 제스처 필요)
document.addEventListener("click", ()=>{ if("Notification" in window && Notification.permission==="default") Notification.requestPermission(); }, {once:true});

/* ---------- 첨부 썸네일 프리페치 (백그라운드·저동시성) ----------
   목록 로드 후 유휴 시간에 이미지 썸네일을 미리 받아 file.php 디스크 캐시 +
   브라우저 캐시를 워밍한다. 상세를 열 때 이미 캐시돼 즉시 표시됨. */
const _prefetched = new Set();
function prefetchThumbs(){
  const urls = [];
  DATA.forEach(r=>{
    (r.attachments||[]).forEach(f=>{
      if(f.is_image && f.thumb && !_prefetched.has(f.thumb)){
        _prefetched.add(f.thumb);
        urls.push("file.php?u="+encodeURIComponent(f.thumb));
      }
    });
  });
  if(!urls.length) return;
  let i = 0; const CONC = 4;                 // 동시 4개까지만(서버·네트워크 부담 최소화)
  const next = ()=>{
    if(i >= urls.length) return;
    const img = new Image();
    img.onload = img.onerror = next;         // 하나 끝나면 다음 장
    try{ img.fetchPriority = "low"; }catch(_){}
    img.src = urls[i++];
  };
  for(let k=0; k<CONC; k++) next();
}
const _idle = window.requestIdleCallback || (cb=>setTimeout(cb, 300));

/* ---------- 데이터 로드 / 동기화 ---------- */
async function load(){
  const ab=document.getElementById("archBar"); if(ab) ab.remove();   // 활성 모드: 보관 바 제거
  try {
    const res = await fetch("data.php", { cache:"no-store" });
    const json = await res.json();
    if(json.error){
      document.getElementById("list").innerHTML = '<div class="err">에러: ' + esc(json.error) + '</div>';
      return;
    }
    DATA = json.rows || [];
    _mUsers = null;   // 멘션 후보 캐시 무효화(데이터 갱신)
    sortData();     // 고정 우선 정렬(서버 정렬과 동일하게 보정)
    detectAndNotify();   // 새 요청/새 댓글 브라우저 알림
    buildAllMS();   // 담당자 옵션 등 갱신(데이터 기반), 선택값 유지
    render();
    _idle(prefetchThumbs);   // 유휴 시간에 첨부 썸네일 미리 로드(캐시 워밍)
    document.getElementById("updated").textContent = "마지막 갱신: " + new Date().toLocaleTimeString("ko-KR");
  } catch(e){
    document.getElementById("list").innerHTML = '<div class="err">불러오기 실패</div>';
  }
}

/* 분석 도구 드롭다운 */
(function(){
  const dd=document.getElementById("analyzeDd");
  document.getElementById("analyzeBtn").addEventListener("click", e=>{ e.stopPropagation(); dd.classList.toggle("open"); });
  document.addEventListener("click", e=>{ if(!dd.contains(e.target)) dd.classList.remove("open"); });
})();

document.getElementById("sync").addEventListener("click", async ()=>{
  const btn = document.getElementById("sync"); btn.disabled = true; btn.classList.add("syncing"); btn.dataset.tip = "동기화 중…";
  try{
    const res = await fetch("sync.php", { cache:"no-store" });
    const j = await res.json();
    if(!j.ok) throw new Error(j.error || "동기화 실패");
    await load();
    if(j.skipped === 'locked'){
      alert("다른 동기화가 진행 중입니다. 잠시 후 자동 반영됩니다.");
    } else {
      let msg = `동기화 완료 (${j.mode==='full'?'전체':'증분'})\n스캔 ${j.scanned} · 변경 ${j.changed}건 (신규 ${j.inserted} · 갱신 ${j.updated} · 보존 ${j.skipped})`;
      if(j.errors && Object.keys(j.errors).length) msg += "\n⚠️ " + Object.keys(j.errors).map(k=>`${k}(${j.errors[k]})`).join(", ");
      alert(msg);
    }
  }catch(err){ alert("동기화 실패: " + err.message); }
  finally{ btn.disabled = false; btn.classList.remove("syncing"); btn.dataset.tip = "동기화"; }
});

document.getElementById("search").addEventListener("input",e=>{
  filter=e.target.value.toLowerCase().trim();
  document.getElementById("searchClear").hidden = !e.target.value;
  saveFilters(); openId=null; render();
});
document.getElementById("searchClear").addEventListener("click",()=>{
  const s=document.getElementById("search");
  s.value=""; filter=""; document.getElementById("searchClear").hidden=true;
  saveFilters(); openId=null; render(); s.focus();
});
document.getElementById("reset").addEventListener("click",()=>{
  FILT = { priority:emptyFilt(), team:emptyFilt(), status:emptyFilt(), asg:emptyFilt() };
  fboardTab = "all"; filter = "";
  document.getElementById("search").value = "";
  document.getElementById("searchClear").hidden = true;
  saveFilters();        // 비운 상태 저장
  buildAllMS();         // 버튼 카운트/체크 초기화
  openId = null; render();
});
document.getElementById("readSel").addEventListener("click",()=>bulkRead(1));
document.getElementById("unreadSel").addEventListener("click",()=>bulkRead(0));
document.getElementById("clearSel").addEventListener("click",()=>{ selected.clear(); render(); });
document.getElementById("toggleUn").addEventListener("click",()=>{
  splitOn = !splitOn;
  document.getElementById("unpanel").style.display = splitOn ? "" : "none";
  document.getElementById("toggleUn").textContent = splitOn ? "미지정 숨기기" : "미지정 리스트 표시";
  saveView();
  render();
});
document.getElementById("toggleHidden").addEventListener("click",()=>{
  showHidden = !showHidden;
  document.getElementById("toggleHidden").classList.toggle("primary", showHidden);
  saveView();
  openId = null; render();
});
/* ---------- 보관 보기 (archived, 300건씩 무한 스크롤) ---------- */
document.getElementById("toggleArch").addEventListener("click",()=>{
  archivedMode = !archivedMode;
  const b=document.getElementById("toggleArch");
  b.classList.toggle("primary", archivedMode);
  b.textContent = archivedMode ? "🗄 보관 닫기" : "🗄 보관 보기";
  document.getElementById("unpanel").style.display = (!archivedMode && splitOn) ? "" : "none";   // 보관 모드엔 미지정 분할 숨김
  saveView();   // 새로고침해도 유지
  buildAllMS();   // 필터 섹션 재구성(보관=유비온만)
  openId = null;
  if(archivedMode) loadArchived(0); else load();
});
async function loadArchived(page){
  if(archLoading) return;
  archLoading = true;
  archPage = Math.max(0, page||0);
  document.getElementById("list").innerHTML = "불러오는 중…";
  try{
    const all = (archPageSize === 'all');
    const limit = all ? 'all' : archPageSize;
    const offset = all ? 0 : archPage*archPageSize;
    const url = "data.php?archived=1&limit="+limit+"&offset="+offset;   // 보관은 전부 유비온 → 보드 구분 없음
    const j = await (await fetch(url,{cache:"no-store"})).json();
    DATA = j.rows||[];
    archTotal = (typeof j.total==='number') ? j.total : DATA.length;
    buildAllMS();   // 로드된 보관 데이터 기준으로 필터(담당팀 등) 재구성
    render();
    renderArchBar();
    window.scrollTo(0,0);
    _idle(prefetchThumbs);
  }catch(e){ document.getElementById("list").innerHTML='<div class="err">보관 목록 불러오기 실패</div>'; }
  finally{ archLoading=false; }
}
/* 보관 모드 페이지네이션/개수 선택 바 */
function renderArchBar(){
  let bar = document.getElementById("archBar");
  if(!archivedMode){ if(bar) bar.remove(); return; }
  if(!bar){
    bar=document.createElement("div"); bar.id="archBar"; bar.className="arch-bar";
    const head = document.getElementById("selAll").closest(".listhead");   // '전체 선택' 헤더 위로
    (head || document.getElementById("list")).before(bar);
  }
  const all = (archPageSize==='all');
  const pages = all ? 1 : Math.max(1, Math.ceil(archTotal/archPageSize));
  const cur = all ? 1 : Math.min(archPage+1, pages);
  const sizes=[100,200,300,500,1000,'all'];
  bar.innerHTML =
    `<span class="arch-total">보관 <b>${archTotal}</b>건</span>`+
    `<label class="arch-size">페이지당 <select id="archSize">`+
      sizes.map(s=>`<option value="${s}" ${String(s)===String(archPageSize)?'selected':''}>${s==='all'?'모두':s}</option>`).join("")+
    `</select></label>`+
    (all ? '' :
      `<span class="arch-pg"><button type="button" id="archPrev" ${archPage<=0?'disabled':''}>‹ 이전</button>`+
      `<span class="arch-cur">${cur} / ${pages}</span>`+
      `<button type="button" id="archNext" ${cur>=pages?'disabled':''}>다음 ›</button></span>`);
  document.getElementById("archSize").addEventListener("change", e=>{
    archPageSize = (e.target.value==='all') ? 'all' : (+e.target.value);
    saveView(); loadArchived(0);
  });
  const pv=document.getElementById("archPrev"), nx=document.getElementById("archNext");
  if(pv) pv.addEventListener("click", ()=>loadArchived(archPage-1));
  if(nx) nx.addEventListener("click", ()=>loadArchived(archPage+1));
}
document.getElementById("selAll").addEventListener("change", e=>{
  if(e.target.checked) filteredItems().forEach(r=>selected.add(r.id));  // 보이는 항목 전체 선택
  else selected.clear();
  render();
});

/* ---------- 백그라운드 동기화 감지 → 화면 리로드 없이 목록만 자동 갱신 ---------- */
let lastChanged = null;
async function pollStatus(){
  try{
    const s = await (await fetch("status.php", { cache:"no-store" })).json();
    if(lastChanged === null){ lastChanged = s.changed_at; return; }   // 최초 호출은 기준값만
    if(s.changed_at > lastChanged){                                   // 변경된 동기화 완료 감지
      if(openId || archivedMode){ return; }                           // 상세 펼침/보관 모드에선 보류
      lastChanged = s.changed_at;
      const y = window.scrollY;                                       // 자동 갱신 시 스크롤 위치 보존(화면 안 튐)
      await load();                                                   // DOM만 조용히 갱신(페이지 리로드 X)
      window.scrollTo(0, y);                                          // 원래 위치로 복원
    }
  }catch(e){ /* 무시하고 다음 폴링 */ }
}
setInterval(pollStatus, 5000);   // 5초마다 가볍게 상태 확인(DB 1행 조회)

/* ---------- 백그라운드 동기화 (JS fetch → 콘솔창 없음) ---------- */
async function bgSync(){
  try{ await fetch("sync.php", { cache:"no-store" }); }catch(e){}
  // 결과 반영은 pollStatus 가 changed_at 변화를 감지해 자동 처리
}
setInterval(bgSync, 60000);   // 60초마다 백그라운드 동기화

/* ===== 이미지 라이트박스(모달 슬라이드) ===== */
document.body.insertAdjacentHTML("beforeend", `
  <div id="lightbox" role="dialog" aria-modal="true">
    <button id="lb-close" title="닫기 (Esc)">✕</button>
    <button class="lb-btn" id="lb-prev" title="이전 (←)">‹</button>
    <button class="lb-btn" id="lb-next" title="다음 (→)">›</button>
    <div class="lb-stage">
      <img id="lb-img" src="" alt="">
      <video id="lb-video" controls playsinline preload="metadata" style="display:none"></video>
      <iframe id="lb-frame" title="문서 미리보기" style="display:none"></iframe>
      <div id="lb-sheet" style="display:none"></div>
      <div class="lb-bar"><span id="lb-count"></span><span id="lb-name"></span>
        <span class="lb-zoom">
          <button id="lb-zout" type="button" title="축소 (−)">−</button>
          <span id="lb-zval">100%</span>
          <button id="lb-zin" type="button" title="확대 (+)">＋</button>
          <button id="lb-zreset" type="button" title="원본 크기 (0)">⤢</button>
        </span>
        <a id="lb-dl" href="#" title="원본 다운로드">⬇ 다운로드</a></div>
    </div>
  </div>`);
let lbImgs=[], lbIdx=0;
const _lb=()=>document.getElementById("lightbox");
function lbOpen(imgs, idx){ lbImgs=imgs||[]; lbIdx=idx||0; if(!lbImgs.length) return; lbRender(); _lb().classList.add("open"); }
function lbRender(){
  const c=lbImgs[lbIdx]; if(!c) return;
  const img=document.getElementById("lb-img"), vid=document.getElementById("lb-video");
  const frm=document.getElementById("lb-frame"), sht=document.getElementById("lb-sheet");
  const t=c.type||"image";
  const isVid=t==="video", isPdf=t==="pdf", isSheet=t==="sheet", isImg=!isVid&&!isPdf&&!isSheet;
  img.style.display=isImg?"":"none";
  vid.style.display=isVid?"":"none";
  frm.style.display=isPdf?"":"none";
  sht.style.display=isSheet?"":"none";
  const zoom=document.querySelector("#lightbox .lb-zoom"); if(zoom) zoom.style.display=isImg?"":"none";
  // 이전 컨텐츠 정리
  if(!isVid){ vid.pause?.(); vid.removeAttribute("src"); vid.load?.(); }
  if(!isPdf) frm.removeAttribute("src");
  if(!isSheet) sht.innerHTML="";
  if(isVid){ vid.src=c.src||""; vid.play?.().catch(()=>{}); }        // 자동재생(권한 없으면 컨트롤로 재생)
  else if(isPdf){ frm.src=c.src||""; }                              // 브라우저 내장 PDF 뷰어
  else if(isSheet){ sht.innerHTML='<div class="lb-sheet-load">불러오는 중…</div>'; renderSheet(sht, c.src, c.name); }
  else { img.src=c.full; img.alt=c.name||""; }
  document.getElementById("lb-count").textContent = lbImgs.length>1 ? ((lbIdx+1)+" / "+lbImgs.length) : "";
  document.getElementById("lb-name").textContent = c.name||"";
  document.getElementById("lb-dl").href = c.dl||c.full||c.src;
  const multi=lbImgs.length>1;
  document.getElementById("lb-prev").style.display = multi?"":"none";
  document.getElementById("lb-next").style.display = multi?"":"none";
  if(isImg) lbZoomReset();   // 새 이미지로 바뀌면 확대 상태 초기화
}
/* ---- 엑셀(SheetJS) 로컬 번들 지연 로드 + 표 렌더 ---- */
let _xlsxP=null;
function loadXLSX(){
  if(window.XLSX) return Promise.resolve(window.XLSX);
  if(_xlsxP) return _xlsxP;
  _xlsxP=new Promise((res,rej)=>{
    const s=document.createElement("script");
    s.src="vendor/xlsx.full.min.js";
    s.onload=()=>res(window.XLSX); s.onerror=()=>rej(new Error("xlsx load fail"));
    document.head.appendChild(s);
  });
  return _xlsxP;
}
async function renderSheet(box, url, name){
  try{
    const XLSX=await loadXLSX();
    const buf=await (await fetch(url,{cache:"force-cache"})).arrayBuffer();
    const wb=XLSX.read(buf,{type:"array"});
    const names=wb.SheetNames; let cur=0;
    const draw=()=>{
      const html=XLSX.utils.sheet_to_html(wb.Sheets[names[cur]], {editable:false, header:"", footer:""});
      const tabs = names.length>1
        ? `<div class="lb-sheet-tabs">${names.map((n,i)=>`<button class="${i===cur?'on':''}" data-i="${i}">${esc(n)}</button>`).join("")}</div>` : "";
      box.innerHTML = tabs + `<div class="lb-sheet-body">${html}</div>`;
      box.querySelectorAll(".lb-sheet-tabs button").forEach(b=>b.addEventListener("click",()=>{ cur=+b.dataset.i; draw(); }));
    };
    draw();
  }catch(e){
    box.innerHTML='<div class="lb-sheet-load">엑셀을 표시할 수 없습니다. 아래 다운로드를 이용하세요.</div>';
  }
}
function lbNav(d){ if(lbImgs.length<2) return; lbIdx=(lbIdx+d+lbImgs.length)%lbImgs.length; lbRender(); }
function lbClose(){ const v=document.getElementById("lb-video"); if(v){ v.pause?.(); v.removeAttribute("src"); v.load?.(); }
  const f=document.getElementById("lb-frame"); if(f) f.removeAttribute("src");
  const s=document.getElementById("lb-sheet"); if(s) s.innerHTML="";
  _lb().classList.remove("open"); }
document.getElementById("lb-prev").addEventListener("click", e=>{ e.stopPropagation(); lbNav(-1); });
document.getElementById("lb-next").addEventListener("click", e=>{ e.stopPropagation(); lbNav(1); });
document.getElementById("lb-close").addEventListener("click", e=>{ e.stopPropagation(); lbClose(); });
document.getElementById("lb-dl").addEventListener("click", e=>e.stopPropagation());
document.getElementById("lb-img").addEventListener("click", e=>e.stopPropagation());
_lb().addEventListener("click", e=>{ if(e.target.id==="lightbox") lbClose(); });

/* ---- 라이트박스 확대/축소 + 패닝 ---- */
let lbScale=1, lbTx=0, lbTy=0, lbDrag=null;
const LB_MIN=1, LB_MAX=8;
function lbImg(){ return document.getElementById("lb-img"); }
function lbApply(){
  const img=lbImg();
  img.style.transform = `translate(${lbTx}px, ${lbTy}px) scale(${lbScale})`;
  img.classList.toggle("zoomed", lbScale>1);
  const zv=document.getElementById("lb-zval"); if(zv) zv.textContent = Math.round(lbScale*100)+"%";
}
function lbZoomReset(){ lbScale=1; lbTx=0; lbTy=0; lbApply(); }
function lbZoomAt(newScale, cx, cy){                 // 커서(cx,cy) 지점을 기준으로 확대/축소
  newScale = Math.min(LB_MAX, Math.max(LB_MIN, newScale));
  const rect=lbImg().getBoundingClientRect();
  const layoutCX=rect.left+rect.width/2-lbTx, layoutCY=rect.top+rect.height/2-lbTy;   // 변형 전 중심
  const relX=cx-layoutCX, relY=cy-layoutCY, k=newScale/lbScale;
  lbTx = relX - (relX - lbTx)*k;
  lbTy = relY - (relY - lbTy)*k;
  lbScale = newScale;
  if(lbScale<=LB_MIN){ lbTx=0; lbTy=0; }             // 100% 로 돌아오면 위치도 초기화
  lbApply();
}
function lbZoomStep(factor){                          // 버튼/키: 화면 중앙 기준
  const rect=lbImg().getBoundingClientRect();
  lbZoomAt(lbScale*factor, rect.left+rect.width/2, rect.top+rect.height/2);
}
// 마우스 휠 확대/축소(커서 기준)
lbImg().addEventListener("wheel", e=>{
  e.preventDefault(); e.stopPropagation();
  lbZoomAt(lbScale*(e.deltaY<0 ? 1.15 : 1/1.15), e.clientX, e.clientY);
}, {passive:false});
// 더블클릭: 1x ↔ 2x 토글
lbImg().addEventListener("dblclick", e=>{
  e.preventDefault(); e.stopPropagation();
  if(lbScale>1) lbZoomReset(); else lbZoomAt(2, e.clientX, e.clientY);
});
// 확대 상태에서 드래그로 이동(pan)
lbImg().addEventListener("mousedown", e=>{
  if(lbScale<=1) return;
  e.preventDefault();
  lbDrag={x:e.clientX, y:e.clientY, tx:lbTx, ty:lbTy};
  lbImg().classList.add("dragging");
});
window.addEventListener("mousemove", e=>{
  if(!lbDrag) return;
  lbTx=lbDrag.tx+(e.clientX-lbDrag.x); lbTy=lbDrag.ty+(e.clientY-lbDrag.y); lbApply();
});
window.addEventListener("mouseup", ()=>{ if(lbDrag){ lbDrag=null; lbImg().classList.remove("dragging"); } });
// 줌 버튼
document.getElementById("lb-zin").addEventListener("click", e=>{ e.stopPropagation(); lbZoomStep(1.25); });
document.getElementById("lb-zout").addEventListener("click", e=>{ e.stopPropagation(); lbZoomStep(1/1.25); });
document.getElementById("lb-zreset").addEventListener("click", e=>{ e.stopPropagation(); lbZoomReset(); });

document.addEventListener("keydown", e=>{
  if(!_lb().classList.contains("open")) return;
  if(e.key==="Escape") lbClose();
  else if(e.key==="ArrowLeft") lbNav(-1);
  else if(e.key==="ArrowRight") lbNav(1);
  else if(e.key==="+"||e.key==="=") { e.preventDefault(); lbZoomStep(1.25); }
  else if(e.key==="-"||e.key==="_") { e.preventDefault(); lbZoomStep(1/1.25); }
  else if(e.key==="0") lbZoomReset();
});

/* ===== 학교 사이트 검색 모달 ===== */
let smVer = "all";
function smBuildVers(){
  const uniq = [...new Set((SCHOOLS||[]).map(s=>s.ver).filter(Boolean))].sort((a,b)=>parseFloat(a)-parseFloat(b));
  const vers = [["all","전체"], ...uniq.map(v=>[v,v])];
  const box = document.getElementById("smVers");
  box.innerHTML = vers.map(([v,l])=>`<button type="button" class="sm-chip${v===smVer?' on':''}" data-v="${v}">${l}</button>`).join("");
  box.querySelectorAll(".sm-chip").forEach(b=>b.addEventListener("click", ()=>{ smVer=b.dataset.v; smBuildVers(); smRender(); }));
}
async function smLoad(force){
  if(SCHOOLS && !force) return;
  try{ SCHOOLS = (await (await fetch("schools.php", {cache:"no-store"})).json()).rows || []; }
  catch(e){ SCHOOLS = []; }
}
function smRender(){
  if(!SCHOOLS){ return; }
  const q = document.getElementById("smSearch").value.trim().toLowerCase();
  const list = SCHOOLS.filter(s => (smVer==="all" || s.ver===smVer) && (!q || s.name.toLowerCase().includes(q)));
  document.getElementById("smCount").textContent = list.length + "개";
  const box = document.getElementById("smList");
  if(!list.length){ box.innerHTML = '<div class="sm-empty">검색 결과가 없습니다.</div>'; return; }
  box.innerHTML = list.map(s=>`
    <div class="sm-row">
      <div class="sm-name">${esc(s.name)}<span class="sm-ver">${esc(s.ver)}</span></div>
      <div class="sm-btns">
        ${s.dev?`<a class="sm-link dev" href="${escAttr(s.dev)}" target="_blank" rel="noopener">개발</a>`:`<span class="sm-link off">개발</span>`}
        ${s.ops?`<a class="sm-link ops" href="${escAttr(s.ops)}" target="_blank" rel="noopener">운영</a>`:`<span class="sm-link off">운영</span>`}
      </div>
    </div>`).join("");
}
async function smOpen(){
  document.getElementById("schoolModal").hidden=false;
  document.getElementById("smList").innerHTML='<div class="sm-empty">불러오는 중…</div>';
  await smLoad(true);          // 모달 열 때마다 최신 DB 반영
  smBuildVers();               // 로드된 데이터 기준으로 버전 칩 생성(2.9/3.2/3.5/3.9/4.5)
  smRender();
  const i=document.getElementById("smSearch"); i.focus(); i.select();
}
function smClose(){ document.getElementById("schoolModal").hidden=true; }
document.getElementById("schoolBtn").addEventListener("click", smOpen);
document.getElementById("smClose").addEventListener("click", smClose);
document.getElementById("schoolModal").addEventListener("click", e=>{ if(e.target.id==="schoolModal") smClose(); });
document.getElementById("smSearch").addEventListener("input", smRender);
document.addEventListener("keydown", e=>{ if(e.key==="Escape" && !document.getElementById("schoolModal").hidden) smClose(); });

restoreFilters();  // 새로고침 전 필터 복원 (localStorage)
document.getElementById("searchClear").hidden = !document.getElementById("search").value;   // 복원된 검색어 있으면 ✕ 표시
restoreView();     // 미지정 분할/숨김 보기 토글 상태 복원
buildAllMS();      // 헤더 다중선택 필터 생성 — 복원된 선택값 반영
if(archivedMode) loadArchived(0); else load();   // 보관 보기 상태면 보관 목록으로 시작
pollStatus();          // 기준값 즉시 설정
bgSync().then(()=>{ if(!archivedMode) load(); });   // 진입 즉시 동기화 → 활성 모드에서만 재로드
</script>
</body>
</html>
