<?php
/**
 * 학교 접속 정보 API (포털 로그인 필요)
 *   GET                                → schools JOIN school_access 목록 (?all=1 이면 미사용 학교 포함)
 *   POST {action:create}               → 새 대학 등록: schools 에 넣고 그 id 로 접속 정보까지 생성
 *   POST {action:save}                 → 한 학교의 접속 정보 저장(없으면 생성) + 마스터(schools) 동기화
 *   POST {action:delete}               → 접속 정보만 삭제(학교 자체는 slack/schools 에서 관리하므로 남긴다)
 *
 * 마스터는 schools 다. 대학명/버전/개발·운영 URL 은 schools 를 고쳐야 하고, 그 외 접속·배포
 * 정보만 school_access 에 들어간다.
 */
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/db.php';

if (!current_portal_user()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => '로그인이 필요합니다'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$COLS = access_cols();

try {
    $pdo = access_db();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        access_session_release();
        // 접속 정보가 아직 없는 학교도 보여야 한다(= 채워 넣어야 할 대상) → schools 기준 LEFT JOIN
        $where = isset($_GET['all']) ? '' : ' WHERE s.active=1';
        $sel   = implode(',', array_map(fn($c) => "a.`$c`", $COLS));
        $rows  = $pdo->query("
            SELECT s.id AS school_id, s.name, s.ver, s.dev, s.ops, s.`log`, s.active,
                   a.id AS access_id, {$sel}
            FROM schools s
            LEFT JOIN school_access a ON a.school_id = s.id
            {$where}
            ORDER BY s.ver+0, s.ver, a.sort_no, s.name
        ")->fetchAll();

        foreach ($rows as &$r) {
            $r['school_id'] = (int)$r['school_id'];
            $r['access_id'] = $r['access_id'] === null ? 0 : (int)$r['access_id'];
            $r['active']    = (int)$r['active'];
            foreach ($COLS as $c) $r[$c] = (string)($r[$c] ?? '');
            // 비밀번호는 DB에 암호화돼 있다 — 복사 버튼이 써야 하므로 여기서 풀어 내려보낸다
            foreach (access_secret_cols() as $c) $r[$c] = access_dec($r[$c]);
        }
        unset($r);
        echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $in       = json_decode(file_get_contents('php://input'), true) ?: [];
    $action   = $in['action'] ?? '';
    $schoolId = (int)($in['school_id'] ?? 0);
    $accessId = (int)($in['access_id'] ?? 0);   // 0 이면 이 학교의 첫 접속정보 = 신규

    if ($action === 'delete') {
        if ($accessId <= 0) throw new Exception('잘못된 접속정보 id');
        $pdo->prepare("DELETE FROM school_access WHERE id=?")->execute([$accessId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // 새 대학 등록 — 마스터인 schools 에 먼저 넣고, 그 id 로 접속 정보를 붙인다.
    // 이 화면에서만 만들고 schools 에 안 넣으면 목록(schools LEFT JOIN)에 아예 안 나온다.
    if ($action === 'create') {
        $name = trim((string)($in['name'] ?? ''));
        $ver  = trim((string)($in['ver'] ?? ''));
        if ($name === '') throw new Exception('대학(기관)명을 입력하세요.');

        // 같은 대학이라도 버전이 다르면 schools 에 따로 있는 게 정상이라(강원대 3.2 / 4.5)
        // 이름만으로는 막지 않고, 이름·버전이 똑같을 때만 막는다.
        $st = $pdo->prepare("SELECT id FROM schools WHERE name=? AND ver=?");
        $st->execute([$name, $ver]);
        if ($dup = $st->fetchColumn()) {
            throw new Exception("이미 등록된 대학입니다. (" . $name . ($ver !== '' ? " / {$ver}" : '') . ")");
        }

        $pdo->prepare("INSERT INTO schools (name, ver, dev, ops, `log`, active, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())")
            ->execute([
                $name, $ver,
                trim((string)($in['dev'] ?? '')),
                trim((string)($in['ops'] ?? '')),
                trim((string)($in['log'] ?? '')),
            ]);
        $schoolId = (int)$pdo->lastInsertId();

        $vals = [];
        foreach ($COLS as $c) $vals[$c] = trim((string)($in[$c] ?? ''));
        foreach (access_secret_cols() as $c) $vals[$c] = access_enc($vals[$c]);
        $sel = implode(',', array_map(fn($c) => "`$c`", $COLS));
        $ph  = implode(',', array_fill(0, count($COLS), '?'));
        $pdo->prepare("INSERT INTO school_access (school_id, {$sel}, created_at, updated_at)
                       VALUES (?, {$ph}, NOW(), NOW())")
            ->execute(array_merge([$schoolId], array_values($vals)));

        echo json_encode(['ok' => true, 'school_id' => $schoolId, 'access_id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    if ($schoolId <= 0) throw new Exception('학교를 선택하세요.');
    $st = $pdo->prepare("SELECT id FROM schools WHERE id=?");
    $st->execute([$schoolId]);
    if (!$st->fetchColumn()) throw new Exception('없는 학교입니다. (schools.id=' . $schoolId . ')');

    if ($action !== 'save') throw new Exception('알 수 없는 action');

    // 마스터에 속한 값(대학명/버전/URL)은 같은 화면에서 고칠 수 있게 하되 schools 로 넘긴다
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') throw new Exception('대학(기관)명을 입력하세요.');
    $pdo->prepare("UPDATE schools SET name=?, ver=?, dev=?, ops=?, `log`=?, updated_at=NOW() WHERE id=?")
        ->execute([
            $name,
            trim((string)($in['ver'] ?? '')),
            trim((string)($in['dev'] ?? '')),
            trim((string)($in['ops'] ?? '')),
            trim((string)($in['log'] ?? '')),
            $schoolId,
        ]);

    $vals = [];
    foreach ($COLS as $c) $vals[$c] = trim((string)($in[$c] ?? ''));
    foreach (access_secret_cols() as $c) $vals[$c] = access_enc($vals[$c]);

    // 한 학교에 접속정보가 두 벌인 경우가 있어서, 어떤 행을 고치는지는 access_id 로 정한다.
    if ($accessId > 0) {
        $set = implode(',', array_map(fn($c) => "`$c`=?", $COLS));
        $pdo->prepare("UPDATE school_access SET {$set}, updated_at=NOW() WHERE id=? AND school_id=?")
            ->execute(array_merge(array_values($vals), [$accessId, $schoolId]));
    } else {
        $sel = implode(',', array_map(fn($c) => "`$c`", $COLS));
        $ph  = implode(',', array_fill(0, count($COLS), '?'));
        $pdo->prepare("INSERT INTO school_access (school_id, {$sel}, created_at, updated_at)
                       VALUES (?, {$ph}, NOW(), NOW())")
            ->execute(array_merge([$schoolId], array_values($vals)));
    }

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
