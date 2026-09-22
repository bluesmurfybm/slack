<?php
/**
 * 로그인 여부를 서버에서 먼저 판단해 화면을 그린다.
 *  - 클라이언트에서 /api/me.php 를 fetch 해서 뒤늦게 전환하면, 이미 로그인된 사용자에게도
 *    로그인 화면이 한 번 번쩍였다가 대시보드로 바뀌는 깜빡임이 생긴다. 그걸 없애기 위해
 *    PHP가 세션을 먼저 확인하고 처음부터 올바른 화면 상태로 HTML을 내려준다.
 */
require_once __DIR__ . '/core/auth.php';
// 로그인 상태에 따라 매번 다르게 그려지는 페이지라 브라우저/중간 캐시에 절대 남으면 안 됨.
// 캐시된 옛 버전이 남아 있으면 "저장 후 대시보드로 안 돌아간다"/"그리드가 가끔 안 보인다" 처럼
// 예전 코드가 실행되는 것처럼 보이는 현상이 생긴다.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$__u = current_portal_user();
$__current = $__u ? [
    'name'        => $__u['name'],
    'email'       => $__u['email'],
    'has_token'   => !empty($__u['slack_token_enc']),
    'needs_setup' => needs_setup($__u),
    'color'       => user_color($__u),
] : null;
require_once __DIR__ . '/core/worksystems.php';
require_once __DIR__ . '/core/board.php';
// 공지·일정을 등록할 수 있는 사람인지 서버가 먼저 판정한다. 화면을 그린 뒤
// 자바스크립트가 버튼을 붙였다 떼면 한 번 번쩍인다.
$__isAdmin = $__u ? board_is_admin($__u['email']) : false;
// 화면에 적는 첨부 한계는 php.ini 를 반영한 실제 값이어야 한다.
// 20MB 라 적어 놓고 2MB 에서 막히면 "왜 안 되지" 가 된다.
$__uploadMax = board_max_upload_label();
// 날씨는 브라우저가 Open-Meteo 에서 직접 받는다. 서버는 좌표만 알려 준다.
$__weather = board_office_weather();
// 배경 설정은 서버가 먼저 내려 줘야 화면이 한 번 번쩍이지 않는다.
$__bgPref  = board_bg_pref($__u);
// 대시보드 타일도 상단바 드롭다운과 같은 목록(worksystems.php)을 쓴다 — 한쪽만 늘어나는 일이 없게.
// 예전에는 url 만 뽑아 넘겼는데, 정작 타일 일곱 개가 손으로 적혀 있어서 json 에
// 시스템을 더해도 드롭다운에만 생기고 타일은 안 생겼다. 목록을 통째로 넘겨
// 타일도 이 목록에서 그린다 — 이제 json 만 고치면 양쪽이 같이 늘어난다.
$__systems = work_systems();
// 모듈이 미로그인 사용자를 되돌려보낼 때 ?need_login=<key> 를 붙인다 — 왜 튕겼는지 알려줘야 한다.
$__notice = need_login_notice(isset($_GET['need_login']) ? (string)$_GET['need_login'] : null);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>blue-iWorks</title>
<link rel="icon" href="styles/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
<link rel="stylesheet" href="styles/topbar.css">
<link rel="stylesheet" href="styles/default.css">
<link rel="stylesheet" href="styles/chatbot.css">
<link rel="stylesheet" href="styles/board.css">
<link rel="stylesheet" href="styles/wxfx.css">
</head>
<body data-bg="<?= htmlspecialchars($__bgPref, ENT_QUOTES, 'UTF-8') ?>">

<!-- 날씨에 따라 움직이는 배경. body 뒤(z-index:-1)라 글자 위로는 아무것도
     지나가지 않는다. 어느 층을 켤지는 body[data-wx] 가 정하고(styles/wxfx.css),
     껍데기는 늘 여기 있다 — 날씨가 바뀔 때 DOM 을 다시 만들지 않는다. -->
<div class="wx-fx" aria-hidden="true">
  <i class="s1"></i><i class="s2"></i><i class="s3"></i>
  <i class="r1"></i><i class="r2"></i><i class="d1"></i><i class="d2"></i>
  <i class="p1 band"></i><i class="p2 band"></i>
  <i class="c1 band"></i><i class="c2 band"></i><i class="c3 band"></i>
  <i class="f1 band"></i><i class="f2 band"></i>
  <i class="m1"></i><i class="st"></i><i class="st2"></i>
  <u class="sun"></u><u class="rays"></u><u class="moon"></u>
  <u class="fl"></u><u class="b1"></u><u class="b2"></u>
</div>

<!-- ================= LOGIN ================= -->
<section id="login" class="<?= $__current ? 'hidden' : '' ?>">
  <div class="login-x">X</div>
  <div class="login-card">
    <div class="logo"><b>blue</b><span class="dash">-</span>iWorks</div>
    <div class="login-tag">Bluesoft e<em>X</em>perience · 사내 업무 포털</div>
    <div class="fld">
      <label>이메일</label>
      <input id="lg-email" type="email" placeholder="name@bluesoft.co.kr" autocomplete="username">
    </div>
    <div class="fld">
      <label>비밀번호</label>
      <input id="lg-pw" type="password" placeholder="비밀번호" autocomplete="current-password"
        onkeydown="if(event.key==='Enter')doLogin()">
    </div>
    <div class="login-opts">
      <label><input id="lg-save-id" type="checkbox"> 아이디 저장</label>
      <label><input id="lg-remember" type="checkbox"> 자동 로그인</label>
    </div>
    <button class="btn-primary" onclick="doLogin()">로그인</button>
  </div>
</section>

<!-- ================= APP (dashboard + profile) ================= -->
<div id="app" class="<?= $__current ? '' : 'hidden' ?>">
  <div class="topbar">
    <div class="topbar-in">
      <div class="logo" style="cursor:pointer" onclick="showDash()"><b>blue</b><span class="dash">-</span>iWorks</div>
      <div class="top-right">
<?php if ($__current): ?>
        <!-- 대시보드 배경을 날씨에 맞출지 고르는 단추. 고른 값은 계정에 남는다. -->
        <button type="button" class="bg-btn" id="bgBtn" onclick="toggleBg()"
                title="대시보드 배경" aria-label="대시보드 배경 바꾸기"></button>
<?php endif; ?>
        <div class="user-menu" id="userMenu">
          <div class="user-chip" onclick="toggleUserMenu(event)" title="메뉴">
            <span class="avatar" id="tb-avatar"></span>
            <span class="nm" id="tb-name"></span>
            <span class="user-caret">▾</span>
          </div>
          <div class="dd-menu" id="userDd">
            <a href="javascript:void(0)" onclick="closeUserMenu();showProfile()">👤 마이페이지</a>
            <a href="javascript:void(0)" onclick="closeUserMenu();showNotices(1)">📢 공지사항</a>
<?php if ($__isAdmin): ?>
            <a href="javascript:void(0)" onclick="closeUserMenu();showManage('events')">⚙️ 포털 관리</a>
<?php endif; ?>
            <div class="dd-sep"></div>
            <?= work_systems_menu('', '', true) ?>
            <div class="dd-sep"></div>
            <a href="javascript:void(0)" onclick="closeUserMenu();logout()">🚪 로그아웃</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- dashboard -->
  <div id="view-dash" class="wrap">
    <div class="hero">
      <h1 id="hero-hi">환영합니다</h1>
    </div>

    <!-- 알림판 — 타일보다 위. 공지와 다가오는 일정은 들어오자마자 봐야 하는 것들이다. -->
    <div class="board">
      <section class="panel">
        <div class="panel-head">
          <h2>Notice</h2>
          <span class="slide-nav no-more" id="nt-nav">
            <button type="button" data-dir="-1" title="이전 공지" aria-label="이전 공지"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 15 6-6 6 6"/></svg></button>
            <span class="pos" aria-live="off"></span>
            <button type="button" data-dir="1" title="다음 공지" aria-label="다음 공지"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
          </span>
          <span class="sp"></span>
<?php if ($__isAdmin): ?>
          <button class="panel-act add" onclick="openNoticeDrawer(0)">+ 새 공지</button>
<?php endif; ?>
          <button class="panel-act" onclick="showNotices(1)">전체 보기 →</button>
        </div>
        <div class="panel-body">
          <div class="slide-win" id="board-notices">
            <div class="slide-track"><div class="panel-empty">불러오는 중…</div></div>
          </div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <h2>Schedule</h2>
          <span class="slide-nav no-more" id="ev-nav">
            <button type="button" data-dir="-1" title="이전 일정" aria-label="이전 일정"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 15 6-6 6 6"/></svg></button>
            <span class="pos" aria-live="off"></span>
            <button type="button" data-dir="1" title="다음 일정" aria-label="다음 일정"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
          </span>
          <span class="sp"></span>
<?php if ($__isAdmin): ?>
          <button class="panel-act add" onclick="openEventModal(0)">+ 등록</button>
          <button class="panel-act" onclick="showManage('events')">관리</button>
<?php endif; ?>
        </div>
        <div class="panel-body" id="board-events">
          <div class="panel-empty">불러오는 중…</div>
        </div>
      </section>

      <!-- 날씨. 제목 줄 없이 그림과 숫자만 두어 위젯처럼 보이게 한다. -->
      <aside class="wx" id="wx" aria-label="사무실 날씨">
        <div class="wx-load">…</div>
      </aside>
    </div>

    <!-- 알림판과 타일은 하는 일이 다르다(읽는 곳 / 가는 곳). 구역 이름과
         가는 선 하나로 경계를 준다 — 색을 더 쓰면 화면이 시끄러워진다. -->
    <div class="sec">
      <h2>업무 시스템</h2>
      <span class="sec-hint">사용할 시스템을 선택하세요</span>
    </div>
    <div class="grid" id="tiles"></div>
  </div>

  <!-- 공지 목록 -->
  <div id="view-notices" class="wrap hidden">
    <div class="page-head">
      <h1>공지사항</h1>
      <span style="flex:1"></span>
<?php if ($__isAdmin): ?>
      <button class="btn-sm" onclick="openNoticeDrawer(0)">+ 새 공지</button>
