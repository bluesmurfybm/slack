<?php
/** 출처 문서 업로드 처리 — 확장자·MIME 검증, 파일명 정리, 웹 접근 불가 경로 저장. */

declare(strict_types=1);

/**
 * 출처 문서 업로더.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 저장 규칙                                                         │
 * │   · 저장 경로는 웹에서 직접 못 여는 곳(BS_UPLOAD_DIR)             │
 * │   · 저장 파일명은 내용 해시 + 난수. 원본명은 DB 에만 둔다          │
 * │   · 확장자 화이트리스트 통과 후 finfo 로 실제 내용을 다시 확인     │
 * │                                                                  │
 * │ 파일명을 그대로 쓰지 않는 이유는 둘이다.                           │
 * │   1. 경로 조작(../)과 제어문자                                    │
 * │   2. 한글·공백 파일명이 서버마다 다르게 깨진다                     │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * BlueCart 의 Attachment 모델과 같은 얼개다(bluecart/includes/model/Attachment.php).
 * 그쪽은 난수만 쓰지만 여기서는 내용 해시를 앞에 붙여, 같은 파일을 두 번 올렸는지
 * 나중에 알아볼 수 있게 했다(중복 제거는 아직 하지 않는다).
 */
final class SourceUploader
{
    public function __construct(private ProjectRepo $projects) {}

    // =================================================================
    // 파일
    // =================================================================

    /**
     * $_FILES 의 다중 업로드 항목을 풀어 하나씩 저장한다.
     *
     * 한 건이 실패해도 나머지는 살린다 — 열 개 올렸는데 하나 때문에 전부
     * 되돌아가면 어느 것이 문제인지 알 수 없다. 실패는 모아서 돌려준다.
     *
     * @param array $files $_FILES['files'] (multiple 이라 값이 배열)
     * @return array{saved:array,failed:array}
     */
    public function storeMany(int $projectId, array $files, array $actor): array
    {
        $items = $this->normalizeFilesArray($files);

        if (count($items) > BS_UPLOAD_MAX_FILES) {
            throw new DomainException(
                sprintf('한 번에 최대 %d개까지 올릴 수 있습니다.', BS_UPLOAD_MAX_FILES)
            );
        }
        $this->assertRoom($projectId, count($items));

        $saved  = [];
        $failed = [];

        foreach ($items as $file) {
            // 파일 칸을 비워 보낸 항목은 조용히 건너뛴다(오류가 아니다).
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            try {
                $saved[] = $this->storeOne($projectId, $file, $actor);
            } catch (DomainException | RuntimeException $e) {
                $failed[] = [
                    'name'    => $this->displayName((string)($file['name'] ?? '')),
                    'message' => $e->getMessage(),
                ];
            }
        }

        if (!$saved && !$failed) {
            throw new DomainException('선택된 파일이 없습니다.');
        }

        return ['saved' => $saved, 'failed' => $failed];
    }

    /**
     * 파일 하나를 저장하고 bs_project_source 행을 만든다.
     * @return array 화면에 돌려줄 요약
     */
    public function storeOne(int $projectId, array $file, array $actor): array
    {
        $this->assertUploadOk($file);

        $origName = $this->sanitizeName((string)$file['name']);
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if ($ext === '' || !isset(BS_UPLOAD_MIME[$ext])) {
            throw new DomainException(sprintf(
                '허용되지 않는 형식입니다(.%s). 가능한 확장자: %s',
                $ext !== '' ? $ext : '없음',
                implode(', ', array_keys(BS_UPLOAD_MIME))
            ));
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            throw new DomainException('빈 파일은 올릴 수 없습니다.');
        }
        if ($size > BS_UPLOAD_MAX_BYTES) {
            throw new DomainException(sprintf(
                '파일이 너무 큽니다(%s). 최대 %s 까지 올릴 수 있습니다.',
                self::humanSize($size),
                self::humanSize(BS_UPLOAD_MAX_BYTES)
            ));
        }

        // 확장자만 믿지 않는다. 실제 내용을 보고 대조한다.
        $mime     = $this->detectMime((string)$file['tmp_name']);
        $expected = BS_UPLOAD_MIME[$ext];
        if (!in_array($mime, $expected, true)) {
            throw new DomainException(sprintf(
                '파일 내용이 확장자(.%s)와 맞지 않습니다. 감지된 형식: %s',
                $ext,
                $mime
            ));
        }

        $dir = $this->dirFor($projectId);
        $this->ensureDir($dir);

        // 저장명 = 내용 해시 앞 16자 + 난수 8자 + 확장자.
        // 해시만 쓰면 같은 파일을 두 번 올릴 때 덮어쓰므로 난수를 붙인다.
        $hash   = @hash_file('sha256', (string)$file['tmp_name']) ?: '';
        $stored = $dir . '/' . substr($hash, 0, 16) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

        $this->moveUploaded((string)$file['tmp_name'], $stored);
        @chmod($stored, 0640);

        try {
            $sourceId = $this->projects->addSource($projectId, [
                'kind'         => BS_EXT_KIND[$ext],
                'title'        => $origName,
                'file_path'    => $stored,
                'file_size'    => $size,
                'mime'         => $mime,
                // 파싱은 P2 에서 한다. 여기서는 대기 상태로만 남긴다.
                'parse_status' => 'pending',
            ], $actor);
        } catch (Throwable $e) {
            // DB 에 못 넣었으면 파일도 남기지 않는다. 남기면 아무도 모르는 쓰레기가 된다.
            @unlink($stored);
            throw $e;
        }

        return [
            'id'           => $sourceId,
            'kind'         => BS_EXT_KIND[$ext],
            'title'        => $origName,
            'file_size'    => $size,
            'size_label'   => self::humanSize($size),
            'mime'         => $mime,
            'parse_status' => 'pending',
        ];
    }

