<?php
/**
 * 개발용 로그인 화면. dev/router.php 를 통해서만 열립니다.
 * 회원 테이블에 있는 사람 중 하나를 골라 세션에 넣습니다.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit('개발 환경 전용입니다.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

// 선택 처리
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($_POST['user_id'])) {
    $row = bc_directory_find((string)$_POST['user_id']);
    if ($row) {
        $_SESSION['ss_mb_id']    = $row['id'];
        $_SESSION['ss_mb_name']  = $row['name'];
        $_SESSION['ss_mb_email'] = $row['email'];
        header('Location: /index.php');
        exit;
    }
    $error = '그런 사용자가 없습니다.';
}

$members = bc_directory_all();
$roleMap = [];
foreach (['REVIEWER', 'BUYER', 'ADMIN'] as $t) {
    foreach (RoleAssign::byType($t) as $r) {
        $roleMap[$r['user_id']][] = BC_ROLE_LABEL[$t] ?? '관리자';
    }
}
$supers = (array)bc_config('iworks.superadmins', []);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>개발용 로그인 | BlueCart</title>
<link rel="stylesheet" href="/assets/app.css">
<style>
  body { margin: 0; background: #f4f7fa; }
  .bc { max-width: 560px; }
  .dev-warn {
    border: 1px solid #e7c6c6; background: #fdf5f5; color: #ab3535;
    border-radius: 5px; padding: 10px 14px; font-size: 13px; margin-bottom: 18px;
  }
  .dev-user {
    display: flex; align-items: center; gap: 10px; width: 100%;
    padding: 11px 14px; border: 1px solid #dce4ec; border-radius: 5px;
    background: #fff; cursor: pointer; font: inherit; text-align: left;
    margin-bottom: 6px;
  }
  .dev-user:hover { background: #f4f7fa; border-color: #1b5fa8; }
  .dev-user b { font-weight: 600; }
  .dev-user .id { color: #5a6a7d; font-size: 12px; }
  .dev-user .roles { margin-left: auto; font-size: 12px; color: #1b5fa8; }
</style>
</head>
<body>
<div class="bc">
  <header class="bc-head"><h1>개발용 로그인</h1></header>

  <div class="dev-warn">
    이 화면은 로컬 개발 환경에서만 열립니다. 비밀번호 확인 없이 계정을 전환합니다.
    운영 서버에서는 iworks 세션을 그대로 씁니다.
  </div>

  <?php if (!empty($error)): ?>
    <div class="bc-alert"><?= h($error) ?></div>
  <?php endif; ?>

  <?php if (!$members): ?>
    <div class="bc-alert">구성원 목록을 불러오지 못했습니다.

config/config.php 의 iworks.member 매핑을 확인하고,
dev/seed_dev.sql 을 실행했는지 확인하세요.</div>
  <?php else: ?>
    <p class="bc-panel__hint" style="margin-bottom:12px">사용할 계정을 고르세요.</p>
    <form method="post">
      <?php foreach ($members as $m):
          $roles = $roleMap[$m['id']] ?? [];
          if (in_array($m['id'], $supers, true)) {
              $roles[] = '관리자(config)';
          }
      ?>
        <button type="submit" name="user_id" value="<?= h($m['id']) ?>" class="dev-user">
          <b><?= h($m['name']) ?></b>
          <span class="id"><?= h($m['id']) ?></span>
          <?php if ($roles): ?>
            <span class="roles"><?= h(implode(' · ', array_unique($roles))) ?></span>
          <?php endif; ?>
        </button>
      <?php endforeach; ?>
    </form>
  <?php endif; ?>

  <p class="bc-panel__hint" style="margin-top:18px">
    계정을 바꾸려면 <a href="/dev/logout">로그아웃</a> 후 다시 고르세요.
  </p>
</div>
</body>
</html>
