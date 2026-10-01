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

        // ---- 서버가 계산한 권한 ----
        'is_proposer'     => bs_rnd_is_proposer($r),
        'can'             => [
            'edit'    => $canEdit,
            'approve' => $canApprove,
            'reject'  => $canApprove,
            // 합류는 P9-3 범위다. 지금은 "받을 수 있는 상태인가" 만 알려 준다.
            'join'    => bs_rnd_join_open($r),
        ],
        'actions'         => bs_rnd_actions($r, $canEdit, $canApprove),
    ] + $extra;
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
    if ($canEdit) {
        $out[] = 'update';
    }
    if ($canApprove) {
        $out[] = 'approve';
        $out[] = 'reject';
    }
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
