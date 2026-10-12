<?php
/**
 * 웹 푸시 알림 — 모든 모듈이 같이 쓰는 뼈대.
 *
 * 모듈은 이것만 부르면 된다.
 *
 *     require_once __DIR__ . '/../core/push.php';
 *     push_send([
 *         'kind'  => 'dti.presenter',            // core/push_kinds.json 에 있는 key
 *         'title' => 'DTI 발표자 등록',
 *         'body'  => '아티클 제목 — 홍길동',
 *         'url'   => 'dti/index.php',            // 알림을 누르면 열 주소(포털 루트 기준 또는 절대 URL)
 *         'to'    => ['someone@bluesoft.co.kr'], // 생략하면 이 종류를 켜 둔 전원
 *     ]);
 *
 * 암호화(RFC 8291 aes128gcm)·서명(VAPID, RFC 8292)·구독 관리·종류별 켜고 끄기·
 * 발송 기록은 전부 여기서 한다.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ push_send() 는 절대 예외를 던지지 않는다                           │
 * │                                                                  │
 * │ 알림은 곁가지다. 발표자 등록·배정 확정 같은 본 작업이 푸시 서버가  │
 * │ 느리거나 죽었다고 실패하면 사람은 "버튼이 안 먹는다" 로 겪는다.   │
 * │ studio 의 Notifier 와 같은 약속이다. 실패는 돌려주는 숫자와       │
 * │ portal_push_log, error_log 에만 남는다.                           │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 왜 composer 라이브러리(minishlink/web-push)가 아닌가               │
 * │                                                                  │
 * │ 운영 서버에는 vendor/ 를 깔지 않는다(phpunit 용 dev 의존만 있다). │
 * │ 필요한 것은 P-256 서명·ECDH·HKDF·AES-128-GCM 네 가지뿐이고 PHP    │
 * │ openssl 확장에 다 있다. 배포 절차를 새로 만드는 쪽이 더 비싸다.   │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 이 파일은 core/db.php 만 부른다. auth.php(세션 시작)를 부르지 않아야
 * CLI(dev/push_cli.php, 시험)에서 그대로 쓸 수 있다.
 */

require_once __DIR__ . '/db.php';

/**
 * 시험 기간 동안 **실제로 발송되는** 계정.
 *
 * 비어 있으면 종류를 켜 둔 전원에게 간다. 구독·설정은 누구나 할 수 있고
 * 막는 것은 발송뿐이다. 시험이 끝나면 [] 로 비운다.
 */
const PUSH_TEST_ALLOW = ['akddd@naddle.net', 'akddd@bluesoft.co.kr'];

/** VAPID 'sub'. 푸시 서비스(구글·모질라·애플)가 문제 생기면 연락할 곳. */
const PUSH_VAPID_SUBJECT = 'mailto:akddd@bluesoft.co.kr';

const PUSH_TTL_DEFAULT = 86400;      // 기기가 꺼져 있으면 푸시 서비스가 하루까지 들고 있는다
const PUSH_MAX_PAYLOAD = 3000;       // 한 레코드(4096) - 헤더 86 - 태그 16 - 구분자 1 에 여유를 둔 값
const PUSH_FAIL_PRUNE  = 5;          // 5xx·타임아웃이 연달아 이만큼이면 죽은 구독으로 보고 지운다
const PUSH_URGENCIES   = ['very-low', 'low', 'normal', 'high'];

// P-256 SubjectPublicKeyInfo 의 DER 머리. 뒤에 65바이트 점(04||X||Y)을 붙이면 공개키 PEM 이 된다.
const PUSH_P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

// =====================================================================
// 작은 도우미
// =====================================================================

