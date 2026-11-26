<?php
/**
 * 작업·조각·시도·노드 조회와 작업 등록.
 */

/**
 * 작업을 등록한다.
 * @param PDO $pdo
 * @param string $title
 * @param string $submitter 포털 이메일
 * @param string[] $repos
 * @param string $instructions
 * @return string job_key
 * @throws InvalidArgumentException 필수 값이 비었을 때
 */
function bcl_job_create($pdo, $title, $submitter, $repos, $instructions)
{
    foreach (['title' => $title, 'submitter' => $submitter, 'instructions' => $instructions] as $field => $value) {
        if (trim((string)$value) === '') throw new InvalidArgumentException('필수 값 없음: ' . $field);
    }
    if (!is_array($repos) || !$repos) throw new InvalidArgumentException('필수 값 없음: repos');

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO bcl_jobs (title, submitter, repos, instructions, created_at) VALUES (?, ?, ?, ?, ?)")
            ->execute([$title, $submitter, json_encode(array_values($repos), JSON_UNESCAPED_UNICODE), $instructions, time()]);
        $id = (int)$pdo->lastInsertId();
        $key = 'job-' . $id;
        $pdo->prepare("UPDATE bcl_jobs SET job_key = ? WHERE id = ?")->execute([$key, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $key;
}

/**
 * 작업 하나를 조각·시도와 함께 가져온다.
 * @param PDO $pdo
 * @param string $job_key
 * @return array|null
 */
function bcl_job_get($pdo, $job_key)
{
    $st = $pdo->prepare("SELECT * FROM bcl_jobs WHERE job_key = ?");
    $st->execute([$job_key]);
    $rows = $st->fetchAll();

    return $rows ? bcl_jobs_shape($pdo, $rows)[0] : null;
}

/**
 * 리더가 필요한 작업 목록.
 * @param PDO $pdo
 * @param int $now
 * @return array[]
 */
function bcl_jobs_open($pdo, $now)
{
    $st = $pdo->prepare("SELECT * FROM bcl_jobs
                         WHERE state <> 'done' AND (leader IS NULL OR lease_until <= ?) ORDER BY id");
    $st->execute([$now]);

    return bcl_jobs_shape($pdo, $st->fetchAll());
}

/**
 * 화면용 작업 목록. 최근에 등록한 것이 먼저다.
 * @param PDO $pdo
 * @param string $state open|done
 * @return array[]
 */
function bcl_jobs_list($pdo, $state)
{
    $st = $pdo->prepare("SELECT * FROM bcl_jobs WHERE state = ? ORDER BY id DESC");
    $st->execute([$state]);

    return bcl_jobs_shape($pdo, $st->fetchAll());
}

/**
 * 살아 있는 노드 목록.
 * @param PDO $pdo
 * @param int $now
 * @return array[]
 */
function bcl_nodes_live($pdo, $now)
{
    $st = $pdo->prepare("SELECT * FROM bcl_nodes WHERE last_seen >= ? ORDER BY node_id");
    $st->execute([$now - NODE_TTL_S]);

    return array_map(function ($row) {
        return [
            'node_id' => $row['node_id'],
            'account' => $row['account'],
            'addr' => $row['addr'],
            'free_cpu' => (float)$row['free_cpu'],
            'free_mem' => (int)$row['free_mem'],
            'running' => (int)$row['running'],
            'attempts' => json_decode($row['attempts'], true),
            'last_seen' => (int)$row['last_seen'],
        ];
    }, $st->fetchAll());
}

/**
 * 작업 행들을 규약의 job 모양으로 바꾼다.
 * @param PDO $pdo
 * @param array[] $jobs bcl_jobs 행
 * @return array[]
 */
function bcl_jobs_shape($pdo, $jobs)
{
    if (!$jobs) return [];

    $job_ids = array_column($jobs, 'id');
    $in = implode(',', array_fill(0, count($job_ids), '?'));
    $st = $pdo->prepare("SELECT * FROM bcl_pieces WHERE job_id IN ({$in}) ORDER BY id");
    $st->execute($job_ids);
    $piece_rows = $st->fetchAll();

    $attempts_by_piece = [];
    if ($piece_rows) {
        $piece_ids = array_column($piece_rows, 'id');
        $in = implode(',', array_fill(0, count($piece_ids), '?'));
        $st = $pdo->prepare("SELECT * FROM bcl_attempts WHERE piece_id IN ({$in}) ORDER BY piece_id, attempt_no");
        $st->execute($piece_ids);
        foreach ($st->fetchAll() as $row) {
            $attempts_by_piece[$row['piece_id']][] = [
                'attempt_no' => (int)$row['attempt_no'],
                'node_id' => $row['node_id'],
                'account' => $row['account'],
                'status' => $row['status'],
                'started' => (int)$row['started'],
                'ended' => $row['ended'] === null ? null : (int)$row['ended'],
                'result_path' => $row['result_path'],
                'patch_path' => $row['patch_path'],
                'log_path' => $row['log_path'],
                'redactions' => $row['redactions'] === null ? null : (json_decode($row['redactions'], true) ?: new stdClass()),
                'closed_by' => $row['closed_by'],
            ];
        }
    }

    $pieces_by_job = [];
    foreach ($piece_rows as $row) {
        $pieces_by_job[$row['job_id']][] = [
            'piece_id' => $row['piece_key'],
            'repo' => $row['repo'],
            'desc' => $row['piece_desc'],
            'state' => $row['state'],
            'attempts' => $attempts_by_piece[$row['id']] ?? [],
        ];
    }

    return array_map(function ($row) use ($pieces_by_job) {
        return [
            'job_id' => $row['job_key'],
            'title' => $row['title'],
            'submitter' => $row['submitter'],
            'repos' => json_decode($row['repos'], true),
            'instructions' => $row['instructions'],
            'state' => $row['state'],
            'leader' => $row['leader'],
            'epoch' => (int)$row['epoch'],
            'lease_until' => (int)$row['lease_until'],
            'summary' => $row['summary'],
            'pieces' => $pieces_by_job[$row['id']] ?? [],
        ];
    }, $jobs);
}
