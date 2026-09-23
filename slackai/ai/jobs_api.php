<?php
/**
 * AI 작업 큐(ai_jobs) 조회·취소·재시도 API — ai/jobs.php 화면이 쓴다.
 *  GET ?status=&kind=&request_id=&q=&from=YYYY-MM-DD&to=YYYY-MM-DD&limit=100&offset=0
 *      → {ok, rows:[{…ai_jobs, title}], total,
 *          totals:{count, cost_usd, running, queued, open, failed_today, today_cost_usd, week_cost_usd,
 *                  by_day:[{day,count,cost_usd}] (최근 14일), by_kind:[{kind,count,cost_usd}]}}
 *        count/cost_usd 는 필터 적용, 나머지 totals 는 전체 기준.
 *  POST (X-Requested-With: fetch)
 *    cancel {id}  queued → cancelled(open_key NULL) / running → cancel_requested=1 (워커가 kill 후 cancelled 기록)   로그인 사용자
 *    retry  {id}  failed|cancelled 잡을 kind/request_id/ref_id/repo_id/params 그대로 새 잡으로 등록                승인자/관리자
 *  CLI 에서는 jobs_api_handle() 만 정의한다(_smoke_admin.php).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/ai_lib.php';
require_once __DIR__ . '/admin_lib.php';

const JOBS_API_KINDS    = ['ingest', 'triage', 'plan', 'execute', 'commit', 'review', 'learn', 'distill', 'check_repo', 'revert', 'resync'];
const JOBS_API_STATUSES = ['queued', 'running', 'done', 'failed', 'cancelled'];

function jobs_api_row(array $r) {
    foreach (['id', 'ref_id', 'repo_id', 'attempts', 'max_attempts', 'cancel_requested', 'tokens_in', 'tokens_out', 'priority'] as $k) {
        if (array_key_exists($k, $r)) $r[$k] = $r[$k] === null ? null : (int)$r[$k];
    }
    $r['cost_usd'] = $r['cost_usd'] === null ? null : (float)$r['cost_usd'];
    $r['title']    = $r['title'] ?? null;
    // 소요 시간(초): started~finished, running 이면 started~now
    $dur = null;
    if (!empty($r['started_at'])) {
        $end = !empty($r['finished_at']) ? strtotime($r['finished_at']) : time();
        $dur = max(0, $end - strtotime($r['started_at']));
    }
    $r['duration_sec'] = $dur;
    return $r;
}

/** 필터 → [whereSql, params] */
function jobs_api_where(array $get) {
    $w = []; $p = [];
    $status = admin_str($get['status'] ?? '');
    if ($status !== '' && $status !== 'all') {
        $list = array_values(array_intersect(admin_list($status), JOBS_API_STATUSES));
        if ($list) { $w[] = 'j.status IN (' . implode(',', array_fill(0, count($list), '?')) . ')'; array_push($p, ...$list); }
    }
    $kind = admin_str($get['kind'] ?? '');
    if ($kind !== '' && $kind !== 'all') {
        $list = array_values(array_intersect(admin_list($kind), JOBS_API_KINDS));
        if ($list) { $w[] = 'j.kind IN (' . implode(',', array_fill(0, count($list), '?')) . ')'; array_push($p, ...$list); }
    }
    $rid = admin_str($get['request_id'] ?? '', 32);
    if ($rid !== '') { $w[] = 'j.request_id = ?'; $p[] = $rid; }
    $q = admin_str($get['q'] ?? '', 100);
    if ($q !== '') { $w[] = '(j.request_id LIKE ? OR r.title LIKE ? OR j.error LIKE ?)'; $like = '%' . $q . '%'; array_push($p, $like, $like, $like); }
    $from = admin_str($get['from'] ?? '');
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $w[] = 'j.created_at >= ?'; $p[] = $from . ' 00:00:00'; }
    $to = admin_str($get['to'] ?? '');
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $w[] = 'j.created_at <= ?'; $p[] = $to . ' 23:59:59'; }
    $repo = admin_id($get['repo_id'] ?? 0);
    if ($repo > 0) { $w[] = 'j.repo_id = ?'; $p[] = $repo; }
    return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $p];
}