    // =================================================================
    // 링크 / 직접 입력
    // =================================================================

    /**
     * 링크 등록(피그마·드라이브 등).
     *
     * 링크는 내려받아 파싱하지 않는다. parse_status 를 'skip' 으로 둔다.
     * 피그마를 API 로 읽을지는 spec §11-7 에서 아직 안 정했다 — 정해지면
     * 그때 'pending' 으로 바꿔 파서가 집어 가게 한다.
     */
    public function storeLink(int $projectId, string $url, string $title, array $actor): array
    {
        $this->assertRoom($projectId, 1);

        $safe = bs_safe_url($url);
        if ($safe === null) {
            throw new InvalidArgumentException('http:// 또는 https:// 로 시작하는 주소만 등록할 수 있습니다.');
        }
        if (mb_strlen($safe) > 500) {
            throw new InvalidArgumentException('주소가 너무 깁니다. 500자 이내로 넣어 주세요.');
        }

        $kind  = $this->linkKind($safe);
        $title = trim($title);
        if ($title === '') {
            $title = $this->titleFromUrl($safe);
        }

        $sourceId = $this->projects->addSource($projectId, [
            'kind'         => $kind,
            'title'        => mb_substr($title, 0, 200),
            'url'          => $safe,
            'parse_status' => 'skip',
        ], $actor);

        return [
            'id'           => $sourceId,
            'kind'         => $kind,
            'title'        => mb_substr($title, 0, 200),
            'url'          => $safe,
            'parse_status' => 'skip',
        ];
    }

    /**
     * 직접 입력한 텍스트.
     *
     * 사람이 적어 넣은 글이 곧 추출 결과라 파싱할 것이 없다.
     * parsed_text 를 채우고 parse_status 를 'ok' 로 둔다 — 'pending' 으로 두면
     * P2 파서가 할 일 없는 건을 계속 집어 간다.
     */
    public function storeText(int $projectId, string $text, string $title, array $actor): array
    {
        $this->assertRoom($projectId, 1);

        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('내용을 입력하세요.');
        }
        // MEDIUMTEXT 상한(16MB)보다 훨씬 앞에서 막는다. 사람이 붙여넣는 양의 한계.
        if (mb_strlen($text) > 200000) {
            throw new InvalidArgumentException('내용이 너무 깁니다. 20만 자 이내로 넣어 주세요.');
        }

        $title = trim($title);
        if ($title === '') {
            // 첫 줄을 제목으로 삼는다.
            $title = mb_substr(trim(strtok($text, "\n") ?: '직접 입력'), 0, 200);
        }

        $sourceId = $this->projects->addSource($projectId, [
            'kind'         => 'text',
            'title'        => mb_substr($title, 0, 200),
            'parsed_text'  => $text,
            'parse_status' => 'ok',
        ], $actor);

