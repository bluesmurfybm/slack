<?php
/**
 * MoodleUp(무들 동향) — 주간 팔로업 리포트 뷰어.
 *  - 데이터: moodle/watch/ 의 Python 배치가 MySQL 에 넣는다. 이 페이지는 SELECT 만 한다
 *    (형광펜·메모는 notes.php 가 사용자가 누를 때만 INSERT/DELETE).
 *  - 로그인: 포털 세션(current_portal_user). slack 과 달리 슬랙 토큰은 필요 없다.
 *  - ?week=2026-W37 로 주차를 고른다. 없으면 최신.
 *  - PAG(Technical Transformation) 코스가 요약의 중심이라 소스 칩·원문 묶음·요약 절을 따로 강조한다.
 */
date_default_timezone_set('Asia/Seoul');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/db.php';

$me = current_portal_user();
if (!$me) {
    header('Location: ../index.php');
    exit;
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // 읽기 전용 — 세션 잠금 바로 해제
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$weekParam = isset($_GET['week']) && preg_match('/^\d{4}-W\d{2}$/', $_GET['week']) ? $_GET['week'] : null;
$weeks  = moodle_weeks();
$report = moodle_report($weekParam);
$items  = $report ? moodle_items($report['id']) : [];
$runs   = $report ? moodle_runs($report['id']) : [];
$notes  = $report ? moodle_notes($report['id']) : [];
$refresh = $report ? moodle_refresh_state($report['week']) : null;   // 갱신 요청 파일 상태
$runCount = $report ? max(1, (int)$report['run_count']) : 1;
$isLatest = $report && $weeks && $weeks[0]['week'] === $report['week'];
$flashErr = isset($_GET['err']) ? (string)$_GET['err'] : '';
$queued   = isset($_GET['queued']);
// 관리자(요약하기·갱신 이력·상태 배지·처리 상태)와 일반 화면. 관리자는 ?as=user 로 일반 화면을 미리 본다.
$isAdmin    = moodle_is_admin($me['email']);
$viewAsUser = $isAdmin && (($_GET['as'] ?? '') === 'user');
$showAdmin  = $isAdmin && !$viewAsUser;
$asParam    = $viewAsUser ? '&as=user' : '';

$SOURCE_LABEL = [
    'moodleorg' => 'moodle.org PAG',
    'github'    => 'GitHub moodle/moodle',
    'devdocs'   => 'moodledev.io',
    'moodlecom' => 'moodle.com 뉴스',
    'tracker'   => 'Tracker (Jira)',
];
$KIND_LABEL = [
    'forum_post' => '포럼 글', 'page_change' => '페이지 변경', 'issue' => '이슈', 'upgrade_txt' => 'upgrade.txt',
    'branch' => '브랜치', 'release' => '릴리스', 'doc_change' => '문서 변경', 'news' => '뉴스',
];
$IMPACT_CLASS = ['고' => 'hi', '중' => 'mid', '저' => 'lo'];
// 배지에 마우스를 올리면 뜨는 설명. 리포트 상태와 소스 상태가 같은 낱말을 쓴다.
$STATUS_HELP = [
    'ok'      => '모든 소스를 수집했고 요약도 만들었습니다',
    'partial' => '일부 소스가 실패했거나 요약을 만들지 못했습니다. 수집된 항목은 그대로 저장됐습니다',
    'failed'  => '모든 소스가 실패해 이 실행은 저장하지 않았습니다',
    'skipped' => '설정이 없어 건너뛴 소스입니다(예: moodle.org 토큰 없음)',
];
$TRIGGER_LABEL = ['timer' => '자동(주간)', 'manual' => '화면에서 수동', 'cli' => '명령줄'];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function badge($status, $extra = '') {
    global $STATUS_HELP, $showAdmin;
    if (!$showAdmin) return '';   // 상태 배지는 관리자 화면에만
    return '<span class="badge ' . h($status) . ' ' . $extra . '" title="' . h($STATUS_HELP[$status] ?? '') . '">' . h($status) . '</span>';
}

// 소스별 묶음(표시 순서 고정) + 필터 칩용 카운트
$groups = [];
$counts = ['source' => [], 'impact' => ['고' => 0, '중' => 0, '저' => 0], 'focus' => 0, 'new' => 0];
foreach ($items as $it) {
    $groups[$it['source']][] = $it;
    $counts['source'][$it['source']] = ($counts['source'][$it['source']] ?? 0) + 1;
    if ($it['impact'] && isset($counts['impact'][$it['impact']])) $counts['impact'][$it['impact']]++;
    if ($it['is_focus']) $counts['focus']++;
    if ($runCount > 1 && (int)$it['added_run'] === $runCount) $counts['new']++;   // 1회차 생성분은 NEW 가 아니다
}
$orderedGroups = [];
foreach (array_keys($SOURCE_LABEL) as $src) if (!empty($groups[$src])) $orderedGroups[$src] = $groups[$src];
foreach ($groups as $src => $rows) if (!isset($orderedGroups[$src])) $orderedGroups[$src] = $rows;

// 갱신 요청 상태 — 대기가 길면(배치가 안 떠 있음) 알려주고 취소할 수 있게 한다
$busy = $refresh && in_array($refresh['state'], ['pending', 'running'], true);
$waitMin = 0;
if ($busy) {
    $since = $refresh['state'] === 'running' ? ($refresh['started_at'] ?? $refresh['requested_at'] ?? '') : ($refresh['requested_at'] ?? '');
    $ts = $since ? strtotime($since) : false;
    if ($ts) $waitMin = (int)floor((time() - $ts) / 60);
}
$stale = $busy && (($refresh['state'] === 'pending' && $waitMin >= 3) || ($refresh['state'] === 'running' && $waitMin >= 30));
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MoodleUp? · blue-iWorks</title>
<link rel="icon" href="../styles/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
<link rel="stylesheet" href="../styles/topbar.css">
<link rel="stylesheet" href="styles/moodle.css">
</head>
<body>
<div class="topbar">
  <div class="topbar-in">
    <a class="logo" href="../index.php" style="text-decoration:none"><b>blue</b><span class="dash">-</span>iWorks</a>
    <div class="top-right">
      <div class="user-menu" id="hdrUserMenu">
        <div class="user-chip" onclick="__hdrToggleMenu(event)" title="메뉴">
          <span class="avatar" style="background:<?= h(user_color($me)) ?>"><?= h(mb_substr($me['name'], 0, 1)) ?></span>
          <span class="nm"><?= h($me['name']) ?></span>
          <span class="user-caret">▾</span>
        </div>
        <div class="dd-menu" id="hdrUserDd">
          <a href="../index.php?view=profile">👤 마이페이지</a>
          <div class="dd-sep"></div>
          <a href="../index.php">🏠 대시보드</a>
          <div class="dd-sep"></div>
          <a href="../api/logout.php">🚪 로그아웃</a>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="wrap">
  <div class="hero">
    <div>
      <h1>MoodleUp?</h1>
      <p><span class="pag-tag">PAG</span> moodle.org Tech Transformation 코스를 중심으로 Tracker · GitHub · moodledev.io · moodle.com 을 매주 모아 코스모스 관점으로 요약합니다.</p>
    </div>
    <a class="back" href="../index.php">← 대시보드</a>
  </div>

<?php if (!$report): ?>
  <div class="card empty">
    <h2>아직 수집된 주차가 없습니다</h2>
    <p>주간 배치가 처음 돌면 여기에 리포트가 쌓입니다.<br>
    서버에서 <code>moodle/watch/run_weekly.py</code> 를 한 번 실행하거나 <code>moodle-watch.timer</code> 가 월요일 새벽에 도는지 확인하세요.</p>
  </div>
<?php else: ?>
  <div class="layout">
    <aside class="card side">
      <h2>주차 <span class="tiny"><?= count($weeks) ?>개</span></h2>
      <?php
      // 달(수집 종료일 KST 기준)로 묶는다. 이번 달만 펼치고 나머지는 접는다. 보고 있는 주차의 달도 펼친다.
      // 이번 달에 아직 리포트가 없으면(월초) 가장 최근 리포트의 달을 이번 달로 본다.
      $monthKey = function ($w) { return moodle_kst($w['period_end'], 'Y-m'); };
      $byMonth = [];
      foreach ($weeks as $w) $byMonth[$monthKey($w)][] = $w;
      $thisMonth = date('Y-m');
      if (!isset($byMonth[$thisMonth]) && $weeks) $thisMonth = $monthKey($weeks[0]);
      $currentMonth = $monthKey($report);
      $label = function ($ym) { return substr($ym, 2, 2) . '년 ' . (int)substr($ym, 5, 2) . '월'; };
      foreach ($byMonth as $ym => $rows): $open = $ym === $thisMonth || $ym === $currentMonth; ?>
        <details class="wk-month <?= $ym === $thisMonth ? 'now' : '' ?>" <?= $open ? 'open' : '' ?>>
          <summary><?= h($label($ym)) ?><?= $ym === $thisMonth ? '<span class="tiny now-tag">이번 달</span>' : '' ?></summary>
          <?php foreach ($rows as $w): ?>
            <a class="wk <?= $w['week'] === $report['week'] ? 'on' : '' ?>" href="?week=<?= h($w['week']) ?><?= $asParam ?>">
              <b><?= h($w['week']) ?> <?= badge($w['status'], 'st') ?></b>
              <span><?= h(moodle_kst($w['period_start'], 'm.d')) ?> ~ <?= h(moodle_kst($w['period_end'], 'm.d')) ?><?= $showAdmin && (int)$w['run_count'] > 1 ? ' · 갱신 ' . ((int)$w['run_count'] - 1) . '회' : '' ?></span>
              <?php if ($w['headline']): ?><span class="hl-line" title="<?= h($w['headline']) ?>"><?= h($w['headline']) ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </details>
      <?php endforeach; ?>
      <?php if ($showAdmin): ?>
      <div class="legend">
        <h2>상태 배지</h2>
        <?php foreach (['ok', 'partial', 'failed'] as $st): ?>
          <div><?= badge($st) ?> <span><?= h($STATUS_HELP[$st]) ?></span></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </aside>

    <main>
      <section class="card sec-card" id="sumCard">
        <div class="sec-h head" id="sumHead" title="클릭하면 요약을 접거나 펼칩니다">
        <div class="report-head">
          <span class="chev">▾</span>
          <h2><?= h($report['week']) ?></h2>
          <?= badge($report['status']) ?>
          <span class="meta">수집 구간 <?= h(moodle_kst($report['period_start'])) ?> ~ <?= h(moodle_kst($report['period_end'])) ?> (KST)</span>
          <span class="meta"><?= $runCount > 1 ? '마지막 갱신 ' : '생성 ' ?><?= h(moodle_kst($report['generated_at'])) ?><?= $showAdmin && $report['model'] ? ' · ' . h($report['model']) : '' ?></span>
          <?php if ($showAdmin && $runCount > 1): ?><span class="meta">갱신 <?= $runCount - 1 ?>회 · 처음 생성 <?= h(moodle_kst($report['first_generated_at'] ?: $report['generated_at'])) ?></span><?php endif; ?>
          <?php if ($showAdmin): ?>
          <form class="refresh" method="post" action="refresh.php" id="refreshForm">
            <input type="hidden" name="week" value="<?= h($report['week']) ?>">
            <button class="btn" type="submit" <?= $busy ? 'disabled' : '' ?> title="<?= $isLatest ? '이 주차를 지금 시각까지 다시 수집하고, 새로 들어온 것만 요약해 기존 요약 아래에 덧붙입니다' : '이 주차 구간을 다시 수집하고, 새로 들어온 것만 요약해 기존 요약 아래에 덧붙입니다' ?>">
              <span class="spin" <?= $busy ? '' : 'hidden' ?>></span><?= $busy ? '요약 중' : '↻ 요약하기' ?>
            </button>
            <a class="btn ghost" href="?week=<?= h($report['week']) ?>&as=user" title="다른 계정이 보는 화면 그대로 봅니다">일반계정화면</a>
          </form>
          <?php elseif ($viewAsUser): ?>
          <div class="refresh"><span class="viewing">일반 계정 화면 보기 중</span><a class="btn ghost" href="?week=<?= h($report['week']) ?>">관리자 화면으로</a></div>
          <?php endif; ?>
        </div>
        <?php if ($showAdmin && $flashErr): ?><div class="note"><?= h($flashErr) ?></div><?php endif; ?>
        <?php if ($showAdmin && $busy): ?>
          <div class="pending <?= $stale ? 'stale' : '' ?>" id="pending" data-week="<?= h($report['week']) ?>" data-gen="<?= h($report['generated_at']) ?>">
            <?php if ($refresh['state'] === 'running'): ?>
              배치가 수집·요약하고 있습니다<?= $waitMin ? " ({$waitMin}분 경과)" : '' ?>. 보통 1~3분 걸리고 끝나면 이 화면이 자동으로 새로 고쳐집니다.
            <?php elseif ($stale): ?>
              요청을 남긴 지 <?= $waitMin ?>분이 지났는데 배치가 집어가지 않았습니다. 서버에서는 <code>moodle-watch-refresh.path</code>, 로컬에서는 <code>python run_weekly.py --serve</code> 가 떠 있어야 합니다.
            <?php else: ?>
              요약 요청을 남겼습니다. 배치가 집어가기를 기다리는 중입니다<?= $waitMin ? " ({$waitMin}분 경과)" : '' ?>.
            <?php endif; ?>
            <?php if (!empty($refresh['requested_by'])): ?><span class="tiny">요청 <?= h($refresh['requested_by']) ?> · <?= h(moodle_kst(substr((string)($refresh['requested_at'] ?? ''), 0, 19))) ?></span><?php endif; ?>
            <?php if ($refresh['state'] === 'pending' || $stale): ?>
              <form method="post" action="refresh.php" class="inline"><input type="hidden" name="week" value="<?= h($report['week']) ?>"><input type="hidden" name="cancel" value="1"><button class="btn ghost" type="submit">요청 취소</button></form>
            <?php endif; ?>
          </div>
        <?php elseif ($showAdmin && $refresh && $refresh['state'] === 'failed'): ?>
          <div class="note">마지막 요약 요청이 실패했습니다: <?= h($refresh['error'] ?? '사유 없음') ?> — 요약하기를 다시 누르면 재시도합니다.</div>
        <?php elseif ($showAdmin && $queued): ?>
          <div class="pending">요청을 남겼지만 아직 배치가 집어가지 않았습니다. 서버에서는 <code>moodle-watch-refresh.path</code>, 로컬에서는 <code>run_weekly.py --serve</code> 가 떠 있어야 합니다.</div>
        <?php endif; ?>
        <?php if ($report['headline']): ?><div class="headline"><?= moodle_md_inline($report['headline']) ?></div><?php endif; ?>
        <?php if ($showAdmin && $report['note']): ?><div class="note"><?= $runCount > 1 && $report['summary_md'] ? '마지막 갱신은 요약을 만들지 못해 이전 요약을 그대로 두었습니다 — ' : '' ?><?= h($report['note']) ?></div><?php endif; ?>

        </div>
        <div class="sec-b" id="sumBody">
        <div class="srcs">
          <?php foreach ($report['sources'] as $s): $label = $SOURCE_LABEL[$s['source']] ?? $s['source']; $isPag = $s['source'] === 'moodleorg'; ?>
            <div class="src <?= $isPag ? 'pag' : '' ?> <?= !empty($groups[$s['source']]) ? 'go' : '' ?>" data-src="<?= h($s['source']) ?>" title="<?= $showAdmin ? h($s['note'] ?? '') : '' ?><?= !empty($groups[$s['source']]) ? ' 클릭하면 원문 항목으로 이동' : '' ?>">
              <?php if ($isPag): ?><span class="pag-tag">핵심</span><?php endif; ?>
              <b><?= h($label) ?></b>
              <?= badge($s['status']) ?>
              <span class="n"><?= (int)($s['count'] ?? 0) ?>건<?php
                if ($isPag && isset($s['stats']['pages_changed'])) echo ' · 글 ' . (int)($s['stats']['posts'] ?? 0) . ' · 페이지 변경 ' . (int)$s['stats']['pages_changed'];
                if ($s['source'] === 'tracker' && isset($s['stats']['fixed'])) echo ' · Fixed ' . (int)$s['stats']['fixed'];
                if ($s['source'] === 'github' && isset($s['stats']['main_commits'])) echo ' · main 커밋 ' . (int)$s['stats']['main_commits'];
              ?></span>
            </div>
          <?php endforeach; ?>
        </div>


        <?php if ($report['summary_md']): ?>
          <div class="hint-annot tiny">요약 글을 드래그하면 <b>형광펜</b>·<b>메모</b>를 남길 수 있습니다. 표시는 팀이 함께 봅니다. 갱신된 내용은 구분선 아래에 날짜와 함께 덧붙습니다.</div>
          <div class="summary annot" data-target="summary"><?= moodle_md_emphasize_pag(moodle_md($report['summary_md'])) ?></div>
        <?php else: ?>
          <p class="tiny" style="margin-top:14px">이 주차는 요약 없이 원문 항목만 수집되었습니다.</p>
        <?php endif; ?>

        <?php // 요약 본문에 이미 '후속 액션' 절이 있으면 같은 내용을 두 번 보이지 않는다
        if ($report['actions'] && mb_strpos((string)$report['summary_md'], '후속 액션') === false): ?>
          <div class="actions">
            <h3>후속 액션</h3>
            <ol><?php foreach ($report['actions'] as $a): ?><li><?= moodle_md_inline($a) ?></li><?php endforeach; ?></ol>
          </div>
        <?php endif; ?>

        <div class="notes" id="notes" hidden>
          <h3>위치를 못 찾은 표시 <span class="badge" id="notesCount">0</span> <span class="tiny">요약이 갱신되어 문장이 바뀐 표시입니다</span></h3>
          <ul id="notesList"></ul>
        </div>
        </div>
      </section>

      <?php if ($showAdmin && count($runs) > 1): ?>
      <details class="card runs sec">
        <summary class="sec-h">갱신 이력 <span class="badge"><?= count($runs) ?>회</span> <span class="tiny"></span></summary>
        <ol class="runlist">
          <?php foreach ($runs as $r): ?>
            <li class="run">
              <div class="run-h">
                <b><?= (int)$r['run_no'] === 1 ? '처음 생성' : (int)$r['run_no'] . '회차 갱신' ?></b>
                <span class="tiny"><?= h(moodle_kst($r['ran_at'])) ?> · <?= h($TRIGGER_LABEL[$r['trigger']] ?? $r['trigger']) ?> · <?= badge($r['status']) ?>
                  <?php if ((int)$r['run_no'] > 1): ?> · 새 항목 <?= (int)$r['new_items'] ?>건<?php else: ?> · 항목 <?= (int)$r['new_items'] ?>건<?php endif; ?></span>
              </div>
              <?php if ((int)$r['run_no'] > 1 && $r['updates_md']): ?><div class="run-b"><?= moodle_md_emphasize_pag(moodle_md($r['updates_md'])) ?></div>
              <?php elseif ((int)$r['run_no'] > 1 && (int)$r['new_items'] === 0): ?><div class="run-b tiny">새로 들어온 항목이 없었습니다.</div>
              <?php elseif ($r['headline']): ?><div class="run-b tiny"><?= moodle_md_inline($r['headline']) ?></div><?php endif; ?>
              <?php if ($r['note']): ?><div class="run-b tiny"><?= h($r['note']) ?></div><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>
      </details>
      <?php endif; ?>

      <section class="card sec-card items" id="itemsSec">
      <div class="sec-h static nonecolor">원문 항목 <span class="badge"><?= count($items) ?>건</span> <span class="tiny"></span></div>
      <div class="sec-b">
      <div class="items-head">
        <div class="chips" id="chips">
          <button class="chip on" data-f="all">전체</button>
          <?php if (!empty($orderedGroups['moodleorg'])): ?><button class="chip pag" data-f="src:moodleorg">PAG<span class="n"><?= count($orderedGroups['moodleorg']) ?></span></button><?php endif; ?>
          <?php if ($counts['new']): ?><button class="chip" data-f="new">NEW<span class="n"><?= $counts['new'] ?></span></button><?php endif; ?>
          <button class="chip" data-f="focus">★ 주목<span class="n"><?= $counts['focus'] ?></span></button>
          <?php foreach ($counts['impact'] as $imp => $n): if (!$n) continue; ?>
            <button class="chip" data-f="imp:<?= h($imp) ?>">영향 <?= h($imp) ?><span class="n"><?= $n ?></span></button>
          <?php endforeach; ?>
          <?php foreach ($orderedGroups as $src => $rows): if ($src === 'moodleorg') continue; ?>
            <button class="chip" data-f="src:<?= h($src) ?>"><?= h($SOURCE_LABEL[$src] ?? $src) ?><span class="n"><?= count($rows) ?></span></button>
          <?php endforeach; ?>
        </div>
      </div>

      <?php foreach ($orderedGroups as $src => $rows): $limit = $src === 'tracker' ? 15 : 40; $isPag = $src === 'moodleorg'; ?>
        <details class="group <?= $isPag ? 'pag' : '' ?>" data-src="<?= h($src) ?>">
          <summary class="sec-h sub"><?php if ($isPag): ?><span class="pag-tag">핵심</span><?php endif; ?><?= h($SOURCE_LABEL[$src] ?? $src) ?> <span class="badge"><?= count($rows) ?></span><?php if ($isPag): ?><span class="tiny">Technical Transformation PAG 코스의 포럼 글과 페이지 변경 — 요약의 중심</span><?php endif; ?></summary>
          <div class="group-b">
          <?php foreach ($rows as $i => $it):
            $impCls = $IMPACT_CLASS[$it['impact'] ?? ''] ?? '';
            $isDiff = in_array($it['kind'], ['page_change', 'upgrade_txt', 'doc_change'], true);
            $excerpt = trim((string)$it['excerpt']);
            $long = mb_strlen($excerpt) > 420;
          ?>
            <div class="item <?= $i >= $limit ? 'rest' : '' ?>"
                 data-src="<?= h($src) ?>" data-imp="<?= h($it['impact'] ?? '') ?>" data-focus="<?= $it['is_focus'] ? 1 : 0 ?>" data-new="<?= ($runCount > 1 && (int)$it['added_run'] === $runCount) ? 1 : 0 ?>">
              <div><?php if ($it['impact']): ?><span class="imp <?= $impCls ?>"><?= h($it['impact']) ?></span><?php elseif ($it['is_focus']): ?><span class="star" title="주목">★</span><?php endif; ?></div>
              <div class="t"><a href="<?= h($it['url']) ?>" target="_blank" rel="noopener"><?= h($it['title']) ?></a></div>
              <div class="m">
                <?php if ($runCount > 1 && (int)$it['added_run'] === $runCount): ?><span class="newb">NEW</span><?php elseif ((int)$it['added_run'] > 1): ?><span class="tiny" title="<?= (int)$it['added_run'] ?>회차 갱신에서 추가"><?= (int)$it['added_run'] ?>회차</span><?php endif; ?>
                <?php if ($isPag): ?><span class="pag-tag">PAG</span><?php endif; ?>
                <span><?= h($KIND_LABEL[$it['kind']] ?? $it['kind']) ?></span>
                <?php if ($it['published_at']): ?><span><?= h(moodle_kst($it['published_at'])) ?></span><?php endif; ?>
                <?php if (!empty($it['meta']['forum'])): ?><span><?= h($it['meta']['forum']) ?></span><?php endif; ?>
                <?php if (!empty($it['meta']['author'])): ?><span><?= h($it['meta']['author']) ?></span><?php endif; ?>
                <?php if (!empty($it['meta']['type'])): ?><span><?= h($it['meta']['type']) ?></span><?php endif; ?>
                <?php if (!empty($it['meta']['components'])): ?><span><?= h(implode(', ', $it['meta']['components'])) ?></span><?php endif; ?>
                <?php if (!empty($it['meta']['fix_versions'])): ?><span>fix <?= h(implode(', ', $it['meta']['fix_versions'])) ?></span><?php endif; ?>
                <?php if (!empty($it['meta']['files'])): ?><span><?= h(implode(', ', $it['meta']['files'])) ?></span><?php endif; ?>
                <?php if ($it['is_focus'] && $it['impact']): ?><span class="star">★ 주목</span><?php endif; ?>
              </div>
              <?php if ($it['impact_reason']): ?><div class="why"><?= h($it['impact_reason']) ?></div><?php endif; ?>
              <?php if ($excerpt !== '' && $src !== 'tracker'): ?>
                <pre class="x <?= $isDiff ? 'diff' : '' ?>"><?= h($excerpt) ?></pre>
                <?php if ($long): ?><button class="more" type="button" onclick="this.previousElementSibling.classList.toggle('open');this.textContent=this.textContent==='더 보기'?'접기':'더 보기'">더 보기</button><?php endif; ?>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if (count($rows) > $limit): ?>
            <button class="toggle" type="button" onclick="var g=this.closest('.group');g.classList.toggle('expanded');this.textContent=g.classList.contains('expanded')?'접기':'나머지 <?= count($rows) - $limit ?>건 더 보기'">나머지 <?= count($rows) - $limit ?>건 더 보기</button>
          <?php endif; ?>
          </div>
        </details>
      <?php endforeach; ?>
      </div>
      </section>
    </main>
  </div>

  <!-- 드래그 선택 시 뜨는 미니 도구막대 -->
  <div class="hlbar" id="hlbar" hidden>
    <button type="button" data-act="highlight" data-color="yellow" title="노란 형광펜"><span class="swatch yellow"></span></button>
    <button type="button" data-act="highlight" data-color="orange" title="주황 형광펜"><span class="swatch orange"></span></button>
    <button type="button" data-act="highlight" data-color="green" title="초록 형광펜"><span class="swatch green"></span></button>
    <span class="sep"></span>
    <button type="button" data-act="note" title="메모"><span class="swatch blue"></span>메모</button>
  </div>
  <div class="npop" id="npop" hidden></div>
  <div class="notebox" id="notebox" hidden>
    <div class="q" id="noteboxQuote"></div>
    <textarea id="noteboxText" rows="3" placeholder="메모를 적고 저장을 누르세요"></textarea>
    <div class="row"><button type="button" class="btn ghost" id="noteboxCancel">취소</button><button type="button" class="btn" id="noteboxSave">저장</button></div>
  </div>
<?php endif; ?>
</div>

<script>
function __hdrToggleMenu(e){ if(e) e.stopPropagation(); document.getElementById("hdrUserMenu").classList.toggle("open"); }
document.addEventListener("click", function(e){
  var um = document.getElementById("hdrUserMenu");
  if(um && !um.contains(e.target)) um.classList.remove("open");
});

// 필터 칩: 서버가 그려 둔 항목을 data-* 로 걸러 보인다. 새 요청은 없다.
(function(){
  var chips = document.getElementById("chips");
  if(!chips) return;
  chips.addEventListener("click", function(e){
    var btn = e.target.closest(".chip"); if(!btn) return;
    chips.querySelectorAll(".chip").forEach(function(c){ c.classList.remove("on"); });
    btn.classList.add("on");
    var f = btn.dataset.f;
    document.querySelectorAll(".group").forEach(function(g){
      var shown = 0;
      g.querySelectorAll(".item").forEach(function(it){
        var ok = f === "all"
          || (f === "focus" && it.dataset.focus === "1")
          || (f === "new" && it.dataset.new === "1")
          || (f.indexOf("imp:") === 0 && it.dataset.imp === f.slice(4))
          || (f.indexOf("src:") === 0 && it.dataset.src === f.slice(4));
        it.classList.toggle("hidden", !ok);
        if(ok) shown++;
      });
      g.style.display = shown ? "" : "none";
      // 필터가 걸리면 결과가 있는 묶음을 열고 접힌 나머지도 펼쳐 보인다
      if(f !== "all" && shown) g.open = true;
      g.classList.toggle("expanded", f !== "all");
      var t = g.querySelector(".toggle"); if(t) t.style.display = f === "all" ? "" : "none";
    });
  });
})();

// 요약 요청이 진행 중이면 상태를 몇 초마다 물어보고, 끝나면(generated_at 이 바뀌면) 새로 고친다.
(function(){
  var el = document.getElementById("pending");
  if(!el) return;
  var week = el.dataset.week, gen = el.dataset.gen, tries = 0;
  function tick(){
    fetch("refresh.php?status=" + encodeURIComponent(week), {credentials:"same-origin"})
      .then(function(r){ return r.json(); })
      .then(function(st){
        if(st.state === "idle" || st.state === "failed" || (st.generated_at && st.generated_at !== gen)) { location.reload(); return; }
        if(++tries < 120) setTimeout(tick, 5000);   // 최대 10분
      })
      .catch(function(){ if(++tries < 120) setTimeout(tick, 8000); });
  }
  setTimeout(tick, 5000);
})();

// ---- 요약 카드: 회색 머리 부분을 누르면 본문을 접거나 펼친다(버튼·링크·폼은 제외)
(function(){
  var head = document.getElementById("sumHead"), body = document.getElementById("sumBody");
  if(!head || !body) return;
  head.addEventListener("click", function(e){
    if(e.target.closest("form, button, a, input, textarea")) return;
    var closed = body.hidden = !body.hidden;
    head.classList.toggle("closed", closed);
    head.querySelector(".chev").textContent = closed ? "▸" : "▾";
  });
})();

// ---- 소스 칩 클릭 → 아래 원문 묶음으로 스크롤
document.querySelectorAll(".src.go").forEach(function(el){
  el.addEventListener("click", function(){
    var g = document.querySelector('.group[data-src="' + el.dataset.src + '"]');
    if(!g) return;
    if(g.style.display === "none"){ var all = document.querySelector('#chips .chip[data-f="all"]'); if(all) all.click(); }
    g.open = true;
    window.scrollTo(0, g.getBoundingClientRect().top + window.scrollY - 70);   // 상단바 높이만큼 띄운다
    g.classList.add("flash"); setTimeout(function(){ g.classList.remove("flash"); }, 1600);
  });
});

// ---- 형광펜·메모 -------------------------------------------------------------
// 위치는 "선택한 글 + 앞뒤 문맥" 텍스트 앵커로 저장한다. 요약이 갱신되어 문장이 바뀌면 그 표시는
// 본문에 찍히지 않고 아래 '위치를 못 찾은 표시' 목록에만 남는다(거기서 지울 수 있다).
(function(){
  var REPORT_ID = <?= $report ? (int)$report['id'] : 0 ?>;
  var ME = <?= json_encode($me['email'] ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var NOTES = <?= json_encode(array_map(function ($n) {
      return ['id' => (int)$n['id'], 'target' => $n['target'], 'kind' => $n['kind'], 'text' => $n['anchor_text'],
              'prefix' => $n['prefix'], 'suffix' => $n['suffix'], 'note' => $n['note'], 'color' => $n['color'],
              'user_name' => $n['user_name'], 'user_email' => $n['user_email'], 'created_at' => moodle_kst($n['created_at'])];
  }, $notes), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  if(!REPORT_ID) return;

  var byId = {};
  NOTES.forEach(function(n){ byId[n.id] = n; });
  var containers = {};
  document.querySelectorAll(".annot").forEach(function(c){ containers[c.dataset.target] = c; });
  var hlbar = document.getElementById("hlbar"), notebox = document.getElementById("notebox"), npop = document.getElementById("npop");
  var pendingSel = null;   // {target, text, prefix, suffix}

  function hideBar(){ hlbar.hidden = true; }
  function hidePop(){ npop.hidden = true; npop.innerHTML = ""; }
  function esc(t){ var d = document.createElement("div"); d.textContent = t == null ? "" : t; return d.innerHTML; }

  // 컨테이너의 텍스트 노드와 누적 오프셋. textContent 와 같은 순서라 오프셋이 일치한다.
  function textNodes(root){
    var out = [], pos = 0, w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null), n;
    while((n = w.nextNode())){ out.push({node:n, start:pos, end:pos + n.nodeValue.length}); pos += n.nodeValue.length; }
    return out;
  }
  // [start,end) 구간의 텍스트를 <mark> 로 감싼다. 경계에 걸린 텍스트 노드는 잘라서 가운데만 감싼다.
  function wrapRange(root, start, end, note){
    textNodes(root).forEach(function(t){
      if(t.end <= start || t.start >= end) return;
      var node = t.node, from = Math.max(start, t.start) - t.start, to = Math.min(end, t.end) - t.start;
      if(to < node.nodeValue.length) node.splitText(to);
      if(from > 0) node = node.splitText(from);
      var m = document.createElement("mark");
      m.className = "hl " + note.color + (note.kind === "note" ? " has-note" : "");
      m.dataset.id = note.id;
      m.title = note.kind === "note" ? "클릭하면 메모가 보입니다" : (note.user_name || "") + " 형광펜 · 클릭하면 자세히";
      node.parentNode.insertBefore(m, node); m.appendChild(node);
    });
  }
  // 앵커 찾기: 앞뒤 문맥까지 맞는 곳 → 문맥 일부 → 글만 맞는 첫 곳
  function locate(full, note){
    var i = -1;
    if(note.prefix || note.suffix){
      var k = full.indexOf(note.prefix + note.text + note.suffix);
      if(k >= 0) i = k + note.prefix.length;
    }
    for(var cut = Math.min(20, note.prefix.length); i < 0 && cut >= 0; cut -= 5){
      var pre = note.prefix.slice(note.prefix.length - cut);
      var k2 = full.indexOf(pre + note.text);
      if(k2 >= 0) i = k2 + pre.length;
    }
    if(i < 0) i = full.indexOf(note.text);
    return i;
  }
  function addLost(n){
    var box = document.getElementById("notes"), list = document.getElementById("notesList");
    var li = document.createElement("li"); li.dataset.id = n.id;
    li.innerHTML = '<span class="swatch ' + esc(n.color) + '"></span><div class="nb"><span class="q">“' + esc(n.text.length > 120 ? n.text.slice(0, 119) + "…" : n.text) + '”</span>' +
      (n.note ? '<div class="nt">' + esc(n.note) + '</div>' : '') + '<div class="tiny">' + esc((n.user_name || n.user_email) + " · " + n.created_at) + '</div></div>' +
      (n.user_email === ME ? '<button class="x" type="button" title="지우기" data-del="' + n.id + '">×</button>' : '');
    list.appendChild(li); box.hidden = false;
    document.getElementById("notesCount").textContent = list.children.length;
  }
  function apply(note){
    var root = containers[note.target] || containers.summary;
    if(!root){ addLost(note); return false; }
    var full = root.textContent, i = locate(full, note);
    if(i < 0){ addLost(note); return false; }
    wrapRange(root, i, i + note.text.length, note);
    return true;
  }
  NOTES.forEach(apply);

  function post(body){
    return fetch("notes.php", {method:"POST", credentials:"same-origin", headers:{"Content-Type":"application/json"}, body: JSON.stringify(body)})
      .then(function(r){ return r.json().then(function(j){ if(!r.ok) throw new Error(j.error || r.status); return j; }); });
  }
  function removeNote(id){
    document.querySelectorAll('mark.hl[data-id="' + id + '"]').forEach(function(m){
      while(m.firstChild) m.parentNode.insertBefore(m.firstChild, m);
      m.remove();
    });
    var li = document.querySelector('#notesList li[data-id="' + id + '"]');
    if(li){ li.remove(); var list = document.getElementById("notesList"); document.getElementById("notesCount").textContent = list.children.length; if(!list.children.length) document.getElementById("notes").hidden = true; }
    delete byId[id];
  }

  // 표시 클릭 → 그 자리에 팝오버(누가·언제·무슨 내용, 본인 것이면 지우기)
  function showPop(mark){
    var n = byId[mark.dataset.id]; if(!n) return;
    var rect = mark.getBoundingClientRect();
    npop.innerHTML = '<div class="npop-h"><span class="swatch ' + esc(n.color) + '"></span><b>' + esc(n.user_name || n.user_email) + '</b><span class="tiny">' + esc(n.created_at) + '</span>' +
      (n.user_email === ME ? '<button class="x" type="button" title="지우기" data-del="' + n.id + '">×</button>' : '') + '</div>' +
      (n.note ? '<div class="npop-b">' + esc(n.note) + '</div>' : '<div class="npop-b tiny">형광펜</div>');
    npop.hidden = false;
    var left = Math.min(rect.left + window.scrollX, window.scrollX + document.documentElement.clientWidth - npop.offsetWidth - 12);
    npop.style.left = Math.max(8, left) + "px";
    npop.style.top = (rect.bottom + window.scrollY + 6) + "px";
  }
  document.addEventListener("click", function(e){
    var del = e.target.closest("[data-del]");
    if(del){
      if(!confirm("이 표시를 지울까요?")) return;
      post({action:"delete", id:+del.dataset.del}).then(function(){ removeNote(del.dataset.del); hidePop(); })
        .catch(function(err){ alert("지우지 못했습니다: " + err.message); });
      return;
    }
    var mark = e.target.closest("mark.hl");
    if(mark){ var sel = window.getSelection(); if(sel && !sel.isCollapsed) return; showPop(mark); return; }
    if(!npop.contains(e.target)) hidePop();
  });

  // 드래그 선택 → 도구막대. 막대는 선택이 풀리거나, 버튼을 누르거나, 스크롤·Esc 로 사라진다.
  document.addEventListener("mouseup", function(e){
    if(hlbar.contains(e.target) || notebox.contains(e.target) || npop.contains(e.target)) return;
    setTimeout(function(){
      var sel = window.getSelection();
      if(!sel || sel.isCollapsed || !sel.rangeCount){ hideBar(); return; }
      var range = sel.getRangeAt(0), root = null;
      for(var k in containers){ if(containers[k].contains(range.commonAncestorContainer)) root = containers[k]; }
      var text = range.toString().trim();
      if(!root || text.length < 2 || text.length > 2000){ hideBar(); return; }
      var pre = document.createRange(); pre.selectNodeContents(root); pre.setEnd(range.startContainer, range.startOffset);
      var raw = range.toString(), start = pre.toString().length + (raw.length - raw.replace(/^\s+/, "").length);
      var full = root.textContent, end = start + text.length;
      pendingSel = {target: root.dataset.target, text: text,
                    prefix: full.slice(Math.max(0, start - 40), start), suffix: full.slice(end, end + 40)};
      hidePop();
      var rect = range.getBoundingClientRect();
      hlbar.hidden = false;
      hlbar.style.left = Math.max(8, rect.left + rect.width / 2 - hlbar.offsetWidth / 2 + window.scrollX) + "px";
      hlbar.style.top = (rect.top + window.scrollY - 44) + "px";
    }, 0);
  });
  // 도구막대·메모 입력상자·말풍선은 바깥을 누르면 모두 닫힌다
  document.addEventListener("mousedown", function(e){
    if(hlbar.contains(e.target) || notebox.contains(e.target) || npop.contains(e.target)) return;
    hideBar();
    if(!notebox.hidden){ notebox.hidden = true; pendingSel = null; }
    if(!e.target.closest("mark.hl")) hidePop();
  });
  document.addEventListener("scroll", function(){ hideBar(); }, {passive:true});
  document.addEventListener("keydown", function(e){ if(e.key === "Escape"){ hideBar(); notebox.hidden = true; hidePop(); } });

  function save(kind, noteText, color){
    if(!pendingSel) return;
    var s = pendingSel; pendingSel = null;
    hideBar(); notebox.hidden = true;
    post({action:"add", report_id: REPORT_ID, target: s.target, kind: kind, text: s.text, prefix: s.prefix, suffix: s.suffix,
          note: noteText || "", color: kind === "note" ? "blue" : (color || "yellow")})
      .then(function(j){
        var n = j.note;
        var note = {id:+n.id, target:n.target, kind:n.kind, text:n.anchor_text, prefix:n.prefix, suffix:n.suffix, note:n.note,
                    color:n.color, user_name:n.user_name, user_email:n.user_email, created_at: new Date().toLocaleString("ko-KR", {hour12:false})};
        byId[note.id] = note;
        window.getSelection().removeAllRanges();
        apply(note);
      })
      .catch(function(err){ alert("저장하지 못했습니다: " + err.message); });
  }
  hlbar.addEventListener("click", function(e){
    var b = e.target.closest("button"); if(!b || !pendingSel) return;
    if(b.dataset.act === "highlight"){ save("highlight", "", b.dataset.color); return; }
    document.getElementById("noteboxQuote").textContent = "“" + (pendingSel.text.length > 160 ? pendingSel.text.slice(0, 159) + "…" : pendingSel.text) + "”";
    document.getElementById("noteboxText").value = "";
    notebox.style.left = hlbar.style.left; notebox.style.top = (parseInt(hlbar.style.top, 10) + 40) + "px";
    notebox.hidden = false; hideBar();
    document.getElementById("noteboxText").focus();
  });
  document.getElementById("noteboxCancel").addEventListener("click", function(){ notebox.hidden = true; pendingSel = null; window.getSelection().removeAllRanges(); });
  document.getElementById("noteboxSave").addEventListener("click", function(){
    var t = document.getElementById("noteboxText").value.trim();
    if(!t){ document.getElementById("noteboxText").focus(); return; }
    save("note", t);
  });
})();
</script>
</body>
</html>
