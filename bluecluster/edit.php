<?php
/**
 * 블루 클러스터 에이전트 — 새 작업 등록.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/repo.php';
require_once __DIR__ . '/lib/pages.php';

$me = bcl_require_login();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$input = ['title' => '', 'repos' => '', 'instructions' => ''];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = array_merge($input, array_intersect_key($_POST, $input));
    if (!bcl_csrf_valid((string)($_POST['_csrf'] ?? ''))) {
        $errors[] = '요청이 만료되었습니다. 새로고침 후 다시 시도하세요.';
    } else {
        $result = bcl_page_submit(bcl_db(), $me['email'], $input);
        if ($result['job_key']) {
            header('Location: view.php?id=' . urlencode($result['job_key']));
            exit;
        }
        $errors = $result['errors'];
    }
}

bcl_page_head($me, '새 작업 · 블루 클러스터 에이전트');
?>
<div class="wrap narrow">
  <div class="crumb"><a href="index.php">블루 클러스터 에이전트</a> <span>›</span> <span>새 작업</span></div>

  <?php if ($errors): ?>
    <div class="banner bad" role="alert"><?php foreach ($errors as $error): ?><span><?= h($error) ?></span><?php endforeach; ?></div>
  <?php endif; ?>

  <form class="form" method="post">
    <input type="hidden" name="_csrf" value="<?= h(bcl_csrf_token()) ?>">
    <div class="card">
      <div class="field">
        <label for="f_title">제목</label>
        <input type="text" id="f_title" name="title" maxlength="200" required value="<?= h($input['title']) ?>">
      </div>
      <div class="field">
        <label for="f_repos">저장소</label>
        <textarea id="f_repos" name="repos" rows="4" required placeholder="한 줄에 하나씩"><?= h($input['repos']) ?></textarea>
        <p class="help">작업이 다룰 저장소 이름을 한 줄에 하나씩 적습니다.</p>
      </div>
      <div class="field">
        <label for="f_instructions">지시</label>
        <textarea id="f_instructions" name="instructions" rows="12" required><?= h($input['instructions']) ?></textarea>
      </div>
    </div>
    <div class="form-foot">
      <a class="back" href="index.php">← 목록</a>
      <button type="submit" class="btn primary">작업 올리기</button>
    </div>
  </form>
</div>
<?php
bcl_page_foot();
