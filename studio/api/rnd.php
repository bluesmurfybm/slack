<?php
/**
 * R&D 과제 API — 보드·상세·발의·수정·승인·반려 (명세서 §8).
 *
 * GET  api/rnd.php?act=board           보드 목록 + 상태별 건수 + 머리 통계
 * GET  api/rnd.php?act=get&id=1        상세 — 개요·참여자·진행 기록·산출물 한 벌
 * POST api/rnd.php?act=propose         발의
 * POST api/rnd.php?act=update          수정 (발의자는 승인 전까지만)
 * POST api/rnd.php?act=approve         과제 승인
 * POST api/rnd.php?act=reject          과제 반려 (사유 필수)
 *
 * POST api/rnd.php?act=join            합류 신청 (사유 + 신고 점유율)
 * GET  api/rnd.php?act=load_check      신청 전 점유 상한 사전 확인 (본인 것만)
 * POST api/rnd.php?act=approve_member  합류 승인 — lead 또는 관리자. 상한을 넘으면 거부
 * POST api/rnd.php?act=reject_member   합류 반려 (사유 필수)
 * POST api/rnd.php?act=leave           스스로 나가기 — 본인만
 * POST api/rnd.php?act=log             진행 기록 (content + finding)
 * POST api/rnd.php?act=output          산출물 등록
 * POST api/rnd.php?act=interest        관심 표시 토글
 * POST api/rnd.php?act=finish          종료 — 산출물 1건 이상 필수
 * POST api/rnd.php?act=drop            중단 (사유 필수)
 *
 * **가시성은 RndRepo 가 쿼리에서 강제한다.** 이 파일은 그 결과를 그대로
 * 내보낸다 — 여기서 다시 거르지 않는다. 두 곳에서 거르면 언젠가 어긋난다.
 *
 * **점유(bs_workload) 적재는 아직 없다.** 승인·합류가 가용도를 바꾸지 않는다.
 * 적재는 P10-2 다. 통제(P10-1)를 먼저 세우고 그 뒤에 적재를 연다 — CLAUDE.md
 * 가 그 순서를 못 박는다. 뒤집으면 그 사이에 운영 데이터가 오염된다.
 * 종료·이탈이 end_date 를 당기는 경로는 지금 미리 넣어 두었고(지우지 않는다),
 * 적재가 시작되면 그대로 동작한다.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once BS_ROOT . '/inc/presenter.php';
require_once BS_ROOT . '/inc/repo/RndRepo.php';
require_once BS_ROOT . '/inc/repo/MemberRepo.php';
require_once BS_ROOT . '/inc/service/Notifier.php';
require_once BS_ROOT . '/inc/service/RndLoadService.php';

$repo = new RndRepo(bs_db());

bs_route(bs_param_str('act', 'board'), [

    // =================================================================
    // 조회 — 로그인만 하면 부를 수 있다. 무엇이 보이는지는 Repo 가 정한다
    // =================================================================

    'board' => function () use ($repo): void {
        bs_require_login_api();

        $filter = [
            'category'   => bs_param_str('category'),
            'status'     => bs_param_str('status'),
            'recruiting' => bs_param_str('recruiting') === '1',
            'mine'       => bs_param_str('mine') === '1',
            'keyword'    => bs_param_str('keyword'),
            'sort'       => bs_param_str('sort', 'recent'),
            'page'       => bs_param_int('page', 1),
            'size'       => bs_param_int('size', 24),
        ];

        $result = $repo->board($filter);

        bs_json_ok([
            'rows'   => array_map('bs_present_rnd_row', $result['rows']),
            'total'  => $result['total'],
            'page'   => $result['page'],
            'size'   => $result['size'],
            'pages'  => $result['pages'],
            'counts' => $repo->statusCounts($filter),
            'stats'  => $repo->stats(),
            // 화면이 단추를 그릴 때 쓴다. 발의는 로그인한 사람 누구나 한다.
            'can'    => [
                'propose' => true,
                'approve' => bs_can(BS_CAP_RND_APPROVE),
            ],
        ]);
    },

    'get' => function () use ($repo): void {
        bs_require_login_api();

        $id = bs_param_int('id', 0);
        if (!$id) {
            bs_json_error('MISSING_PARAM', '과제 번호가 없습니다.', 400);
        }

        // 상세 화면의 네 탭(개요·참여자·진행 기록·산출물)이 쓰는 것을
        // 한 번에 돌려준다. 못 보는 과제와 없는 과제는 **같게** 답한다 —
        // 가르면 비공개 과제가 존재한다는 사실이 샌다.
        bs_json_ok(bs_rnd_detail($repo, $id));
    },

    // =================================================================
    // 변경
    // =================================================================

    'propose' => function () use ($repo): void {
        $user = bs_begin_write();

        // 발의 **전에** 본다. 올리고 난 뒤에 알려 주면 늦다.
        //
        // 막지는 않는다 — 중단 자체가 잘못이 아니다. 해 보고 아니면 접는
        // 것이 R&D 다. 다만 연속으로 접힌 뒤 또 올리면 승인하는 사람이
        // 그 사실을 알고 판단해야 한다 (명세서 §9).
        $warning = (new RndLoadService(bs_db()))->proposalWarning((string)$user['id']);

        $id = $repo->propose(bs_read_rnd_input(), $user);
        $r  = $repo->find($id);

        bs_json_ok([
            'id'      => $id,
            'rnd'     => $r ? bs_present_rnd($r) : null,
            'warning' => $warning,
            'message' => '과제를 발의했습니다. 승인되면 보드에 올라갑니다.'
                       . ($warning ? ' ' . $warning['message'] : ''),
        ]);
    },

    'update' => function () use ($repo): void {
        bs_begin_write();

        $id = bs_param_int('id', 0);
        if (!$id) {
            bs_json_error('MISSING_PARAM', '과제 번호가 없습니다.', 400);
        }

        // 권한 판정은 Repo 안에 있다(bs_rnd_can_edit). 여기서 또 보지 않는다.
        $repo->update($id, bs_read_rnd_input(true));
        $r = $repo->find($id);

        bs_json_ok([
            'rnd'     => $r ? bs_present_rnd($r) : null,
            'message' => '수정했습니다.',
        ]);
    },

    'approve' => function () use ($repo): void {
        $user = bs_begin_write();

        $id = bs_param_int('id', 0);
        if (!$id) {
            bs_json_error('MISSING_PARAM', '과제 번호가 없습니다.', 400);
        }

        try {
            $repo->approve($id, $user);
        } catch (RndCapExceededException $e) {
            // 발의자가 상한에 걸리면 과제 승인 자체가 막힌다 — 승인되면
            // 그 자리에서 lead 점유가 올라가기 때문이다.
            bs_json_error('RND_CAP_EXCEEDED', $e->getMessage(), 400, $e->check);
        }
        $r = $repo->find($id);

        bs_json_ok([
            'rnd'     => $r ? bs_present_rnd($r) : null,
            'message' => '승인했습니다.',
            // 사람이 오해하지 않게 적어 둔다 — 승인은 점유를 만들지 않는다.
            'notice'  => '승인은 과제를 열어 줄 뿐입니다. 가용도는 사람이 합류해야 바뀝니다.',
        ]);
    },

    'reject' => function () use ($repo): void {
        $user = bs_begin_write();

        $id = bs_param_int('id', 0);
        if (!$id) {
            bs_json_error('MISSING_PARAM', '과제 번호가 없습니다.', 400);
        }

        $repo->reject($id, bs_param_str('reason'), $user);
        $r = $repo->find($id);

        bs_json_ok([
            'rnd'     => $r ? bs_present_rnd($r) : null,
            'message' => '반려했습니다. 발의자가 고쳐서 다시 낼 수 있습니다.',
        ]);
    },

    // =================================================================
    // 합류 (명세서 §8.4)
    // =================================================================

    'join' => function () use ($repo): void {
        $user = bs_begin_write();
        $pid  = bs_rnd_project_param();

        $repo->requestJoin($pid, [
            'join_reason' => bs_param_str('join_reason'),
            'load_ratio'  => bs_param_str('load_ratio'),
        ], $user);

        bs_json_ok(bs_rnd_detail($repo, $pid) + [
            'message' => '합류를 신청했습니다. 과제를 주도하는 사람이 승인하면 참여가 시작됩니다.',
        ]);
    },

    /**
     * 신청 전 사전 확인 (명세서 §9.2).
     *
     * 신청해 놓고 승인에서 막히는 것보다, 누르기 전에 "지금 0.25 를 쓰고
     * 있어서 0.1 까지만 됩니다" 를 보는 쪽이 낫다. 승인 때 거는 검사와
     * **같은 함수**를 쓴다 — 두 벌로 두면 화면은 된다고 하고 서버는
     * 막는 상태가 된다.
     */
    'load_check' => function () use ($repo): void {
        $user = bs_require_login_api();
        $pid  = bs_rnd_project_param();

        $svc = new RndLoadService(bs_db());
        $mid = (new MemberRepo(bs_db()))->findByUserId((string)$user['id'])['id'] ?? null;
        if ($mid === null) {
            bs_json_error('NOT_FOUND', '구성원 명단에 없습니다.', 404);
        }

        // 남의 점유를 들여다보지 못하게 한다. 본인 것만 본다.
        bs_json_ok($svc->checkCap((int)$mid, $pid, (float)bs_param_str('load_ratio', '0')) + [
            'stale_projects' => $svc->staleProjectsOf((int)$mid),
        ]);
    },

    /**
     * 한 사람의 R&D 점유 내역 (P10-2).
     *
     * 가용도 표시에서 "R&D 15%" 를 눌렀을 때 **어떤 과제인지** 보여 주는
     * 드로어가 쓴다. 합산 숫자만 보여 주고 끝내면 그 15% 가 어디서 왔는지
     * 아무도 되짚을 수 없다.
     *
     * 본인 것은 언제나 본다. 남의 것은 배정을 짜는 사람만 본다 — 후보 표와
     * 같은 선이다(BS_CAP_ALLOCATION_PROPOSE).
     *
     * **역량 점수를 함께 내보내지 않는다.** 이 응답은 점유 감사 전용이다.
     */
    'member_load' => function (): void {
        $user = bs_require_login_api();

        $members = new MemberRepo(bs_db());
        $meMid   = (int)($members->findByUserId((string)$user['id'])['id'] ?? 0);
        $mid     = bs_param_int('member_id', 0) ?: $meMid;

        if ($mid !== $meMid && !bs_can(BS_CAP_ALLOCATION_PROPOSE) && !bs_is_admin()) {
            bs_json_error('FORBIDDEN', '다른 사람의 점유 내역을 볼 권한이 없습니다.', 403);
        }
        if (!$mid) {
            bs_json_error('NOT_FOUND', '구성원 명단에 없습니다.', 404);
        }

        $svc = new RndLoadService(bs_db());
        $cur = $svc->currentLoad($mid);
        $lim = $svc->limits();

        bs_json_ok([
            'member_id' => $mid,
            'emp_name'  => $members->find($mid)['emp_name'] ?? null,
            'total'     => $cur['total'],
            'count'     => $cur['count'],
            // 못 볼 과제는 이름을 가린다. 숫자는 그대로라 합계가 맞는다.
            'projects'  => $svc->maskInvisible($cur['projects']),
            'stale'     => $svc->staleProjectsOf($mid),
            'limit'     => $lim,
            // 상한 대비 여유. 음수면 이미 넘은 것이다(설정을 낮춘 경우).
            'headroom'  => round($lim['total_cap'] - $cur['total'], 3),
        ]);
    },

    /**
     * 관리자용 R&D 점유 현황 (P10-2).
     *
     * ┌──────────────────────────────────────────────────────────────┐
     * │ **역량 점수를 이 화면에 섞지 않는다.**                        │
     * │                                                              │
     * │ 점유율 감사 전용이다. 여기에 역량 점수를 함께 올리면 구성원을 │
     * │ 한 줄로 세운 표가 되고, 그것은 CLAUDE.md 가 금지한 전사      │
     * │ 랭킹이다. 정렬 한 번이면 그렇게 된다.                        │
     * │                                                              │
     * │ 이 응답에는 cap_score · breadth_score · fit 류가 한 칸도      │
     * │ 들어 있지 않다. rnd_test 가 그것을 지킨다.                    │
     * └──────────────────────────────────────────────────────────────┘
     */
    'admin_load' => function (): void {
        bs_require_login_api();
        if (!bs_is_admin()) {
            bs_json_error('FORBIDDEN', '관리자만 볼 수 있습니다.', 403);
        }

        $pdo = bs_db();
        $svc = new RndLoadService($pdo);
        $lim = $svc->limits();

        // R&D 에 참여 중인 사람만 추린다. 전원을 올리면 "0% 인 사람" 이
        // 대부분이라 볼 것이 묻힌다.
        $ids = $pdo->query(
            "SELECT DISTINCT rm.member_id
               FROM bs_rnd_member rm
               JOIN bs_project p ON p.id = rm.project_id
              WHERE rm.status = 'approved' AND p.project_type = 'rnd'
                AND p.deleted_at IS NULL AND p.status IN ('approved','running')"
        )->fetchAll(PDO::FETCH_COLUMN);

        // ┌──────────────────────────────────────────────────────────────┐
        // │ 사람 수에 비례해 묻지 않는다                                  │
        // │                                                              │
        // │ 전에는 구성원 1명마다 3회씩(점유·이름·정체) 물었다 —          │
        // │ 10명이면 31회다. 한 번에 묶어 가져와 PHP 에서 나눈다.          │
        // │ 질의 수가 **인원과 무관하게 고정**이 된다.                     │
        // └──────────────────────────────────────────────────────────────┘
        $rows = [];
        if ($ids) {
            $ids = array_map('intval', $ids);
            $ph  = implode(',', array_fill(0, count($ids), '?'));

            // ① 이름 — 한 번
            $st = $pdo->prepare("SELECT id, emp_name, role_label FROM bs_member WHERE id IN ($ph)");
            $st->execute($ids);
            $names = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $names[(int)$m['id']] = $m;
            }

            // ② 참여 중인 과제 — 한 번. 정체 여부도 같은 줄에서 낸다.
            $weeks   = bs_setting_int('rnd_stale_weeks');
            $limitTs = strtotime('-' . ($weeks * 7) . ' day');

            $st = $pdo->prepare(
                "SELECT rm.member_id, p.id, p.code, p.name, p.visibility, rm.load_ratio,
                        COALESCE(
                          (SELECT MAX(l.created_at) FROM bs_rnd_log l WHERE l.project_id = p.id),
                          p.approved_at) AS last_at
                   FROM bs_rnd_member rm
                   JOIN bs_project p ON p.id = rm.project_id
                  WHERE rm.member_id IN ($ph) AND rm.status = 'approved'
                    AND p.project_type = 'rnd' AND p.deleted_at IS NULL
                    AND p.status IN ('approved','running')"
            );
            $st->execute($ids);

            $byMember = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byMember[(int)$r['member_id']][] = [
                    'project_id'  => (int)$r['id'],
                    'code'        => $r['code'],
                    'name'        => $r['name'],
                    'visibility'  => $r['visibility'],
                    'load_ratio'  => (float)$r['load_ratio'],
                    'last_log_at' => bs_date($r['last_at']),
                    'is_stale'    => $r['last_at'] !== null
                                     && strtotime((string)$r['last_at']) < $limitTs,
                ];
            }

            foreach ($ids as $mid) {
                $ps    = $byMember[$mid] ?? [];
                $total = 0.0;
                foreach ($ps as $one) { $total += $one['load_ratio']; }
                $total = round($total, 3);
                $stale = array_values(array_filter($ps, static fn($o) => $o['is_stale']));

                $rows[] = [
                    'member_id'  => $mid,
                    'emp_name'   => $names[$mid]['emp_name'] ?? ('#' . $mid),
                    'role_label' => $names[$mid]['role_label'] ?? null,
                    'total'      => $total,
                    'count'      => count($ps),
                    'projects'   => $svc->maskInvisible($ps),
                    'stale'      => $svc->maskInvisible($stale),
                    'headroom'   => round($lim['total_cap'] - $total, 3),
                    'over'       => $total > $lim['total_cap'],
                ];
            }
        }
        usort($rows, static fn($a, $b) => ($b['total'] <=> $a['total'])
                                       ?: ($a['member_id'] <=> $b['member_id']));

        bs_json_ok(['rows' => $rows, 'limit' => $lim]);
    },

    'approve_member' => function () use ($repo): void {
        $user = bs_begin_write();

        $rowId = bs_param_int('member_row_id', 0);
        if (!$rowId) {
            bs_json_error('MISSING_PARAM', '참여 기록 번호가 없습니다.', 400);
        }

        try {
            $row = $repo->approveMember($rowId, $user);
        } catch (RndCapExceededException $e) {
            // 전용 코드로 내보낸다. 화면이 "무엇을 줄여야 하는지" 를
            // 보여 줘야 하므로 위반 내역과 현재 점유를 함께 싣는다.
            bs_json_error('RND_CAP_EXCEEDED', $e->getMessage(), 400, $e->check);
        }
        $pid = (int)$row['project_id'];

        $queued = bs_rnd_notify_member($repo, $pid, $row, true, '');

        bs_json_ok(bs_rnd_detail($repo, $pid) + [
            'message' => $row['emp_name'] . ' 님의 합류를 승인했습니다.'
                       . ($queued ? ' 알림 ' . $queued . '건을 보낼 목록에 넣었습니다.' : ''),
            'notify_notice' => '알림은 적재만 된 상태입니다. 실제 발송 경로는 아직 없습니다.',
        ]);
    },

    'reject_member' => function () use ($repo): void {
        $user = bs_begin_write();

        $rowId = bs_param_int('member_row_id', 0);
        if (!$rowId) {
            bs_json_error('MISSING_PARAM', '참여 기록 번호가 없습니다.', 400);
        }
        $reason = bs_param_str('reason');

        $row = $repo->rejectMember($rowId, $reason, $user);
        $pid = (int)$row['project_id'];

        $queued = bs_rnd_notify_member($repo, $pid, $row, false, $reason);

        bs_json_ok(bs_rnd_detail($repo, $pid) + [
            'message' => $row['emp_name'] . ' 님의 합류를 반려했습니다.'
                       . ($queued ? ' 알림 ' . $queued . '건을 보낼 목록에 넣었습니다.' : ''),
            'notify_notice' => '알림은 적재만 된 상태입니다. 실제 발송 경로는 아직 없습니다.',
        ]);
    },

    'leave' => function () use ($repo): void {
        $user = bs_begin_write();
        $pid  = bs_rnd_project_param();

        $repo->leave($pid, $user);

        bs_json_ok(bs_rnd_detail($repo, $pid) + [
            'message' => '이 과제에서 나왔습니다.',
        ]);
    },

    // =================================================================
    // 진행 기록 · 산출물 · 관심
    // =================================================================

    'log' => function () use ($repo): void {
        $user = bs_begin_write();
        $pid  = bs_rnd_project_param();

        $repo->addLog($pid, [
            'content'   => bs_param_str('content'),
            'finding'   => bs_param_str('finding'),
            'worked_on' => bs_param_str('worked_on'),
        ], $user);

        bs_json_ok(bs_rnd_detail($repo, $pid) + ['message' => '진행 기록을 남겼습니다.']);
    },

    'output' => function () use ($repo): void {
        $user = bs_begin_write();
        $pid  = bs_rnd_project_param();

        $repo->addOutput($pid, [
            'kind'    => bs_param_str('kind', 'doc'),
            'title'   => bs_param_str('title'),
            'url'     => bs_param_str('url'),
            'summary' => bs_param_str('summary'),
        ], $user);

        bs_json_ok(bs_rnd_detail($repo, $pid) + ['message' => '산출물을 등록했습니다.']);
    },

    'interest' => function () use ($repo): void {
        $user = bs_begin_write();
        $pid  = bs_rnd_project_param();

        $on = $repo->toggleInterest($pid, $user);

        bs_json_ok(bs_rnd_detail($repo, $pid) + [
            'interested' => $on,
            'message'    => $on ? '관심 과제로 표시했습니다.' : '관심 표시를 지웠습니다.',
        ]);
    },

    // =================================================================
    // 종료 (명세서 §8.5)
    // =================================================================

    'finish' => function () use ($repo): void {
        $user = bs_begin_write();
        $pid  = bs_rnd_project_param();

        try {
            $repo->finish($pid, $user);
        } catch (RndNoOutputException $e) {
            // 이것만 전용 코드로 내보낸다 — 화면이 "산출물을 먼저 등록하라" 는
            // 다음 행동으로 이어 줘야 하기 때문이다 (명세서 §8.5).
            bs_json_error('RND_NO_OUTPUT', $e->getMessage(), 400);
        }

        bs_json_ok(bs_rnd_detail($repo, $pid) + [
            'message' => '과제를 종료했습니다.',
            'notice'  => '점유 기록은 끝 날짜만 당겨 두고 지우지 않습니다. '
                       . '지난 기간의 가용도를 다시 계산할 수 있어야 합니다.',
        ]);
    },

    'drop' => function () use ($repo): void {
        $user = bs_begin_write();
        $pid  = bs_rnd_project_param();

        $repo->drop($pid, bs_param_str('reason'), $user);

        bs_json_ok(bs_rnd_detail($repo, $pid) + [
            'message' => '과제를 중단했습니다.',
            'notice'  => '중단한 과제는 역량 지표에 반영하지 않습니다.',
        ]);
    },
]);

