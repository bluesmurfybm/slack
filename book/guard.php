<?php
/**
 * 포털 세션 → 신원. 세션에 기대는 유일한 자리다.
 * FastAPI 시절에는 포털이 심어 준 SSO 쿠키(blueiwork_id)를 직접 검증했지만,
 * 이제 같은 PHP 앱 안에 있으므로 세션을 그대로 쓴다(dti·learn 과 같은 방식).
 */

function book_identity(): ?array {
    require_once __DIR__ . '/../core/auth.php';

    $user = current_portal_user();
    if (!$user) return null;

    return [
        'email' => $user['email'],
        'name' => $user['name'] ?? '',
        'color' => user_color($user),
    ];
}

function book_require_admin(array $ctx): void {
    if (!book_is_admin($ctx['config'], $ctx['identity']['email'])) {
        throw new BookError('관리자만 할 수 있습니다', 403);
    }
}

/** 화면 페이지의 입구. 로그인이 없으면 포털로 돌려보내고 끝낸다. */
function book_page_begin(): array {
    $identity = book_identity();
    if (!$identity) {
        header('Location: ../index.php?need_login=book');
        exit;
    }
    $config = book_config_from_portal();
    return [
        'identity' => $identity,
        'is_admin' => book_is_admin($config, $identity['email']),
        'portal' => rtrim($config['portal_url'], '/'),
        'config' => $config,
    ];
}

/** book/ 기준 경로에 수정시각 판 번호를 붙인다 — 고칠 때마다 손으로 올리는 값은 언젠가 어긋난다 */
function book_asset(string $rel): string {
    $mtime = @filemtime(__DIR__ . '/' . $rel);
    return $rel . '?v=' . ($mtime ?: 0);
}

function book_h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
