<?php
/**
 * 웹 푸시 — 내 구독과 종류별 설정. 본인 것만 다룬다.
 *
 *   GET                       공개키 · 종류 목록(켜짐 여부) · 내 기기 목록
 *   POST   { subscription }   이 기기 구독 저장 (PushSubscription.toJSON())
 *   DELETE { endpoint }       이 기기 구독 지우기 — endpoint 가 길어 쿼리가 아니라 본문으로 받는다
 *   DELETE { id }             내 기기 목록에서 하나 지우기(다른 기기)
 *   PUT    { kind, enabled }  종류 하나 켜고 끄기 (즉시 저장)
 *
 * 응답은 모두 GET 과 같은 꼴이라 화면은 받은 대로 다시 그리면 된다.
 *
 * 시험 발송은 여기 두지 않는다 — 관리자만 쓰는 일이라 dev/push_test.php 가 맡는다.
 * 이 API 는 누구나 자기 것만 고치는 곳으로 남겨 둔다.
 */
require_once __DIR__ . '/../core/board.php';      // BoardError, work_systems()
require_once __DIR__ . '/../core/push.php';
header('Content-Type: application/json; charset=utf-8');

$u      = require_portal_login();
$uid    = (int)$u['id'];
$method = $_SERVER['REQUEST_METHOD'];

function push_api_payload(array $u): array
{
    $sys = ['portal' => ['label' => 'blue-iWorks 포털', 'emoji' => '🏠']];
    foreach (work_systems() as $s) $sys[$s['key']] = ['label' => $s['label'], 'emoji' => $s['emoji'] ?? ''];

    $prefs   = push_prefs_of((int)$u['id']);
    $devices = push_subscriptions_of((int)$u['id']);
    $kinds = [];
    foreach (push_kinds() as $k) {
        $kinds[] = [
            'key'       => $k['key'],
            'sys'       => $k['sys'],
            'sys_label' => $sys[$k['sys']]['label'] ?? $k['sys'],
            'sys_emoji' => $sys[$k['sys']]['emoji'] ?? '',
            'label'     => $k['label'],
            'desc'      => $k['desc'],
            'enabled'   => $prefs[$k['key']],
        ];
    }
    return [
        'public_key'  => push_public_key(),
        'kinds'       => $kinds,
        'devices'     => count($devices),
        // 내 기기 목록. hash 로 화면이 '지금 이 기기' 를 가린다
        'device_list' => array_map(static fn ($d) => [
            'id' => (int)$d['id'], 'hash' => $d['hash'], 'label' => $d['label'],
            'created_at' => $d['created_at'], 'last_ok_at' => $d['last_ok_at'],
        ], $devices),
    ];
}

try {
    if ($method === 'GET') {
        echo json_encode(push_api_payload($u), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];

    if ($method === 'POST') {
        push_subscribe($uid, $body['subscription'] ?? null, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    } elseif ($method === 'DELETE') {
        if (isset($body['id'])) push_unsubscribe_id($uid, (int)$body['id']);
        else                    push_unsubscribe($uid, (string)($body['endpoint'] ?? ''));
    } elseif ($method === 'PUT') {
        if (!isset($body['kind']) || !array_key_exists('enabled', $body)) {
            throw new BoardError('무엇을 바꿀지 알 수 없습니다.', 400);
        }
        push_set_pref($uid, (string)$body['kind'], (bool)$body['enabled']);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(push_api_payload($u), JSON_UNESCAPED_UNICODE);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (BoardError $e) {
    http_response_code($e->getCode());
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[push] ' . basename(__FILE__) . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => '처리 중 오류가 났습니다: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
