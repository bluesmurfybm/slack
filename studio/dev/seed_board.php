<?php
/** 대시보드를 눌러 볼 수 있게 배정을 확정하고 진행상황 몇 건을 남긴다. */

declare(strict_types=1);

/* ┌──────────────────────────────────────────────────────────────────┐
   │ 대시보드는 **확정된 배정안**이 있어야 그려진다. 시드만 깔면       │
   │ 비어 있어서 무엇이 되는지 볼 수 없다. 이 스크립트가 그 앞까지     │
   │ 밀어 준다 — 산출 → 확정 → 진행상황 몇 건.                        │
   │                                                                  │
   │   php assign/dev/seed_board.php          (기본: 1번 프로젝트)     │
   └──────────────────────────────────────────────────────────────────┘ */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('명령줄에서만 실행할 수 있습니다.');
}

require dirname(__DIR__) . '/inc/bootstrap.php';
foreach (['ProjectRepo', 'TaskRepo', 'MemberRepo', 'AllocationRepo', 'ProgressRepo'] as $r) {
    require dirname(__DIR__) . "/inc/repo/$r.php";
}
require dirname(__DIR__) . '/inc/service/AvailabilityCalculator.php';
require dirname(__DIR__) . '/inc/service/AllocationEngine.php';

$cfg = require dirname(__DIR__, 2) . '/config.php';
if (!in_array($cfg['db']['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "[중단] config.php 의 DB 가 로컬이 아닙니다.\n");
    exit(2);
}

$pdo      = ba_db();
$projects = new ProjectRepo($pdo);
$tasks    = new TaskRepo($pdo);
$members  = new MemberRepo($pdo);
$allocs   = new AllocationRepo($pdo);
$avail    = new AvailabilityCalculator($pdo);
$progress = new ProgressRepo($pdo);
$engine   = new AllocationEngine($tasks, $members, $allocs, $avail, $projects);

$pid   = (int)($argv[1] ?? 1);
$actor = ['id' => 'seed_board.php', 'name' => '시드'];

$p = $projects->find($pid);
if (!$p) {
    fwrite(STDERR, "프로젝트 #$pid 가 없습니다. 먼저 seed_dev.sql 을 적재하세요.\n");
    exit(2);
}

// 이전 것을 치우고 다시 만든다. 여러 번 돌려도 같은 상태가 되게.
$pdo->prepare(
    'DELETE pr FROM ba_progress pr JOIN ba_task t ON t.id = pr.task_id WHERE t.project_id = ?'
)->execute([$pid]);
$pdo->prepare(
    "DELETE w FROM ba_workload w
       JOIN ba_allocation_item i ON i.id = w.ref_id
       JOIN ba_task t            ON t.id = i.task_id
      WHERE w.kind = 'assigned' AND w.ref_type = 'allocation_item' AND t.project_id = ?"
)->execute([$pid]);
$pdo->prepare('DELETE FROM ba_allocation WHERE project_id = ?')->execute([$pid]);
$pdo->prepare("DELETE FROM ba_notification WHERE ref_type IN ('allocation','progress','progress_blocker')")
    ->execute();

$confirmed = $tasks->confirmedForAllocation($pid);
if (!$confirmed) {
    fwrite(STDERR, "확정된 태스크가 없습니다. seed_dev.sql 이 적재돼 있는지 확인하세요.\n");
    exit(2);
}

// --- 산출 → 확정 -------------------------------------------------------
$r   = $engine->propose($pid, []);
$aid = $allocs->createVersion($pid, [
    'weights' => $r['meta']['weights'], 'eval_ver' => $r['meta']['eval_ver'],
], $actor);
$allocs->saveItems($aid, $r['items']);
$allocs->confirm($aid, $actor);

echo "프로젝트 #$pid ({$p['name']}) 배정 확정 — 항목 " . count($r['items']) . "건\n";

// --- 진행상황 몇 건 ------------------------------------------------------
$items = $allocs->items($aid);
$samples = [
    ['status' => 'doing',  'progress_pct' => 40, 'content' => '화면 골격까지 끝냈습니다.'],
    ['status' => 'review', 'progress_pct' => 80, 'content' => '리뷰 요청드립니다.',
     'blocker' => '성적부 연동 스펙이 아직 안 나왔습니다.'],
];
$n = 0;
foreach ($items as $i => $it) {
    $s = $samples[$i % count($samples)];
    $progress->create((int)$it['task_id'], (int)$it['member_id'], $s + ['worked_on' => date('Y-m-d')]);
    $n++;
    if ($n >= 2) {
        break;
    }
}
echo "진행상황 {$n}건을 남겼습니다(하나는 블로커 포함).\n";

// --- 지연이 보이도록 기한 하나를 과거로 ----------------------------------
$late = $items[0]['task_id'] ?? null;
if ($late) {
    $pdo->prepare('UPDATE ba_task SET plan_end = ? WHERE id = ?')
        ->execute([date('Y-m-d', strtotime('-10 days')), $late]);
    echo "지연이 보이도록 태스크 1건의 기한을 10일 전으로 당겼습니다.\n";
}

echo "\n대시보드에서 확인하세요:  /assign/index.php\n";
// 알림은 API 층(api/progress.php)이 접수한다. 이 스크립트는 리포지토리를
// 직접 부르므로 알림이 쌓이지 않는다 — 화면에서 한 건 올려 보면 쌓인다.
echo "알림 적재함은 비어 있습니다. 화면에서 진행상황을 올리면 그때 쌓입니다
";
echo "(보내는 경로는 아직 없습니다 — sql/007_migration_notify_outbox.sql 참고).
";
