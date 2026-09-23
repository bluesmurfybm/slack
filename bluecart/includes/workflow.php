<?php
/**
 * 구매 프로세스 상태 기계.
 *
 *   REQUESTED ──승인──▶ APPROVED ──진행──▶ PURCHASING ──입고──▶ STOCKED
 *       │                                                        (종료)
 *       ├──반려──▶ REJECTED ──재요청──▶ REQUESTED
 *       │              └──철회──▶ CANCELED
 *       └──철회──▶ CANCELED
 *
 * 구매 담당 지정
 *   - 구매담당자가 한 명뿐이면 지정 단계 자체가 없다. 그 사람이 바로 처리한다.
 *   - 두 명 이상일 때만 승인 시 지목할 수 있다(선택).
 *   - 지목하지 않으면 배정된 구매담당자 전원에게 알림이 가고 누구나 집어 갈 수 있다.
 *   - 지목된 건은 그 사람과 관리자만 구매 진행/구비 완료로 바꿀 수 있다.
 *   - 담당은 승인 이후 언제든 바꿀 수 있다(assign).
 *
 * 승인 생략
 *   - 검토승인자나 관리자가 직접 요청을 올릴 때는 자기가 자기 요청을 검토하는
 *     셈이라 의미가 없다. 등록과 동시에 승인 처리해 구매 대기로 넘길 수 있다.
 *   - 이때도 이력에는 REQUEST_CREATED 와 REVIEW_APPROVED 가 모두 남는다.
 *
 * 반려 후 처리 정책
 *   - 반려 시 사유 입력란을 제공하고 비어 있으면 저장하지 않는다.
 *   - 요청자는 반려 건을 수정해 재요청하거나(같은 번호 유지, 재요청 횟수 누적)
 *     철회할 수 있다. 반려 건은 목록 기본 필터에서 빠지고 '반려' 필터로 조회한다.
 *   - 일정 기간(bc_setting.reject_auto_close_days, 기본 14일) 동안 요청자가
 *     아무 조치도 하지 않으면 cron 이 자동 철회한다. 미결 건이 쌓이지 않게 하려는 것.
 */

declare(strict_types=1);

const BC_STATUS = [
    'REQUESTED'  => ['label' => '검토 대기', 'tone' => 'wait'],
    'APPROVED'   => ['label' => '구매 대기', 'tone' => 'ready'],
    'PURCHASING' => ['label' => '구매 진행', 'tone' => 'work'],
    'STOCKED'    => ['label' => '구비 완료', 'tone' => 'done'],
    'REJECTED'   => ['label' => '반려',      'tone' => 'stop'],
    'CANCELED'   => ['label' => '철회',      'tone' => 'off'],
];

/**
 * 목록 위 상태 탭. 상태 축은 이 탭이 전담한다 — 고르는 자리가 하나뿐이라야
 * 선택 표시가 서로 어긋나지 않는다.
 *
 *   all  조건 없음(그 모집단 전체)   OUT  반려 + 철회
 */
const BC_STATUS_TABS = ['all', 'REQUESTED', 'APPROVED', 'PURCHASING', 'STOCKED', 'OUT'];

/** 상태 탭 → 상태 코드. 화면·엑셀·집계가 이 한 곳을 본다. */
function bc_tab_status(string $tab): array
{
    if ($tab === 'OUT') {
        return ['REJECTED', 'CANCELED'];
    }
    if (in_array($tab, ['REQUESTED', 'APPROVED', 'PURCHASING', 'STOCKED'], true)) {
        return [$tab];
    }
    return [];      // all — 조건 없음
}

/**
 * 관리자 화면의 사람 축(담당) → 조회 범위.
 *
 * 상태 축과 겹치지 않게 따로 받는다. 다만 '내가 처리할 건' 은 정의상
 * 역할별 단계를 함께 정하므로 그 제한을 status_in 으로 돌려준다.
 * 이 제한은 목록뿐 아니라 집계에도 걸어서, 해당 없는 단계가 탭에서
 * 저절로 0건이 되게 한다 — 탭을 여러 개 동시에 켜지 않아도 된다.
 *
 * @return array{assignee_id:?string, include_unassigned:bool, status_in:?array}
 */
