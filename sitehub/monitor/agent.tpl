<?php
/**
 * blumo-agent.php  v1.0  —  BLUMO 사이트 에이전트 (CodeIgniter 3 / XpressEngine·Rhymix 공용)
 * (주)블루소프트
 *
 * 설치 : 사이트 최상위(index.php 와 같은 위치)에 업로드
 * 호출 : https://example.com/blumo-agent.php?key=발급키
 *        https://example.com/blumo-agent.php?key=발급키&mode=ping   (DB 생략, 초경량)
 *
 * 설계 원칙
 *  - 프레임워크를 부트스트랩하지 않는다. 앱이 죽어도 이 파일은 응답해야 원인 판별이 되므로
 *    설정 파일만 "파싱"해서 DB에 직접 붙는다.
 *  - 어떤 경우에도 HTTP 200 + JSON 으로 응답한다. (HTTP 코드는 외부 프로브가 따로 본다)
 *  - 자격증명·절대경로 등 민감정보는 출력하지 않는다.
 */

/* ======================= 설정 ======================= */
$CFG = array(
    // 반드시 변경. 32자 이상 랜덤 문자열 권장
    'key'           => 'CHANGE-THIS-TO-A-LONG-RANDOM-STRING',
    // 비우면 IP 제한 없음. 예: array('1.2.3.4', '5.6.7.8')
    'allow_ips'     => array(),
    // DB 응답이 이 시간을 넘으면 warn (ms)
    'slow_db_ms'    => 300,
    // 디스크 여유가 이 밑이면 warn (MB)
    'min_free_mb'   => 200,
    // PHP 에러로그 경로. 비우면 자동 탐지
    'error_log'     => '',
    // 최근 N분 이내 에러만 집계. BLUMO 가 ?since=(직전 점검 시각) 을 주면 그 뒤만 본다
    'error_window'  => 60,
    // DB 커넥션 사용률이 이 % 넘으면 warn
    'conn_warn_pct' => 80,
    // DB 접속 대기 한도 (초). 넘으면 fail 로 떨어뜨린다
    'db_timeout'    => 5,
    // DB 설정 파일을 직접 지정 (CI3·XE 가 아닌 사이트). 이 파일 기준 상대경로 가능. 예: 'config/config.php'
    'db_config'     => '',
);
/* ==================================================== */

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@error_reporting(E_ALL);
@set_time_limit(25);

$H_T0 = microtime(true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex, nofollow');

/* ---------- 인증 ---------- */
$given = isset($_GET['key']) ? (string)$_GET['key'] : '';
$ok_key = (strlen($given) > 0) && h_hash_equals($CFG['key'], $given);
$ok_ip  = empty($CFG['allow_ips']) || in_array(h_client_ip(), $CFG['allow_ips'], true);

if (!$ok_key || !$ok_ip) {
    header('HTTP/1.1 404 Not Found');
    echo '{"error":"not found"}';
    exit;
}

/* ---------- 실행 ---------- */
$checks = array();
$root   = h_doc_root();

$checks['php'] = h_check_php();

$cms = h_detect_cms($root, $CFG);

if (isset($_GET['mode']) && $_GET['mode'] === 'ping') {
    h_output($cms, $checks, $H_T0, 'ping');
}

$checks['filesystem'] = h_check_fs($root, $cms, $CFG);
$checks['session']    = h_check_session();
$checks['database']   = h_check_db($cms, $CFG);
$checks['errorlog']   = h_check_errorlog($root, $cms, $CFG);

h_output($cms, $checks, $H_T0, 'full');


/* =================== 이하 함수 =================== */

function h_hash_equals($a, $b) {
    if (function_exists('hash_equals')) return hash_equals($a, $b);
    if (strlen($a) !== strlen($b)) return false;
    $r = 0;
    for ($i = 0; $i < strlen($a); $i++) $r |= ord($a[$i]) ^ ord($b[$i]);
    return $r === 0;
}

function h_client_ip() {
    $keys = array('HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR');
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $v = explode(',', $_SERVER[$k]);
            return trim($v[0]);
        }
    }
    return '';
}

function h_doc_root() {
    return rtrim(str_replace('\\', '/', dirname(__FILE__)), '/');
}

function h_ms($t0) {
    return round((microtime(true) - $t0) * 1000, 1);
}

