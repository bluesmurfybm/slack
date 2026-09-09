<?php
/**
 * MoodleUp? 북마크 — 요약에서 북마크한 단락을 주차별로 모아 보고 검색한다.
 *  - 데이터는 moodle_note(kind=bookmark). 지우기는 notes.php(본인 것만).
 *  - 검색은 ?q= 로 서버에서 LIKE 한 번. 결과 안의 일치 부분은 강조한다.
 *  - 단락을 누르면 해당 주차 요약의 그 위치(#note-ID)로 간다.
 */
date_default_timezone_set('Asia/Seoul');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/db.php';

$me = current_portal_user();
if (!$me) {
    header('Location: ../index.php');
    exit;
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$q = trim((string)($_GET['q'] ?? ''));
$rows = moodle_bookmarks($q);
$total = moodle_bookmark_count();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
/** 검색어 일치 부분을 <em> 으로 감싼다(이스케이프 후) */
function hl($text, $q) {
    $e = h($text);
    if ($q === '') return $e;
    return preg_replace('/' . preg_quote(h($q), '/') . '/iu', '<em>$0</em>', $e);
}

$byWeek = [];
foreach ($rows as $r) $byWeek[$r['week']][] = $r;
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>북마크 · MoodleUp?</title>
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
          <a href="index.php">🧭 MoodleUp?</a>
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
      <h1>🔖 북마크</h1>
      <p>주간 요약에서 북마크한 단락을 한곳에 모았습니다. 단락을 누르면 그 주차의 원래 자리로 이동합니다.</p>
    </div>
    <div class="hero-links">
      <a class="back" href="index.php">← MoodleUp?</a>
    </div>
  </div>

  <section class="card">
    <form class="bm-search" method="get" action="bookmarks.php">
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="북마크 검색 — 단락 내용, 주차(2026-W37), 헤드라인, 작성자" autofocus>
      <button class="btn" type="submit">검색</button>
      <?php if ($q !== ''): ?><a class="btn ghost" href="bookmarks.php">전체 보기</a><?php endif; ?>
    </form>
    <div class="tiny"><?= $q !== '' ? '검색 결과 ' . count($rows) . '건 / 전체 ' . $total . '건' : '전체 ' . $total . '건' ?></div>

    <?php if (!$rows): ?>
      <div class="empty">
        <h2><?= $q !== '' ? '일치하는 북마크가 없습니다' : '아직 북마크가 없습니다' ?></h2>
        <p>요약 글을 드래그하고 도구막대에서 <b>북마크</b>를 누르면 여기에 모입니다.</p>
      </div>
    <?php else: ?>
      <?php foreach ($byWeek as $week => $list): $first = $list[0]; ?>
        <div class="bm-week">
          <h3><a href="index.php?week=<?= h($week) ?>"><?= h($week) ?></a>
            <span class="tiny"><?= h(moodle_kst($first['period_start'], 'm.d')) ?> ~ <?= h(moodle_kst($first['period_end'], 'm.d')) ?><?= $first['headline'] ? ' · ' . hl($first['headline'], $q) : '' ?></span></h3>
          <?php foreach ($list as $n): ?>
            <div class="bm" data-id="<?= (int)$n['id'] ?>">
              <span class="ic">🔖</span>
              <a class="q" href="index.php?week=<?= h($week) ?>#note-<?= (int)$n['id'] ?>" title="이 주차 요약의 원래 자리로"><?= hl($n['anchor_text'], $q) ?></a>
              <?php if ($n['user_email'] === $me['email']): ?><button class="x" type="button" title="북마크 지우기" data-del="<?= (int)$n['id'] ?>">×</button><?php else: ?><span></span><?php endif; ?>
              <div class="m"><span><?= h($n['user_name'] ?: $n['user_email']) ?></span><span><?= h(moodle_kst($n['created_at'])) ?></span><a href="index.php?week=<?= h($week) ?>#note-<?= (int)$n['id'] ?>">요약에서 보기 →</a></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
</div>

<script>
function __hdrToggleMenu(e){ if(e) e.stopPropagation(); document.getElementById("hdrUserMenu").classList.toggle("open"); }
document.addEventListener("click", function(e){
  var um = document.getElementById("hdrUserMenu");
  if(um && !um.contains(e.target)) um.classList.remove("open");
  var d = e.target.closest("[data-del]");
  if(d){
    if(!confirm("이 북마크를 지울까요?")) return;
    fetch("notes.php", {method:"POST", credentials:"same-origin", headers:{"Content-Type":"application/json"},
          body: JSON.stringify({action:"delete", id:+d.dataset.del})})
      .then(function(r){ return r.json(); })
      .then(function(j){ if(!j.ok) throw new Error(j.error || "실패"); location.reload(); })
      .catch(function(err){ alert("지우지 못했습니다: " + err.message); });
  }
});
</script>
</body>
</html>
