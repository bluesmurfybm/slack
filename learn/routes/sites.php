<?php
/** 교육 플랫폼 관리. 삭제는 비활성(active=0)이다 — 지난 신청 건이 이 이름을 들고 있다. */

function learn_site_out(array $s) {
    return ['id' => (int)$s['id'], 'name' => $s['name'], 'url' => $s['url'],
            'sort_order' => (int)$s['sort_order'], 'active' => (int)$s['active']];
}

function learn_fetch_site(PDO $pdo, $sid) {
    $st = $pdo->prepare("SELECT * FROM learn_sites WHERE id=?");
    $st->execute([$sid]);
    $row = $st->fetch();
    if (!$row) throw new LearnError('없는 플랫폼입니다', 404);
    return $row;
}

function learn_site_unique(PDO $pdo, $name, $sid = null) {
    $st = $pdo->prepare("SELECT id FROM learn_sites WHERE name=?");
    $st->execute([$name]);
    $dup = $st->fetchColumn();
    if ($dup && (int)$dup !== (int)$sid) throw new LearnError('이미 있는 플랫폼입니다', 409);
}

function learn_route_sites(PDO $pdo, array $identity, array $seg, $method) {
    $sid = isset($seg[1]) ? (int)$seg[1] : 0;

    if (!$sid && $method === 'GET') {
        $sql = "SELECT * FROM learn_sites";
        if (!learn_is_admin($identity['email'])) $sql .= " WHERE active=1";
        $out = [];
        foreach ($pdo->query($sql . " ORDER BY sort_order, id")->fetchAll() as $s) {
            $out[] = learn_site_out($s);
        }
        jsend($out);
    }

    learn_require_admin_action($identity);

    if (!$sid && $method === 'POST') {
        $body = body_json();
        $name = want_str($body, 'name', '플랫폼 이름', true);
        $url  = want_url($body['url'] ?? '', '플랫폼 주소');
        learn_site_unique($pdo, $name);
        $pdo->prepare("INSERT INTO learn_sites (name, url, sort_order) VALUES (?,?,?)")
            ->execute([$name, $url, (int)($body['sort_order'] ?? 0)]);
        jsend(learn_site_out(learn_fetch_site($pdo, (int)$pdo->lastInsertId())), 201);
    }

    if ($sid && $method === 'PUT')    learn_site_update($pdo, $sid);
    if ($sid && $method === 'DELETE') learn_site_deactivate($pdo, $sid);
    throw new LearnError('없는 API 입니다', 404);
}

function learn_site_update(PDO $pdo, $sid) {
    $site  = learn_fetch_site($pdo, $sid);
    $body  = body_json();
    $patch = [];

    if (isset($body['name']))       $patch['name'] = want_str($body, 'name', '플랫폼 이름', true);
    if (isset($body['url']))        $patch['url']  = want_url($body['url'], '플랫폼 주소');
    if (isset($body['sort_order'])) $patch['sort_order'] = (int)$body['sort_order'];
    if (isset($body['active']))     $patch['active'] = to_flag($body['active']);

    if (isset($patch['name']) && $patch['name'] !== $site['name']) {
        learn_site_unique($pdo, $patch['name'], $sid);
        // 분류는 플랫폼을 이름으로 참조한다 — 같이 옮기지 않으면 통째로 고아가 된다.
        // 지난 신청 건의 site 는 그대로 둔다(당시 이름이 남아야 한다).
        $pdo->prepare("UPDATE learn_categories SET site=? WHERE site=?")
            ->execute([$patch['name'], $site['name']]);
    }

    if ($patch) {
        $set = [];
        foreach (array_keys($patch) as $c) $set[] = "`$c`=?";
        $pdo->prepare("UPDATE learn_sites SET " . implode(',', $set) . " WHERE id=?")
            ->execute(array_merge(array_values($patch), [$sid]));
    }
    jsend(learn_site_out(learn_fetch_site($pdo, $sid)));
}

function learn_site_deactivate(PDO $pdo, $sid) {
    learn_fetch_site($pdo, $sid);
    $pdo->prepare("UPDATE learn_sites SET active=0 WHERE id=?")->execute([$sid]);
    jsend(learn_site_out(learn_fetch_site($pdo, $sid)));
}
