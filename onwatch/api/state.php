<?php
/**
 * GET api/state.php?id=N — 실행 상태(대기·실행 중·쉼)와 마지막 실행.
 * 상세 화면이 '지금 실행' 을 누른 뒤 몇 초마다 묻는다. 끝나면 화면을 다시 읽는다.
 */
require_once __DIR__ . '/_init.php';

$u = ow_require_login_api();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // 읽기만 — 세션 잠금을 바로 푼다
$w = ow_api_watch();
ow_json_ok(['state' => ow_present_state($w, $u)]);
