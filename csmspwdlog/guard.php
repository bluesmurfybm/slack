<?php
date_default_timezone_set('Asia/Seoul');
require_once __DIR__ . '/../core/auth.php';

function csmspwdlog_require_login() {
    $u = current_portal_user();
    if (!$u) {
        header('Location: ../index.php?need_login=csmspwdlog');
        exit;
    }
    return $u;
}
