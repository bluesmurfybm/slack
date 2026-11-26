<?php
/**
 * 리더·조각·시도·노드 상태 바꾸기.
 */

/**
 * 작업 행을 잠근 트랜잭션 안에서 $fn 을 실행한다.
 * @param PDO $pdo
 * @param string $job_key
 * @param callable $fn function (array $job) 작업 행을 받아 응답을 돌려준다
 * @return array
 * @throws BclApiError 등록되지 않은 작업
 */
function bcl_with_job_lock($pdo, $job_key, $fn)
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM bcl_jobs WHERE job_key = ? FOR UPDATE");
        $st->execute([$job_key]);
        $job = $st->fetch();
        if (!$job) throw new BclApiError(404, '등록되지 않은 작업');
        $out = $fn($job);
        $pdo->commit();
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * 요청자가 작업의 현재 리더인가.
 * @param array $job bcl_jobs 행
 * @param mixed $node_id
 * @param mixed $epoch
 * @return bool
 */
function bcl_is_leader($job, $node_id, $epoch)
{
    return $job['leader'] !== null && $job['leader'] === $node_id && $epoch === (int)$job['epoch'];
}

/**
 * @param PDO $pdo
 * @param array $job bcl_jobs 행
 * @param string $piece_key
 * @return array bcl_pieces 행
 * @throws BclApiError 없는 조각
 */
function bcl_piece_row($pdo, $job, $piece_key)
{
    $st = $pdo->prepare("SELECT * FROM bcl_pieces WHERE job_id = ? AND piece_key = ?");
    $st->execute([$job['id'], $piece_key]);
    $piece = $st->fetch();
    if (!$piece) throw new BclApiError(404, '없는 조각');
    return $piece;
}

/**
 * @param PDO $pdo
 * @param array $piece bcl_pieces 행
 * @param mixed $attempt_no
 * @return array bcl_attempts 행
 * @throws BclApiError 없는 시도
 */
function bcl_attempt_row($pdo, $piece, $attempt_no)
{
    $st = $pdo->prepare("SELECT * FROM bcl_attempts WHERE piece_id = ? AND attempt_no = ?");
    $st->execute([$piece['id'], is_int($attempt_no) ? $attempt_no : 0]);
    $attempt = $st->fetch();
    if (!$attempt) throw new BclApiError(404, '없는 시도');
    return $attempt;
}

/**
 * 시도를 끝낸다.
 * @param PDO $pdo
 * @param array $piece bcl_pieces 행
 * @param array $attempt bcl_attempts 행
 * @param string $status
 * @param int $now
 */
function bcl_attempt_end($pdo, $piece, $attempt, $status, $now)
{
    $pdo->prepare("UPDATE bcl_attempts SET status = ?, ended = ? WHERE id = ?")->execute([$status, $now, $attempt['id']]);

    // 지난 시도가 늦게 끝나도 지금 실행 중인 시도의 조각 상태는 건드리지 않는다
    $st = $pdo->prepare("SELECT MAX(attempt_no) FROM bcl_attempts WHERE piece_id = ?");
    $st->execute([$piece['id']]);
    if ((int)$st->fetchColumn() !== (int)$attempt['attempt_no']) return;

    $pdo->prepare("UPDATE bcl_pieces SET state = ? WHERE id = ?")->execute([$status === 'done' ? 'done' : 'pending', $piece['id']]);
}

/**
 * 리더 자리를 차지한다.
 * @param PDO $pdo
 * @param string $job_key
 * @param string $node_id
 * @param int $now
 * @return array ['ok' => bool, 'leader' => 리더, 'epoch' => int]
 */
function bcl_job_claim($pdo, $job_key, $node_id, $now)
{
    return bcl_with_job_lock($pdo, $job_key, function ($job) use ($pdo, $node_id, $now) {
        $held = $job['leader'] !== null && $now < (int)$job['lease_until'];
        if ($job['state'] === 'done' || $held) {
            return ['ok' => false, 'leader' => $job['leader'], 'epoch' => (int)$job['epoch']];
        }

        $epoch = (int)$job['epoch'] + 1;
        $pdo->prepare("UPDATE bcl_jobs SET leader = ?, epoch = ?, lease_until = ? WHERE id = ?")
            ->execute([$node_id, $epoch, $now + BCL_LEASE_S, $job['id']]);
        return ['ok' => true, 'leader' => $node_id, 'epoch' => $epoch];
    });
}

