<?php
/**
 * 공통 상단바의 사용자 메뉴(이름 칩 + 드롭다운) — 포털 메인과 모든 모듈이 이 하나를 쓴다.
 *
 * 예전에는 화면마다 드롭다운을 손으로 적어서 항목이 제각각이었다(공지사항·포털 관리·
 * 배포 허용은 메인에만, 대시보드는 moodle·onwatch 에만 …). 항목을 더하거나 빼려면
 * 여기만 고친다.
 *
 *     <?= portal_user_menu('../', 'book') ?>
 *
 * 항목 순서(메인 기준)
 *   마이페이지 · [서버 일괄 배포 허용] · 공지사항 · [포털 관리] · (모듈 전용 항목)
 *   ── 업무 시스템 ──
 *   [앱으로 설치] · 로그아웃
 * [ ] 는 조건부다 — 배포 가능 사용자, 포털 관리자, 설치할 수 있는 브라우저.
 *
 * 메인에서는 화면을 갈아 끼우고(index.php 가 data-um 을 보고 처리), 모듈에서는
 * 포털의 ?view=… 로 넘어간다. 그래서 링크는 늘 진짜 주소를 달고 있다.
 *
 * worksystems.php 가 끝에서 이 파일을 부르므로 모듈은 따로 require 하지 않아도 된다.
 */
require_once __DIR__ . '/worksystems.php';

/**
 * 지금 로그인한 사람 {name, color, email, admin, deploy}. 모르면 null.
 *
 * current_portal_user() 를 쓰지 않는 이유는 work_systems_menu_data() 와 같다 —
 * SSO 쿠키를 다시 굽는데, 메뉴는 본문 출력이 시작된 뒤에 그려진다.
 * 메뉴는 곁가지라 여기서 실패해도 화면은 떠야 한다.
 */
function portal_user_menu_me(): ?array {
    static $me = false;
    if ($me !== false) {
        return $me;
    }
    $me  = null;
    $uid = (int)($_SESSION['portal_uid'] ?? 0);
    if (!$uid || !function_exists('current_portal_user')) {
        return $me;
    }
    try {
        $st = portal_db()->prepare("SELECT name, email, color FROM portal_users WHERE id = ?");
        $st->execute([$uid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $me;
        }
        $me = ['name' => (string)$row['name'], 'color' => user_color($row), 'email' => (string)$row['email'],
               'admin' => false, 'deploy' => false];
        // 보여 줄지만 정한다. 실제 차단은 각 API 가 다시 한다.
        require_once __DIR__ . '/board.php';
        require_once __DIR__ . '/deploy.php';
        $me['admin']  = (bool)board_is_admin($me['email']);
        $me['deploy'] = (bool)deploy_is_member($me['email']);
    } catch (Throwable $e) {
        error_log('[usermenu] 사용자 확인: ' . $e->getMessage());
    }
    return $me;
}

/**
 * 사용자 메뉴 HTML(.user-menu 통째). .top-right 안에 넣는다.
 *
 * $base     이 페이지에서 포털 루트까지 되짚는 접두사(포털 = '', 모듈 = '../' …)
 * $current  자기 모듈 key — 업무 시스템 목록에서 현재 위치로 표시된다
 * $opt
 *   'portal' => true   포털 메인. 업무 시스템을 새 탭으로 열고, 로그인 전에도 껍데기를 그린다
 *   'name', 'color'    칩에 적을 이름·색. 안 주면 로그인한 사람 것(모듈이 '사용자로 보기' 를 할 때만 준다)
 *   'extra'            모듈 전용 항목 [['label' => '📊 내 프로파일', 'href' => 'member_profile.php'], …]
 */
function portal_user_menu(string $base = '', string $current = '', array $opt = []): string {
    $h      = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $portal = !empty($opt['portal']);
    $me     = portal_user_menu_me();
    $name   = (string)($opt['name'] ?? ($me['name'] ?? ''));
    if ($name === '' && !$portal) {
        return '';
    }
    $color = (string)($opt['color'] ?? ($me['color'] ?? user_menu_fallback_color()));
    $home  = $base . 'index.php';

    $item = function (string $key, string $label, string $href, bool $hidden = false) use ($h) {
        return '<a href="' . $h($href) . '" data-um="' . $key . '"' . ($hidden ? ' hidden' : '') . '>' . $label . '</a>';
    };

    $html = '<div class="user-menu" id="hdrUserMenu">'
        . '<div class="user-chip" title="메뉴">'
        . '<span class="avatar" style="background:' . $h($color) . '">' . $h(mb_substr($name, 0, 1)) . '</span>'
        . '<span class="nm">' . $h($name) . '</span>'
        . '<span class="user-caret">▾</span>'
        . '</div>'
        . '<div class="dd-menu" id="hdrUserDd">'
        . $item('profile', '👤 마이페이지', $home . '?view=profile')
        . (!empty($me['deploy']) ? $item('deploy', '🚀 서버 일괄 배포 허용', $home . '?view=deploy') : '')
        . $item('notices', '📢 공지사항', $home . '?view=notices')
        . (!empty($me['admin']) ? $item('manage', '⚙️ 포털 관리', $home . '?view=manage') : '');
    foreach ((array)($opt['extra'] ?? []) as $x) {
        $html .= '<a href="' . $h($x['href']) . '">' . $h($x['label']) . '</a>';
    }
    $html .= '<div class="dd-sep"></div>'
        . work_systems_menu($base, $current, $portal)
        . '<div class="dd-sep"></div>'
        // 설치할 수 있을 때만 보인다(pwa.js 의 bluePwa). 안내 띠에서 '다음에' 를 눌렀어도 여기서 다시 설치할 수 있다
        . $item('install', '📲 앱으로 설치', '#', true)
        . $item('logout', '🚪 로그아웃', $base . 'api/logout.php')
        . '</div></div>';

    return $html . portal_user_menu_script();
}

/** 저장된 색도 이름도 없을 때의 아바타 색 */
function user_menu_fallback_color(): string {
    return '#606D79';
}

/**
 * 여닫기·앱 설치 동작. 한 페이지에 한 번만 붙는다.
 * 모듈마다 toggleUserMenu 를 따로 두던 것을 여기로 모았다.
 */
function portal_user_menu_script(): string {
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    return <<<'HTML'
<script>
(function () {
  var um = document.getElementById("hdrUserMenu");
  if (!um) return;
  var chip = um.querySelector(".user-chip");
  function set(open) { um.classList.toggle("open", open); }
  chip.addEventListener("click", function () { set(!um.classList.contains("open")); });
  // 바깥을 누르거나 항목을 고르면 닫는다
  document.addEventListener("click", function (e) {
    if (!um.contains(e.target) || e.target.closest(".dd-menu a")) set(false);
  });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape") set(false); });

  var ins = um.querySelector('[data-um="install"]');
  function sync() { ins.hidden = !(window.bluePwa && window.bluePwa.available()); }
  if (ins) {
    ins.addEventListener("click", function (e) {
      e.preventDefault();
      if (window.bluePwa) window.bluePwa.install();
    });
    document.addEventListener("bluepwa:change", sync);
    window.addEventListener("load", sync);
    sync();
  }
})();
</script>
HTML;
}
