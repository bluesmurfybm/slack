<?php
/**
 * /jobs 경로.
 */

/**
 * @param array $ctx
 * @param array $req ['method' => 메서드, 'seg' => 경로 조각, 'body' => 본문, 'raw' => 본문 원문]
 * @return array 응답 본문
 * @throws BclApiError
 */
function bcl_route_jobs($ctx, $req)
{
    $seg = $req['seg'];
    $shape = $seg;
    if (isset($shape[1]) && $shape !== ['jobs', 'open']) $shape[1] = '{id}';
    if (isset($shape[3]) && $shape[2] === 'pieces') $shape[3] = '{pid}';
    $id = $seg[1] ?? '';
    $pid = $seg[3] ?? '';
    $body = $req['body'];

    return match ($req['method'] . ' /' . implode('/', $shape)) {
        'POST /jobs' => bcl_route_job_create($ctx, $body),
        'GET /jobs/open' => ['jobs' => bcl_jobs_open($ctx['pdo'], $ctx['now'])],
        'GET /jobs/{id}' => bcl_job_get($ctx['pdo'], $id) ?? throw new BclApiError(404, '등록되지 않은 작업'),
        'POST /jobs/{id}/claim' => bcl_route_job_claim($ctx, $id, $body),
        'POST /jobs/{id}/lease' => bcl_route_job_lease($ctx, $id, $body),
        'POST /jobs/{id}/pieces' => bcl_route_job_pieces($ctx, $id, $body),
        'POST /jobs/{id}/finish' => bcl_route_job_finish($ctx, $id, $body),
        'POST /jobs/{id}/pieces/{pid}/start' => bcl_route_piece_start($ctx, $id, $pid, $body),
        'POST /jobs/{id}/pieces/{pid}/finish' => bcl_route_piece_finish($ctx, $id, $pid, $req),
        'POST /jobs/{id}/pieces/{pid}/close' => bcl_route_piece_close($ctx, $id, $pid, $body),
        default => throw new BclApiError(404, '없는 경로'),
    };
}

/**
 * @param array $ctx
 * @param array|null $body
 * @return array
 */
function bcl_route_job_create($ctx, $body)
{
    bcl_api_require($body, ['title', 'repos', 'instructions']);
    try {
        // 등록자는 본문이 아니라 토큰 주인으로 남긴다
        return ['job_id' => bcl_job_create($ctx['pdo'], $body['title'], $ctx['user']['email'], $body['repos'], $body['instructions'])];
    } catch (InvalidArgumentException $e) {
        throw new BclApiError(400, $e->getMessage());
    }
}

/**
 * @param array $ctx
 * @param string $id
 * @param array|null $body
 * @return array
 */
function bcl_route_job_claim($ctx, $id, $body)
{
    bcl_api_require($body, ['node_id']);
    bcl_api_check_node_id($body['node_id']);
    return bcl_job_claim($ctx['pdo'], $id, $body['node_id'], $ctx['now']);
}

/**
 * @param array $ctx
 * @param string $id
 * @param array|null $body
 * @return array
 */
function bcl_route_job_lease($ctx, $id, $body)
{
    bcl_api_require($body, ['node_id', 'epoch']);
    bcl_api_check_node_id($body['node_id']);
    return bcl_job_lease($ctx['pdo'], $id, $body['node_id'], $body['epoch'], $ctx['now']);
}

/**
 * @param array $ctx
 * @param string $id
 * @param array|null $body
 * @return array
 */
function bcl_route_job_pieces($ctx, $id, $body)
{
    bcl_api_require($body, ['node_id', 'epoch', 'pieces']);
    bcl_api_check_node_id($body['node_id']);
    bcl_route_pieces_check($body['pieces']);
    return bcl_job_pieces($ctx['pdo'], $id, $body['node_id'], $body['epoch'], $body['pieces']);
}

