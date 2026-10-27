<?php
/**
 * OnWatch(수시 감시) — 목록.
 *  - 누구나 모든 감시를 본다. 기본은 종료되지 않은 것 전부, 탭으로 내 것·받는 것을 고른다.
 *  - 감시마다 '새 소식 N' 은 내 알림함의 안 읽은 수다(포털 종 숫자·카드 배지와 같은 원본).
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

$me  = ow_require_login();
$uid = (int)$me['id'];
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$scope  = in_array($_GET['scope'] ?? '', ['mine', 'subscribed'], true) ? $_GET['scope'] : 'all';
$status = in_array($_GET['status'] ?? '', ['active', 'paused', 'ended', 'any'], true) ? $_GET['status'] : '';
$q      = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);

$watches = ow_watch_list($uid, $scope, $status, $q);
$counts  = ow_counts($uid);
$unread  = ow_unread_by_watch($uid);
$monthCost = ow_month_cost();

$qs = function (array $over) use ($scope, $status, $q) {
    $p = array_filter(array_merge(['scope' => $scope === 'all' ? '' : $scope, 'status' => $status, 'q' => $q], $over), 'strlen');
    return $p ? '?' . http_build_query($p) : '?';
};

ow_page_head($me, 'OnWatch');
?>
<div class="wrap">
  <div class="hero">
    <div>
      <h1><span class="hero-ic"><?= file_get_contents(OW_PORTAL_ROOT . '/styles/icons/onwatch.svg') ?></span>OnWatch</h1>
      <p>필요할 때 켜 두는 감시. 정해 둔 대상을 기간 동안 주기적으로 살피고, <b>새 소식이 있을 때만</b> 알립니다.</p>
    </div>
    <div class="hero-links">
      <span class="cost-chip" title="이번 달 모든 감시의 추정 비용 합계">이번 달 <b>$<?= number_format($monthCost, 2) ?></b></span>
      <a class="btn primary" href="edit.php">＋ 새 감시</a>
      <a class="back" href="../index.php">← 대시보드</a>
    </div>
  </div>

  <div class="toolbar">
    <nav class="tabs" aria-label="보기">
      <a class="tab<?= $scope === 'all' ? ' on' : '' ?>" href="<?= h($qs(['scope' => ''])) ?>">전체 <span class="n"><?= $counts['all'] ?></span></a>
      <a class="tab<?= $scope === 'mine' ? ' on' : '' ?>" href="<?= h($qs(['scope' => 'mine'])) ?>">내가 만든 <span class="n"><?= $counts['mine'] ?></span></a>
      <a class="tab<?= $scope === 'subscribed' ? ' on' : '' ?>" href="<?= h($qs(['scope' => 'subscribed'])) ?>">받는 중 <span class="n"><?= $counts['subscribed'] ?></span></a>
    </nav>
    <div class="filters">
      <div class="chips">
        <?php foreach (['' => '진행 중', 'active' => '감시 중', 'paused' => '일시정지', 'ended' => '종료', 'any' => '전부'] as $k => $label): ?>
          <a class="chip<?= $status === $k ? ' on' : '' ?>" href="<?= h($qs(['status' => $k])) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
      </div>
      <form class="search" method="get">
        <?php if ($scope !== 'all'): ?><input type="hidden" name="scope" value="<?= h($scope) ?>"><?php endif; ?>
        <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="이름·지시문 검색" aria-label="검색">
      </form>
    </div>
  </div>

<?php if (!$watches): ?>
  <div class="card empty">
    <?php if ($q !== '' || $status !== '' || $scope !== 'all'): ?>
      <h2>조건에 맞는 감시가 없습니다</h2>
      <p><a href="?">모든 감시 보기</a></p>
    <?php else: ?>
      <h2>아직 감시가 없습니다</h2>
      <p>새로 나올 패치, 바뀔 공지, 다음 릴리스처럼 <b>며칠~몇 달 동안 지켜봐야 할 것</b>을 적어 두면<br>
         정해 둔 주기마다 웹을 살피고 달라진 것이 있을 때만 알려 드립니다.</p>
      <a class="btn primary" href="edit.php">＋ 첫 감시 만들기</a>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="wlist">
  <?php foreach ($watches as $w):
      $wid = (int)$w['id'];
      $n = $unread[$wid] ?? 0;
      $isSub = (int)$w['is_sub'] > 0;
      $ending = $w['status'] !== 'ended' && strtotime((string)$w['ends_at']) - time() < 3 * 86400;
  ?>
    <article class="witem st-<?= h($w['status']) ?><?= $n ? ' has-new' : '' ?>">
      <a class="wlink" href="view.php?id=<?= $wid ?>" aria-label="<?= h($w['title']) ?> 열기"></a>
      <div class="wmain">
        <div class="wtitle">
          <?= ow_status_pill($w) ?>
          <h2><?= h($w['title']) ?></h2>
          <?php if ($n): ?><span class="new-badge" title="안 읽은 알림">새 소식 <?= $n ?></span><?php endif; ?>
        </div>
        <p class="wprompt"><?= h(mb_strimwidth(preg_replace('/\s+/u', ' ', (string)$w['prompt']), 0, 180, '…')) ?></p>
        <?php if ($w['last_finding']): ?>
          <p class="wlast"><span class="lbl">최근 소식</span> <?= h($w['last_finding']) ?> <span class="muted">· <?= h(ow_when($w['last_change_at'])) ?></span></p>
        <?php endif; ?>
      </div>
      <div class="wmeta">
        <span title="주기"><i class="ico">⟳</i><?= h(ow_schedule_label($w)) ?></span>
        <?php if ($w['status'] === 'active' && $w['next_run_at']): ?>
          <span title="다음 실행"><i class="ico">▸</i>다음 <?= h(ow_when($w['next_run_at'])) ?></span>
        <?php endif; ?>
        <span class="<?= $ending ? 'warn' : '' ?>" title="감시 종료 <?= h(substr((string)$w['ends_at'], 0, 16)) ?>"><i class="ico">◷</i><?php
            echo $w['status'] === 'ended' ? '종료됨 ' . h(substr((string)($w['ended_at'] ?: $w['ends_at']), 0, 10))
                                           : '~' . h(date('n/j', strtotime((string)$w['ends_at']))) . ' <b>' . h(ow_left($w['ends_at'])) . '</b>';
        ?></span>
        <span title="찾은 소식 / 받는 사람"><i class="ico">✦</i>소식 <?= (int)$w['finding_count'] ?> · 받는 사람 <?= (int)$w['sub_count'] ?></span>
        <span class="who"><?= ow_avatar($w['owner_name'], $w['owner_color']) ?><?= h($w['owner_name']) ?></span>
      </div>
      <?php if ($w['status'] !== 'ended'): ?>
        <button type="button" class="sub-btn<?= $isSub ? ' on' : '' ?>" data-sub="<?= $wid ?>"
                aria-pressed="<?= $isSub ? 'true' : 'false' ?>"
                title="<?= $isSub ? '소식을 받고 있습니다. 누르면 그만 받습니다' : '이 감시의 소식을 받습니다' ?>"><?= $isSub ? '🔔 받는 중' : '🔕 받기' ?></button>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>
<?php ow_page_foot(['page' => 'list']); ?>
