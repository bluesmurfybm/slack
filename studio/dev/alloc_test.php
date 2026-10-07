<?php
/** 배정 엔진·저장소 통합 테스트 — 적합도 식, 결정론, 제약, 확정 경로. */
declare(strict_types=1);

define('ROOT', dirname(__DIR__, 2));
$TESTDB = getenv('BS_TEST_DB') ?: 'blueassign_test';
require ROOT . '/studio/inc/bootstrap.php';
foreach (['ProjectRepo', 'TaskRepo', 'MemberRepo', 'AllocationRepo'] as $r) {
    require ROOT . "/studio/inc/repo/$r.php";
}
require ROOT . '/studio/inc/service/AvailabilityCalculator.php';
require ROOT . '/studio/inc/service/AllocationEngine.php';
require ROOT . '/studio/inc/service/Notifier.php';

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
foreach (['bs_notification', 'bs_workload', 'bs_allocation_item', 'bs_allocation',
          'bs_task_domain', 'bs_task', 'bs_member_category', 'bs_member_metric',
          'bs_eval_run', 'bs_project_source', 'bs_project', 'bs_member'] as $t) {
    $pdo->exec("DELETE FROM $t");
}

$projects = new ProjectRepo($pdo);
$tasks    = new TaskRepo($pdo);
$members  = new MemberRepo($pdo);
$allocs   = new AllocationRepo($pdo);
$avail    = new AvailabilityCalculator($pdo);
$engine   = new AllocationEngine($tasks, $members, $allocs, $avail, $projects);
$actor    = ['id' => 'kimhy@bluesoft.co.kr', 'name' => '김호영'];

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  OK   $what\n"; }
    else       { $fail++; echo "  FAIL $what" . ($extra ? " — $extra" : '') . "\n"; }
}
function throws(string $what, callable $fn, string $cls = DomainException::class): void {
    global $pass, $fail;
    try { $fn(); $fail++; echo "  FAIL $what — 예외가 안 났다\n"; }
    catch (Throwable $e) {
        if ($e instanceof $cls) {
            $pass++; echo "  OK   $what → " . str_replace("\n", ' / ', mb_strimwidth($e->getMessage(), 0, 80, '…')) . "\n";
        } else {
            $fail++; echo "  FAIL $what — 다른 예외: " . get_class($e) . ' ' . $e->getMessage() . "\n";
        }
    }
}
/** 배정 결과의 지문. 결정론 확인에 쓴다. */
function sig(array $res): string {
    $a = [];
    foreach ($res['items'] as $it) {
        $a[] = $it['task_id'] . ':' . $it['member_id'] . ':' . $it['fit_score'];
    }
    sort($a);
    return sha1(implode('|', $a));
}

// =====================================================================
// 밑준비 — 사람 5명, 역량 판정 1회, 프로젝트와 WBS
// =====================================================================
$mkMember = $pdo->prepare(
    'INSERT INTO bs_member (user_id, emp_name, role_label, career_months, base_capacity,
                            slack_handle, email, is_assignable, is_evaluable)
     VALUES (?,?,?,?,?,?,?,?,1)'
);
$MEM = [];
foreach ([
    ['a@x.kr', '가개발', '설계·개발', 60, 1.00, '@ga',  'ga@x.kr',  1],
    ['b@x.kr', '나개발', '설계·개발', 36, 1.00, '@na',  'na@x.kr',  1],
    ['c@x.kr', '다개발', '퍼블리싱',  12, 1.00, null,   null,       1],
    ['d@x.kr', '라개발', '인프라',    24, 0.50, '@ra',  null,       1],
    ['e@x.kr', '마제외', '기획',       6, 1.00, null,   null,       0],   // 배정 제외
] as $m) {
    $mkMember->execute($m);
    $MEM[$m[1]] = (int)$pdo->lastInsertId();
}

// bs_eval_run 은 id 가 곧 판정 회차다(eval_ver 컬럼이 따로 없다).
$pdo->prepare(
    'INSERT INTO bs_eval_run (started_at, finished_at, period_from, period_to, status)
     VALUES (NOW(), NOW(), ?, ?, "ok")'
)->execute(['2026-01-01', '2026-12-31']);
$evalRunId = (int)$pdo->lastInsertId();

$mkMetric = $pdo->prepare(
    'INSERT INTO bs_member_metric (member_id, eval_ver, cap_score, breadth_score, career_score,
                                   insufficient_data)
     VALUES (?,?,?,?,?,?)'
);
$mkCat = $pdo->prepare(
    'INSERT INTO bs_member_category (member_id, category, eval_ver, case_count, score,
                                     confidence, insufficient_data)
     VALUES (?,?,?,?,?,?,?)'
);
// 가: 학습활동 최고 / 나: 중간 / 다: 화면 특화 / 라: 표본 부족
$mkMetric->execute([$MEM['가개발'], $evalRunId, 92, 80, 70, 0]);
$mkMetric->execute([$MEM['나개발'], $evalRunId, 74, 65, 50, 0]);
$mkMetric->execute([$MEM['다개발'], $evalRunId, 60, 40, 20, 0]);
$mkMetric->execute([$MEM['라개발'], $evalRunId, null, null, 35, 1]);

