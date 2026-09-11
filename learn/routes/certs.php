<?php
/** 이수증 등록·목록·다운로드·삭제. */

function learn_cert_listing(PDO $pdo, $rid) {
    $st = $pdo->prepare("SELECT id, name, uploaded_by, created_at
                         FROM learn_certs WHERE request_id=? ORDER BY id");
    $st->execute([$rid]);
    $out = [];
    foreach ($st->fetchAll() as $c) {
        $c['id'] = (int)$c['id'];
        $out[] = $c;
    }
    return $out;
}

function learn_fetch_cert(PDO $pdo, $rid, $cid) {
    $st = $pdo->prepare("SELECT * FROM learn_certs WHERE id=? AND request_id=?");
    $st->execute([$cid, $rid]);
    $row = $st->fetch();
    if (!$row) throw new LearnError('없는 이수증입니다', 404);
    return $row;
}

function learn_route_certs(PDO $pdo, array $identity, $rid, array $seg, $method) {
    $cid = isset($seg[3]) ? (int)$seg[3] : 0;

    if (!$cid) {
        if ($method === 'GET') {
            learn_fetch_request($pdo, $rid);
            jsend(learn_cert_listing($pdo, $rid));
        }
        if ($method === 'POST') learn_cert_upload($pdo, $identity, $rid);
        throw new LearnError('없는 API 입니다', 404);
    }

    if (($seg[4] ?? '') === 'download' && $method === 'GET') {
        learn_cert_download($pdo, $rid, $cid);
    }
    if (($seg[4] ?? '') === '' && $method === 'DELETE') {
        learn_cert_delete($pdo, $identity, $rid, $cid);
    }
    throw new LearnError('없는 API 입니다', 404);
}

/** 등록·삭제는 본인이나 관리자만, 그리고 이수 이후 상태에서만 */
function learn_cert_guard(PDO $pdo, array $identity, $rid) {
    $req = learn_fetch_request($pdo, $rid);
    learn_require_applicant_or_admin($req, $identity, '이수증을 다룰');
    learn_ensure_transition($req, ACT_ATTACH);
    return $req;
}

function learn_cert_upload(PDO $pdo, array $identity, $rid) {
    learn_cert_guard($pdo, $identity, $rid);
    if (empty($_FILES['file'])) {
        // post_max_size 를 넘으면 PHP 가 본문을 통째로 버려 $_FILES 가 빈다 —
        // "올릴 파일이 없습니다" 로 끝내면 왜 안 되는지 알 수 없다.
        $sent = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($sent > 0) throw new LearnError(MAX_UPLOAD_MB . 'MB 까지 올릴 수 있습니다', 413);
        throw new LearnError('올릴 파일이 없습니다', 422);
    }

    [$original, $stored] = learn_save_upload($rid, $_FILES['file']);
    try {
        $pdo->prepare("INSERT INTO learn_certs
                       (request_id, name, path, uploaded_by, created_at) VALUES (?,?,?,?,?)")
            ->execute([$rid, $original, $stored, $identity['email'], now_stamp()]);
    } catch (Throwable $e) {
        learn_remove_upload($stored);   // DB 에 못 남기면 파일만 떠도는 게 된다
        throw $e;
    }
    jsend(learn_cert_listing($pdo, $rid), 201);
}

function learn_cert_delete(PDO $pdo, array $identity, $rid, $cid) {
    learn_cert_guard($pdo, $identity, $rid);
    $cert = learn_fetch_cert($pdo, $rid, $cid);
    learn_remove_upload($cert['path']);
    $pdo->prepare("DELETE FROM learn_certs WHERE id=?")->execute([$cid]);
    jsend(learn_cert_listing($pdo, $rid));
}

function learn_cert_download(PDO $pdo, $rid, $cid) {
    $cert = learn_fetch_cert($pdo, $rid, $cid);
    $path = learn_resolve_upload($cert['path']);
    [$ctype, $disposition] = learn_disposition($cert['name'] ?: $cert['path']);

    header('Content-Type: ' . $ctype);
    header('Content-Disposition: ' . $disposition);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    // 인라인으로 열리는 pdf·이미지가 다른 사이트에 끼워지는 걸 막는다
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
