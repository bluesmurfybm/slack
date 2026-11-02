<?php
/**
 * BLUMO 점검 엔진 — 예전 D:\00_CIboard_package\004_blumo\blumo.php(폴더는 지웠다)를 SiteHub 안으로 옮긴 것.
 *
 *  - 감시 대상: monitor=1 이면서 보관 안 된 사이트의 감시 주소(lab_site_monitors, enabled=1).
 *  - 점검 한 번 = 외부에서 주소를 열어 증상을 가르고(DNS/TLS/상태코드/트래픽 차단/백지…),
 *    에이전트(blumo-agent.php)가 붙어 있으면 DB·디스크·세션·에러로그로 원인까지 한 줄로 만든다.
 *  - 기록: lab_monitor_checks(점검마다) · lab_monitor_state(주소별 현재 상태) · lab_monitor_incidents(확정된 장애).
 *  - 알림: 상태가 바뀔 때만 웹훅(Slack #blumo). confirm_failures 회 연속 실패해야 장애로 확정, 장애가 이어지면
 *    repeat_alert_min 분마다 재알림, 복구되면 지속시간과 함께 알림. 무음(mute_until) 중이면 기록만.
 *  - 설정은 lab_sync_meta 의 blumo_* (웹훅 주소는 enc:v1 암호문). 화면은 monitor.php › 설정(지정 계정).
 *  - 자동 점검은 작업 스케줄러가 monitor_run.php 를 5분마다 부른다(tools/monitor-task.bat).
 *
 * 함수 이름 blumo_* — 판정 규칙(blumo_classify · blumo_cause)은 예전 BLUMO 와 같다. 바꾸면 장애 이력의 뜻이 달라진다.
 */

const BLUMO_DEFAULTS = [
    'timeout' => 15, 'health_timeout' => 20, 'slow_ms' => 5000, 'cert_warn_days' => 14,
    'confirm_failures' => 2, 'repeat_alert_min' => 60, 'alert_on_warn' => 0, 'webhook_format' => 'slack',
    'retention_days' => 180,
];
const BLUMO_RANGES = [   // 설정 화면에서 받는 값의 범위
    'timeout' => [3, 60], 'health_timeout' => [3, 60], 'slow_ms' => [500, 60000], 'cert_warn_days' => [1, 90],
    'confirm_failures' => [1, 10], 'repeat_alert_min' => [5, 1440], 'retention_days' => [30, 730],
];
const BLUMO_UP_CLASSES = ['OK', 'SLOW', 'TLS_EXPIRING'];   // 이 셋은 '살아 있음' — 장애가 아니다

/** config.php — 화면은 guard.php 의 sitehub_config(), CLI(monitor_run.php)는 세션 없이 직접 읽는다 */
function blumo_site_config(): array {
    static $c = null;
    if ($c === null) $c = function_exists('sitehub_config') ? sitehub_config() : (require __DIR__ . '/../config.php');
    return is_array($c) ? $c : [];
}

/* ── 설정 ─────────────────────────────────────────────────────────────────────── */

function blumo_meta_get(PDO $pdo, string $k, $def = null) {
    $st = $pdo->prepare("SELECT v FROM lab_sync_meta WHERE k = ?");
    $st->execute(['blumo_' . $k]);
    $v = $st->fetchColumn();
    return $v === false ? $def : $v;
}