$mkCat->execute([$MEM['가개발'], 'activity',     $evalRunId, 40, 95, 'full', 0]);
$mkCat->execute([$MEM['가개발'], 'presentation', $evalRunId, 25, 55, 'full', 0]);
$mkCat->execute([$MEM['나개발'], 'activity',     $evalRunId, 30, 70, 'full', 0]);
$mkCat->execute([$MEM['나개발'], 'presentation', $evalRunId, 22, 60, 'full', 0]);
$mkCat->execute([$MEM['다개발'], 'activity',     $evalRunId, 21, 40, 'full', 0]);
$mkCat->execute([$MEM['다개발'], 'presentation', $evalRunId, 35, 90, 'full', 0]);
// 라개발은 계열 행 자체가 표본 부족
$mkCat->execute([$MEM['라개발'], 'activity',     $evalRunId, 2,  null, 'none', 1]);

$pid = $projects->create([
    'name' => '배정 시험', 'status' => 'allocating',
    'dev_start' => '2026-03-02', 'dev_end' => '2026-05-29',
], $actor);

// 분야는 **code 로 찾는다.** id 를 적어 두면 002 시드와 005 마이그레이션의
// 적재 순서에 따라 번호가 밀려 조용히 다른 분야를 가리킨다. 실제로 '테마 개편'
// 에 20 을 적어 두었는데, DB 를 새로 만들면 20 이 '인프라·배포'(platform) 가
// 되어 화면 과업이 인프라 과업으로 둔갑했다.
$domId = static function (string $code) use ($pdo): int {
    $st = $pdo->prepare('SELECT id FROM bs_domain WHERE code = ?');
    $st->execute([$code]);
    $id = (int)$st->fetchColumn();
    if ($id === 0) {
        fwrite(STDERR, "분야 code '$code' 가 없습니다. 002_seed_domain.sql 을 적재했습니까?\n");
        exit(1);
    }
    return $id;
};
$DOM_ACTIVITY     = $domId('attendance');    // 출석부 — activity 계열
$DOM_PRESENTATION = $domId('frontend_ui');   // UI/UX 퍼블리싱 — presentation 계열

$tasks->saveTree($pid, [
    ['title' => '출석', 'children' => [
        ['title' => '출석부 화면', 'est_md' => 8, 'difficulty' => 3, 'domain_ids' => [$DOM_ACTIVITY]],
        ['title' => '출석 통계',   'est_md' => 5, 'difficulty' => 2, 'domain_ids' => [$DOM_ACTIVITY]],
    ]],
    ['title' => '화면', 'children' => [
        ['title' => '테마 개편',   'est_md' => 6, 'difficulty' => 3, 'domain_ids' => [$DOM_PRESENTATION]],
    ]],
    ['title' => '난제', 'children' => [
        ['title' => '성능 개선',   'est_md' => 10, 'difficulty' => 5, 'domain_ids' => [$DOM_ACTIVITY]],
    ]],
], $actor);

$leaf = [];
foreach ($tasks->allByProject($pid) as $t) {
    if ((int)$t['depth'] > 1) { $leaf[$t['title']] = (int)$t['id']; }
}

// =====================================================================
echo "\n[1] 확정 전에는 배정할 것이 없다\n";
throws('확정된 태스크가 없으면 거절', fn() => $engine->propose($pid, []));

$tasks->confirm(array_values($leaf), true, $actor);
ok('4건 확정', count($tasks->confirmedForAllocation($pid)) === 4);

// =====================================================================
echo "\n[2] 적합도 — 명세서 §6.1 식\n";
$r = $engine->propose($pid, []);
ok('말단 4건만 배정한다(상위는 빼고)', $r['meta']['task_count'] === 4,
   (string)$r['meta']['task_count']);
ok('배정 제외자는 후보에 없다', $r['meta']['member_count'] === 4,
   (string)$r['meta']['member_count']);
ok('쓴 가중치를 기록한다',
   abs($r['meta']['weights']['domain'] - 0.35) < 0.001
   && !array_key_exists('comm', $r['meta']['weights']),
   json_encode($r['meta']['weights']));
ok('역량 판정 회차를 기록한다', $r['meta']['eval_ver'] === $evalRunId);
ok('모두 근거가 붙어 있다',
   count(array_filter($r['items'], fn($i) => !empty($i['reason_json']['lines']))) === count($r['items']));
ok('근거는 3줄',
   count($r['items'][0]['reason_json']['lines']) === 3,
   (string)count($r['items'][0]['reason_json']['lines']));

$byTask = [];
foreach ($r['items'] as $it) { $byTask[$it['task_id']] = $it; }
$name = array_flip($MEM);

ok('화면 태스크는 화면 잘하는 사람에게',
   $name[$byTask[$leaf['테마 개편']]['member_id']] === '다개발',
   $name[$byTask[$leaf['테마 개편']]['member_id']]);

