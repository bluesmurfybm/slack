<?php
/**
 * Slack 첨부파일 프록시. 로그인 필요.
 *  - Slack 파일(url_private/thumb)은 Bearer 토큰이 있어야 접근 가능하므로,
 *    서버가 로그인 사용자 토큰으로 대신 받아 브라우저로 스트리밍한다.
 *  GET ?u=<https://files.slack.com/...>
 */
require_once __DIR__ . '/auth.php';
require_login();
session_release();

$tok = current_token();
$u   = isset($_GET['u']) ? $_GET['u'] : '';

// 보안: 로그인 토큰 + Slack 파일 도메인만 허용
if ($tok === '' || strpos($u, 'https://files.slack.com/') !== 0) {
    http_response_code(400);
    exit('bad request');
}

$ch = curl_init($u);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tok],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 20,
]);
$data  = curl_exec($ch);
$ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($data === false || $code !== 200) {
    http_response_code(502);
    exit('fetch failed (http ' . $code . ')');
}

// Slack 이 파일 대신 HTML 로그인/에러 페이지를 주면 = 대개 토큰에 files:read 스코프 없음
if (stripos((string)$ctype, 'text/html') !== false) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("이미지를 받지 못했습니다. 로그인 토큰에 'files:read' 스코프가 없을 가능성이 큽니다.\n"
       . "Slack 앱 OAuth & Permissions → User Token Scopes 에 files:read 추가 후 재설치·재로그인 하세요.");
}

header('Content-Type: ' . ($ctype ?: 'application/octet-stream'));
header('Cache-Control: private, max-age=3600');
// 다운로드 모드: 파일명 지정해 첨부로 내려받기
if (!empty($_GET['dl'])) {
    $name = isset($_GET['name']) ? preg_replace('/[\r\n"\\\\\/]+/', '_', $_GET['name']) : 'download';
    header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($_GET['name'] ?? $name));
    header('Content-Length: ' . strlen($data));
}
echo $data;
