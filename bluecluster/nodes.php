<?php
/**
 * ClusterAgent — 살아 있는 PC 목록.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/repo.php';
require_once __DIR__ . '/lib/pages.php';

$me = bcl_require_login();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$nodes = bcl_nodes_live(bcl_db(), time());

bcl_page_head($me, 'PC 목록 · ClusterAgent');
?>
<div class="wrap" data-refresh="3">
  <div class="crumb"><a href="index.php">ClusterAgent</a> <span>›</span> <span>PC 목록</span></div>
  <h1 class="sec-h">살아 있는 PC <span class="muted"><?= count($nodes) ?>대 · 마지막 신호 <?= h(BCL_NODE_TTL_S) ?>초 안</span></h1>

<?php if (!$nodes): ?>
  <div class="card empty"><h2>살아 있는 PC 가 없습니다</h2><p>PC 에서 노드를 켜면 여기에 나타납니다.</p></div>
<?php else: ?>
  <div class="tbl-wrap">
  <table class="tbl">
    <thead>
      <tr><th>PC</th><th>계정</th><th>주소</th><th>남은 CPU</th><th>남은 메모리</th><th>실행 중</th><th>마지막 신호</th></tr>
    </thead>
    <tbody>
    <?php foreach ($nodes as $node): ?>
      <tr>
        <td><?= h($node['node_id']) ?></td>
        <td><?= h($node['account']) ?></td>
        <td><?= h($node['addr']) ?></td>
        <td><?= h(bcl_page_percent($node['free_cpu'])) ?></td>
        <td><?= h(bcl_page_percent($node['free_mem'])) ?></td>
        <td><?= h($node['running']) ?></td>
        <td><?= h(bcl_page_time($node['last_seen'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</div>
<?php
bcl_page_foot();
