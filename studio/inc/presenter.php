<?php
/**
 * 서버가 계산해 내려주는 권한 플래그와 가능한 액션 (CLAUDE.md · 명세서 §8.6).
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 화면이 스스로 판단하지 않는다                                     │
 * │                                                                  │
 * │ "이 사람이 이 과제를 볼 수 있는가", "승인 단추를 그릴 것인가" 를  │
 * │ 화면마다 다시 계산하면 반드시 어긋난다. 한 화면에서 고친 규칙이   │
 * │ 다른 화면에 전해지지 않고, 그 틈으로 샌다.                        │
 * │                                                                  │
 * │ 그래서 판정을 여기 한 곳에 모으고, 화면과 API 는 **결과만** 쓴다. │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 이 파일이 왜 inc/ 에 있는가 — 다른 presenter 함수(bs_present_project 등)는
 * 각 api/*.php 안에 있다. R&D 는 **보드 화면·발의 화면·API 세 진입점이 같은
 * 판정을 써야** 해서 공유 자리가 필요하다. 화면은 api/ 의 함수를 쓸 수 없다.
 */

declare(strict_types=1);

// =====================================================================
// 가시성 — Repo 레벨 강제의 실제 알맹이
// =====================================================================

/**
 * R&D 과제 조회에 덧붙일 가시성 조건.
 *
 * **모든 읽기 쿼리가 이것을 거쳐야 한다.** RndRepo 가 자기 모든 SELECT 에
 * 붙인다. 화면에서 거르는 것이 아니라 쿼리가 애초에 안 집어 온다.
 *
 *   private  발의자 본인과 관리자만
 *   open     로그인한 사람 누구나 (열람 + 합류)
 *   public   로그인한 사람 누구나 (열람만)
 *
 * @param  string $alias  bs_project 의 별칭. '' 이면 접두사 없이
 * @return array{0:string,1:array}  [WHERE 조각, 바인딩]
 */
function bs_rnd_visible_sql(string $alias = 'p'): array
{
    $pre = $alias === '' ? '' : $alias . '.';

    // 관리자는 전부 본다. 그래도 project_type 조건은 남긴다 —
    // 이 함수를 거친 쿼리가 프로젝트를 집어 오면 안 된다.
    if (bs_is_admin()) {
        return ["{$pre}project_type = 'rnd'", []];
    }

    $me = bs_current_user();
    if ($me === null) {
        // 미로그인은 아무것도 못 본다. 화면은 bs_require_login() 에서
        // 이미 걸리지만, 쿼리가 혼자 불려도 안전해야 한다.
        return ['1 = 0', []];
    }

    return [
        "{$pre}project_type = 'rnd'
         AND ({$pre}visibility IN ('open', 'public') OR {$pre}proposer_id = ?)",
        [(string)$me['id']],
    ];
}

/**
 * 이 과제를 볼 수 있는가. 이미 집어 온 행을 다시 검사할 때 쓴다.
 *
 * 쿼리가 막는 것이 1차 방어이고 이것은 2차다. 둘 다 둔다 —
 * 한쪽만 두면 새 쿼리를 더할 때 조용히 뚫린다.
 */
function bs_rnd_can_view(array $rnd): bool
{
    if (bs_is_admin()) {
        return true;
    }
    $me = bs_current_user();
    if ($me === null) {
        return false;
    }
    if (in_array((string)$rnd['visibility'], ['open', 'public'], true)) {
        return true;
    }
    return (string)$rnd['proposer_id'] === (string)$me['id'];
}

/** 발의자 본인인가. */
function bs_rnd_is_proposer(array $rnd): bool
{
    $me = bs_current_user();
    return $me !== null && (string)$rnd['proposer_id'] === (string)$me['id'];
}

/**
 * 고칠 수 있는가.
 *
 * 발의자는 **승인 전까지만** 고친다. 승인된 뒤에 범위와 점유율이 바뀌면
 * 승인한 사람이 본 것과 다른 것이 돌아간다. 관리자는 언제든 고친다.
 */
function bs_rnd_can_edit(array $rnd): bool
{
    if (bs_is_admin()) {
        return true;
    }
    return bs_rnd_is_proposer($rnd)
        && in_array((string)$rnd['status'], ['draft', 'proposed'], true);
}

/** 승인·반려할 수 있는가. 상태가 proposed 일 때만 의미가 있다. */
function bs_rnd_can_approve(array $rnd): bool
{
    return bs_can(BS_CAP_RND_APPROVE)
        && (string)$rnd['status'] === 'proposed';
}

// =====================================================================
// 표현
// =====================================================================

/**
 * 과제 한 건 → 화면이 그대로 쓰는 모양.
 *
 * 권한 플래그와 가능한 액션을 **서버가 붙여서** 내려준다.
 */
