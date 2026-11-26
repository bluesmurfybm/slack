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
<style>
body { font-family: Pretendard, sans-serif; margin: 0; background: #F4F6FB; color: #1B2333; }
.bcl-main { max-width: 960px; margin: 24px auto; padding: 0 16px; }
.bcl-empty { background: #fff; border-radius: 12px; padding: 48px 16px; text-align: center; color: #5A667F; }
</style>
</head>
<body>
<div class="topbar">
  <div class="topbar-in">
    <a class="logo" href="../index.php" style="text-decoration:none"><b>blue</b><span class="dash">-</span>iWorks</a>
    <div class="top-right">
      <?= work_systems_bell('../', BCL_MODULE_KEY) ?>
      <div class="user-menu" id="hdrUserMenu">
        <div class="user-chip" title="메뉴">
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
</body>
</html>
<?php
}
