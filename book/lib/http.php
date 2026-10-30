<?php
/**
 * JSON API 공통 — 요청 파싱 · 응답 · 입력 검증 · 라우팅.
 *
 * 오류 본문은 파이썬 HTTPException 과 같은 {"detail": "..."} 형태를 유지한다.
 * 화면(static/core.js)이 그 키를 읽어 토스트를 띄운다.
 *
 * 라우트는 값을 돌려주고 출력은 book_send() 한 곳에서만 한다(dti 와 같다).
 */

class BookError extends RuntimeException {
    public function __construct(string $detail, int $status = 400) {
        parent::__construct($detail, $status);
    }

    public function status(): int {
        return $this->getCode() ?: 400;
    }
}

/* ---------- 요청 ---------- */

function book_request_from_globals(): array {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'HEAD') $method = 'GET';

    $path = trim((string)($_GET['p'] ?? ''), '/');
    $seg = $path === '' ? [] : explode('/', $path);

    $raw = file_get_contents('php://input');
    $body = [];
    if ($raw !== '' && $raw !== false) {
        $body = json_decode($raw, true);
        if (!is_array($body)) throw new BookError('본문을 읽을 수 없습니다', 422);
    }

    return ['method' => $method, 'seg' => $seg, 'body' => $body, 'query' => $_GET];
}

/* ---------- 응답 ---------- */

function book_json(mixed $data, int $status = 200): array {
    return ['status' => $status, 'data' => $data];
}

/** 출력하고 끝내는 유일한 자리. 라우트는 값을 돌려주기만 한다. */
function book_send(array $res): void {
    http_response_code($res['status']);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($res['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* ---------- 라우팅 ---------- */

function book_handle(array $ctx, array $req): array {
    try {
        return book_route($ctx, $req);
    } catch (BookError $e) {
        return book_json(['detail' => $e->getMessage()], $e->status());
    } catch (Throwable $e) {
        error_log('[book] ' . $e);
        return book_json(['detail' => '요청이 실패했습니다'], 500);
    }
}

function book_route(array $ctx, array $req): array {
    // whoami 만은 미로그인에서도 답한다 — 화면이 여기서 받은 portal_url 로 되돌아간다
    if (($req['seg'][0] ?? '') === 'whoami') return book_route_identity($ctx, $req);
    if (!$ctx['identity']) throw new BookError('로그인이 필요합니다', 401);

    return match ($req['seg'][0] ?? '') {
        'members' => book_route_identity($ctx, $req),
        'requests' => book_route_requests($ctx, $req),
        'booksearch' => book_route_booksearch($ctx, $req),
        default => throw new BookError('없는 API 입니다', 404),
    };
}

/* ---------- 입력 검증 ---------- */

function book_want_str(array $body, string $key, string $label, bool $required = false): string {
    $value = trim((string)($body[$key] ?? ''));
    if ($required && $value === '') {
        throw new BookError("{$label}을(를) 입력해 주세요", 422);
    }
    return $value;
}

function book_want_nullable_int(array $body, string $key, string $label): ?int {
    $value = $body[$key] ?? null;
    if ($value === null || $value === '') return null;
    if (!is_numeric($value) || (int)$value < 0) {
        throw new BookError("{$label}은(는) 0 이상의 숫자여야 합니다", 422);
    }
    return (int)$value;
}

function book_want_date(mixed $value, string $label): string {
    $date = trim((string)$value);
    if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date)) {
        throw new BookError("{$label}은(는) YYYY-MM-DD 형식이어야 합니다", 422);
    }
    return $date;
}

function book_want_url(mixed $value, string $label): string {
    $url = trim((string)$value);
    if ($url !== '' && !preg_match('~^https?://~i', $url)) {
        throw new BookError("{$label}은(는) http(s) 로 시작해야 합니다", 422);
    }
    return $url;
}

function book_want_one_of(mixed $value, array $allowed, string $label): string {
    $picked = trim((string)$value);
    if (!in_array($picked, $allowed, true)) {
        throw new BookError("{$label}은(는) " . implode(', ', $allowed) . ' 중 하나여야 합니다', 422);
    }
    return $picked;
}

function book_flag(mixed $value): int {
    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ? 1 : 0;
}
