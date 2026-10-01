<?php
/**
 * 대시보드 API — 프로젝트 카드, 칸반·간트, 담당자별 현황, 지연 (명세서 §7.1 Step4).
 *
 * GET api/dashboard.php?act=projects              진행 중 프로젝트 카드
 * GET api/dashboard.php?act=board&project_id=1    칸반·간트·담당자·지연·피드
 * GET api/dashboard.php?act=mine                  내가 맡은 것만
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 질의 수를 태스크 수와 무관하게 유지한다                            │
 * │                                                                  │
 * │ 태스크가 수백 건이어도 질의 수는 고정이다. 태스크마다 한 번씩     │
 * │ 물으면 화면 하나에 수백 번이 나간다.                              │
 * │                                                                  │
 * │ 지키는 방법은 하나뿐이다 — **목록을 먼저 받고, 그 id 들로 IN(…)  │
 * │ 질의를 한 번씩 더 던진 뒤, 짝짓기는 PHP 에서 한다.**              │
 * │ 반복문 안에서 리포지토리를 부르지 말 것.                          │
 * │                                                                  │
 * │ 실제 질의 수는 dev/query_count.php 로 잰다.                       │
 * └──────────────────────────────────────────────────────────────────┘
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once BS_ROOT . '/inc/repo/ProjectRepo.php';
require_once BS_ROOT . '/inc/repo/TaskRepo.php';
require_once BS_ROOT . '/inc/repo/MemberRepo.php';
require_once BS_ROOT . '/inc/repo/AllocationRepo.php';
require_once BS_ROOT . '/inc/repo/ProgressRepo.php';

$pdo      = bs_db();
$projects = new ProjectRepo($pdo);
$tasks    = new TaskRepo($pdo);
$members  = new MemberRepo($pdo);
$allocs   = new AllocationRepo($pdo);
$progress = new ProgressRepo($pdo);

bs_route(bs_param_str('act', 'projects'), [

    /**
     * 진행 중 프로젝트 카드.
     *
     * 질의 5회 — 프로젝트 목록 / 진척률 / 지연 건수 / 확정 배정안 / 인원 수.
     * 프로젝트가 몇 개든 이 수는 그대로다.
     */
    'projects' => function () use ($pdo, $projects, $progress): void {
        bs_require_login_api();

        $rows = bs_dash_projects($projects);
        if (!$rows) {
            bs_json_ok(['rows' => [], 'today' => date('Y-m-d')]);
        }
        $ids = array_column($rows, 'id');

        $prog    = $progress->projectProgressMany($ids);      // 1
        $overdue = bs_dash_overdue_counts($pdo, $ids);        // 1
        $confirm = bs_dash_confirmed_map($pdo, $ids);         // 1
        $people  = bs_dash_people_counts($pdo, $ids);         // 1

        $out = [];
        foreach ($rows as $p) {
            $id = (int)$p['id'];
            $out[] = bs_dash_card($p, $prog[$id] ?? null, $overdue[$id] ?? 0,
                                  $confirm[$id] ?? null, $people[$id] ?? 0);
        }

        bs_json_ok(['rows' => $out, 'today' => date('Y-m-d')]);
    },

    /**
     * 한 프로젝트의 대시보드.
     *
     * **확정된 배정안이 없으면 보드를 그리지 않는다.** 초안을 대신
     * 보여 주면 확정되지도 않은 배정을 사실로 받아들이게 된다.
     */
    'board' => function () use ($pdo, $projects, $tasks, $members, $allocs, $progress): void {
        $me        = bs_require_login_api();
        $projectId = bs_dash_project_param($projects);
        $project   = $projects->find($projectId);

        $alloc = $allocs->confirmed($projectId);              // 1
        if ($alloc === null) {
            bs_json_ok([
                'project'    => bs_dash_project($project),
                'allocation' => null,
                'columns'    => bs_dash_empty_columns(),
                'by_member'  => [], 'overdue' => [], 'feed' => [], 'gantt' => [],
                'message'    => '확정된 배정안이 없습니다. 3단계에서 배정을 확정해야 '
                              . '대시보드에 나옵니다.',
            ]);
        }

        // --- 태스크와 배정을 한 번씩만 읽는다 ---------------------------
        $leaf  = bs_dash_leaf_tasks($pdo, $projectId);        // 1
        $items = $allocs->items((int)$alloc['id']);           // 1

        $taskIds = array_keys($leaf);
        $latest  = $progress->latestPerTask($taskIds);        // 1
        $feed    = $progress->feedByProject($projectId, 30);  // 1
        $overdue = $progress->overdueTasks($projectId);       // 1

        // --- 짝짓기는 여기서. DB 를 다시 타지 않는다 ---------------------
        $ownerOf  = [];
        $byMember = [];
        foreach ($items as $it) {
            $tid = (int)$it['task_id'];
            if ($it['role'] === 'owner') {
                $ownerOf[$tid] = $it;
            }
            $byMember[(int)$it['member_id']]['name']  = $it['emp_name'];
            $byMember[(int)$it['member_id']]['role']  = $it['role_label'];
            $byMember[(int)$it['member_id']]['tasks'][] = $tid;
        }

        $today     = date('Y-m-d');
        $overdueId = array_flip(array_column($overdue, 'id'));

        $cards = [];
        foreach ($leaf as $tid => $t) {
            $own = $ownerOf[$tid] ?? null;
            $cards[$tid] = [
                'task_id'      => $tid,
                'wbs_no'       => $t['wbs_no'],
                'title'        => $t['title'],
                'est_md'       => $t['est_md'] !== null ? (float)$t['est_md'] : null,
                'difficulty'   => $t['difficulty'] !== null ? (int)$t['difficulty'] : null,
                'status'       => $t['status'],
                'status_label' => BS_TASK_STATUS[$t['status']] ?? $t['status'],
                'progress_pct' => (int)$t['progress_pct'],
                'plan_start'   => $t['plan_start'],
                'plan_end'     => $t['plan_end'],
                'owner_id'     => $own ? (int)$own['member_id'] : null,
                'owner_name'   => $own['emp_name'] ?? null,
                'overdue'      => isset($overdueId[$tid]),
                'last'         => $latest[$tid] ?? null,
            ];
        }

        bs_json_ok([
            'project'    => bs_dash_project($project),
            'allocation' => [
                'id' => (int)$alloc['id'], 'version' => (int)$alloc['version'],
                'confirmed_at' => $alloc['confirmed_at'],
                'confirmed_by_name' => $alloc['confirmed_by_name'],
            ],
            'summary'   => $progress->projectProgress($projectId),   // 1
            'columns'   => bs_dash_columns($cards),
            'gantt'     => bs_dash_gantt($cards, $project),
            'by_member' => bs_dash_members($byMember, $cards),
            'overdue'   => $overdue,
            'feed'      => $feed,
            'me'        => bs_dash_me($members, $me, $byMember),     // 1
            'today'     => $today,
        ]);
    },

    /** 내가 맡은 태스크만. 개발자가 먼저 보는 화면. */
    'mine' => function () use ($pdo, $members, $progress): void {
        $me = bs_require_login_api();

        $mine = $members->findByUserId((string)$me['id']);            // 1
        if (!$mine) {
            bs_json_ok(['rows' => [], 'member' => null,
                'message' => '구성원 명단에 없어 배정받은 태스크를 찾을 수 없습니다.']);
        }

        $rows = bs_dash_my_tasks($pdo, (int)$mine['id']);             // 1
        $last = $progress->latestPerTask(array_column($rows, 'task_id')); // 1

        $today = date('Y-m-d');
        foreach ($rows as &$r) {
            $r['overdue'] = $r['plan_end'] !== null && $r['plan_end'] < $today
                         && !in_array($r['status'], BS_TASK_OVERDUE_EXEMPT, true);
            $r['last'] = $last[$r['task_id']] ?? null;
        }
        unset($r);

        bs_json_ok([
            'member' => ['id' => (int)$mine['id'], 'emp_name' => $mine['emp_name']],
            'rows'   => $rows,
            'today'  => $today,
        ]);
    },
]);


