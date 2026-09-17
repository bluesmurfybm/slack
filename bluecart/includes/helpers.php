<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// 출력
// ---------------------------------------------------------------------

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bc_json(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bc_json_ok(array $data = []): never
{
    bc_json(['ok' => true] + $data);
}

function bc_json_error(string $message, int $code = 400, array $extra = []): never
{
    bc_json(['ok' => false, 'error' => $message] + $extra, $code);
}

// ---------------------------------------------------------------------
// 입력
// ---------------------------------------------------------------------

/** JSON 본문 또는 폼 전송을 동일하게 배열로 받는다. */
function bc_input(): array
{
    static $input = null;
    if ($input !== null) {
        return $input;
    }
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ctype, 'application/json') !== false) {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        $input = is_array($decoded) ? $decoded : [];
    } else {
        $input = $_POST;
    }
    return $input;
}

function bc_param(string $key, mixed $default = null): mixed
{
    $in = bc_input();
    if (array_key_exists($key, $in)) {
        return $in[$key];
    }
    return $_GET[$key] ?? $default;
}

function bc_param_str(string $key, string $default = ''): string
{
    $v = bc_param($key, $default);
    return is_scalar($v) ? trim((string)$v) : $default;
}

function bc_param_int(string $key, ?int $default = null): ?int
{
    $v = bc_param($key);
    if ($v === null || $v === '') {
        return $default;
    }
    return is_numeric($v) ? (int)$v : $default;
}

function bc_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        bc_json_error('POST 요청만 허용됩니다.', 405);
    }
}

// ---------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------

function bc_csrf_token(): string
{
    if (empty($_SESSION['bc_csrf'])) {
        $_SESSION['bc_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['bc_csrf'];
}

function bc_verify_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? bc_param_str('_csrf');
    if ($sent === '' || !hash_equals($_SESSION['bc_csrf'] ?? '', $sent)) {
        bc_json_error('요청이 만료되었습니다. 새로고침 후 다시 시도하세요.', 419);
    }
}

// ---------------------------------------------------------------------
// 포맷
// ---------------------------------------------------------------------

function bc_date(?string $dt, string $fmt = 'Y-m-d'): string
{
    if ($dt === null || $dt === '' || str_starts_with($dt, '0000')) {
        return '';
    }
    $ts = strtotime($dt);
    return $ts ? date($fmt, $ts) : '';
}

function bc_money(?int $won): string
{
    return $won === null ? '' : number_format($won) . '원';
}

/** URL 이 http(s) 인지 확인. javascript: 등 차단. */
function bc_safe_url(?string $url): ?string
{
    if ($url === null || trim($url) === '') {
        return null;
    }
    $url = trim($url);
    return preg_match('#^https?://#i', $url) ? $url : null;
}
