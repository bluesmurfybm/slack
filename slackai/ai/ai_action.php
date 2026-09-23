<?php
/**
 * 🤖 AI 패널 액션.  POST ai/ai_action.php  JSON {action, request_id, …}
 *
 *  action           | 권한       | 규칙
 *  -----------------|-----------|------------------------------------------------------------------
 *  triage/retriage  | 로그인     | triage 잡 enqueue (retriage = force). 열린 잡 있으면 409 already_running
 *  plan             | 로그인     | triage 필수(422 triage_required), 레포 확정 필수(422 repo_unresolved); repo_id?/hint?
 *  approve_execute  | 승인자·XRW | confirm:true + plan_id + plan_version; draft & 같은 version 만(409 plan_changed);
 *                   |           | 레포 check_status=ok(422 repo_unresolved); 예산 ≤ 설정(422 budget_exceeded);
 *                   |           | ai_plans approved → ai_executions queued → execute 잡(ref_id=execution_id)
 *  reject_plan      | 로그인·XRW | reason 필수; draft 만
 *  commit           | 승인자·XRW | confirm:true + execution_id + message; execution done; 중복 커밋 409 commit_exists;
 *                   |           | ai_commits pending → commit 잡(ref_id=commit_id, push:false)
 *  review           | 로그인     | commit committed|pushed → review 잡(ref_id=commit_id)
 *  learn            | 로그인     | 플랜에 커밋 존재 → learn 잡(ref_id=plan_id, params {plan_id, commit_id, review_id?})
 *  cancel_job       | 로그인·XRW | queued→cancelled, running→cancel_requested=1
 *  set_repo         | 로그인     | repo_id (0=해제); triage 없으면 stub(version 0) 생성; repo_reason='user'
 *  set_tags         | 로그인·XRW | tag_ids[] (활성 태그만); 전체 교체 source='user'; before/after 를 ai_events 에
 *  approve_lesson / reject_lesson | 승인자·XRW | lesson_id
 *
 *  XRW = 요청 헤더 X-Requested-With: fetch 필수(403 bad_request_origin).
 *  모든 성공은 ai_event + ai_mark_changed. 응답 {ok:true, action, …}; 실패 {ok:false, error, message, …} + HTTP 코드.
 *
 *  로직은 ai_perform_action($in, $me) 에 있고 실패는 AiActionError 로 던진다. AI_PANEL_LIB 상수를 정의하고
 *  include 하면 HTTP 처리부를 건너뛰므로 CLI 스모크 테스트에서 함수만 부를 수 있다.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/ai_lib.php';

/** HTTP 코드가 붙은 액션 실패 */
class AiActionError extends RuntimeException {
    public $errorCode;
    public $http;
    public $extra;
    public function __construct($errorCode, $message, $http = 400, array $extra = []) {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->http      = $http;
        $this->extra     = $extra;
    }
}
function ai_abort($errorCode, $message, $http = 400, array $extra = []) { throw new AiActionError($errorCode, $message, $http, $extra); }

const AI_ACTIONS_XRW      = ['approve_execute', 'commit', 'reject_plan', 'set_tags', 'approve_lesson', 'reject_lesson', 'cancel_job'];
const AI_ACTIONS_APPROVER = ['approve_execute', 'commit', 'approve_lesson', 'reject_lesson'];
const AI_ACTIONS_NO_REQ   = ['approve_lesson', 'reject_lesson', 'cancel_job'];   // request_id 없이도 가능(행에서 찾음)

/** 열린(queued/running) 같은 잡의 id — ai_enqueue 가 null 을 돌려줬을 때 409 응답용 */
function ai_open_job_id($kind, $rid, $refId = null) {
    $st = db()->prepare("SELECT id, status FROM ai_jobs WHERE dedupe_key = ? AND open_key = 1 ORDER BY id DESC LIMIT 1");
    $st->execute([$kind . ':' . ($rid ?? '') . ':' . ($refId ?? '')]);
    return $st->fetch() ?: null;
}

