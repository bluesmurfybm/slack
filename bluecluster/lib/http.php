<?php
/**
 * 노드용 API 의 요청 해석과 JSON 응답.
 */

class BclApiError extends RuntimeException
{
    /**
     * @param int $status HTTP 상태
     * @param string $reason
     */
    public function __construct($status, $reason)
    {
        parent::__construct($reason, $status);
    }
}

/**
 * 성공 응답을 만든다.
 * @param array $data
 * @return array
 */
function bcl_api_ok($data)
{
    return ['status' => 200, 'data' => $data];
}

/**
 * 오류 응답을 만든다.
 * @param int $status
 * @param string $reason
 * @return array
 */
function bcl_api_error($status, $reason)
{
    return ['status' => $status, 'data' => ['reason' => $reason]];
}

/**
 * 응답을 내보낸다.
 * @param array $res
 */
function bcl_api_send($res)
{
    http_response_code($res['status']);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($res['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * 요청 하나를 처리한다.
 * @param array $ctx ['pdo' => PDO, 'user' => 포털 사용자|null, 'now' => unix 초, 'files_dir' => 결과 폴더]
 * @param string $method
 * @param string $path 예: /jobs/job-1/claim
 * @param string $raw 요청 본문
 * @return array ['status' => HTTP 상태, 'data' => 응답 본문]
 */
function bcl_api_handle($ctx, $method, $path, $raw)
{
    try {
        if (!$ctx['user']) throw new BclApiError(401, '인증 필요');
        $req = [
            'method' => $method,
            'seg' => explode('/', trim($path, '/')),
            'body' => $method === 'POST' ? bcl_api_body($raw) : null,
            'raw' => $raw,
        ];

        return bcl_api_ok(match ($req['seg'][0]) {
            'jobs' => bcl_route_jobs($ctx, $req),
            'nodes' => bcl_route_nodes($ctx, $req),
            default => throw new BclApiError(404, '없는 경로'),
        });
    } catch (BclApiError $e) {
        return bcl_api_error($e->getCode(), $e->getMessage());
    } catch (Throwable $e) {
        error_log('[bluecluster] ' . $e);
        return bcl_api_error(500, '서버 오류');
    }
}

/**
 * JSON 본문을 읽는다.
 * @param string $raw
 * @return array|null 본문이 없으면 null
 * @throws BclApiError
 */
function bcl_api_body($raw)
{
    if ($raw === '') return null;
    $body = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || ($body !== null && !is_array($body))) {
        throw new BclApiError(400, 'JSON 형식 오류');
    }
    return $body;
}

/**
 * 필수 값이 모두 왔는지 확인한다.
 * @param mixed $body
 * @param string[] $fields
 * @throws BclApiError
 */
function bcl_api_require($body, $fields)
{
    $body = is_array($body) ? $body : [];
    foreach ($fields as $field) {
        // 값이 null 이어도 보낸 것으로 친다
        if (!array_key_exists($field, $body)) throw new BclApiError(400, '필수 값 없음: ' . $field);
    }
}

/**
 * @param string $field
 * @param mixed $value
 * @return BclApiError
 */
function bcl_api_bad_value($field, $value)
{
    return new BclApiError(400, "{$field} 값 오류: "
        . (is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
}

/**
 * node_id 가 올바른지 확인한다.
 * @param mixed $node_id
 * @throws BclApiError
 */
function bcl_api_check_node_id($node_id)
{
    if (!is_string($node_id) || $node_id === '' || trim($node_id) !== $node_id || mb_strlen($node_id) > BCL_NODE_ID_MAX) {
        throw bcl_api_bad_value('node_id', $node_id);
    }
}
