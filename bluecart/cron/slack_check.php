<?php
/**
 * 슬랙 알림 점검. cron 이 아니라 손으로 돌리는 도구다.
 *
 *   php cron/slack_check.php
 *   php cron/slack_check.php --dm=kimhy@bluesoft.co.kr   실제로 한 통 보내 본다
 *
 * cron/ 에 둔 이유는 운영 서버에 남는 폴더이기 때문이다. dev/ 는 배포할 때
 * 지우는데(README 15.1), 슬랙이 안 되는 순간은 대개 배포 뒤에 온다.
 *
 * 개인 DM 이 안 될 때 원인은 거의 셋 중 하나다.
 *   1. notify.enabled 가 꺼져 있다 — 보내지 않고 로그만 SENT 로 남는다
 *   2. 스코프를 추가하고 재설치를 안 했다 — 앱 설정에는 있는데 토큰에는 없다
 *   3. 포털 계정 이메일과 슬랙 프로필 이메일이 다르다 — 그 사람만 조용히 빠진다
 *
 * 셋 다 화면에서는 보이지 않는다. 그래서 여기서 하나씩 찍어 본다.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI 전용');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BC_ROOT . '/includes/notify/SlackChannel.php';

/** DM 을 만들려면 토큰에 이 스코프들이 붙어 있어야 한다. */
const NEED_SCOPES = ['chat:write', 'users:read.email'];

$PASS = 0;
$WARN = 0;
$FAIL = 0;

function line(string $s = ''): void { echo $s . PHP_EOL; }
function head(string $s): void { line(''); line('=== ' . $s . ' ==='); }
function ok_(string $what, string $detail = ''): void   { global $PASS; $PASS++; line('  [정상] ' . $what . ($detail ? '  ' . $detail : '')); }
function warn_(string $what, string $detail = ''): void { global $WARN; $WARN++; line('  [주의] ' . $what . ($detail ? '  ' . $detail : '')); }
function fail_(string $what, string $detail = ''): void { global $FAIL; $FAIL++; line('  [오류] ' . $what . ($detail ? '  ' . $detail : '')); }

/**
 * 슬랙 API 를 부르고 응답 헤더까지 받는다.
 *
 * 토큰에 실제로 붙어 있는 스코프는 본문이 아니라 x-oauth-scopes 헤더에만
 * 들어 있다. 앱 설정 화면의 목록은 "요청한 스코프"라 재설치 전에는 토큰과
 * 다를 수 있는데, 그 차이를 볼 수 있는 자리가 여기뿐이다.
 *
 * @return array{json:array, scopes:string[]}
 */
function slack_call(string $method, array $params = []): array
{
    $ch = curl_init('https://slack.com/api/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . SlackChannel::botToken(),
            'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
        ],
    ]);
    $raw = curl_exec($ch);
    $len = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('호출 실패: ' . $err);
    }

    $scopes = [];
    foreach (explode("\n", substr((string)$raw, 0, $len)) as $h) {
        if (stripos($h, 'x-oauth-scopes:') === 0) {
            $scopes = array_map('trim', explode(',', trim(substr($h, 15))));
        }
    }

    $json = json_decode(substr((string)$raw, $len), true);
    return ['json' => is_array($json) ? $json : [], 'scopes' => $scopes];
}

// ---------------------------------------------------------------------
line();
line('BlueCart 슬랙 알림 점검');
line('  설정 파일: ' . (defined('BC_CONFIG_FILE')
    ? BC_CONFIG_FILE
    : (getenv('BLUECART_CONFIG') ?: BC_ROOT . '/config/config.php')));
line('  DB: ' . bc_config('db.database') . ' @ ' . bc_config('db.host'));
line(str_repeat('-', 62));

// ---------------------------------------------------------------------
head('1. 발송 스위치');

if (bc_config('notify.enabled', true)) {
    ok_('notify.enabled = true');
} else {
    fail_('notify.enabled = false — 실제로 보내지 않습니다');
    line('         이 상태에서는 발송을 건너뛰고 bc_notify_log 에 SENT 로만 남습니다.');
    line('         "로그는 SENT 인데 DM 이 안 온다" 의 대부분이 이 경우입니다.');
    line('         config/config.php 의 notify.enabled 를 true 로 바꾸세요.');
}

// ---------------------------------------------------------------------
head('2. 봇 토큰');

$token = SlackChannel::botToken();
if ($token === '') {
    fail_('봇 토큰이 없습니다');
    line('         관리자 탭 > 알림 설정에서 넣거나, config 의 notify.slack.bot_token 에 적으세요.');
    line('         화면에 값이 보이는데 여기서 비어 있다면 암호화 키가 바뀐 것입니다.');
    line('         (includes/secret.php 참고) 그때는 화면에서 다시 입력하면 됩니다.');
    line('');
    line(str_repeat('-', 62));
    printf('정상 %d · 주의 %d · 오류 %d%s', $PASS, $WARN, $FAIL, PHP_EOL . PHP_EOL);
    exit(1);
}

