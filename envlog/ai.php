<?php
/**
 * EnvLog AI 분석 — 로그 조각을 모아 Claude 에게 묻는다. api.php 가 ?a=ai_* 요청을 여기로 넘긴다.
 *
 *   ai_key      GET  → {has, source}          POST {key}           개인 Anthropic API 키 저장/삭제 (portal_users.anthropic_key_enc)
 *   ai_collect  POST {servers, dir, access_base, php_base, ip, from, to}
 *                → 로테이션 규칙으로 파일을 고르고 데몬 /file/extract 로 줄을 모아 돌려준다 (아직 AI 호출 없음)
 *   ai_ask      POST {prompt, model, context:[{server,file,text}], ip, from, to, school}
 *                → Claude 에 묻고 답을 SSE 로 흘려보낸다
 *
 * 설계 요점
 *   - 파일 전체를 보내지 않는다. 시간 범위(최대 2시간)·IP 로 서버에서 먼저 추리고(envlog_remote.sh extract),
 *     줄 수·바이트 상한을 건다. 무엇을 보낼지는 ai_collect 결과를 화면에서 보고 사용자가 확정한다.
 *   - 로테이션: 매일 ROTATE_HOUR(기본 05:00)에 돈다. 그 시각에 current → `<이름>-YYYYMMDD`(돈 날짜) 로 바뀌므로
 *     [D-1 05:00, D 05:00) 의 기록은 `<이름>-D` 에 있다. 아직 안 돈 구간은 current 파일.
 *   - 키: 사용자가 등록한 개인 키 > config.php 의 'anthropic_api_key'. 키는 서버에서만 쓰고 화면에 내려가지 않는다.
 *   - Anthropic 호출은 curl 직접(운영 서버에 composer vendor 를 두지 않는 규칙).
 */

const ENVLOG_AI_MAX_WINDOW_SEC = 2 * 3600;      // 시간 범위 상한 (IP 없이 전체를 볼 때)
const ENVLOG_AI_MAX_WINDOW_IP_HOURS = 72;       // IP 가 있을 때의 상한(시간) — grep 으로 그 IP 줄만 뽑으므로 길어도 양이 제한된다. config envlog_ai_max_window_ip_hours
const ENVLOG_AI_PHP_PAD_SEC = 120;              // IP 모드에서 php_errors 를 읽을 범위: 그 IP 의 첫~마지막 요청 ± 이 초
const ENVLOG_AI_MAX_ACCESS_LINES = 400;         // access_log 서버·파일당 줄 상한
const ENVLOG_AI_MAX_PHP_LINES = 300;            // php_errors 서버·파일당 줄 상한
const ENVLOG_AI_MAX_BYTES = 160 * 1024;         // 전체 자료 바이트 상한 (대략 4~5만 토큰)
const ENVLOG_AI_MODELS = ['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5'];   // 첫 번째가 기본 (Haiku — 비용 우선)
const ENVLOG_AI_EST_OUTPUT_TOKENS = 1500;       // 1회 예상 비용 계산에 쓰는 출력 토큰 가정
const ENVLOG_AI_PRICE = [   // $/1M 토큰 (입력, 출력) — 화면에 대략의 비용을 보여 주기 위한 값
    'claude-opus-5-5' => [4.0, 20.0], 'claude-sonnet-5-5' => [2.0, 10.0], 'claude-haiku-5-5' => [0.10, 0.50],
];

/** 요금 통제 설정 — config.php 로 바꿀 수 있다 */
function envlog_ai_limits() {
    $cfg = require __DIR__ . '/../config.php';
    return ['monthly_cap_usd' => (float)($cfg['envlog_ai_monthly_cap_usd'] ?? 100),   // 월 상한 (USD)
            'per_request_krw' => (float)($cfg['envlog_ai_max_per_request_krw'] ?? 50), // 1회 예상 비용 상한 (원)
            'usd_krw' => (float)($cfg['envlog_ai_usd_krw'] ?? 1400)];                  // 환율 (표시·판정용)
}

