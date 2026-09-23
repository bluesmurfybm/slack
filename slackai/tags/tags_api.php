<?php
/**
 * 태그(ai_tags) 관리 API — tags/tags.php 화면과 AI 패널의 태그 선택기가 쓴다.
 *  GET  ?all=1                    → {ok, rows:[{id,name,slug,parent_id,color,keywords,description,sort_order,active,usage}]}
 *                                   (all 없으면 active=1 만; usage = request_tags 건수)
 *  POST {action, ...}  (X-Requested-With: fetch 필수)
 *    create  {name, slug?, parent_id?, color?, keywords?, description?}   로그인 사용자
 *    update  {id, name?, slug?, parent_id?, color?, keywords?, description?}
 *    toggle  {id, active?}                                                  로그인 사용자
 *    reorder {order:[id,...]}                                               로그인 사용자
 *    merge   {from_id, into_id}     request_tags 를 into 로 이동(INSERT IGNORE) 후 from 삭제   승인자/관리자
 *    delete  {id, force?}           사용 중이면 409 in_use; force 는 관리자만(request_tags 연쇄 삭제)  승인자/관리자
 *  오류: 400 dup_name / dup_slug / bad_color / bad_parent / bad_request, 403 forbidden, 404 not_found, 409 in_use
 *  CLI 에서는 tags_api_handle() 만 정의하고 실행하지 않는다(_smoke_admin.php).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ai/ai_lib.php';
require_once __DIR__ . '/../ai/admin_lib.php';

/** 태그 1건 (+usage). 없으면 null */
function tags_api_get(PDO $pdo, $id) {
    $st = $pdo->prepare("SELECT t.*, (SELECT COUNT(*) FROM request_tags rt WHERE rt.tag_id = t.id) AS `usage`
                         FROM ai_tags t WHERE t.id = ?");
    $st->execute([(int)$id]);
    $r = $st->fetch();
    return $r ? tags_api_row($r) : null;
}

/** 행 타입 정규화 */
function tags_api_row(array $r) {
    $r['id']         = (int)$r['id'];
    $r['parent_id']  = $r['parent_id'] === null ? null : (int)$r['parent_id'];
    $r['sort_order'] = (int)$r['sort_order'];
    $r['active']     = (int)$r['active'];
    $r['usage']      = (int)($r['usage'] ?? 0);
    $r['keywords']   = (string)($r['keywords'] ?? '');
    $r['description']= (string)($r['description'] ?? '');
    return $r;
}

/** 전체 목록 */
function tags_api_list(PDO $pdo, $all) {
    $where = $all ? '' : ' WHERE t.active = 1';
    $rows = $pdo->query("SELECT t.*, (SELECT COUNT(*) FROM request_tags rt WHERE rt.tag_id = t.id) AS `usage`
                         FROM ai_tags t{$where} ORDER BY t.sort_order, t.id")->fetchAll();
    return array_map('tags_api_row', $rows);
}

/** 이름 중복 검사 (자기 자신 제외) */
function tags_api_name_exists(PDO $pdo, $name, $exceptId = 0) {
    $st = $pdo->prepare("SELECT id FROM ai_tags WHERE name = ? AND id <> ? LIMIT 1");
    $st->execute([$name, (int)$exceptId]);
    return (bool)$st->fetchColumn();
}

/** slug 중복 검사 */
function tags_api_slug_exists(PDO $pdo, $slug, $exceptId = 0) {
    $st = $pdo->prepare("SELECT id FROM ai_tags WHERE slug = ? AND id <> ? LIMIT 1");
    $st->execute([$slug, (int)$exceptId]);
    return (bool)$st->fetchColumn();
}

/** 비어 있으면 이름에서 slug 생성(충돌 시 -2, -3 …), 직접 준 값이 충돌하면 400 */
function tags_api_resolve_slug(PDO $pdo, $slugIn, $name, $exceptId = 0) {
    $given = admin_str($slugIn) !== '';
    $slug  = admin_slugify($given ? $slugIn : $name);
    if (!tags_api_slug_exists($pdo, $slug, $exceptId)) return $slug;
    if ($given) admin_abort('dup_slug', "slug '{$slug}' 가 이미 있습니다.", 400);
    for ($i = 2; $i < 100; $i++) {
        $cand = mb_substr($slug, 0, 60 - strlen("-{$i}")) . "-{$i}";
        if (!tags_api_slug_exists($pdo, $cand, $exceptId)) return $cand;
    }
    admin_abort('dup_slug', 'slug 를 만들 수 없습니다.', 400);
}

/** parent_id 검증: 존재해야 하고, 자기 자신·자기 하위여서는 안 됨 */
function tags_api_resolve_parent(PDO $pdo, $parentId, $selfId = 0) {
    $parentId = admin_int_or_null($parentId);
    if ($parentId === null || $parentId <= 0) return null;
    if ($selfId && $parentId === (int)$selfId) admin_abort('bad_parent', '자기 자신을 상위로 둘 수 없습니다.', 400);
    $st = $pdo->prepare("SELECT id, parent_id FROM ai_tags WHERE id = ?");
    // 상위 체인을 따라가며 자기 자신이 나오면 순환
    $cur = $parentId; $hops = 0;
    while ($cur && $hops++ < 50) {
        $st->execute([$cur]);
        $row = $st->fetch();
        if (!$row) admin_abort('bad_parent', '상위 태그가 없습니다.', 400);
        if ($selfId && (int)$row['id'] === (int)$selfId) admin_abort('bad_parent', '하위 태그를 상위로 둘 수 없습니다.', 400);
        $cur = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
    }
    return $parentId;
}

/**
 * @param string $method GET|POST
 * @param array  $get    $_GET
 * @param array  $in     POST JSON 본문
 * @param array  $me     ['email','is_admin','is_approver']
 * @return array         응답 본문(ok 는 admin_run 이 붙임)
 */
function tags_api_handle($method, array $get, array $in, array $me) {
    $pdo   = db();
    $actor = (string)($me['email'] ?? 'unknown');

    if ($method === 'GET') {
        return ['rows' => tags_api_list($pdo, !empty($get['all']))];
    }

    $action = admin_str($in['action'] ?? '');

    if ($action === 'create' || $action === 'update') {
        $id = $action === 'update' ? admin_id($in['id'] ?? 0) : 0;
        $old = null;
        if ($action === 'update') {
            if ($id <= 0) admin_abort('bad_request', '잘못된 id 입니다.', 400);
            $old = tags_api_get($pdo, $id);
            if (!$old) admin_abort('not_found', '태그가 없습니다.', 404);
        }
        $name = admin_str($in['name'] ?? ($old['name'] ?? ''), 60);
        if ($name === '') admin_abort('bad_request', '태그 이름을 입력하세요.', 400);
        if (tags_api_name_exists($pdo, $name, $id)) admin_abort('dup_name', "이미 같은 이름의 태그가 있습니다: {$name}", 400);

        $slugIn = array_key_exists('slug', $in) ? admin_str($in['slug'], 60) : (string)($old['slug'] ?? '');
        $slug   = tags_api_resolve_slug($pdo, $slugIn, $name, $id);

        $colorIn = array_key_exists('color', $in) ? admin_str($in['color']) : (string)($old['color'] ?? '');
        if ($colorIn === '') $colorIn = '#9aa0a6';
        $color = admin_color($colorIn);
        if ($color === null) admin_abort('bad_color', '색상은 #rrggbb 형식이어야 합니다.', 400);

        $parentId = array_key_exists('parent_id', $in) ? tags_api_resolve_parent($pdo, $in['parent_id'], $id) : ($old['parent_id'] ?? null);
        $keywords = array_key_exists('keywords', $in) ? implode(',', admin_list($in['keywords'])) : (string)($old['keywords'] ?? '');
        $desc     = array_key_exists('description', $in) ? admin_str($in['description'], 300) : (string)($old['description'] ?? '');

        if ($action === 'create') {
            $next = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM ai_tags")->fetchColumn();
            $active = admin_bool($in['active'] ?? 1, 1);
            $pdo->prepare("INSERT INTO ai_tags (name, slug, parent_id, color, keywords, description, sort_order, active, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$name, $slug, $parentId, $color, $keywords, $desc, $next, $active]);
            $newId = (int)$pdo->lastInsertId();
            ai_event(null, $actor, 'tag.create', 'ai_tags', $newId, ['name' => $name, 'slug' => $slug]);
            ai_mark_changed();
            return ['id' => $newId, 'row' => tags_api_get($pdo, $newId)];
        }

        $active = array_key_exists('active', $in) ? admin_bool($in['active'], 1) : (int)$old['active'];
        $pdo->prepare("UPDATE ai_tags SET name=?, slug=?, parent_id=?, color=?, keywords=?, description=?, active=?, updated_at=NOW() WHERE id=?")
            ->execute([$name, $slug, $parentId, $color, $keywords, $desc, $active, $id]);
        $new = tags_api_get($pdo, $id);
        $diff = [];
        foreach (['name', 'slug', 'parent_id', 'color', 'keywords', 'description', 'active'] as $k) {
            if (($old[$k] ?? null) !== ($new[$k] ?? null)) $diff[$k] = ['from' => $old[$k] ?? null, 'to' => $new[$k] ?? null];
        }
        if ($diff) ai_event(null, $actor, 'tag.update', 'ai_tags', $id, $diff);
        ai_mark_changed();
        return ['row' => $new];
    }

    if ($action === 'toggle') {
        $id = admin_id($in['id'] ?? 0);
        $t  = $id ? tags_api_get($pdo, $id) : null;
        if (!$t) admin_abort('not_found', '태그가 없습니다.', 404);
        $to = array_key_exists('active', $in) ? admin_bool($in['active'], 1) : (int)!$t['active'];
        $pdo->prepare("UPDATE ai_tags SET active=?, updated_at=NOW() WHERE id=?")->execute([$to, $id]);
        ai_event(null, $actor, 'tag.toggle', 'ai_tags', $id, ['active' => $to]);
        ai_mark_changed();
        return ['id' => $id, 'active' => $to];
    }

    if ($action === 'reorder') {
        $order = $in['order'] ?? null;
        if (!is_array($order) || !$order) admin_abort('bad_request', 'order 배열이 필요합니다.', 400);
        $ids = [];
        foreach ($order as $v) { $i = admin_id($v); if ($i > 0 && !in_array($i, $ids, true)) $ids[] = $i; }
        if (!$ids) admin_abort('bad_request', 'order 에 유효한 id 가 없습니다.', 400);
        $st = $pdo->prepare("UPDATE ai_tags SET sort_order=?, updated_at=NOW() WHERE id=?");
        $pdo->beginTransaction();
        try {
            $n = 0;
            foreach ($ids as $i => $id) { $st->execute([($i + 1) * 10, $id]); $n += $st->rowCount(); }
            // 목록에 없는 태그는 뒤로 밀어 순서를 유지
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $rest = $pdo->prepare("SELECT id FROM ai_tags WHERE id NOT IN ($ph) ORDER BY sort_order, id");
            $rest->execute($ids);
            $k = count($ids);
            foreach ($rest->fetchAll(PDO::FETCH_COLUMN) as $rid) { $st->execute([(++$k) * 10, (int)$rid]); }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        ai_event(null, $actor, 'tag.reorder', 'ai_tags', null, ['count' => count($ids)]);
        ai_mark_changed();
        return ['updated' => $n];
    }

    if ($action === 'merge') {
        admin_require($me, 'approver');
        $from = admin_id($in['from_id'] ?? 0);
        $into = admin_id($in['into_id'] ?? 0);
        if ($from <= 0 || $into <= 0) admin_abort('bad_request', 'from_id / into_id 가 필요합니다.', 400);
        if ($from === $into) admin_abort('bad_request', '같은 태그로는 병합할 수 없습니다.', 400);
        $tf = tags_api_get($pdo, $from);
        $ti = tags_api_get($pdo, $into);
        if (!$tf || !$ti) admin_abort('not_found', '태그가 없습니다.', 404);
        $pdo->beginTransaction();
        try {
            $mv = $pdo->prepare("INSERT IGNORE INTO request_tags (request_id, tag_id, source, confidence, set_by, created_at)
                                 SELECT request_id, ?, source, confidence, set_by, created_at FROM request_tags WHERE tag_id = ?");
            $mv->execute([$into, $from]);
            $moved = $mv->rowCount();
            $pdo->prepare("DELETE FROM request_tags WHERE tag_id = ?")->execute([$from]);
            // 하위 태그·학습 노트·분석 결과의 참조도 into 로
            $newParent = ($ti['parent_id'] === $from) ? $tf['parent_id'] : $into;
            $pdo->prepare("UPDATE ai_tags SET parent_id = ?, updated_at = NOW() WHERE parent_id = ? AND id <> ?")->execute([$newParent, $from, $into]);
            if ($ti['parent_id'] === $from) {
                $pdo->prepare("UPDATE ai_tags SET parent_id = ?, updated_at = NOW() WHERE id = ?")->execute([$tf['parent_id'], $into]);
            }
            $pdo->prepare("UPDATE ai_lessons SET tag_id = ?, updated_at = NOW() WHERE tag_id = ?")->execute([$into, $from]);
            $pdo->prepare("DELETE FROM ai_tags WHERE id = ?")->execute([$from]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        ai_event(null, $actor, 'tag.merge', 'ai_tags', $into,
                 ['from' => ['id' => $from, 'name' => $tf['name'], 'usage' => $tf['usage']], 'into' => ['id' => $into, 'name' => $ti['name']], 'moved' => $moved]);
        ai_mark_changed();
        return ['from_id' => $from, 'into_id' => $into, 'moved' => $moved, 'row' => tags_api_get($pdo, $into)];
    }

    if ($action === 'delete') {
        admin_require($me, 'approver');
        $id    = admin_id($in['id'] ?? 0);
        $force = admin_bool($in['force'] ?? 0, 0) === 1;
        $t = $id ? tags_api_get($pdo, $id) : null;
        if (!$t) admin_abort('not_found', '태그가 없습니다.', 404);
        if ($t['usage'] > 0) {
            if (!$force) {
                admin_abort('in_use', "사용 중인 태그입니다({$t['usage']}건). 다른 태그로 병합하거나 강제 삭제(관리자)하세요.", 409,
                            ['usage' => $t['usage']]);
            }
            admin_require($me, 'admin');
        }
        $pdo->beginTransaction();
        try {
            if ($t['usage'] > 0) $pdo->prepare("DELETE FROM request_tags WHERE tag_id = ?")->execute([$id]);
            // 하위 태그는 삭제되는 태그의 상위로 올린다
            $pdo->prepare("UPDATE ai_tags SET parent_id = ?, updated_at = NOW() WHERE parent_id = ?")->execute([$t['parent_id'], $id]);
            $pdo->prepare("UPDATE ai_lessons SET tag_id = NULL, updated_at = NOW() WHERE tag_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM ai_tags WHERE id = ?")->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        ai_event(null, $actor, 'tag.delete', 'ai_tags', $id, ['name' => $t['name'], 'usage' => $t['usage'], 'force' => $force]);
        ai_mark_changed();
        return ['id' => $id, 'deleted_usage' => $t['usage']];
    }

    admin_abort('bad_request', '알 수 없는 action 입니다.', 400);
}

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../auth.php';
    require_login();
    session_release();
    admin_run('tags_api_handle');
}
