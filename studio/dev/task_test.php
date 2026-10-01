<?php
/** TaskRepo 통합 테스트 — WBS 트리 저장·번호 매김·확정 차단을 실제 DB 에서 확인한다. */
declare(strict_types=1);

define('ROOT', dirname(__DIR__, 2));
$TESTDB = getenv('BA_TEST_DB') ?: 'blueassign_test';
require ROOT . '/studio/inc/bootstrap.php';
require ROOT . '/studio/inc/repo/ProjectRepo.php';
require ROOT . '/studio/inc/repo/TaskRepo.php';
require ROOT . '/studio/inc/service/WbsExtractor.php';

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
$pdo->exec('DELETE FROM ba_allocation_item');
$pdo->exec('DELETE FROM ba_task_domain');
$pdo->exec('DELETE FROM ba_task');
$pdo->exec('DELETE FROM ba_project_source');
$pdo->exec('DELETE FROM ba_project');

$projects = new ProjectRepo($pdo);
$repo     = new TaskRepo($pdo);
$actor    = ['id' => 'kimhy@bluesoft.co.kr', 'name' => '김호영'];

$pid = $projects->create(['name' => 'WBS 시험 프로젝트', 'status' => 'scoping'], $actor);

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  OK   $what\n"; }
    else       { $fail++; echo "  FAIL $what" . ($extra ? " — $extra" : '') . "\n"; }
}
function throws(string $what, callable $fn, string $expectClass = DomainException::class): void {
    global $pass, $fail;
    try { $fn(); $fail++; echo "  FAIL $what — 예외가 안 났다\n"; }
    catch (Throwable $e) {
        if ($e instanceof $expectClass) {
            $pass++; echo "  OK   $what → " . str_replace("\n", ' / ', $e->getMessage()) . "\n";
        } else {
            $fail++; echo "  FAIL $what — 다른 예외: " . get_class($e) . ' ' . $e->getMessage() . "\n";
        }
    }
}
/** 트리에서 제목으로 노드 찾기 */
function pick(array $nodes, string $title): ?array {
    foreach ($nodes as $n) {
        if ($n['title'] === $title) { return $n; }
        $x = pick($n['children'], $title);
        if ($x) { return $x; }
    }
    return null;
}
function treeOf(TaskRepo $r, int $pid): array {
    $flat = $r->allByProject($pid);
    return $r->toTree($flat, $r->domainsFor(array_column($flat, 'id')));
}

$TREE = [
    ['title' => '요구사항 분석', 'children' => [
        ['title' => '현행 조사', 'est_md' => 3, 'difficulty' => 2],
        ['title' => '인터뷰', 'est_md' => 99, 'children' => [
            ['title' => '교수 인터뷰', 'est_md' => 1.5, 'domain_ids' => [1, 5]],
            ['title' => '학생 인터뷰', 'est_md' => 0.5],
        ]],
    ]],
    ['title' => '개발', 'children' => [
        ['title' => '출석부 개선', 'est_md' => 8, 'difficulty' => 4,
         'plan_start' => '2026-07-06', 'plan_end' => '2026-07-31', 'domain_ids' => [1]],
    ]],
];

// =====================================================================
echo "\n[1] 트리 저장\n";
$r = $repo->saveTree($pid, $TREE, $actor);
ok('7건 저장', $r['saved'] === 7 && $r['created'] === 7, json_encode($r));
ok('삭제 없음', $r['deleted'] === 0);
ok('revision 반환', strlen($r['revision']) === 16);

$tree = treeOf($repo, $pid);
ok('뿌리 2개', count($tree) === 2, (string)count($tree));

