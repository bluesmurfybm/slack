<?php
/**
 * 배정안 API — 산출, 수동 조정, 확정.
 *
 * GET  api/allocate.php?act=versions&project_id=1
 * GET  api/allocate.php?act=detail&allocation_id=1
 * GET  api/allocate.php?act=current&project_id=1     확정본만 (대시보드용)
 * POST api/allocate.php?act=propose        배정안 산출 (version + 1)
 * POST api/allocate.php?act=update_item    담당자 수동 조정
 * POST api/allocate.php?act=add_item       지원 인원 추가
 * POST api/allocate.php?act=delete_item
 * POST api/allocate.php?act=confirm        확정 + 알림 적재
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 확정 전 배정안은 PM·관리자만 볼 수 있다.                          │
 * │ detail 은 확정본이 아니면 BS_CAP_ALLOCATION_PROPOSE 를 요구한다.  │
 * │ 대시보드가 쓰는 경로는 act=current 하나뿐이고, 그쪽은             │
 * │ AllocationRepo::confirmed() 만 본다 — 초안이 샐 구멍을 하나로 줄인다.│
 * └──────────────────────────────────────────────────────────────────┘
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once BS_ROOT . '/inc/repo/ProjectRepo.php';
require_once BS_ROOT . '/inc/repo/TaskRepo.php';
require_once BS_ROOT . '/inc/repo/MemberRepo.php';
require_once BS_ROOT . '/inc/repo/AllocationRepo.php';
require_once BS_ROOT . '/inc/service/AvailabilityCalculator.php';
require_once BS_ROOT . '/inc/service/AllocationEngine.php';
require_once BS_ROOT . '/inc/service/Notifier.php';

$pdo      = bs_db();
$projects = new ProjectRepo($pdo);
$tasks    = new TaskRepo($pdo);
$members  = new MemberRepo($pdo);
$allocs   = new AllocationRepo($pdo);
$avail    = new AvailabilityCalculator($pdo);
$engine   = new AllocationEngine($tasks, $members, $allocs, $avail, $projects);

bs_route(bs_param_str('act', 'versions'), [

    /**
     * 차수별 비교.
     *
     * 가중치를 바꿔 가며 몇 차례 돌려도 어느 쪽이 나은지 알려면 표를 하나씩
     * 열어 세어 봐야 했다. 무작위 배정을 대조군으로 만든 뜻도 비교할 자리가
     * 없으면 살지 않는다.
     */
    'compare' => function () use ($projects, $allocs, $members): void {
        bs_require_login_api();
        $projectId = bs_alloc_project_param($projects);
        bs_require_cap_api(BS_CAP_ALLOCATION_PROPOSE, $projectId);

        $vers  = $allocs->versions($projectId);
        $stats = $allocs->versionStats($projectId);

        // ┌──────────────────────────────────────────────────────────────┐
        // │ 가용 공수는 **차수마다 다르다** (2026-10-08)                  │
        // │                                                              │
        // │ 전에는 "기간 × 가용도라 차수와 무관" 이라 보고 한 번만 구했다.│
        // │ AIDD 계수와 참여 비중이 생기면서 그 말이 더는 참이 아니다 —   │
        // │ 같은 사람이 차수마다 다른 그릇을 가진다.                      │
        // │                                                              │
        // │ 다만 무거운 쪽(가용도 계산)은 **AIDD 계수별로 한 번만** 한다. │
        // │ 보통 한두 가지뿐이라 차수 수와 무관하게 끝난다.               │
        // └──────────────────────────────────────────────────────────────┘
        $mids = [];
        foreach ($stats as $s) {
            foreach (array_keys($s['by_member']) as $mid) { $mids[$mid] = true; }
        }
        [$from, $to] = bs_alloc_window($projectId);
        $availBy = [];                      // aidd 계수 => forMembers() 결과
        $calc    = ($mids && $from !== null && $to !== null)
                 ? new AvailabilityCalculator(bs_db()) : null;

        $rows = [];
        foreach ($vers as $v) {
            $s  = $stats[(int)$v['id']] ?? null;
            $p  = $v['params'] ?? [];
            $md = $s['md'] ?? 0.0;

            // 가용 공수를 넘긴 사람 수. 넘긴 채로 확정하는 것은 사람 판단이지만
            // 어느 안이 더 무리인지는 숫자로 보여야 한다.
            $load   = (float)($p['aidd']['load'] ?? 1.0);
            $ratio  = (float)($p['constraints']['capacity_ratio'] ?? 1.0);
            $shares = (array)($p['shares'] ?? []);
            if ($calc !== null && !isset($availBy[(string)$load])) {
                $availBy[(string)$load] = $calc->forMembers(array_keys($mids), $from, $to, $load);
            }
            $av = $availBy[(string)$load] ?? [];

            $over = 0;
            foreach (($s['by_member'] ?? []) as $mid => $m) {
                if (!isset($av[$mid])) { continue; }
                $c = AvailabilityCalculator::capacityMd(
                    $av[$mid], $ratio, (float)($shares[$mid]['share'] ?? 1.0));
                if ($c > 0 && $m > $c) { $over++; }
            }

            $rows[] = [
                'id'        => (int)$v['id'],
                'version'   => (int)$v['version'],
                'status'    => $v['status'],
                'status_label' => $v['status_label'] ?? $v['status'],
                'created_at'=> $v['created_at'],
                'method'    => $p['method'] ?? AllocationEngine::M_WEIGHTED,
                'level'     => $p['level']  ?? AllocationEngine::L_LEAF,
                'seed'      => $p['seed']   ?? null,
                'weights'   => $p['weights'] ?? null,
                // 같은 WBS 라도 AIDD 를 켜고 끄면 공수·가용도가 달라진다.
                // 안 보이면 두 차수를 같은 조건으로 오해한다.
                'aidd'      => !empty($p['aidd']['enabled']),
                'aidd_effort' => isset($p['aidd']['effort']) ? (float)$p['aidd']['effort'] : null,
                // 고른 후보 수. 안 골랐으면 null — '전원 대상' 이었다는 뜻이다.
                'picked'    => isset($p['member_ids']) && $p['member_ids']
                               ? count($p['member_ids']) : null,
                'items'     => $s['items']   ?? 0,
                'members'   => $s['members'] ?? 0,
                'avg_fit'   => $s['avg_fit'] ?? null,
                'md'        => $md,
                // 한 사람에게 몰린 비율. 이 화면에서 가장 먼저 보게 되는 숫자다.
                'top_share' => $md > 0 ? (int)round(($s['top_md'] ?? 0) / $md * 100) : 0,
                'over'      => $over,
                'orphans'   => count($allocs->tasksWithoutOwner((int)$v['id'])),
            ];
        }

        bs_json_ok(['rows' => $rows]);
    },

    'versions' => function () use ($projects, $allocs): void {
        bs_require_login_api();
        $projectId = bs_alloc_project_param($projects);

        $rows = $allocs->versions($projectId);
        $conf = null;
        foreach ($rows as $r) {
            if ($r['status'] === 'confirmed') { $conf = $r['version']; }
        }

        // 확정 전 배정안의 존재 자체는 알려도 되지만, 안을 열려면 권한이 필요하다.
        $canSee = bs_can(BS_CAP_ALLOCATION_PROPOSE, $projectId);

        bs_json_ok([
            'rows'              => $canSee
                ? $rows
                : array_values(array_filter($rows, static fn($r) => $r['status'] === 'confirmed')),
            'confirmed_version' => $conf,
            'can_propose'       => $canSee,
            'can_confirm'       => bs_can(BS_CAP_ALLOCATION_CONFIRM, $projectId),
            'hidden_drafts'     => $canSee ? 0
                : count(array_filter($rows, static fn($r) => $r['status'] !== 'confirmed')),
        ]);
    },

    'detail' => function () use ($allocs, $members, $tasks): void {
        bs_require_login_api();
        $a = bs_alloc_param($allocs);

        // 확정 전 배정안은 아무나 볼 수 없다. 초안이 사내에 돌면
        // 확정되지도 않은 배정을 사실로 받아들이는 사람이 생긴다.
        if ($a['status'] !== 'confirmed') {
            bs_require_cap_api(BS_CAP_ALLOCATION_PROPOSE, (int)$a['project_id']);
        }

        $items = $allocs->items((int)$a['id']);

        bs_json_ok([
            'allocation'  => $a,
            'items'       => $items,
            'load'        => bs_alloc_load($allocs, $members, $tasks, $a, $items),
            // 배정 대상이 아닌 상위 분류. 표에서 **자리만** 잡아 준다.
            'groups'      => $allocs->groupRows((int)$a['id']),
            'unassigned'  => $allocs->tasksWithoutOwner((int)$a['id']),
            'can_edit'    => $a['status'] !== 'confirmed' && $a['status'] !== 'archived'
                             && bs_can(BS_CAP_ALLOCATION_PROPOSE, (int)$a['project_id']),
            'can_confirm' => bs_can(BS_CAP_ALLOCATION_CONFIRM, (int)$a['project_id']),
        ]);
    },

    /**
     * 확정본만. 대시보드와 진행상황 화면의 입구다.
     * 확정 전에는 null 을 돌려준다 — 초안을 대신 보여 주지 않는다.
     */
    'current' => function () use ($projects, $allocs, $members, $tasks): void {
        bs_require_login_api();
        $projectId = bs_alloc_project_param($projects);

        $r = $allocs->confirmedWithItems($projectId);
        if ($r === null) {
            bs_json_ok([
                'allocation' => null, 'items' => [], 'load' => [],
                'message' => '확정된 배정안이 없습니다. 확정 전에는 대시보드에 나오지 않습니다.',
            ]);
        }
        bs_json_ok([
            'allocation' => $r['allocation'],
            'items'      => $r['items'],
            'load'       => bs_alloc_load($allocs, $members, $tasks, $r['allocation'], $r['items']),
        ]);
    },

    /**
     * 담당자로 고를 수 있는 사람. 배정 화면의 선택 상자가 쓴다.
     *
     * 점수를 함께 내려주지 않는다. 사람 목록에 점수를 붙이면 그 자체가
     * 전사 비교표가 된다(CLAUDE.md §1.4). 점수는 태스크별 근거에서만 본다.
     */
    'members' => function () use ($projects, $members): void {
        bs_require_login_api();
        $projectId = bs_alloc_project_param($projects);
        bs_require_cap_api(BS_CAP_ALLOCATION_PROPOSE, $projectId);

        $rows = array_map(static fn($m) => [
            'id'         => (int)$m['id'],
            'emp_name'   => $m['emp_name'],
            'role_label' => $m['role_label'],
            'team'       => $m['team'],
        ], $members->assignable());

        bs_json_ok(['rows' => $rows]);
    },

    /**
     * 배정안 산출.
     * 항상 새 버전(version + 1)을 만들고 status='proposed' 로 시작한다.
     */
    'propose' => function () use ($projects, $tasks, $members, $allocs, $engine): void {
        $user      = bs_begin_write();
        $projectId = bs_alloc_project_param($projects);
        bs_require_cap_api(BS_CAP_ALLOCATION_PROPOSE, $projectId);

        // 확정된 태스크가 하나도 없으면 여기서 막는다.
        // "WBS 를 먼저 확정하세요" 로 돌려보내는 편이 친절하다.
        if (!$tasks->confirmedForAllocation($projectId)) {
            bs_json_error('NO_TASKS',
                '확정된 태스크가 없습니다. 3단계에서 WBS 를 확정해야 배정 대상이 됩니다.', 400);
        }

        $params = [
            'weights'     => bs_alloc_assoc('weights'),
            'constraints' => bs_alloc_assoc('constraints'),
            'member_ids'  => array_map('intval', bs_param_array('member_ids')),
            // 배정 방식. 모르는 값은 엔진이 가중치로 돌린다 — 오타 하나로
            // 배정 방식이 바뀌면 안 된다.
            'method'      => bs_param_str('method'),
            // 씨앗을 주면 같은 무작위 결과를 다시 만든다. 비우면 새로 뽑고,
            // 뽑은 값은 params_json 에 남아 나중에 재현할 수 있다.
            'seed'        => bs_param_str('seed'),
            // 배정 단위. 말단까지(기본) / 대분류 / 중분류 / 자동.
            'level'       => bs_param_str('level'),
        ];

        // 고른 후보에게 최소 1건. 안 보내면 엔진이 정한다 —
        // **후보를 골랐을 때만 기본으로 켜진다.**
        if (bs_has_param('min_one')) {
            $params['min_one'] = (bool)bs_param_int('min_one', 0);
        }
        // AIDD 는 프로젝트 설정을 따르되 차수별로 끌 수 있다. 끈 차수를
        // 하나 만들어 [차수 비교] 로 견주면 이 옵션이 얼마나 바꾸는지
        // 숫자로 보인다.
        if (bs_has_param('aidd')) {
            $params['aidd'] = (bool)bs_param_int('aidd', 1);
        }

        // 이전 안에서 사람이 손댄 항목을 그대로 가져올지.
        $keepId = bs_param_int('keep_manual_from', 0);
        if ($keepId) {
            $prev = $allocs->find($keepId);
            if (!$prev || (int)$prev['project_id'] !== $projectId) {
                bs_json_error('NOT_FOUND', '이어받을 배정안을 찾을 수 없습니다.', 404);
            }
            $pinned = [];
            foreach ($allocs->items($keepId) as $it) {
                if ($it['is_manual'] && $it['role'] === 'owner') {
                    $pinned[(int)$it['task_id']] = (int)$it['member_id'];
                }
            }
            $params['pinned'] = $pinned;
        }

        $r = $engine->propose($projectId, $params);

        // 엔진이 쓴 eval_ver 를 반드시 기록한다. 나중에 "왜 이렇게 배정됐나" 를
        // 되짚으려면 어느 역량 스냅샷을 봤는지 알아야 한다.
        $allocationId = $allocs->createVersion($projectId, [
            'weights'     => $r['meta']['weights'],
            'constraints' => $r['meta']['constraints'],
            'member_ids'  => $params['member_ids'],
            'eval_ver'    => $r['meta']['eval_ver'],
            'engine_ver'  => $r['meta']['engine_ver'],
            'pinned_from' => $keepId ?: null,
            // ★ 무작위로 뽑았다면 **씨앗이 있어야 재현된다.** 재현 안 되는
            //   배정안은 "왜 이 사람이죠?" 에 답할 수 없다.
            //   method 는 나중에 실적을 되짚을 때도 쓴다 — 무작위로 붙은
            //   일이 역량 점수의 근거가 됐는지 알아볼 수 있어야 한다.
            'method'      => $r['meta']['method'],
            'seed'        => $r['meta']['seed'],
            // 어느 단위로 묶었는지. 같은 WBS 라도 단위가 다르면 다른 안이다.
            'level'       => $r['meta']['level'],
            // 최소 보장을 걸었는지. 안 적어 두면 같은 가중치로 다시 돌렸을
            // 때 왜 결과가 다른지 설명할 수 없다.
            'min_one'     => (bool)($r['meta']['min_one']['enabled'] ?? false),
            // 계수까지 통째로 남긴다. 프로젝트 설정이 나중에 바뀌어도
            // 이 차수는 그때 값으로 설명된다.
            'aidd'        => $r['meta']['aidd'] ?? null,
            // 막대의 분모도 **그때 비중**으로 그려야 한다. 오늘 비중을 바꿨다고
            // 지난 차수의 막대가 흔들리면 그 차수를 설명할 수 없다.
            'shares'      => $r['meta']['shares'] ?? [],
        ], $user);

        $allocs->saveItems($allocationId, $r['items']);

        $a     = $allocs->find($allocationId);
        $items = $allocs->items($allocationId);

        bs_json_ok([
            'allocation_id' => $allocationId,
            'version'       => (int)$a['version'],
            'status'        => $a['status'],
            'allocation'    => $allocs->versions($projectId)[0] ?? null,
            'items'         => $items,
            'load'          => bs_alloc_load($allocs, $members, $tasks, $a, $items),
            'unassigned'    => $r['unassigned'],
            'meta'          => $r['meta'],
            'message'       => '배정안 ' . $a['version'] . '차를 만들었습니다. 검토 후 확정하세요.'
                . ($r['meta']['level'] !== AllocationEngine::L_LEAF
                    ? ' ' . AllocationEngine::LEVEL_LABEL[$r['meta']['level']] . ' 로 묶었습니다.'
                    : '')
                . ($r['meta']['method'] !== AllocationEngine::M_WEIGHTED
                    ? ' ' . AllocationEngine::METHOD_LABEL[$r['meta']['method']]
                      . ' 로 뽑았습니다(씨앗 ' . $r['meta']['seed'] . ').'
                    : '')
                . ($r['meta']['min_one']['moved'] ?? []
                    ? ' 0건이던 후보 ' . count($r['meta']['min_one']['moved'])
                      . '명에게 한 건씩 넘겼습니다.'
                    : '')
                . ($r['meta']['min_one']['failed'] ?? []
                    ? ' 후보 ' . count($r['meta']['min_one']['failed'])
                      . '명은 한 건도 주지 못했습니다.'
                    : '')
                . ($r['unassigned']
                    ? ' 담당자를 못 정한 태스크가 ' . count($r['unassigned']) . '건 있습니다.'
                    : ''),
        ]);
    },

    /**
     * 담당자 수동 조정.
     * is_manual=1 과 사유를 반드시 남긴다 — 나중에 왜 바꿨는지 알아야 한다.
     */
    'update_item' => function () use ($allocs, $members, $tasks): void {
        $user = bs_begin_write();
        $itemId = bs_param_int('item_id', 0);
        if (!$itemId) {
            bs_json_error('MISSING_PARAM', '배정 항목 번호가 없습니다.', 400);
        }
        $cur = $allocs->findItem($itemId);
        if (!$cur) {
            bs_json_error('NOT_FOUND', '배정 항목을 찾을 수 없습니다.', 404);
        }
        $projectId = (int)$cur['project_id'];
        bs_require_cap_api(BS_CAP_ALLOCATION_PROPOSE, $projectId);

        // 사유 없는 변경은 나중에 설명할 수 없다. 담당자를 바꿀 때는 받는다.
        $note      = bs_param_str('manual_note');
        $newMember = bs_has_param('member_id') ? bs_param_int('member_id', 0) : null;
        if ($newMember !== null && $newMember !== (int)$cur['member_id'] && $note === '') {
            bs_json_error('MISSING_PARAM',
                '담당자를 바꾸려면 사유를 적어 주세요. 나중에 왜 바꿨는지 알아야 합니다.', 400);
        }

        $data = ['manual_note' => $note];
        foreach (['member_id' => 'int', 'role' => 'str', 'alloc_ratio' => 'float'] as $k => $t) {
            if (!bs_has_param($k)) {
                continue;
            }
            $data[$k] = match ($t) {
                'int'   => bs_param_int($k, 0),
                'float' => (float)bs_param_str($k, '1.0'),
                default => bs_param_str($k),
            };
        }

        $allocs->updateItem($itemId, $data, $user);

        $a     = $allocs->find((int)$cur['allocation_id']);
        $items = $allocs->items((int)$a['id']);
        bs_json_ok([
            'item_id'    => $itemId,
            'allocation' => $a,
            'items'      => $items,
            'load'       => bs_alloc_load($allocs, $members, $tasks, $a, $items),
            'unassigned' => $allocs->tasksWithoutOwner((int)$a['id']),
            'message'    => '배정을 조정했습니다.',
        ]);
    },

    /** 지원 인원 추가(수동). */
    'add_item' => function () use ($allocs, $members, $tasks): void {
        $user = bs_begin_write();
        $allocationId = bs_param_int('allocation_id', 0);
        $taskId       = bs_param_int('task_id', 0);
        $memberId     = bs_param_int('member_id', 0);
        if (!$allocationId || !$taskId || !$memberId) {
            bs_json_error('MISSING_PARAM', '배정안·태스크·구성원을 모두 지정하세요.', 400);
        }
        $a = $allocs->find($allocationId);
        if (!$a) {
            bs_json_error('NOT_FOUND', '배정안을 찾을 수 없습니다.', 404);
        }
        bs_require_cap_api(BS_CAP_ALLOCATION_PROPOSE, (int)$a['project_id']);

        $role = bs_param_str('role', 'support');

        // 담당(owner)은 태스크당 한 명이다(명세서 §6.2). 이미 있으면 막는다.
        if ($role === 'owner') {
            foreach ($allocs->items($allocationId) as $it) {
                if ((int)$it['task_id'] === $taskId && $it['role'] === 'owner') {
                    bs_json_error('CONFLICT',
                        '이 태스크에는 이미 담당자가 있습니다. 바꾸려면 그 항목을 수정하세요.', 409);
                }
            }
        }

        $id = $allocs->addItem($allocationId, [
            'task_id'     => $taskId,
            'member_id'   => $memberId,
            'role'        => $role,
            'alloc_ratio' => (float)bs_param_str('alloc_ratio', '1.0'),
            'manual_note' => bs_param_str('manual_note'),
        ], $user);

        $a     = $allocs->find($allocationId);
        $items = $allocs->items($allocationId);
        bs_json_ok([
            'item_id'    => $id,
            'allocation' => $a,
            'items'      => $items,
            'load'       => bs_alloc_load($allocs, $members, $tasks, $a, $items),
            'unassigned' => $allocs->tasksWithoutOwner($allocationId),
            'message'    => '배정 항목을 추가했습니다.',
        ]);
    },

    'delete_item' => function () use ($allocs, $members, $tasks): void {
        bs_begin_write();
        $itemId = bs_param_int('item_id', 0);
        if (!$itemId) {
            bs_json_error('MISSING_PARAM', '배정 항목 번호가 없습니다.', 400);
        }
        $cur = $allocs->findItem($itemId);
        if (!$cur) {
            bs_json_error('NOT_FOUND', '배정 항목을 찾을 수 없습니다.', 404);
        }
        bs_require_cap_api(BS_CAP_ALLOCATION_PROPOSE, (int)$cur['project_id']);

        $allocationId = (int)$cur['allocation_id'];
        $allocs->deleteItem($itemId);

        $a     = $allocs->find($allocationId);
        $items = $allocs->items($allocationId);
        bs_json_ok([
            'allocation' => $a,
            'items'      => $items,
            'load'       => bs_alloc_load($allocs, $members, $tasks, $a, $items),
            'unassigned' => $allocs->tasksWithoutOwner($allocationId),
            'message'    => '배정 항목을 삭제했습니다.',
        ]);
    },

    /**
     * 확정.
     *
     * ┌──────────────────────────────────────────────────────────────┐
     * │ 여기가 유일한 확정 경로다 (CLAUDE.md).                        │
     * │ 다른 act 에서 status 를 confirmed 로 바꾸지 말 것.            │
     * │                                                              │
     * │ 확정 시 함께 일어나는 일:                                     │
     * │   1. confirmed_by / confirmed_at 기록                        │
     * │   2. 같은 프로젝트의 다른 버전을 archived 로                  │
     * │   3. bs_workload 에 kind='assigned' 점유 생성                │
     * │   (1~3 은 AllocationRepo::confirm() 이 한 트랜잭션으로)       │
     * │   4. 알림 적재 — **트랜잭션 바깥**이다. 슬랙이 죽었다고       │
     * │      확정이 롤백되면 안 된다.                                 │
     * └──────────────────────────────────────────────────────────────┘
     */
    'confirm' => function () use ($projects, $allocs, $members, $tasks, $avail, $pdo): void {
        $user         = bs_begin_write();
        $allocationId = bs_param_int('allocation_id', 0);
        if (!$allocationId) {
            bs_json_error('MISSING_PARAM', '배정안 번호가 없습니다.', 400);
        }
        $a = $allocs->find($allocationId);
        if (!$a) {
            bs_json_error('NOT_FOUND', '배정안을 찾을 수 없습니다.', 404);
        }
        $projectId = (int)$a['project_id'];
        bs_require_cap_api(BS_CAP_ALLOCATION_CONFIRM, $projectId);

        // ┌──────────────────────────────────────────────────────────────┐
        // │ 확정할 수 없는 안에는 경고하지 않는다 (2026-10-08)            │
        // │                                                              │
        // │ 과배정 경고가 상태 확인보다 먼저 있어서, **이미 확정된 안**을 │
        // │ 다시 확정하려 하면 "이미 확정됐습니다" 대신 "가용 공수를      │
        // │ 넘겼습니다" 가 떴다. 사람이 고칠 수 없는 것을 고치라고 하는   │
        // │ 셈이다.                                                      │
        // │                                                              │
        // │ 규칙을 두 벌로 두지 않는다 — 여기서는 **경고를 건너뛰기만**   │
        // │ 하고, 막는 일은 그대로 confirm() 이 한다.                     │
        // └──────────────────────────────────────────────────────────────┘
        $settled = in_array($a['status'], ['confirmed', 'archived'], true);

        $items = $allocs->items($allocationId);
        $load  = bs_alloc_load($allocs, $members, $tasks, $a, $items);

        // 과배정은 **막지 않고 경고한다.** 기간이 짧아 넘치는 것은 흔하고,
        // 그걸 아는 채로 밀어붙이는 판단은 사람 몫이다. 다만 모르고
        // 넘어가지는 않게 confirm=1 을 한 번 더 받는다.
        $over = array_values(array_filter($load, static fn($l) => !empty($l['over'])));
        if (!$settled && $over && bs_param_int('accept_overload', 0) !== 1) {
            bs_json_error('OVERLOAD',
                '가용 공수를 넘긴 사람이 있습니다:' . "\n · "
                . implode("\n · ", array_map(
                    static fn($l) => $l['emp_name'] . ' ' . $l['assigned_md']
                                   . ' / ' . $l['capacity_md'] . ' M/D', $over))
                . "\n그래도 확정하려면 다시 눌러 주세요.", 409);
        }

        // 1~3
        $allocs->confirm($allocationId, $user);

        // 4 — 여기서 실패해도 확정은 이미 끝났다.
        $notifier = new OutboxNotifier($pdo);
        $notice   = bs_alloc_notices($projects, $members, $allocs, $projectId, $allocationId);
        $sent     = $notifier->send($notice);

        bs_json_ok([
            'allocation_id' => $allocationId,
            'status'        => 'confirmed',
            'allocation'    => $allocs->find($allocationId),
            'notify'        => $sent + ['notifier' => $notifier->name()],
            'message'       => '배정안을 확정했습니다.'
                . ($sent['queued'] ? ' 알림 ' . $sent['queued'] . '건을 보낼 목록에 넣었습니다.' : '')
                . ($sent['skipped'] ? ' ' . $sent['skipped'] . '건은 받을 주소가 없어 빠졌습니다.' : ''),
            // 보냈다고 말하지 않는다. 아직 내보내는 경로가 없다.
            'notify_notice' => '알림은 적재만 된 상태입니다. 실제 발송 경로는 아직 없습니다'
                             . '(sql/007_migration_notify_outbox.sql 의 설명 참고).',
        ]);
    },
]);


