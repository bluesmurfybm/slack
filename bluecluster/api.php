<?php
/**
 * 노드용 API. 경로는 ?p= 로 받는다 (예: api.php?p=/jobs/job-1/claim).
 * 요청·응답 규약은 blue-cluster 저장소의 docs/iworks-api.md 를 따른다.
 *
 * - POST /jobs: 작업 등록
 * - GET /jobs/open: 리더가 필요한 작업
 * - GET /jobs/{id}: 작업 하나
 * - POST /jobs/{id}/claim: 리더 차지
 * - POST /jobs/{id}/lease: 리스 연장
 * - POST /jobs/{id}/pieces: 조각 등록
 * - POST /jobs/{id}/pieces/{pid}/start: 조각 시도 시작
 * - POST /jobs/{id}/pieces/{pid}/finish: 조각 시도 끝
 * - POST /jobs/{id}/pieces/{pid}/close: 리더가 조각 시도 닫기
 * - POST /jobs/{id}/finish: 작업 끝
 * - POST /nodes/heartbeat: 노드 하트비트
 * - GET /nodes/live: 살아 있는 노드
 *
 * 로그인 세션 또는 Authorization: Bearer <개인 API 토큰> 을 받는다.
 */

require_once __DIR__ . '/inc/bootstrap.php';
// 세션 잠금을 쥐고 있으면 같은 사용자의 동시 요청이 직렬화된다
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

require_once BCL_ROOT . '/db.php';
require_once BCL_ROOT . '/lib/repo.php';
require_once BCL_ROOT . '/lib/http.php';
require_once BCL_ROOT . '/lib/files.php';
require_once BCL_ROOT . '/lib/cluster.php';
require_once BCL_ROOT . '/routes/jobs.php';
require_once BCL_ROOT . '/routes/nodes.php';

try {
    $user = current_portal_user();
    $res = $user
        ? bcl_api_handle(
            ['pdo' => bcl_db(), 'user' => $user, 'now' => time(), 'files_dir' => bcl_files_dir()],
            $_SERVER['REQUEST_METHOD'],
            (string)($_GET['p'] ?? ''),
            file_get_contents('php://input')
        )
        : bcl_api_error(401, '인증 필요');
} catch (Throwable $e) {
    error_log('[bluecluster] ' . $e);
    $res = bcl_api_error(500, '서버 오류');
}
bcl_api_send($res);
