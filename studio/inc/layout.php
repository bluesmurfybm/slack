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
 * @param string $nav     현재 위치 표시용 화면 키. bs_layout_nav() 의 키와 맞춘다
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
<link rel="icon" href="../styles/favicon.ico">
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
      <?= bs_layout_nav($nav) ?>
    </div>
  </div>
</header>

<div class="ba" id="ba-app"
     data-csrf="<?= h(bs_csrf_token()) ?>"
     data-me-id="<?= h($user['id']) ?>"
     data-me-name="<?= h($user['name']) ?>"
     data-is-admin="<?= bs_is_admin() ? '1' : '0' ?>"
     data-nav="<?= h($nav) ?>">
<?php
}

/**
 * 모듈 안 화면 이동 탭.
 *
 * TODO(P1): 권한에 따라 감출 항목을 정한다. 지금은 전부 보여준다.
 *           (구성원 목록·프로파일은 열람 범위가 아직 미정 — spec §11-4)
 */
function bs_layout_nav(string $current = ''): string
{
    $items = [
        'dashboard' => ['index.php',        '대시보드'],
        'project'   => ['project_list.php', '프로젝트'],
        'member'    => ['member_list.php',  '구성원'],
    ];
    // 외부 연동은 관리자만 쓴다. 다른 사람에게는 눌러 봐야 튕기는 줄이라
    // 아예 그리지 않는다. 막는 쪽은 settings.php 와 API 가 따로 한다.
    if (bs_is_admin()) {
        $items['settings'] = ['settings.php', '연동'];
    }

    $html = '<nav class="ba-nav" aria-label="화면 이동">';
    foreach ($items as $key => [$href, $label]) {
        $on = $key === $current;
        $html .= '<a href="' . h($href) . '"'
               . ($on ? ' class="on" aria-current="page"' : '')
               . '>' . h($label) . '</a>';
    }
    return $html . '</nav>';
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
