<?php
/** 대시보드 질의 수를 실제로 잰다. 태스크가 늘어도 늘지 않아야 한다. */

declare(strict_types=1);

/* ┌──────────────────────────────────────────────────────────────────┐
   │ 재는 방법                                                         │
   │                                                                  │
   │ MySQL 세션 상태값 `Questions` 는 그 연결이 보낸 문장 수를 센다.    │
   │ 측정 구간 앞뒤로 읽어 차이를 보면 실제 질의 수가 나온다.          │
   │ 읽는 행위 자체도 한 문장이라, 먼저 빈 구간을 재서 그 값을 뺀다.   │
   │                                                                  │
   │ PHP 쪽에서 세는 방법(PDO 를 감싸기)도 있지만, 그러면 **리포지토리 │
   │ 가 실제로 DB 에 보낸 것** 이 아니라 우리가 부른 횟수를 세게 된다. │
   │ 준비(prepare)와 실행이 나뉘는 경우를 놓친다.                      │
   │                                                                  │
   │   php studio/dev/query_count.php                                  │
   │   php studio/dev/query_count.php 300     (태스크 300건으로 재기)   │
   └──────────────────────────────────────────────────────────────────┘ */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('명령줄에서만 실행할 수 있습니다.');
}

define('ROOT', dirname(__DIR__, 2));
$TESTDB = getenv('BS_TEST_DB') ?: 'blueassign_test';
require ROOT . '/studio/inc/bootstrap.php';
foreach (['ProjectRepo', 'TaskRepo', 'MemberRepo', 'AllocationRepo', 'ProgressRepo'] as $r) {
    require ROOT . "/studio/inc/repo/$r.php";
}
require ROOT . '/studio/inc/service/AvailabilityCalculator.php';
require ROOT . '/studio/inc/service/RndLoadService.php';
require ROOT . '/studio/inc/service/AllocationEngine.php';

$N = max(10, (int)($argv[1] ?? 200));

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

// ---------------------------------------------------------------------
// 재기 도구
// ---------------------------------------------------------------------
function questions(PDO $pdo): int
{
    $r = $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC);
    return (int)($r['Value'] ?? 0);
}

/** 빈 구간을 재서 측정 자체의 비용을 알아 둔다. */
function overhead(PDO $pdo): int
{
    $a = questions($pdo);
    $b = questions($pdo);
    return $b - $a;   // 보통 1 (SHOW 문 자신)
}

function measure(PDO $pdo, int $oh, string $label, callable $fn): int
{
    $a = questions($pdo);
    $fn();
    $b = questions($pdo);
    $n = $b - $a - $oh;
    printf("  %-44s %3d 회\n", $label, $n);
    return $n;
}

// ---------------------------------------------------------------------
// 밑준비 — 태스크 N 건짜리 프로젝트
// ---------------------------------------------------------------------
foreach (['bs_notification', 'bs_progress_comment', 'bs_progress', 'bs_workload',
          'bs_allocation_item', 'bs_allocation', 'bs_task_domain', 'bs_task',
          'bs_member_category', 'bs_member_metric', 'bs_eval_run',
          'bs_project_source', 'bs_project', 'bs_member'] as $t) {
    $pdo->exec("DELETE FROM $t");
}

$projects = new ProjectRepo($pdo);
$tasks    = new TaskRepo($pdo);
$members  = new MemberRepo($pdo);
$allocs   = new AllocationRepo($pdo);
$progress = new ProgressRepo($pdo);
$avail    = new AvailabilityCalculator($pdo);
$rndsvc   = new RndLoadService($pdo);
$actor    = ['id' => 'pm@x.kr', 'name' => 'PM'];

$mk = $pdo->prepare(
    'INSERT INTO bs_member (user_id, emp_name, role_label, career_months, base_capacity,
                            is_assignable, is_evaluable)
     VALUES (?,?,?,?,1.00,1,1)'
);
$MEM = [];
for ($i = 1; $i <= 10; $i++) {
    $mk->execute(["m$i@x.kr", "구성원$i", '설계·개발', 24]);
    $MEM[] = (int)$pdo->lastInsertId();
}

$pid = $projects->create([
    'name' => '질의 수 측정', 'status' => 'running',
    'dev_start' => '2026-03-02', 'dev_end' => '2026-08-31', 'deploy_date' => '2026-09-15',
], $actor);

// 대분류 5개 × 소분류로 N 건
$tree = [];
$per  = (int)ceil($N / 5);
for ($g = 1; $g <= 5; $g++) {
    $kids = [];
    for ($k = 1; $k <= $per; $k++) {
        $kids[] = [
            'title' => "태스크 $g-$k", 'est_md' => 1 + ($k % 5),
            'difficulty' => 1 + ($k % 5),
            'plan_start' => '2026-03-02', 'plan_end' => '2026-06-30',
        ];
    }
    $tree[] = ['title' => "대분류 $g", 'children' => $kids];
}
$tasks->saveTree($pid, $tree, $actor);