// =====================================================================
echo "\n[2] wbs_no 는 서버가 자리에서 매긴다\n";
ok('1',     pick($tree, '요구사항 분석')['wbs_no'] === '1');
ok('1.1',   pick($tree, '현행 조사')['wbs_no']    === '1.1');
ok('1.2',   pick($tree, '인터뷰')['wbs_no']       === '1.2');
ok('1.2.1', pick($tree, '교수 인터뷰')['wbs_no']  === '1.2.1');
ok('1.2.2', pick($tree, '학생 인터뷰')['wbs_no']  === '1.2.2');
ok('2',     pick($tree, '개발')['wbs_no']         === '2');
ok('2.1',   pick($tree, '출석부 개선')['wbs_no']  === '2.1');
ok('depth 도 자리에서 나온다', pick($tree, '교수 인터뷰')['depth'] === 3);

// =====================================================================
echo "\n[3] 공수 롤업 — 상위에 적힌 자기 공수는 합계에서 뺀다\n";
ok('1.2 롤업 = 1.5 + 0.5',   abs(pick($tree, '인터뷰')['est_md_roll'] - 2.0) < 0.001,
   (string)pick($tree, '인터뷰')['est_md_roll']);
ok('상위의 자기 공수 99 는 무시', pick($tree, '인터뷰')['est_md_own_ignored'] === true);
ok('1 롤업 = 3 + 2',          abs(pick($tree, '요구사항 분석')['est_md_roll'] - 5.0) < 0.001,
   (string)pick($tree, '요구사항 분석')['est_md_roll']);
ok('말단은 자기 값 그대로',   abs(pick($tree, '현행 조사')['est_md_roll'] - 3.0) < 0.001);

// =====================================================================
echo "\n[4] 분야 연결 — 가중치는 고른 개수로 균등하게\n";
$d = pick($tree, '교수 인터뷰')['domains'];
ok('2개 붙음', count($d) === 2, (string)count($d));
ok('가중치 0.5씩', abs($d[0]['weight'] - 0.5) < 0.001 && abs($d[1]['weight'] - 0.5) < 0.001);
ok('계열 이름이 같이 온다', ($d[0]['cat_label'] ?? '') !== '');

// =====================================================================
echo "\n[5] 확정 전에는 배정 대상이 아니다\n";
ok('배정 대상 0건', count($repo->confirmedForAllocation($pid)) === 0);

$leafIds = [];
foreach ($repo->allByProject($pid) as $t) {
    if (!in_array((int)$t['id'], array_map(
            static fn($x) => (int)$x['parent_id'],
            array_filter($repo->allByProject($pid), static fn($y) => $y['parent_id'] !== null)
        ), true)) {
        $leafIds[] = (int)$t['id'];
    }
}
ok('말단 4건', count($leafIds) === 4, (string)count($leafIds));

throws('미확정은 assertAssignable 이 막는다', fn() => $repo->assertAssignable($leafIds));

$n = $repo->confirm($leafIds, true, $actor);
ok('4건 확정', $n === 4, (string)$n);
ok('배정 대상 4건', count($repo->confirmedForAllocation($pid)) === 4);
$repo->assertAssignable($leafIds);
ok('확정 뒤에는 assertAssignable 통과', true);

// =====================================================================
echo "\n[6] 끝났거나 보류인 태스크는 확정돼 있어도 배정 대상이 아니다\n";
$repo->updateProgress($leafIds[0], 'done', 100);
ok('완료 1건 빠짐', count($repo->confirmedForAllocation($pid)) === 3);
$repo->updateProgress($leafIds[1], 'hold', 0);
ok('보류 1건 더 빠짐', count($repo->confirmedForAllocation($pid)) === 2);
throws('완료·보류는 assertAssignable 도 막는다', fn() => $repo->assertAssignable($leafIds));
$repo->updateProgress($leafIds[0], 'todo', 0);
$repo->updateProgress($leafIds[1], 'todo', 0);

