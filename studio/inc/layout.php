<?php
/** BlueStudio 화면 공통 레이아웃 — 포털 상단바·모듈 머리말·꼬리말. 모든 화면이 이걸 쓴다. */

declare(strict_types=1);

/**
 * 화면 상단(<head> ~ 모듈 머리말 ~ 본문 컨테이너 시작)을 그린다.
 *
 * BlueCart 는 화면이 하나뿐이라 index.php 안에 상단바를 직접 박아 뒀지만,
 * 이 모듈은 화면이 여섯이라 한 곳으로 뺐다. 마크업과 클래스 이름은
 * bluecart/index.php 의 것을 그대로 옮긴 것이다 — 포털 styles/topbar.css 가
 * .topbar / .user-chip / .dd-menu 를 그 이름으로 그린다.
 *
 * @param array  $user    bs_require_login() 이 돌려준 사용자
 * @param string $title   브라우저 탭 제목(모듈명은 함수가 붙인다)
 * @param string $eyebrow 머리말 위 작은 글씨
 * @param string $desc    머리말 설명문
 * @param string $nav     현재 위치 표시용 화면 키. BS_NAV_GROUPS 의 키와 맞춘다
 */
function bs_layout_head(
    array $user,
    string $title,
    string $eyebrow = '과업 편성/현황',
    string $desc = '',
    string $nav = ''
): void {
    $avatarChar  = mb_substr($user['name'] !== '' ? $user['name'] : '?', 0, 1);
    $avatarColor = $user['color'] ?? '#63719A';
    ?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title) ?> · BlueStudio</title>
<link rel="icon" type="image/svg+xml" href="../styles/favicons/studio.svg">
<?php require_once __DIR__ . '/../../core/pwa.php'; echo pwa_head_tags('../'); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
<link rel="stylesheet" href="../styles/topbar.css">
<link rel="stylesheet" href="assets/assign.css?v=<?= bs_asset_v('assets/assign.css') ?>">
</head>
<body>

<!-- ================= 포털 공통 상단바 ================= -->
<div class="topbar">
  <div class="topbar-in">
    <a class="logo" href="../index.php" style="text-decoration:none">
      <b>blue</b><span class="dash">-</span>iWorks
    </a>
    <div class="top-right">
      <div class="user-menu" id="hdrUserMenu">
        <div class="user-chip" onclick="baToggleUserMenu(event)" title="메뉴">
          <span class="avatar" style="background:<?= h($avatarColor) ?>"><?= h($avatarChar) ?></span>
          <span class="nm"><?= h($user['name']) ?></span>
          <span class="user-caret">&#9662;</span>
        </div>
        <div class="dd-menu" id="hdrUserDd">
          <a href="../index.php">&#128100; 마이페이지</a>
          <?php // 구성원 목록이 관리자 묶음으로 가서(2026-10-16) 본인 프로파일 가는 길을 여기 둔다. ?>
          <a href="member_profile.php">&#128202; 내 프로파일</a>
          <div class="dd-sep"></div>
          <?php // core/worksystems.php — studio 가 현재 위치로 표시된다 ?>
          <?= work_systems_menu('../', BS_MODULE_KEY) ?>
          <div class="dd-sep"></div>
          <a href="javascript:void(0)" onclick="baLogout()">&#128682; 로그아웃</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($user['view_as'])): ?>
<!-- 사용자로 보기 중 (2026-10-12). 보는 동안 내내 떠 있어야 한다 — 내 화면으로
     착각하고 "왜 저장이 안 되지" 하지 않게. -->
<div class="ba-viewas" role="status">
  <span>&#128065; <b><?= h($user['name']) ?></b> 님으로 보는 중입니다 —
    보기만 되고 저장은 막혀 있습니다. <span class="ba-dim">(실제 로그인: <?= h($user['real_name']) ?>)</span></span>
  <button type="button" class="ba-btn ba-btn--sm" id="ba-viewas-stop">돌아가기</button>
</div>
<?php endif; ?>

<!-- ================= 모듈 머리말 ================= -->
<header class="ba-site">
  <div class="ba-site__in">
    <div class="ba-site__lead">
      <span class="ba-site__eyebrow"><?= h($eyebrow) ?></span>
      <h1 class="ba-site__title">BlueStudio</h1>
      <?php if ($desc !== ''): ?>
      <p class="ba-site__desc"><?= h($desc) ?></p>
      <?php endif; ?>
    </div>
    <div class="ba-site__aside">
      <?= bs_layout_mode($nav) ?>
    </div>
  </div>
  <?= BS_SUBNAV_STYLE === 'band' ? bs_layout_subnav($nav) : '' ?>
