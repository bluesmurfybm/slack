<?php
/**
 * 웹 푸시 CLI 발송 — 터미널과 Python 워커(slackai/worker)용.
 *
 *   php dev/push_cli.php --kind test.ping --title "제목" --body "본문" --url dti/index.php --to a@x,b@y
 *   php dev/push_cli.php --json-file /tmp/push.json
 *
 * --json-file 은 {kind,title,body,url,tag,to,ttl,urgency} 를 담은 UTF-8 JSON 이다.
 * Windows 에서 한글 인자는 코드페이지 때문에 깨지기 쉬워, 다른 언어에서 부를 때는
 * 파일로 넘긴다(slackai/tools/post_cli.php 의 --text-file 과 같은 이유).
 *
 * 출력은 push_send() 결과 JSON 한 줄. 발송이 0건이어도 종료 코드는 0 이다 —
 * 알림은 실패해도 부르는 쪽을 멈추지 않는다는 약속을 따른다. 인자가 잘못됐을 때만 2.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
date_default_timezone_set('Asia/Seoul');
require_once dirname(__DIR__) . '/core/push.php';

$opt = getopt('', ['kind:', 'title:', 'body:', 'url:', 'tag:', 'to:', 'ttl:', 'urgency:', 'json-file:', 'help']);

if (isset($opt['help'])) {
    fwrite(STDOUT, "usage: php dev/push_cli.php --kind KIND --title T [--body B] [--url U] [--tag G] [--to a@x,b@y]\n"
                 . "       php dev/push_cli.php --json-file FILE\n"
                 . "kinds: " . implode(', ', array_keys(push_kinds())) . "\n");
    exit(0);
}

if (isset($opt['json-file'])) {
    $data = json_decode((string)@file_get_contents($opt['json-file']), true);
    if (!is_array($data)) {
        fwrite(STDERR, "JSON 파일을 읽을 수 없습니다: {$opt['json-file']}\n");
        exit(2);
    }
} else {
    $data = [];
    foreach (['kind', 'title', 'body', 'url', 'tag', 'to', 'ttl', 'urgency'] as $k) {
        if (isset($opt[$k])) $data[$k] = $opt[$k];
    }
}

if (empty($data['kind']) || empty($data['title'])) {
    fwrite(STDERR, "--kind 와 --title 은 꼭 있어야 합니다 (--help)\n");
    exit(2);
}
if (!push_kind((string)$data['kind'])) {
    fwrite(STDERR, "알 수 없는 종류: {$data['kind']} — core/push_kinds.json 에 먼저 더해 주세요\n");
    exit(2);
}

// 시험 주입구(_subs/_post)는 CLI 로 열지 않는다
$send = array_intersect_key($data, array_flip(['kind', 'title', 'body', 'url', 'tag', 'to', 'ttl', 'urgency', 'icon']));
echo json_encode(push_send($send), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
exit(0);
