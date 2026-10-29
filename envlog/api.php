<?php
/**
 * Coursemos EnvLog API (포털 로그인 필요)
 *
 *   GET  ?a=schools&q=             대학 자동완성 — schools JOIN school_access, test_srv 복호화 (DB 는 여기서 직접)
 *   GET  ?a=state                  내 접속 상태(대학·서버 목록)
 *   POST ?a=connect  {school_id}   DB 의 테스트서버 정보로 접속   / {host,user,password,name} 직접 입력
 *   POST ?a=disconnect
 *   GET  ?a=files|logdirs|info|chunk|seek|grep  &server=&path=…   로그 조회 (그대로 데몬에 넘김)
 *
 * 역할 나누기
 *   - DB(대학 목록·테스트서버 접속 정보)는 PHP 가 config.php 로 직접 읽는다. 포털과 같은 서버에서
 *     도니 SSH 터널도, DB 비밀번호 입력도 없다. 복호화는 access/db.php 의 access_dec() 그대로.
 *   - SSH 로 로그를 읽는 일은 상주 데몬(daemon/envlogd.py, 127.0.0.1:8766)이 한다. 요청마다
 *     SSH 를 새로 맺을 수 없어서다(테스트서버 → 운영서버 2단 접속). 데몬은 DB 를 모르고,
 *     접속할 때 이 파일이 넘겨 주는 호스트·계정·비밀번호만 받는다.
 *   - 데몬 주소는 config.php 의 'envlog_url' (없으면 http://127.0.0.1:8766).
 *   - 데몬 세션은 포털 사용자별이다 — X-Envlog-User 헤더에 portal_users.id 를 실어 보낸다.
 */
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../access/db.php';   // access_db(), access_dec(), access_session_release 는 안 씀

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = current_portal_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => '로그인이 필요합니다'], JSON_UNESCAPED_UNICODE);
    exit;
}
$uid = (string)(int)$user['id'];
envlog_session_release();   // 이후는 세션을 안 쓴다 — 패널 여러 개가 동시에 청크를 읽는다
set_time_limit(0);           // 접속(최대 2분)·grep(최대 5분)은 PHP 기본 30초를 넘긴다

$action = $_GET['a'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** 데몬 주소 — config.php 의 envlog_url. 포털 설정에 키가 없으면 기본값 */
function envlog_daemon_url() {
    static $url = null;
    if ($url === null) {
        $cfg = require __DIR__ . '/../config.php';
        $url = rtrim((string)($cfg['envlog_url'] ?? ''), '/') ?: 'http://127.0.0.1:8766';
    }
    return $url;
}

/**
 * 대학 목록을 데몬(--iworks-db)이 만드는가 — 개발 PC 에서만.
 * 로컬 시험 포털(SQLite, php -S)에는 대학 접속 정보가 없으니 데몬이 iWorks 서버 DB 를 터널로 읽는다.
 * config.php 에 'envlog_schools_from' => 'daemon' | 'db' 로 못 박을 수도 있다.
 */
function envlog_schools_from_daemon() {
    static $v = null;
    if ($v === null) {
        $cfg = require __DIR__ . '/../config.php';
        $from = (string)($cfg['envlog_schools_from'] ?? '');
        if ($from !== '') $v = ($from === 'daemon');
        elseif (db_local_sqlite()) $v = true;
        else {
            // 설정이 없으면 떠 있는 데몬에게 묻는다 — 개발 PC 에서 `envlogd.py --iworks-db` 로 띄워 두면 그쪽이 대학 목록을 준다.
            // 운영에서는 데몬이 보통 모드라 false. 데몬이 아직 없으면(연결 거부) false — 여기서는 자동 기동하지 않는다.
            [$out] = envlog_call('0', 'GET', '/state', [], null, 3);
            $st = $out !== null ? json_decode($out, true) : null;
            $v = !empty($st['schools_from_daemon']);
        }
    }
    return $v;
}

/**
 * 데몬 호출. 응답 본문(JSON 문자열)과 HTTP 코드를 그대로 돌려준다.
 * curl 확장이 없는 설치본을 위해 스트림으로도 간다.
 */
function envlog_call($uid, $method, $path, array $query = [], $body = null, $timeout = 70) {
    $url = envlog_daemon_url() . $path . ($query ? '?' . http_build_query($query) : '');
    $headers = ['X-Envlog-User: ' . $uid, 'Accept: application/json'];
    if ($body !== null) $headers[] = 'Content-Type: application/json';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $out  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($out === false) return [null, 0, $err];
        return [$out, $code, ''];
    }

    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '',
        'timeout' => $timeout, 'ignore_errors' => true,
    ]]);
    $out = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
    }
    if ($out === false) return [null, 0, error_get_last()['message'] ?? '연결 실패'];
    return [$out, $code, ''];
}

