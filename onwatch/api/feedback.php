<?php
/**
 * POST api/feedback.php — 소식 평가
 * 본문: id(감시), finding_id, feedback(useful|duplicate|wrong|''=지우기)
 * '중복'·'틀림' 은 다음 실행 때 "이런 것은 다시 알리지 말라" 로 모델에 넘어간다(worker/prompts.py).
 */
require_once __DIR__ . '/_init.php';

ow_require_post();
$u = ow_require_login_api();
ow_verify_csrf();

$w = ow_api_watch();
if (!ow_perms($w, $u)['can_feedback']) ow_json_error('등록한 사람과 받는 사람만 평가할 수 있습니다.', 403);

$fid = ow_param_int('finding_id');
$fb  = ow_param_str('feedback');
if (!$fid) ow_json_error('평가할 소식을 지정하세요.');
if ($fb !== '' && !isset(OW_FEEDBACK[$fb])) ow_json_error('알 수 없는 평가입니다.');

ow_set_feedback($fid, (int)$w['id'], $fb === '' ? null : $fb, (int)$u['id']);
ow_json_ok(['feedback' => $fb, 'label' => OW_FEEDBACK[$fb] ?? '', 'by' => $u['name']]);