function blumo_meta_set(PDO $pdo, string $k, $v): void {
    $pdo->prepare("INSERT INTO lab_sync_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute(['blumo_' . $k, (string)$v]);
}

/** 점검에 쓰는 설정 한 벌 — 화면에서 저장한 값 > 기본값 */
function blumo_cfg(PDO $pdo): array {
    $c = BLUMO_DEFAULTS;
    foreach ($pdo->query("SELECT k, v FROM lab_sync_meta WHERE k LIKE 'blumo\\_%'") as $r) {
        $k = substr($r['k'], 6);
        if (array_key_exists($k, BLUMO_DEFAULTS) && $r['v'] !== '') $c[$k] = is_int(BLUMO_DEFAULTS[$k]) ? (int)$r['v'] : $r['v'];
        if ($k === 'webhook_url') $c['webhook_url'] = sitehub_dec($r['v']);
        if ($k === 'paused') $c['paused'] = $r['v'] === '1';
    }
    $c['webhook_url'] = $c['webhook_url'] ?? '';
    $c['paused'] = $c['paused'] ?? false;
    $cfg = blumo_site_config();
    $c['ca_bundle'] = !empty($cfg['sitehub_blumo_ca']) ? $cfg['sitehub_blumo_ca'] : __DIR__ . '/monitor/cacert.pem';
    return $c;
}

/**
 * HTTPS 인증서 검증용 CA — 윈도우 PHP 는 CA 묶음이 없어 모든 https 가 SSL 오류(60)로 실패하는 게 기본이라
 * monitor/cacert.pem(Mozilla 묶음)을 쓴다. 인증서 검증을 끄면 TLS_FAIL(만료·도메인 불일치)을 못 잡는다.
 */
function blumo_ca($ch, array $cfg): void {
    if (!empty($cfg['ca_bundle']) && is_file($cfg['ca_bundle'])) curl_setopt($ch, CURLOPT_CAINFO, $cfg['ca_bundle']);
    elseif (DIRECTORY_SEPARATOR === '\\' && defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
}

/* ── 대상 ─────────────────────────────────────────────────────────────────────── */

/** 감시 대상. $id 를 주면 그 주소 하나(멈춤·감시 끔이어도 — 사람이 누른 점검), 아니면 자동 점검할 것만 */
function blumo_targets(PDO $pdo, ?int $id = null): array {
    $sql = "SELECT m.*, s.name AS site_name, s.monitor AS site_monitor, s.archived AS site_archived
            FROM lab_site_monitors m JOIN lab_sites s ON s.id = m.site_id";
    if ($id !== null) {
        $st = $pdo->prepare($sql . " WHERE m.id = ?");
        $st->execute([$id]);
    } else {
        $st = $pdo->query($sql . " WHERE s.monitor = 1 AND s.archived = 0 AND m.enabled = 1 ORDER BY s.sort_no, s.name, s.id, m.sort_no, m.id");
    }
    $out = [];
    foreach ($st->fetchAll() as $m) $out[] = blumo_target_row($m);
    return $out;
}

function blumo_target_row(array $m): array {
    $m['id'] = (int)$m['id'];
    $m['site_id'] = (int)$m['site_id'];
    $m['name'] = trim($m['site_name'] . ($m['label'] !== '' ? ' (' . $m['label'] . ')' : ''));
    $m['url'] = sitehub_monitor_url($m['url']);
    $m['health_key'] = sitehub_dec((string)$m['health_key']);
    foreach (['slow_ms', 'timeout', 'min_size'] as $c) $m[$c] = $m[$c] === null ? null : (int)$m[$c];
    return $m;
}

/* ── 점검 ─────────────────────────────────────────────────────────────────────── */

function blumo_probe(array $m, array $cfg): array {
    $timeout = $m['timeout'] ?? $cfg['timeout'];
    $body = '';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $m['url'], CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout), CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CERTINFO => true, CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Blumo/1.0 (+bluesoft.co.kr)', CURLOPT_HEADER => false,
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body) {
            if (strlen($body) < 131072) $body .= $chunk;   // 앞 128KB만
            return strlen($chunk);
        },
    ]);
    blumo_ca($ch, $cfg);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $i = curl_getinfo($ch);
    curl_close($ch);

    $g = fn($k) => isset($i[$k]) ? (float)$i[$k] : 0.0;
    $appc = $g('appconnect_time') > 0 ? $g('appconnect_time') : $g('connect_time');
    $r = [
        'ts' => time(), 'errno' => $errno, 'err' => $err,
        'code' => (int)($i['http_code'] ?? 0), 'final_url' => $i['url'] ?? $m['url'],
        'dns_ms' => (int)round($g('namelookup_time') * 1000),
        'connect_ms' => (int)round(($g('connect_time') - $g('namelookup_time')) * 1000),
        'tls_ms' => (int)round(($appc - $g('connect_time')) * 1000),
        'ttfb_ms' => (int)round($g('starttransfer_time') * 1000),
        'total_ms' => (int)round($g('total_time') * 1000),
        'size' => isset($i['size_download']) ? (int)$i['size_download'] : strlen($body),
        'cert_days' => blumo_cert_days($i), 'body' => $body, 'health' => null,
    ];
    $r['class'] = blumo_classify($m, $cfg, $r);
    return $r;
}

function blumo_cert_days(array $i): ?int {
    if (empty($i['certinfo'])) return null;
    foreach ($i['certinfo'] as $c) {
        foreach (['Expire date', 'Expire Date', 'expire date'] as $k) {
            if (!empty($c[$k]) && ($t = strtotime($c[$k]))) return (int)floor(($t - time()) / 86400);
        }
    }
    return null;
}

function blumo_classify(array $m, array $cfg, array $r): string {
    // 1. 네트워크 계층
    if ($r['errno']) {
        switch ($r['errno']) {
            case 6:  return 'DNS_FAIL';
            case 7:  return 'CONNECT_FAIL';
            case 28: return 'TIMEOUT';
            case 35: case 51: case 58: case 59: case 60: case 83: return 'TLS_FAIL';
            case 52: return 'EMPTY_REPLY';
            case 56: return 'RECV_ERROR';
            default: return 'NET_ERR_' . $r['errno'];
        }
    }
    $body = $r['body'];
    $fin = $r['final_url'];
    // 2. 카페24 트래픽 초과 차단 (전용 안내 페이지로 리다이렉트된다)
    if (stripos($fin, 'hostinfo.cafe24.com') !== false || stripos($fin, 'overTraffic') !== false
        || (strpos($body, '트래픽') !== false && strpos($body, '초과') !== false)) return 'CAFE24_TRAFFIC';
    // 3. 호스팅 계정 정지 / 만료
    if (stripos($fin, 'expire') !== false || (strpos($body, '서비스') !== false && strpos($body, '만료') !== false)) return 'HOSTING_SUSPENDED';
    // 4. 애플리케이션 DB 오류 (CI3 / XE 특유 문구)
    foreach (['A Database Error Occurred', 'Unable to connect to your database', 'DB 연결에 실패', 'database connect fail',
              'Too many connections', 'Access denied for user', "Can't connect to MySQL"] as $s) {
        if (stripos($body, $s) !== false) return 'APP_DB_ERROR';
    }
    // 5. PHP 치명적 오류가 화면에 노출
    if (stripos($body, 'Fatal error') !== false || stripos($body, 'Parse error') !== false || stripos($body, 'Allowed memory size') !== false) return 'PHP_FATAL';
    // 6. HTTP 상태
    if ($r['code'] >= 500) return 'HTTP_' . $r['code'];
    if ($r['code'] === 403) return 'HTTP_403';
    if ($r['code'] === 404) return 'HTTP_404';
    if ($r['code'] === 0) return 'NO_RESPONSE';
    if ($r['code'] >= 400) return 'HTTP_' . $r['code'];
    // 7. 200 인데 내용이 비정상 (백지 / 변조 / 템플릿 깨짐)
    if ($r['size'] < ($m['min_size'] ?? 500)) return 'EMPTY_BODY';
    if (($m['expect'] ?? '') !== '' && strpos($body, $m['expect']) === false) return 'CONTENT_MISMATCH';
    // 8. 인증서 만료 임박
    if ($r['cert_days'] !== null && $r['cert_days'] <= $cfg['cert_warn_days']) return 'TLS_EXPIRING';
    // 9. 느림
    if ($r['total_ms'] > ($m['slow_ms'] ?? $cfg['slow_ms'])) return 'SLOW';
    return 'OK';
}

