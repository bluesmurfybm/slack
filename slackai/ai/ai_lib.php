<?php
/**
 * slackai AI 레이어 공용 헬퍼.
 *  - 순수 DB 함수(ai_setting/ai_enqueue/ai_event/ai_mark_changed …)는 CLI(sync.php, tools/*.php)에서도 쓰므로
 *    auth 를 require 하지 않는다. 웹 전용 함수(ai_actor/ai_is_admin/ai_is_approver)는 auth.php 가 먼저 로드된 상태에서만 부른다.
 *  - 잡 큐 계약(kind/priority/max_attempts/dedupe_key)은 워커 core/jobs.py 와 같다. 바꾸면 둘 다 고친다.
 */
require_once __DIR__ . '/../db.php';

/** 잡 종류별 기본 우선순위(높을수록 먼저) / 최대 시도 횟수. 워커 jobs/__init__.py 와 동일. */
const AI_JOB_PRIORITY = ['commit' => 30, 'revert' => 25, 'execute' => 20, 'review' => 10, 'ingest' => 8, 'plan' => 5,
                         'resync' => 2, 'check_repo' => 1, 'discover_repos' => 1, 'triage' => 0, 'learn' => -5, 'distill' => -10];
const AI_JOB_MAX_ATTEMPTS = ['triage' => 3, 'review' => 3, 'resync' => 3, 'ingest' => 3, 'check_repo' => 2,
                             'plan' => 2, 'learn' => 2, 'distill' => 2, 'discover_repos' => 1, 'execute' => 1, 'commit' => 1, 'revert' => 1];

/** 플랜 모델: 패널 별칭 → Claude 모델 ID. 워커 core/config.py PLAN_MODELS 와 같다. 자동 플랜은 ai_settings.plan_model_auto(기본 haiku). */
const AI_PLAN_MODELS = ['haiku' => 'claude-haiku-4-5', 'sonnet' => 'claude-sonnet-5', 'opus' => 'claude-opus-5'];

function ai_now() { return date('Y-m-d H:i:s'); }

/** ai_settings 값 (없으면 $default) */
function ai_setting($k, $default = null) {
    static $cache = null;
    if ($cache === null) $cache = ai_settings_all();
    return array_key_exists($k, $cache) ? $cache[$k] : $default;
}

/** ai_settings 전체 [k => v] */
function ai_settings_all() {
    $out = [];
    foreach (db()->query("SELECT k, v FROM ai_settings") as $r) $out[$r['k']] = $r['v'];
    return $out;
}

/** ai_settings 저장 */
function ai_set_setting($k, $v, $by = '') {
    db()->prepare("INSERT INTO ai_settings (k, v, updated_by, updated_at) VALUES (?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE v = VALUES(v), updated_by = VALUES(updated_by), updated_at = NOW()")
        ->execute([$k, (string)$v, $by]);
}

/** AI 관련 데이터가 바뀌었음을 표시 → 화면 status.php 폴링이 패널을 갱신한다 */
function ai_mark_changed() { meta_set('ai_changed_at', time()); }

/** 감사 로그 */
function ai_event($requestId, $actor, $action, $refTable = null, $refId = null, array $detail = []) {
    db()->prepare("INSERT INTO ai_events (request_id, actor, action, ref_table, ref_id, detail, ip, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$requestId ?: null, (string)$actor, (string)$action, $refTable, $refId,
                   $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
                   $_SERVER['REMOTE_ADDR'] ?? null]);
}

/**
 * 잡 등록. 같은 kind+request_id(+ref_id) 의 열린 잡(queued/running)이 있으면 새로 만들지 않고 null 을 돌려준다.
 * @return int|null 새 잡 id, 중복이면 null
 */
