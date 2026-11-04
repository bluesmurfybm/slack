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
    $status = (string)$rnd['status'];
    if (bs_rnd_is_proposer($rnd) && in_array($status, ['draft', 'proposed'], true)) {
        return true;
    }
    // ┌──────────────────────────────────────────────────────────────┐
    // │ 승인 뒤 — 주도자와 수정 권한을 받은 참여자 (2026-10-14)       │
    // │                                                              │
    // │ 기간·점유율까지 고칠 수 있다(사람이 정했다). 그래도 사람별     │
    // │ 점유는 **승인된 참여율**로만 올라가므로 승인 통제(§9.1)는 그대로│
    // │ 다 — 바뀐 기간은 update() 가 점유에 다시 맞춘다. 끝난 과제     │
    // │ (done·dropped)는 기록이므로 관리자만 고친다.                    │
    // └──────────────────────────────────────────────────────────────┘
    return in_array($status, ['approved', 'running'], true)
        && (string)($rnd['my_status'] ?? '') === 'approved'
        && ((string)($rnd['my_role'] ?? '') === 'lead' || !empty($rnd['my_can_edit']));
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
            'delete'       => bs_rnd_can_delete($r),
            'restore'      => bs_rnd_can_purge($r),
            'purge'        => bs_rnd_can_purge($r),
            'interest'     => !bs_rnd_is_deleted($r),
            // 승인된 과제를 프로젝트로 (032)
            'promote_project' => bs_rnd_can_promote_project($r),
        ],
        // 삭제 상태 (2026-10-16). 관리자가 [삭제 포함] 으로 볼 때만 이 값이 선다.
        'deleted'         => bs_rnd_is_deleted($r),
        'deleted_at'      => bs_date($r['deleted_at'] ?? null, 'Y-m-d H:i'),
        'deleted_by_name' => $r['deleted_by_name'] ?? null,
        'delete_reason'   => $r['delete_reason'] ?? null,
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

/**
 * 종료·중단할 수 있는가. 발의자·lead 이거나 관리자이고, 아직 돌아가는 과제여야 한다.
 *
 * 발의자도 넣는다(2026-10-16) — 발의자는 발의할 때 승인된 lead 로 붙으므로
 * 실제로는 같은 사람이다. 다만 보드 카드는 내 역할(my_role)을 싣지 않아
 * lead 판정만으로는 카드에서 [중단] 이 안 보였다.
 */
function bs_rnd_can_close(array $rnd): bool
{
    return !bs_rnd_is_deleted($rnd)
        && (bs_rnd_can_manage_team($rnd) || bs_rnd_is_proposer($rnd))
        && in_array((string)$rnd['status'], ['approved', 'running'], true);
}

/** 삭제 상태인가 (deleted_at). */
function bs_rnd_is_deleted(array $rnd): bool
{
    return !empty($rnd['deleted_at']);
}

/**
 * 삭제(삭제 상태로 바꾸기)할 수 있는가 (2026-10-16).
 *
 *   관리자      언제든
 *   발의자·lead **시작 전에만** — 작성 중·발의됨이거나, 다른 참여자·진행 기록·
 *               산출물이 하나도 없을 때. 이미 굴러간 과제는 [중단] 으로 끝낸다.
 *               발의자가 남의 기록까지 한 번에 감추지 못하게 하려는 것이다.
 */
function bs_rnd_can_delete(array $rnd): bool
{
    if (bs_rnd_is_deleted($rnd)) {
        return false;
    }
    if (bs_is_admin()) {
        return true;
    }
    if (!bs_rnd_is_proposer($rnd) && !bs_rnd_is_lead($rnd)) {
        return false;
    }
    if (in_array((string)$rnd['status'], ['draft', 'proposed'], true)) {
        return true;
    }
    return (int)($rnd['member_count'] ?? 0) <= 1
        && (int)($rnd['log_count'] ?? 0) === 0
        && (int)($rnd['output_count'] ?? 0) === 0;
}

