<?php
/**
 * 로그인한 사람에게 API 문서 명세(openapi.json)를 내준다.
 */
require_once __DIR__ . '/../core/auth.php';
header('Cache-Control: no-store');

require_portal_login();

header('Content-Type: application/json; charset=utf-8');
readfile(__DIR__ . '/openapi.json');