/** 에이전트 — 여기서 "증상"이 "원인"으로 바뀐다 */
function blumo_health(array $m, array $cfg, array $r): array {
    $url = $m['health'] . (strpos($m['health'], '?') === false ? '?' : '&') . 'key=' . rawurlencode($m['health_key']);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => $cfg['health_timeout'],
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'Blumo/1.0 (+bluesoft.co.kr)',
    ]);
    blumo_ca($ch, $cfg);
    $out = curl_exec($ch);
    $hc = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($out === false || $hc !== 200) { $r['health'] = ['status' => 'unreachable', 'http' => $hc]; return $r; }
    $j = json_decode($out, true);
    $r['health'] = is_array($j) ? $j : ['status' => 'unparsable'];
    return $r;
}

/** 증상 + 내부 지표 → 사람이 읽는 원인 한 줄 */
function blumo_cause(array $r): string {
    $h = $r['health'];
    $hint = '';
    if (is_array($h) && isset($h['checks'])) {
        $c = $h['checks'];
        if (($c['database']['status'] ?? '') === 'fail') $hint = 'DB: ' . ($c['database']['error'] ?? 'fail');
        elseif (($c['filesystem']['status'] ?? '') === 'fail') $hint = '디스크/쓰기권한: ' . ($c['filesystem']['error'] ?? 'fail');
        elseif (($c['session']['status'] ?? '') === 'fail') $hint = '세션 저장 실패';
        elseif (($c['errorlog']['recent'] ?? 0) > 0) $hint = 'PHP 에러 ' . $c['errorlog']['recent'] . '건 (' . substr((string)($c['errorlog']['last'] ?? ''), 0, 120) . ')';
        elseif (($c['database']['status'] ?? '') === 'warn') $hint = 'DB 경고: ' . ($c['database']['error'] ?? '') . (isset($c['database']['connections']) ? ' [' . $c['database']['connections'] . ']' : '');
    } elseif (is_array($h) && ($h['status'] ?? '') === 'unreachable') {
        $hint = '에이전트 응답 없음 → 웹서버/PHP 레벨 문제';
    }
    $map = [
        'OK' => '정상', 'SLOW' => '응답 지연',
        'DNS_FAIL' => 'DNS 조회 실패 — 도메인 만료 또는 네임서버 문제',
        'CONNECT_FAIL' => '서버 연결 거부 — 웹서버 중단 또는 IP 차단',
        'TIMEOUT' => '응답 시간 초과 — 서버 과부하 또는 무한대기',
        'TLS_FAIL' => 'SSL 인증서 오류 — 만료/도메인 불일치/체인 누락',
        'TLS_EXPIRING' => 'SSL 인증서 만료 임박',
        'EMPTY_REPLY' => '서버가 응답 없이 연결 종료', 'RECV_ERROR' => '수신 중 연결 끊김',
        'CAFE24_TRAFFIC' => '카페24 일일 전송량(트래픽) 초과 차단',
        'HOSTING_SUSPENDED' => '호스팅 서비스 만료/정지 추정',
        'APP_DB_ERROR' => 'DB 연결 실패 메시지가 화면에 노출됨',
        'PHP_FATAL' => 'PHP 치명적 오류 노출',
        'CONTENT_MISMATCH' => '200이지만 기대 문구 없음 — 백지/변조/템플릿 깨짐',
        'EMPTY_BODY' => '응답 본문이 비정상적으로 작음', 'NO_RESPONSE' => '응답 없음',
        'HTTP_403' => '403 — 접근 차단(권한/방화벽/WAF)', 'HTTP_404' => '404 — 경로 또는 리라이트 설정 문제',
        'HTTP_500' => '500 — 애플리케이션 오류', 'HTTP_502' => '502 — 백엔드(PHP-FPM) 응답 불가',
        'HTTP_503' => '503 — 서버 과부하 또는 동시접속 한도', 'HTTP_504' => '504 — 백엔드 타임아웃',
    ];
    $base = $map[$r['class']] ?? $r['class'];
    if (!$hint) return $base;
    // 페이지는 정상인데 에이전트만 이상 → 선행 경보로 표시
    if (in_array($r['class'], BLUMO_UP_CLASSES, true)) return $base . ' (에이전트 경고: ' . $hint . ')';
    return $base . ' | ' . $hint;
}

/** 주소 하나 점검 → 기록 · 상태 · 알림. 사람이 누른 점검도 같은 길 */
function blumo_check(PDO $pdo, array $m, array $cfg): array {
    $r = blumo_probe($m, $cfg);
    if (($m['health'] ?? '') !== '' && ($m['health_key'] ?? '') !== '') $r = blumo_health($m, $cfg, $r);
    $r['cause'] = blumo_cause($r);
    blumo_store($pdo, $m, $r);
    blumo_state($pdo, $cfg, $m, $r);
    return $r;
}

