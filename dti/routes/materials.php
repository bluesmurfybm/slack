<?php
/** 발표자료·스캔 원본 칸. 경로는 /topics/{tid}/{slot}/... 이다. */

function dti_route_materials(array $ctx, array $req, int $tid): array {
    $slot = dti_slot_check((string)($req['seg'][2] ?? ''));

    return match ([$req['method'], $req['seg'][3] ?? null]) {
        ['POST', 'link'] => dti_material_attach_link($ctx, $tid, $slot, $req['body']),
        ['POST', 'file'] => dti_material_attach_file($ctx, $tid, $slot, $req['files']),
        ['GET', 'download'] => dti_material_download($ctx, $tid, $slot),
        ['DELETE', null] => dti_material_detach($ctx, $tid, $slot),
        default => throw new DtiError('없는 API 입니다', 404),
    };
}

function dti_material_attach_link(array $ctx, int $tid, string $slot, array $body): array {
    [$topic, $pres] = dti_material_guard($ctx, $tid);

    $url = dti_want_url($body['url'] ?? '', '주소');
    if ($url === '') throw new DtiError('http(s) 로 시작하는 주소만 넣을 수 있습니다', 422);

    $holder = dti_material_holder_for_write($ctx, $slot, $topic, $pres);
    dti_material_remove_file($ctx, $holder, $slot);
    dti_slot_set($holder, $slot, [
        'kind' => 'link',
        'url' => $url,
        'name' => dti_want_str($body, 'name', '자료 이름') ?: $url,
        'path' => null,
    ]);

    return dti_material_save($ctx, $topic, $holder, $slot);
}

function dti_material_attach_file(array $ctx, int $tid, string $slot, array $files): array {
    [$topic, $pres] = dti_material_guard($ctx, $tid);

    $file = $files['file'] ?? null;
    if (!is_array($file)) {
        // post_max_size 를 넘기면 PHP 가 $_FILES 를 통째로 비워 보낸다. 그것도 용량 초과다
        throw new DtiError($ctx['config']['max_upload_mb'] . 'MB 까지 올릴 수 있습니다', 413);
    }

    $stored = dti_save_upload($ctx['config']['upload_dir'], $ctx['config']['max_upload_mb'],
                              $tid, $file, $ctx['mover'] ?? null);

    $holder = dti_material_holder_for_write($ctx, $slot, $topic, $pres);
    dti_material_remove_file($ctx, $holder, $slot);
    dti_slot_set($holder, $slot, [
        'kind' => 'file',
        'path' => $stored,
        'url' => null,
        'name' => basename((string)($file['name'] ?? '자료')),
    ]);

    return dti_material_save($ctx, $topic, $holder, $slot);
}

function dti_material_detach(array $ctx, int $tid, string $slot): array {
    [$topic, $pres] = dti_material_guard($ctx, $tid);

    $holder = dti_slot_holder($slot, $topic, $pres);
    if ($holder === null) {
        return dti_json(dti_topic_present($topic, null));
    }

    dti_material_remove_file($ctx, $holder, $slot);
    dti_slot_set($holder, $slot, ['kind' => null, 'name' => null, 'url' => null, 'path' => null]);

    return dti_material_save($ctx, $topic, $holder, $slot);
}

function dti_material_download(array $ctx, int $tid, string $slot): array {
    $pdo = $ctx['pdo'];
    $topic = dti_topic_find_or_fail($pdo, $tid);
    $holder = dti_slot_holder($slot, $topic, dti_presentation_of_topic($pdo, $tid));

    $path = dti_resolve_upload($ctx['config']['upload_dir'], dti_slot_get($holder, $slot, 'path'));
    $name = dti_slot_get($holder, $slot, 'name') ?? basename($path);

    return dti_file($path, $name);
}

function dti_material_guard(array $ctx, int $tid): array {
    $pdo = $ctx['pdo'];
    $topic = dti_topic_find_or_fail($pdo, $tid);
    $pres = dti_presentation_of_topic($pdo, $tid);

    $email = $ctx['identity']['email'];
    if (!dti_may_manage($pres, $email, dti_is_admin($ctx['config'], $email))) {
        throw new DtiError('발표자 본인이나 관리자만 자료를 올릴 수 있습니다', 403);
    }
    return [$topic, $pres];
}

function dti_material_holder_for_write(array $ctx, string $slot, array $topic, ?array $pres): array {
    return dti_slot_holder($slot, $topic, $pres)
        ?? dti_presentation_create($ctx['pdo'], (int)$topic['id']);
}

function dti_material_remove_file(array $ctx, array $holder, string $slot): void {
    dti_remove_upload($ctx['config']['upload_dir'], dti_slot_get($holder, $slot, 'path'));
}

function dti_material_save(array $ctx, array $topic, array $holder, string $slot): array {
    $pdo = $ctx['pdo'];
    if (dti_slot_on_topic($slot)) {
        dti_topic_update($pdo, $holder);
        // 스캔 칸은 아티클 행에 있다. 배열은 복사되므로 고친 쪽을 화면에 내보낸다
        $topic = $holder;
    } else {
        dti_presentation_update($pdo, $holder);
    }

    return dti_json(dti_topic_present($topic, dti_presentation_of_topic($pdo, (int)$topic['id'])));
}
