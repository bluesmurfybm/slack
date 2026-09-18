<?php
/**
 * 공지 첨부 — 올리기 / 내려받기 / 지우기.
 *
 *   POST   ?notice_id=3   multipart/form-data, files[]    관리자
 *   GET    ?id=7          내려받기 (로그인한 구성원 누구나)
 *   DELETE ?id=7          지우기                          관리자
 *
 * 저장 위치는 var/notice 이고 웹으로 직접 못 받는다. 반드시 이 파일을 거친다.
 */
require_once __DIR__ . '/../core/board.php';

$u      = require_portal_login();
$method = $_SERVER['REQUEST_METHOD'];

// 내려받기만 JSON 이 아니다. 나머지 응답은 전부 JSON.
if ($method === 'GET') {
    try {
        $f    = board_file(isset($_GET['id']) ? (int)$_GET['id'] : 0);
        $path = board_resolve_file($f['stored_name']);
        list($mime, $disposition) = board_disposition($f['orig_name']);

        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disposition);
        header('Content-Length: ' . filesize($path));
        // 브라우저가 내용을 보고 타입을 바꿔 잡지 못하게 한다.
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, must-revalidate');
        readfile($path);
    } catch (BoardError $e) {
        http_response_code($e->getCode());
        header('Content-Type: text/plain; charset=utf-8');
        echo $e->getMessage();
    }
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    board_require_admin($u);

    if ($method === 'POST') {
        $noticeId = isset($_GET['notice_id']) ? (int)$_GET['notice_id'] : 0;
        board_notice($noticeId);   // 없으면 404

        $saved  = [];
        $errors = [];
        // PHP 는 files[] 를 필드마다 배열로 흩어 놓는다. 한 건씩 다시 모아 넘긴다.
        $in = isset($_FILES['files']) ? $_FILES['files'] : null;
        if ($in && is_array($in['name'])) {
            for ($i = 0; $i < count($in['name']); $i++) {
                try {
                    $saved[] = board_save_file($noticeId, [
                        'name'     => $in['name'][$i],
                        'type'     => $in['type'][$i],
                        'tmp_name' => $in['tmp_name'][$i],
                        'error'    => $in['error'][$i],
                        'size'     => $in['size'][$i],
                    ]);
                } catch (BoardError $e) {
                    $errors[] = $in['name'][$i] . ' — ' . $e->getMessage();
                }
            }
        }
        echo json_encode([
            'saved'  => count($saved),
            'errors' => $errors,
            'files'  => board_notice_files($noticeId),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'DELETE') {
        board_delete_file(isset($_GET['id']) ? (int)$_GET['id'] : 0);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'method not allowed'], JSON_UNESCAPED_UNICODE);

} catch (BoardError $e) {
    http_response_code($e->getCode());
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