/**
 * 데몬 자동 기동 — systemd 같은 서비스 등록 없이 쓴다.
 *  데몬이 응답하지 않으면 여기서 백그라운드로 띄우고(웹 서버 계정으로), 데몬은 한 시간 동안 아무 요청이
 *  없으면 스스로 끝난다(ENVLOG_EXIT_IDLE). 파이썬 패키지는 daemon/venv 에 처음 한 번 자동으로 깐다
 *  (서버에 python3 와 venv 모듈만 있으면 된다). 로그는 daemon/var/envlogd.log.
 *  config.php 로 바꿀 수 있는 것: 'envlog_python' (venv 대신 쓸 파이썬 실행 파일), 'envlog_autostart' => false.
 *  개발 PC 의 --iworks-db 모드는 비밀번호를 콘솔에서 받으므로 자동 기동 대상이 아니다 — 직접 띄운다.
 */
function envlog_ensure_daemon() {
    static $cfg = null;
    if ($cfg === null) $cfg = require __DIR__ . '/../config.php';
    if (($cfg['envlog_autostart'] ?? true) === false) return '자동 기동이 꺼져 있습니다(envlog_autostart)';
    if (!preg_match('#^https?://(127\.0\.0\.1|localhost)(?::(\d+))?$#', envlog_daemon_url(), $m)) {
        return '데몬 주소가 이 서버가 아닙니다 — 자동 기동 불가 (' . envlog_daemon_url() . ')';
    }
    $port = (int)($m[2] ?? 80) ?: 80;
    $dir  = __DIR__ . '/daemon';
    $var  = $dir . '/var';
    if (!is_dir($var)) @mkdir($var, 0775, true);
    if (!is_dir($var) || !is_writable($var)) return "daemon/var 에 쓸 수 없습니다 — 웹 서버 계정에 쓰기 권한을 주세요: {$var}";

    // 같은 순간 여러 요청이 동시에 띄우지 않게 표시 파일(spawn.lock)을 원자적으로 만든다.
    // flock 을 들고 있으면 그 핸들이 자식(데몬)에 상속돼 데몬이 사는 동안 잠금이 안 풀린다 — 그래서 파일 존재로만 잠근다.
    $lockFile = $var . '/spawn.lock';
    if (is_file($lockFile) && time() - (int)@filemtime($lockFile) > 90) @unlink($lockFile);   // 죽은 기동의 찌꺼기
    $lock = @fopen($lockFile, 'x');
    if (!$lock) {                                    // 다른 요청이 띄우는 중 → 떠오를 때까지만 기다린다
        for ($i = 0; $i < 40; $i++) { usleep(250000); if (envlog_daemon_alive()) return ''; }
        return '다른 요청이 엔진을 띄우는 중입니다 — 잠시 후 다시 시도하세요';
    }
    fclose($lock);
    try {
        if (envlog_daemon_alive()) return '';          // 다른 요청이 방금 띄웠다

        $win = strncasecmp(PHP_OS, 'WIN', 3) === 0;
        $py  = (string)($cfg['envlog_python'] ?? '');
        if ($py === '') {
            $venvPy = $dir . ($win ? '/venv/Scripts/python.exe' : '/venv/bin/python');
            if (!is_file($venvPy)) {
                $err = envlog_make_venv($dir, $win);
                if ($err !== '') return $err;
            }
            $py = $venvPy;
        }
        if (!is_file($py)) return "파이썬 실행 파일이 없습니다: {$py}";

        $log = $var . '/envlogd.log';
        $cmd = escapeshellarg($py) . ' ' . escapeshellarg($dir . '/envlogd.py') . ' --port ' . $port;
        if ($win) {
            // start /B: 새 콘솔 없이 분리 실행. popen 으로 바로 돌아온다
            $h = popen('start /B "" ' . $cmd . ' >> ' . escapeshellarg($log) . ' 2>&1', 'r');
            if ($h) pclose($h);
        } else {
            // setsid + nohup: php-fpm 요청이 끝나도 살아남는다
            exec('cd ' . escapeshellarg($dir) . ' && nohup setsid ' . $cmd . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null &');
        }
        for ($i = 0; $i < 40; $i++) {        // 최대 10초 기다린다 (asyncssh import 가 1~2초)
            usleep(250000);
            if (envlog_daemon_alive()) return '';
        }
        $tail = is_file($log) ? trim(substr(file_get_contents($log), -600)) : '';
        return '엔진을 띄웠지만 응답이 없습니다. 로그: ' . $log . ($tail !== '' ? "
" . $tail : '');
    } finally {
        @unlink($lockFile);
    }
}

/** 데몬이 떠 있는가 — /state 에 짧게 찔러 본다 */
function envlog_daemon_alive() {
    [$out] = envlog_call('0', 'GET', '/state', [], null, 3);
    return $out !== null;
}

/** 처음 한 번: python3 -m venv + pip install -r requirements.txt (1분쯤 걸린다) */
function envlog_make_venv($dir, $win) {
    $sys = (string)((require __DIR__ . '/../config.php')['envlog_system_python'] ?? '');
    $cands = $sys !== '' ? [$sys] : ($win ? ['python', 'py -3'] : ['python3', 'python']);
    foreach ($cands as $c) {
        $o = []; $rc = 1;
        exec($c . ' -m venv ' . escapeshellarg($dir . '/venv') . ' 2>&1', $o, $rc);
        if ($rc !== 0) continue;
        $pip = $dir . ($win ? '/venv/Scripts/python.exe' : '/venv/bin/python');
        exec(escapeshellarg($pip) . ' -m pip install -q -r ' . escapeshellarg($dir . '/requirements.txt') . ' 2>&1', $o, $rc);
        if ($rc === 0) return '';
        return '파이썬 패키지 설치 실패(daemon/venv): ' . implode(' ', array_slice($o, -5));
    }
    return 'python3 를 찾지 못했습니다 — 서버에 python3(venv 포함)를 설치하거나 config.php 에 envlog_system_python 경로를 적으세요';
}

/** 데몬 응답을 그대로 흘려보낸다. 데몬이 안 떠 있으면 띄워 보고, 그래도 안 되면 이유를 알려 준다 */
function envlog_relay($uid, $method, $path, array $query = [], $body = null, $timeout = 70) {
    [$out, $code, $err] = envlog_call($uid, $method, $path, $query, $body, $timeout);
    if ($out === null && $err !== '' && !preg_match('/timed? ?out/i', $err)) {   // 연결 자체가 안 됨 → 자동 기동
        $why = envlog_ensure_daemon();
        if ($why === '') [$out, $code, $err] = envlog_call($uid, $method, $path, $query, $body, $timeout);
        else $err = $why;
    }
    if ($out === null) {
        http_response_code(502);
        echo json_encode(['error' => 'EnvLog 엔진(envlogd)에 연결하지 못했습니다 (' . envlog_daemon_url() . '). ' . $err], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code($code ?: 200);
    echo $out;
    exit;
}

/**
 * test_srv 원문 → 접속 정보. 형식은 access 모듈이 정해 둔 것(access_norm_test_srv 참고):
 *   1줄 IP[:포트], 2줄 '아이디 / 비밀번호', 그 뒤 줄 중 / 또는 ~ 로 시작하는 줄 = 다중 사이트 폴더,
 *   '--' 로 시작하는 줄은 주석. '-- 운영서버 없음' 주석이면 운영서버를 찾지 않는다.
 */
function envlog_parse_test_srv($text) {
    $lines = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', (string)$text))), 'strlen'));
    $body  = array_values(array_filter($lines, fn($l) => strncmp($l, '--', 2) !== 0));
    $r = ['host' => '', 'port' => 22, 'user' => '', 'password' => '', 'dir' => '',
          'no_ops' => (bool)array_filter($lines, fn($l) => preg_match('/^--\s*운영서버/u', $l))];
    if (count($body) >= 2
        && preg_match('/^([A-Za-z0-9.\-]+)(?:\s*:\s*(\d{1,5}))?$/', $body[0], $m)
        && preg_match('/^([^\/\s:]+)\s*(?:\/|:|\s)\s*(.+)$/u', $body[1], $a)) {
        $r['host'] = $m[1]; $r['port'] = (int)($m[2] ?? 0) ?: 22;
        $r['user'] = $a[1]; $r['password'] = trim($a[2]);
    }
    foreach (array_slice($body, 2) as $l) {
        if ($l[0] === '/' || $l[0] === '~') { $r['dir'] = $l; break; }
    }
    return $r;
}

/** 접속 가능한 대학 목록(비밀번호 포함 — 밖으로 내보낼 때는 뺀다) */
function envlog_schools() {
    $rows = access_db()->query("
        SELECT s.id, s.name, s.ver, s.ops, s.dev, a.vpn, a.test_srv
        FROM schools s JOIN school_access a ON a.school_id = s.id
        WHERE s.active = 1 AND COALESCE(a.test_srv, '') <> ''
        ORDER BY s.name, a.sort_no
    ")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $t = envlog_parse_test_srv(access_dec($r['test_srv']));
        $vpn = trim((string)($r['vpn'] ?? ''));
        $reason = $t['host'] === '' ? '테스트 서버 정보를 읽지 못함 (EnvHub 의 형식: IP[:포트] / 아이디 / 비밀번호)'
                : ($vpn !== '' ? "VPN({$vpn}) 이 필요한 대학 — 포털 서버에서 접속할 수 없습니다" : '');
        $out[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'ver' => (string)($r['ver'] ?? ''),
                  'site' => (string)($r['ops'] ?: $r['dev'] ?: ''), 'vpn' => $vpn,
                  'ok' => $reason === '', 'reason' => $reason] + $t;
    }
    return $out;
}

try {
    switch ($action) {
        case 'schools': {
            if (envlog_schools_from_daemon()) envlog_relay($uid, 'GET', '/schools', array_intersect_key($_GET, array_flip(['q', 'reload'])), null, 60);
            $q = mb_strtolower(trim((string)($_GET['q'] ?? '')));
            $items = [];
            $rows = envlog_schools();
            foreach ($rows as $r) {
                if ($q !== '' && mb_strpos(mb_strtolower($r['name']), $q) === false
                    && mb_strpos(mb_strtolower($r['site']), $q) === false && strpos($r['host'], $q) === false) continue;
                unset($r['password']);
                $items[] = $r;
                if (count($items) >= 30) break;
            }
            echo json_encode(['items' => $items, 'total' => count($rows)], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'state':
            envlog_relay($uid, 'GET', '/state', [], null, 20);
            // no break — relay exits
        case 'connect': {
            if ($method !== 'POST') throw new RuntimeException('POST 만 받습니다');
            $in = json_decode(file_get_contents('php://input'), true) ?: [];
            if (isset($in['school_id']) && envlog_schools_from_daemon()) {
                envlog_relay($uid, 'POST', '/connect', [], json_encode(['school_id' => (int)$in['school_id']]), 130);
            }
            if (isset($in['school_id'])) {
                $school = null;
                foreach (envlog_schools() as $r) if ($r['id'] === (int)$in['school_id']) { $school = $r; break; }
                if (!$school) { http_response_code(404); echo json_encode(['error' => '대학을 찾지 못했습니다'], JSON_UNESCAPED_UNICODE); break; }
                if (!$school['ok']) { http_response_code(400); echo json_encode(['error' => $school['name'] . ': ' . $school['reason']], JSON_UNESCAPED_UNICODE); break; }
            } else {   // 직접 입력
                $host = trim((string)($in['host'] ?? '')); $port = 22;
                if (preg_match('/^([^:\s]+)[: ](\d+)$/', $host, $m)) { $host = $m[1]; $port = (int)$m[2]; }
                $school = ['id' => -1, 'name' => (string)($in['name'] ?? '') ?: $host, 'ver' => '', 'site' => '', 'vpn' => '',
                           'host' => $host, 'port' => (int)($in['port'] ?? 0) ?: $port,
                           'user' => (string)($in['user'] ?? '') ?: 'ubion', 'password' => (string)($in['password'] ?? ''),
                           'dir' => '', 'no_ops' => false];
            }
            if ($school['host'] === '') { http_response_code(400); echo json_encode(['error' => '서버 주소가 없습니다'], JSON_UNESCAPED_UNICODE); break; }
            envlog_relay($uid, 'POST', '/connect', [], json_encode($school, JSON_UNESCAPED_UNICODE), 130);
        }
        case 'disconnect':
            envlog_relay($uid, 'POST', '/disconnect', [], '{}', 20);
        case 'files':
            envlog_relay($uid, 'GET', '/files', array_intersect_key($_GET, array_flip(['server', 'path'])), null, 50);
        case 'logdirs':
            envlog_relay($uid, 'GET', '/logdirs', array_intersect_key($_GET, array_flip(['server', 'root'])), null, 50);
        case 'info':
            envlog_relay($uid, 'GET', '/file/info', array_intersect_key($_GET, array_flip(['server', 'path'])), null, 80);
        case 'chunk':
            envlog_relay($uid, 'GET', '/file/chunk', array_intersect_key($_GET, array_flip(['server', 'path', 'start', 'end'])), null, 80);
        case 'seek':
            envlog_relay($uid, 'GET', '/file/seek', array_intersect_key($_GET, array_flip(['server', 'path', 'key'])), null, 140);
        case 'grep':
            envlog_relay($uid, 'GET', '/file/grep', array_intersect_key($_GET, array_flip(['server', 'path', 'q', 'mode', 'from', 'max'])), null, 320);
        default:
            http_response_code(400);
            echo json_encode(['error' => '알 수 없는 요청: ' . $action], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