/** enqueue 하되 중복이면 409 already_running(+기존 job_id) */
function ai_enqueue_or_conflict($kind, $rid, array $params, $by, $refId = null, $repoId = null, $priority = null) {
    $jid = ai_enqueue($kind, $rid, $params, $by, $refId, $repoId, $priority);
    if ($jid === null) {
        $ex = ai_open_job_id($kind, $rid, $refId);
        ai_abort('already_running', '이미 같은 작업이 ' . (($ex['status'] ?? '') === 'running' ? '진행' : '대기') . ' 중입니다.', 409,
                 ['job_id' => $ex ? (int)$ex['id'] : null, 'job_status' => $ex['status'] ?? null]);
    }
    return $jid;
}

function ai_bool($v) { return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true; }

function ai_row($sql, array $args) {
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetch() ?: null;
}

function ai_repo_row($repoId) {
    return $repoId > 0 ? ai_row("SELECT id, name, vcs, local_path, active, check_status, check_message, default_branch FROM ai_repos WHERE id = ?", [$repoId]) : null;
}

/* ---------------------------------------------------------------- actions */

function ai_act_triage(array $in, array $me, array $req) {
    $force  = ai_bool($in['force'] ?? false);
    $params = ['source' => 'user', 'reason' => $force ? 'retriage' : 'manual', 'force' => $force];
    // 사람이 직접 누른 분석은 동기화가 자동 등록한 triage(우선순위 0)보다 먼저 처리되게 3 으로
    $jid = ai_enqueue_or_conflict('triage', $req['id'], $params, $me['email'], null, null, 3);
    ai_event($req['id'], $me['email'], $force ? 'triage.retry' : 'triage.request', 'ai_jobs', $jid, ['force' => $force]);
    return ['job_id' => $jid];
}

function ai_act_plan(array $in, array $me, array $req) {
    $rid = $req['id'];
    $tri = ai_row("SELECT request_id, version, repo_id FROM ai_triage WHERE request_id = ?", [$rid]);
    if (!$tri || (int)$tri['version'] < 1) ai_abort('triage_required', '먼저 AI 분석(triage)을 실행하세요.', 422);

    $latest = ai_row("SELECT id, version, status FROM ai_plans WHERE request_id = ? ORDER BY version DESC LIMIT 1", [$rid]);
    if ($latest && in_array($latest['status'], ['approved', 'executing'], true)) {
        ai_abort('plan_in_progress', "플랜 v{$latest['version']} 이(가) {$latest['status']} 상태입니다. 실행이 끝난 뒤 다시 생성하세요.", 409,
                 ['plan_id' => (int)$latest['id'], 'status' => $latest['status']]);
    }

    $repoId = array_key_exists('repo_id', $in) && $in['repo_id'] !== '' && $in['repo_id'] !== null ? (int)$in['repo_id'] : (int)$tri['repo_id'];
    if ($repoId <= 0) ai_abort('repo_unresolved', '대상 레포를 먼저 선택하세요.', 422);
    $repo = ai_repo_row($repoId);
    if (!$repo || !(int)$repo['active']) ai_abort('repo_not_found', '레포를 찾을 수 없거나 비활성입니다: #' . $repoId, 404);
    if ($repoId !== (int)$tri['repo_id']) {   // 사용자가 다른 레포를 골라 플랜 생성 → 매핑도 갱신
        db()->prepare("UPDATE ai_triage SET repo_id = ?, repo_confidence = 1.000, repo_reason = 'user', updated_at = NOW() WHERE request_id = ?")
            ->execute([$repoId, $rid]);
        ai_event($rid, $me['email'], 'set_repo', 'ai_triage', null, ['before' => $tri['repo_id'] ? (int)$tri['repo_id'] : null, 'after' => $repoId, 'via' => 'plan']);
    }

    $hint   = trim((string)($in['hint'] ?? ''));
    $params = ['repo_id' => $repoId, 'hint' => $hint !== '' ? $hint : null, 'reason' => 'user'];
    // 모델: 비우면 자동 플랜과 같은 기본(plan_model_auto, 보통 haiku). 필요할 때만 sonnet/opus 로 따로 조회
    $model = strtolower(trim((string)($in['model'] ?? '')));
    if ($model !== '') {
        if (!isset(AI_PLAN_MODELS[$model])) ai_abort('bad_model', '모델은 haiku / sonnet / opus 중 하나여야 합니다.', 400);
        $params['model'] = AI_PLAN_MODELS[$model];
    }
    if ($latest) $params['prev_plan_id'] = (int)$latest['id'];
    $jid = ai_enqueue_or_conflict('plan', $rid, $params, $me['email'], null, $repoId);
    ai_event($rid, $me['email'], 'plan.request', 'ai_jobs', $jid, ['repo_id' => $repoId, 'hint' => $hint, 'model' => $params['model'] ?? 'auto', 'prev_plan_id' => $latest ? (int)$latest['id'] : null]);
    return ['job_id' => $jid, 'repo_id' => $repoId];
}

