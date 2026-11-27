<?php
/**
 * 시도 결과 원본 파일.
 */

/**
 * 시도 결과를 쌓는 폴더.
 * @param array|null $portal 포털 설정
 * @return string
 */
function bcl_files_dir($portal = null)
{
    $portal = $portal ?? require __DIR__ . '/../../config.php';
    return rtrim($portal['bluecluster']['files_dir'] ?? BCL_FILES_DIR_DEFAULT, '/');
}

/**
 * 결과 폴더를 준비한다.
 * @param string $dir
 * @return bool 쓸 수 있으면 true
 */
function bcl_files_prepare_dir($dir)
{
    @mkdir($dir, 0775, true);
    return is_dir($dir) && is_writable($dir);
}

/**
 * 시도 결과 원본을 저장한다.
 * @param string $files_dir
 * @param string $job_key
 * @param string $piece_key
 * @param int $attempt_no
 * @param string $result
 * @param string $patch
 * @param string $log_json
 * @return string[] ['result_path' => 경로, 'patch_path' => 경로, 'log_path' => 경로]
 * @throws BclApiError 폴더나 파일에 쓸 수 없을 때
 */
function bcl_files_save_attempt($files_dir, $job_key, $piece_key, $attempt_no, $result, $patch, $log_json)
{
    $dir = $files_dir . '/' . $job_key;
    if (!bcl_files_prepare_dir($dir)) {
        error_log('[bluecluster] 결과 폴더에 쓸 수 없음: ' . $dir);
        throw new BclApiError(500, '결과 파일을 저장하지 못했습니다');
    }

    $stem = "{$dir}/{$piece_key}-{$attempt_no}";
    $files = [
        'result_path' => [$stem . '.result.txt', $result],
        'patch_path' => [$stem . '.patch', $patch],
        'log_path' => [$stem . '.log.json', $log_json],
    ];
    foreach ($files as [$path, $bytes]) {
        if (@file_put_contents($path, $bytes) !== strlen($bytes)) {
            error_log('[bluecluster] 결과 파일을 쓰지 못함: ' . $path);
            throw new BclApiError(500, '결과 파일을 저장하지 못했습니다');
        }
    }

    return array_map(fn($file) => $file[0], $files);
}
