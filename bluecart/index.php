<?php
/**
 * BlueCart — 사내 물품 구매 요청. iworks 포털의 한 모듈.
 *
 * 포털 안에 있으면 포털 세션으로 로그인 상태를 판단하고, 상단바·로그아웃·
 * 업무 시스템 메뉴를 포털과 똑같이 그린다(learn/dti 와 같은 방식).
 * 포털이 없으면 단독으로도 뜬다 — 로컬 개발용.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

// 로그인 상태에 따라 다르게 그려지는 페이지라 캐시에 남으면 안 된다.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$user       = bc_require_login();
$roles      = bc_my_roles();
$showAdmin  = bc_can_see_admin();
$categories = Category::all();
$years      = PurchaseRequest::years();
$thisYear   = (int)date('Y');
$csrf       = bc_csrf_token();

$inPortal  = BC_PORTAL_ROOT !== '';
$moduleKey = (string)bc_config('iworks.module_key', 'bluecart');

// 상단바의 "업무 시스템" 목록은 포털의 worksystems.json 하나가 원본이다.
if ($inPortal) {
    require_once BC_PORTAL_ROOT . '/core/worksystems.php';
}

// 아바타 글자와 색 — 포털 상단바와 같은 규칙을 쓴다.
$avatarChar  = mb_substr($user['name'] !== '' ? $user['name'] : '?', 0, 1);
$avatarColor = $user['color'] ?? '#63719A';

$roleLabels = array_map(
    fn($r) => $r === 'ADMIN' ? '관리자' : (BC_ROLE_LABEL[$r] ?? $r),
    array_values(array_diff($roles, ['STAFF']))
);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BlueCart · 물품 구매 요청</title>
<?php if ($inPortal): ?>
<link rel="icon" href="../styles/favicon.ico">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
<?php if ($inPortal): ?>
<link rel="stylesheet" href="../styles/topbar.css">
<?php else: ?>
<link rel="stylesheet" href="assets/topbar-fallback.css">
<?php endif; ?>
<link rel="stylesheet" href="assets/app.css?v=4">
</head>
<body>

<!-- ================= 공통 상단바 ================= -->
<div class="topbar">
  <div class="topbar-in">
    <a class="logo" href="<?= $inPortal ? '../index.php' : '#' ?>" style="text-decoration:none">
      <b>blue</b><span class="dash">-</span>iWorks
    </a>
    <div class="top-right">
      <div class="user-menu" id="hdrUserMenu">
        <div class="user-chip" onclick="bcToggleUserMenu(event)" title="메뉴">
          <span class="avatar" style="background:<?= h($avatarColor) ?>"><?= h($avatarChar) ?></span>
          <span class="nm"><?= h($user['name']) ?></span>
          <span class="user-caret">&#9662;</span>
        </div>
        <div class="dd-menu" id="hdrUserDd">
          <?php if ($inPortal): ?>
            <a href="../index.php">&#128100; 마이페이지</a>
            <div class="dd-sep"></div>
            <?= work_systems_menu('../', $moduleKey) ?>
            <div class="dd-sep"></div>
            <a href="../api/logout.php">&#128682; 로그아웃</a>
          <?php else: ?>
            <div class="dd-label">개발 환경</div>
            <a href="/dev/logout">&#128682; 계정 바꾸기</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ================= 모듈 머리말 =================
     오른쪽에 역할 배지와 화면 탭(구성원/관리자)을 함께 둔다.
     탭이 본문 맨 위에 있으면 집계·필터와 층이 겹쳐 보여서 머리말로 올렸다. -->
<header class="bc-site">
  <div class="bc-site__in">
    <div class="bc-site__lead">
      <span class="bc-site__eyebrow">물품 구매 요청</span>
      <h1 class="bc-site__title">BlueCart</h1>
      <p class="bc-site__desc">필요한 물품을 요청하고 구비까지 진행 상황을 확인합니다.</p>
    </div>

    <div class="bc-site__aside">
      <?php if ($showAdmin): ?>
      <nav class="bc-tabs" role="tablist" aria-label="화면 구분">
        <button type="button" role="tab" id="bc-tab-member" data-view="member" aria-selected="true">구성원</button>
        <button type="button" role="tab" id="bc-tab-admin" data-view="admin" aria-selected="false">관리자</button>
      </nav>
      <?php endif; ?>
    </div>
  </div>
</header>

<div class="bc" id="bc-app"
     data-csrf="<?= h($csrf) ?>"
     data-me-id="<?= h($user['id']) ?>"
     data-me-name="<?= h($user['name']) ?>"
     data-year="<?= $thisYear ?>"
     data-can-admin="<?= $showAdmin ? '1' : '0' ?>"
     data-is-admin="<?= in_array('ADMIN', $roles, true) ? '1' : '0' ?>"
     data-can-review="<?= array_intersect($roles, ['REVIEWER', 'ADMIN']) ? '1' : '0' ?>"
     data-needs-assignee="<?= bc_needs_assignee() ? '1' : '0' ?>">

  <section id="bc-view-member" role="tabpanel" aria-labelledby="bc-tab-member">
    <?php require __DIR__ . '/views/member.php'; ?>
  </section>

  <?php if ($showAdmin): ?>
  <section id="bc-view-admin" role="tabpanel" aria-labelledby="bc-tab-admin" hidden>
    <?php require __DIR__ . '/views/admin.php'; ?>
  </section>
  <?php endif; ?>

  <?php require __DIR__ . '/views/modals.php'; ?>

  <div class="bc-toast" id="bc-toast" role="status" hidden></div>
</div>

<script>
// 상단바 드롭다운 — 포털/다른 모듈과 같은 동작.
function bcToggleUserMenu(e) {
  if (e) e.stopPropagation();
  document.getElementById('hdrUserMenu').classList.toggle('open');
}
document.addEventListener('click', function (e) {
  var um = document.getElementById('hdrUserMenu');
  if (um && !um.contains(e.target)) um.classList.remove('open');
});
</script>
<script src="assets/app.js?v=4"></script>
</body>
</html>