function blumo_store(PDO $pdo, array $m, array $r): void {
    $snippet = trim(preg_replace('/\s+/u', ' ', strip_tags(substr($r['body'], 0, 2000))) ?? '');
    if (!preg_match('//u', $snippet)) $snippet = @mb_convert_encoding($snippet, 'UTF-8', 'EUC-KR') ?: '';
    $pdo->prepare("INSERT INTO lab_monitor_checks (monitor_id, site_id, at, class, cause, code, dns_ms, connect_ms, tls_ms, ttfb_ms, total_ms, size, cert_days, health, snippet)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$m['id'], $m['site_id'], date('Y-m-d H:i:s', $r['ts']), $r['class'], mb_substr($r['cause'], 0, 500), $r['code'],
                   $r['dns_ms'], $r['connect_ms'], $r['tls_ms'], $r['ttfb_ms'], $r['total_ms'], $r['size'], $r['cert_days'],
                   $r['health'] ? json_encode($r['health'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                   in_array($r['class'], ['OK'], true) ? null : mb_substr($snippet, 0, 500)]);
}

/** 상태 전이 + 알림. 무음(mute_until) 중이면 기록·상태만 바꾸고 알림은 보내지 않는다 */
function blumo_state(PDO $pdo, array $cfg, array $m, array $r): void {
    $isDown = !in_array($r['class'], BLUMO_UP_CLASSES, true);
    $isWarn = in_array($r['class'], ['SLOW', 'TLS_EXPIRING'], true);
    $now = time();
    $st = $pdo->prepare("SELECT * FROM lab_monitor_state WHERE monitor_id = ?");
    $st->execute([$m['id']]);
    $row = $st->fetch() ?: ['status' => 'up', 'since' => date('Y-m-d H:i:s', $now), 'fail_count' => 0, 'last_alert' => null];
    $muted = !empty($m['mute_until']) && strtotime($m['mute_until']) > $now;
    $send = function ($title, $body, $level) use ($cfg, $muted) { return $muted ? false : blumo_webhook($cfg, $title, $body, $level); };

    $fail = $isDown ? (int)$row['fail_count'] + 1 : 0;
    $status = $row['status'] ?: 'up';
    $alert = $row['last_alert'] ? strtotime($row['last_alert']) : 0;
    $since = $row['since'] ? strtotime($row['since']) : $now;
    $repeat = $cfg['repeat_alert_min'] * 60;

    if ($isDown && $fail >= $cfg['confirm_failures'] && $status !== 'down') {
        $status = 'down';
        $pdo->prepare("INSERT INTO lab_monitor_incidents (monitor_id, site_id, started, class, cause) VALUES (?,?,?,?,?)")
            ->execute([$m['id'], $m['site_id'], date('Y-m-d H:i:s', $now), $r['class'], mb_substr($r['cause'], 0, 500)]);
        $send('장애 발생 · ' . $m['name'], blumo_msg($m, $r, null), 'down');
        $alert = $now;
    } elseif ($isDown && $status === 'down' && $now - $alert >= $repeat) {
        $send('장애 지속 · ' . $m['name'] . ' (' . blumo_dur($now - $since) . ')', blumo_msg($m, $r, null), 'down');
        $alert = $now;
    } elseif (!$isDown && $status === 'down') {
        $dur = blumo_dur($now - $since);
        $pdo->prepare("UPDATE lab_monitor_incidents SET ended = ? WHERE monitor_id = ? AND ended IS NULL")->execute([date('Y-m-d H:i:s', $now), $m['id']]);
        $send('복구 · ' . $m['name'], blumo_msg($m, $r, $dur), 'up');
        $status = 'up';
        $alert = $now;
    } elseif ($isWarn && $status === 'up' && $now - $alert >= $repeat && $cfg['alert_on_warn']) {
        $send('경고 · ' . $m['name'], blumo_msg($m, $r, null), 'warn');
        $alert = $now;
    }
    if ($status !== ($row['status'] ?: 'up')) $since = $now;

    $pdo->prepare("INSERT INTO lab_monitor_state (monitor_id, site_id, status, since, fail_count, last_alert, class, cause, code, total_ms, cert_days, checked_at, updated_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE site_id=VALUES(site_id), status=VALUES(status), since=VALUES(since), fail_count=VALUES(fail_count),
                     last_alert=VALUES(last_alert), class=VALUES(class), cause=VALUES(cause), code=VALUES(code), total_ms=VALUES(total_ms),
                     cert_days=VALUES(cert_days), checked_at=VALUES(checked_at), updated_at=NOW()")
        ->execute([$m['id'], $m['site_id'], $status, date('Y-m-d H:i:s', $since), $fail, $alert ? date('Y-m-d H:i:s', $alert) : null,
                   $r['class'], mb_substr($r['cause'], 0, 500), $r['code'], $r['total_ms'], $r['cert_days'], date('Y-m-d H:i:s', $r['ts'])]);
}

function blumo_msg(array $m, array $r, ?string $dur): string {
    $l = [$m['name'] . ' — ' . $r['cause'], 'URL: ' . $m['url'],
          'HTTP ' . $r['code'] . ' / 응답 ' . $r['total_ms'] . 'ms (DNS ' . $r['dns_ms'] . ' · 연결 ' . $r['connect_ms'] . ' · TLS ' . $r['tls_ms'] . ' · TTFB ' . $r['ttfb_ms'] . ')'];
    if ($r['cert_days'] !== null) $l[] = 'SSL 만료까지 ' . $r['cert_days'] . '일';
    if ($r['final_url'] && $r['final_url'] !== $m['url']) $l[] = '최종 URL: ' . $r['final_url'];
    if (is_array($r['health']) && isset($r['health']['status'])) {
        $l[] = 'health: ' . $r['health']['status']
             . (empty($r['health']['fails']) ? '' : ' / fail=' . implode(',', (array)$r['health']['fails']))
             . (empty($r['health']['warns']) ? '' : ' / warn=' . implode(',', (array)$r['health']['warns']));
    }
    if ($dur) $l[] = '장애 지속시간: ' . $dur;
    $l[] = '확인 시각: ' . date('Y-m-d H:i:s', $r['ts']);
    return implode("\n", $l);
}

function blumo_dur(int $sec): string {
    $sec = max(0, $sec);
    if ($sec < 60) return $sec . '초';
    if ($sec < 3600) return floor($sec / 60) . '분';
    if ($sec < 86400) return floor($sec / 3600) . '시간 ' . floor(($sec % 3600) / 60) . '분';
    return floor($sec / 86400) . '일 ' . floor(($sec % 86400) / 3600) . '시간';
}

/** 웹훅 — slack(Incoming Webhook) · jandi(커넥트) · raw(JSON). 성공이면 true, 실패면 오류 문장(로그·화면용) */
function blumo_webhook(array $cfg, string $title, string $body, string $level) {
    if (($cfg['webhook_url'] ?? '') === '') return '웹훅 주소가 없습니다';
    $icon = $level === 'down' ? '[장애]' : ($level === 'up' ? '[복구]' : '[경고]');
    $color = $level === 'down' ? '#D14836' : ($level === 'up' ? '#2EB886' : '#FAC11B');
    $headers = ['Content-Type: application/json'];
    $fmt = $cfg['webhook_format'] ?? 'slack';
    if ($fmt === 'jandi') {
        $payload = ['body' => $icon . ' ' . $title . "\n" . $body, 'connectColor' => $color, 'connectInfo' => [['title' => $title, 'description' => $body]]];
        $headers[] = 'Accept: application/vnd.tosslab.jandi-v2+json';
    } elseif ($fmt === 'slack') {
        $payload = ['text' => $icon . ' *' . $title . '*', 'attachments' => [['color' => $color, 'text' => $body]]];
    } else {
        $payload = ['level' => $level, 'title' => $title, 'body' => $body, 'ts' => time()];
    }
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $cfg['webhook_url'], CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                            CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    blumo_ca($ch, $cfg);
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        $msg = "웹훅 실패 HTTP $code" . ($err ? " ($err)" : '') . ' ' . mb_substr((string)$out, 0, 200);
        error_log('[BLUMO] ' . $msg);
        return $msg;
    }
    return true;
}

/** 실제 알림과 같은 모양으로 장애 → 복구 2건. 성공이면 true, 실패면 오류 문장 */
function blumo_test_webhook(PDO $pdo) {
    $cfg = blumo_cfg($pdo);
    $now = date('Y-m-d H:i:s');
    $down = "BLUMO 연결테스트 — 503 — 서버 과부하 또는 동시접속 한도 | DB: Too many connections — 동시접속 한도 초과 (1040)\n"
          . "URL: https://example.co.kr/\nHTTP 503 / 응답 8120ms (DNS 12 · 연결 31 · TLS 55 · TTFB 8100)\nhealth: fail / fail=database\n"
          . "확인 시각: $now\n※ SiteHub › BLUMO 감시 설정에서 보낸 연결 확인용 테스트 메시지입니다";
    $up = "BLUMO 연결테스트 — 정상\nURL: https://example.co.kr/\nHTTP 200 / 응답 412ms (DNS 11 · 연결 28 · TLS 51 · TTFB 390)\nhealth: ok\n장애 지속시간: 0분\n확인 시각: $now";
    $a = blumo_webhook($cfg, '장애 발생 · BLUMO 연결테스트', $down, 'down');
    if ($a !== true) return $a;
    sleep(1);
    return blumo_webhook($cfg, '복구 · BLUMO 연결테스트', $up, 'up');
}

/* ── 전체 점검 (작업 스케줄러 · 화면의 '전체 지금 점검') ─────────────────────────────────── */

function blumo_lock_path(): string {
    return dirname(__DIR__) . '/var/sitehub/blumo.lock';
}

/** 진행 중인 전체 점검 — {started, done, total, current, by} 또는 null. 2분 넘게 갱신이 없으면 죽은 실행으로 본다 */
function blumo_lock_info(): ?array {
    $f = blumo_lock_path();
    if (!is_file($f)) return null;
    if (filemtime($f) < time() - 120) { @unlink($f); return null; }
    $j = json_decode((string)@file_get_contents($f), true);
    if (is_array($j) && !empty($j['pending']) && filemtime($f) < time() - 20) { @unlink($f); return null; }   // 화면이 만든 잠금을 CLI 가 안 이어받음
    return is_array($j) ? $j : ['done' => 0, 'total' => 0];
}

/** 전체 점검. $by = schedule|manual. 일시정지면 schedule 은 건너뛴다 */
function blumo_run(PDO $pdo, string $by = 'schedule', ?callable $echo = null): array {
    $cfg = blumo_cfg($pdo);
    $say = $echo ?: function ($s) {};
    if ($by === 'schedule' && $cfg['paused']) {
        blumo_meta_set($pdo, 'last_skip', date('Y-m-d H:i:s'));
        $say("[BLUMO] 전체 일시정지 상태 — 점검을 건너뜁니다.");
        return ['skipped' => 'paused'];
    }
    $lock = blumo_lock_path();
    if (!is_dir(dirname($lock))) { @mkdir(dirname($lock), 0775, true); @file_put_contents(dirname($lock) . '/.htaccess', "Require all denied\n"); }
    $cur = blumo_lock_info();
    if ($cur && empty($cur['pending'])) { $say("[BLUMO] 다른 점검이 진행 중입니다. 건너뜁니다."); return ['skipped' => 'running']; }
    $targets = blumo_targets($pdo);
    $started = time();
    $write = fn($n, $name) => @file_put_contents($lock, json_encode(['started' => $started, 'done' => $n, 'total' => count($targets), 'by' => $by, 'current' => $name], JSON_UNESCAPED_UNICODE));
    $write(0, '');
    register_shutdown_function(function () use ($lock) { @unlink($lock); });
    @set_time_limit(0);
    $down = 0; $n = 0;
    foreach ($targets as $m) {
        $write($n, $m['name']);
        $r = blumo_check($pdo, $m, $cfg);
        if (!in_array($r['class'], BLUMO_UP_CLASSES, true)) $down++;
        $say(sprintf("[%s] %-24s %-18s http=%s %sms %s", date('H:i:s'), $m['name'], $r['class'], $r['code'], $r['total_ms'], $r['cause']));
        $n++;
    }
    @unlink($lock);
    blumo_meta_set($pdo, 'last_run', date('Y-m-d H:i:s'));
    blumo_meta_set($pdo, 'last_run_by', $by);
    if ($by === 'schedule') blumo_meta_set($pdo, 'last_auto_run', date('Y-m-d H:i:s'));   // 작업 스케줄러가 살아 있는지
    blumo_prune($pdo, $cfg);
    return ['checked' => $n, 'down' => $down, 'took' => time() - $started];
}

/** 오래된 점검 기록 정리 — 하루 한 번. 장애 이력(incidents)은 지우지 않는다 */
function blumo_prune(PDO $pdo, array $cfg): void {
    if (blumo_meta_get($pdo, 'pruned_on') === date('Y-m-d')) return;
    blumo_meta_set($pdo, 'pruned_on', date('Y-m-d'));
    $pdo->prepare("DELETE FROM lab_monitor_checks WHERE at < ?")->execute([date('Y-m-d H:i:s', time() - $cfg['retention_days'] * 86400)]);
}

/** 화면의 '전체 지금 점검' — 웹 요청이 길어지지 않게 CLI 를 뒤로 띄운다. 띄웠으면 true, 아니면 오류 문장 */
function blumo_spawn_run() {
    $php = blumo_php_cli();
    if ($php === '') return '점검용 PHP(php.exe)를 찾지 못했습니다. config.php 에 \'sitehub_php_cli\' => \'C:\\\\wamp64\\\\bin\\\\php\\\\php8.x\\\\php.exe\' 처럼 적어 주세요.';
    $lock = blumo_lock_path();
    if (!is_dir(dirname($lock))) @mkdir(dirname($lock), 0775, true);
    // 화면이 바로 진행 표시를 띄울 수 있게 잠금을 먼저 만들고 CLI 가 이어받는다
    @file_put_contents($lock, json_encode(['pending' => 1, 'started' => time(), 'done' => 0, 'total' => 0, 'by' => 'manual']));
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'monitor_run.php') . ' --manual';
    if (DIRECTORY_SEPARATOR === '\\') pclose(popen('start "" /B ' . $cmd . ' > NUL 2>&1', 'r'));
    else exec($cmd . ' > /dev/null 2>&1 &');
    return true;
}