// =====================================================================
// 공통
// =====================================================================

function bs_alloc_project_param(ProjectRepo $projects): int
{
    $projectId = bs_param_int('project_id', 0);
    if (!$projectId) {
        bs_json_error('MISSING_PARAM', '프로젝트 번호가 없습니다.', 400);
    }
    if (!$projects->find($projectId)) {
        bs_json_error('NOT_FOUND', '프로젝트를 찾을 수 없습니다.', 404);
    }
    return $projectId;
}

function bs_alloc_param(AllocationRepo $allocs): array
{
    $id = bs_param_int('allocation_id', 0);
    if (!$id) {
        bs_json_error('MISSING_PARAM', '배정안 번호가 없습니다.', 400);
    }
    $a = $allocs->find($id);
    if (!$a) {
        bs_json_error('NOT_FOUND', '배정안을 찾을 수 없습니다.', 404);
    }
    // find() 는 원본 행이라 화면이 쓰는 모양으로 한 번 더 감싼다.
    foreach ($allocs->versions((int)$a['project_id']) as $v) {
        if ((int)$v['id'] === $id) {
            return $v;
        }
    }
    return $a;
}

/** JSON 으로 온 연관배열 파라미터. 스칼라가 오면 무시한다. */
function bs_alloc_assoc(string $key): array
{
    $v = bs_param($key, []);
    return is_array($v) ? $v : [];
}

