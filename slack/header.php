<?php
/**
 * 전 모듈 공통 상단바 — blue-iwork 포털 대시보드와 완전히 동일한 클래스/스타일.
 * slack 쪽 페이지들의 <body> 바로 다음에 include 해서 쓴다.
 * (require_once auth.php; require_login(); 이후에 include할 것 — current_portal_user() 필요)
 *
 * slack/ 바로 아래 페이지(lists.php 등)는 $__bwBase 안 정해도 됨(기본 '').
 * slack/xxx/ 하위 폴더 페이지(schools/schools_admin.php 등)는 include 전에
 * $__bwBase = '../'; 로 한 단계 더 위임을 알려줘야 링크가 안 깨진다.
 */
$__bwBase    = isset($__bwBase) ? $__bwBase : '';
$__bwUser    = function_exists('current_portal_user') ? current_portal_user() : null;
$__bwCfg     = require __DIR__ . '/../config.php';
$__bwBookUrl = $__bwCfg['links']['book'] ?? '#';
// config.php의 book 링크는 "포털 루트" 기준 상대경로일 수 있음(예: "book") — 절대주소(http...)가
// 아니면 $__bwBase로 포털 루트까지 되짚어준다. 안 그러면 slack/ 하위 폴더에서 엉뚱한 곳으로 감.
if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $__bwBookUrl)) {
    $__bwBookUrl = $__bwBase . '../' . $__bwBookUrl;
}
?>
<div class="topbar">
  <div class="topbar-in">
    <a class="logo" href="<?= $__bwBase ?>../index.php" style="text-decoration:none"><b>blue</b><span class="dash">-</span>iwork</a>
    <div class="top-right">
      <?php if ($__bwUser): ?>
      <div class="user-menu" id="hdrUserMenu">
        <div class="user-chip" onclick="__hdrToggleMenu(event)" title="메뉴">
          <span class="avatar" style="background:<?= htmlspecialchars(user_color($__bwUser), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(mb_substr($__bwUser['name'], 0, 1), ENT_QUOTES, 'UTF-8') ?></span>
          <span class="nm"><?= htmlspecialchars($__bwUser['name'], ENT_QUOTES, 'UTF-8') ?></span>
          <span class="user-caret">▾</span>
        </div>
        <div class="dd-menu" id="hdrUserDd">
          <a href="<?= $__bwBase ?>../index.php?view=profile">👤 마이페이지</a>
          <div class="dd-sep"></div>
          <div class="dd-label">업무 시스템</div>
          <a href="<?= htmlspecialchars($__bwBookUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">📚 도서구매신청</a>
          <div class="dd-sep"></div>
          <a href="<?= $__bwBase ?>logout.php">🚪 로그아웃</a>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
function __hdrToggleMenu(e){ if(e) e.stopPropagation(); document.getElementById("hdrUserMenu").classList.toggle("open"); }
document.addEventListener("click", function(e){
  var um = document.getElementById("hdrUserMenu");
  if(um && !um.contains(e.target)) um.classList.remove("open");
});
</script>
