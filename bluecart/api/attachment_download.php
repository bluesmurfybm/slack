<?php
/**
 * GET api/attachment_download.php?id=12            내려받기
 * GET api/attachment_download.php?id=12&inline=1   보기 (이미지·PDF 만)
 *
 * 저장 경로는 웹에서 직접 접근할 수 없고, 이 스크립트만 파일을 내보냅니다.
 * 기본은 내려받기입니다. 바로 보기(inline)는 **이미지와 PDF 만** 엽니다
 * (2026-10-16) — 업로드 때 내용으로 확인한 형식(mime_type)이 그것일 때만.
 * HTML·SVG 처럼 같은 출처에서 스크립트가 돌 수 있는 형식은 애초에 올릴 수
 * 없고, 바로 보기 대상도 아닙니다.
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

// 바로 보기를 허용하는 형식. 업로드 때 내용으로 확인한 값만 믿는다.
const BC_INLINE_MIME = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif'];
$mime   = (string)($att['mime_type'] ?? '');
$inline = bc_param_str('inline') === '1' && in_array($mime, BC_INLINE_MIME, true);

$name  = $att['orig_name'];
$ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'file';

// 출력 버퍼에 남은 내용이 있으면 파일 앞에 섞여 들어간다.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; '
     . "filename*=UTF-8''" . rawurlencode($name));
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
// PDF 는 예외 — Chrome 내장 뷰어는 default-src 'none' 이 걸리면 PDF 를 그리지 않는다.
// PDF 안의 스크립트는 브라우저 뷰어가 막는다.
if (!($inline && $mime === 'application/pdf')) {
    header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; style-src \'unsafe-inline\'; sandbox');
}
header('Cache-Control: private, no-store');

readfile($path);
exit;