/**
 * 사람별 부하. 화면의 막대가 쓴다.
 *
 * 가용 공수는 **배정 당시 기준**이 아니라 지금 다시 계산한다. 그래야
 * 수동 조정 뒤에도 막대가 맞는다.
 */
/**
 * 가용도를 재는 기간. 배정안이 저장해 둔 것을 쓰지 않는다 — 프로젝트 기간이
 * 바뀌면 막대도 따라 바뀌어야 한다.
 *
 * 부하 막대와 차수 비교가 **같은 기간**을 봐야 숫자가 어긋나지 않는다.
 *
 * @return array{0:?string, 1:?string}
 */
function bs_alloc_window(int $projectId): array
{
    $q = bs_db()->prepare('SELECT dev_start, dev_end, test_start, test_end, deploy_date
                             FROM bs_project WHERE id = ?');
    $q->execute([$projectId]);
    $p = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        $p['dev_start']   ?: ($p['test_start'] ?: null),
        $p['deploy_date'] ?: ($p['test_end'] ?: ($p['dev_end'] ?: null)),
    ];
}

function bs_alloc_load(AllocationRepo $allocs, MemberRepo $members, TaskRepo $tasks,
                       array $allocation, array $items): array
{
    $byMember = $allocs->loadByMember((int)$allocation['id']);

    // ┌──────────────────────────────────────────────────────────────────┐
    // │ 0건인 후보도 줄을 받는다 (2026-10-07)                             │
    // │                                                                  │
    // │ 전에는 배정 항목이 있는 사람만 그렸다. 그래서 **고른 후보가 한    │
    // │ 건도 못 받으면 막대에서 아예 사라졌다** — 쏠렸다는 사실을 보려면  │
    // │ 후보 화면으로 돌아가 사람 수를 세어야 했다. 최소 1건 보장이       │
    // │ 실패한 자리가 바로 여기라 반드시 보여야 한다.                     │
    // └──────────────────────────────────────────────────────────────────┘
    $p = $allocation['params'] ?? (isset($allocation['params_json'])
         ? json_decode((string)$allocation['params_json'], true) : null);
    foreach ((array)($p['member_ids'] ?? []) as $mid) {
        $mid = (int)$mid;
        if ($mid && !isset($byMember[$mid])) {
            $byMember[$mid] = ['md' => 0.0, 'items' => 0, 'owner' => 0];
        }
    }
    if (!$byMember) {
        return [];
    }

    $ids = array_keys($byMember);
    $cap = [];

    // 기간은 배정안이 저장해 둔 것을 쓰지 않는다 — 프로젝트 기간이 바뀌면
    // 막대도 따라 바뀌어야 한다.
    $pdo = bs_db();
    [$from, $to] = bs_alloc_window((int)$allocation['project_id']);

    // 그 배정안이 **산출될 때 쓴** 계수로 본다. 프로젝트 설정을 나중에
    // 바꿔도 지난 차수의 막대가 흔들리면 안 된다 — 그 차수는 그때 숫자로
    // 만들어진 것이다.
    $pa = $allocation['params'] ?? (isset($allocation['params_json'])
          ? json_decode((string)$allocation['params_json'], true) : null);
    $aiddLoad = (float)($pa['aidd']['load'] ?? 1.0);

    // ★ 엔진과 **같은 식**으로 센다. 전에는 비중도 capacity_ratio 도 빠져
    //   있어, 비중 0.3 인 사람이 "9 / 35.78 (25%)" 로 여유로워 보였다.
    $ratio  = (float)($pa['constraints']['capacity_ratio'] ?? 1.0);
    $shares = (array)($pa['shares'] ?? []);

    $peak = [];
    if ($from !== null && $to !== null && $from <= $to) {
        $calc = new AvailabilityCalculator($pdo);
        foreach ($calc->forMembers($ids, $from, $to, $aiddLoad) as $mid => $a) {
            $cap[$mid] = AvailabilityCalculator::capacityMd(
                $a, $ratio, (float)($shares[$mid]['share'] ?? 1.0));
            // 가용 공수가 넉넉해도 그 공수가 **특정 달에 몰려 있을 수** 있다.
            // 10월에 115% 찬 사람에게 10월 일을 주면 막대는 74% 라고 하지만
            // 실제로는 불가능한 일정이다.
            $peak[$mid] = ['over_months' => $a['over_months'], 'peak_pct' => $a['peak_pct'],
                           'aidd_pct' => $a['aidd_pct'], 'aidd_on' => $a['aidd_on']];
        }
    }

    $out = [];
    foreach ($ids as $mid) {
        $m  = $members->find($mid);
        $md = $byMember[$mid]['md'];
        $c  = $cap[$mid] ?? 0.0;
        $out[] = [
            'member_id'   => $mid,
            'emp_name'    => $m['emp_name'] ?? ('#' . $mid),
            'role_label'  => $m['role_label'] ?? null,
            'assigned_md' => $md,
            'capacity_md' => $c,
            'load_pct'    => $c > 0 ? (int)round($md / $c * 100) : null,
            'task_count'  => $byMember[$mid]['items'],
            'owner_count' => $byMember[$mid]['owner'],
            'over'        => $c > 0 && $md > $c,
            'over_months' => $peak[$mid]['over_months'] ?? [],
            'peak_pct'    => $peak[$mid]['peak_pct'] ?? 0,
            // 0건인 사람이 왜 0건인지 말해 준다. 비중을 낮춘 사람은 최소
            // 보장 대상이 아니라 조용히 0건으로 남는다.
            'share'       => (float)($shares[$mid]['share'] ?? 1.0),
            'share_reason'=> $shares[$mid]['reason'] ?? null,
            // 가용 공수에 AIDD 가 얼마나 보태졌는지. 막대가 왜 늘었는지
            // 말해 주지 않으면 사람은 숫자가 틀렸다고 생각한다.
            'aidd_on'     => !empty($peak[$mid]['aidd_on']),
            'aidd_pct'    => $peak[$mid]['aidd_pct'] ?? 0,
        ];
    }
    usort($out, static fn($a, $b) => ($b['assigned_md'] <=> $a['assigned_md'])
                                  ?: ($a['member_id'] <=> $b['member_id']));
    return $out;
}

