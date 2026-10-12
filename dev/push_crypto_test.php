<?php
/**
 * core/push.php 시험 — 암호화·서명·발송 뒷정리. DB 와 네트워크를 쓰지 않는다.
 *
 *   php dev/push_crypto_test.php
 *
 * 다른 dev/*_test.php 는 함수를 떼어 eval 하지만, push.php 는 db.php 만 부르고
 * 부를 때 아무 일도 하지 않으므로(연결은 portal_db() 를 처음 부를 때) 그대로 require 한다.
 * VAPID 키는 임시 폴더에 만들어 루트 vapid.key 를 건드리지 않는다.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
date_default_timezone_set('Asia/Seoul');
require dirname(__DIR__) . '/core/push.php';

$pass = 0; $fail = 0;
function ok($name, $got, $want) {
    global $pass, $fail;
    $g = var_export($got, true); $w = var_export($want, true);
    if ($g === $w) { $pass++; echo "  OK   $name\n"; }
    else { $fail++; echo "  FAIL $name  받음 $g / 기대 $w\n"; }
}

$tmpDir = sys_get_temp_dir() . '/push_test_' . bin2hex(random_bytes(4));
mkdir($tmpDir);
$keyPath = $tmpDir . '/vapid.key';
push_vapid_path($keyPath);

/** 가짜 구독자(브라우저 역할) */
function fake_subscriber(): array {
    $k = push_ec_new();
    return ['priv' => $k, 'pub' => push_ec_public_raw($k), 'auth' => random_bytes(16)];
}

echo "\n[1] base64url\n";
$bin = random_bytes(65);
ok('왕복', push_b64url_decode(push_b64url_encode($bin)) === $bin, true);
ok('+ / = 없음', (bool)preg_match('#[+/=]#', push_b64url_encode("\xfb\xff\xfe")), false);
ok('채움 없는 입력 해독', push_b64url_decode('YWI'), 'ab');
ok('잘못된 입력은 빈 문자열', push_b64url_decode('***'), '');

echo "\n[2] VAPID 키 파일\n";
$k1 = push_vapid_keys();
ok('파일 생성', is_file($keyPath), true);
ok('공개키 65바이트', strlen($k1['public_raw']), 65);
ok('04 로 시작', $k1['public_raw'][0], "\x04");
ok('개인키 PEM', strpos($k1['private_pem'], 'PRIVATE KEY') !== false, true);
$mtime = filemtime($keyPath);
ok('두 번째 호출은 같은 키', push_vapid_keys()['public_raw'] === $k1['public_raw'], true);
ok('공개키 문자열 = 파일 값', push_public_key(), json_decode(file_get_contents($keyPath), true)['public']);
ok('파일을 다시 쓰지 않음', filemtime($keyPath), $mtime);

echo "\n[3] JWT (ES256)\n";
$jwt = push_vapid_jwt('https://fcm.googleapis.com');
$parts = explode('.', $jwt);
ok('세 조각', count($parts), 3);
ok('헤더', json_decode(push_b64url_decode($parts[0]), true), ['typ' => 'JWT', 'alg' => 'ES256']);
$claims = json_decode(push_b64url_decode($parts[1]), true);
ok('aud', $claims['aud'], 'https://fcm.googleapis.com');
ok('sub', $claims['sub'], PUSH_VAPID_SUBJECT);
ok('exp 는 24시간 이내', $claims['exp'] > time() && $claims['exp'] <= time() + 86400, true);
$sig = push_b64url_decode($parts[2]);
ok('서명 64바이트', strlen($sig), 64);
$vpub = openssl_pkey_get_public(push_raw_pub_to_pem($k1['public_raw']));
ok('서명 검증', openssl_verify("$parts[0].$parts[1]", push_raw_to_der($sig), $vpub, OPENSSL_ALGO_SHA256), 1);
$forged = push_b64url_encode(json_encode(['aud' => 'https://evil', 'exp' => $claims['exp'], 'sub' => $claims['sub']]));
ok('변조한 내용은 검증 실패', openssl_verify("$parts[0].$forged", push_raw_to_der($sig), $vpub, OPENSSL_ALGO_SHA256), 0);
ok('같은 aud 는 캐시', push_vapid_jwt('https://fcm.googleapis.com'), $jwt);
ok('aud 추출', push_audience('https://web.push.apple.com/QGuQyavXutnMH?x=1'), 'https://web.push.apple.com');

// R 의 첫 바이트가 0x80 이상이면 DER 에서 0x00 이 붙는다 — 떼고 붙이는 양쪽을 확인한다
$priv = openssl_pkey_get_private($k1['private_pem']);
$hiSeen = false; $loSeen = false; $allRound = true;
for ($i = 0; $i < 64 && !($hiSeen && $loSeen); $i++) {
    openssl_sign("m$i", $der, $priv, OPENSSL_ALGO_SHA256);
    $raw = push_der_to_raw($der);
    if (ord($raw[0]) >= 0x80) $hiSeen = true; else $loSeen = true;
    if (push_raw_to_der($raw) !== $der) $allRound = false;
    if (openssl_verify("m$i", push_raw_to_der($raw), $vpub, OPENSSL_ALGO_SHA256) !== 1) $allRound = false;
}
ok('DER ↔ raw 왕복(상위 비트 있음/없음 모두)', [$hiSeen, $loSeen, $allRound], [true, true, true]);

