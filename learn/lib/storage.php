<?php
/**
 * 이수증 파일 저장.
 *
 * 이수증이 발급되지 않는 강의는 강의 사이트의 진행률 화면을 캡쳐한 이미지로 대신한다.
 * 서버는 둘을 구분하지 않는다 — 첨부가 한 장이라도 있으면 청구할 수 있다.
 */

const UPLOAD_DIR    = __DIR__ . '/../var/uploads';
const MAX_UPLOAD_MB = 50;

// HTML/SVG 는 같은 오리진에서 열리면 포털 세션을 노린 XSS 가 된다. 확장자 화이트리스트
// 밖은 아예 받지 않고, 받은 것도 learn_disposition 이 인라인 여부를 다시 판정한다.
const ALLOWED_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'pdf'];

const INLINE_TYPES = [
    'pdf'  => 'application/pdf',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'bmp'  => 'image/bmp',
];

function learn_upload_dir() {
    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0777, true);
    return realpath(UPLOAD_DIR) ?: UPLOAD_DIR;
}

function learn_ensure_allowed($filename) {
    $ext = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXT, true)) {
        throw new LearnError('이미지(png, jpg, gif, webp, bmp)와 pdf 만 올릴 수 있습니다', 422);
    }
    return $ext;
}

/** 저장 파일명은 서버가 만든다. 원본명은 DB 에만 둔다. */
function learn_save_upload($rid, array $file) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            throw new LearnError(MAX_UPLOAD_MB . 'MB 까지 올릴 수 있습니다', 413);
        }
        throw new LearnError('파일을 올리지 못했습니다', 400);
    }
    if ((int)$file['size'] > MAX_UPLOAD_MB * 1024 * 1024) {
        throw new LearnError(MAX_UPLOAD_MB . 'MB 까지 올릴 수 있습니다', 413);
    }

    $original = basename((string)($file['name'] ?? '이수증'));
    $ext      = learn_ensure_allowed($original);
    $stored   = $rid . '_' . bin2hex(random_bytes(16)) . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], learn_upload_dir() . '/' . $stored)) {
        throw new LearnError('파일을 저장하지 못했습니다', 500);
    }
    return [$original, $stored];
}

function learn_remove_upload($stored) {
    if (!$stored) return;
    $path = learn_upload_dir() . '/' . basename((string)$stored);
    if (is_file($path)) @unlink($path);
}

/** 경로 이탈 검사. DB 값이라도 그대로 붙이지 않는다. */
function learn_resolve_upload($stored) {
    if (!$stored) throw new LearnError('올라온 이수증이 없습니다', 404);
    $root = learn_upload_dir();
    $path = realpath($root . '/' . basename((string)$stored));
    if (!$path || strncmp($path, $root, strlen($root)) !== 0 || !is_file($path)) {
        throw new LearnError('파일을 찾을 수 없습니다', 404);
    }
    return $path;
}

/** 화이트리스트 밖은 강제로 내려받기 처리한다 */
function learn_disposition($name) {
    $ext    = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
    $inline = isset(INLINE_TYPES[$ext]);
    return [
        $inline ? INLINE_TYPES[$ext] : 'application/octet-stream',
        ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name),
    ];
}
