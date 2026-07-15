<?php
/**
 * Slack 리스트 준실시간 감시 데몬 (CLI 전용).
 *  15초 간격으로 sync.php(증분)를 실행 → 변경 시 data_changed_at 갱신
 *  → lists.php 의 기존 5초 pollStatus 가 감지해 화면 자동 갱신.
 *  (Slack Lists 는 푸시 API 가 없어 폴링. 증분 스캔이라 호출량 적음 — rate limit 여유)
 *
 *  토큰: config.local.php 의 'slack_token' (개인 xoxp)
 *  실행: php slack_watch.php  /  상시: start_slack_watch.bat
 */
if (php_sapi_name() !== 'cli') exit('CLI 전용');

$local = is_file(__DIR__ . '/config.local.php') ? (require __DIR__ . '/config.local.php') : [];
$tok = $local['slack_token'] ?? getenv('SLACK_TOKEN');
if (!$tok) exit("SLACK_TOKEN 없음 — config.local.php 에 'slack_token' => 'xoxp-...' 추가\n");
putenv('SLACK_TOKEN=' . $tok);

const INTERVAL = 15;   // 초. 체감 반영 = 이 값 + 브라우저 폴링(≤5초)

echo '[' . date('m-d H:i:s') . "] Slack 감시 시작 (매 " . INTERVAL . "초 증분 동기화)\n";
$php = PHP_BINARY;
$sync = __DIR__ . '/sync.php';
while (true) {
    $t0 = microtime(true);
    $out = trim((string)shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($sync) . ' 2>&1'));
    // 변경 없을 때는 조용히, 변경/오류만 로그
    if ($out !== '' && (strpos($out, '변경 0') === false || stripos($out, 'error') !== false)) {
        echo '[' . date('m-d H:i:s') . "] $out\n";
    }
    $sleep = INTERVAL - (microtime(true) - $t0);
    if ($sleep > 0) usleep((int)($sleep * 1e6));
}