/**
 * 리더 리스를 늘린다.
 * @param PDO $pdo
 * @param string $job_key
 * @param string $node_id
 * @param int $epoch
 * @param int $now
 * @return array ['ok' => bool]
 */
function bcl_job_lease($pdo, $job_key, $node_id, $epoch, $now)
{
    return bcl_with_job_lock($pdo, $job_key, function ($job) use ($pdo, $node_id, $epoch, $now) {
        if (!bcl_is_leader($job, $node_id, $epoch)) return ['ok' => false];

        $pdo->prepare("UPDATE bcl_jobs SET lease_until = ? WHERE id = ?")->execute([$now + BCL_LEASE_S, $job['id']]);
        return ['ok' => true];
    });
}

/**
 * 조각 목록을 한 번만 등록한다.
 * @param PDO $pdo
 * @param string $job_key
 * @param string $node_id
 * @param int $epoch
 * @param array[] $pieces [['piece_id' => 이름, 'repo' => 저장소, 'desc' => 설명], ...]
 * @return array ['ok' => bool]
 */
function bcl_job_pieces($pdo, $job_key, $node_id, $epoch, $pieces)
{
    return bcl_with_job_lock($pdo, $job_key, function ($job) use ($pdo, $node_id, $epoch, $pieces) {
        if (!bcl_is_leader($job, $node_id, $epoch)) return ['ok' => false];

        $st = $pdo->prepare("SELECT COUNT(*) FROM bcl_pieces WHERE job_id = ?");
        $st->execute([$job['id']]);
        if ((int)$st->fetchColumn() > 0) return ['ok' => true];

        $insert = $pdo->prepare("INSERT INTO bcl_pieces (job_id, piece_key, repo, piece_desc) VALUES (?, ?, ?, ?)");
        foreach ($pieces as $piece) {
            $insert->execute([$job['id'], $piece['piece_id'], $piece['repo'], $piece['desc']]);
        }
        return ['ok' => true];
    });
}

/**
 * 조각 시도를 시작한다.
 * @param PDO $pdo
 * @param string $job_key
 * @param string $piece_key
 * @param string $node_id
 * @param string $account
 * @param string $token_user 요청한 토큰의 주인 이메일
 * @param int $now
 * @return array ['ok' => bool, 'attempt_no' => int|null]
 */
