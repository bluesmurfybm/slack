<?php
/**
 * BLUMO 자동 점검 (CLI 전용) — 작업 스케줄러가 5분마다 부른다 (tools/monitor-task.bat 로 등록).
 *   php sitehub/monitor_run.php                전체 점검 (일시정지면 건너뜀)
 *   php sitehub/monitor_run.php --manual       화면의 '전체 지금 점검' — 일시정지여도 돈다
 *   php sitehub/monitor_run.php --id=12        감시 주소 하나
 *   php sitehub/monitor_run.php --doctor       환경 진단
 *   php sitehub/monitor_run.php --test-webhook 웹훅 테스트 (장애/복구 샘플 2건)
 * 종료코드: 정상 0, 하나라도 장애 1 (크론 알림 연동용)
 * CLI 는 core/auth.php 를 거치지 않으므로 시간대를 여기서 못 박는다(CLAUDE.md).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
date_default_timezone_set('Asia/Seoul');
require_once __DIR__ . '/db.php';   // guard.php(세션) 없이 — 설정은 config.php 를 직접 읽는다
require_once __DIR__ . '/monitor_engine.php';

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (substr($a, 0, 2) !== '--') continue;
    $p = explode('=', substr($a, 2), 2);
    $opt[$p[0]] = $p[1] ?? true;
}
$pdo = sitehub_db();
$say = function ($s) { echo $s, "\n"; };

if (isset($opt['doctor'])) { foreach (blumo_doctor($pdo) as $l) $say($l); exit(0); }

if (isset($opt['test-webhook'])) {
    $r = blumo_test_webhook($pdo);
    $say($r === true ? '웹훅 발송 성공 — 채널에 장애/복구 2건이 왔는지 확인하세요' : '웹훅 발송 실패: ' . $r);
    exit($r === true ? 0 : 1);
}

if (isset($opt['id'])) {
    $t = blumo_targets($pdo, (int)$opt['id']);
    if (!$t) { $say('감시 주소가 없습니다: ' . $opt['id']); exit(2); }
    $r = blumo_check($pdo, $t[0], blumo_cfg($pdo));
    $say(sprintf('%s  %s  http=%s %sms  %s', $t[0]['name'], $r['class'], $r['code'], $r['total_ms'], $r['cause']));
    exit(in_array($r['class'], BLUMO_UP_CLASSES, true) ? 0 : 1);
}

$res = blumo_run($pdo, isset($opt['manual']) ? 'manual' : 'schedule', $say);
if (isset($res['checked'])) $say("[BLUMO] {$res['checked']}곳 점검 · 이상 {$res['down']} · {$res['took']}초");
exit(!empty($res['down']) ? 1 : 0);
