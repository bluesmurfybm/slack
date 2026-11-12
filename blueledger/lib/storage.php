<?php
/**
 * 영수증 파일 저장·열람.
 *
 *  파일은 var/receipts 아래에 서버가 지은 이름으로 둔다. 문서루트 아래이므로 nginx 에서
 *  /blueledger/var/ 를 막아야 한다(README 참고). 열람은 반드시 api.php 를 거쳐 로그인과
 *  권한을 확인한 뒤 내보낸다.
 *  사진은 화면이 미리 1800px·JPEG 로 줄여서 보낸다 — 서버에 GD 가 없어도 된다.
 */

const RECEIPT_DIR   = __DIR__ . '/../var/receipts';
const MAX_UPLOAD_MB = 20;

// HTML/SVG 는 같은 오리진에서 열리면 포털 세션을 노린 XSS 가 된다. 이 다섯 가지만 받는다.
const RECEIPT_TYPES = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf',
];

function bl_receipt_dir() {
    if (!is_dir(RECEIPT_DIR)) @mkdir(RECEIPT_DIR, 0775, true);
    return realpath(RECEIPT_DIR) ?: RECEIPT_DIR;
}

/** multipart 'file' 하나를 받아 저장하고 화면에 줄 {id,type,name} 을 돌려준다 */
function bl_upload_receipt(PDO $pdo, array $me) {
    $file = $_FILES['file'] ?? null;
    if (!$file) {
        // post_max_size 를 넘으면 PHP 가 본문을 통째로 버려 $_FILES 가 빈다
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) throw new BlError('파일이 너무 큽니다 (' . MAX_UPLOAD_MB . 'MB 이하)', 413);
        throw new BlError('올릴 파일이 없습니다', 400);
    }
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new BlError('파일이 너무 큽니다 (' . MAX_UPLOAD_MB . 'MB 이하)', 413);
    if ($err !== UPLOAD_ERR_OK) throw new BlError('파일을 올리지 못했습니다', 400);
    if ((int)$file['size'] > MAX_UPLOAD_MB * 1024 * 1024) throw new BlError('파일이 너무 큽니다 (' . MAX_UPLOAD_MB . 'MB 이하)', 413);

    $mime = bl_sniff_mime($file['tmp_name']);
    if (!isset(RECEIPT_TYPES[$mime])) throw new BlError('사진(JPG·PNG) 또는 PDF 파일만 올릴 수 있습니다', 400);

    $id = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . RECEIPT_TYPES[$mime];
    $dir = bl_receipt_dir_writable();
    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $id)) {
        bl_receipt_log('파일 옮기기 실패', $dir);
        throw new BlError(RECEIPT_DIR_HELP, 500);
    }

    $name = clip(basename((string)($file['name'] ?? '영수증')), 120);
    try {
        $pdo->prepare("INSERT INTO bl_travel_files (id, orig_name, mime, size, uploaded_by, created_at) VALUES (?,?,?,?,?,?)")
            ->execute([$id, $name, $mime, (int)$file['size'], $me['email'], now_stamp()]);
    } catch (Throwable $e) {
        @unlink($dir . '/' . $id);
        error_log('[blueledger] 영수증 DB 기록 실패: ' . $e->getMessage());
        throw new BlError('영수증 파일은 받았지만 DB(bl_travel_files 표)에 기록하지 못했습니다. 서버 관리자에게 알려 주세요', 500);
    }
    return ['id' => $id, 'type' => $mime, 'name' => $name];
}

// 운영 서버에서 처음 올릴 때 폴더가 없거나 웹 서버 계정이 쓸 수 없으면 '파일을 저장하지 못했습니다' 한 줄만 보여
// 원인을 알 수 없었다(2026-11-05). 화면에는 할 일을, 서버 오류 기록에는 경로·계정을 남긴다.
const RECEIPT_DIR_HELP = '영수증 저장 폴더(blueledger/var/receipts)에 쓸 수 없습니다. 서버 관리자에게 이 폴더를 웹 서버 계정이 쓸 수 있게 해 달라고 알려 주세요';

/** 올리기 전용 — 폴더를 만들고 쓸 수 있는지 확인한다. 안 되면 원인을 남기고 멈춘다 */
function bl_receipt_dir_writable() {
    $dir = bl_receipt_dir();
    if (!is_dir($dir) || !is_writable($dir)) {
        bl_receipt_log(is_dir($dir) ? '폴더에 쓸 수 없음' : '폴더를 만들 수 없음', $dir);
        throw new BlError(RECEIPT_DIR_HELP, 500);
    }
    return $dir;
}

function bl_receipt_log($why, $dir) {
    $who = function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : get_current_user();
    error_log("[blueledger] 영수증 저장 실패 — $why: $dir (PHP 실행 계정: $who)");
}

/** 브라우저가 붙여 보낸 Content-Type 은 믿지 않고 파일 첫 바이트로 판정한다 */
function bl_sniff_mime($path) {
    $head = (string)@file_get_contents($path, false, null, 0, 16);
    if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) return 'image/jpeg';
    if (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) return 'image/png';
    if (strncmp($head, 'GIF8', 4) === 0) return 'image/gif';
    if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') return 'image/webp';
    if (strncmp($head, '%PDF', 4) === 0) return 'application/pdf';
    return '';
}

/**
 * 열람 권한: 정산 담당·대표는 전부, 직원은 본인이 올렸거나 본인 청구에 붙은 것만.
 */
function bl_send_receipt(PDO $pdo, array $me, $id) {
    $id = basename((string)$id);
    $st = $pdo->prepare("SELECT * FROM bl_travel_files WHERE id=?");
    $st->execute([$id]);
    $f = $st->fetch();
    if (!$f) throw new BlError('영수증 파일을 찾을 수 없습니다', 404);

    if (!bl_can_see_all($me) && $f['uploaded_by'] !== $me['email']) {
        $trip = $f['trip_id'] ? bl_fetch_trip($pdo, $f['trip_id']) : null;
        if (!$trip || !bl_is_mine($trip, $me)) throw new BlError('권한이 없습니다', 403);
    }

    $root = bl_receipt_dir();
    $path = realpath($root . '/' . $id);
    if (!$path || strncmp($path, $root, strlen($root)) !== 0 || !is_file($path)) {
        throw new BlError('영수증 파일을 찾을 수 없습니다', 404);
    }
    header('Content-Type: ' . $f['mime']);
    header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($f['orig_name'] ?: $id));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
