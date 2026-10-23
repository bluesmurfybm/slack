<?php
/**
 * 출력·입력·CSRF. bluecart/includes/helpers.php 와 같은 모양이고 접두사만 ow_ 다.
 * 응답 형식도 같다 — {"ok":true,…} / {"ok":false,"error":"메시지"}.
 */

if (!function_exists('h')) {
    function h($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/** 정적 파일 판 번호 — 파일 수정시각. 손으로 올리는 번호는 잊는다. */
function ow_asset_v(string $rel): string
{
    $t = @filemtime(OW_ROOT . '/' . ltrim($rel, '/'));
    return (string)($t ?: 0);
}

function ow_json($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ow_json_ok(array $data = []): void
{
    ow_json(['ok' => true] + $data);
}

function ow_json_error(string $message, int $code = 400, array $extra = []): void
{
    ow_json(['ok' => false, 'error' => $message] + $extra, $code);
}

/** JSON 본문과 폼 전송을 같은 배열로 받는다. */
function ow_input(): array
{
    static $input = null;
    if ($input !== null) return $input;
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ctype, 'application/json') !== false) {
        $decoded = json_decode(file_get_contents('php://input') ?: '', true);
        $input = is_array($decoded) ? $decoded : [];
    } else {
        $input = $_POST;
    }
    return $input;
}

function ow_param(string $key, $default = null)
{
    $in = ow_input();
    if (array_key_exists($key, $in)) return $in[$key];
    return $_GET[$key] ?? $default;
}

function ow_param_str(string $key, string $default = ''): string
{
    $v = ow_param($key, $default);
    return is_scalar($v) ? trim((string)$v) : $default;
}

function ow_param_int(string $key, ?int $default = null): ?int
{
    $v = ow_param($key);
    if ($v === null || $v === '') return $default;
    return is_numeric($v) ? (int)$v : $default;
}

function ow_param_bool(string $key): bool
{
    $v = ow_param($key);
    return $v === true || $v === 1 || $v === '1' || $v === 'on' || $v === 'true';
}

function ow_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        ow_json_error('POST 요청만 허용됩니다.', 405);
    }
}

/** 화면용. 비로그인이면 포털로 돌려보낸다(왜 튕겼는지 포털이 알려 준다). */
function ow_require_login(): array
{
    $u = current_portal_user();
    if (!$u) {
        header('Location: ../index.php?need_login=' . OW_MODULE_KEY);
        exit;
    }
    return $u;
}

/** API 용. 비로그인이면 401 JSON. */
function ow_require_login_api(): array
{
    $u = current_portal_user();
    if (!$u) ow_json_error('로그인이 필요합니다.', 401);
    return $u;
}

function ow_csrf_token(): string
{
    if (empty($_SESSION['ow_csrf'])) $_SESSION['ow_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['ow_csrf'];
}

function ow_verify_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ow_param_str('_csrf');
    if ($sent === '' || !hash_equals($_SESSION['ow_csrf'] ?? '', $sent)) {
        ow_json_error('요청이 만료되었습니다. 새로고침 후 다시 시도하세요.', 419);
    }
}

/** 'YYYY-mm-dd HH:ii:ss' → 사람이 읽는 짧은 시각. 오늘이면 시각만, 올해면 월·일부터. */
function ow_when(?string $dt): string
{
    if (!$dt) return '';
    $ts = strtotime($dt);
    if (!$ts) return '';
    if (date('Y-m-d', $ts) === date('Y-m-d')) return '오늘 ' . date('H:i', $ts);
    if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('+1 day'))) return '내일 ' . date('H:i', $ts);
    if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) return '어제 ' . date('H:i', $ts);
    if (date('Y', $ts) === date('Y')) return date('n월 j일 H:i', $ts);
    return date('Y.m.d H:i', $ts);
}

/** 남은 기간을 'D-3' / '5시간 남음' 처럼. 지났으면 ''. */
function ow_left(?string $dt): string
{
    if (!$dt) return '';
    $sec = strtotime($dt) - time();
    if ($sec <= 0) return '';
    if ($sec < 86400) return max(1, (int)floor($sec / 3600)) . '시간 남음';
    return 'D-' . (int)ceil($sec / 86400);
}

/** 줄바꿈으로 적은 도메인 목록 정리. 'https://a.com/x' 처럼 적어도 호스트만 남긴다. */
function ow_clean_domains(string $text, int $max = 20): array
{
    $out = [];
    foreach (preg_split('/[\s,]+/u', $text) as $d) {
        $d = strtolower(trim($d));
        if ($d === '') continue;
        if (preg_match('#^[a-z][a-z0-9+.-]*://#', $d)) $d = (string)parse_url($d, PHP_URL_HOST);
        $d = preg_replace('#/.*$#', '', $d);
        $d = preg_replace('/^www\./', '', $d);
        if (!preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $d)) continue;
        $out[$d] = true;
        if (count($out) >= $max) break;
    }
    return array_keys($out);
}

/** 슬랙 웹훅은 hooks.slack.com 만 받는다 — 아무 주소나 받으면 배치가 내부망으로 요청을 보내게 된다. */
function ow_valid_slack_webhook(string $url): bool
{
    return (bool)preg_match('#^https://hooks\.slack\.com/(services|workflows|triggers)/[A-Za-z0-9/_-]+$#', $url);
}
