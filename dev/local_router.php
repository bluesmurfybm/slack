<?php
/**
 * PHP 내장 서버(php -S)용 문지기 — 로컬_실행.bat 이 포털 루트를 문서루트로 띄우며 건다.
 * 운영 nginx 의 deny 규칙과 같은 것을 막는다: 설정·비밀 키·DB 파일·업로드 원본·배치 소스·도구.
 */
// 이 PC 의 시험용 DB(SQLite)로만 띄운다. config.php 가 MySQL 을 가리키면(예: 운영 설정을 복사해 온 경우)
// 운영 DB 에 붙지 않도록 아무 화면도 내주지 않는다.
require_once __DIR__ . '/../core/sqlite_compat.php';
if (!db_local_sqlite()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "로컬 시험은 시험용 DB(SQLite)로만 띄웁니다.\n"
       . "config.php 의 db.driver 가 'sqlite' 가 아니라 멈췄습니다 — 운영 DB 에 연결될 수 있습니다.\n"
       . "config.php 를 지우고 로컬_실행.bat 을 다시 실행하면 시험용 설정이 새로 만들어집니다.\n";
    return true;
}

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$deny = [
    '#(^|/)config(\.local|\.test)?\.php$#i',     // 루트·모듈 설정
    '#(^|/)(env|llm)\.config\.php$#i',
    '#\.(key|db|db-wal|db-shm|sqlite|sqlite-wal|sqlite-shm|sql|sh|bat|env)$#i',
    '#/\.#',                                    // .sessions, .git, .htaccess …
    '#^/(var|learn/var|dti/var|studio/var|blueledger/var)(/|$)#i',
    '#^/(blueledger/(tools|lib)|learn/tools|dti/tools|slackai/(tools|worker)|moodle/watch|envlog/daemon|core)(/|$)#i',
];
foreach ($deny as $re) {
    if (preg_match($re, $path)) {
        http_response_code(403);
        echo 'Forbidden';
        return true;
    }
}
return false;   // 나머지는 내장 서버가 평소대로 내준다(폴더면 index.php)
