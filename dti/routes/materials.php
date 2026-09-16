<?php
/** 발표자료·스캔 원본 칸. 경로는 /topics/{tid}/{slot}/... 이고 한 칸에 여러 건이 들어간다. */

function dti_route_materials(array $ctx, array $req, int $tid): array {
    $slot = dti_slot_check((string)($req['seg'][2] ?? ''));
    $method = $req['method'];
    $third = $req['seg'][3] ?? null;
    $fourth = $req['seg'][4] ?? null;

    if ($method === 'POST' && $third === 'link') {
        return dti_material_attach_link($ctx, $tid, $slot, $req['body']);
    }
    if ($method === 'POST' && $third === 'file') {
        return dti_material_attach_file($ctx, $tid, $slot, $req['files']);
    }
    // 자료가 여러 건이 되기 전의 경로 — 첫 자료를 열고, 칸을 통째로 비운다
    if ($method === 'GET' && $third === 'download') {
        return dti_material_download($ctx, $tid, $slot, null);
    }
    if ($method === 'DELETE' && $third === null) {
        return dti_material_detach_all($ctx, $tid, $slot);
    }

    if ($third !== null && ctype_digit($third)) {
        if ($method === 'GET' && $fourth === 'download') {
            return dti_material_download($ctx, $tid, $slot, (int)$third);
        }
        if ($method === 'DELETE' && $fourth === null) {
            return dti_material_detach($ctx, $tid, $slot, (int)$third);
        }
    }

    throw new DtiError('없는 API 입니다', 404);
}

function dti_material_attach_link(array $ctx, int $tid, string $slot, array $body): array {
    $topic = dti_material_guard($ctx, $tid);

    $url = dti_want_url($body['url'] ?? '', '주소');
    if ($url === '') throw new DtiError('http(s) 로 시작하는 주소만 넣을 수 있습니다', 422);

    dti_material_create($ctx['pdo'], $tid, $slot, [
        'kind' => 'link',
        'url' => $url,
        'name' => dti_want_str($body, 'name', '자료 이름') ?: $url,
        'created_by' => $ctx['identity']['email'],
    ]);

    return dti_material_payload($ctx, $topic);
}

function dti_material_attach_file(array $ctx, int $tid, string $slot, array $files): array {
    $topic = dti_material_guard($ctx, $tid);

    $file = $files['file'] ?? null;
    if (!is_array($file)) {
        // post_max_size 를 넘기면 PHP 가 $_FILES 를 통째로 비워 보낸다. 그것도 용량 초과다
        throw new DtiError($ctx['config']['max_upload_mb'] . 'MB 까지 올릴 수 있습니다', 413);
    }

    $config = $ctx['config'];
    $stored = dti_save_upload($config['upload_dir'], $config['max_upload_mb'],
                              $tid, $file, $ctx['mover'] ?? null);
    $original = basename((string)($file['name'] ?? '자료'));

    dti_material_create($ctx['pdo'], $tid, $slot, [
        'kind' => 'file',
        'path' => $stored,
        'name' => $original,
        'created_by' => $ctx['identity']['email'],
    ]);

    $converter = $ctx['converter']
        ?? dti_soffice_converter($config['soffice'], $config['soffice_timeout']);
    $pdf = dti_pdf_companion($config['upload_dir'], $tid, $original, $stored, $converter);
    if ($pdf !== null) {
        dti_material_create($ctx['pdo'], $tid, $slot, [
            'kind' => 'file',
            'path' => $pdf['path'],
            'name' => $pdf['name'],
            'created_by' => $ctx['identity']['email'],
        ]);
    }

    return dti_material_payload($ctx, $topic);
}

function dti_material_detach(array $ctx, int $tid, string $slot, int $mid): array {
    $topic = dti_material_guard($ctx, $tid);
    $material = dti_material_find_or_fail($ctx['pdo'], $tid, $slot, $mid);

    dti_material_remove($ctx['pdo'], $ctx['config']['upload_dir'], $material);

    return dti_material_payload($ctx, $topic);
}

function dti_material_detach_all(array $ctx, int $tid, string $slot): array {
    $topic = dti_material_guard($ctx, $tid);

    dti_material_remove_all($ctx['pdo'], $ctx['config']['upload_dir'], $tid, $slot);

    return dti_material_payload($ctx, $topic);
}

/** $mid 가 null 이면 그 칸의 첫 자료를 연다 */
function dti_material_download(array $ctx, int $tid, string $slot, ?int $mid): array {
    $pdo = $ctx['pdo'];
    dti_topic_find_or_fail($pdo, $tid);

    if ($mid === null) {
        $material = dti_material_list($pdo, $tid, $slot)[0] ?? null;
        if ($material === null) throw new DtiError('올라온 파일이 없습니다', 404);
    } else {
        $material = dti_material_find_or_fail($pdo, $tid, $slot, $mid);
    }

    $path = dti_resolve_upload($ctx['config']['upload_dir'], $material['path']);

    return dti_file($path, $material['name'] ?: basename($path));
}

function dti_material_guard(array $ctx, int $tid): array {
    $pdo = $ctx['pdo'];
    $topic = dti_topic_find_or_fail($pdo, $tid);
    $pres = dti_presentation_of_topic($pdo, $tid);

    $email = $ctx['identity']['email'];
    if (!dti_may_manage($pres, $email, dti_is_admin($ctx['config'], $email))) {
        throw new DtiError('발표자 본인이나 관리자만 자료를 올릴 수 있습니다', 403);
    }
    return $topic;
}

function dti_material_payload(array $ctx, array $topic): array {
    $pdo = $ctx['pdo'];
    $tid = (int)$topic['id'];

    return dti_json(dti_topic_present_one($pdo, $topic, dti_presentation_of_topic($pdo, $tid)));
}