function push_b64url_encode(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function push_b64url_decode(string $s): string
{
    $s = strtr(trim($s), '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    $out = base64_decode($s, true);
    return $out === false ? '' : $out;
}

/** 시험 기간 허용 목록(소문자). */
function push_allowlist(): array
{
    return array_values(array_map('strtolower', PUSH_TEST_ALLOW));
}

/** 이 계정으로 실제 발송이 되는가. 마이페이지가 알림 칸을 보일지 정할 때 쓴다. */
function push_can_receive(?string $email): bool
{
    $allow = push_allowlist();
    if (!$allow) return true;
    return $email !== null && in_array(strtolower(trim($email)), $allow, true);
}

// =====================================================================
// 알림 종류 — core/push_kinds.json 이 유일한 원본
//
// worksystems.json 과 같은 발상이다. 모듈이 알림을 새로 내려면 json 에
// 한 줄 더하고 push_send() 를 부른다. JSON 이라 Python 워커도 읽을 수 있다.
// =====================================================================

/** @return array<string,array> key => {key,sys,label,desc,default} (json 순서 유지) */
function push_kinds(): array
{
    static $kinds = null;
    if ($kinds === null) {
        $kinds = [];
        $rows = json_decode((string)@file_get_contents(__DIR__ . '/push_kinds.json'), true);
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (!isset($r['key'])) continue;
            $kinds[$r['key']] = [
                'key'     => (string)$r['key'],
                'sys'     => (string)($r['sys'] ?? 'portal'),
                'label'   => (string)($r['label'] ?? $r['key']),
                'desc'    => (string)($r['desc'] ?? ''),
                'default' => !empty($r['default']),
            ];
        }
    }
    return $kinds;
}

function push_kind(string $key): ?array
{
    return push_kinds()[$key] ?? null;
}

// =====================================================================
// EC 키 — Windows PHP 대비
// =====================================================================

/**
 * P-256 키를 새로 만든다.
 *
 * Windows 용 PHP 는 openssl.cnf 를 못 찾으면 키 생성이 실패한다. 기본 설정으로
 * 먼저 해 보고, 안 되면 core/push_openssl.cnf(빈 설정)를 넘겨 다시 한다.
 * private_key_bits 는 EC 에서 쓰이지 않지만, 설정 파일이 비어 있으면 PHP 가
 * 0 으로 읽고 "384비트 이상" 검사에 걸리므로 함께 준다.
 */
function push_ec_new()
{
    $opt = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'private_key_bits' => 2048];
    $k = @openssl_pkey_new($opt);
    if ($k === false) {
        while (openssl_error_string() !== false) { /* 쌓인 오류 비우기 */ }
        $k = @openssl_pkey_new($opt + ['config' => __DIR__ . '/push_openssl.cnf']);
    }
    if ($k === false) {
        $err = [];
        while (($e = openssl_error_string()) !== false) $err[] = $e;
        throw new RuntimeException('EC 키를 만들 수 없습니다: ' . implode(' / ', $err));
    }
    return $k;
}

/** 키 → 65바이트 비압축 공개키(04||X||Y). X·Y 는 앞자리 0 이 빠져 올 수 있어 32바이트로 채운다. */
function push_ec_public_raw($key): string
{
    $d = openssl_pkey_get_details($key)['ec'];
    return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
}