/* ---------- CMS 탐지 ---------- */
function h_detect_cms($root, $CFG) {
    $out = array('type' => 'unknown', 'config' => null, 'root' => $root, 'version' => null);

    if (!empty($CFG['db_config'])) {
        $p = (string)$CFG['db_config'];
        if (!preg_match('#^(/|[A-Za-z]:)#', $p)) $p = $root . '/' . ltrim($p, '/');
        $out['type']   = 'custom';
        $out['config'] = $p;
        return $out;
    }

    // XE / Rhymix
    $xe = $root . '/files/config/db.config.php';
    if (is_file($xe)) {
        $out['type']   = is_file($root . '/common/framework/Config.php') ? 'rhymix' : 'xe';
        $out['config'] = $xe;
        return $out;
    }

    // CI3 : application/config/database.php (앱 폴더명이 다를 수 있어 1단계 스캔)
    $cands = array($root . '/application/config/database.php');
    $dh = @opendir($root);
    if ($dh) {
        while (($f = readdir($dh)) !== false) {
            if ($f === '.' || $f === '..') continue;
            if (!is_dir($root . '/' . $f)) continue;
            $p = $root . '/' . $f . '/config/database.php';
            if (is_file($p)) $cands[] = $p;
        }
        closedir($dh);
    }
    foreach ($cands as $p) {
        if (is_file($p)) {
            $out['type']   = 'ci3';
            $out['config'] = $p;
            $sys = dirname(dirname(dirname($p))) . '/system/core/CodeIgniter.php';
            if (is_file($sys)) {
                $s = @file_get_contents($sys, false, null, 0, 4000);
                if ($s && preg_match("/CI_VERSION'\s*,\s*'([^']+)'/", $s, $m)) $out['version'] = $m[1];
            }
            return $out;
        }
    }

    // 자체 제작 사이트: config/config.php 등에 DB 접속값이 있다. 에이전트를 하위 폴더에 올린 경우가 있어
    // DOCUMENT_ROOT 와 상위 폴더(3단계)까지 찾는다
    $bases = array($root);
    if (!empty($_SERVER['DOCUMENT_ROOT'])) $bases[] = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    for ($d = $root, $i = 0; $i < 3; $i++) { $up = dirname($d); if ($up === $d) break; $bases[] = $d = $up; }
    // 처음 찾은 파일에 DB 값이 없을 수 있다(config/ 에 상수만 두고 접속은 루트 config.php 에서 하는 사이트) — 전부 모아 차례로 읽는다
    $found = array();
    foreach (array_unique($bases) as $b) {
        foreach (array('config/config.php', 'config/database.php', 'config/db.php', 'config.php', 'dbconfig.php', 'db_config.php', 'data/dbconfig.php',
                       'inc/config.php', 'include/config.php', 'common/config.php', 'lib/config.php') as $f) {
            if (is_file($b . '/' . $f)) $found[] = $b . '/' . $f;
        }
    }
    if ($found) {
        $out['type']    = 'custom';
        $out['config']  = $found[0];
        $out['configs'] = $found;
    }
    return $out;
}

/* ---------- DB 설정 파싱 ---------- */
function h_db_config($cms) {
    if (!$cms['config'] || !is_file($cms['config'])) return null;
    if ($cms['type'] === 'ci3')    return h_db_config_ci3($cms['config']);
    if ($cms['type'] === 'custom') {
        foreach (isset($cms['configs']) ? $cms['configs'] : array($cms['config']) as $f) {
            if (($c = h_db_config_text($f)) !== null) return $c;
        }
        return null;
    }
    return h_db_config_xe($cms['config']);
}

/**
 * include 하지 않고 글자로만 읽는다 — 자체 제작 config 는 세션 시작·리다이렉트·DB 접속까지 하는 경우가 있다.
 * define('DB_HOST', '…') · $db_host = '…' · ['hostname'] = '…' · 'hostname' => '…' · (ini/env) DB_HOST=… 꼴을 모은다.
 * config.php 가 DB 설정 파일 경로만 들고 있는 사이트가 있어, 거기 적힌 파일(include·경로 문자열)도 2단계까지 따라가 읽는다.
 */
