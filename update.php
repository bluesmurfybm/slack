<?php
/**
 * 레코드 진행상태/담당자/예상완료일 수정 → 해당 리스트(Slack) write-back + DB 반영. 로그인 필요.
 *  - 보드별 컬럼 매핑(boards.php)으로 리스트에 맞는 컬럼에 기록.
 *  - 멀티셀렉트(와이오즈 진행상태)도 select=[새 옵션] 한 번으로 전체 교체됨.
 *  POST { request_id, field:'status'|'asg'|'eta', value }
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/slack_lib.php';
require_login();
session_release();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST only']); exit;
}

$cfg    = require __DIR__ . '/config.php';
$boards = require __DIR__ . '/boards.php';
$tok    = current_token();

$in    = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
$rid   = isset($in['request_id']) ? trim($in['request_id']) : '';
$field = $in['field'] ?? '';
$value = isset($in['value']) ? trim((string)$in['value']) : '';

if ($rid === '' || !in_array($field, ['status', 'asg', 'eta'], true)) {
    echo json_encode(['ok' => false, 'error' => '잘못된 요청']); exit;
}

try {
    $pdo = db();
    $st = $pdo->prepare("SELECT list_id FROM requests WHERE id = ?");
    $st->execute([$rid]);
    $list = $st->fetchColumn() ?: ($cfg['list_id'] ?? '');
    $col  = $boards[$list]['col'] ?? SLACK_COL;

    if ($field === 'status') {
        $sel   = slackSelectMaps($tok, $list, $rid);
        $map   = $sel[$col['status']] ?? [];
        $optId = array_search($value, $map, true);
        if ($optId === false) { echo json_encode(['ok' => false, 'error' => '알 수 없는 상태값'], JSON_UNESCAPED_UNICODE); exit; }
        $r = slackUpdateCell($tok, $list, $rid, $col['status'], 'select', [$optId]);
        if (empty($r['ok'])) { echo json_encode(['ok' => false, 'error' => 'Slack: ' . ($r['error'] ?? 'fail')]); exit; }
        $pdo->prepare("UPDATE requests SET status_id=?, status=? WHERE id=?")->execute([$optId, $value, $rid]);
        echo json_encode(['ok' => true, 'field' => 'status', 'value' => $value, 'status_id' => $optId], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($field === 'eta') {
        $r = slackUpdateCell($tok, $list, $rid, $col['eta'], 'date', $value !== '' ? [$value] : []);
        if (empty($r['ok'])) { echo json_encode(['ok' => false, 'error' => 'Slack: ' . ($r['error'] ?? 'fail')]); exit; }
        $pdo->prepare("UPDATE requests SET `eta`=? WHERE id=?")->execute([$value !== '' ? $value : null, $rid]);
        echo json_encode(['ok' => true, 'field' => 'eta', 'eta' => $value], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // asg (담당자)
    $r = slackUpdateCell($tok, $list, $rid, $col['asg'], 'user', $value !== '' ? [$value] : []);
    if (empty($r['ok'])) { echo json_encode(['ok' => false, 'error' => 'Slack: ' . ($r['error'] ?? 'fail')]); exit; }
    $name = '—';
    if ($value !== '') { $names = slackResolveUsers($tok, [$value]); $name = $names[$value] ?? $value; }
    $pdo->prepare("UPDATE requests SET asg_id=?, asg=? WHERE id=?")->execute([$value !== '' ? $value : null, $name, $rid]);
    echo json_encode(['ok' => true, 'field' => 'asg', 'asg_id' => $value, 'asg' => $name], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
