<?php
/**
 * 🤖 AI 패널 전체 상태 1회 응답.
 *   GET ai/ai_state.php?id=Rec…[&plan_version=N]
 *
 * 응답(모두 한 번에): request(요약 필드), triage, tags{assigned, all}, similar[], repo{resolved, candidates, all},
 *   plan(선택 버전 + versions[]), execution(그 플랜의 최신, diff 는 20KB 절단 + diff_truncated), commit, review,
 *   lessons[], job(현재 열린 잡 + stale), jobs[], events[], can_approve/is_admin/me, settings 일부, now.
 *
 * 로직은 ai_build_state($rid, $me) 함수 하나에 있다. AI_PANEL_LIB 상수를 정의하고 include 하면
 * HTTP 처리부를 건너뛰므로 CLI 스모크 테스트에서 함수만 부를 수 있다.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/ai_lib.php';

const AI_DIFF_PREVIEW_BYTES = 20480;   // 패널에 인라인으로 보내는 diff 최대 바이트(전체는 ai_diff.php)

/** JSON 컬럼 디코드(NULL/깨진 값은 $default) */
function ai_jdec($s, $default = null) {
    if ($s === null || $s === '') return $default;
    $v = json_decode($s, true);
    return $v === null ? $default : $v;
}

/** DATETIME 문자열 → 경과 초 (NULL 이면 null) */
function ai_age_sec($dt) {
    if (!$dt) return null;
    $t = strtotime($dt);
    return $t ? max(0, time() - $t) : null;
}

/** 값 캐스팅 헬퍼: 지정 키들을 int/float/bool 로 */
function ai_cast(array &$row, array $ints = [], array $floats = [], array $bools = []) {
    foreach ($ints as $k)   if (array_key_exists($k, $row)) $row[$k] = $row[$k] === null ? null : (int)$row[$k];
    foreach ($floats as $k) if (array_key_exists($k, $row)) $row[$k] = $row[$k] === null ? null : (float)$row[$k];
    foreach ($bools as $k)  if (array_key_exists($k, $row)) $row[$k] = $row[$k] === null ? null : (bool)$row[$k];
}

/**
 * 패널 상태 조립.
 * @param string     $rid          requests.id
 * @param array      $me           ai_me() 모양 [email, is_admin, can_approve]
 * @param int|null   $planVersion  특정 버전을 보고 싶을 때(없으면 최신 버전)
 * @return array|null  요청이 없으면 null
 */
