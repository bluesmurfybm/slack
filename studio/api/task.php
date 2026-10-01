<?php
/**
 * 태스크(WBS) API — 트리 조회·저장, 확정, 이동, 삭제.
 *
 * GET  api/task.php?act=tree&project_id=1
 * GET  api/task.php?act=detail&id=1
 * POST api/task.php?act=save_tree    WBS 통째로 저장 (confirmed 는 못 바꾼다)
 * POST api/task.php?act=confirm      확정 (confirmed=1)
 * POST api/task.php?act=unconfirm    확정 해제
 * POST api/task.php?act=move         한 건만 자리 옮기기
 * POST api/task.php?act=update       한 건만 수정
 * POST api/task.php?act=delete       한 건(+하위) 삭제
 * POST api/task.php?act=parse        출처 문서 → parsed_text
 * POST api/task.php?act=extract      parsed_text → WBS 초안 (저장 안 함)
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ confirmed 를 바꾸는 경로는 confirm / unconfirm **둘뿐이다.**      │
 * │ save_tree 로 확정까지 한 번에 하게 만들지 말 것 — 그러면          │
 * │ '사람이 검토했다' 는 표시가 트리 편집의 부산물이 된다.            │
 * └──────────────────────────────────────────────────────────────────┘
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once BS_ROOT . '/inc/repo/ProjectRepo.php';
require_once BS_ROOT . '/inc/repo/TaskRepo.php';
require_once BS_ROOT . '/inc/service/WbsExtractor.php';

$pdo      = bs_db();
$projects = new ProjectRepo($pdo);
$tasks    = new TaskRepo($pdo);

bs_route(bs_param_str('act', 'tree'), [

    'tree' => function () use ($projects, $tasks): void {
        bs_require_login_api();
        $projectId = bs_task_project_param($projects);

        $flat    = $tasks->allByProject($projectId);
        $domains = $tasks->domainsFor(array_column($flat, 'id'));
        $tree    = $tasks->toTree($flat, $domains);

        bs_json_ok([
            'tree'     => $tree,
            'counts'   => bs_task_counts($flat),
            // 화면은 이 값을 들고 있다가 저장할 때 그대로 돌려줘야 한다.
            // 그 사이 남이 저장했으면 서버가 거절한다.
            'revision' => $tasks->revision($projectId),
            'can_edit'    => bs_can(BS_CAP_PROJECT_MANAGE, $projectId),
            'can_confirm' => bs_can(BS_CAP_WBS_CONFIRM, $projectId),
        ]);
    },

    'detail' => function () use ($tasks): void {
        bs_require_login_api();
        $id = bs_param_int('id', 0);
        if (!$id) {
            bs_json_error('MISSING_PARAM', '태스크 번호가 없습니다.', 400);
        }
        $task = $tasks->find($id);
        if (!$task) {
            bs_json_error('NOT_FOUND', '태스크를 찾을 수 없습니다.', 404);
        }
        bs_json_ok([
            'task'    => bs_present_task($task),
            'domains' => $tasks->domains($id),
        ]);
    },

    /**
     * 출처 문서에서 글자를 뽑아 parsed_text 를 채운다.
     *
     * 도출과 나눠 둔다 — 파싱은 느리고 실패할 수 있어서, 무엇이 읽혔고
     * 무엇이 실패했는지 사람이 먼저 보는 편이 낫다. 한 파일이 실패해도
     * 나머지는 계속 간다(WbsExtractor::parseSource 가 예외를 삼킨다).
     */
    'parse' => function () use ($projects, $tasks): void {
        bs_begin_write();
        $projectId = bs_task_project_param($projects);
        bs_require_cap_api(BS_CAP_PROJECT_MANAGE, $projectId);

        $ex = new WbsExtractor($projects, $tasks);
        // 이미 읽은 것도 다시 읽을지. 기본은 대기 중인 것만.
        $all = bs_param_int('all', 0) === 1;

        $results = $ex->parseProjectSources($projectId, !$all);

        $rows = [];
        foreach ($projects->sources($projectId) as $s) {
            $id = (int)$s['id'];
            $r  = $results[$id] ?? null;
            $rows[] = [
                'id'      => $id,
                'title'   => $s['title'],
                'kind'    => $s['kind'],
                'status'  => $s['parse_status'],
                'error'   => $s['parse_error'],
                'touched' => $r !== null,
                'chars'   => $r['chars'] ?? null,
                'blocks'  => $r['blocks'] ?? null,
                'notes'   => $r['notes'] ?? [],
            ];
        }

        $ok   = count(array_filter($results, static fn($r) => $r['status'] === 'ok'));
        $bad  = count(array_filter($results, static fn($r) => $r['status'] === 'fail'));
        $skip = count(array_filter($results, static fn($r) => $r['status'] === 'skip'));

        bs_json_ok([
            'sources' => $rows,
            'summary' => ['ok' => $ok, 'fail' => $bad, 'skip' => $skip,
                          'touched' => count($results)],
            'message' => $results
                ? "문서 {$ok}건을 읽었습니다."
                  . ($bad ? " {$bad}건은 읽지 못했습니다." : '')
                  . ($skip ? " {$skip}건은 파일이 아니라 건너뛰었습니다." : '')
                : '새로 읽을 문서가 없습니다.',
        ]);
    },

    /**
     * 출처 문서에서 WBS 초안을 뽑는다. 결과를 **저장하지 않는다.**
     * 사람이 화면에서 보고 고친 뒤 save_tree 로 저장한다.
     *
     * 나온 태스크는 전부 origin='auto', confirmed=0 이다.
     * 이 응답만 보고 배정으로 넘어가는 경로를 만들지 말 것.
     */
    'extract' => function () use ($projects, $tasks): void {
        bs_begin_write();
        $projectId = bs_task_project_param($projects);
        bs_require_cap_api(BS_CAP_PROJECT_MANAGE, $projectId);

        $ex = new WbsExtractor($projects, $tasks);

        // use_llm=0 이면 규칙만 쓴다. 사외 반출이 걸리는 동안에도
        // 이 경로로는 쓸 수 있다.
        $useLlm = bs_param_int('use_llm', 1) === 1;

        try {
            $r = $ex->extractForProject($projectId, $useLlm);
        } catch (LlmError $e) {
            bs_json_error('LLM_ERROR', $e->getMessage(), 502);
        }

        bs_json_ok($r + [
            'origin' => 'auto',
            'notice' => '도출된 초안입니다. 검토해 저장하고 확정해야 배정 대상이 됩니다.',
        ]);
    },

    'save_tree' => function () use ($projects, $tasks): void {
        $user      = bs_begin_write();
        $projectId = bs_task_project_param($projects);
        bs_require_cap_api(BS_CAP_PROJECT_MANAGE, $projectId);

        $tree = bs_param_array('tree');
        $rev  = bs_param_str('revision', '');

        $r = $tasks->saveTree($projectId, $tree, $user, $rev !== '' ? $rev : null);

        // 저장 뒤 화면이 다시 그릴 수 있게 트리를 돌려준다. 한 번 더 부르게
        // 하면 그 사이에 남이 저장한 것과 섞인다.
        $flat = $tasks->allByProject($projectId);

        bs_json_ok($r + [
            'message' => bs_task_save_message($r),
            'tree'    => $tasks->toTree($flat, $tasks->domainsFor(array_column($flat, 'id'))),
            'counts'  => bs_task_counts($flat),
        ]);
    },

    /**
     * 초안 확정. confirmed 를 1 로 올리는 유일한 API 경로다.
     */
    'confirm' => function () use ($tasks): void {
        $user = bs_begin_write();
        bs_task_set_confirm($tasks, $user, true);
    },

    /** 확정 해제. 배정안에 들어간 태스크면 막힌다. */
    'unconfirm' => function () use ($tasks): void {
        $user = bs_begin_write();
        bs_task_set_confirm($tasks, $user, false);
    },

    'move' => function () use ($tasks): void {
        bs_begin_write();
        $id = bs_param_int('id', 0);
        if (!$id) {
            bs_json_error('MISSING_PARAM', '태스크 번호가 없습니다.', 400);
        }
        $projectId = $tasks->projectIdOf([$id]);
        bs_require_cap_api(BS_CAP_PROJECT_MANAGE, $projectId);

        // parent_id 를 아예 안 보내는 것과 null 로 보내는 것은 다르다.
        // 전자는 잘못된 요청, 후자는 "최상위로 올려라" 다.
        if (!bs_has_param('parent_id')) {
            bs_json_error('MISSING_PARAM', '옮길 위치(parent_id)를 지정하세요. 최상위면 null.', 400);
        }
        $parentId = bs_param_int('parent_id', 0);
        $tasks->move($id, $parentId ?: null, max(0, bs_param_int('seq', 0) ?? 0));

        $flat = $tasks->allByProject($projectId);
        bs_json_ok([
            'message'  => '태스크를 옮겼습니다.',
            'tree'     => $tasks->toTree($flat, $tasks->domainsFor(array_column($flat, 'id'))),
            'counts'   => bs_task_counts($flat),
            'revision' => $tasks->revision($projectId),
        ]);
    },

    'update' => function () use ($tasks): void {
        bs_begin_write();
        $id = bs_param_int('id', 0);
        if (!$id) {
            bs_json_error('MISSING_PARAM', '태스크 번호가 없습니다.', 400);
        }
        $projectId = $tasks->projectIdOf([$id]);
        bs_require_cap_api(BS_CAP_PROJECT_MANAGE, $projectId);

        // 넘어온 키만 바꾼다. confirmed 는 여기서 바꾸지 않는다 — act=confirm 으로만.
        $data = [];
        foreach (['title', 'description', 'est_md', 'difficulty',
                  'plan_start', 'plan_end', 'source_ref'] as $k) {
            if (bs_has_param($k)) {
                $data[$k] = bs_param($k);
            }
        }
        if (bs_has_param('domain_ids')) {
            $data['domain_ids'] = bs_param_array('domain_ids');
        }
        if (!$data) {
            bs_json_error('MISSING_PARAM', '바꿀 내용이 없습니다.', 400);
        }
        $tasks->update($id, $data);

        bs_json_ok([
            'id'       => $id,
            'message'  => '태스크를 수정했습니다.',
            'task'     => bs_present_task($tasks->find($id) ?? []),
            'domains'  => $tasks->domains($id),
            'revision' => $tasks->revision($projectId),
        ]);
    },

    'delete' => function () use ($tasks): void {
        bs_begin_write();
        $id = bs_param_int('id', 0);
        if (!$id) {
            bs_json_error('MISSING_PARAM', '태스크 번호가 없습니다.', 400);
        }
        $projectId = $tasks->projectIdOf([$id]);
        bs_require_cap_api(BS_CAP_PROJECT_MANAGE, $projectId);

        // 배정안에 들어 있으면 TaskRepo 가 DomainException 으로 막는다.
        $tasks->delete($id);

        $flat = $tasks->allByProject($projectId);
        bs_json_ok([
            'message'  => '태스크를 삭제했습니다.',
            'tree'     => $tasks->toTree($flat, $tasks->domainsFor(array_column($flat, 'id'))),
            'counts'   => bs_task_counts($flat),
            'revision' => $tasks->revision($projectId),
        ]);
    },
]);


