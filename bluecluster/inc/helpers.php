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
