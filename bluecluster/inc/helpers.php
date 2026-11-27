<?php
/**
 * 출력·로그인 확인.
 */

if (!function_exists('h')) {
    function h($s)
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * 정적 파일 판 번호.
 * @param string $rel 모듈 안 상대 경로
 * @return string
 */
function bcl_asset_v($rel)
{
    return (string)(@filemtime(BCL_ROOT . '/' . ltrim($rel, '/')) ?: 0);
}

/**
 * 세션 CSRF 토큰.
 * @return string
 */
function bcl_csrf_token()
{
    if (empty($_SESSION['bcl_csrf'])) $_SESSION['bcl_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['bcl_csrf'];
}

/**
 * 보낸 CSRF 토큰이 세션 것과 같은지.
 * @param string $sent
 * @return bool
 */
function bcl_csrf_valid($sent)
{
    return $sent !== '' && hash_equals($_SESSION['bcl_csrf'] ?? '', $sent);
}

/**
 * 화면용 로그인 확인.
 * @return array
 */
function bcl_require_login()
{
    $u = current_portal_user();
    if (!$u) {
        header('Location: ../index.php?need_login=' . BCL_MODULE_KEY);
        exit;
    }
    return $u;
}