// =====================================================================

/** 과제 번호. 없으면 400 으로 끊는다. */
function bs_rnd_project_param(): int
{
    $id = bs_param_int('id', 0);
    if (!$id) {
        bs_json_error('MISSING_PARAM', '과제 번호가 없습니다.', 400);
    }
    return $id;
}

/**
 * 상세 한 벌 — 네 탭이 쓰는 것을 한 번에 돌려준다 (명세서 §8.3).
 *
 * 탭을 옮길 때마다 묻지 않게 한 번에 싣는다. 과제 하나의 참여자·기록·
 * 산출물은 많아야 수십 건이라 나눠 받을 이유가 없다.
 */
function bs_rnd_detail(RndRepo $repo, int $pid): array
{
    $r = $repo->find($pid);
    if (!$r) {
        bs_json_error('NOT_FOUND', '과제를 찾을 수 없습니다.', 404);
    }
    $rnd       = bs_present_rnd($r);
    $canManage = $rnd['can']['manage_team'];
    $svc       = new RndLoadService(bs_db());

    // 승인하는 사람이 발의자의 중단 이력을 **함께** 본다 (명세서 §9).
    // 발의 때 경고를 띄우고 끝내면, 정작 판단하는 사람은 모른 채 승인한다.
    $rnd['proposer_warning'] = $svc->proposalWarning((string)($r['proposer_id'] ?? ''), (int)$r['id']);
    $rnd['domains'] = $repo->domains($pid);

    return [
        'rnd'     => $rnd,
        'limits'  => $svc->limits(),
        'members' => array_map(
            static fn(array $m) => bs_present_rnd_member($m, $canManage),
            $repo->members($pid)
        ),
        'logs'    => array_map('bs_present_rnd_log', $repo->logs($pid)),
        'outputs' => array_map('bs_present_rnd_output', $repo->outputs($pid)),
    ];
}

