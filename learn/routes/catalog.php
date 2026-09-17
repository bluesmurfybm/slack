<?php
/**
 * 추천 · 필수 강의 카탈로그.
 *
 * 관리자가 등록한 "회사가 권하는 강의"다. 신청(learn_requests)과 별개이고,
 * 직원이 카드에서 신청하면 learn_requests.catalog_id 로 연결된다.
 * 그 연결 하나로 이수 여부와 동료 수강 현황이 따라 나오므로 별도 이수 테이블을 두지 않는다.
 */

const GRADE_REQUIRED = '필수';
const GRADE_PICK     = '추천';
const GRADES         = [GRADE_REQUIRED, GRADE_PICK];

const SCOPE_ALL  = '전사';
const SCOPE_SOME = '지정';
const SCOPES     = [SCOPE_ALL, SCOPE_SOME];

// 직원 카드에 뜨는 내 상태
const MY_NONE = '미신청';
const MY_WAIT = '승인 대기';
const MY_ING  = '수강 중';
const MY_DONE = '이수 완료';

const CATALOG_INT_COLS = ['id', 'duration_min', 'is_free', 'price', 'sort_order', 'active'];

function learn_catalog_out(array $c) {
    foreach (CATALOG_INT_COLS as $k) $c[$k] = (int)$c[$k];
    $c['target_emails'] = learn_target_list($c['target_emails']);
    return $c;
}

function learn_target_list($raw) {
    $out = [];
    foreach (explode(',', (string)$raw) as $e) {
        $e = strtolower(trim($e));
        if ($e !== '') $out[] = $e;
    }
    return array_values(array_unique($out));
}

/** 이 사람이 이 강의의 대상인가. 전사면 모두, 지정이면 명단에 있을 때만. */
function learn_is_target(array $c, $email) {
    if (($c['target_scope'] ?? SCOPE_ALL) !== SCOPE_SOME) return true;
    return in_array(strtolower((string)$email), (array)$c['target_emails'], true);
}

/** 노출 기간 안인가. active=0 은 임시 저장이라 직원 화면에 아예 안 나간다. */
function learn_is_open(array $c, $today) {
    if (!(int)$c['active']) return false;
    if ($c['open_from'] !== '' && $today < $c['open_from']) return false;
    return !($c['open_to'] !== '' && $today > $c['open_to']);
}

/** 신청에 붙일 등급·이수기한표. 한 요청 안에서 한 번만 읽는다. */
function learn_catalog_grades(PDO $pdo) {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach ($pdo->query("SELECT id, grade, due_date FROM learn_catalog")->fetchAll() as $c) {
            $map[(int)$c['id']] = ['grade' => $c['grade'], 'due' => $c['due_date']];
        }
    }
    return $map;
}

function learn_fetch_catalog(PDO $pdo, $cid) {
    $st = $pdo->prepare("SELECT * FROM learn_catalog WHERE id=?");
    $st->execute([$cid]);
    $row = $st->fetch();
    if (!$row) throw new LearnError('없는 강의입니다', 404);
    return $row;
}

/**
 * 카탈로그별 신청 현황을 한 번에 읽는다. 카드마다 조회하면 N+1 이 된다.
 * 이수 판정은 이수증 1장 이상 — 진행상태 '완료'는 본인이 그냥 누를 수 있어 증빙이 없다.
 */
function learn_catalog_stats(PDO $pdo) {
    $sql = "SELECT r.catalog_id, r.applicant_email, r.applicant, r.id AS rid,
                   r.approved_at, r.rejected_at, r.is_free, r.account_type,
                   r.claimed_at, r.claim_approved_at, r.claim_rejected_at, r.refunded_at,
                   r.created_at, r.progress, r.rating,
                   (SELECT COUNT(*) FROM learn_certs c WHERE c.request_id=r.id) AS certs,
                   (SELECT MAX(c.created_at) FROM learn_certs c WHERE c.request_id=r.id) AS cert_at
            FROM learn_requests r
            WHERE r.catalog_id > 0 AND r.rejected_at = ''";
    $by = [];
    foreach ($pdo->query($sql)->fetchAll() as $r) {
        $by[(int)$r['catalog_id']][] = $r;
    }
    return $by;
}

function learn_my_state(array $rows, $email) {
    foreach ($rows as $r) {
        if (strcasecmp($r['applicant_email'], $email) !== 0) continue;
        if ((int)$r['certs']) {
            return ['state' => MY_DONE, 'request_id' => (int)$r['rid'],
                    'at' => substr((string)$r['cert_at'], 0, 10),
                    'rating' => $r['rating'] === null ? null : (float)$r['rating']];
        }
        $state = learn_derive_status($r) === S_REQUESTED ? MY_WAIT : MY_ING;
        return ['state' => $state, 'request_id' => (int)$r['rid'],
                'at' => substr((string)$r['created_at'], 0, 10), 'rating' => null];
    }
    return ['state' => MY_NONE, 'request_id' => 0, 'at' => '', 'rating' => null];
}

