<?php
/**
 *   GET    /whoami                로그인 사용자 · 상태 목록
 *   GET    /sheets                시트 목록 + 상태별 대학 수
 *   POST   /sheets                시트 만들기 {title, description}
 *   GET    /sheets/{id}           시트 + 대학별 행 (?status=미조치 로 거르기)
 *   PUT    /sheets/{id}           시트 이름·설명 수정
 *   DELETE /sheets/{id}           시트 삭제
 *   PUT    /sheets/{id}/cells     대학별 상태 채우기 {items: [{school_id | school[, ver], status, note}]}
 *   GET    /sheets/{id}/cells/{school_id}/history   셀이 바뀌기 전 값 (최근 10건)
 *   GET    /sheets/{id}/versions  버전별 대학 상태·갱신 날짜 (?ver=4.5 ?status=미조치)
 *                                 — 로그인 대신 Authorization: Bearer <schooltodo_api_token | schooltodo_api_write_token> 도 받는다
 *   PUT    /sheets/{id}/status    대학별 상태 변경 {items: [{school_id | school[, ver], status, note}], by}
 *                                 — status 는 영문 코드·한글 둘 다. 로그인 대신 Bearer <schooltodo_api_write_token> 도 받는다
 */

require_once __DIR__ . '/bootstrap.php';

try {
    $req = schooltodo_request_from_globals();
} catch (SchooltodoError $e) {
    schooltodo_send(schooltodo_json(['detail' => $e->getMessage()], $e->status()));
    exit;
}

$config = schooltodo_config_from_portal();
$identity = schooltodo_session_identity();
// 세션 잠금을 쥐고 있으면 동시 요청이 직렬화된다
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$pdo = schooltodo_connect($config);
schooltodo_migrate($pdo);

schooltodo_send(schooltodo_handle([
    'config' => $config,
    'pdo' => $pdo,
    'identity' => $identity,
    'token_ok' => schooltodo_bearer_ok($config['api_token']),
    'write_token_ok' => schooltodo_bearer_ok($config['write_token']),
], $req));
