<?php
/**
 * 서버 일괄 배포 허용 — 내 상태. 본인 것만 다룬다.
 *
 *   GET                  { member, allowed, until, hours, now }
 *   PUT { enabled }      허용 켜기/끄기. 배포 가능 사용자가 아니면 403
 *
 * blue-app 의 deploy-svn-projects 스킬이 실행 직전에 GET 으로 allowed 를 확인한다.
 * 응답 꼴을 바꾸면 스킬(svndeploy/permission.py)도 같이 고쳐야 한다.
 */
require_once __DIR__ . '/../core/deploy.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$u      = require_portal_login();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'PUT') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body) || !array_key_exists('enabled', $body) || !is_bool($body['enabled'])) {
            throw new BoardError('enabled 값(true/false)이 필요합니다', 422);
        }
        deploy_set_enabled($u['email'], $body['enabled']);
    } elseif ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(deploy_state($u['email']), JSON_UNESCAPED_UNICODE);

} catch (BoardError $e) {
    http_response_code($e->getCode());
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[deploy] ' . basename(__FILE__) . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => '처리 중 오류가 났습니다'], JSON_UNESCAPED_UNICODE);
}