function h_db_config_text($file) {
    $kv = array();
    $seen = array();
    h_db_collect_kv($file, $kv, $seen, 0);
    $c = array(
        'driver' => h_kv_pick($kv, array('db_driver', 'dbdriver', 'db_type')),
        'host'   => h_kv_pick($kv, array('db_host', 'db_hostname', 'dbhost', 'db_server', 'db_ip', 'dbip', 'db_addr', 'mysql_host', 'mysql_server', 'mysql_ip', 'g5_mysql_host', 'hostname', 'host', 'server')),
        'port'   => h_kv_pick($kv, array('db_port', 'dbport', 'mysql_port', 'port')),
        'user'   => h_kv_pick($kv, array('db_user', 'db_username', 'db_userid', 'db_id', 'dbid', 'dbuser', 'db_login', 'mysql_user', 'mysql_id', 'mysql_userid', 'g5_mysql_user', 'username', 'userid', 'user')),
        'pass'   => h_kv_pick($kv, array('db_pass', 'db_password', 'db_passwd', 'db_pw', 'db_pwd', 'dbpw', 'dbpwd', 'dbpass', 'dbpasswd', 'mysql_password', 'mysql_pass', 'mysql_pw', 'mysql_pwd', 'g5_mysql_password', 'password', 'passwd', 'pass', 'pwd', 'pw')),
        'name'   => h_kv_pick($kv, array('db_name', 'db_database', 'db_dbname', 'dbname', 'db_db', 'mysql_db', 'mysql_dbname', 'mysql_database', 'mysql_name', 'g5_mysql_db', 'database', 'dbase', 'db')),
        'prefix' => h_kv_pick($kv, array('db_prefix', 'dbprefix', 'table_prefix', 'prefix')),
    );
    // 접속 줄의 DB 를 먼저 본다 — select_db 에 옛 이름이 남아 서버에선 실패하는 사이트가 있다(365dodream). 접속 줄에 DB 가 없을 때만 select_db 이름을 쓴다
    $sel = h_kv_pick($kv, array('db_select_name'));
    if ($sel !== null && $sel !== $c['name']) {
        if ($c['name'] === null) $c['name'] = $sel;
        else $c['alt_name'] = $sel;
    }
    if ($c['user'] === null || $c['name'] === null) return null;
    if ($c['host'] === null)   $c['host'] = 'localhost';
    if ($c['pass'] === null)   $c['pass'] = '';
    if ($c['prefix'] === null) $c['prefix'] = '';
    if ($c['driver'] === null || strpos(strtolower($c['driver']), 'mysql') === false) $c['driver'] = 'mysqli';
    return h_db_normalize($c);
}

function h_db_collect_kv($file, &$kv, &$seen, $depth) {
    $real = @realpath($file);
    if ($real === false || isset($seen[$real]) || count($seen) >= 8) return;
    $seen[$real] = true;
    $src = @file_get_contents($real, false, null, 0, 262144);
    if ($src === false || $src === '') return;
    $isPhp = preg_match('/\.(php\d?|inc)$/i', $real) || strpos($src, '<?') !== false;
    $src = $isPhp ? h_strip_php_comments($src) : h_strip_conf_comments($src);   // 주석 처리된 개발·로컬 접속 줄을 빼야 한다

    // mysqli_connect("host", "user", "pass", "db", "port") · new mysqli(…) · mysql_connect(…) — 인자로 바로 넘기는 사이트
    if (preg_match('/(mysqli_connect|mysql_connect|mysqli_real_connect|new\s+mysqli)\s*\(([^;]*?)\)\s*(?:or\b[^;]*)?;/i', $src, $m)) {
        preg_match_all('/\'([^\']*)\'|"([^"]*)"|(\d+)|(\$[A-Za-z0-9_]+)/', $m[2], $tk, PREG_SET_ORDER);
        if (stripos($m[1], 'real_connect') !== false) array_shift($tk);   // 첫 인자는 $link
        $vals = array();
        foreach ($tk as $t) {
            $c = $t[0][0];
            if ($c === '$') { $vals = array(); break; }   // 변수로 넘기면 아래 이름 규칙이 읽는다
            $vals[] = $c === "'" ? $t[1] : ($c === '"' ? $t[2] : $t[3]);
        }
        foreach (array('db_host', 'db_user', 'db_pass', 'db_name', 'db_port') as $i => $n) {
            if (isset($vals[$i]) && !isset($kv[$n])) $kv[$n] = $vals[$i];
        }
    }
    // new PDO('mysql:host=…;port=…;dbname=…', 'user', 'pass')
    if (preg_match('/new\s+PDO\s*\(\s*[\'"]mysql:([^\'"]*)[\'"]\s*(?:,\s*[\'"]([^\'"]*)[\'"])?\s*(?:,\s*[\'"]([^\'"]*)[\'"])?/i', $src, $m)) {
        foreach (explode(';', $m[1]) as $part) {
            $p = explode('=', $part, 2);
            if (count($p) === 2 && !isset($kv['db_' . strtolower(trim($p[0]))])) $kv['db_' . strtolower(trim($p[0]))] = trim($p[1]);
        }
        if (isset($kv['db_dbname']) && !isset($kv['db_name'])) $kv['db_name'] = $kv['db_dbname'];
        if (isset($m[2]) && !isset($kv['db_user'])) $kv['db_user'] = $m[2];
        if (isset($m[3]) && !isset($kv['db_pass'])) $kv['db_pass'] = $m[3];
    }
    // mysqli_select_db($conn, 'name') — 접속 줄의 DB 가 안 될 때 두 번째로 시도할 이름으로만 둔다
    if (preg_match('/(?:mysqli_select_db\s*\(\s*\$[A-Za-z0-9_]+\s*,|mysql_select_db\s*\()\s*[\'"]([^\'"]+)[\'"]/i', $src, $m)) {
        if (!isset($kv['db_select_name'])) $kv['db_select_name'] = $m[1];
    }

    $q = '(?:\'([^\']*)\'|"([^"]*)")';
    $pats = array(
        '/define\s*\(\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*,\s*' . $q . '/',
        '/\$([A-Za-z0-9_]+)\s*=\s*' . $q . '\s*;/',
        '/\[\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*\]\s*=\s*' . $q . '/',
        '/[\'"]([A-Za-z0-9_]+)[\'"]\s*=>\s*' . $q . '/',
    );
    if (!$isPhp) $pats[] = '/^[ \t]*([A-Za-z0-9_.]+)[ \t]*[=:][ \t]*["\']?([^"\'\r\n;#]*)/m';
    foreach ($pats as $re) {
        if (!preg_match_all($re, $src, $m, PREG_SET_ORDER)) continue;
        foreach ($m as $x) {
            $k = strtolower(str_replace('.', '_', $x[1]));
            $v = isset($x[3]) ? $x[3] : $x[2];
            if (!isset($kv[$k])) $kv[$k] = trim($v);
        }
    }

    if ($depth >= 2) return;
    if (!preg_match_all('/[\'"]([^\'"\r\n]{1,200}\.(?:php\d?|inc|ini|conf|cfg|env))[\'"]/i', $src, $m)) return;
    $base = dirname($real);
    $root = h_doc_root();
    foreach (array_unique($m[1]) as $p) {
        $p = str_replace('\\', '/', $p);
        $cands = preg_match('#^(/|[A-Za-z]:)#', $p)
            ? array($p, $base . $p, $root . $p)
            : array($base . '/' . $p, $root . '/' . $p, dirname($root) . '/' . $p);
        foreach ($cands as $c) {
            if (@is_file($c) && @is_readable($c)) { h_db_collect_kv($c, $kv, $seen, $depth + 1); break; }
        }
    }
}

