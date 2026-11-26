<?php
/**
 * 화면이 쓰는 조회·등록·내려받기 로직.
 */

/** 내려받기 종류와 시도 열·파일 확장자 */
const BCL_DOWNLOAD_KINDS = [
    'result' => ['column' => 'result_path', 'ext' => 'result.txt', 'label' => '결과'],
    'patch' => ['column' => 'patch_path', 'ext' => 'patch', 'label' => '패치'],
    'log' => ['column' => 'log_path', 'ext' => 'log.json', 'label' => '대화 기록'],
];

/**
 * 목록 탭 값.
 * @param string|null $raw
 * @return string open|done
 */
function bcl_page_tab($raw)
{
    return $raw === 'done' ? 'done' : 'open';
}

/**
 * 시도 상태의 한글 이름.
 * @param string $status
 * @return string
 */
function bcl_page_status_label($status)
{
    $labels = ['running' => '실행 중', 'done' => '완료', 'stopped_by_owner' => '주인 중지', 'lost' => '유실'];

    return $labels[$status] ?? $status;
}

/**
 * unix 시각을 화면 표기로 바꾼다.
 * @param int|null $ts
 * @return string
 */
function bcl_page_time($ts)
{
    if ($ts === null) return '-';

    return (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i:s');
}

/**
 * 가린 횟수를 화면 문구로 바꾼다.
 * @param array|stdClass|null $redactions 종류별 횟수
 * @return string
 */
function bcl_page_redactions($redactions)
{
    $counts = (array)$redactions;
    if (!$counts) return '가림 없음';

    return implode(', ', array_map(fn($kind, $n) => "{$kind} {$n}", array_keys($counts), $counts));
}

/**
 * 작업을 등록한다.
 * @param PDO $pdo
 * @param string $submitter 로그인 사용자 이메일
 * @param array $input title, repos(줄마다 하나), instructions
 * @return array ['job_key' => string|null, 'errors' => string[]]
 */
function bcl_page_submit($pdo, $submitter, $input)
{
    $title = trim((string)($input['title'] ?? ''));
    $instructions = trim((string)($input['instructions'] ?? ''));
    $repos = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($input['repos'] ?? '')))));

    $errors = [];
    if ($title === '') $errors[] = '제목을 입력하세요.';
    if (!$repos) $errors[] = '저장소를 한 줄에 하나씩 입력하세요.';
    if ($instructions === '') $errors[] = '지시를 입력하세요.';
    if ($errors) return ['job_key' => null, 'errors' => $errors];

    return ['job_key' => bcl_job_create($pdo, $title, $submitter, $repos, $instructions), 'errors' => []];
}

/**
 * 탭에 맞는 작업 목록. 등록·완료 시각을 더한다.
 * @param PDO $pdo
 * @param string $state open|done
 * @return array[]
 */
function bcl_page_jobs($pdo, $state)
{
    $jobs = bcl_jobs_list($pdo, $state);
    $times = bcl_page_job_times($pdo, array_column($jobs, 'job_id'));

    return array_map(fn($job) => $job + $times[$job['job_id']], $jobs);
}

/**
 * 상세 화면용 작업 하나.
 * @param PDO $pdo
 * @param string $job_key
 * @param string|null $files_dir 시도 결과 폴더
 * @return array|null
 */
function bcl_page_job($pdo, $job_key, $files_dir = null)
{
    $job = bcl_job_get($pdo, $job_key);
    if (!$job) return null;

    $st = $pdo->prepare("SELECT a.id, p.piece_key, a.attempt_no, a.token_user
                         FROM bcl_attempts a JOIN bcl_pieces p ON p.id = a.piece_id JOIN bcl_jobs j ON j.id = p.job_id
                         WHERE j.job_key = ?");
    $st->execute([$job_key]);
    $extra = [];
    foreach ($st->fetchAll() as $row) {
        $extra[$row['piece_key']][(int)$row['attempt_no']] = ['attempt_id' => (int)$row['id'], 'token_user' => $row['token_user']];
    }

    foreach ($job['pieces'] as &$piece) {
        foreach ($piece['attempts'] as &$attempt) {
            $attempt += $extra[$piece['piece_id']][$attempt['attempt_no']];
            $attempt['files'] = array_values(array_filter(
                array_keys(BCL_DOWNLOAD_KINDS),
                fn($kind) => bcl_page_download_path($pdo, $attempt['attempt_id'], $kind, $files_dir) !== null
            ));
        }
        unset($attempt);
    }
    unset($piece);

    return $job + bcl_page_job_times($pdo, [$job_key])[$job_key];
}

/**
 * 작업별 등록·완료 시각.
 * @param PDO $pdo
 * @param string[] $job_keys
 * @return array[] job_key => ['created_at' => int, 'finished_at' => int|null]
 */
function bcl_page_job_times($pdo, $job_keys)
{
    if (!$job_keys) return [];

    $in = implode(',', array_fill(0, count($job_keys), '?'));
    $st = $pdo->prepare("SELECT job_key, created_at, finished_at FROM bcl_jobs WHERE job_key IN ({$in})");
    $st->execute(array_values($job_keys));
    $times = [];
    foreach ($st->fetchAll() as $row) {
        $times[$row['job_key']] = [
            'created_at' => (int)$row['created_at'],
            'finished_at' => $row['finished_at'] === null ? null : (int)$row['finished_at'],
        ];
    }

    return $times;
}

/**
 * 내려받아도 되는 파일의 실제 경로.
 * @param PDO $pdo
 * @param int $attempt_id bcl_attempts.id
 * @param string $kind result|patch|log
 * @param string|null $files_dir 시도 결과 폴더
 * @return string|null 실제 경로
 */
function bcl_page_download_path($pdo, $attempt_id, $kind, $files_dir = null)
{
    if (!isset(BCL_DOWNLOAD_KINDS[$kind])) return null;

    $column = BCL_DOWNLOAD_KINDS[$kind]['column'];
    $st = $pdo->prepare("SELECT {$column} FROM bcl_attempts WHERE id = ?");
    $st->execute([$attempt_id]);
    $stored = $st->fetchColumn();
    if (!$stored) return null;

    $base = realpath($files_dir ?? bcl_files_dir());
    $path = realpath($stored);
    if ($base === false || $path === false) return null;

    return strpos($path, $base . DIRECTORY_SEPARATOR) === 0 && is_file($path) ? $path : null;
}

/**
 * 내려받을 때 쓰는 파일 이름.
 * @param PDO $pdo
 * @param int $attempt_id bcl_attempts.id
 * @param string $kind result|patch|log
 * @return string|null 알 수 없는 시도·종류면 null
 */
function bcl_page_download_name($pdo, $attempt_id, $kind)
{
    if (!isset(BCL_DOWNLOAD_KINDS[$kind])) return null;

    $st = $pdo->prepare("SELECT j.job_key, p.piece_key, a.attempt_no
                         FROM bcl_attempts a JOIN bcl_pieces p ON p.id = a.piece_id JOIN bcl_jobs j ON j.id = p.job_id
                         WHERE a.id = ?");
    $st->execute([$attempt_id]);
    $row = $st->fetch();
    if (!$row) return null;

    $stem = preg_replace('/[^A-Za-z0-9._-]/', '_', "{$row['job_key']}-{$row['piece_key']}-{$row['attempt_no']}");

    return $stem . '.' . BCL_DOWNLOAD_KINDS[$kind]['ext'];
}
