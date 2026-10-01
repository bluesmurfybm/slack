<?php
/** ProgressRepo·대시보드 집계 통합 테스트 — 진척률, 지연, N+1 없음, 상태 동기화. */
declare(strict_types=1);

define('ROOT', dirname(__DIR__, 2));
$TESTDB = getenv('BA_TEST_DB') ?: 'blueassign_test';
require ROOT . '/studio/inc/bootstrap.php';
foreach (['ProjectRepo', 'TaskRepo', 'MemberRepo', 'AllocationRepo', 'ProgressRepo'] as $r) {
    require ROOT . "/studio/inc/repo/$r.php";
}

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname={$TESTDB};charset=utf8mb4", 'root', '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "시험 DB '{$TESTDB}' 에 붙지 못했습니다. repo_test.php 의 안내를 보세요.\n");
    exit(2);
}
$pdo->exec("SET time_zone = '+09:00'");
foreach (['ba_notification', 'ba_progress_comment', 'ba_progress', 'ba_workload',
          'ba_allocation_item', 'ba_allocation', 'ba_task_domain', 'ba_task',
          'ba_member_category', 'ba_member_metric', 'ba_eval_run',
          'ba_project_source', 'ba_project', 'ba_member'] as $t) {
    $pdo->exec("DELETE FROM $t");
}

$projects = new ProjectRepo($pdo);
$tasks    = new TaskRepo($pdo);
$members  = new MemberRepo($pdo);
$allocs   = new AllocationRepo($pdo);
$progress = new ProgressRepo($pdo);
$actor    = ['id' => 'pm@x.kr', 'name' => 'PM'];

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  OK   $what\n"; }
    else       { $fail++; echo "  FAIL $what" . ($extra ? " — $extra" : '') . "\n"; }
}
function throws(string $what, callable $fn, string $cls = InvalidArgumentException::class): void {
    global $pass, $fail;
    try { $fn(); $fail++; echo "  FAIL $what — 예외가 안 났다\n"; }
    catch (Throwable $e) {
        if ($e instanceof $cls) { $pass++; echo "  OK   $what → " . $e->getMessage() . "\n"; }
        else { $fail++; echo "  FAIL $what — 다른 예외: " . get_class($e) . ' ' . $e->getMessage() . "\n"; }
    }
}
/** 세션이 보낸 문장 수. N+1 이 없는지 재는 데 쓴다. */
function questions(PDO $pdo): int {
    $r = $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC);
    return (int)($r['Value'] ?? 0);
}
function countQueries(PDO $pdo, callable $fn): int {
    $a = questions($pdo);    // 이 호출 자체가 1문장
    $fn();
    return questions($pdo) - $a - 1;
}

// =====================================================================
// 밑준비 — 사람 3명, 태스크 6건, 확정 배정안
// =====================================================================
$mk = $pdo->prepare(
    'INSERT INTO ba_member (user_id, emp_name, role_label, career_months, is_assignable)
     VALUES (?,?,?,?,1)'
);
$MEM = [];
foreach ([['a@x.kr', '가개발'], ['b@x.kr', '나개발'], ['c@x.kr', '다개발']] as $m) {
    $mk->execute([$m[0], $m[1], '설계·개발', 24]);
    $MEM[$m[1]] = (int)$pdo->lastInsertId();
}

$pid = $projects->create([
    'name' => '진행 시험', 'status' => 'running',
    'dev_start' => '2026-01-05', 'dev_end' => '2026-06-30', 'deploy_date' => '2026-07-15',
], $actor);

$tasks->saveTree($pid, [
    ['title' => '가군', 'children' => [
        // 공수 있음 / 기한 지남
        ['title' => '지난 일 A', 'est_md' => 10, 'plan_start' => '2026-01-05', 'plan_end' => '2026-02-01'],
        ['title' => '지난 일 B', 'est_md' => 2,  'plan_start' => '2026-01-05', 'plan_end' => '2026-02-01'],
        // 공수 없음
        ['title' => '공수 없는 일', 'plan_start' => '2026-03-01', 'plan_end' => '2026-12-31'],
    ]],
    ['title' => '나군', 'children' => [
        ['title' => '앞으로 일 A', 'est_md' => 4, 'plan_start' => '2026-05-01', 'plan_end' => '2026-12-31'],
        ['title' => '앞으로 일 B', 'est_md' => 4],   // 기간 없음
    ]],
], $actor);

$leaf = [];
foreach ($tasks->allByProject($pid) as $t) {
    if ((int)$t['depth'] > 1) { $leaf[$t['title']] = (int)$t['id']; }
}
$tasks->confirm(array_values($leaf), true, $actor);

$items = [];
$names = array_keys($MEM);
foreach (array_values($leaf) as $i => $tid) {
    $items[] = ['task_id' => $tid, 'member_id' => $MEM[$names[$i % 3]],
                'role' => 'owner', 'alloc_ratio' => 1.0, 'fit_score' => 50,
                'reason_json' => ['lines' => ['시험']]];
}
$aid = $allocs->createVersion($pid, [], $actor);
$allocs->saveItems($aid, $items);
$allocs->confirm($aid, $actor);

