<?php
/** 강의 분류(2단계). medium 이 빈 문자열이면 대분류 행이다. */

function learn_category_out(array $c) {
    return ['id' => (int)$c['id'], 'site' => $c['site'], 'large' => $c['large'],
            'medium' => $c['medium'], 'sort_order' => (int)$c['sort_order'],
            'recommended' => (int)$c['recommended'], 'active' => (int)$c['active']];
}

function learn_fetch_category(PDO $pdo, $cid) {
    $st = $pdo->prepare("SELECT * FROM learn_categories WHERE id=?");
    $st->execute([$cid]);
    $row = $st->fetch();
    if (!$row) throw new LearnError('없는 분류입니다', 404);
    return $row;
}

function learn_find_category(PDO $pdo, $site, $large, $medium) {
    $st = $pdo->prepare("SELECT * FROM learn_categories
                         WHERE site=? AND large=? AND medium=? LIMIT 1");
    $st->execute([$site, $large, $medium]);
    return $st->fetch() ?: null;
}

/** 대분류 행에 딸린 중분류들. 중분류 행은 자식이 없다. */
function learn_category_children(PDO $pdo, array $row) {
    if ($row['medium'] !== '') return [];
    $st = $pdo->prepare("SELECT * FROM learn_categories
                         WHERE site=? AND large=? AND medium<>'' ORDER BY sort_order, id");
    $st->execute([$row['site'], $row['large']]);
    return $st->fetchAll();
}

function learn_category_shape(PDO $pdo, $site, $large, $medium, $cid = null) {
    $dup = learn_find_category($pdo, $site, $large, $medium);
    if ($dup && (int)$dup['id'] !== (int)$cid) {
        throw new LearnError('이미 있는 분류입니다', 409);
    }
    if ($medium !== '' && !learn_find_category($pdo, $site, $large, '')) {
        throw new LearnError('대분류를 먼저 등록해 주세요', 422);
    }
}

function learn_route_categories(PDO $pdo, array $identity, array $seg, $method) {
    $tail = $seg[1] ?? '';

    if ($tail === '' && $method === 'GET') {
        $sql  = "SELECT * FROM learn_categories WHERE 1=1";
        $args = [];
        if (!empty($_GET['site'])) { $sql .= " AND site=?"; $args[] = $_GET['site']; }
        if (!learn_is_admin($identity['email'])) $sql .= " AND active=1";
        $st = $pdo->prepare($sql . " ORDER BY sort_order, id");
        $st->execute($args);
        $out = [];
        foreach ($st->fetchAll() as $c) $out[] = learn_category_out($c);
        jsend($out);
    }

    learn_require_admin_action($identity);

    if ($tail === '' && $method === 'POST')            learn_category_create($pdo);
    if ($tail === 'recommended' && $method === 'PUT')  learn_category_recommend($pdo);

    $cid = (int)$tail;
    if ($cid && ($seg[2] ?? '') === 'purge' && $method === 'DELETE') {
        learn_category_purge($pdo, $cid);
    }
    if ($cid && ($seg[2] ?? '') === '') {
        if ($method === 'PUT')    learn_category_update($pdo, $cid);
        if ($method === 'DELETE') learn_category_deactivate($pdo, $cid);
    }
    throw new LearnError('없는 API 입니다', 404);
}

function learn_category_create(PDO $pdo) {
    $body   = body_json();
    $site   = want_str($body, 'site', '플랫폼', true);
    $large  = want_str($body, 'large', '대분류', true);
    $medium = want_str($body, 'medium', '중분류');
    learn_category_shape($pdo, $site, $large, $medium);

    $pdo->prepare("INSERT INTO learn_categories (site, large, medium, sort_order, recommended)
                   VALUES (?,?,?,?,?)")
        ->execute([$site, $large, $medium, (int)($body['sort_order'] ?? 0),
                   to_flag($body['recommended'] ?? false)]);
    jsend(learn_category_out(learn_fetch_category($pdo, (int)$pdo->lastInsertId())), 201);
}

function learn_category_update(PDO $pdo, $cid) {
    $row   = learn_fetch_category($pdo, $cid);
    $body  = body_json();
    $patch = [];

    if (isset($body['site']))        $patch['site']   = want_str($body, 'site', '플랫폼', true);
    if (isset($body['large']))       $patch['large']  = want_str($body, 'large', '대분류', true);
    if (isset($body['medium']))      $patch['medium'] = want_str($body, 'medium', '중분류');
    if (isset($body['sort_order']))  $patch['sort_order']  = (int)$body['sort_order'];
    if (isset($body['recommended'])) $patch['recommended'] = to_flag($body['recommended']);
    if (isset($body['active']))      $patch['active']      = to_flag($body['active']);

    $after = array_merge($row, $patch);
    learn_category_shape($pdo, $after['site'], $after['large'], $after['medium'], $cid);

    // 자식은 바꾸기 전 이름으로 찾아 둔다 — 대분류를 고친 뒤에는 찾을 수 없다
    $children = learn_category_children($pdo, $row);

    if ($patch) {
        $set = [];
        foreach (array_keys($patch) as $c) $set[] = "`$c`=?";
        $pdo->prepare("UPDATE learn_categories SET " . implode(',', $set) . " WHERE id=?")
            ->execute(array_merge(array_values($patch), [$cid]));
    }

    // 대분류를 고치면 딸린 중분류가 같은 이름을 들고 따라와야 한다
    if ($children) {
        $sql  = "UPDATE learn_categories SET site=?, large=?";
        $args = [$after['site'], $after['large']];
        if (array_key_exists('active', $patch)) {
            $sql .= ", active=?";
            $args[] = $patch['active'];
        }
        $ids  = implode(',', array_map(fn($c) => (int)$c['id'], $children));
        $pdo->prepare($sql . " WHERE id IN ($ids)")->execute($args);
    }
    jsend(learn_category_out(learn_fetch_category($pdo, $cid)));
}

/** 한 플랫폼의 추천 지정을 화면 상태 그대로 맞춘다. 목록에 없는 행은 해제된다. */
function learn_category_recommend(PDO $pdo) {
    $body = body_json();
    $site = want_str($body, 'site', '플랫폼', true);
    $ids  = array_map('intval', (array)($body['ids'] ?? []));

    $st = $pdo->prepare("SELECT * FROM learn_categories WHERE site=? ORDER BY sort_order, id");
    $st->execute([$site]);
    $rows = $st->fetchAll();
    if (!$rows) throw new LearnError('분류가 없는 플랫폼입니다', 404);

    $known   = array_map(fn($r) => (int)$r['id'], $rows);
    $unknown = array_values(array_diff($ids, $known));
    if ($unknown) {
        sort($unknown);
        throw new LearnError("{$site} 의 분류가 아닌 항목이 있습니다: "
                             . implode(', ', $unknown), 422);
    }

    $upd = $pdo->prepare("UPDATE learn_categories SET recommended=? WHERE id=?");
    $out = [];
    foreach ($rows as $r) {
        $want = in_array((int)$r['id'], $ids, true) ? 1 : 0;
        if ((int)$r['recommended'] !== $want) $upd->execute([$want, (int)$r['id']]);
        $r['recommended'] = $want;
        $out[] = learn_category_out($r);
    }
    jsend($out);
}

/** 하드 삭제 금지 — 지난 신청 건이 이 이름을 그대로 들고 있다 */
function learn_category_deactivate(PDO $pdo, $cid) {
    $row = learn_fetch_category($pdo, $cid);
    $ids = [$cid];
    foreach (learn_category_children($pdo, $row) as $child) $ids[] = (int)$child['id'];
    $pdo->exec("UPDATE learn_categories SET active=0 WHERE id IN (" . implode(',', $ids) . ")");
    jsend(learn_category_out(learn_fetch_category($pdo, $cid)));
}

/**
 * 이 분류를 쓰는 신청이 몇 건인지. 신청 행은 분류를 이름 문자열로 들고 있어 옵션 행이
 * 사라져도 표시는 남지만, 쓰는 중인 분류를 지우면 관리자가 필터에서 그 이름을 다시
 * 고를 수 없게 된다 — 그래서 쓰고 있으면 지우지 못하게 막고 숨기기로 보낸다.
 */
function learn_category_usage(PDO $pdo, array $row) {
    $sql  = "SELECT COUNT(*) FROM learn_requests WHERE site=? AND category_large=?";
    $args = [$row['site'], $row['large']];
    if ($row['medium'] !== '') {
        // 중분류는 그 중분류를 고른 건만, 대분류는 그 아래 전부를 센다
        $sql .= " AND category_medium=?";
        $args[] = $row['medium'];
    }
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return (int)$st->fetchColumn();
}

/** 정말 지운다. 대분류를 지우면 그 아래 중분류도 함께 사라진다. */
function learn_category_purge(PDO $pdo, $cid) {
    $row  = learn_fetch_category($pdo, $cid);
    $kids = learn_category_children($pdo, $row);
    $used = learn_category_usage($pdo, $row);
    if ($used) {
        throw new LearnError("이 분류를 쓰는 신청이 {$used}건 있어 지울 수 없습니다. "
                             . '숨기기만 됩니다.', 409);
    }
    $ids = [$cid];
    foreach ($kids as $child) $ids[] = (int)$child['id'];
    $pdo->exec("DELETE FROM learn_categories WHERE id IN (" . implode(',', $ids) . ")");
    jsend(['deleted' => count($ids)]);
}