</header>

<div class="ba" id="ba-app"
     data-csrf="<?= h(bs_csrf_token()) ?>"
     data-me-id="<?= h($user['id']) ?>"
     data-me-name="<?= h($user['name']) ?>"
     data-is-admin="<?= bs_is_admin() ? '1' : '0' ?>"
     data-nav="<?= h($nav) ?>">
  <?= BS_SUBNAV_STYLE === 'pill' ? bs_layout_subnav($nav) : '' ?>
<?php
}

/**
 * 모듈 안 화면 이동 — 대메뉴 [구성원][관리자] + 서브 메뉴 (2026-10-16, BlueCart 와 같은 짜임).
 *
 *   머리말 오른쪽  대메뉴   구성원 | 관리자          ← 관리자에게만 보인다
 *   탭 띠          구성원 → 홈(대시보드) · 프로젝트 · R&D 과제
 *                  관리자 → 구성원 관리 · 설정
 *
 * 대시보드는 글자 대신 홈 아이콘이다(2026-10-16) — 역할이 홈이고, 다른 탭과
 * 갈라 보이게. 프로젝트 | R&D 과제 는 본문 안 칩(.ba-typetabs)이었는데 이 띠로
 * 올렸다. 칩은 CSS 로 숨겨 두었다.
 *
 * 관리자가 아니면 대메뉴 없이 구성원 서브 메뉴만 그린다. 구성원 관리 화면을
 * 주소로 직접 열어도 막지는 않는다(지금껏 누구나 보던 화면이다) — 메뉴에서만
 * 뺀다. 본인 프로파일은 사용자 메뉴의 '내 프로파일' 로 간다.
 */
const BS_NAV_GROUPS = [
    'member' => ['label' => '구성원', 'items' => [
        'dashboard' => ['index.php',        '대시보드', 'home'],   // 아이콘만 그린다
        'project'   => ['project_list.php', '프로젝트'],
        'rnd'       => ['rnd_board.php',    'R&D 과제'],
    ]],
    'admin' => ['label' => '관리자', 'items' => [
        'member'    => ['member_list.php',  '구성원 관리'],
        'settings'  => ['settings.php',     '설정'],
    ]],
];

/**
 * 서브 메뉴 모양 (2026-10-16 시안).
 *   'band'  머리말 아래에 붙은 전폭 띠 — 굵은 밑줄 탭 (본문 안 탭보다 한 단계 위)
 *   'pill'  본문 맨 위 둥근 탭 — bluecart .bc-subtabs 모양 (바로 앞 버전)
 * 되돌리려면 이 값만 'pill' 로. 정해지면 쓰지 않는 쪽을 지운다.
 */
const BS_SUBNAV_STYLE = 'band';

/** 지금 화면이 속한 묶음. 관리자가 아니면 늘 구성원 묶음이다. */
function bs_nav_group(string $current): string
{
    return bs_is_admin() && isset(BS_NAV_GROUPS['admin']['items'][$current]) ? 'admin' : 'member';
}

/** 머리말 오른쪽 대메뉴. 누르면 그 묶음의 첫 화면으로 간다. */
function bs_layout_mode(string $current = ''): string
{
    if (!bs_is_admin()) {
        return '';
    }
    $group = bs_nav_group($current);
    $html  = '<nav class="ba-mode" aria-label="화면 구분">';
    foreach (BS_NAV_GROUPS as $g => $def) {
        $first = reset($def['items'])[0];
        $html .= '<a href="' . h($first) . '"'
               . ($g === $group ? ' class="on" aria-current="true"' : '')
               . '>' . h($def['label']) . '</a>';
    }
    return $html . '</nav>';
}

/** 탭에 쓰는 아이콘. 지금은 홈 하나. */
function bs_nav_icon(string $name): string
{
    return match ($name) {
        'home'  => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
                 . '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/></svg>',
        default => '',
    };
}