function bcl_piece_start($pdo, $job_key, $piece_key, $node_id, $account, $token_user, $now)
{
    return bcl_with_job_lock($pdo, $job_key, function ($job) use ($pdo, $piece_key, $node_id, $account, $token_user, $now) {
        $piece = bcl_piece_row($pdo, $job, $piece_key);
        if ($job['state'] === 'done' || $piece['state'] !== 'pending') return ['ok' => false, 'attempt_no' => null];

        $st = $pdo->prepare("SELECT COALESCE(MAX(attempt_no), 0) + 1 FROM bcl_attempts WHERE piece_id = ?");
        $st->execute([$piece['id']]);
        $attempt_no = (int)$st->fetchColumn();
        $pdo->prepare("INSERT INTO bcl_attempts (piece_id, attempt_no, node_id, account, token_user, started) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$piece['id'], $attempt_no, $node_id, $account, $token_user, $now]);
        $pdo->prepare("UPDATE bcl_pieces SET state = 'running' WHERE id = ?")->execute([$piece['id']]);
        return ['ok' => true, 'attempt_no' => $attempt_no];
    });
}

/**
 * 노드가 조각 시도를 끝낸다.
 * @param PDO $pdo
 * @param string $files_dir
 * @param string $job_key
 * @param string $piece_key
 * @param array $finish ['node_id' => 노드, 'token_user' => 요청한 토큰의 주인 이메일, 'attempt_no' => 시도 번호, 'status' => 상태, 'result' => 결과, 'patch' => 패치, 'log_json' => 로그 JSON, 'redactions_json' => 가린 횟수 JSON]
 * @param int $now
 * @return array ['ok' => bool]
 * @throws BclApiError 다른 사용자의 시도
 */
function bcl_piece_finish($pdo, $files_dir, $job_key, $piece_key, $finish, $now)
{
    return bcl_with_job_lock($pdo, $job_key, function ($job) use ($pdo, $files_dir, $piece_key, $finish, $now) {
        $piece = bcl_piece_row($pdo, $job, $piece_key);
        $attempt = bcl_attempt_row($pdo, $piece, $finish['attempt_no']);
        if ($attempt['token_user'] !== $finish['token_user']) throw new BclApiError(403, '다른 사용자의 시도');
        if ($attempt['status'] !== 'running' || $attempt['node_id'] !== $finish['node_id']) return ['ok' => false];

        $paths = bcl_files_save_attempt($files_dir, $job['job_key'], $piece['piece_key'], (int)$attempt['attempt_no'],
            $finish['result'], $finish['patch'], $finish['log_json']);
        $pdo->prepare("UPDATE bcl_attempts SET result_path = ?, patch_path = ?, log_path = ?, redactions = ? WHERE id = ?")
            ->execute([$paths['result_path'], $paths['patch_path'], $paths['log_path'], $finish['redactions_json'], $attempt['id']]);
        bcl_attempt_end($pdo, $piece, $attempt, $finish['status'], $now);
        return ['ok' => true];
    });
}

/**
 * 리더가 조각 시도를 닫는다.
 * @param PDO $pdo
 * @param string $job_key
 * @param string $piece_key
 * @param string $node_id
 * @param int $epoch
 * @param int $attempt_no
 * @param string $status
 * @param int $now
 * @return array ['ok' => bool]
 */
function bcl_piece_close($pdo, $job_key, $piece_key, $node_id, $epoch, $attempt_no, $status, $now)
{
    return bcl_with_job_lock($pdo, $job_key, function ($job) use ($pdo, $piece_key, $node_id, $epoch, $attempt_no, $status, $now) {
        $piece = bcl_piece_row($pdo, $job, $piece_key);
        $attempt = bcl_attempt_row($pdo, $piece, $attempt_no);
        if (!bcl_is_leader($job, $node_id, $epoch) || $attempt['status'] !== 'running') return ['ok' => false];

        $pdo->prepare("UPDATE bcl_attempts SET closed_by = ? WHERE id = ?")->execute([$node_id, $attempt['id']]);
        bcl_attempt_end($pdo, $piece, $attempt, $status, $now);
        return ['ok' => true];
    });
}

/**
 * 작업을 끝낸다.
 * @param PDO $pdo
 * @param string $job_key
 * @param string $node_id
 * @param int $epoch
 * @param string $summary
 * @param int $now
 * @return array ['ok' => bool]
 */
function bcl_job_finish($pdo, $job_key, $node_id, $epoch, $summary, $now)
{
    return bcl_with_job_lock($pdo, $job_key, function ($job) use ($pdo, $node_id, $epoch, $summary, $now) {
        if (!bcl_is_leader($job, $node_id, $epoch)) return ['ok' => false];

        $pdo->prepare("UPDATE bcl_jobs SET state = 'done', summary = ?, finished_at = ? WHERE id = ?")->execute([$summary, $now, $job['id']]);
        return ['ok' => true];
    });
}

/**
 * 노드 하트비트를 기록한다.
 * @param PDO $pdo
 * @param array $beat 하트비트 본문
 * @param string $token_user 요청한 토큰의 주인 이메일
 * @param int $now
 * @throws BclApiError 다른 사용자의 노드
 */
function bcl_node_heartbeat($pdo, $beat, $token_user, $now)
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT token_user, last_seen FROM bcl_nodes WHERE node_id = ? FOR UPDATE");
        $st->execute([$beat['node_id']]);
        $node = $st->fetch();
        // 꺼진 노드의 이름은 다른 사용자가 넘겨받을 수 있다
        if ($node && $node['token_user'] !== $token_user && (int)$node['last_seen'] >= $now - BCL_NODE_TTL_S) {
            throw new BclApiError(403, '다른 사용자의 노드');
        }

        $pdo->prepare("INSERT INTO bcl_nodes (node_id, account, token_user, addr, free_cpu, free_mem, running, attempts, last_seen)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE account = VALUES(account), token_user = VALUES(token_user), addr = VALUES(addr),
                           free_cpu = VALUES(free_cpu), free_mem = VALUES(free_mem), running = VALUES(running),
                           attempts = VALUES(attempts), last_seen = VALUES(last_seen)")
            ->execute([
                $beat['node_id'], $beat['account'], $token_user, $beat['addr'], $beat['free_cpu'], $beat['free_mem'], $beat['running'],
                json_encode($beat['attempts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now,
            ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