/** 전체 기준 집계 */
function jobs_api_totals(PDO $pdo) {
    $t = [];
    $st = $pdo->query("SELECT
            SUM(status='running') AS running, SUM(status='queued') AS queued,
            SUM(status='failed' AND finished_at >= CURDATE()) AS failed_today,
            COALESCE(SUM(CASE WHEN finished_at >= CURDATE() THEN cost_usd END), 0) AS today_cost_usd,
            COALESCE(SUM(CASE WHEN finished_at >= CURDATE() - INTERVAL 6 DAY THEN cost_usd END), 0) AS week_cost_usd
        FROM ai_jobs")->fetch();
    $t['running']        = (int)($st['running'] ?? 0);
    $t['queued']         = (int)($st['queued'] ?? 0);
    $t['open']           = $t['running'] + $t['queued'];
    $t['failed_today']   = (int)($st['failed_today'] ?? 0);
    $t['today_cost_usd'] = round((float)($st['today_cost_usd'] ?? 0), 4);
    $t['week_cost_usd']  = round((float)($st['week_cost_usd'] ?? 0), 4);

    // 최근 14일: 비어 있는 날도 0 으로 채운다 (일자 기준은 finished_at, 없으면 created_at)
    $byDay = [];
    for ($i = 13; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-{$i} day")); $byDay[$d] = ['day' => $d, 'count' => 0, 'cost_usd' => 0.0]; }
    $q = $pdo->query("SELECT DATE(COALESCE(finished_at, created_at)) AS day, COUNT(*) AS cnt, COALESCE(SUM(cost_usd), 0) AS cost
                      FROM ai_jobs WHERE COALESCE(finished_at, created_at) >= CURDATE() - INTERVAL 13 DAY GROUP BY day");
    foreach ($q as $r) {
        if (isset($byDay[$r['day']])) { $byDay[$r['day']]['count'] = (int)$r['cnt']; $byDay[$r['day']]['cost_usd'] = round((float)$r['cost'], 4); }
    }
    $t['by_day'] = array_values($byDay);

    $byKind = [];
    foreach ($pdo->query("SELECT kind, COUNT(*) AS cnt, COALESCE(SUM(cost_usd), 0) AS cost FROM ai_jobs GROUP BY kind ORDER BY cnt DESC") as $r) {
        $byKind[] = ['kind' => $r['kind'], 'count' => (int)$r['cnt'], 'cost_usd' => round((float)$r['cost'], 4)];
    }
    $t['by_kind'] = $byKind;
    return $t;
}

function jobs_api_handle($method, array $get, array $in, array $me) {
    $pdo   = db();
    $actor = (string)($me['email'] ?? 'unknown');

    if ($method === 'GET') {
        [$where, $params] = jobs_api_where($get);
        $limit  = max(1, min(500, (int)($get['limit'] ?? 100)));
        $offset = max(0, (int)($get['offset'] ?? 0));
        $base = "FROM ai_jobs j LEFT JOIN requests r ON r.id = j.request_id" . $where;

        $st = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(j.cost_usd), 0) AS cost " . $base);
        $st->execute($params);
        $agg = $st->fetch();

        $st = $pdo->prepare("SELECT j.id, j.kind, j.request_id, j.ref_id, j.repo_id, j.status, j.priority, j.attempts, j.max_attempts, j.run_after,
                                    j.cancel_requested, j.worker, j.progress, j.heartbeat_at, j.started_at, j.finished_at, j.error, j.model,
                                    j.tokens_in, j.tokens_out, j.cost_usd, j.requested_by, j.created_at, j.params, r.title
                             " . $base . " ORDER BY j.id DESC LIMIT {$limit} OFFSET {$offset}");
        $st->execute($params);
        $rows = array_map('jobs_api_row', $st->fetchAll());

        $totals = jobs_api_totals($pdo);
        $totals['count']    = (int)($agg['cnt'] ?? 0);
        $totals['cost_usd'] = round((float)($agg['cost'] ?? 0), 4);
        return ['rows' => $rows, 'total' => $totals['count'], 'limit' => $limit, 'offset' => $offset, 'totals' => $totals,
                'kinds' => JOBS_API_KINDS, 'statuses' => JOBS_API_STATUSES,
                'can_retry' => !empty($me['is_approver']), 'is_admin' => !empty($me['is_admin'])];
    }

    $action = admin_str($in['action'] ?? '');
    $id = admin_id($in['id'] ?? 0);
    if ($id <= 0) admin_abort('bad_request', '잘못된 id 입니다.', 400);
    $st = $pdo->prepare("SELECT * FROM ai_jobs WHERE id = ?");
    $st->execute([$id]);
    $job = $st->fetch();
    if (!$job) admin_abort('not_found', '잡이 없습니다.', 404);

    if ($action === 'cancel') {
        if ($job['status'] === 'queued') {
            $u = $pdo->prepare("UPDATE ai_jobs SET status='cancelled', open_key=NULL, finished_at=NOW(), error=COALESCE(error, ?) WHERE id=? AND status='queued'");
            $u->execute(['cancelled by ' . $actor, $id]);
            if ($u->rowCount() === 0) admin_abort('conflict', '잡 상태가 바뀌었습니다. 새로고침하세요.', 409);
            $result = 'cancelled';
        } elseif ($job['status'] === 'running') {
            $pdo->prepare("UPDATE ai_jobs SET cancel_requested=1 WHERE id=?")->execute([$id]);
            $result = 'cancel_requested';
        } else {
            admin_abort('not_open', "이미 끝난 잡입니다({$job['status']}).", 409);
        }
        ai_event($job['request_id'], $actor, 'job.cancel', 'ai_jobs', $id, ['kind' => $job['kind'], 'was' => $job['status'], 'result' => $result]);
        ai_mark_changed();
        return ['id' => $id, 'result' => $result];
    }

    if ($action === 'retry') {
        admin_require($me, 'approver');
        if (!in_array($job['status'], ['failed', 'cancelled'], true)) {
            admin_abort('not_retryable', "실패/취소된 잡만 재시도할 수 있습니다({$job['status']}).", 409);
        }
        $params = json_decode((string)$job['params'], true);
        if (!is_array($params)) $params = [];
        $params['retry_of'] = $id;
        $newId = ai_enqueue($job['kind'], $job['request_id'], $params, $actor,
                            $job['ref_id'] === null ? null : (int)$job['ref_id'],
                            $job['repo_id'] === null ? null : (int)$job['repo_id']);
        if ($newId === null) admin_abort('duplicate', '같은 잡이 이미 대기/실행 중입니다.', 409);
        ai_event($job['request_id'], $actor, 'job.retry', 'ai_jobs', $newId, ['kind' => $job['kind'], 'retry_of' => $id]);
        return ['id' => $id, 'new_id' => $newId];
    }

    admin_abort('bad_request', '알 수 없는 action 입니다.', 400);
}

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../auth.php';
    require_login();
    session_release();
    admin_run('jobs_api_handle');
}