$leaf = [];
foreach ($tasks->allByProject($pid) as $t) {
    if ((int)$t['depth'] > 1) { $leaf[] = (int)$t['id']; }
}
$tasks->confirm($leaf, true, $actor);

// 확정 배정안 — 사람에게 골고루
$items = [];
foreach ($leaf as $i => $tid) {
    $items[] = [
        'task_id' => $tid, 'member_id' => $MEM[$i % count($MEM)],
        'role' => 'owner', 'alloc_ratio' => 1.0, 'fit_score' => 50,
        'reason_json' => ['lines' => ['측정용']],
    ];
}
$aid = $allocs->createVersion($pid, [], $actor);
$allocs->saveItems($aid, $items);
$allocs->confirm($aid, $actor);

// 진행 기록 — 태스크당 2건
$mkP = $pdo->prepare(
    'INSERT INTO bs_progress (task_id, member_id, status, progress_pct, content, worked_on)
     VALUES (?,?,?,?,?,?)'
);
foreach ($leaf as $i => $tid) {
    $m = $MEM[$i % count($MEM)];
    $mkP->execute([$tid, $m, 'doing', 30, '진행 중', '2026-04-01']);
    $mkP->execute([$tid, $m, 'doing', 60, '더 진행', '2026-04-10']);
}

echo "\n대시보드 질의 수\n" . str_repeat('=', 62) . "\n";
echo "밑준비: 태스크 " . count($leaf) . "건(말단), 구성원 " . count($MEM)
   . "명, 진행 기록 " . (count($leaf) * 2) . "건\n\n";

$oh = overhead($pdo);
echo "측정 오차 보정값: {$oh}\n\n";