/** 복구·영구삭제 — 관리자만, 삭제 상태인 과제만. */
function bs_rnd_can_purge(array $rnd): bool
{
    return bs_is_admin() && bs_rnd_is_deleted($rnd);
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
        // 수정 권한 (026). 주도자는 칸과 무관하게 언제나 고친다.
        'can_edit'      => (string)$m['role'] === 'lead' || !empty($m['can_edit']),
        'edit_granted_by' => $m['edit_granted_by'] ?? null,
        // 신청 상태인 사람에게만 승인·반려 단추가 의미 있다.
        'can'           => [
            'approve' => $canManage && $status === 'requested',
            'reject'  => $canManage && $status === 'requested',
            // 승인된 일반 참여자에게만 켜고 끈다. 주도자는 늘 켜져 있다.
            'grant_edit' => $canManage && $status === 'approved' && (string)$m['role'] !== 'lead',
        ],
    ];
}

/**
 * 진행 기록·산출물을 고치고 지울 수 있는가 (2026-10-19).
 *
 * 작성자·주도자·관리자, **과제 상태와 무관하게 언제든** (사용자 결정).
 * 종료된 과제의 산출물을 모두 지우면 '산출물 1건 이상' 이 깨진 채 남는다 —
 * 화면이 지울 때 그 점을 알린다.
 */
function bs_rnd_can_edit_item(array $rnd, string $authorId): bool
{
    if (bs_is_admin() || bs_rnd_is_lead($rnd)) {
        return true;
    }
    $me = bs_current_user();
    return $me !== null && $authorId !== '' && $authorId === (string)$me['id'];
}

/** 첨부 한 건 (bs_rnd_file). file_path 는 내보내지 않는다. */
function bs_present_rnd_file(array $f): array
{
    require_once BS_ROOT . '/inc/service/SourceUploader.php';   // humanSize()
    $mime = (string)($f['mime'] ?? '');
    return [
        'id'         => (int)$f['id'],
        'name'       => $f['orig_name'],
        'mime'       => $mime,
        'size_label' => isset($f['file_size']) ? SourceUploader::humanSize((int)$f['file_size']) : null,
        'viewable'   => in_array($mime, BS_VIEWABLE_MIME, true),
        'url'        => 'api/rnd_file.php?id=' . (int)$f['id'],
        'uploaded_by_name' => $f['uploaded_by_name'] ?? null,
    ];
}

/**
 * 진행 기록 한 건. content 와 finding 을 끝까지 나눠 둔다 (명세서 §8.3).
 * $rnd 를 주면 고치기·지우기 판정을, $files 를 주면 첨부를 함께 싣는다 (2026-10-19).
 */
function bs_present_rnd_log(array $l, ?array $rnd = null, array $files = []): array
{
    return [
        'id'         => (int)$l['id'],
        'member_id'  => (int)$l['member_id'],
        'emp_name'   => $l['emp_name'],
        'content'    => $l['content'],
        'finding'    => $l['finding'] ?? null,
        'worked_on'  => $l['worked_on'] ?? null,
        'url'        => bs_safe_url($l['url'] ?? null),      // 031
        'created_at' => bs_date($l['created_at'] ?? null),
        'updated_at' => bs_date($l['updated_at'] ?? null, 'Y-m-d H:i'),
        'updated_by_name' => $l['updated_by_name'] ?? null,
        'files'      => array_map('bs_present_rnd_file', $files),
        'can'        => ['edit' => $rnd !== null && bs_rnd_can_edit_item($rnd, (string)($l['author_id'] ?? ''))],
    ];
}

