<?php
/**
 * 단건 항목 최신값 새로고침: 상세를 열 때 해당 레코드의 진행상태/담당자/예상완료일을
 * Slack(원본)에서 즉시 읽어 DB 반영 후 반환 → 동시편집으로 화면이 뒤처지는 문제 방지.
 *  GET ?id=<request_id>
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/slack_lib.php';
require_login();
session_release();
header('Content-Type: application/json; charset=utf-8');

$cfg    = require __DIR__ . '/config.php';
$boards = require __DIR__ . '/boards.php';
$tok    = current_token();
$rid    = isset($_GET['id']) ? trim($_GET['id']) : '';
if ($rid === '') { echo json_encode(['ok' => false, 'error' => 'id 필요']); exit; }

try {
    $pdo = db();
    $st = $pdo->prepare("SELECT list_id FROM requests WHERE id = ?");
    $st->execute([$rid]);
    $list = $st->fetchColumn() ?: ($cfg['list_id'] ?? '');
    $col  = $boards[$list]['col'] ?? SLACK_COL;

    $info = slackGet('slackLists.items.info', $tok, ['list_id' => $list, 'id' => $rid]);
    if (empty($info['ok']) || !isset($info['record']['fields'])) {
        echo json_encode(['ok' => false, 'error' => 'Slack 조회 실패']); exit;
    }
    $m = slackIndexFields($info['record']['fields']);

    // 진행상태
    $sel      = slackSelectMaps($tok, $list, $rid);
    $statusId = isset($m[$col['status']]) ? slackFieldSelect($m[$col['status']]) : null;
    $status   = $statusId !== null ? (($sel[$col['status']] ?? [])[$statusId] ?? '') : '';
    // 담당자
    $asgId    = isset($m[$col['asg']]) ? slackFieldUser($m[$col['asg']]) : null;
    $asg      = '—';
    if ($asgId) { $names = slackResolveUsers($tok, [$asgId]); $asg = $names[$asgId] ?? $asgId; }
    // 예상완료일
    $eta      = isset($m[$col['eta']]) ? slackFieldDate($m[$col['eta']]) : null;

    // DB 반영(최신화)
    $pdo->prepare("UPDATE requests SET status_id=?, status=?, asg_id=?, asg=?, `eta`=? WHERE id=?")
        ->execute([$statusId, $status, $asgId ?: null, $asg, $eta ?: null, $rid]);

    echo json_encode(['ok' => true, 'id' => $rid,
        'status' => $status, 'status_id' => $statusId,
        'asg' => $asg, 'asg_id' => $asgId, 'eta' => $eta], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