/** 65바이트 공개키 → openssl 이 읽는 PEM. openssl_pkey_derive() 는 맨 점을 받지 못한다. */
function push_raw_pub_to_pem(string $raw65): string
{
    if (strlen($raw65) !== 65 || $raw65[0] !== "\x04") {
        throw new InvalidArgumentException('P-256 공개키는 04 로 시작하는 65바이트여야 합니다');
    }
    $der = hex2bin(PUSH_P256_SPKI_PREFIX) . $raw65;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

// =====================================================================
// VAPID 키쌍 — 루트 vapid.key (sso_secret.key 와 같은 방식, git 제외)
//
// 한 번 만들면 **바꾸면 안 된다.** 키가 바뀌면 지금까지 받은 구독이 전부
// 403 으로 거절되고, 사람마다 마이페이지에서 다시 "알림 받기" 를 눌러야 한다.
// 그래서 파일이 있는데 못 읽으면 새로 만들지 않고 실패한다.
// =====================================================================

/** 키 파일 경로. 시험이 임시 경로로 바꿔 끼울 수 있게 setter 를 겸한다. */
function push_vapid_path(?string $set = null): string
{
    static $path = null;
    if ($set !== null) $path = $set;
    return $path ?? dirname(__DIR__) . '/vapid.key';
}

/** @return array{private_pem:string, public_raw:string} */
function push_vapid_keys(): array
{
    static $cache = [];
    $path = push_vapid_path();
    if (isset($cache[$path])) return $cache[$path];

    if (!is_file($path)) {
        $k = push_ec_new();
        if (!@openssl_pkey_export($k, $pem) || !$pem) {
            while (openssl_error_string() !== false) {}
            if (!openssl_pkey_export($k, $pem, null, ['config' => __DIR__ . '/push_openssl.cnf'])) {
                throw new RuntimeException('VAPID 개인키를 내보낼 수 없습니다');
            }
        }
        $json = json_encode(['private_pem' => $pem, 'public' => push_b64url_encode(push_ec_public_raw($k)),
                             'created_at' => date('c')], JSON_PRETTY_PRINT);
        // 'x' — 이미 있으면 실패한다. 두 요청이 동시에 처음 만들려 할 때 한쪽 키만 남게 한다.
        $fh = @fopen($path, 'x');
        if ($fh) {
            fwrite($fh, $json);
            fclose($fh);
            @chmod($path, 0600);
            error_log('[push] VAPID 키를 새로 만들었습니다: ' . $path);
        }
        clearstatcache(true, $path);
    }

    $data = json_decode((string)@file_get_contents($path), true);
    if (!is_array($data) || empty($data['private_pem']) || empty($data['public'])) {
        throw new RuntimeException('VAPID 키 파일을 읽을 수 없습니다: ' . $path);
    }
    $pub = push_b64url_decode($data['public']);
    if (strlen($pub) !== 65) {
        throw new RuntimeException('VAPID 공개키 형식이 잘못되었습니다: ' . $path);
    }
    return $cache[$path] = ['private_pem' => $data['private_pem'], 'public_raw' => $pub];
}

/** 브라우저 pushManager.subscribe({applicationServerKey}) 에 넘길 값. */
function push_public_key(): string
{
    return push_b64url_encode(push_vapid_keys()['public_raw']);
}

// =====================================================================
// ES256 서명 — openssl 은 DER 을 주고 JWT 는 r||s 64바이트를 원한다
// =====================================================================

function push_der_to_raw(string $der): string
{
    $o = 0;
    if (strlen($der) < 8 || ord($der[$o++]) !== 0x30) throw new InvalidArgumentException('DER 서명이 아닙니다');
    $len = ord($der[$o++]);
    if ($len & 0x80) $o += $len & 0x7f;           // 긴 길이 형식(P-256 에서는 안 나오지만 방어)
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        if (ord($der[$o++] ?? "\0") !== 0x02) throw new InvalidArgumentException('DER 정수가 아닙니다');
        $l = ord($der[$o++]);
        $v = ltrim(substr($der, $o, $l), "\0");   // 부호용 0x00 제거
        $o += $l;
        if (strlen($v) > 32) throw new InvalidArgumentException('P-256 서명 값이 너무 깁니다');
        $out .= str_pad($v, 32, "\0", STR_PAD_LEFT);
    }
    return $out;
}

function push_raw_to_der(string $raw): string
{
    if (strlen($raw) !== 64) throw new InvalidArgumentException('r||s 는 64바이트여야 합니다');
    $body = '';
    foreach ([substr($raw, 0, 32), substr($raw, 32)] as $v) {
        $v = ltrim($v, "\0");
        if ($v === '' || ord($v[0]) >= 0x80) $v = "\0" . $v;   // 양수로 읽히게
        $body .= "\x02" . chr(strlen($v)) . $v;
    }
    return "\x30" . chr(strlen($body)) . $body;
}