<?php endif; ?>
      <button class="btn-sm" onclick="showDash()">대시보드</button>
    </div>
    <div class="list-card" id="nl-list"></div>
    <div class="pager" id="nl-pager"></div>
  </div>

  <!-- 포털 관리 -->
  <div id="view-manage" class="wrap hidden">
    <div class="page-head">
      <h1>포털 관리</h1>
      <span style="flex:1"></span>
      <button class="btn-sm" onclick="showDash()">대시보드</button>
    </div>
    <div class="tabs" role="tablist">
      <button role="tab" data-mtab="events"   aria-selected="true"  onclick="showManage('events')">중요 일정</button>
      <button role="tab" data-mtab="notices"  aria-selected="false" onclick="showManage('notices')">공지</button>
      <button role="tab" data-mtab="admins"   aria-selected="false" onclick="showManage('admins')">관리자</button>
    </div>
    <div id="mg-body"></div>
  </div>

  <!-- 공지 상세 — 가운데 팝업. 읽기만 하는 화면이라 굳이 화면을 옮기지 않는다. -->
  <div class="modal modal--center hidden" id="nd-modal">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="nd-modal-title">
      <div class="modal-head">
        <h3 id="nd-modal-title">공지사항</h3>
        <button class="modal-x" onclick="closeNoticeModal()" aria-label="닫기">&times;</button>
      </div>
      <div class="modal-body">
        <div class="detail-card" id="nd-card"></div>
      </div>
      <div class="modal-foot">
        <span class="left" id="nd-admin-btns"></span>
        <button class="btn-sm" onclick="closeNoticeModal();showNotices(1)">전체보기 페이지로 이동</button>
        <button class="btn-primary" style="width:auto;padding:9px 20px;margin:0" onclick="closeNoticeModal()">닫기</button>
      </div>
    </div>
  </div>

  <!-- 공지 작성 / 수정 — 오른쪽 드로어 -->
  <div class="modal modal--right hidden" id="ne-modal">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="ne-title">
      <div class="modal-head">
        <h3 id="ne-title">새 공지</h3>
        <button class="modal-x" onclick="closeNoticeDrawer()" aria-label="닫기">&times;</button>
      </div>
      <div class="modal-body">
        <div class="err hidden" id="ne-err"></div>
        <div class="edit-card" style="border:0;padding:0;max-width:none">
          <div class="fld">
            <label>제목</label>
            <input type="text" id="ne-subject" maxlength="200" placeholder="예) 9월 전사 워크숍 안내">
          </div>
          <div class="fld">
            <label>내용</label>
            <textarea id="ne-body" maxlength="20000" placeholder="쓴 그대로 보입니다. 줄바꿈은 살아 있고, 주소는 자동으로 링크가 됩니다."></textarea>
          </div>
          <div class="two">
            <div class="fld">
              <label>노출 시작일 <span style="font-weight:400">· 비우면 바로</span></label>
              <input type="date" id="ne-from">
            </div>
            <div class="fld">
              <label>노출 종료일 <span style="font-weight:400">· 비우면 계속</span></label>
              <input type="date" id="ne-to">
            </div>
          </div>
          <div class="fld">
            <label>첨부파일</label>
            <input type="file" id="ne-files" multiple>
            <div class="hintline">한 건에 10개, 파일당 <?= htmlspecialchars($__uploadMax, ENT_QUOTES, 'UTF-8') ?> 까지. 이미지·PDF·문서·압축파일만 올라갑니다.</div>
            <div class="files" id="ne-filelist" style="border:0;padding:0;margin-top:10px"></div>
          </div>
          <div class="fld">
            <label class="check"><input type="checkbox" id="ne-important"> 중요 공지</label>
            <div class="hintline">목록에서 제목 앞에 중요 표시가 붙습니다. 순서는 바뀌지 않습니다.</div>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn-ghost" onclick="closeNoticeDrawer()">취소</button>
        <button class="btn-primary" id="ne-save" style="width:auto;padding:10px 20px;margin:0" onclick="saveNotice()">저장</button>
      </div>
    </div>
  </div>

  <!-- 일정 분류 낱말 설정 — 오른쪽 드로어 -->
  <div class="modal modal--right hidden" id="kw-modal">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="kw-title">
      <div class="modal-head">
        <h3 id="kw-title">일정 분류 설정</h3>
        <button class="modal-x" onclick="closeKwModal()" aria-label="닫기">&times;</button>
      </div>
      <div class="modal-body">
        <div class="err hidden" id="kw-err"></div>

        <p class="kw-help">
          일정 이름에 아래 낱말이 있으면 그 종류로 분류되어 아이콘과 색이 붙습니다.
          <b>쉼표로 나눠</b> 적으세요. 위에 있는 종류가 먼저 걸립니다 —
          조사가 맨 위라야 ‘부친상’ 에 축하 색이 붙지 않습니다.<br>
          낱말 뒤 빈칸이 뜻을 가질 때는 <code>"축 "</code> 처럼 따옴표로 감싸세요.
          그래야 ‘축구 대회’ 가 경사로 걸리지 않습니다.
        </p>
        <div id="kw-list"></div>
      </div>
      <div class="modal-foot">
        <button class="btn-ghost" onclick="closeKwModal()">취소</button>
        <button class="btn-primary" id="kw-save" style="width:auto;padding:10px 20px;margin:0" onclick="saveKwWords()">저장</button>
      </div>
    </div>
  </div>

  <!-- 일정 등록 / 수정 — 오른쪽 드로어 -->
  <div class="modal modal--right hidden" id="ev-modal">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="ev-title">
      <div class="modal-head">
        <h3 id="ev-title">일정 등록</h3>
        <button class="modal-x" onclick="closeEventModal()" aria-label="닫기">&times;</button>
      </div>
      <div class="modal-body">
        <div class="err hidden" id="ev-err"></div>
        <div class="edit-card" style="border:0;padding:0;max-width:none">
          <div class="fld">
            <label>일정 이름</label>
            <input type="text" id="ev-name" maxlength="200" placeholder="예) 전사 워크숍">
          </div>
          <div class="two">
            <div class="fld">
              <label>시작일 (D-day 기준)</label>
              <input type="date" id="ev-start">
            </div>
            <div class="fld">
              <label>종료일 <span style="font-weight:400">· 하루짜리면 비워 두세요</span></label>
              <input type="date" id="ev-end">
            </div>
          </div>
          <div class="fld">
            <label>장소</label>
            <input type="text" id="ev-place" maxlength="120" placeholder="예) 본사 대회의실">
          </div>
          <div class="fld">
            <label>메모</label>
            <input type="text" id="ev-memo" maxlength="500" placeholder="한 줄 설명 (선택)">
          </div>

          <h4 class="kw-sec" style="margin-top:20px">축하 폭죽</h4>
          <div class="fld kw-opt">
            <label for="ev-cheer">그날 들어오면 폭죽 쏘기</label>
            <select id="ev-cheer" onchange="syncEvCheer()">
              <option value="0">아니오</option>
              <option value="1">예</option>
            </select>
            <span class="kw-note">
              일정 이름이 함께 뜹니다. 경사뿐 아니라 공휴일·회식에도 쓸 수 있고,
              문구는 종류에 따라 달라집니다. 조사에는 켜도 터지지 않습니다.
            </span>
          </div>
          <div class="fld kw-opt" id="ev-early-row">
            <label for="ev-cheer-early">주말·공휴일이면 미리 축하</label>
            <select id="ev-cheer-early">
              <option value="1">예</option>
              <option value="0">아니오</option>
            </select>
            <span class="kw-note">
              토요일 일정은 금요일에 터집니다. ‘아니오’ 면 당일에만 터집니다.
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn-ghost" onclick="closeEventModal()">취소</button>
        <button class="btn-primary" id="ev-save" style="width:auto;padding:10px 20px;margin:0" onclick="saveEvent()">저장</button>
      </div>
    </div>
  </div>

  <!-- profile -->
  <div id="view-profile" class="wrap hidden">
    <div class="page-head">
      <h1>마이페이지</h1>
    </div>
    <div class="form-card">
      <div class="fld">
        <label>이름</label>
        <input id="pf-name" type="text" disabled>
      </div>
      <div class="fld">
        <label>이메일</label>
        <input id="pf-email" type="email" disabled>
      </div>
      <div class="fld">
        <label>고유 색상</label>
        <div class="color-row">
          <input id="pf-color" type="color" value="#1C5DE5" oninput="buildSwatches(this.value)" disabled>
          <div class="swatches" id="pf-swatches"></div>
        </div>
        <div class="hintline">아바타·뱃지에 표시되는 색상입니다.</div>
      </div>
      <div class="fld">
        <label>새 비밀번호</label>
        <div class="pw-wrap">
          <input id="pf-pw" type="password" placeholder="변경하려면 입력 (비우면 유지)" disabled>
          <button class="eye" onclick="toggleEye('pf-pw',this)" title="표시/숨김"></button>
        </div>
        <div class="hintline">초기 비밀번호는 blue$123 입니다.</div>
      </div>

      <div class="token-note">
        🔒 <b>슬랙 연동 토큰</b>은 민감정보입니다. 본인만 입력·수정하며, 저장 후에는 화면에 원문이 다시 표시되지 않습니다.
        토큰이 유출되면 즉시 재발급하세요.
      </div>
      <div class="fld">
        <label>슬랙 연동 토큰</label>
        <div class="pw-wrap">
          <input id="pf-token" type="password" placeholder="xoxp-... (본인 토큰 붙여넣기)" disabled>
          <button class="eye" onclick="toggleEye('pf-token',this)" title="표시/숨김"></button>
        </div>
      </div>

      <div class="form-actions">
        <button class="btn-primary" id="pf-editbtn" style="width:auto;padding:11px 22px;margin:0" onclick="startEditProfile()">수정</button>
        <button class="btn-ghost hidden" id="pf-cancel" onclick="cancelEditProfile()">취소</button>
        <button class="btn-primary hidden" id="pf-save" style="width:auto;padding:11px 22px;margin:0" onclick="saveProfile()">저장</button>
      </div>
    </div>
  </div>

  <!-- chatbot -->
  <div id="chat-panel" class="chat-panel hidden">
    <div class="chat-head">
      <span class="bot-av" id="chat-botav"></span>
      <div class="tt">
        <h3>blue chatbot</h3>
        <div class="st">데모 챗봇입니다. 무엇이든 물어보세요</div>
      </div>
      <button class="chat-x" onclick="closeChat()" title="닫기">&times;</button>
    </div>
    <div class="chat-log" id="chat-log"></div>
    <div class="chat-form">
      <textarea id="chat-input" rows="1" placeholder="메시지를 입력하세요"
        onkeydown="chatKeydown(event)" oninput="chatGrow(this)"></textarea>
      <button class="chat-send" id="chat-send" onclick="sendChat()" title="보내기"></button>
    </div>
  </div>
  <button id="chat-fab" class="chat-fab hidden" onclick="toggleChat()" title="챗봇"></button>
</div>

<div class="toast" id="toast"></div>

