<?php
/**
 * ClusterAgent — 작업 상세. 조각별 시도 목록과 결과 내려받기.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/repo.php';
require_once __DIR__ . '/lib/files.php';
require_once __DIR__ . '/lib/pages.php';

$me = bcl_require_login();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$job = bcl_page_job(bcl_db(), (string)($_GET['id'] ?? ''));
if (!$job) {
    http_response_code(404);
    bcl_page_head($me, 'ClusterAgent');
    echo '<div class="wrap narrow"><div class="card empty"><h2>작업을 찾을 수 없습니다</h2><p>지워졌거나 주소가 틀렸습니다. <a href="index.php">목록으로</a></p></div></div>';
    bcl_page_foot();
    exit;
}

bcl_page_head($me, $job['title'] . ' · ClusterAgent');
?>
<div class="wrap" <?= $job['state'] === 'open' ? 'data-refresh="5"' : '' ?>>
  <div class="crumb"><a href="index.php">ClusterAgent</a> <span>›</span> <span><?= h($job['job_id']) ?></span></div>

  <header class="vhead card">
    <div class="vtitle">
      <span class="pill st-<?= h($job['state']) ?>"><?= $job['state'] === 'open' ? '진행 중' : '완료' ?></span>
      <h1><?= h($job['title']) ?></h1>
    </div>
    <div class="vmeta">
      <span><?= h($job['submitter']) ?> 등록 · <?= h(bcl_page_time($job['created_at'])) ?></span>
      <span>리더 PC <?= h($job['leader'] ?? '-') ?></span>
      <?php if ($job['finished_at']): ?><span>완료 <?= h(bcl_page_time($job['finished_at'])) ?></span><?php endif; ?>
    </div>
  </header>

  <section class="card" style="margin-bottom:12px">
    <h2 class="sec-h">저장소 <span class="muted"><?= count($job['repos']) ?>개</span></h2>
    <p style="margin:0 0 16px"><?= h(implode(', ', $job['repos'])) ?></p>
    <h2 class="sec-h">지시</h2>
    <div class="prompt-box"><?= h($job['instructions']) ?></div>
    <?php if ($job['summary'] !== null): ?>
      <h2 class="sec-h">리더 요약</h2>
      <div class="prompt-box"><?= h($job['summary']) ?></div>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2 class="sec-h">조각 <span class="muted"><?= count($job['pieces']) ?>개</span></h2>
    <?php if (!$job['pieces']): ?><p class="muted">리더 PC 가 아직 조각을 나누지 않았습니다.</p><?php endif; ?>
    <?php foreach ($job['pieces'] as $piece): ?>
      <div class="piece">
        <h3><?= h($piece['piece_id']) ?> <span class="muted">· <?= h($piece['repo']) ?> · <?= h($piece['state']) ?></span></h3>
        <p class="small muted" style="margin:0 0 6px"><?= h($piece['desc']) ?></p>
        <?php if (!$piece['attempts']): ?><p class="small muted">시도가 아직 없습니다.</p><?php else: ?>
        <div class="tbl-wrap">
        <table class="tbl">
          <thead>
            <tr><th>#</th><th>PC</th><th>계정</th><th>토큰 주인</th><th>상태</th><th>시작</th><th>종료</th><th>가림</th><th>닫은 노드</th><th>내려받기</th></tr>
          </thead>
          <tbody>
          <?php foreach ($piece['attempts'] as $attempt): ?>
            <tr>
              <td><?= h($attempt['attempt_no']) ?></td>
              <td><?= h($attempt['node_id']) ?></td>
              <td><?= h($attempt['account']) ?></td>
              <td><?= h($attempt['token_user'] ?: '-') ?></td>
              <td><span class="pill st-<?= h($attempt['status']) ?>"><?= h(bcl_page_status_label($attempt['status'])) ?></span></td>
              <td><?= h(bcl_page_time($attempt['started'])) ?></td>
              <td><?= h(bcl_page_time($attempt['ended'])) ?></td>
              <td><?= h(bcl_page_redactions($attempt['redactions'])) ?></td>
              <td><?= h($attempt['closed_by'] ?? '-') ?></td>
              <td>
                <?php if (!$attempt['files']): ?>-<?php endif; ?>
                <div class="links">
                <?php foreach ($attempt['files'] as $kind): ?>
                  <a class="btn small" href="download.php?attempt=<?= (int)$attempt['attempt_id'] ?>&amp;kind=<?= h($kind) ?>"><?= h(BCL_DOWNLOAD_KINDS[$kind]['label']) ?></a>
                <?php endforeach; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>
</div>
<?php
bcl_page_foot();