        return [
            'id'           => $sourceId,
            'kind'         => 'text',
            'title'        => mb_substr($title, 0, 200),
            'parsed_len'   => mb_strlen($text),
            'parse_status' => 'ok',
        ];
    }

    // =================================================================
    // 내부
    // =================================================================

    /** 프로젝트당 출처 문서 수 상한. */
    private function assertRoom(int $projectId, int $adding): void
    {
        $have = $this->projects->countSources($projectId);
        if ($have + $adding > BS_SOURCE_MAX_PER_PROJECT) {
            throw new DomainException(sprintf(
                '출처 문서는 프로젝트당 최대 %d개입니다. (현재 %d개)',
                BS_SOURCE_MAX_PER_PROJECT,
                $have
            ));
        }
    }

    /**
     * $_FILES 의 multiple 형식을 건별 배열로 편다.
     *
     * PHP 는 multiple 업로드를 ['name'=>[...], 'size'=>[...]] 처럼 컬럼 방향으로
     * 준다. 그대로 쓰면 건별 검증을 할 수 없어 행 방향으로 뒤집는다.
     */
    private function normalizeFilesArray(array $f): array
    {
        if (!isset($f['name'])) {
            return [];
        }
        if (!is_array($f['name'])) {
            return [$f];    // 단일 업로드
        }

        $out = [];
        foreach (array_keys($f['name']) as $i) {
            $out[] = [
                'name'     => $f['name'][$i]     ?? '',
                'type'     => $f['type'][$i]     ?? '',
                'tmp_name' => $f['tmp_name'][$i] ?? '',
                'error'    => $f['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
                'size'     => $f['size'][$i]     ?? 0,
            ];
        }
        return $out;
    }

    /** 프로젝트별 하위 폴더. 한 폴더에 파일이 수천 개 쌓이지 않게 나눈다. */
    private function dirFor(int $projectId): string
    {
        return rtrim(BS_UPLOAD_DIR, '/\\') . '/' . date('Y') . '/' . $projectId;
    }

    private function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('업로드 디렉터리를 만들지 못했습니다: ' . $dir);
        }
    }

    /**
     * 경로 조작과 제어문자를 걷어낸다.
     * 여기서 돌려주는 값은 **DB 에 남길 원본명**이지 저장 파일명이 아니다.
     */
    private function sanitizeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name, " .\t");
        if ($name === '') {
            throw new DomainException('파일 이름이 올바르지 않습니다.');
        }
        return mb_substr($name, 0, 200);
    }

    /** 오류 메시지에 쓸 이름. sanitize 가 실패해도 예외를 던지지 않는다. */
    private function displayName(string $name): string
    {
        $n = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $name))) ?? '';
        return $n !== '' ? mb_substr($n, 0, 80) : '(이름 없음)';
    }

    /**
     * 파일 내용으로 MIME 을 판정한다. 못 알아보면 application/octet-stream.
     *
     * finfo_file 은 경고를 낸다(윈도에서 아주 작은 파일이나 특이한 내용일 때
     * "Failed to open stream: Invalid argument" 가 뜨는 것을 확인했다).
     * 경고가 그대로 출력되면 **JSON 응답 앞에 섞여 본문이 깨진다** — 화면은
     * "서버 응답을 읽지 못했습니다" 만 보게 되고 진짜 이유를 알 수 없다.
     * 판정 실패는 아래에서 정상적으로 다루므로 출력만 막는다.
     */
    private function detectMime(string $path): string
    {
        if (!function_exists('finfo_open')) {
            return 'application/octet-stream';
        }
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if (!$fi) {
            return 'application/octet-stream';
        }
        // finfo_close() 는 PHP 8.3 에서 deprecated 다(finfo 가 객체라 GC 가 정리한다).
        // BlueCart 는 아직 부르지만 여기서는 쓰지 않는다.
        $mime = @finfo_file($fi, $path);

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    private function moveUploaded(string $tmp, string $dest): void
    {
        if (move_uploaded_file($tmp, $dest)) {
            return;
        }
        // CLI 테스트에서는 move_uploaded_file 이 동작하지 않는다(BlueCart 도 같은 처리).
        if (PHP_SAPI === 'cli' && @rename($tmp, $dest)) {
            return;
        }
        throw new RuntimeException('파일을 저장하지 못했습니다.');
    }

    private function assertUploadOk(array $file): void
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
            UPLOAD_ERR_EXTENSION  => 'PHP 확장이 업로드를 막았습니다.',
            default               => '업로드에 실패했습니다.',
        });
    }

    /** 주소를 보고 피그마인지 일반 링크인지 가른다. */
    private function linkKind(string $url): string
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        return str_contains($host, 'figma.com') ? 'figma' : 'url';
    }

    private function titleFromUrl(string $url): string
    {
        $host = (string)parse_url($url, PHP_URL_HOST);
        $path = (string)parse_url($url, PHP_URL_PATH);
        $last = basename($path);
        return $last !== '' ? $host . ' / ' . rawurldecode($last) : ($host ?: $url);
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
