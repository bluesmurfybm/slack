<?php
/**
 * 화면 머리·꼬리. 상단바는 다른 모듈(moodle 등)과 같은 마크업에 ../styles/topbar.css.
 */

function ow_page_head(array $me, string $title): void
{
    $csrf = ow_csrf_token();
    ?><!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= h($csrf) ?>">
<title><?= h($title) ?> · blue-iWorks</title>
<link rel="icon" type="image/svg+xml" href="../styles/favicons/onwatch.svg">
<?php require_once OW_PORTAL_ROOT . '/core/pwa.php'; echo pwa_head_tags('../'); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
<link rel="stylesheet" href="../styles/topbar.css">
<link rel="stylesheet" href="assets/onwatch.css?v=<?= ow_asset_v('assets/onwatch.css') ?>">
</head>
<body>
<div class="topbar">
  <div class="topbar-in">
    <a class="logo" href="../index.php" style="text-decoration:none"><b>blue</b><span class="dash">-</span>iWorks</a>
    <div class="top-right">
      <div class="user-menu" id="hdrUserMenu">
        <div class="user-chip" onclick="owToggleMenu(event)" title="메뉴">
          <span class="avatar" style="background:<?= h(user_color($me)) ?>"><?= h(mb_substr($me['name'], 0, 1)) ?></span>
          <span class="nm"><?= h($me['name']) ?></span>
          <span class="user-caret">▾</span>
        </div>
        <div class="dd-menu" id="hdrUserDd">
          <a href="../index.php?view=profile">👤 마이페이지</a>
          <div class="dd-sep"></div>
          <a href="../index.php">🏠 대시보드</a>
          <div class="dd-sep"></div>
          <?= work_systems_menu('../', OW_MODULE_KEY) ?>
          <div class="dd-sep"></div>
          <a href="../api/logout.php">🚪 로그아웃</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
}

function ow_page_foot(array $boot = []): void
{
    ?>
<div class="ow-toast" id="owToast" role="status" aria-live="polite"></div>
<script>window.OW = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="assets/onwatch.js?v=<?= ow_asset_v('assets/onwatch.js') ?>"></script>
</body>
</html>
<?php
}

/** 상태 표시 알약 */
function ow_status_pill(array $w): string
{
    $st = (string)$w['status'];
    return '<span class="ow-pill st-' . h($st) . '">' . h(OW_STATUS_LABEL[$st] ?? $st) . '</span>';
}

function ow_avatar(?string $name, ?string $color): string
{
    $name = (string)$name;
    return '<span class="ow-av" style="background:' . h($color ?: '#8A94AD') . '" title="' . h($name) . '">'
         . h(mb_substr($name !== '' ? $name : '?', 0, 1)) . '</span>';
}