// =====================================================================
// 공통
// =====================================================================

function bs_dash_project_param(ProjectRepo $projects): int
{
    $id = bs_param_int('project_id', 0);
    if (!$id) {
        bs_json_error('MISSING_PARAM', '프로젝트 번호가 없습니다.', 400);
    }
    if (!$projects->find($id)) {
        bs_json_error('NOT_FOUND', '프로젝트를 찾을 수 없습니다.', 404);
    }
    return $id;
}

/** 카드에 올릴 프로젝트. 상태로 거른다. */
function bs_dash_projects(ProjectRepo $projects): array
{
    $r = $projects->search([
        'status' => BS_DASH_PROJECT_STATUS,
        'size'   => 50,
        'sort'   => 'deploy_date',
    ]);
    return $r['rows'] ?? [];
}

function bs_dash_project(array $p): array
{
    return [
        'id'          => (int)$p['id'],
        'code'        => $p['code'],
        'name'        => $p['name'],
        'client'      => $p['client'],
        'status'      => $p['status'],
        'status_label' => BS_PROJECT_STATUS[$p['status']] ?? $p['status'],
        'dev_start'   => $p['dev_start'],
        'dev_end'     => $p['dev_end'],
        'test_start'  => $p['test_start'],
        'test_end'    => $p['test_end'],
        'deploy_date' => $p['deploy_date'],
        'owner_name'  => $p['owner_name'],
    ];
}

