<?php
/**
 * GET  api/notify_settings.php   알림 매트릭스 + 이벤트/역할 메타 + 슬랙 연결 상태
 * POST api/notify_settings.php   matrix[event][role][channel] = 1 저장 (관리자)
 *
 * 슬랙 봇 토큰과 Webhook URL 은 암호화해서 bc_setting 에 넣는다. 원문은 어떤
 * 응답에도 실어 보내지 않고, 설정 여부와 가린 표기(xoxb-••••1a2b)만 내려준다.
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
        'slack_secrets' => [
            'bot_token'   => bc_secret_state('slack_bot_token',   SlackChannel::botToken()),
            'webhook_url' => bc_secret_state('slack_webhook_url', SlackChannel::webhookUrl()),
        ],
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

// 비밀값은 다 검사한 뒤에 저장한다. 하나가 형식에 걸려 중간에 끊기면
// 앞의 것만 바뀐 상태로 남는다.
$secrets = [
    'slack_bot_token' => [
        'label'   => '슬랙 봇 토큰',
        'pattern' => '/^xox[abeprs]-[A-Za-z0-9-]{10,}$/',
        'hint'    => 'xoxb- 로 시작하는 Bot User OAuth Token 이어야 합니다.',
    ],
    'slack_webhook_url' => [
        'label'   => '슬랙 Webhook URL',
        'pattern' => '#^https://hooks\.slack\.com/services/[A-Za-z0-9/_+-]{10,}$#',
        'hint'    => 'https://hooks.slack.com/services/... 형태여야 합니다.',
    ],
];

$pending = [];
foreach ($secrets as $key => $def) {
    if (bc_param($key . '_clear')) {
        $pending[$key] = null;                 // 지우기
        continue;
    }
    $value = bc_param_str($key);
    if ($value === '') {
        continue;                              // 빈 칸은 "그대로 두기" 다
    }
    if (!preg_match($def['pattern'], $value)) {
        bc_json_error($def['label'] . ' 형식이 올바르지 않습니다. ' . $def['hint']);
    }
    $pending[$key] = $value;
}

try {
    foreach ($pending as $key => $value) {
        Setting::setSecret($key, $value, $user['id']);
    }
} catch (Throwable $e) {
    error_log('[BlueCart] secret save failed: ' . $e->getMessage());
    bc_json_error('비밀값을 저장하지 못했습니다: ' . $e->getMessage(), 500);
}

bc_json_ok(['message' => '알림 설정을 저장했습니다.']);

/**
 * 화면에 내려 줄 상태. 원문은 넣지 않는다.
 *   source: db     관리자 화면에서 저장한 값
 *           config config.php / 환경변수에서 온 값 (화면에서 지울 수 없다)
 */
function bc_secret_state(string $key, string $effective): array
{
    $fromDb = Setting::secret($key) !== '';
    return [
        'set'    => $effective !== '',
        'source' => $fromDb ? 'db' : ($effective !== '' ? 'config' : ''),
        'masked' => bc_mask_secret($effective),
    ];
}
