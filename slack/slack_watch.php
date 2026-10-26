<?php
/**
 * Slack 리스트 준실시간 감시 데몬 (CLI 전용).
 *  INTERVAL 초마다 sync.php(증분)를 실행 → 변경 시 data_changed_at 갱신
 *  → lists.php 의 pollStatus(2초)가 감지해 화면 자동 갱신.
 *  (Slack Lists 는 푸시 API 가 없어 폴링. 증분 스캔이라 호출량 적음 — rate limit 여유)
 *  데몬이 돌면 브라우저는 동기화를 하지 않는다(lists.php bgSync: 서버 동기화가 90초 넘게 멈췄을 때만 대신 실행).
 *
 *  토큰: --account=<포털 로그인 이메일>  → 그 사람이 마이페이지에 저장한 슬랙 토큰(암호문)을 풀어 쓴다
 *        또는 SLACK_TOKEN 환경변수
 *  실행: php slack_watch.php --account=me@bluesoft.co.kr
 *  상시: Windows = start_slack_watch.bat / Linux = systemd 서비스
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const INTERVAL = 15;          // 초. 체감 반영 = 이 값 + 브라우저 폴링(2초)
const MAX_INTERVAL = 300;     // 오류가 이어질 때 늘려 가는 상한

function wlog($m) { echo '[' . date('m-d H:i:s') . "] $m\n"; }

/** --account 로 지정한 포털 사용자의 슬랙 토큰. core/auth.php 는 include 할 때 포털 .sessions 에
 *  세션을 열므로 onwatch/bin/secret.php 처럼 임시 폴더에 세션을 먼저 열고 저장 없이 닫는다. */
function watch_portal_token($email) {
    session_save_path(sys_get_temp_dir());
    session_id('slackwatchcli');
    @session_start();
    require_once __DIR__ . '/../core/auth.php';
    session_abort();
    $st = portal_db()->prepare("SELECT slack_token_enc FROM portal_users WHERE email = ?");
    $st->execute([$email]);
    $enc = $st->fetchColumn();
    return $enc ? dec_token($enc) : '';
}

$account = '';
foreach (array_slice($argv, 1) as $a) {
    if (strncmp($a, '--account=', 10) === 0) $account = trim(substr($a, 10));
}
$tok = $account !== '' ? watch_portal_token($account) : (string)getenv('SLACK_TOKEN');
if ($tok === '') {
    wlog($account !== ''
        ? "'$account' 의 슬랙 토큰이 없습니다 — 포털 마이페이지에서 등록하세요."
        : 'SLACK_TOKEN 없음 — --account=<포털 이메일> 또는 SLACK_TOKEN 환경변수를 지정하세요.');
    exit(1);
}
putenv('SLACK_TOKEN=' . $tok);   // sync.php(CLI)가 읽는다 — 명령줄 인자로 넘기면 ps 에 토큰이 보인다

/** sync.php 1회 실행 — 실행마다 새 프로세스라 장시간 돌아도 메모리가 쌓이지 않는다.
 *  RUN_LIMIT 초를 넘기면 강제 종료한다: 한 번이 어디선가 멈추면 데몬 전체가 멈춰 실시간 동기화가 조용히 죽는다.
 *  중간에 끊어도 안전하다 — 행 단위 upsert 이고 워터마크는 끝에서만 올리므로 다음 회차가 이어받는다.
 *  출력은 파이프 대신 임시 파일로 받는다(Windows 는 proc_open 파이프를 기다리며 시간 제한을 걸 수 없다). */
function run_sync($script) {
    $log = tempnam(sys_get_temp_dir(), 'slackwatch');
    $p = proc_open([PHP_BINARY, $script], [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['redirect', 1]], $pipes);
    if (!is_resource($p)) { @unlink($log); return '오류: sync.php 실행 실패'; }
    fclose($pipes[0]);
    $t0 = time(); $timedOut = false;
    while (proc_get_status($p)['running']) {
        if (time() - $t0 > RUN_LIMIT) { proc_terminate($p); $timedOut = true; break; }
        usleep(300000);
    }
    proc_close($p);
    $out = trim((string)@file_get_contents($log));
    @unlink($log);
    return $timedOut ? trim($out . "\n오류: " . RUN_LIMIT . '초 넘게 끝나지 않아 강제 종료') : $out;
}

const RUN_LIMIT = 300;        // 동기화 1회 상한(초). 평소 4~8초, 댓글 수 스캔이 겹치면 10~20초

wlog('Slack 감시 시작 (매 ' . INTERVAL . '초 증분 동기화)' . ($account !== '' ? " — $account" : ''));
$wait = INTERVAL;
while (true) {
    $t0 = microtime(true);
    $out = run_sync(__DIR__ . '/sync.php');
    $bad = ($out === '' || strpos($out, '오류') !== false || stripos($out, 'error') !== false
            || strpos($out, '토큰 없음') !== false);
    // 변경 없을 때는 조용히, 변경/오류만 로그
    if ($bad || strpos($out, '변경 0') === false) wlog($out !== '' ? $out : '(sync.php 출력 없음)');
    // 오류가 이어지면(토큰 만료·rate limit 등) 간격을 두 배씩 늘리고, 정상으로 돌아오면 원래대로
    $wait = $bad ? min(MAX_INTERVAL, $wait * 2) : INTERVAL;
    $sleep = $wait - (microtime(true) - $t0);
    if ($sleep > 0) usleep((int)($sleep * 1e6));
}
