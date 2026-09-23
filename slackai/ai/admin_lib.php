<?php
/**
 * slackai 관리 페이지·부속 API(tags/repos/jobs/lessons/settings) 공용 헬퍼.
 *  - 각 API 는 `xxx_api_handle($method, array $get, array $in, array $me): array` 순수 함수로 작성하고,
 *    웹에서는 admin_run() 이 인증·헤더 검사·JSON 응답을 맡는다. CLI(_smoke) 에서는 함수만 직접 부른다.
 *  - 권한 모델(plan 1.7): admin = portal_admin, approver = config/ai_settings approvers ∪ admin, 나머지 = 로그인 사용자.
 *  - 함수 이름은 전부 admin_ 접두로 두어 ai_lib.php(다른 작업자가 확장 중)와 충돌하지 않게 한다.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/ai_lib.php';

/** 핸들러가 던지는 HTTP 오류 (admin_run 이 ai_fail 로 변환) */
class AdminApiError extends Exception {
    public $codeStr;
    public $http;
    public $extra;
    public function __construct($codeStr, $message, $http = 400, array $extra = []) {
        parent::__construct($message);
        $this->codeStr = (string)$codeStr;
        $this->http    = (int)$http;
        $this->extra   = $extra;
    }
}

/** 핸들러 안에서 오류 응답을 만들 때 */
function admin_abort($codeStr, $message, $http = 400, array $extra = []) {
    throw new AdminApiError($codeStr, $message, $http, $extra);
}

/** 현재 사용자 요약 (웹 전용: auth.php 로드 후) */
function admin_me() {
    $email = ai_actor();
    $admin = ai_is_admin($email);
    return [
        'email'       => $email,
        'is_admin'    => $admin,
        'is_approver' => $admin || ai_is_approver($email),
    ];
}

/** 권한 검사: 'admin' | 'approver' | 'user' (로그인만) */
function admin_require(array $me, $level) {
    if ($level === 'admin' && empty($me['is_admin'])) {
        admin_abort('forbidden', '관리자(portal_admin)만 할 수 있습니다.', 403);
    }
    if ($level === 'approver' && empty($me['is_approver'])) {
        admin_abort('forbidden', '승인자 또는 관리자만 할 수 있습니다.', 403);
    }
}

/**
 * 웹 진입점. GET 은 그대로, POST 는 X-Requested-With: fetch 헤더 + JSON 본문을 요구한다.
 * 핸들러가 돌려준 배열에 ok:true 를 붙여 JSON 으로 응답한다.
 */
function admin_run(callable $handler) {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $in = [];
    if ($method === 'POST') {
        if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'fetch') {
            ai_fail('bad_request', 'X-Requested-With: fetch 헤더가 필요합니다.', 400);
        }
        $in = ai_input();
    } elseif ($method !== 'GET') {
        ai_fail('method_not_allowed', 'GET/POST 만 지원합니다.', 405);
    }
    $me = admin_me();
    try {
        $out = $handler($method, $_GET, $in, $me);
        ai_json(['ok' => true] + (is_array($out) ? $out : []));
    } catch (AdminApiError $e) {
        ai_fail($e->codeStr, $e->getMessage(), $e->http, $e->extra);
    } catch (Throwable $e) {
        ai_fail('server_error', $e->getMessage(), 500);
    }
}

/* ===================== 입력 정규화 ===================== */

/** 문자열 트림 + 길이 제한 */
function admin_str($v, $max = 0) {
    $s = trim((string)($v ?? ''));
    if ($max > 0 && mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
    return $s;
}

/** 양의 정수 id (없거나 0 이하면 0) */
function admin_id($v) {
    return is_numeric($v) ? max(0, (int)$v) : 0;
}

/** nullable 정수 (빈 문자열/null → null) */
function admin_int_or_null($v) {
    if ($v === null || $v === '' || $v === false) return null;
    return is_numeric($v) ? (int)$v : null;
}

/** 0/1 */
function admin_bool($v, $default = 1) {
    if ($v === null || $v === '') return (int)$default;
    if (is_string($v)) {
        $l = strtolower(trim($v));
        if (in_array($l, ['0', 'false', 'off', 'no', ''], true)) return 0;
        return 1;
    }
    return (int)!!$v;
}

/** 콤마/줄바꿈 구분 문자열 또는 배열 → 중복 제거된 문자열 배열 */
function admin_list($v, $splitRe = '/[,\n\r]+/u') {
    if (is_array($v)) {
        $items = $v;
    } else {
        $items = preg_split($splitRe, (string)($v ?? '')) ?: [];
    }
    $out = [];
    foreach ($items as $it) {
        $s = trim((string)$it);
        if ($s === '') continue;
        if (!in_array($s, $out, true)) $out[] = $s;
    }
    return $out;
}

/** 이름 → slug (소문자, 글자·숫자 이외는 '-' ; 한글은 그대로 둔다) */
function admin_slugify($name) {
    $s = mb_strtolower(trim((string)$name));
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
    $s = trim((string)$s, '-');
    return $s === '' ? 'tag' : mb_substr($s, 0, 60);
}

/** #rrggbb 색상 검증 (소문자 정규화). 잘못되면 null */
function admin_color($v) {
    $s = strtolower(trim((string)($v ?? '')));
    return preg_match('/^#[0-9a-f]{6}$/', $s) ? $s : null;
}

/** 이메일 형식 검증 */
function admin_is_email($v) {
    return (bool)filter_var(trim((string)$v), FILTER_VALIDATE_EMAIL);
}

/* ===================== 화면 공용 ===================== */

/**
 * 관리 페이지 간 이동 링크 (모든 관리 페이지가 한 단계 하위 폴더에 있으므로 ../X/Y.php 로 통일)
 * @param string $current 현재 페이지 key: tags|repos|jobs|lessons|settings|schools
 */
function admin_nav_html($current) {
    $items = [
        'tags'     => ['../tags/tags.php',              '🏷️ 태그'],
        'repos'    => ['../repos/repos.php',            '📁 레포'],
        'jobs'     => ['../ai/jobs.php',                '🤖 작업 로그'],
        'lessons'  => ['../ai/lessons.php',             '📚 학습 노트'],
        'settings' => ['../ai/settings.php',            '⚙️ AI 설정'],
        'schools'  => ['../schools/schools_admin.php',  '🏫 학교'],
    ];
    $h = '<nav class="adm-nav">';
    foreach ($items as $k => [$href, $label]) {
        $cls = $k === $current ? ' class="on"' : '';
        $h .= '<a' . $cls . ' href="' . htmlspecialchars($href, ENT_QUOTES) . '">' . $label . '</a>';
    }
    $h .= '</nav>';
    return $h;
}