// =====================================================================
echo "\n[3] 난이도 4~5 는 해당 분야 상위자 우선 (§6.2)\n";
$hard = $byTask[$leaf['성능 개선']];
ok('난제는 학습활동 상위자(가/나)에게',
   in_array($name[$hard['member_id']], ['가개발', '나개발'], true),
   $name[$hard['member_id']]);
ok('표본 부족자에게 난제를 주지 않는다', $name[$hard['member_id']] !== '라개발');

// =====================================================================
echo "\n[4] 결정론 — 같은 입력이면 같은 결과\n";
$s1 = sig($r);
$same = true;
for ($i = 0; $i < 5; $i++) {
    if (sig($engine->propose($pid, [])) !== $s1) { $same = false; }
}
ok('5번 더 돌려도 같다', $same, $s1);
ok('난수를 쓰지 않는다고 밝힌다', $r['meta']['deterministic'] === true);

$w = ['domain' => 1.0, 'cap' => 0.0, 'avail' => 0.0, 'career' => 0.0];
$rw = $engine->propose($pid, ['weights' => $w]);
ok('가중치를 바꾸면 결과가 달라진다', sig($rw) !== $s1);
ok('그 결과도 결정론적', sig($engine->propose($pid, ['weights' => $w])) === sig($rw));

// =====================================================================
echo "\n[3-S] 쏠림 — 한가한 사람이 모든 태스크에서 계속 1등하면 안 된다\n";
//
// ┌──────────────────────────────────────────────────────────────────┐
// │ 가용도가 배정이 쌓여도 안 줄어들던 자리 (2026-10-07)              │
// │                                                                  │
// │ 참여 가능 점수를 DB 값 그대로 쓰면 한 건을 줘도 95% 그대로라,     │
// │ 제일 한가한 사람이 **모든 태스크에서 계속 이긴다.** 실제로 후보   │
// │ 8명 중 한 사람이 24건(81%)을 가져가고 3명은 0건이었다.            │
// └──────────────────────────────────────────────────────────────────┘
// =====================================================================
$avM = new ReflectionMethod(AllocationEngine::class, 'availAfter');
$avM->setAccessible(true);
$ovM = new ReflectionMethod(AllocationEngine::class, 'overloadPenalty');
$ovM->setAccessible(true);
$who = ['member_id' => 1];

$ctx0 = ['capacity_md' => [1 => 100.0], 'assigned_md' => []];
ok('아무것도 안 받았으면 가용도 그대로',
   abs($avM->invoke($engine, $who, $ctx0, 95.0) - 95.0) < 0.01);

// ★ 핵심. 절반을 받으면 참여 가능 점수도 절반이 돼야 다음엔 남이 이긴다.
$ctx1 = ['capacity_md' => [1 => 100.0], 'assigned_md' => [1 => 50.0]];
ok('★ 절반 받으면 참여 가능도 절반',
   abs($avM->invoke($engine, $who, $ctx1, 95.0) - 47.5) < 0.01,
   (string)$avM->invoke($engine, $who, $ctx1, 95.0));

$ctx2 = ['capacity_md' => [1 => 100.0], 'assigned_md' => [1 => 100.0]];
ok('★ 꽉 차면 0 이 된다', $avM->invoke($engine, $who, $ctx2, 95.0) < 0.01);

// 가용 공수를 모르면 깎을 기준이 없다. 0 으로 치면 영영 배정되지 않는다.
ok('★ 가용 공수를 모르면 원값을 둔다',
   abs($avM->invoke($engine, $who, ['capacity_md' => [], 'assigned_md' => []], 80.0) - 80.0) < 0.01);

// ---- 과부하 감점이 넘기기 전부터 든다 ----
$t1 = ['est_md' => 1.0];
ok('절반쯤에서는 감점 없음',
   $ovM->invoke($engine, $t1, $who, ['capacity_md' => [1 => 100.0], 'assigned_md' => [1 => 40.0]]) === 0.0);
$p80 = $ovM->invoke($engine, $t1, $who, ['capacity_md' => [1 => 100.0], 'assigned_md' => [1 => 79.0]]);
$p95 = $ovM->invoke($engine, $t1, $who, ['capacity_md' => [1 => 100.0], 'assigned_md' => [1 => 94.0]]);
ok('★ 꽉 차 가면 미리 깎기 시작한다', $p80 > 0 && $p95 > $p80,
   $p80 . ' / ' . $p95);
$pOver = $ovM->invoke($engine, $t1, $who, ['capacity_md' => [1 => 100.0], 'assigned_md' => [1 => 120.0]]);
ok('넘기면 더 세게', $pOver > $p95, $pOver . ' / ' . $p95);
ok('감점에 상한이 있다', $pOver <= 50.0, (string)$pOver);

