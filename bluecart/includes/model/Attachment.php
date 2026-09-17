<?php
declare(strict_types=1);

/**
 * 요청에 붙는 첨부파일(견적서, 제품 사진, 영수증 등).
 *
 * 저장 위치는 웹 루트 바깥(config app.upload_dir)이고, 파일명은 난수로
 * 바꿔 저장합니다. 원본 이름은 DB에만 두고 내려받을 때 복원합니다.
 * 업로드 경로로 직접 접근하는 길이 없어야 실행 가능한 파일이 올라와도
 * 웹에서 실행되지 않습니다.
 */
final class Attachment
{
    /** 확장자와 실제 내용이 맞는지 확인할 때 쓰는 대응표. */
    private const MIME_BY_EXT = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'pdf'  => ['application/pdf'],
        'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'xls'  => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2'],
        'docx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'doc'  => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
        'hwp'  => ['application/x-hwp', 'application/haansofthwp', 'application/x-ole-storage', 'application/CDFV2', 'application/zip'],
        'txt'  => ['text/plain'],
    ];

    public static function listFor(int $requestId): array
    {
        return bc_fetch_all(
            'SELECT id, request_id, orig_name, mime_type, file_size, uploader_id, created_at
               FROM bc_attachment WHERE request_id = ? ORDER BY id ASC',
            [$requestId]
        );
    }

    public static function find(int $id): ?array
    {
        return bc_fetch_one('SELECT * FROM bc_attachment WHERE id = ?', [$id]);
    }

    public static function countFor(int $requestId): int
    {
        return (int)bc_fetch_value('SELECT COUNT(*) FROM bc_attachment WHERE request_id = ?', [$requestId], 0);
    }

    /**
     * 누가 파일을 올리거나 지울 수 있는가.
     *   - 요청자: 검토 대기 / 반려 상태에서만 (심사 중인 내용이 바뀌면 곤란)
     *   - 구매담당자·검토승인자: 종료되지 않은 건이면 언제든 (영수증, 견적서)
     *   - 관리자: 항상
     */
    public static function canModify(array $request, array $user): bool
    {
        $roles = bc_roles_of($user['id']);
        if (in_array('ADMIN', $roles, true)) {
            return true;
        }
        if (in_array($request['status'], ['CANCELED'], true)) {
            return false;
        }
        if ($request['requester_id'] === $user['id']) {
            return in_array($request['status'], ['REQUESTED', 'REJECTED'], true);
        }
        if (array_intersect($roles, ['REVIEWER', 'BUYER'])) {
            return $request['status'] !== 'STOCKED';
        }
        return false;
    }

    /**
     * $_FILES 항목 하나를 저장한다.
     *
     * @param array $file $_FILES['...'] 형식
     * @return int 첨부 id
     */
    public static function store(int $requestId, array $file, array $user): int
    {
        self::assertUploadOk($file);

        $origName = self::sanitizeName((string)$file['name']);
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $allowed  = array_map('strtolower', (array)bc_config('app.upload_ext', []));

        if ($ext === '' || !in_array($ext, $allowed, true)) {
            throw new DomainException(
                '허용되지 않는 형식입니다. 가능한 확장자: ' . implode(', ', $allowed)
            );
        }

        $maxSize = (int)bc_config('app.upload_max', 10 * 1024 * 1024);
        if ($file['size'] > $maxSize) {
            throw new DomainException(
                sprintf('파일이 너무 큽니다. 최대 %dMB 까지 올릴 수 있습니다.', (int)round($maxSize / 1048576))
            );
        }
        if ($file['size'] <= 0) {
            throw new DomainException('빈 파일은 올릴 수 없습니다.');
        }

        // 확장자만 믿지 않고 실제 내용을 확인한다.
        $mime = self::detectMime($file['tmp_name']);
        $expected = self::MIME_BY_EXT[$ext] ?? [];
        if ($expected && !in_array($mime, $expected, true)) {
            throw new DomainException(
                sprintf('파일 내용이 확장자(.%s)와 맞지 않습니다. (감지된 형식: %s)', $ext, $mime)
            );
        }

        $dir = self::dirFor($requestId);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('업로드 디렉터리를 만들지 못했습니다: ' . $dir);
        }

        $stored = $dir . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $stored)) {
            // CLI 테스트에서는 move_uploaded_file 이 동작하지 않으므로 rename 으로 대체
            if (PHP_SAPI !== 'cli' || !@rename($file['tmp_name'], $stored)) {
                throw new RuntimeException('파일을 저장하지 못했습니다.');
            }
        }
        @chmod($stored, 0640);

        bc_query(
            'INSERT INTO bc_attachment (request_id, orig_name, stored_path, mime_type, file_size, uploader_id)
             VALUES (?,?,?,?,?,?)',
            [$requestId, $origName, $stored, $mime, (int)$file['size'], $user['id']]
        );
        return (int)bc_db()->lastInsertId();
    }

    public static function delete(int $id): void
    {
        $row = self::find($id);
        if (!$row) {
            throw new DomainException('첨부파일을 찾을 수 없습니다.');
        }
        bc_query('DELETE FROM bc_attachment WHERE id = ?', [$id]);
        if (is_file($row['stored_path'])) {
            @unlink($row['stored_path']);
        }
    }

    // -----------------------------------------------------------------

    private static function dirFor(int $requestId): string
    {
        $base = rtrim((string)bc_config('app.upload_dir'), '/');
        if ($base === '') {
            throw new RuntimeException('app.upload_dir 이 설정되어 있지 않습니다.');
        }
        return $base . '/' . date('Y') . '/' . $requestId;
    }

    /** 경로 조작과 제어문자를 걷어낸다. */
    private static function sanitizeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name, " .\t");
        if ($name === '') {
            throw new DomainException('파일 이름이 올바르지 않습니다.');
        }
        return mb_substr($name, 0, 200);
    }

    private static function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $mime = finfo_file($fi, $path);
                finfo_close($fi);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }
        return 'application/octet-stream';
    }

    private static function assertUploadOk(array $file): void
    {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_OK) {
            return;
        }
        throw new DomainException(match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                '파일이 서버 허용 크기를 넘었습니다. php.ini 의 upload_max_filesize 를 확인하세요.',
            UPLOAD_ERR_PARTIAL    => '파일이 일부만 전송되었습니다. 다시 시도하세요.',
            UPLOAD_ERR_NO_FILE    => '선택된 파일이 없습니다.',
            UPLOAD_ERR_NO_TMP_DIR => '서버에 임시 디렉터리가 없습니다.',
            UPLOAD_ERR_CANT_WRITE => '서버에 파일을 쓰지 못했습니다.',
            default               => '업로드에 실패했습니다.',
        });
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . 'B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024) . 'KB';
        }
        return round($bytes / 1048576, 1) . 'MB';
    }
}
