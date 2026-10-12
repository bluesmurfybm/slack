<?php
/**
 * 알림함 — 내가 받은 알림. 본인 것만 다룬다. 원본은 portal_notification(core/push.php 가 쌓는다).
 *
 *   GET  ?before=<id>&limit=30&unread=1   최신순 목록 + 안 읽은 수
 *   GET  ?count=1                          안 읽은 수만(메뉴 표시용 — 가볍게)
 *   POST { read: [id,…] }                  읽음 표시
 *   POST { read_all: true }                모두 읽음
 *   DELETE { ids: [id,…] }                 지우기
 *   DELETE { read: true }                  읽은 것 모두 지우기
 *
 * 화면은 index.php 의 '알림' 화면, 그리고 알림을 누를 때 sw.js 가 읽음 표시를 보낸다.
 */
require_once __DIR__ . '/../core/board.php';      // BoardError, work_systems()
require_once __DIR__ . '/../core/push.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$u      = require_portal_login();
$uid    = (int)$u['id'];
$method = $_SERVER['REQUEST_METHOD'];

/** 종류 key → 이름·시스템 이모지. 화면이 따로 json 을 읽지 않게 붙여 준다. */
function notif_decorate(array $rows): array
{
    static $sys = null;
    if ($sys === null) {
        $sys = ['portal' => ['label' => 'blue-iWorks 포털', 'emoji' => '🏠']];
        foreach (work_systems() as $s) $sys[$s['key']] = ['label' => $s['label'], 'emoji' => $s['emoji'] ?? ''];
    }
    foreach ($rows as &$r) {
        $k = push_kind($r['kind']);
        $s = $sys[$k['sys'] ?? 'portal'] ?? $sys['portal'];
        $r['id']         = (int)$r['id'];
        $r['kind_label'] = $k['label'] ?? $r['kind'];
        $r['sys_label']  = $s['label'];
        $r['sys_emoji']  = $s['emoji'];
    }
    unset($r);
    return $rows;
}

try {
    if ($method === 'GET') {
        if (!empty($_GET['count'])) {
            echo json_encode(['unread' => push_inbox_unread($uid)]);
            exit;
        }
        $list = push_inbox_list(
            $uid,
            isset($_GET['before']) ? (int)$_GET['before'] : null,
            isset($_GET['limit']) ? (int)$_GET['limit'] : 30,
            !empty($_GET['unread'])
        );
        echo json_encode([
            'items'    => notif_decorate($list['items']),
            'has_more' => $list['has_more'],
            'unread'   => push_inbox_unread($uid),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];

    if ($method === 'POST') {
        if (!empty($body['read_all']))         push_inbox_mark_read($uid);
        elseif (isset($body['read']) && is_array($body['read'])) push_inbox_mark_read($uid, $body['read']);
        else throw new BoardError('무엇을 읽음으로 할지 알 수 없습니다.', 400);
    } elseif ($method === 'DELETE') {
        if (isset($body['ids']) && is_array($body['ids'])) push_inbox_delete($uid, $body['ids']);
        elseif (!empty($body['read']))                    push_inbox_delete($uid);
        else throw new BoardError('무엇을 지울지 알 수 없습니다.', 400);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'unread' => push_inbox_unread($uid)]);

} catch (BoardError $e) {
    http_response_code($e->getCode());
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[push] ' . basename(__FILE__) . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => '처리 중 오류가 났습니다: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