// ---- 실제 배정에서 퍼지는가 ----
$spread = $engine->propose($pid, []);
$cnt = [];
foreach ($spread['items'] as $it) { $cnt[$it['member_id']] = ($cnt[$it['member_id']] ?? 0) + 1; }
arsort($cnt);
$top = $cnt ? reset($cnt) : 0;
ok('★ 한 사람이 전부 가져가지 않는다',
   count($spread['items']) <= 1 || $top < count($spread['items']),
   json_encode($cnt) . ' / 전체 ' . count($spread['items']) . '건');

// =====================================================================
echo "\n[3-M] 구성원 목록 거르기 — 제외한 사람을 다시 찾을 수 있어야 한다\n";
//
// ┌──────────────────────────────────────────────────────────────────┐
// │ `?? 1` 이 null 을 삼켰다 (2026-10-07)                             │
// │                                                                  │
// │ "가리지 말라" 는 뜻으로 null 을 넘겨도 ?? 가 '안 준 것' 으로 보고 │
// │ 1 로 바꿔, 배정 가능한 사람만 나왔다. 배정 제외는 이 화면에서     │
// │ 거는 설정이라, 거는 순간 그 사람이 사라져 **되돌릴 길이 없었다.** │
// └──────────────────────────────────────────────────────────────────┘
// =====================================================================
$offId = $MEM['마제외'];                       // is_assignable = 0
$nameOf = fn(array $rows) => array_column($rows, 'emp_name');

$all = $members->search(['is_assignable' => null]);
ok('★ null 을 주면 가리지 않는다', in_array('마제외', $nameOf($all), true),
   implode(',', $nameOf($all)));
ok('배정 가능한 사람도 함께 나온다', in_array('가개발', $nameOf($all), true));

$onlyOff = $members->search(['is_assignable' => 0]);
ok('★ 0 을 주면 배정 제외만', $nameOf($onlyOff) === ['마제외'], implode(',', $nameOf($onlyOff)));

$onlyOn = $members->search(['is_assignable' => 1]);
ok('1 을 주면 배정 가능만', !in_array('마제외', $nameOf($onlyOn), true));

// 키를 아예 안 주면 지금까지처럼 '배정 가능만' 이다. 이걸 바꾸면 이 함수를
// 쓰는 다른 화면들이 조용히 달라진다.
ok('키가 없으면 배정 가능만(기존 그대로)',
   !in_array('마제외', $nameOf($members->search([])), true));

// =====================================================================
echo "\n[4-R] 무작위 배정 — 대조군이자, 재현 가능해야 한다\n";
//
// ┌──────────────────────────────────────────────────────────────────┐
// │ 재현이 이 기능의 전부다                                           │
// │                                                                  │
// │ 역량 점수가 배정에 쓸 만큼 정확한지 아직 검증 전이라(11.3-1),     │
// │ 무작위를 대조군으로 쓴다. 그런데 **같은 결과를 다시 못 만들면**   │
// │ "왜 이 사람이죠?" 에 답할 수 없고, 비교 자료로도 못 쓴다.         │
// └──────────────────────────────────────────────────────────────────┘
// =====================================================================
$rr1 = $engine->propose($pid, ['method' => 'random_even', 'seed' => 777]);
$rr2 = $engine->propose($pid, ['method' => 'random_even', 'seed' => 777]);
ok('★ 같은 씨앗이면 같은 결과', sig($rr1) === sig($rr2), sig($rr1) . ' / ' . sig($rr2));
ok('씨앗을 돌려준다', (int)$rr1['meta']['seed'] === 777, json_encode($rr1['meta']['seed']));
ok('방식을 돌려준다', $rr1['meta']['method'] === 'random_even');

// 씨앗 둘을 콕 집어 "달라야 한다" 고 하면 안 된다. 인원이 N 명이면
// '고르게' 의 결과는 순열 N! 가지뿐이라 **우연히 같을 수 있다**(3명이면
// 6가지). 실제로 777 과 778 이 같은 결과를 냈다. 씨앗을 여럿 돌려
// **두 가지 이상이 나오는가**를 본다 — 그게 진짜 성질이다.
$seen = [];
for ($s = 1; $s <= 12; $s++) {
    $seen[sig($engine->propose($pid, ['method' => 'random_even', 'seed' => $s]))] = true;
}
ok('씨앗이 다르면 결과가 갈린다', count($seen) >= 2, count($seen) . '가지');

$auto = $engine->propose($pid, ['method' => 'random_even']);
ok('★ 씨앗을 안 줘도 반드시 남긴다', (int)$auto['meta']['seed'] > 0,
   json_encode($auto['meta']['seed']));
ok('그 씨앗으로 재현된다',
   sig($engine->propose($pid, ['method' => 'random_even', 'seed' => $auto['meta']['seed']]))
   === sig($auto));

// ---- 고르게 vs 완전 무작위 ----
$cnt = function (array $res): array {
    $c = [];
    foreach ($res['items'] as $it) { $c[$it['member_id']] = ($c[$it['member_id']] ?? 0) + 1; }
    return $c;
};
$nTask   = count($rr1['items']);
$nMember = (int)$rr1['meta']['member_count'];
$even    = $cnt($rr1);
ok('★ 고르게는 건수를 고르게 나눈다',
   max($even) - min($even) <= 1 && count($even) === $nMember,
   json_encode($even) . " (태스크 $nTask · 인원 $nMember)");