/** 산출물 한 건. $rnd·$files 는 진행 기록과 같다 (2026-10-19). */
function bs_present_rnd_output(array $o, ?array $rnd = null, array $files = []): array
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
        'updated_at' => bs_date($o['updated_at'] ?? null, 'Y-m-d H:i'),
        'updated_by_name' => $o['updated_by_name'] ?? null,
        'files'      => array_map('bs_present_rnd_file', $files),
        'can'        => ['edit' => $rnd !== null && bs_rnd_can_edit_item($rnd, (string)($o['author_id'] ?? ''))],
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
    // 삭제된 과제는 되살리거나 영구히 지우는 것 말고는 하지 않는다.
    if (bs_rnd_is_deleted($rnd)) {
        return bs_rnd_can_purge($rnd) ? ['restore', 'purge'] : [];
    }
    $out = [];
    if ($canEdit)                     { $out[] = 'update'; }
    if ($canApprove)                  { $out[] = 'approve'; $out[] = 'reject'; }
    if (bs_rnd_can_join($rnd))        { $out[] = 'join'; }
    if (bs_rnd_can_leave($rnd))       { $out[] = 'leave'; }
    if (bs_rnd_can_contribute($rnd))  { $out[] = 'log'; $out[] = 'output'; }
    if (bs_rnd_can_close($rnd))       { $out[] = 'finish'; $out[] = 'drop'; }
    if (bs_rnd_can_delete($rnd))      { $out[] = 'delete'; }
    if (bs_rnd_can_promote_project($rnd)) { $out[] = 'promote_project'; }
    return $out;
}

/**
 * R&D 과제를 프로젝트로 넘길 수 있는가 (032).
 *
 * 승인된 뒤(승인됨·진행 중·종료)에만, 주도자·발의자나 관리자가, 프로젝트
 * 등록 권한(027)이 있을 때. 승인 전 과제를 프로젝트로 넘기면 과제 승인
 * 통제를 옆길로 돌아가는 셈이다.
 */