/**
 * 점검을 돌릴 PHP CLI 실행 파일. Apache 로 띄우면 PHP_BINARY 가 httpd.exe·php-cgi.exe 이거나 비어 있어서 따로 찾는다.
 * config.php 'sitehub_php_cli' > PHP_BINARY(cli) > 같은 폴더의 php.exe > php.ini 옆 > PHP_BINDIR > PATH.
 */
function blumo_php_cli(): string {
    $cfg = blumo_site_config();
    if (!empty($cfg['sitehub_php_cli'])) return (string)$cfg['sitehub_php_cli'];
    $win = DIRECTORY_SEPARATOR === '\\';
    $exe = $win ? 'php.exe' : 'php';
    if (PHP_SAPI === 'cli' && PHP_BINARY) return PHP_BINARY;
    $c = [];
    if (PHP_BINARY) $c[] = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $exe;
    if ($ini = php_ini_loaded_file()) $c[] = dirname($ini) . DIRECTORY_SEPARATOR . $exe;
    $c[] = PHP_BINDIR . DIRECTORY_SEPARATOR . $exe;
    foreach ($c as $p) if (is_file($p)) return $p;
    $w = trim((string)@shell_exec($win ? 'where php 2>NUL' : 'command -v php 2>/dev/null'));
    if ($w !== '') { $l = preg_split('/\r?\n/', $w); if (is_file(trim($l[0]))) return trim($l[0]); }
    return '';
}