$fromDb = Setting::secret('slack_bot_token') !== '';
ok_('봇 토큰 있음', bc_mask_secret($token) . ' (' . ($fromDb ? '관리자 화면 저장값' : '설정 파일') . ')');

if (!str_starts_with($token, 'xoxb-')) {
    warn_('xoxb- 로 시작하지 않습니다', '개인 DM 은 봇 토큰(xoxb-)이 필요합니다');
}

// ---------------------------------------------------------------------
head('3. 토큰에 실제로 붙어 있는 스코프');

$scopes = [];
$apiOk  = false;      // 여기가 막히면 아래 이메일 조회도 다 실패한다. 구분해야 한다.
try {
    $res    = slack_call('auth.test');
    $scopes = $res['scopes'];

    if (!empty($res['json']['ok'])) {
        $apiOk = true;
        ok_('auth.test 통과',
            '워크스페이스: ' . ($res['json']['team'] ?? '?') . ' / 봇: ' . ($res['json']['user'] ?? '?'));
    } else {
        fail_('auth.test 실패', (string)($res['json']['error'] ?? 'unknown'));
        line('         invalid_auth / token_revoked 라면 앱을 재설치하고 새 토큰을 넣으세요.');
    }
} catch (Throwable $e) {
    fail_('슬랙에 닿지 못했습니다', $e->getMessage());
    if (stripos($e->getMessage(), 'certificate') !== false) {
        line('         인증서 검증 실패입니다. php.ini 의 curl.cainfo 에 cacert.pem 경로를');
        line('         지정하세요. 윈도우 개발 환경에서 흔하고, 운영 리눅스에서는 드뭅니다.');
    } else {
        line('         서버에서 slack.com 으로 나가는 길(방화벽, 프록시)을 확인하세요.');
    }
}

if ($scopes) {
    line('  토큰 스코프: ' . implode(', ', $scopes));
    foreach (NEED_SCOPES as $need) {
        if (in_array($need, $scopes, true)) {
            ok_($need);
        } else {
            fail_($need . ' 없음');
            line('         앱 설정에 추가만 하고 Reinstall to Workspace 를 안 하면 이렇게 됩니다.');
            line('         재설치한 뒤 새 봇 토큰을 알림 설정에 다시 넣으세요.');
        }
    }
}

// ---------------------------------------------------------------------
head('4. 알림 설정에서 개인 DM 이 켜진 단계');

$dmRows = bc_fetch_all(
    'SELECT event_code, target_role FROM bc_notify_setting
      WHERE channel = "SLACK_DM" AND is_enabled = 1 ORDER BY event_code'
);
if ($dmRows) {
    ok_(count($dmRows) . '개 단계에서 켜져 있음');
    foreach ($dmRows as $r) {
        line('         · ' . (BC_EVENT[$r['event_code']] ?? $r['event_code'])
             . ' → ' . (BC_ROLE_LABEL[$r['target_role']] ?? $r['target_role']));
    }
} else {
    warn_('개인 DM 이 켜진 단계가 없습니다', '알림 설정 화면에서 체크하세요');
}

// 어느 채널도 안 켜진 단계는 알림이 통째로 나가지 않는다. DM 을 고치는 중에
// 이런 단계가 있으면 그동안은 아무도 소식을 못 받는다.
$silent = bc_fetch_all(
    'SELECT s.event_code, s.target_role
       FROM bc_notify_setting s
      GROUP BY s.event_code, s.target_role
     HAVING SUM(s.is_enabled) = 0'
);
foreach ($silent as $r) {
    warn_('알림이 아예 없는 단계',
          (BC_EVENT[$r['event_code']] ?? $r['event_code'])
          . ' → ' . (BC_ROLE_LABEL[$r['target_role']] ?? $r['target_role']));
}

// ---------------------------------------------------------------------
head('5. 받을 사람을 슬랙에서 찾을 수 있는가');

// 포털 설정에서는 col_slack_id 가 null 이라 이메일 조회가 유일한 경로다.
$hasSlackCol = (string)bc_config('iworks.member.col_slack_id', '') !== '';
line('  슬랙 ID 컬럼: ' . ($hasSlackCol
    ? bc_config('iworks.member.col_slack_id') . ' (있으면 이메일 조회를 건너뜁니다)'
    : '없음 — 이메일로만 찾습니다'));