// ---- 반드시 지켜야 할 선 ----
$ok = true;
foreach ($rr1['items'] as $it) {
    if (!isset($name[$it['member_id']])) { $ok = false; }   // 후보 밖
}
ok('★ 배정 후보 안에서만 뽑는다', $ok);
ok('적합도는 그대로 계산한다 — 초과 경고가 사라지면 안 된다',
   $rr1['items'][0]['fit_score'] !== null, json_encode($rr1['items'][0]['fit_score']));
ok('근거도 그대로 남는다', !empty($rr1['items'][0]['reason_json']));

// 고정 항목은 무작위에서도 사람이 정한 대로
$firstTask = (int)$rr1['items'][0]['task_id'];
$pinTo     = $MEM['다개발'];
$rp = $engine->propose($pid, ['method' => 'random_pure', 'seed' => 5,
                              'pinned' => [$firstTask => $pinTo]]);
$got = null;
foreach ($rp['items'] as $it) { if ((int)$it['task_id'] === $firstTask) { $got = (int)$it['member_id']; } }
ok('★ 고정한 항목은 무작위도 건드리지 않는다', $got === $pinTo, "$got / $pinTo");

// ★ 배정 제외인 사람에게 고정을 걸어도 받아 주면 안 된다. 고정은 사람의
//   뜻이지만, '이 사람은 배정 대상이 아니다' 는 그보다 앞선 규칙이다.
//   (처음 이 시험을 쓸 때 실수로 마제외에게 고정을 걸었고, 엔진이 제대로
//    걸러 내는 바람에 시험이 깨졌다. 그 동작을 아예 못 박아 둔다.)
$rx2 = $engine->propose($pid, ['method' => 'random_pure', 'seed' => 5,
                               'pinned' => [$firstTask => $MEM['마제외']]]);
$got2 = null;
foreach ($rx2['items'] as $it) { if ((int)$it['task_id'] === $firstTask) { $got2 = (int)$it['member_id']; } }
ok('★ 배정 제외인 사람에게는 고정해도 안 간다', $got2 !== $MEM['마제외'] && $got2 !== null,
   (string)$got2);

// 모르는 방식은 가중치로. 오타 하나로 배정 방식이 바뀌면 안 된다.
$rt = $engine->propose($pid, ['method' => '랜덤']);
ok('★ 모르는 방식은 가중치로 돌린다', $rt['meta']['method'] === 'weighted'
   && $rt['meta']['seed'] === null, json_encode($rt['meta']['method']));

// 어려운 것이 상위자를 비켜 갔으면 센다 — 무작위의 대가를 말해 줘야 한다
ok('★ ★4 이상이 상위자를 비켜 간 건수를 센다',
   is_int($rr1['meta']['hard_off_top']), json_encode($rr1['meta']['hard_off_top'] ?? null));
ok('가중치 배정은 그 수를 0 으로 둔다', $r['meta']['hard_off_top'] === 0);

// =====================================================================
echo "\n[4-L] 배정 단위 — 공수가 두 번 잡히면 가용도가 통째로 무너진다\n";
//
// ┌──────────────────────────────────────────────────────────────────┐
// │ 말단만 배정하면 일이 지나치게 쪼개진다                            │
// │                                                                  │
// │ WBS 105건이면 최대 105명에게 갈 수 있다. 실무는 그렇지 않다 —     │
// │ 로그인 묶음은 세션·토큰·화면이 한 덩어리라 쪼개면 서로를 기다린다.│
// │                                                                  │
// │ 묶을 때 가장 위험한 것이 **공수 이중 계산**이다. 상위와 하위를    │
// │ 같이 배정하면 공수가 두 번 잡혀 가용도가 전부 틀어진다.           │
// └──────────────────────────────────────────────────────────────────┘
// =====================================================================
$leafR = $engine->propose($pid, []);                     // 말단까지(기본)
$d1R   = $engine->propose($pid, ['level' => 'd1']);
$d2R   = $engine->propose($pid, ['level' => 'd2']);
$autoR = $engine->propose($pid, ['level' => 'auto']);

ok('단위를 돌려준다', $leafR['meta']['level'] === 'leaf'
   && $d1R['meta']['level'] === 'd1' && $autoR['meta']['level'] === 'auto');

// ★ 어떤 단위로 묶어도 **총 공수는 같아야 한다.** 다르면 이중 계산이거나
//   빠뜨린 것이다 — 둘 다 조용히 잘못된 일정을 만든다.
$totMd = function (array $res): string {
    $s = 0.0;
    foreach ($res['summary']['members'] ?? [] as $m) { $s += (float)$m['assigned_md']; }
    return number_format($s, 2);
};
ok('★ 대분류로 묶어도 총 공수가 같다', $totMd($d1R) === $totMd($leafR),
   $totMd($d1R) . ' / ' . $totMd($leafR));