/* ── 주소 열어 확인 (감시 주소 폼) ─────────────────────────────────────────────────────── */

/** https → http 순으로 열어 살아 있는 쪽과 <title>, 정상 판정 문구 후보를 준다 */
function blumo_probe_url(PDO $pdo, string $url): array {
    $cfg = blumo_cfg($pdo);
    $raw = trim($url);
    if (!preg_match('#^(https?://)?[a-z0-9.\-]+(:\d+)?(/.*)?$#i', $raw)) throw new Exception('주소 형식이 올바르지 않습니다.');
    $hostPath = preg_replace('#^https?://#i', '', $raw);
    $schemes = preg_match('#^http://#i', $raw) ? ['http'] : (preg_match('#^https://#i', $raw) ? ['https'] : ['https', 'http']);
    $best = null;
    foreach ($schemes as $sc) {
        $u = sitehub_monitor_url($sc . '://' . $hostPath);
        $r = blumo_probe(['url' => $u, 'timeout' => 12, 'expect' => '', 'min_size' => 0, 'slow_ms' => null], $cfg);
        $alive = $r['code'] >= 200 && $r['code'] < 400;
        if ($alive || !$best) $best = [$u, $r];
        if ($alive) break;
    }
    [$u, $r] = $best;
    if ($r['code'] === 0) throw new Exception('열지 못했습니다: ' . ($r['err'] ?: $r['class']));
    $body = $r['body'];
    if (!preg_match('//u', $body)) {
        $cs = preg_match('/charset=["\']?([a-z0-9\-_]+)/i', $body, $mm) ? $mm[1] : 'EUC-KR';
        $body = @mb_convert_encoding($body, 'UTF-8', $cs) ?: $body;
    }
    $title = preg_match('#<title[^>]*>(.*?)</title>#is', $body, $mm) ? trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($mm[1]), ENT_QUOTES, 'UTF-8'))) : '';
    // '사이트명 | 페이지명' 처럼 길면 앞머리만 — 페이지마다 바뀌는 뒷부분 때문에 오판하지 않게
    $expect = trim(preg_split('/\s+[|\-–—:·]\s+|\s*\|\s*/u', $title)[0] ?? '');
    return ['url' => $u, 'final_url' => $r['final_url'], 'code' => $r['code'], 'class' => $r['class'], 'ms' => $r['total_ms'],
            'size' => $r['size'], 'cert_days' => $r['cert_days'], 'title' => mb_substr($title, 0, 200), 'expect' => mb_substr($expect, 0, 200)];
}

