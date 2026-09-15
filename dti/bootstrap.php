<?php
/**
 * dti 조립. 여기 두 함수만 전역이다 — 조립 지점이라서다.
 * 나머지는 전부 클래스이고 의존성은 생성자로 받는다.
 */

require_once __DIR__ . '/autoload.php';

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
