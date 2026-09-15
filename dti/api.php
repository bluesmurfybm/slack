<?php
/**
 * DTI 발표 JSON API — 프런트 컨트롤러.
 *
 * 화면(static/*.js)은 FastAPI 시절 경로를 그대로 쓴다. core.js 의 dtiApiURL() 이
 * `/magazineapi/topics/3` 을 `api.php?p=/topics/3` 으로 바꿔 여기로 보낸다.
 */

require_once __DIR__ . '/bootstrap.php';

$identity = dti_identity();
// 화면이 API 를 동시에 여러 개 부른다 — 세션 잠금을 쥐고 있으면 그게 직렬화된다
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

try {
    $req = dti_request_from_globals();
} catch (DtiError $e) {
    dti_send(dti_json(['detail' => $e->getMessage()], $e->status()));
    exit;
}

$config = dti_config_from_portal();
$pdo = dti_connect($config);
dti_migrate($pdo);

dti_send(dti_handle([
    'config' => $config,
    'pdo' => $pdo,
    'identity' => $identity,
    'mover' => null,
    'webhook' => null,
], $req));
