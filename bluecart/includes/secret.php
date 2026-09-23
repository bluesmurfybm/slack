<?php
declare(strict_types=1);

/**
 * 관리자 화면에서 입력받는 비밀값(슬랙 봇 토큰, Webhook URL)을 DB 에 넣기 전에
 * 암호화한다.
 *
 * 방식과 저장 형식은 포털이 개인 슬랙 토큰에 쓰는 것과 같다(core/auth.php 의
 * enc_token/dec_token). AES-256-GCM 이고 저장 값은
 *
 *   base64( iv(12) + tag(16) + cipher )
 *
 * 키는 아래 순서로 찾는다.
 *
 *   1. config.php 의 app.secret_key   — base64 32바이트. 직접 정하고 싶을 때.
 *   2. 포털 config.php 의 key         — 포털 모듈로 동작할 때. 포털과 같은 키.
 *   3. config/secret.local.php        — 없으면 최초 1회 자동 생성(포털과 같은 방식).
 *
 * 키가 바뀌면 이미 저장된 값은 풀리지 않는다. 그때는 관리자 화면에서 다시
 * 입력하면 된다. 복호화 실패는 예외가 아니라 빈 값으로 다뤄, 알림 설정 하나
 * 때문에 화면 전체가 죽지 않게 한다.
 */

/** 암호화 키 원문 32바이트. 찾지 못하면 RuntimeException. */
function bc_secret_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }

    $b64 = trim((string)bc_config('app.secret_key', ''));

    if ($b64 === '' && BC_PORTAL_ROOT !== '') {
        $portal = require BC_PORTAL_ROOT . '/config.php';
        if (is_array($portal) && !empty($portal['key'])) {
            $b64 = (string)$portal['key'];
        }
    }

    if ($b64 === '') {
        $file = BC_ROOT . '/config/secret.local.php';
        if (!is_file($file)) {
            $php = "<?php\n"
                 . "// BlueCart 비밀값 암호화 키. 최초 실행 때 자동으로 만들어졌습니다.\n"
                 . "// 커밋하지 말고, 서버를 옮길 때는 이 파일도 같이 옮기세요.\n"
                 . "// 이 키가 바뀌면 저장해 둔 슬랙 봇 토큰을 다시 입력해야 합니다.\n"
                 . "return ['key' => '" . base64_encode(random_bytes(32)) . "'];\n";
            if (@file_put_contents($file, $php, LOCK_EX) === false) {
                throw new RuntimeException('암호화 키 파일을 만들 수 없습니다: ' . $file);
            }
            @chmod($file, 0600);
        }
        $local = require $file;
        $b64   = (string)($local['key'] ?? '');
    }

    $raw = base64_decode($b64, true);
    if ($raw === false || strlen($raw) !== 32) {
        throw new RuntimeException('암호화 키가 올바르지 않습니다. base64 로 적은 32바이트여야 합니다.');
    }
    return $key = $raw;
}

/** 저장용 암호문. 빈 문자열은 그대로 빈 문자열. */
function bc_encrypt(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $iv  = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', bc_secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('암호화에 실패했습니다.');
    }
    return base64_encode($iv . $tag . $cipher);
}

/** 복호화. 값이 없거나 키가 맞지 않으면 빈 문자열. */
function bc_decrypt(string $enc): string
{
    if ($enc === '') {
        return '';
    }
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) <= 28) {
        return '';
    }
    $plain = openssl_decrypt(
        substr($raw, 28), 'aes-256-gcm', bc_secret_key(),
        OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16)
    );
    return $plain === false ? '' : $plain;
}

/**
 * 화면에 보여 줄 가림 표기. 원문은 절대 내보내지 않는다.
 * 슬랙 토큰은 종류를 구분할 수 있게 접두사(xoxb- 등)만 남긴다.
 */
function bc_mask_secret(string $value): string
{
    if ($value === '') {
        return '';
    }
    $tail   = strlen($value) > 4 ? substr($value, -4) : '';
    $prefix = preg_match('/^(xox[a-z])-/', $value, $m) ? $m[1] . '-' : '';
    return $prefix . '••••' . $tail;
}
