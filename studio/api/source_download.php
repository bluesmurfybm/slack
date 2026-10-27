<?php
/**
 * GET api/source_download.php?id=12            내려받기
 * GET api/source_download.php?id=12&inline=1   보기 (이미지·PDF 만)
 *
 * 출처 문서(bs_project_source) 파일을 내보낸다 (2026-10-15).
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 저장 경로(BS_UPLOAD_DIR)는 웹에서 막혀 있고 이 스크립트만 파일을  │
 * │ 내보낸다 — bluecart/api/attachment_download.php 와 같은 방식.     │
 * │                                                                  │
 * │ 바로 보기(inline)는 **이미지와 PDF 만** 연다. 나머지는 늘 내려받기│
 * │ 다. 같은 출처에서 열리면 스크립트가 돌 수 있는 형식(HTML·SVG)은   │
 * │ 애초에 업로드가 막혀 있지만(BS_UPLOAD_MIME), 여기서도 한 번 더    │
 * │ 형식을 확인하고 CSP sandbox 를 건다.                              │
 * │                                                                  │
 * │ 엑셀·PPT·워드는 브라우저가 그리지 못한다. 외부 뷰어(Office Online │
 * │ 등)는 업무 문서를 바깥으로 보내는 일이라 쓰지 않는다 — 화면은     │
 * │ 추출해 둔 본문(api/project.php?act=source_text)을 보여 준다.      │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 권한: 그 과업을 볼 수 있는 사람. 프로젝트는 로그인한 구성원 전원(지워진 것은
 * 관리자만), R&D 과제는 공개 범위 안의 사람 (ProjectRepo::findSourceForView).
 */
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once BS_ROOT . '/inc/repo/ProjectRepo.php';

// 바로 보기를 허용하는 형식은 BS_VIEWABLE_MIME (inc/bootstrap.php) 하나로 본다.

function bs_download_fail(int $status, string $msg): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    exit($msg);
}

if (bs_current_user() === null) {
    bs_download_fail(401, '로그인이 필요합니다.');
}

$id  = bs_param_int('id', 0) ?? 0;
$src = $id > 0 ? (new ProjectRepo(bs_db()))->findSourceForView($id, bs_is_admin()) : null;
if (!$src) {
    bs_download_fail(404, '파일을 찾을 수 없습니다.');
}
if (empty($src['file_path'])) {
    // 링크·직접 입력으로 등록한 출처. 내려받을 파일이 없다.
    bs_download_fail(404, '파일로 올린 문서가 아닙니다.');
}

$path = (string)$src['file_path'];
if (!is_file($path) || !is_readable($path)) {
    error_log('[BlueStudio] missing source file: #' . $id . ' ' . $path);
    bs_download_fail(410, '파일이 저장소에 없습니다. 관리자에게 문의하세요.');
}

$mime   = (string)($src['mime'] ?? '');
$inline = bs_param_str('inline') === '1' && in_array($mime, BS_VIEWABLE_MIME, true);

$name  = (string)$src['title'];
$ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'file';

// 출력 버퍼에 남은 내용이 있으면 파일 앞에 섞여 들어간다.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
     . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
// PDF 는 예외다. Chrome 내장 뷰어는 sandbox·default-src 'none' 이 걸리면 PDF 를
// 그리지 않는다(빈 화면/차단). PDF 안의 스크립트는 브라우저 뷰어가 이미 막는다.
if (!($inline && $mime === 'application/pdf')) {
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
}
header('Cache-Control: private, no-store');

readfile($path);
exit;