/** 본문 맨 위 서브 메뉴 — 고른 묶음의 화면들. */
function bs_layout_subnav(string $current = ''): string
{
    $group = bs_nav_group($current);
    $label = BS_NAV_GROUPS[$group]['label'];
    $links = '';
    foreach (BS_NAV_GROUPS[$group]['items'] as $key => $item) {
        [$href, $text] = $item;
        $icon = $item[2] ?? null;
        $cls  = trim(($icon ? 'ba-tab--icon ' : '') . ($key === $current ? 'on' : ''));
        $links .= '<a href="' . h($href) . '"'
                . ($cls !== '' ? ' class="' . $cls . '"' : '')
                . ($key === $current ? ' aria-current="page"' : '')
                . ($icon ? ' aria-label="' . h($text) . '" title="' . h($text) . '"' : '')
                . '>' . ($icon ? bs_nav_icon($icon) : h($text)) . '</a>';
    }
    if (BS_SUBNAV_STYLE === 'band') {
        return '<div class="ba-subbar"><nav class="ba-subbar__in" aria-label="' . h($label) . ' 메뉴">'
             . $links . '</nav></div>';
    }
    return '<nav class="ba-subnav" aria-label="' . h($label) . ' 메뉴">' . $links . '</nav>';
}

/**
 * 본문 컨테이너를 닫고 공통 스크립트를 붙인다.
 * 화면마다 추가 스크립트가 있으면 $extraScripts 에 경로를 넘긴다.
 */