// =====================================================================
// 공통
// =====================================================================

/** project_id 를 읽고 실재를 확인한다. 없으면 여기서 끊는다. */
function bs_task_project_param(ProjectRepo $projects): int
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

/**
 * confirm / unconfirm 공통.
 *
 * 권한은 **태스크가 속한 프로젝트 기준**으로 본다. task_ids 만 받아
 * project_id 를 요청에서 같이 받으면, 남의 태스크 id 에 내 프로젝트 번호를
 * 붙여 보내는 것으로 권한을 넘길 수 있다.
 */
function bs_task_set_confirm(TaskRepo $tasks, array $user, bool $on): never
{
    $ids = array_map('intval', bs_param_array('task_ids'));
    if (!$ids) {
        bs_json_error('MISSING_PARAM',
            ($on ? '확정할' : '확정을 풀') . ' 태스크를 지정하세요.', 400);
    }
    $projectId = $tasks->projectIdOf($ids);
    bs_require_cap_api(BS_CAP_WBS_CONFIRM, $projectId);

    $n    = $tasks->confirm($ids, $on, $user);
    $flat = $tasks->allByProject($projectId);

    bs_json_ok([
        'changed'  => $n,
        'message'  => $n === 0
            ? '바뀐 것이 없습니다.'
            : $n . '건을 ' . ($on ? '확정했습니다.' : '확정 해제했습니다.'),
        'counts'   => bs_task_counts($flat),
        'revision' => $tasks->revision($projectId),
    ]);
}

