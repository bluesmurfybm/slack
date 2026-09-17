<?php
/**
 * GET  api/notify_settings.php   알림 매트릭스 + 이벤트/역할 메타
 * POST api/notify_settings.php   matrix[event][role][channel] = 1 저장 (관리자)
 */
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    bc_require_login_api();
    if (!bc_can_see_admin()) {
        bc_json_error('관리자 권한이 필요합니다.', 403);
    }
    bc_json_ok([
        'matrix'   => Setting::notifyMatrix(),
        'events'   => BC_EVENT,
        'targets'  => BC_EVENT_TARGETS,
        'roles'    => BC_ROLE_LABEL,
        'channels' => ['EMAIL' => '이메일', 'SLACK_CHANNEL' => '슬랙 채널', 'SLACK_DM' => '슬랙 개인 DM'],
        'slack_channel' => Setting::get('slack_default_channel', ''),
    ]);
}

$user = bc_require_admin_api();
bc_verify_csrf();

$matrix = bc_param('matrix', []);
if (!is_array($matrix)) {
    bc_json_error('설정 형식이 올바르지 않습니다.');
}
Setting::saveNotifyMatrix($matrix, $user['id']);

$slack = bc_param_str('slack_channel');
if ($slack !== '') {
    if (!preg_match('/^[#@]?[A-Za-z0-9가-힣._-]{1,80}$/u', $slack)) {
        bc_json_error('슬랙 채널 이름 형식이 올바르지 않습니다.');
    }
    Setting::set('slack_default_channel', $slack, $user['id']);
}

bc_json_ok(['message' => '알림 설정을 저장했습니다.']);