<script>
/* ===== 타일 링크 ===== */
/* config.php 의 links 설정을 그대로 씀(서버가 단일 소스) */
const SYSTEMS = <?= json_encode($__systems, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

/* 모듈에서 미로그인으로 튕겨 온 경우에만 채워진다(?need_login=<key>) */
const NOTICE = <?= json_encode($__notice, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

/* 공지·일정을 등록할 수 있는 사람인가. 화면을 숨기는 용도일 뿐이고,
   실제 차단은 api/*.php 가 board_require_admin() 으로 다시 한다. */
const IS_ADMIN = <?= $__isAdmin ? 'true' : 'false' ?>;

/* 사무실 좌표. 자료는 브라우저가 Open-Meteo 에서 직접 받는다 —
   서버가 밖으로 못 나가는 곳에서도 날씨가 뜨게 하려는 것이다. */
const OFFICE = <?= json_encode($__weather, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

let current = <?= $__current ? json_encode($__current, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) : 'null' ?>; // {name, email, has_token, needs_setup}

function avatarColor(name){
  let h=0; for(const ch of (name||"?")) h=(h*31+ch.charCodeAt(0))%360;
  return `hsl(${h},58%,52%)`;
}
const esc=s=>(s||"").replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));

/* ---- 아이디 저장: 저장된 이메일 있으면 로그인 폼에 미리 채워둠 ---- */
const SAVED_EMAIL_KEY="blueiwork_saved_email";
(function(){
  const saved=localStorage.getItem(SAVED_EMAIL_KEY);
  if(saved){
    document.getElementById("lg-email").value=saved;
    document.getElementById("lg-save-id").checked=true;
  }
})();

/* ---- login ---- */
async function doLogin(){
  const email=document.getElementById("lg-email").value.trim();
  const pw=document.getElementById("lg-pw").value;
  const saveId=document.getElementById("lg-save-id").checked;
  const remember=document.getElementById("lg-remember").checked;
  try{
    const r=await fetch("api/login.php",{method:"POST",headers:{"Content-Type":"application/json"},
      body:JSON.stringify({email,password:pw,remember})});
    if(!r.ok) throw 0;
    if(saveId) localStorage.setItem(SAVED_EMAIL_KEY,email);
    else localStorage.removeItem(SAVED_EMAIL_KEY);
    current=await r.json();
    document.getElementById("login").classList.add("hidden");
    document.getElementById("app").classList.remove("hidden");
    renderShell(); enterApp();
  }catch(e){ toast("이메일 또는 비밀번호가 올바르지 않습니다"); }
}

/* 로그인 직후/세션 복원 직후 공통 진입 로직 */
function enterApp(){
  // 초기 비밀번호를 안 바꿨으면 다른 건 다 무시하고 무조건 정보수정으로 — 대시보드/book/slack 전부 막힘
  if(current.needs_setup){
    showProfile("초기 비밀번호를 변경해야 계속 사용할 수 있습니다");
    return;
  }
  const params=new URLSearchParams(location.search);
  if(params.has("need_token")){
    history.replaceState(null,"",location.pathname);
    showProfile("슬랙 연동 토큰을 먼저 등록해야 업무현황판을 사용할 수 있습니다", true);
    return;
  }
  if(params.get("view")==="profile"){
    history.replaceState(null,"",location.pathname);
    showProfile();
    return;
  }
  showDash();
}

async function logout(){
  try{ await fetch("api/logout.php",{method:"POST"}); }catch(e){}
  current=null;
  document.getElementById("lg-pw").value="";
  closeChat();
  chatReady=null;
  document.getElementById("chat-log").innerHTML="";
  document.getElementById("app").classList.add("hidden");
  document.getElementById("login").classList.remove("hidden");
}

function renderShell(){
  const first=current.name.slice(0,1);
  const av=document.getElementById("tb-avatar");
  av.textContent=first; av.style.background=current.color||avatarColor(current.name);
  document.getElementById("tb-name").textContent=current.name;
  document.getElementById("hero-hi").textContent=`${current.name}님, 환영합니다`;
}

function toggleUserMenu(e){
  if(e) e.stopPropagation();
  document.getElementById("userMenu").classList.toggle("open");
}
function closeUserMenu(){
  document.getElementById("userMenu").classList.remove("open");
}
document.addEventListener("click",(e)=>{
  const um=document.getElementById("userMenu");
  if(um && !um.contains(e.target)) um.classList.remove("open");
});

/* ---- views ---- */
/* 화면이 늘어나면서 "이것만 보이고 나머지는 숨긴다"를 한 곳에서 처리한다.
   화면마다 서로를 숨기게 두면 하나 추가할 때마다 빠뜨리는 곳이 생긴다. */
const VIEWS=["view-dash","view-profile","view-notices","view-manage"];
function showView(id){
  VIEWS.forEach(v=>{
    const el=document.getElementById(v);
    if(el) el.classList.toggle("hidden", v!==id);
  });
  setChatVisible(id==="view-dash");
  window.scrollTo(0,0);
}

function showDash(){
  // 초기 비밀번호 미변경 상태면 대시보드로 못 나감 — 토큰 미등록만으로는 막지 않음(book은 접근 가능해야 함)
  if(current && current.needs_setup){
    showProfile("초기 비밀번호를 변경해야 계속 사용할 수 있습니다");
    return;
  }
  showView("view-dash");
  renderTiles();
  loadBoard();
  loadWeather();
}
const SWATCH_COLORS=["#B6574A","#BA7D4D","#A58838","#818C46","#548058","#458278","#457797","#5A64AD","#8164AB","#9B5797","#B25D7E","#8C7055","#606D79"];
function hexOrDefault(c){ return /^#[0-9a-fA-F]{6}$/.test(c||"") ? c : "#1C5DE5"; }
let profileEditing=false;
function buildSwatches(selected){
  document.getElementById("pf-swatches").innerHTML=SWATCH_COLORS.map(c=>
    `<button type="button" class="swatch${c.toLowerCase()===selected.toLowerCase()?" on":""}" style="background:${c}" onclick="pickSwatch('${c}')" title="${c}"${profileEditing?"":" disabled"}></button>`
  ).join("");
}
function pickSwatch(c){
  if(!profileEditing) return;
  document.getElementById("pf-color").value=c;
  buildSwatches(c);
}

/* 마이페이지: 기본은 보기 전용, "수정" 눌러야 입력칸이 활성화된다.
   단, 초기 비번 미변경/토큰 요구 상황이면 볼 것도 없이 바로 수정 모드로 연다. */
function setProfileMode(editing){
  profileEditing=editing;
  ["pf-name","pf-email","pf-color","pf-pw","pf-token"].forEach(id=>{
    document.getElementById(id).disabled=!editing;
  });
  buildSwatches(document.getElementById("pf-color").value);
  document.getElementById("pf-editbtn").classList.toggle("hidden", editing);
  document.getElementById("pf-cancel").classList.toggle("hidden", !editing);
  document.getElementById("pf-save").classList.toggle("hidden", !editing);
  if(editing) document.getElementById("pf-cancel").disabled = !!(current && current.needs_setup);
}
function startEditProfile(){ setProfileMode(true); }
function cancelEditProfile(){
  document.getElementById("pf-name").value=current.name;
  document.getElementById("pf-email").value=current.email;
  document.getElementById("pf-pw").value="";
  document.getElementById("pf-color").value=hexOrDefault(current.color);
  document.getElementById("pf-token").value="";
  setProfileMode(false);
}

function showProfile(alertMsg, forceEdit){
  showView("view-profile");
  document.getElementById("pf-name").value=current.name;
  document.getElementById("pf-email").value=current.email;
  document.getElementById("pf-pw").value="";
  document.getElementById("pf-color").value=hexOrDefault(current.color);
  const tokenEl=document.getElementById("pf-token");
  tokenEl.value="";
  tokenEl.placeholder = current.has_token ? "저장됨 · 변경하려면 새 토큰 입력" : "xoxp-... (본인 토큰 붙여넣기)";
  setProfileMode(!!(current && current.needs_setup) || !!forceEdit);
  if(alertMsg) toast(alertMsg);
}

const arrow=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17L17 7M17 7H8M17 7v9"/></svg>`;
function renderTiles(){
  const bookIcon=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>`;
  const slackIcon=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>`;
  const dtiIcon=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9h4"/><path d="M18 14h-8M18 18h-8M18 6h-8v4h8V6Z"/></svg>`;
  const learnIcon=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10 12 5 2 10l10 5 10-5Z"/><path d="M6 12v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5"/><path d="M22 10v6"/></svg>`;
  const accessIcon=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 8.1-8.1M17 6l2.5 2.5M14.5 8.5 17 11"/></svg>`;
  const moodleIcon=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m16.2 7.8-2.3 6.1-6.1 2.3 2.3-6.1z"/></svg>`;
  const plusIcon=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>`;
  const cartIcon=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h2.2l2.3 12.2a1.6 1.6 0 0 0 1.6 1.3h9.1a1.6 1.6 0 0 0 1.6-1.3L21 7H5.3"/></svg>`;

  // key → 아이콘. 그림은 코드에 두고 이름·설명·색은 worksystems.json 에 둔다.
  // 모르는 key 가 오면 plusIcon 으로 그려 타일이 통째로 사라지지는 않게 한다.
  const ICONS = { book:bookIcon, slack:slackIcon, dti:dtiIcon, learn:learnIcon,
                  access:accessIcon, moodle:moodleIcon, bluecart:cartIcon };

  // 타일은 SYSTEMS(=worksystems.json) 를 그대로 따라간다. 시스템을 더하려면
  // json 에 한 줄 적고 여기 ICONS 에 아이콘만 얹으면 된다.
  document.getElementById("tiles").innerHTML =
    SYSTEMS.map(s => `
    <a class="tile" href="${esc(s.url)}" target="_blank" rel="noopener">
      <span class="go">${arrow}</span>
      <span class="ic" style="background:${esc(s.color || "#5A667F")}">${ICONS[s.key] || plusIcon}</span>
      <div><h3>${esc(s.label)}</h3><p>${esc(s.desc || "")}</p></div>
    </a>`).join("") + `
    <div class="tile soon">
      <span class="badge-soon">준비중</span>
      <span class="ic">${plusIcon}</span>
      <div><h3>추가 예정</h3><p>새로운 사내 시스템이 이 자리에 추가됩니다.</p></div>
    </div>`;
}

/* ---- profile save ---- */
function toggleEye(id,btn){
  const el=document.getElementById(id);
  el.type = el.type==="password" ? "text" : "password";
}
async function saveProfile(){
  function v(id){ return document.getElementById(id).value.trim(); }
  const wasForced=!!(current && current.needs_setup);   // 강제 진입(초기 비번 미변경) 상태였는지
  const name=v("pf-name"), email=v("pf-email");
  if(!name){toast("이름을 입력하세요");return;}
  if(!email){toast("이메일을 입력하세요");return;}

  const body={ name, email, color:document.getElementById("pf-color").value };
  const pw=v("pf-pw"); if(pw) body.password=pw;
  const tk=v("pf-token"); if(tk) body.slack_token=tk; // 빈칸이면 미전송=기존 토큰 유지

  const r=await fetch("api/me.php",{method:"PUT",headers:{"Content-Type":"application/json"},
    body:JSON.stringify(body)});
  if(!r.ok){ toast("저장 실패"); return; }
  current=await r.json();
  renderShell();
  if(current.needs_setup){
    // 아직도 초기 비번 그대로 — 수정 모드 유지하고 계속 안내
    setProfileMode(true);
    toast("초기 비밀번호를 변경해야 계속 사용할 수 있습니다");
  }else if(wasForced){
    // 강제 진입 상태를 막 해소함 — 대시보드로 보내줌
    toast("회원정보가 저장되었습니다");
    showDash();
  }else{
    // 마이페이지에 자발적으로 들어와 수정한 경우 — 보기 모드로 돌아가 그대로 머무름
    setProfileMode(false);
    toast("회원정보가 저장되었습니다");
  }
}

/* =====================================================================
   알림판 — 주요 공지 / 중요 일정
   서버는 api/notices.php, api/notice_file.php, api/events.php, api/admins.php.
   관리자만 쓰는 버튼은 화면에서도 감추지만, 막는 쪽은 언제나 서버다.
   ===================================================================== */

/** JSON 주고받기 한 곳. 서버가 준 error 문구를 그대로 띄운다. */
async function bapi(url, opt){
  const o=Object.assign({headers:{}}, opt||{});
  if(o.body && typeof o.body==="string") o.headers["Content-Type"]="application/json";
  const r=await fetch(url,o);
  let data=null;
  try{ data=await r.json(); }catch(e){}
  if(!r.ok) throw new Error((data&&data.error)||`요청에 실패했습니다 (HTTP ${r.status})`);
  return data;
}

function fmtDate(s){ return (s||"").slice(0,10); }
function fmtDateDot(s){ return fmtDate(s).replace(/-/g,"."); }
function fmtSize(n){
  n=+n||0;
  if(n<1024) return n+"B";
  if(n<1048576) return Math.round(n/1024)+"KB";
  return (n/1048576).toFixed(1)+"MB";
}
/* 본문은 일반 텍스트다. 이스케이프를 먼저 하고, 그 결과에서 주소만 링크로 바꾼다.
   순서를 뒤집으면 만들어 둔 <a> 까지 이스케이프돼 그대로 글자로 보인다. */
function linkify(text){
  return esc(text).replace(/https?:\/\/[^\s<]+/g, u=>
    `<a href="${u}" target="_blank" rel="noopener noreferrer">${u}</a>`);
}
/* 요일까지 붙여 준다. 일정은 "몇 일" 보다 "무슨 요일" 이 먼저 궁금하다. */
const WEEKDAYS=["일","월","화","수","목","금","토"];
function fmtWhen(e){
  const d=new Date(e.starts_on+"T00:00:00");
  let out=`${fmtDateDot(e.starts_on)}(${WEEKDAYS[d.getDay()]})`;
  if(e.ends_on && e.ends_on!==e.starts_on) out+=` ~ ${fmtDateDot(e.ends_on)}`;
  return out;
}

/* ---- 대시보드 위 두 칸 ---- */
let boardTotal=0;
async function loadBoard(){
  try{
    // 칸 안에서 스크롤되므로 몇 건을 받아도 바깥 높이는 그대로다.
    const d=await bapi("api/notices.php?size=20&page=1");
    boardTotal=d.total;
    renderBoardNotices(d.rows);
  }catch(e){
    document.getElementById("board-notices").innerHTML=
      `<div class="panel-empty">공지를 불러오지 못했습니다.<br>${esc(e.message)}</div>`;
  }
  try{
    // 카드가 한 장씩 돌아가므로 넉넉히 받아 둔다. 칸 높이는 그대로다.
    const d=await bapi("api/events.php?scope=upcoming&limit=12");
    renderBoardEvents(d.rows);
  }catch(e){
    document.getElementById("board-events").innerHTML=
      `<div class="panel-empty">일정을 불러오지 못했습니다.<br>${esc(e.message)}</div>`;
  }
}

/* 중요 표시. 맨 위 고정을 없앴으니 순서는 그대로 두고 눈에만 띄게 한다.
   경고 삼각형은 "문제가 생겼다" 로 읽혀서 공지에는 별이 맞다. */
const IMP_ICON=`<svg class="nt-imp" viewBox="0 0 24 24" width="14" height="14" fill="currentColor" role="img" aria-label="중요"><path d="M12 1.8l3.1 6.3 6.9 1-5 4.9 1.2 6.9-6.2-3.3-6.2 3.3L7 14l-5-4.9 6.9-1z"/></svg>`;
/* 첨부 표시. 이모지는 기기마다 모양이 달라 클립을 직접 그린다. */
function clipTag(n){
  if(!n) return "";
  return `<span class="clip" title="첨부 ${n}개">`+
    `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21.4 11.05 12.3 20.2a5.5 5.5 0 0 1-7.8-7.8l9.2-9.2a3.7 3.7 0 0 1 5.2 5.2l-9.1 9.2a1.8 1.8 0 0 1-2.6-2.6l8.5-8.5"/></svg>`+
    `${n}</span>`;
}

function noticeRow(n){
  return `<button class="nt-row${n.is_important?" imp":""}" onclick="openNotice(${n.id})">
      ${n.is_important?IMP_ICON:""}
      <span class="tt" title="${esc(n.title)}">${esc(n.title)}</span>
      ${clipTag(n.file_count)}
      ${n.is_new?`<span class="nt-new">NEW</span>`:""}
      <span class="dt">${fmtDateDot(n.created_at)}</span>
    </button>`;
}

function renderBoardNotices(rows){
  const win=document.getElementById("board-notices");
  if(!rows.length){
    win.innerHTML=`<div class="slide-track"><div class="panel-empty">아직 올라온 공지가 없습니다.</div></div>`;
    noticeSlider.stop();
    return;
  }
  win.innerHTML=`<div class="slide-track">`+rows.map(noticeRow).join("")+`</div>`;
  noticeSlider.reset();
}

/* ---- 자동 세로 슬라이딩 ----
   맨 윗줄을 한 칸 위로 밀어 올린 뒤 맨 아래로 옮겨 붙인다. 목록을 복제하지
   않으므로 줄이 늘어도 DOM 이 두 배가 되지 않는다.

   한 칸 옮기는 일이 끝났는지는 transitionend 로 알지만, 그것만 믿으면 안 된다.
   창이 숨어 있으면(다른 화면에 가 있을 때) 전이가 아예 시작되지 않아 이벤트가
   영영 안 오고, 그러면 busy 가 풀리지 않아 슬라이더가 멎는다. 시간 제한을 같이 건다. */
const SLIDE_MS=550;
function makeSlider(winId, interval, navId, onMove){
  let timer=null, busy=false, paused=false, pos=0;
  const calm=window.matchMedia("(prefers-reduced-motion: reduce)");

  const win  =()=>document.getElementById(winId);
  const track=()=>{ const w=win(); return w && w.querySelector(".slide-track"); };
  const nav  =()=>navId ? document.getElementById(navId) : null;

  /** 읽는 중에 바뀌면 안 된다 — 올려 두거나 초점이 들어오면 멈춘다.
      목록을 다시 그릴 때마다 창이 새로 생길 수 있어 그때마다 걸어 준다.
      이동 단추는 머리말에 붙박이라 한 번만 걸면 된다. */
  function bind(){
    const w=win();
    if(w && !w.dataset.slideBound){
      w.dataset.slideBound="1";
      ["mouseenter","focusin"].forEach(ev=>w.addEventListener(ev,()=>{paused=true;}));
      ["mouseleave","focusout"].forEach(ev=>w.addEventListener(ev,()=>{paused=false;}));
    }
    const n=nav();
    if(n && !n.dataset.slideBound){
      n.dataset.slideBound="1";
      n.querySelectorAll("[data-dir]").forEach(b=>
        b.addEventListener("click",()=>manual(parseInt(b.dataset.dir,10))));
      ["mouseenter","focusin"].forEach(ev=>n.addEventListener(ev,()=>{paused=true;}));
      ["mouseleave","focusout"].forEach(ev=>n.addEventListener(ev,()=>{paused=false;}));
    }
  }

  function count(){ const t=track(); return t ? t.children.length : 0; }
  function hasMore(){
    const w=win(), t=track();
    return !!(w && t && t.scrollHeight - w.clientHeight > 2);
  }

  /** 지금 맨 위에 있는 게 몇 번째인지 보여 준다. 다 보이면 단추째 감춘다. */
  function paint(){
    // 창 바깥에 따로 그려 두는 것(일정 점판)이 있으면 먼저 알린다.
    if(onMove) onMove(pos);
    const n=nav();
    if(!n) return;
    // 넘길 게 없으면 화살표와 숫자만 감춘다. 칸 자체를 숨기면 안 된다 —
    // 일정 위젯은 이 요소가 곧 칸이다.
    const many = hasMore() && count() > 1;
    n.classList.toggle("no-more", !many);
    const label=n.querySelector(".pos");
    if(label) label.textContent = many ? (pos+1)+" / "+count() : "";
  }

  /**
   * 한 칸 옮긴다. dir 이 1 이면 다음, -1 이면 이전.
   *
   * 다음은 맨 윗줄을 위로 민 뒤 맨 뒤로 옮겨 붙이고,
   * 이전은 맨 뒷줄을 먼저 앞에 붙여 놓고 위로 밀린 상태에서 제자리로 내린다.
   * 어느 쪽이든 목록을 복제하지 않는다.
   */
  function move(dir){
    const w=win(), t=track();
    if(busy || !w || !t || t.children.length<2) return;
    if(w.offsetParent===null) return;   // 숨어 있으면 건너뛴다
    if(!hasMore()) return;              // 다 보이면 옮길 것도 없다

    busy=true;
    moveSlide(t, dir);

    const n=count();
    pos=((pos + dir) % n + n) % n;
    paint();
  }

  /** 한 줄씩 위로 밀어 올린다(공지). */
  function moveSlide(t, dir){
    const gap=parseFloat(getComputedStyle(t).rowGap)||0;
    const ease=`transform ${SLIDE_MS}ms cubic-bezier(.4,0,.2,1)`;

    let settled=false, fin;
    const finish=(moveFirstToEnd)=>()=>{
      if(settled) return;
      settled=true;
      t.removeEventListener("transitionend",fin);
      t.style.transition="none";
      t.style.transform="none";
      if(moveFirstToEnd) t.appendChild(moveFirstToEnd);
      void t.offsetHeight;   // 되돌린 위치를 즉시 반영(깜빡임 방지)
      busy=false;
    };

    if(dir > 0){
      const first=t.children[0];
      const h=first.getBoundingClientRect().height+gap;
      fin=finish(first);
      t.addEventListener("transitionend",fin);
      setTimeout(fin, SLIDE_MS+250);     // 전이가 안 와도 반드시 풀린다
      t.style.transition=ease;
      t.style.transform=`translateY(-${h}px)`;
    }else{
      const last=t.children[t.children.length-1];
      t.insertBefore(last, t.children[0]);
      const h=last.getBoundingClientRect().height+gap;
      t.style.transition="none";
      t.style.transform=`translateY(-${h}px)`;
      void t.offsetHeight;               // 밀린 상태를 먼저 확정한 뒤 되돌려야 움직인다
      fin=finish(null);
      t.addEventListener("transitionend",fin);
      setTimeout(fin, SLIDE_MS+250);
      t.style.transition=ease;
      t.style.transform="none";
    }
  }

  /** 시계가 부르는 쪽. 마우스를 올려 두면 건너뛴다. */
  function tick(){ if(!paused) move(1); }

  /** 사람이 누른 쪽. 올려 둔 상태여도 움직이고, 타이머를 다시 센다 —
      누르자마자 자동으로 또 넘어가면 두 칸이 지나간 것처럼 보인다. */
  function manual(dir){ move(dir); start(); }

  function start(){
    stop();
    if(calm.matches) return;   // 움직임을 줄여 달라고 했으면 자동으로는 돌리지 않는다
    timer=setInterval(tick, interval);
  }
  function stop(){ if(timer){ clearInterval(timer); timer=null; } }

  return {
    reset(){
      bind();
      const t=track();
      if(t){ t.style.transition="none"; t.style.transform="none"; }
      busy=false; paused=false; pos=0;
      paint();
      start();
    },
    stop(){ stop(); const n=nav(); if(n) n.classList.add("no-more"); }
  };
}
const noticeSlider=makeSlider("board-notices", 3600, "nt-nav");
const eventSlider =makeSlider("board-events-list", 4200, "ev-nav", paintPix);

/* 한 번에 한 건씩, 크게 보여 준다. 왼쪽 D-day 는 달력 한 장 모양으로 두르고
   (위쪽 굵은 띠 + 고리 두 개) 오른쪽에 제목과 날짜를 놓는다.
   카드로 감싸지는 않는다 — 바탕을 깔면 그 칸만 무겁게 튄다. */
/* D-day 를 통째로 점으로 찍는다(3×5 픽셀 글자).

   판은 다섯 글자 자리로 붙박이다 — D-3 이든 D-128 이든 점 개수와 판 크기가
   같아서, 넘어갈 때 글자만 바뀌고 판은 그대로 있는 것처럼 보인다.
   켜진 점만 일정 종류 색을 입고 꺼진 점은 언제나 같은 색이라, 색이 바뀌는 것이
   곧 '무슨 일정인지' 가 된다. */
/* 3칸 × 7줄 픽셀 글자. 5줄짜리보다 세로로 길어 판을 꽉 채운다. */
const PIX_FONT={
  "0":"111101101101101101111","1":"010110010010010010111","2":"111001001111100100111",
  "3":"111001001111001001111","4":"101101101111001001001","5":"111100100111001001111",
  "6":"111100100111101101111","7":"111001001001001001001","8":"111101101111101101111",
  "9":"111101101111001001111",
  "D":"110101101101101101110","A":"010101101111101101101","Y":"101101101010010010010",
  "N":"101101111111111101101","O":"111101101101101101111","W":"101101101101111111101",
  "-":"000000000111000000000","+":"000000010111010000000"," ":"000000000000000000000"
};
/* 판은 점으로만 이루어진 네모다 — 테두리도 고리도 없다.
   글자가 놓이는 칸 바깥에도 꺼진 점을 깔아 판 전체를 채운다. */
const PIX_GW=3, PIX_GH=7;                  // 글자 한 자의 칸 수
const PIX_CELLS=5;                         // 글자 자리 수(붙박이)
// 여백은 위아래·좌우 한 줄씩만. 나머지는 글자가 차지한다.
const PIX_PAD_X=1, PIX_PAD_Y=1;
const PIX_W=PIX_CELLS*(PIX_GW+1)-1+PIX_PAD_X*2;
const PIX_H=PIX_GH+PIX_PAD_Y*2;

/** 점으로 찍을 수 있는 글자로 바꾼다. 한글은 못 찍으므로 짧은 말로 옮긴다. */
function pixText(label){
  if(label==="진행중") return "NOW";
  const t=String(label).toUpperCase().replace(/[^0-9A-Z+-]/g,"");
  return t.slice(0, PIX_CELLS) || "?";
}

/* 점 하나하나는 한 번만 그려 두고, 일정이 바뀌면 켜짐/꺼짐만 갈아 끼운다.
   DOM 을 새로 만들지 않으므로 판은 그 자리에 가만히 있고 글자만 바뀐다.
   fill 에 전이를 걸어 뒀으니 바뀌는 점만 스르르 물든다. */
function pixSkeleton(){
  let dots="";
  for(let y=0;y<PIX_H;y++) for(let x=0;x<PIX_W;x++){
    dots+=`<rect class="off" x="${x+0.12}" y="${y+0.12}" `+
          `width="0.76" height="0.76" rx="0.16"/>`;
  }
  return `<svg class="pix" viewBox="0 0 ${PIX_W} ${PIX_H}" aria-hidden="true">${dots}</svg>`;
}

/** 켜야 할 점의 자리(판 전체를 훑은 일련번호) */
function pixOnMap(label){
  const txt=pixText(label);
  // 글자 수가 아니라 '점 칸' 으로 가운데를 잡는다. 칸 단위로 맞추면
  // D-43 처럼 네 글자일 때 한쪽으로 한 칸 치우친다.
  const cols=txt.length*(PIX_GW+1)-1;                 // 글자 3칸 + 사이 1칸
  const x0=PIX_PAD_X+Math.round((PIX_CELLS*(PIX_GW+1)-1-cols)/2);
  const on=new Set();
  for(let c=0;c<txt.length;c++){
    const g=PIX_FONT[txt[c]]||PIX_FONT[" "];
    for(let i=0;i<PIX_GW*PIX_GH;i++){
      if(g[i]!=="1") continue;
      const x=i%PIX_GW, y=(i-x)/PIX_GW;
      on.add((y+PIX_PAD_Y)*PIX_W + x0+c*(PIX_GW+1)+x);
    }
  }
  return on;
}

/** 지금 보고 있는 일정에 맞춰 판을 다시 칠한다. */
let evRows=[];
function paintPix(i){
  const e=evRows[i], box=document.getElementById("ev-pix");
  if(!e || !box) return;
  box.className="ev-pix k-"+e.kind.key+(e.heat==="today" ? " today" : "");
  box.setAttribute("aria-label", e.dday_label);
  const on=pixOnMap(e.dday_label);
  const rects=box.querySelectorAll("rect");
  for(let k=0;k<rects.length;k++){
    rects[k].setAttribute("class", on.has(k) ? "on" : "off");
  }
}

function renderBoardEvents(rows){
  const box=document.getElementById("board-events");
  if(!rows.length){
    box.innerHTML=`<div class="panel-empty">다가오는 일정이 없습니다.</div>`;
    eventSlider.stop();
    return;
  }
  // 점판은 칸 바깥에 고정해 두고 글자만 창 안에서 넘어간다.
  evRows=rows;
  box.innerHTML=
    `<div class="ev-wrap">`+
      `<div class="ev-pix" id="ev-pix" role="img">${pixSkeleton()}</div>`+
      `<div class="slide-win" id="board-events-list"><div class="slide-track">`+
        rows.map(e=>`
          <div class="ev-text">
            <div class="nm" title="${esc(e.title)}"><i class="ki" title="${esc(e.kind.label)}">${e.kind.icon}</i>${esc(e.title)}</div>
            <div class="sub" title="${esc(fmtWhen(e))}${e.place?" | "+esc(e.place):""}">${esc(fmtWhen(e))}${e.place?` <em>|</em> `+esc(e.place):""}</div>
          </div>`).join("")+
      `</div></div>`+
    `</div>`;
  eventSlider.reset();
  maybeCelebrate(rows);
}

/* =====================================================================
   오늘 경사 일정이 있으면 들어올 때 한 번 폭죽을 터뜨린다.

   한 번 터지고 끝나므로 canvas 를 써도 화면에 부담이 남지 않는다 —
   마지막 알갱이가 사라지면 canvas 를 지우고 리스너까지 뗀다.
   날씨 배경(.wx-fx)과 달리 계속 도는 것이 아니라서 canvas 를 골랐다.
   CSS 로는 알갱이 200개를 각각 다른 포물선으로 던질 수 없다.
   ===================================================================== */
const BOOM_COLORS = ["#FF5E8A", "#FFC24B", "#5FD8A2", "#79B0FF", "#C98FE8", "#FF9457"];

/* 무엇을 축하하는지 함께 띄운다. 폭죽만 터지면 왜 터지는지 모른다.
   여러 건이면 두 줄까지 보여 주고 나머지는 '외 N건' 으로 줄인다. */
function hailCard(titles, cap){
  if(!titles.length) return null;
  const box = document.createElement("div");
  box.className = "fx-hail";
  const rest = titles.length - 2;
  box.innerHTML =
    `<div class="fx-card" role="status">` +
      `<span class="fx-cap">${esc(cap || "🎉 축하합니다")}</span>` +
      titles.slice(0, 2).map(t => `<strong>${esc(t)}</strong>`).join("") +
      (rest > 0 ? `<span class="fx-more">외 ${rest}건</span>` : "") +
    `</div>`;
  document.body.appendChild(box);
  setTimeout(() => box.remove(), 3600);
  return box;
}

function celebrate(titles, cap){
  hailCard(titles || [], cap);
  // 움직임을 줄여 달라고 한 사람에게는 알림 카드만 띄우고 폭죽은 건너뛴다.
  if(window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

  const cv = document.createElement("canvas");
  cv.className = "fx-boom";
  cv.setAttribute("aria-hidden", "true");
  document.body.appendChild(cv);

  const ctx = cv.getContext("2d");
  const dpr = Math.min(window.devicePixelRatio || 1, 2);
  function size(){
    cv.width  = innerWidth  * dpr;
    cv.height = innerHeight * dpr;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }
  size();
  addEventListener("resize", size);

  const P = [];            // 불꽃 알갱이
  const C = [];            // 색종이
  const G = 0.00042;       // 중력 (px/ms²)
  const pick = () => BOOM_COLORS[(Math.random() * BOOM_COLORS.length) | 0];

  function burst(x, y, n, spd){
    for(let i = 0; i < n; i++){
      const a = Math.random() * Math.PI * 2;
      const v = spd * (0.35 + Math.random() * 0.65);
      P.push({x, y, vx: Math.cos(a) * v, vy: Math.sin(a) * v, c: pick(),
              r: 1.2 + Math.random() * 2.0, life: 950 + Math.random() * 750, age: 0});
    }
  }
  function confetti(n){
    for(let i = 0; i < n; i++){
      C.push({x: Math.random() * innerWidth, y: -20 - Math.random() * 240,
              vx: (Math.random() - .5) * 0.05, vy: 0.07 + Math.random() * 0.09,
              w: 4 + Math.random() * 5, h: 7 + Math.random() * 7, c: pick(),
              rot: Math.random() * Math.PI, vr: (Math.random() - .5) * 0.006,
              life: 3400, age: 0});
    }
  }

  // 세 번에 나눠 터뜨린다. 한꺼번에 터뜨리면 '펑' 한 번으로 끝나 심심하다.
  const H = Math.min(innerHeight * 0.42, 300);
  burst(innerWidth * 0.26, H,        74, 0.42);
  setTimeout(() => burst(innerWidth * 0.72, H * 0.82, 66, 0.38), 360);
  setTimeout(() => burst(innerWidth * 0.49, H * 1.10, 80, 0.45), 760);
  confetti(90);

  let prev = performance.now();
  (function tick(now){
    const dt = Math.min(now - prev, 48);      // 탭을 다녀오면 dt 가 튄다
    prev = now;
    ctx.clearRect(0, 0, innerWidth, innerHeight);

    for(let i = P.length - 1; i >= 0; i--){
      const p = P[i];
      p.age += dt;
      if(p.age > p.life){ P.splice(i, 1); continue; }
      p.vy += G * dt;  p.vx *= 0.995;  p.vy *= 0.995;
      p.x  += p.vx * dt;  p.y += p.vy * dt;
      // 알갱이 하나를 점이 아니라 짧은 꼬리로 긋는다. 점만 찍으면 흩뿌린
      // 색종이처럼 보이고, 꼬리가 있어야 터져 나가는 불꽃으로 읽힌다.
      ctx.globalAlpha = Math.max(0, 1 - p.age / p.life);
      ctx.strokeStyle = p.c;
      ctx.lineWidth = p.r * 1.7;
      ctx.lineCap = "round";
      ctx.beginPath();
      ctx.moveTo(p.x - p.vx * 26, p.y - p.vy * 26);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
    }
    for(let i = C.length - 1; i >= 0; i--){
      const c = C[i];
      c.age += dt;
      if(c.age > c.life || c.y > innerHeight + 40){ C.splice(i, 1); continue; }
      c.vy += G * dt * 0.25;
      c.x  += c.vx * dt + Math.sin((c.age + i * 90) / 420) * 0.35;   // 좌우로 나풀
      c.y  += c.vy * dt;
      c.rot += c.vr * dt;
      ctx.globalAlpha = Math.min(1, Math.max(0, (c.life - c.age) / 700));
      ctx.save(); ctx.translate(c.x, c.y); ctx.rotate(c.rot);
      ctx.fillStyle = c.c; ctx.fillRect(-c.w / 2, -c.h / 2, c.w, c.h); ctx.restore();
    }

    if(P.length || C.length) requestAnimationFrame(tick);
    else { removeEventListener("resize", size); cv.remove(); }
  })(prev);
}

/* 들어올 때 한 번만. 화면 안을 돌아다니다 대시보드로 돌아와도, 새로고침을
   해도 다시 터지지 않게 이 창(sessionStorage)에 오늘 날짜로 표를 남긴다.
   창을 새로 열거나 다시 로그인하면 그날 처음으로 치고 한 번 더 보여 준다. */
function maybeCelebrate(rows){
  // 터뜨릴 날인지는 서버가 정해서 cheer 로 실어 준다(board_decorate_event).
  //   today = 바로 오늘 / early = 그날이 주말·공휴일이라 오늘 미리
  const hits = rows.filter(e => e.cheer === "today" || e.cheer === "early");
  if(!hits.length) return;
  // 여러 건이면 첫 건의 문구를 쓴다. 종류가 섞이는 날은 드물고,
  // 문구를 여러 줄 쌓으면 정작 일정 이름이 눈에 안 들어온다.
  const cap = hits[0].cheer_cap;

  const d = new Date();                       // 현지 날짜로 잡는다.
  const key = "iw-boom-" + d.getFullYear() + "-" +
              String(d.getMonth() + 1).padStart(2, "0") + "-" +
              String(d.getDate()).padStart(2, "0");
  try{
    if(sessionStorage.getItem(key)) return;
    sessionStorage.setItem(key, "1");
  }catch(_){ /* 저장을 못 하는 창이어도 한 번은 보여 준다 */ }

  // 화면이 자리 잡은 뒤에
  setTimeout(() => celebrate(hits.map(e => e.title), cap), 450);
}

/* ---- 공지 목록 ---- */
let nlPage=1;
async function showNotices(page){
  nlPage=page||1;
  showView("view-notices");
  const list=document.getElementById("nl-list");
  list.innerHTML=`<div class="panel-empty">불러오는 중…</div>`;
  document.getElementById("nl-pager").innerHTML="";
  try{
    const d=await bapi(`api/notices.php?size=10&page=${nlPage}`);
    if(!d.rows.length){
      list.innerHTML=`<div class="panel-empty">아직 올라온 공지가 없습니다.</div>`;
      return;
    }
    list.innerHTML=d.rows.map(n=>`
      <button class="nl${n.is_important?" imp":""}" onclick="openNotice(${n.id})">
        ${n.is_important?IMP_ICON:""}
        <span class="tt">${esc(n.title)}</span>
        ${clipTag(n.file_count)}
        ${n.is_new?`<span class="nt-new">NEW</span>`:""}
        <span class="who">${esc(n.author_name)}</span>
        <span class="dt">${fmtDateDot(n.created_at)}</span>
        <span class="vw">${n.view_count}회</span>
      </button>`).join("");

    const pages=Math.max(1,Math.ceil(d.total/d.size));
    document.getElementById("nl-pager").innerHTML=
      `<button class="btn-sm" onclick="showNotices(${nlPage-1})"${nlPage<=1?" disabled":""}>이전</button>`+
      `<span>${nlPage} / ${pages} · 전체 ${d.total}건</span>`+
      `<button class="btn-sm" onclick="showNotices(${nlPage+1})"${nlPage>=pages?" disabled":""}>다음</button>`;
  }catch(e){
    list.innerHTML=`<div class="panel-empty">${esc(e.message)}</div>`;
  }
}

/* ---- 공지 상세 — 가운데 팝업 ---- */
let ndId=0;
function closeNoticeModal(){ document.getElementById("nd-modal").classList.add("hidden"); }

async function openNotice(id){
  ndId=id;
  document.getElementById("nd-modal").classList.remove("hidden");
  document.getElementById("nd-admin-btns").innerHTML="";
  const card=document.getElementById("nd-card");
  card.innerHTML=`<div class="panel-empty">불러오는 중…</div>`;
  try{
    const d=await bapi(`api/notices.php?id=${id}`);
    const n=d.notice;
    card.innerHTML=`
      <h2>${n.is_important?IMP_ICON+" ":""}${esc(n.title)}</h2>
      <div class="detail-meta">
        <span>${esc(n.author_name)}</span>
        <span>${esc(n.created_at)}</span>
        ${n.updated_at?`<span>수정 ${esc(n.updated_at)}</span>`:""}
        <span>조회 ${n.view_count}</span>
        ${n.window.label?`<span class="win win-${n.window.state}">${esc(n.window.label)}</span>`:""}
      </div>
      <div class="detail-body">${linkify(n.body)}</div>
      ${n.files.length?`
        <div class="files">
          <h3>첨부파일 ${n.files.length}개</h3>
          ${n.files.map(f=>`
            <a class="file" href="api/notice_file.php?id=${f.id}" target="_blank" rel="noopener">
              <span>📎</span><span>${esc(f.orig_name)}</span>
              <span class="sz">${fmtSize(f.file_size)}</span>
            </a>`).join("")}
        </div>`:""}`;
    if(d.can_edit){
      document.getElementById("nd-admin-btns").innerHTML=
        `<button class="btn-sm" onclick="openNoticeDrawer(${n.id})">수정</button> `+
        `<button class="btn-sm danger" onclick="deleteNotice(${n.id})">삭제</button> `;
    }
  }catch(e){
    card.innerHTML=`<div class="panel-empty">${esc(e.message)}</div>`;
  }
}

async function deleteNotice(id){
  if(!confirm("이 공지를 삭제할까요? 첨부파일도 함께 지워지고 되돌릴 수 없습니다.")) return;
  try{
    await bapi(`api/notices.php?id=${id}`,{method:"DELETE"});
    closeNoticeModal();
    toast("공지를 삭제했습니다");
    refreshAfterNotice();
  }catch(e){ toast(e.message); }
}

/* ---- 공지 작성 / 수정 ---- */
let neId=0;          // 0 이면 새 글
let nePending=[];    // 아직 서버로 안 보낸 파일(새 글일 때는 저장 후에 올린다)

function openNoticeDrawer(id){
  neId=id||0;
  nePending=[];
  // 상세 팝업 위에 드로어가 겹치면 어지럽다. 수정으로 들어오면 팝업은 닫는다.
  closeNoticeModal();
  document.getElementById("ne-modal").classList.remove("hidden");
  document.getElementById("ne-err").classList.add("hidden");
  document.getElementById("ne-title").textContent=neId?"공지 수정":"새 공지";
  document.getElementById("ne-files").value="";

  if(!neId){
    ["ne-subject","ne-body","ne-from","ne-to"].forEach(k=>document.getElementById(k).value="");
    document.getElementById("ne-important").checked=false;
    renderNeFiles([]);
    document.getElementById("ne-subject").focus();
    return;
  }
  bapi(`api/notices.php?id=${neId}`).then(d=>{
    const n=d.notice;
    document.getElementById("ne-subject").value=n.title;
    document.getElementById("ne-body").value=n.body;
    document.getElementById("ne-from").value=n.starts_on||"";
    document.getElementById("ne-to").value=n.ends_on||"";
    document.getElementById("ne-important").checked=n.is_important;
    renderNeFiles(n.files);
  }).catch(e=>toast(e.message));
}

function closeNoticeDrawer(){
  document.getElementById("ne-modal").classList.add("hidden");
}

/** 공지를 고친 뒤 지금 보고 있는 화면만 다시 그린다. */
function refreshAfterNotice(){
  const manage=!document.getElementById("view-manage").classList.contains("hidden");
  const list  =!document.getElementById("view-notices").classList.contains("hidden");
  if(manage)     showManage("notices");
  else if(list)  showNotices(nlPage);
  else           loadBoard();
}

/* 저장된 첨부(지울 수 있음)와 아직 안 올린 파일(뺄 수 있음)을 한 줄씩 보여 준다. */
function renderNeFiles(saved){
  document.getElementById("ne-filelist").innerHTML=
    saved.map(f=>`
      <div class="file">
        <span>📎</span><span>${esc(f.orig_name)}</span>
        <span class="sz">${fmtSize(f.file_size)}</span>
        <button class="rm" onclick="deleteNoticeFile(${f.id})" title="삭제">&times;</button>
      </div>`).join("")+
    nePending.map((f,i)=>`
      <div class="file" style="border-style:dashed">
        <span>📎</span><span>${esc(f.name)}</span>
        <span class="sz">${fmtSize(f.size)} · 저장 시 올라감</span>
        <button class="rm" onclick="dropPendingFile(${i})" title="빼기">&times;</button>
      </div>`).join("");
}

function dropPendingFile(i){
  nePending.splice(i,1);
  if(neId) bapi(`api/notices.php?id=${neId}`).then(d=>renderNeFiles(d.notice.files));
  else renderNeFiles([]);
}

document.getElementById("ne-files").addEventListener("change", function(){
  nePending=nePending.concat(Array.prototype.slice.call(this.files));
  this.value="";
  if(neId) bapi(`api/notices.php?id=${neId}`).then(d=>renderNeFiles(d.notice.files));
  else renderNeFiles([]);
});

async function deleteNoticeFile(fileId){
  if(!confirm("첨부파일을 삭제할까요?")) return;
  try{
    await bapi(`api/notice_file.php?id=${fileId}`,{method:"DELETE"});
    const d=await bapi(`api/notices.php?id=${neId}`);
    renderNeFiles(d.notice.files);
    toast("첨부파일을 삭제했습니다");
  }catch(e){ toast(e.message); }
}

async function saveNotice(){
  const err=document.getElementById("ne-err");
  const btn=document.getElementById("ne-save");
  err.classList.add("hidden");
  btn.disabled=true;
  try{
    const body=JSON.stringify({
      title:document.getElementById("ne-subject").value.trim(),
      body:document.getElementById("ne-body").value.trim(),
      starts_on:document.getElementById("ne-from").value,
      ends_on:document.getElementById("ne-to").value,
      is_important:document.getElementById("ne-important").checked
    });
    // 새 글은 먼저 저장해 번호를 받은 뒤 파일을 붙인다. 업로드가 실패해도 글은 남는다.
    const res=neId
      ? await bapi(`api/notices.php?id=${neId}`,{method:"PUT",body})
      : await bapi("api/notices.php",{method:"POST",body});
    const id=res.id;

    // 첨부가 실패해도 글은 이미 저장됐다. 그때는 드로어를 닫지 않고
    // 그 글의 수정 상태로 남겨 이유를 보여 준다 — 토스트는 2초면 사라져서
    // "첨부가 왜 안 되지" 로 끝나 버린다.
    if(nePending.length){
      const fd=new FormData();
      nePending.forEach(f=>fd.append("files[]",f));
      let up;
      try{
        up=await bapi(`api/notice_file.php?notice_id=${id}`,{method:"POST",body:fd});
      }catch(upErr){
        neId=id;
        document.getElementById("ne-title").textContent="공지 수정";
        err.textContent="글은 저장했지만 첨부에 실패했습니다.\n"+upErr.message;
        err.classList.remove("hidden");
        refreshAfterNotice();
        return;
      }
      if(up.errors && up.errors.length){
        neId=id;
        document.getElementById("ne-title").textContent="공지 수정";
        err.textContent="글은 저장했지만 일부 첨부에 실패했습니다.\n"+up.errors.join("\n");
        err.classList.remove("hidden");
        nePending=[];
        renderNeFiles(up.files||[]);
        refreshAfterNotice();
        return;
      }
    }
    nePending=[];
    closeNoticeDrawer();
    toast(neId?"공지를 수정했습니다":"공지를 등록했습니다");
    refreshAfterNotice();
    openNotice(id);
  }catch(e){
    err.textContent=e.message;
    err.classList.remove("hidden");
  }finally{
    btn.disabled=false;
  }
}

/* ---- 포털 관리 ---- */
function showManage(tab){
  showView("view-manage");
  document.querySelectorAll("[data-mtab]").forEach(b=>
    b.setAttribute("aria-selected", String(b.dataset.mtab===tab)));
  const box=document.getElementById("mg-body");
  box.innerHTML=`<div class="panel-empty">불러오는 중…</div>`;
  if(tab==="events")  return renderManageEvents(box);
  if(tab==="notices") return renderManageNotices(box);
  return renderManageAdmins(box);
}

async function renderManageEvents(box){
  try{
    const d=await bapi("api/events.php?scope=all");
    box.innerHTML=
      `<div class="page-head" style="padding:0 0 14px">
         <span style="flex:1"></span>
         <button class="btn-sm" onclick="openEventModal(0)">+ 일정 등록</button>
         <button class="btn-sm" onclick="openKwModal()">설정</button>
       </div>`+
      (d.rows.length?`<div class="list-card">`+d.rows.map(e=>`
        <div class="mrow">
          <span class="dday heat-${e.heat}" style="min-width:64px;text-align:center">${esc(e.dday_label)}</span>
          <span class="tt">
            <div class="nm">${esc(e.title)}</div>
            <div class="sub">${esc(fmtWhen(e))}${e.place?" · "+esc(e.place):""}${e.memo?" · "+esc(e.memo):""}</div>
          </span>
          <span class="btns">
            <button class="btn-sm" onclick="openEventModal(${e.id})">수정</button>
            <button class="btn-sm danger" onclick="deleteEvent(${e.id})">삭제</button>
          </span>
        </div>`).join("")+`</div>`
      :`<div class="list-card"><div class="panel-empty">등록된 일정이 없습니다.</div></div>`);
  }catch(e){ box.innerHTML=`<div class="panel-empty">${esc(e.message)}</div>`; }
}

async function renderManageNotices(box){
  try{
    // 관리 화면에서만 예약·종료된 공지까지 본다.
    const d=await bapi("api/notices.php?scope=manage&size=50&page=1");
    box.innerHTML=
      `<div class="page-head" style="padding:0 0 14px">
         <span style="flex:1"></span>
         <button class="btn-sm" onclick="openNoticeDrawer(0)">+ 새 공지</button>
       </div>`+
      (d.rows.length?`<div class="list-card">`+d.rows.map(n=>`
        <div class="mrow${n.window.state==='ended'?" dim":""}">
          <span class="tt">
            <div class="nm${n.is_important?" imp":""}">${n.is_important?IMP_ICON+" ":""}${esc(n.title)}
              ${n.window.label?`<span class="win win-${n.window.state}">${esc(n.window.label)}</span>`:""}</div>
            <div class="sub">${esc(n.author_name)} · ${fmtDateDot(n.created_at)} · 조회 ${n.view_count}${n.file_count?` · 첨부 ${n.file_count}`:""}</div>
          </span>
          <span class="btns">
            <button class="btn-sm" onclick="openNotice(${n.id})">보기</button>
            <button class="btn-sm" onclick="openNoticeDrawer(${n.id})">수정</button>
            <button class="btn-sm danger" onclick="deleteNotice(${n.id})">삭제</button>
          </span>
        </div>`).join("")+`</div>`
      :`<div class="list-card"><div class="panel-empty">등록된 공지가 없습니다.</div></div>`);
  }catch(e){ box.innerHTML=`<div class="panel-empty">${esc(e.message)}</div>`; }
}

async function renderManageAdmins(box){
  try{
    const d=await bapi("api/admins.php");
    const picked=d.rows.map(r=>r.email.toLowerCase());
    const opts=d.members.filter(m=>!picked.includes(m.email.toLowerCase()))
      .map(m=>`<option value="${esc(m.email)}">${esc(m.name)} · ${esc(m.email)}</option>`).join("");
    box.innerHTML=
      `<div class="list-card" style="margin-bottom:16px">`+d.rows.map(r=>`
        <div class="mrow">
          <span class="tt">
            <div class="nm">${esc(r.name)} ${r.is_owner?`<span class="tag-owner">고정</span>`:""}</div>
            <div class="sub">${esc(r.email)}</div>
          </span>
          <span class="btns">
            <button class="btn-sm danger" onclick="removeAdmin('${esc(r.email)}')"${r.is_owner?" disabled title='고정 관리자는 뺄 수 없습니다'":""}>빼기</button>
          </span>
        </div>`).join("")+`</div>`+
      (opts?`<div class="mrow" style="background:var(--card);border:1px solid var(--line);border-radius:16px">
         <span class="tt"><select id="mg-newadmin" style="width:100%;font-family:var(--sans);font-size:14px;padding:9px 11px;border:1.5px solid var(--line);border-radius:10px;background:#FBFCFF">${opts}</select></span>
         <span class="btns"><button class="btn-sm" onclick="addAdmin()">관리자로 추가</button></span>
       </div>`
      :`<div class="panel-empty">포털 계정 전원이 이미 관리자입니다.</div>`)+
      `<div class="hintline" style="margin-top:12px">관리자는 공지와 중요 일정을 등록·수정·삭제할 수 있습니다. '고정' 은 코드에 박아 둔 사람이라 화면에서 뺄 수 없습니다.</div>`;
  }catch(e){ box.innerHTML=`<div class="panel-empty">${esc(e.message)}</div>`; }
}

async function addAdmin(){
  const sel=document.getElementById("mg-newadmin");
  if(!sel || !sel.value) return;
  try{
    await bapi("api/admins.php",{method:"POST",body:JSON.stringify({email:sel.value})});
    toast("관리자로 추가했습니다");
    showManage("admins");
  }catch(e){ toast(e.message); }
}

async function removeAdmin(email){
  if(!confirm(`${email} 를 관리자에서 뺄까요?`)) return;
  try{
    await bapi(`api/admins.php?email=${encodeURIComponent(email)}`,{method:"DELETE"});
    toast("관리자에서 뺐습니다");
    showManage("admins");
  }catch(e){ toast(e.message); }
}

/* ---- 일정 분류 낱말 설정 ----
   소스를 고치지 않고 낱말만 늘리고 줄일 수 있게 한 화면이다.
   종류의 순서(우선순위)와 아이콘은 코드가 쥐고 있고, 여기서는 낱말만 만진다.
   폭죽을 쏠지는 일정마다 고른다 — 여기가 아니라 일정 등록 창에 있다. */
async function openKwModal(){
  const err=document.getElementById("kw-err");
  const list=document.getElementById("kw-list");
  err.classList.add("hidden");
  list.innerHTML=`<div class="panel-empty">불러오는 중…</div>`;
  document.getElementById("kw-modal").classList.remove("hidden");
  try{
    const d=await bapi("api/event_words.php");
    list.innerHTML=d.rows.map(r=>`
      <div class="fld kw-row">
        <label>
          <i class="ki">${r.icon}</i>${esc(r.label)}
          ${r.overriden?`<em class="kw-tag">고침</em>`:""}
          <button type="button" class="kw-reset" onclick="resetKwWords('${r.kind}')"
            title="코드 기본값으로 되돌립니다">기본값</button>
        </label>
        <textarea id="kw-${r.kind}" rows="2"
          data-default="${esc(r.default)}">${esc(r.words)}</textarea>
      </div>`).join("");
  }catch(e){ list.innerHTML=`<div class="panel-empty">${esc(e.message)}</div>`; }
}

function closeKwModal(){
  document.getElementById("kw-modal").classList.add("hidden");
}

/* 되돌리기는 저장까지 하지 않고 칸만 기본값으로 채운다 —
   저장을 눌러야 바뀌는 편이 취소할 여지가 있어 안전하다. */
function resetKwWords(kind){
  const ta=document.getElementById("kw-"+kind);
  if(ta) ta.value=ta.dataset.default;
}

async function saveKwWords(){
  const err=document.getElementById("kw-err");
  const btn=document.getElementById("kw-save");
  err.classList.add("hidden");
  btn.disabled=true;
  try{
    const body={};
    document.querySelectorAll("#kw-list textarea").forEach(ta=>{
      body[ta.id.replace(/^kw-/,"")]=ta.value;
    });
    await bapi("api/event_words.php",{method:"PUT",body:JSON.stringify(body)});
    closeKwModal();
    toast("일정 분류 낱말을 저장했습니다");
    // 이미 등록된 일정의 아이콘·색이 그 자리에서 바뀌어야 납득이 된다
    showManage("events");
    loadBoard();
  }catch(e){
    err.textContent=e.message; err.classList.remove("hidden");
  }finally{ btn.disabled=false; }
}

/* ---- 일정 등록 / 수정 ---- */
let evId=0;
function openEventModal(id){
  evId=id||0;
  const err=document.getElementById("ev-err");
  err.classList.add("hidden");
  document.getElementById("ev-title").textContent=evId?"일정 수정":"일정 등록";
  document.getElementById("ev-modal").classList.remove("hidden");

  const set=(k,v)=>{document.getElementById(k).value=v||"";};
  if(!evId){
    set("ev-name",""); set("ev-start",""); set("ev-end",""); set("ev-place",""); set("ev-memo","");
    set("ev-cheer","0"); set("ev-cheer-early","1"); syncEvCheer();
    document.getElementById("ev-name").focus();
    return;
  }
  // 목록 전체를 받아 그중 하나를 고른다. 일정은 많아야 수십 건이라 따로 단건 API 를 두지 않았다.
  bapi("api/events.php?scope=all").then(d=>{
    const e=d.rows.find(x=>x.id===evId);
    if(!e){ toast("일정을 찾을 수 없습니다"); closeEventModal(); return; }
    set("ev-name",e.title); set("ev-start",e.starts_on); set("ev-end",e.ends_on);
    set("ev-place",e.place); set("ev-memo",e.memo);
    set("ev-cheer",String(e.cheer_on||0)); set("ev-cheer-early",String(e.cheer_early ?? 1));
    syncEvCheer();
  }).catch(e=>toast(e.message));
}

/* 폭죽을 끄면 '미리 축하' 는 물어볼 것이 없다. 감추지 않고 잠그기만 한다 —
   사라지면 그런 설정이 있었다는 것도 모른다. */
function syncEvCheer(){
  const on=document.getElementById("ev-cheer").value==="1";
  document.getElementById("ev-early-row").classList.toggle("off", !on);
  document.getElementById("ev-cheer-early").disabled=!on;
}

function closeEventModal(){
  document.getElementById("ev-modal").classList.add("hidden");
}

async function saveEvent(){
  const err=document.getElementById("ev-err");
  const btn=document.getElementById("ev-save");
  err.classList.add("hidden");
  btn.disabled=true;
  try{
    const body=JSON.stringify({
      title:document.getElementById("ev-name").value.trim(),
      starts_on:document.getElementById("ev-start").value,
      ends_on:document.getElementById("ev-end").value,
      place:document.getElementById("ev-place").value.trim(),
      memo:document.getElementById("ev-memo").value.trim(),
      cheer_on:document.getElementById("ev-cheer").value,
      cheer_early:document.getElementById("ev-cheer-early").value
    });
    if(evId) await bapi(`api/events.php?id=${evId}`,{method:"PUT",body});
    else     await bapi("api/events.php",{method:"POST",body});
    closeEventModal();
    toast(evId?"일정을 수정했습니다":"일정을 등록했습니다");
    refreshAfterEvent();
  }catch(e){
    err.textContent=e.message;
    err.classList.remove("hidden");
  }finally{
    btn.disabled=false;
  }
}

async function deleteEvent(id){
  if(!confirm("이 일정을 삭제할까요?")) return;
  try{
    await bapi(`api/events.php?id=${id}`,{method:"DELETE"});
    toast("일정을 삭제했습니다");
    refreshAfterEvent();
  }catch(e){ toast(e.message); }
}

/** 일정을 고친 뒤 지금 보고 있는 화면만 다시 그린다. */
function refreshAfterEvent(){
  if(!document.getElementById("view-manage").classList.contains("hidden")) showManage("events");
  else loadBoard();
}

/* 겹쳐 뜬 창은 바깥을 누르거나 Esc 로 닫는다.
   여러 겹이면 맨 위 하나만 닫는다 — 상세를 보다 수정을 열었을 때
   Esc 한 번에 둘 다 닫히면 당황스럽다. */
const OVERLAYS=[
  ["ne-modal", closeNoticeDrawer],
  ["ev-modal", closeEventModal],
  ["nd-modal", closeNoticeModal]
];
OVERLAYS.forEach(([id,close])=>{
  document.getElementById(id).addEventListener("click",e=>{ if(e.target.id===id) close(); });
});
document.addEventListener("keydown",e=>{
  if(e.key!=="Escape") return;
  for(const [id,close] of OVERLAYS){
    if(!document.getElementById(id).classList.contains("hidden")){ close(); return; }
  }
});

/* =====================================================================
   날씨 위젯 + 대시보드 배경

   Open-Meteo 는 열쇠 없이 쓰는 무료 서비스이고 CORS 를 열어 두어서
   브라우저가 바로 부를 수 있다. 서버를 거치지 않으므로 사내망이 밖으로
   못 나가도 각자의 브라우저에서는 뜬다.
   ===================================================================== */

/* WMO 날씨 코드 → 우리가 쓰는 갈래.
   https://open-meteo.com/en/docs 의 weather_code 표를 묶은 것이다. */
function wxKind(code, isDay){
  if(code===0)                      return isDay ? "clear" : "night";
  if(code===1||code===2)            return isDay ? "partly" : "night";
  if(code===3)                      return "cloud";
  if(code===45||code===48)          return "fog";
  if(code>=51&&code<=57)            return "drizzle";
  if(code>=61&&code<=67)            return "rain";
  if(code>=71&&code<=77)            return "snow";
  if(code>=80&&code<=82)            return "rain";
  if(code===85||code===86)          return "snow";
  if(code>=95)                      return "storm";
  return "cloud";
}
const WX_TEXT={clear:"맑음",night:"맑은 밤",partly:"구름 조금",cloud:"흐림",
               fog:"안개",drizzle:"이슬비",rain:"비",snow:"눈",storm:"뇌우"};

/* 그림은 선으로만 그린다 — 이모지는 기기마다 모양이 달라 위젯이 들쭉날쭉해진다. */
const WX_ICON={
  clear:`<circle cx="12" cy="12" r="4.6"/><path d="M12 2.4v2.2M12 19.4v2.2M2.4 12h2.2M19.4 12h2.2M5.2 5.2l1.6 1.6M17.2 17.2l1.6 1.6M18.8 5.2l-1.6 1.6M6.8 17.2l-1.6 1.6"/>`,
  night:`<path d="M20 14.6A8.4 8.4 0 0 1 9.4 4 8.4 8.4 0 1 0 20 14.6z"/>`,
  partly:`<circle cx="8.4" cy="8.4" r="3.4"/><path d="M8.4 1.8v1.7M1.8 8.4h1.7M3.9 3.9l1.2 1.2M12.9 3.9l-1.2 1.2"/><path d="M17.6 20.5H9.1a3.9 3.9 0 0 1-.4-7.8 5.3 5.3 0 0 1 10.1 1.2 3.4 3.4 0 0 1-1.2 6.6z"/>`,
  cloud:`<path d="M17.6 19.5H8.4a4.2 4.2 0 0 1-.4-8.4 5.7 5.7 0 0 1 10.9 1.3 3.7 3.7 0 0 1-1.3 7.1z"/>`,
  fog:`<path d="M17 13.5H8.2a3.9 3.9 0 0 1-.4-7.8 5.3 5.3 0 0 1 10.1 1.2 3.4 3.4 0 0 1-.9 6.6z"/><path d="M4 17.5h16M6.5 21h11"/>`,
  drizzle:`<path d="M17.2 14.4H8.4a3.9 3.9 0 0 1-.4-7.8 5.3 5.3 0 0 1 10.1 1.2 3.4 3.4 0 0 1-.9 6.6z"/><path d="M9.2 18v1.6M13 17.6v2.2M16.8 18v1.6"/>`,
  rain:`<path d="M17.2 13.6H8.4A3.9 3.9 0 0 1 8 5.8a5.3 5.3 0 0 1 10.1 1.2 3.4 3.4 0 0 1-.9 6.6z"/><path d="M8.8 16.6 7.6 20M13 16.6 11.8 20M17.2 16.6 16 20"/>`,
  snow:`<path d="M17.2 13.6H8.4A3.9 3.9 0 0 1 8 5.8a5.3 5.3 0 0 1 10.1 1.2 3.4 3.4 0 0 1-.9 6.6z"/><path d="M9 17.6v.02M13 17v.02M17 17.6v.02M11 20.4v.02M15 20.4v.02"/>`,
  storm:`<path d="M17.2 13.2H8.4A3.9 3.9 0 0 1 8 5.4a5.3 5.3 0 0 1 10.1 1.2 3.4 3.4 0 0 1-.9 6.6z"/><path d="m12.6 15.4-2.4 3.6h3l-2 3.4"/>`
};

function wxSvg(kind){
  return `<svg viewBox="0 0 24 24" aria-hidden="true">${WX_ICON[kind]||WX_ICON.cloud}</svg>`;
}

let wxKindNow="";
async function loadWeather(){
  const box=document.getElementById("wx");
  if(!box) return;
  const url="https://api.open-meteo.com/v1/forecast"+
    `?latitude=${OFFICE.lat}&longitude=${OFFICE.lon}`+
    "&current=temperature_2m,apparent_temperature,weather_code,is_day"+
    "&daily=temperature_2m_max,temperature_2m_min"+
    "&timezone=Asia%2FSeoul&forecast_days=1";
  try{
    const r=await fetch(url);
    if(!r.ok) throw new Error("HTTP "+r.status);
    const w=await r.json();
    const c=w.current, dmax=w.daily.temperature_2m_max[0], dmin=w.daily.temperature_2m_min[0];
    const kind=wxKind(c.weather_code, c.is_day===1);
    wxKindNow=kind;
    box.className="wx wx-"+kind;
    box.innerHTML=
      `<div class="wx-ic">${wxSvg(kind)}</div>`+
      `<div class="wx-t">${Math.round(c.temperature_2m)}<span>°</span></div>`+
      `<div class="wx-s">${esc(WX_TEXT[kind]||"")}</div>`+
      `<div class="wx-b"><span>${esc(OFFICE.label)}</span>`+
        `<span class="wx-mm">${Math.round(dmin)}° / ${Math.round(dmax)}°</span></div>`;
    applyBg();
  }catch(e){
    // 날씨는 있으면 좋은 것이라 실패해도 화면을 막지 않는다. 칸만 조용히 비운다.
    box.className="wx wx-off";
    box.innerHTML=`<div class="wx-ic">${wxSvg("cloud")}</div>`+
                  `<div class="wx-s">날씨를 불러오지 못했습니다</div>`;
  }
}

/* ---- 대시보드 배경 ---- */
function applyBg(){
  const on = document.body.dataset.bg !== "plain";
  document.body.classList.toggle("bg-on", on && !!wxKindNow);
  document.body.dataset.wx = on ? (wxKindNow||"") : "";
  paintBgBtn();
}

const BG_ICON={
  weather:`<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6 7 7M17 17l1.4 1.4M18.4 5.6 17 7M7 17l-1.4 1.4"/></svg>`,
  plain:`<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="14" rx="3"/><path d="M3.5 15.5 9 11l4 3.2 3.2-2.4 4.3 3.4"/></svg>`
};
function paintBgBtn(){
  const b=document.getElementById("bgBtn");
  if(!b) return;
  const on = document.body.dataset.bg !== "plain";
  b.innerHTML = on ? BG_ICON.weather : BG_ICON.plain;
  b.classList.toggle("on", on);
  b.title = on ? "배경: 날씨에 따라 — 눌러서 끄기" : "배경: 기본 — 눌러서 날씨에 맞추기";
  b.setAttribute("aria-pressed", String(on));
}

async function toggleBg(){
  const next = document.body.dataset.bg === "plain" ? "weather" : "plain";
  document.body.dataset.bg = next;
  applyBg();
  try{
    await bapi("api/me.php",{method:"PUT",body:JSON.stringify({bg_pref:next})});
    toast(next==="plain" ? "기본 배경으로 두었습니다" : "배경을 날씨에 맞춥니다");
  }catch(e){
    toast("설정을 저장하지 못했습니다: "+e.message);
  }
}

let toastT;
function toast(m){const el=document.getElementById("toast");el.textContent=m;el.classList.add("show");
  clearTimeout(toastT);toastT=setTimeout(()=>el.classList.remove("show"),2200);}

/* eye icon inject */
document.querySelectorAll('.eye').forEach(b=>{
  b.innerHTML=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>`;
});

/* ---- 챗봇 ---- */
const CHAT_API="/chatapi"; // nginx 가 chatbot 서버(8003)로 넘긴다
let chatBusy=false;

/* POST /ask 는 GET /conversations 가 발급한 쿠키를 요구한다. 부트스트랩 약속을
   하나만 들고 있다가 열기·보내기가 같이 기다리게 해서, 패널을 열기 전에 친 첫
   메시지가 404 로 튕기거나 두 번 열었을 때 이력이 겹쳐 그려지는 일을 막는다. */
let chatReady=null;

function chatBootstrap(){
  if(!chatReady) chatReady=chatLoadConversation().catch(e=>{ chatReady=null; throw e; });
  return chatReady;
}
async function chatLoadConversation(){
  const r=await fetch(`${CHAT_API}/conversations`,{credentials:"same-origin"});
  if(!r.ok) throw new Error(`대화를 시작하지 못했습니다. (HTTP ${r.status})`);
  const d=await r.json().catch(()=>({}));
  chatRestore(d.messages || []);
}
function chatRestore(messages){
  document.getElementById("chat-log").innerHTML="";
  for(const m of messages) chatAppend(m.role==="user" ? "me" : "bot", m.content);
  if(!messages.length) chatAppend("bot", `${current?current.name+"님, ":""}무엇을 도와드릴까요?`);
}

function setChatVisible(on){
  document.getElementById("chat-fab").classList.toggle("hidden", !on);
  if(!on) closeChat();
}
function openChat(){
  document.getElementById("chat-panel").classList.remove("hidden");
  document.getElementById("chat-fab").classList.add("open");
  chatBootstrap().catch(e=>chatAppend("err", e.message)); // 실패해도 보낼 때 다시 시도한다
  document.getElementById("chat-input").focus();
}
function closeChat(){
  document.getElementById("chat-panel").classList.add("hidden");
  document.getElementById("chat-fab").classList.remove("open");
}
function toggleChat(){
  if(document.getElementById("chat-panel").classList.contains("hidden")) openChat();
  else closeChat();
}

function chatAppend(cls, text){
  const log=document.getElementById("chat-log");
  const el=document.createElement("div");
  el.className="chat-msg "+cls;
  el.textContent=text; // 서버가 준 문자열이라 HTML 로 해석시키지 않는다
  log.appendChild(el);
  log.scrollTop=log.scrollHeight;
}
function chatTypingOn(){
  const log=document.getElementById("chat-log");
  const el=document.createElement("div");
  el.className="chat-typing"; el.id="chat-typing";
  el.innerHTML="<i></i><i></i><i></i>";
  log.appendChild(el);
  log.scrollTop=log.scrollHeight;
}
function chatTypingOff(){
  const el=document.getElementById("chat-typing");
  if(el) el.remove();
}

function chatGrow(el){
  el.style.height="auto";
  el.style.height=Math.min(el.scrollHeight,96)+"px";
}
function chatKeydown(e){
  if(e.key==="Enter" && !e.shiftKey){ e.preventDefault(); sendChat(); }
}

async function sendChat(){
  if(chatBusy) return;
  const box=document.getElementById("chat-input");
  const text=box.value.trim();
  if(!text) return;
  chatBusy=true;
  document.getElementById("chat-send").disabled=true;
  try{
    await chatBootstrap(); // 쿠키가 있어야 /ask 가 받는다. 실패하면 입력은 남겨 둔다
    box.value=""; chatGrow(box);
    chatAppend("me", text);
    chatTypingOn();
    chatAppend("bot", await askBot(text));
  }catch(e){
    chatAppend("err", e.message || "답변을 가져오지 못했습니다. 잠시 후 다시 시도해 주세요.");
  }finally{
    chatTypingOff();
    chatBusy=false;
    document.getElementById("chat-send").disabled=false;
    box.focus();
  }
}

async function askBot(text, retried){
  const r=await fetch(`${CHAT_API}/ask`,{method:"POST",credentials:"same-origin",
    headers:{"Content-Type":"application/json"},body:JSON.stringify({question:text})});
  const d=await r.json().catch(()=>({}));
  if(r.ok){
    if(!d.content) throw new Error("답변이 비어 있습니다.");
    return d.content; // matched_id 도 함께 오지만 화면에는 쓰지 않는다
  }
  /* 대화가 없거나(404) 만료됐으면(409) 새 대화를 발급받아 딱 한 번만 다시 보낸다.
     chatBootstrap 이 로그를 비우고 다시 그리므로 안내와 질문은 그 뒤에 얹는다. */
  if((r.status===404 || r.status===409) && !retried){
    chatTypingOff();
    chatReady=null;
    await chatBootstrap();
    if(r.status===409) chatAppend("err", "대화가 만료되어 새로 시작했습니다.");
    chatAppend("me", text);
    chatTypingOn();
    return askBot(text, true);
  }
  // 새 대화로도 안 되면 서버 안내문(개발자용 문구)을 그대로 보여주지 않는다
  if(r.status===404 || r.status===409) throw new Error("대화를 이어갈 수 없습니다. 페이지를 새로고침해 주세요.");
  const detail=typeof d.detail==="string" ? d.detail : null; // 422 의 detail 은 배열이다
  if(r.status===429){
    const after=parseInt(r.headers.get("retry-after"), 10);
    throw new Error(after>0 ? `요청이 많습니다. ${after}초 후에 다시 시도해 주세요.`
      : detail || "요청이 많습니다. 잠시 후 다시 시도해 주세요.");
  }
  throw new Error(detail || `답변을 가져오지 못했습니다. (HTTP ${r.status})`);
}

(function(){
  document.getElementById("chat-botav").innerHTML=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 4v4M9 14h.01M15 14h.01"/></svg>`;
  document.getElementById("chat-fab").innerHTML=
    `<span class="ic-open"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.5 8.6 8.6 0 0 1-3.9-.9L3 21l1.9-5.6A8.4 8.4 0 0 1 12.5 3 8.4 8.4 0 0 1 21 11.5z"/></svg></span>`+
    `<span class="ic-close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></span>`;
  document.getElementById("chat-send").innerHTML=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>`;
})();

/* ---- 로그인 여부는 PHP가 이미 판단해서 화면/현재사용자(current)를 내려줬다 ----
   반드시 스크립트의 모든 선언(const/let/function) 다음, 맨 마지막에 실행해야 한다.
   위쪽에서 실행하면 아직 초기화 안 된 뒤쪽의 const/let(arrow, toastT 등)을
   먼저 참조하게 돼 TDZ ReferenceError로 스크립트 전체가 죽는다. */
/* 모듈에서 미로그인으로 튕겨 왔으면 왜 튕겼는지 알린다. need_login 만 지운다 —
   pathname 으로 싹 지우면 enterApp() 이 읽는 need_token/view=profile 까지 날아간다. */
if(NOTICE){
  const p=new URLSearchParams(location.search);
  p.delete("need_login");
  history.replaceState(null,"",location.pathname+(p.toString()?"?"+p:""));
  toast(NOTICE);
}
if(current){ renderShell(); paintBgBtn(); enterApp(); }
</script>
</body>
</html>