/** 카드 아래 한 줄 — 몇 명이 이수했고 몇 명이 듣고 있는지 */
function learn_peer_counts(array $rows) {
    $done = $ing = 0;
    foreach ($rows as $r) {
        if ((int)$r['certs']) $done++; else $ing++;
    }
    return ['done' => $done, 'ing' => $ing];
}

function learn_route_catalog(PDO $pdo, array $identity, array $seg, $method) {
    $tail = $seg[1] ?? '';

    if ($tail === '' && $method === 'GET')  learn_catalog_list($pdo, $identity);
    learn_require_admin_action($identity);

    if ($tail === '' && $method === 'POST') learn_catalog_create($pdo, $identity);

    $cid = (int)$tail;
    if ($cid && ($seg[2] ?? '') === 'completion' && $method === 'GET') {
        learn_catalog_completion($pdo, $cid);
    }
    if ($cid && ($seg[2] ?? '') === '') {
        if ($method === 'PUT')    learn_catalog_update($pdo, $identity, $cid);
        if ($method === 'DELETE') learn_catalog_delete($pdo, $cid);
    }
    throw new LearnError('없는 API 입니다', 404);
}

/* ---------- 목록 ---------- */

function learn_catalog_list(PDO $pdo, array $identity) {
    $admin = learn_is_admin($identity['email']);
    $email = $identity['email'];
    $today = date('Y-m-d');

    $rows  = $pdo->query("SELECT * FROM learn_catalog
                          ORDER BY grade DESC, sort_order, id DESC")->fetchAll();
    $stats = learn_catalog_stats($pdo);

    $out = [];
    foreach ($rows as $row) {
        $c = learn_catalog_out($row);
        $mine = $stats[$c['id']] ?? [];

        $c['is_target'] = learn_is_target($c, $email);
        $c['is_open']   = learn_is_open($c, $today);
        // 관리자는 임시 저장·노출 기간 밖까지 본다. 직원 화면에서 빼는 판정은 서버가 한다.
        if (!$admin && (!$c['is_open'] || !$c['is_target'])) continue;

        $c['me']    = learn_my_state($mine, $email);
        $c['peers'] = learn_peer_counts($mine);
        $c['target_count'] = $c['target_scope'] === SCOPE_SOME
            ? count($c['target_emails']) : count(learn_members());
        // 관리자 목록의 이수율 — 대상 인원 대비 이수 인원
        $c['done_count'] = $c['peers']['done'];
        if (!$admin) unset($c['target_emails']);
        $out[] = $c;
    }
    jsend($out);
}

/** 필수 강의 이수 현황 — 행=대상자, 열=상태. 13명이면 한 화면에 들어간다. */
function learn_catalog_completion(PDO $pdo, $cid) {
    $c     = learn_catalog_out(learn_fetch_catalog($pdo, $cid));
    $rows  = learn_catalog_stats($pdo)[$cid] ?? [];

    $people = [];
    foreach (learn_members() as $m) {
        if (!learn_is_target($c, $m['email'])) continue;
        $people[] = $m + learn_my_state($rows, $m['email']);
    }
    jsend(['catalog' => $c, 'people' => $people]);
}

/* ---------- 등록 · 수정 · 삭제 ---------- */

function learn_read_catalog_body(array $body, $partial) {
    $has = fn($k) => array_key_exists($k, $body) && $body[$k] !== null;
    $v = [];

    if (!$partial || $has('site'))  $v['site']  = want_str($body, 'site', '교육 플랫폼', true);
    if (!$partial || $has('title')) $v['title'] = want_str($body, 'title', '강의명', true);
    foreach (['category_large', 'category_medium'] as $k) {
        if (!$partial || $has($k)) $v[$k] = want_str($body, $k, '분류');
    }
    if (!$partial || $has('level')) {
        $v['level'] = want_one_of($body['level'] ?? '', LEVELS, '학습수준', true);
    }
    if (!$partial || $has('url')) $v['url'] = want_url($body['url'] ?? '', '수강주소');
    if (!$partial || $has('duration_min')) {
        $v['duration_min'] = want_int($body, 'duration_min', '강의 시간');
    }
    if (!$partial || $has('is_free')) $v['is_free'] = to_flag($body['is_free'] ?? false);
    if (!$partial || $has('price'))   $v['price']   = want_int($body, 'price', '수강료');
    if (!$partial || $has('grade')) {
        $v['grade'] = want_one_of($body['grade'] ?? GRADE_PICK, GRADES, '등급');
    }
    if (!$partial || $has('reason')) {
        $v['reason'] = want_str($body, 'reason', '사유', true);
        if (mb_strlen($v['reason']) > 300) {
            throw new LearnError('사유는 300자까지 쓸 수 있습니다', 422);
        }
    }
    if (!$partial || $has('due_date')) $v['due_date'] = want_date($body['due_date'] ?? '', '이수 기한');
    if (!$partial || $has('target_scope')) {
        $v['target_scope'] = want_one_of($body['target_scope'] ?? SCOPE_ALL, SCOPES, '대상 범위');
    }
    if (!$partial || $has('target_emails')) {
        $known = [];
        foreach (learn_members() as $m) $known[strtolower($m['email'])] = true;
        $wanted = [];
        foreach ((array)($body['target_emails'] ?? []) as $e) {
            $e = strtolower(trim((string)$e));
            if ($e === '') continue;
            if (!isset($known[$e])) throw new LearnError("명단에 없는 사람입니다: {$e}", 422);
            $wanted[$e] = true;
        }
        $v['target_emails'] = implode(',', array_keys($wanted));
    }
    foreach (['open_from' => '노출 시작일', 'open_to' => '노출 종료일'] as $k => $label) {
        if (!$partial || $has($k)) $v[$k] = want_date($body[$k] ?? '', $label);
    }
    if (!$partial || $has('sort_order')) $v['sort_order'] = (int)($body['sort_order'] ?? 0);
    if (!$partial || $has('active'))     $v['active']     = to_flag($body['active'] ?? true);
    return $v;
}

/** 필수는 기한과 대상이 있어야 필수다 — 없으면 그냥 강한 추천일 뿐이다 */
function learn_check_catalog(array $after) {
    if (($after['grade'] ?? GRADE_PICK) === GRADE_REQUIRED && ($after['due_date'] ?? '') === '') {
        throw new LearnError('필수 강의는 이수 기한을 입력해 주세요', 422);
    }
    if (($after['target_scope'] ?? SCOPE_ALL) === SCOPE_SOME
            && !learn_target_list($after['target_emails'] ?? '')) {
        throw new LearnError('대상자를 한 명 이상 골라 주세요', 422);
    }
    if (($after['open_from'] ?? '') !== '' && ($after['open_to'] ?? '') !== ''
            && $after['open_from'] > $after['open_to']) {
        throw new LearnError('노출 종료일이 시작일보다 빠릅니다', 422);
    }
    if (($after['due_date'] ?? '') !== '' && ($after['open_from'] ?? '') !== ''
            && $after['due_date'] < $after['open_from']) {
        throw new LearnError('이수 기한이 노출 시작일보다 빠릅니다', 422);
    }
}

/** 같은 수강주소를 두 번 등록하면 직원 화면에 같은 강의가 두 장 뜬다 */
function learn_check_dup_url(PDO $pdo, $url, $cid = null) {
    if ($url === '') return;
    $st = $pdo->prepare("SELECT id, title FROM learn_catalog WHERE url=?");
    $st->execute([$url]);
    foreach ($st->fetchAll() as $dup) {
        if ((int)$dup['id'] !== (int)$cid) {
            throw new LearnError("같은 수강주소가 이미 등록돼 있습니다: {$dup['title']}", 409);
        }
    }
}

function learn_catalog_create(PDO $pdo, array $identity) {
    $v = learn_read_catalog_body(body_json(), false);
    learn_ensure_site($pdo, $v['site']);
    learn_check_catalog($v);
    learn_check_dup_url($pdo, $v['url']);
    if ($v['is_free']) $v['price'] = 0;

    $v['created_by'] = $v['updated_by'] = $identity['email'];
    $v['created_at'] = $v['updated_at'] = now_stamp();

    $cols = array_keys($v);
    $pdo->prepare('INSERT INTO learn_catalog (`' . implode('`,`', $cols) . '`) VALUES ('
                  . implode(',', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($v));
    jsend(learn_catalog_out(learn_fetch_catalog($pdo, (int)$pdo->lastInsertId())), 201);
}

function learn_catalog_update(PDO $pdo, array $identity, $cid) {
    $row   = learn_fetch_catalog($pdo, $cid);
    $patch = learn_read_catalog_body(body_json(), true);
    if (isset($patch['site'])) learn_ensure_site($pdo, $patch['site']);

    $after = array_merge($row, $patch);
    learn_check_catalog($after);
    if (isset($patch['url'])) learn_check_dup_url($pdo, $patch['url'], $cid);
    if ((int)$after['is_free']) $patch['price'] = 0;

    $patch['updated_by'] = $identity['email'];
    $patch['updated_at'] = now_stamp();

    $set = [];
    foreach (array_keys($patch) as $c) $set[] = "`$c`=?";
    $pdo->prepare("UPDATE learn_catalog SET " . implode(',', $set) . " WHERE id=?")
        ->execute(array_merge(array_values($patch), [$cid]));
    jsend(learn_catalog_out(learn_fetch_catalog($pdo, $cid)));
}

/**
 * 지운다. 이미 신청한 사람이 있으면 거부하고 노출만 끄게 한다 —
 * 신청 건의 catalog_id 가 없는 id 를 가리키게 되면 이수 현황이 통째로 어긋난다.
 */
function learn_catalog_delete(PDO $pdo, $cid) {
    learn_fetch_catalog($pdo, $cid);
    $st = $pdo->prepare("SELECT COUNT(*) FROM learn_requests WHERE catalog_id=?");
    $st->execute([$cid]);
    $used = (int)$st->fetchColumn();
    if ($used) {
        throw new LearnError("이 강의로 신청한 건이 {$used}건 있어 지울 수 없습니다. "
                             . '노출만 끌 수 있습니다.', 409);
    }
    $pdo->prepare("DELETE FROM learn_catalog WHERE id=?")->execute([$cid]);
    jsend(['ok' => true]);
}