// 주석 · PHP 태그 바깥 글(<!-- --> 포함)을 뺀다 — 실행되지 않는 예전 접속 줄을 먼저 집어 가면 엉뚱한 DB 로 붙는다
function h_strip_php_comments($src) {
    if (function_exists('token_get_all')) {
        $out = '';
        foreach (@token_get_all($src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT || $t[0] === T_INLINE_HTML) continue;
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return $out;
    }
    $src = preg_replace('#<!--.*?-->#s', '', $src);
    $src = preg_replace('#/\*.*?\*/#s', '', $src);
    return preg_replace('#(^|[ \t;{}])(//|\#)[^\r\n]*#m', '$1', $src);
}

function h_strip_conf_comments($src) {
    return preg_replace('/^[ \t]*[;#][^\r\n]*/m', '', $src);
}

function h_kv_pick($kv, $names) {
    foreach ($names as $n) {
        if (isset($kv[$n]) && $kv[$n] !== '') return $kv[$n];
    }
    return null;
}

function h_db_config_ci3($file) {
    // CI3 설정 파일은 defined('BASEPATH') 가드로 시작한다
    if (!defined('BASEPATH'))    define('BASEPATH', dirname(dirname($file)) . '/');
    if (!defined('ENVIRONMENT')) define('ENVIRONMENT', 'production');
    if (!defined('APPPATH'))     define('APPPATH', dirname(dirname($file)) . '/');

    $db = array();
    $active_group = 'default';
    $query_builder = true;

    @include $file;

    if (!is_array($db) || !count($db)) return null;
    $g = (isset($active_group) && isset($db[$active_group])) ? $active_group : null;
    if ($g === null) { $keys = array_keys($db); $g = $keys[0]; }
    $c = $db[$g];

    return h_db_normalize(array(
        'driver' => isset($c['dbdriver']) ? $c['dbdriver'] : 'mysqli',
        'host'   => isset($c['hostname']) ? $c['hostname'] : 'localhost',
        'port'   => isset($c['port']) ? $c['port'] : null,
        'user'   => isset($c['username']) ? $c['username'] : '',
        'pass'   => isset($c['password']) ? $c['password'] : '',
        'name'   => isset($c['database']) ? $c['database'] : '',
        'prefix' => isset($c['dbprefix']) ? $c['dbprefix'] : '',
    ));
}

function h_db_config_xe($file) {
    // XE: if(!defined('__XE__')) exit;  /  Rhymix: RX_VERSION 가드가 있다
    if (!defined('__XE__'))    define('__XE__', true);
    if (!defined('__ZBXE__'))  define('__ZBXE__', true);
    if (!defined('RX_VERSION')) define('RX_VERSION', '2.0');

    $db_info = null;
    $ret = @include $file;

    // Rhymix 계열: 배열을 return
    if (is_array($ret)) {
        $m = isset($ret['master']) ? $ret['master']
           : (isset($ret['db']['master']) ? $ret['db']['master'] : $ret);
        return h_db_normalize(array(
            'driver' => h_pick($m, array('type', 'db_type'), 'mysqli'),
            'host'   => h_pick($m, array('host', 'hostname', 'db_hostname'), 'localhost'),
            'port'   => h_pick($m, array('port', 'db_port'), null),
            'user'   => h_pick($m, array('user', 'username', 'userid', 'db_userid'), ''),
            'pass'   => h_pick($m, array('pass', 'password', 'db_password'), ''),
            'name'   => h_pick($m, array('database', 'dbname', 'db_database'), ''),
            'prefix' => h_pick($m, array('prefix', 'db_table_prefix'), ''),
        ));
    }

    if (is_object($db_info)) $db_info = get_object_vars($db_info);
    if (!is_array($db_info)) return null;

    // XE 1.7+ : master_db 배열 / 그 이전 : 플랫 속성
    $m = isset($db_info['master_db']) && is_array($db_info['master_db']) ? $db_info['master_db'] : $db_info;

    return h_db_normalize(array(
        'driver' => h_pick($m, array('db_type', 'type'), 'mysqli'),
        'host'   => h_pick($m, array('db_hostname', 'host'), 'localhost'),
        'port'   => h_pick($m, array('db_port', 'port'), null),
        'user'   => h_pick($m, array('db_userid', 'user'), ''),
        'pass'   => h_pick($m, array('db_password', 'pass'), ''),
        'name'   => h_pick($m, array('db_database', 'database'), ''),
        'prefix' => h_pick($m, array('db_table_prefix', 'prefix'), ''),
    ));
}

function h_pick($arr, $keys, $def) {
    foreach ($keys as $k) {
        if (is_array($arr) && isset($arr[$k]) && $arr[$k] !== '') return $arr[$k];
    }
    return $def;
}

function h_db_normalize($c) {
    $host = (string)$c['host'];
    $port = $c['port'];
    if (strpos($host, ':') !== false) {
        $p = explode(':', $host, 2);
        $host = $p[0];
        if (!$port) $port = $p[1];
    }
    $c['host'] = $host !== '' ? $host : 'localhost';
    $c['port'] = $port ? (int)$port : 3306;
    $c['driver'] = strtolower((string)$c['driver']);
    return $c;
}

/* ---------- 개별 점검 ---------- */
function h_check_php() {
    return array(
        'status'       => 'ok',
        'version'      => PHP_VERSION,
        'sapi'         => PHP_SAPI,
        'memory_limit' => ini_get('memory_limit'),
        'memory_used'  => round(memory_get_usage(true) / 1048576, 1) . 'MB',
        'server_time'  => date('c'),
    );
}

function h_check_fs($root, $cms, $CFG) {
    $r = array('status' => 'ok');

    $cands = array();
    if ($cms['type'] === 'ci3' && $cms['config']) {
        $app = dirname(dirname($cms['config']));
        $cands[] = $app . '/cache';
        $cands[] = $app . '/logs';
    } else {
        $cands[] = $root . '/files/cache';
        $cands[] = $root . '/files';
    }
    $cands[] = $root;

    $dir = null;
    foreach ($cands as $d) { if (is_dir($d) && is_writable($d)) { $dir = $d; break; } }

    if ($dir === null) {
        $r['status'] = 'fail';
        $r['error']  = 'no writable directory';
        $r['tried']  = count($cands);
        return $r;
    }

    $r['dir'] = str_replace($root, '.', $dir);

    $file = $dir . '/.health_' . getmypid() . '.tmp';
    $data = str_repeat('x', 16384); // 16KB
    $t0 = microtime(true);
    $w = @file_put_contents($file, $data);
    $r['write_ms'] = h_ms($t0);

    if ($w === false || $w < strlen($data)) {
        $r['status'] = 'fail';
        $r['error']  = 'write failed (디스크 용량 초과 또는 권한)';
    } else {
        $t1 = microtime(true);
        $rd = @file_get_contents($file);
        $r['read_ms'] = h_ms($t1);
        if ($rd !== $data) { $r['status'] = 'fail'; $r['error'] = 'readback mismatch'; }
    }
    @unlink($file);

    $free  = @disk_free_space($dir);
    $total = @disk_total_space($dir);
    if ($free !== false) {
        $r['free_mb'] = round($free / 1048576);
        if ($total) $r['used_pct'] = round(($total - $free) / $total * 100, 1);
        if ($r['free_mb'] < $CFG['min_free_mb'] && $r['status'] === 'ok') {
            $r['status'] = 'warn';
            $r['error']  = 'low disk space';
        }
    }
    if ($r['write_ms'] > 1000 && $r['status'] === 'ok') {
        $r['status'] = 'warn';
        $r['error']  = 'slow disk I/O';
    }
    return $r;
}

function h_check_session() {
    $r = array('status' => 'ok');
    $path = ini_get('session.save_path');
    $handler = ini_get('session.save_handler');
    $r['handler'] = $handler ? $handler : 'files';
    if ($r['handler'] !== 'files') { $r['path'] = '(non-file handler)'; return $r; }

    if (!$path) $path = sys_get_temp_dir();
    if (strpos($path, ';') !== false) { $p = explode(';', $path); $path = array_pop($p); }
    $r['writable'] = is_dir($path) && is_writable($path);
    if (!$r['writable']) {
        $r['status'] = 'fail';
        $r['error']  = 'session save_path not writable (로그인 전면 실패 원인)';
    }
    return $r;
}

function h_check_db($cms, $CFG) {
    $r = array('status' => 'ok');
    $cfg = h_db_config($cms);

    if (!$cfg) {
        if (!$cms['config']) {
            // 설정 파일 위치를 모르는 사이트 — 장애가 아니므로 경고로 올리지 않는다. 필요하면 $CFG['db_config'] 로 지정
            $r['status'] = 'skip';
            $r['note']   = 'db config not located (set $CFG[db_config] to enable)';
            return $r;
        }
        $r['status'] = 'warn';
        $r['error']  = 'db config not found or unreadable';
        $root = h_doc_root();
        $r['tried']  = array_map(function ($f) use ($root) { return strpos($f, $root . '/') === 0 ? substr($f, strlen($root) + 1) : $f; },
                                 isset($cms['configs']) ? $cms['configs'] : array($cms['config']));
        return $r;
    }
    $r['driver'] = $cfg['driver'];
    $r['host']   = $cfg['host'];
    $r['name']   = $cfg['name'];

    if (strpos($cfg['driver'], 'mysql') === false) {
        $r['status'] = 'warn';
        $r['error']  = 'unsupported driver for health check';
        return $r;
    }

    $tmo = isset($CFG['db_timeout']) ? (int)$CFG['db_timeout'] : 5;
    $t0 = microtime(true);
    $link = null;
    $pdo  = null;

    // PHP 8.1+ 는 mysqli 오류를 예외로 던진다. 헬스체크가 죽으면 안 되므로 끈다.
    if (function_exists('mysqli_report')) { @mysqli_report(MYSQLI_REPORT_OFF); }

    if (function_exists('mysqli_init')) {
        $link = @mysqli_init();
        $connected = false;
        $errno = 0; $errmsg = '';
        foreach (h_db_names($cfg) as $ni => $dbname) {
        if ($ni > 0) {
            if (!in_array($errno, array(1044, 1049), true)) break;
            $link = @mysqli_init();
            $errno = 0; $errmsg = '';
        }
        if ($link) {
            @mysqli_options($link, MYSQLI_OPT_CONNECT_TIMEOUT, $tmo);
            if (defined('MYSQLI_OPT_READ_TIMEOUT')) @mysqli_options($link, MYSQLI_OPT_READ_TIMEOUT, $tmo + 5);
            try {
                $connected = @mysqli_real_connect($link, $cfg['host'], $cfg['user'], $cfg['pass'], $dbname, $cfg['port']);
            } catch (Exception $e) {
                $connected = false;
                $errmsg = $e->getMessage();
                $errno  = (int)$e->getCode();
            }
            if (!$connected) {
                if (!$errno)  $errno  = (int)mysqli_connect_errno();
                if (!$errmsg) $errmsg = (string)mysqli_connect_error();
            }
        }
        if ($connected) { $r['name'] = $dbname; break; }
        }
        if (!$connected) {
            $r['status']     = 'fail';
            $r['connect_ms'] = h_ms($t0);
            $r['errno']      = $errno;
            $r['error']      = h_db_reason($errno, $errmsg);
            return $r;
        }
    } elseif (class_exists('PDO')) {
        try {
            $names = h_db_names($cfg);
            foreach ($names as $ni => $dbname) {
                try {
                    $dsn = 'mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'] . ';dbname=' . $dbname;
                    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], array(
                        PDO::ATTR_TIMEOUT => $tmo,
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                    ));
                    $r['name'] = $dbname;
                    break;
                } catch (Exception $e) {
                    $no = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : (int)$e->getCode();
                    if ($ni + 1 >= count($names) || !in_array($no, array(1044, 1049), true)) throw $e;
                }
            }
        } catch (Exception $e) {
            $r['status']     = 'fail';
            $r['connect_ms'] = h_ms($t0);
            $r['errno']      = (int)$e->getCode();
            $r['error']      = h_db_reason((int)$e->getCode(), $e->getMessage());
            return $r;
        }
    } else {
        $r['status'] = 'warn';
        $r['error']  = 'no mysqli/pdo extension';
        return $r;
    }
    $r['connect_ms'] = h_ms($t0);

    // 왕복 쿼리
    $t1 = microtime(true);
    $qok = false;
    try {
        $qok = $link ? (bool)@mysqli_query($link, 'SELECT 1') : (bool)@$pdo->query('SELECT 1');
    } catch (Exception $e) { $qok = false; }
    $r['query_ms'] = h_ms($t1);
    if (!$qok) {
        $r['status'] = 'fail';
        $r['error']  = 'SELECT 1 failed';
    }

    // 실제 테이블 접근 (권한/테이블 손상 확인)
    $t2 = microtime(true);
    $tables = 0;
    try {
        if ($link) {
            $res = @mysqli_query($link, 'SHOW TABLES');
            if ($res) { $tables = mysqli_num_rows($res); mysqli_free_result($res); }
        } else {
            $res = @$pdo->query('SHOW TABLES');
            if ($res) $tables = count($res->fetchAll(PDO::FETCH_NUM));
        }
    } catch (Exception $e) { $tables = 0; }
    $r['tables']       = $tables;
    $r['show_table_ms'] = h_ms($t2);
    if ($tables === 0 && $r['status'] === 'ok') {
        $r['status'] = 'fail';
        $r['error']  = 'no tables visible (권한 또는 DB 누락)';
    }

    // 커넥션 사용률 — 카페24 공유호스팅의 대표 장애 원인
    $vars = h_db_kv($link, $pdo, "SHOW VARIABLES LIKE 'max_connections'");
    $stat = h_db_kv($link, $pdo, "SHOW STATUS LIKE 'Threads_connected'");
    if ($vars && $stat) {
        $max = (int)reset($vars);
        $cur = (int)reset($stat);
        $r['connections'] = $cur . '/' . $max;
        if ($max > 0) {
            $pct = round($cur / $max * 100, 1);
            $r['conn_pct'] = $pct;
            if ($pct >= $CFG['conn_warn_pct'] && $r['status'] === 'ok') {
                $r['status'] = 'warn';
                $r['error']  = 'DB connection pool near limit';
            }
        }
    }

    if ($r['status'] === 'ok' && ($r['connect_ms'] + $r['query_ms']) > $CFG['slow_db_ms']) {
        $r['status'] = 'warn';
        $r['error']  = 'slow database';
    }

    if ($link) @mysqli_close($link);
    $pdo = null;
    return $r;
}