function bs_present_rnd(array $r, array $extra = []): array
{
    $status     = (string)$r['status'];
    $visibility = (string)$r['visibility'];
    $category   = (string)($r['rnd_category'] ?? '');

    $canEdit    = bs_rnd_can_edit($r);
    $canApprove = bs_rnd_can_approve($r);

    return [
        'id'              => (int)$r['id'],
        'code'            => $r['code'],
        'name'            => $r['name'],
        'summary'         => $r['summary'] ?? null,
        'notes'           => $r['notes'] ?? null,

        'status'          => $status,
        'status_label'    => BS_RND_STATUS[$status] ?? $status,
        'category'        => $category !== '' ? $category : null,
        'category_label'  => BS_RND_CATEGORY[$category] ?? null,
        'visibility'      => $visibility,
        'visibility_label' => BS_RND_VISIBILITY[$visibility] ?? $visibility,

        'proposer_id'     => $r['proposer_id'] ?? null,
        'proposer_name'   => $r['proposer_name'] ?? null,
        'approved_by'     => $r['approved_by'] ?? null,
        'approved_by_name' => $r['approved_by_name'] ?? null,
        'approved_at'     => bs_date($r['approved_at'] ?? null),

        'dev_start'       => $r['dev_start'] ?? null,
        'dev_end'         => $r['dev_end'] ?? null,
        'load_cap'        => isset($r['load_cap']) && $r['load_cap'] !== null
                             ? (float)$r['load_cap'] : null,
        'recruiting'      => (int)($r['recruiting'] ?? 0) === 1,

        'member_count'    => isset($r['member_count']) ? (int)$r['member_count'] : null,
        'output_count'    => isset($r['output_count']) ? (int)$r['output_count'] : null,
        'log_count'       => isset($r['log_count']) ? (int)$r['log_count'] : null,
        'interest_count'  => isset($r['interest_count']) ? (int)$r['interest_count'] : null,
        'last_log_at'     => bs_date($r['last_log_at'] ?? null),
        'stale'           => bs_rnd_is_stale($r),

        'created_at'      => bs_date($r['created_at'] ?? null),
        'updated_at'      => bs_date($r['updated_at'] ?? null),

        // ---- 내 참여 상태 (find() 가 함께 집어 온다) ----
        'my_status'       => $r['my_status'] ?? null,
        'my_status_label' => isset($r['my_status'])
                             ? (BS_RND_MEMBER_STATUS[$r['my_status']] ?? null) : null,
        'my_role'         => $r['my_role'] ?? null,
        'my_interest'     => (int)($r['my_interest'] ?? 0) > 0,

        // ---- 서버가 계산한 권한 ----
        'is_proposer'     => bs_rnd_is_proposer($r),
        'is_lead'         => bs_rnd_is_lead($r),
        'can'             => [
            'edit'         => $canEdit,
            'approve'      => $canApprove,
            'reject'       => $canApprove,
            'join'         => bs_rnd_can_join($r),
            'leave'        => bs_rnd_can_leave($r),
            'manage_team'  => bs_rnd_can_manage_team($r),
            'write_log'    => bs_rnd_can_contribute($r),
            'add_output'   => bs_rnd_can_contribute($r),
            'finish'       => bs_rnd_can_close($r),
            'drop'         => bs_rnd_can_close($r),
            'interest'     => true,
        ],
        'actions'         => bs_rnd_actions($r, $canEdit, $canApprove),
    ] + $extra;
}

/** 이 과제를 주도하는 사람인가. */
function bs_rnd_is_lead(array $rnd): bool
{
    return (string)($rnd['my_role'] ?? '') === 'lead'
        && (string)($rnd['my_status'] ?? '') === 'approved';
}

/** 팀을 다룰 수 있는가 — 승인·반려. lead 이거나 관리자. */
function bs_rnd_can_manage_team(array $rnd): bool
{
    return bs_is_admin() || bs_rnd_is_lead($rnd);
}

/**
 * 기록·산출물을 남길 수 있는가. **승인된 참여자만.**
 *
 * 관리자라고 남의 과제에 진행 기록을 쓰지는 않는다 — 기록은 한 일을
 * 적는 자리이지 관리 권한의 자리가 아니다.
 */
function bs_rnd_can_contribute(array $rnd): bool
{
    return (string)($rnd['my_status'] ?? '') === 'approved'
        && in_array((string)$rnd['status'], ['approved', 'running'], true);
}

/** 종료·중단할 수 있는가. lead 이거나 관리자이고, 아직 돌아가는 과제여야 한다. */
function bs_rnd_can_close(array $rnd): bool
{
    return bs_rnd_can_manage_team($rnd)
        && in_array((string)$rnd['status'], ['approved', 'running'], true);
}

/**
 * 합류를 신청할 수 있는가.
 *
 * 받는 상태(§8.4)이면서, **내가 아직 안 붙어 있어야** 한다.
 * 이미 신청했거나 참여 중이면 단추를 그리지 않는다.
 */
function bs_rnd_can_join(array $rnd): bool
{
    if (!bs_rnd_join_open($rnd)) {
        return false;
    }
    return !in_array((string)($rnd['my_status'] ?? ''), ['requested', 'approved'], true);
}

/** 나갈 수 있는가. 참여 중이고 주도자가 아니어야 한다. */
function bs_rnd_can_leave(array $rnd): bool
{
    return in_array((string)($rnd['my_status'] ?? ''), ['requested', 'approved'], true)
        && (string)($rnd['my_role'] ?? '') !== 'lead';
}

