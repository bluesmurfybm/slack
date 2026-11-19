<?php
/**
 * 서버 일괄 배포 가능 사용자 명단.
 *
 *   GET       명단 + 후보(포털 계정 전원)   관리자
 *   POST      추가  { email }               관리자
 *   DELETE    빼기  ?email=...              관리자
 *
 * 명단에 넣는다고 바로 배포할 수 있는 것은 아니다. 본인이 api/deploy.php 로 허용을 켜야 한다.
 */
require_once __DIR__ . '/../core/deploy.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$u      = require_portal_login();
$method = $_SERVER['REQUEST_METHOD'];

try {
    board_require_admin($u);

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        deploy_add_user(isset($body['email']) ? $body['email'] : '', $u['email']);
    } elseif ($method === 'DELETE') {
        deploy_remove_user(isset($_GET['email']) ? $_GET['email'] : '');
    } elseif ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'rows'    => deploy_user_list(),
        'members' => portal_db()->query("SELECT name, email FROM portal_users ORDER BY name")->fetchAll(),
        'hours'   => DEPLOY_ALLOW_HOURS,
    ], JSON_UNESCAPED_UNICODE);

} catch (BoardError $e) {
    http_response_code($e->getCode());
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[deploy] ' . basename(__FILE__) . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => '처리 중 오류가 났습니다'], JSON_UNESCAPED_UNICODE);
}