function ai_act_approve_execute(array $in, array $me, array $req) {
    $rid = $req['id'];
    if (!ai_bool($in['confirm'] ?? false)) ai_abort('confirm_required', '확인(confirm:true) 이 필요합니다.', 400);
    $planId = (int)($in['plan_id'] ?? 0);
    $ver    = (int)($in['plan_version'] ?? 0);
    if ($planId <= 0 || $ver <= 0) ai_abort('plan_required', 'plan_id 와 plan_version 이 필요합니다.', 400);

    $plan = ai_row("SELECT * FROM ai_plans WHERE id = ? AND request_id = ?", [$planId, $rid]);
    if (!$plan) ai_abort('plan_not_found', '플랜을 찾을 수 없습니다: #' . $planId, 404);
    if ($plan['status'] !== 'draft' || (int)$plan['version'] !== $ver) {
        ai_abort('plan_changed', "플랜 상태가 바뀌었습니다 (v{$plan['version']} {$plan['status']}). 패널을 새로 고친 뒤 다시 확인하세요.", 409,
                 ['status' => $plan['status'], 'version' => (int)$plan['version']]);
    }

    $tri    = ai_row("SELECT repo_id FROM ai_triage WHERE request_id = ?", [$rid]);
    $repoId = (int)($plan['repo_id'] ?: ($tri['repo_id'] ?? 0));
    if ($repoId <= 0) ai_abort('repo_unresolved', '대상 레포가 확정되지 않았습니다.', 422);
    $repo = ai_repo_row($repoId);
    if (!$repo || !(int)$repo['active']) ai_abort('repo_unresolved', '레포가 없거나 비활성입니다: #' . $repoId, 422);
    if (($repo['check_status'] ?? '') !== 'ok') {
        ai_abort('repo_unresolved', "레포 점검 상태가 ok 가 아닙니다 ({$repo['name']}: " . ($repo['check_status'] ?: 'unchecked') . '). 📁 레포 매핑에서 [점검] 을 먼저 실행하세요.', 422,
                 ['repo_id' => $repoId, 'check_status' => $repo['check_status'], 'check_message' => $repo['check_message']]);
    }

    $max    = (float)ai_setting('budget_execute_usd', 10);
    $budget = (isset($in['budget_usd']) && $in['budget_usd'] !== '' && $in['budget_usd'] !== null) ? (float)$in['budget_usd'] : $max;
    if (!($budget > 0) || $budget > $max + 1e-9) {
        ai_abort('budget_exceeded', sprintf('예산은 0 보다 크고 설정 상한 $%.2f 이하여야 합니다.', $max), 422, ['max_budget_usd' => $max]);
    }

    $open = ai_row("SELECT id, status FROM ai_executions WHERE plan_id = ? AND status IN ('queued','running') ORDER BY id DESC LIMIT 1", [$planId]);
    if ($open) ai_abort('execution_in_progress', "이 플랜의 실행 #{$open['id']} 이(가) {$open['status']} 입니다.", 409, ['execution_id' => (int)$open['id']]);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // compare-and-set: 다른 탭이 먼저 승인/반려/재생성했으면 0행
        $up = $pdo->prepare("UPDATE ai_plans SET status = 'approved', approved_by = ?, approved_at = NOW(), budget_usd = ?, repo_id = ?
                             WHERE id = ? AND status = 'draft' AND version = ?");
        $up->execute([$me['email'], $budget, $repoId, $planId, $ver]);
        if ($up->rowCount() === 0) {
            $pdo->rollBack();
            ai_abort('plan_changed', '플랜 상태가 방금 바뀌었습니다. 패널을 새로 고친 뒤 다시 확인하세요.', 409);
        }
        $pdo->prepare("INSERT INTO ai_executions (plan_id, request_id, status, approved_by, created_at) VALUES (?, ?, 'queued', ?, NOW())")
            ->execute([$planId, $rid, $me['email']]);
        $execId = (int)$pdo->lastInsertId();

        $jid = ai_enqueue('execute', $rid, ['plan_id' => $planId, 'execution_id' => $execId, 'budget_usd' => $budget], $me['email'], $execId, $repoId);
        if ($jid === null) {
            $pdo->rollBack();
            $ex = ai_open_job_id('execute', $rid, $execId);
            ai_abort('already_running', '실행 잡이 이미 열려 있습니다.', 409, ['job_id' => $ex ? (int)$ex['id'] : null]);
        }
        $pdo->prepare("UPDATE ai_executions SET job_id = ? WHERE id = ?")->execute([$jid, $execId]);
        ai_event($rid, $me['email'], 'plan.approve', 'ai_plans', $planId,
                 ['version' => $ver, 'execution_id' => $execId, 'job_id' => $jid, 'budget_usd' => $budget, 'repo_id' => $repoId, 'repo' => $repo['name']]);
        $pdo->commit();
    } catch (AiActionError $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['plan_id' => $planId, 'plan_version' => $ver, 'execution_id' => $execId, 'job_id' => $jid, 'budget_usd' => $budget, 'repo_id' => $repoId];
}

function ai_act_reject_plan(array $in, array $me, array $req) {
    $rid    = $req['id'];
    $planId = (int)($in['plan_id'] ?? 0);
    $reason = trim((string)($in['reason'] ?? ''));
    if ($planId <= 0) ai_abort('plan_required', 'plan_id 가 필요합니다.', 400);
    if ($reason === '') ai_abort('reason_required', '반려 사유를 입력하세요.', 400);
    $plan = ai_row("SELECT id, version, status FROM ai_plans WHERE id = ? AND request_id = ?", [$planId, $rid]);
    if (!$plan) ai_abort('plan_not_found', '플랜을 찾을 수 없습니다: #' . $planId, 404);
    if ($plan['status'] !== 'draft') ai_abort('plan_changed', "draft 상태의 플랜만 반려할 수 있습니다 (현재 {$plan['status']}).", 409, ['status' => $plan['status']]);
    $up = db()->prepare("UPDATE ai_plans SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), reject_reason = ? WHERE id = ? AND status = 'draft'");
    $up->execute([$me['email'], $reason, $planId]);
    if ($up->rowCount() === 0) ai_abort('plan_changed', '플랜 상태가 방금 바뀌었습니다.', 409);
    ai_event($rid, $me['email'], 'plan.reject', 'ai_plans', $planId, ['version' => (int)$plan['version'], 'reason' => $reason]);
    return ['plan_id' => $planId, 'status' => 'rejected'];
}

function ai_act_commit(array $in, array $me, array $req) {
    $rid = $req['id'];
    if (!ai_bool($in['confirm'] ?? false)) ai_abort('confirm_required', '확인(confirm:true) 이 필요합니다.', 400);
    $execId  = (int)($in['execution_id'] ?? 0);
    $message = trim(str_replace("\r\n", "\n", (string)($in['message'] ?? '')));
    if ($execId <= 0) ai_abort('execution_required', 'execution_id 가 필요합니다.', 400);
    if ($message === '') ai_abort('message_required', '커밋 메시지를 입력하세요.', 400);

    $exec = ai_row("SELECT id, plan_id, status, files_changed FROM ai_executions WHERE id = ? AND request_id = ?", [$execId, $rid]);
    if (!$exec) ai_abort('execution_not_found', '실행 기록이 없습니다: #' . $execId, 404);
    if ($exec['status'] !== 'done') ai_abort('execution_not_done', "실행이 done 상태가 아닙니다 (현재 {$exec['status']}).", 409, ['status' => $exec['status']]);
    $dup = ai_row("SELECT id, status FROM ai_commits WHERE execution_id = ? AND status IN ('pending','committed','pushed') ORDER BY id DESC LIMIT 1", [$execId]);
    if ($dup) ai_abort('commit_exists', "이 실행은 이미 커밋 #{$dup['id']} ({$dup['status']}) 이(가) 있습니다.", 409, ['commit_id' => (int)$dup['id'], 'status' => $dup['status']]);

    $plan   = ai_row("SELECT id, repo_id, title FROM ai_plans WHERE id = ?", [(int)$exec['plan_id']]);
    $repoId = (int)($plan['repo_id'] ?? 0);
    $repo   = ai_repo_row($repoId);
    if (!$repo) ai_abort('repo_unresolved', '플랜의 레포를 찾을 수 없습니다.', 422);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO ai_commits (execution_id, request_id, repo_id, vcs, branch, message, status, committed_by, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW())")
            ->execute([$execId, $rid, $repoId, $repo['vcs'], $repo['default_branch'] ?: null, $message, $me['email']]);
        $cid = (int)$pdo->lastInsertId();
        $jid = ai_enqueue('commit', $rid, ['execution_id' => $execId, 'commit_id' => $cid, 'push' => false], $me['email'], $cid, $repoId);
        if ($jid === null) {
            $pdo->rollBack();
            $ex = ai_open_job_id('commit', $rid, $cid);
            ai_abort('already_running', '커밋 잡이 이미 열려 있습니다.', 409, ['job_id' => $ex ? (int)$ex['id'] : null]);
        }
        $pdo->prepare("UPDATE ai_commits SET job_id = ? WHERE id = ?")->execute([$jid, $cid]);
        ai_event($rid, $me['email'], 'commit.request', 'ai_commits', $cid,
                 ['execution_id' => $execId, 'job_id' => $jid, 'repo_id' => $repoId, 'vcs' => $repo['vcs'], 'subject' => mb_substr(strtok($message, "\n"), 0, 120)]);
        $pdo->commit();
    } catch (AiActionError $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['commit_id' => $cid, 'job_id' => $jid, 'execution_id' => $execId];
}

function ai_act_review(array $in, array $me, array $req) {
    $rid = $req['id'];
    $cid = (int)($in['commit_id'] ?? 0);
    $c = $cid > 0
        ? ai_row("SELECT id, status FROM ai_commits WHERE id = ? AND request_id = ?", [$cid, $rid])
        : ai_row("SELECT id, status FROM ai_commits WHERE request_id = ? AND status IN ('committed','pushed') ORDER BY id DESC LIMIT 1", [$rid]);
    if (!$c) ai_abort('commit_not_found', '검토할 커밋이 없습니다.', 404);
    if (!in_array($c['status'], ['committed', 'pushed'], true)) ai_abort('commit_not_done', "커밋이 완료되지 않았습니다 (현재 {$c['status']}).", 409, ['status' => $c['status']]);
    $cid = (int)$c['id'];
    $jid = ai_enqueue_or_conflict('review', $rid, ['commit_id' => $cid], $me['email'], $cid);
    ai_event($rid, $me['email'], 'review.request', 'ai_jobs', $jid, ['commit_id' => $cid]);
    return ['job_id' => $jid, 'commit_id' => $cid];
}

function ai_act_learn(array $in, array $me, array $req) {
    $rid    = $req['id'];
    $planId = (int)($in['plan_id'] ?? 0);
    $plan = $planId > 0
        ? ai_row("SELECT id, version FROM ai_plans WHERE id = ? AND request_id = ?", [$planId, $rid])
        : ai_row("SELECT id, version FROM ai_plans WHERE request_id = ? AND status IN ('committed','reviewed','executed') ORDER BY version DESC LIMIT 1", [$rid]);
    if (!$plan) ai_abort('plan_not_found', '학습할 플랜이 없습니다.', 404);
    $planId = (int)$plan['id'];
    $c = ai_row("SELECT c.id FROM ai_commits c JOIN ai_executions e ON e.id = c.execution_id
                 WHERE e.plan_id = ? AND c.status IN ('committed','pushed') ORDER BY c.id DESC LIMIT 1", [$planId]);
    if (!$c) ai_abort('commit_required', '커밋이 완료된 플랜만 학습할 수 있습니다.', 409);
    $cid = (int)$c['id'];
    $rev = ai_row("SELECT id FROM ai_reviews WHERE commit_id = ? ORDER BY id DESC LIMIT 1", [$cid]);
    $params = ['plan_id' => $planId, 'commit_id' => $cid];
    if ($rev) $params['review_id'] = (int)$rev['id'];
    $jid = ai_enqueue_or_conflict('learn', $rid, $params, $me['email'], $planId);
    ai_event($rid, $me['email'], 'learn.request', 'ai_jobs', $jid, $params);
    return ['job_id' => $jid] + $params;
}

function ai_act_cancel_job(array $in, array $me, $req) {
    $jid = (int)($in['job_id'] ?? 0);
    if ($jid <= 0) ai_abort('job_required', 'job_id 가 필요합니다.', 400);
    $j = ai_row("SELECT id, kind, status, request_id FROM ai_jobs WHERE id = ?", [$jid]);
    if (!$j || ($req && $j['request_id'] !== $req['id'])) ai_abort('job_not_found', '잡을 찾을 수 없습니다: #' . $jid, 404);
    $rid = $j['request_id'];
    if ($j['status'] === 'queued') {
        $up = db()->prepare("UPDATE ai_jobs SET status = 'cancelled', open_key = NULL, finished_at = NOW(), error = ? WHERE id = ? AND status = 'queued'");
        $up->execute(['사용자 취소: ' . $me['email'], $jid]);
        if ($up->rowCount() === 0) ai_abort('job_changed', '잡 상태가 방금 바뀌었습니다.', 409);
        $result = 'cancelled';
    } elseif ($j['status'] === 'running') {
        db()->prepare("UPDATE ai_jobs SET cancel_requested = 1 WHERE id = ? AND status = 'running'")->execute([$jid]);
        $result = 'cancel_requested';
    } else {
        ai_abort('job_finished', "이미 끝난 잡입니다 ({$j['status']}).", 409, ['status' => $j['status']]);
    }
    ai_event($rid, $me['email'], 'job.cancel', 'ai_jobs', $jid, ['kind' => $j['kind'], 'prev_status' => $j['status'], 'result' => $result]);
    return ['job_id' => $jid, 'result' => $result, 'request_id' => $rid];
}

function ai_act_set_repo(array $in, array $me, array $req) {
    $rid    = $req['id'];
    $repoId = (int)($in['repo_id'] ?? 0);
    $repo   = null;
    if ($repoId > 0) {
        $repo = ai_repo_row($repoId);
        if (!$repo) ai_abort('repo_not_found', '레포를 찾을 수 없습니다: #' . $repoId, 404);
    }
    $tri    = ai_row("SELECT request_id, repo_id FROM ai_triage WHERE request_id = ?", [$rid]);
    $before = $tri && $tri['repo_id'] ? (int)$tri['repo_id'] : null;
    $after  = $repoId > 0 ? $repoId : null;
    if (!$tri) {   // 분석 전이라도 레포를 정해둘 수 있게 stub(version 0) 행 — 워커 triage 가 덮어쓰며 version 1 로
        db()->prepare("INSERT INTO ai_triage (request_id, version, repo_id, repo_confidence, repo_reason, created_at, updated_at)
                       VALUES (?, 0, ?, ?, 'user', NOW(), NOW())")
            ->execute([$rid, $after, $after ? 1.000 : null]);
    } else {
        db()->prepare("UPDATE ai_triage SET repo_id = ?, repo_confidence = ?, repo_reason = 'user', updated_at = NOW() WHERE request_id = ?")
            ->execute([$after, $after ? 1.000 : null, $rid]);
    }
    ai_event($rid, $me['email'], 'set_repo', 'ai_triage', null, ['before' => $before, 'after' => $after, 'repo' => $repo['name'] ?? null]);

    // 분석이 끝난 문의에 레포를 정하면 플랜까지 자동으로(auto_plan). 승인~검토 중인 플랜이 있으면 건드리지 않는다.
    $planJob = null;
    if ($after && $tri && ai_setting('auto_plan', '1') === '1' && (int)$repo['active'] === 1) {
        $v      = ai_row("SELECT version FROM ai_triage WHERE request_id = ?", [$rid]);
        $latest = ai_row("SELECT id, status FROM ai_plans WHERE request_id = ? ORDER BY version DESC LIMIT 1", [$rid]);
        $busy   = $latest && in_array($latest['status'], ['approved', 'executing', 'executed', 'committed', 'reviewed'], true);
        if ($v && (int)$v['version'] >= 1 && !$busy) {
            $params = ['repo_id' => $after, 'reason' => 'set_repo', 'source' => 'auto'];
            if ($latest) $params['prev_plan_id'] = (int)$latest['id'];
            $planJob = ai_enqueue('plan', $rid, $params, $me['email'], null, $after);
            if ($planJob) ai_event($rid, $me['email'], 'plan.request', 'ai_jobs', $planJob, ['repo_id' => $after, 'via' => 'set_repo']);
        }
    }
    return ['repo_id' => $after, 'plan_job_id' => $planJob,
            'repo' => $repo ? ['id' => (int)$repo['id'], 'name' => $repo['name'], 'vcs' => $repo['vcs']] : null];
}

function ai_act_set_tags(array $in, array $me, array $req) {
    $rid = $req['id'];
    $ids = $in['tag_ids'] ?? null;
    if (!is_array($ids)) ai_abort('tag_ids_required', 'tag_ids 배열이 필요합니다.', 400);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    $names = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT id, name, color FROM ai_tags WHERE active = 1 AND id IN ($ph)");
        $st->execute($ids);
        foreach ($st->fetchAll() as $t) $names[(int)$t['id']] = $t;
        $bad = array_values(array_diff($ids, array_keys($names)));
        if ($bad) ai_abort('invalid_tag', '없거나 비활성인 태그: #' . implode(', #', $bad), 422, ['invalid' => $bad]);
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT rt.tag_id AS id, g.name, rt.source FROM request_tags rt LEFT JOIN ai_tags g ON g.id = rt.tag_id WHERE rt.request_id = ? ORDER BY rt.tag_id");
        $st->execute([$rid]);
        $before = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'source' => $r['source']], $st->fetchAll());
        $pdo->prepare("DELETE FROM request_tags WHERE request_id = ?")->execute([$rid]);
        $ins = $pdo->prepare("INSERT INTO request_tags (request_id, tag_id, source, confidence, set_by, created_at) VALUES (?, ?, 'user', NULL, ?, NOW())");
        $after = [];
        foreach ($ids as $id) {
            $ins->execute([$rid, $id, $me['email']]);
            $after[] = ['id' => $id, 'name' => $names[$id]['name']];
        }
        $changed = array_column($before, 'id') !== array_column($after, 'id');
        ai_event($rid, $me['email'], 'set_tags', 'request_tags', null, ['before' => $before, 'after' => $after, 'changed' => $changed]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $tags = [];
    foreach ($ids as $id) $tags[] = ['id' => $id, 'name' => $names[$id]['name'], 'color' => $names[$id]['color'], 'source' => 'user'];
    return ['tags' => $tags, 'changed' => $changed];
}

function ai_act_lesson(array $in, array $me, $req, $approve) {
    $lid = (int)($in['lesson_id'] ?? 0);
    if ($lid <= 0) ai_abort('lesson_required', 'lesson_id 가 필요합니다.', 400);
    $l = ai_row("SELECT id, request_id, title, status FROM ai_lessons WHERE id = ?", [$lid]);
    if (!$l || ($req && $l['request_id'] !== null && $l['request_id'] !== $req['id'])) ai_abort('lesson_not_found', '학습 노트를 찾을 수 없습니다: #' . $lid, 404);
    $target = $approve ? 'approved' : 'rejected';
    if ($l['status'] === $target) ai_abort('lesson_unchanged', "이미 {$target} 상태입니다.", 409, ['status' => $l['status']]);
    if ($approve) {
        db()->prepare("UPDATE ai_lessons SET status = 'approved', approved_by = ?, approved_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$me['email'], $lid]);
    } else {
        db()->prepare("UPDATE ai_lessons SET status = 'rejected', approved_by = NULL, approved_at = NULL, updated_at = NOW() WHERE id = ?")->execute([$lid]);
    }
    $reason = trim((string)($in['reason'] ?? ''));
    ai_event($l['request_id'], $me['email'], $approve ? 'lesson.approve' : 'lesson.reject', 'ai_lessons', $lid,
             ['title' => $l['title'], 'prev_status' => $l['status']] + ($reason !== '' ? ['reason' => $reason] : []));
    return ['lesson_id' => $lid, 'status' => $target, 'request_id' => $l['request_id']];
}

/* ---------------------------------------------------------------- dispatcher */

/**
 * @param array $in  {action, request_id, …}
 * @param array $me  ai_me() 모양 [email, is_admin, can_approve, xrw]
 * @return array 성공 결과(ok/action 제외)
 * @throws AiActionError
 */
function ai_perform_action(array $in, array $me) {
    $action = strtolower(trim((string)($in['action'] ?? '')));
    if ($action === 'retriage') { $action = 'triage'; $in['force'] = true; }
    $known = ['triage', 'plan', 'approve_execute', 'reject_plan', 'commit', 'review', 'learn', 'cancel_job', 'set_repo', 'set_tags', 'approve_lesson', 'reject_lesson'];
    if (!in_array($action, $known, true)) ai_abort('unknown_action', '알 수 없는 action: ' . $action, 400);
    if (in_array($action, AI_ACTIONS_XRW, true) && empty($me['xrw'])) ai_abort('bad_request_origin', '이 액션은 X-Requested-With: fetch 헤더가 필요합니다.', 403);
    if (in_array($action, AI_ACTIONS_APPROVER, true) && empty($me['can_approve'])) ai_abort('forbidden', '승인 권한이 없습니다. (config.php slackai.approvers / AI 설정 approvers)', 403);
    if (empty($me['email']) || $me['email'] === 'unknown') ai_abort('forbidden', '사용자 이메일을 확인할 수 없습니다.', 403);

    $rid = trim((string)($in['request_id'] ?? ''));
    $req = null;
    if ($rid !== '') {
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $rid)) ai_abort('bad_request_id', 'request_id 형식이 잘못되었습니다.', 400);
        $req = ai_row("SELECT id, board, title, status, archived FROM requests WHERE id = ?", [$rid]);
        if (!$req) ai_abort('request_not_found', '요청을 찾을 수 없습니다: ' . $rid, 404);
    } elseif (!in_array($action, AI_ACTIONS_NO_REQ, true)) {
        ai_abort('request_id_required', 'request_id 가 필요합니다.', 400);
    }

    switch ($action) {
        case 'triage':          $out = ai_act_triage($in, $me, $req); break;
        case 'plan':            $out = ai_act_plan($in, $me, $req); break;
        case 'approve_execute': $out = ai_act_approve_execute($in, $me, $req); break;
        case 'reject_plan':     $out = ai_act_reject_plan($in, $me, $req); break;
        case 'commit':          $out = ai_act_commit($in, $me, $req); break;
        case 'review':          $out = ai_act_review($in, $me, $req); break;
        case 'learn':           $out = ai_act_learn($in, $me, $req); break;
        case 'cancel_job':      $out = ai_act_cancel_job($in, $me, $req); break;
        case 'set_repo':        $out = ai_act_set_repo($in, $me, $req); break;
        case 'set_tags':        $out = ai_act_set_tags($in, $me, $req); break;
        case 'approve_lesson':  $out = ai_act_lesson($in, $me, $req, true); break;
        case 'reject_lesson':   $out = ai_act_lesson($in, $me, $req, false); break;
        default:                ai_abort('unknown_action', '알 수 없는 action', 400);
    }
    ai_mark_changed();   // status.php 폴링 → 다른 탭의 열린 패널 갱신
    return ['action' => $action, 'request_id' => $rid !== '' ? $rid : ($out['request_id'] ?? null)] + $out;
}

/* ===================== HTTP ===================== */
if (!defined('AI_PANEL_LIB')) {
    require_login();
    session_release();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') ai_fail('method_not_allowed', 'POST 만 허용합니다.', 405);
    $in = ai_input();
    try {
        $out = ai_perform_action($in, ai_me());
        ai_json(['ok' => true] + $out);
    } catch (AiActionError $e) {
        if (db()->inTransaction()) db()->rollBack();
        ai_fail($e->errorCode, $e->getMessage(), $e->http, $e->extra);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        ai_fail('server_error', $e->getMessage(), 500);
    }
}