/**
 * 화면 머리에 띄울 집계.
 *
 * `assignable` 은 **실제로 배정에 넘어갈 수 있는 건수**다. confirmed 건수와
 * 다를 수 있다(완료·보류는 빠진다). 두 숫자를 같이 보여줘야 "확정은 했는데
 * 왜 배정 후보에 안 뜨지" 를 화면에서 알 수 있다.
 */
function bs_task_counts(array $flat): array
{
    $total = count($flat);
    $conf  = 0;
    $assignable = 0;
    $estSum = 0.0;
    $leafNoEst = 0;

    $hasChild = [];
    foreach ($flat as $r) {
        if ($r['parent_id'] !== null) {
            $hasChild[(int)$r['parent_id']] = true;
        }
    }

    foreach ($flat as $r) {
        if ((int)$r['confirmed'] === 1) {
            $conf++;
            if (!in_array($r['status'], BS_TASK_NOT_ASSIGNABLE_STATUS, true)) {
                $assignable++;
            }
        }
        $isLeaf = !isset($hasChild[(int)$r['id']]);
        if ($isLeaf) {
            if ($r['est_md'] !== null) {
                $estSum += (float)$r['est_md'];
            } else {
                $leafNoEst++;
            }
        }
    }

    return [
        'total'        => $total,
        'confirmed'    => $conf,
        'unconfirmed'  => $total - $conf,
        'assignable'   => $assignable,
        'est_md_total' => round($estSum, 2),
        // 공수가 비어 있는 말단 태스크. 배정 공수 계산이 이만큼 빈다.
        'leaf_no_est'  => $leafNoEst,
    ];
}

