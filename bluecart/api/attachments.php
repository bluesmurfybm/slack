<?php
/**
 * GET  api/attachments.php?request_id=1       첨부 목록
 * POST api/attachments.php                    op=upload (multipart) | op=delete
 *
 * 업로드는 multipart/form-data 로 보내므로 JSON 본문이 아닙니다.
 * 필드: op=upload, request_id, _csrf, files[] (여러 개 가능)
 */
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once BC_ROOT . '/includes/model/Attachment.php';

$user = bc_require_login_api();

// ---------------------------------------------------------------------
// 목록
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $requestId = bc_param_int('request_id');
    if (!$requestId) {
        bc_json_error('요청 번호가 없습니다.');
    }
    $req = PurchaseRequest::find($requestId);
    if (!$req) {
        bc_json_error('요청을 찾을 수 없습니다.', 404);
    }

    bc_json_ok([
        'can_modify' => Attachment::canModify($req, $user),
        'rows'       => array_map(fn($a) => [
            'id'        => (int)$a['id'],
            'name'      => $a['orig_name'],
            'size'      => Attachment::humanSize((int)$a['file_size']),
            'uploader'  => $a['uploader_id'],
            'is_mine'   => $a['uploader_id'] === $user['id'],
            'url'       => 'api/attachment_download.php?id=' . (int)$a['id'],
        ], Attachment::listFor($requestId)),
    ]);
}

// ---------------------------------------------------------------------
// 변경
// ---------------------------------------------------------------------
bc_verify_csrf();
$op = bc_param_str('op');

if ($op === 'delete') {
    $id  = bc_param_int('id');
    $att = $id ? Attachment::find($id) : null;
    if (!$att) {
        bc_json_error('첨부파일을 찾을 수 없습니다.', 404);
    }
    $req = PurchaseRequest::find((int)$att['request_id']);
    if (!$req || !Attachment::canModify($req, $user)) {
        bc_json_error('이 첨부파일을 지울 권한이 없습니다.', 403);
    }
    // 올린 본인이나 관리자만 삭제
    if ($att['uploader_id'] !== $user['id'] && !in_array('ADMIN', bc_roles_of($user['id']), true)) {
        bc_json_error('본인이 올린 파일만 지울 수 있습니다.', 403);
    }

    Attachment::delete($id);
    PurchaseRequest::log((int)$att['request_id'], 'REQUEST_UPDATED',
        $req['status'], $req['status'], $user, '첨부 삭제: ' . $att['orig_name']);

    bc_json_ok(['message' => '첨부파일을 지웠습니다.']);
}

if ($op !== 'upload') {
    bc_json_error('알 수 없는 요청입니다.');
}

$requestId = bc_param_int('request_id');
$req = $requestId ? PurchaseRequest::find($requestId) : null;
if (!$req) {
    bc_json_error('요청을 찾을 수 없습니다.', 404);
}
if (!Attachment::canModify($req, $user)) {
    bc_json_error('이 요청에는 파일을 올릴 수 없습니다.', 403);
}

// POST 본문 자체가 너무 커서 PHP 가 통째로 버린 경우 $_FILES 가 비어 있다.
if (empty($_FILES) && ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    bc_json_error('전송 크기가 서버 한도를 넘었습니다. php.ini 의 post_max_size 를 확인하세요.', 413);
}

$files = $_FILES['files'] ?? null;
if (!$files || !isset($files['name'])) {
    bc_json_error('선택된 파일이 없습니다.');
}

// 단일/다중 업로드를 같은 형태로 정규화
$items = [];
if (is_array($files['name'])) {
    foreach ($files['name'] as $i => $_) {
        $items[] = [
            'name'     => $files['name'][$i],
            'type'     => $files['type'][$i]     ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error'    => $files['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
            'size'     => $files['size'][$i]     ?? 0,
        ];
    }
} else {
    $items[] = $files;
}

const MAX_PER_REQUEST = 10;
if (Attachment::countFor($requestId) + count($items) > MAX_PER_REQUEST) {
    bc_json_error(sprintf('첨부는 요청당 %d개까지만 올릴 수 있습니다.', MAX_PER_REQUEST));
}

$saved  = [];
$errors = [];
foreach ($items as $item) {
    try {
        Attachment::store($requestId, $item, $user);
        $saved[] = $item['name'];
    } catch (DomainException $e) {
        $errors[] = $item['name'] . ': ' . $e->getMessage();
    } catch (Throwable $e) {
        error_log('[BlueCart] upload failed: ' . $e->getMessage());
        $errors[] = $item['name'] . ': 저장에 실패했습니다.';
    }
}

if ($saved) {
    PurchaseRequest::log($requestId, 'REQUEST_UPDATED', $req['status'], $req['status'],
        $user, '첨부 추가: ' . implode(', ', $saved));
}

// 일부만 성공한 경우에도 성공한 건은 유지하고 실패 사유를 함께 돌려준다.
if (!$saved && $errors) {
    bc_json_error(implode("\n", $errors));
}

bc_json_ok([
    'message' => sprintf('%d개를 올렸습니다.', count($saved))
        . ($errors ? sprintf(' (%d개 실패)', count($errors)) : ''),
    'saved'   => $saved,
    'errors'  => $errors,
]);
