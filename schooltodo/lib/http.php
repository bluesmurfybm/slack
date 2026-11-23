<?php

class SchooltodoError extends RuntimeException {
    /**
     * @param string $detail
     * @param int $status
     */
    public function __construct($detail, $status = 400) {
        parent::__construct($detail, $status);
    }

    /**
     * @return int
     */
    public function status() {
        return $this->getCode() ?: 400;
    }
}

/**
 * @return array
 */
function schooltodo_request_from_globals() {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'HEAD') $method = 'GET';

    $path = trim((string)($_GET['p'] ?? ''), '/');
    $seg = $path === '' ? [] : explode('/', $path);

    $raw = file_get_contents('php://input');
    $body = [];
    if ($raw !== '' && $raw !== false) {
        $body = json_decode($raw, true);
        if (!is_array($body)) throw new SchooltodoError('본문을 읽을 수 없습니다', 422);
    }

    return ['method' => $method, 'seg' => $seg, 'body' => $body, 'query' => $_GET];
}

/**
 * @param mixed $data
 * @param int $status
 * @return array
 */
function schooltodo_json($data, $status = 200) {
    return ['status' => $status, 'data' => $data];
}

/**
 * @param array $res
 */
function schooltodo_send($res) {
    http_response_code($res['status']);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($res['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * @param array $ctx
 * @param array $req
 * @return array
 */
function schooltodo_handle($ctx, $req) {
    try {
        return schooltodo_route($ctx, $req);
    } catch (SchooltodoError $e) {
        return schooltodo_json(['detail' => $e->getMessage()], $e->status());
    } catch (Throwable $e) {
        error_log('[schooltodo] ' . $e);
        return schooltodo_json(['detail' => '요청이 실패했습니다'], 500);
    }
}

/**
 * @param array $ctx
 * @param array $req
 * @return array
 */
function schooltodo_route($ctx, $req) {
    if (($req['seg'][0] ?? '') === 'whoami') return schooltodo_route_whoami($ctx);
    // 토큰은 읽기 전용 버전별 조회에서만 로그인 대신 받는다 — 쓰기 API 는 계속 포털 계정으로만
    $byToken = !empty($ctx['token_ok']) && schooltodo_is_token_route($req);
    if (!$ctx['identity'] && !$byToken) throw new SchooltodoError('로그인이 필요합니다', 401);

    return match ($req['seg'][0] ?? '') {
        'sheets' => schooltodo_route_sheets($ctx, $req),
        default => throw new SchooltodoError('없는 API 입니다', 404),
    };
}

/**
 * 토큰으로 부를 수 있는 경로인가 — GET /sheets/{id}/versions 하나뿐.
 * @param array $req
 * @return bool
 */
function schooltodo_is_token_route($req) {
    $seg = $req['seg'];
    return $req['method'] === 'GET'
        && count($seg) === 3
        && $seg[0] === 'sheets'
        && ctype_digit((string)$seg[1])
        && $seg[2] === 'versions';
}

/**
 * @param mixed $value
 * @param string $label
 * @param int $max
 * @return string
 */
function schooltodo_want_text($value, $label, $max) {
    if (is_array($value)) throw new SchooltodoError("{$label} 은(는) 글자여야 합니다", 422);
    $text = trim((string)$value);
    if (mb_strlen($text) > $max) {
        throw new SchooltodoError("{$label} 은(는) {$max}자까지 쓸 수 있습니다", 422);
    }
    return $text;
}

/**
 * @param mixed $value
 * @param string $label
 * @return string
 */
function schooltodo_want_status($value, $label) {
    if (!is_string($value) || !in_array($value, SCHOOLTODO_STATUSES, true)) {
        throw new SchooltodoError("{$label} 은(는) " . implode(', ', SCHOOLTODO_STATUSES) . ' 중 하나여야 합니다', 422);
    }
    return $value;
}