/**
 * 프로젝트 카드 하나.
 *
 * D-day 는 운영 배포일 기준이고, 없으면 개발 종료일로 대신한다.
 * 무엇을 기준으로 셌는지 함께 돌려준다 — 날짜가 둘이면 화면이
 * 어느 쪽인지 말해야 한다.
 */
function bs_dash_card(array $p, ?array $prog, int $overdue, ?array $conf, int $people): array
{
    $base = $p['deploy_date'] ?: ($p['dev_end'] ?: null);
    $dday = null;
    if ($base !== null) {
        $d1 = new DateTimeImmutable(date('Y-m-d'));
        $d2 = new DateTimeImmutable($base);
        $dday = (int)$d1->diff($d2)->format('%r%a');
    }

    return bs_dash_project($p) + [
        'dday'        => $dday,
        'dday_base'   => $base,
        'dday_of'     => $p['deploy_date'] ? '운영 배포' : ($p['dev_end'] ? '개발 완료' : null),
        'progress'    => $prog,
        'overdue'     => $overdue,
        'people'      => $people,
        // 확정 전에는 null 이다. 화면이 "아직 확정 전" 을 그릴 수 있게.
        'allocation'  => $conf,
        'stage'       => bs_dash_stage($p),
    ];
}

/** 단계별 상태(개발/테스트/배포). 오늘이 어느 구간인지. */
function bs_dash_stage(array $p): array
{
    $today = date('Y-m-d');
    $mk = function (?string $from, ?string $to) use ($today): string {
        if ($from === null && $to === null) { return 'none'; }
        $end = $to ?: $from;
        if ($from !== null && $today < $from) { return 'before'; }
        if ($end !== null && $today > $end)   { return 'past'; }
        return 'now';
    };
    return [
        'dev'    => ['from' => $p['dev_start'],  'to' => $p['dev_end'],
                     'state' => $mk($p['dev_start'], $p['dev_end'])],
        'test'   => ['from' => $p['test_start'], 'to' => $p['test_end'],
                     'state' => $mk($p['test_start'], $p['test_end'])],
        'deploy' => ['from' => $p['deploy_date'], 'to' => $p['deploy_date'],
                     'state' => $mk($p['deploy_date'], $p['deploy_date'])],
    ];
}

/** 프로젝트별 지연 건수. 한 번의 질의로. */
function bs_dash_overdue_counts(PDO $pdo, array $ids): array
{
    if (!$ids) { return []; }
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $sph = implode(',', array_fill(0, count(BS_TASK_OVERDUE_EXEMPT), '?'));
    $st = $pdo->prepare(
        "SELECT t.project_id, COUNT(*) n
           FROM bs_task t
          WHERE t.project_id IN ($ph)
            AND t.confirmed = 1
            AND t.plan_end IS NOT NULL AND t.plan_end < ?
            AND t.status NOT IN ($sph)
            AND NOT EXISTS (SELECT 1 FROM bs_task c WHERE c.parent_id = t.id)
          GROUP BY t.project_id"
    );
    $st->execute(array_merge($ids, [date('Y-m-d')], BS_TASK_OVERDUE_EXEMPT));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['project_id']] = (int)$r['n'];
    }
    return $out;
}

