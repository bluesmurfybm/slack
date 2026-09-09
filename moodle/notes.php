<?php
/**
 * 요약본 형광펜·메모 API. 화면에서 드래그해 남긴 표시를 저장하고 지운다.
 *  - POST JSON {action:"add", report_id, target, kind, text, prefix, suffix, note, color}
 *  - POST JSON {action:"delete", id}  — 본인 것만
 *  표시는 팀이 함께 본다(작성자 이름이 붙는다). 위치는 텍스트 앵커(선택한 글 + 앞뒤 문맥)로 저장하고
 *  화면(JS)이 다시 찍는다. 요약이 갱신되어 문장이 바뀌면 앵커를 못 찾은 표시는 목록에만 남는다.
 *  사용자가 누를 때만 INSERT/DELETE 한 번씩 — 폴링은 없다.
 */
date_default_timezone_set('Asia/Seoul');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$me = current_portal_user();
if (!$me) {
    http_response_code(401);
    echo json_encode(['error' => '로그인이 필요합니다'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '{"error":"POST only"}';
    exit;
}
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) {
    http_response_code(400);
    echo '{"error":"json"}';
    exit;
}

$fail = function ($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

$action = $in['action'] ?? '';

if ($action === 'delete') {
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) $fail('id');
    $ok = moodle_note_delete($id, $me['email']);
    echo json_encode(['ok' => $ok, 'id' => $id]);
    exit;
}

if ($action === 'add') {
    $reportId = (int)($in['report_id'] ?? 0);
    $kind     = ($in['kind'] ?? '') === 'note' ? 'note' : 'highlight';
    $target   = in_array($in['target'] ?? '', ['summary', 'updates'], true) ? $in['target'] : 'summary';
    $text     = trim((string)($in['text'] ?? ''));
    $note     = trim((string)($in['note'] ?? ''));
    $color    = preg_match('/^[a-z]{3,12}$/', (string)($in['color'] ?? '')) ? $in['color'] : ($kind === 'note' ? 'blue' : 'yellow');
    if ($reportId <= 0) $fail('report_id');
    if ($text === '' || mb_strlen($text) > 2000) $fail('선택한 글이 없거나 너무 깁니다(2000자)');
    if ($kind === 'note' && $note === '') $fail('메모 내용을 적어 주세요');
    if (mb_strlen($note) > 2000) $fail('메모가 너무 깁니다(2000자)');
    $stmt = moodle_db()->prepare("SELECT id FROM moodle_weekly_report WHERE id = ?");
    $stmt->execute([$reportId]);
    if (!$stmt->fetchColumn()) $fail('리포트가 없습니다', 404);

    $row = moodle_note_add([
        'report_id'  => $reportId,
        'target'     => $target,
        'kind'       => $kind,
        'text'       => $text,
        'prefix'     => mb_substr((string)($in['prefix'] ?? ''), -120),
        'suffix'     => mb_substr((string)($in['suffix'] ?? ''), 0, 120),
        'note'       => $kind === 'note' ? $note : null,
        'color'      => $color,
        'user_email' => $me['email'],
        'user_name'  => $me['name'],
    ]);
    echo json_encode(['ok' => true, 'note' => $row], JSON_UNESCAPED_UNICODE);
    exit;
}

$fail('action');