/**
 * 등록할 조각 목록이 올바른지 확인한다.
 * @param mixed $pieces
 * @throws BclApiError
 */
function bcl_route_pieces_check($pieces)
{
    if (!is_array($pieces)) throw bcl_api_bad_value('pieces', $pieces);
    foreach ($pieces as $piece) {
        bcl_api_require($piece, ['piece_id', 'repo', 'desc']);
        $key = $piece['piece_id'];
        // piece_id 가 결과 파일 이름이 된다
        if (!is_string($key) || !preg_match(BCL_PIECE_ID_PATTERN, $key) || preg_match(BCL_RESERVED_NAME_PATTERN, $key)) {
            throw bcl_api_bad_value('piece_id', $key);
        }
    }

    $keys = array_column($pieces, 'piece_id');
    $duplicates = array_diff_assoc($keys, array_unique($keys));
    if ($duplicates) throw new BclApiError(400, 'piece_id 중복: ' . reset($duplicates));
}

/**
 * @param array $ctx
 * @param string $id
 * @param array|null $body
 * @return array
 */
function bcl_route_job_finish($ctx, $id, $body)
{
    bcl_api_require($body, ['node_id', 'epoch', 'summary']);
    bcl_api_check_node_id($body['node_id']);
    return bcl_job_finish($ctx['pdo'], $id, $body['node_id'], $body['epoch'], $body['summary'], $ctx['now']);
}

/**
 * @param array $ctx
 * @param string $id
 * @param string $pid
 * @param array|null $body
 * @return array
 */
function bcl_route_piece_start($ctx, $id, $pid, $body)
{
    bcl_api_require($body, ['node_id', 'account']);
    bcl_api_check_node_id($body['node_id']);
    return bcl_piece_start($ctx['pdo'], $ctx['files_dir'], $id, $pid, $body['node_id'], $body['account'], $ctx['user']['email'], $ctx['now']);
}

/**
 * @param array $ctx
 * @param string $id
 * @param string $pid
 * @param array $req
 * @return array
 */
function bcl_route_piece_finish($ctx, $id, $pid, $req)
{
    $body = $req['body'];
    bcl_api_require($body, ['node_id', 'attempt_no', 'status', 'result', 'patch', 'log']);
    bcl_api_check_node_id($body['node_id']);
    if (!in_array($body['status'], BCL_FINISH_STATUSES, true)) throw bcl_api_bad_value('status', $body['status']);
    foreach (['result', 'patch'] as $field) {
        if (!is_string($body[$field])) throw bcl_api_bad_value($field, $body[$field]);
    }

    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
    // 연관 배열로 읽은 본문은 빈 객체 {} 가 [] 로 바뀌므로 log 는 객체로 다시 읽는다
    $log = json_decode($req['raw'])->log;

    return bcl_piece_finish($ctx['pdo'], $ctx['files_dir'], $id, $pid, [
        'node_id' => $body['node_id'],
        'token_user' => $ctx['user']['email'],
        'attempt_no' => $body['attempt_no'],
        'status' => $body['status'],
        'result' => $body['result'],
        'patch' => $body['patch'],
        'log_json' => json_encode($log, $flags),
        'redactions_json' => empty($body['redactions']) ? '{}' : json_encode($body['redactions'], $flags),
    ], $ctx['now']);
}

/**
 * @param array $ctx
 * @param string $id
 * @param string $pid
 * @param array|null $body
 * @return array
 */
function bcl_route_piece_close($ctx, $id, $pid, $body)
{
    bcl_api_require($body, ['node_id', 'epoch', 'attempt_no', 'status']);
    bcl_api_check_node_id($body['node_id']);
    if (!in_array($body['status'], BCL_CLOSE_STATUSES, true)) throw bcl_api_bad_value('status', $body['status']);
    return bcl_piece_close($ctx['pdo'], $id, $pid, $body['node_id'], $body['epoch'], $body['attempt_no'], $body['status'], $ctx['now']);
}
