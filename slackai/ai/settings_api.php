<?php
/**
 * AI 설정(ai_settings) API — ai/settings.php 화면이 쓴다. 승인자 또는 관리자만(GET 포함).
 *  GET            → {ok, rows:{k:{v,updated_by,updated_at}}, schema:{k:{type,…}}}
 *  POST {k, v}    → 알려진 키만 검증 후 upsert. {items:{k:v,…}} 로 여러 개도 가능.
 *      bool    paused auto_triage auto_plan auto_review retriage_on_update enqueue_on_full post_to_slack   → '0'|'1'
 *      money   budget_plan_usd budget_execute_usd budget_review_usd max_daily_usd                          → 0 이상, 소수 2자리
 *      ratio   similar_min                                                                                 → 0~1, 소수 3자리
 *      int     similar_limit(1~100) comment_scan_sec(0~86400) heartbeat_stale_sec(10~3600)
 *      emails  approvers                                                                                   → JSON 이메일 배열
 *      reviewer reviewer                                                                                   → codex|openai|gemini|claude 콤마 목록
 *  오류: 400 unknown_key / bad_value, 403 forbidden
 *  CLI 에서는 settings_api_handle() 만 정의한다(_smoke_admin.php).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/ai_lib.php';
require_once __DIR__ . '/admin_lib.php';

/** 알려진 키와 검증 규칙. 워커 core/config.py Effective() 가 읽는 키 목록과 같다. */
function settings_api_schema() {
    return [
        'paused'              => ['type' => 'bool'],
        'auto_triage'         => ['type' => 'bool'],
        'auto_plan'           => ['type' => 'bool'],
        'auto_review'         => ['type' => 'bool'],
        'retriage_on_update'  => ['type' => 'bool'],
        'enqueue_on_full'     => ['type' => 'bool'],
        'post_to_slack'       => ['type' => 'bool'],
        'budget_plan_usd'     => ['type' => 'money', 'min' => 0, 'max' => 1000],
        'budget_execute_usd'  => ['type' => 'money', 'min' => 0, 'max' => 1000],
        'budget_review_usd'   => ['type' => 'money', 'min' => 0, 'max' => 1000],
        'max_daily_usd'       => ['type' => 'money', 'min' => 0, 'max' => 10000],
        'similar_min'         => ['type' => 'ratio'],
        'similar_limit'       => ['type' => 'int', 'min' => 1, 'max' => 100],
        'comment_scan_sec'    => ['type' => 'int', 'min' => 0, 'max' => 86400],
        'heartbeat_stale_sec' => ['type' => 'int', 'min' => 10, 'max' => 3600],
        'approvers'           => ['type' => 'emails'],
        'reviewer'            => ['type' => 'reviewer', 'allowed' => ['codex', 'openai', 'gemini', 'claude']],
        'plan_model_auto'     => ['type' => 'choice', 'allowed' => array_values(AI_PLAN_MODELS),
                                  'labels' => ['claude-haiku-4-5' => 'Haiku 4.5 (저렴·빠름)', 'claude-sonnet-5' => 'Sonnet 5', 'claude-opus-5' => 'Opus 5 (고비용)']],
    ];
}