// ---------------------------------------------------------------------
// 재기 — api/dashboard.php 가 하는 일을 그대로 부른다
// ---------------------------------------------------------------------
echo "[act=projects] 프로젝트 카드\n";
$total = 0;
$total += measure($pdo, $oh, 'ProjectRepo::search (목록 + 건수)', function () use ($projects) {
    $projects->search(['status' => BS_DASH_PROJECT_STATUS, 'size' => 50, 'sort' => 'deploy_date']);
});
$ids = [$pid];
$total += measure($pdo, $oh, 'ProgressRepo::projectProgressMany', function () use ($progress, $ids) {
    $progress->projectProgressMany($ids);
});
$total += measure($pdo, $oh, '지연 건수 / 확정본 / 인원 수 (3회)', function () use ($pdo, $ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sph = implode(',', array_fill(0, count(BS_TASK_OVERDUE_EXEMPT), '?'));
    $st = $pdo->prepare("SELECT t.project_id, COUNT(*) n FROM bs_task t
                          WHERE t.project_id IN ($ph) AND t.confirmed=1
                            AND t.plan_end IS NOT NULL AND t.plan_end < ?
                            AND t.status NOT IN ($sph)
                            AND NOT EXISTS (SELECT 1 FROM bs_task c WHERE c.parent_id=t.id)
                          GROUP BY t.project_id");
    $st->execute(array_merge($ids, [date('Y-m-d')], BS_TASK_OVERDUE_EXEMPT));
    $st->fetchAll();
    $st = $pdo->prepare("SELECT project_id,id,version,confirmed_at FROM bs_allocation
                          WHERE project_id IN ($ph) AND status='confirmed'");
    $st->execute($ids); $st->fetchAll();
    $st = $pdo->prepare("SELECT a.project_id, COUNT(DISTINCT i.member_id) n FROM bs_allocation a
                           JOIN bs_allocation_item i ON i.allocation_id=a.id
                          WHERE a.project_id IN ($ph) AND a.status='confirmed'
                          GROUP BY a.project_id");
    $st->execute($ids); $st->fetchAll();
});
echo "  " . str_repeat('-', 44) . " ---\n";
printf("  %-44s %3d 회\n\n", '합계', $total);
$projectsTotal = $total;

echo "[act=board] 한 프로젝트의 칸반·간트·담당자·피드\n";
$total = 0;
$total += measure($pdo, $oh, 'AllocationRepo::confirmed', function () use ($allocs, $pid) {
    $allocs->confirmed($pid);
});
$total += measure($pdo, $oh, '말단 태스크 목록', function () use ($pdo, $pid) {
    $st = $pdo->prepare('SELECT t.id FROM bs_task t WHERE t.project_id=? AND t.confirmed=1
                           AND NOT EXISTS (SELECT 1 FROM bs_task c WHERE c.parent_id=t.id)');
    $st->execute([$pid]); $st->fetchAll();
});
$total += measure($pdo, $oh, 'AllocationRepo::items', function () use ($allocs, $aid) {
    $allocs->items($aid);
});
$total += measure($pdo, $oh, 'ProgressRepo::latestPerTask (태스크 전체)',
    function () use ($progress, $leaf) { $progress->latestPerTask($leaf); });
$total += measure($pdo, $oh, 'ProgressRepo::feedByProject', function () use ($progress, $pid) {
    $progress->feedByProject($pid, 30);
});
$total += measure($pdo, $oh, 'ProgressRepo::overdueTasks', function () use ($progress, $pid) {
    $progress->overdueTasks($pid);
});
$total += measure($pdo, $oh, 'ProgressRepo::projectProgress', function () use ($progress, $pid) {
    $progress->projectProgress($pid);
});
$total += measure($pdo, $oh, 'MemberRepo::findByUserId (내 것 표시)',
    function () use ($members) { $members->findByUserId('m1@x.kr'); });

// P10-2 에서 담당자 카드에 가용도가 붙었다. **모사에도 넣어야 숫자가 참이 된다** —
// 안 넣으면 이 도구가 실제보다 적은 수를 말한다.
// forMembers() 는 구성원 수와 무관하게 묶어 묻는다.
$total += measure($pdo, $oh, 'AvailabilityCalculator::forMembers (담당자 가용도)',
    function () use ($avail, $MEM) { $avail->forMembers($MEM, '2026-01-01', '2026-12-31'); });

echo "  " . str_repeat('-', 44) . " ---\n";
printf("  %-44s %3d 회\n\n", '합계', $total);
$boardTotal = $total;

echo "[act=mine] 내가 맡은 태스크\n";
$total = 0;
$total += measure($pdo, $oh, 'MemberRepo::findByUserId', function () use ($members) {
    $members->findByUserId('m1@x.kr');
});
$myTasks = [];
$total += measure($pdo, $oh, '내 배정 태스크 목록', function () use ($pdo, $MEM, &$myTasks) {
    $st = $pdo->prepare("SELECT t.id AS task_id FROM bs_allocation_item i
                           JOIN bs_allocation a ON a.id=i.allocation_id AND a.status='confirmed'
                           JOIN bs_task t ON t.id=i.task_id
                          WHERE i.member_id=?");
    $st->execute([$MEM[0]]);
    $myTasks = array_column($st->fetchAll(), 'task_id');
});
$total += measure($pdo, $oh, 'ProgressRepo::latestPerTask', function () use ($progress, &$myTasks) {
    $progress->latestPerTask($myTasks);
});

// 통합 뷰 — 내가 참여 중인 R&D 과제 (P11). **한 문장**이다.
//
// api/dashboard.php 의 bs_dash_my_rnd() 와 같은 쿼리다. 그 파일은 include 하면
// bs_route() 가 돌아 버려서 가져다 쓸 수 없다. 같은 모양으로 적어 두되,
// 바뀌면 이쪽도 함께 고쳐야 한다.
$total += measure($pdo, $oh, '내 R&D 과제 목록 (P11)', function () use ($pdo, $MEM) {
    $st = $pdo->prepare(
        "SELECT p.id, p.code, p.name, p.status, rm.role, rm.load_ratio,
                (SELECT COUNT(*) FROM bs_rnd_output o WHERE o.project_id = p.id) AS output_count,
                (SELECT MAX(l.created_at) FROM bs_rnd_log l WHERE l.project_id = p.id) AS last_log_at
           FROM bs_rnd_member rm
           JOIN bs_project p ON p.id = rm.project_id
          WHERE rm.member_id = ? AND rm.status = 'approved'
            AND p.project_type = 'rnd' AND p.deleted_at IS NULL
            AND p.status IN ('approved','running')");
    $st->execute([$MEM[0]]);
    $st->fetchAll();
});
echo "  " . str_repeat('-', 44) . " ---\n";
printf("  %-44s %3d 회\n\n", '합계', $total);
$mineTotal = $total;

// =====================================================================
echo "[act=overview] 조직 지표 + 내 점유 구성 (P11)\n";
$total = 0;

// 조직 지표 셋을 **서브셀렉트 하나로 묶어** 한 번에 묻는다.
// 세 번 나눠 물으면 모두가 하루에 여러 번 여는 화면에 질의가 셋 는다.
$total += measure($pdo, $oh, '조직 지표 3개 (한 문장)', function () use ($pdo) {
    $st = $pdo->prepare(
        "SELECT
           (SELECT COUNT(*) FROM bs_project WHERE project_type='project' AND deleted_at IS NULL
             AND status IN ('allocating','confirmed','running')) AS a,
           (SELECT COUNT(*) FROM bs_project WHERE project_type='rnd' AND deleted_at IS NULL
             AND status IN ('approved','running')) AS b,
           (SELECT COUNT(*) FROM bs_rnd_output o JOIN bs_project p ON p.id=o.project_id
             WHERE p.deleted_at IS NULL AND o.created_at >= ?) AS c");
    $st->execute(['2026-01-01 00:00:00']);
    $st->fetch();
});
$total += measure($pdo, $oh, 'MemberRepo::findByUserId', function () use ($members) {
    $members->findByUserId('m1@x.kr');
});
$total += measure($pdo, $oh, 'AvailabilityCalculator::forMembers (내 점유)',
    function () use ($avail, $MEM) { $avail->forMembers([$MEM[0]], '2026-01-01', '2026-12-31'); });
$total += measure($pdo, $oh, 'RndLoadService::staleProjectsOf (정체 경고)',
    function () use ($rndsvc, $MEM) { $rndsvc->staleProjectsOf($MEM[0]); });
echo "  " . str_repeat('-', 44) . " ---\n";
printf("  %-44s %3d 회\n\n", '합계', $total);
$overviewTotal = $total;

// ---------------------------------------------------------------------
// 구성원 목록 (member_list.php)
//
// 이 화면은 API 가 없다 — 서버가 직접 그린다. 그래서 act 가 아니라 화면이
// 부르는 메서드를 그대로 모사한다.
//
// **인원수에 비례하면 안 된다.** 사람마다 forMember()·skills()·metric() 을
// 부르면 13명이면 40회, 50명이면 150회가 된다. 아래 세 메서드는 전부
// 일괄 조회다. 그 성질을 사람 수를 바꿔 가며 확인한다.
// ---------------------------------------------------------------------
$memberListQueries = function () use ($pdo, $members, $avail) {
    $oh2   = overhead($pdo);
    $total = 0;
    $rows  = [];
    $total += measure($pdo, $oh2, 'MemberRepo::search (목록)',
        function () use ($members, &$rows) { $rows = $members->search(['is_assignable' => 1]); });
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    $total += measure($pdo, $oh2, 'AvailabilityCalculator::forMembers (가용도)',
        function () use ($avail, $ids) { $avail->forMembers($ids, '2026-01-01', '2026-12-31'); });
    $total += measure($pdo, $oh2, 'MemberRepo::primaryDomains (주요 분야)',
        function () use ($members, $ids) { $members->primaryDomains($ids); });
    $total += measure($pdo, $oh2, 'MemberRepo::sampleStatusFor (표본 여부)',
        function () use ($members, $ids) { $members->sampleStatusFor($ids); });
    $total += measure($pdo, $oh2, 'MemberRepo::filterOptions (거르개 값)',
        function () use ($members) { $members->filterOptions(); });
    $total += measure($pdo, $oh2, 'MemberRepo::findByUserId (내 줄 표시)',
        function () use ($members) { $members->findByUserId('m1@x.kr'); });
    return [$total, count($rows)];
};

echo "── 구성원 목록 (member_list.php) ──────────────────────\n";
[$memberTotal, $memberCount] = $memberListQueries();
echo "  " . str_repeat('-', 44) . " ---\n";
printf("  %-44s %3d 회  (구성원 %d명)\n\n", '합계', $memberTotal, $memberCount);

// 사람을 세 배로 늘려 같은 수가 나오는지 본다.
$mk2 = $pdo->prepare(
    'INSERT INTO bs_member (user_id, emp_name, role_label, career_months, base_capacity,
                            is_assignable, is_evaluable)
     VALUES (?,?,?,?,1.00,1,1)'
);
for ($i = 11; $i <= 40; $i++) {
    $mk2->execute(["m$i@x.kr", "구성원$i", '설계·개발', 24]);
}
echo "── 같은 화면, 구성원을 늘려서 ─────────────────────────\n";
[$memberTotal2, $memberCount2] = $memberListQueries();
echo "  " . str_repeat('-', 44) . " ---\n";
printf("  %-44s %3d 회  (구성원 %d명)\n", '합계', $memberTotal2, $memberCount2);
printf("  %-44s %s\n\n", '인원수와 무관한가',
    $memberTotal === $memberTotal2 ? '그렇다' : '!! 아니다 — N+1 이 생겼다');

// ---------------------------------------------------------------------
// 태스크 수를 바꿔도 같은지
// ---------------------------------------------------------------------
echo str_repeat('=', 62) . "\n";
printf("태스크 %d건 기준 — projects %d회 · board %d회 · mine %d회\n",
    count($leaf), $projectsTotal, $boardTotal, $mineTotal);
printf("                 overview %d회 (P11)
", $overviewTotal);
printf("구성원 수와 무관 — member_list %d회 (10명/40명 동일)
", $memberTotal);
echo "\n태스크 수와 무관해야 맞습니다. 확인하려면 건수를 바꿔 다시 재 보세요:\n";
echo "  php studio/dev/query_count.php 50\n";
echo "  php studio/dev/query_count.php 500\n\n";
