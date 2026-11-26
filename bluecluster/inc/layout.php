<?php
/**
 * 화면 머리·꼬리.
 */

/**
 * @param array $me 로그인 사용자
 * @param string $title 화면 제목
 */
function bcl_page_head($me, $title)
{
    ?><!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title) ?> · blue-iWorks</title>
<link rel="icon" type="image/svg+xml" href="../styles/favicons/bluecluster.svg">
<?php require_once BCL_PORTAL_ROOT . '/core/pwa.php'; echo pwa_head_tags('../'); ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
<link rel="stylesheet" href="../styles/topbar.css">
<link rel="stylesheet" href="assets/bluecluster.css?v=<?= bcl_asset_v('assets/bluecluster.css') ?>">
</head>
<body>
<div class="topbar">
  <div class="topbar-in">
    <a class="logo" href="../index.php" style="text-decoration:none"><b>blue</b><span class="dash">-</span>iWorks</a>
    <div class="top-right">
      <?= work_systems_bell('../', BCL_MODULE_KEY) ?>
      <div class="user-menu" id="hdrUserMenu">
        <div class="user-chip" onclick="bclToggleMenu(event)" title="메뉴">
          <span class="avatar" style="background:<?= h(user_color($me)) ?>"><?= h(mb_substr($me['name'], 0, 1)) ?></span>
          <span class="nm"><?= h($me['name']) ?></span>
          <span class="user-caret">▾</span>
        </div>
        <div class="dd-menu" id="hdrUserDd">
          <a href="../index.php?view=profile">👤 마이페이지</a>
          <div class="dd-sep"></div>
          <a href="../index.php">🏠 대시보드</a>
          <div class="dd-sep"></div>
          <?= work_systems_menu('../', BCL_MODULE_KEY) ?>
          <div class="dd-sep"></div>
          <a href="../api/logout.php">🚪 로그아웃</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
}

function bcl_page_foot()
{
    ?>
<script src="assets/bluecluster.js?v=<?= bcl_asset_v('assets/bluecluster.js') ?>"></script>
</body>
</html>
<?php
}