function bs_rnd_can_promote_project(array $rnd): bool
{
    if (bs_rnd_is_deleted($rnd) || !in_array($rnd['status'], ['approved', 'running', 'done'], true)) {
        return false;
    }
    if (!bs_can(BS_CAP_PROJECT_CREATE)) {
        return false;
    }
    return bs_is_admin() || bs_rnd_is_lead($rnd) || bs_rnd_is_proposer($rnd);
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
 * 정체 판정 — 진행 기록이 설정된 기간 넘게 없는가 (명세서 §9, CLAUDE.md 2항).
 *
 * 기간은 `rnd_stale_weeks` 설정값이다. **코드에 박지 않는다**(§9.2).
 * 판정 자체는 RndLoadService 가 들고 있고 여기서는 그것을 부른다 —
 * 보드와 상세가 서로 다른 기준으로 "조용함" 을 말하면 안 된다.
 */
function bs_rnd_is_stale(array $rnd): bool
{
    require_once __DIR__ . '/service/RndLoadService.php';
    return (new RndLoadService(bs_db()))->isStale($rnd);
}

/**
 * 출처 문서(참고자료 포함) 한 건 → 화면 모양. 프로젝트와 R&D 과제가 같이 쓴다.
 * file_path 는 절대 내보내지 않는다 — 서버 디렉터리 구조를 알려 줄 이유가 없다.
 *
 * has_file · viewable 은 화면이 [보기]·[내려받기] 를 그릴지 정하는 데 쓴다.
 * viewable 은 브라우저가 바로 그리는 형식(이미지·PDF)인가.
 */
function bs_present_source(array $r): array
{
    require_once BS_ROOT . '/inc/service/SourceUploader.php';   // humanSize()
    $hasFile = !empty($r['has_file']);
    $mime    = (string)($r['mime'] ?? '');
    return [
        'id'           => (int)$r['id'],
        'purpose'      => $r['purpose'] ?? 'scope',
        'kind'         => $r['kind'],
        'kind_label'   => BS_SOURCE_KIND[$r['kind']] ?? $r['kind'],
        'title'        => $r['title'],
        'url'          => $r['url'],
        'file_size'    => isset($r['file_size']) ? (int)$r['file_size'] : null,
        'size_label'   => isset($r['file_size']) && $r['file_size'] !== null
                            ? SourceUploader::humanSize((int)$r['file_size']) : null,
        'mime'         => $r['mime'],
        'has_file'     => $hasFile,
        'viewable'     => $hasFile && in_array($mime, BS_VIEWABLE_MIME, true),
        'parse_status' => $r['parse_status'],
        'parse_error'  => $r['parse_error'] ?? null,
        'parsed_len'   => isset($r['parsed_len']) ? (int)$r['parsed_len'] : null,
        'uploaded_by'  => $r['uploaded_by_name'] ?? null,
        'created_at'   => bs_date($r['created_at'], 'Y-m-d H:i'),
    ];
}


// =====================================================================
// 아이디어·제안 (032)
//
// 볼 수 있는 범위는 없다 — 로그인한 사람 누구나 본다. 나누려고 올리는
// 곳이라서다. 대신 **익명**이 있다. 작성자는 관리자와 본인만 본다.
// =====================================================================

/** 내가 올린 아이디어인가. 익명이어도 author_id 는 채워져 있다. */
function bs_idea_is_author(array $idea): bool
{
    $me = bs_current_user();
    return $me !== null && (string)($idea['author_id'] ?? '') === (string)$me['id'];
}

function bs_idea_is_deleted(array $idea): bool
{
    return !empty($idea['deleted_at']);
}

/**
 * 본문을 고칠 수 있는가.
 *
 * 채택·반려·과제화 뒤에는 잠근다. 평가와 피드백은 그때 본문을 보고 한
 * 것이라, 뒤에서 본문이 바뀌면 남은 평가가 다른 글에 붙은 셈이 된다.
 */
function bs_idea_can_edit(array $idea): bool
{
    if (bs_idea_is_deleted($idea)) {
        return false;
    }
    if (bs_is_admin()) {
        return $idea['status'] !== 'promoted';
    }
    return bs_idea_is_author($idea)
        && in_array($idea['status'], ['open', 'reviewing', 'hold'], true);
}

/** 지울 수 있는가. 과제·프로젝트로 넘어간 것은 출발점이라 남긴다. */
function bs_idea_can_delete(array $idea): bool
{
    if (bs_idea_is_deleted($idea) || $idea['status'] === 'promoted') {
        return false;
    }
    return bs_is_admin() || bs_idea_is_author($idea);
}

/** 평가할 수 있는가. 자기 아이디어는 평가하지 않는다. */
function bs_idea_can_rate(array $idea): bool
{
    return bs_current_user() !== null
        && !bs_idea_is_deleted($idea)
        && !bs_idea_is_author($idea)
        && in_array($idea['status'], ['open', 'reviewing', 'adopted', 'hold'], true)
        && empty($idea['duplicate_of']);   // 묶인 쪽은 원래 아이디어에 모은다
}

/** 피드백은 넘어간 뒤에도 받는다 — 과제에서 참고할 말이 붙는다. */
function bs_idea_can_comment(array $idea): bool
{
    return bs_current_user() !== null && !bs_idea_is_deleted($idea);
}

/** 검토(채택·보류·반려)·묶기. */
function bs_idea_can_review(array $idea): bool
{
    return !bs_idea_is_deleted($idea)
        && $idea['status'] !== 'promoted'
        && bs_can(BS_CAP_IDEA_REVIEW);
}

/**
 * R&D 과제로 올릴 수 있는가.
 *
 * 과제 발의는 원래 누구나 한다. 다만 **남의 아이디어**는 채택된 뒤에만
 * 가져간다 — 올린 사람이 다듬는 중인 생각을 다른 사람이 먼저 과제로
 * 만들어 버리면 제안할 마음이 꺾인다. 올린 사람은 언제든 올린다.
 * 올린 뒤에도 과제는 **발의됨**에서 시작한다. 승인을 건너뛰지 않는다.
 */
function bs_idea_can_promote_rnd(array $idea): bool
{
    if (bs_idea_is_deleted($idea) || in_array($idea['status'], ['promoted', 'rejected'], true)
        || !empty($idea['duplicate_of']) || bs_current_user() === null) {
        return false;
    }
    return bs_is_admin() || bs_idea_is_author($idea) || $idea['status'] === 'adopted';
}

/**
 * 프로젝트로 바로 올릴 수 있는가 — 과제 단계를 건너뛰는 길.
 * 프로젝트 등록 권한(027)이 있어야 하고, 관리자가 아니면 채택된 것만.
 */
function bs_idea_can_promote_project(array $idea): bool
{
    if (bs_idea_is_deleted($idea) || in_array($idea['status'], ['promoted', 'rejected'], true)
        || !empty($idea['duplicate_of'])) {
        return false;
    }
    if (!bs_can(BS_CAP_PROJECT_CREATE)) {
        return false;
    }
    return bs_is_admin() || $idea['status'] === 'adopted';
}

/**
 * 전환이 막힌 까닭 (2026-11-03). 단추를 숨기기만 하면 "전환 기능이 없다"
 * 로 읽힌다 — 늘 그리고, 못 누르면 왜 못 누르는지 적는다.
 * 누를 수 있으면 null. 넘어간 것·지운 것은 패널 자체를 그리지 않으므로 null.
 *
 * @return array{rnd:?string, project:?string}
 */
function bs_idea_promote_hint(array $idea): array
{
    if (bs_idea_is_deleted($idea) || $idea['status'] === 'promoted') {
        return ['rnd' => null, 'project' => null];
    }
    $common = match (true) {
        $idea['status'] === 'rejected'  => '반려된 아이디어는 전환하지 않습니다.',
        !empty($idea['duplicate_of'])   => '묶인 아이디어입니다. 원래 아이디어에서 전환하세요.',
        default                         => null,
    };
    $rnd = $common ?? (bs_idea_can_promote_rnd($idea) ? null
        : '채택되면 누구나 전환할 수 있습니다. 그 전에는 올린 사람과 관리자만 합니다.');
    $prj = $common ?? match (true) {
        bs_idea_can_promote_project($idea) => null,
        !bs_can(BS_CAP_PROJECT_CREATE)     => '프로젝트 등록 권한이 있는 사람만 전환합니다. 권한은 관리자가 줍니다.',
        default                            => '채택된 뒤에 전환할 수 있습니다.',
    };
    return ['rnd' => $rnd, 'project' => $prj];
}

/**
 * 아이디어 참고 자료 한 건 → 화면 모양 (033). 프로젝트 참고 자료(bs_present_source)와
 * 같은 열쇠를 써서 srcActsHtml()·보기 창을 그대로 쓴다. 내려받는 곳만 다르다.
 * file_path 는 내보내지 않는다.
 */
function bs_present_idea_source(array $r): array
{
    require_once BS_ROOT . '/inc/service/SourceUploader.php';   // humanSize()
    $hasFile = !empty($r['file_path']);
    $mime    = (string)($r['mime'] ?? '');
    return [
        'id'           => (int)$r['id'],
        'purpose'      => 'reference',
        'kind'         => $r['kind'],
        'kind_label'   => BS_SOURCE_KIND[$r['kind']] ?? $r['kind'],
        'title'        => $r['title'],
        'url'          => $r['url'],
        'file_size'    => $r['file_size'] !== null ? (int)$r['file_size'] : null,
        'size_label'   => $r['file_size'] !== null ? SourceUploader::humanSize((int)$r['file_size']) : null,
        'mime'         => $r['mime'],
        'has_file'     => $hasFile,
        'viewable'     => $hasFile && in_array($mime, BS_VIEWABLE_MIME, true),
        'parsed_len'   => 0,
        'download_url' => $hasFile ? 'api/idea_file.php?id=' . (int)$r['id'] : null,
        'uploaded_by'  => $r['uploaded_by_name'] ?? null,
        'created_at'   => bs_date($r['created_at'], 'Y-m-d H:i'),
    ];
}

/** 화면이 그릴 단추. 목록에 없는 단추는 그리지 않는다. */
function bs_idea_actions(array $idea): array
{
    $out = [];
    if (bs_idea_can_edit($idea))            { $out[] = 'update'; }
    if (bs_idea_can_delete($idea))          { $out[] = 'delete'; }
    if (bs_idea_can_rate($idea))            { $out[] = 'rate'; }
    if (bs_idea_can_comment($idea))         { $out[] = 'comment'; }
    if (bs_idea_can_review($idea))          { $out[] = 'review'; $out[] = 'merge'; }
    if (bs_idea_can_promote_rnd($idea))     { $out[] = 'promote_rnd'; }
    if (bs_idea_can_promote_project($idea)) { $out[] = 'promote_project'; }
    return $out;
}

/**
 * 작성자 표시. 익명이면 관리자와 본인 말고는 "익명" 만 본다.
 * author_id 는 이 함수를 거친 뒤에는 밖으로 나가지 않는다.
 */
function bs_idea_author_label(array $idea): string
{
    if (!empty($idea['is_anonymous']) && !bs_is_admin() && !bs_idea_is_author($idea)) {
        return '익명';
    }
    return (string)($idea['author_name'] ?? '');
}

/**
 * 아이디어 한 건을 화면 모양으로.
 * $full=false 면 목록용 — 본문 대신 앞부분만 싣는다.
 */
function bs_present_idea(array $r, bool $full = true): array
{
    $out = [
        'id'            => (int)$r['id'],
        'code'          => $r['code'],
        'title'         => $r['title'],
        'rnd_category'  => $r['rnd_category'],
        'category_label'=> $r['rnd_category'] !== null ? (BS_RND_CATEGORY[$r['rnd_category']] ?? $r['rnd_category']) : null,
        'status'        => $r['status'],
        'status_label'  => BS_IDEA_STATUS[$r['status']] ?? $r['status'],
        'author_name'   => bs_idea_author_label($r),
        'is_anonymous'  => !empty($r['is_anonymous']),
        'is_mine'       => bs_idea_is_author($r),
        'duplicate_of'  => $r['duplicate_of'] !== null ? (int)$r['duplicate_of'] : null,
        'promoted_project_id' => $r['promoted_project_id'] !== null ? (int)$r['promoted_project_id'] : null,
        'deleted'       => bs_idea_is_deleted($r),
        'created_at'    => bs_date($r['created_at'], 'Y-m-d H:i'),
        'updated_at'    => bs_date($r['updated_at'], 'Y-m-d H:i'),
        'actions'       => bs_idea_actions($r),
    ];
    if ($full) {
        $out += [
            'problem'          => $r['problem'],
            'proposal'         => $r['proposal'],
            'effect'           => $r['effect'],
            'url'              => $r['url'],
            'review_note'      => $r['review_note'],
            'reviewed_by_name' => $r['reviewed_by_name'],
            'reviewed_at'      => $r['reviewed_at'] ? bs_date($r['reviewed_at'], 'Y-m-d H:i') : null,
            'promoted_by_name' => $r['promoted_by_name'],
            'promoted_at'      => $r['promoted_at'] ? bs_date($r['promoted_at'], 'Y-m-d H:i') : null,
        ];
    } else {
        $lead = trim((string)($r['proposal'] ?: $r['problem'] ?: ''));
        $out['excerpt'] = mb_strimwidth(preg_replace('/\s+/u', ' ', $lead), 0, 160, '…');
    }
    return $out;
}
