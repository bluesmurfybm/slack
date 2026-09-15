<?php
/** dti 조립 — require 목록과 시간대. */

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/guard.php';

foreach (['members', 'emotions', 'topics', 'presentations', 'related', 'score',
          'fields', 'storage', 'slots', 'notify'] as $__lib) {
    require_once __DIR__ . '/lib/' . $__lib . '.php';
}
foreach (['identity', 'topics', 'materials', 'fields', 'scores'] as $__route) {
    require_once __DIR__ . '/routes/' . $__route . '.php';
}

date_default_timezone_set('Asia/Seoul');
