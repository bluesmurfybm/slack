<?php
/**
 * GET api/rnd_file.php?id=12            내려받기
 * GET api/rnd_file.php?id=12&inline=1   보기 (이미지·PDF 만)
 *
 * R&D 진행 기록·산출물 첨부(bs_rnd_file, 029)를 내보낸다 (2026-10-19).
 * 프로젝트 출처의 api/source_download.php 와 같은 방식이다 — 저장 경로는 웹에서
 * 막혀 있고 이 스크립트만 파일을 내보낸다. 바로 보기는 BS_VIEWABLE_MIME 만.
 *
 * 권한: **그 과제를 볼 수 있는 사람** (RndRepo::findFileForView → find() 가
 * 공개 범위와 삭제 여부를 본다). 비공개 과제의 파일이 번호만으로 새지 않게.
 */
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once BS_ROOT . '/inc/repo/RndRepo.php';

function bs_rndfile_fail(int $status, string $msg): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    exit($msg);
}

if (bs_current_user() === null) {
    bs_rndfile_fail(401, '로그인이 필요합니다.');
}

$id = bs_param_int('id', 0) ?? 0;
$f  = $id > 0 ? (new RndRepo(bs_db()))->findFileForView($id) : null;
if (!$f) {
    bs_rndfile_fail(404, '파일을 찾을 수 없습니다.');
}

$path = (string)$f['file_path'];
if (!is_file($path) || !is_readable($path)) {
    error_log('[BlueStudio] missing rnd file: #' . $id . ' ' . $path);
    bs_rndfile_fail(410, '파일이 저장소에 없습니다. 관리자에게 문의하세요.');
}

$mime   = (string)($f['mime'] ?? '');
$inline = bs_param_str('inline') === '1' && in_array($mime, BS_VIEWABLE_MIME, true);
$name   = (string)$f['orig_name'];
$ascii  = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'file';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
     . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
// PDF 는 예외 — Chrome 내장 뷰어는 sandbox·default-src 'none' 이 걸리면 그리지 않는다.
if (!($inline && $mime === 'application/pdf')) {
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
}
header('Cache-Control: private, no-store');

readfile($path);
exit;
