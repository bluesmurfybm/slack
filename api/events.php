<?php
/**
 * 중요 일정 — 첫 화면 D-day 목록과 관리.
 *
 *   GET    ?scope=upcoming&limit=4   다가오는 일정 (구성원 누구나, 첫 화면용)
 *   GET    ?scope=all                지난 것까지 전부 (관리 화면용)
 *   POST                             등록  { title, starts_on, ends_on, place, memo }  관리자
 *   PUT    ?id=3                     수정                                              관리자
 *   DELETE ?id=3                     삭제                                              관리자
 */
require_once __DIR__ . '/../core/board.php';
header('Content-Type: application/json; charset=utf-8');

$u      = require_portal_login();
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try {
    if ($method === 'GET') {
        $all = (isset($_GET['scope']) ? $_GET['scope'] : 'upcoming') === 'all';
        if ($all) {
            board_require_admin($u);
            $rows = board_all_events();
        } else {
            $limit = isset($_GET['limit']) ? max(1, min(20, (int)$_GET['limit'])) : 4;
            $rows  = board_events($limit);
        }
        echo json_encode([
            'rows'     => $rows,
            'can_edit' => board_is_admin($u['email']),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    board_require_admin($u);
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($method === 'POST' || ($method === 'PUT' && $id)) {
        $savedId = board_save_event($method === 'PUT' ? $id : 0, $body, $u);
        echo json_encode(['id' => $savedId], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'DELETE' && $id) {
        board_delete_event($id);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'method not allowed'], JSON_UNESCAPED_UNICODE);

} catch (BoardError $e) {
    http_response_code($e->getCode());
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
