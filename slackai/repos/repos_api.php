<?php
/**
 * 레포(ai_repos: 고객사 → 로컬 작업 사본) 관리 API — repos/repos.php 화면과 AI 패널의 레포 선택기가 쓴다.
 *  GET  [?all=1]      → {ok, rows:[{…ai_repos, school_name, match_rules:{lms_patterns:[],title_keywords:[]}, ref_count}]}
 *                       (all 없으면 active=1 만)
 *  POST {action, ...} (X-Requested-With: fetch 필수)
 *    create  {name, school_id?, vcs, remote_url?, local_path, default_branch?, version?, php_bin?, match_rules?{lms_patterns[],title_keywords[]}, notes?, active?}  승인자
 *    update  {id, …}                                                                                                                                      승인자
 *    toggle  {id, active?}                                                                                                                                승인자
 *    check   {id}   → check_repo 잡 등록(ai_enqueue), check_status='unchecked'. 실제 경로 검증은 워커가 한다(PHP 는 디스크를 보지 않음)                 승인자
 *    discover {}    → discover_repos 잡: 워커가 로컬 작업사본 URL 을 school_access.repo 와 맞춰 ai_repos 자동 등록/갱신            승인자
 *  GET ?discover_status=1 → 최근 discover_repos 잡 1건(status/progress/result 요약)
 *  GET ?school_repo=<school_id> → school_access.repo 에서 뽑은 svn/git 주소 목록(폼 채우기용 · repo 컬럼만 읽음)
 *    delete  {id}   → ai_plans/ai_triage 가 참조하면 409 in_use(→ 미사용 전환 유도)                                                                      관리자
 *  CLI 에서는 repos_api_handle() 만 정의한다(_smoke_admin.php).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ai/ai_lib.php';
require_once __DIR__ . '/../ai/admin_lib.php';

const REPOS_API_SELECT = "SELECT r.*, s.name AS school_name,
        (SELECT COUNT(*) FROM ai_plans p WHERE p.repo_id = r.id) + (SELECT COUNT(*) FROM ai_triage t WHERE t.repo_id = r.id) AS ref_count
    FROM ai_repos r LEFT JOIN schools s ON s.id = r.school_id";

/** school_access.repo 자유 텍스트 → svn/git 주소 목록 (워커 jobs/discover_repos.py::extract_urls 와 같은 규칙) */
function repos_api_extract_urls($text) {
    preg_match_all('~(svn(?:\+ssh)?://[^\s\'"<>]+|https?://[^\s\'"<>]+?\.git\b|https?://[^\s\'"<>]+/(?:ub)?git/[^\s\'"<>]+|ssh://[^\s\'"<>]+?\.git\b|git@[^\s\'"<>]+?\.git\b)~i', (string)$text, $m);
    $out = [];
    foreach ($m[0] as $u) { $u = rtrim(trim($u), '/.,;)'); if ($u !== '' && !in_array($u, $out, true)) $out[] = $u; }
    return $out;
}

/** 포털 DB(school_access 가 있는 곳) 이름 — config.php db.name */
function repos_api_portal_db() {
    $cfg = require __DIR__ . '/../../config.php';
    $n = (string)($cfg['db']['name'] ?? 'slack_db');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $n)) admin_abort('server_error', '포털 DB 이름이 올바르지 않습니다.', 500);
    return $n;
}

/** match_rules JSON → 정규화된 배열 */
function repos_api_rules($v) {
    if (is_string($v)) { $d = json_decode($v, true); $v = is_array($d) ? $d : []; }
    if (!is_array($v)) $v = [];
    return [
        'lms_patterns'   => admin_list($v['lms_patterns'] ?? [], '/[\n\r,]+/u'),
        'title_keywords' => admin_list($v['title_keywords'] ?? [], '/[\n\r,]+/u'),
    ];
}

function repos_api_row(array $r) {
    $r['id']          = (int)$r['id'];
    $r['school_id']   = $r['school_id'] === null ? null : (int)$r['school_id'];
    $r['active']      = (int)$r['active'];
    $r['ref_count']   = (int)($r['ref_count'] ?? 0);
    $r['match_rules'] = repos_api_rules($r['match_rules'] ?? null);
    $r['check_status']= $r['check_status'] ?: 'unchecked';
    return $r;
}

function repos_api_get(PDO $pdo, $id) {
    $st = $pdo->prepare(REPOS_API_SELECT . " WHERE r.id = ?");
    $st->execute([(int)$id]);
    $r = $st->fetch();
    return $r ? repos_api_row($r) : null;
}

function repos_api_list(PDO $pdo, $all) {
    $where = $all ? '' : ' WHERE r.active = 1';
    $rows = $pdo->query(REPOS_API_SELECT . $where . " ORDER BY r.active DESC, r.name, r.id")->fetchAll();
    return array_map('repos_api_row', $rows);
}