// =====================================================================
echo "\n[7] saveTree 는 confirmed 를 건드리지 않는다\n";
$before = count($repo->confirmedForAllocation($pid));
$again  = [];
$rebuild = function (array $ns) use (&$rebuild): array {
    return array_map(static fn($n) => [
        'id' => $n['id'], 'title' => $n['title'] . ' (수정)',
        'confirmed' => false,          // 일부러 끼워 넣는다
        'status'    => 'done',         // 이것도
        'children'  => $rebuild($n['children']),
    ], $ns);
};
$repo->saveTree($pid, $rebuild(treeOf($repo, $pid)), $actor);
ok('confirmed 그대로', count($repo->confirmedForAllocation($pid)) === $before,
   $before . ' → ' . count($repo->confirmedForAllocation($pid)));
ok('제목은 바뀜', pick(treeOf($repo, $pid), '개발 (수정)') !== null);

// =====================================================================
echo "\n[8] 낙관적 잠금 — 남이 먼저 저장하면 거절\n";
$rev = $repo->revision($pid);
$repo->saveTree($pid, $rebuild(treeOf($repo, $pid)), $actor, $rev);   // 같은 표면 통과
ok('맞는 revision 은 통과', true);

// 바로 이어서 한 번 더 — updated_at 이 같은 초라 예전 방식이면 새던 자리다.
$stale = $repo->revision($pid);
$repo->confirm([$leafIds[2]], false, $actor);
throws('확정 해제만 해도 revision 이 바뀐다',
       fn() => $repo->saveTree($pid, treeOf($repo, $pid), $actor, $stale));
$repo->confirm([$leafIds[2]], true, $actor);

$stale2 = $repo->revision($pid);
$one = treeOf($repo, $pid);
$one[0]['title'] = '제목만 바꿈';
$repo->saveTree($pid, $one, $actor, $stale2);
throws('같은 초에 두 번 저장해도 두 번째는 막힌다',
       fn() => $repo->saveTree($pid, $one, $actor, $stale2));

// =====================================================================
echo "\n[9] 계층 제한\n";
throws('4단계는 거절', fn() => $repo->saveTree($pid, [
    ['title' => 'A', 'children' => [['title' => 'B', 'children' => [
        ['title' => 'C', 'children' => [['title' => 'D']]]]]]],
], $actor));
throws('제목이 비면 거절', fn() => $repo->saveTree($pid, [['title' => '  ']], $actor),
       InvalidArgumentException::class);
throws('난이도 6 거절', fn() => $repo->saveTree($pid, [['title' => 'X', 'difficulty' => 6]], $actor),
       InvalidArgumentException::class);
throws('공수 음수 거절', fn() => $repo->saveTree($pid, [['title' => 'X', 'est_md' => -1]], $actor),
       InvalidArgumentException::class);
throws('시작일 > 종료일 거절',
       fn() => $repo->saveTree($pid, [['title' => 'X',
           'plan_start' => '2026-08-01', 'plan_end' => '2026-07-01']], $actor),
       InvalidArgumentException::class);
throws('없는 날짜 거절',
       fn() => $repo->saveTree($pid, [['title' => 'X', 'plan_start' => '2026-02-30']], $actor),
       InvalidArgumentException::class);
throws('분야 6개 거절', fn() => $repo->saveTree($pid,
       [['title' => 'X', 'domain_ids' => [1,2,3,4,5,6]]], $actor),
       InvalidArgumentException::class);

// 거절된 저장이 반쪽으로 남지 않았는지 — 트랜잭션 확인
ok('실패한 저장이 트리를 건드리지 않았다', count($repo->allByProject($pid)) === 7,
   (string)count($repo->allByProject($pid)));

// =====================================================================
echo "\n[10] 남의 프로젝트 태스크를 끼워 넣을 수 없다\n";
$pid2 = $projects->create(['name' => '다른 프로젝트'], $actor);
$repo->saveTree($pid2, [['title' => '남의 일']], $actor);
$otherId = (int)$repo->allByProject($pid2)[0]['id'];
throws('남의 태스크 id 를 넣으면 거절',
       fn() => $repo->saveTree($pid, [['id' => $otherId, 'title' => '가로채기']], $actor));
