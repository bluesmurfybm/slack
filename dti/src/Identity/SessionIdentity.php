<?php

namespace Dti\Identity;

/**
 * 포털 세션 → Identity. 세션에 기대는 유일한 클래스다.
 * FastAPI 시절에는 포털이 심어 준 SSO 쿠키(blueiwork_id)를 직접 검증했지만,
 * 이제 같은 PHP 앱 안에 있으므로 세션을 그대로 쓴다(learn·access 와 같은 방식).
 */
final class SessionIdentity
{
    public static function current(): ?Identity
    {
        require_once __DIR__ . '/../../../auth.php';

        $user = current_portal_user();
        if (!$user) return null;

        return new Identity($user['email'], $user['name'] ?? '', user_color($user));
    }
}
