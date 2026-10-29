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
 * 받기·보내기 모두 디스크↔소켓으로 흘려보낸다 — 큰 동영상을 통째로 메모리에 올리면
 * memory_limit(운영 기본 128M)을 넘겨 실패한다.
 */
// 운영(Linux)에서 slack/ 폴더 주인이 배포 계정이면 웹서버(www-data)가 .filecache 를 만들거나 쓸 수 없다
// (.gitignore 대상이라 배포로도 안 생긴다) → 런타임 폴더(slack/.cache, 안 되면 시스템 임시 폴더) 아래로 물러난다
$cacheDir = null;
foreach ([__DIR__ . '/.filecache', slack_runtime_dir() . '/filecache'] as $d) {
    if (!is_dir($d)) @mkdir($d, 0775, true);
    if (is_dir($d) && is_writable($d)) { $cacheDir = $d; break; }
}
if ($cacheDir === null) {
    http_response_code(500);
    exit('cache write failed: 캐시 폴더에 쓸 수 없습니다 (' . __DIR__ . '/.filecache 권한 확인)');
}
// 웹 폴더 안이라 이름(sha256)만 알면 로그인 없이 받을 수 있다 — 직접 접근은 막는다(기존 폴더도 보강)
if (!is_file($cacheDir . '/.htaccess')) @file_put_contents($cacheDir . '/.htaccess', "Require all denied\n");
$key       = hash('sha256', $u);
$cacheFile = $cacheDir . '/' . $key;         // 바이트
$metaFile  = $cacheFile . '.type';           // content-type

$serveFile = function ($path, $ctype) {
    $len = (int)filesize($path);
    header('Content-Type: ' . ($ctype ?: 'application/octet-stream'));
    // 불변 콘텐츠 → 브라우저도 장기 캐시
    header('Cache-Control: private, max-age=604800, immutable');
    header('Accept-Ranges: bytes');          // 동영상 탐색(seek) 지원 알림
    $isDl = !empty($_GET['dl']);
    if ($isDl) {
        $name = isset($_GET['name']) ? preg_replace('/[\r\n"\\\\\/]+/', '_', $_GET['name']) : 'download';
        header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($_GET['name'] ?? $name));
    }
    $start = 0; $end = $len - 1;
    // Range 요청(동영상 seek/부분 로드) 처리 — 다운로드가 아닐 때만
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if (!$isDl && $range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
        if ($m[1] === '' && $m[2] !== '') {                // bytes=-N : 끝에서 N바이트
            $start = max(0, $len - (int)$m[2]);
        } else {
            $start = ($m[1] === '') ? 0 : (int)$m[1];
            $end   = ($m[2] === '') ? $len - 1 : min((int)$m[2], $len - 1);
        }
        if ($start > $end || $start >= $len) {
            http_response_code(416);
            header('Content-Range: bytes */' . $len);
            return;
        }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $len);
    }
    header('Content-Length: ' . ($end - $start + 1));
    $fp = fopen($path, 'rb');
    if (!$fp) return;
    fseek($fp, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fp) && !connection_aborted()) {
        $buf = fread($fp, min(1 << 16, $left));
        if ($buf === false || $buf === '') break;
        echo $buf;
        $left -= strlen($buf);
        flush();
    }
    fclose($fp);
};

// 캐시 적중 시 Slack 을 거치지 않고 즉시 응답
if (is_file($cacheFile) && filesize($cacheFile) > 0) {
    $ctype = is_file($metaFile) ? trim((string)file_get_contents($metaFile)) : '';
    $serveFile($cacheFile, $ctype);
    exit;
}

// 임시 파일로 바로 받는다(원자적 기록: 다 받은 뒤 rename)
$tmp = $cacheFile . '.' . getmypid() . '.part';
$out = @fopen($tmp, 'wb');
if (!$out) {
    http_response_code(500);
    exit('cache write failed: ' . $cacheDir . ' 에 쓸 수 없습니다');
}
$ch = curl_init($u);
curl_setopt_array($ch, [
    CURLOPT_FILE           => $out,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tok],
    CURLOPT_FOLLOWLOCATION => true,           // 리다이렉트 대상이 다른 호스트면 curl 이 Authorization 을 떼고 따라간다
    CURLOPT_TIMEOUT        => 120,
]);
$ok    = curl_exec($ch);
$ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
fclose($out);

if ($ok === false || $code !== 200) {
    @unlink($tmp);
    http_response_code(502);
    exit('fetch failed (http ' . $code . ')');
}

// Slack 이 파일 대신 HTML 로그인/에러 페이지를 주면 = 대개 토큰에 files:read 스코프 없음
if (stripos((string)$ctype, 'text/html') !== false) {
    @unlink($tmp);
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("이미지를 받지 못했습니다. 로그인 토큰에 'files:read' 스코프가 없을 가능성이 큽니다.\n"
       . "Slack 앱 OAuth & Permissions → User Token Scopes 에 files:read 추가 후 재설치·재로그인 하세요.");
}

// 다음 요청부터 Slack 을 거치지 않도록 캐시에 확정
if (@rename($tmp, $cacheFile)) {
    @file_put_contents($metaFile, (string)$ctype);
    $serveFile($cacheFile, $ctype);
} else {
    $serveFile($tmp, $ctype);                // 동시에 같은 파일을 받은 요청이 먼저 확정한 경우 등
    @unlink($tmp);
}
