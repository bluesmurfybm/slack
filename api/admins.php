<?php
/**
 * 포털 관리자 명단.
 *
 *   GET       명단 + 후보(포털 계정 전원)   관리자
 *   POST      추가  { email }               관리자
 *   DELETE    빼기  ?email=...              관리자 (고정 관리자는 뺄 수 없다)
 */
require_once __DIR__ . '/../core/board.php';
header('Content-Type: application/json; charset=utf-8');

$u      = require_portal_login();
$method = $_SERVER['REQUEST_METHOD'];

try {
    board_require_admin($u);

    if ($method === 'GET') {
        echo json_encode([
            'rows'    => board_admin_list(),
            // 추가할 때 고르라고 포털 계정 전원을 같이 준다.
            'members' => portal_db()->query("SELECT name, email FROM portal_users ORDER BY name")->fetchAll(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        board_add_admin(isset($body['email']) ? $body['email'] : '', $u['email']);
        echo json_encode(['rows' => board_admin_list()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'DELETE') {
        board_remove_admin(isset($_GET['email']) ? $_GET['email'] : '');
        echo json_encode(['rows' => board_admin_list()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'method not allowed'], JSON_UNESCAPED_UNICODE);

} catch (BoardError $e) {
    http_response_code($e->getCode());
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[board] ' . basename(__FILE__) . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => '처리 중 오류가 났습니다: ' . $e->getMessage()],
                     JSON_UNESCAPED_UNICODE);
}
