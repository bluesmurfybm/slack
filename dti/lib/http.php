<?php
/**
 * JSON API 공통 — 요청 파싱 · 응답 · 입력 검증 · 라우팅.
 *
 * 오류 본문은 파이썬 HTTPException 과 같은 {"detail": "..."} 형태를 유지한다.
 * 화면(static/core.js)이 그 키를 읽어 토스트를 띄운다.
 *
 * 라우트는 값을 돌려주고 출력은 dti_send() 한 곳에서만 한다.
 */

class DtiError extends RuntimeException {
    public function __construct($detail, $status = 400) {
        parent::__construct($detail, $status);
    }

    public function status() {
        return $this->getCode() ?: 400;
    }
}

/* ---------- 요청 ---------- */

function dti_request_from_globals(): array {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    // HEAD 는 GET 과 같은 라우트로 보낸다 — 본문은 SAPI 가 알아서 버린다
    if ($method === 'HEAD') $method = 'GET';

    $path = trim((string)($_GET['p'] ?? ''), '/');
    $seg = $path === '' ? [] : explode('/', $path);

    $raw = file_get_contents('php://input');
    $body = [];
    if ($raw !== '' && $raw !== false
            && !str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) {
        $body = json_decode($raw, true);
        if (!is_array($body)) throw new DtiError('본문을 읽을 수 없습니다', 422);
    }

    return ['method' => $method, 'seg' => $seg, 'body' => $body, 'query' => $_GET, 'files' => $_FILES];
}

/* ---------- 응답 ---------- */

function dti_json($data, $status = 200): array {
    return ['status' => $status, 'data' => $data];
}

function dti_file($path, $name): array {
    return ['status' => 200, 'file' => $path, 'name' => $name];
}

/** 출력하고 끝내는 유일한 자리. 라우트는 값을 돌려주기만 한다. */
function dti_send(array $res): void {
    http_response_code($res['status']);

    if (isset($res['file'])) {
        [$type, $disposition] = dti_disposition($res['name'] ?? basename($res['file']));
        header("Content-Type: {$type}");
        header("Content-Disposition: {$disposition}");
        header('Content-Length: ' . filesize($res['file']));
        readfile($res['file']);
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($res['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* ---------- 라우팅 ---------- */

function dti_handle(array $ctx, array $req): array {
    try {
        return dti_route($ctx, $req);
    } catch (DtiError $e) {
        return dti_json(['detail' => $e->getMessage()], $e->status());
    } catch (Throwable $e) {
        error_log('[dti] ' . $e);
        return dti_json(['detail' => '요청이 실패했습니다'], 500);
    }
}

function dti_route(array $ctx, array $req): array {
    // whoami 만은 미로그인에서도 답한다 — 화면이 여기서 받은 portal_url 로 되돌아간다
    if (($req['seg'][0] ?? '') === 'whoami') return dti_route_identity($ctx, $req);
    if (!$ctx['identity']) throw new DtiError('로그인이 필요합니다', 401);

    return match ($req['seg'][0] ?? '') {
        'members' => dti_route_identity($ctx, $req),
        'topics' => dti_route_topics($ctx, $req),
        'fields' => dti_route_fields($ctx, $req),
        'score' => dti_route_scores($ctx, $req),
        default => throw new DtiError('없는 API 입니다', 404),
    };
}

/* ---------- 입력 검증 ---------- */

function dti_want_str(array $body, $key, $label, $required = false): string {
    $value = trim((string)($body[$key] ?? ''));
    if ($required && $value === '') {
        throw new DtiError("{$label}을(를) 입력해 주세요", 422);
    }
    return $value;
}

function dti_want_nullable_int(array $body, $key, $label): ?int {
    $value = $body[$key] ?? null;
    if ($value === null || $value === '') return null;
    if (!is_numeric($value)) {
        throw new DtiError("{$label}은(는) 숫자여야 합니다", 422);
    }
    return (int)$value;
}

function dti_want_date($value, $label): string {
    $date = trim((string)$value);
    if ($date === '') return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new DtiError("{$label}은(는) YYYY-MM-DD 형식이어야 합니다", 422);
    }
    return $date;
}

function dti_want_url($value, $label): string {
    $url = trim((string)$value);
    if ($url !== '' && !preg_match('~^https?://~i', $url)) {
        throw new DtiError('http(s) 로 시작하는 주소만 넣을 수 있습니다', 422);
    }
    return $url;
}

function dti_want_one_of($value, array $allowed, $label, $blankOk = true): string {
    $picked = trim((string)$value);
    if ($picked === '' && $blankOk) return '';
    if (!in_array($picked, $allowed, true)) {
        throw new DtiError("없는 {$label}입니다: {$picked}", 422);
    }
    return $picked;
}

function dti_flag($value): int {
    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ? 1 : 0;
}
