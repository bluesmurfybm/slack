<?php
/**
 * BLUMO 감시 — 화면 쪽(상태 요약·목록·상세·연결). 점검 자체는 monitor_engine.php, 자동 실행은 monitor_run.php.
 *
 *  예전에는 BLUMO 가 따로 돌았다(D:\00_CIboard_package\004_blumo — 지웠다, 그 SQLite 는 var/sitehub/blumo_legacy.sqlite). 지금은 SiteHub 안에 있다:
 *  감시 대상 = 사이트의 '감시 설정' 탭(lab_site_monitors), 점검 기록·상태·장애 이력 = lab_monitor_*,
 *  설정(웹훅·기준·일시정지) = lab_sync_meta 의 blumo_*. 화면은 목록의 원 · 감시 설정 탭 · monitor.php(대시보드/장애 이력/설정).
 */
require_once __DIR__ . '/monitor_engine.php';

/** 감시 상태를 '모름'으로 보는 기준 — 자동 점검은 5분마다, 이만큼 점검이 없으면 멈춘 것으로 본다 */
const SITEHUB_MONITOR_STALE_MIN = 20;

/** 주소를 점검에 쓰는 모양으로 — 스킴이 없으면 https, 경로가 없으면 '/' */
function sitehub_monitor_url(string $u): string {
    $u = trim($u);
    if ($u === '') return '';
    if (!preg_match('#^https?://#i', $u)) $u = 'https://' . ltrim($u, '/');
    if (substr_count($u, '/') < 3) $u .= '/';
    return $u;
}

/** 주소 비교용 — 스킴·www·끝 '/' 를 뗀 소문자 */
function sitehub_monitor_key(string $u): string {
    $u = strtolower(trim($u));
    $u = preg_replace('#^https?://#', '', $u);
    $u = preg_replace('#^www\.#', '', $u);
    return rtrim($u, '/');
}

function sitehub_monitor_host(string $u): string {
    $h = parse_url(sitehub_monitor_url($u), PHP_URL_HOST);
    return $h ? preg_replace('#^www\.#', '', strtolower($h)) : '';
}

function sitehub_ago(int $sec): string {
    if ($sec < 60) return '방금';
    if ($sec < 3600) return floor($sec / 60) . '분 전';
    if ($sec < 86400) return floor($sec / 3600) . '시간 전';
    return floor($sec / 86400) . '일 전';
}

/** 감시 주소 하나 → ['code' => up|down|paused|unknown, 'text' => 한 줄] */
function sitehub_monitor_one(array $m, ?array $st): array {
    if (!(int)$m['enabled']) return ['code' => 'paused', 'text' => '자동 점검 멈춤'];
    if (!$st || !$st['checked_at']) return ['code' => 'unknown', 'text' => '아직 점검 전'];
    $age = time() - strtotime($st['checked_at']);
    if ($st['status'] === 'down') return ['code' => 'down', 'text' => '장애 · ' . ($st['cause'] ?: $st['class'])];
    if ($age > SITEHUB_MONITOR_STALE_MIN * 60) return ['code' => 'unknown', 'text' => '마지막 점검이 ' . sitehub_ago($age) . ' — 자동 점검(작업 스케줄러)이 멈췄는지 확인'];
    return ['code' => 'up', 'text' => '정상' . ($st['class'] && $st['class'] !== 'OK' ? ' · ' . $st['class'] : '')];
}

/**
 * 사이트 하나의 감시 요약 — 목록의 원 하나. monitor=0 이면 null.
 *  down 이 하나라도 있으면 down, 전부 up 이면 up, 주소가 없으면 empty, 전부 멈춤이면 paused, 그 외(점검 전·끊김)는 unknown.
 */
function sitehub_monitor_status(array $site): ?array {
    if (!(int)$site['monitor']) return null;
    $ms = $site['monitors'] ?? [];
    if (!$ms) return ['code' => 'empty', 'text' => '감시 주소가 없습니다 — 감시 설정에서 추가하세요', 'n' => 0];
    $codes = array_map(fn($m) => $m['watch']['code'], $ms);
    if (in_array('down', $codes, true)) $code = 'down';
    elseif (!array_diff($codes, ['up'])) $code = 'up';
    elseif (!array_diff($codes, ['paused'])) $code = 'paused';
    else $code = 'unknown';
    $lines = array_map(fn($m) => ($m['label'] !== '' ? $m['label'] . ': ' : (count($ms) > 1 ? sitehub_monitor_host($m['url']) . ': ' : '')) . $m['watch']['text'], $ms);
    return ['code' => $code, 'text' => implode("\n", $lines), 'n' => count($ms)];
}

