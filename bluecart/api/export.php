<?php
/**
 * GET api/export.php?type=list&year=2026&...   요청 목록 엑셀
 * GET api/export.php?type=stats&year=2026      통계 엑셀
 *
 * 목록은 화면에 걸린 필터를 그대로 받아 같은 조건으로 내보냅니다.
 * JSON 이 아니라 파일을 내보내므로 오류도 화면에 글자로 표시합니다.
 */
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once BC_ROOT . '/includes/XlsxWriter.php';

// 파일 응답이라 JSON 오류 처리기를 쓰면 엑셀 대신 깨진 파일이 내려간다.
set_exception_handler(function (Throwable $e) {
    error_log('[BlueCart] export failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo '내보내기에 실패했습니다. 관리자에게 문의하세요.';
    exit;
});

$user = bc_current_user();
if ($user === null) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    exit('로그인이 필요합니다.');
}

$type = bc_param_str('type', 'list');
$year = bc_param_int('year', (int)date('Y'));

// ---------------------------------------------------------------------
// 통계
// ---------------------------------------------------------------------
if ($type === 'stats') {
    if (!bc_can_see_admin()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('관리자 권한이 필요합니다.');
    }

    $s = PurchaseRequest::statistics($year);
    $x = new XlsxWriter();

    $hours = function (?float $h): string {
        if ($h === null) {
            return '-';
        }
        return $h < 48 ? round($h, 1) . '시간' : round($h / 24, 1) . '일';
    };

    $x->addSheet('요약', ['항목', '값'], [
        ['집계 연도',        $year],
        ['전체 요청',        $s['status']['TOTAL']],
        ['검토 대기',        $s['status']['REQUESTED']],
        ['구매 대기',        $s['status']['APPROVED']],
        ['구매 진행',        $s['status']['PURCHASING']],
        ['구비 완료',        $s['status']['STOCKED']],
        ['반려',             $s['status']['REJECTED']],
        ['철회',             $s['status']['CANCELED']],
        ['평균 검토 소요',   $hours($s['lead_time']['review_hours'])],
        ['요청→구비 평균',   $hours($s['lead_time']['total_hours'])],
        ['소요시간 산출 건수', $s['lead_time']['sample']],
        ['내보낸 시각',      date('Y-m-d H:i:s')],
    ], [22, 18]);

    $x->addSheet('월별', ['월', '요청 건수', '구비 완료', '실구매 합계(원)'],
        array_map(fn($m) => [
            $m['m'] . '월', (int)$m['cnt'], (int)$m['done'], (int)$m['amount'],
        ], $s['by_month']), [10, 12, 12, 18]);

    $x->addSheet('사용처별', ['사용처', '요청 건수', '실구매 합계(원)'],
        array_map(fn($c) => [$c['name'], (int)$c['cnt'], (int)$c['amount']],
                  $s['by_category']), [20, 12, 18]);

    $x->addSheet('요청자별', ['요청자', '요청 건수'],
        array_map(fn($r) => [$r['name'], (int)$r['cnt']], $s['by_requester']), [16, 12]);

    $x->addSheet('구매담당자별', ['구매담당자', '처리 건수', '실구매 합계(원)'],
        array_map(fn($b) => [$b['name'], (int)$b['cnt'], (int)$b['amount']],
                  $s['by_buyer']), [16, 12, 18]);

    $x->addSheet('자주 구비한 물품', ['물품', '구비 횟수', '총 수량'],
        array_map(fn($i) => [$i['name'], (int)$i['cnt'], (int)$i['qty']], $s['top_items']),
        [40, 12, 12]);

    $x->download(sprintf('물품구매_통계_%d_%s.xlsx', $year, date('Ymd')));
}

// ---------------------------------------------------------------------
// 목록
// ---------------------------------------------------------------------
$status = bc_param('status', []);
$status = is_array($status) ? $status : array_filter(explode(',', (string)$status));

$tab = bc_param_str('tab', '');
if (!$status) {
    $status = match ($tab) {
        'progress' => ['REQUESTED', 'APPROVED', 'PURCHASING'],
        'stocked'  => ['STOCKED'],
        'rejected' => ['REJECTED', 'CANCELED'],
        default    => [],
    };
}

$filter = [
    'year'        => $year,
    'status'      => $status,
    'category_id' => bc_param_int('category_id'),
    'keyword'     => bc_param_str('keyword'),
    'from'        => bc_param_str('from') ?: null,
    'to'          => bc_param_str('to') ?: null,
    'sort'        => bc_param_str('sort', 'recent'),
    'page'        => 1,
    'size'        => 200,
];
if (bc_param_str('mine') === '1') {
    $filter['requester_id'] = $user['id'];
}

// 엑셀은 화면 페이지와 달리 조건에 맞는 전체를 담는다.
$rows = [];
$page = 1;
do {
    $filter['page'] = $page;
    $chunk = PurchaseRequest::search($filter);
    foreach ($chunk['rows'] as $r) {
        $rows[] = $r;
    }
    $page++;
    // 방어선: 10만 건을 넘기면 멈춘다
} while (count($rows) < $chunk['total'] && count($rows) < 100000 && $chunk['rows']);

$data = [];
$no = 0;
foreach ($rows as $r) {
    $no++;
    $handledAt = $r['stocked_at'] ?: ($r['purchasing_at'] ?: $r['reviewed_at']);
    $data[] = [
        $no,
        $r['req_no'],
        $r['category_name'],
        $r['item_name'],
        (int)$r['quantity'],
        $r['unit'],
        bc_date($r['requested_at'], 'Y-m-d'),
        $r['requester_name'],
        bc_status_label($r['status']),
        bc_date($handledAt, 'Y-m-d'),
        $r['reviewer_name'] ?? '',
        $r['assignee_name'] ?? '',
        $r['buyer_name'] ?? '',
        $r['est_amount']    !== null ? (int)$r['est_amount']    : '',
        $r['actual_amount'] !== null ? (int)$r['actual_amount'] : '',
        $r['deliver_to'] ?? '',
        bc_date($r['need_by']),
        $r['ref_url'] ?? '',
        $r['note'] ?? '',
        $r['review_comment'] ?? '',
        $r['purchase_note'] ?? '',
        (int)$r['resubmit_count'],
    ];
}

$x = new XlsxWriter();
$x->addSheet(
    $year . '년 요청',
    ['No.', '요청번호', '사용처', '필요 물품', '필요 갯수', '단위',
     '신청일', '신청자', '처리상태', '처리일', '검토자', '구매담당', '처리자',
     '예상금액', '실구매금액', '수령장소', '희망수령일', '참고링크',
     '비고', '검토의견/반려사유', '구매메모', '재요청횟수'],
    $data,
    [6, 12, 14, 34, 10, 8, 12, 10, 11, 12, 10, 10, 10, 12, 12, 16, 12, 40, 34, 28, 24, 10]
);

$suffix = $status ? '_' . implode('-', array_map('bc_status_label', $status)) : '';
$x->download(sprintf('물품구매_목록_%d%s_%s.xlsx', $year, $suffix, date('Ymd')));
