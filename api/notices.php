<?php
/**
 * 공지 목록 / 상세 / 등록 / 수정 / 삭제.
 *
 *   GET    ?page=1&size=10   목록 (구성원 누구나)
 *   GET    ?id=3             상세 (조회수 +1)
 *   POST                     등록  { title, body, is_pinned }   관리자
 *   PUT    ?id=3             수정  { title, body, is_pinned }   관리자
 *   DELETE ?id=3             삭제                               관리자
 */
require_once __DIR__ . '/../core/board.php';
header('Content-Type: application/json; charset=utf-8');

$u      = require_portal_login();
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try {
    if ($method === 'GET' && $id) {
        // 자기가 쓴 글을 열어도 조회수가 오르지만, 사내 공지에서 그 차이는 의미가 없다.
        $n = board_notice($id, true);
        echo json_encode([
            'notice'   => $n,
            'can_edit' => board_is_admin($u['email']),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'GET') {
        $size = isset($_GET['size']) ? max(1, min(50, (int)$_GET['size'])) : 10;
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        // 관리 화면에서만 예약·종료된 공지까지 본다. 구성원 목록에는 안 섞인다.
        $all  = (isset($_GET['scope']) ? $_GET['scope'] : '') === 'manage';
        if ($all) {
            board_require_admin($u);
        }
        echo json_encode([
            'rows'     => board_notices($size, ($page - 1) * $size, $all),
            'total'    => board_notice_count($all),
            'page'     => $page,
            'size'     => $size,
            'can_edit' => board_is_admin($u['email']),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    board_require_admin($u);
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($method === 'POST') {
        echo json_encode(['id' => board_create_notice($body, $u)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'PUT' && $id) {
        board_update_notice($id, $body);
        echo json_encode(['id' => $id], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'DELETE' && $id) {
        board_delete_notice($id);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'method not allowed'], JSON_UNESCAPED_UNICODE);

} catch (BoardError $e) {
    http_response_code($e->getCode());
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
