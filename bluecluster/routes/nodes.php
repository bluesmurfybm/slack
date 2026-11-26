<?php
/**
 * /nodes 경로.
 */

/**
 * @param array $ctx
 * @param array $req ['method' => 메서드, 'seg' => 경로 조각, 'body' => 본문, 'raw' => 본문 원문]
 * @return array 응답 본문
 * @throws BclApiError
 */
function bcl_route_nodes($ctx, $req)
{
    return match ($req['method'] . ' /' . implode('/', $req['seg'])) {
        'POST /nodes/heartbeat' => bcl_route_heartbeat($ctx, $req['body']),
        'GET /nodes/live' => ['nodes' => bcl_nodes_live($ctx['pdo'], $ctx['now'])],
        default => throw new BclApiError(404, '없는 경로'),
    };
}

/**
 * @param array $ctx
 * @param array|null $body
 * @return array
 */
function bcl_route_heartbeat($ctx, $body)
{
    bcl_api_require($body, ['node_id', 'account', 'addr', 'free_cpu', 'free_mem', 'running', 'attempts']);
    bcl_node_heartbeat($ctx['pdo'], $body, $ctx['user']['email'], $ctx['now']);
    return ['ok' => true];
}