function ai_enqueue($kind, $requestId, array $params = [], $requestedBy = '', $refId = null, $repoId = null, $priority = null, $maxAttempts = null) {
    $kind = (string)$kind;
    $dedupe = $kind . ':' . ($requestId ?? '') . ':' . ($refId ?? '');
    $st = db()->prepare("INSERT IGNORE INTO ai_jobs
            (kind, request_id, ref_id, repo_id, dedupe_key, params, status, open_key, priority, max_attempts, requested_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'queued', 1, ?, ?, ?, NOW())");
    $st->execute([
        $kind, $requestId ?: null, $refId, $repoId, $dedupe,
        $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
        $priority ?? (AI_JOB_PRIORITY[$kind] ?? 0),
        $maxAttempts ?? (AI_JOB_MAX_ATTEMPTS[$kind] ?? 1),
        (string)$requestedBy,
    ]);
    if ($st->rowCount() === 0) return null;
    $id = (int)db()->lastInsertId();   // meta_set 이 다른 INSERT 를 실행하므로 먼저 읽는다
    ai_mark_changed();
    return $id;
}

/** 요청의 현재 열린 잡(queued/running) 1건 + stale 여부 */
function ai_current_job($requestId) {
    $st = db()->prepare("SELECT id, kind, status, progress, heartbeat_at, started_at, created_at, cancel_requested, attempts, error
                         FROM ai_jobs WHERE request_id = ? AND status IN ('queued','running') ORDER BY id DESC LIMIT 1");
    $st->execute([$requestId]);
    $j = $st->fetch();
    if (!$j) return null;
    $stale = (int)ai_setting('heartbeat_stale_sec', 120);
    $hb = $j['heartbeat_at'] ? strtotime($j['heartbeat_at']) : 0;
    $j['stale'] = ($j['status'] === 'running' && $hb > 0 && (time() - $hb) > $stale);
    return $j;
}

/** 요청의 최근 잡 목록 */
function ai_recent_jobs($requestId, $limit = 20) {
    $st = db()->prepare("SELECT id, kind, status, progress, error, model, cost_usd, tokens_in, tokens_out, requested_by,
                                created_at, started_at, finished_at, heartbeat_at, cancel_requested, attempts
                         FROM ai_jobs WHERE request_id = ? ORDER BY id DESC LIMIT " . (int)$limit);
    $st->execute([$requestId]);
    return $st->fetchAll();
}

/* ===================== 웹 전용 (auth.php 로드 후) ===================== */

/** 현재 사용자의 포털 이메일 (감사·승인 컬럼용) */
function ai_actor() {
    if (function_exists('current_portal_user')) {
        $pu = current_portal_user();
        if ($pu && !empty($pu['email'])) return (string)$pu['email'];
    }
    if (function_exists('current_user')) {
        $u = current_user();
        if ($u) return (string)($u['portal_email'] ?? $u['name'] ?? $u['id'] ?? 'unknown');
    }
    return 'unknown';
}

/** 포털 관리자인가 (core/db.php 의 portal_admin) */
function ai_is_admin($email) {
    if ($email === '' || $email === 'unknown') return false;
    try {
        if (!function_exists('portal_db')) require_once __DIR__ . '/../../core/db.php';
        $st = portal_db()->prepare("SELECT 1 FROM portal_admin WHERE email = ? LIMIT 1");
        $st->execute([$email]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** 승인자인가: config.php slackai.approvers ∪ ai_settings.approvers ∪ admin. 두 목록이 모두 비면 admin 만. */
function ai_is_approver($email) {
    if ($email === '' || $email === 'unknown') return false;
    $cfg  = require __DIR__ . '/../../config.php';
    $list = array_map('strtolower', (array)($cfg['slackai']['approvers'] ?? []));
    $more = json_decode((string)ai_setting('approvers', '[]'), true);
    if (is_array($more)) foreach ($more as $m) $list[] = strtolower((string)$m);
    if (in_array(strtolower($email), $list, true)) return true;
    return ai_is_admin($email);
}

/** JSON 응답 */
function ai_json(array $data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** JSON 오류 응답 */
function ai_fail($code, $message, $http = 400, array $extra = []) {
    ai_json(['ok' => false, 'error' => $code, 'message' => $message] + $extra, $http);
}

/** JSON 본문 파싱 (POST) */
function ai_input() {
    $in = json_decode(file_get_contents('php://input'), true);
    return is_array($in) ? $in : $_POST;
}

/* ===================== panel helpers (ai_state/ai_action) ===================== */

/**
 * 현재 웹 사용자 컨텍스트. ai_state/ai_action 은 이 배열만 보고 권한을 판단한다.
 *  - email       : 포털 이메일(감사·승인 컬럼)
 *  - is_admin    : portal_admin 보유
 *  - can_approve : 승인자(config approvers ∪ ai_settings.approvers ∪ admin) → 승인·커밋·학습노트 승인
 *  - xrw         : 요청에 X-Requested-With: fetch 헤더가 있었나(파괴적 액션의 CSRF 가드)
 * CLI 스모크 테스트는 같은 모양의 배열을 직접 만들어 넘긴다.
 */
function ai_me() {
    $email = ai_actor();
    $admin = ai_is_admin($email);
    return [
        'email'       => $email,
        'is_admin'    => $admin,
        'can_approve' => $admin || ai_is_approver($email),
        'xrw'         => (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch'),
    ];
}