function h_db_names($cfg) {
    $names = array($cfg['name']);
    if (!empty($cfg['alt_name']) && $cfg['alt_name'] !== $cfg['name']) $names[] = $cfg['alt_name'];
    return $names;
}

function h_db_kv($link, $pdo, $sql) {
    $out = array();
    try {
    if ($link) {
        $res = @mysqli_query($link, $sql);
        if (!$res) return null;
        while ($row = mysqli_fetch_row($res)) $out[$row[0]] = $row[1];
        mysqli_free_result($res);
    } else {
        $res = @$pdo->query($sql);
        if (!$res) return null;
        foreach ($res->fetchAll(PDO::FETCH_NUM) as $row) $out[$row[0]] = $row[1];
    }
    } catch (Exception $e) { return null; }
    return $out ? $out : null;
}

function h_db_reason($errno, $msg) {
    $map = array(
        1040 => 'Too many connections — 동시접속 한도 초과',
        1044 => 'Access denied to database — 계정에 해당 DB 권한 없음(DB 이름 확인)',
        1045 => 'Access denied — 계정/비밀번호 변경됨',
        1049 => 'Unknown database — DB 삭제 또는 이름 변경',
        2002 => 'Connection refused — DB 서버 다운 또는 소켓 경로 오류',
        2003 => "Can't connect — DB 호스트 접근 불가(방화벽/서버 다운)",
        2005 => 'Unknown host — DB 호스트명 오류',
        2006 => 'Server has gone away',
        2013 => 'Lost connection during query',
        1203 => 'User has exceeded max_user_connections — 계정 동시접속 한도',
        1226 => 'User resource limit exceeded — 호스팅 계정 쿼터 초과',
    );
    $base = isset($map[$errno]) ? $map[$errno] : 'connect failed';
    return $base . ' (' . $errno . ')';
}

