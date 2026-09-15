<?php
/** 아티클과 발표. /topics/{tid}/{slot}/... 은 routes/materials.php 로 넘긴다. */

function dti_route_topics(array $ctx, array $req): array {
    $tid = $req['seg'][1] ?? null;

    if ($tid === null) {
        return match ($req['method']) {
            'GET' => dti_topic_index($ctx),
            'POST' => dti_topic_create($ctx, $req['body']),
            default => throw new DtiError('없는 API 입니다', 404),
        };
    }

    return match ([$req['method'], $req['seg'][2] ?? null]) {
        ['GET', null] => dti_topic_show($ctx, (int)$tid),
        ['PUT', null] => dti_topic_edit($ctx, (int)$tid, $req['body']),
        ['DELETE', null] => dti_topic_destroy($ctx, (int)$tid),
        ['POST', 'claim'] => dti_topic_claim($ctx, (int)$tid, $req['body']),
        ['POST', 'release'] => dti_topic_release($ctx, (int)$tid),
        ['POST', 'schedule'] => dti_topic_schedule($ctx, (int)$tid, $req['body']),
        ['POST', 'complete'] => dti_topic_complete($ctx, (int)$tid, $req['body']),
        ['POST', 'assign'] => dti_topic_assign($ctx, (int)$tid, $req['body']),
        ['GET', 'related'] => dti_topic_related($ctx, (int)$tid),
        ['POST', 'emotions'] => dti_topic_emotion($ctx, (int)$tid, (string)($req['seg'][3] ?? '')),
        default => dti_route_materials($ctx, $req, (int)$tid),
    };
}

function dti_topic_index(array $ctx): array {
    $pdo = $ctx['pdo'];
    $email = $ctx['identity']['email'];

    // 숨김·보관은 관리자 화면에만 있어야 한다. 목록에서 빼는 판정은 서버가 한다
    $rows = dti_topic_list_with_presentations($pdo, dti_is_admin($ctx['config'], $email));
    [$counts, $mine] = dti_emotion_summary($pdo, $email);

    $out = [];
    foreach ($rows as [$topic, $pres]) {
        $out[] = dti_topic_present($topic, $pres, $counts[$topic['id']] ?? null, $mine[$topic['id']] ?? null);
    }
    return dti_json($out);
}

function dti_topic_show(array $ctx, int $tid): array {
    $topic = dti_topic_find_or_fail($ctx['pdo'], $tid);
    return dti_json(dti_topic_present($topic, dti_presentation_of_topic($ctx['pdo'], $tid)));
}

function dti_topic_create(array $ctx, array $body): array {
    dti_require_admin($ctx);
    $pdo = $ctx['pdo'];

    $topic = dti_topic_new();
    dti_topic_fill($topic, $body, true);
    $topic['created_by'] = $ctx['identity']['email'];
    $topic['created_at'] = date('Y-m-d H:i:s');
    $tid = dti_topic_insert($pdo, $topic);

    $plannedDate = dti_want_date($body['planned_date'] ?? '', '예정일');
    if ($plannedDate !== '') {
        dti_presentation_create($pdo, $tid, ['planned_date' => $plannedDate]);
    }
    dti_related_rebuild($pdo);

    return dti_json(dti_topic_present($topic, dti_presentation_of_topic($pdo, $tid)), 201);
}

function dti_topic_edit(array $ctx, int $tid, array $body): array {
    dti_require_admin($ctx);
    $pdo = $ctx['pdo'];

    $topic = dti_topic_find_or_fail($pdo, $tid);
    dti_topic_fill($topic, $body, false);
    dti_topic_update($pdo, $topic);

    // 예정일은 아티클이 아니라 발표 행에 있다. 값이 왔을 때만 손댄다
    if (array_key_exists('planned_date', $body)) {
        $plannedDate = dti_want_date($body['planned_date'], '예정일');
        $pres = dti_presentation_of_topic($pdo, $tid);
        if ($pres === null && $plannedDate !== '') {
            dti_presentation_create($pdo, $tid, ['planned_date' => $plannedDate]);
        } elseif ($pres !== null) {
            $pres['planned_date'] = $plannedDate;
            dti_presentation_update($pdo, $pres);
        }
    }

    dti_related_rebuild($pdo);

    return dti_json(dti_topic_present($topic, dti_presentation_of_topic($pdo, $tid)));
}

