<?php
/**
 * '지금 다시 가져오기' — 갱신 요청을 파일로 남긴다.
 *  - POST week=2026-W37 : watch/var/requests/<week>.json 을 만들고 화면으로 돌아간다.
 *  - GET  ?status=<week>: 요청 상태 JSON (화면이 처리 중일 때 몇 초마다 물어본다).
 *  PHP 는 Python 을 직접 띄우지 않는다. 서버에서는 systemd path 유닛(moodle-watch-refresh.path)이,
 *  로컬에서는 `python run_weekly.py --serve` 가 이 파일을 집어 배치를 돌린다.
 */
date_default_timezone_set('Asia/Seoul');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/db.php';

$me = current_portal_user();
if (!$me) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '로그인이 필요합니다'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Cache-Control: no-store');

$isWeek = function ($w) { return is_string($w) && preg_match('/^\d{4}-W\d{2}$/', $w); };

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $week = $_GET['status'] ?? '';
    header('Content-Type: application/json; charset=utf-8');
    if (!$isWeek($week)) { http_response_code(400); echo '{"error":"week"}'; exit; }
    $st = moodle_refresh_state($week);
    $report = moodle_report($week);
    echo json_encode([
        'week'         => $week,
        'state'        => $st ? $st['state'] : 'idle',
        'error'        => $st['error'] ?? null,
        'requested_at' => $st['requested_at'] ?? null,
        'generated_at' => $report ? $report['generated_at'] : null,
        'run_count'    => $report ? (int)$report['run_count'] : 0,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
if (!moodle_is_admin($me['email'])) {
    header('Location: index.php?err=' . rawurlencode('요약하기는 관리자 계정만 할 수 있습니다'));
    exit;
}

$week = $_POST['week'] ?? '';
$back = 'index.php?week=' . rawurlencode($isWeek($week) ? $week : '');
if (!$isWeek($week) || !moodle_report($week)) {
    header('Location: index.php?err=' . rawurlencode('갱신할 주차가 없습니다'));
    exit;
}

$dir = moodle_requests_dir();

// 요청 취소: 대기 중인 요청 파일을 지운다. 처리 중(running)은 오래 멈춘 경우(30분)에만 지울 수 있다.
if (!empty($_POST['cancel'])) {
    $state = moodle_refresh_state($week);
    if ($state && $state['state'] === 'pending') {
        @unlink($state['path']);
    } elseif ($state && $state['state'] === 'running') {
        $started = strtotime($state['started_at'] ?? $state['requested_at'] ?? '') ?: 0;
        if (time() - $started >= 30 * 60) @unlink($state['path']);
        else { header('Location: ' . $back . '&err=' . rawurlencode('배치가 처리 중이라 취소할 수 없습니다. 끝나기를 기다려 주세요')); exit; }
    }
    header('Location: ' . $back);
    exit;
}

if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
    header('Location: ' . $back . '&err=' . rawurlencode("요청 폴더를 만들 수 없습니다: $dir (배치 실행 계정과 웹 계정이 같이 쓸 수 있게 권한을 주세요)"));
    exit;
}
if (!is_writable($dir)) {
    header('Location: ' . $back . '&err=' . rawurlencode("요청 폴더에 쓸 수 없습니다: $dir"));
    exit;
}

$state = moodle_refresh_state($week);
if ($state && in_array($state['state'], ['pending', 'running'], true)) {
    header('Location: ' . $back . '&queued=1');   // 이미 진행 중이면 중복 요청을 만들지 않는다
    exit;
}
@unlink("$dir/$week.failed.json");   // 재시도: 이전 실패 기록은 지운다

$payload = json_encode([
    'week'         => $week,
    'requested_by' => $me['email'],
    'requested_at' => gmdate('Y-m-d\TH:i:s+00:00'),
], JSON_UNESCAPED_UNICODE);
if (@file_put_contents("$dir/$week.json", $payload, LOCK_EX) === false) {
    header('Location: ' . $back . '&err=' . rawurlencode('요청 파일을 쓸 수 없습니다'));
    exit;
}
header('Location: ' . $back . '&queued=1');
