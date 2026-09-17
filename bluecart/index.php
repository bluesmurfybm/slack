<?php
/**
 * BlueCart 진입점.
 *
 * iworks 안에 얹는 화면이라 공통 헤더/푸터가 있다면
 * 아래 include_header / include_footer 자리에 연결하세요.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$user       = bc_require_login();
$roles      = bc_my_roles();
$showAdmin  = bc_can_see_admin();
$categories = Category::all();
$years      = PurchaseRequest::years();
$thisYear   = (int)date('Y');
$csrf       = bc_csrf_token();

// iworks 공통 헤더가 있다면:
// require_once $_SERVER['DOCUMENT_ROOT'] . '/common/header.php';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>물품 구매 요청 | iworks</title>
<link rel="stylesheet" href="assets/app.css?v=1">
</head>
<body>

<div class="bc" id="bc-app"
     data-csrf="<?= h($csrf) ?>"
     data-me-id="<?= h($user['id']) ?>"
     data-me-name="<?= h($user['name']) ?>"
     data-year="<?= $thisYear ?>"
     data-can-admin="<?= $showAdmin ? '1' : '0' ?>"
     data-is-admin="<?= in_array('ADMIN', $roles, true) ? '1' : '0' ?>"
     data-can-review="<?= array_intersect($roles, ['REVIEWER', 'ADMIN']) ? '1' : '0' ?>"
     data-needs-assignee="<?= bc_needs_assignee() ? '1' : '0' ?>">

  <header class="bc-head">
    <h1>물품 구매 요청</h1>
    <p class="bc-head__sub">필요한 물품을 요청하고 구비까지 진행 상황을 확인합니다.</p>
    <p class="bc-head__me">
      <b><?= h($user['name']) ?></b>
      <?php if ($roles): ?>
        · <?= h(implode(', ', array_map(
              fn($r) => BC_ROLE_LABEL[$r] ?? ($r === 'ADMIN' ? '관리자' : $r),
              array_diff($roles, ['STAFF'])
          ))) ?>
      <?php endif; ?>
    </p>
  </header>

  <nav class="bc-tabs" role="tablist" aria-label="화면 구분">
    <button type="button" role="tab" id="bc-tab-member" data-view="member" aria-selected="true">구성원</button>
    <?php if ($showAdmin): ?>
    <button type="button" role="tab" id="bc-tab-admin" data-view="admin" aria-selected="false">관리자</button>
    <?php endif; ?>
  </nav>

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

<script src="assets/app.js?v=1"></script>
</body>
</html>
<?php
// require_once $_SERVER['DOCUMENT_ROOT'] . '/common/footer.php';
