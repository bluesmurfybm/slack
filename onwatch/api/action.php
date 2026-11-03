<?php
/**
 * POST api/action.php — 감시 한 건에 하는 일
 * 본문: id, action
 *   run | cancel_run                     지금 실행 / 요청 취소
 *   pause | resume | end                 일시정지 / 재개 / 종료
 *   extend (days=7|14|30)                종료일 늘리기(종료된 감시면 다시 켠다)
 *   subscribe | unsubscribe              소식 받기 / 그만 받기
 *   baseline (run_id 또는 baseline)      실행 결과의 제안을 기준 상태로 반영
 *   delete                               지우기(이력까지 모두)
 */
require_once __DIR__ . '/_init.php';

ow_require_post();
$u = ow_require_login_api();
ow_verify_csrf();

$w      = ow_api_watch();
$uid    = (int)$u['id'];
$action = ow_param_str('action');
$p      = ow_perms($w, $u);

$deny = function (string $msg = '이 감시에서 그 일을 할 수 없습니다.') { ow_json_error($msg, 403); };
$msg  = '';

switch ($action) {
    case 'run':
        if (!$p['can_run']) $deny($p['state'] !== 'idle' ? '이미 실행 중이거나 대기 중입니다.' : '등록한 사람과 받는 사람만 실행할 수 있습니다.');
        ow_request_run($w, $uid);
        $msg = '실행을 요청했습니다. 1분 안에 시작합니다.';
        break;
    case 'cancel_run':
        if (!$p['can_cancel_run']) $deny('취소할 요청이 없거나 이미 시작했습니다.');
        ow_cancel_run($w, $uid);
        $msg = '실행 요청을 취소했습니다.';
        break;
    case 'pause':
        if (!$p['can_pause']) $deny();
        ow_watch_set_status($w, 'paused', $uid);
        $msg = '일시정지했습니다. 다시 켜기 전까지 정기 실행을 하지 않습니다.';
        break;
    case 'resume':
        if (!$p['can_resume']) $deny();
        ow_watch_set_status($w, 'active', $uid);
        $msg = '다시 감시합니다.';
        break;
    case 'end':
        if (!$p['can_end']) $deny();
        ow_watch_set_status($w, 'ended', $uid);
        $msg = '감시를 끝냈습니다. 기록은 그대로 남습니다.';
        break;
    case 'extend':
        if (!$p['can_extend']) $deny();
        $days = ow_param_int('days', 7);
        if (!in_array($days, [7, 14, 30], true)) $days = 7;
        $end = ow_watch_extend($w, $days, $uid);
        $msg = '종료일을 ' . date('n월 j일', strtotime($end)) . '로 늘렸습니다.';
        break;
    case 'subscribe':
        if (!$p['can_subscribe']) $deny();
        ow_subscribe((int)$w['id'], $uid, true);
        ow_log((int)$w['id'], $uid, 'subscribe');
        $msg = '이제 이 감시의 소식을 받습니다.';
        break;
    case 'unsubscribe':
        if (!$p['can_unsubscribe']) $deny();
        ow_subscribe((int)$w['id'], $uid, false);
        ow_log((int)$w['id'], $uid, 'unsubscribe');
        $msg = '이 감시의 소식을 그만 받습니다.';
        break;
    case 'baseline':
        if (!$p['can_baseline']) $deny();
        $text = ow_param_str('baseline');
        $runId = ow_param_int('run_id');
        if ($runId) {
            $run = ow_run_get($runId);
            if (!$run || (int)$run['watch_id'] !== (int)$w['id'] || trim((string)$run['baseline_hint']) === '') {
                ow_json_error('반영할 제안이 없습니다.', 404);
            }
            $text = trim((string)$run['baseline_hint']);
        }
        if (mb_strlen($text) > 8000) ow_json_error('기준 상태는 8,000자까지입니다.');
        ow_apply_baseline($w, $text, $uid);
        $msg = '기준 상태를 바꿨습니다. 다음 실행부터 이 내용을 기준으로 봅니다.';
        break;
    case 'delete':
        if (!$p['can_delete']) $deny();
        ow_watch_delete((int)$w['id']);
        ow_json_ok(['message' => '지웠습니다.', 'redirect' => 'index.php']);
        break;
    default:
        ow_json_error('알 수 없는 동작입니다.');
}

$w = ow_watch_get((int)$w['id']);
ow_json_ok(['message' => $msg, 'perms' => ow_perms($w, $u), 'state' => ow_present_state($w, $u)]);
