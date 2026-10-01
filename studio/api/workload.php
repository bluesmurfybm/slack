<?php
/**
 * 직접 등록하는 점유 API — 슬랙·메일에 안 잡히는 업무를 가용도에 반영한다.
 *
 * GET  api/workload.php?act=list&member_id=1[&from=&to=]
 * POST api/workload.php?act=create
 * POST api/workload.php?act=update
 * POST api/workload.php?act=delete
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 이 API 가 조심해야 하는 것                                        │
 * │                                                                  │
 * │ 여기 한 줄을 넣으면 그 사람의 가용도가 그대로 깎이고, 그러면      │
 * │ **모든 프로젝트의 후보 목록에서 사실상 사라진다.** 악의가 없어도  │
 * │ 착오 한 건이 조용히 남는다.                                       │
 * │                                                                  │
 * │ 그래서 셋을 강제한다.                                             │
 * │   1. 사유(note)와 제목(label) 없이는 못 넣는다                    │
 * │   2. 누가 넣었는지 남는다(created_by) — 화면에도 보인다           │
 * │   3. 본인은 자기 것을 언제나 볼 수 있다                           │
 * │                                                                  │
 * │ 고치고 지우는 것은 **직접 등록한 것만**이다. 배정에서 나온 점유는 │
 * │ 배정안이 관리한다(AllocationRepo 가 막는다).                      │
 * └──────────────────────────────────────────────────────────────────┘
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once BS_ROOT . '/inc/repo/MemberRepo.php';
require_once BS_ROOT . '/inc/repo/AllocationRepo.php';
require_once BS_ROOT . '/inc/service/AvailabilityCalculator.php';

$pdo     = bs_db();
$members = new MemberRepo($pdo);
$allocs  = new AllocationRepo($pdo);
$avail   = new AvailabilityCalculator($pdo);

bs_route(bs_param_str('act', 'list'), [

    /**
     * 한 사람의 직접 등록 점유.
     * 기간을 주면 그 기간과 겹치는 것만 — 후보 드로어가 프로젝트 기간으로 부른다.
     */
    'list' => function () use ($members, $allocs, $avail): void {
        $me       = bs_require_login_api();
        $memberId = bs_wl_member_param($members);

        bs_wl_require_read($members, $me, $memberId);

        $from = bs_param_str('from') ?: null;
        $to   = bs_param_str('to') ?: null;

        $rows = $allocs->manualWorkloadOf($memberId, $from, $to);

        // 가용도도 함께 준다. 넣거나 지운 뒤 화면이 바로 다시 그릴 수 있게.
        $av = ($from !== null && $to !== null)
            ? $avail->forMember($memberId, $from, $to) : null;

        bs_json_ok([
            'rows'         => $rows,
            'availability' => $av ? bs_wl_avail($av) : null,
            'sources'      => BS_WORKLOAD_SOURCE,
            'can_write'    => bs_wl_can_write($members, $me, $memberId),
        ]);
    },

    'create' => function () use ($members, $allocs, $avail): void {
        $me       = bs_begin_write();
        $memberId = bs_wl_member_param($members);
        bs_wl_require_write($members, $me, $memberId);

        $id = $allocs->addWorkload($memberId, [
            'kind'            => 'manual',
            'label'           => bs_param_str('label'),
            'note'            => bs_param_str('note'),
            'source'          => bs_param_str('source'),
            'source_url'      => bs_param_str('source_url'),
            'start_date'      => bs_param_str('start_date'),
            'end_date'        => bs_param_str('end_date'),
            'load_ratio'      => bs_wl_ratio(),
            'created_by'      => $me['id'],
            'created_by_name' => $me['name'] ?? null,
        ]);

        bs_json_ok(bs_wl_after($allocs, $avail, $memberId, [
            'id'      => $id,
            'message' => '다른 업무를 등록했습니다. 가용도에 바로 반영됩니다.',
        ]));
    },

    'update' => function () use ($members, $allocs, $avail): void {
        $me = bs_begin_write();
        $id = bs_param_int('id', 0);
        if (!$id) {
            bs_json_error('MISSING_PARAM', '점유 기록 번호가 없습니다.', 400);
        }
        $cur = $allocs->findWorkload($id);
        if (!$cur) {
            bs_json_error('NOT_FOUND', '점유 기록을 찾을 수 없습니다.', 404);
        }
        bs_wl_require_write($members, $me, (int)$cur['member_id']);

        $data = [];
        foreach (['label', 'note', 'source', 'source_url', 'start_date', 'end_date'] as $k) {
            if (bs_has_param($k)) {
                $data[$k] = bs_param_str($k);
            }
        }
        if (bs_has_param('load_pct') || bs_has_param('load_ratio')) {
            $data['load_ratio'] = bs_wl_ratio();
        }
        if (!$data) {
            bs_json_error('MISSING_PARAM', '바꿀 내용이 없습니다.', 400);
        }

        $allocs->updateWorkload($id, $data);

        bs_json_ok(bs_wl_after($allocs, $avail, (int)$cur['member_id'], [
            'id'      => $id,
            'message' => '고쳤습니다.',
        ]));
    },

    'delete' => function () use ($members, $allocs, $avail): void {
        $me = bs_begin_write();
        $id = bs_param_int('id', 0);
        if (!$id) {
            bs_json_error('MISSING_PARAM', '점유 기록 번호가 없습니다.', 400);
        }
        $cur = $allocs->findWorkload($id);
        if (!$cur) {
            bs_json_error('NOT_FOUND', '점유 기록을 찾을 수 없습니다.', 404);
        }
        $memberId = (int)$cur['member_id'];
        bs_wl_require_write($members, $me, $memberId);

        $allocs->deleteWorkload($id);

        bs_json_ok(bs_wl_after($allocs, $avail, $memberId, [
            'message' => '지웠습니다. 가용도가 그만큼 돌아옵니다.',
        ]));
    },
]);


