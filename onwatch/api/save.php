<?php
/**
 * POST api/save.php — 감시 만들기 / 고치기
 * 본문: id(고칠 때), title, prompt, baseline, starts_at, ends_at, interval_min, run_time, weekday,
 *       weekdays_only, hour_from, hour_to, notify_mode, min_importance, notify_slack, slack_webhook,
 *       prefer_domains, block_domains, max_searches, monthly_budget_usd, run_now(새로 만들 때)
 * 응답: {ok, id, url} / 검사 실패면 {ok:false, error, fields:{칸:메시지}}
 */
require_once __DIR__ . '/_init.php';

ow_require_post();
$u = ow_require_login_api();
ow_verify_csrf();

$id = ow_param_int('id');
$existing = null;
if ($id) {
    $existing = ow_watch_get($id);
    if (!$existing) ow_json_error('감시를 찾을 수 없습니다.', 404);
    if (!ow_perms($existing, $u)['can_edit']) ow_json_error('등록한 사람만 고칠 수 있습니다.', 403);
}

[$row, $err] = ow_validate(ow_input(), $existing);
if ($err) ow_json_error(reset($err), 422, ['fields' => $err]);

if ($existing) {
    ow_watch_update($existing, $row, (int)$u['id']);
    $id = (int)$existing['id'];
} else {
    $id = ow_watch_create($row, (int)$u['id'], ow_param_bool('run_now'));
}

ow_json_ok(['id' => $id, 'url' => 'view.php?id=' . $id]);
