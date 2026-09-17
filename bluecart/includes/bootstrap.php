<?php
/**
 * 모든 진입점(index.php, api/*.php, cron/*.php)이 최초로 include 하는 파일.
 */

declare(strict_types=1);

define('BC_ROOT', dirname(__DIR__));

// ---------------------------------------------------------------------
// 설정 로드
// ---------------------------------------------------------------------
// 기본은 config/config.php. 테스트나 배치에서는 bootstrap 을 부르기 전에
// BC_CONFIG_FILE 을 정의하거나 BLUECART_CONFIG 환경변수로 다른 파일을 지정할 수
// 있습니다. 운영 설정을 실수로 덮어쓰지 않으려는 장치입니다.
$configPath = defined('BC_CONFIG_FILE')
    ? BC_CONFIG_FILE
    : (getenv('BLUECART_CONFIG') ?: BC_ROOT . '/config/config.php');

if (!is_file($configPath)) {
    http_response_code(500);
    exit('설정 파일이 없습니다: ' . $configPath
        . "\nconfig/config.sample.php 를 config/config.php 로 복사해 작성하세요.");
}

/** @var array $BC_CONFIG */
$BC_CONFIG = require $configPath;

// 개발 서버는 포트가 매번 달라질 수 있습니다. dev/router.php 가 실제 주소를
// 알려 주면 그 값을 씁니다. 알림 메일의 링크가 엉뚱한 포트를 가리키지 않게
// 하려는 처리입니다. 운영에서는 이 상수가 정의되지 않습니다.
if (defined('BC_BASE_URL') && BC_BASE_URL !== '') {
    $BC_CONFIG['app']['base_url'] = BC_BASE_URL;
}

date_default_timezone_set($BC_CONFIG['app']['timezone'] ?? 'Asia/Seoul');

if (!empty($BC_CONFIG['app']['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}

/**
 * 점 표기법으로 설정값 조회. bc_config('db.host')
 */
function bc_config(string $path, mixed $default = null): mixed
{
    global $BC_CONFIG;
    $node = $BC_CONFIG;
    foreach (explode('.', $path) as $key) {
        if (!is_array($node) || !array_key_exists($key, $node)) {
            return $default;
        }
        $node = $node[$key];
    }
    return $node;
}

// ---------------------------------------------------------------------
// 세션 : iworks 가 이미 세션을 열었다면 그대로 사용한다.
// ---------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once BC_ROOT . '/includes/helpers.php';
require_once BC_ROOT . '/includes/db.php';
require_once BC_ROOT . '/includes/auth.php';
require_once BC_ROOT . '/includes/directory.php';
require_once BC_ROOT . '/includes/workflow.php';
require_once BC_ROOT . '/includes/presenter.php';
require_once BC_ROOT . '/includes/model/Category.php';
require_once BC_ROOT . '/includes/model/RoleAssign.php';
require_once BC_ROOT . '/includes/model/PurchaseRequest.php';
require_once BC_ROOT . '/includes/model/Setting.php';
require_once BC_ROOT . '/includes/model/Attachment.php';
require_once BC_ROOT . '/includes/notify/Notifier.php';
