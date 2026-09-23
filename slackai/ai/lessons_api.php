<?php
/**
 * 학습 노트(ai_lessons) API — ai/lessons.php 화면이 쓴다. 승인된 노트만 워커가 다음 플랜 프롬프트에 주입한다.
 *  GET ?status=proposed|approved|rejected|all (기본 proposed) &kind=&repo_id=&q=&limit=200
 *      → {ok, rows:[{…ai_lessons, title(request), request_title, repo_name, tag_name}],
 *          counts:{proposed,approved,rejected,all}, kinds:[{kind,count}], repos:[{id,name}]}
 *  POST (X-Requested-With: fetch)
 *    approve {id}                              승인자
 *    reject  {id}                              승인자
 *    update  {id, title?, lesson_md?, weight?} 승인자
 *    delete  {id}                              관리자
 *  CLI 에서는 lessons_api_handle() 만 정의한다(_smoke_admin.php).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/ai_lib.php';
require_once __DIR__ . '/admin_lib.php';

const LESSONS_API_SELECT = "SELECT l.*, r.title AS request_title, p.name AS repo_name, t.name AS tag_name
    FROM ai_lessons l
    LEFT JOIN requests r ON r.id = l.request_id
    LEFT JOIN ai_repos p ON p.id = l.repo_id
    LEFT JOIN ai_tags  t ON t.id = l.tag_id";

function lessons_api_row(array $r) {
    foreach (['id', 'plan_id', 'commit_id', 'repo_id', 'tag_id', 'evidence_count', 'used_count', 'job_id'] as $k) {
        if (array_key_exists($k, $r)) $r[$k] = $r[$k] === null ? null : (int)$r[$k];
    }
    $r['weight'] = (float)$r['weight'];
    return $r;
}

function lessons_api_get(PDO $pdo, $id) {
    $st = $pdo->prepare(LESSONS_API_SELECT . " WHERE l.id = ?");
    $st->execute([(int)$id]);
    $r = $st->fetch();
    return $r ? lessons_api_row($r) : null;
}

function lessons_api_handle($method, array $get, array $in, array $me) {
    $pdo   = db();
    $actor = (string)($me['email'] ?? 'unknown');

    if ($method === 'GET') {
        $w = []; $p = [];
        $status = admin_str($get['status'] ?? 'proposed');
        if ($status === '') $status = 'proposed';
        if ($status !== 'all') {
            if (!in_array($status, ['proposed', 'approved', 'rejected'], true)) admin_abort('bad_request', 'status 가 잘못되었습니다.', 400);
            $w[] = 'l.status = ?'; $p[] = $status;
        }
        $kind = admin_str($get['kind'] ?? '', 20);
        if ($kind !== '' && $kind !== 'all') { $w[] = 'l.kind = ?'; $p[] = $kind; }
        $repo = admin_id($get['repo_id'] ?? 0);
        if ($repo > 0) { $w[] = 'l.repo_id = ?'; $p[] = $repo; }
        $scope = admin_str($get['scope'] ?? '', 8);
        if ($scope !== '' && in_array($scope, ['repo', 'tag', 'global'], true)) { $w[] = 'l.scope = ?'; $p[] = $scope; }
        $q = admin_str($get['q'] ?? '', 100);
        if ($q !== '') {
            $w[] = '(l.title LIKE ? OR l.lesson_md LIKE ? OR l.request_id LIKE ? OR r.title LIKE ?)';
            $like = '%' . $q . '%'; array_push($p, $like, $like, $like, $like);
        }
        $limit = max(1, min(500, (int)($get['limit'] ?? 200)));
        $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
        $st = $pdo->prepare(LESSONS_API_SELECT . $where . " ORDER BY l.status = 'proposed' DESC, l.weight DESC, l.id DESC LIMIT {$limit}");
        $st->execute($p);
        $rows = array_map('lessons_api_row', $st->fetchAll());

        $counts = ['proposed' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
        foreach ($pdo->query("SELECT status, COUNT(*) AS c FROM ai_lessons GROUP BY status") as $r) {
            if (isset($counts[$r['status']])) $counts[$r['status']] = (int)$r['c'];
            $counts['all'] += (int)$r['c'];
        }
        $kinds = [];
        foreach ($pdo->query("SELECT kind, COUNT(*) AS c FROM ai_lessons GROUP BY kind ORDER BY c DESC, kind") as $r) $kinds[] = ['kind' => $r['kind'], 'count' => (int)$r['c']];
        $repos = [];
        foreach ($pdo->query("SELECT id, name FROM ai_repos ORDER BY active DESC, name") as $r) $repos[] = ['id' => (int)$r['id'], 'name' => $r['name']];
        return ['rows' => $rows, 'counts' => $counts, 'kinds' => $kinds, 'repos' => $repos,
                'can_approve' => !empty($me['is_approver']), 'is_admin' => !empty($me['is_admin'])];
    }

    $action = admin_str($in['action'] ?? '');
    $id = admin_id($in['id'] ?? 0);
    if ($id <= 0) admin_abort('bad_request', '잘못된 id 입니다.', 400);
    $l = lessons_api_get($pdo, $id);
    if (!$l) admin_abort('not_found', '학습 노트가 없습니다.', 404);

    if ($action === 'approve' || $action === 'reject') {
        admin_require($me, 'approver');
        $to = $action === 'approve' ? 'approved' : 'rejected';
        if ($l['status'] === $to) admin_abort('conflict', "이미 {$to} 상태입니다.", 409);
        $pdo->prepare("UPDATE ai_lessons SET status=?, approved_by=?, approved_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$to, $actor, $id]);
        ai_event($l['request_id'], $actor, 'lesson.' . $action, 'ai_lessons', $id, ['was' => $l['status'], 'kind' => $l['kind'], 'title' => $l['title']]);
        ai_mark_changed();
        return ['id' => $id, 'status' => $to, 'row' => lessons_api_get($pdo, $id)];
    }

    if ($action === 'update') {
        admin_require($me, 'approver');
        $title  = array_key_exists('title', $in) ? admin_str($in['title'], 300) : $l['title'];
        if ($title === '') admin_abort('bad_request', '제목을 입력하세요.', 400);
        $lesson = array_key_exists('lesson_md', $in) ? trim((string)$in['lesson_md']) : (string)$l['lesson_md'];
        $weight = $l['weight'];
        if (array_key_exists('weight', $in) && $in['weight'] !== '' && $in['weight'] !== null) {
            if (!is_numeric($in['weight'])) admin_abort('bad_request', 'weight 는 0~1 숫자여야 합니다.', 400);
            $weight = (float)$in['weight'];
            if ($weight < 0 || $weight > 1) admin_abort('bad_request', 'weight 는 0~1 사이여야 합니다.', 400);
        }
        $scope = $l['scope'];
        if (array_key_exists('scope', $in) && in_array($in['scope'], ['repo', 'tag', 'global'], true)) $scope = $in['scope'];
        $pdo->prepare("UPDATE ai_lessons SET title=?, lesson_md=?, weight=?, scope=?, updated_at=NOW() WHERE id=?")
            ->execute([$title, $lesson, number_format($weight, 3, '.', ''), $scope, $id]);
        $diff = [];
        if ($title !== $l['title']) $diff['title'] = ['from' => $l['title'], 'to' => $title];
        if ($lesson !== (string)$l['lesson_md']) $diff['lesson_md'] = ['from' => mb_substr((string)$l['lesson_md'], 0, 500), 'to' => mb_substr($lesson, 0, 500)];
        if (abs($weight - $l['weight']) > 0.0005) $diff['weight'] = ['from' => $l['weight'], 'to' => $weight];
        if ($scope !== $l['scope']) $diff['scope'] = ['from' => $l['scope'], 'to' => $scope];
        if ($diff) ai_event($l['request_id'], $actor, 'lesson.update', 'ai_lessons', $id, $diff);
        ai_mark_changed();
        return ['row' => lessons_api_get($pdo, $id)];
    }

    if ($action === 'delete') {
        admin_require($me, 'admin');
        $pdo->prepare("DELETE FROM ai_lessons WHERE id=?")->execute([$id]);
        ai_event($l['request_id'], $actor, 'lesson.delete', 'ai_lessons', $id, ['kind' => $l['kind'], 'title' => $l['title'], 'status' => $l['status']]);
        ai_mark_changed();
        return ['id' => $id];
    }

    admin_abort('bad_request', '알 수 없는 action 입니다.', 400);
}

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../auth.php';
    require_login();
    session_release();
    admin_run('lessons_api_handle');
}