/**
 * 합류 승인·반려를 당사자에게 알린다.
 *
 * 보내지 않고 **적재만 한다** — 발송 경로가 아직 없다
 * (sql/007_migration_notify_outbox.sql). 알림이 실패해도 승인은 이미 끝났다.
 *
 * @return int 적재한 건수
 */
function bs_rnd_notify_member(RndRepo $repo, int $pid, array $row, bool $approved, string $reason): int
{
    $r = $repo->find($pid);
    if (!$r) {
        return 0;
    }

    $head = '[' . $r['name'] . '] 합류 ' . ($approved ? '승인' : '반려');
    $lines = [$head, ''];
    $lines[] = $approved
        ? '합류가 승인되었습니다. 진행 기록과 산출물을 남길 수 있습니다.'
        : '합류가 반려되었습니다.';
    if (!$approved && $reason !== '') {
        $lines[] = '';
        $lines[] = '[사유] ' . $reason;
    }
    if ($approved) {
        $lines[] = '';
        $lines[] = '신고 점유율 ' . (float)$row['load_ratio'];
    }
    $body = implode("\n", $lines);

    $notices = [];
    $m = (new MemberRepo(bs_db()))->find((int)$row['member_id']);
    if ($m) {
        $slack = (string)($m['slack_handle'] ?? '');
        $mail  = (string)($m['email'] ?? '');
        // 슬랙과 메일 둘 다 접수한다. 어느 쪽이 살아 있을지 모른다.
        $notices[] = new Notice('slack', (int)$row['member_id'], $slack, $body, $head,
                                'rnd_member', (int)$row['id']);
        $notices[] = new Notice('email', (int)$row['member_id'], $mail, $body, $head,
                                'rnd_member', (int)$row['id']);
    }
    if (!$notices) {
        return 0;
    }

    $sent = (new OutboxNotifier(bs_db()))->send($notices);
    return (int)($sent['queued'] ?? 0);
}

