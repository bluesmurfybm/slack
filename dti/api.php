<?php
/**
 * DTI 발표 JSON API — 진입점.
 *
 * 화면(static/*.js)은 FastAPI 시절 경로를 그대로 쓴다. core.js 의 dtiApiURL() 이
 * `/magazineapi/topics/3` 을 `api.php?p=/topics/3` 으로 바꿔 여기로 보낸다.
 * 라우팅과 처리는 Dti\Kernel 이 하고, 이 파일은 신원을 만들어 넘기고 응답을 내보내기만 한다.
 */

require_once __DIR__ . '/bootstrap.php';

$identity = dti_identity();
// 화면이 API 를 동시에 여러 개 부른다 — 세션 잠금을 쥐고 있으면 그게 직렬화된다
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

try {
    $request = Dti\Http\Request::fromGlobals();
} catch (Dti\Http\ApiException $e) {
    Dti\Http\Response::json(['detail' => $e->getMessage()], $e->status())->send();
    exit;
}

(new Dti\Kernel(dti_config(), dti_database(), $identity))->handle($request)->send();
