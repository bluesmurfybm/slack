<?php
/**
 * 업무 시스템 카드 묶음.
 *
 *   GET                묶음 + 카드 소속 + 카드 목록      로그인한 사람 누구나
 *   POST   { name }    묶음 만들기                       관리자
 *   PUT    { id, name }              이름 바꾸기         관리자
 *   PUT    { order:[id,…] }          묶음 순서           관리자
 *   PUT    { sys_key, group_id }     카드 소속           관리자 (group_id null 이면 뺀다)
 *   DELETE ?id=                      묶음 지우기         관리자 (카드는 '기타' 로 간다)
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 여기서 바꾸는 것은 **전사 공통**이다                              │
 * │                                                                  │
 * │ 묶음·묶음 순서·카드 소속은 모든 사람의 첫 화면을 바꾼다. 그래서   │
 * │ 관리자만 쓴다.                                                    │
 * │                                                                  │
 * │ 묶음 **안에서** 카드를 늘어놓는 순서는 사람마다 다르고, 그쪽은    │
 * │ api/me.php 의 tile_order 가 맡는다. 두 축을 여기서 섞지 않는다.   │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 읽기를 관리자로 막지 않는 이유 — 대시보드가 이 응답으로 카드를 그린다.
 * 묶음 이름은 첫 화면에 그대로 보이는 값이라 숨길 것이 없다.
 */
require_once __DIR__ . '/../core/board.php';
header('Content-Type: application/json; charset=utf-8');

$u      = require_portal_login();
$method = $_SERVER['REQUEST_METHOD'];

/** 화면이 한 번에 다 받도록 세 가지를 함께 준다. */
function tg_payload()
{
    return [
        'groups'  => board_tile_groups(),
        'assign'  => board_tile_assignments(),
        // 관리 화면이 "어느 카드를 어디에 넣을까" 를 그리려면 카드 목록이 필요하다.
        // 원본은 worksystems.json 이고 여기서는 그릴 만큼만 추린다.
        'systems' => array_map(static function ($s) {
            return ['key' => $s['key'], 'label' => $s['label'], 'color' => isset($s['color']) ? $s['color'] : null];
        }, work_systems()),
        'default_name' => board_tile_group_default_name(),
    ];
}

try {
    if ($method === 'GET') {
        echo json_encode(tg_payload(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    board_require_admin($u);
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }

    if ($method === 'POST') {
        board_create_tile_group(isset($body['name']) ? $body['name'] : '');
        echo json_encode(tg_payload(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'PUT') {
        // 세 가지 일을 한 메서드가 받는다. 무엇을 하려는지는 들어온 칸으로 가른다.
        if (array_key_exists('order', $body) && is_array($body['order'])) {
            board_reorder_tile_groups($body['order']);
        } elseif (array_key_exists('sys_key', $body)) {
            board_assign_tile(
                $body['sys_key'],
                array_key_exists('group_id', $body) ? $body['group_id'] : null
            );
        } elseif (array_key_exists('id', $body)) {
            board_rename_tile_group($body['id'], isset($body['name']) ? $body['name'] : '');
        } else {
            throw new BoardError('무엇을 바꿀지 알 수 없습니다.', 400);
        }
        echo json_encode(tg_payload(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'DELETE') {
        board_delete_tile_group(isset($_GET['id']) ? $_GET['id'] : 0);
        echo json_encode(tg_payload(), JSON_UNESCAPED_UNICODE);
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
