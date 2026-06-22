<?php
/**
 * 레코드 진행상태/담당자 수정 → Slack 리스트 write-back + DB 반영. 로그인 필요.
 *  POST { request_id, field:'status'|'asg', value }
 *   - status: value = 진행상태 라벨 (스키마에서 옵션ID로 변환)
 *   - asg:    value = 담당자 Slack user ID (빈값이면 미지정 처리)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/slack_lib.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

$cfg  = require __DIR__ . '/config.php';
$tok  = current_token();              // 로그인 사용자 토큰으로 write-back
$list = $cfg['list_id'];

$in    = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
$rid   = isset($in['request_id']) ? trim($in['request_id']) : '';
$field = isset($in['field']) ? $in['field'] : '';
$value = isset($in['value']) ? trim((string)$in['value']) : '';

if ($rid === '' || !in_array($field, ['status', 'asg', 'eta'], true)) {
    echo json_encode(['ok' => false, 'error' => '잘못된 요청']);
    exit;
}

try {
    $pdo = db();

    if ($field === 'status') {
        // 라벨 → 옵션ID
        $sel = slackSelectMaps($tok, $list, $rid);
        $map = $sel[SLACK_COL['status']] ?? [];          // optId => label
        $optId = array_search($value, $map, true);        // label => optId
        if ($optId === false) { echo json_encode(['ok' => false, 'error' => '알 수 없는 상태값']); exit; }

        $r = slackUpdateCell($tok, $list, $rid, SLACK_COL['status'], 'select', [$optId]);
        if (empty($r['ok'])) { echo json_encode(['ok' => false, 'error' => 'Slack: ' . ($r['error'] ?? 'fail')]); exit; }

        $pdo->prepare("UPDATE requests SET status_id=?, status=? WHERE id=?")->execute([$optId, $value, $rid]);
        echo json_encode(['ok' => true, 'field' => 'status', 'value' => $value, 'status_id' => $optId], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($field === 'eta') {
        // 예상처리완료일 (YYYY-MM-DD), 빈값이면 해제
        $dateArr = $value !== '' ? [$value] : [];
        $r = slackUpdateCell($tok, $list, $rid, SLACK_COL['eta'], 'date', $dateArr);
        if (empty($r['ok'])) { echo json_encode(['ok' => false, 'error' => 'Slack: ' . ($r['error'] ?? 'fail')]); exit; }
        $pdo->prepare("UPDATE requests SET eta=? WHERE id=?")->execute([$value !== '' ? $value : null, $rid]);
        echo json_encode(['ok' => true, 'field' => 'eta', 'eta' => $value], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // asg (담당자)
    $userArr = $value !== '' ? [$value] : [];
    $r = slackUpdateCell($tok, $list, $rid, SLACK_COL['asg'], 'user', $userArr);
    if (empty($r['ok'])) { echo json_encode(['ok' => false, 'error' => 'Slack: ' . ($r['error'] ?? 'fail')]); exit; }

    $name = '—';
    if ($value !== '') {
        $names = slackResolveUsers($tok, [$value]);
        $name  = $names[$value] ?? $value;
    }
    $pdo->prepare("UPDATE requests SET asg_id=?, asg=? WHERE id=?")
        ->execute([$value !== '' ? $value : null, $name, $rid]);
    echo json_encode(['ok' => true, 'field' => 'asg', 'asg_id' => $value, 'asg' => $name], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
