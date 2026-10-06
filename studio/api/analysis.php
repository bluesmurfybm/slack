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
require_once BS_ROOT . '/inc/service/Integration.php';
require_once BS_ROOT . '/inc/service/LinkAnalyzer.php';
require_once BS_ROOT . '/inc/service/LlmClient.php';

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

    // ┌──────────────────────────────────────────────────────────────┐
    // │ 막힌 연동을 화면에 알린다                                      │
    // │                                                              │
    // │ 대기 중인 링크가 있는데 그 연동이 쉬는 중이거나 꺼져 있으면,  │
    // │ 사람은 "왜 안 되지?" 만 하게 된다. 실제로 그렇게 하루를       │
    // │ 날렸다. **대기 건이 있는 연동만** 말한다 — 쓰지도 않는        │
    // │ 연동의 사정까지 늘어놓으면 읽지 않는다.                       │
    // └──────────────────────────────────────────────────────────────┘
    $store   = new Integration(bs_db());
    $blocked = [];
    foreach ([Integration::FIGMA => '피그마', Integration::GOOGLE => '구글 드라이브'] as $p => $who) {
        $waiting = 0;
        foreach ($links as $l) {
            if ((string)$l['provider'] === $p && (string)$l['status'] === 'pending') {
                $waiting++;
            }
        }
        $why = $waiting > 0 ? $store->blockedReason($p) : null;
        if ($why !== null) {
            $blocked[] = ['provider' => $p, 'who' => $who, 'pending' => $waiting, 'reason' => $why];
        }
    }

    $job = $jobs->liveOf($projectId);
    return [
        'links'   => $links,
        'count'   => $count,
        'total'   => count($links),
        'blocked' => $blocked,
        // 쓰지 않기로 한 연동의 링크를 정리하거나 되살리는 단추를 가른다.
        'by_provider' => $an->byProvider($projectId),
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

        $out = an_payload($an, $jobs, $pid);
        $msg = $r['created']
            ? sprintf('%d개를 읽도록 넣었습니다. 잠시 뒤부터 진행률이 올라갑니다.', $pending)
            : '이미 진행 중입니다.';
        // 막힌 연동이 있으면 **넣되 미리 말해 준다.** 거절하지 않는 것은,
        // 제한이 풀리는 순간 저절로 이어지게 두는 편이 낫기 때문이다.
        if (($out['blocked'] ?? []) !== []) {
            $msg .= ' 다만 ' . implode(' ', array_map(
                static fn($b) => (string)$b['reason'], $out['blocked']));
        }

        bs_json_ok($out + ['message' => $msg]);
    },

    /**
     * 난이도 판정을 큐에 넣는다. **여기서 모델을 부르지 않는다.**
     *
     * 태스크가 100개면 모델을 100번 부른다 — 링크 읽기와 같은 이유로 크론에
     * 맡긴다. AI 가 꺼져 있어도 넣는다: 규칙 판정이 바탕으로 늘 돌기 때문에
     * 난이도는 어떻든 채워지고, 나중에 AI 를 켜고 [다시 매기기] 하면 된다.
     */
    'score' => function () use ($projects, $an, $jobs, $pdo): void {
        $pid  = an_project($projects);
        $redo = bs_param_str('redo') === '1';

        require_once BS_ROOT . '/inc/repo/TaskRepo.php';
        $todo = (new TaskRepo($pdo))->pendingDifficulty($pid, $redo);
        if ($todo === []) {
            bs_json_error('NOTHING_TODO', $redo
                ? '매길 태스크가 없습니다. 먼저 WBS 를 만드세요.'
                : '난이도가 빈 태스크가 없습니다. 다시 매기려면 [다시 매기기] 를 쓰세요.', 400);
        }

        $me = ['id' => bs_current_user()['id'] ?? '', 'name' => bs_current_user()['name'] ?? ''];
        $r  = $jobs->enqueue($pid, 'difficulty', count($todo), $me);
        if ($r['created'] && $redo) {
            // 워커가 '다시 매기기' 인지 알아야 이미 매긴 것까지 집는다.
            $jobs->finish((int)$r['job']['id'], 'queued', 'redo');
        }

        $llm  = bs_llm_client();
        $note = $llm->available()
            ? ''
            : ' AI 가 꺼져 있어 규칙으로 매깁니다 — 설정 화면에서 Claude 를 연결하면 더 정확해집니다.';

        bs_json_ok(an_payload($an, $jobs, $pid) + [
            // "이미 진행 중입니다" 만 뜨면 사람은 **아무 일도 안 일어난다**
            // 고 읽는다. 실제로 그래서 단추를 다시 눌렀다. 지금 어디쯤인지
            // 숫자로 말해 준다.
            'message' => ($r['created']
                ? sprintf('태스크 %d건의 난이도를 매기도록 넣었습니다. 1분쯤 뒤부터 진행률이 올라갑니다.',
                          count($todo))
                : sprintf('이미 넣어 두었습니다 (%d/%d건). 크론이 집어 가면 이어서 진행합니다.',
                          (int)($r['job']['done'] ?? 0), (int)($r['job']['total'] ?? 0))) . $note,
        ]);
    },

    /**
     * 쓰지 않기로 한 연동의 대기 링크를 '건너뜀' 으로 정리한다.
     *
     * **실패로 박지 않는다.** 고장난 것이 아니라 안 읽기로 한 것이고,
     * 실패로 보이면 누군가 고치려 든다. 되돌릴 수 있게 둔다.
     */
    'skip_provider' => function () use ($projects, $an, $jobs): void {
        $pid      = an_project($projects);
        $provider = bs_param_str('provider');
        if (!in_array($provider, [Integration::FIGMA, Integration::GOOGLE], true)) {
            bs_json_error('BAD_REQUEST', '어느 연동인지 알 수 없습니다.');
        }
        $who = Integration::label($provider);
        $n   = $an->skipPending($pid, $provider,
            sprintf('%s 를 쓰지 않기로 해 읽지 않습니다. 설정에서 다시 켜고 [다시 읽기] 하면 됩니다.', $who));

        bs_json_ok(an_payload($an, $jobs, $pid) + [
            'message' => $n > 0
                ? sprintf('%s 대기 %d건을 건너뜀으로 정리했습니다. 기록은 그대로 남습니다.', $who, $n)
                : '정리할 대기 건이 없습니다.',
        ]);
    },

    /** 건너뛰기로 했던 것을 다시 읽도록 되돌린다. 연동을 다시 켤 때 쓴다. */
    'revive_provider' => function () use ($projects, $an, $jobs): void {
        $pid      = an_project($projects);
        $provider = bs_param_str('provider');
        if (!in_array($provider, [Integration::FIGMA, Integration::GOOGLE], true)) {
            bs_json_error('BAD_REQUEST', '어느 연동인지 알 수 없습니다.');
        }
        $who = Integration::label($provider);
        $n   = $an->revivePending($pid, $provider);

        bs_json_ok(an_payload($an, $jobs, $pid) + [
            'message' => $n > 0
                ? sprintf('%s 링크 %d건을 다시 읽도록 되돌렸습니다 — [링크 분석 시작] 을 누르세요.', $who, $n)
                : '되돌릴 건이 없습니다.',
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
