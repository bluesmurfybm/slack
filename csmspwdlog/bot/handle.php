<?php
/**
 * 슬랙 메시지 1건 처리 — bot.py 가 메시지마다 실행한다.
 *   입력  stdin JSON {channel, ts, user, text}, 환경변수 SLACK_BOT_TOKEN
 *   출력  마지막 줄 JSON {ok, results:[{host, result}]}
 *   --dry-run  Slack 호출·DB 기록 없이, 비밀번호를 가린 DM 문구를 같이 출력한다.
 *
 * 출력·로그에 계정 값을 싣지 않는다(--dry-run 도 비밀번호는 가린다).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
date_default_timezone_set('Asia/Seoul');
require_once __DIR__ . '/../lib.php';

function csmspwdlog_cli_out(array $data, int $code = 0): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE), "\n";
    exit($code);
}

try {
    $dry = in_array('--dry-run', $argv, true);
    $in  = json_decode((string)stream_get_contents(STDIN), true);
    if (!is_array($in)) csmspwdlog_cli_out(['ok' => false, 'error' => 'bad_input'], 2);
    $channel = trim((string)($in['channel'] ?? ''));
    $ts      = trim((string)($in['ts'] ?? ''));
    $user    = trim((string)($in['user'] ?? ''));
    $text    = (string)($in['text'] ?? '');
    if ($channel === '' || $ts === '' || $user === '') csmspwdlog_cli_out(['ok' => false, 'error' => 'bad_input'], 2);

    $hosts = csmspwdlog_hosts_in($text);
    if (!$hosts) csmspwdlog_cli_out(['ok' => true, 'results' => []]);

    $token = (string)getenv('SLACK_BOT_TOKEN');
    if (!$dry && $token === '') csmspwdlog_cli_out(['ok' => false, 'error' => 'no_token'], 2);

    $pdo = portal_db();
    if (!$dry) csmspwdlog_table($pdo);

    $name = ''; $email = '';
    if (!$dry) {
        $ui = csmspwdlog_slack('users.info', $token, ['user' => $user]);
        if (!empty($ui['ok'])) {
            $prof  = $ui['user']['profile'] ?? [];
            $name  = (string)($prof['real_name'] ?? '') ?: (string)($ui['user']['real_name'] ?? '') ?: (string)($ui['user']['name'] ?? '');
            $email = (string)($prof['email'] ?? '');
        }
    }

    $results = [];
    foreach ($hosts as $h) {
        $id = null;
        if (!$dry) {
            // 같은 메시지를 두 번 받아도(Socket Mode 재전송·재기동) DM 은 한 번만 나가야 한다
            $ins = $pdo->prepare("INSERT IGNORE INTO csmspwdlog
                    (created_at, channel, msg_ts, slack_user_id, slack_name, slack_email, url_raw, host, result)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
            $ins->execute([date('Y-m-d H:i:s'), $channel, $ts, $user, mb_substr($name, 0, 100), mb_substr($email, 0, 190),
                           $h['raw'], mb_substr($h['host'], 0, 190)]);
            if ($ins->rowCount() === 0) { $results[] = ['host' => $h['host'], 'result' => 'duplicate']; continue; }
            $id = (int)$pdo->lastInsertId();
        }

        $schools = csmspwdlog_find($pdo, $h['host']);
        $blocks = []; $hasAccount = false;
        foreach ($schools as $s) {
            $acc = csmspwdlog_accounts($pdo, $s['school_id'], $s['env']);
            if ($acc) $hasAccount = true;
            $blocks[] = csmspwdlog_dm_text($s, $acc, $dry);
        }
        if (!$schools) {
            $result = 'not_found';
            $dm = '`' . csmspwdlog_slack_esc($h['host']) . "` — EnvHub 에 등록된 대학을 찾지 못했습니다.\n운영 또는 테스트 사이트 주소를 그대로 입력해 주세요.";
        } else {
            $result = $hasAccount ? 'sent' : 'no_info';
            $dm = implode("\n\n", $blocks);
        }

        $detail = count($schools) > 1 ? '대학 ' . count($schools) . '곳 일치' : '';
        if ($dry) {
            $results[] = ['host' => $h['host'], 'result' => $result, 'text' => $dm];
            continue;
        }

        $r = csmspwdlog_slack('chat.postMessage', $token, ['channel' => $user, 'text' => $dm, 'unfurl_links' => 'false', 'unfurl_media' => 'false']);
        if (empty($r['ok'])) {
            $err = 'dm: ' . (string)($r['error'] ?? 'unknown');
            if ($result === 'sent') $result = 'dm_failed';
            $detail = trim($detail . ' ' . $err);
        }

        $first = $schools[0] ?? null;
        $pdo->prepare("UPDATE csmspwdlog SET env = ?, school_id = ?, school_name = ?, ver = ?, result = ?, detail = ? WHERE id = ?")
            ->execute([$first['env'] ?? '', $first['school_id'] ?? null,
                       mb_substr(implode(', ', array_column($schools, 'name')), 0, 200), $first['ver'] ?? '',
                       $result, mb_substr($detail, 0, 500), $id]);
        $results[] = ['host' => $h['host'], 'result' => $result];
    }
    csmspwdlog_cli_out(['ok' => true, 'results' => $results]);
} catch (Throwable $e) {
    csmspwdlog_cli_out(['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()], 1);
}
