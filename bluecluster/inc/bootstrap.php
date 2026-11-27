<?php
/**
 * ClusterAgent — 모든 진입점이 처음 include 하는 파일.
 *
 * 포털 core/auth.php 가 세션 이름과 저장 경로를 정한 뒤 세션을 여므로 반드시 그것을 먼저 부른다.
 */

define('BCL_ROOT', dirname(__DIR__));
define('BCL_PORTAL_ROOT', dirname(BCL_ROOT));

date_default_timezone_set('Asia/Seoul');

require_once BCL_PORTAL_ROOT . '/core/auth.php';
require_once BCL_PORTAL_ROOT . '/core/worksystems.php';

require_once BCL_ROOT . '/inc/settings.php';
require_once BCL_ROOT . '/inc/helpers.php';
