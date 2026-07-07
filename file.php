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

/*
 * 디스크 캐시: Slack 파일은 URL(파일 ID) 기준으로 내용이 불변이므로
 * 한 번 받아두면 영구 재사용 가능. 다음 요청부터는 Slack 을 거치지 않고
 * 로컬 파일에서 즉시 스트리밍 → 초기 이미지 로딩 대폭 개선.
 */
$cacheDir = __DIR__ . '/.filecache';
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0777, true); }
$key       = hash('sha256', $u);
$cacheFile = $cacheDir . '/' . $key;         // 바이트
$metaFile  = $cacheFile . '.type';           // content-type

$serveFromCache = function ($data, $ctype) use ($u) {
    $len = strlen($data);
    header('Content-Type: ' . ($ctype ?: 'application/octet-stream'));
    // 불변 콘텐츠 → 브라우저도 장기 캐시
    header('Cache-Control: private, max-age=604800, immutable');
    header('Accept-Ranges: bytes');          // 동영상 탐색(seek) 지원 알림
    $isDl = !empty($_GET['dl']);
    if ($isDl) {
        $name = isset($_GET['name']) ? preg_replace('/[\r\n"\\\\\/]+/', '_', $_GET['name']) : 'download';
        header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($_GET['name'] ?? $name));
    }
    // Range 요청(동영상 seek/부분 로드) 처리 — 다운로드가 아닐 때만
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if (!$isDl && $range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
        $start = ($m[1] === '') ? 0 : (int)$m[1];
        $end   = ($m[2] === '') ? $len - 1 : (int)$m[2];
        if ($start > $end || $start >= $len) {
            http_response_code(416);
            header('Content-Range: bytes */' . $len);
            return;
        }
        $end = min($end, $len - 1);
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $len);
        header('Content-Length: ' . ($end - $start + 1));
        echo substr($data, $start, $end - $start + 1);
        return;
    }
    header('Content-Length: ' . $len);
    echo $data;
};

// 캐시 적중 시 Slack 을 거치지 않고 즉시 응답
if (is_file($cacheFile) && filesize($cacheFile) > 0) {
    $data  = file_get_contents($cacheFile);
    $ctype = is_file($metaFile) ? trim((string)file_get_contents($metaFile)) : '';
    $serveFromCache($data, $ctype);
    exit;
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

// 다음 요청부터 Slack 을 거치지 않도록 디스크에 저장(원자적 기록)
$tmp = $cacheFile . '.' . getmypid() . '.part';
if (@file_put_contents($tmp, $data) !== false) {
    @rename($tmp, $cacheFile);
    @file_put_contents($metaFile, (string)$ctype);
}

$serveFromCache($data, $ctype);
