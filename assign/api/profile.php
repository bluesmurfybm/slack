<?php
/**
 * 구성원 프로파일 API — 역량 조회, 근거 추적, 이의 제기. 열람 권한이 가장 민감한 엔드포인트다.
 *
 * GET  api/profile.php?act=view&member_id=1     (생략하면 본인)
 * GET  api/profile.php?act=evidence&member_id=1&category=activity
 * GET  api/profile.php?act=objections           이의 목록 (관리자)
 * POST api/profile.php?act=objection            이의 제기
 * POST api/profile.php?act=review_objection     이의 처리 (관리자)
 * POST api/profile.php?act=adjust               관리자 보정치
 * POST api/profile.php?act=set_evaluable        평가 대상 전환 (관리자)
 *
 * **전사 랭킹은 내지 않는다** (명세서 §1.4). 이 파일에 여러 사람의 점수를
 * 한 번에 돌려주는 엔드포인트를 만들지 말 것.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once BA_ROOT . '/inc/repo/MemberRepo.php';

$repo = new MemberRepo(ba_db());

ba_route(ba_param_str('act', 'view'), [

    /**
     * 프로파일 조회.
     *
     * ┌──────────────────────────────────────────────────────────────┐
     * │ 열람 규칙 (CLAUDE.md)                                         │
     * │   · 본인은 자기 프로파일을 **항상** 볼 수 있다                 │
     * │   · 남의 것은 PM/관리자만                                     │
     * │   · 모든 점수에 근거가 따라붙어야 한다                         │
     * └──────────────────────────────────────────────────────────────┘
     */
    'view' => function () use ($repo): void {
        $user   = ba_require_login_api();
        $target = ba_resolve_profile_target($repo, ba_param_int('member_id', 0), $user);

        $evalVer = $repo->latestEvalVer();
        if ($evalVer === null) {
            ba_json_ok([
                'member'    => ba_present_member($target),
                'eval_ver'  => null,
                'message'   => '아직 역량 판정을 돌린 적이 없습니다.',
                'categories' => [], 'metric' => null, 'domains' => [],
            ]);
        }

        ba_json_ok([
            'member'     => ba_present_member($target),
            'eval_ver'   => $evalVer,
            'eval_run'   => $repo->evalRun($evalVer),
            'categories' => $repo->categoryScores((int)$target['id'], $evalVer),
            'metric'     => $repo->metric((int)$target['id'], $evalVer),
            'domains'    => $repo->skills((int)$target['id'], $evalVer),
            'min_sample' => BA_MIN_SAMPLE,
            'labels'     => BA_DOMAIN_CATEGORY,
            'is_self'    => $target['user_id'] === $user['id'],
            'can'        => [
                'adjust'    => ba_can(BA_CAP_EVAL_RUN),
                'objection' => $target['user_id'] === $user['id'],
            ],
        ]);
    },

    /**
     * 근거 목록.
     *
     * CLAUDE.md: "모든 점수는 원천 근거(슬랙 건 / 메일 스레드 링크)로 역추적
     * 가능해야 한다." 화면에서 점수를 누르면 이 목록이 떠야 한다.
     */
    'evidence' => function () use ($repo): void {
        $user   = ba_require_login_api();
        $target = ba_resolve_profile_target($repo, ba_param_int('member_id', 0), $user);

        $category = ba_param_str('category');
        $domainId = ba_param_int('domain_id');
        if ($category === '' && !$domainId) {
            ba_json_error('MISSING_PARAM', '계열 또는 분야를 지정하세요.', 400);
        }

        $evalVer = $repo->latestEvalVer();
        $rows    = $repo->evidence((int)$target['id'], $category, $domainId, $evalVer);

        ba_json_ok([
            'member_id' => (int)$target['id'],
            'category'  => $category,
            'domain_id' => $domainId,
            'label'     => $category !== ''
                             ? (BA_DOMAIN_CATEGORY[$category]['label'] ?? $category)
                             : null,
            'total'     => count($rows),
            'rows'      => array_map('ba_present_evidence', $rows),
        ]);
    },

    'objections' => function () use ($repo): void {
        ba_require_login_api();
        ba_require_cap_api(BA_CAP_OBJECTION_REVIEW);
        ba_json_ok(['rows' => $repo->objections(['status' => ba_param_str('status')])]);
    },

    /**
     * 이의 제기. **본인만** 자기 프로파일에 대해 낼 수 있다.
     */
    'objection' => function () use ($repo): void {
        $user    = ba_begin_write();
        $content = ba_param_str('content');
        if ($content === '') {
            ba_json_error('MISSING_PARAM', '이의 내용을 입력하세요.', 400);
        }

        $me = $repo->findByUserId($user['id']);
        if (!$me) {
            ba_json_error('FORBIDDEN', '구성원으로 등록되어 있지 않습니다.', 403);
        }

        // 남의 member_id 를 받아 대신 제기하는 경로를 만들지 않는다.
        $id = $repo->addObjection((int)$me['id'], [
            'eval_ver'  => ba_param_int('eval_ver') ?: $repo->latestEvalVer(),
            'domain_id' => ba_param_int('domain_id'),
            'content'   => $content,
        ]);

        // TODO(P6): 관리자 알림 — 알림 경로는 conventions.md §9-3 결정 후.
        ba_json_ok(['id' => $id, 'message' => '이의를 접수했습니다. 검토 후 알려드립니다.']);
    },

    'review_objection' => function () use ($repo): void {
        $user        = ba_begin_write();
        $objectionId = ba_param_int('objection_id', 0);
        $status      = ba_param_str('status');
        if (!$objectionId) {
            ba_json_error('MISSING_PARAM', '이의 번호가 없습니다.', 400);
        }
        ba_require_cap_api(BA_CAP_OBJECTION_REVIEW);
        if (!in_array($status, ['reviewed', 'applied', 'rejected'], true)) {
            ba_json_error('INVALID_ARGUMENT', '처리 상태가 올바르지 않습니다.', 400);
        }

        $repo->reviewObjection($objectionId, $status, ba_param_str('note'), $user);
        ba_json_ok(['message' => '이의를 처리했습니다.']);
    },

    /**
     * 관리자 보정치. 사유 없이 점수를 손대지 못하게 한다.
     */
    'adjust' => function () use ($repo): void {
        $user     = ba_begin_write();
        $memberId = ba_param_int('member_id', 0);
        $reason   = ba_param_str('reason');
        $adjust   = (float)ba_param_str('adjust', '0');

        if (!$memberId) {
            ba_json_error('MISSING_PARAM', '구성원을 지정하세요.', 400);
        }
        if ($reason === '') {
            ba_json_error('MISSING_PARAM', '보정 사유를 입력하세요. 본인에게 표시됩니다.', 400);
        }
        ba_require_cap_api(BA_CAP_EVAL_RUN);

        if ($adjust < BA_ADJUST_MIN || $adjust > BA_ADJUST_MAX) {
            ba_json_error('OUT_OF_RANGE',
                sprintf('보정치는 %+.0f ~ %+.0f 사이여야 합니다.', BA_ADJUST_MIN, BA_ADJUST_MAX), 400);
        }

        $evalVer = $repo->latestEvalVer();
        if ($evalVer === null) {
            ba_json_error('NOT_FOUND', '보정할 판정 회차가 없습니다.', 404);
        }
        $repo->applyManualAdjust($memberId, $evalVer, $adjust, $reason, $user);
        ba_json_ok(['message' => '보정치를 반영했습니다.']);
    },

    /**
     * 평가 대상 전환.
     * `is_assignable` 과 섞지 말 것 — 여기서 제외해도 배정 후보에서는 빠지지 않는다.
     */
    'set_evaluable' => function () use ($repo): void {
        $user     = ba_begin_write();
        $memberId = ba_param_int('member_id', 0);
        if (!$memberId) {
            ba_json_error('MISSING_PARAM', '구성원을 지정하세요.', 400);
        }
        ba_require_cap_api(BA_CAP_EVAL_RUN);

        $evaluable = ba_param_str('evaluable') === '1';
        $reason    = ba_param_str('reason');
        if (!$evaluable && $reason === '') {
            ba_json_error('MISSING_PARAM', '평가 제외 사유를 입력하세요. 본인에게 표시됩니다.', 400);
        }

        $repo->setEvaluable($memberId, $evaluable, $reason, $user);
        ba_json_ok([
            'member_id' => $memberId,
            'evaluable' => $evaluable,
            'message'   => $evaluable ? '평가 대상으로 되돌렸습니다.' : '평가 대상에서 제외했습니다.',
        ]);
    },
]);