function bc_assign_scope(string $assign, string $userId, array $roles): array
{
    $out = ['assignee_id' => null, 'include_unassigned' => false, 'status_in' => null];

    if ($assign === 'assigned') {          // 내가 맡은 건
        $out['assignee_id'] = $userId;
        return $out;
    }
    if ($assign !== 'todo') {              // 전체
        return $out;
    }

    $stages = [];
    if (array_intersect($roles, ['REVIEWER', 'ADMIN'])) {
        $stages[] = 'REQUESTED';
    }
    if (array_intersect($roles, ['BUYER', 'ADMIN'])) {
        $stages[] = 'APPROVED';
        $stages[] = 'PURCHASING';
        // 구매담당자에게는 내가 맡은 건과 아직 아무도 안 맡은 건만 보여준다.
        // 관리자는 전부 본다.
        if (!in_array('ADMIN', $roles, true)) {
            $out['assignee_id']        = $userId;
            $out['include_unassigned'] = true;
        }
    }
    // 역할이 없으면 처리할 것도 없다. 빈 배열은 '조건 없음' 이라 전체가 나오므로
    // 어떤 상태와도 맞지 않는 값을 넣어 0건이 되게 한다.
    $out['status_in'] = $stages ?: ['__NONE__'];
    return $out;
}

const BC_EVENT = [
    'REQUEST_CREATED'     => '구매 요청 등록',
    'REQUEST_UPDATED'     => '요청 내용 수정',
    'REQUEST_RESUBMITTED' => '반려 후 재요청',
    'REQUEST_CANCELED'    => '요청 철회',
    'REVIEW_APPROVED'     => '검토 승인',
    'REVIEW_REJECTED'     => '검토 반려',
    'PURCHASE_ASSIGNED'   => '구매 담당 지정',
    'PURCHASE_STARTED'    => '구매 진행 시작',
    'PURCHASE_DONE'       => '구비 완료',
];

/**
 * 배송/수령 희망 장소 선택지.
 *
 * 자유 입력이던 칸을 목록에서 고르게 바꿨다. 사람마다 '사무실', '회사', '본사'
 * 처럼 다르게 적어 집계가 안 됐기 때문. 빈 값(= 지정 안 함)도 그대로 둔다.
 * 목록에 없는 세부 사항은 비고란에 적는다.
 *
 * 값을 그대로 DB(`bc_request.deliver_to`)에 넣으므로 한 번 쓰기 시작한 값은
 * 바꾸지 말고, 쓰지 않을 값은 목록에서 빼는 대신 새 값을 아래에 덧붙인다.
 */
const BC_DELIVER_PLACES = ['사무실', 'CAFE', '기타'];

function bc_is_deliver_place(string $v): bool
{
    return $v === '' || in_array($v, BC_DELIVER_PLACES, true);
}

const BC_ROLE_LABEL = [
    'REQUESTER' => '요청자',
    'REVIEWER'  => '검토승인자',
    'BUYER'     => '구매담당자',
];

/**
 * 액션 정의: 어떤 상태에서, 누가, 어디로 보낼 수 있는가.
 *
 *   from     허용 이전 상태
 *   to       전이 후 상태
 *   role     필요한 역할 (OWNER 는 요청 본인)
 *   event    알림/이력용 이벤트 코드
 *   comment  코멘트 필수 여부
 */
const BC_ACTIONS = [
    'approve' => [
        'from' => ['REQUESTED'], 'to' => 'APPROVED',
        'role' => ['REVIEWER', 'ADMIN'], 'event' => 'REVIEW_APPROVED',
        'comment' => false, 'label' => '승인',
    ],
    'reject' => [
        'from' => ['REQUESTED'], 'to' => 'REJECTED',
        'role' => ['REVIEWER', 'ADMIN'], 'event' => 'REVIEW_REJECTED',
        'comment' => true, 'label' => '반려',
    ],
    'assign' => [
        'from' => ['APPROVED', 'PURCHASING'], 'to' => null,
        'role' => ['REVIEWER', 'BUYER', 'ADMIN'], 'event' => 'PURCHASE_ASSIGNED',
        'comment' => false, 'label' => '담당 지정',
    ],
    'start_purchase' => [
        'from' => ['APPROVED'], 'to' => 'PURCHASING',
        'role' => ['BUYER', 'ADMIN'], 'event' => 'PURCHASE_STARTED',
        'comment' => false, 'label' => '구매 진행', 'assignee_only' => true,
    ],
    'complete' => [
        'from' => ['PURCHASING', 'APPROVED'], 'to' => 'STOCKED',
        'role' => ['BUYER', 'ADMIN'], 'event' => 'PURCHASE_DONE',
        'comment' => false, 'label' => '구비 완료', 'assignee_only' => true,
    ],
    'resubmit' => [
        'from' => ['REJECTED'], 'to' => 'REQUESTED',
        'role' => ['OWNER'], 'event' => 'REQUEST_RESUBMITTED',
        'comment' => false, 'label' => '재요청',
    ],
    'cancel' => [
        'from' => ['REQUESTED', 'REJECTED'], 'to' => 'CANCELED',
        'role' => ['OWNER', 'ADMIN'], 'event' => 'REQUEST_CANCELED',
        'comment' => false, 'label' => '철회',
    ],
];

