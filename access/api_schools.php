<?php
/**
 * Coursemos EnvHub 외부 조회 API — 모든 대학의 이름·버전·저장소·테스트 서버 정보를 토큰으로 내려준다.
 *
 *   GET access/api_schools.php
 *   Authorization: Bearer <envhub_api_token>
 *
 *   쿼리(모두 선택)
 *     all=1         미사용(schools.active=0) 학교도 포함
 *     ver=4.5       버전이 정확히 같은 학교만
 *     q=강원        대학명에 들어간 글자로 거르기
 *     school_id=12  그 학교만
 *
 *   응답 {ok:true, count:N, rows:[{
 *          name, ver, repo,
 *          test_server: {ip, port, username, password, project_root}
 *        }]}
 *   test_server 는 school_access.test_srv 를 칸별로 나눈 것(access_parse_test_srv()). 비어 있으면
 *   각 칸이 ''(port 는 null). 포트를 안 적은 곳도 null 이다 — 기본값(22)은 쓰는 쪽이 정한다.
 *
 * 포털 세션 대신 토큰으로 인증한다 — 서버(coursemos-analytics 등)·스크립트가 쿠키 없이 부르기 위함.
 * 토큰은 config.php 의 'envhub_api_token' (없으면 환경변수 ENVHUB_API_TOKEN). 둘 다 비면 API 는 꺼진다.
 *
 * ※ 테스트 서버 비밀번호를 복호화해 평문으로 싣는다. 그래서 HTTPS 요청만 받는다
 *   (localhost 는 개발용으로 예외). 토큰이 새면 모든 대학 테스트 서버 계정이 같이 새므로 공유에 주의.
 */
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function api_schools_fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function api_schools_token() {
    $cfg = require __DIR__ . '/../config.php';
    $t = (string)($cfg['envhub_api_token'] ?? '');
    if ($t === '') $t = (string)getenv('ENVHUB_API_TOKEN');
    return trim($t);
}

/** Authorization 헤더는 서버 설정에 따라 들어오는 자리가 다르다(Apache CGI/FPM 은 REDIRECT_ 로 옮김) */
function api_schools_bearer() {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
        }
    }
    return preg_match('~^\s*Bearer\s+(\S+)\s*$~i', $h, $m) ? $m[1] : '';
}

function api_schools_is_https() {
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
    if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;   // 리버스 프록시 뒤
    $host = strtolower(preg_replace('~:\d+$~', '', $_SERVER['HTTP_HOST'] ?? ''));
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || substr($host, -10) === '.localhost';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') api_schools_fail(405, 'GET 만 지원합니다');

$token = api_schools_token();
if ($token === '') api_schools_fail(503, 'API 토큰이 설정되지 않았습니다 (config.php envhub_api_token)');
if (!api_schools_is_https()) api_schools_fail(403, 'HTTPS 로만 호출할 수 있습니다');

$given = api_schools_bearer();
if ($given === '' || !hash_equals($token, $given)) {
    header('WWW-Authenticate: Bearer');
    api_schools_fail(401, '토큰이 없거나 맞지 않습니다');
}

try {
    $pdo  = access_db();
    $where = [];
    $args  = [];
    if (!isset($_GET['all']))                   { $where[] = 's.active = 1'; }
    if (($v = trim((string)($_GET['ver'] ?? ''))) !== '')  { $where[] = 's.ver = ?';    $args[] = $v; }
    if (($q = trim((string)($_GET['q'] ?? ''))) !== '')    { $where[] = 's.name LIKE ?'; $args[] = '%' . addcslashes($q, '%_\\') . '%'; }
    if (($id = (int)($_GET['school_id'] ?? 0)) > 0)        { $where[] = 's.id = ?';     $args[] = $id; }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    // 접속 정보가 아직 없는 학교도 목록에 있어야 한다 — access_api.php 와 같이 schools 기준 LEFT JOIN
    // 필요한 칸만 고른다: 대학명·버전(schools) + 저장소·테스트 서버 정보(school_access)
    $st  = $pdo->prepare("
        SELECT s.name, s.ver, a.repo, a.test_srv
        FROM schools s
        LEFT JOIN school_access a ON a.school_id = s.id
        {$whereSql}
        ORDER BY s.ver+0, s.ver, a.sort_no, s.name
    ");
    $st->execute($args);

    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            'name'        => (string)$r['name'],
            'ver'         => (string)$r['ver'],
            'repo'        => trim((string)($r['repo'] ?? '')),
            // test_srv 는 암호화돼 있다(access_secret_cols) — 풀고 나서 칸별로 나눈다
            'test_server' => access_parse_test_srv(access_dec((string)($r['test_srv'] ?? ''))),
        ];
    }

    echo json_encode(['ok' => true, 'count' => count($rows), 'rows' => $rows],
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    error_log('[envhub api_schools] ' . $e->getMessage());
    api_schools_fail(500, '조회 중 오류가 났습니다');
}