/**
 * VAPID JWT. aud 는 푸시 서비스의 scheme://host 다(경로를 넣으면 애플이 거절한다).
 * 같은 서비스로 여러 건 보낼 때 다시 서명하지 않도록 한 시간 넘게 남았으면 재사용한다.
 */
function push_vapid_jwt(string $aud): string
{
    static $cache = [];
    $now = time();
    $key = push_vapid_path() . '|' . $aud;
    if (isset($cache[$key]) && $cache[$key][1] - $now > 3600) return $cache[$key][0];

    $exp = $now + 12 * 3600;                      // 24시간을 넘으면 안 된다
    $h = push_b64url_encode('{"typ":"JWT","alg":"ES256"}');
    $p = push_b64url_encode(json_encode(['aud' => $aud, 'exp' => $exp, 'sub' => PUSH_VAPID_SUBJECT],
                                        JSON_UNESCAPED_SLASHES));
    $priv = openssl_pkey_get_private(push_vapid_keys()['private_pem']);
    if (!$priv || !openssl_sign("$h.$p", $der, $priv, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('VAPID 서명에 실패했습니다');
    }
    $jwt = "$h.$p." . push_b64url_encode(push_der_to_raw($der));
    $cache[$key] = [$jwt, $exp];
    return $jwt;
}

function push_audience(string $endpoint): string
{
    $u = parse_url($endpoint);
    if (empty($u['scheme']) || empty($u['host'])) throw new InvalidArgumentException('endpoint 주소가 잘못되었습니다');
    return $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
}

// =====================================================================
// RFC 8291 — 페이로드 암호화 (aes128gcm, 레코드 하나)
// =====================================================================

/**
 * @param string $uaPub  구독의 p256dh (65바이트 원본)
 * @param string $auth   구독의 auth   (16바이트 원본)
 * @return string        salt(16) | rs(4) | idlen(1) | as_pub(65) | 암호문 | 태그(16)
 */
function push_encrypt(string $plain, string $uaPub, string $auth): string
{
    if (strlen($auth) !== 16) throw new InvalidArgumentException('auth 는 16바이트여야 합니다');
    $uaKey = openssl_pkey_get_public(push_raw_pub_to_pem($uaPub));
    if (!$uaKey) throw new InvalidArgumentException('구독 공개키(p256dh)를 읽을 수 없습니다');

    $salt  = random_bytes(16);
    $as    = push_ec_new();                        // 메시지마다 새 키 — 재사용하면 안 된다
    $asPub = push_ec_public_raw($as);

    $shared = openssl_pkey_derive($uaKey, $as, 32);
    if ($shared === false) throw new RuntimeException('ECDH 계산에 실패했습니다');

    [$cek, $nonce] = push_derive_keys($shared, $auth, $uaPub, $asPub, $salt);

    $tag = '';
    $ct  = openssl_encrypt($plain . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ct === false) throw new RuntimeException('AES-GCM 암호화에 실패했습니다');

    return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
}

/** IKM → CEK(16) · NONCE(12). 암호화·복호화가 같은 식을 쓰도록 한 곳에 둔다. */
function push_derive_keys(string $shared, string $auth, string $uaPub, string $asPub, string $salt): array
{
    // hash_hkdf(알고리즘, 입력키, 길이, info, salt) — PHP 는 salt 가 맨 끝이다
    $ikm   = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPub . $asPub, $auth);
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    return [$cek, $nonce];
}

/**
 * 시험용 역방향. 가짜 구독자(브라우저 역할)의 개인키로 푼다.
 * 실패하면 null — 변조·키 불일치를 시험에서 확인한다.
 */