// =====================================================================

/**
 * 발의·수정 입력 읽기.
 *
 * $partial 이면 **넘어온 키만** 돌려준다. 수정에서 빠진 칸을 null 로
 * 덮으면, 화면이 한 칸만 보냈을 때 나머지가 조용히 지워진다.
 */
function bs_read_rnd_input(bool $partial = false): array
{
    $keys = ['name', 'summary', 'notes', 'rnd_category', 'visibility',
             'dev_start', 'dev_end', 'load_cap', 'recruiting', 'status'];

    $out = [];

    // 분야 태그 — 역량 반영에서 **계열의 유일한 근거**다 (명세서 4.7).
    // 태그가 없으면 종료돼도 경험 범위에 반영되지 않는다.
    if (!$partial || bs_has_param('domain_ids')) {
        $out['domain_ids'] = array_map('intval', (array)bs_param_array('domain_ids'));
    }
    foreach ($keys as $k) {
        if ($partial && !bs_has_param($k)) {
            continue;
        }
        $out[$k] = match ($k) {
            'recruiting' => bs_param_str($k) === '1' || bs_param_str($k) === 'true',
            'load_cap'   => bs_param_str($k),
            default      => bs_param_str($k),
        };
    }

    // status 는 발의에서만 쓴다(draft 로 저장할지 바로 낼지). 수정에서는
    // 무시한다 — 상태 전이는 approve/reject 전용 경로다.
    if ($partial) {
        unset($out['status']);
    }
    return $out;
}