// =====================================================================
// 공통
// =====================================================================

function bs_wl_member_param(MemberRepo $members): int
{
    $id = bs_param_int('member_id', 0);
    if (!$id) {
        bs_json_error('MISSING_PARAM', '구성원 번호가 없습니다.', 400);
    }
    if (!$members->find($id)) {
        bs_json_error('NOT_FOUND', '구성원을 찾을 수 없습니다.', 404);
    }
    return $id;
}

/**
 * 점유율. 화면은 % 로 보내고 DB 는 0~1 로 받는다.
 * 둘 다 받되 % 를 우선한다 — 화면이 쓰는 단위다.
 */
function bs_wl_ratio(): float
{
    if (bs_has_param('load_pct')) {
        return round(((float)bs_param_str('load_pct', '100')) / 100, 3);
    }
    return (float)bs_param_str('load_ratio', '1.0');
}

/**
 * 볼 수 있는가.
 *
 * 본인은 언제나 볼 수 있다 — 자기 가용도가 왜 그런지 알 권리가 있다.
 * 남의 것은 PM·관리자만 본다.
 */
function bs_wl_require_read(MemberRepo $members, array $me, int $memberId): void
{
    if (bs_wl_can_write($members, $me, $memberId)) {
        return;
    }
    bs_json_error('FORBIDDEN', '본인 것만 볼 수 있습니다.', 403);
}

/**
 * 넣고 고칠 수 있는가.
 *
 * 본인이거나, 어느 프로젝트든 PM 이거나, 관리자.
 *
 * PM 이 남의 점유를 넣을 수 있게 열어 둔 이유는, 배정을 짜는 사람이
 * "저 사람 다음 달에 상주 나간다" 를 가장 먼저 알기 때문이다.
 * 대신 **누가 넣었는지 반드시 남고 본인에게 보인다** — 그것이 이 권한을
 * 여는 조건이다.
 */
function bs_wl_require_write(MemberRepo $members, array $me, int $memberId): void
{
    if (bs_wl_can_write($members, $me, $memberId)) {
        return;
    }
    bs_json_error('FORBIDDEN',
        '본인 것이거나 PM·관리자만 등록할 수 있습니다.', 403);
}

/**
 * 판단은 bootstrap 의 bs_can_edit_workload() 한 곳에서만 한다.
 * api/candidate.php 가 단추를 그릴지 정할 때도 같은 함수를 쓴다 —
 * 두 벌로 두면 단추는 보이는데 누르면 403 이 나는 일이 생긴다.
 */
function bs_wl_can_write(MemberRepo $members, array $me, int $memberId): bool
{
    $m = $members->find($memberId);
    return $m !== null && bs_can_edit_workload((string)$m['user_id']);
}

function bs_wl_is_self(MemberRepo $members, array $me, int $memberId): bool
{
    $mine = $members->findByUserId((string)$me['id']);
    return $mine !== null && (int)$mine['id'] === $memberId;
}

/** 넣거나 고친 뒤 화면이 다시 그릴 재료. */
function bs_wl_after(AllocationRepo $allocs, AvailabilityCalculator $avail,
                     int $memberId, array $extra): array
{
    $from = bs_param_str('from') ?: null;
    $to   = bs_param_str('to') ?: null;
    $av   = ($from !== null && $to !== null)
          ? $avail->forMember($memberId, $from, $to) : null;

    return $extra + [
        'member_id'    => $memberId,
        'rows'         => $allocs->manualWorkloadOf($memberId, $from, $to),
        'availability' => $av ? bs_wl_avail($av) : null,
    ];
}

/** 가용도 응답 모양. 후보 표와 같은 키를 쓴다 — 화면이 두 벌로 그리지 않게. */
function bs_wl_avail(array $a): array
{
    return [
        'available_pct' => $a['available_pct'],
        'confirmed_pct' => $a['confirmed_pct'],
        'inferred_pct'  => $a['inferred_pct'],
        'capacity_pct'  => $a['capacity_pct'],
        'confidence'    => $a['confidence'],
        'workdays'      => $a['workdays'],
        'breakdown'     => $a['breakdown'],
        'inferred_items' => $a['inferred_items'],
    ];
}