ok('남의 태스크는 그대로', count($repo->allByProject($pid2)) === 1);
throws('서로 다른 프로젝트를 한 번에 확정 불가',
       fn() => $repo->projectIdOf([$otherId, (int)$repo->allByProject($pid)[0]['id']]));

// =====================================================================
echo "\n[11] 이동\n";
$tree = treeOf($repo, $pid);
$dev  = pick($tree, '개발 (수정)') ?? pick($tree, '제목만 바꿈');
$repo->move((int)$dev['id'], null, 0);
$tree = treeOf($repo, $pid);
ok('맨 앞으로 옮겨짐', (int)$tree[0]['id'] === (int)$dev['id']);
ok('번호가 다시 매겨짐', $tree[0]['wbs_no'] === '1' && $tree[1]['wbs_no'] === '2');

$parent = pick($tree, '요구사항 분석 (수정)') ?? $tree[1];
throws('자기 자신 아래로 이동 불가', fn() => $repo->move((int)$parent['id'], (int)$parent['id'], 0));
$child = $parent['children'][0] ?? null;
if ($child) {
    throws('자기 하위 아래로 이동 불가',
           fn() => $repo->move((int)$parent['id'], (int)$child['id'], 0));
}

// =====================================================================
echo "\n[12] 삭제 — 하위까지 같이, 잎부터\n";
$tree  = treeOf($repo, $pid);
$root0 = $tree[0];
$kids  = count($repo->allByProject($pid));
$repo->delete((int)$root0['id']);
ok('하위까지 사라짐', count($repo->allByProject($pid)) < $kids);
ok('고아가 남지 않았다', (int)$pdo->query(
    'SELECT COUNT(*) FROM ba_task t LEFT JOIN ba_task p ON p.id = t.parent_id
      WHERE t.parent_id IS NOT NULL AND p.id IS NULL')->fetchColumn() === 0);
$tree = treeOf($repo, $pid);
ok('삭제 뒤 번호 다시 매김', !$tree || $tree[0]['wbs_no'] === '1');

// =====================================================================
echo "\n[13] 배정안에 들어간 태스크는 못 지우고 확정도 못 푼다\n";
$repo->saveTree($pid, [['title' => '배정된 일']], $actor);
$tid = (int)$repo->allByProject($pid)[0]['id'];
$repo->confirm([$tid], true, $actor);

$pdo->prepare(
    'INSERT INTO ba_member (user_id, emp_name) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE emp_name = VALUES(emp_name)'
)->execute(['tasktest@bluesoft.co.kr', '시험구성원']);
$mid = (int)$pdo->query("SELECT id FROM ba_member WHERE user_id='tasktest@bluesoft.co.kr'")->fetchColumn();

$pdo->prepare(
    'INSERT INTO ba_allocation (project_id, version, status, created_by, created_by_name)
     VALUES (?, 1, ?, ?, ?)'
)->execute([$pid, 'draft', $actor['id'], $actor['name']]);
$aid = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO ba_allocation_item (allocation_id, task_id, member_id) VALUES (?,?,?)')
    ->execute([$aid, $tid, $mid]);

throws('배정된 태스크 삭제 거부', fn() => $repo->delete($tid));
throws('배정된 태스크 확정 해제 거부', fn() => $repo->confirm([$tid], false, $actor));
ok('확정은 그대로', count($repo->confirmedForAllocation($pid)) === 1);
throws('트리에서 빼는 저장도 거부', fn() => $repo->saveTree($pid, [['title' => '딴것']], $actor));
ok('거부 뒤에도 태스크가 남아 있다', $repo->find($tid) !== null);

// =====================================================================
echo "\n[14] 상한\n";
$big = [];
for ($i = 0; $i < BA_TASK_MAX_PER_PROJECT + 1; $i++) {
    $big[] = ['title' => '대량 ' . $i];
}
throws('상한을 넘으면 거절(엑셀 통째 붙여넣기 방어)',
       fn() => $repo->saveTree($pid, $big, $actor));

// =====================================================================
// =====================================================================
echo "\n[15] WBS 도출 — 스키마 검증\n";

// 분야표는 TaskRepo 가 읽는다. $repo 가 시험 DB 를 들고 있으므로
// 따로 맞춰 줄 것이 없다 — 그러려고 ba_db() 호출을 걷어냈다.
$ex = new WbsExtractor($projects, $repo, null, new NullLlmClient());
$dt = $ex->domainTable();
ok('분야표를 읽는다', count($dt) > 10 && isset($dt['attendance']), (string)count($dt));

$good = ['tasks' => [
    ['title' => '출석 통합', 'depth' => 1],
    ['title' => '통합 출석부', 'depth' => 2, 'est_md' => 9, 'difficulty' => 4,
     'domain_codes' => ['attendance'], 'source_ref' => '요구사항!A1'],
]];
$v = $ex->validate($good, $dt);
ok('정상 응답 2건', count($v) === 2);
ok('값이 그대로', $v[1]['est_md'] === 9.0 && $v[1]['difficulty'] === 4
   && $v[1]['domain_codes'] === ['attendance']);

throws('tasks 없으면 거절', fn() => $ex->validate(['wrong' => 1], $dt), LlmSchemaError::class);
throws('tasks 가 배열이 아니면 거절', fn() => $ex->validate(['tasks' => 'x'], $dt), LlmSchemaError::class);
throws('tasks 가 객체면 거절', fn() => $ex->validate(['tasks' => ['a' => 1]], $dt), LlmSchemaError::class);
throws('tasks 가 비면 거절', fn() => $ex->validate(['tasks' => []], $dt), LlmSchemaError::class);
throws('제목이 하나도 없으면 거절',
       fn() => $ex->validate(['tasks' => [['depth' => 1], ['title' => '']]], $dt),
       LlmSchemaError::class);
throws('너무 많으면 거절', function () use ($ex, $dt) {
    $big = [];
    for ($i = 0; $i <= WbsExtractor::MAX_TASKS; $i++) { $big[] = ['title' => "t$i", 'depth' => 1]; }
    $ex->validate(['tasks' => $big], $dt);
}, LlmSchemaError::class);

// 고칠 수 있는 것은 고치고 버리지 않는다 — 한 항목 때문에 전부 날리면
// 모델을 다시 부르는 값이 더 크다.
$messy = ['tasks' => [
    ['title' => '정상', 'depth' => 1],
    ['title' => '깊이 초과', 'depth' => 9],
    ['title' => '난이도 초과', 'depth' => 2, 'difficulty' => 99],
    ['title' => '공수 음수', 'depth' => 2, 'est_md' => -5],
    ['title' => '없는 분야', 'depth' => 2, 'domain_codes' => ['nope', 'quiz', 'nope2']],
    ['title' => '분야 과다', 'depth' => 2, 'domain_codes' => ['quiz','attendance','media','sso','notify','infra']],
    ['title' => str_repeat('가', 400), 'depth' => 2],
    ['title' => '   ', 'depth' => 1],
    'not an object',
]];
$v = $ex->validate($messy, $dt);
ok('고칠 수 있는 것은 살린다', count($v) === 7, (string)count($v));
ok('깊이는 범위 안으로', $v[1]['depth'] === BA_TASK_MAX_DEPTH, (string)$v[1]['depth']);
ok('범위 밖 난이도는 비운다', $v[2]['difficulty'] === null);
ok('음수 공수는 비운다', $v[3]['est_md'] === null);
ok('없는 분야 코드는 버린다', $v[4]['domain_codes'] === ['quiz'],
   json_encode($v[4]['domain_codes']));
ok('분야는 상한까지만', count($v[5]['domain_codes']) === BA_TASK_MAX_DOMAINS,
   (string)count($v[5]['domain_codes']));
ok('긴 제목은 자른다', mb_strlen($v[6]['title']) === 300, (string)mb_strlen($v[6]['title']));
ok('버린 것을 알려 준다', isset($v[0]['_dropped']) && count($v[0]['_dropped']) === 2,
   json_encode($v[0]['_dropped'] ?? null, JSON_UNESCAPED_UNICODE));

// =====================================================================
echo "\n[16] WBS 도출 — 재시도는 한 번뿐\n";

$srcs = [['id' => 1, 'kind' => 'xlsx', 'title' => '요구사항',
          'parsed_text' => "[[요구사항!A1:D3]]\n구분\t요구사항\t공수\t난이도\n출석\t통합 출석부\t9\t4"]];
$proj = ['id' => 1, 'name' => '시험', 'client' => null, 'track' => null,
         'summary' => null, 'notes' => null, 'extra' => null];

$fx = new FixtureLlmClient([['tasks' => [['title' => '한 번에 성공', 'depth' => 1]]]]);
[$t, $m] = (new WbsExtractor($projects, $repo, null, $fx))->extractByLlm($proj, $srcs, $dt);
ok('정상이면 한 번만 부른다', $fx->callCount() === 1 && $m['attempts'] === 1);
ok('결과가 온다', $t[0]['title'] === '한 번에 성공');

$fx2 = new FixtureLlmClient([['wrong' => 1], ['tasks' => [['title' => '두 번째 성공', 'depth' => 1]]]]);
[$t, $m] = (new WbsExtractor($projects, $repo, null, $fx2))->extractByLlm($proj, $srcs, $dt);
ok('형식이 깨지면 한 번 더 부른다', $fx2->callCount() === 2 && $m['attempts'] === 2);
ok('두 번째 결과를 쓴다', $t[0]['title'] === '두 번째 성공');

$fx3 = new FixtureLlmClient([['wrong' => 1], ['also_wrong' => 2], ['tasks' => [['title' => '세 번째', 'depth' => 1]]]]);
throws('두 번 실패하면 멈춘다',
       fn() => (new WbsExtractor($projects, $repo, null, $fx3))->extractByLlm($proj, $srcs, $dt),
       LlmError::class);
ok('세 번째는 부르지 않는다', $fx3->callCount() === 2, (string)$fx3->callCount());

// 재시도해도 소용없는 오류는 한 번에 포기한다
$fx4 = new FixtureLlmClient([new LlmError('모델이 거부함', retryable: false)]);
throws('재시도 불가 오류는 바로 포기',
       fn() => (new WbsExtractor($projects, $repo, null, $fx4))->extractByLlm($proj, $srcs, $dt),
       LlmError::class);
ok('한 번만 부른다', $fx4->callCount() === 1, (string)$fx4->callCount());

throws('LLM 이 없으면 extractByLlm 은 거절',
       fn() => (new WbsExtractor($projects, $repo, null, new NullLlmClient()))
                   ->extractByLlm($proj, $srcs, $dt),
       LlmError::class);

// =====================================================================
echo "\n[17] WBS 도출 — 규칙 기반\n";

[$t, $m] = $ex->extractByRule($srcs, $dt);
ok('머리글 줄은 태스크가 아니다',
   !in_array('요구사항', array_column($t, 'title'), true)
   && !in_array('구분', array_column($t, 'title'), true),
   json_encode(array_column($t, 'title'), JSON_UNESCAPED_UNICODE));
ok('구분 칸이 상위가 된다', $t[0]['title'] === '출석' && $t[0]['depth'] === 1,
   json_encode($t[0], JSON_UNESCAPED_UNICODE));
ok('요구사항 칸이 하위가 된다', $t[1]['title'] === '통합 출석부' && $t[1]['depth'] === 2);
ok('공수 열에서 공수를 읽는다', $t[1]['est_md'] === 9.0, (string)$t[1]['est_md']);
ok('난이도 열에서 난이도를 읽는다', $t[1]['difficulty'] === 4, (string)$t[1]['difficulty']);
ok('분야를 추정해 붙인다', in_array('attendance', $t[1]['domain_codes'], true),
   json_encode($t[1]['domain_codes']));
ok('출처에 위치가 남는다', str_contains((string)$t[1]['source_ref'], '요구사항!A1:D3'),
   (string)$t[1]['source_ref']);
ok('품질 주의를 함께 준다', str_contains($m['quality_note'] ?? '', '규칙만으로'));

// 표로 읽을 수 없으면 줄 단위로 되돌아간다
$plain = [['id' => 2, 'kind' => 'pptx', 'title' => '자료',
           'parsed_text' => "[[슬라이드 2]]\n범위\n1. 출석 통합\n2. 성적부 연동\n2026-03-02"]];
[$t2] = $ex->extractByRule($plain, $dt);
$titles = array_column($t2, 'title');
ok('번호 매김에서 깊이를 읽는다',
   in_array('출석 통합', $titles, true) && in_array('성적부 연동', $titles, true),
   json_encode($titles, JSON_UNESCAPED_UNICODE));
ok('짧은 꼬리표는 버린다', !in_array('범위', $titles, true));
ok('날짜만 있는 줄은 버린다', !in_array('2026-03-02', $titles, true));

// =====================================================================
echo "\n[18] 분야 추정\n";
$g = $ex->guessDomains('온라인 출석부 통합', '', $dt);
ok('출석은 attendance', array_key_first($g) === 'attendance', json_encode($g));
$g = $ex->guessDomains('지난 학기 데이터 마이그레이션', '', $dt);
ok('여러 분야가 걸리면 가중치로 가린다', count($g) > 1 && abs(array_sum($g) - 1.0) < 0.01,
   json_encode($g));
ok('걸리는 것이 없으면 빈 배열', $ex->guessDomains('zzzz', '', $dt) === []);
ok('빈 글자도 터지지 않는다', $ex->guessDomains('', '', $dt) === []);

// =====================================================================
echo "\n[19] 초안 트리 만들기\n";
$flat = [
    ['title' => 'A', 'depth' => 1],
    ['title' => 'A1', 'depth' => 2],
    ['title' => 'A1a', 'depth' => 3],
    ['title' => 'A2', 'depth' => 2],
    ['title' => 'B', 'depth' => 1],
];
$tree = $ex->toDraftTree($flat, $dt);
ok('뿌리 2개', count($tree) === 2);
ok('A 의 자식 2개', count($tree[0]['children']) === 2, (string)count($tree[0]['children']));
ok('A1 의 자식 1개', count($tree[0]['children'][0]['children']) === 1);
ok('A2 는 자식 없음', count($tree[0]['children'][1]['children']) === 0);
ok('전부 초안 표시', $tree[0]['origin'] === 'auto' && $tree[0]['confirmed'] === false);

// 상위 없이 시작하거나 단계를 건너뛰어도 버리지 않는다
$tree = $ex->toDraftTree([
    ['title' => '갑자기 3단계', 'depth' => 3],
    ['title' => '그 다음', 'depth' => 2],
], $dt);
ok('상위 없는 항목을 끌어올린다', count($tree) === 1 && $tree[0]['title'] === '갑자기 3단계',
   json_encode(array_column($tree, 'title'), JSON_UNESCAPED_UNICODE));
ok('그 아래에 붙는다', count($tree[0]['children']) === 1);
$n = 0;
$cnt = function ($ns) use (&$cnt, &$n) { foreach ($ns as $x) { $n++; $cnt($x['children']); } };
$cnt($tree);
ok('아무것도 잃지 않는다', $n === 2, (string)$n);
ok('빈 입력은 빈 트리', $ex->toDraftTree([], $dt) === []);

echo "\n" . str_repeat('=', 29) . "\n통과 $pass / 실패 $fail\n\n";
exit($fail > 0 ? 1 : 0);