/* ── 환경 진단 (설정 화면) ─────────────────────────────────────────────────────────── */

function blumo_doctor(PDO $pdo): array {
    $cfg = blumo_cfg($pdo);
    $o = [];
    $o[] = 'PHP ' . PHP_VERSION . ' (' . PHP_OS . ', ' . PHP_SAPI . ') · curl ' . (function_exists('curl_version') ? curl_version()['version'] . ' / ' . curl_version()['ssl_version'] : '없음')
         . ' · openssl ' . (extension_loaded('openssl') ? 'OK' : '없음') . ' · mbstring ' . (extension_loaded('mbstring') ? 'OK' : '없음');
    $o[] = 'CA 묶음 ' . $cfg['ca_bundle'] . (is_file($cfg['ca_bundle']) ? '' : '  ← 파일 없음! https 점검이 전부 TLS_FAIL 이 됩니다');
    $php = blumo_php_cli();
    $o[] = '점검용 PHP ' . ($php !== '' ? $php : '못 찾음 — config.php 에 sitehub_php_cli 경로 지정 필요 (전체 지금 점검이 안 됨)');
    $targets = [];
    if ($cfg['webhook_url'] !== '') { $u = parse_url($cfg['webhook_url']); $targets['웹훅 호스트'] = 'https://' . ($u['host'] ?? '') . '/'; }
    $targets['일반 HTTPS 사이트'] = 'https://www.google.com/';
    $first = blumo_targets($pdo)[0] ?? null;
    if ($first) $targets['감시 1번 (' . $first['name'] . ')'] = $first['url'];
    foreach ($targets as $label => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 12, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        blumo_ca($ch, $cfg);
        curl_exec($ch);
        $no = curl_errno($ch); $er = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $line = $label . ' ' . $url . ' — ' . ($no ? "실패 ($no) $er" : "연결 OK (HTTP $code)");
        if ($no === 60 || $no === 77) $line .= ' → CA 인증서 문제 (monitor/cacert.pem 확인)';
        if ($no === 6) $line .= ' → DNS 실패';
        if ($no === 7 || $no === 28) $line .= ' → 연결 차단 (방화벽/프록시)';
        $o[] = $line;
    }
    $auto = blumo_meta_get($pdo, 'last_auto_run');
    $o[] = '마지막 자동 점검 ' . ($auto ?: '없음 — 작업 스케줄러 등록 필요 (sitehub/tools/monitor-task.bat)');
    return $o;
}

/* ── 예전 BLUMO(blumo.sqlite) 가져오기 — 설정(웹훅·기준·일시정지)과 점검 기록 ───────────────────────── */

function blumo_legacy_path(): string {
    $cfg = blumo_site_config();
    if (!empty($cfg['sitehub_blumo_legacy_db'])) return (string)$cfg['sitehub_blumo_legacy_db'];
    // 예전 BLUMO 폴더(D:\00_CIboard_package\004_blumo)는 지웠다 — 그 blumo.sqlite 를 여기에 복사해 두었다 (var/ 는 gitignore · 웹 차단)
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'sitehub' . DIRECTORY_SEPARATOR . 'blumo_legacy.sqlite';
}

/**
 * 예전 BLUMO 를 읽어 온다. 감시 주소는 'BLUMO 기존 대상 연결'(blumo_seed.php)로 먼저 붙여 두어야 한다 —
 * 기록은 BLUMO 사이트 이름 → BLUMO id → 그 blumo_id 를 가진 감시 주소로 옮긴다. 이미 옮긴 기록은 다시 넣지 않는다.
 */
