<?php
/**
 * envlog(Coursemos EnvLog) 모듈 인증 가드 — access/guard.php 와 같은 규칙.
 *  - 포털(blue-iWorks) 로그인만 요구한다. 개인 Slack 토큰은 필요 없다.
 */

date_default_timezone_set('Asia/Seoul');
require_once __DIR__ . '/../core/auth.php';   // 세션 시작 + current_portal_user()

/** 화면(HTML) 페이지용 — 미로그인이면 포털 로그인 화면으로 보낸다 */
function envlog_require_login() {
    $u = current_portal_user();
    if (!$u) {
        header('Location: ../index.php?need_login=envlog');
        exit;
    }
    return $u;
}

/** 세션 잠금을 바로 풀어 동시 AJAX(서버별 청크 읽기)가 직렬화되지 않게 한다 */
function envlog_session_release() {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
}