/** 이벤트별 알림 수신 역할. 실제 발송 여부는 bc_notify_setting 이 결정. */
const BC_EVENT_TARGETS = [
    'REQUEST_CREATED'     => ['REVIEWER'],
    'REQUEST_RESUBMITTED' => ['REVIEWER'],
    'REQUEST_CANCELED'    => ['REVIEWER'],
    'REVIEW_APPROVED'     => ['BUYER', 'REQUESTER'],
    'REVIEW_REJECTED'     => ['REQUESTER'],
    'PURCHASE_ASSIGNED'   => ['BUYER'],
    'PURCHASE_STARTED'    => ['REQUESTER'],
    'PURCHASE_DONE'       => ['REQUESTER', 'REVIEWER'],
];

function bc_status_label(string $code): string
{
    return BC_STATUS[$code]['label'] ?? $code;
}

function bc_status_tone(string $code): string
{
    return BC_STATUS[$code]['tone'] ?? 'off';
}

/**
 * 특정 요청에 대해 현재 사용자가 쓸 수 있는 액션 키 목록.
 */
function bc_available_actions(array $request, ?array $user = null): array
{
    $user ??= bc_current_user();
    if ($user === null) {
        return [];
    }
    $roles   = bc_roles_of($user['id']);
    $isOwner = $request['requester_id'] === $user['id'];
    $out     = [];

    foreach (BC_ACTIONS as $key => $def) {
        if (!in_array($request['status'], $def['from'], true)) {
            continue;
        }
        $allowed = false;
        foreach ($def['role'] as $need) {
            if ($need === 'OWNER' ? $isOwner : in_array($need, $roles, true)) {
                $allowed = true;
                break;
            }
        }

        // 구매담당자가 한 명뿐이면 담당을 지정할 대상이 없다.
        if ($allowed && $key === 'assign' && count(RoleAssign::byType('BUYER')) < 2) {
            $allowed = false;
        }

        // 담당자가 지정된 건은 그 사람(또는 관리자)만 진행/완료할 수 있다.
        // 담당자가 비어 있으면 배정된 구매담당자 누구나 집어 갈 수 있다.
        if ($allowed && !empty($def['assignee_only'])
            && !empty($request['assignee_id'])
            && $request['assignee_id'] !== $user['id']
            && !in_array('ADMIN', $roles, true)) {
            $allowed = false;
        }

        if ($allowed) {
            $out[] = $key;
        }
    }
    return $out;
}

/**
 * 이 건을 처리할 사람을 한 줄로 설명한다. 목록에 그대로 쓴다.
 */
function bc_assignee_label(array $request): string
{
    if (!empty($request['assignee_name'])) {
        return $request['assignee_name'];
    }
    // 담당 지정 기능이 생기기 전에 처리된 건은 실제 처리자를 보여준다.
    if (!empty($request['buyer_name'])) {
        return $request['buyer_name'];
    }
    if (!in_array($request['status'], ['APPROVED', 'PURCHASING'], true)) {
        return '';
    }
    // 구매담당자가 한 명뿐이면 지정하지 않아도 그 사람이 처리한다.
    $sole = RoleAssign::soleBuyer();
    return $sole ? $sole['user_name'] : '미지정';
}

/** 담당 지정 단계를 쓸지 여부. 구매담당자가 2명 이상일 때만 의미가 있다. */
function bc_needs_assignee(): bool
{
    return count(RoleAssign::byType('BUYER')) > 1;
}
