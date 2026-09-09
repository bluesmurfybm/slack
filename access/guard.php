<?php
/**
 * access 모듈 인증 가드.
 *  - 포털(blue-iWorks) 로그인만 요구한다. slack 모듈처럼 개인 Slack 토큰까지 강제하지 않는다
 *    — 접속 정보 조회는 슬랙과 아무 상관이 없어서 토큰 없는 사람이 막히면 안 된다.
 */

date_default_timezone_set('Asia/Seoul');
require_once __DIR__ . '/../auth.php';   // 세션 시작 + current_portal_user()

/** 화면(HTML) 페이지용 — 미로그인이면 포털 로그인 화면으로 보낸다 */
function access_require_login() {
    $u = current_portal_user();
    if (!$u) {
        header('Location: ../index.php');
        exit;
    }
    return $u;
}

/** 읽기 전용 요청에서 세션 잠금을 바로 풀어 동시 AJAX 가 직렬화되지 않게 한다 */
function access_session_release() {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
}