/**
 * 확정 알림 문안.
 *
 * 사람마다 자기가 맡은 것만 담는다. 남의 배정까지 보내면 그 자체가
 * 전사 비교표가 된다(CLAUDE.md 가 막는 것).
 *
 * @return Notice[]
 */
function bs_alloc_notices(ProjectRepo $projects, MemberRepo $members, AllocationRepo $allocs,
                          int $projectId, int $allocationId): array
{
    $p = $projects->find($projectId);
    $a = $allocs->find($allocationId);

    $byMember = [];
    foreach ($allocs->items($allocationId) as $it) {
        $byMember[(int)$it['member_id']][] = $it;
    }

    $out = [];
    foreach ($byMember as $mid => $items) {
        $m = $members->find($mid);
        if (!$m) {
            continue;
        }

        $lines = [];
        $lines[] = $p['name'] . ' 배정이 확정됐습니다 (' . $a['version'] . '차).';
        $lines[] = '';
        foreach ($items as $it) {
            $lines[] = sprintf('· %s %s [%s]%s%s',
                $it['wbs_no'] ?: '-', $it['task_title'], $it['role_name'],
                $it['est_md'] !== null ? ' ' . $it['est_md'] . ' M/D' : '',
                $it['plan_start'] ? ' (' . $it['plan_start'] . ' ~ ' . ($it['plan_end'] ?: '') . ')' : ''
            );
        }
        $lines[] = '';
        $lines[] = '자세한 내용은 BlueStudio 에서 확인하세요.';
        $body = implode("\n", $lines);

        $subject = '[' . $p['name'] . '] 배정 확정 (' . count($items) . '건)';

        // 슬랙과 메일 둘 다 접수한다. 어느 쪽이 살아 있을지 모른다.
        $out[] = new Notice('slack', $mid, (string)($m['slack_handle'] ?? ''),
                            $body, $subject, 'allocation', $allocationId);
        $out[] = new Notice('email', $mid, (string)($m['email'] ?? ''),
                            $body, $subject, 'allocation', $allocationId);
    }
    return $out;
}