function dti_topic_destroy(array $ctx, int $tid): array {
    dti_require_admin($ctx);
    $pdo = $ctx['pdo'];

    $topic = dti_topic_find_or_fail($pdo, $tid);
    $pres = dti_presentation_of_topic($pdo, $tid);
    if ($pres) dti_presentation_purge($pdo, $ctx['config']['upload_dir'], $pres);
    dti_topic_delete($pdo, (int)$topic['id']);
    dti_related_rebuild($pdo);

    return dti_json(['ok' => true]);
}

function dti_topic_claim(array $ctx, int $tid, array $body): array {
    $pdo = $ctx['pdo'];
    $identity = $ctx['identity'];

    $topic = dti_topic_find_or_fail($pdo, $tid);
    if ($topic['active'] !== 1 || $topic['archived'] !== 0) {
        throw new DtiError('지금은 예약할 수 없는 아티클입니다', 409);
    }

    $plannedDate = array_key_exists('planned_date', $body)
        ? dti_want_date($body['planned_date'], '예정일') : null;
    if ($plannedDate === '') $plannedDate = null;

    // 동시 예약 방지 — 조건부 UPDATE 한 방. 발표자 없는 행(자료만 등)이 있으면 그 행을 차지한다
    $taken = dti_presentation_claim($pdo, $tid, $identity['email'], $identity['name'], $plannedDate);

    if (!$taken) {
        try {
            $values = ['presenter_email' => $identity['email'], 'presenter' => $identity['name']];
            if ($plannedDate !== null) $values['planned_date'] = $plannedDate;
            dti_presentation_create($pdo, $tid, $values);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;
            throw new DtiError('이미 예약되었거나 발표가 끝난 아티클입니다', 409);
        }
    }

    $pres = dti_presentation_of_topic($pdo, $tid);
    dti_notify_new_presenter($ctx['config']['slack_webhook'], $ctx['webhook'] ?? null,
        $topic['title'], $pres['presenter'], $pres['planned_date']);

    return dti_json(dti_topic_present($topic, $pres));
}

function dti_topic_release(array $ctx, int $tid): array {
    $pdo = $ctx['pdo'];
    $email = $ctx['identity']['email'];

    $topic = dti_topic_find_or_fail($pdo, $tid);
    $pres = dti_presentation_of_topic($pdo, $tid);
    if (!dti_may_manage($pres, $email, dti_is_admin($ctx['config'], $email))) {
        throw new DtiError('본인이 예약한 아티클만 취소할 수 있습니다', 403);
    }
    if ($pres) dti_presentation_unassign($pdo, $ctx['config']['upload_dir'], $pres);

    return dti_json(dti_topic_present($topic, dti_presentation_of_topic($pdo, $tid)));
}

function dti_topic_schedule(array $ctx, int $tid, array $body): array {
    // claim 은 아무도 안 잡은 주제에만 걸려서, 예약 후 날짜를 넣을 경로가 따로 필요하다
    $pdo = $ctx['pdo'];
    $email = $ctx['identity']['email'];

    $topic = dti_topic_find_or_fail($pdo, $tid);
    $pres = dti_presentation_of_topic($pdo, $tid);
    if (!dti_may_manage($pres, $email, dti_is_admin($ctx['config'], $email))) {
        throw new DtiError('본인이 예약한 아티클만 예정일을 정할 수 있습니다', 403);
    }
    if ($pres === null) throw new DtiError('예약이 없는 아티클입니다', 409);
    if ($pres['done_date'] !== '') throw new DtiError('이미 발표가 끝난 아티클입니다', 409);

    $pres['planned_date'] = dti_want_date($body['planned_date'] ?? '', '예정일');
    dti_presentation_update($pdo, $pres);

    return dti_json(dti_topic_present($topic, $pres));
}

function dti_topic_complete(array $ctx, int $tid, array $body): array {
    dti_require_admin($ctx);
    $pdo = $ctx['pdo'];

    $topic = dti_topic_find_or_fail($pdo, $tid);
    $pres = dti_presentation_of_topic($pdo, $tid) ?? dti_presentation_create($pdo, $tid);
    $pres['done_date'] = dti_want_date($body['done_date'] ?? '', '발표일') ?: date('Y-m-d');
    dti_presentation_update($pdo, $pres);

    return dti_json(dti_topic_present($topic, $pres));
}

