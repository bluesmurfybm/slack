<?php
/**
 * GET api/attachment_download.php?id=12
 *
 * 저장 경로는 웹에서 직접 접근할 수 없고, 이 스크립트만 파일을 내보냅니다.
 * 모든 첨부는 항상 내려받기로만 처리합니다. 브라우저에서 바로 열리면
 * HTML 이나 SVG 가 같은 출처에서 실행될 수 있기 때문입니다.
 */
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once BC_ROOT . '/includes/model/Attachment.php';

$user = bc_current_user();
if ($user === null) {
    http_response_code(401);
    exit('로그인이 필요합니다.');
}

$id  = bc_param_int('id');
$att = $id ? Attachment::find($id) : null;
if (!$att) {
    http_response_code(404);
    exit('첨부파일을 찾을 수 없습니다.');
}

// 사내 전용 도구이고 요청 목록은 전 구성원에게 공개이므로
// 로그인한 사람이면 내려받을 수 있다. 요청별로 막아야 한다면 여기서 거른다.
if (!PurchaseRequest::find((int)$att['request_id'])) {
    http_response_code(404);
    exit('원본 요청이 삭제되었습니다.');
}

$path = $att['stored_path'];
if (!is_file($path) || !is_readable($path)) {
    error_log('[BlueCart] missing attachment file: ' . $path);
    http_response_code(410);
    exit('파일이 저장소에 없습니다. 관리자에게 문의하세요.');
}

$name  = $att['orig_name'];
$ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'file';

// 출력 버퍼에 남은 내용이 있으면 파일 앞에 섞여 들어간다.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $ascii . '"; '
     . "filename*=UTF-8''" . rawurlencode($name));
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'');
header('Cache-Control: private, no-store');

readfile($path);
exit;