// =====================================================================
// 공통
// =====================================================================

/**
 * 볼 대상을 정하고 권한을 확인한다.
 *
 * member_id 를 생략하면 본인이다. 본인은 항상 통과한다(CLAUDE.md).
 * 통과하지 못하면 여기서 끊긴다 — 호출부는 반환값만 쓰면 된다.
 */
function ba_resolve_profile_target(MemberRepo $repo, int $memberId, array $user): array
{
    $target = $memberId > 0
        ? $repo->find($memberId)
        : $repo->findByUserId($user['id']);

    if (!$target) {
        ba_json_error('NOT_FOUND',
            $memberId > 0 ? '구성원을 찾을 수 없습니다.' : '구성원으로 등록되어 있지 않습니다.', 404);
    }
    if (!ba_can_view_profile((string)$target['user_id'])) {
        ba_json_error('FORBIDDEN', '본인 또는 PM·관리자만 볼 수 있습니다.', 403);
    }
    return $target;
}

/** 구성원 기본 정보 + 평가 대상 여부. */
function ba_present_member(array $m): array
{
    $evaluable = (int)($m['is_evaluable'] ?? 1) === 1;
    return [
        'id'            => (int)$m['id'],
        'user_id'       => $m['user_id'],
        'emp_name'      => $m['emp_name'],
        'role_label'    => $m['role_label'] ?? null,
        'team'          => $m['team'] ?? null,
        'career_months' => (int)($m['career_months'] ?? 0),
        'is_assignable' => (int)($m['is_assignable'] ?? 1) === 1,
        // 평가 제외와 표본 부족은 **다른 말**이다. 화면이 구분해 보여줘야 한다.
        'is_evaluable'  => $evaluable,
        'exclude_reason' => $evaluable ? null : ($m['eval_exclude_reason'] ?? null),
    ];
}

/**
 * 근거 한 건.
 * `source_url` 이 빠지면 근거로 쓸 수 없다 — 반드시 싣는다.
 */
function ba_present_evidence(array $r): array
{
    return [
        'id'           => (int)$r['id'],
        'title'        => $r['title'],
        'source_url'   => $r['source_url'],
        'org_name'     => $r['org_name'],
        'status_raw'   => $r['status_raw'],
        'difficulty'   => $r['difficulty'] !== null ? (int)$r['difficulty'] : null,
        'difficulty_by' => $r['difficulty_by'],
        'msg_count'    => (int)($r['msg_count'] ?? 0),
        'requested_at' => ba_date($r['requested_at']),
        'closed_at'    => ba_date($r['closed_at']),
        'domains'      => $r['domains'] ?? null,
    ];
}
