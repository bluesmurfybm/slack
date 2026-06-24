<?php
/**
 * 동기화 상태(경량). 화면 자동 새로고침 판단용.
 *  - changed_at : 마지막으로 데이터가 "변경"된 동기화 완료시각 (이 값이 늘면 화면 갱신)
 *  - synced_at  : 마지막 동기화 완료시각(변경 없어도 갱신)
 *  - running    : 현재 동기화 진행 중 여부(잠금 보유)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_login();
session_release();   // 세션 잠금 즉시 해제(5초 폴링 경합 방지)

header('Content-Type: application/json; charset=utf-8');

// 진행 중 여부: 잠금을 비차단으로 잡아보고 성공하면 즉시 해제(=실행 중 아님)
$running = false;
$fp = @fopen(sys_get_temp_dir() . '/slackapi_sync.lock', 'c');
if ($fp) {
    if (flock($fp, LOCK_EX | LOCK_NB)) {
        flock($fp, LOCK_UN);
    } else {
        $running = true;
    }
    fclose($fp);
}

echo json_encode([
    'changed_at' => (int)meta_get('data_changed_at', 0),
    'synced_at'  => (int)meta_get('last_synced_at', 0),
    'running'    => $running,
], JSON_UNESCAPED_UNICODE);