function blumo_legacy_import(PDO $pdo, string $path, bool $withSettings, bool $withHistory): array {
    if (!extension_loaded('pdo_sqlite')) throw new Exception('이 PHP 에 pdo_sqlite 확장이 없어 blumo.sqlite 를 읽을 수 없습니다. 웹훅 주소는 설정에 직접 넣어 주세요.');
    if (!is_file($path)) throw new Exception('파일이 없습니다: ' . $path);
    $old = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $old->exec('PRAGMA busy_timeout=5000');
    $out = ['settings' => [], 'checks' => 0, 'incidents' => 0, 'states' => 0, 'unmapped' => []];
    if ($withSettings) {
        $set = [];
        foreach ($old->query("SELECT key, value FROM settings") as $r) $set[$r['key']] = $r['value'];
        foreach (array_keys(BLUMO_RANGES) as $k) {
            if (isset($set[$k]) && $set[$k] !== '' && $k !== 'retention_days') { blumo_meta_set($pdo, $k, (int)$set[$k]); $out['settings'][] = $k; }
        }
        if (isset($set['alert_on_warn'])) { blumo_meta_set($pdo, 'alert_on_warn', $set['alert_on_warn'] === '1' ? 1 : 0); $out['settings'][] = 'alert_on_warn'; }
        if (!empty($set['webhook_url'])) { blumo_meta_set($pdo, 'webhook_url', sitehub_enc($set['webhook_url'])); $out['settings'][] = 'webhook_url'; }
        if (!empty($set['webhook_format'])) { blumo_meta_set($pdo, 'webhook_format', $set['webhook_format']); $out['settings'][] = 'webhook_format'; }
        if (isset($set['paused'])) { blumo_meta_set($pdo, 'paused', $set['paused'] === '1' ? '1' : '0'); $out['settings'][] = 'paused'; }
    }
    if ($withHistory) {
        $map = [];   // BLUMO 이름 → 감시 주소 (BLUMO id 로, 없으면 같은 주소로)
        $byBid = []; $byUrl = [];
        foreach ($pdo->query("SELECT id, site_id, blumo_id, url FROM lab_site_monitors") as $m) {
            if ($m['blumo_id'] !== null) $byBid[(int)$m['blumo_id']] = $m;
            $byUrl[sitehub_monitor_key($m['url'])] = $byUrl[sitehub_monitor_key($m['url'])] ?? $m;
        }
        foreach ($old->query("SELECT id, name, url FROM sites") as $s) {
            $hit = $byBid[(int)$s['id']] ?? ($byUrl[sitehub_monitor_key((string)$s['url'])] ?? null);
            if ($hit) $map[$s['name']] = $hit; else $out['unmapped'][] = $s['name'];
        }
        $dt = fn($ts) => $ts ? date('Y-m-d H:i:s', (int)$ts) : null;
        $has = $pdo->prepare("SELECT 1 FROM lab_monitor_checks WHERE monitor_id = ? AND at = ? AND class = ? LIMIT 1");
        $ins = $pdo->prepare("INSERT INTO lab_monitor_checks (monitor_id, site_id, at, class, cause, code, dns_ms, connect_ms, tls_ms, ttfb_ms, total_ms, size, cert_days, health, snippet)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $pdo->beginTransaction();
        foreach ($old->query("SELECT * FROM checks ORDER BY id") as $c) {
            $m = $map[$c['site']] ?? null;
            if (!$m) continue;
            $has->execute([$m['id'], $dt($c['ts']), $c['class']]);
            if ($has->fetchColumn()) continue;
            $ins->execute([$m['id'], $m['site_id'], $dt($c['ts']), $c['class'], mb_substr((string)$c['cause'], 0, 500), $c['code'], $c['dns_ms'], $c['connect_ms'],
                           $c['tls_ms'], $c['ttfb_ms'], $c['total_ms'], $c['size'], $c['cert_days'], $c['health'], $c['snippet']]);
            $out['checks']++;
        }
        $hasI = $pdo->prepare("SELECT 1 FROM lab_monitor_incidents WHERE monitor_id = ? AND started = ? LIMIT 1");
        $insI = $pdo->prepare("INSERT INTO lab_monitor_incidents (monitor_id, site_id, started, ended, class, cause) VALUES (?,?,?,?,?,?)");
        foreach ($old->query("SELECT * FROM incidents ORDER BY id") as $i) {
            $m = $map[$i['site']] ?? null;
            if (!$m) continue;
            $hasI->execute([$m['id'], $dt($i['started'])]);
            if ($hasI->fetchColumn()) continue;
            $insI->execute([$m['id'], $m['site_id'], $dt($i['started']), $dt($i['ended']), $i['class'], mb_substr((string)$i['cause'], 0, 500)]);
            $out['incidents']++;
        }
        // 현재 상태 — 여기에 아직 상태가 없는 주소만 (새로 점검한 게 이긴다)
        $last = [];
        foreach ($old->query("SELECT c.* FROM checks c JOIN (SELECT site, MAX(id) mx FROM checks GROUP BY site) x ON c.id = x.mx") as $c) $last[$c['site']] = $c;
        $insS = $pdo->prepare("INSERT IGNORE INTO lab_monitor_state (monitor_id, site_id, status, since, fail_count, last_alert, class, cause, code, total_ms, cert_days, checked_at, updated_at)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
        foreach ($old->query("SELECT * FROM state") as $s) {
            $m = $map[$s['site']] ?? null;
            if (!$m) continue;
            $c = $last[$s['site']] ?? [];
            $insS->execute([$m['id'], $m['site_id'], $s['status'] ?: 'up', $dt($s['since']), (int)$s['fail_count'], $dt($s['last_alert']),
                            $c['class'] ?? (string)$s['last_class'], mb_substr((string)($c['cause'] ?? ''), 0, 500), $c['code'] ?? null, $c['total_ms'] ?? null,
                            $c['cert_days'] ?? null, $dt($c['ts'] ?? null)]);
            $out['states'] += $insS->rowCount();
        }
        $pdo->commit();
    }
    blumo_meta_set($pdo, 'legacy_import_at', date('Y-m-d H:i:s'));
    return $out;
}
