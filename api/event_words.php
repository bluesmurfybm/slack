<?php
/**
 * 일정 제목을 종류로 가르는 낱말.
 *
 *   GET       종류별 낱말 + 코드 기본값            관리자
 *   PUT       저장  { congrats: "결혼, 청첩, …" }  관리자
 *   DELETE    한 종류를 기본값으로  ?kind=…        관리자
 *
 * 순서(우선순위)와 아이콘은 코드가 쥔다. 여기서 바꿀 수 있는 것은 낱말뿐이다 —
 * 조사가 맨 위에 있어야 '부친상' 에 축하 색이 붙지 않는데, 그 순서를
 * 실수로라도 무너뜨릴 수 있으면 안 된다.
 *
 * 폭죽을 쏠지는 일정마다 고른다(portal_event.cheer_on) — 여기가 아니다.
 */
require_once __DIR__ . '/../core/board.php';
header('Content-Type: application/json; charset=utf-8');

$u      = require_portal_login();
$method = $_SERVER['REQUEST_METHOD'];

try {
    board_require_admin($u);

    if ($method === 'GET') {
        echo json_encode(['rows' => board_kind_word_rows()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'PUT') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        // 하나라도 잘못되면 아무것도 저장하지 않는다 — 반만 들어가면 화면과
        // DB 가 어긋나 무엇이 저장됐는지 알 수 없게 된다.
        foreach ($body as $k => $v) { board_text_to_words($v); }
        board_save_kind_words($body, $u['email']);
        echo json_encode(['rows' => board_kind_word_rows()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'DELETE') {
        board_reset_kind_words(isset($_GET['kind']) ? $_GET['kind'] : '');
        echo json_encode(['rows' => board_kind_word_rows()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => '지원하지 않는 요청입니다'], JSON_UNESCAPED_UNICODE);
} catch (BoardError $e) {
    http_response_code($e->getCode() ?: 400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // 화면이 HTML 오류를 받으면 파싱에서 죽는다. 무슨 일이든 JSON 으로 돌려준다.
    http_response_code(500);
    echo json_encode(['error' => '처리 중 문제가 생겼습니다: ' . $e->getMessage()],
        JSON_UNESCAPED_UNICODE);
}
