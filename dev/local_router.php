<?php
/**
 * PHP 내장 서버(php -S)용 문지기 — 로컬_실행.bat 이 포털 루트를 문서루트로 띄우며 건다.
 * 운영 nginx 의 deny 규칙과 같은 것을 막는다: 설정·비밀 키·DB 파일·업로드 원본·배치 소스·도구.
 */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$deny = [
    '#(^|/)config(\.local|\.test)?\.php$#i',     // 루트·모듈 설정
    '#(^|/)(env|llm)\.config\.php$#i',
    '#\.(key|db|db-wal|db-shm|sqlite|sqlite-wal|sqlite-shm|sql|sh|bat|env)$#i',
    '#/\.#',                                    // .sessions, .git, .htaccess …
    '#^/(var|learn/var|dti/var|studio/var|blueledger/var)(/|$)#i',
    '#^/(blueledger/(tools|lib)|learn/tools|dti/tools|slackai/(tools|worker)|moodle/watch|core)(/|$)#i',
];
foreach ($deny as $re) {
    if (preg_match($re, $path)) {
        http_response_code(403);
        echo 'Forbidden';
        return true;
    }
}
return false;   // 나머지는 내장 서버가 평소대로 내준다(폴더면 index.php)