function h_check_errorlog($root, $cms, $CFG) {
    $r = array('status' => 'ok', 'recent' => 0);
    $since = time() - ($CFG['error_window'] * 60);
    $given = isset($_GET['since']) && ctype_digit((string)$_GET['since']) ? (int)$_GET['since'] : 0;
    $bySince = $given > time() - 7 * 86400 && $given <= time() + 300;
    if ($bySince) $since = $given;

    $files = array();
    if ($CFG['error_log']) {
        $files[] = $CFG['error_log'];
    } else {
        $ini = @ini_get('error_log');
        if ($ini && $ini !== 'syslog' && is_file($ini)) $files[] = $ini;
        foreach (array('php_error.log', 'error_log', 'php_errors.log') as $n) {
            if (is_file($root . '/' . $n)) $files[] = $root . '/' . $n;
        }
        if ($cms['type'] === 'ci3' && $cms['config']) {
            $logdir = dirname(dirname($cms['config'])) . '/logs';
            $f = $logdir . '/log-' . date('Y-m-d') . '.php';
            if (is_file($f)) $files[] = $f;
        }
    }
    $files = array_unique($files);
    if (!$files) { $r['note'] = 'no readable error log (PHP 에러로그 설정 권장)'; return $r; }

    $last = '';
    foreach ($files as $f) {
        $tail = h_tail($f, 60000);
        if ($tail === '') continue;
        $lines = explode("\n", $tail);
        foreach ($lines as $ln) {
            if ($ln === '') continue;
            $isErr = (stripos($ln, 'PHP Fatal') !== false)
                  || (stripos($ln, 'PHP Parse error') !== false)
                  || (stripos($ln, 'Uncaught') !== false)
                  || (strpos($ln, 'ERROR -') !== false)
                  || (strpos($ln, 'CRITICAL -') !== false);
            if (!$isErr) continue;
            $ts = h_line_time($ln);
            if ($ts === null && $bySince) continue;   // 시각 없는 줄은 매 점검마다 다시 세게 되므로 직전 점검 기준일 땐 뺀다
            if ($ts !== null && $ts <= $since) continue;
            $r['recent']++;
            $last = $ln;
        }
    }
    if ($r['recent'] > 0) {
        $r['status'] = 'warn';
        $r['last']   = substr(preg_replace('/\s+/', ' ', $last), 0, 300);
        $r['window'] = $bySince ? 'since ' . date('Y-m-d H:i:s', $since) : $CFG['error_window'] . 'm';
    }
    if ($r['recent'] >= 20) $r['status'] = 'fail';
    return $r;
}