/** 참여자 한 명. */
function bs_present_rnd_member(array $m, bool $canManage): array
{
    $status = (string)$m['status'];
    return [
        'id'            => (int)$m['id'],
        'member_id'     => (int)$m['member_id'],
        'emp_name'      => $m['emp_name'],
        'role_label'    => $m['role_label'] ?? null,
        'role'          => (string)$m['role'],
        'role_name'     => BS_RND_MEMBER_ROLE[$m['role']] ?? $m['role'],
        'load_ratio'    => (float)$m['load_ratio'],
        'status'        => $status,
        'status_label'  => BS_RND_MEMBER_STATUS[$status] ?? $status,
        'join_reason'   => $m['join_reason'] ?? null,
        'reject_reason' => $m['reject_reason'] ?? null,
        'approved_by_name' => $m['approved_by_name'] ?? null,
        'approved_at'   => bs_date($m['approved_at'] ?? null),
        'joined_at'     => bs_date($m['joined_at'] ?? null),
        'left_at'       => bs_date($m['left_at'] ?? null),
        // 신청 상태인 사람에게만 승인·반려 단추가 의미 있다.
        'can'           => [
            'approve' => $canManage && $status === 'requested',
            'reject'  => $canManage && $status === 'requested',
        ],
    ];
}

/** 진행 기록 한 건. content 와 finding 을 끝까지 나눠 둔다 (명세서 §8.3). */
function bs_present_rnd_log(array $l): array
{
    return [
        'id'         => (int)$l['id'],
        'member_id'  => (int)$l['member_id'],
        'emp_name'   => $l['emp_name'],
        'content'    => $l['content'],
        'finding'    => $l['finding'] ?? null,
        'worked_on'  => $l['worked_on'] ?? null,
        'created_at' => bs_date($l['created_at'] ?? null),
    ];
}

/** 산출물 한 건. */
function bs_present_rnd_output(array $o): array
{
    $kind = (string)$o['kind'];
    return [
        'id'         => (int)$o['id'],
        'kind'       => $kind,
        'kind_label' => BS_RND_OUTPUT_KIND[$kind] ?? $kind,
        'title'      => $o['title'],
        'url'        => bs_safe_url($o['url'] ?? null),
        // file_path 는 **내보내지 않는다.** 서버 안쪽 경로다.
        'has_file'   => !empty($o['file_path']),
        'summary'    => $o['summary'] ?? null,
        'created_by_name' => $o['created_by_name'] ?? null,
        'created_at' => bs_date($o['created_at'] ?? null),
    ];
}

/** 목록 카드에 올릴 만큼만. 상세 전용 칸은 싣지 않는다. */
function bs_present_rnd_row(array $r): array
{
    $full = bs_present_rnd($r);
    unset($full['notes'], $full['approved_by'], $full['approved_by_name']);
    return $full;
}

/**
 * 지금 이 사람이 누를 수 있는 것.
 *
 * 화면은 이 배열만 보고 단추를 그린다. 목록에 없는 단추는 그리지 않는다.
 */
function bs_rnd_actions(array $rnd, bool $canEdit, bool $canApprove): array
{
    $out = [];
    if ($canEdit)                     { $out[] = 'update'; }
    if ($canApprove)                  { $out[] = 'approve'; $out[] = 'reject'; }
    if (bs_rnd_can_join($rnd))        { $out[] = 'join'; }
    if (bs_rnd_can_leave($rnd))       { $out[] = 'leave'; }
    if (bs_rnd_can_contribute($rnd))  { $out[] = 'log'; $out[] = 'output'; }
    if (bs_rnd_can_close($rnd))       { $out[] = 'finish'; $out[] = 'drop'; }
    return $out;
}

/**
 * 합류 신청을 받는 상태인가.
 *
 * 공개 범위가 open 이고, 모집 중이고, 살아 있는 과제여야 한다.
 * **승인 전 과제에는 합류할 수 없다** — 승인되지 않은 과제에 사람이 붙으면
 * 점유 통제(§9)가 뒤에서 열린다.
 */
function bs_rnd_join_open(array $rnd): bool
{
    return in_array((string)$rnd['visibility'], BS_RND_JOINABLE, true)
        && (int)($rnd['recruiting'] ?? 0) === 1
        && in_array((string)$rnd['status'], ['approved', 'running'], true);
}

/**
 * 정체 판정 — 진행 기록이 4주 넘게 없는가 (CLAUDE.md 2항).
 *
 * 아직 안 돌아가는 과제(승인 전)는 정체가 아니다. 종료된 것도 아니다.
 */
function bs_rnd_is_stale(array $rnd): bool
{
    if (!in_array((string)($rnd['status'] ?? ''), ['approved', 'running'], true)) {
        return false;
    }
    $last = $rnd['last_log_at'] ?? null;
    if ($last === null) {
        // 기록이 하나도 없으면 승인 시점을 기준으로 본다.
        $last = $rnd['approved_at'] ?? null;
    }
    if ($last === null) {
        return false;
    }
    return strtotime((string)$last) < strtotime('-28 day');
}
