<?php
/**
 * POST api/preview.php — 등록 화면의 일정 미리보기.
 * 본문: 등록 폼과 같은 칸. 응답: {next:[시각 3개], runs:기간 중 실행 수, cost:{low,high,per_run_low,per_run_high}}
 * 일정 계산을 자바스크립트로 또 짜지 않으려고 서버의 ow_next_run() 을 그대로 부른다.
 * 비용은 추정이다 — 실제 비용은 실행 이력에 남는다.
 */
require_once __DIR__ . '/_init.php';

ow_require_post();
ow_require_login_api();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$in = ow_input();
$in['title'] = $in['title'] ?? '미리보기';
$in['prompt'] = str_pad((string)($in['prompt'] ?? ''), 20, '.');
$in['slack_webhook'] = '';
[$row, $err] = ow_validate($in);
unset($err['title'], $err['prompt'], $err['baseline'], $err['slack_webhook']);
if ($err) ow_json_ok(['error' => reset($err), 'fields' => $err]);

$next = [];
$after = ow_after_for($row);
$runs = 0;
// 기간 중 실행 수. 매시간 180일이면 4천여 번이라 넉넉히 끊는다.
for ($t = ow_next_run($row, $after); $t !== null && $runs < 5000; $t = ow_next_run($row, $t)) {
    if (count($next) < 3) $next[] = ['at' => $t, 'label' => ow_when($t)];
    $runs++;
}
[$lo, $hi] = ow_estimate_run_cost((int)$row['max_searches']);
$budget = (float)$row['monthly_budget_usd'];
$days = max(1, (strtotime((string)$row['ends_at']) - strtotime((string)$row['starts_at'])) / 86400);
$perMonthRuns = $runs * min(1, 30 / $days);
ow_json_ok([
    'next'  => $next,
    'runs'  => $runs,
    'label' => ow_schedule_label($row),
    'cost'  => [
        'per_run_low'  => round($lo, 2),
        'per_run_high' => round($hi, 2),
        'month_low'    => round($lo * $perMonthRuns, 2),
        'month_high'   => round($hi * $perMonthRuns, 2),
        'budget'       => $budget,
        'over_budget'  => $lo * $perMonthRuns > $budget,
    ],
]);