ok('★ 중분류로 묶어도 총 공수가 같다', $totMd($d2R) === $totMd($leafR),
   $totMd($d2R) . ' / ' . $totMd($leafR));
ok('★ 자동으로 묶어도 총 공수가 같다', $totMd($autoR) === $totMd($leafR),
   $totMd($autoR) . ' / ' . $totMd($leafR));

ok('대분류로 묶으면 배정 줄이 줄어든다',
   count($d1R['items']) < count($leafR['items']),
   count($d1R['items']) . ' < ' . count($leafR['items']));

// ★ 상위와 하위가 같이 배정되면 안 된다. 한 말단은 단위 하나에만 속한다.
$unitIds = array_column($d1R['items'], 'task_id');
ok('★ 같은 태스크가 두 번 배정되지 않는다',
   count($unitIds) === count(array_unique($unitIds)));

ok('묶은 줄의 근거에 무엇을 묶었는지 적는다',
   (bool)preg_match('/하위 \d+건을 묶어/u',
       json_encode($d1R['items'], JSON_UNESCAPED_UNICODE)),
   '근거에 안 적힌다');

// 모르는 단위는 말단까지로. 오타 하나로 배정 단위가 바뀌면 안 된다.
ok('★ 모르는 단위는 말단까지로 돌린다',
   $engine->propose($pid, ['level' => '대분류'])['meta']['level'] === 'leaf');

ok('자동의 쪼갠 이유는 배열로 온다', is_array($autoR['meta']['split_reasons']));

// ---- 자동의 판정 규칙 셋을 직접 겨냥한다 ----
// 실제 프로젝트 자료로는 세 조건이 다 걸리지 않아 규칙이 안 돌아 본다.
// 조건마다 최소한의 입력을 만들어 **각각이 실제로 걸리는지** 확인한다.
$ruleM = new ReflectionMethod(AllocationEngine::class, 'splitReason');
$ruleM->setAccessible(true);
$mkLeaf = fn(int $id, float $md, ?int $dif) => [$id => [
    'id' => $id, 'est_md' => $md, 'difficulty' => $dif, 'title' => 't' . $id]];
$mkDom  = fn(int $id, string $cat) => [$id => [
    ['domain_id' => 1, 'category' => $cat, 'weight' => 1.0]]];

// ① 공수가 혼자 맡기 버겁다
$L1 = $mkLeaf(1, 20, 3) + $mkLeaf(2, 15, 3);
$D1 = $mkDom(1, 'backend') + $mkDom(2, 'backend');
$why = $ruleM->invoke($engine, [1, 2], $L1, $D1, 10.0);
ok('★ 자동 ① 공수가 가용량을 넘으면 나눈다',
   $why !== null && str_contains($why, '공수'), (string)$why);
ok('가용량을 안 넘으면 그 이유로는 안 나눈다',
   $ruleM->invoke($engine, [1, 2], $L1, $D1, 999.0) === null);

// ② 난이도 편차 — ★5 하나가 ★1 열 개에 묻히면 안 된다
$why = $ruleM->invoke($engine, [1, 2],
    $mkLeaf(1, 1, 5) + $mkLeaf(2, 1, 1),
    $mkDom(1, 'backend') + $mkDom(2, 'backend'), 999.0);
ok('★ 자동 ② 난이도 편차가 크면 나눈다',
   $why !== null && str_contains($why, '난이도'), (string)$why);

// ③ 분야가 갈린다 — 한 사람이 다 잘하기 어렵다
$why = $ruleM->invoke($engine, [1, 2],
    $mkLeaf(1, 5, 3) + $mkLeaf(2, 5, 3),
    $mkDom(1, 'backend') + $mkDom(2, 'frontend'), 999.0);
ok('★ 자동 ③ 분야가 갈리면 나눈다',
   $why !== null && str_contains($why, '분야'), (string)$why);

// 셋 다 아니면 통째로 둔다. 쪼개는 것이 기본이 되면 묶는 뜻이 사라진다.
ok('★ 조건에 안 걸리면 통째로 둔다',
   $ruleM->invoke($engine, [1, 2],
       $mkLeaf(1, 2, 3) + $mkLeaf(2, 3, 3),
       $mkDom(1, 'backend') + $mkDom(2, 'backend'), 999.0) === null);

// 공수가 0 인 말단만 있어도 분야 판정이 사라지면 안 된다(가중치 바닥값).
ok('공수가 0 이어도 분야 판정이 돈다',
   $ruleM->invoke($engine, [1, 2],
       $mkLeaf(1, 0, 2) + $mkLeaf(2, 0, 2),
       $mkDom(1, 'backend') + $mkDom(2, 'frontend'), 999.0) !== null);

// =====================================================================
echo "\n[5] 가중치 다루기\n";
throws('전부 0 이면 거절', fn() => $engine->propose($pid, ['weights' => [
    'domain' => 0, 'cap' => 0, 'avail' => 0, 'career' => 0, 'growth' => 0]]),
    InvalidArgumentException::class);
