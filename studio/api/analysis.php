<?php
/**
 * 링크 분석 — 찾고, 큐에 넣고, 진행률을 본다.
 *
 *   GET  api/analysis.php?act=status&project_id=1   링크 목록 + 진행률
 *   POST api/analysis.php?act=scan                  출처 문서에서 링크 찾기
 *   POST api/analysis.php?act=start                 읽기 시작(큐에 넣기)
 *   POST api/analysis.php?act=cancel                멈추기
 *   POST api/analysis.php?act=retry                 실패한 것만 다시
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 여기서 링크를 읽지 않는다                                         │
 * │                                                                  │
 * │ IA 항목이 100개면 피그마를 100번 부른다. 웹 요청 안에서 하면      │
 * │ 타임아웃으로 죽고, 끊기면 어디까지 했는지도 모른다.               │
 * │                                                                  │
 * │ 큐에 넣기만 하고 바로 돌려준다. 실제로 읽는 것은 크론 워커        │
 * │ (cron/analyze.php)다. 화면은 진행률만 물어본다.                    │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 찾기(scan)는 글자만 보므로 요청 안에서 끝낸다 — 바깥을 타지 않는다.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once BS_ROOT . '/inc/repo/ProjectRepo.php';
require_once BS_ROOT . '/inc/repo/JobRepo.php';
require_once BS_ROOT . '/inc/service/DocumentParser.php';
require_once BS_ROOT . '/inc/service/OfficeDocumentParser.php';
require_once BS_ROOT . '/inc/service/LinkAnalyzer.php';

$pdo      = bs_db();
$projects = new ProjectRepo($pdo);
$jobs     = new JobRepo($pdo);
$an       = new LinkAnalyzer($pdo);

/** 프로젝트를 확인하고 권한을 본다. 분석은 그 프로젝트를 관리하는 사람의 일이다. */
function an_project(ProjectRepo $projects, bool $write = true): int
{
    $me = $write ? bs_begin_write() : bs_require_login_api();
    $id = bs_param_int('project_id', 0) ?? 0;
    if ($id <= 0) {
        bs_json_error('MISSING_PARAM', '프로젝트 번호가 없습니다.', 400);
    }
    if (!$projects->find($id)) {
        bs_json_error('NOT_FOUND', '프로젝트를 찾을 수 없습니다.', 404);
    }
    if ($write) {
        bs_require_cap_api(BS_CAP_PROJECT_MANAGE, $id);
    }
    unset($me);
    return $id;
}

/** 화면이 한 번에 다 받는 꾸러미. 진행률과 목록을 따로 묻지 않게. */
function an_payload(LinkAnalyzer $an, JobRepo $jobs, int $projectId): array
{
    $links = $an->links($projectId);
    $count = ['pending' => 0, 'ok' => 0, 'fail' => 0, 'skip' => 0];
    foreach ($links as $l) {
        $k = (string)$l['status'];
        $count[$k] = ($count[$k] ?? 0) + 1;
    }

    $job = $jobs->liveOf($projectId);
    return [
        'links'   => $links,
        'count'   => $count,
        'total'   => count($links),
        // 도는 중인 작업이 있으면 진행률. 없으면 null — 화면이 단추를 되살린다.
        'job'     => $job === null ? null : [
            'id'      => (int)$job['id'],
            'status'  => $job['status'],
            'total'   => (int)$job['total'],
            'done'    => (int)$job['done'],
            'failed'  => (int)$job['failed'],
            'message' => $job['message'],
        ],
        'recent'  => array_map(static fn($j) => [
            'id'          => (int)$j['id'],
            'status'      => $j['status'],
            'done'        => (int)$j['done'],
            'total'       => (int)$j['total'],
            'failed'      => (int)$j['failed'],
            'message'     => $j['message'],
            'finished_at' => $j['finished_at'],
        ], $jobs->recent($projectId, 3)),
    ];
}

bs_route(bs_param_str('act', 'status'), [

    'status' => function () use ($projects, $an, $jobs): void {
        $pid = an_project($projects, false);
        bs_json_ok(an_payload($an, $jobs, $pid));
    },

    /**
     * 출처 문서의 글자에서 링크를 찾아 담는다.
     *
     * 바깥을 타지 않으므로 요청 안에서 끝낸다. 이미 읽어 둔 링크는
     * 건드리지 않는다 — 다시 찾아도 읽은 내용이 날아가면 안 된다.
     */
    'scan' => function () use ($projects, $an, $jobs): void {
        $pid = an_project($projects);
        $r   = $an->scan($pid);

        $msg = $r['added'] > 0
            ? sprintf('링크 %d개를 새로 찾았습니다.', $r['added'])
            : '새로 찾은 링크가 없습니다.';
        if ($r['skipped'] > 0) {
            $msg .= sprintf(' %d개는 한도(%d)를 넘어 담지 않았습니다.',
                            $r['skipped'], LinkAnalyzer::MAX_LINKS);
        }
        $pending = $an->pendingCount($pid);
        $msg .= $pending > 0
            ? sprintf(' 읽을 수 있는 것이 %d개입니다 — [링크 분석 시작] 을 누르세요.', $pending)
            : ' 읽을 수 있는 링크는 없습니다(구글 드라이브·피그마만 읽습니다).';

        bs_json_ok(an_payload($an, $jobs, $pid) + ['message' => $msg]);
    },

    /**
     * 읽기를 큐에 넣는다. **여기서 읽지 않는다.**
     *
     * 크론이 1분마다 집어 간다. 그래서 누른 직후에는 아무 일도 안 일어난
     * 것처럼 보일 수 있다 — 그 사실을 응답에 적는다.
     */
    'start' => function () use ($projects, $an, $jobs): void {
        $pid     = an_project($projects);
        $pending = $an->pendingCount($pid);
        if ($pending === 0) {
            bs_json_error('NOTHING_TODO',
                '읽을 링크가 없습니다. 먼저 [링크 찾기] 를 누르거나, 출처 문서를 분석하세요.', 400);
        }

        $me  = ['id' => bs_current_user()['id'] ?? '', 'name' => bs_current_user()['name'] ?? ''];
        $r   = $jobs->enqueue($pid, 'links', $pending, $me);

        bs_json_ok(an_payload($an, $jobs, $pid) + [
            'message' => $r['created']
                ? sprintf('%d개를 읽도록 넣었습니다. 잠시 뒤부터 진행률이 올라갑니다.', $pending)
                : '이미 진행 중입니다.',
        ]);
    },

    'cancel' => function () use ($projects, $an, $jobs): void {
        $pid = an_project($projects);
        $n   = $jobs->cancel($pid);
        bs_json_ok(an_payload($an, $jobs, $pid) + [
            'message' => $n > 0
                ? '멈췄습니다. 읽던 한 건은 끝내고 멈춥니다.'
                : '진행 중인 작업이 없습니다.',
        ]);
    },

    /** 설정을 고친 뒤 실패한 것만 다시. 성공한 것은 다시 읽지 않는다. */
    'retry' => function () use ($projects, $an, $jobs): void {
        $pid = an_project($projects);
        $n   = $an->retryFailed($pid);
        bs_json_ok(an_payload($an, $jobs, $pid) + [
            'message' => $n > 0
                ? sprintf('실패한 %d개를 다시 읽도록 되돌렸습니다. [링크 분석 시작] 을 누르세요.', $n)
                : '다시 읽을 실패 건이 없습니다.',
        ]);
    },
]);