/** 입력 → 저장할 컬럼 배열 (create/update 공용). $old 가 있으면 빠진 키는 기존 값 유지 */
function repos_api_fields(PDO $pdo, array $in, ?array $old) {
    $pick = function ($k, $default = null) use ($in, $old) {
        if (array_key_exists($k, $in)) return $in[$k];
        return $old !== null ? ($old[$k] ?? $default) : $default;
    };
    $name = admin_str($pick('name', ''), 120);
    if ($name === '') admin_abort('bad_request', '레포 이름을 입력하세요.', 400);
    $local = admin_str($pick('local_path', ''), 500);
    if ($local === '') admin_abort('bad_request', '로컬 작업 사본 경로(local_path)를 입력하세요.', 400);
    $vcs = strtolower(admin_str($pick('vcs', 'svn'), 5));
    if (!in_array($vcs, ['svn', 'git'], true)) admin_abort('bad_request', 'vcs 는 svn 또는 git 이어야 합니다.', 400);

    $schoolId = admin_int_or_null($pick('school_id'));
    if ($schoolId !== null && $schoolId > 0) {
        $st = $pdo->prepare("SELECT id FROM schools WHERE id = ?");
        $st->execute([$schoolId]);
        if (!$st->fetchColumn()) admin_abort('bad_request', '학교(school_id)가 없습니다.', 400);
    } else {
        $schoolId = null;
    }
    $rules = repos_api_rules(array_key_exists('match_rules', $in) ? $in['match_rules'] : ($old['match_rules'] ?? []));
    $dup = $pdo->prepare("SELECT id FROM ai_repos WHERE name = ? AND id <> ? LIMIT 1");
    $dup->execute([$name, (int)($old['id'] ?? 0)]);
    if ($dup->fetchColumn()) admin_abort('dup_name', "이미 같은 이름의 레포가 있습니다: {$name}", 400);

    return [
        'name'           => $name,
        'school_id'      => $schoolId,
        'vcs'            => $vcs,
        'remote_url'     => admin_str($pick('remote_url', ''), 500) ?: null,
        'local_path'     => $local,
        'default_branch' => admin_str($pick('default_branch', ''), 80) ?: null,
        'version'        => admin_str($pick('version', ''), 20) ?: null,
        'php_bin'        => admin_str($pick('php_bin', ''), 300) ?: null,
        'match_rules'    => json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'notes'          => admin_str($pick('notes', ''), 20000) ?: null,
        'active'         => admin_bool($pick('active', 1), 1),
    ];
}

