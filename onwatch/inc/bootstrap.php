<?php
/**
 * OnWatch(수시 감시) — 모든 진입점(화면·api·bin)이 처음 include 하는 파일.
 *
 * 포털 안의 한 폴더로만 동작한다(단독 실행 모드 없음). 포털 core/auth.php 가
 * 세션 이름과 저장 경로를 정한 뒤 세션을 여므로 반드시 그것을 먼저 부른다.
 * bin/ 의 CLI 는 세션이 필요 없어 OW_CLI 를 정의하고 auth.php 를 건너뛴다.
 *
 * PHP 7.4 에서도 돈다 — 로컬 PATH 의 php 가 7.4 이고 core/ 도 7.4 문법이다.
 */

define('OW_ROOT', dirname(__DIR__));
define('OW_PORTAL_ROOT', dirname(OW_ROOT));

date_default_timezone_set('Asia/Seoul');

if (!defined('OW_CLI')) {
    require_once OW_PORTAL_ROOT . '/core/auth.php';      // 세션 시작 + current_portal_user()
    require_once OW_PORTAL_ROOT . '/core/worksystems.php';
} else {
    require_once OW_PORTAL_ROOT . '/core/db.php';
}
require_once OW_PORTAL_ROOT . '/core/push.php';

require_once OW_ROOT . '/inc/settings.php';
require_once OW_ROOT . '/inc/helpers.php';
require_once OW_ROOT . '/inc/db.php';
require_once OW_ROOT . '/inc/schedule.php';
require_once OW_ROOT . '/inc/repo.php';
require_once OW_ROOT . '/inc/presenter.php';