echo "\n[4] 원시 공개키 → PEM\n";
$sub = fake_subscriber();
$pk = openssl_pkey_get_public(push_raw_pub_to_pem($sub['pub']));
ok('openssl 이 읽음', $pk !== false, true);
ok('같은 점', push_ec_public_raw($pk) === $sub['pub'], true);
$threw = false;
try { push_raw_pub_to_pem(substr($sub['pub'], 1)); } catch (InvalidArgumentException $e) { $threw = true; }
ok('64바이트는 거절', $threw, true);

echo "\n[5] RFC 8291 암호화 왕복\n";
$plain = '{"title":"안녕","url":"dti/index.php"}';
$body = push_encrypt($plain, $sub['pub'], $sub['auth']);
ok('길이 = 86 + 평문 + 1 + 16', strlen($body), 86 + strlen($plain) + 1 + 16);
ok('rs = 4096', unpack('N', substr($body, 16, 4))[1], 4096);
ok('idlen = 65', ord($body[20]), 65);
ok('복호화', push_decrypt($body, $sub['priv'], $sub['auth']), $plain);
$bad = $body; $bad[100] = chr(ord($bad[100]) ^ 1);
ok('한 바이트 변조 → 실패', push_decrypt($bad, $sub['priv'], $sub['auth']), null);
ok('다른 auth → 실패', push_decrypt($body, $sub['priv'], random_bytes(16)), null);
$other = fake_subscriber();
ok('다른 구독자 키 → 실패', push_decrypt($body, $other['priv'], $sub['auth']), null);
ok('매번 다른 암호문', push_encrypt($plain, $sub['pub'], $sub['auth']) !== $body, true);
$big = str_repeat('가', 1000);   // 3000바이트
$bb = push_encrypt($big, $sub['pub'], $sub['auth']);
ok('3000바이트도 한 레코드(4096 이하)', strlen($bb) - 86 <= 4096, true);
ok('3000바이트 왕복', push_decrypt($bb, $sub['priv'], $sub['auth']) === $big, true);

echo "\n[6] 페이로드 · 주소\n";
$p = json_decode(push_payload(['kind' => 'test.ping', 'title' => 't', 'body' => 'b', 'url' => 'dti/index.php?x=1']), true);
ok('url 은 그대로', $p['url'], 'dti/index.php?x=1');
ok('url 비면 포털 첫 화면', push_clean_url(''), './');
ok('절대 주소 허용', push_clean_url('https://iworks.bizblue.co.kr/learn/'), 'https://iworks.bizblue.co.kr/learn/');
ok('javascript: 는 버림', push_clean_url('javascript:alert(1)'), './');
$p = push_payload(['kind' => 'test.ping', 'title' => 't', 'body' => str_repeat('나', 3000)]);
ok('긴 본문은 잘라서 한도 안', strlen($p) <= PUSH_MAX_PAYLOAD, true);
ok('잘라도 JSON', is_array(json_decode($p, true)), true);
ok('Topic 정리', push_clean_topic('dti topic/12 한글'), 'dti-topic-12');
ok('Topic 32자', strlen(push_clean_topic(str_repeat('a', 50))), 32);
ok('Topic 없음', push_clean_topic(''), null);

echo "\n[7] push_send — 던지지 않는다\n";
$row = ['sub_id' => 7, 'user_id' => 1, 'email' => 'akddd@naddle.net', 'fail_count' => 0,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
        'p256dh' => push_b64url_encode($sub['pub']), 'auth' => push_b64url_encode($sub['auth'])];
$seen = [];
$r = push_send(['kind' => 'test.ping', 'title' => '시험', 'body' => '본문', 'url' => 'learn/index.php', 'tag' => 't1',
                '_subs' => [$row], '_post' => function ($ep, $b, $h) use (&$seen, $sub) {
                    $seen = ['ep' => $ep, 'h' => $h, 'plain' => push_decrypt($b, $sub['priv'], $sub['auth'])];
                    return ['status' => 201, 'error' => null];
                }]);
ok('201 → sent', [$r['sent'], $r['failed'], $r['pruned']], [1, 0, 0]);
$got = json_decode($seen['plain'], true);
ok('받은 쪽이 푼 제목', $got['title'], '시험');
ok('받은 쪽이 푼 url', $got['url'], 'learn/index.php');
ok('받은 쪽 kind', $got['kind'], 'test.ping');
$hs = implode("\n", $seen['h']);
ok('Authorization: vapid', (bool)preg_match('/^Authorization: vapid t=[\w-]+\.[\w-]+\.[\w-]+, k=[\w-]{87}$/m', $hs), true);
ok('Content-Encoding', strpos($hs, 'Content-Encoding: aes128gcm') !== false, true);
ok('Topic', strpos($hs, 'Topic: t1') !== false, true);
ok('TTL', strpos($hs, 'TTL: 86400') !== false, true);

