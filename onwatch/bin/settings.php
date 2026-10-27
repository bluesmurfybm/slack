<?php
/**
 * 배치(worker/)가 화면 쪽 설정을 읽는 길 — CLI 전용. JSON 한 줄.
 *
 *     php onwatch/bin/settings.php   →  {"slack_enabled":true}
 *
 * 화면과 배치가 같은 스위치를 따로 들고 있으면 한쪽만 바꾸는 일이 생긴다.
 * 원본은 inc/settings.php(+ config.local.php) 하나로 두고 배치는 여기서 읽는다.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('OW_ROOT', dirname(__DIR__));
require_once OW_ROOT . '/inc/settings.php';

echo json_encode(['slack_enabled' => (bool)ow_config('slack_enabled')]), "\n";