function push_decrypt(string $body, $uaPriv, string $auth): ?string
{
    if (strlen($body) < 21 + 16) return null;
    $salt  = substr($body, 0, 16);
    $idlen = ord($body[20]);
    $asPub = substr($body, 21, $idlen);
    $rest  = substr($body, 21 + $idlen);
    $ct    = substr($rest, 0, -16);
    $tag   = substr($rest, -16);

    $priv  = is_string($uaPriv) ? openssl_pkey_get_private($uaPriv) : $uaPriv;
    $uaPub = push_ec_public_raw($priv);
    $shared = openssl_pkey_derive(openssl_pkey_get_public(push_raw_pub_to_pem($asPub)), $priv, 32);
    if ($shared === false) return null;

    [$cek, $nonce] = push_derive_keys($shared, $auth, $uaPub, $asPub, $salt);
    $plain = openssl_decrypt($ct, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($plain === false) return null;
    $plain = rtrim($plain, "\0");
    if (substr($plain, -1) !== "\x02") return null;
    return substr($plain, 0, -1);
}

// =====================================================================
// 구독 · 종류별 설정 (DB)
// =====================================================================

/**
 * 브라우저가 준 PushSubscription.toJSON() 을 검사해 정리한다.
 * @return array{endpoint:string,p256dh:string,auth:string}
 */
function push_clean_subscription($sub): array
{
    if (!is_array($sub)) throw new InvalidArgumentException('구독 정보가 없습니다');
    $endpoint = trim((string)($sub['endpoint'] ?? ''));
    $p256dh   = trim((string)($sub['keys']['p256dh'] ?? ''));
    $auth     = trim((string)($sub['keys']['auth'] ?? ''));
    if (!preg_match('#^https://#i', $endpoint) || strlen($endpoint) > 2000) {
        throw new InvalidArgumentException('구독 주소(endpoint)가 올바르지 않습니다');
    }
    $pub = push_b64url_decode($p256dh);
    if (strlen($pub) !== 65 || $pub[0] !== "\x04") throw new InvalidArgumentException('구독 공개키(p256dh)가 올바르지 않습니다');
    if (strlen(push_b64url_decode($auth)) !== 16) throw new InvalidArgumentException('구독 인증값(auth)이 올바르지 않습니다');
    return ['endpoint' => $endpoint, 'p256dh' => push_b64url_encode($pub), 'auth' => $auth];
}

/**
 * 구독 저장. 기기(브라우저)마다 한 줄이고 endpoint 로 가린다.
 * 같은 기기에서 다른 계정으로 다시 받으면 주인을 바꾼다 — 한 기기가 두 사람
 * 알림을 다 받으면 안 된다.
 */
function push_subscribe(int $userId, $sub, string $ua = ''): void
{
    $s = push_clean_subscription($sub);
    portal_db()->prepare("
        INSERT INTO portal_push_subscription
            (user_id, endpoint_hash, endpoint, p256dh, auth, ua, created_at, fail_count)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), 0)
        ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id), endpoint = VALUES(endpoint), p256dh = VALUES(p256dh),
            auth = VALUES(auth), ua = VALUES(ua), fail_count = 0
    ")->execute([$userId, hash('sha256', $s['endpoint']), $s['endpoint'], $s['p256dh'], $s['auth'],
                 mb_substr($ua, 0, 255)]);
}

/** 본인 구독만 지운다. */
function push_unsubscribe(int $userId, string $endpoint): bool
{
    $st = portal_db()->prepare("DELETE FROM portal_push_subscription WHERE endpoint_hash = ? AND user_id = ?");
    $st->execute([hash('sha256', trim($endpoint)), $userId]);
    return $st->rowCount() > 0;
}