$r = push_send(['kind' => 'test.ping', 'title' => 't', '_subs' => [$row], '_post' => fn() => ['status' => 410]]);
ok('410 → pruned', [$r['sent'], $r['failed'], $r['pruned']], [0, 0, 1]);
$r = push_send(['kind' => 'test.ping', 'title' => 't', '_subs' => [$row], '_post' => fn() => ['status' => 500, 'error' => 'x']]);
ok('500 → failed', [$r['sent'], $r['failed'], $r['pruned']], [0, 1, 0]);
$r = push_send(['kind' => 'test.ping', 'title' => 't', '_subs' => [$row],
                '_post' => function () { throw new RuntimeException('네트워크 끊김'); }]);
ok('전송 함수가 던져도 → failed', [$r['failed'], $r['detail'][0]['error']], [1, '네트워크 끊김']);
$broken = $row; $broken['p256dh'] = 'AAAA';
$r = push_send(['kind' => 'test.ping', 'title' => 't', '_subs' => [$broken, $row], '_post' => fn() => ['status' => 201]]);
ok('망가진 구독 하나가 나머지를 막지 않음', [$r['sent'], $r['failed']], [1, 1]);
$r = push_send(['kind' => 'nope.kind', 'title' => 't', '_subs' => [$row], '_post' => fn() => ['status' => 201]]);
ok('모르는 종류 → skipped', [$r['sent'], $r['skipped']], [0, 1]);
$r = push_send(['kind' => 'test.ping', 'title' => '  ', '_subs' => [$row], '_post' => fn() => ['status' => 201]]);
ok('빈 제목 → skipped', [$r['sent'], $r['skipped']], [0, 1]);

echo "\n[8] 허용 목록 · 종류\n";
ok('대소문자 무시', push_can_receive('AKDDD@Naddle.net'), true);
ok('목록 밖', push_can_receive('someone@bluesoft.co.kr'), PUSH_TEST_ALLOW ? false : true);
ok('null', push_can_receive(null), PUSH_TEST_ALLOW ? false : true);
ok('test.ping 등록됨', push_kind('test.ping')['default'], true);
$keys = array_keys(push_kinds());
ok('key 는 kind 컬럼(40자) 안', max(array_map('strlen', $keys)) <= 40, true);
$sysOk = true;
$ws = array_column(json_decode(file_get_contents(dirname(__DIR__) . '/core/worksystems.json'), true), 'key');
foreach (push_kinds() as $k) if ($k['sys'] !== 'portal' && !in_array($k['sys'], $ws, true)) $sysOk = false;
ok('sys 는 portal 이거나 worksystems key', $sysOk, true);

echo "\n[9] 기기 이름 · 알림함 번호\n";
ok('iPhone 홈 화면 앱', push_device_label('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'), 'iPhone · Safari');
ok('Windows Edge', push_device_label('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0'), 'Windows · Edge');
ok('Android Chrome', push_device_label('Mozilla/5.0 (Linux; Android 14; SM-S918N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36'), 'Android · Chrome');
ok('Mac Chrome', push_device_label('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36'), 'Mac · Chrome');
ok('빈 UA', push_device_label(''), 'PC · 브라우저');
ok('페이로드 nid', json_decode(push_payload(['kind' => 'test.ping', 'title' => 't', 'nid' => 42]), true)['nid'], 42);
ok('nid 없으면 null', json_decode(push_payload(['kind' => 'test.ping', 'title' => 't']), true)['nid'], null);

echo "\n[10] 구독 정보 검사\n";
$good = ['endpoint' => 'https://fcm.googleapis.com/x', 'keys' => ['p256dh' => push_b64url_encode($sub['pub']), 'auth' => push_b64url_encode($sub['auth'])]];
ok('정상', push_clean_subscription($good)['endpoint'], 'https://fcm.googleapis.com/x');
foreach ([
    'http 주소'   => ['endpoint' => 'http://x/y'] + $good,
    '짧은 p256dh' => ['keys' => ['p256dh' => 'AAAA', 'auth' => $good['keys']['auth']]] + $good,
    '짧은 auth'   => ['keys' => ['p256dh' => $good['keys']['p256dh'], 'auth' => 'AAAA']] + $good,
    '배열 아님'   => 'x',
] as $name => $bad) {
    $threw = false;
    try { push_clean_subscription($bad); } catch (InvalidArgumentException $e) { $threw = true; }
    ok("거절: $name", $threw, true);
}

@unlink($keyPath); @rmdir($tmpDir);
echo "\n통과 $pass · 실패 $fail\n";
exit($fail ? 1 : 0);