function repos_api_handle($method, array $get, array $in, array $me) {
    $pdo   = db();
    $actor = (string)($me['email'] ?? 'unknown');

    if ($method === 'GET') {
        if (!empty($get['discover_status'])) {
            $j = $pdo->query("SELECT id, status, progress, error, result, requested_by, created_at, started_at, finished_at
                              FROM ai_jobs WHERE kind = 'discover_repos' ORDER BY id DESC LIMIT 1")->fetch();
            if ($j) {
                $r = json_decode((string)$j['result'], true) ?: [];
                $j['result'] = array_intersect_key($r, array_flip(['scanned', 'matched', 'created', 'updated', 'schools_without_wc']))
                             + ['unmatched' => array_slice(array_column($r['unmatched'] ?? [], 'path'), 0, 50)];
                $j['id'] = (int)$j['id'];
            }
            return ['job' => $j ?: null];
        }
        if (!empty($get['school_repo'])) {
            // 계정·비번 컬럼은 읽지 않는다 — repo 칸만
            $st = $pdo->prepare("SELECT repo FROM `" . repos_api_portal_db() . "`.school_access WHERE school_id = ?");
            $st->execute([admin_id($get['school_repo'])]);
            $urls = [];
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) foreach (repos_api_extract_urls($t) as $u) if (!in_array($u, $urls, true)) $urls[] = $u;
            return ['urls' => $urls];
        }
        if (!empty($get['id'])) {
            $r = repos_api_get($pdo, admin_id($get['id']));
            if (!$r) admin_abort('not_found', '레포가 없습니다.', 404);
            return ['row' => $r];
        }
        return ['rows' => repos_api_list($pdo, !empty($get['all'])), 'can_write' => !empty($me['is_approver']), 'is_admin' => !empty($me['is_admin'])];
    }

    $action = admin_str($in['action'] ?? '');

    if ($action === 'create') {
        admin_require($me, 'approver');
        $f = repos_api_fields($pdo, $in, null);
        $pdo->prepare("INSERT INTO ai_repos (name, school_id, vcs, remote_url, local_path, default_branch, version, php_bin, match_rules, notes, active,
                                             check_status, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unchecked', NOW())")
            ->execute([$f['name'], $f['school_id'], $f['vcs'], $f['remote_url'], $f['local_path'], $f['default_branch'], $f['version'],
                       $f['php_bin'], $f['match_rules'], $f['notes'], $f['active']]);
        $id = (int)$pdo->lastInsertId();
        ai_event(null, $actor, 'repo.create', 'ai_repos', $id, ['name' => $f['name'], 'vcs' => $f['vcs'], 'local_path' => $f['local_path']]);
        ai_mark_changed();
        return ['id' => $id, 'row' => repos_api_get($pdo, $id)];
    }

    if ($action === 'update') {
        admin_require($me, 'approver');
        $id  = admin_id($in['id'] ?? 0);
        $old = $id ? repos_api_get($pdo, $id) : null;
        if (!$old) admin_abort('not_found', '레포가 없습니다.', 404);
        $f = repos_api_fields($pdo, $in, $old);
        // 경로/VCS 가 바뀌면 이전 점검 결과는 무효
        $recheck = ($f['local_path'] !== $old['local_path']) || ($f['vcs'] !== $old['vcs']);
        $sql = "UPDATE ai_repos SET name=?, school_id=?, vcs=?, remote_url=?, local_path=?, default_branch=?, version=?, php_bin=?, match_rules=?, notes=?, active=?, updated_at=NOW()"
             . ($recheck ? ", check_status='unchecked', check_message=NULL, head_revision=NULL" : '') . " WHERE id=?";
        $pdo->prepare($sql)->execute([$f['name'], $f['school_id'], $f['vcs'], $f['remote_url'], $f['local_path'], $f['default_branch'], $f['version'],
                                      $f['php_bin'], $f['match_rules'], $f['notes'], $f['active'], $id]);
        $new  = repos_api_get($pdo, $id);
        $diff = [];
        foreach (['name', 'school_id', 'vcs', 'remote_url', 'local_path', 'default_branch', 'version', 'php_bin', 'notes', 'active'] as $k) {
            if (($old[$k] ?? null) !== ($new[$k] ?? null)) $diff[$k] = ['from' => $old[$k] ?? null, 'to' => $new[$k] ?? null];
        }
        if ($old['match_rules'] !== $new['match_rules']) $diff['match_rules'] = ['from' => $old['match_rules'], 'to' => $new['match_rules']];
        if ($diff) ai_event(null, $actor, 'repo.update', 'ai_repos', $id, $diff);
        ai_mark_changed();
        return ['row' => $new, 'recheck' => $recheck];
    }

    if ($action === 'toggle') {
        admin_require($me, 'approver');
        $id = admin_id($in['id'] ?? 0);
        $r  = $id ? repos_api_get($pdo, $id) : null;
        if (!$r) admin_abort('not_found', '레포가 없습니다.', 404);
        $to = array_key_exists('active', $in) ? admin_bool($in['active'], 1) : (int)!$r['active'];
        $pdo->prepare("UPDATE ai_repos SET active=?, updated_at=NOW() WHERE id=?")->execute([$to, $id]);
        ai_event(null, $actor, 'repo.toggle', 'ai_repos', $id, ['active' => $to]);
        ai_mark_changed();
        return ['id' => $id, 'active' => $to];
    }

    if ($action === 'check') {
        admin_require($me, 'approver');
        $id = admin_id($in['id'] ?? 0);
        $r  = $id ? repos_api_get($pdo, $id) : null;
        if (!$r) admin_abort('not_found', '레포가 없습니다.', 404);
        // 워커가 local_path 존재·vcs 종류·HEAD 리비전을 확인해 last_checked/check_status/check_message/head_revision 을 기록한다.
        $jobId = ai_enqueue('check_repo', null, ['repo_id' => $id], $actor, $id, $id);
        $pdo->prepare("UPDATE ai_repos SET check_status='unchecked', updated_at=NOW() WHERE id=?")->execute([$id]);
        ai_event(null, $actor, 'repo.check', 'ai_repos', $id, ['job_id' => $jobId, 'duplicate' => $jobId === null]);
        ai_mark_changed();
        return ['id' => $id, 'job_id' => $jobId, 'duplicate' => $jobId === null, 'last_checked' => $r['last_checked']];
    }

    if ($action === 'discover') {
        admin_require($me, 'approver');
        $jobId = ai_enqueue('discover_repos', null, ['source' => 'ui'], $actor);
        ai_event(null, $actor, 'repo.discover.request', 'ai_jobs', $jobId, ['duplicate' => $jobId === null]);
        return ['job_id' => $jobId, 'duplicate' => $jobId === null];
    }

    if ($action === 'delete') {
        admin_require($me, 'admin');
        $id = admin_id($in['id'] ?? 0);
        $r  = $id ? repos_api_get($pdo, $id) : null;
        if (!$r) admin_abort('not_found', '레포가 없습니다.', 404);
        if ($r['ref_count'] > 0) {
            admin_abort('in_use', "플랜/분석 결과 {$r['ref_count']}건이 이 레포를 참조합니다. 삭제 대신 '미사용'으로 전환하세요.", 409,
                        ['ref_count' => $r['ref_count']]);
        }
        $pdo->prepare("DELETE FROM ai_repos WHERE id=?")->execute([$id]);
        ai_event(null, $actor, 'repo.delete', 'ai_repos', $id, ['name' => $r['name'], 'local_path' => $r['local_path']]);
        ai_mark_changed();
        return ['id' => $id];
    }

    admin_abort('bad_request', '알 수 없는 action 입니다.', 400);
}

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../auth.php';
    require_login();
    session_release();
    admin_run('repos_api_handle');
}