function push_subscriptions_of(int $userId): array
{
    $st = portal_db()->prepare("SELECT id, ua, created_at, last_ok_at, fail_count
                                  FROM portal_push_subscription WHERE user_id = ? ORDER BY id");
    $st->execute([$userId]);
    return $st->fetchAll();
}

/** @return array<string,bool> 모든 종류에 대해 켜짐 여부. 고른 적 없는 종류는 json 의 default. */
function push_prefs_of(int $userId): array
{
    $st = portal_db()->prepare("SELECT kind, enabled FROM portal_push_pref WHERE user_id = ?");
    $st->execute([$userId]);
    $set = [];
    foreach ($st->fetchAll() as $r) $set[$r['kind']] = (bool)$r['enabled'];
    $out = [];
    foreach (push_kinds() as $k => $row) $out[$k] = $set[$k] ?? $row['default'];
    return $out;
}

function push_set_pref(int $userId, string $kind, bool $enabled): void
{
    if (!push_kind($kind)) throw new InvalidArgumentException('알 수 없는 알림 종류입니다');
    portal_db()->prepare("
        INSERT INTO portal_push_pref (user_id, kind, enabled, updated_at) VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = NOW()
    ")->execute([$userId, $kind, $enabled ? 1 : 0]);
}

/**
 * 이 종류를 받을 구독 목록.
 *
 * @param array|string|null $to  이메일·portal_users.id 의 배열(또는 콤마 문자열). null 이면 켜 둔 전원
 * @param int $skipped           허용 목록 때문에 빠진 구독 수를 돌려준다
 */
function push_recipients(string $kind, $to = null, int &$skipped = 0): array
{
    $kindRow = push_kind($kind);
    if (!$kindRow) return [];

    $sql = "SELECT s.id AS sub_id, s.endpoint, s.p256dh, s.auth, s.fail_count, u.id AS user_id, u.email
              FROM portal_push_subscription s
              JOIN portal_users u ON u.id = s.user_id
         LEFT JOIN portal_push_pref p ON p.user_id = u.id AND p.kind = ?
             WHERE COALESCE(p.enabled, ?) = 1";
    $args = [$kind, $kindRow['default'] ? 1 : 0];

    if ($to !== null) {
        if (is_string($to)) $to = preg_split('/\s*,\s*/', trim($to), -1, PREG_SPLIT_NO_EMPTY);
        $emails = []; $ids = [];
        foreach ((array)$to as $t) {
            if (is_int($t) || ctype_digit((string)$t)) $ids[] = (int)$t;
            elseif (trim((string)$t) !== '') $emails[] = strtolower(trim((string)$t));
        }
        if (!$emails && !$ids) return [];
        $or = [];
        if ($emails) { $or[] = 'LOWER(u.email) IN (' . implode(',', array_fill(0, count($emails), '?')) . ')'; array_push($args, ...$emails); }
        if ($ids)    { $or[] = 'u.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';          array_push($args, ...$ids); }
        $sql .= ' AND (' . implode(' OR ', $or) . ')';
    }
    $sql .= ' ORDER BY s.id';

    $st = portal_db()->prepare($sql);
    $st->execute($args);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        if (!push_can_receive($r['email'])) { $skipped++; continue; }
        $out[] = $r;
    }
    return $out;
}

// =====================================================================
// 발송
// =====================================================================

/**
 * 알림을 누르면 열 주소를 정리한다.
 *  - 비면 포털 첫 화면('./')
 *  - http(s) 절대주소는 그대로
 *  - 그 밖의 스킴(javascript: 등)은 버린다
 *  - 나머지는 포털 루트 기준 상대경로로 보고 그대로 둔다 — sw.js 가 자기 scope(포털 루트)로 푼다
 */
function push_clean_url(?string $url): string
{
    $url = trim((string)$url);
    if ($url === '') return './';
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && !preg_match('#^https?://#i', $url)) return './';
    return mb_substr($url, 0, 500);
}

/** Topic 헤더는 URL-safe base64 문자 32자 이내여야 한다. */
function push_clean_topic(?string $tag): ?string
{
    $t = substr(trim(preg_replace('/[^A-Za-z0-9_-]+/u', '-', (string)$tag), '-'), 0, 32);
    return $t === '' ? null : $t;
}

