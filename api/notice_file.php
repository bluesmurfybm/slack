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

        // 올린 게 있다고 했는데 $_FILES 가 비었다면 php.ini 한계를 넘긴 것이다.
        // 이때 PHP 는 POST 본문을 통째로 버리므로 아무 오류도 안 남는다 —
        // 여기서 잡지 않으면 "0개 저장, 오류 없음" 으로 조용히 끝난다.
        if (!$in && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new BoardError(
                '올린 파일이 서버에 도착하지 않았습니다. php.ini 의 post_max_size('
                . ini_get('post_max_size') . ') / upload_max_filesize('
                . ini_get('upload_max_filesize') . ') 를 넘었는지 확인하세요.', 413);
        }
        if (!$in) {
            throw new BoardError('올릴 파일이 없습니다', 422);
        }

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
} catch (Throwable $e) {
    // 여기까지 오면 DB 오류 같은 예상 밖의 일이다. HTML fatal 을 내보내면
    // 화면에서는 JSON 파싱이 깨져 "요청 실패" 한 줄로만 보인다.
    error_log('[board] notice_file: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => '첨부 처리 중 오류가 났습니다: ' . $e->getMessage()],
                     JSON_UNESCAPED_UNICODE);
}
