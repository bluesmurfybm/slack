<?php
/**
 * 포털 비밀 값 암호화. 세션을 열지 않으므로 CLI·테스트에서도 부를 수 있다.
 */

/**
 * @return string
 */
function portal_crypto_key() {
    $cfg = require dirname(__DIR__) . '/config.php';
    return base64_decode($cfg['key']);
}

/**
 * 비밀 값을 암호화한다.
 * @param string|null $plain
 * @return string|null
 */
function enc_token($plain) {
    if ($plain === null || $plain === '') return null;
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', portal_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $cipher);
}

/**
 * 암호화한 비밀 값을 푼다.
 * @param string|null $enc
 * @return string
 */
function dec_token($enc) {
    if (!$enc) return '';
    $raw = base64_decode($enc);
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', portal_crypto_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}
