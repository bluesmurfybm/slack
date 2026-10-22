<?php
declare(strict_types=1);

/* 리조트 예약 · 식대 정산 — 화면. 포털 로그인 없이 들어오면 포털 로그인 화면으로 보낸다 */

require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/../../core/worksystems.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

if (!current_portal_user()) {
    header('Location: ../../index.php?need_login=resort');
    exit;
}
$u = current_user();
$csrf = bl_csrf_token();
$v = RESORT_VERSION;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= $h($csrf) ?>">
<title>리조트 예약 · BlueLedger</title>
<link rel="icon" type="image/svg+xml" href="../../styles/favicons/resort.svg">
<?php require_once __DIR__ . '/../../core/pwa.php'; echo pwa_head_tags('../../'); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+KR:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
<link rel="stylesheet" href="../../styles/topbar.css">
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
<script src="assets/xlsx.full.min.js?v=0.18.5"></script>
</head>
<body data-role="<?= $u['role'] === 'admin' ? 'admin' : 'staff' ?>">
<div class="topbar">
  <div class="topbar-in">
    <a class="logo" href="../../index.php" style="text-decoration:none"><b>blue</b><span class="dash">-</span>iWorks</a>
    <div class="top-right">
      <div class="user-menu" id="hdrUserMenu">
        <div class="user-chip" id="hdrChip" title="메뉴">
          <span class="avatar" style="background:<?= $h($u['color']) ?>"><?= $h(mb_substr($u['name'], 0, 1)) ?></span>
          <span class="nm"><?= $h($u['name']) ?></span>
          <span class="user-caret">▾</span>
        </div>
        <div class="dd-menu">
          <a href="../../index.php?view=profile">👤 마이페이지</a>
          <a href="../travel.php">🧾 출장여비 청구</a>
          <div class="dd-sep"></div>
          <?= work_systems_menu('../../', 'resort') ?>
          <div class="dd-sep"></div>
          <a href="../../api/logout.php">🚪 로그아웃</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php readfile(__DIR__ . '/lib/view_app.html'); ?>
<script>window.RESORT_ME = <?= json_encode(['id' => $u['id'], 'name' => $u['name'], 'role' => $u['role']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="assets/app.js?v=<?= $v ?>"></script>
</body>
</html>
