<?php

/**
 * @return array|null
 */
function schooltodo_session_identity() {
    require_once __DIR__ . '/../core/auth.php';

    $user = current_portal_user();
    if (!$user) return null;

    return [
        'email' => $user['email'],
        'name' => $user['name'] ?? '',
        'color' => user_color($user),
    ];
}

/**
 * @return array
 */
function schooltodo_page_begin() {
    $identity = schooltodo_session_identity();
    if (!$identity) {
        header('Location: ../index.php?need_login=schooltodo');
        exit;
    }
    $config = schooltodo_config_from_portal();

    return [
        'identity' => $identity,
        'portal' => rtrim($config['portal_url'], '/'),
    ];
}

/**
 * @param string $rel
 * @return string
 */
function schooltodo_asset($rel) {
    $mtime = @filemtime(__DIR__ . '/' . $rel);
    return $rel . '?v=' . ($mtime ?: 0);
}

/**
 * @param string|null $s
 * @return string
 */
function schooltodo_h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Authorization: Bearer 토큰이 설정값과 맞는가. 토큰이 설정되지 않았으면 언제나 false.
 * 평문 http 로 토큰이 오가지 않게 HTTPS(또는 localhost) 요청만 인정한다.
 * @param string $token
 * @return bool
 */
function schooltodo_bearer_ok($token) {
    if ($token === '') return false;

    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = strtolower(preg_replace('~:\d+$~', '', $_SERVER['HTTP_HOST'] ?? ''));
    $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost');
    if (!$https && !$local) return false;

    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('~^\s*Bearer\s+(\S+)\s*$~i', $h, $m)) return false;
    return hash_equals($token, $m[1]);
}