function dti_topic_assign(array $ctx, int $tid, array $body): array {
    // 예약과 달리 이미 예약된 주제도 덮어쓴다 — 배정 권한은 관리자에게 있다
    dti_require_admin($ctx);
    $pdo = $ctx['pdo'];

    $topic = dti_topic_find_or_fail($pdo, $tid);
    $email = dti_want_str($body, 'email', '발표자');
    $members = dti_members_all($pdo, $ctx['config']);
    if ($email !== '' && !dti_members_has($members, $email)) {
        throw new DtiError('명단에 없는 사람입니다', 422);
    }

    $pres = dti_presentation_of_topic($pdo, $tid);

    if ($email === '') {
        if ($pres) dti_presentation_unassign($pdo, $ctx['config']['upload_dir'], $pres);
        return dti_json(dti_topic_present($topic, dti_presentation_of_topic($pdo, $tid)));
    }

    $values = ['presenter_email' => $email, 'presenter' => dti_members_name_of($members, $email)];
    if (array_key_exists('planned_date', $body)) {
        $values['planned_date'] = dti_want_date($body['planned_date'], '예정일');
    }

    if ($pres) {
        foreach ($values as $field => $value) {
            $pres[$field] = $value;
        }
        dti_presentation_update($pdo, $pres);
    } else {
        $pres = dti_presentation_create($pdo, $tid, $values);
    }
    dti_notify_new_presenter($ctx['config']['slack_webhook'], $ctx['webhook'] ?? null,
        $topic['title'], $pres['presenter'], $pres['planned_date']);

    return dti_json(dti_topic_present($topic, $pres));
}

function dti_topic_related(array $ctx, int $tid): array {
    dti_topic_find_or_fail($ctx['pdo'], $tid);
    return dti_json(dti_related_top_for($ctx['pdo'], $tid, DTI_MAX_RELATED));
}

function dti_topic_emotion(array $ctx, int $tid, string $kind): array {
    $pdo = $ctx['pdo'];

    dti_topic_find_or_fail($pdo, $tid);
    $kind = dti_want_one_of($kind, DTI_EMOTIONS, '반응', blankOk: false);

    $pres = dti_presentation_of_topic($pdo, $tid);
    if ($pres === null || $pres['done_date'] === '') {
        throw new DtiError('발표가 끝난 아티클에만 반응을 남길 수 있습니다', 409);
    }

    $left = dti_emotion_toggle($pdo, (int)$pres['id'], $ctx['identity']['email'], $kind,
                               date('Y-m-d H:i:s'));

    return dti_json([
        'kind' => $kind,
        'count' => dti_emotion_count_for($pdo, (int)$pres['id'], $kind),
        'mine' => $left,
    ]);
}

/**
 * 본문의 값을 아티클에 채운다. 등록은 빠진 값을 기본값으로 두고($defaults),
 * 수정은 본문에 온 키만 바꾼다 — 파이썬 TopicPatch 의 exclude_unset 과 같아야 한다.
 */
function dti_topic_fill(array &$topic, array $body, bool $defaults): void {
    $has = static fn (string $key) => array_key_exists($key, $body);

    if ($defaults || $has('title')) $topic['title'] = dti_want_str($body, 'title', '제목', required: true);
    if ($defaults || $has('field')) $topic['field'] = dti_want_str($body, 'field', '분야');
    if ($defaults || $has('keywords')) $topic['keywords'] = dti_want_str($body, 'keywords', '키워드');
    if ($defaults || $has('volume')) $topic['volume'] = dti_want_str($body, 'volume', 'Volume');
    if ($defaults || $has('page')) $topic['page'] = dti_want_str($body, 'page', 'Page');
    if ($defaults || $has('note')) $topic['note'] = dti_want_str($body, 'note', '비고');
    if ($defaults || $has('requirement')) {
        $topic['requirement'] = dti_want_str($body, 'requirement', '발표구분') ?: 'recommended';
    }
    if ($defaults || $has('magazine')) {
        $topic['magazine'] = dti_want_one_of($body['magazine'] ?? '', DTI_MAGAZINES, '매거진');
    }
    if ($defaults || $has('team')) {
        $topic['team'] = dti_want_one_of($body['team'] ?? '', DTI_TEAMS, '팀');
    }
    if ($defaults || $has('year')) $topic['year'] = dti_want_nullable_int($body, 'year', '년도');
    if ($has('active')) $topic['active'] = dti_flag($body['active']);
    if ($has('archived')) $topic['archived'] = dti_flag($body['archived']);
}
