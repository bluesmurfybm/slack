<?php
/** dti 조립 — require 목록과 시간대, 설정·DB 를 만드는 함수. */

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/lib/emotions.php';
require_once __DIR__ . '/lib/fields.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/slots.php';
require_once __DIR__ . '/lib/notify.php';

date_default_timezone_set('Asia/Seoul');

function dti_config(): Dti\Config
{
    static $config = null;
    return $config ??= Dti\Config::fromPortal();
}

function dti_database(): Dti\Database
{
    static $database = null;
    if ($database === null) {
        $database = new Dti\Database(dti_config());
        $database->migrate();
    }
    return $database;
}
