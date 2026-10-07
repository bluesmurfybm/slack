<?php
if (!isset($page)) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../core/worksystems.php';

$base = $page['base'];
$me = $page['identity'];
$admin = $page['admin'];
?>
<!DOCTYPE html>
<html lang="ko">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= dti_h($admin ? "{$page['label']} · BlueMagazine" : 'BlueMagazine') ?></title>
  <link rel="icon" href="<?= dti_asset($page, 'styles/favicon.ico') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet"
    href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
  <link rel="stylesheet" href="<?= dti_asset($page, '../styles/topbar.css') ?>">
  <link rel="stylesheet" href="<?= dti_asset($page, 'styles/style.css') ?>">
</head>

<body data-page="<?= $page['key'] ?>" data-mode="<?= $admin ? 'admin' : 'user' ?>">
  <div class="topbar">
    <div class="topbar-in">
      <a class="logo" href="<?= dti_h($page['portal']) ?>" style="text-decoration:none"><b>blue</b><span
          class="dash">-</span>iWorks</a>
      <div class="top-right">
        <div class="user-menu" id="hdrUserMenu">
          <div class="user-chip" onclick="toggleUserMenu(event)" title="메뉴">
            <span class="avatar" style="background:<?= dti_h($me['color'] ?? '#606D79') ?>"><?= dti_h(mb_substr($me['name'], 0, 1)) ?></span>
            <span class="nm"><?= dti_h($me['name']) ?></span>
            <span class="user-caret">▾</span>
          </div>
          <div class="dd-menu" id="hdrUserDd">
            <a href="<?= dti_h($page['portal']) ?>/?view=profile">👤 마이페이지</a>
            <div class="dd-sep"></div>
            <?= work_systems_menu($base . '../', 'dti') ?>
            <div class="dd-sep"></div>
            <a href="<?= dti_h($page['portal']) ?>/api/logout.php">🚪 로그아웃</a>
          </div>
        </div>
      </div>
    </div>
  </div>
  <header class="site">
    <div class="site-inner">
      <div class="brand">
        <span class="eyebrow"><?= $admin ? '운영 관리' : 'BlueUP-DTI' ?></span>
        <h1>BlueMagazine</h1>
        <?php if ($admin): ?><p class="lede">아티클 등록 · 노출 관리 · 발표자 지정 · 보관</p><?php endif; ?>
      </div>
      <?php if ($page['is_admin']): ?>
      <div class="site-act">
        <?php if ($page['key'] === 'articles'): ?>
        <button class="btn-new" onclick="openForm()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 5v14M5 12h14" />
          </svg>
          아티클 등록
        </button>
        <?php endif; ?>
        <nav class="mode-nav" aria-label="화면 전환">
          <a href="<?= dti_page_href($page, 'list') ?>"<?= $admin ? '' : ' class="on" aria-current="page"' ?>>구성원 화면</a>
          <a href="<?= dti_page_href($page, 'articles') ?>"<?= $admin ? ' class="on" aria-current="page"' : '' ?>>관리자 화면</a>
        </nav>
      </div>
      <?php endif; ?>
    </div>
  </header>