/** 프로젝트별 확정 배정안. 확정 전이면 키가 없다. */
function bs_dash_confirmed_map(PDO $pdo, array $ids): array
{
    if (!$ids) { return []; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        "SELECT project_id, id, version, confirmed_at
           FROM bs_allocation
          WHERE project_id IN ($ph) AND status = 'confirmed'"
    );
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['project_id']] = [
            'id' => (int)$r['id'], 'version' => (int)$r['version'],
            'confirmed_at' => $r['confirmed_at'],
        ];
    }
    return $out;
}

/** 프로젝트별 참여 인원 수(확정 배정안 기준). */
function bs_dash_people_counts(PDO $pdo, array $ids): array
{
    if (!$ids) { return []; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        "SELECT a.project_id, COUNT(DISTINCT i.member_id) n
           FROM bs_allocation a
           JOIN bs_allocation_item i ON i.allocation_id = a.id
          WHERE a.project_id IN ($ph) AND a.status = 'confirmed'
          GROUP BY a.project_id"
    );
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['project_id']] = (int)$r['n'];
    }
    return $out;
}

/** 말단 태스크(확정된 것만). @return array<int, array> */
function bs_dash_leaf_tasks(PDO $pdo, int $projectId): array
{
    $st = $pdo->prepare(
        'SELECT t.id, t.wbs_no, t.title, t.est_md, t.difficulty, t.status,
                t.progress_pct, t.plan_start, t.plan_end
           FROM bs_task t
          WHERE t.project_id = ? AND t.confirmed = 1
            AND NOT EXISTS (SELECT 1 FROM bs_task c WHERE c.parent_id = t.id)
          ORDER BY t.wbs_no'
    );
    $st->execute([$projectId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['id']] = $r;
    }
    return $out;
}

/** 칸반 열. 명세서 §7.1 Step4 의 6개 + 보류는 따로. */
function bs_dash_columns(array $cards): array
{
    $cols = [];
    foreach (BS_KANBAN_COLUMNS as $k) {
        $cols[$k] = ['key' => $k, 'label' => BS_TASK_STATUS[$k] ?? $k, 'cards' => []];
    }
    // 보류는 명세서의 6열에 없다. 그렇다고 버리면 화면에서 사라져
    // 아무도 다시 안 본다. 따로 모아 끝에 둔다.
    $cols['hold'] = ['key' => 'hold', 'label' => BS_TASK_STATUS['hold'], 'cards' => []];

    foreach ($cards as $c) {
        $k = isset($cols[$c['status']]) ? $c['status'] : 'todo';
        $cols[$k]['cards'][] = $c;
    }
    foreach ($cols as &$col) {
        $col['count'] = count($col['cards']);
    }
    unset($col);
    return array_values($cols);
}

function bs_dash_empty_columns(): array
{
    return bs_dash_columns([]);
}

/** 간트용 — 기간이 있는 것만. 없으면 그릴 수 없다고 알린다. */
function bs_dash_gantt(array $cards, array $project): array
{
    $from = $project['dev_start'] ?: ($project['test_start'] ?: null);
    $to   = $project['deploy_date'] ?: ($project['test_end'] ?: ($project['dev_end'] ?: null));

    $bars = [];
    $noPeriod = 0;
    foreach ($cards as $c) {
        if ($c['plan_start'] === null || $c['plan_end'] === null) {
            $noPeriod++;
            continue;
        }
        $bars[] = [
            'task_id' => $c['task_id'], 'wbs_no' => $c['wbs_no'], 'title' => $c['title'],
            'from' => $c['plan_start'], 'to' => $c['plan_end'],
            'owner_name' => $c['owner_name'], 'progress_pct' => $c['progress_pct'],
            'overdue' => $c['overdue'], 'status' => $c['status'],
        ];
        if ($from === null || $c['plan_start'] < $from) { $from = $c['plan_start']; }
        if ($to === null   || $c['plan_end']   > $to)   { $to   = $c['plan_end']; }
    }
    return [
        'from' => $from, 'to' => $to, 'bars' => $bars,
        // 계획 기간이 없는 태스크를 조용히 빼지 않는다. 몇 건이 빠졌는지 말한다.
        'no_period' => $noPeriod,
    ];
}