/** 값 검증·정규화. 잘못되면 400 bad_value */
function settings_api_normalize($k, $v) {
    $schema = settings_api_schema();
    if (!isset($schema[$k])) admin_abort('unknown_key', "알 수 없는 설정 키입니다: {$k}", 400);
    $rule = $schema[$k];
    switch ($rule['type']) {
        case 'bool':
            if (is_bool($v)) return $v ? '1' : '0';
            $s = strtolower(trim((string)$v));
            if (in_array($s, ['1', 'true', 'on', 'yes'], true)) return '1';
            if (in_array($s, ['0', 'false', 'off', 'no', ''], true)) return '0';
            admin_abort('bad_value', "{$k}: 0 또는 1 이어야 합니다.", 400);
        case 'money':
            $s = trim((string)$v);
            if ($s === '' || !is_numeric($s)) admin_abort('bad_value', "{$k}: 숫자(USD)여야 합니다.", 400);
            $f = (float)$s;
            if ($f < $rule['min'] || $f > $rule['max']) admin_abort('bad_value', "{$k}: {$rule['min']}~{$rule['max']} 사이여야 합니다.", 400);
            return number_format($f, 2, '.', '');
        case 'ratio':
            $s = trim((string)$v);
            if ($s === '' || !is_numeric($s)) admin_abort('bad_value', "{$k}: 0~1 숫자여야 합니다.", 400);
            $f = (float)$s;
            if ($f < 0 || $f > 1) admin_abort('bad_value', "{$k}: 0~1 사이여야 합니다.", 400);
            return number_format($f, 3, '.', '');
        case 'int':
            $s = trim((string)$v);
            if ($s === '' || !preg_match('/^-?\d+$/', $s)) admin_abort('bad_value', "{$k}: 정수여야 합니다.", 400);
            $i = (int)$s;
            if ($i < $rule['min'] || $i > $rule['max']) admin_abort('bad_value', "{$k}: {$rule['min']}~{$rule['max']} 사이여야 합니다.", 400);
            return (string)$i;
        case 'emails':
            $list = $v;
            if (is_string($list)) {
                $d = json_decode($list, true);
                $list = is_array($d) ? $d : admin_list($list, '/[,\s;]+/u');
            }
            if (!is_array($list)) admin_abort('bad_value', "{$k}: 이메일 배열이어야 합니다.", 400);
            $out = [];
            foreach ($list as $e) {
                $e = strtolower(trim((string)$e));
                if ($e === '') continue;
                if (!admin_is_email($e)) admin_abort('bad_value', "{$k}: 이메일 형식이 아닙니다: {$e}", 400);
                if (!in_array($e, $out, true)) $out[] = $e;
            }
            return json_encode($out, JSON_UNESCAPED_UNICODE);
        case 'choice':
            $v = trim((string)$v);
            if (!in_array($v, $rule['allowed'], true)) admin_abort('bad_value', "{$k}: 허용 값이 아닙니다: {$v} (" . implode('|', $rule['allowed']) . ")", 400);
            return $v;
        case 'reviewer':
            $list = is_array($v) ? $v : admin_list($v);
            $out = [];
            foreach ($list as $r) {
                $r = strtolower(trim((string)$r));
                if ($r === '') continue;
                if (!in_array($r, $rule['allowed'], true)) admin_abort('bad_value', "{$k}: 허용되지 않는 검토자입니다: {$r} (" . implode('|', $rule['allowed']) . ")", 400);
                if (!in_array($r, $out, true)) $out[] = $r;
            }
            if (!$out) admin_abort('bad_value', "{$k}: 검토자를 하나 이상 지정하세요.", 400);
            return implode(',', $out);
    }
    admin_abort('bad_value', "{$k}: 검증 규칙이 없습니다.", 400);
}

function settings_api_rows(PDO $pdo) {
    $rows = [];
    foreach ($pdo->query("SELECT k, v, updated_by, updated_at FROM ai_settings ORDER BY k") as $r) {
        $rows[$r['k']] = ['v' => $r['v'], 'updated_by' => $r['updated_by'], 'updated_at' => $r['updated_at']];
    }
    return $rows;
}

function settings_api_handle($method, array $get, array $in, array $me) {
    admin_require($me, 'approver');
    $pdo   = db();
    $actor = (string)($me['email'] ?? 'unknown');

    if ($method === 'GET') {
        $cfg = require __DIR__ . '/../../config.php';
        return ['rows' => settings_api_rows($pdo), 'schema' => settings_api_schema(),
                'config_approvers' => array_values((array)($cfg['slackai']['approvers'] ?? [])),
                'is_admin' => !empty($me['is_admin'])];
    }

    $items = [];
    if (isset($in['items']) && is_array($in['items'])) {
        $items = $in['items'];
    } elseif (array_key_exists('k', $in)) {
        $items[(string)$in['k']] = $in['v'] ?? '';
    }
    if (!$items) admin_abort('bad_request', '{k, v} 또는 {items:{k:v}} 가 필요합니다.', 400);

    $before  = settings_api_rows($pdo);
    $changed = [];
    $normalized = [];
    foreach ($items as $k => $v) $normalized[(string)$k] = settings_api_normalize((string)$k, $v);   // 전부 검증 후 저장
    foreach ($normalized as $k => $v) {
        $old = $before[$k]['v'] ?? null;
        if ($old === $v) continue;
        ai_set_setting($k, $v, $actor);
        $changed[$k] = ['from' => $old, 'to' => $v];
    }
    if ($changed) {
        ai_event(null, $actor, 'settings.update', 'ai_settings', null, $changed);
        ai_mark_changed();
    }
    $rows = settings_api_rows($pdo);
    $out  = ['changed' => array_keys($changed), 'rows' => array_intersect_key($rows, $normalized)];
    if (count($normalized) === 1) { $k = array_key_first($normalized); $out['k'] = $k; $out['v'] = $normalized[$k]; $out['row'] = $rows[$k] ?? null; }
    return $out;
}

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../auth.php';
    require_login();
    session_release();
    admin_run('settings_api_handle');
}
