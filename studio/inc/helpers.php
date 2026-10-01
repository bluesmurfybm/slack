<?php
/** BlueStudio 공용 헬퍼 — HTML 이스케이프, JSON 응답, 요청 파라미터, CSRF. */

declare(strict_types=1);

// =====================================================================
// 출력
// =====================================================================

/**
 * HTML 이스케이프.
 *
 * core/ 에는 이 함수가 없다(grep 확인). 포털 index.php 와 BlueCart 가 각자
 * 정의해 쓰고 있어 여기서도 모듈 안에 둔다.
 * 다른 모듈이 먼저 정의했을 수 있어 function_exists 로 감싼다.
 */
if (!function_exists('h')) {
    function h(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * 정적 파일 주소에 붙일 판 번호.
 * 파일 수정시각이라 고칠 때마다 저절로 바뀐다(BlueCart bc_asset_v 와 같은 방식).
 */
function ba_asset_v(string $rel): string
{
    $t = @filemtime(BA_ROOT . '/' . ltrim($rel, '/'));
    return (string)($t ?: 0);
}

// =====================================================================
// JSON 응답
// =====================================================================
//
// ┌──────────────────────────────────────────────────────────────────┐
// │ 응답 형식에 대한 결정 — 읽고 나서 고치십시오                        │
// │                                                                  │
// │ 이 모듈은 아래 형식을 씁니다.                                      │
// │   성공  {"ok":true,  "data":{...}}                               │
// │   실패  {"ok":false, "error":{"code":"...","message":"..."}}     │
// │                                                                  │
// │ 근거: docs/bluestudio-spec.md §8 과 CLAUDE.md 가 둘 다 이 형식을   │
// │ 적고 있습니다.                                                    │
// │                                                                  │
// │ 주의: **BlueCart 는 다릅니다.** BlueCart 는 data 로 감싸지 않고     │
// │ error 도 문자열입니다.                                            │
// │   {"ok":true, "rows":[...]}  /  {"ok":false,"error":"메시지"}     │
// │ docs/conventions.md §9-2 에 "결정 필요" 로 남겨 둔 항목이며,        │
// │ 이 파일이 그 결정을 내린 곳입니다.                                 │
// │                                                                  │
// │ BlueCart 쪽에 맞추기로 바꾸려면 아래 두 함수만 고치면 됩니다.       │
// │ 호출부(api/*.php)는 건드릴 필요가 없습니다.                        │
// └──────────────────────────────────────────────────────────────────┘

function ba_json(mixed $payload, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 성공 응답. 페이로드는 data 아래로 들어간다. */
function ba_json_ok(mixed $data = null): never
{
    ba_json(['ok' => true, 'data' => $data]);
}

/**
 * 실패 응답.
 *
 * @param string $code    기계가 읽는 코드. 대문자+밑줄. 예: NOT_FOUND
 * @param string $message 사람이 읽는 한글 문장. 화면이 그대로 보여준다.
 */
function ba_json_error(string $code, string $message, int $httpStatus = 400): never
{
    ba_json(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $httpStatus);
}

// =====================================================================
// 요청
// =====================================================================

/** JSON 본문과 폼 전송을 같게 취급한다(BlueCart bc_input 과 같은 방식). */
function ba_input(): array
{
    static $input = null;
    if ($input !== null) {
        return $input;
    }
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ctype, 'application/json') !== false) {
        $raw     = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        $input   = is_array($decoded) ? $decoded : [];
    } else {
        $input = $_POST;
    }
    return $input;
}

function ba_param(string $key, mixed $default = null): mixed
{
    $in = ba_input();
    if (array_key_exists($key, $in)) {
        return $in[$key];
    }
    return $_GET[$key] ?? $default;
}

/**
 * 그 칸이 요청에 **들어 있었는지**. 값이 빈 문자열이어도 true 다.
 *
 * "안 보낸 것" 과 "비워서 보낸 것" 은 다르다. 수정 API 가 이 둘을 구분하지
 * 못하면, 보내지 않은 칸을 빈 값으로 덮어써 멀쩡한 데이터를 지운다.
 * (실제로 그렇게 새던 것을 HTTP 시험에서 잡았다.)
 */
function ba_has_param(string $key): bool
{
    return array_key_exists($key, ba_input()) || array_key_exists($key, $_GET);
}

function ba_param_str(string $key, string $default = ''): string
{
    $v = ba_param($key, $default);
    return is_scalar($v) ? trim((string)$v) : $default;
}

function ba_param_int(string $key, ?int $default = null): ?int
{
    $v = ba_param($key);
    if ($v === null || $v === '') {
        return $default;
    }
    return is_numeric($v) ? (int)$v : $default;
}

/** 배열 파라미터(domains[] 등). 스칼라가 오면 한 칸짜리 배열로 만든다. */
function ba_param_array(string $key): array
{
    $v = ba_param($key, []);
    if ($v === null || $v === '') {
        return [];
    }
    return is_array($v) ? array_values($v) : [$v];
}

/** 요청 메서드. */
function ba_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function ba_require_post(): void
{
    if (ba_method() !== 'POST') {
        ba_json_error('METHOD_NOT_ALLOWED', 'POST 요청만 허용됩니다.', 405);
    }
}

// =====================================================================
// CSRF
// =====================================================================
//
// 세션 키를 ba_csrf 로 따로 둔다. BlueCart(bc_csrf)와 같은 세션을 공유하므로
// 키 이름이 겹치면 두 모듈이 서로의 토큰을 덮어쓴다.

function ba_csrf_token(): string
{
    if (empty($_SESSION['ba_csrf'])) {
        $_SESSION['ba_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['ba_csrf'];
}

function ba_verify_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ba_param_str('_csrf');
    if ($sent === '' || !hash_equals($_SESSION['ba_csrf'] ?? '', $sent)) {
        ba_json_error('CSRF_EXPIRED', '요청이 만료되었습니다. 새로고침 후 다시 시도하세요.', 419);
    }
}

// =====================================================================
// 포맷
// =====================================================================

function ba_date(?string $dt, string $fmt = 'Y-m-d'): string
{
    if ($dt === null || $dt === '' || str_starts_with($dt, '0000')) {
        return '';
    }
    $ts = strtotime($dt);
    return $ts ? date($fmt, $ts) : '';
}

/** http(s) 링크만 통과시킨다. javascript: 등 차단. */
function ba_safe_url(?string $url): ?string
{
    if ($url === null || trim($url) === '') {
        return null;
    }
    $url = trim($url);
    return preg_match('#^https?://#i', $url) ? $url : null;
}