/** 사용 기록 표 — 모든 호출의 토큰·비용을 남겨 월 상한을 지킨다 */
function envlog_ai_usage_table() {
    static $done = false;
    if ($done) return;
    $done = true;
    portal_db()->exec("
        CREATE TABLE IF NOT EXISTS `envlog_ai_usage` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`       INT UNSIGNED NOT NULL,
            `model`         VARCHAR(40)  NOT NULL,
            `input_tokens`  INT          NOT NULL DEFAULT 0,
            `output_tokens` INT          NOT NULL DEFAULT 0,
            `cache_read`    INT          NOT NULL DEFAULT 0,
            `cost_usd`      DECIMAL(10,5) NOT NULL DEFAULT 0,
            `created_at`    DATETIME     NOT NULL,
            PRIMARY KEY (`id`),
            KEY `ix_month` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='envlog AI 분석 호출 기록 — 월 상한 계산용'
    ");
}

/** 이번 달 누적 비용(USD) */
function envlog_ai_month_usage() {
    envlog_ai_usage_table();
    $st = portal_db()->prepare("SELECT COALESCE(SUM(cost_usd),0) c, COUNT(*) n FROM envlog_ai_usage WHERE created_at >= ?");
    $st->execute([date('Y-m-01 00:00:00')]);
    $r = $st->fetch();
    return ['usd' => (float)$r['c'], 'calls' => (int)$r['n']];
}

/** 화면 머리글에 보여 줄 요금 상태 */
function envlog_ai_budget_state() {
    $lim = envlog_ai_limits(); $u = envlog_ai_month_usage();
    return ['month_usd' => round($u['usd'], 4), 'month_krw' => (int)round($u['usd'] * $lim['usd_krw']), 'calls' => $u['calls'],
            'cap_usd' => $lim['monthly_cap_usd'], 'cap_krw' => (int)round($lim['monthly_cap_usd'] * $lim['usd_krw']),
            'per_request_krw' => $lim['per_request_krw'], 'usd_krw' => $lim['usd_krw']];
}

/** 1회 예상 비용(USD): 입력 토큰(추정) + 예상 출력 */
function envlog_ai_estimate_usd($model, $inputTokens) {
    [$pi, $po] = ENVLOG_AI_PRICE[$model] ?? [0, 0];
    return $inputTokens / 1e6 * $pi + ENVLOG_AI_EST_OUTPUT_TOKENS / 1e6 * $po;
}

function envlog_ai_rotate_hour() {
    $cfg = require __DIR__ . '/../config.php';
    return (int)($cfg['envlog_rotate_hour'] ?? 5);
}

/**
 * 시간 범위에 걸치는 로그 파일 이름들. $base 는 'php_errors.log' 처럼 current 이름.
 * 예) 지금 2026-10-30 09:00, 범위 2026-10-29 13:00~15:00 → ['php_errors.log-20261030']
 *     범위 2026-10-30 04:30~06:00 → ['php_errors.log-20261030', 'php_errors.log']
 */
function envlog_ai_files_for($base, $fromTs, $toTs, $now = null) {
    $now = $now ?? time();
    $h = envlog_ai_rotate_hour();
    $rotDay = function ($ts) use ($h) {            // 그 시각의 기록이 들어갈 '돈 날짜'
        $d = strtotime(date('Y-m-d', $ts)); return (int)date('G', $ts) < $h ? $d : $d + 86400;
    };
    $lastRot = $rotDay($now) - 86400;               // 마지막으로 실제 돈 날짜 (오늘 05:00 이전이면 어제)
    $out = [];
    for ($d = $rotDay($fromTs); $d <= $rotDay($toTs); $d += 86400) {
        $out[] = $d <= $lastRot ? $base . '-' . date('Ymd', $d) : $base;
    }
    return array_values(array_unique($out));
}

function envlog_ai_key_get($user) {
    $k = isset($user['anthropic_key_enc']) ? dec_token($user['anthropic_key_enc']) : '';
    if ($k !== '') return [$k, 'user'];
    $cfg = require __DIR__ . '/../config.php';
    $k = (string)($cfg['anthropic_api_key'] ?? '');
    return $k !== '' ? [$k, 'config'] : ['', ''];
}

function envlog_ai_handle($action, $method, $user, $uid) {
    switch ($action) {
        case 'ai_key':
            if ($method === 'POST') {
                $in = json_decode(file_get_contents('php://input'), true) ?: [];
                $key = trim((string)($in['key'] ?? ''));
                if ($key !== '' && !preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,}$/', $key)) {
                    http_response_code(400); echo json_encode(['error' => 'Anthropic API 키 형식이 아닙니다 (sk-ant-… 로 시작)'], JSON_UNESCAPED_UNICODE); return;
                }
                portal_db()->prepare("UPDATE portal_users SET anthropic_key_enc = ? WHERE id = ?")
                           ->execute([$key === '' ? null : enc_token($key), (int)$user['id']]);
                $user['anthropic_key_enc'] = $key === '' ? null : enc_token($key);
            }
            [$k, $src] = envlog_ai_key_get($user);
            echo json_encode(['has' => $k !== '', 'source' => $src, 'models' => ENVLOG_AI_MODELS, 'rotate_hour' => envlog_ai_rotate_hour(),
                              'max_window_min' => ENVLOG_AI_MAX_WINDOW_SEC / 60,
                              'max_window_ip_hours' => (float)((require __DIR__ . '/../config.php')['envlog_ai_max_window_ip_hours'] ?? ENVLOG_AI_MAX_WINDOW_IP_HOURS),
                              'budget' => envlog_ai_budget_state()], JSON_UNESCAPED_UNICODE);
            return;

        case 'ai_budget':
            echo json_encode(envlog_ai_budget_state(), JSON_UNESCAPED_UNICODE);
            return;

        case 'ai_collect':
            $in = json_decode(file_get_contents('php://input'), true) ?: [];
            echo json_encode(envlog_ai_collect($uid, $in), JSON_UNESCAPED_UNICODE);
            return;

        case 'ai_ask':
            $in = json_decode(file_get_contents('php://input'), true) ?: [];
            envlog_ai_ask($user, $in);
            return;
    }
    http_response_code(400);
    echo json_encode(['error' => '알 수 없는 요청: ' . $action], JSON_UNESCAPED_UNICODE);
}

/** 시간 문자열(Y-m-d H:i) → timestamp. 잘못되면 null */
function envlog_ai_ts($s) {
    $s = trim((string)$s);
    if ($s === '') return null;
    $t = strtotime(str_replace('T', ' ', $s));
    return $t === false ? null : $t;
}

function envlog_ai_collect($uid, array $in) {
    $servers = array_values(array_filter(array_map('intval', (array)($in['servers'] ?? [])), fn($s) => $s >= 0));
    $dir = rtrim((string)($in['dir'] ?? ''), '/');
    $accessBase = trim((string)($in['access_base'] ?? ''));
    $phpBase = trim((string)($in['php_base'] ?? 'php_errors.log'));
    $ip = trim((string)($in['ip'] ?? ''));
    $from = envlog_ai_ts($in['from'] ?? ''); $to = envlog_ai_ts($in['to'] ?? '');
    if (!$servers || $dir === '') return ['error' => '서버와 로그 폴더가 필요합니다'];
    if ($from === null || $to === null) return ['error' => '시간 범위는 필수입니다 (최대 ' . (ENVLOG_AI_MAX_WINDOW_SEC / 60) . '분). IP 는 선택'];
    // IP 는 여러 개(콤마) — 각각 검사하고 빈 것·중복은 버린다 (최대 10개)
    $ips = array_values(array_unique(array_filter(array_map('trim', explode(',', $ip)), 'strlen')));
    foreach ($ips as $one) if (!preg_match('/^[0-9A-Za-z.:_\-]{3,64}$/', $one)) return ['error' => "IP 형식이 아닙니다: {$one}"];
    if (count($ips) > 10) return ['error' => 'IP 는 최대 10개까지입니다'];
    $ip = implode(',', $ips);
    if (($from === null) !== ($to === null)) return ['error' => '시작·끝 시간을 둘 다 넣으세요'];
    if ($from !== null) {
        if ($to <= $from) return ['error' => '끝 시간이 시작 시간보다 뒤여야 합니다'];
        $cfg = require __DIR__ . '/../config.php';
        $ipHours = (float)($cfg['envlog_ai_max_window_ip_hours'] ?? ENVLOG_AI_MAX_WINDOW_IP_HOURS);
        if ($ip === '' && $to - $from > ENVLOG_AI_MAX_WINDOW_SEC) return ['error' => 'IP 없이 볼 때 시간 범위는 최대 ' . (ENVLOG_AI_MAX_WINDOW_SEC / 60) . '분까지입니다 — IP 를 넣으면 최대 ' . $ipHours . '시간'];
        if ($ip !== '' && $to - $from > $ipHours * 3600) return ['error' => 'IP 로 볼 때 시간 범위는 최대 ' . $ipHours . '시간까지입니다'];
    }
    if ($phpBase === '' && $accessBase === '') return ['error' => '검색할 로그 파일이 없습니다 (php_errors.log / access_log 중 하나는 골라야 합니다)'];
    $fromKey = date('YmdHis', $from); $toKey = date('YmdHis', $to);
    $parts = []; $total = 0; $errors = []; $files = [];
    // 한 파일 추출 → parts 에 쌓고 줄 목록을 돌려준다
    $pull = function ($sid, $kind, $name, $fk, $tk, $ipArg) use ($uid, $dir, &$parts, &$total, &$errors, &$files) {
        $max = $kind === 'php' ? ENVLOG_AI_MAX_PHP_LINES : ENVLOG_AI_MAX_ACCESS_LINES;
        $files[$name] = true;
        [$out, $code, $err] = envlog_call($uid, 'GET', '/file/extract', ['server' => $sid, 'path' => $dir . '/' . $name, 'from' => $fk, 'to' => $tk, 'ip' => $ipArg, 'max' => $max], null, 320);
        $j = $out !== null ? json_decode($out, true) : null;
        if ($j === null || isset($j['error'])) {
            $msg = $j['error'] ?? ($err ?: '응답 없음');
            $missing = (bool)preg_match('/파일이 없습니다/u', $msg);
            if (!$missing) $errors[] = "S{$sid} {$name}: {$msg}";
            $parts[] = ['server' => $sid, 'file' => $name, 'kind' => $kind, 'lines' => 0, 'bytes' => 0, 'capped' => false, 'missing' => $missing, 'text' => ''];
            return [];
        }
        $text = implode("\n", $j['lines']);
        $parts[] = ['server' => $sid, 'file' => $name, 'kind' => $kind, 'lines' => count($j['lines']), 'bytes' => strlen($text),
                    'capped' => !empty($j['capped']), 'missing' => false, 'text' => $text];
        $total += strlen($text);
        return $j['lines'];
    };
    $mon = ['Jan'=>1,'Feb'=>2,'Mar'=>3,'Apr'=>4,'May'=>5,'Jun'=>6,'Jul'=>7,'Aug'=>8,'Sep'=>9,'Oct'=>10,'Nov'=>11,'Dec'=>12];
    foreach ($servers as $sid) {
        $hitMin = null; $hitMax = null;
        if ($accessBase !== '') {
            foreach (envlog_ai_files_for($accessBase, $from, $to) as $name) {
                foreach ($pull($sid, 'access', $name, $fromKey, $toKey, $ip) as $ln) {
                    // access 줄의 시각 → 그 IP 의 활동 구간 (php_errors 범위를 좁히는 데 쓴다)
                    if ($ip !== '' && preg_match('#\[(\d\d)/([A-Za-z]{3})/(\d{4}):(\d\d):(\d\d):(\d\d)#', $ln, $m) && isset($mon[$m[2]])) {
                        $t = mktime((int)$m[4], (int)$m[5], (int)$m[6], $mon[$m[2]], (int)$m[1], (int)$m[3]);
                        $hitMin = $hitMin === null ? $t : min($hitMin, $t); $hitMax = $hitMax === null ? $t : max($hitMax, $t);
                    }
                }
            }
        }
        if ($phpBase !== '') {
            $pf = $from; $pt = $to;
            if ($ip !== '' && $accessBase !== '') {
                if ($hitMin === null) {   // 그 IP 의 요청이 없는 서버 — php_errors 는 볼 이유가 없다
                    $parts[] = ['server' => $sid, 'file' => $phpBase, 'kind' => 'php', 'lines' => 0, 'bytes' => 0, 'capped' => false, 'missing' => false, 'skipped' => 'IP 요청 없음', 'text' => ''];
                    continue;
                }
                $pf = max($from, $hitMin - ENVLOG_AI_PHP_PAD_SEC); $pt = min($to, $hitMax + ENVLOG_AI_PHP_PAD_SEC);   // 첫~마지막 요청 ± 2분
            }
            foreach (envlog_ai_files_for($phpBase, $pf, $pt) as $name) $pull($sid, 'php', $name, date('YmdHis', $pf), date('YmdHis', $pt), '');
        }
    }
    $files = array_keys($files);
    // 전체 상한: 넘치면 큰 조각부터 뒤를 잘라 맞춘다 (잘린 조각은 trimmed 표시)
    if ($total > ENVLOG_AI_MAX_BYTES) {
        usort($parts, fn($a, $b) => $b['bytes'] <=> $a['bytes']);
        while ($total > ENVLOG_AI_MAX_BYTES) {
            $cut = false;
            foreach ($parts as &$p) {
                if ($p['bytes'] <= 2048) continue;
                $newLen = max(2048, (int)($p['bytes'] * 0.8));
                $t = substr($p['text'], 0, $newLen); $t = substr($t, 0, (int)strrpos($t, "\n"));
                $total -= $p['bytes'] - strlen($t); $p['text'] = $t; $p['bytes'] = strlen($t); $p['lines'] = substr_count($t, "\n") + 1; $p['trimmed'] = true; $cut = true;
                if ($total <= ENVLOG_AI_MAX_BYTES) break;
            }
            unset($p);
            if (!$cut) break;
        }
        usort($parts, fn($a, $b) => [$a['server'], $a['kind']] <=> [$b['server'], $b['kind']]);
    }
    $approx = (int)($total / 3.2) + 600;   // 지시문·질문 포함
    $lim = envlog_ai_limits(); $est = [];
    foreach (ENVLOG_AI_MODELS as $m) { $usd = envlog_ai_estimate_usd($m, $approx); $est[$m] = ['usd' => round($usd, 4), 'krw' => (int)ceil($usd * $lim['usd_krw']), 'ok' => $usd * $lim['usd_krw'] <= $lim['per_request_krw']]; }
    return ['parts' => $parts, 'total_bytes' => $total, 'approx_tokens' => $approx, 'estimate' => $est, 'per_request_krw' => $lim['per_request_krw'], 'errors' => $errors,
            'files' => $files, 'from' => date('Y-m-d H:i', $from), 'to' => date('Y-m-d H:i', $to), 'ip' => $ip];
}

function envlog_ai_ask($user, array $in) {
    [$key, $src] = envlog_ai_key_get($user);
    if ($key === '') { http_response_code(400); echo json_encode(['error' => 'Anthropic API 키가 없습니다 — AI 상자의 "API 키" 에서 등록하세요'], JSON_UNESCAPED_UNICODE); return; }
    $model = in_array($in['model'] ?? '', ENVLOG_AI_MODELS, true) ? $in['model'] : ENVLOG_AI_MODELS[0];
    $prompt = trim((string)($in['prompt'] ?? ''));
    $ctx = (array)($in['context'] ?? []);
    if ($prompt === '') { http_response_code(400); echo json_encode(['error' => '질문을 입력하세요'], JSON_UNESCAPED_UNICODE); return; }
    if (!$ctx) { http_response_code(400); echo json_encode(['error' => '먼저 "조회 대상 파일 확인" 으로 로그를 모으세요'], JSON_UNESCAPED_UNICODE); return; }

    $doc = '';
    foreach ($ctx as $c) {
        $t = (string)($c['text'] ?? ''); if ($t === '') continue;
        $doc .= "===== 서버 S" . (int)($c['server'] ?? 0) . " · " . preg_replace('/[^\w.\-]/', '', (string)($c['file'] ?? '')) . " (" . substr_count($t, "\n") + 1 . "줄) =====\n" . $t . "\n\n";
    }
    if (strlen($doc) > ENVLOG_AI_MAX_BYTES + 8192) $doc = substr($doc, 0, ENVLOG_AI_MAX_BYTES + 8192);

    // 요금 통제 ① 1회 예상 비용 상한 ② 월 상한 — 둘 다 호출 전에 거절한다
    $lim = envlog_ai_limits();
    $approx = (int)(strlen($doc) / 3.2) + (int)(strlen($prompt) / 2) + 400;
    $estUsd = envlog_ai_estimate_usd($model, $approx); $estKrw = $estUsd * $lim['usd_krw'];
    if ($estKrw > $lim['per_request_krw']) {
        http_response_code(400);
        echo json_encode(['error' => sprintf('이 요청의 예상 비용이 약 %d원으로 1회 상한 %d원을 넘어 거절합니다 — 시간 범위를 줄이거나 access_log 를 빼거나 더 저렴한 모델을 고르세요',
                                             (int)ceil($estKrw), (int)$lim['per_request_krw'])], JSON_UNESCAPED_UNICODE);
        return;
    }
    $month = envlog_ai_month_usage();
    if ($month['usd'] + $estUsd > $lim['monthly_cap_usd']) {
        http_response_code(400);
        echo json_encode(['error' => sprintf('이번 달 AI 사용 상한($%.2f)에 도달해 거절합니다 (누적 $%.2f). 다음 달에 다시 쓸 수 있습니다', $lim['monthly_cap_usd'], $month['usd'])], JSON_UNESCAPED_UNICODE);
        return;
    }

    $system = "당신은 Moodle 기반 LMS(Coursemos) 운영을 돕는 로그 분석 전문가입니다. 아파치 access_log 와 PHP 오류 로그(php_errors.log) 조각을 보고 "
            . "운영자의 질문에 한국어로 답합니다. 규칙: ① 로그에 있는 사실과 추정을 구분해 말한다 ② 시간순으로 사용자의 행동 흐름을 먼저 짧게 정리하고, "
            . "그 다음 오류·이상 징후를 시각·파일·요청과 함께 짚고, 마지막에 가장 가능성 높은 원인과 확인 방법을 제안한다 ③ 근거가 되는 로그 줄은 시각과 함께 인용한다 "
            . "④ 자료가 부족하면 무엇을 더 봐야 하는지 말한다. 자료는 서버에서 시간 범위·IP 로 미리 추린 일부이며 줄 수 상한에서 잘렸을 수 있다.";
    $meta = "대학: " . (string)($in['school'] ?? '') . "\n" . ($in['ip'] ?? '' ? "대상 IP: {$in['ip']}\n" : '')
          . (($in['from'] ?? '') !== '' ? "시간 범위: {$in['from']} ~ {$in['to']} (Asia/Seoul)\n" : '') . "로그 로테이션: 매일 " . envlog_ai_rotate_hour() . ":00\n";

    $body = [
        'model' => $model, 'max_tokens' => 4000, 'stream' => true,
        'system' => [['type' => 'text', 'text' => $system]],
        'messages' => [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => $meta . "\n<logs>\n" . $doc . "</logs>\n", 'cache_control' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => "질문: " . $prompt],
        ]]],
    ];

    // SSE 로 흘려보낸다 (nginx 버퍼링 끔)
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache'); header('X-Accel-Buffering: no');
    while (ob_get_level()) ob_end_flush();
    $send = function ($arr) { echo 'data: ' . json_encode($arr, JSON_UNESCAPED_UNICODE) . "\n\n"; flush(); };
    $send(['start' => true, 'model' => $model, 'key_source' => $src, 'sent_bytes' => strlen($doc)]);

    $usage = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0]; $buf = ''; $stop = ''; $httpErr = '';
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'accept: text/event-stream'],
        CURLOPT_TIMEOUT => 300, CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$buf, &$usage, &$stop, &$httpErr, $send) {
            $buf .= $data;
            while (($pos = strpos($buf, "\n")) !== false) {
                $line = rtrim(substr($buf, 0, $pos), "\r"); $buf = substr($buf, $pos + 1);
                if (strncmp($line, 'data: ', 6) !== 0) { if ($line !== '' && $line[0] === '{') $httpErr .= $line; continue; }
                $ev = json_decode(substr($line, 6), true); if (!$ev) continue;
                switch ($ev['type'] ?? '') {
                    case 'content_block_delta':
                        if (($ev['delta']['type'] ?? '') === 'text_delta') $send(['t' => $ev['delta']['text']]);
                        break;
                    case 'message_start':
                        $u = $ev['message']['usage'] ?? []; $usage['input'] = (int)($u['input_tokens'] ?? 0);
                        $usage['cache_read'] = (int)($u['cache_read_input_tokens'] ?? 0); $usage['cache_write'] = (int)($u['cache_creation_input_tokens'] ?? 0);
                        break;
                    case 'message_delta':
                        $usage['output'] = (int)($ev['usage']['output_tokens'] ?? $usage['output']); $stop = (string)($ev['delta']['stop_reason'] ?? $stop);
                        break;
                    case 'error':
                        $send(['error' => ($ev['error']['message'] ?? 'API 오류')]);
                        break;
                }
            }
            return strlen($data);
        },
    ]);
    $ok = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $cerr = curl_error($ch); curl_close($ch);
    if ($ok === false) { $send(['error' => 'Anthropic API 연결 실패: ' . $cerr]); return; }
    if ($code >= 400) {
        $j = json_decode($httpErr ?: $buf, true);
        $send(['error' => 'Anthropic API 오류 ' . $code . ': ' . ($j['error']['message'] ?? substr($httpErr ?: $buf, 0, 300))]); return;
    }
    [$pi, $po] = ENVLOG_AI_PRICE[$model] ?? [0, 0];
    $cost = ($usage['input'] + $usage['cache_write'] * 1.25 + $usage['cache_read'] * 0.1) / 1e6 * $pi + $usage['output'] / 1e6 * $po;
    try {   // 사용 기록 (월 상한 계산용). 실패해도 답은 이미 나갔으니 조용히 로그만
        envlog_ai_usage_table();
        portal_db()->prepare("INSERT INTO envlog_ai_usage (user_id, model, input_tokens, output_tokens, cache_read, cost_usd, created_at) VALUES (?,?,?,?,?,?,NOW())")
                   ->execute([(int)$user['id'], $model, $usage['input'] + $usage['cache_write'], $usage['output'], $usage['cache_read'], round($cost, 5)]);
    } catch (Throwable $e) { error_log('[envlog ai] usage 기록 실패: ' . $e->getMessage()); }
    $send(['done' => true, 'usage' => $usage, 'stop' => $stop, 'cost_usd' => round($cost, 4), 'cost_krw' => (int)ceil($cost * $lim['usd_krw']), 'budget' => envlog_ai_budget_state()]);
}
