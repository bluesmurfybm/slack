<?php
/** book 조립 — require 목록과 시간대. */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/guard.php';

foreach (['http', 'requests', 'lookup', 'notify'] as $__lib) {
    require_once __DIR__ . '/lib/' . $__lib . '.php';
}
foreach (['identity', 'requests', 'books'] as $__route) {
    require_once __DIR__ . '/routes/' . $__route . '.php';
}

date_default_timezone_set('Asia/Seoul');
