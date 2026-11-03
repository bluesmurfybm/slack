<?php
/** 포털 알림함·웹 푸시(core/push.php). 알림이 실패해도 신청·승인은 그대로 끝나야 하므로 던지지 않는다. */

const LEARN_NOTICE_MINE = [
    S_APPROVED => '수강이 승인되었습니다',
    S_NO_REFUND => '수강이 승인되었습니다',
    S_REJECTED => '수강 신청이 반려되었습니다. 사유는 BlueLearn 에서 확인해 주세요',
    S_CLAIM_APPROVED => '수강료 청구가 승인되었습니다',
    S_CLAIM_REJECTED => '수강료 청구가 반려되었습니다. 사유는 BlueLearn 에서 확인해 주세요',
    S_REFUNDED => '수강료 환급이 끝났습니다',
];

function learn_notice_url($rid) {
    return 'learn/index.php?req=' . (int)$rid;
}

function learn_notice_send(array $opts) {
    try {
        require_once __DIR__ . '/../../core/push.php';
        push_send($opts);
    } catch (Throwable $e) {
        error_log('[learn] 알림 실패: ' . $e->getMessage());
    }
}

/** 신청 한 건의 상태가 $status 로 바뀌었다. 승인을 기다리면 관리자에게, 결과는 신청자에게 */
function learn_notify_request(array $req, $status, array $identity) {
    $actor = strtolower((string)($identity['email'] ?? ''));
    $who = ($identity['name'] ?? '') !== '' ? $identity['name'] : $actor;
    if ($status === S_REQUESTED || $status === S_CLAIMED) {
        $to = array_values(array_diff(learn_admin_emails(), [$actor]));
        if (!$to) return;
        learn_notice_send([
            'kind' => 'learn.review',
            'title' => ($status === S_CLAIMED ? '수강료 청구 · ' : '수강 신청 · ') . $req['title'],
            'body' => $who . ' 님이 ' . ($status === S_CLAIMED ? '수강료를 청구했습니다' : '수강을 신청했습니다'),
            'url' => learn_notice_url($req['id']),
            'to' => $to,
            'tag' => 'learn-req-' . (int)$req['id'],
        ]);
        return;
    }
    if (!isset(LEARN_NOTICE_MINE[$status])) return;
    $owner = strtolower((string)$req['applicant_email']);
    if ($owner === '' || $owner === $actor) return;
    learn_notice_send([
        'kind' => 'learn.mine',
        'title' => $status . ' · ' . $req['title'],
        'body' => LEARN_NOTICE_MINE[$status],
        'url' => learn_notice_url($req['id']),
        'to' => [$owner],
        'tag' => 'learn-req-' . (int)$req['id'],
    ]);
}

/** 직원 화면에 새로 뜨는 추천·필수 강의 — 대상자에게. 임시 저장(노출 끔)은 알리지 않는다 */
function learn_notify_catalog(array $c, array $identity) {
    if (!learn_is_open($c, date('Y-m-d'))) return;
    $except = [strtolower((string)($identity['email'] ?? ''))];
    learn_notice_send([
        'kind' => 'learn.catalog',
        'title' => $c['grade'] . ' 강의 · ' . $c['title'],
        'body' => $c['grade'] === GRADE_REQUIRED && $c['due_date'] !== '' ? '이수기한 ' . $c['due_date'] : '새 강의가 목록에 올라왔습니다',
        'url' => 'learn/index.php#catalog',
        'to' => ($c['target_scope'] ?? SCOPE_ALL) === SCOPE_SOME ? $c['target_emails'] : null,
        'except' => $except,
        'tag' => 'learn-catalog-' . (int)$c['id'],
    ]);
}

/** 화면에서 본 것은 알림함에서도 읽음으로 */
function learn_notice_seen($kindPrefix, $url) {
    try {
        $u = current_portal_user();
        if (!$u) return;
        require_once __DIR__ . '/../../core/push.php';
        push_inbox_mark_read_url((int)$u['id'], $kindPrefix, $url);
    } catch (Throwable $e) {
        error_log('[learn] 알림 읽음 실패: ' . $e->getMessage());
    }
}