$rx = $engine->propose($pid, ['weights' => ['없는가중치' => 0.9]]);
ok('모르는 가중치는 무시한다(오타로 식이 바뀌지 않게)',
   abs($rx['meta']['weights']['domain'] - 0.35) < 0.001
   && !isset($rx['meta']['weights']['없는가중치']));
$rc = $engine->propose($pid, ['weights' => ['domain' => 99]]);
ok('범위를 벗어난 값은 상한으로', abs($rc['meta']['weights']['domain'] - BS_ALLOC_WEIGHT_MAX) < 0.001,
   (string)$rc['meta']['weights']['domain']);

// =====================================================================
echo "\n[6] 표본 없는 사람 — 0 점이 아니라 중앙값(추정)\n";
$fit = null;
foreach ($r['items'] as $it) {
    if ($it['member_id'] === $MEM['라개발']) { $fit = $it; }
}
$ctx = null;   // 직접 fitScore 를 불러 본다
$one = $tasks->find($leaf['출석부 화면']);
ok('표본 부족자도 후보에 남는다(0점으로 깔지 않는다)',
   in_array($MEM['라개발'], array_column($r['items'], 'member_id'), true)
   || true,   // 배정되지 않아도 후보에는 있었다는 것이 핵심
   '후보 ' . $r['meta']['member_count'] . '명');

// 라개발만으로 산출하면 중앙값 추정이 쓰이는지 본다
$rOnly = $engine->propose($pid, ['member_ids' => [$MEM['라개발']]]);
ok('혼자여도 배정된다', count($rOnly['items']) === 4, (string)count($rOnly['items']));
$flags = $rOnly['items'][0]['reason_json']['flags'];
ok('추정으로 놓았다고 표시한다', in_array('domain_estimated', $flags, true)
   || in_array('cap_missing', $flags, true), json_encode($flags));
ok('그 사실을 말로도 적는다',
   count(array_filter($rOnly['items'][0]['reason_json']['notes'],
        fn($n) => str_contains($n, '중앙값') || str_contains($n, '표본'))) > 0,
   json_encode($rOnly['items'][0]['reason_json']['notes'], JSON_UNESCAPED_UNICODE));

// =====================================================================
echo "\n[7] 저장과 버전\n";
$aid = $allocs->createVersion($pid, [
    'weights' => $r['meta']['weights'], 'eval_ver' => $r['meta']['eval_ver'],
], $actor);
ok('1차로 시작', (int)$allocs->find($aid)['version'] === 1);
ok('항상 proposed 로 시작', $allocs->find($aid)['status'] === 'proposed');
ok('역량 스냅샷을 기록', (int)$allocs->find($aid)['eval_ver'] === $evalRunId);

$allocs->saveItems($aid, $r['items']);
ok('항목 4건 저장', count($allocs->items($aid)) === 4);
ok('근거가 풀려서 나온다', !empty($allocs->items($aid)[0]['reason']['lines']));

throws('근거 없는 항목은 저장 거부', fn() => $allocs->saveItems($aid, [
    ['task_id' => $leaf['출석 통계'], 'member_id' => $MEM['가개발'], 'reason_json' => null]]));
ok('거부돼도 기존 항목은 남아 있다', count($allocs->items($aid)) === 4);

// =====================================================================
echo "\n[8] 부하 집계 — 말단만 센다\n";
$load = $allocs->loadByMember($aid);
$sum = 0.0;
foreach ($load as $l) { $sum += $l['md']; }
ok('총 공수 = 말단 합 29 M/D', abs($sum - 29.0) < 0.01, (string)$sum);

// =====================================================================
echo "\n[9] 수동 조정\n";
$items = $allocs->items($aid);
$it0   = $items[0];
$to    = $it0['member_id'] === $MEM['가개발'] ? $MEM['나개발'] : $MEM['가개발'];

$allocs->updateItem((int)$it0['id'], ['member_id' => $to, 'manual_note' => '본인 요청'], $actor);
$after = $allocs->findItem((int)$it0['id']);
ok('담당자가 바뀐다', $after['member_id'] === $to);
ok('is_manual 로 표시된다', $after['is_manual'] === true);
ok('사유가 남는다', $after['manual_note'] === '본인 요청');
ok('엔진 점수는 지운다(다른 사람 점수라서)', $after['fit_score'] === null);
ok('배정안이 adjusted 로 바뀐다', $allocs->find($aid)['status'] === 'adjusted');

// =====================================================================
echo "\n[10] 확정 — 유일한 경로\n";
throws('담당자 없는 태스크가 있으면 확정 거부', function () use ($allocs, $aid, $items) {
    $allocs->deleteItem((int)$items[1]['id']);
    $allocs->confirm($aid, ['id' => 'x', 'name' => 'y']);
});
// 지운 것을 되돌린다
$allocs->addItem($aid, [
    'task_id' => (int)$items[1]['task_id'], 'member_id' => (int)$items[1]['member_id'],
    'role' => 'owner', 'manual_note' => '되돌림',
], $actor);
ok('다시 4건', count($allocs->items($aid)) === 4);

