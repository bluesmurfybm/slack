<?php
/**
 * GET api/requests.php
 *   목록 + 대시보드 집계를 한 번에 돌려준다.
 *
 * 파라미터
 *   year          기본값 = 현재 연도
 *   status[]      상태 다중 필터
 *   tab           progress | stocked | mine | rejected (탭 프리셋)
 *   category_id, keyword, from, to, sort, page, size
 *   scope         member(기본) | admin
 */
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$user = bc_require_login_api();

$scope = bc_param_str('scope', 'member');
if ($scope === 'admin' && !bc_can_see_admin()) {
    bc_json_error('관리자 권한이 필요합니다.', 403);
}

$year = bc_param_int('year', (int)date('Y'));

$status = bc_param('status', []);
$status = is_array($status) ? $status : array_filter(explode(',', (string)$status));

// 탭 프리셋. 명시적 status 필터가 있으면 그쪽이 우선.
$tab  = bc_param_str('tab', '');
$mine          = false;
$assigneeScope = false;
$assigneeOnly  = false;
if (!$status) {
    switch ($tab) {
        case 'progress':                                    // 구매 진행중 물품
            $status = ['REQUESTED', 'APPROVED', 'PURCHASING'];
            break;
        case 'stocked':                                     // 구비완료 물품
            $status = ['STOCKED'];
            break;
        case 'rejected':
            $status = ['REJECTED', 'CANCELED'];
            break;
        case 'mine':
            $mine = true;
            break;
        case 'todo':                                        // 관리자: 내가 처리할 건
            $roles = bc_my_roles();
            $status = [];
            if (array_intersect($roles, ['REVIEWER', 'ADMIN'])) {
                $status[] = 'REQUESTED';
            }
            if (array_intersect($roles, ['BUYER', 'ADMIN'])) {
                $status[] = 'APPROVED';
                $status[] = 'PURCHASING';
                // 구매담당자에게는 내가 맡은 건과 아직 아무도 안 맡은 건만 보여준다.
                // 관리자는 전부 본다.
                if (!in_array('ADMIN', $roles, true)) {
                    $assigneeScope = true;
                }
            }
            if (!$status) {
                $status = ['__NONE__'];
            }
            break;
        case 'assigned':                                    // 내가 맡은 건만
            $assigneeOnly = true;
            break;
    }
}

$filter = [
    'year'        => $year,
    'status'      => $status,
    'category_id' => bc_param_int('category_id'),
    'keyword'     => bc_param_str('keyword'),
    'from'        => bc_param_str('from') ?: null,
    'to'          => bc_param_str('to') ?: null,
    'sort'        => bc_param_str('sort', 'recent'),
    'page'        => bc_param_int('page', 1),
    'size'        => bc_param_int('size', 30),
];

if ($mine || bc_param_str('mine') === '1') {
    $filter['requester_id'] = $user['id'];
}

if ($assigneeOnly) {
    $filter['assignee_id'] = $user['id'];
} elseif ($assigneeScope) {
    $filter['assignee_id']        = $user['id'];
    $filter['include_unassigned'] = true;
}

$result = PurchaseRequest::search($filter);

// 목록 각 행에 현재 사용자가 쓸 수 있는 액션을 붙인다.
$rows = [];
foreach ($result['rows'] as $r) {
    $rows[] = bc_present_request($r, $user);
}

bc_json_ok([
    'rows'   => $rows,
    'total'  => $result['total'],
    'page'   => $result['page'],
    'size'   => $result['size'],
    'counts' => PurchaseRequest::statusCounts([
        'year'         => $year,
        'category_id'  => $filter['category_id'],
        'requester_id' => $filter['requester_id'] ?? null,
    ]),
    'years'  => PurchaseRequest::years(),
]);