function h_tail($file, $bytes) {
    $fp = @fopen($file, 'rb');
    if (!$fp) return '';
    $size = filesize($file);
    if ($size > $bytes) @fseek($fp, -$bytes, SEEK_END);
    $data = @stream_get_contents($fp);
    @fclose($fp);
    return $data === false ? '' : $data;
}

function h_line_time($ln) {
    // "[14-Sep-2026 10:11:12 Asia/Seoul]" 또는 "ERROR - 2026-09-14 10:11:12"
    // 시간대 이름(Asia/Seoul·UTC)이 붙어 있으면 함께 넘긴다 — 사이트 PHP 기본 시간대와 로그 시간대가 다르면 since 비교가 어긋난다
    if (preg_match('/\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}(?: [A-Za-z_]+(?:\/[A-Za-z_]+)*)?)\]/', $ln, $m)
        || preg_match('/\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2})/', $ln, $m)) {
        $t = @strtotime($m[1]);
        return $t ? $t : null;
    }
    if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $ln, $m)) {
        $t = strtotime($m[1]);
        return $t ? $t : null;
    }
    return null;
}

/* ---------- 출력 ---------- */
function h_output($cms, $checks, $t0, $mode) {
    $fails = array();
    $warns = array();
    foreach ($checks as $name => $c) {
        if (!isset($c['status'])) continue;
        if ($c['status'] === 'fail') $fails[] = $name;
        if ($c['status'] === 'warn') $warns[] = $name;
    }
    $status = count($fails) ? 'fail' : (count($warns) ? 'warn' : 'ok');

    $out = array(
        'ok'      => ($status !== 'fail'),
        'status'  => $status,
        'mode'    => $mode,
        'cms'     => $cms['type'],
        'cms_ver' => $cms['version'],
        'fails'   => $fails,
        'warns'   => $warns,
        'took_ms' => h_ms($t0),
        'ts'      => date('c'),
        'checks'  => $checks,
    );

    if (defined('JSON_UNESCAPED_UNICODE')) {
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        echo json_encode($out);
    }
    exit;
}