$targets = [];
foreach (RoleAssign::TYPES as $type) {
    foreach (RoleAssign::byType($type) as $m) {
        $targets[$m['user_id']][] = BC_ROLE_LABEL[$type] ?? ($type === 'ADMIN' ? '관리자' : $type);
    }
}
// 요청자도 DM 을 받는다. 최근에 실제로 올린 사람들을 함께 본다.
foreach (bc_fetch_all(
    'SELECT DISTINCT requester_id FROM bc_request ORDER BY id DESC LIMIT 20'
) as $r) {
    $targets[$r['requester_id']][] = '요청자';
}

if (!$targets) {
    warn_('역할 배정도 요청도 없습니다', '처리 역할 배정 화면에서 먼저 배정하세요');
}

$dir  = bc_directory_map(array_keys($targets));
$miss = 0;

foreach ($targets as $uid => $roles) {
    $row   = $dir[$uid] ?? null;
    $name  = $row['name'] ?? $uid;
    $email = $row['email'] ?? null;
    $tag   = $name . ' (' . implode('/', array_unique($roles)) . ')';

    if ($hasSlackCol && !empty($row['slack_id'])) {
        ok_($tag, '슬랙 ID ' . $row['slack_id'] . ' 가 회원 정보에 있음');
        continue;
    }
    if (!$email) {
        fail_($tag, '이메일이 없어 슬랙에서 찾을 수 없습니다');
        $miss++;
        continue;
    }
    // API 자체가 막혀 있으면 전원이 '못 찾음' 으로 나온다. 이메일이 틀린 것처럼
    // 읽히면 엉뚱한 곳을 고치게 되므로, 확인 못 했다고만 말한다.
    if (!$apiOk) {
        warn_($tag, $email . ' → 확인 못 함 (3번을 먼저 해결하세요)');
        continue;
    }

    $slackId = SlackChannel::lookupUserByEmail($email);
    if ($slackId) {
        ok_($tag, $email . ' → ' . $slackId);
    } else {
        fail_($tag, $email . ' → 못 찾음');
        $miss++;
    }
}

if ($miss) {
    line('');
    line('         못 찾은 사람은 DM 이 조용히 빠집니다(SKIPPED).');
    line('         포털 계정 이메일과 슬랙 프로필 이메일이 글자까지 같아야 합니다.');
    line('         비활성화된 슬랙 계정도 찾지 못합니다.');
}

// ---------------------------------------------------------------------
head('6. 최근 개인 DM 발송 기록');

$logs = bc_fetch_all(
    'SELECT status, COUNT(*) c, LEFT(MAX(error_msg), 70) e
       FROM bc_notify_log WHERE channel = "SLACK_DM" GROUP BY status'
);
if (!$logs) {
    line('  아직 발송 기록이 없습니다.');
} else {
    foreach ($logs as $r) {
        printf('  %-8s %4d건  %s%s', $r['status'], $r['c'], (string)$r['e'], PHP_EOL);
    }
    line('');
    line('  자세히 보기:');
    line('    SELECT created_at, event_code, recipient, status, error_msg');
    line('      FROM bc_notify_log WHERE channel = \'SLACK_DM\' ORDER BY id DESC LIMIT 20;');
}

// ---------------------------------------------------------------------
// 시험 발송 — 앞의 점검이 모두 통과해도 실제로 한 통 받아 보기 전에는 모른다.
// ---------------------------------------------------------------------
$dmTo = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--dm=')) {
        $dmTo = substr($arg, 5);
    }
}

if ($dmTo !== '') {
    head('7. 시험 발송: ' . $dmTo);

    $slackId = SlackChannel::lookupUserByEmail($dmTo);
    if (!$slackId) {
        fail_('그 이메일로 슬랙 사용자를 찾지 못했습니다');
    } else {
        try {
            SlackChannel::postMessage(
                $slackId,
                "슬랙 연결 시험\n\n보낸 곳: BlueCart 점검 스크립트\n시각: " . date('Y-m-d H:i:s')
                . "\n\n이 메시지가 보이면 개인 DM 이 정상입니다.",
                ':satellite_antenna:'
            );
            if (bc_config('notify.enabled', true)) {
                ok_('보냈습니다', '슬랙에서 확인하세요 — ' . $slackId);
            } else {
                warn_('notify.enabled 가 false 라 실제로는 나가지 않았습니다');
            }
        } catch (Throwable $e) {
            fail_('발송 실패', $e->getMessage());
        }
    }
} else {
    line('');
    line('  실제로 한 통 받아 보려면:');
    line('    php cron/slack_check.php --dm=<본인 이메일>');
}

// ---------------------------------------------------------------------
line();
line(str_repeat('-', 62));
printf('정상 %d · 주의 %d · 오류 %d%s', $PASS, $WARN, $FAIL, PHP_EOL);
line();

exit($FAIL > 0 ? 1 : 0);