function bs_present_task(array $r): ?array
{
    if (!$r) {
        return null;
    }
    return [
        'id'           => (int)$r['id'],
        'project_id'   => (int)$r['project_id'],
        'parent_id'    => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
        'depth'        => (int)$r['depth'],
        'seq'          => (int)$r['seq'],
        'wbs_no'       => $r['wbs_no'],
        'title'        => $r['title'],
        'description'  => $r['description'],
        'est_md'       => $r['est_md'] !== null ? (float)$r['est_md'] : null,
        'difficulty'   => $r['difficulty'] !== null ? (int)$r['difficulty'] : null,
        'plan_start'   => $r['plan_start'],
        'plan_end'     => $r['plan_end'],
        'origin'       => $r['origin'],
        'confirmed'    => (int)$r['confirmed'] === 1,
        'status'       => $r['status'],
        'status_label' => BS_TASK_STATUS[$r['status']] ?? $r['status'],
        'progress_pct' => (int)$r['progress_pct'],
        'source_id'    => $r['source_id'] !== null ? (int)$r['source_id'] : null,
        'source_ref'   => $r['source_ref'],
    ];
}

function bs_task_save_message(array $r): string
{
    $parts = [];
    if ($r['created']) { $parts[] = '추가 ' . $r['created'] . '건'; }
    if ($r['updated']) { $parts[] = '수정 ' . $r['updated'] . '건'; }
    if ($r['deleted']) { $parts[] = '삭제 ' . $r['deleted'] . '건'; }
    return $parts ? 'WBS 를 저장했습니다 (' . implode(', ', $parts) . ').'
                  : '바뀐 것이 없습니다.';
}