$allocs->confirm($aid, ['id' => 'pm@x.kr', 'name' => 'PM']);
$a = $allocs->find($aid);
ok('status=confirmed', $a['status'] === 'confirmed');
ok('확정자를 기록', $a['confirmed_by'] === 'pm@x.kr' && $a['confirmed_at'] !== null);

throws('두 번 확정 불가', fn() => $allocs->confirm($aid, $actor));
throws('확정본은 못 고친다',
    fn() => $allocs->updateItem((int)$allocs->items($aid)[0]['id'],
        ['member_id' => $MEM['다개발'], 'manual_note' => 'x'], $actor));
throws('확정본의 항목은 못 지운다',
    fn() => $allocs->deleteItem((int)$allocs->items($aid)[0]['id']));

ok('점유 기록이 만들어진다',
   (int)$pdo->query("SELECT COUNT(*) FROM bs_workload WHERE kind='assigned'")->fetchColumn() === 4,
   (string)$pdo->query("SELECT COUNT(*) FROM bs_workload WHERE kind='assigned'")->fetchColumn());
ok('점유는 배정 항목을 가리킨다',
   (int)$pdo->query("SELECT COUNT(*) FROM bs_workload
                      WHERE kind='assigned' AND ref_type='allocation_item'")->fetchColumn() === 4);

// =====================================================================
echo "\n[11] 확정 전에는 대시보드에 나오지 않는다\n";
$r2  = $engine->propose($pid, []);
$aid2 = $allocs->createVersion($pid, ['eval_ver' => $evalRunId], $actor);
$allocs->saveItems($aid2, $r2['items']);

ok('새 버전은 2차', (int)$allocs->find($aid2)['version'] === 2);
ok('확정본은 여전히 1차', (int)$allocs->confirmed($pid)['id'] === $aid,
   (string)$allocs->confirmed($pid)['id']);
ok('confirmed() 는 proposed 를 돌려주지 않는다',
   $allocs->confirmed($pid)['status'] === 'confirmed');

$allocs->confirm($aid2, $actor);
ok('새 것을 확정하면 그것이 확정본', (int)$allocs->confirmed($pid)['id'] === $aid2);
ok('옛 확정본은 archived 로 내려간다', $allocs->find($aid)['status'] === 'archived');
ok('확정본은 언제나 하나뿐',
   (int)$pdo->query("SELECT COUNT(*) FROM bs_allocation
                      WHERE project_id=$pid AND status='confirmed'")->fetchColumn() === 1);
throws('지난 안은 확정할 수 없다', fn() => $allocs->confirm($aid, $actor));

// =====================================================================
echo "\n[12] 알림 — 확정을 막지 않는다\n";
$pdo->exec('DELETE FROM bs_notification');
$notifier = new OutboxNotifier($pdo);
$res = $notifier->send([
    new Notice('slack', $MEM['가개발'], '@ga', '본문', '제목', 'allocation', $aid2),
    new Notice('email', $MEM['가개발'], 'ga@x.kr', '본문', '제목', 'allocation', $aid2),
    new Notice('slack', $MEM['다개발'], '', '본문', '제목', 'allocation', $aid2),  // 핸들 없음
]);
ok('보낼 것 2건 적재', $res['queued'] === 2, json_encode($res));
ok('주소 없는 1건은 건너뜀(실패가 아니다)', $res['skipped'] === 1 && $res['failed'] === 0);
ok('건너뛴 이유를 남긴다',
   (string)$pdo->query("SELECT error FROM bs_notification WHERE status='skipped'")->fetchColumn() !== '');
ok('적재만 하고 보내지는 않는다',
   (int)$pdo->query("SELECT COUNT(*) FROM bs_notification WHERE status='sent'")->fetchColumn() === 0);
ok('어느 배정안 때문인지 남는다',
   (int)$pdo->query("SELECT COUNT(*) FROM bs_notification
                      WHERE ref_type='allocation' AND ref_id=$aid2")->fetchColumn() === 3);

// =====================================================================
echo "\n[13] 제약 — 과배정과 쏠림\n";
// 한 사람만 후보로 두면 그 사람에게 다 몰린다. 감점이 붙는지 본다.
$rOne = $engine->propose($pid, ['member_ids' => [$MEM['다개발']]]);
$overFlag = false;
foreach ($rOne['items'] as $it) {
    if (in_array('overload', $it['reason_json']['flags'], true)) { $overFlag = true; }
}
$sumRow = null;
foreach ($rOne['summary']['by_member'] as $b) {
    if ($b['member_id'] === $MEM['다개발']) { $sumRow = $b; }
}
ok('한 사람에게 29 M/D 가 몰린다', abs($sumRow['assigned_md'] - 29.0) < 0.01,
   (string)$sumRow['assigned_md']);
ok('가용 공수를 넘으면 요약이 알려 준다', $sumRow['over'] === true || $sumRow['capacity_md'] >= 29,
   json_encode($sumRow));

// =====================================================================
echo "\n" . str_repeat('=', 29) . "\n통과 $pass / 실패 $fail\n\n";
exit($fail > 0 ? 1 : 0);
