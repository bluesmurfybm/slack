<?php
/**
 * 감시 상세 — 소식 / 실행 이력 / 설정 / 변경 기록.
 * 열면 이 감시에서 온 내 알림을 읽음으로 바꾼다(포털 종 숫자·카드 배지가 같이 준다).
 * 읽음으로 바꾸기 전에 안 읽은 알림의 시각을 받아 두고, 그 뒤 소식에 NEW 를 붙인다.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

$me  = ow_require_login();
$uid = (int)$me['id'];
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$id = (int)($_GET['id'] ?? 0);
$w  = $id ? ow_watch_get($id) : null;
if (!$w) {
    http_response_code(404);
    ow_page_head($me, 'OnWatch');
    echo '<div class="wrap narrow"><div class="card empty"><h2>감시를 찾을 수 없습니다</h2><p>지워졌거나 주소가 틀렸습니다. <a href="index.php">목록으로</a></p></div></div>';
    ow_page_foot();
    exit;
}

$newSince = ow_mark_seen($uid, $id);
$p        = ow_perms($w, $me);
$findings = ow_findings($id);
$runs     = ow_runs($id, 50);
$events   = ow_events($id);
$subs     = ow_subscribers($id);
$monthCost = ow_month_cost($id);
$budget   = (float)$w['monthly_budget_usd'];
$lastRun  = $runs[0] ?? null;
// 기준 상태 갱신 제안 — 제안이 있는 가장 최근 실행 하나. 그 뒤 '변화 없음' 실행이 있어도
// 제안은 살아 있다. 기준 상태를 그 뒤에 바꿨거나 이미 같은 내용이면 보이지 않는다.
$hint = null;
foreach ($runs as $r) {
    if (trim((string)$r['baseline_hint']) === '') continue;
    if ((!$w['baseline_updated_at'] || $r['finished_at'] > $w['baseline_updated_at'])
        && trim((string)$r['baseline_hint']) !== trim((string)$w['baseline'])) {
        $hint = $r;
    }
    break;
}
$tab = in_array($_GET['tab'] ?? '', ['runs', 'settings', 'log'], true) ? $_GET['tab'] : 'findings';
$secLeft = strtotime((string)$w['ends_at']) - time();
$runsById = [];
foreach ($runs as $r) $runsById[(int)$r['id']] = $r;

// 소식을 실행(날짜) 단위로 묶는다
$byRun = [];
foreach ($findings as $f) $byRun[(int)$f['run_id']][] = $f;
$impOrder = ['high' => 0, 'medium' => 1, 'low' => 2];

ow_page_head($me, $w['title'] . ' · OnWatch');
?>
<div class="wrap">
  <div class="crumb"><a href="index.php">OnWatch</a> <span>›</span> <span><?= h($w['title']) ?></span></div>

  <header class="vhead card">
    <div class="vtitle">
      <?= ow_status_pill($w) ?>
      <h1><?= h($w['title']) ?></h1>
    </div>
    <div class="vmeta">
      <span class="who"><?= ow_avatar($w['owner_name'], $w['owner_color']) ?><?= h($w['owner_name']) ?> 등록 · <?= h(ow_when($w['created_at'])) ?></span>
      <span><i class="ico">⟳</i><?= h(ow_schedule_label($w)) ?></span>
      <span><i class="ico">◷</i><?= h(date('Y.n.j', strtotime((string)$w['starts_at']))) ?> ~ <?= h(date('Y.n.j', strtotime((string)$w['ends_at']))) ?>
        <?php if ($w['status'] !== 'ended'): ?><b class="<?= $secLeft < 3 * 86400 ? 'warn' : '' ?>"><?= h(ow_left($w['ends_at'])) ?></b><?php endif; ?></span>
      <?php if ($w['status'] === 'active' && $w['next_run_at']): ?>
        <span><i class="ico">▸</i>다음 실행 <?= h(ow_when($w['next_run_at'])) ?></span>
      <?php endif; ?>
      <?php if ($w['last_run_at']): ?><span>마지막 실행 <?= h(ow_when($w['last_run_at'])) ?></span><?php endif; ?>
    </div>

    <div class="vactions" id="vActions">
      <div class="runbox" id="runBox" data-state="<?= h($p['state']) ?>">
        <button type="button" class="btn primary" data-act="run"<?= $p['can_run'] ? '' : ' hidden' ?>
                title="정기 실행을 기다리지 않고 지금 한 번 살핍니다">▶ 지금 실행</button>
        <span class="runstate" data-when="queued"><span class="spin"></span>실행 대기 중 — 1분 안에 시작합니다
          <button type="button" class="link" data-act="cancel_run"<?= $p['can_cancel_run'] ? '' : ' hidden' ?>>취소</button></span>
        <span class="runstate" data-when="running"><span class="spin"></span>살피는 중… 보통 2~6분 걸립니다</span>
      </div>
      <?php if ($p['can_subscribe']): ?>
        <button type="button" class="btn" data-act="subscribe">🔔 소식 받기</button>
      <?php elseif ($p['can_unsubscribe']): ?>
        <button type="button" class="btn ghost" data-act="unsubscribe" title="누르면 그만 받습니다">🔔 받는 중</button>
      <?php endif; ?>
      <?php if ($p['can_edit']): ?><a class="btn ghost" href="edit.php?id=<?= $id ?>">✎ 고치기</a><?php endif; ?>
      <div class="more">
        <button type="button" class="btn ghost" data-menu aria-haspopup="true" aria-expanded="false">⋯</button>
        <div class="menu" role="menu">
          <?php if ($p['can_pause']): ?><button type="button" data-act="pause">⏸ 일시정지</button><?php endif; ?>
          <?php if ($p['can_resume']): ?><button type="button" data-act="resume">▶ 다시 켜기</button><?php endif; ?>
          <?php if ($p['can_extend']): ?>
            <div class="menu-lbl">종료일 늘리기<?= $w['status'] === 'ended' ? ' (다시 켬)' : '' ?></div>
            <div class="menu-row">
              <button type="button" data-act="extend" data-days="7">+1주</button>
              <button type="button" data-act="extend" data-days="14">+2주</button>
              <button type="button" data-act="extend" data-days="30">+1달</button>
            </div>
          <?php endif; ?>
          <a href="edit.php?clone=<?= $id ?>">⧉ 복제해서 새로 만들기</a>
          <?php if ($p['can_end']): ?><button type="button" data-act="end" data-confirm="감시를 끝낼까요? 기록은 남고, 종료일 늘리기로 다시 켤 수 있습니다.">■ 지금 종료</button><?php endif; ?>
          <?php if ($p['can_delete']): ?><button type="button" class="danger" data-act="delete" data-confirm="이 감시와 모든 소식·실행 기록을 지웁니다. 되돌릴 수 없습니다.">🗑 지우기</button><?php endif; ?>
        </div>
      </div>
    </div>
  </header>

  <?php // ---- 알림 띠: 곧 끝남 / 일시정지 / 종료 / 예산 / 연속 실패 ---- ?>
  <?php if ($w['status'] === 'active' && $secLeft < 3 * 86400): ?>
    <div class="banner warn">⏳ <b><?= h(ow_left($w['ends_at'])) ?></b> 뒤 감시가 끝납니다.
      <?php if ($p['can_extend']): ?><span class="bbtn"><button type="button" data-act="extend" data-days="7">+1주</button><button type="button" data-act="extend" data-days="30">+1달</button></span><?php endif; ?></div>
  <?php elseif ($w['status'] === 'paused'): ?>
    <div class="banner">⏸ 일시정지 중입니다. 정기 실행을 하지 않습니다(지금 실행은 됩니다).
      <?php if ($p['can_resume']): ?><span class="bbtn"><button type="button" data-act="resume">다시 켜기</button></span><?php endif; ?></div>
  <?php elseif ($w['status'] === 'ended'): ?>
    <div class="banner">■ <?= h(date('Y.n.j', strtotime((string)($w['ended_at'] ?: $w['ends_at'])))) ?> 에 끝난 감시입니다. 기록은 그대로 볼 수 있습니다.
      <?php if ($p['can_extend']): ?><span class="bbtn"><button type="button" data-act="extend" data-days="7">1주 더 감시</button><button type="button" data-act="extend" data-days="30">1달 더 감시</button></span><?php endif; ?></div>
  <?php endif; ?>
  <?php if ($monthCost >= $budget && $w['status'] !== 'ended'): ?>
    <div class="banner bad">💸 이번 달 비용($<?= number_format($monthCost, 2) ?>)이 상한($<?= number_format($budget, 2) ?>)에 닿아 남은 정기 실행을 건너뜁니다.
      <?php if ($p['can_edit']): ?><a href="edit.php?id=<?= $id ?>">상한 바꾸기</a><?php endif; ?></div>
  <?php endif; ?>
  <?php if ((int)$w['fail_streak'] >= 2): ?>
    <div class="banner bad">⚠ 최근 <?= (int)$w['fail_streak'] ?>번 연달아 실행에 실패했습니다. <a href="?id=<?= $id ?>&tab=runs">실행 이력</a>에서 이유를 보세요.</div>
  <?php endif; ?>

  <?php if ($hint && $p['can_baseline']): ?>
    <section class="card hintbox">
      <h3>💡 기준 상태를 바꾸자는 제안 <span class="muted"><?= h(ow_when($hint['finished_at'])) ?> 실행</span></h3>
      <p class="help">새 소식이 기준이 된 뒤에는 그것과 달라진 것만 알리는 편이 정확합니다. 반영하기 전에 고칠 수 있습니다.</p>
      <div class="cmp">
        <div><div class="cmp-h">지금</div><div class="cmp-b"><?= nl2br(h($w['baseline'] ?: '(비어 있음)')) ?></div></div>
        <div><div class="cmp-h">제안</div><textarea id="hintText" rows="5"><?= h($hint['baseline_hint']) ?></textarea></div>
      </div>
      <div class="btns"><button type="button" class="btn primary" data-act="baseline" data-from-hint>반영</button></div>
    </section>
  <?php endif; ?>

  <div class="vlayout">
    <main>
      <nav class="tabs">
        <a class="tab<?= $tab === 'findings' ? ' on' : '' ?>" href="?id=<?= $id ?>">소식 <span class="n"><?= count($findings) ?></span></a>
        <a class="tab<?= $tab === 'runs' ? ' on' : '' ?>" href="?id=<?= $id ?>&tab=runs">실행 이력 <span class="n"><?= count($runs) ?></span></a>
        <a class="tab<?= $tab === 'settings' ? ' on' : '' ?>" href="?id=<?= $id ?>&tab=settings">지시문·설정</a>
        <a class="tab<?= $tab === 'log' ? ' on' : '' ?>" href="?id=<?= $id ?>&tab=log">변경 기록</a>
      </nav>

<?php if ($tab === 'findings'): ?>
      <?php if (!$findings): ?>
        <div class="card empty small">
          <?php if (!$runs): ?>
            <h2>아직 실행하지 않았습니다</h2>
            <p><?= $w['run_requested_at'] ? '곧 첫 실행을 시작합니다.' : ($w['next_run_at'] ? '첫 실행은 ' . h(ow_when($w['next_run_at'])) . ' 입니다.' : '') ?></p>
          <?php else: ?>
            <h2>아직 새 소식이 없습니다</h2>
            <p>지금까지 <?= count($runs) ?>번 살폈고 알릴 만한 변화를 찾지 못했습니다. 무엇을 봤는지는 <a href="?id=<?= $id ?>&tab=runs">실행 이력</a>의 조사 노트에 있습니다.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php foreach ($byRun as $runId => $items):
          $r = $runsById[$runId] ?? null;
          usort($items, function ($a, $b) use ($impOrder) { return ($impOrder[$a['importance']] ?? 3) <=> ($impOrder[$b['importance']] ?? 3); });
      ?>
        <section class="fgroup">
          <h3 class="fgroup-h"><?= h(ow_when($items[0]['created_at'])) ?><?php if ($r && $r['summary']): ?> <span class="muted">— <?= h($r['summary']) ?></span><?php endif; ?></h3>
          <?php foreach ($items as $f):
              $isNew = $newSince !== null && $f['created_at'] >= $newSince;
              $srcs = ow_sources($f['sources_json']);
          ?>
          <article class="finding imp-<?= h($f['importance']) ?><?= $isNew ? ' is-new' : '' ?><?= $f['alerted'] ? '' : ' quiet' ?>" id="f<?= (int)$f['id'] ?>">
            <div class="ftags">
              <?php if ($isNew): ?><span class="tag new">NEW</span><?php endif; ?>
              <span class="tag imp"><?= h(OW_IMPORTANCE[$f['importance']] ?? $f['importance']) ?></span>
              <span class="tag"><?= h(OW_CHANGE_TYPE[$f['change_type']] ?? $f['change_type']) ?></span>
              <span class="tag conf-<?= h($f['confidence']) ?>" title="확인 수준"><?= h(OW_CONFIDENCE[$f['confidence']] ?? $f['confidence']) ?></span>
              <?php if (!$f['alerted']): ?><span class="tag ghost" title="최소 중요도보다 낮아 알리지 않고 기록만 했습니다">기록만</span><?php endif; ?>
              <?php if ($f['published']): ?><span class="pub">발표 <?= h($f['published']) ?></span><?php endif; ?>
            </div>
            <h4><?= h($f['title']) ?></h4>
            <div class="md"><?= ow_md($f['detail_md']) ?></div>
            <?php if (trim((string)$f['verification']) !== ''): ?>
              <div class="verify"><b>확인할 것</b><?= ow_md($f['verification']) ?></div>
            <?php endif; ?>
            <?php if ($srcs): ?>
              <ul class="srcs">
                <?php foreach ($srcs as $s): ?>
                  <li><a href="<?= h($s['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($s['title']) ?></a>
                    <span class="host"><?= h(parse_url($s['url'], PHP_URL_HOST)) ?></span>
                    <?php if ($s['published']): ?><span class="muted"><?= h($s['published']) ?></span><?php endif; ?>
                    <?php if (!$s['seen']): ?><span class="unseen" title="조사 중에 이 주소를 직접 열어 보지 못했습니다. 링크가 맞는지 확인하세요">미열람</span><?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <div class="ffoot">
              <?php if ($p['can_feedback']): ?>
                <div class="fb" data-fid="<?= (int)$f['id'] ?>" data-cur="<?= h($f['feedback']) ?>">
                  <?php foreach (OW_FEEDBACK as $k => $label): ?>
                    <button type="button" data-fb="<?= h($k) ?>" class="<?= $f['feedback'] === $k ? 'on' : '' ?>"
                            title="<?= $k === 'useful' ? '도움이 됐습니다' : ($k === 'duplicate' ? '이미 알던 것 — 다음부터 비슷한 것을 알리지 않습니다' : '사실과 다릅니다 — 다음 실행에서 피합니다') ?>"><?= h($label) ?></button>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <?php if ($f['feedback']): ?><span class="muted fb-who"><?= h($f['feedback_name']) ?> 평가</span><?php endif; ?>
            </div>
          </article>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>

<?php elseif ($tab === 'runs'): ?>
      <?php if (!$runs): ?><div class="card empty small"><h2>실행 이력이 없습니다</h2></div><?php endif; ?>
      <div class="runs">
      <?php foreach ($runs as $r): ?>
        <details class="run st-<?= h($r['status']) ?>">
          <summary>
            <span class="rs"><?= h(OW_RUN_STATUS[$r['status']] ?? $r['status']) ?></span>
            <span class="rt"><?= h(ow_when($r['started_at'])) ?></span>
            <span class="rtrig"><?= h(OW_TRIGGER_LABEL[$r['trigger']] ?? $r['trigger']) ?><?= $r['requested_name'] ? ' · ' . h($r['requested_name']) : '' ?></span>
            <span class="rsum"><?= h($r['summary'] ?: ($r['error'] ? mb_strimwidth((string)$r['error'], 0, 90, '…') : '')) ?></span>
            <span class="rnum" title="새 소식 / 이미 알린 것이라 버린 수">+<?= (int)$r['new_findings'] ?><?= (int)$r['dup_findings'] ? ' <span class="muted">(중복 ' . (int)$r['dup_findings'] . ')</span>' : '' ?></span>
            <span class="rcost" title="검색 <?= (int)$r['searches'] ?>회 · 입력 <?= number_format((int)$r['input_tokens']) ?> · 출력 <?= number_format((int)$r['output_tokens']) ?> 토큰">$<?= number_format((float)$r['cost_usd'], 3) ?></span>
          </summary>
          <div class="run-body">
            <p class="muted">엔진 <?= h($r['engine'] ?: '-') ?><?= $r['model'] ? ' · ' . h($r['model']) : '' ?> · 웹 검색 <?= (int)$r['searches'] ?>회
              <?php if ($r['finished_at']): ?> · <?= max(1, strtotime((string)$r['finished_at']) - strtotime((string)$r['started_at'])) ?>초<?php endif; ?>
              <?= $r['notified'] ? ' · 알림 보냄' : '' ?></p>
            <?php if ($r['error']): ?><pre class="errbox"><?= h($r['error']) ?></pre><?php endif; ?>
            <?php if ($r['research_md']): ?><h4>조사 노트</h4><div class="md notes"><?= ow_md($r['research_md']) ?></div><?php endif; ?>
          </div>
        </details>
      <?php endforeach; ?>
      </div>

<?php elseif ($tab === 'settings'): ?>
      <section class="card">
        <h3 class="sec-h">지시문</h3>
        <div class="prompt-box"><?= nl2br(h($w['prompt'])) ?></div>
        <h3 class="sec-h">기준 상태 <?php if ($w['baseline_updated_at']): ?><span class="muted"><?= h(ow_when($w['baseline_updated_at'])) ?> 바뀜</span><?php endif; ?></h3>
        <div class="prompt-box"><?= $w['baseline'] ? nl2br(h($w['baseline'])) : '<span class="muted">비어 있음</span>' ?></div>
        <dl class="kv">
          <dt>주기</dt><dd><?= h(ow_schedule_label($w)) ?></dd>
          <dt>기간</dt><dd><?= h(substr((string)$w['starts_at'], 0, 16)) ?> ~ <?= h(substr((string)$w['ends_at'], 0, 16)) ?></dd>
          <dt>알림</dt><dd><?= h(OW_NOTIFY_MODES[$w['notify_mode']] ?? $w['notify_mode']) ?>, 중요도 <?= h(OW_IMPORTANCE[$w['min_importance']] ?? '') ?> 이상
            · 포털 알림<?php
            if (!ow_config('slack_enabled')) echo ' · 슬랙 <span class="muted">(관리자가 꺼 둠)</span>';
            elseif ($w['notify_slack']) echo ' · 슬랙(' . ($w['slack_webhook_enc'] ? '전용 채널' : '기본 채널') . ')';
            ?></dd>
          <dt>먼저 볼 출처</dt><dd><?= $w['prefer_domains'] ? h(str_replace("\n", ', ', (string)$w['prefer_domains'])) : '<span class="muted">없음</span>' ?></dd>
          <dt>보지 않을 도메인</dt><dd><?= $w['block_domains'] ? h(str_replace("\n", ', ', (string)$w['block_domains'])) : '<span class="muted">없음</span>' ?></dd>
          <dt>검색 상한</dt><dd>한 번에 <?= (int)$w['max_searches'] ?>회</dd>
          <dt>월 비용 상한</dt><dd>$<?= number_format($budget, 2) ?></dd>
        </dl>
        <?php if ($p['can_edit']): ?><p><a class="btn ghost" href="edit.php?id=<?= $id ?>">✎ 고치기</a></p><?php endif; ?>
      </section>

<?php else: ?>
      <section class="card">
        <ul class="log">
          <?php foreach ($events as $e): ?>
            <li><span class="lt"><?= h(ow_when($e['created_at'])) ?></span>
              <b><?= h($e['user_name'] ?: '배치') ?></b>
              <?= h(['create' => '만듦', 'edit' => '고침', 'pause' => '일시정지', 'resume' => '다시 켬', 'end' => '종료',
                     'extend' => '종료일 늘림', 'run' => '실행', 'baseline' => '기준 상태 바꿈', 'subscribe' => '소식 받기 시작',
                     'unsubscribe' => '소식 받기 그만', 'notice' => '알림'][$e['action']] ?? $e['action']) ?>
              <?php if ($e['detail']): ?><span class="muted">— <?= h($e['detail']) ?></span><?php endif; ?></li>
          <?php endforeach; ?>
          <?php if (!$events): ?><li class="muted">기록이 없습니다.</li><?php endif; ?>
        </ul>
      </section>
<?php endif; ?>
    </main>

    <aside class="vside">
      <section class="card">
        <h3 class="side-h">이번 달 비용</h3>
        <?php $pct = $budget > 0 ? min(100, $monthCost / $budget * 100) : 0; ?>
        <div class="meter<?= $pct >= 100 ? ' full' : ($pct >= 80 ? ' high' : '') ?>"><span style="width:<?= round($pct, 1) ?>%"></span></div>
        <p class="meter-l"><b>$<?= number_format($monthCost, 2) ?></b> / $<?= number_format($budget, 2) ?></p>
        <?php if ($lastRun && $lastRun['status'] !== 'running'): ?>
          <p class="muted small">마지막 실행 $<?= number_format((float)$lastRun['cost_usd'], 3) ?> · 검색 <?= (int)$lastRun['searches'] ?>회</p>
        <?php endif; ?>
      </section>
      <section class="card">
        <h3 class="side-h">받는 사람 <span class="muted"><?= count($subs) ?></span></h3>
        <ul class="people">
          <?php foreach ($subs as $s): ?>
            <li><?= ow_avatar($s['name'], $s['color']) ?><?= h($s['name']) ?><?= (int)$s['id'] === (int)$w['owner_id'] ? ' <span class="muted">등록</span>' : '' ?></li>
          <?php endforeach; ?>
          <?php if (!$subs): ?><li class="muted">없음</li><?php endif; ?>
        </ul>
        <p class="muted small">받는 사람은 포털 알림함·푸시로 받습니다<?= ow_config('slack_enabled') && $w['notify_slack'] ? '. 슬랙은 채널로 한 번 갑니다' : '' ?>. 기기 푸시는 마이페이지의 알림 설정을 따릅니다.</p>
      </section>
    </aside>
  </div>
</div>
<?php ow_page_foot(['page' => 'view', 'id' => $id, 'state' => $p['state'], 'lastRunId' => $lastRun ? (int)$lastRun['id'] : null]); ?>
