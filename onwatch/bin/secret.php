<?php
/**
 * 감시 전용 슬랙 웹훅 복호화 — CLI 전용. 배치(worker/notify.py)가 부른다.
 *
 *     echo '<slack_webhook_enc>' | php onwatch/bin/secret.php
 *
 * 화면이 core/auth.php::enc_token() 으로 암호화해 저장한다. 같은 키(포털 config.php 의 key)로
 * 푸는 일을 Python 에 다시 짜지 않으려고 PHP 에 맡긴다. 결과가 hooks.slack.com 주소가 아니면
 * 아무것도 내지 않는다 — 배치가 엉뚱한 곳으로 요청을 보내지 않게.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('OW_CLI', true);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
// dec_token() 은 core/auth.php 에 있고, 그 파일은 세션이 없으면 포털 .sessions 에 세션을 연다.
// 배치가 부를 때마다 세션 파일이 쌓이지 않게 임시 폴더에 세션을 먼저 열어 두고 저장 없이 닫는다.
session_save_path(sys_get_temp_dir());
session_id('onwatchcli');
@session_start();
require_once OW_PORTAL_ROOT . '/core/auth.php';

$plain = dec_token(trim((string)stream_get_contents(STDIN)));
session_abort();
echo ow_valid_slack_webhook($plain) ? $plain : '';