function bs_layout_foot(array $extraScripts = []): void
{
    ?>
  <div class="ba-toast" id="ba-toast" role="status" hidden></div>
</div>

<script>
// 상단바 드롭다운 — 포털/BlueCart 와 같은 동작.
function baToggleUserMenu(e) {
  if (e) e.stopPropagation();
  document.getElementById('hdrUserMenu').classList.toggle('open');
}
document.addEventListener('click', function (e) {
  var um = document.getElementById('hdrUserMenu');
  if (um && !um.contains(e.target)) um.classList.remove('open');
});
// 로그아웃은 포털 엔드포인트를 그대로 쓴다(포털 index.php 와 같은 방식).
function baLogout() {
  fetch('../api/logout.php', { method: 'POST' })
    .catch(function () {})
    .then(function () { location.href = '../index.php'; });
}
</script>
<script src="assets/assign.js?v=<?= bs_asset_v('assets/assign.js') ?>"></script>
<?php foreach ($extraScripts as $src): ?>
<script src="<?= h($src) ?>?v=<?= bs_asset_v($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

/**
 * 가용도 한 칸. `assets/assign.js` 의 `availCell()` 과 **같은 마크업**이다.
 *
 * 두 벌로 두는 것은 좋지 않지만, 한쪽은 서버에서 그리고 한쪽은 API 응답으로
 * 그린다. 합치려면 목록 화면을 API 로 돌려야 하는데 구성원 목록은 13명짜리
 * 표라 그만한 값이 없다. **클래스 이름과 조각 구성은 반드시 맞춰 둔다** —
 * 어긋나면 같은 숫자가 두 화면에서 다르게 보인다.
 *
 * 합산 숫자만 보여 주는 자리를 만들지 않는다 (명세서 P10-2). 프로젝트·R&D·
 * 추정을 각각 적고, R&D 가 0 이면 그 조각은 쓰지 않는다 — 참여가 없는 사람
 * 화면에 쓸데없는 0% 가 늘어서는 안 된다.
 */
function bs_avail_html(?array $a): string
{
    if ($a === null) {
        return '<span class="ba-dim">—</span>';
    }
    $proj     = (int)($a['project_pct'] ?? $a['confirmed_pct'] ?? 0);
    $rnd      = (int)($a['rnd_pct'] ?? 0);
    $inferred = (int)($a['inferred_pct'] ?? 0);
    $avail    = (int)($a['available_pct'] ?? 0);
    $hasRnd   = $rnd > 0;

    $parts = ['프로젝트 ' . $proj . '%'];
    if ($hasRnd) {
        $parts[] = 'R&amp;D ' . $rnd . '%';
    }
    $parts[] = '추정 ' . $inferred . '%';

    // 반일 근무자는 기준이 100 이 아니다. 안 적으면 남는 칸이 무엇인지 알 수
    // 없다 — 점유가 아니라 애초의 근무량이다.
    $capPct = (int)round(((float)($a['base_capacity'] ?? 1.0)) * 100);
    $note   = $capPct < 100 ? ', 기준 근무 ' . $capPct . '%' : '';

    return '<div class="ba-av">'
         . '<div class="ba-av__bar">'
         . '<i class="ba-av__c" style="width:' . $proj . '%"></i>'
         . ($hasRnd ? '<i class="ba-av__r" style="width:' . $rnd . '%"></i>' : '')
         . '<i class="ba-av__i" style="width:' . $inferred . '%"></i>'
         . '</div>'
         . '<div class="ba-av__txt"><b>가용 ' . $avail . '%</b> '
         . '<span class="ba-dim">(' . implode(' + ', $parts) . ' 점유' . $note . ')</span>'
         . '</div></div>';
}

/**
 * 아직 만들지 않은 화면 자리를 채우는 표시.
 *
 * **지금 쓰는 화면은 없다.** 마지막 사용처였던 member_list.php 를 채우면서
 * 비었다. 다음 화면을 스캐폴딩할 때 쓰라고 남겨 둔다.
 */
function bs_placeholder(string $heading, string $phase, array $todo = []): void
{
    ?>
  <section class="ba-placeholder">
    <p class="ba-placeholder__phase"><?= h($phase) ?>에서 구현</p>
    <h2><?= h($heading) ?></h2>
    <?php if ($todo): ?>
    <ul class="ba-placeholder__todo">
      <?php foreach ($todo as $line): ?>
      <li><?= h($line) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
<?php
}

/**
 * 첨부 한 건의 [보기]·[내려받기] 단추 (2026-10-19). 프로젝트·R&D 가 같이 쓴다.
 * assets/assign.js 의 srcActsHtml() 와 **같은 마크업**이다 — 한쪽만 고치지 말 것.
 *
 *   보기      브라우저가 그리는 형식(이미지·PDF) 이거나 뽑아 둔 본문이 있을 때
 *   내려받기  파일로 올린 것
 *
 * $s 는 sources() 한 줄 (has_file · mime · parsed_len · id · title).
 */
function bs_source_acts_html(array $s): string
{
    $hasFile = !empty($s['has_file']);
    $canSee  = ($hasFile && in_array((string)($s['mime'] ?? ''), BS_VIEWABLE_MIME, true))
            || (int)($s['parsed_len'] ?? 0) > 0;
    $h = '';
    if ($canSee) {
        $h .= '<button type="button" class="ba-btn ba-btn--sm" data-src-view="' . (int)$s['id'] . '"'
            . ' data-mime="' . h((string)($s['mime'] ?? '')) . '" data-file="' . ($hasFile ? '1' : '0') . '"'
            . ' data-title="' . h((string)$s['title']) . '">보기</button>';
    }
    if ($hasFile) {
        $h .= '<a class="ba-btn ba-btn--sm" href="api/source_download.php?id=' . (int)$s['id'] . '">내려받기</a>';
    }
    return $h === '' ? '' : '<span class="ba-src__acts">' . $h . '</span>';
}

/**
 * 첨부 보기 창 (2026-10-19 공용으로). 이미지·PDF 는 그대로, 나머지는 뽑아 둔 본문.
 * 외부 뷰어는 쓰지 않는다 — 업무 문서를 바깥으로 보내는 일이다.
 * assign.js 의 initSourceViewer 가 [data-src-view] 단추와 이 창을 잇는다.
 */
function bs_source_viewer_html(): string
{
    return <<<'HTML'
<div class="ba-drawer" id="ba-src-viewer" hidden>
  <div class="ba-drawer__box ba-drawer__box--wide" role="dialog" aria-modal="true" aria-labelledby="ba-sv-title">
    <div class="ba-drawer__head">
      <h2 id="ba-sv-title">문서 보기</h2>
      <button type="button" class="ba-close" data-close aria-label="닫기">&times;</button>
    </div>
    <div class="ba-drawer__body ba-sv" id="ba-sv-body"></div>
    <div class="ba-drawer__foot">
      <span class="ba-dim" id="ba-sv-note"></span>
      <span class="ba-spacer"></span>
      <a class="ba-btn" id="ba-sv-dl" href="#" hidden>내려받기</a>
      <a class="ba-btn" id="ba-sv-tab" href="#" target="_blank" rel="noopener" hidden>새 창에서 열기</a>
      <button type="button" class="ba-btn" data-close>닫기</button>
    </div>
  </div>
</div>
HTML;
}

/**
 * 목록으로 돌아가는 링크 (2026-10-19). 상세·등록·수정 화면 맨 위에 같은 모양으로.
 *
 * 목록의 조회 조건(필터·쪽)은 assign.js 가 sessionStorage 에 두었다가 목록을
 * 다시 열 때 되살린다(listMemory) — 그래서 여기서는 주소만 건다.
 */
function bs_back_link(string $href, string $label): string
{
    return '<nav class="ba-back" aria-label="이동"><a href="' . h($href) . '">'
         . '<span aria-hidden="true">&lsaquo;</span> ' . h($label) . '</a></nav>';
}
