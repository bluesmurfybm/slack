<?php
/** 로그인 사용자와 화면이 쓰는 상수. */

function learn_route_whoami($identity) {
    // 포털과 같은 폴더 트리에 있으므로 상대경로로 충분하다
    $base = [
        'email'         => $identity['email'] ?? null,
        'name'          => $identity['name'] ?? null,
        'color'         => $identity['color'] ?? null,
        'is_admin'      => $identity ? learn_is_admin($identity['email']) : false,
        'is_owner'      => $identity ? learn_is_owner($identity['email']) : false,
        'levels'        => LEVELS,
        'account_types' => ACCOUNT_TYPES,
        'progresses'    => PROGRESSES,
        'dev_login'     => false,
        'portal_url'    => '..',
        'slack_url'     => '../slack/lists.php',
    ];
    jsend($base);
}

function learn_route_members() {
    jsend(learn_members());
}