/** 감시 주소들의 상태 + 24시간 가용률 */
function sitehub_monitor_states(PDO $pdo, array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $out = [];
    foreach ($pdo->query("SELECT * FROM lab_monitor_state WHERE monitor_id IN ({$in})") as $r) $out[(int)$r['monitor_id']] = $r;
    $st = $pdo->prepare("SELECT monitor_id, COUNT(*) n, SUM(class IN ('" . implode("','", BLUMO_UP_CLASSES) . "')) ok
                         FROM lab_monitor_checks WHERE monitor_id IN ({$in}) AND at >= ? GROUP BY monitor_id");
    $st->execute([date('Y-m-d H:i:s', time() - 86400)]);
    foreach ($st as $r) if (isset($out[(int)$r['monitor_id']]) && $r['n']) $out[(int)$r['monitor_id']]['avail_24h'] = round($r['ok'] / $r['n'] * 100, 2);
    return $out;
}

function sitehub_monitor_state_out(?array $raw): ?array {
    if (!$raw) return null;
    $i = fn($k) => $raw[$k] === null ? null : (int)$raw[$k];
    return ['status' => $raw['status'], 'class' => $raw['class'], 'cause' => $raw['cause'], 'code' => $i('code'),
            'total_ms' => $i('total_ms'), 'cert_days' => $i('cert_days'), 'avail_24h' => $raw['avail_24h'] ?? null,
            'since' => $raw['since'], 'checked_at' => $raw['checked_at']];
}

/** sitehub_load_sites 가 부른다 — 감시 주소마다 키 복호화 + 상태 + 원 */
function sitehub_monitor_attach(PDO $pdo, array &$sites): void {
    $ids = [];
    foreach ($sites as $s) foreach ($s['monitors'] as $m) $ids[] = (int)$m['id'];
    $states = sitehub_monitor_states($pdo, $ids);
    foreach ($sites as &$s) {
        foreach ($s['monitors'] as &$m) {
            $m['health_key'] = sitehub_dec($m['health_key']);
            foreach (['slow_ms', 'timeout', 'min_size', 'blumo_id'] as $c) $m[$c] = $m[$c] === null ? null : (int)$m[$c];
            $m['enabled'] = (int)$m['enabled'];
            if ($m['mute_until'] && strtotime($m['mute_until']) <= time()) $m['mute_until'] = null;
            $raw = $states[(int)$m['id']] ?? null;
            $m['state'] = sitehub_monitor_state_out($raw);
            $m['watch'] = sitehub_monitor_one($m, $raw);
        }
        unset($m);
        $s['monitor_status'] = sitehub_monitor_status($s);
    }
    unset($s);
}

/** 화면이 1분마다 받아 가는 원 상태만 — 사이트 전체를 다시 안 받게. {site_id: {monitor_status, monitors: {id: {watch, state, enabled, mute_until}}}} */
function sitehub_monitor_snapshot(PDO $pdo): array {
    $sites = [];
    foreach ($pdo->query("SELECT id, monitor FROM lab_sites WHERE monitor = 1") as $r) $sites[(int)$r['id']] = ['id' => (int)$r['id'], 'monitor' => 1, 'monitors' => []];
    if (!$sites) return [];
    foreach ($pdo->query("SELECT * FROM lab_site_monitors WHERE site_id IN (" . implode(',', array_keys($sites)) . ") ORDER BY sort_no, id") as $m) {
        $sites[(int)$m['site_id']]['monitors'][] = $m;
    }
    sitehub_monitor_attach($pdo, $sites);
    $out = [];
    foreach ($sites as $id => $s) {
        $w = [];
        foreach ($s['monitors'] as $m) $w[$m['id']] = ['watch' => $m['watch'], 'state' => $m['state'], 'enabled' => $m['enabled'], 'mute_until' => $m['mute_until']];
        $out[$id] = ['monitor_status' => $s['monitor_status'], 'monitors' => $w];
    }
    return $out;
}

/** 화면 머리·배너에 쓰는 BLUMO 전체 상태 */
function sitehub_blumo_meta(PDO $pdo): array {
    $m = [];
    foreach ($pdo->query("SELECT k, v FROM lab_sync_meta WHERE k IN ('blumo_paused', 'blumo_last_run', 'blumo_last_run_by', 'blumo_last_auto_run', 'blumo_webhook_url')") as $r) $m[$r['k']] = $r['v'];
    $auto = $m['blumo_last_auto_run'] ?? null;
    return ['paused' => ($m['blumo_paused'] ?? '0') === '1', 'last_run' => $m['blumo_last_run'] ?? null, 'last_run_by' => $m['blumo_last_run_by'] ?? null,
            'last_auto_run' => $auto, 'auto_age' => $auto ? max(0, time() - strtotime($auto)) : null,
            'webhook' => !empty($m['blumo_webhook_url']), 'running' => blumo_lock_info(), 'stale_min' => SITEHUB_MONITOR_STALE_MIN];
}

/* ── 감시 대시보드 (monitor.php) ─────────────────────────────────────────────────── */

/** 감시 중인 사이트의 모든 감시 주소 + 상태 */
function sitehub_monitor_board(PDO $pdo): array {
    $rows = $pdo->query("SELECT m.*, s.name AS site_name, s.customer, s.sort_no AS site_sort FROM lab_site_monitors m
                         JOIN lab_sites s ON s.id = m.site_id WHERE s.monitor = 1 AND s.archived = 0
                         ORDER BY s.sort_no, s.name, s.id, m.sort_no, m.id")->fetchAll();
    $states = sitehub_monitor_states($pdo, array_column($rows, 'id'));
    $out = [];
    foreach ($rows as $m) {
        $raw = $states[(int)$m['id']] ?? null;
        if ($m['mute_until'] && strtotime($m['mute_until']) <= time()) $m['mute_until'] = null;
        $out[] = ['id' => (int)$m['id'], 'site_id' => (int)$m['site_id'], 'site_name' => $m['site_name'], 'customer' => $m['customer'],
                  'label' => $m['label'], 'url' => $m['url'], 'expect' => $m['expect'], 'agent' => $m['health'] !== '' && $m['health_key'] !== null && $m['health_key'] !== '',
                  'note' => (string)$m['note'], 'enabled' => (int)$m['enabled'], 'mute_until' => $m['mute_until'],
                  'state' => sitehub_monitor_state_out($raw), 'watch' => sitehub_monitor_one($m, $raw)];
    }
    return $out;
}

/** 감시 주소 하나의 상세 — 점검 기록 80회(에이전트 응답·오류 화면 포함), 장애 20건, 24h/7일 가용률·평균 */
function sitehub_monitor_detail(PDO $pdo, int $id): array {
    $t = blumo_targets($pdo, $id);
    if (!$t) throw new Exception('감시 주소가 없습니다.');
    $m = $t[0];
    $st = $pdo->prepare("SELECT * FROM lab_monitor_checks WHERE monitor_id = ? ORDER BY id DESC LIMIT 80");
    $st->execute([$id]);
    $checks = array_map(function ($c) {
        foreach (['id', 'code', 'dns_ms', 'connect_ms', 'tls_ms', 'ttfb_ms', 'total_ms', 'size', 'cert_days'] as $k) $c[$k] = $c[$k] === null ? null : (int)$c[$k];
        $c['health'] = $c['health'] ? json_decode($c['health'], true) : null;
        return $c;
    }, $st->fetchAll());
    $st = $pdo->prepare("SELECT * FROM lab_monitor_incidents WHERE monitor_id = ? ORDER BY started DESC LIMIT 20");
    $st->execute([$id]);
    $inc = $st->fetchAll();
    $avail = function (int $sec) use ($pdo, $id) {
        $q = $pdo->prepare("SELECT COUNT(*) n, SUM(class IN ('" . implode("','", BLUMO_UP_CLASSES) . "')) ok, AVG(total_ms) a FROM lab_monitor_checks WHERE monitor_id = ? AND at >= ?");
        $q->execute([$id, date('Y-m-d H:i:s', time() - $sec)]);
        $r = $q->fetch();
        return ['n' => (int)$r['n'], 'pct' => $r['n'] ? round($r['ok'] / $r['n'] * 100, 2) : null, 'avg_ms' => $r['n'] ? (int)round($r['a']) : null];
    };
    $raw = sitehub_monitor_states($pdo, [$id])[$id] ?? null;
    return ['monitor' => ['id' => $m['id'], 'site_id' => $m['site_id'], 'name' => $m['name'], 'url' => $m['url'], 'expect' => $m['expect'],
                          'agent' => $m['health'] !== '' && $m['health_key'] !== '', 'enabled' => (int)$m['enabled'], 'mute_until' => $m['mute_until'],
                          'state' => sitehub_monitor_state_out($raw), 'watch' => sitehub_monitor_one($m, $raw)],
            'checks' => $checks, 'incidents' => $inc, 'day' => $avail(86400), 'week' => $avail(86400 * 7)];
}

function sitehub_monitor_incidents(PDO $pdo, int $limit = 300): array {
    $rows = $pdo->query("SELECT i.*, s.name AS site_name, m.label FROM lab_monitor_incidents i
                         LEFT JOIN lab_site_monitors m ON m.id = i.monitor_id LEFT JOIN lab_sites s ON s.id = i.site_id
                         ORDER BY i.started DESC LIMIT " . max(1, min(1000, $limit)))->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['monitor_id'] = (int)$r['monitor_id']; $r['site_id'] = (int)$r['site_id']; }
    unset($r);
    return $rows;
}

/** 키가 들어간 에이전트 파일 — monitor/agent.tpl(예전 blumo-agent.php)에 키와 대상 이름을 넣는다 */
function sitehub_monitor_agent_file(PDO $pdo, int $id): array {
    $t = blumo_targets($pdo, $id)[0] ?? null;
    if (!$t || $t['health_key'] === '') throw new Exception('에이전트 키가 없는 감시 주소입니다. 감시 주소 수정에서 키를 먼저 만드세요.');
    $src = (string)@file_get_contents(__DIR__ . '/monitor/agent.tpl');
    if ($src === '' || strpos($src, "'CHANGE-THIS-TO-A-LONG-RANDOM-STRING'") === false) throw new Exception('에이전트 원본(monitor/agent.tpl)을 읽지 못했습니다.');
    $src = str_replace("'CHANGE-THIS-TO-A-LONG-RANDOM-STRING'", var_export($t['health_key'], true), $src);
    $src = str_replace(' * (주)블루소프트', ' * (주)블루소프트' . "\n * 대상 : " . $t['name'] . ' (' . $t['url'] . ")\n * 발급 : " . date('Y-m-d H:i'), $src);
    $fn = basename((string)parse_url($t['health'] !== '' ? $t['health'] : 'x/blumo-agent.php', PHP_URL_PATH));
    if (!preg_match('/^[A-Za-z0-9._\-]+\.php$/', $fn)) $fn = 'blumo-agent.php';
    return ['name' => $fn, 'body' => $src];
}

/* ── 연결 전부터 BLUMO 에 있던 31곳 (blumo_seed.php) ─────────────────────────────────── */

/**
 * 씨앗마다 붙일 사이트를 고른다 — 주소의 호스트가 사이트의 운영/관리자/개발 URL 호스트와 같으면 그 사이트,
 * 아니면 이름 매칭(match_lib). 이미 같은 blumo_id 나 같은 주소의 감시 주소가 있으면 linked.
 */
function sitehub_blumo_seed_plan(PDO $pdo): array {
    $seed = require __DIR__ . '/blumo_seed.php';
    require_once __DIR__ . '/match_lib.php';
    $sites = $pdo->query("SELECT id, name, customer, url_ops, url_admin, url_dev, archived, slack_names FROM lab_sites ORDER BY archived, sort_no, id")->fetchAll();
    $byHost = [];
    foreach ($sites as $s) foreach (['url_ops', 'url_admin', 'url_dev'] as $c) {
        $h = sitehub_monitor_host((string)$s[$c]);
        if ($h !== '' && !isset($byHost[$h])) $byHost[$h] = (int)$s['id'];
    }
    $have = [];
    foreach ($pdo->query("SELECT id, site_id, url, blumo_id FROM lab_site_monitors") as $m) {
        if ($m['blumo_id'] !== null) $have['b' . (int)$m['blumo_id']] = (int)$m['site_id'];
        $have['u' . sitehub_monitor_key($m['url'])] = (int)$m['site_id'];
    }
    $index = sitehub_site_index($pdo);
    $out = [];
    foreach ($seed as [$bid, $name, $url, $expect, $memo]) {
        $label = preg_match('/\((국문|영문)\)\s*$/u', $name, $mm) ? $mm[1] : '';
        $linkedTo = $have['b' . $bid] ?? ($have['u' . sitehub_monitor_key($url)] ?? null);
        $site = $byHost[sitehub_monitor_host($url)] ?? null;
        $how = $site ? 'url' : '';
        if (!$site) {
            $amb = false;
            foreach ([$name, preg_replace('/\s*\([^)]*\)\s*$/u', '', $name)] as $try) {
                $hit = sitehub_match_name($index, $try, $amb);
                if ($hit) { $site = (int)$hit['id']; $how = 'name'; break; }
            }
        }
        $out[] = ['blumo_id' => $bid, 'name' => $name, 'url' => $url, 'expect' => $expect, 'memo' => $memo, 'label' => $label,
                  'site_id' => $linkedTo ?? $site, 'how' => $linkedTo ? 'linked' : $how, 'linked' => $linkedTo !== null];
    }
    return $out;
}