function ai_build_state($rid, array $me, $planVersion = null) {
    $pdo = db();

    // ---- 요청(패널이 제목/상태/레포 판별에 쓰는 최소 필드) ----
    $st = $pdo->prepare("SELECT id, board, title, status, asg, `done`, archived, lms, created, cmt_count FROM requests WHERE id = ?");
    $st->execute([$rid]);
    $req = $st->fetch();
    if (!$req) return null;
    ai_cast($req, ['created', 'archived', 'cmt_count']);

    // ---- triage ----
    $st = $pdo->prepare("SELECT * FROM ai_triage WHERE request_id = ?");
    $st->execute([$rid]);
    $tri = $st->fetch() ?: null;
    if ($tri) {
        ai_cast($tri, ['version', 'difficulty', 'repo_id', 'job_id'], ['repo_confidence', 'cost_usd']);
        $tri['questions']          = ai_jdec($tri['questions'], []);
        $tri['suggested_new_tags'] = ai_jdec($tri['suggested_new_tags'], []);
        $tri['repo_candidates']    = ai_jdec($tri['repo_candidates'], []);
        $tri['stub']               = ($tri['version'] === 0);   // set_repo 만으로 만들어진 행(분석 전)
        $tri['age_sec']            = ai_age_sec($tri['updated_at']);
    }

    // ---- 태그 ----
    $allTags = $pdo->query("SELECT id, name, slug, parent_id, color, description, sort_order
                            FROM ai_tags WHERE active = 1 ORDER BY sort_order, id")->fetchAll();
    foreach ($allTags as &$t) ai_cast($t, ['id', 'parent_id', 'sort_order']);
    unset($t);
    $st = $pdo->prepare("SELECT rt.tag_id AS id, g.name, g.color, g.active, rt.source, rt.confidence, rt.set_by, rt.created_at
                         FROM request_tags rt LEFT JOIN ai_tags g ON g.id = rt.tag_id
                         WHERE rt.request_id = ? ORDER BY g.sort_order, g.id");
    $st->execute([$rid]);
    $assigned = $st->fetchAll();
    foreach ($assigned as &$t) { ai_cast($t, ['id', 'active'], ['confidence']); if ($t['name'] === null) $t['name'] = '(삭제된 태그 #' . $t['id'] . ')'; }
    unset($t);

    // ---- 유사 과거 문의 (LLM 상위 rank 1~5 먼저, 그 뒤 TF-IDF 후보) ----
    $st = $pdo->prepare("SELECT s.similar_id, s.score_tfidf, s.score_llm, s.`rank`, s.why_similar, s.resolution, s.created_at,
                                r.title, r.status, r.`done`, r.asg, r.archived, r.board, r.created, r.cmt_count
                         FROM ai_similar s LEFT JOIN requests r ON r.id = s.similar_id
                         WHERE s.request_id = ?
                         ORDER BY (s.`rank` = 0), s.`rank`, s.score_llm DESC, s.score_tfidf DESC, s.id
                         LIMIT 25");
    $st->execute([$rid]);
    $similar = $st->fetchAll();
    foreach ($similar as &$s) {
        ai_cast($s, ['rank', 'archived', 'created', 'cmt_count'], ['score_tfidf', 'score_llm']);
        if ($s['title'] === null) $s['title'] = '(목록에 없는 항목 ' . $s['similar_id'] . ')';
    }
    unset($s);

    // ---- 레포 ----
    $repos = $pdo->query("SELECT id, name, school_id, vcs, local_path, default_branch, version, active,
                                 check_status, check_message, head_revision, last_checked
                          FROM ai_repos WHERE active = 1 ORDER BY name")->fetchAll();
    foreach ($repos as &$r) ai_cast($r, ['id', 'school_id', 'active']);
    unset($r);
    $resolved = null;
    if ($tri && $tri['repo_id']) {
        foreach ($repos as $r) if ($r['id'] === $tri['repo_id']) { $resolved = $r; break; }
        if (!$resolved) {   // 비활성 레포가 매핑돼 있으면 그래도 보여준다
            $st = $pdo->prepare("SELECT id, name, school_id, vcs, local_path, default_branch, version, active,
                                        check_status, check_message, head_revision, last_checked FROM ai_repos WHERE id = ?");
            $st->execute([$tri['repo_id']]);
            $resolved = $st->fetch() ?: null;
            if ($resolved) ai_cast($resolved, ['id', 'school_id', 'active']);
        }
    }
    $byId = [];
    foreach ($repos as $r) $byId[$r['id']] = $r['name'];
    $candidates = [];
    foreach (($tri ? $tri['repo_candidates'] : []) as $c) {
        if (!is_array($c)) continue;
        $cid = (int)($c['id'] ?? $c['repo_id'] ?? 0);
        if ($cid <= 0) continue;
        $candidates[] = ['id' => $cid, 'name' => $byId[$cid] ?? ('#' . $cid), 'score' => isset($c['score']) ? (float)$c['score'] : null,
                         'reason' => (string)($c['reason'] ?? '')];
    }

    // ---- 플랜 (버전 목록 + 선택/최신 버전 전체) ----
    $st = $pdo->prepare("SELECT id, version, status, title, cost_usd, est_minutes, budget_usd, created_at, created_by, approved_by, rejected_by, budget_hit
                         FROM ai_plans WHERE request_id = ? ORDER BY version DESC");
    $st->execute([$rid]);
    $versions = $st->fetchAll();
    foreach ($versions as &$v) ai_cast($v, ['id', 'version', 'est_minutes', 'budget_hit'], ['cost_usd', 'budget_usd']);
    unset($v);
    $plan = null;
    if ($versions) {
        $pick = $versions[0]['id'];
        if ($planVersion !== null) foreach ($versions as $v) if ($v['version'] === (int)$planVersion) { $pick = $v['id']; break; }
        $st = $pdo->prepare("SELECT * FROM ai_plans WHERE id = ?");
        $st->execute([$pick]);
        $plan = $st->fetch() ?: null;
        if ($plan) {
            ai_cast($plan, ['id', 'version', 'repo_id', 'est_minutes', 'budget_hit', 'num_turns', 'duration_ms', 'job_id'], ['budget_usd', 'cost_usd']);
            $plan['plan_json']    = ai_jdec($plan['plan_json'], null);
            $plan['metrics_json'] = ai_jdec($plan['metrics_json'], null);
            $plan['versions']     = $versions;
            $plan['is_latest']    = ($plan['id'] === $versions[0]['id']);
            $plan['repo_name']    = $plan['repo_id'] ? ($byId[$plan['repo_id']] ?? null) : null;
        }
    }

    // ---- 실행 (그 플랜의 최신) ----
    $exec = null;
    if ($plan) {
        $st = $pdo->prepare("SELECT * FROM ai_executions WHERE plan_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$plan['id']]);
        $exec = $st->fetch() ?: null;
        if ($exec) {
            ai_cast($exec, ['id', 'plan_id', 'resumed', 'files_changed', 'lines_added', 'lines_deleted', 'budget_hit', 'job_id'], ['cost_usd']);
            $diff = (string)($exec['diff'] ?? '');
            $len  = strlen($diff);
            $exec['diff_len'] = $len;
            if ($len > AI_DIFF_PREVIEW_BYTES) {
                $cut = substr($diff, 0, AI_DIFF_PREVIEW_BYTES);
                $nl  = strrpos($cut, "\n");                 // 줄 중간에서 끊지 않는다(멀티바이트 깨짐 방지)
                if ($nl !== false && $nl > AI_DIFF_PREVIEW_BYTES * 0.8) $cut = substr($cut, 0, $nl + 1);
                $exec['diff'] = $cut;
                $exec['diff_truncated'] = true;
            } else {
                $exec['diff_truncated'] = false;
            }
            $exec['diff_stat']        = ai_jdec($exec['diff_stat'], []);
            $exec['preexisting_json'] = ai_jdec($exec['preexisting_json'], []);
            $exec['lint']             = ['ok' => $exec['lint_ok'] === null ? null : (bool)(int)$exec['lint_ok'], 'output' => $exec['lint_output']];
            unset($exec['lint_ok'], $exec['lint_output']);
        }
    }

    // ---- 커밋 (그 실행의 최신) ----
    $commit = null;
    if ($exec) {
        $st = $pdo->prepare("SELECT * FROM ai_commits WHERE execution_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$exec['id']]);
        $commit = $st->fetch() ?: null;
        if ($commit) ai_cast($commit, ['id', 'execution_id', 'repo_id', 'job_id']);
    }

    // ---- 검토 (그 커밋의 최신) ----
    $review = null;
    if ($commit) {
        $st = $pdo->prepare("SELECT * FROM ai_reviews WHERE commit_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$commit['id']]);
        $review = $st->fetch() ?: null;
        if ($review) {
            ai_cast($review, ['id', 'commit_id', 'job_id'], ['cost_usd']);
            $review['addresses_inquiry'] = $review['addresses_inquiry'] === null ? null : (bool)(int)$review['addresses_inquiry'];
            $review['findings_json']     = ai_jdec($review['findings_json'], []);
        }
    }

    // ---- 학습 노트 (이 요청에서 나온 것; 제안 먼저) ----
    $st = $pdo->prepare("SELECT l.*, g.name AS tag_name FROM ai_lessons l LEFT JOIN ai_tags g ON g.id = l.tag_id
                         WHERE l.request_id = ? ORDER BY (l.status = 'proposed') DESC, l.id DESC LIMIT 50");
    $st->execute([$rid]);
    $lessons = $st->fetchAll();
    foreach ($lessons as &$l) {
        ai_cast($l, ['id', 'plan_id', 'commit_id', 'repo_id', 'tag_id', 'evidence_count', 'used_count', 'job_id'], ['weight']);
        $l['repo_name'] = $l['repo_id'] ? ($byId[$l['repo_id']] ?? null) : null;
    }
    unset($l);

    // ---- 잡 / 이벤트 ----
    $job = ai_current_job($rid);
    if ($job) {
        ai_cast($job, ['id', 'cancel_requested', 'attempts']);
        $job['hb_age']      = ai_age_sec($job['heartbeat_at']);
        $job['queued_age']  = ai_age_sec($job['created_at']);
        $job['params']      = null;
        $st = $pdo->prepare("SELECT params FROM ai_jobs WHERE id = ?");
        $st->execute([$job['id']]);
        $job['params'] = ai_jdec($st->fetchColumn(), null);
    }
    $jobs = ai_recent_jobs($rid, 20);
    $costTotal = 0.0;
    foreach ($jobs as &$j) {
        ai_cast($j, ['id', 'tokens_in', 'tokens_out', 'cancel_requested', 'attempts'], ['cost_usd']);
        if ($j['cost_usd']) $costTotal += $j['cost_usd'];
    }
    unset($j);
    $st = $pdo->prepare("SELECT id, actor, action, ref_table, ref_id, detail, created_at FROM ai_events WHERE request_id = ? ORDER BY id DESC LIMIT 20");
    $st->execute([$rid]);
    $events = $st->fetchAll();
    foreach ($events as &$e) { ai_cast($e, ['id', 'ref_id']); $e['detail'] = ai_jdec($e['detail'], null); }
    unset($e);

    return [
        'request_id'  => $rid,
        'request'     => $req,
        'triage'      => $tri,
        'tags'        => ['assigned' => $assigned, 'all' => $allTags],
        'similar'     => $similar,
        'repo'        => ['resolved' => $resolved, 'candidates' => $candidates, 'all' => $repos,
                          'reason' => $tri['repo_reason'] ?? null, 'confidence' => $tri['repo_confidence'] ?? null],
        'plan'        => $plan,
        'execution'   => $exec,
        'commit'      => $commit,
        'review'      => $review,
        'lessons'     => $lessons,
        'job'         => $job,
        'jobs'        => $jobs,
        'cost_total'  => round($costTotal, 4),
        'events'      => $events,
        'me'          => $me['email'],
        'can_approve' => (bool)$me['can_approve'],
        'is_admin'    => (bool)$me['is_admin'],
        'settings'    => [
            'budget_execute_usd'  => (float)ai_setting('budget_execute_usd', 10),
            'heartbeat_stale_sec' => (int)ai_setting('heartbeat_stale_sec', 120),
            'post_to_slack'       => ai_setting('post_to_slack', '0') === '1',
        ],
        'now'         => time(),
    ];
}

/* ===================== HTTP ===================== */
if (!defined('AI_PANEL_LIB')) {
    require_login();
    session_release();
    $rid = trim((string)($_GET['id'] ?? ''));
    if ($rid === '' || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $rid)) ai_fail('bad_request_id', 'id 가 필요합니다.', 400);
    $pv = isset($_GET['plan_version']) && $_GET['plan_version'] !== '' ? (int)$_GET['plan_version'] : null;
    try {
        $state = ai_build_state($rid, ai_me(), $pv);
    } catch (Throwable $e) {
        ai_fail('server_error', $e->getMessage(), 500);
    }
    if ($state === null) ai_fail('request_not_found', '요청을 찾을 수 없습니다: ' . $rid, 404);
    ai_json(['ok' => true] + $state);
}
