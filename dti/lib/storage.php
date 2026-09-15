<?php
/**
 * 발표자료·스캔 원본 파일. 저장 파일명은 서버가 만들고 원본명은 DB 에만 둔다.
 *
 * 업로드 확장자는 제한하지 않는다 — 발표자료는 PPT·문서까지 올라온다. 대신 내보낼 때
 * 화이트리스트 밖은 전부 내려받기로 바꾼다. HTML/SVG 가 같은 오리진에서 인라인으로 열리면
 * 포털 세션을 노린 XSS 가 되기 때문이다.
 */

const DTI_INLINE_TYPES = [
    'pdf' => 'application/pdf',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'bmp' => 'image/bmp',
    'txt' => 'text/plain',
];

function dti_upload_dir($dir) {
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    return realpath($dir) ?: $dir;
}

/**
 * 업로드 한 건을 저장하고 저장 파일명을 돌려준다.
 * $mover 는 임시파일을 옮기는 방법이다 — 운영은 move_uploaded_file 만 안전하고,
 * 테스트는 진짜 업로드가 아니라 이 자리를 갈아끼운다.
 */
function dti_save_upload($dir, $maxUploadMb, $topicId, array $file, $mover = null) {
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw dti_upload_too_large($maxUploadMb);
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new DtiError('파일을 올리지 못했습니다', 400);
    }
    if ((int)($file['size'] ?? 0) > $maxUploadMb * 1024 * 1024) {
        throw dti_upload_too_large($maxUploadMb);
    }

    $original = basename((string)($file['name'] ?? '자료'));
    $extension = pathinfo($original, PATHINFO_EXTENSION);
    $suffix = $extension === '' ? '' : '.' . substr($extension, 0, 16);
    $stored = $topicId . '_' . bin2hex(random_bytes(16)) . $suffix;

    $mover = $mover ?: static fn ($from, $to) => move_uploaded_file($from, $to);
    if (!$mover($file['tmp_name'] ?? '', dti_upload_dir($dir) . '/' . $stored)) {
        throw new DtiError('파일을 저장하지 못했습니다', 500);
    }
    return $stored;
}

function dti_remove_upload($dir, $stored) {
    if (!$stored) return;
    $path = dti_upload_dir($dir) . '/' . basename($stored);
    if (is_file($path)) @unlink($path);
}

/** 경로 이탈 검사. DB 값이라도 그대로 붙이지 않는다. */
function dti_resolve_upload($dir, $stored) {
    if (!$stored) throw new DtiError('올라온 파일이 없습니다', 404);

    $root = dti_upload_dir($dir);
    $path = realpath($root . '/' . basename($stored));
    if (!$path || !str_starts_with($path, $root) || !is_file($path)) {
        throw new DtiError('파일을 찾을 수 없습니다', 404);
    }
    return $path;
}

/** Content-Type 과 Content-Disposition */
function dti_disposition($name) {
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $inline = isset(DTI_INLINE_TYPES[$extension]);

    return [
        $inline ? DTI_INLINE_TYPES[$extension] : 'application/octet-stream',
        ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name),
    ];
}

function dti_upload_too_large($maxUploadMb) {
    return new DtiError($maxUploadMb . 'MB 까지 올릴 수 있습니다', 413);
}
