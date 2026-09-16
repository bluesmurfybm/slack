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

/* 키노트(.key)는 LibreOffice 가 변환하지 못해 넣지 않는다. */
const DTI_PDF_CONVERTIBLE = ['pptx', 'ppt', 'odp'];

function dti_pdf_convertible(string $name): bool {
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), DTI_PDF_CONVERTIBLE, true);
}

function dti_stored_name(int $topicId, string $original): string {
    $extension = pathinfo(basename($original), PATHINFO_EXTENSION);
    $suffix = $extension === '' ? '' : '.' . substr($extension, 0, 16);

    return $topicId . '_' . bin2hex(random_bytes(16)) . $suffix;
}

function dti_upload_dir(string $dir): string {
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    return realpath($dir) ?: $dir;
}

/**
 * 업로드 한 건을 저장하고 저장 파일명을 돌려준다.
 * $mover 는 임시파일을 옮기는 방법이다 — 운영은 move_uploaded_file 만 안전하고,
 * 테스트는 진짜 업로드가 아니라 이 자리를 갈아끼운다.
 */
function dti_save_upload(string $dir, int $maxUploadMb, int $topicId, array $file,
                         ?callable $mover = null): string {
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

    $stored = dti_stored_name($topicId, (string)($file['name'] ?? '자료'));

    $mover = $mover ?: static fn ($from, $to) => move_uploaded_file($from, $to);
    if (!$mover($file['tmp_name'] ?? '', dti_upload_dir($dir) . '/' . $stored)) {
        throw new DtiError('파일을 저장하지 못했습니다', 500);
    }
    return $stored;
}

/**
 * soffice 는 쓸 수 있는 홈이 없으면 프로필을 만들다 실패하고, 같은 프로필을 공유하는
 * 인스턴스끼리는 서로를 막는다. 그래서 -env:UserInstallation 을 호출마다 새로 준다.
 */
function dti_soffice_command(string $soffice, string $source, string $outDir, int $timeoutSec): string {
    $profile = sys_get_temp_dir() . '/dti-soffice-' . bin2hex(random_bytes(8));

    return 'timeout ' . $timeoutSec . ' ' . escapeshellarg($soffice)
        . ' --headless --norestore'
        . ' -env:UserInstallation=file://' . escapeshellarg($profile)
        . ' --convert-to pdf --outdir ' . escapeshellarg($outDir)
        . ' ' . escapeshellarg($source) . ' 2>&1';
}

/**
 * 올라온 발표자료를 PDF 로 바꿔 별개 자료로 넣을 값을 돌려준다.
 * 변환이 안 되면 null 이다 — PPT 업로드 자체는 성공시켜야 한다.
 */
function dti_pdf_companion(string $dir, int $topicId, string $original, string $stored,
                           callable $converter): ?array {
    if (!dti_pdf_convertible($original)) {
        return null;
    }

    $root = dti_upload_dir($dir);
    $name = pathinfo(basename($original), PATHINFO_FILENAME) . '.pdf';
    $path = dti_stored_name($topicId, $name);
    $out = $root . '/' . $path;
    try {
        $converter($root . '/' . basename($stored), $out);
    } catch (Throwable $e) {
        /* soffice 가 없거나 죽는다. */
    }
    /* soffice 는 변환에 실패해도 종료코드 0 으로 끝나는 경우가 있다. */
    if (!is_file($out)) {
        return null;
    }

    return ['name' => $name, 'path' => $path];
}

/* soffice 는 원본 확장자를 떼고 .pdf 를 붙인 이름으로 내놓는다. */
function dti_soffice_converter(string $soffice, int $timeoutSec = 60, ?callable $run = null): callable {
    $run = $run ?: static fn (string $cmd) => exec($cmd);

    return static function (string $source, string $out) use ($soffice, $timeoutSec, $run): void {
        $work = sys_get_temp_dir() . '/dti-convert-' . bin2hex(random_bytes(8));
        mkdir($work, 0777, true);
        try {
            $run(dti_soffice_command($soffice, $source, $work, $timeoutSec));
            $produced = $work . '/' . pathinfo($source, PATHINFO_FILENAME) . '.pdf';
            if (is_file($produced)) rename($produced, $out);
        } finally {
            foreach (glob($work . '/*') ?: [] as $leftover) {
                @unlink($leftover);
            }
            @rmdir($work);
        }
    };
}

function dti_remove_upload(string $dir, ?string $stored): void {
    if (!$stored) return;
    $path = dti_upload_dir($dir) . '/' . basename($stored);
    if (is_file($path)) @unlink($path);
}

/** 경로 이탈 검사. DB 값이라도 그대로 붙이지 않는다. */
function dti_resolve_upload(string $dir, ?string $stored): string {
    if (!$stored) throw new DtiError('올라온 파일이 없습니다', 404);

    $root = dti_upload_dir($dir);
    $path = realpath($root . '/' . basename($stored));
    if (!$path || !str_starts_with($path, $root) || !is_file($path)) {
        throw new DtiError('파일을 찾을 수 없습니다', 404);
    }
    return $path;
}

/** Content-Type 과 Content-Disposition */
function dti_disposition(string $name): array {
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $inline = isset(DTI_INLINE_TYPES[$extension]);

    return [
        $inline ? DTI_INLINE_TYPES[$extension] : 'application/octet-stream',
        ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name),
    ];
}

function dti_upload_too_large(int $maxUploadMb): DtiError {
    return new DtiError($maxUploadMb . 'MB 까지 올릴 수 있습니다', 413);
}