// =====================================================================
echo "\n[1] 진행상황 등록 — 태스크 상태를 함께 바꾼다\n";
$t1 = $leaf['지난 일 A'];
$p1 = $progress->create($t1, $MEM['가개발'], [
    'status' => 'doing', 'progress_pct' => 30, 'content' => '절반쯤',
]);
ok('기록이 생긴다', $p1 > 0);
$after = $tasks->find($t1);
ok('태스크 상태가 따라간다', $after['status'] === 'doing', $after['status']);
ok('진행률도 따라간다', (int)$after['progress_pct'] === 30, (string)$after['progress_pct']);

// 준 것만 바꾼다 — 상태만 올렸는데 진행률이 0 으로 덮이면 안 된다.
$progress->create($t1, $MEM['가개발'], ['status' => 'review']);
$after = $tasks->find($t1);
ok('상태만 올리면 진행률은 그대로', (int)$after['progress_pct'] === 30,
   (string)$after['progress_pct']);
ok('상태는 바뀐다', $after['status'] === 'review');

$progress->create($t1, $MEM['가개발'], ['status' => 'done']);
$after = $tasks->find($t1);
ok('done 이면 진행률을 100 으로 맞춘다', (int)$after['progress_pct'] === 100,
   (string)$after['progress_pct']);

throws('빈 등록은 거절', fn() => $progress->create($t1, $MEM['가개발'], []));
throws('모르는 상태는 거절', fn() => $progress->create($t1, $MEM['가개발'], ['status' => 'zzz']));
throws('진행률 101 거절', fn() => $progress->create($t1, $MEM['가개발'], ['progress_pct' => 101]));
throws('진행률 -1 거절', fn() => $progress->create($t1, $MEM['가개발'], ['progress_pct' => -1]));
throws('이상한 작업일 거절',
    fn() => $progress->create($t1, $MEM['가개발'], ['content' => 'x', 'worked_on' => '어제']));

// =====================================================================
echo "\n[2] 조회\n";
ok('태스크별 기록 3건', count($progress->byTask($t1)) === 3, (string)count($progress->byTask($t1)));
ok('최근 것이 앞', $progress->byTask($t1)[0]['status'] === 'done');
ok('사람별 기록', count($progress->byMember($MEM['가개발'])) === 3);
ok('프로젝트 피드', count($progress->feedByProject($pid)) === 3);
ok('기록에 사람 이름이 붙는다', $progress->byTask($t1)[0]['emp_name'] === '가개발');
ok('태스크 제목도 붙는다', $progress->byTask($t1)[0]['task_title'] === '지난 일 A');

$progress->create($leaf['지난 일 B'], $MEM['나개발'], ['progress_pct' => 50]);
$latest = $progress->latestPerTask(array_values($leaf));
ok('태스크별 최신 하나씩', count($latest) === 2, (string)count($latest));
ok('가장 최근 기록을 고른다', $latest[$t1]['status'] === 'done');
ok('기록 없는 태스크는 키가 없다', !isset($latest[$leaf['앞으로 일 B']]));
ok('빈 목록도 터지지 않는다', $progress->latestPerTask([]) === []);

// =====================================================================
echo "\n[3] N+1 이 없다 — 태스크가 늘어도 질의 수는 그대로\n";
$q1 = countQueries($pdo, fn() => $progress->latestPerTask(array_values($leaf)));
ok('latestPerTask 는 1회', $q1 === 1, (string)$q1);

// 태스크를 늘려도 같은지 본다.
// 트리 저장으로 늘리면 배정된 태스크를 빼려다 막힌다(그게 맞는 동작이다).
// 여기서 재려는 것은 조회 질의 수뿐이라 행을 직접 넣는다.
$ins = $pdo->prepare(
    'INSERT INTO ba_task (project_id, parent_id, depth, seq, wbs_no, title, confirmed, status)
     VALUES (?, NULL, 1, ?, ?, ?, 1, "todo")'
);
$bulk = array_values($leaf);
for ($i = 0; $i < 200; $i++) {
    $ins->execute([$pid, 900 + $i, '9.' . $i, "대량 $i"]);
    $bulk[] = (int)$pdo->lastInsertId();
}
$q2 = countQueries($pdo, fn() => $progress->latestPerTask($bulk));
ok('태스크 ' . count($bulk) . '건이어도 1회', $q2 === 1, (string)$q2);

$q2b = countQueries($pdo, fn() => $progress->overdueTasks($pid));
ok('지연 조회도 1회', $q2b === 1, (string)$q2b);
$q2c = countQueries($pdo, fn() => $progress->projectProgress($pid));
ok('진척률도 1회', $q2c === 1, (string)$q2c);
$q2d = countQueries($pdo, fn() => $progress->feedByProject($pid, 30));
ok('피드도 1회', $q2d === 1, (string)$q2d);

// 늘린 것을 치운다
$pdo->prepare("DELETE FROM ba_task WHERE project_id = ? AND title LIKE '대량 %'")->execute([$pid]);

