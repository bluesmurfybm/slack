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

// 상태 축 — 목록 위 탭이 전담한다.
$tab = bc_param_str('tab', 'all');
if (!in_array($tab, BC_STATUS_TABS, true)) {
    $tab = 'all';
}

// 사람 축 — 상태 축과 겹치지 않게 따로 받는다.
//   구성원: mine=1 / 관리자: assign = all | todo | assigned
$assign = $scope === 'admin' ? bc_param_str('assign', 'all') : 'all';
$scopeOf = bc_assign_scope($assign, (string)$user['id'], bc_my_roles());

// 목록에 실제로 걸리는 상태 = 고른 탭 ∩ 담당이 정한 범위.
$listStatus = bc_tab_status($tab);
if ($scopeOf['status_in'] !== null) {
    $listStatus = $listStatus
        ? array_values(array_intersect($listStatus, $scopeOf['status_in']))
        : $scopeOf['status_in'];
    if (!$listStatus) {
        $listStatus = ['__NONE__'];     // 겹치는 단계가 없다 = 0건
    }
}

$filter = [
    'year'        => $year,
    'status'      => $listStatus,
    'category_id' => bc_param_int('category_id'),
    'keyword'     => bc_param_str('keyword'),
    'from'        => bc_param_str('from') ?: null,
    'to'          => bc_param_str('to') ?: null,
    'sort'        => bc_param_str('sort', 'status'),
    'page'        => bc_param_int('page', 1),
    'size'        => bc_param_int('size', 30),
];

if (bc_param_str('mine') === '1') {
    $filter['requester_id'] = $user['id'];
}
if ($scopeOf['assignee_id'] !== null) {
    $filter['assignee_id']        = $scopeOf['assignee_id'];
    $filter['include_unassigned'] = $scopeOf['include_unassigned'];
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
    // 집계는 목록과 같은 모집단을 본다 — 범위 축과 사람 축을 그대로 태운다.
    // 상태 축(고른 탭)만 뺀다. 집계가 나눠 보여 주는 축이라 여기서 걸면
    // 자기 자신을 지우고, 탭마다 제 건수를 못 달게 된다.
    'counts' => PurchaseRequest::statusCounts([
        'year'               => $year,
        'category_id'        => $filter['category_id'],
        'requester_id'       => $filter['requester_id'] ?? null,
        'keyword'            => $filter['keyword'],
        'from'               => $filter['from'],
        'to'                 => $filter['to'],
        'assignee_id'        => $scopeOf['assignee_id'],
        'include_unassigned' => $scopeOf['include_unassigned'],
        'status_in'          => $scopeOf['status_in'],
    ]),
    'years'  => PurchaseRequest::years(),
]);
