<?php
/**
 * POST api/preview.php — 등록 화면의 일정 미리보기.
 * 본문: 등록 폼과 같은 칸. 응답: {next:[시각 3개], runs:기간 중 실행 수, cost:{low,high,per_run_low,per_run_high},
 *       recommend:{model,reason}, models:{모델:{per_run_low,per_run_high,month_low,month_high}}}
 * 일정 계산을 자바스크립트로 또 짜지 않으려고 서버의 ow_next_run() 을 그대로 부른다.
 * 비용은 추정이다 — 실제 비용은 실행 이력에 남는다. cost 는 고른 모델(없으면 권장 모델) 기준.
 */
require_once __DIR__ . '/_init.php';

ow_require_post();
ow_require_login_api();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$in = ow_input();
$prompt = (string)($in['prompt'] ?? '');
$in['title'] = $in['title'] ?? '미리보기';
$in['prompt'] = str_pad($prompt, 20, '.');
$in['slack_webhook'] = '';
[$row, $err] = ow_validate($in);
unset($err['title'], $err['prompt'], $err['baseline'], $err['slack_webhook'], $err['model']);
[$recModel, $recWhy] = ow_recommend_model(['prompt' => $prompt, 'max_searches' => $row['max_searches']]);
if ($err) ow_json_ok(['error' => reset($err), 'fields' => $err]);

$next = [];
$after = ow_after_for($row);
$runs = 0;
// 기간 중 실행 수. 매시간 180일이면 4천여 번이라 넉넉히 끊는다.
for ($t = ow_next_run($row, $after); $t !== null && $runs < 5000; $t = ow_next_run($row, $t)) {
    if (count($next) < 3) $next[] = ['at' => $t, 'label' => ow_when($t)];
    $runs++;
}
$budget = (float)$row['monthly_budget_usd'];
$days = max(1, (strtotime((string)$row['ends_at']) - strtotime((string)$row['starts_at'])) / 86400);
$perMonthRuns = $runs * min(1, 30 / $days);
$models = [];
foreach (ow_models() as $m => $_) {
    [$mlo, $mhi] = ow_estimate_run_cost((int)$row['max_searches'], $m);
    $models[$m] = ['per_run_low' => round($mlo, 2), 'per_run_high' => round($mhi, 2),
                   'month_low' => round($mlo * $perMonthRuns, 2), 'month_high' => round($mhi * $perMonthRuns, 2)];
}
[$lo, $hi] = ow_estimate_run_cost((int)$row['max_searches'], $row['model'] ?? $recModel);
ow_json_ok([
    'next'      => $next,
    'runs'      => $runs,
    'label'     => ow_schedule_label($row),
    'recommend' => ['model' => $recModel, 'reason' => $recWhy],
    'models'    => $models,
    'cost'      => [
        'per_run_low'  => round($lo, 2),
        'per_run_high' => round($hi, 2),
        'month_low'    => round($lo * $perMonthRuns, 2),
        'month_high'   => round($hi * $perMonthRuns, 2),
        'budget'       => $budget,
        'over_budget'  => $lo * $perMonthRuns > $budget,
    ],
]);
