<?php
/**
 * 공통 상단바 + 페이지 헤더. dti/views/head.php 와 같은 마크업 — slack/header.php 는 include 하지 않는다
 * (그쪽은 header.css/common.css 를 전제로 하고, 이 모듈은 dti 의 style.css 를 쓴다).
 * index.php 가 $me(포털 사용자 행)를 정한 뒤 include 한다. 직접 열리면 404.
 */
if (!isset($me)) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../core/worksystems.php';
// 페이지마다 바꾸는 머리 — 기본은 목록 화면(index.php)
$pageTitle = $pageTitle ?? 'BlueLab SiteHub';
$pageLede  = $pageLede ?? '유지보수 사이트의 주소 · 계정 · 담당자 · 계약 · 처리 이력';
$canStats  = $canStats ?? sitehub_can_stats($me);
?>
<!DOCTYPE html>
<html lang="ko">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= sitehub_h($pageTitle) ?></title>
  <link rel="icon" href="../styles/favicon.ico">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet"
    href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
  <link rel="stylesheet" href="../styles/topbar.css">
  <link rel="stylesheet" href="<?= sitehub_asset('styles/style.css') ?>">
</head>

<body data-page="sitehub">
  <div class="topbar">
    <div class="topbar-in">
      <a class="logo" href="../index.php" style="text-decoration:none"><b>blue</b><span class="dash">-</span>iWorks</a>
      <div class="top-right">
        <div class="user-menu" id="hdrUserMenu">
          <div class="user-chip" onclick="toggleUserMenu(event)" title="메뉴">
            <span class="avatar" style="background:<?= sitehub_h(user_color($me)) ?>"><?= sitehub_h(mb_substr($me['name'], 0, 1)) ?></span>
            <span class="nm"><?= sitehub_h($me['name']) ?></span>
            <span class="user-caret">▾</span>
          </div>
          <div class="dd-menu" id="hdrUserDd">
            <a href="../index.php?view=profile">👤 마이페이지</a>
            <div class="dd-sep"></div>
            <?= work_systems_menu('../', 'sitehub') ?>
            <div class="dd-sep"></div>
            <a href="../api/logout.php">🚪 로그아웃</a>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script>
    function toggleUserMenu(e) { if (e) e.stopPropagation(); document.getElementById("hdrUserMenu").classList.toggle("open"); }
    document.addEventListener("click", function (e) {
      var um = document.getElementById("hdrUserMenu");
      if (um && !um.contains(e.target)) um.classList.remove("open");
    });
  </script>
  <header class="site">
    <div class="site-inner">
      <div class="brand">
        <span class="eyebrow">BlueLab</span>
        <h1><?= sitehub_h($pageTitle) ?></h1>
        <p class="lede"><?= sitehub_h($pageLede) ?></p>
      </div>
      <div class="site-act">
<?php if (isset($headActions)): ?>
        <?= $headActions ?>
<?php else: ?>
        <a class="btn-ghost sh-statlink" href="monitor.php" title="BLUMO — 감시 중인 사이트의 상태 · 장애 이력 · 설정">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3-8 4 16 3-8h4" /></svg>
          감시
        </a>
<?php if ($canStats): ?>
        <a class="btn-ghost sh-statlink" href="stats.php" title="통계 — 지정된 계정에만 보입니다">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2" /></svg>
          통계
        </a>
<?php endif; ?>
        <button class="btn-new" type="button" onclick="openSiteForm()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 5v14M5 12h14" />
          </svg>
          사이트 추가
        </button>
<?php endif; ?>
      </div>
    </div>
  </header>