/** 담당자별 카드 — 배정 수, 진행률, 지연 건. */
function bs_dash_members(array $byMember, array $cards): array
{
    $out = [];
    foreach ($byMember as $mid => $m) {
        $n = 0; $done = 0; $late = 0; $md = 0.0; $psum = 0.0; $wsum = 0.0;
        foreach (array_unique($m['tasks']) as $tid) {
            $c = $cards[$tid] ?? null;
            if ($c === null) { continue; }
            $n++;
            if ($c['status'] === 'done') { $done++; }
            if ($c['overdue']) { $late++; }
            $w = $c['est_md'] ?: 1.0;
            $md   += $c['est_md'] ?: 0.0;
            $wsum += $w;
            $psum += $w * $c['progress_pct'];
        }
        $out[] = [
            'member_id'   => $mid,
            'emp_name'    => $m['name'],
            'role_label'  => $m['role'] ?? null,
            'task_count'  => $n,
            'done_count'  => $done,
            'overdue'     => $late,
            'assigned_md' => round($md, 2),
            'progress_pct' => $wsum > 0 ? (int)round($psum / $wsum) : 0,
        ];
    }
    usort($out, static fn($a, $b) => ($b['overdue'] <=> $a['overdue'])
                                  ?: ($b['task_count'] <=> $a['task_count'])
                                  ?: ($a['member_id'] <=> $b['member_id']));
    return $out;
}

/** 지금 보는 사람이 이 프로젝트에서 맡은 것. 드로어를 열 수 있는지 판단에 쓴다. */
function bs_dash_me(MemberRepo $members, array $me, array $byMember): array
{
    $mine = $members->findByUserId((string)$me['id']);
    if (!$mine) {
        return ['member_id' => null, 'task_ids' => []];
    }
    $mid = (int)$mine['id'];
    return [
        'member_id' => $mid,
        'emp_name'  => $mine['emp_name'],
        'task_ids'  => array_values(array_unique($byMember[$mid]['tasks'] ?? [])),
    ];
}

/** 내가 맡은 태스크 — 확정된 배정안에서만. */
function bs_dash_my_tasks(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare(
        "SELECT t.id AS task_id, t.wbs_no, t.title, t.status, t.progress_pct,
                t.plan_start, t.plan_end, t.est_md, t.difficulty,
                p.id AS project_id, p.name AS project_name, p.code AS project_code,
                i.role
           FROM bs_allocation_item i
           JOIN bs_allocation a ON a.id = i.allocation_id AND a.status = 'confirmed'
           JOIN bs_task       t ON t.id = i.task_id
           JOIN bs_project    p ON p.id = t.project_id AND p.deleted_at IS NULL
          WHERE i.member_id = ?
          ORDER BY (t.plan_end IS NULL), t.plan_end, t.wbs_no"
    );
    $st->execute([$memberId]);
    return array_map(static function (array $r): array {
        $r['task_id']      = (int)$r['task_id'];
        $r['project_id']   = (int)$r['project_id'];
        $r['progress_pct'] = (int)$r['progress_pct'];
        $r['est_md']       = $r['est_md'] !== null ? (float)$r['est_md'] : null;
        $r['difficulty']   = $r['difficulty'] !== null ? (int)$r['difficulty'] : null;
        $r['status_label'] = BS_TASK_STATUS[$r['status']] ?? $r['status'];
        $r['role_name']    = BS_ALLOC_ROLE[$r['role']] ?? $r['role'];
        return $r;
    }, $st->fetchAll(PDO::FETCH_ASSOC));
}