/** 브라우저(sw.js)가 받는 JSON. 너무 길면 본문을 줄인다. */
function push_payload(array $o): string
{
    $data = [
        'title' => mb_substr((string)$o['title'], 0, 120),
        'body'  => (string)($o['body'] ?? ''),
        'url'   => push_clean_url($o['url'] ?? null),
        'tag'   => isset($o['tag']) && $o['tag'] !== '' ? mb_substr((string)$o['tag'], 0, 64) : null,
        'icon'  => isset($o['icon']) && $o['icon'] !== '' ? (string)$o['icon'] : null,
        'kind'  => (string)$o['kind'],
        'ts'    => time(),
    ];
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $over = strlen($json) - PUSH_MAX_PAYLOAD;
    if ($over > 0) {
        $data['body'] = mb_strcut($data['body'], 0, max(0, strlen($data['body']) - $over - 8)) . '…';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return $json;
}

function push_request_headers(string $endpoint, int $length, int $ttl, string $urgency, ?string $topic): array
{
    $vapid = push_vapid_keys();
    $h = [
        'Authorization: vapid t=' . push_vapid_jwt(push_audience($endpoint)) . ', k=' . push_b64url_encode($vapid['public_raw']),
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'Content-Length: ' . $length,
        'TTL: ' . $ttl,
        'Urgency: ' . $urgency,
    ];
    if ($topic !== null) $h[] = 'Topic: ' . $topic;
    return $h;
}

/** @return array{status:?int, error:?string} 예외를 던지지 않는다. */
function push_http_post(string $endpoint, string $body, array $headers): array
{
    $c = curl_init($endpoint);
    curl_setopt_array($c, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $resp = curl_exec($c);
    if ($resp === false) {
        return ['status' => null, 'error' => 'curl: ' . curl_error($c)];
    }
    $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    // 실패면 푸시 서비스가 준 이유를 조금 남긴다(FCM·애플은 본문에 사유를 적어 준다)
    return ['status' => $code, 'error' => $code >= 300 ? mb_substr(trim((string)$resp), 0, 200) : null];
}

/**
 * 알림 보내기.
 *
 * @param array $opts
 *   kind    (필수) core/push_kinds.json 의 key
 *   title   (필수)
 *   body    본문
 *   url     알림을 누르면 열 주소. 포털 루트 기준 상대경로(예: 'dti/index.php') 또는 http(s) 절대주소. 비면 포털 첫 화면
 *   tag     같은 tag 의 알림은 기기에서 하나로 겹쳐진다(예: 'dti-topic-12')
 *   icon    아이콘 주소(포털 루트 기준). 비면 sw.js 기본값
 *   to      이메일·portal_users.id 배열 또는 콤마 문자열. 생략하면 이 종류를 켜 둔 전원
 *   ttl     초. 기본 하루
 *   urgency very-low|low|normal|high
 *   _subs   (시험용) DB 대신 쓸 구독 행 배열 — 주면 DB 를 전혀 건드리지 않는다
 *   _post   (시험용) push_http_post 대신 부를 함수
 *
 * @return array{sent:int, failed:int, pruned:int, skipped:int, recipients:int, detail:array, error?:string}
 *   skipped = 허용 목록 때문에 빠진 구독 수, 또는 종류를 몰라 통째로 건너뛰었으면 1
 */
function push_send(array $opts): array
{
    $r = ['sent' => 0, 'failed' => 0, 'pruned' => 0, 'skipped' => 0, 'recipients' => 0, 'detail' => []];
    try {
        $kind = (string)($opts['kind'] ?? '');
        if (!push_kind($kind)) {
            error_log('[push] 알 수 없는 알림 종류: ' . $kind);
            $r['skipped'] = 1;
            $r['error'] = '알 수 없는 알림 종류입니다: ' . $kind;
            return $r;
        }
        $title = trim((string)($opts['title'] ?? ''));
        if ($title === '') {
            $r['skipped'] = 1;
            $r['error'] = '제목이 비어 있습니다';
            return $r;
        }
        $opts['title'] = $title;
        $payload = push_payload($opts);
        $url     = push_clean_url($opts['url'] ?? null);
        $ttl     = max(0, (int)($opts['ttl'] ?? PUSH_TTL_DEFAULT));
        $urgency = in_array($opts['urgency'] ?? '', PUSH_URGENCIES, true) ? $opts['urgency'] : 'normal';
        $topic   = push_clean_topic($opts['tag'] ?? null);
        $post    = $opts['_post'] ?? 'push_http_post';

        $useDb = !array_key_exists('_subs', $opts);
        $skipped = 0;
        $subs = $useDb ? push_recipients($kind, $opts['to'] ?? null, $skipped) : (array)$opts['_subs'];
        $r['skipped'] = $skipped;
        $r['recipients'] = count($subs);

        foreach ($subs as $s) {
            $d = ['user_id' => $s['user_id'] ?? null, 'email' => $s['email'] ?? null, 'sub_id' => $s['sub_id'] ?? null,
                  'status' => null, 'error' => null, 'result' => 'failed'];
            try {
                $body = push_encrypt($payload, push_b64url_decode($s['p256dh']), push_b64url_decode($s['auth']));
                $res  = $post($s['endpoint'], $body, push_request_headers($s['endpoint'], strlen($body), $ttl, $urgency, $topic));
                $d['status'] = isset($res['status']) ? (int)$res['status'] : null;
                $d['error']  = $res['error'] ?? null;
            } catch (Throwable $e) {
                $d['error'] = $e->getMessage();
            }

            $st = $d['status'];
            if ($st !== null && $st >= 200 && $st < 300) {
                $d['result'] = 'sent';
                $r['sent']++;
                if ($useDb) push_db_quiet("UPDATE portal_push_subscription SET last_ok_at = NOW(), fail_count = 0 WHERE id = ?", [$d['sub_id']]);
            } elseif ($st === 404 || $st === 410) {
                // 브라우저에서 구독이 사라졌다(권한 회수, 사이트 데이터 삭제 등). 다시 살아나지 않는다.
                $d['result'] = 'pruned';
                $r['pruned']++;
                if ($useDb) push_db_quiet("DELETE FROM portal_push_subscription WHERE id = ?", [$d['sub_id']]);
            } else {
                $r['failed']++;
                if ($st === 401 || $st === 403) {
                    // VAPID 키가 구독 당시와 다르다 — vapid.key 를 바꿨거나 다른 서버의 구독이다
                    error_log('[push] VAPID 거절(' . $st . ') sub=' . $d['sub_id'] . ' — vapid.key 가 바뀌지 않았는지 확인');
                }
                if ($useDb && ($st === null || $st === 429 || $st >= 500)) {
                    $fails = (int)($s['fail_count'] ?? 0) + 1;
                    if ($fails >= PUSH_FAIL_PRUNE) {
                        $d['result'] = 'pruned';
                        $r['pruned']++;
                        $r['failed']--;
                        push_db_quiet("DELETE FROM portal_push_subscription WHERE id = ?", [$d['sub_id']]);
                    } else {
                        push_db_quiet("UPDATE portal_push_subscription SET fail_count = ? WHERE id = ?", [$fails, $d['sub_id']]);
                    }
                }
            }
            if ($useDb) push_log($d['user_id'], $d['sub_id'], $kind, $title, $url, $st, $d['error']);
            $r['detail'][] = $d;
        }
    } catch (Throwable $e) {
        error_log('[push] push_send: ' . $e->getMessage());
        $r['error'] = $e->getMessage();
    }
    return $r;
}

/** 실패해도 던지지 않는 DB 쓰기. 발송 뒷정리용. */
function push_db_quiet(string $sql, array $args): void
{
    try {
        portal_db()->prepare($sql)->execute($args);
    } catch (Throwable $e) {
        error_log('[push] DB: ' . $e->getMessage());
    }
}

function push_log(?int $userId, ?int $subId, string $kind, string $title, string $url, ?int $status, ?string $error): void
{
    push_db_quiet(
        "INSERT INTO portal_push_log (user_id, sub_id, kind, title, url, status_code, error, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
        [$userId, $subId, $kind, mb_substr($title, 0, 200), mb_substr($url, 0, 500), $status,
         $error !== null ? mb_substr($error, 0, 255) : null]
    );
}
