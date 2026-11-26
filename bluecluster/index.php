<?php
/**
 * 블루 클러스터 에이전트 — 작업 목록.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/repo.php';
require_once __DIR__ . '/lib/pages.php';

$me = bcl_require_login();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$pdo = bcl_db();
$tab = bcl_page_tab($_GET['tab'] ?? null);
$jobs = bcl_page_jobs($pdo, $tab);
$tabs = ['open' => '진행 중', 'done' => '완료'];

bcl_page_head($me, '블루 클러스터 에이전트');
?>
<div class="wrap" data-refresh="5">
  <div class="hero">
    <div>
      <h1>블루 클러스터 에이전트</h1>
      <p>PC 여러 대가 나눠 맡는 코딩 작업. 작업을 올리면 리더 PC 가 조각으로 나누고 각 PC 가 시도합니다.</p>
    </div>
    <div class="hero-links">
      <a class="btn" href="nodes.php">PC 목록</a>
      <a class="btn primary" href="edit.php">＋ 새 작업</a>
      <a class="back" href="../index.php">← 대시보드</a>
    </div>
  </div>

  <div class="toolbar">
    <nav class="tabs" aria-label="보기">
      <?php foreach ($tabs as $key => $label): ?>
        <a class="tab<?= $tab === $key ? ' on' : '' ?>" href="?tab=<?= h($key) ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>

<?php if (!$jobs): ?>
  <div class="card empty">
    <h2><?= $tab === 'open' ? '진행 중인 작업이 없습니다' : '완료된 작업이 없습니다' ?></h2>
    <?php if ($tab === 'open'): ?><p>새 작업을 올리면 PC 가 맡아 시작합니다.</p><a class="btn primary" href="edit.php">＋ 새 작업</a><?php endif; ?>
  </div>
<?php else: ?>
  <div class="tbl-wrap">
  <table class="tbl">
    <thead>
      <tr><th>작업 번호</th><th>제목</th><th>낸 사람</th><th>상태</th><th>리더 PC</th><th>등록</th><th>완료</th></tr>
    </thead>
    <tbody>
    <?php foreach ($jobs as $job): ?>
      <tr>
        <td><a href="view.php?id=<?= h(urlencode($job['job_id'])) ?>"><?= h($job['job_id']) ?></a></td>
        <td><a href="view.php?id=<?= h(urlencode($job['job_id'])) ?>"><?= h($job['title']) ?></a></td>
        <td><?= h($job['submitter']) ?></td>
        <td><span class="pill st-<?= h($job['state']) ?>"><?= h($tabs[$job['state']] ?? $job['state']) ?></span></td>
        <td><?= h($job['leader'] ?? '-') ?></td>
        <td><?= h(bcl_page_time($job['created_at'])) ?></td>
        <td><?= h(bcl_page_time($job['finished_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</div>
<?php
bcl_page_foot();