$q3 = countQueries($pdo, fn() => $progress->commentsFor([1, 2, 3, 4, 5]));
ok('댓글도 한 번에', $q3 === 1, (string)$q3);

$q4 = countQueries($pdo, fn() => $progress->projectProgressMany([$pid]));
ok('여러 프로젝트 진척률도 1회', $q4 === 1, (string)$q4);

// =====================================================================
echo "\n[4] 진척률 — 공수로 가중, 빈 공수는 중앙값\n";
// 지난 일 A: 10 M/D 100% / 지난 일 B: 2 M/D 50% / 나머지 3건 0%
// 공수 없는 일 = 중앙값(2,4,4,10 → 4)
$pr = $progress->projectProgress($pid);
ok('말단 5건을 센다', $pr['counted'] === 5, (string)$pr['counted']);
ok('공수 없는 1건을 알려 준다', $pr['no_est'] === 1, (string)$pr['no_est']);
ok('중앙값 4', abs($pr['median'] - 4.0) < 0.01, (string)$pr['median']);
// (10*100 + 2*50 + 4*0 + 4*0 + 4*0) / (10+2+4+4+4) = 1100/24 = 45.8
ok('가중 진척률 45.8%', abs($pr['pct'] - 45.8) < 0.15, (string)$pr['pct']);
ok('총 공수 24', abs($pr['total_md'] - 24.0) < 0.01, (string)$pr['total_md']);

$many = $progress->projectProgressMany([$pid]);
ok('한 번에 내도 같은 값', abs($many[$pid]['pct'] - $pr['pct']) < 0.01);

ok('상위 태스크는 세지 않는다(이중 계산 방지)', $pr['counted'] === 5);

// =====================================================================
echo "\n[5] 지연 판정\n";
$late = $progress->overdueTasks($pid, '2026-03-01');
$lateTitles = array_column($late, 'title');
ok('기한 지난 것만', in_array('지난 일 B', $lateTitles, true), json_encode($lateTitles, JSON_UNESCAPED_UNICODE));
ok('완료는 지연이 아니다', !in_array('지난 일 A', $lateTitles, true));
ok('아직 안 온 기한은 지연이 아니다', !in_array('앞으로 일 A', $lateTitles, true));
ok('기한 없는 것은 지연이 아니다', !in_array('앞으로 일 B', $lateTitles, true));
ok('며칠 늦었는지 알려 준다', ($late[0]['overdue_days'] ?? 0) === 28,
   (string)($late[0]['overdue_days'] ?? '?'));

$pdo->prepare("UPDATE ba_task SET status='prod_deployed' WHERE id=?")
    ->execute([$leaf['지난 일 B']]);
// 이 시점의 지연은 '지난 일 B' 하나뿐이다. 면제 상태로 바꾸면 0 이 된다.
ok('운영 배포는 지연으로 세지 않는다',
   count($progress->overdueTasks($pid, '2026-03-01')) === 0,
   (string)count($progress->overdueTasks($pid, '2026-03-01')));
$pdo->prepare("UPDATE ba_task SET status='hold' WHERE id=?")->execute([$leaf['지난 일 B']]);
ok('보류도 지연이 아니다', count($progress->overdueTasks($pid, '2026-03-01')) === 0);
$pdo->prepare("UPDATE ba_task SET status='doing' WHERE id=?")->execute([$leaf['지난 일 B']]);

// =====================================================================
echo "\n[6] 댓글\n";
$rows = $progress->byTask($t1);
$pgId = $rows[0]['id'];
$c1 = $progress->addComment($pgId, ['id' => 'pm@x.kr', 'name' => 'PM'], '확인');
ok('댓글이 생긴다', $c1 > 0);
ok('작성자 이름 스냅샷', $progress->comments($pgId)[0]['user_name'] === 'PM');
throws('빈 댓글 거절', fn() => $progress->addComment($pgId, ['id' => 'x', 'name' => 'y'], '   '));

$progress->addComment($pgId, ['id' => 'a@x.kr', 'name' => '가개발'], '네');
ok('여러 건이 순서대로', count($progress->comments($pgId)) === 2);

$progress->deleteComment($c1);
ok('댓글 삭제', count($progress->comments($pgId)) === 1);

// =====================================================================
echo "\n[7] 기록 삭제 — 태스크 상태는 되돌리지 않는다\n";
$before = $tasks->find($t1);
$progress->addComment($pgId, ['id' => 'x@x.kr', 'name' => 'X'], '지워질 댓글');
$progress->delete($pgId);
ok('기록이 지워진다', count($progress->byTask($t1)) === 2, (string)count($progress->byTask($t1)));
ok('댓글도 함께 지워진다(FK CASCADE)',
   (int)$pdo->query("SELECT COUNT(*) FROM ba_progress_comment WHERE progress_id=$pgId")
            ->fetchColumn() === 0);
$after = $tasks->find($t1);
ok('태스크 상태는 그대로', $after['status'] === $before['status'],
   $before['status'] . ' → ' . $after['status']);

// =====================================================================
echo "\n" . str_repeat('=', 29) . "\n통과 $pass / 실패 $fail\n\n";
exit($fail > 0 ? 1 : 0);
